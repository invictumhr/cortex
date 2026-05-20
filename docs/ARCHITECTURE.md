# Cortex — Architecture

How the multi-persona boardroom actually works under the hood, end-to-end.

## The mental model

A *Chat* is a conversation thread between the user and a panel of AI *Personas*. Each persona runs on its own AI *Model* from one of six *Providers*. A discussion is structured into *rounds*; in each round, every active persona contributes once. A dedicated *Scribe* persona summarises periodically; a *Chair* persona is asked to commit to a single decision at the end (or on pause, in the web's continuous mode).

CLI runs are **bounded** (a fixed number of rounds). Web runs are **continuous** (loop until paused). The two share the same orchestration code; the difference is a `chats.continuous` flag.

## The core flow

```
ChatMessageController::store  (web)              CortexDiscuss::handle  (CLI)
        │                                                   │
        └──────────────┬────────────────────────────────────┘
                       ▼
          ChatOrchestrator::sendUserMessage
                       │
                       ├── persist user message + broadcast
                       ├── auto-detect chat language (first message only)
                       │
                       └── if continuous && active: return     ← message just joins the live stream
                           else:                               ← (re)start the loop
                              ChatOrchestrator::startRound
                                       │
                                       └── dispatch GeneratePersonaResponse(position=0)
                                                       │
              ┌────────────────────────────────────────┘
              ▼  (queued job, runs once per persona, self-chains)
GeneratePersonaResponse::handle
        │
        ├── kill-switch check (global Cache key)
        ├── status === ACTIVE check
        ├── (continuous only) heartbeat check — page must be open
        │
        ├── PersonaResponder::respond(chat, persona, round, turn)
        │       │
        │       ├── modelFor(persona, chat)  — strong-mode flagship override
        │       ├── ContextBuilder::build  — system prompt + transcript
        │       ├── adapter.sendMessage()  — primary model
        │       ├── (drop-out recovery) fallback model if primary fails/empty
        │       ├── persist ChatMessage + recordUsage
        │       └── broadcast ChatMessageCreated   ← live stream to the page
        │
        ├── budget guard — auto-pause if total_cost >= budget_limit
        │
        ├── if more personas in this round: dispatch next position → self-chain
        │
        └── end of round:
                ├── broadcast RoundCompleted
                ├── ScribeService.maybeSummarize(round)  — interim, periodic
                └── if continuous && still ACTIVE:
                        ChatOrchestrator::startRound(round + 1)   ← loop continues
                    else if bounded && final round:
                        ScribeService.summarize(final: true)
                        ChairService.decide()
                        broadcast TurnCompleted
                    else:
                        broadcast TurnCompleted (paused / left / etc.)
```

On explicit user pause (web only):

```
ChatActionController::pause
        │
        └── ChatOrchestrator::pause(chat, conclude: true)
                ├── status = PAUSED                          ← the running job sees this on next iteration and stops
                └── dispatch ConcludeDiscussion job
                        │
                        ├── guard: skip if chat resumed
                        ├── guard: skip if no new contributions since last verdict
                        ├── ScribeService.summarize(final: true)
                        └── ChairService.decide()
```

## Key components

### `app/Services/Chat/`

- **`ChatOrchestrator`** — entry point. `sendUserMessage`, `startRound`, `pause`, `resume`. Owns the discussion lifecycle. Knows nothing about model calls.
- **`PersonaResponder`** — produces one persona contribution. `modelFor()` resolves which model (handles `--strong` flagship override). Drop-out recovery: if the persona's primary model fails or returns empty, retries on `cortex.fallback_model`. Persists the message and broadcasts it.
- **`ContextBuilder`** — assembles the per-persona prompt: pinned context/constraints block, latest scribe summary, recent transcript (capped at `context_message_limit`), language directive. Round 1 messages are filtered so personas don't see each other's opening takes (keeps openings diverse). Round 2+ system prompt forces epistemic disagreement; the last round of 3+ forces convergence. Includes anti-confabulation rules.
- **`ScribeService`** — `maybeSummarize(chat, round)` for periodic interim summaries (every `scribe_round_interval` rounds or `scribe_interval` messages). `summarize(chat, final=true)` produces a cumulative final synthesis over the whole discussion. Output is structured JSON: `summary`, `key_ideas` (with `contributing_personas` attribution), `key_decisions`, `open_questions`, `action_items`, `assumptions_to_validate`, `durable_insights`, plus a priority matrix in the summary text. Broadcasts are non-fatal — a broadcast failure (e.g. payload too large, Reverb down) never aborts the discussion.
- **`ChairService`** — after a discussion, forces a single recommendation. Reads the latest scribe synthesis as basis; instructs the Chair model to output exactly: DECISION / REASON / BIGGEST TRADE-OFF / FIRST STEP. Persisted as a `role=persona` ChatMessage with `metadata.chair_decision=true`.
- **`PanelArchitect`** — instead of picking from the fixed roster, asks a model to design 5 question-specific orthogonal expert roles. Persists them as *ephemeral* personas (`personas.is_ephemeral=true`) attached to the chat only. The `cortex:prune` command cleans up truly-orphaned ephemerals.
- **`KnowledgeService`** — accumulates `durable_insights` the scribe extracts from each discussion into a global memory. The `--memory` flag injects the consolidated digest into a new discussion's context.
- **`CostEstimator`** — pre-flight cost estimate built from this chat's actual configuration (panel models, round count, measured size of pinned context/constraints), not from unrelated history. Honest-but-rough; treat as an order-of-magnitude figure.
- **`UsageGuard`** — per-minute and daily rate / budget limits.

### `app/Jobs/`

- **`GeneratePersonaResponse`** — the queued, self-chaining per-persona job described above.
- **`ConcludeDiscussion`** — dispatched on user pause of a continuous chat. Runs final scribe synthesis + Chair decision, gated by "nothing new since last verdict" to avoid waste on rapid pause/resume cycles.

### `app/Services/Ai/`

- `AiProviderInterface` + `AbstractAdapter`. Concrete adapters:
  - `AnthropicAdapter` (Claude family)
  - `OpenAiCompatibleAdapter` (base for OpenAI, xAI, Mistral, DeepSeek — all Pusher-compatible APIs)
  - `GoogleAdapter` (Gemini)
- `AiProviderFactory::for(AiModel)` returns the right adapter for a model.

**Per-model gotchas baked into the adapters:**

- Claude Opus 4.7 rejects `temperature` — the Anthropic adapter skips it for `claude-opus-4-7`.
- Gemini Flash burns its output budget on internal "thinking" — the Google adapter sets `thinkingConfig.thinkingBudget=0` for flash models.
- OpenAI o-series uses `max_completion_tokens` (not `max_tokens`) and rejects temperature.

API keys live in `.env` under the **`CORTEX_` prefix** (deliberate — avoids collision with ambient empty `ANTHROPIC_API_KEY` etc. in many shell environments). Stored in `ai_providers.api_key`, encrypted at rest (`encrypted` cast).

### `app/Services/LanguageDetector.php`

The chat language is **set explicitly** at creation, never auto-detected:

- `ChatController::store` reads the `language` field from the request body (`en` | `hr`, default `en`) — supplied by the GUI from the current `LanguageToggle` value (`useT().lang`).
- `CortexDiscuss::createChat` reads the `--language=en|hr` CLI option (default `en`).
- `CortexBenchmark::handle` reads the same `--language` option and propagates it to the boardroom, single-model control and judge.

The static helper `LanguageDetector::fromIso(string $code)` maps the ISO 639-1 code to the full English language name stored on `chats.language` and interpolated into prompts. The full list of supported codes lives in `LanguageDetector::SUPPORTED` and is currently:

`en` (English), `hr` (Croatian), `sr` (Serbian), `bs` (Bosnian), `sl` (Slovenian), `sk` (Slovak), `cs` (Czech), `pl` (Polish), `bg` (Bulgarian), `ru` (Russian), `uk` (Ukrainian), `de` (German), `fr` (French), `it` (Italian), `es` (Spanish), `pt` (Portuguese), `nl` (Dutch), `ro` (Romanian), `hu` (Hungarian), `sv` (Swedish), `el` (Greek), `da` (Danish), `fi` (Finnish).

CLI commands validate the option against `LanguageDetector::supportedIsoCodes()` and exit with a helpful error listing the valid codes if an unknown one is passed.

Once set, `chats.language` drives the language directive in every prompt (`ContextBuilder`, `ScribeService`, `ChairService`, `PanelArchitect`). The directive is placed last in the system prompt and explicitly overrides any persona-baked language preference (e.g., Realist's HR-hardcoded prompt).

A heuristic `detect()` method also lives in `LanguageDetector` (Croatian-specific characters + common-word matcher) — currently unused after the move to explicit language selection, kept for potential future smart-default features.

## Personas

- **~29 fixed personas** seeded by `database/seeders/PersonaSeeder.php`: `slug`, `name`, `title`, `system_prompt`, `expertise_areas`, `ai_model_id`. The persona→model mapping is authoritative in **`PersonaModelSeeder`** (overrides PersonaSeeder defaults).
- Flags on `personas`:
  - `is_scribe` — the Scribe (summariser; doesn't vote).
  - `is_chair` — the Chair (commits to the final decision).
  - `is_ephemeral` — Architect-generated roles; hidden from the regular roster.
- **Panel selection** (CLI `cortex:discuss`): three modes
  - `--personas=a,b,c` — manual list.
  - `--architect` — `PanelArchitect` designs a question-specific panel of ephemeral roles.
  - default — *router*: a cheap model classifies the topic and picks 5 personas by domain; the Realist persona is always pinned for feasibility-check.

## Web UI

`resources/js/`

- **`Pages/Chats/Index`** — chat list + "New boardroom" form (title, description, context, constraints, Architect/Strong checkboxes, manual persona picker).
- **`Pages/Chats/Show`** — continuous live boardroom (Reverb stream); the only controls are pause/play and the input bar (always enabled — you can send a message at any time). A heartbeat (`POST /chats/{id}/heartbeat` every ~20 s) keeps the discussion alive while the page is open; on unload, a `navigator.sendBeacon` to `/chats/{id}/leave` instantly pauses it.
- **`Components/Cortex/`** — `MessageBubble`, `ChatInputBar`, `PersonaInfoPanel`, `ChatSidebar`, `LanguageToggle`, `PowerShellModal`.
- **`i18n/`** — translations dict (`hr` / `en`), `I18nProvider`, `useT()` hook. Language preference stored in `localStorage.cortex.lang`, initialised from `navigator.language`. UI translates automatically; the *discussion* language is independent (auto-detected from the user's first message and stored on `chats.language`).

The discussion-quality features (Chair, scribe, drop-out recovery, epistemic disagreement forcing) are in the **shared pipeline** — the web UI and CLI use the same `ChatOrchestrator` + `GeneratePersonaResponse` + `PersonaResponder`.

## Database

Key tables:

| Table | Notable columns |
|---|---|
| `chats` | `rounds_per_turn`, `continuous`, `language`, `current_round`, `scribe_interval`, `status`, `context`, `constraints`, `strong`, `total_messages` / `_input_tokens` / `_output_tokens` / `_cost` |
| `personas` | `is_scribe`, `is_chair`, `is_ephemeral`, `ai_model_id`, `system_prompt`, `expertise_areas` |
| `chat_messages` | `role` (`user` / `persona` / `scribe` / `system`), `round_number`, `turn_number`, `model_used`, `cost`, `metadata` (e.g. `chair_decision`, `fallback_from`) |
| `chat_personas` | pivot with `is_active`, `joined_at`, `message_count`, `cost` |
| `scribe_summaries` | structured fields (`key_ideas` with attribution, `key_decisions`, `open_questions`, `action_items`, `assumptions_to_validate`) |
| `chat_feedback` | `rating`, `helpful_ideas`, `used_ideas` — captured but not yet consumed by anything (future learning-loop hook) |
| `ai_providers`, `ai_models` | provider config + per-model pricing |
| `chat_attachments` | images, URLs, PowerShell output |
| `powershell_permissions`, `powershell_logs` | sidecar executor (optional) |

## Configuration

`config/cortex.php` — everything is overridable via `CORTEX_*` env vars:

- Orchestration: `context_message_limit`, `max_rounds`, `round_presets`, `persona_max_tokens`, `persona_temperature`, `scribe_max_tokens`, `default_scribe_interval`, `scribe_round_interval`.
- Models: `router_model`, `fallback_model`, `flagship_models` (per-provider for `--strong`), `architect_model`, `architect_panel_models`, `benchmark_control_model`, `benchmark_evaluator_model`.
- Languages: `deliberation_language`, `output_language` (defaults; per-chat values override).
- Safety: `budget_limit` (per chat, EUR), `daily_budget_limit`, `max_messages_per_minute`, `max_turns_per_minute`, `kill_switch_key` (cache key).
- PowerShell sidecar: `url`, `token`.
- URL fetcher: `timeout`, `max_content_length`.

## Conventions and gotchas

- **Personas deliberate and respond in the chat's `language`** (auto-detected from first message). The pinned context/constraints block in the persona prompt is in Croatian regardless; the language directive at the end of the system prompt overrides and instructs the persona to write its output in `chats.language`. Models from all six providers handle this fine.
- **`--fast` mode** sets `scribe_interval=1000000` as a sentinel that effectively disables the scribe + Chair pass.
- **Ephemeral personas** are hidden from the regular roster and router; `cortex:prune` deletes truly-orphaned ones (no messages and no chats).
- **Live changes:** PHP's built-in server re-reads source per request; `queue:listen` respawns a fresh worker per job, so backend changes are picked up immediately. After a `config/*.php` edit, run `php artisan config:clear`. After a JSX edit, `npm run build`.
- **Broadcasts are best-effort.** The scribe explicitly wraps its broadcasts in try/catch so a payload-too-large or Reverb-down error never aborts the discussion. Reverb's `max_request_size` and `max_message_size` are bumped to 1 MB (default 10 KB is too small for big scribe syntheses).
- **`bin/*.ps1` must be ASCII-only** — PowerShell 5.1 reads UTF-8-without-BOM as ANSI and breaks on diacritics and em-dashes. PHP output to the console may be UTF-8.
- **Heartbeat-based stop on leave (web):** the open chat page pings `chats/{id}/heartbeat` every ~20 s (TTL 50 s server-side). When the heartbeat lapses, the next round-check pauses the discussion. Plus a `sendBeacon` to `chats/{id}/leave` on `pagehide` for instant stop. The `chats/*/leave` route is CSRF-exempt because `sendBeacon` cannot attach a CSRF token; the endpoint only pauses the caller's own chat.
- **CSRF protection is on by default** for all `web` routes via Laravel's middleware; only `chats/*/leave` is exempt.

## Live verification

```bash
# CLI smoke-test (cheap)
php artisan cortex:discuss "..." --personas=helena,realist --fast --json

# Full bounded discussion
php artisan cortex:discuss "..." --rounds=2 --json

# Benchmark a single question
php artisan cortex:benchmark "..." --json

# Full 30-question benchmark suite
php benchmark/run.php
```

After backend edits: nothing. The dev `php artisan serve` re-reads PHP per request; `queue:listen` respawns workers. After `config/*.php` edits: `php artisan config:clear`. After JSX edits: `npm run build`.
