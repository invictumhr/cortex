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
    [string]$Context,
    [string]$Constraints,
    [string]$Language = '',
    [switch]$Architect,
    [switch]$Strong,
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
            usage         = 'cortex "<tema>" [-Personas a,b,c] [-Rounds N] [-Context file] [-Constraints "..."] [-Fast] [-Memory] [-Title "..."] [-Chat ID] [-Json]'
            parameters    = [ordered]@{
                Topic    = 'Tema/pitanje boardroomu (prvi pozicijski argument). Obavezno za pokretanje.'
                Personas = 'Slugovi persona (zarezom ili razmakom). Izostavljeno => bira 5 automatski.'
                Rounds   = 'Broj krugova rasprave 1-200 (default 2).'
                Scribe   = 'Prag poruka za scribe sazetak (default 50).'
                Fast     = 'Brzi nacin: 1 krug, bez scribe sazetka.'
                Memory   = 'Ubaci akumulirano globalno znanje u kontekst rasprave.'
                Context  = 'Putanja do datoteke s kontekstom (data model, postojece stanje).'
                Constraints = 'Tvrda ogranicenja koja sve persone moraju postovati.'
                Architect = 'Model dizajnira panel uloga skrojen za pitanje umjesto fiksnih persona.'
                Strong   = 'Panel trci na flagship modelima svakog providera (skuplje, jace).'
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
    -Context <file>   Prilozi kontekst personama (data model, postojece stanje)
    -Constraints "."  Tvrda ogranicenja koja nijedan prijedlog ne smije krsiti
    -Architect        Model dizajnira panel uloga skrojen za pitanje
    -Strong           Panel na flagship modelima svakog providera (skuplje, jace)
    -Language <iso>   Jezik rasprave (ISO 639-1, default en; en hr sr bs sl sk cs pl bg ru uk de fr it es pt nl ro hu sv el da fi)
    -Title "..."      Naslov rasprave
    -Chat ID          Nastavi postojecu raspravu (produbljivanje)
    -Json             Strojno-citljiv JSON izlaz (za druge agente)

  PRIMJERI
    cortex "Kako optimizirati checkout na webshopu?"
    cortex "..." -Personas marco,helena,chen,kira,petra -Rounds 2 -Json
    cortex "Brzo misljenje?" -Personas marco -Fast

  SRODNE NAREDBE
    cortex-feedback <chat> <1-5>   Ocijeni korisnost rasprave
    cortex-benchmark "<tema>"      Usporedi boardroom protiv jednog jakog modela
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
if ($Context)      { $cliArgs += "--context=$Context" }
if ($Constraints)  { $cliArgs += "--constraints=$Constraints" }
if ($Language)     { $cliArgs += "--language=$Language" }
if ($Architect)    { $cliArgs += '--architect' }
if ($Strong)       { $cliArgs += '--strong' }
if ($Fast)         { $cliArgs += '--fast' }
if ($Memory)       { $cliArgs += '--memory' }
if ($Json)         { $cliArgs += '--json' }

& php @cliArgs
exit $LASTEXITCODE
