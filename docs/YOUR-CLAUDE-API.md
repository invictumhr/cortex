# Cortex REST API -- instructions for Claude agents

You are integrating with Cortex, an AI boardroom SaaS. You send a topic,
a panel of AI personas debates it across rounds, a Scribe synthesizes, and
a Chair delivers one decision. Everything is billed from a prepaid EUR wallet.

## Prerequisites

The user MUST provide their Cortex API token. Ask for it if missing:
> "I need your Cortex API token (starts with `ctx_`) to use the API.
> You can issue one at https://cortex.com.hr/user/api-tokens."

Store the token only for the current session. Never log or echo it back.

## Base URL

```
https://cortex.com.hr/api/v1
```

Every request needs two headers:
```
Authorization: Bearer ctx_...
Accept: application/json
```

## Rate limits

- 10 requests per token per minute
- 50 requests per token per hour

A `429` response includes `retry_after_seconds` -- wait that long, then retry.

## Core flow: start discussion + poll for results

### Step 1 -- Start a discussion (async)

```
POST /api/v1/discuss
Content-Type: application/json

{
  "topic": "Should we rewrite our auth layer in Rust?",
  "rounds": 2,
  "language": "en"
}
```

Response (202 Accepted -- the boardroom is now running):
```json
{
  "ok": true,
  "chat_id": "a3f7c2e9...64-char-sha256...",
  "status": "active",
  "title": "API: Rust Auth Layer Rewrite",
  "rounds": 2,
  "poll_url": "/api/v1/chats/a3f7c2e9..."
}
```

### Step 2 -- Poll until done

```
GET /api/v1/chats/{chat_id}
```

Check `chat.status`:
- `"active"` -- still running. Wait 5-10 seconds, poll again.
- `"paused"` -- done. All messages (personas + scribe + chair) are in the response.

Use `?messages_after=ID` for incremental polling (only new messages since last check).

### Step 3 -- Read the results

The response includes:
- `messages[]` -- all contributions, each with `role` (user/persona/scribe), `content`, `model_used`, `user_cost`
- `scribe_summaries[]` -- structured data with `key_ideas`, `key_decisions`, `open_questions`, `action_items`
- The last persona message from the Chair contains the final DECISION/REASON/TRADE-OFF/FIRST STEP

### Step 4 -- Optional follow-up

```
POST /api/v1/chats/{chat_id}/messages
Content-Type: application/json

{
  "content": "What about security implications?",
  "rounds": 1
}
```

Returns 202. Poll the same chat_id for the new turn's messages.

## Panel composition options

Pick ONE (or omit all for default 5-agent quick mode):

| Option | Example | What happens |
|--------|---------|-------------|
| `agents: N` | `"agents": 3` | System picks N models + generates personas for the topic |
| `models: [...]` | `"models": ["gpt-4o", "claude-sonnet-4-6"]` | You pick models, system picks personas |
| `panel: [...]` | `"panel": [{"persona": "marco", "model": "gpt-4o"}]` | Full control over persona+model pairs |

## Other endpoints

| Method | Path | Purpose |
|--------|------|---------|
| GET | `/chats` | List chats. Query: `limit`, `before` (cursor), `include_archived=1` |
| GET | `/chats/{id}` | Full chat + messages + scribe summaries. Query: `messages_after=ID` |
| POST | `/chats/{id}/messages` | Follow-up message (async, poll for results) |
| POST | `/chats/{id}/archive` | Soft-archive a chat |
| DELETE | `/chats/{id}` | Hard delete (irreversible) |
| GET | `/wallet` | Balance, spend, tier |
| GET | `/wallet/transactions` | Ledger. Query: `type`, `before`, `limit` |

## Status codes

- `200` -- success (GET endpoints)
- `202` -- accepted, processing async (POST /discuss, POST /messages)
- `401` -- bad or missing token
- `402` -- wallet empty
- `403` -- wrong scope or not your chat
- `429` -- rate limited (check `retry_after_seconds`)

## Practical tips for Claude agents

1. **Always check wallet first** -- `GET /wallet` before starting a discussion.
   If `available` < 0.10 EUR, warn the user their balance is low.

2. **Poll with backoff** -- after POST /discuss, wait 10s before first poll,
   then every 5s. A 5-agent, 2-round discussion takes 30-90 seconds.

3. **Use `messages_after`** -- on each poll, pass the highest message `id` you
   already have. Saves bandwidth and avoids re-processing.

4. **Read the Chair message last** -- it has the final decision. Look for
   `role: "persona"` from the Chair (the last persona message in the turn).

5. **Parse scribe summaries** -- `scribe_summaries[].key_ideas` is an array of
   `{idea, contributing_personas}` objects. `action_items` and `key_decisions`
   are string arrays. Use these for structured extraction.

6. **Language** -- pass `"language": "hr"` for Croatian, `"en"` for English, etc.
   The entire discussion (personas, scribe, chair) outputs in that language.

7. **Context and constraints** -- pass `"context": "..."` with background info
   (data models, current state) and `"constraints": "..."` with hard rules.
   Every persona sees these. Without context, the panel guesses.

## Example: full polling loop (pseudocode)

```
token = user's ctx_... token
resp = POST /discuss { topic, rounds: 2, language: "en" }
chat_id = resp.chat_id
last_msg_id = 0

while true:
    data = GET /chats/{chat_id}?messages_after={last_msg_id}
    for msg in data.messages:
        process(msg)
        last_msg_id = max(last_msg_id, msg.id)
    if data.chat.status != "active":
        break
    sleep(5)

chair_msg = data.messages[-1]  // final decision
scribe = data.scribe_summaries[-1]  // structured synthesis
```
