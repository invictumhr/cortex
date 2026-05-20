# Cortex — AI boardroom platforma

Cortex je osobni alat za višeperspektivni brainstorming: korisnik zada temu, "boardroom"
AI persona (svaka na svom modelu) raspravlja je kroz krugove, *scribe* sažima, a *Chair*
donosi jednu odluku. Cilj je dobiti više-kutnu analizu koju jedan model ne bi dao.

## Stack

Laravel 12 (PHP 8.3, Laragon, Windows) · MySQL 8 · Redis (queue + cache) ·
Filament 5 admin (`/admin`) · Inertia + React (JSX) chat UI · Laravel Reverb (WebSockets) ·
Vite + Tailwind. 6 AI providera (Anthropic, OpenAI, xAI, Google, Mistral, DeepSeek).

## Pokretanje lokalno

- `cortex-serve` (PowerShell, `bin/` na PATH-u) → diže web + `queue:listen` + `reverb` na
  **http://127.0.0.1:8888**. Auto-start pri prijavi: `bin/cortex-autostart.ps1` preko Windows
  Startup mape (čeka MySQL/Redis, pa digne stack).
- Preduvjet: Laragon MySQL + Redis moraju raditi.
- Login: `admin@cortex.test` / `password` (`DatabaseSeeder`).
- Frontend nakon JSX izmjene: `npm run build` (built asseti u `public/build`).

## Kako rasprava teče (core flow)

1. **`ChatOrchestrator::sendUserMessage($chat,$user,$content)`** — sprema user poruku,
   `current_round=0`, status `active`, zove `startRound($chat,$turn,1)`.
2. **`startRound`** — postavi `current_round`, snapshota govornike (`activePersonas` koji
   nisu scribe/chair, po `sort_order`), dispatcha job `GeneratePersonaResponse(... position=0)`.
3. **`GeneratePersonaResponse`** (job, `app/Jobs/`) — per-persona, self-chaining: provjeri
   kill-switch i status → `PersonaResponder::respond()` u try/catch (greška → vidljiva
   `system` poruka) → budget guard (pauza ako `total_cost >= budget_limit`) → ako ima još
   persona u krugu dispatcha sljedeću poziciju; inače je krug gotov → `RoundCompleted`,
   scribe, pa sljedeći krug ili `TurnCompleted`.
4. **`PersonaResponder::respond`** — `modelFor()` (model persone, ili flagship ako je
   `chat->strong`) → `ContextBuilder::build` → zove model preko adaptera. **Drop-out
   recovery:** ako primarni model padne ili vrati prazno → fallback model
   (`cortex.fallback_model`); ako i fallback padne → exception (job upiše system poruku).
5. **Zadnji krug turna** → `ScribeService::summarize($chat, final:true)` (kumulativna
   sinteza) + `ChairService::decide($chat)` (jedna odluka).

CLI tjera `queue.default=sync` (rasprava teče inline). Web koristi pravi Redis queue +
Reverb (zato `queue:listen` i `reverb` u `cortex-serve`).

**Kontinuirani mod (web):** chatovi stvoreni kroz web imaju `continuous=true` — petlja se
vrti krug-za-krugom dok je `status=active`, bez `rounds_per_turn` granice. Korisnik je
kontrolira pauzom/playom i može poslati poruku bilo kad (ubaci se u tekuću raspravu).
`Chats/Show` šalje *heartbeat* (`cortex:heartbeat:{id}` cache, ~20 s); kad heartbeat
istekne (korisnik napusti stranicu) job sam pauzira raspravu — plus instant `sendBeacon`
na `chats/{id}/leave`. Eksplicitna pauza pokreće `ConcludeDiscussion` job (finalna sinteza
+ Chair). CLI chatovi ostaju `continuous=false` (omeđeni s `rounds_per_turn`).

## Ključne komponente (`app/Services/Chat/`, `app/Jobs/`)

- **`ChatOrchestrator`** — `sendUserMessage`, `startRound`, `pause`/`resume`, `addRounds`.
- **`GeneratePersonaResponse`** — job opisan gore.
- **`PersonaResponder`** — jedan doprinos persone; `modelFor()` (strong-mode flagship
  override), fallback model, `recordUsage`.
