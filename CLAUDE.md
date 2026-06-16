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
- **Gotchas po modelu:** Opus 4.7+ odbija `temperature` (AnthropicAdapter ga
  preskače za `claude-opus-4-[7-9]*`); Gemini Flash troši izlaz na "thinking" →
  adapter šalje `thinkingConfig.thinkingBudget=0`; OpenAI o-serija koristi
  `max_completion_tokens` i bez temperature.
- **Retry/backoff:** transijentne greške (connection, 408/429/5xx) retry-aju
  se na ISTOM modelu (`AbstractAdapter::withRetries`, backoff iz
  `cortex.provider_retry_backoff_ms`, default 1s+4s = 3 pokušaja) prije nego
  fallback model preuzme. 4xx se NE retry-a.
- **finish_reason normalizacija:** svi adapteri mapiraju provider-specifične
  reasone u kanonske (`stop|max_tokens|content_filter|tool_use`) — kritično za
  is_billable check (Gemini vraća uppercase `SAFETY`).
- **Prompt caching (Anthropic):** system blok dobiva `cache_control: ephemeral`
  (gasi se s `CORTEX_PROMPT_CACHING=false`). ContextBuilder zato drži pinned
  KONTEKST/OGRANIČENJA u system promptu (round-invariant prefix) — svaki krug
  nakon prvog je cache read (10% input cijene). Cost računica koristi
  `AiResponse::billableInputTokens()` (write 1.25×, read 0.1× foldano u
  input-ekvivalent); `inputTokens` ostaje pravi ukupni kontekst za statistiku.

## Persone

- 32 fiksne persone (`database/seeders/PersonaSeeder.php`): 30 debate + Scribe + Chair. slug, name, title,
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

**Orphan RESERVE sweep** (`cortex:wallet-release-stale`, scheduled hourly):
RESERVE stariji od 6h bez DEBIT/RELEASE childa = job umro između reserve i
settle → sweep ga release-a natrag u spendable. Bitno: reconcile invariante
ovo NE hvataju (obje i dalje vrijede za orphan), zato postoji zaseban sweep.
Flagovi: `--hours=N`, `--dry-run`. Loga u `alerts` log channel.

**`payment_source_ref` je UNIQUE index** — idempotencija depozita je enforced
na DB razini; `deposit()` hvata `UniqueConstraintViolationException` i vraća
pobjednički red (concurrent webhook redelivery safe).

**Snapshot pricing**: pri `reserve()` se kopira AiModel-ova cijena per 1M
tokens (`rate_input_snapshot`, `rate_output_snapshot`, `provider_id`) — debit
uvijek koristi snapshotanu cijenu, ne live. Margin drift apsorbira Cortex.
TTL je u config (`rate_snapshot_ttl_hours`, default 6h — kraće od originalnih
24h iz panela jer smanjuje exposure).

**`is_billable` strict criteria** (postavlja PersonaResponder/Scribe/Chair):
true SAMO ako `output_tokens > 0 AND finish_reason ∉ ['content_filter', 'safety', 'blocked']`.
Sve drugo (prazan stream, refusal) → `release()` umjesto `commitDebit()`.
Adapteri normaliziraju provider-specifične finish reasone u kanonski rječnik
(`stop|max_tokens|content_filter|tool_use`, `AbstractAdapter::normalizeFinishReason`)
— bez toga bi Geminijev uppercase `SAFETY` prošao blocklist i bio naplaćen.

**Free credit logika** (Opcija B iz design-a):
1. Registracija → `Registered` event → user kreiran s IP/UA hash (RegisteredUserController)
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

Ten endpoints, scope-gated. Tokens su izdani iz `/user/api-tokens` (plaintext
prikazan jednom). Auth header: `Authorization: Bearer ctx_...`.

