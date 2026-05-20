<#
  Cortex local stack - web + queue worker + reverb, all driven from one window.
  Stavi bin/ na PATH pa pokreni iz bilo kojeg direktorija:  cortex-serve
  Web: http://127.0.0.1:8888   Ctrl+C zaustavlja sva tri procesa.
#>
$root = Split-Path -Parent $PSScriptRoot
Set-Location $root

if (-not (Test-Path (Join-Path $root 'artisan'))) {
    Write-Error "Cortex: 'artisan' nije pronaden u $root."
    exit 1
}

Write-Output ''
Write-Output '  Cortex lokalno:   http://127.0.0.1:8888'
Write-Output '  Admin (Filament): http://127.0.0.1:8888/admin'
Write-Output '  Ctrl+C zaustavlja web + queue + reverb.'
Write-Output ''

npx concurrently -c "#93c5fd,#fb7185,#fdba74" --names=web,queue,reverb `
  "php artisan serve --host=127.0.0.1 --port=8888" `
  "php artisan queue:listen --tries=1" `
  "php artisan reverb:start"

exit $LASTEXITCODE
