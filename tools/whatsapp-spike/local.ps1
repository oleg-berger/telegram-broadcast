param(
    [Parameter(Mandatory = $true)]
    [ValidateSet('prepare', 'start', 'status', 'pair', 'qr', 'stats', 'stop')]
    [string] $Action
)

$ErrorActionPreference = 'Stop'
$taskRoot = [IO.Path]::GetFullPath((Join-Path $PSScriptRoot '..\..'))
$taskSecrets = Join-Path $taskRoot 'secrets\whatsapp-spike'
$taskRuntime = Join-Path $taskRoot 'var\whatsapp-spike'
$taskEnv = Join-Path $taskSecrets 'transport.env'
$taskTokenFile = Join-Path $taskSecrets 'session-token.json'
$taskCompose = Join-Path $PSScriptRoot 'compose.yaml'
$taskSession = 'whatsapp-test'
$taskBase = 'http://127.0.0.1:21465'

function Invoke-SpikeCompose {
    param([string[]] $Arguments)
    & docker compose --project-name broadcast-whatsapp-spike --env-file $taskEnv --file $taskCompose @Arguments
    if ($LASTEXITCODE -ne 0) {
        throw 'Local transport command failed.'
    }
}

function Get-SpikeHeaders {
    if (-not (Test-Path -LiteralPath $taskTokenFile)) {
        throw 'Run pair first.'
    }
    $saved = Get-Content -LiteralPath $taskTokenFile -Raw | ConvertFrom-Json
    if ($saved.session -cne $taskSession -or [string]::IsNullOrWhiteSpace($saved.token)) {
        throw 'Invalid local session token.'
    }
    return @{ Authorization = 'Bearer ' + $saved.token }
}

function Write-SpikeStatus {
    param($Response)
    $known = @('INITIALIZING', 'QRCODE', 'CONNECTED', 'DISCONNECTED', 'UNPAIRED', 'CLOSED')
    if ($Response.status -cnotin $known) {
        throw 'Unexpected session status.'
    }
    # Session responses can contain QR credentials; never print the whole object.
    Write-Output ('session_status=' + $Response.status)
}

