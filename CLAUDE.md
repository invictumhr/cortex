# Cortex — AI boardroom SaaS

Cortex je prepaid SaaS za višeperspektivni AI brainstorming: korisnik zada temu,
"boardroom" AI persona (svaka na svom modelu) raspravlja je kroz krugove,
*Scribe* sažima, *Chair* donosi jednu odluku. Naplata ide po stvarno potrošenim
tokenima — svaka persona, Scribe i Chair rade pre-flight rezervaciju iz user
walleta, a poslije se commit-a stvarni trošak × tier margin. Top-up je preko
SMS-isporučenih 14-digit PIN-ova.

## Stack

Laravel 12 (PHP 8.3, Laragon, Windows) · MySQL 8 · Redis (queue + cache) ·
**Filament 5.6** s dva panela: `/admin` (super-admin) i `/user` (customer) ·
Inertia + React (JSX) chat UI · Laravel Reverb (WebSockets) · Vite + Tailwind
(custom design system, dark mode) · 6 AI providera (Anthropic, OpenAI, xAI,
Google, Mistral, DeepSeek).

## Pokretanje lokalno

- `cortex-serve` (PowerShell, `bin/` na PATH-u) → diže web + `queue:listen` +
  `reverb` na **http://127.0.0.1:8888**. Auto-start preko `bin/cortex-autostart.ps1`
  u Windows Startup mapi (čeka MySQL/Redis pa digne stack).
- Preduvjet: Laragon MySQL + Redis moraju raditi.
- **Login admin:** `admin@cortex.test` / `password` (seedan s `is_admin=true` i
  `email_verified_at=now()` — pristupa `/admin` Filament panelu).
- Frontend nakon JSX izmjene: `npm run build` (built asseti u `public/build`).
- Testovi: `php artisan test` — phpunit.xml forsira sqlite `:memory:` da
  `RefreshDatabase` NE briše dev DB.
- Cron za reconciliation: u produkciji treba Linux cron / Windows Task Scheduler
  koji svake minute pokreće `php artisan schedule:run` (inače dnevni
  `cortex:wallet-reconcile` nikad ne okine).

## Multi-user lifecycle (bird's-eye)

```
Anonymous
   │
   ▼ POST /register (Breeze)
Registered (unverified)         ← IP hash + UA hash sprema se za anti-farming
   │  Auth::login()
   ▼ middleware('verified') → redirect /verify-email
Pending verification             ← email link iz Laravel notification
   │  click link → Verified event
Verified user                    ← GrantSignupCredit listener daje €0.50 grant
   │
   ▼
Active user
   ├─ /chats (Inertia)           ← chat UI; svaki send proxy preko WalletService
   ├─ /user  (Filament)          ← profile, wallet, PIN redeem, API tokens
   ├─ POST /api/v1/discuss       ← Bearer ctx_… token, per-token rate limit
   │
   ├─ Redeem PIN → DEPOSIT €5+   ← prvi takav trigger-a +€1 first-deposit bonus
   └─ Account → Delete           ← cascade-delete preko FK
```

Sve mutacije balansa idu kroz `WalletService` (atomic `SELECT ... FOR UPDATE`
+ event-sourced ledger). Daily cron `cortex:wallet-reconcile` provjerava
invariante (vidi sekciju "Billing").

## Kako rasprava teče (core flow)

1. **`ChatOrchestrator::sendUserMessage($chat,$user,$content)`** — sprema user
   poruku, `current_round=0`, status `active`, zove `startRound($chat,$turn,1)`.
2. **`startRound`** — postavi `current_round`, snapshota govornike
   (`activePersonas` koji nisu scribe/chair, po `sort_order`), dispatcha job
   `GeneratePersonaResponse(... position=0)`. **No-speakers branch** piše system
   message + `TurnCompleted` umjesto da padne tiho.
3. **`GeneratePersonaResponse`** (job, `app/Jobs/`) — per-persona self-chaining:
   provjeri kill-switch i status → `PersonaResponder::respond()` u try/catch
   (greška → vidljiva `system` poruka) → budget guard (pauza ako
   `total_cost >= budget_limit`) → ako ima još persona u krugu dispatcha sljedeću
   poziciju; inače je krug gotov → `RoundCompleted`, Scribe, pa sljedeći krug ili
   `TurnCompleted`. **Hardened**: silent return na status≠active zamijenjen
   `reportUnexpectedPause()` (log warning + system message + broadcast) za bounded
   CLI runs; continuous web ostaje tih (legitimate user-left).
