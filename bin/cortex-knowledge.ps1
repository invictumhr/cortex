# Cortex knowledge wrapper - prikazi ili obnovi globalnu memoriju.
#   cortex-knowledge             prikaz digesta i zadnjih zabiljeski
#   cortex-knowledge --rebuild   regeneriraj konsolidirani digest
#   cortex-knowledge --json      strojno-citljiv izlaz
$artisan = Join-Path (Split-Path -Parent $PSScriptRoot) 'artisan'

if (-not (Test-Path $artisan)) {
    Write-Error "Cortex: 'artisan' nije pronaden ($artisan)."
    exit 1
}

& php $artisan cortex:knowledge @args
exit $LASTEXITCODE
