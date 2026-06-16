# Changelog

Sve značajne promjene u Cortex projektu dokumentirane su u ovoj datoteci.

Format prati [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).
Datumi su rekonstruirani iz git povijesti; sažeci su izvedeni iz stvarno
izmijenjenih datoteka (poruke commita su sažete). Sve godine su 2026.

---

## [2026-06-16] — Hardening: naplata, otpornost providera, async API

Commit `30f8209` · 51 datoteka, +2223 / −147

### Fixed
- `Wallet::availableBalance()` više ne oduzima `reserved_balance` dvaput
  (`reserve()` već prebacuje sredstva iz `balance`) — UI i 402-gate više ne
  podbacuju usred runa.
- `payment_source_ref` je sada UNIQUE; `deposit()` hvata
  `UniqueConstraintViolationException` i zatvara concurrent-webhook race
  dvostrukog kreditiranja.
- Dodan nedostajući `POST /register` route (registracija je bila samo GET —
  store akcija nedostupna).

### Added
- `cortex:wallet-release-stale` (hourly) — sweep orphan RESERVE redaka (job
  umro između reserve i settle) natrag u spendable; `--hours`, `--dry-run`.
- `alerts` log channel (`storage/logs/alerts-*.log`, 90 dana) — reconcile
  drift, poison pill i stale-reserve sweep dual-logaju ovdje.
- Anthropic prompt caching (`cache_control: ephemeral`, toggle
  `prompt_caching`) + `AiResponse::billableInputTokens()` (write 1.25× /
  read 0.1×).
- `AbstractAdapter::withRetries()` — retry transijentnih grešaka (connection,
  408/429/5xx) na istom modelu prije fallbacka; 4xx se ne retry-a.
- `AbstractAdapter::normalizeFinishReason()` — kanonizacija provider finish
  reasona (Gemini uppercase `SAFETY` → `content_filter`).
- REST API: `GET /api/v1/personas` + `GET /api/v1/models` (MetaController);
  `Idempotency-Key` na discuss/messages; `has_more` paginacija;
  `validateLogged()` (422 dobiva audit red).
- Web UI: paginacija transcripta ("Učitaj ranije"), Echo reconnect catch-up.
- 9 novih test suite-ova (adapter retry, prompt caching, finish-reason, API
  auth, orchestration hardening, free credit, topup redeem, stale-reserve
  sweep, deletion cascade).

### Changed
- `ContextBuilder` seli pinned KONTEKST/OGRANIČENJA u system prompt
  (round-invariant prefix → cache hit) + `trimToTokenBudget()` hard cap.
- `discuss` i `chats/{id}/messages` su sada async (`202` + `poll_url`);
  messages vraća `409 discussion_running` za bounded chat s turnom u tijeku.
- `startRound()` atomic round-claim (conditional UPDATE) — dedup protiv
  dvostruke naplate kruga.
- `GeneratePersonaResponse` budget pre-check prije sljedećeg plaćenog poziva.
- Heartbeat TTL chata 50 → 120 s.

### Security / Privacy
- Brisanje chata/usera čisti `chat-attachments/{id}` direktorije s diska
  (FK cascade ne pali Eloquent evente → datoteke bi inače curile).

---

## [2026-06-09] — Responsive prilagodba

Commiti `7ce4818` `f3e06f3` `52ae412` `ffb4ec4` · 13 datoteka

### Changed
- Mobilni/responsive tweakovi chat sučelja: `ChatInputBar`, `ChatSidebar`,
  `MessageBubble`, `Chats/Index`, `Chats/Show` + `tailwind.config.js`.

---

## [2026-06-08] — Web GUI + HTTP API polish

Commiti `f57b9c9` `5d8e287` `4053eea` `83f33c7` `950de3b` · 44 datoteke

### Added
- Filament `Users` resource (admin CRUD korisnika).
- `TitleGenerator` — automatski naslov rasprave.
- Dokumentacija: `docs/YOUR-CLAUDE-API.md`, `docs/YOUR-CLAUDE-CLI.md`.

### Changed
- Dorada `ChatsController` / `DiscussController` / `AuthenticateApiToken`.
- `AnthropicAdapter`, `config/cortex.php`.
- Frontend: `Register.jsx`, `BalanceBanner`, `ChatInputBar`, `Chats/Index`.

---

## [2026-05-22] — REST API proširenje + chat UI

Commit `0394332` · 44 datoteke, +3929 / −446

### Added
- API: `ChatsController`, `WalletController`, `LogsApiUsage` trait.
- Filament: `ApiDocs` + `AgentBriefing` stranice, `TopupCodeBatch` resource +
  export controller.
- Boardroom: `BoardroomComposer`.

### Changed
- Dorade `ChatOrchestrator`, `PanelArchitect`, `PersonaResponder`.
- Frontend: `BalanceBanner`, `ChatInputBar`, `ChatSidebar`, `Chats/Index`,
  `Chats/Show`, i18n.

---

## [2026-05-22] — Multi-user SaaS sloj (wallet, API tokeni, top-up)

Commit `4b913f0` · 88 datoteka, +6428 / −481

### Added
- Billing core: `Wallet`, `WalletTransaction` (event-sourced ledger),
  `WalletService`, billing migracije, `WalletReconcile`,
  `InsufficientFundsException`, `WalletTransactionObserver`.
- Free credit: `GrantSignupCredit` listener.
- API tokeni: `ApiToken`, `ApiTokenUsage`, `AuthenticateApiToken` middleware,
  `Api/DiscussController`, Paddle webhook.
- SMS top-up: `TopupCode`, `TopupCodeService`, `GenerateTopupCodes`.
- Filament `/user` panel: Profile, ChangePassword, DeleteAccount, RedeemCode,
  ApiTokens, WalletTransactions.
- Admin widgeti: margin overview, cost-over-time, provider breakdown,
  top-spenders, topup stats.

---

## [2026-05-20] — Dubina boardrooma (Chair, Architect, jezici, benchmark)

Commit `466d22a` · 68 datoteka, +6909 / −402

### Added
- `ChairService` (završna odluka), `CostEstimator`, `PanelArchitect`
  (ephemeral persone), `ConcludeDiscussion` job, `LanguageDetector`.
- CLI: `CortexBenchmark`, `CortexPrune`.
- Migracije: context + chair, `strong`, `is_ephemeral`, `continuous`,
  `language`, `used_ideas`.

---

## [2026-05-18] — Inicijalni temelj

Commit `c016ed7` · 272 datoteke, +29296

### Added
- Laravel 12 + Breeze auth (login / register / verify / reset), Inertia +
  React chat UI.
- Boardroom engine: AI provideri + adapteri, `CortexDiscuss` CLI
  (+ `CortexFeedback`, `CortexKnowledge`, `CortexPersonas`,
  `CortexSmokeTest`, PowerShell executor).
- Modeli `AiModel` / `AiProvider` / `Chat`; kontroleri `ChatController`,
  `ChatMessageController`, `ChatActionController`, `ChatAttachmentController`,
  `ChatPersonaController`; knowledge + powershell sustav.
