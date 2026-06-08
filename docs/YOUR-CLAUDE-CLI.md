# Cortex CLI -- instructions for Claude agents

You are using the Cortex CLI to run AI boardroom discussions from the terminal.
A panel of AI personas debates a topic, a Scribe synthesizes, and a Chair
delivers one decision. The CLI runs synchronously -- it streams output live
and returns when the discussion is complete.

## Prerequisites

The user MUST have the Cortex CLI available. It requires:

1. **Cortex project** at `D:\laragon\www\invictum\cortex` (or wherever deployed)
2. **Laragon running** with MySQL + Redis active
3. **`cortex` on PATH** (PowerShell wrapper in `bin/cortex.ps1`)

If the command is not found, the user needs to add `bin/` to their PATH or
run `php artisan cortex:discuss` directly from the project directory.

## Basic usage

```powershell
cortex "Your topic or question here"
```

The CLI:
1. Auto-generates a title prefixed with "CLI: ..."
2. Routes the topic to 5 best-fit personas (default)
3. Runs 2 rounds of debate
4. Scribe synthesizes with structured output
5. Chair delivers final decision
6. Streams everything live to the terminal

## Key flags

| Flag | Example | What it does |
|------|---------|-------------|
| `-Rounds N` | `-Rounds 3` | Number of debate rounds (default 2) |
| `-Agents N` | `-Agents 3` | How many personas in the panel (2-8, default 5) |
| `-Personas a,b` | `-Personas marco,luna,chen` | Pick specific personas by slug |
| `-Strong` | `-Strong` | Run on flagship models (Opus, GPT-5.5, etc.) -- more expensive |
| `-Fast` | `-Fast` | 1 round, no scribe summary -- quick opinion |
| `-Context file` | `-Context schema.sql` | Attach a file as context for all personas |
| `-Constraints "..."` | `-Constraints "budget under 10k, no PHP"` | Hard rules every persona must respect |
| `-Language xx` | `-Language hr` | Output language (ISO 639-1). Default: en |
| `-Json` | `-Json` | Machine-readable JSON output (for piping to other tools) |
| `-Title "..."` | `-Title "Q3 strategy"` | Manual title (skip auto-generation) |
| `-Memory` | `-Memory` | Include accumulated knowledge from past discussions |
| `-Chat ID` | `-Chat 42` | Continue an existing chat instead of starting new |

## Supported languages

en, hr, sr, bs, sl, sk, cs, pl, bg, ru, uk, de, fr, it, es, pt, nl, ro, hu, sv, el, da, fi

## JSON output format (for parsing)

With `-Json`, the CLI outputs a single JSON object:

```json
{
  "chat_id": 42,
  "title": "CLI: Your Generated Title",
  "status": "paused",
  "rounds": 2,
  "messages": [
    {
      "role": "user",
      "content": "...",
      "round_number": 0
    },
    {
      "role": "persona",
      "persona_name": "Marco",
      "content": "...",
      "round_number": 1,
      "model_used": "gpt-4o",
      "user_cost": 0.0012
    },
    {
      "role": "scribe",
      "content": "TEMA: ...\nKLJUCNE IDEJE: ...",
      "round_number": 2
    },
    {
      "role": "persona",
      "persona_name": "Chair",
      "content": "ODLUKA: ...\nRAZLOG: ...\nNAJVECI TRADE-OFF: ...\nPRVI KORAK: ...",
      "round_number": 2
    }
  ],
  "total_user_cost_eur": 0.0624
}
```

## Practical tips for Claude agents

1. **Always attach context for technical topics.** Without `-Context`, personas
   have no knowledge of the user's specific codebase, schema, or constraints.
   Write relevant context to a temp file and pass it:
   ```powershell
   # Write context to temp file
   Set-Content -Path "$env:TEMP\ctx.txt" -Value "Current DB schema: ..."
   cortex "Should we normalize the orders table?" -Context "$env:TEMP\ctx.txt"
   ```

2. **Use `-Constraints` for hard boundaries.** Without constraints, personas
   may suggest solutions outside the user's reality:
   ```powershell
   cortex "How to scale our API?" -Constraints "AWS only, budget 500 EUR/mo, team of 2"
   ```

3. **Use `-Json` when you need to parse the output.** The default human-readable
   output is streamed live and hard to parse. JSON gives you structured data.

4. **`-Fast` for quick opinions.** When you just need a quick take, not a full
   debate:
   ```powershell
   cortex "Is Redis or Memcached better for session storage?" -Fast
   ```

5. **`-Strong` for important decisions.** Flagship models (Claude Opus, GPT-5.5)
   give deeper analysis but cost ~5-10x more:
   ```powershell
   cortex "Architecture for our payment system" -Strong -Context arch.md
   ```

6. **Parse the Chair's decision.** The Chair message always follows this format:
   ```
   ODLUKA: [the recommended path]
   RAZLOG: [why this one]
   NAJVECI TRADE-OFF: [what you sacrifice]
   PRVI KORAK: [first concrete action]
   ```
   In English output, these are: DECISION / REASON / BIGGEST TRADE-OFF / FIRST STEP.

7. **Scribe output differs by channel.** CLI scribe uses structured sections
   (TEMA, KLJUCNE IDEJE, ODLUKE, PRIORITETNA MATRICA). Web GUI scribe writes
   flowing prose. Both populate the same structured metadata in the database.

8. **Continue a discussion.** To add a follow-up question to an existing chat:
   ```powershell
   cortex "What about the security implications?" -Chat 42
   ```

## Example workflows

### Technical architecture review
```powershell
cortex "Should we migrate from monolith to microservices?" `
  -Context "D:\project\docs\current-arch.md" `
  -Constraints "Team of 4, must keep deploying during migration, budget 20k EUR" `
  -Rounds 3 -Language en
```

### Quick product decision
```powershell
cortex "Free trial or freemium model for our SaaS?" -Fast -Language en
```

### Deep strategic analysis
```powershell
cortex "5-year technology strategy for our fintech startup" `
  -Strong -Rounds 3 -Agents 7 `
  -Context "D:\docs\company-state.md" `
  -Constraints "Regulated industry, EU compliance required"
```

### Machine-readable for piping
```powershell
$result = cortex "Best database for time-series IoT data?" -Json | ConvertFrom-Json
$decision = $result.messages | Where-Object { $_.persona_name -eq "Chair" } | Select-Object -Last 1
Write-Host "Chair says: $($decision.content)"
```
