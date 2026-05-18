# Cortex feedback wrapper - zabiljezi ocjenu korisnosti rasprave.
#   cortex-feedback <chatId> <rating 1-5> [--note="..."] [--ideas="a,b"] [--json]
$artisan = Join-Path (Split-Path -Parent $PSScriptRoot) 'artisan'

if (-not (Test-Path $artisan)) {
    Write-Error "Cortex: 'artisan' nije pronaden ($artisan)."
    exit 1
}

& php $artisan cortex:feedback @args
exit $LASTEXITCODE
