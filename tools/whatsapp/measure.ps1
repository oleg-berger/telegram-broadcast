param([ValidateSet('idle', 'connect', 'exchange', 'recovery')][string]$Phase = 'idle', [ValidateRange(5, 120)][int]$Seconds = 20)
$ErrorActionPreference = 'Stop'
try {
    $repo = [IO.Path]::GetFullPath((Join-Path $PSScriptRoot '..\..'))
    $container = 'broadcast-whatsapp-spike-transport-1'
    $labels = & docker inspect --format '{{json .Config.Labels}}' $container 2>$null
    if ($LASTEXITCODE -ne 0 -or ($labels | ConvertFrom-Json).'com.docker.compose.project' -ne 'broadcast-whatsapp-spike') { throw 'Unexpected container.' }
    $samples = @()
    $deadline = [DateTime]::UtcNow.AddSeconds($Seconds)
    do {
        $raw = & docker stats --no-stream --format '{{json .}}' $container 2>$null
        if ($LASTEXITCODE -eq 0 -and $raw) {
            $metric = $raw | ConvertFrom-Json
            $cpu = [double]::Parse($metric.CPUPerc.TrimEnd('%'), [Globalization.CultureInfo]::InvariantCulture)
            if ($metric.MemUsage -match '^([0-9.]+)([KMG]?i?B)') {
                $value = [double]::Parse($Matches[1], [Globalization.CultureInfo]::InvariantCulture)
                $factor = @{ B = 1.0; KB = 1000.0; KiB = 1024.0; MB = 1000000.0; MiB = 1048576.0; GB = 1000000000.0; GiB = 1073741824.0 }[$Matches[2]]
                $samples += @{ at = [DateTime]::UtcNow.ToString('o'); cpu_percent = $cpu; memory_mib = [Math]::Round($value * $factor / 1048576, 3) }
            }
        }
        Start-Sleep -Seconds 1
    } while ([DateTime]::UtcNow -lt $deadline)
    if ($samples.Count -eq 0) { throw 'No resource samples.' }
    $summary = @{ phase = $Phase; samples = $samples.Count; window_seconds = $Seconds;
        memory_peak_mib = ($samples | ForEach-Object { $_.memory_mib } | Measure-Object -Maximum).Maximum;
        cpu_peak_percent = ($samples | ForEach-Object { $_.cpu_percent } | Measure-Object -Maximum).Maximum;
        cpu_average_percent = [Math]::Round(($samples | ForEach-Object { $_.cpu_percent } | Measure-Object -Average).Average, 3) }
    $directory = Join-Path $repo 'var\whatsapp-app\measurements'
    New-Item -ItemType Directory -Path $directory -Force | Out-Null
    $record = @{ summary = $summary; samples = $samples }
    [IO.File]::WriteAllText((Join-Path $directory ($Phase + '-' + [DateTime]::UtcNow.ToString('yyyyMMdd-HHmmss') + '.json')), ($record | ConvertTo-Json -Depth 5), (New-Object Text.UTF8Encoding($false)))
    $summary | ConvertTo-Json -Compress
} catch {
    Write-Output ('resource_measurement_failed; exception=' + $_.Exception.GetType().Name)
    exit 1
}