- **`ContextBuilder`** — gradi system prompt + transcript po personi: pinnan blok
  KONTEKST/OGRANIČENJA, scribe sažetak, zadnjih N poruka. 1. krug je **nezavisan** (persona
  ne vidi tuđe poruke); krug 2+ → epistemičko forsiranje neslaganja; zadnji krug rasprave
  od 3+ kruga → konvergencija. Sadrži i anti-konfabulacijska pravila.
- **`ScribeService`** — interim sažeci (svakih `scribe_round_interval` krugova ili
  `scribe_interval` poruka) + **garantirana finalna kumulativna sinteza** na kraju turna.
  Izlaz je strukturirani JSON (summary, key_ideas — svaka ideja je objekt
  `{idea, contributing_personas}` s atribucijom persona, key_decisions, open_questions,
  action_items, assumptions_to_validate, durable_insights) + PRIORITETNA MATRICA.
- **`ChairService`** — nakon rasprave forsira JEDNU preporuku: ODLUKA / RAZLOG /
  NAJVEĆI TRADE-OFF / PRVI KORAK.
- **`PanelArchitect`** — generira *ephemeral* persone (uloge skrojene za temu) umjesto
  fiksnog rostera.
- **`KnowledgeService`** — globalna memorija; scribe ubacuje `durable_insights`.
- **`UsageGuard`** — rate/budget limiti.
- **`CostEstimator`** — pre-flight procjena troška rasprave: medijana povijesne potrošnje
  tokena × cijene modela panela (strong-aware).
- **`LanguageDetector`** (`app/Services/`) — `fromIso(string $code)` mapira ISO 639-1 kod
  u puno englesko ime (`'fr'` → `'French'`) koje se interpolira u prompte. Podržano je 23
  jezika (`LanguageDetector::SUPPORTED`): en, hr, sr, bs, sl, sk, cs, pl, bg, ru, uk, de,
  fr, it, es, pt, nl, ro, hu, sv, el, da, fi. `chats.language` se postavlja **eksplicitno**
  na stvaranju: CLI iz `--language` opcije (default `en`), GUI iz trenutnog UI jezika
  (`LanguageToggle` — trenutno HR/EN). Direktiva o jeziku u ContextBuilder/Scribe/Chair/
  Architect promptima čita `$chat->language` i nadjačava sve jezične preferencije zapečene
  u personine system_prompte.

## AI provideri (`app/Services/Ai/`)

- `AiProviderInterface` + `AbstractAdapter`. Adapteri: `AnthropicAdapter`,
  `OpenAiCompatibleAdapter` (baza za OpenAI/xAI/Mistral/DeepSeek), `GoogleAdapter`.
  `AiProviderFactory::for(AiModel)` vraća adapter.
- API ključevi u `.env` s **`CORTEX_` prefiksom** (izbjegava koliziju s ambient praznim
  `ANTHROPIC_API_KEY`); seedani u `ai_providers.api_key`, kriptirani (`encrypted` cast).
- **Gotchas po modelu:** Opus 4.7 odbija `temperature` (AnthropicAdapter ga preskače za
  `claude-opus-4-7`); Gemini Flash troši izlaz na "thinking" → adapter šalje
  `thinkingConfig.thinkingBudget=0`; OpenAI o-serija koristi `max_completion_tokens` i bez
  temperature.

## Persone

- ~30 fiksnih persona (`database/seeders/PersonaSeeder.php`): slug, name, title,
  `system_prompt`, `expertise_areas`, `ai_model_id`. Mapiranje persona→model je u
  **`PersonaModelSeeder`** (autoritativan, override-a PersonaSeeder).
- Zastavice na `personas`: `is_scribe` (Scribe — sažima, ne glasa), `is_chair` (Chair —
  završna odluka), `is_ephemeral` (architect-generirane uloge — skrivene iz rostera).
- **Odabir panela** (`cortex:discuss`): `--personas` ručno | `--architect` (PanelArchitect
  generira uloge) | default = *router* (jeftin model klasificira temu, bira 5 po domeni;
  Realist persona uvijek pinnan).

## CLI (`bin/` wrapperi + `app/Console/Commands/`)

- `cortex "<tema>"` (= `cortex:discuss`) — flagovi: `-Personas a,b`, `-Rounds N`,
  `-Context <datoteka>`, `-Constraints "..."`, `-Language en|hr` (default `en`),
  `-Architect`, `-Strong`, `-Fast`, `-Memory`, `-Chat <id>`, `-Json`.
  Bez argumenata → help + roster.