4. **`PersonaResponder::respond`** — `modelFor()` (model persone, ili flagship
   ako `chat->strong`) → **wallet `reserve()`** (pre-flight hold) → `ContextBuilder::build`
   → zove model preko adaptera → ako `is_billable` → **wallet `commitDebit()`**;
   inače `release()`. **Drop-out recovery:** ako primarni model padne ili vrati
   prazno → fallback model (`cortex.fallback_model`); ako i fallback padne →
   exception (job upiše system poruku).
5. **Zadnji krug turna** → `ScribeService::summarize($chat, final:true)`
   (kumulativna sinteza) + `ChairService::decide($chat)` (jedna odluka). I Scribe
   i Chair rade reserve/commit/release ciklus kroz isti `WalletService`.

CLI tjera `queue.default=sync` (rasprava teče inline). Web koristi pravi Redis
queue + Reverb (zato `queue:listen` i `reverb` u `cortex-serve`). API
(`POST /api/v1/discuss`) interno forsira sync da vrati cijeli rezultat u response.

**Kontinuirani mod (web):** chatovi stvoreni kroz web imaju `continuous=true` —
petlja se vrti krug-za-krugom dok je `status=active`, bez `rounds_per_turn`
granice. Korisnik je kontrolira pauzom/playom; `Chats/Show` šalje *heartbeat*
(`cortex:heartbeat:{id}` cache, ~20 s) — kad istekne, job sam pauzira raspravu.
Plus instant `sendBeacon` na `chats/{id}/leave`. Eksplicitna pauza pokreće
`ConcludeDiscussion` job (finalna sinteza + Chair). CLI chatovi ostaju
`continuous=false` (omeđeni `rounds_per_turn`).

## Ključne komponente (`app/Services/Chat/`, `app/Jobs/`)

- **`ChatOrchestrator`** — `sendUserMessage`, `startRound`, `pause`/`resume`, `addRounds`.
- **`GeneratePersonaResponse`** — job opisan gore + `reportUnexpectedPause()` +
  `failed()` handler koji piše system message u chat.
- **`PersonaResponder`** — jedan doprinos persone: estimate → reserve → call →
  commitDebit/release, sa snapshot pricing rate-ovima i tier margin
  (`marginMultiplierFor()`). Stampa `wallet_transaction_id`, `provider_cost`,
  `user_cost`, `is_billable`, `finish_reason` na `chat_messages`.
- **`ContextBuilder`** — gradi system prompt + transcript po personi: pinnan
  blok KONTEKST/OGRANIČENJA, scribe sažetak, zadnjih N poruka. 1. krug je
  **nezavisan** (persona ne vidi tuđe poruke); krug 2+ → epistemičko forsiranje
  neslaganja; zadnji krug rasprave od 3+ kruga → konvergencija. Anti-konfabulacija pravila.
- **`ScribeService`** — interim sažeci (svakih `scribe_round_interval` krugova
  ili `scribe_interval` poruka) + **garantirana finalna kumulativna sinteza** na
  kraju turna. Izlaz strukturirani JSON (summary, key_ideas — objekt
  `{idea, contributing_personas}` s atribucijom persona, key_decisions,
  open_questions, action_items, assumptions_to_validate, durable_insights) +
  PRIORITETNA MATRICA. Bill-an kroz wallet.
- **`ChairService`** — nakon rasprave forsira JEDNU preporuku: ODLUKA / RAZLOG /
  NAJVEĆI TRADE-OFF / PRVI KORAK. Bill-an kroz wallet.
- **`PanelArchitect`** — generira *ephemeral* persone (uloge skrojene za temu)
  umjesto fiksnog rostera.
- **`KnowledgeService`** — globalna memorija; scribe ubacuje `durable_insights`.
  Anonimizirani — ostaju i nakon brisanja računa.
- **`UsageGuard`** — rate/budget limiti (legacy, dopuna walletu).
- **`CostEstimator`** — pre-flight procjena troška rasprave: medijana povijesne
  potrošnje tokena × cijene modela panela (strong-aware).
