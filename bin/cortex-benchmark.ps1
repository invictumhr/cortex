# Cortex benchmark wrapper - usporedi boardroom protiv jednog jakog modela.
#   cortex-benchmark "<tema>" [--rounds=N] [--personas=a,b] [--json]
$artisan = Join-Path (Split-Path -Parent $PSScriptRoot) 'artisan'

if (-not (Test-Path $artisan)) {
    Write-Error "Cortex: 'artisan' nije pronaden ($artisan)."
    exit 1
}

& php $artisan cortex:benchmark @args
exit $LASTEXITCODE
