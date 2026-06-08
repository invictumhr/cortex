<div align="center">

# 🧠 Cortex

**A council of AI experts for your hardest decisions.**

*Multi-model AI brainstorming: five personas, each on a different model, debate your question — then a Chair commits to a single decision.*

Built by **[Invictum](https://invictum.hr)** · Open source under [MIT License](LICENSE)

</div>

---

## What is Cortex?

You ask Cortex a hard, open-ended question. Five AI personas — each running on a different model (Anthropic Claude, OpenAI o-series, xAI Grok, Google Gemini, Mistral, DeepSeek) — debate it across rounds. A *Scribe* persona summarises the discussion as it goes. When you pause, the *Chair* delivers a single decision: **DECISION / REASON / BIGGEST TRADE-OFF / FIRST STEP**.

Unlike most "multi-agent" frameworks where the same model talks to itself in different costumes, Cortex's personas are genuinely distinct minds — different architectures, different training data, different failure modes — coming at the same problem from different angles.

It's a prepaid SaaS: users register, verify email, top up via SMS PIN codes, and pay per-token from their wallet. Self-hostable.

## Why?

> "I want a second opinion. And a third. From five different brains."

A single LLM is a confident generalist that often glosses over obvious trade-offs because it doesn't argue with itself. A multi-model debate surfaces:

- **Tensions** one model would smooth over.
- **Trade-offs** between competing priorities.
- **Non-obvious angles** that come from different training distributions.
- **A forced decision** — the Chair must commit, not waffle.

The boardroom is benchmarked against a single strong model by a blind judge. See [`benchmark/`](benchmark/) for the suite and results.

## How a discussion runs

```
You: Should we migrate our Rails monolith to microservices?

  · Viktor is thinking…
[round 1] Viktor (grok-3)
Migration cost is rarely the bottleneck people think it is — coordination cost is...

  · Helena is thinking…
[round 1] Helena (claude-sonnet)
From a product perspective, the question isn't really monolith vs microservices...

  · Realist is thinking…
[round 1] Realist (claude-sonnet)
At 30 engineers, microservices buy nothing you couldn't get from clear module boundaries...

  · Ana is thinking…
[round 1] Ana (o3)
Three failure modes I have seen at this team size...

  · Petra is thinking…
[round 1] Petra (deepseek)
Whatever you decide, the integration testing story changes overnight...

[round 2] (personas now see each other and push back on specific claims)

📝 SCRIBE — final synthesis:
DECISION: Stay on the monolith for 12+ months. Invest in module boundaries first.
KEY IDEAS:
  - "Module discipline captures 80% of the upside" — Viktor, Realist
  - "Testing story is the real cost" — Petra, Helena
  - "Coordination wins at <40 engineers" — Ana, Viktor
PRIORITY MATRIX: ...

🪑 CHAIR:
DECISION: Stay on the monolith. Build clean module boundaries first.
REASON: At 30 engineers, the operational tax of microservices exceeds the velocity gain. Module discipline captures most of the upside.
BIGGEST TRADE-OFF: You delay independent deployments. If you grow past 60 engineers next year, splitting then will be harder than splitting now.
FIRST STEP: This week, identify the 3 highest-friction module boundaries and block new cross-cutting code there.
```

*(Illustrative example. The real boardroom may converge or genuinely disagree; the Chair always commits.)*

## What makes it different

- **Multi-provider personas by design** — 5 different models per discussion, not one model wearing 5 hats. Real cognitive diversity comes from architecture/training diversity.
- **Scribe + Chair pattern** — a dedicated summariser (sees the whole transcript) and a dedicated decider (forces commitment) are separate from the debaters.
- **Panel Architect** — for a specific question, a model can design 5 question-tailored expert roles instead of using the fixed roster.
- **Benchmark with a blind judge** — `cortex:benchmark` runs the boardroom vs a single flagship model, A/B-randomised, judged by a neutral model. Reproducible.
- **Cost pre-flight** — every CLI run shows an estimated cost range before starting; the final cost lands in the JSON output for agent callers.
- **Bilingual** — Croatian or English UI and discussion, auto-detected from your input. Toggleable in the UI.
- **Continuous web boardroom** — pause/play discussion that runs until you stop it. A heartbeat from the open page auto-pauses it the moment you leave — so nothing ever runs in the background burning money.
- **CLI for AI agents** — clean JSON output so other AI agents can drive Cortex (`cortex "..." --json`).

## Stack

- **PHP 8.3 / Laravel 12** (orchestration, jobs, scribe, Chair)
- **MySQL 8** (chats, messages, personas, summaries)
- **Redis** (queue + cache + heartbeat keys)
- **Laravel Reverb** (WebSocket live stream of personas as they respond)
- **Inertia + React (JSX) + Tailwind** (web UI)
- **Filament 5.6** — two panels: `/admin` (super-admin) and `/user` (customer: wallet, API tokens, profile)
- **6 AI provider adapters**: Anthropic, OpenAI, xAI, Google Gemini, Mistral, DeepSeek

## Quick start

### Prerequisites

- PHP 8.3+, MySQL 8 (or compatible), Redis, Node.js 18+
- API key for **at least one** supported provider (you get more diversity from more providers)

### Install

```bash
git clone https://github.com/YOUR_USER/cortex.git
cd cortex

composer install
npm install
npm run build

cp .env.example .env
php artisan key:generate
```

### Configure

Edit `.env` with your provider keys (`CORTEX_` prefix is deliberate — avoids collision with ambient empty `ANTHROPIC_API_KEY` etc. in many shell environments):

```env
CORTEX_ANTHROPIC_API_KEY=sk-ant-...
CORTEX_OPENAI_API_KEY=sk-...
CORTEX_XAI_API_KEY=xai-...
CORTEX_GOOGLE_API_KEY=...
CORTEX_MISTRAL_API_KEY=...
CORTEX_DEEPSEEK_API_KEY=...
```

Set DB and Redis credentials in the same file.

### Migrate and seed

```bash
php artisan migrate
php artisan db:seed
```

Default login: `admin@cortex.test` / `password`. Change it in `database/seeders/DatabaseSeeder.php` or via the admin panel.

### Run

On Windows / Laragon, the convenient way (brings up web + queue + reverb in one shot):

```powershell
bin/cortex-serve.ps1
```

Or manually (any OS):

```bash
php artisan serve --port=8888 &
php artisan queue:listen --tries=1 &
php artisan reverb:start &
```

Open <http://127.0.0.1:8888> in your browser.

## Usage

### Web

1. On `/chats`, fill the form (title, optional description / context / hard constraints), pick 5 personas (or tick **Architect** for an auto-designed panel), click **Start boardroom**.
2. On the chat page, type a message → the boardroom starts. Personas appear live via WebSocket, round after round.
3. Send another message any time — it joins the next round.
4. **Pause** when you've seen enough → the Chair delivers the final verdict.
5. **Resume** to continue.
6. Close the tab → the discussion auto-pauses (heartbeat).

### CLI

```bash
# Quick discussion (router auto-picks 5 personas)
php artisan cortex:discuss "How should we architect a notification system for 10M users?"

# Explicit panel + rounds
php artisan cortex:discuss "..." --personas=marco,helena,kira --rounds=3

# Architect-designed panel
php artisan cortex:discuss "..." --architect --rounds=2

# Strong mode (each persona on its provider's flagship model)
php artisan cortex:discuss "..." --strong

# With attached context and hard constraints
php artisan cortex:discuss "..." --context=schema.txt --constraints="no breaking API changes"

# Pick a discussion language (ISO 639-1; default en). Supported:
#   en hr sr bs sl sk cs pl bg ru uk  de fr it es pt nl ro hu sv el da fi
php artisan cortex:discuss "..." --language=fr

# Machine-readable JSON output (for other AI agents)
php artisan cortex:discuss "..." --json
```

PowerShell wrapper (Windows):

```powershell
cortex "..." -Personas marco,helena,kira -Rounds 3 -Json
```

### Benchmark

```bash
# Single benchmark: boardroom vs single flagship, blind judge
php artisan cortex:benchmark "Should startups raise from VCs at all?" --json

# Full 30-question suite (in benchmark/)
php benchmark/run.php
```

## Benchmark results

We ran the boardroom against **Claude Opus 4.7** on 30 open-ended hard-reasoning questions, judged blind A/B-randomised by **two independent judges** (claude-sonnet-4-6 and gpt-4o):

| Judge | Boardroom wins |
|---|---|
| claude-sonnet-4-6 | 7 / 30 (23.3%) |
| gpt-4o | 12 / 30 (40.0%) |
| **Mean** | **31.7%** |

**Boardroom does NOT systematically beat a strong single model.** It wins on multi-dimensional design and diagnostic problems (system architecture, build-vs-buy, NSM diagnostics, process design). It loses on clear strategic decisions, argue-both-sides essays, and synthesis questions where Opus alone produces a sharper answer. Cost is **~2.8× more** than the single model for that ~32% win rate.

Interesting side finding: the Claude judge (sonnet) sided with the Claude single-model control 9 times out of 11 disagreements; the OpenAI judge (gpt-4o) flipped most of those to boardroom. **Always cross-judge multi-model benchmarks with a different provider family.**

Full methodology, per-category breakdown, caveats and "when to use it" guidance: [`benchmark/README.md`](benchmark/README.md) (English) · [`benchmark/README.hr.md`](benchmark/README.hr.md) (Croatian).

## How it works (1-minute architecture)

```
User message
    │
    ▼
ChatOrchestrator → starts a round
    │
    ▼
GeneratePersonaResponse (queued job, per persona, self-chains)
    │
    ├─► PersonaResponder → ContextBuilder → adapter → model
    │        │
    │        └── live broadcast via Reverb to the open chat page
    │
    └─► End of round → ScribeService.maybeSummarize (periodic interim)
            │
            └── Loop until: paused, heartbeat lost, or budget hit
                    │
                    ▼ (on explicit user pause)
            ConcludeDiscussion job
                    → ScribeService.summarize(final)
                    → ChairService.decide
```

**Round 1** personas are independent (don't see each other) so opening takes stay diverse. **Round 2+** forces epistemic disagreement: each persona must either reject a specific prior claim with reasons or introduce a genuinely new angle. The **last round** of a 3+ round discussion forces convergence.

## Documentation

- **[`docs/ARCHITECTURE.md`](docs/ARCHITECTURE.md)** — deep dive into the orchestration pipeline, services, jobs, AI provider adapters, language handling, and conventions. Read this if you want to understand how Cortex works under the hood or extend it.
- **[`docs/RESEARCH.md`](docs/RESEARCH.md)** — lab notebook from the 30-question benchmark experiment. Methodology, full results, judge reasoning quoted directly, observed patterns (including a Claude-judge bias finding and a format-penalty hypothesis), bugs uncovered, and concrete v2 experiment design.
- **[`benchmark/README.md`](benchmark/README.md)** — polished public summary of the benchmark (English) · **[`benchmark/README.hr.md`](benchmark/README.hr.md)** — Croatian mirror.
- **[`CLAUDE.md`](CLAUDE.md)** — internal project memory in Croatian, originally written for an AI dev assistant working on this codebase.

## Configuration knobs

In `config/cortex.php`:

| Key | Default | What it does |
|---|---|---|
| `context_message_limit` | 30 | Recent messages each persona sees |
| `persona_max_tokens` | 1200 | Token cap per persona contribution |
| `scribe_max_tokens` | 6000 | Token cap for scribe summaries |
| `default_scribe_interval` | 50 | Scribe summarises every N messages |
| `scribe_round_interval` | 2 | Scribe also summarises every N rounds |
| `budget_limit` | 10.00 | EUR ceiling per chat (auto-pause) |
| `daily_budget_limit` | 25.00 | EUR ceiling across all chats per day |
| `router_model` | gpt-4o-mini | Cheap model that picks the panel for a topic |
| `fallback_model` | gpt-4o-mini | Used if a persona's primary model fails |
| `flagship_models` | per-provider | Used by `--strong` mode |
| `architect_model` | claude-sonnet-4-6 | Designs question-specific roles |
| `output_language` | English | Default language for scribe/Chair (per-chat overridable) |
| `billing.margin_hobby` | 2.0 | User cost multiplier (hobby tier) |
| `billing.margin_enterprise` | 1.7 | User cost multiplier (enterprise tier) |
| `billing.free_credit_signup` | 0.50 | EUR granted on email verification |
| `billing.min_send_balance` | 0.05 | Minimum wallet balance to send a message (HTTP 402 below) |
| `billing.low_balance_warning` | 0.50 | Threshold for UI balance warning banner |
| `api_rate_limit.per_day` | 50 | API requests per token per day |
| `api_rate_limit.per_hour` | 10 | API requests per token per hour |

All overridable via `CORTEX_*` env variables.

## Billing & Wallet

Users prepay via SMS PIN codes (14-digit, generated in batches by admin). All balance mutations go through `WalletService` with atomic `SELECT ... FOR UPDATE` and an append-only ledger (`wallet_transactions`).

- **Signup** → email verification → €0.50 free credit grant
- **First deposit** ≥ €5 → automatic €1.00 bonus
- Every persona response, scribe summary, and Chair decision does a **reserve → call → commitDebit** cycle through the wallet
- Margin: 2.0× (hobby) or 1.7× (enterprise, if 30-day spend ≥ €100)
- Daily reconciliation command (`cortex:wallet-reconcile`) checks ledger invariants

## REST API

Eight endpoints behind Bearer token auth (`ctx_...` tokens, managed in `/user/api-tokens`). Per-token rate limits (50/day, 10/hour).

| Method | Path | Scope | Action |
|---|---|---|---|
| POST | `/api/v1/discuss` | `cortex:discuss` | Start a new boardroom |
| GET | `/api/v1/chats` | `cortex:chats.read` | List chats |
| GET | `/api/v1/chats/{id}` | `cortex:chats.read` | Full chat with messages |
| POST | `/api/v1/chats/{id}/messages` | `cortex:chats.write` | Follow-up message |
| POST | `/api/v1/chats/{id}/archive` | `cortex:chats.write` | Archive a chat |
| DELETE | `/api/v1/chats/{id}` | `cortex:chats.write` | Hard delete |
| GET | `/api/v1/wallet` | `cortex:wallet.read` | Balance, tier, spend |
| GET | `/api/v1/wallet/transactions` | `cortex:wallet.read` | Ledger pagination |

Status codes: `200` ok · `401` invalid token · `402` insufficient funds · `403` wrong scope or cross-user · `422` validation · `429` rate limited.

## Project structure

```
app/
  Services/
    Chat/                      ← orchestrator, personas, scribe, Chair, architect
    Ai/                        ← provider adapters
    Billing/                   ← WalletService, TopupCodeService
    LanguageDetector.php       ← 23-language ISO 639-1 mapping
  Jobs/
    GeneratePersonaResponse.php   ← per-persona, self-chaining
    ConcludeDiscussion.php        ← scribe-final + Chair on pause
  Models/                      ← Chat, ChatMessage, Persona, Wallet, ApiToken, ...
  Http/Controllers/Api/        ← REST API (discuss, chats, wallet)
  Filament/
    Resources/                 ← admin panel resources
    User/                      ← customer panel (profile, wallet, API tokens, redeem)
  Console/Commands/            ← cortex:discuss, cortex:benchmark, cortex:wallet-reconcile, ...
resources/js/
  Pages/Chats/                 ← Inertia pages (Index, Show)
  Components/Cortex/           ← MessageBubble, ChatInputBar, BalanceBanner, ...
  i18n/                        ← UI translations HR/EN
benchmark/                     ← 30-question reference suite + runner + results
database/
  migrations/
  seeders/                     ← PersonaSeeder (32 personas incl. Scribe + Chair), PersonaModelSeeder
config/cortex.php              ← all knobs
bin/cortex.ps1                 ← PowerShell wrapper for cortex:discuss
```

## Things to know

- **Multi-user SaaS.** Registration with email verification, per-user wallets, SMS PIN top-ups, API tokens. Admin and customer Filament panels.
- **Cost is real.** A 5-persona × 2-round discussion costs roughly €0.10–€0.40 in provider costs (user cost includes a tier-based margin: 2.0× hobby, 1.7× enterprise). Budget guard auto-pauses at the per-chat limit.
- **Continuous web mode auto-stops.** The chat page heartbeats every ~20 s; the moment you leave (close tab, navigate away), the next round-check pauses the discussion. Nothing ever burns money in the background.
- **CLI vs Web differ.** CLI uses `--rounds=N` (bounded). Web is continuous (no round count — you pause when done).
- **Multilingual.** UI toggle (HR/EN). Discussion language is set explicitly — UI toggle on web, `--language=<iso>` on CLI. CLI supports 23 ISO 639-1 codes (en, hr, sr, bs, sl, sk, cs, pl, bg, ru, uk, de, fr, it, es, pt, nl, ro, hu, sv, el, da, fi).

## Contributing

This is a personal tool that happens to be open-sourced. Issues and PRs are welcome but I make no maintenance commitments. The most useful contributions:

- **Persona prompts** in other languages.
- **Adapters** for additional AI providers.
- **Benchmark questions** in your domain.
- **Bug reports** with reproducible steps.

## Made by Invictum

Cortex is built and maintained by **[Invictum](https://invictum.hr)** — a small software studio from Požega, Croatia.

Stack we use daily: Laravel 12, PHP 8.3, WordPress, Alpine + Tailwind, Astro / Next, GSAP, Percona MySQL, PostgreSQL, Redis, Docker + Linux, Cloudflare, Sentry, LLM integrations (Claude / GPT / local Ollama), Stripe + SEPA, Postmark / Resend.

## License

MIT — see [LICENSE](LICENSE). Copyright © 2026 [Invictum](https://invictum.hr).

---

*Built for thinking, not vibe-coding.*