try {
    # Stopping must remain possible even after checkout or loss of the env file.
    if ($Action -eq 'stop') {
        $ids = @(& docker ps -aq --filter 'label=com.docker.compose.project=broadcast-whatsapp-spike' --filter 'label=com.docker.compose.service=transport')
        if ($LASTEXITCODE -ne 0) { throw 'Cannot inspect the local transport.' }
        foreach ($id in $ids) {
            if ($id -cnotmatch '^[0-9a-f]{12,64}$') { throw 'Unexpected container ID.' }
            & docker stop --time 35 $id | Out-Null
            if ($LASTEXITCODE -ne 0) { throw 'Cannot stop the local transport.' }
        }
        Write-Output 'local_transport_stopped_data_preserved'
        exit 0
    }
    $gitRoot = $taskRoot.Replace('\', '/')
    $branch = & git -c "safe.directory=$gitRoot" -C $taskRoot branch --show-current
    if ($LASTEXITCODE -ne 0 -or $branch -cne 'broadcast-whatsapp') {
        throw 'Use the broadcast-whatsapp branch.'
    }
    if ($Action -eq 'prepare') {
        New-Item -ItemType Directory -Force -Path $taskSecrets, (Join-Path $taskRuntime 'data') | Out-Null
        # Secrets include the transport database and cached QR, not only tokens.
        foreach ($directory in @($taskSecrets, $taskRuntime)) {
            $acl = New-Object Security.AccessControl.DirectorySecurity
            $acl.SetAccessRuleProtection($true, $false)
            foreach ($sid in @([Security.Principal.WindowsIdentity]::GetCurrent().User, [Security.Principal.SecurityIdentifier]::new('S-1-5-18'), [Security.Principal.SecurityIdentifier]::new('S-1-5-32-544'))) {
                $rule = [Security.AccessControl.FileSystemAccessRule]::new($sid, 'FullControl', 'ContainerInherit,ObjectInherit', 'None', 'Allow')
                $acl.AddAccessRule($rule)
            }
            Set-Acl -LiteralPath $directory -AclObject $acl
        }
        if (-not (Test-Path -LiteralPath $taskEnv)) {
            $bytes = New-Object byte[] 24
            $rng = [Security.Cryptography.RandomNumberGenerator]::Create()
            try { $rng.GetBytes($bytes) } finally { $rng.Dispose() }
            $secret = [BitConverter]::ToString($bytes).Replace('-', '').ToLowerInvariant()
            [IO.File]::WriteAllText($taskEnv, "SECRET_KEY=$secret`n", [Text.Encoding]::ASCII)
        }
        Write-Output 'local_spike_prepared'
        exit 0
    }
    if (-not (Test-Path -LiteralPath $taskEnv)) {
        throw 'Run prepare first.'
    }
    switch ($Action) {
        'start' {
            Invoke-SpikeCompose -Arguments @('config', '--quiet')
            Invoke-SpikeCompose -Arguments @('up', '-d', '--wait', '--wait-timeout', '60')
            Write-Output 'local_transport_started'
        }
        'pair' {
            if (-not (Test-Path -LiteralPath $taskTokenFile)) {
                $raw = & docker compose --project-name broadcast-whatsapp-spike --env-file $taskEnv --file $taskCompose exec -T transport /app/token -session $taskSession 2>$null
                if ($LASTEXITCODE -ne 0) { throw 'Offline token generation failed.' }
                $token = ($raw -join "`n") | ConvertFrom-Json
                if ($token.session -cne $taskSession -or [string]::IsNullOrWhiteSpace($token.token)) {
                    throw 'Invalid generated token.'
                }
                [IO.File]::WriteAllText($taskTokenFile, ($token | Select-Object session, token | ConvertTo-Json), [Text.UTF8Encoding]::new($false))
            }
            $response = Invoke-RestMethod -Uri "$taskBase/api/$taskSession/start-session" -Method Post -Headers (Get-SpikeHeaders) -TimeoutSec 30 -MaximumRedirection 0
            Write-SpikeStatus $response
        }
        'status' {
            $health = Invoke-WebRequest -UseBasicParsing -Uri "$taskBase/healthz" -TimeoutSec 5 -MaximumRedirection 0
            Write-Output ('health_http=' + [int] $health.StatusCode)
            if (Test-Path -LiteralPath $taskTokenFile) {
                $response = Invoke-RestMethod -Uri "$taskBase/api/$taskSession/status-session" -Headers (Get-SpikeHeaders) -TimeoutSec 10 -MaximumRedirection 0
                Write-SpikeStatus $response
            }
        }
        'qr' {
            $temporary = Join-Path $taskRuntime ('qr-' + [Guid]::NewGuid().ToString('N') + '.tmp')
            try {
                $response = Invoke-WebRequest -UseBasicParsing -Uri "$taskBase/api/$taskSession/qrcode-session" -Headers (Get-SpikeHeaders) -TimeoutSec 10 -MaximumRedirection 0
                if ($response.Headers['Content-Type'] -notlike 'image/png*') {
                    throw 'QR is not available. Check status or run pair again.'
                }
                # OutFile + PassThru produces empty files on Windows PowerShell 5.1.
                $stream = $response.RawContentStream
                $stream.Position = 0
                $signature = New-Object byte[] 8
                if ($stream.Read($signature, 0, 8) -ne 8 -or [BitConverter]::ToString($signature) -cne '89-50-4E-47-0D-0A-1A-0A') {
                    throw 'Invalid QR image.'
                }
                $stream.Position = 0
                $file = [IO.File]::Open($temporary, [IO.FileMode]::CreateNew)
                try { $stream.CopyTo($file) } finally { $file.Dispose() }
                $destination = Join-Path $taskRuntime 'qr.png'
                Move-Item -LiteralPath $temporary -Destination $destination -Force
                Write-Output $destination
            } finally {
                if (Test-Path -LiteralPath $temporary) { Remove-Item -LiteralPath $temporary }
            }
        }
        'stats' {
            Invoke-SpikeCompose -Arguments @('stats', '--no-stream', '--format', 'json')
        }
    }
} catch {
    # Exceptions from HTTP tools may contain credentials, URLs or remote bodies.
    Write-Output ('local_spike_failed; action=' + $Action + '; exception=' + $_.Exception.GetType().Name)
    exit 1
}
