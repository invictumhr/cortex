<?php

namespace App\Filament\User\Pages;

use BackedEnum;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Str;

/**
 * Self-serve page that hands an AI agent (Claude, GPT, Gemini, any tool-using
 * assistant) a single CLAUDE.md file describing how to operate the Cortex API.
 *
 * Workflow:
 *   1. User generates a token under /user/api-tokens
 *   2. User downloads CLAUDE.md here
 *   3. User attaches the .md + their token to their AI agent of choice
 *   4. The agent reads the file and knows the full API contract
 *
 * The file content is generated dynamically so the base URL, rate limits,
 * and scope list stay in sync with whatever the app actually serves.
 */
class AgentBriefing extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCpuChip;

    protected static string|\UnitEnum|null $navigationGroup = 'Developers';

    protected static ?string $navigationLabel = 'CLAUDE.md';

    protected static ?string $title = 'CLAUDE.md — AI Agent Briefing';

    /** Below API Documentation (sort=2) so the order is Tokens → Docs → Agent Brief. */
    protected static ?int $navigationSort = 3;

    protected string $view = 'filament.user.pages.agent-briefing';

    /**
     * Header action that streams the file to the browser as CLAUDE.md.
     * Livewire 3 + Filament 5 understand a Response returned from an action
     * and turn it into a download instead of a JSON ack.
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('download')
                ->label('Download CLAUDE.md')
                ->icon(Heroicon::OutlinedArrowDownTray)
                ->color('primary')
                ->action(function () {
                    $content = $this->getMarkdownContent();

                    return response()->streamDownload(
                        fn () => print $content,
                        'CLAUDE.md',
                        ['Content-Type' => 'text/markdown; charset=UTF-8'],
                    );
                }),
        ];
    }

    /** Used by the Blade view to render the formatted preview below the download. */
    public function getRenderedHtml(): string
    {
        return Str::markdown(
            $this->getMarkdownContent(),
            ['html_input' => 'escape', 'allow_unsafe_links' => false],
        );
    }

    /** The raw markdown — single source of truth for both download + preview. */
    public function getMarkdownContent(): string
    {
        $base = rtrim(config('app.url'), '/');
        $perHour = (int) config('cortex.api_rate_limit.per_hour', 10);
        $perDay = (int) config('cortex.api_rate_limit.per_day', 50);

        return <<<MD
        # Cortex API — AI Agent Briefing

        You are an AI assistant (Claude, GPT, Gemini, or any tool-using agent) and you
        have access to the **Cortex AI boardroom API**. This file is the operating manual.
        Read it once, then operate the API on the user's behalf.

        ---

        ## What Cortex is

        Cortex is a multi-perspective AI brainstorming platform. Instead of one model
        answering, **a panel of 3–8 AI personas on different models debate the question**
        across rounds, a **Scribe** synthesizes, and a **Chair** delivers one final
        decision. The output is richer and more balanced than any single model produces
        alone.

        **Use it when the user's question is:**
        - **Strategic** ("should we…", "is X a good idea")
        - **Architectural** ("how should we design…")
        - **Trade-off heavy** (multiple valid approaches)
        - **High-stakes** (a wrong answer is expensive)

        **Don't use it for:**
        - Trivial lookups ("what's the capital of France")
        - Code formatting / mechanical refactoring
        - Anything you can answer correctly in 10 seconds

        ---

        ## Authentication

        Set the user's API token (they gave it to you in the chat) as a Bearer header on
        **every** request:

        ```
        Authorization: Bearer ctx_YOUR_TOKEN_HERE
        ```

        If the user hasn't given you a token yet, ask:

        > "To use Cortex on your behalf I need your API token. Generate one at
        > `/user/api-tokens` and paste it here."

        Tokens are revocable — if the user revokes, you'll get `401`. Just ask for a new one.

        ---

        ## Base URL

        ```
        {$base}/api/v1
        ```

        ---

        ## Cost awareness — read this carefully

        **Every call burns real wallet euros.** You are spending the user's money.
        Before launching expensive runs, check the wallet:

        ```bash
        curl {$base}/api/v1/wallet -H "Authorization: Bearer ctx_..."
        ```

        Rough cost guide (varies by topic length + models picked):

        | Configuration                   | Typical cost          |
        |---------------------------------|-----------------------|
        | `agents=3, rounds=1`            | €0.05 – €0.15         |
        | `agents=5, rounds=2`            | €0.20 – €0.50         |
        | `agents=8, rounds=3`            | €0.80 – €1.50         |
        | `agents=5, rounds=2, strong=true` (flagship models) | 3–5× the above |

        **Default to small and cheap.** Escalate to `agents=5, rounds=2+` only if the
        user explicitly asks for "deep", "thorough", or "important decision". Never
        run `strong=true` without explicit confirmation.

        ---

        ## Endpoints — what you can call

        ### GET `/api/v1/personas` — discover the persona roster

        Returns every fixed persona: `slug`, `name`, `title`, `expertise_areas`, `role`
        (debater / scribe / chair). Use the slugs in the `panel` / `personas` fields of
        POST /discuss. Any valid token can call this — no scope needed.

        ### GET `/api/v1/models` — discover usable models

        Returns active models (`model_string`, `name`, `provider`, `supports_vision`).
        Use the model strings in `models` / `panel[].model`. No scope needed.

        ### POST `/api/v1/discuss` — start a new boardroom

        **Asynchronous.** Returns `202 Accepted` immediately with the chat id and a
        `poll_url` — the discussion runs in the background. Poll
        `GET /api/v1/chats/{id}` until `chat.status` flips from `"active"` to
        `"paused"`; that means the turn (personas + Scribe + Chair) is done.

        Pick **one** of the three panel-init modes:

        - **Quick**: `agents: N` — system picks models + personas (recommended default)
        - **Custom models**: `models: ["gpt-4o", "claude-opus-4-7", ...]`
        - **Custom**: `panel: [{persona: "slug", model: "..."}, ...]` (advanced)

        Send an **`Idempotency-Key` header** (any unique string, e.g. a UUID) so a
        network-retry duplicate replays the original response instead of starting —
        and billing — a second boardroom.

        ```bash
        curl {$base}/api/v1/discuss \\
          -H "Authorization: Bearer ctx_..." \\
          -H "Content-Type: application/json" \\
          -H "Idempotency-Key: 7f3c1e2a-..." \\
          -d '{
            "topic": "Should we use Postgres or MongoDB for this project?",
            "agents": 3,
            "rounds": 1,
            "language": "en",
            "context": "Optional background — paste data model, current code, etc.",
            "constraints": "Optional hard requirements every persona must respect."
          }'
        ```

        Response shape (`202`):

        ```json
        {
          "ok": true,
          "chat_id": "<64-char public id>",
          "status": "active",
          "title": "...",
          "rounds": 1,
          "poll_url": "/api/v1/chats/<public id>"
        }
        ```

        ### GET `/api/v1/chats` — list past discussions

        Newest first. Use `?limit=N` (max 100) and `?before=ISO8601` for pagination.
        The response includes `has_more` — keep paging with `next_before` while it's
        `true`. Pass `?include_archived=1` if you need archived too.

        ### GET `/api/v1/chats/{id}` — fetch a chat + messages

        Returns full transcript. Use `?messages_after=ID` to fetch only newer messages
        for incremental polling.

        ### POST `/api/v1/chats/{id}/messages` — follow-up turn

        Drop a new user message into an existing chat. Returns `202` + `poll_url` like
        /discuss. Supports the same `Idempotency-Key` header. If the previous turn is
        still running you get `409 discussion_running` — poll until `status` is
        `"paused"`, then retry.

        ```bash
        curl {$base}/api/v1/chats/<public id>/messages \\
          -H "Authorization: Bearer ctx_..." \\
          -H "Content-Type: application/json" \\
          -d '{"content": "What if we go option B instead?", "rounds": 1}'
        ```

        ### POST `/api/v1/chats/{id}/archive` — soft archive

        Hides chat from default listings. Reversible — re-fetch with `?include_archived=1`.

        ### DELETE `/api/v1/chats/{id}` — hard delete

        Permanent. Use only on explicit user request.

        ### GET `/api/v1/wallet` — balance + 30-day spend

        Always check this before launching big runs. Returns:

        ```json
        {
          "balance": 114.11,
          "reserved": 0,
          "available": 114.11,
          "currency": "EUR",
          "spend_30d": 4.48,
          "tier": "hobby",
          "min_send_balance": 0.05
        }
        ```

        ### GET `/api/v1/wallet/transactions` — ledger

        Paginated. Use `?type=DEBIT` to filter to spend rows only.

        ---

        ## Common patterns

        ### Pattern 1 — quick decision support

        User asks: *"Should I use Postgres or MongoDB for this?"*

        ```json
        POST /api/v1/discuss
        { "topic": "Postgres vs MongoDB for <project context>", "agents": 3, "rounds": 1 }
        ```

        Wait 30–60 sec, then summarize the **Chair's verdict** back to the user.

        ### Pattern 2 — deep architectural review

        User explicitly asks for "boardroom" / "deep dive" / "multiple perspectives":

        ```json
        POST /api/v1/discuss
        {
          "topic": "...",
          "agents": 5,
          "rounds": 2,
          "context": "<full background, current code, constraints>",
          "constraints": "Must support: X, Y, Z"
        }
        ```

        ### Pattern 3 — follow-up question on existing chat

        After a discussion, user wants to dig deeper:

        ```json
        POST /api/v1/chats/42/messages
        { "content": "What if we go option B instead?", "rounds": 1 }
        ```

        Append the new messages to your context. **Do not** call `/discuss` again —
        that starts a brand new chat with no memory of the prior turn.

        ### Pattern 4 — defensive pre-flight

        Before any expensive run, check the balance:

        ```text
        1. GET /wallet
        2. If available < estimated_cost → tell user to top up, do not call /discuss
        3. Else proceed
        ```

        ---

        ## How to summarize the response to the user

        When the API returns, **do not dump all persona messages back**. The user wants
        synthesis, not a wall of debate.

        Format your summary like this:

        1. **Lead with the Chair's verdict** (last message with `role: "chair"`) — that's
           the one decision the boardroom converged on.
        2. **Then 2–3 key trade-offs** the Scribe surfaced (from the most recent message
           with `role: "scribe"` — content is structured JSON, parse `key_decisions` and
           `open_questions`).
        3. **End with the cost**: "This used €0.23 of your wallet. Balance remaining: €X."
        4. Offer to dig deeper with a follow-up message on the same chat.

        ---

        ## Error handling

        | Status | Meaning                              | What to do                                                                  |
        |--------|--------------------------------------|-----------------------------------------------------------------------------|
        | `200`  | Success                              | Parse response, summarize for user                                          |
        | `401`  | Token missing / invalid / revoked    | Ask user for a fresh token                                                  |
        | `402`  | Insufficient wallet balance          | Tell user to top up at `/user/redeem-code`                                  |
        | `403`  | Wrong scope or cross-user chat       | Ask user to issue a token with the required scope (in `required` field)     |
        | `404`  | Chat doesn't exist or was deleted    | Don't retry — the chat is gone                                              |
        | `409`  | Turn still running / key in flight   | Poll the chat until `status` is `"paused"`, then retry                      |
        | `422`  | Validation error                     | Read `errors` map, fix the request                                          |
        | `429`  | Rate limit hit                       | Wait `retry_after_seconds`, then retry. **Do not retry-loop.**              |
        | `500`  | Server error                         | Retry with the SAME `Idempotency-Key` — safe; without one, check `GET /chats` first |

        ---

        ## Rate limits

        - **{$perHour} requests / token / hour**
        - **{$perDay} requests / token / day**

        If you're about to do many calls, batch where possible or tell the user upfront.

        ---

        ## Scopes (if your token is restricted)

        Tokens can be limited to specific scopes. On `403 scope_required`, look at the
        `required` field and tell the user which scope is missing.

        | Scope                    | Lets you                                            |
        |--------------------------|-----------------------------------------------------|
        | `cortex:discuss`         | Start new discussions                               |
        | `cortex:chats.read`      | List + read chats                                   |
        | `cortex:chats.write`     | Follow-up messages, archive, delete                 |
        | `cortex:wallet.read`     | Check balance + ledger                              |

        A token with **no scopes set** has full access (super-user).

        ---

        ## When to walk away

        If the user keeps asking trivial follow-ups, gently say:

        > "This one is cheap enough to answer directly — want me to just give you my best
        > guess instead of spending wallet euros?"

        **Be a responsible spender.** The user is trusting you with their wallet.

        ---

        *Generated dynamically from the user's Cortex instance.
        Base URL: `{$base}`*
        MD;
    }
}