- **`LanguageDetector`** (`app/Services/`) — `fromIso(string $code)` mapira ISO
  639-1 kod u puno englesko ime (`'fr'` → `'French'`). Podržano 23 jezika
  (`LanguageDetector::SUPPORTED`): en, hr, sr, bs, sl, sk, cs, pl, bg, ru, uk,
  de, fr, it, es, pt, nl, ro, hu, sv, el, da, fi. `chats.language` se postavlja
  **eksplicitno** na kreaciji **i ažurira na svaku korisničku poruku** preko
  `ChatMessageController` (čita `language` field iz FormData → mapira → updejta).
  CLI: `--language` opcija; GUI: `LanguageToggle` (HR/EN, localStorage
  `cortex.lang`); API: `language` field u request body. Direktiva o jeziku u
  ContextBuilder/Scribe/Chair/Architect promptima čita `$chat->language` u
  realnom vremenu (nema cache) i nadjačava sve jezične preferencije zapečene u
  personine system_prompte.

## AI provideri (`app/Services/Ai/`)

- `AiProviderInterface` + `AbstractAdapter`. Adapteri: `AnthropicAdapter`,
  `OpenAiCompatibleAdapter` (baza za OpenAI/xAI/Mistral/DeepSeek), `GoogleAdapter`.
  `AiProviderFactory::for(AiModel)` vraća adapter.
- API ključevi u `.env` s **`CORTEX_` prefiksom** (izbjegava koliziju s ambient
  praznim `ANTHROPIC_API_KEY`); seedani u `ai_providers.api_key`, kriptirani
  (`encrypted` cast).
- **Gotchas po modelu:** Opus 4.7 odbija `temperature` (AnthropicAdapter ga
  preskače za `claude-opus-4-7`); Gemini Flash troši izlaz na "thinking" →
  adapter šalje `thinkingConfig.thinkingBudget=0`; OpenAI o-serija koristi
  `max_completion_tokens` i bez temperature.

## Persone

- ~30 fiksnih persona (`database/seeders/PersonaSeeder.php`): slug, name, title,
  `system_prompt`, `expertise_areas`, `ai_model_id`. Mapiranje persona→model je
  u **`PersonaModelSeeder`** (autoritativan, override-a PersonaSeeder).
- Zastavice na `personas`: `is_scribe` (Scribe — sažima, ne glasa), `is_chair`
  (Chair — završna odluka), `is_ephemeral` (architect-generirane uloge —
  skrivene iz rostera).
- **Odabir panela** (`cortex:discuss`): `--personas` ručno | `--architect`
  (PanelArchitect generira uloge) | default = *router* (jeftin model klasificira
  temu, bira 5 po domeni; Realist persona uvijek pinnan).

## Billing & Wallet (`app/Services/Billing/`)

Mental model:
- `balance` = spendable euros
- `reserved_balance` = held za in-flight runs
- **Total user funds** = `balance + reserved_balance`
- `availableBalance()` = `balance` (UI display)

**`WalletService`** — single mutation point. Sve metode rade pod
`DB::transaction()` s `SELECT ... FOR UPDATE` na `wallets` retku:
- `forUser($user)` — lazy create
- `reserve(wallet, estimatedUserCost, rateSnapshotModel, sourceType, sourceId, metadata)`
  → throw `InsufficientFundsException` ako balance manji od estimate
- `commitDebit(reserve, providerCost, actualUserCost, ...)` → vraća
  `{debit: WalletTransaction, leftover: ?WalletTransaction}` (leftover = RELEASE
  ako actual < reserved). **Poison pill**: ako `actualUserCost > reservedAmount × 1.3`,
  log warning ali debit ide kroz (margin-drift exposure je naša, ne korisnička).
- `release(reserve, reason)` — full rollback bez debita
- `deposit(wallet, amount, paymentSourceRef, metadata)` — **idempotent** po
  `payment_source_ref` (webhook redelivery safe)
- `grant(wallet, amount, reason, sourceType, sourceId)` — admin/signup/bonus
- `marginMultiplierFor(user)` — vraća `margin_hobby` (2.0) ili `margin_enterprise`
  (1.7) ako je 30-day spend `>= enterprise_threshold_eur` (100€)

**Ledger semantika** (`wallet_transactions` table):
| type | amount | reserved_delta | notes |
|---|---|---|---|
| GRANT/DEPOSIT/REFUND | +X | 0 | inflow do total funds |
| DEBIT (settling reserve) | -X | -X | held postaje spent |
| DEBIT (admin manual) | -X | 0 | rare |
| RESERVE | 0 | +X | zero-sum transfer balance → reserved |
| RELEASE | 0 | -X | zero-sum reserved → balance |
| ADJUSTMENT | ±X | 0 | admin override |

