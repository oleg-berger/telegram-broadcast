param(
    [ValidateSet('prepare', 'demo', 'status', 'listen', 'staff', 'callback', 'inbox')]
    [string]$Action = 'status',
    [string]$Recipient = '',
    [string]$Text = '',
    [switch]$Once
)
$ErrorActionPreference = 'Stop'
$commandAction = $Action
try {
    $repo = [IO.Path]::GetFullPath((Join-Path $PSScriptRoot '..\..'))
    Set-Location -LiteralPath $repo
    $branch = & git -c "safe.directory=$($repo.Replace('\', '/'))" branch --show-current 2>$null
    if ($LASTEXITCODE -ne 0 -or $branch -ne 'broadcast-whatsapp') { throw 'Branch mismatch.' }
    if ($Action -eq 'prepare') {
        $private = Join-Path $repo 'secrets\whatsapp-app'
        $runtime = Join-Path $repo 'var\whatsapp-app'
        foreach ($directory in @($private, $runtime)) {
            New-Item -ItemType Directory -Path $directory -Force | Out-Null
            $acl = New-Object Security.AccessControl.DirectorySecurity
            $acl.SetAccessRuleProtection($true, $false)
            foreach ($sid in @([Security.Principal.WindowsIdentity]::GetCurrent().User, [Security.Principal.SecurityIdentifier]'S-1-5-18', [Security.Principal.SecurityIdentifier]'S-1-5-32-544')) {
                $rule = New-Object Security.AccessControl.FileSystemAccessRule($sid, 'FullControl', 'ContainerInherit,ObjectInherit', 'None', 'Allow')
                $acl.AddAccessRule($rule)
            }
            Set-Acl -LiteralPath $directory -AclObject $acl
        }
        $allowlist = Join-Path $private 'allowed-recipients.json'
        if ($Recipient -eq '' -and -not (Test-Path -LiteralPath $allowlist)) {
            $prior = Join-Path $repo 'var\whatsapp-spike\outbound-test-20261102-once.json'
            if (Test-Path -LiteralPath $prior) { $Recipient = (Get-Content -Raw -LiteralPath $prior | ConvertFrom-Json).recipient }
        }
        if ($Recipient -ne '') {
            $normalized = $Recipient.TrimStart('+')
            if ($normalized -notmatch '^[1-9][0-9]{6,14}$') { throw 'Invalid recipient.' }
            [IO.File]::WriteAllText($allowlist, (ConvertTo-Json -InputObject @($normalized) -Compress), (New-Object Text.UTF8Encoding($false)))
        }
        if (-not (Test-Path -LiteralPath $allowlist)) { throw 'Recipient allowlist is missing.' }
        $settings = @{ mode = 'local-fake-telegram-mail'; baseUrl = 'http://127.0.0.1:21465'; session = 'whatsapp-test' }
        [IO.File]::WriteAllText((Join-Path $private 'config.json'), ($settings | ConvertTo-Json -Compress), (New-Object Text.UTF8Encoding($false)))
        $commandAction = 'init'
    }
    $phpArguments = @('-d', 'extension=intl', '-d', 'extension=zip', '-d', 'extension=fileinfo', (Join-Path $PSScriptRoot 'console.php'), $commandAction)
    if ($Action -in @('staff', 'callback')) { $phpArguments += $Text }
    if ($Once) { $phpArguments += '--once' }
    & php @phpArguments
    if ($LASTEXITCODE -ne 0) { throw 'Local command failed.' }
} catch {
    Write-Output ('local_launcher_failed; exception=' + $_.Exception.GetType().Name)
    exit 1
}
