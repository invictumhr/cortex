<#
  Cortex CLI - vijece AI strucnjaka.
  Pokrece raspravu vise AI persona o zadanoj temi iz bilo kojeg direktorija.
  Stavi mapu bin/ u PATH, pa zovi:  cortex "<tema>" [-Personas a,b] [-Rounds N]
#>
[CmdletBinding()]
param(
    [Parameter(Position = 0)]
    [string]$Topic,
    [string[]]$Personas,
    [int]$Rounds = 0,
    [int]$Scribe = 0,
    [int]$Chat = 0,
    [string]$Title,
    [switch]$Fast,
    [switch]$Memory,
    [switch]$Json,
    [switch]$Help
)

$artisan = Join-Path (Split-Path -Parent $PSScriptRoot) 'artisan'

if (-not (Test-Path $artisan)) {
    Write-Error "Cortex: 'artisan' nije pronaden ($artisan)."
    exit 1
}

if ($Help -or -not $Topic) {
    if ($Json) {
        $schema = [ordered]@{
            tool          = 'cortex'
            description   = 'Vijece AI strucnjaka: pokrece raspravu vise AI persona (svaka na svom modelu) o zadanoj temi i vraca strukturiranu sintezu.'
            usage         = 'cortex "<tema>" [-Personas a,b,c] [-Rounds N] [-Scribe N] [-Fast] [-Memory] [-Title "..."] [-Chat ID] [-Json]'
            parameters    = [ordered]@{
                Topic    = 'Tema/pitanje boardroomu (prvi pozicijski argument). Obavezno za pokretanje.'
                Personas = 'Slugovi persona (zarezom ili razmakom). Izostavljeno => bira 5 automatski.'
                Rounds   = 'Broj krugova rasprave 1-200 (default 2).'
                Scribe   = 'Prag poruka za scribe sazetak (default 50).'
                Fast     = 'Brzi nacin: 1 krug, bez scribe sazetka.'
                Memory   = 'Ubaci akumulirano globalno znanje u kontekst rasprave.'
                Title    = 'Naslov rasprave.'
                Chat     = 'ID postojece rasprave za nastavak (produbljivanje).'
                Json     = 'Strojno-citljiv JSON izlaz.'
            }
            persona_slugs = @('marco','luna','viktor','helena','sophia','ana','kai','petra','rex','zara','miro','frida','oscar','nikola','ada','darwin','hawking','max','kira','chen','dragan','iris','yuki','leo','tesla','mara','ghost','rosa','pixel')
            output_fields = @('ok','version','chat_id','cost_eur','speakers','turn_messages','scribe_summary','scribe')
            related       = @('cortex-feedback <chat> <1-5> - ocjena korisnosti rasprave','cortex-knowledge [--rebuild] - globalna memorija')
            example       = 'cortex "Kako optimizirati checkout?" -Personas marco,helena,kira -Rounds 2 -Json'
            notes         = 'Sinkrono, blokira ~60-180s. Treba MySQL i Redis. Exit 0 = ok, 1 = greska.'
        }
        $schema | ConvertTo-Json -Depth 5 -Compress
    }
    else {
        Write-Output @'

  CORTEX - vijece AI strucnjaka
  Pokrece raspravu vise AI persona o zadanoj temi i vraca strukturiranu sintezu.

  KORISTENJE
    cortex "<tema>" [opcije]

  OPCIJE
    -Personas a,b,c   Strucnjaci u panelu (izostavljeno => bira 5 automatski)
    -Rounds N         Krugova rasprave: 1 brzo, 2-3 temeljito (default 2)
    -Scribe N         Prag za scribe sazetak (default 50; stavi 8 za kratke)
    -Fast             Brzi nacin: 1 krug, bez scribe sazetka
    -Memory           Ubaci akumulirano znanje u kontekst rasprave
    -Title "..."      Naslov rasprave
    -Chat ID          Nastavi postojecu raspravu (produbljivanje)
    -Json             Strojno-citljiv JSON izlaz (za druge agente)

  PRIMJERI
    cortex "Kako optimizirati checkout na webshopu?"
    cortex "..." -Personas marco,helena,chen,kira,petra -Rounds 2 -Json
    cortex "Brzo misljenje?" -Personas marco -Fast

  SRODNE NAREDBE
    cortex-feedback <chat> <1-5>   Ocijeni korisnost rasprave
    cortex-knowledge [--rebuild]   Globalna memorija (akumulirano znanje)
'@
        & php $artisan cortex:personas
        Write-Output @'
  Strojni opis parametara (za agente):  cortex -Json

'@
    }
    exit 0
}

$cliArgs = @($artisan, 'cortex:discuss', $Topic)
if ($Personas)     { $cliArgs += "--personas=$($Personas -join ',')" }
if ($Rounds -gt 0) { $cliArgs += "--rounds=$Rounds" }
if ($Scribe -gt 0) { $cliArgs += "--scribe=$Scribe" }
if ($Chat -gt 0)   { $cliArgs += "--chat=$Chat" }
if ($Title)        { $cliArgs += "--title=$Title" }
if ($Fast)         { $cliArgs += '--fast' }
if ($Memory)       { $cliArgs += '--memory' }
if ($Json)         { $cliArgs += '--json' }

& php @cliArgs
exit $LASTEXITCODE