- `cortex:discuss` prije pokretanja ispiše **procjenu troška** (`CostEstimator`), a tijekom
  rasprave **streama poruke uživo** preko listenera na `ChatMessageCreated`/`PersonaIsTyping`
  umjesto tišine pa dumpa na kraju.
- `cortex-feedback <chat> <1-5>` (`--used` = stvarno implementirane ideje),
  `cortex-benchmark "<tema>"` (boardroom vs jedan jak model + slijepi ocjenjivač),
  `cortex-knowledge`, `cortex:personas`, `cortex:prune` (briše orphane ephemeral persone),
  `cortex:smoke-test`, `cortex:ps-executor`.
- **`bin/*.ps1` moraju biti ASCII-only** — PowerShell 5.1 čita UTF-8-bez-BOM kao ANSI pa
  dijakritike/em-dash puknu. (PHP izlaz u konzolu smije biti UTF-8.)

## Web UI (`resources/js/Pages/Chats/`, `app/Http/Controllers/`)

- `Chats/Index` — popis chatova + forma „Novi boardroom" (naslov, opis, kontekst,
  ograničenja, krugovi, Architect/Strong checkbox, ručni persona picker).
- `Chats/Show` — kontinuirana rasprava uživo (Reverb stream); jedina kontrola je
  pauza/play, poruka se šalje bilo kad, heartbeat gasi raspravu na izlazu.
- **i18n** (`resources/js/i18n/`) — HR/EN UI translations (`translations.js`),
  `I18nProvider` + `useT()` hook, `LanguageToggle` komponenta. Prefencija u
  `localStorage.cortex.lang`, inicijalno iz `navigator.language`. UI prevodi neovisno
  od jezika rasprave (`chats.language`, auto-detektirano iz korisnikove prve poruke).
- `ChatController` (`index`/`show`/`store`). Discussion-quality (Chair, scribe, drop-out,
  epistemičko forsiranje) dijeli **isti pipeline** kao CLI.

## DB (ključne tablice)

- `chats` — `rounds_per_turn`, `continuous` (web=kontinuirano, CLI=omeđeno), `language`
  (`Croatian`/`English`, postavljeno eksplicitno: CLI `--language`, GUI iz `LanguageToggle`),
  `current_round`, `scribe_interval`, `status`, `context`, `constraints`, `strong`,
  `total_messages/input_tokens/output_tokens/cost`.
- `personas` — `is_scribe`, `is_chair`, `is_ephemeral`, `ai_model_id`, `system_prompt`.
- `chat_messages` — `role` (user/persona/scribe/system), `round_number`/`turn_number`,
  `model_used`, `cost`, `metadata` (npr. `chair_decision`, `fallback_from`).
- `chat_personas` (pivot), `scribe_summaries`, `chat_feedback` (rating, helpful_ideas,
  used_ideas), `ai_providers`, `ai_models`, `chat_attachments`, `powershell_*`.

## Config — `config/cortex.php`

`api_version`, `context_message_limit`, `persona_max_tokens`/`persona_temperature`,
`scribe_max_tokens`, `default_scribe_interval` + `scribe_round_interval`, `budget_limit` +
`daily_budget_limit`, `fallback_model`, `router_model`, `flagship_models` (strong mode),
`architect_model` + `architect_panel_models`, `benchmark_control_model` +
`benchmark_evaluator_model`. Sve podesivo i preko `CORTEX_*` env varijabli.

## Konvencije / gotchas

- `deliberation_language=English` (persone raspravljaju EN — token-efikasnije),
  `output_language=Croatian` (scribe i Chair pišu HR).
- `--fast` gasi scribe/chair sentinelom `scribe_interval=1000000`.
- Ephemeral persone se nikad ne pojavljuju u rosteru/routeru; `cortex:prune` briše samo
  istinske orphane (bez poruka **i** bez chata).
- Promjene se provjeravaju živo: `php artisan cortex:discuss "..." --json` (CLI) ili kroz
  web na 8888. Migracije: `php artisan migrate`; nakon izmjene seedera —
  `php artisan db:seed --class=...`.
- Nakon izmjene `.env`/configa: `php artisan config:clear`.