| Method | Path                                    | Scope                  | Action |
|--------|-----------------------------------------|------------------------|--------|
| GET    | `/api/v1/personas`                      | (bilo koji valjan token) | Roster fiksnih persona: slug, name, title, expertise_areas, role (debater/scribe/chair). Slugovi za `panel`/`personas` polja. |
| GET    | `/api/v1/models`                        | (bilo koji valjan token) | Aktivni modeli s upotrebljivim providerom: model_string, name, provider, supports_vision, max_context_tokens. Cijene se NE izlažu. |
| POST   | `/api/v1/discuss`                       | `cortex:discuss`       | **Async**: pokreće boardroom, vraća `202` + `chat_id` (public_id) + `poll_url`; klijent polla GET /chats/{id} dok `status` ne flipne na `paused`. Tijelo `{topic, agents?\|models?\|panel?, rounds?, title?, context?, constraints?, language?}`. Podržava **`Idempotency-Key` header** (24h TTL, per-token) — retry s istim ključem replaya originalni odgovor umjesto duple naplate. |
| GET    | `/api/v1/chats`                         | `cortex:chats.read`    | Lista chatova (newest-first). Query: `before` (ISO8601 za stranicu), `limit` (max 100), `include_archived=1`. Response uključuje `has_more` + `next_before`. |
| GET    | `/api/v1/chats/{id}`                    | `cortex:chats.read`    | Pun chat + personas + poruke + scribe summaries. Query `messages_after=ID` za incremental fetch. |
| POST   | `/api/v1/chats/{id}/messages`           | `cortex:chats.write`   | Follow-up poruka; `202` + `poll_url`. Podržava `Idempotency-Key`. Ako bounded chat ima turn u tijeku → `409 discussion_running`. Opcionalni `language` (ISO 639-1) updejta chat language real-time. |
| POST   | `/api/v1/chats/{id}/archive`            | `cortex:chats.write`   | `status` → `archived`; mirror web archive. |
| DELETE | `/api/v1/chats/{id}`                    | `cortex:chats.write`   | Hard delete s FK cascade — bespovratno. |
| GET    | `/api/v1/wallet`                        | `cortex:wallet.read`   | `{balance, reserved, available, currency, spend_30d, margin_multiplier, tier, low_warning_threshold, min_send_balance, topup_url}`. |
| GET    | `/api/v1/wallet/transactions`           | `cortex:wallet.read`   | Ledger pagination. Query: `type` (DEBIT/DEPOSIT/…), `before`, `limit` (max 200). Response uključuje `has_more`. |

Status kodovi: `200` ok · `202` accepted (async discuss/messages) · `401` invalid/missing/revoked token · `403` `scope_required` ili `forbidden` (cross-user chat) · `402` `insufficient_funds` · `409` `discussion_running` ili `idempotency_conflict` · `422` validation error · `429` `rate_limited` (s `retry_after_seconds`) · `500` server error.

Idempotency je implementiran u `Api\Concerns\HandlesIdempotency` (cache-based,
claim preko `Cache::add`, uspjeh se sprema 24h, fail oslobađa ključ za retry).

- **`AuthenticateApiToken` middleware** (`api.token` alias):
  - Extract Bearer `ctx_…` → hash → lookup `api_tokens.token_hash`
  - Reject ako `revoked_at !== null`
  - Optional scope check (npr. `api.token:cortex:wallet.read`)
  - Per-token rate limit: `per_day` 50, `per_hour` 10 (config `cortex.api_rate_limit`)
  - Stampa `api_token` na request attributes (controller čita za audit) i
    auth-ira ApiToken→user u request context
  - Updejta `last_used_at` na svakom uspješnom prolazu
- **Scope strings** definirani kao konstante u `App\Models\ApiToken` (jedan
  izvor istine za middleware aliase + Filament checkbox listu):
  - `SCOPE_DISCUSS = 'cortex:discuss'`
  - `SCOPE_CHATS_READ = 'cortex:chats.read'`
  - `SCOPE_CHATS_WRITE = 'cortex:chats.write'`
  - `SCOPE_WALLET_READ = 'cortex:wallet.read'`
  - `SCOPE_KNOWLEDGE = 'cortex:knowledge'`
  - Token bez scope-a (NULL) prolazi sve endpointe (super-user). Empty array
    pri kreaciji se normalizira na NULL (`CreateApiToken::handleRecordCreation`).