**Invariante** (enforced by `cortex:wallet-reconcile` daily):
- `balance + reserved_balance == sum(amount)` za sve transakcije walleta
- `reserved_balance == sum(reserved_delta)`

**Snapshot pricing**: pri `reserve()` se kopira AiModel-ova cijena per 1M
tokens (`rate_input_snapshot`, `rate_output_snapshot`, `provider_id`) — debit
uvijek koristi snapshotanu cijenu, ne live. Margin drift apsorbira Cortex.
TTL je u config (`rate_snapshot_ttl_hours`, default 6h — kraće od originalnih
24h iz panela jer smanjuje exposure).

**`is_billable` strict criteria** (postavlja PersonaResponder/Scribe/Chair):
true SAMO ako `output_tokens > 0 AND finish_reason ∉ ['content_filter', 'safety', 'blocked']`.
Sve drugo (prazan stream, refusal) → `release()` umjesto `commitDebit()`.

**Free credit logika** (Opcija B iz design-a):
1. Registracija → `Registered` event → user kreiran s IP/UA hash (BrowserController)
2. Email verified → `Verified` event → `GrantSignupCredit` listener:
   - Anti-farming: ako 3+ regs sa istim `registration_ip_hash` u zadnjih 7 dana →
     soft deny (stamp `free_credit_granted_at`, no grant)
   - Inače → `WalletService::grant($wallet, 0.50, 'signup bonus')`
3. Prvi `DEPOSIT` ≥ €5 (preko PIN-a ili Paddle-a) → `WalletTransactionObserver`
   automatski `grant($wallet, 1.00, 'first_deposit_bonus')`

## SMS PIN top-up (`app/Services/Billing/TopupCodeService.php`)

- **`topup_codes`** tablica: `code_hash` (SHA-256), `amount`, `batch_label`,
  `redeemed_at/by/ip_hash`, `wallet_transaction_id`, `metadata`, `created_by_user_id`
- **`TopupCodeService::generateBatch(count, eachAmount, batchLabel, createdBy)`** —
  do 10k kodova po batchu, collision retry (statistički nemoguća za 14-digit),
  vraća `[{model: TopupCode, plaintext: '12345678901234'}]` — **plaintext samo
  jednom**
- **`TopupCodeService::redeem(user, rawCode, request)`** — input normalizacija
  (dash-tolerant), per-IP rate limit 10/10min, per-user 20/h, atomic `SELECT FOR UPDATE`,
  idempotent ako isti user re-typa svoj kod, soft-fail "already used" za druge.
  Poziva `WalletService::deposit()` s `payment_source_ref = 'topup_code:{id}'`
- **CLI:** `php artisan cortex:topup-codes 50 5 --batch="SMS Q2"` → table s
  plaintext PIN-ovima (printano JEDNOM, ne može se vratiti)
- **Admin UI:** `/admin/topup-codes` (Filament resource) — lista + filter po
  statusu/batchu, **"Generate batch"** akcija s formom (count, amount, label) →
  plaintext PIN-ovi u persistent notifikaciji
- **User UI:** `/user/redeem-code` (Filament Page) — single 14-digit input,
  Croatian/English label-toleranti

## REST API (`routes/api.php` + `app/Http/Controllers/Api/`)

- **`POST /api/v1/discuss`** — pokreće boardroom za authenticated token-a:
  body `{topic, personas?[], rounds?, title?, context?, constraints?}`, vraća
  `{ok, chat_id, status, rounds, messages[], total_provider_cost_eur, total_user_cost_eur}`.
  Interno forsira `queue.default=sync` + `broadcasting.default=log` — vraća
  cijeli rezultat u response (V1 sync, async može doći u V2 s webhook callbackom).
- **`AuthenticateApiToken` middleware** (`api.token` alias):
  - Extract Bearer `ctx_…` → hash → lookup `api_tokens.token_hash`
  - Reject ako `revoked_at !== null`
  - Optional scope check (`api.token:cortex:discuss`)
  - Per-token rate limit: `per_day` 50, `per_hour` 10 (config `cortex.api_rate_limit`)
  - Stampa `api_token` na request attributes (controller čita za audit) i
    auth-ira ApiToken→user u request context
  - Updejta `last_used_at` na svakom uspješnom prolazu
