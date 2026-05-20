<#
  Cortex auto-start - ceka Laragon servise (MySQL + Redis) pa digne stack na 8888.
  Pokrece se automatski iz Windows Startup mape pri prijavi; ne treba ga rucno zvati.
#>
$ErrorActionPreference = 'Continue'

function Test-Tcp {
    param([int]$Port)
    try {
        $client = New-Object System.Net.Sockets.TcpClient
        $client.Connect('127.0.0.1', $Port)
        $client.Close()
        return $true
    }
    catch { return $false }
}

function Wait-Tcp {
    param([string]$Name, [int]$Port, [int]$TimeoutSeconds = 180)
    $deadline = (Get-Date).AddSeconds($TimeoutSeconds)
    while ((Get-Date) -lt $deadline) {
        if (Test-Tcp $Port) {
            Write-Output "[cortex-autostart] $Name (port $Port) spreman."
            return $true
        }
        Start-Sleep -Seconds 3
    }
    return $false
}

if (Test-Tcp 8888) {
    Write-Output '[cortex-autostart] Cortex vec radi na http://127.0.0.1:8888 - nista za napraviti.'
    Start-Sleep -Seconds 4
    exit 0
}

# Best-effort: digni Laragon ako MySQL nije gore.
$laragon = 'D:\laragon\laragon.exe'
if ((-not (Test-Tcp 3306)) -and (Test-Path $laragon)) {
    Write-Output '[cortex-autostart] Pokrecem Laragon...'
    Start-Process $laragon -ArgumentList 'start'
}

Write-Output '[cortex-autostart] Cekam MySQL i Redis...'
$dbOk = Wait-Tcp 'MySQL' 3306
$redisOk = Wait-Tcp 'Redis' 6379

if (-not ($dbOk -and $redisOk)) {
    Write-Warning '[cortex-autostart] Laragon servisi (MySQL/Redis) nisu se digli. Pokreni Laragon rucno pa zovni cortex-serve.'
    Start-Sleep -Seconds 15
    exit 1
}

Write-Output '[cortex-autostart] Servisi spremni - dizem Cortex stack.'
& (Join-Path $PSScriptRoot 'cortex-serve.ps1')