- **Logging** (`App\Http\Controllers\Api\Concerns\LogsApiUsage` trait): svaki
  endpoint piše JEDAN red u `api_token_usages` (chat_id, chat_message_id,
  endpoint, provider_cost, user_cost, response_time_ms, status enum
  `ok|rate_limited|insufficient_funds|error`). Cross-user 403, validation 422
  (kroz `validateLogged()` helper — običan `validate()` bi bacio prije loga),
  insufficient funds 402 — sve loga.
- **Cross-user izolacija:** sve `/chats/{id}` rute provjeravaju
  `chat->user_id === token->user_id` u controlleru i vraćaju `403 forbidden`
  ako se ne poklapaju (route model binding ne filtrira po owneru).
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
- `cortex:wallet-release-stale [--hours=6] [--dry-run]` — hourly sweep za
  orphan RESERVE retke (job umro između reserve i settle); release-a smrznuta
  sredstva natrag u spendable.
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
  - **Paginacija transcripta**: inicijalno se učitava zadnjih 100 poruka
    (`hasEarlierMessages` prop); "Učitaj ranije poruke" gumb dovlači starije
    stranice kroz `GET /chats/{id}/messages?before_id=…` (JSON feed podržava
    i `after_id` za incremental fetch). Heartbeat TTL je 120 s (ping 20 s →
    5 propuštenih pinga tolerancije).
  - **Echo reconnect catch-up**: na svaki `connected` event WebSocket veze
    UI refetcha propuštene poruke (`after_id` = zadnji viđeni ID) — pad
    Reverba/mreže više ne ostavlja zamrznuti transcript.
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
  - `ApiDocs` — self-serve REST API reference; Blade view s live base URL i
    scope listom iz `ApiToken` konstanti. Nav group Developers, sort=2.
  - `AgentBriefing` — generira downloadable CLAUDE.md za AI agente s punim API
    ugovorom (endpoints, auth, scopes, rate limits). Nav group Developers, sort=3.
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

### Resilience/cost ključevi
- `context_token_budget` (24000) — hard cap na sastavljeni transcript kontekst
  u ~tokenima (4 znaka/token, floor 4000 znakova); najstarije linije se režu
  prve, pinned blok i scribe sažetak nikad.
- `prompt_caching` (true) — Anthropic `cache_control` na system bloku.
- `provider_retry_backoff_ms` ('1000,4000' CSV) — backoff za transijentne
  provider greške; prazan string gasi retry.

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
- **`cortex.api_rate_limit`**: `per_minute` (10), `per_hour` (50)

Sve podesivo preko `CORTEX_*` env varijabli.

## Reconciliation & scheduled tasks (`routes/console.php`)

- **`cortex:wallet-reconcile`** — dailyAt 04:00, withoutOverlapping, onOneServer.
  Provjerava obje invariante za sve walleta; alert ako drift > €0.01 tolerance.
  Exit `FAILURE` ako bilo koji wallet ne prolazi.
- **`cortex:wallet-release-stale`** — hourly; release orphan RESERVE retke
  (>6h bez settlementa). Loga u `alerts` channel.
- **`queue:prune-failed --hours=168`** — daily; failed_jobs stariji od tjedan
  dana se brišu (payloadi su veliki, tablica bi rasla unbounded).
- **`cortex:prune`** — weekly; orphan ephemeral persone.
- **Alerts log channel** (`config/logging.php` → `alerts`): poison pill,
  reconcile drift i stale-reserve sweep pišu i u `storage/logs/alerts-*.log`
  (90 dana retencije) — ops surface odvojen od laravel.log šuma.
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
- **Brisanje chata/usera čisti i datoteke**: FK cascade briše retke bez
  Eloquent eventova, pa `Chat::deleting` i `User::deleting` hookovi brišu
  `chat-attachments/{chatId}/` direktorije s diska (GDPR completeness).
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