- **Logging:** svaka API akcija piše red u `api_token_usages` (chat_id,
  endpoint, provider_cost, user_cost, response_time_ms, status enum
  `ok|rate_limited|insufficient_funds|error`)
- **`ApiToken::issue(user, label, scopes)`** factory u Modelu — generira
  `ctx_` + 48 random chars, vraća `[model, plaintext]`. Plaintext se prikazuje
  jednom (u Filament create page-u kroz persistent notifikaciju)

## Email verification gate

- **`User implements MustVerifyEmail`** — Laravel Breeze verified middleware
  aktivan na `auth` rutama
- **Routes:** `/chats/*`, `/profile`, `/dashboard` su pod `['auth', 'verified']`
  grupom — neverified user redirect na `/verify-email`
- **Filament:** `User::canAccessPanel()` blokira `/user` ako
  `email_verified_at === null` (admin panel preskače gate jer su admins
  internally-seeded)
- **`GrantSignupCredit` listener** vezan za **`Verified` event** (NE `Registered`)
  — throwaway signups koji nikad ne kliknu link nas ne koštaju € 0.50
- **`RegisteredUserController` hashira** IP i UA na `users.registration_ip_hash`
  i `registration_ua_hash` (SHA-256) — GDPR-compliant, koristi se za
  anti-farming check kasnije

## CLI (`bin/` wrapperi + `app/Console/Commands/`)

- `cortex "<tema>"` (= `cortex:discuss`) — flagovi: `-Personas a,b`, `-Rounds N`,
  `-Context <datoteka>`, `-Constraints "..."`, `-Language en|hr` (default `en`),
  `-Architect`, `-Strong`, `-Fast`, `-Memory`, `-Chat <id>`, `-Json`.
  Bez argumenata → help + roster. **Detektira incomplete runs** i vraća
  `self::FAILURE` umjesto lažnog success-a.
- `cortex:discuss` prije pokretanja ispiše **procjenu troška** (`CostEstimator`),
  a tijekom rasprave **streama poruke uživo** preko listenera na
  `ChatMessageCreated`/`PersonaIsTyping` umjesto tišine pa dumpa na kraju.
- `cortex:topup-codes <count> <amount> --batch="..." [--json]` — batch generator
  PIN-ova, plaintext printan jednom.
- `cortex:wallet-reconcile [--tolerance=0.01]` — daily integrity check; exit
  `FAILURE` s tablicom drift-a ako razlika premaši tolerance.
- `cortex-feedback <chat> <1-5>` (`--used` = stvarno implementirane ideje),
  `cortex-benchmark "<tema>"` (boardroom vs jedan jak model + slijepi ocjenjivač),
  `cortex-knowledge`, `cortex:personas`, `cortex:prune` (briše orphane ephemeral
  persone), `cortex:smoke-test`, `cortex:ps-executor`.
- **`bin/*.ps1` moraju biti ASCII-only** — PowerShell 5.1 čita UTF-8-bez-BOM
  kao ANSI pa dijakritike/em-dash puknu. (PHP izlaz u konzolu smije biti UTF-8.)

## Web UI (chat — `resources/js/Pages/Chats/`)

- **`Chats/Index`** — moderni welcome screen: centered "Welcome back, X." hero,
  composer-shaped form s naslovom + opisom, mode chips (Architect/Strong),
  3-col persona tiles s color-tinted avatars, "Recent" sekcija ispod top 5
  chatova. Start button postaje **"Top up to start"** (link na `/user/redeem-code`)
  ako je wallet prazan.
- **`Chats/Show`** — kontinuirana rasprava uživo (Reverb stream):
  - Sticky header s pulsing status pill (Active/Paused), round counter, kumulativni €, msg count
  - `BalanceBanner` iznad poruka (3-state)
  - Centered `max-w-3xl` message column s `animate-fade-up` entry
  - Soft-pulse 3-dot "is thinking" indicator s persona name + round
  - Composer (`ChatInputBar`) s auto-grow textarea, attachment chips, send arrow + spinner
  - Persona drawer (`PersonaInfoPanel`) klizi s desne strane (Personas button u header)
  - **Slanje poruke** šalje `language` field iz UI toggle-a → backend re-aligna
    `chat->language` real-time (sljedeća persona u novom jeziku)
- **i18n** (`resources/js/i18n/`) — HR/EN UI translations (`translations.js`),
  `I18nProvider` + `useT()` hook, `LanguageToggle` komponenta. Preferencija u
  `localStorage.cortex.lang`, inicijalno iz `navigator.language`.
- **`ChatController`** (`index`/`show`/`store`). Discussion-quality (Chair, scribe,
  drop-out, epistemičko forsiranje) dijeli **isti pipeline** kao CLI.
- **`ChatMessageController::store`** — pre-flight balance check, vraća
  **HTTP 402** s `{error, message, available, topup_url}` ako wallet ispod
  `min_send_balance` (€0.05). Updejta `chat->language` ako se promijenio.

## Filament paneli

**Dva panela dijele `web` guard.** `User::canAccessPanel(Panel $panel)` gate-a
po panel ID-u: `admin` → `is_admin=true`, `user` → `!is_admin && email_verified`.

### `/admin` (AdminPanelProvider) — super-admin

- **Widgets** (`app/Filament/Widgets/`):
  - `MarginOverviewWidget` (4 KPI cards: provider cost / user billings / margin% / paying users — 30d)
  - `CostOverTimeChart` (30-day line: provider cost vs user billings)
  - `ProviderCostBreakdownWidget` (Blade — Eloquent GROUP BY trips MySQL
    `only_full_group_by` zbog Filament auto-secondary-sort, pa rendered direktno)
  - `TopSpendersWidget` (Blade, top 10 users by 30d spend)
  - `TopupCodeStatsWidget` (codes issued/outstanding/redeemed/conversion%)
  - `CortexCostStats` (postojeći — chats, messages, tokens)
- **Resources** (`app/Filament/Resources/`):
  - Sve postojeće (AiProviders, AiModels, Personas, Chats, …)
  - `Wallets` (read-only lista, **"Grant" akcija** s amount + reason → kreira
    GRANT row preko `WalletService::grant()`)
  - `TopupCodes` (**"Generate batch" akcija** s count + amount + label →
    plaintext PIN-ovi u persistent notifikaciji)

### `/user` (UserPanelProvider) — customer

Navigation grupe: **Account** / **Billing** / **Developers**.

- **Dashboard widgets** (`app/Filament/User/Widgets/`):
  - `WalletOverviewWidget` (3 stats: Spendable / Reserved / 30-day spend)
  - `UserSpendChart` (30-day line, vlastiti debit po danu)
  - `ApiTokenUsageWidget` (per-token tablica: calls, successful, spent, last call)
- **Pages** (`app/Filament/User/Pages/`):
  - `Profile` — name, email (rotates `email_verified_at` na promjenu → re-verify
    notifikacija), country (39 zemalja, HR+EU first), VAT ID. Plus info card:
    Member since · Email verified · Free credit issued
  - `ChangePassword` — current+new+confirm, manual Hash::check, force re-login
    drugih sessiona
  - `DeleteAccount` — password + literal "DELETE" confirmation + modal "This
    cannot be undone", cascade-delete preko FK
  - `RedeemCode` — 14-digit PIN input s dash-tolerant parsing
- **Resources** (`app/Filament/User/Resources/`):
  - `WalletTransactionResource` (read-only ledger, scopes na user-ov wallet,
    filter po type)
  - `ApiTokenResource` (CRUD; `CreateApiToken` page koristi `ApiToken::issue()`
    pa prikaže plaintext jednom u persistent notifikaciji; row action "Revoke"
    set-a `revoked_at`)
- **Redirect:** `/profile` (Breeze) → `/user/profile`. Chat sidebar avatar
  također linka na `/user`.

## Frontend design system

- **Tailwind config** (`tailwind.config.js`):
  - `darkMode: 'class'` (toggle preko `.dark` na `<html>`)
  - **`cortex`** paleta — sapphire (#4c5fe6), 50-950 spektar
  - **`ink`** paleta — warm neutrals (toplije od pure zinc)
  - Custom shadows: `shadow-soft`, `shadow-pop`, `shadow-inset-line`
  - Custom easings: `ease-snap` (Apple-ish), animations `fade-up` + `soft-pulse`
- **`resources/css/app.css`** — Inter font preko Google Fonts (rsms.me je
  uzrokovao decorative stylistic alternates — "©ortex"-looking caps — pa je
  zamijenjeno), `.surface`, `.surface-muted`, `.btn`, `.btn-primary`,
  `.btn-ghost`, `.btn-soft`, `.pill` component classes.
- **`useTheme` hook** (`resources/js/hooks/useTheme.js`) — 3-state:
  light/dark/system, persistent u `localStorage.cortex.theme`, no-flash
  applyTheme() na import.
- **`ThemeToggle`** komponenta — segmented control u sidebaru.
- **`BalanceBanner`** (`Components/Cortex/`) — 3-state komponenta na Index +
  Show. Čita `wallet` iz Inertia shared props. `compact` prop za sidebar.
- **`ChatSidebar`** — collapsible (56px rail), chat list grouped Today/Yesterday/Earlier,
  brand monogram (3-circle gradient), avatar inicijali, theme toggle + Admin
  link + user link u footer.
- **`MessageBubble`** — Claude/GPT idiom: user msg s soft cortex-tinted bubble
  desno, AI msg flat (avatar + name + body bez bubble-a), Scribe collapsible
  amber card, System tiny pill u sredini.
- **`ChatInputBar`** — auto-grow textarea (max 240px), attachment chips iznad,
  send arrow button. **Wallet-aware**: ako balance ispod `min_send`, textarea
  disabled + placeholder "Wallet empty — top up", send button postaje Top-up
  Link. 402 odgovor s servera prikaže inline rejection banner.

## DB (sve tablice + ključne kolone)

### Original (postojeće)
- **`users`** — Breeze + SaaS extension: `is_admin`, `vat_id`,
  `vat_validation_status` (`unknown|valid|invalid|manual_override`),
  `country_code` (2-char ISO), `free_credit_granted_at`,
  `registration_ip_hash` + `registration_ua_hash` (SHA-256)
- **`chats`** — `rounds_per_turn`, `continuous` (web=true, CLI=false),
  `language` (`Croatian`/`English`/…, eksplicit), `current_round`, `scribe_interval`,
  `status`, `context`, `constraints`, `strong`,
  `total_messages/input_tokens/output_tokens/cost`,
  **`initiated_by_token_id`** (NULL = web/CLI)
- **`personas`** — `is_scribe`, `is_chair`, `is_ephemeral`, `ai_model_id`,
  `system_prompt`, `expertise_areas`
- **`chat_messages`** — `role` (user/persona/scribe/system),
  `round_number`/`turn_number`, `model_used`, `cost` (legacy provider_cost),
  `metadata`, **`is_billable`** (bool), **`provider_cost`**, **`user_cost`**,
  **`finish_reason`**, **`wallet_transaction_id`** (FK → wallet_transactions)
- **`chat_personas`** (pivot), **`scribe_summaries`**, **`chat_feedback`**
  (rating, helpful_ideas, used_ideas), **`ai_providers`**, **`ai_models`**,
  **`chat_attachments`**, **`powershell_*`**, **`knowledge_*`**

### Nove (Multi-user SaaS)
- **`wallets`** — `user_id` (unique), `balance` decimal(12,6), `reserved_balance`
  decimal(12,6), `currency` (default EUR), `maintenance_flag` (batch migration lock)
- **`wallet_transactions`** (append-only ledger):
  - `wallet_id`, `type` enum (DEPOSIT/RESERVE/RELEASE/DEBIT/REFUND/GRANT/ADJUSTMENT)
  - `amount` (signed impact na total funds), `reserved_delta` (signed impact na reserved_balance)
  - `source_type` + `source_id` (npr. 'chat', 75), `reserved_id` (FK back to parent RESERVE),
    `parent_transaction_id` (FK za REFUND chain)
  - `payment_source_ref` (indexed, idempotency key)
  - `rate_input_snapshot`, `rate_output_snapshot`, `provider_id`
  - `provider_cost`, `user_cost` (split tako da margin = trivial GROUP BY)
  - `metadata` (JSON), `created_at` (no `updated_at`, append-only)
- **`api_tokens`** — `user_id`, `token_hash` (sha256), `label`, `scopes` (JSON),
  `last_used_at`, `revoked_at`, unique(user_id, token_hash)
- **`api_token_usages`** — `api_token_id`, `chat_id?`, `chat_message_id?`,
  `endpoint`, `provider_cost`, `user_cost`, `response_time_ms`, `status` enum
  (`ok|rate_limited|insufficient_funds|error`)
- **`topup_codes`** — `code_hash` (sha256 unique), `amount`, `batch_label`,
  `redeemed_at/by_user_id/ip_hash`, `wallet_transaction_id`, `metadata`,
  `created_by_user_id`

## Config — `config/cortex.php`

### Postojeći ključevi (još uvijek aktivni)
`api_version`, `context_message_limit`, `persona_max_tokens`/`persona_temperature`,
`scribe_max_tokens`, `default_scribe_interval` + `scribe_round_interval`,
`budget_limit` + `daily_budget_limit`, `fallback_model`, `router_model`,
`flagship_models` (strong mode), `architect_model` + `architect_panel_models`,
`benchmark_control_model` + `benchmark_evaluator_model`, `deliberation_language`,
**`output_language` (default `English`, ne više `Croatian`)**, `kill_switch_key`.

### Novi blokovi
- **`cortex.billing`**:
  - `margin_hobby` (2.0), `margin_enterprise` (1.7), `enterprise_threshold_eur` (100)
  - `free_credit_signup` (0.50), `free_credit_first_deposit` (1.00), `first_deposit_threshold` (5.0)
  - `min_topup_eur` (5.0)
  - `estimate_fallback_output_tokens` (800), `rate_snapshot_ttl_hours` (6),
    `poison_pill_ratio` (1.3)
  - `min_send_balance` (0.05) — chat message endpoint floor (HTTP 402 ispod)
  - `low_balance_warning` (0.50) — UI banner threshold
- **`cortex.paddle`** (postoji ali neaktivan u V1):
  - `vendor_id`, `webhook_secret`, `topup_tiers[]` (5/10/25/50 EUR price_id mapiranje)
- **`cortex.api_rate_limit`**: `per_day` (50), `per_hour` (10), `concurrent` (3)

Sve podesivo preko `CORTEX_*` env varijabli.

## Reconciliation & scheduled tasks (`routes/console.php`)

- **`Schedule::command('cortex:wallet-reconcile')->dailyAt('04:00')->withoutOverlapping()->onOneServer()`**
- Provjerava obje invariante za sve walleta; alert ako drift > €0.01 tolerance.
  Exit `FAILURE` ako bilo koji wallet ne prolazi.
- **Treba** Windows Task Scheduler ili Linux cron koji svake minute pokreće
  `php artisan schedule:run` (inače cron nikad ne okine). Production deployment
  step.

## Konvencije / gotchas

- **`deliberation_language=English`** (persone raspravljaju EN — token-efikasnije),
  **`output_language=English` (default je promijenjen)**, ali svaka rasprava ima
  svoj `chats.language` koji je nadjačava (set iz UI toggle / `--language` /
  API request body).
- `--fast` gasi scribe/chair sentinelom `scribe_interval=1000000`.
- Ephemeral persone se nikad ne pojavljuju u rosteru/routeru;
  `cortex:prune` briše samo istinske orphane (bez poruka **i** bez chata).
- Promjene se provjeravaju živo: `php artisan cortex:discuss "..." --json` (CLI)
  ili kroz web na 8888. Migracije: `php artisan migrate`; nakon izmjene seedera
  — `php artisan db:seed --class=...`.
- Nakon izmjene `.env`/configa: `php artisan config:clear`.
- **Testovi NE smiju koristiti dev DB** — `phpunit.xml` forsira `DB_CONNECTION=sqlite`
  + `DB_DATABASE=:memory:` (RefreshDatabase trait bi inače wipe-ao cortex bazu).
- **Filament + MySQL `only_full_group_by`**: Eloquent query s GROUP BY (npr.
  ProviderCostBreakdown) trip-a strict mode jer Filament dodaje sekundarni
  `id DESC` sort za pagination. Rješenje: koristi `Widget` (custom Blade view)
  s `DB::table()` umjesto `TableWidget`.
- **Filament inputs u modal akcijama** koriste `id="mountedActionSchema0.X"`
  bez `name` atributa — selector po `getElementById`, ne `[name=...]`.
- **Inputi React forme** trebaju native value setter za state sync:
  `Object.getOwnPropertyDescriptor(HTMLInputElement.prototype, 'value').set.call(el, v)` +
  `el.dispatchEvent(new Event('input', {bubbles: true}))`.
- **Sessions invalidirane kad se user password promijeni** (Laravel auto) —
  testovi/MCP smoke moraju re-login nakon password change.
- **Wallet mutacije pod loadom**: nikad ne pozivaj `wallet->update(['balance' => …])`
  direktno; uvijek kroz `WalletService` — invariante to pretpostavljaju.
- **Inertia shared `wallet` prop** je live na svaki request — frontend ga čita
  preko `usePage().props.wallet` (refresh balance bez puno reactive plumbing).
