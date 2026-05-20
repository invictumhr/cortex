# Cortex Boardroom — Benchmark

**Does a multi-model AI boardroom actually beat a single strong model? We tested on 30 open-ended questions with two independent judges. The honest answer: not by default.**

## TL;DR

| Metric | Value |
|---|---|
| Questions | 30 (open-ended, hard reasoning) |
| Boardroom | 5 personas (Viktor, Helena, Ana, Petra, Marco) on 5 different models across 4 providers, 2 rounds + Scribe + Chair |
| Single-model control | Claude Opus 4.7 |
| Judges | claude-sonnet-4-6 *and* gpt-4o (two independent judges, blind A/B) |
| **Boardroom wins (sonnet judge)** | **7 / 30 = 23.3%** |
| **Boardroom wins (gpt-4o judge)** | **12 / 30 = 40.0%** |
| **Mean boardroom win rate** | **31.7%** |
| Inter-judge agreement | 19 / 30 = 63.3% |
| Boardroom cost per question | €0.32 |
| Single-model cost per question | €0.11 |
| **Boardroom cost multiplier** | **2.8×** |
| Total benchmark spend | €12.87 + ~€3 for the cross-judge |
| Total runtime | 92 min |

**The boardroom does NOT systematically beat a strong single model.** It is competitive on multi-dimensional design and diagnostic problems; it loses on clear decisions and synthesis questions where one strong model produces a sharper answer. At ~3× the cost for ~32% win rate, it is **not** a cost-effective replacement for a good single model — but it is genuinely useful for specific problem shapes.

## Methodology

For each of 30 questions, we:

1. **Run a Cortex boardroom** — 5 fixed personas (Viktor `grok-3`, Helena `claude-sonnet-4-6`, Ana `o3`, Petra `deepseek-chat`, Marco), 2 rounds, with the Scribe producing a final structured synthesis and the Chair forcing a single decision.
2. **Ask Claude Opus 4.7 alone** to answer the same question. It is told it is an expert and to lay out the recommendation, key risks, and trade-offs.
3. **Show both answers to a judge model**, A/B-randomised so the judge cannot tell which side is the boardroom. The judge is instructed to pick which answer better surfaces real tensions, trade-offs, risks and non-obvious angles, and to NOT reward length or confidence. The judge returns a strict JSON verdict with winner, scores, and reasoning.

We use **two independent judges** to mitigate single-judge bias:

- **claude-sonnet-4-6** (Anthropic) — same provider family as the single-model control.
- **gpt-4o** (OpenAI) — different provider family.

(We also tried `gemini-2.5-pro` as a third judge. It returned an empty response on 28 of 30 calls — a known Gemini-2.5 "thinking budget eats output" issue our adapter does not yet handle for the Pro tier. We dropped Gemini from the analysis.)

The benchmark code, the 30 questions, the raw boardroom and single-model answers, and both judges' verdicts and reasons are all in this directory. Anyone can reproduce.

## Results — headline

```
                           sonnet judge      gpt-4o judge
boardroom wins             7  (23.3%)        12 (40.0%)
single-model wins         23  (76.7%)        18 (60.0%)
```

**Mean boardroom win rate across judges: 31.7%.**

The single model wins clearly under both judges, but **gpt-4o is 17 percentage points more favourable to the boardroom than sonnet is**. That spread is the experiment's most interesting finding — see "The Claude-judge bias" below.

## Inter-judge agreement

```
both judges agreed              19 / 30  (63.3%)
  - both → boardroom               4
  - both → single model           15
both judges disagreed           11 / 30  (36.7%)
  - sonnet → single, gpt-4o → boardroom   9
  - sonnet → boardroom, gpt-4o → single   2
```

## The Claude-judge bias is real

The 11 disagreements are **not** random:

> Of 11 splits, **9 had sonnet pick the single model while gpt-4o picked the boardroom**. Only **2** went the other way.

This is a 9:2 skew. Claude-family judge (sonnet) consistently preferred the Claude-family single-model answer (Opus 4.7) over a mixed-provider boardroom that included no Claude voice in the panel itself. The same pairs of answers, judged by a non-Claude judge (gpt-4o), flipped to boardroom 9 out of 11 times.

**This is a serious caveat that anyone benchmarking multi-agent systems should adopt:** a Claude judge evaluating Claude answers is not blind. The boardroom's "true" win rate is probably closer to the gpt-4o number (40%) than the sonnet number (23%). But neither shifts the bottom line — the boardroom does **not** systematically beat a strong single model on this question set.

## Where the boardroom wins

**4 questions** where both judges unanimously preferred the boardroom:

- **q7** *(tech_strategy)*: How would you architect a real-time notification system for 10 million users with 99.9% delivery and sub-1-second latency?
- **q11** *(business)*: Build vs buy for internal tooling at a 100-person company — what factors actually decide?
- **q12** *(business)*: An app's North Star metric is daily active users but revenue is not following. Diagnose the likely causes and recommend a fix.
- **q17** *(eng_management)*: Design a code review process for a 20-person team that catches real bugs without burning engineers out.

**Pattern**: multi-dimensional design and diagnostic problems where genuinely different lenses (architecture, product, engineering, QA, operations) each contribute something the others miss. The boardroom's debate naturally surfaces angles a single voice would smooth over.

## Where the boardroom loses

**15 questions** where both judges unanimously preferred the single Opus 4.7. A representative spread:

- **Clear strategic decisions**: q1 (Rails → microservices), q3 (rewrite criteria), q9 (hire vs raise prices), q13 (runway vs growth), q14 (self-serve vs sales-led).
- **Argue-both-sides essays**: q4 (feature flags), q20 (AI content liability).
- **Trend analysis / prediction**: q26 (interface 2030), q27 (AI bubble), q30 (AI agent startup failures).
- **Bar-defining**: q19 (L4 → L5 engineer promotion bar).

**Pattern**: when one strong model can produce a clean, well-organised answer, the boardroom's debate adds noise and verbosity. The Scribe's structured synthesis helps but does not out-compete a sharp single voice. The Chair's terse final decision is good but rarely sharper than what Opus would produce alone.

## Results by category

| Category | n | Sonnet boardroom | GPT-4o boardroom | Mean B% | Unanimous B |
|---|---:|---:|---:|---:|---:|
| eng_management | 5 | 1 | 3 | 40.0% | 1 |
| business | 6 | 2 | 2 | 33.3% | 2 |
| tech_strategy | 8 | 2 | 3 | 31.2% | 1 |
| policy | 5 | 1 | 2 | 30.0% | 0 |
| open_analysis | 6 | 1 | 2 | 25.0% | 0 |

The boardroom does relatively best on **engineering management** and **business**, worst on **open analysis** (interpretation/prediction questions where multi-perspective debate seems to dilute rather than sharpen).

## Cost

```
Boardroom total          €9.46  (€0.32 per question)
Single model total       €3.41  (€0.11 per question)
Boardroom multiplier     2.8×
```

You are paying **roughly 3× the cost for a ~32% win rate**. That is a poor cost/benefit ratio for general use. It is reasonable for the specific problem shapes where the boardroom convincingly wins.

## When should you use the Cortex boardroom?

Based on this 30-question experiment:

**YES, worth it for:**

- **Multi-stakeholder design problems** (architecture, process design) where different real-world functions (engineering, product, QA, ops) genuinely see different constraints.
- **Diagnostic problems** where the failure could be in many places and you need different lenses to surface the right hypothesis (q12 was a textbook example: "DAU is up but revenue isn't" needs the marketing, product, engineering and finance angles).
- **When you already have a strong opinion** and want it challenged from angles you might not have considered. The boardroom is a forced-perspective device, not a wisdom machine.

**NO, use a single strong model (Opus 4.7 or equivalent) for:**

- **Clear strategic decisions** with a small number of well-understood options.
- **Argue-both-sides essays** — one strong model can lay both cases out cleanly.
- **Synthesis and decision-articulation** questions where the answer is an articulation, not a debate.
- **Trend analysis and prediction** — adding voices does not add foresight.
- **Anything where you would accept the first decent answer**. The 3× cost is not worth ~30% upside when the single model's "loss" is still usually a perfectly good answer.

## Caveats

1. **Sample size is small** (n=30). The 31.7% mean win rate could shift by ±10 percentage points with a different question set.
2. **Question selection is mine.** I picked open-ended hard-reasoning questions; a different curator could tilt the result either way.
3. **Judges are LLMs**, not humans. They reward thoroughness, structure and trade-off surfacing — proxies for "good answer", not the thing itself. Real-world value depends on whether you act on the answer and whether the action works.
4. **The boardroom panel was fixed** (5 personas, no Architect, 2 rounds). Results could differ with Architect-designed question-specific panels or more rounds. Worth a follow-up benchmark.
5. **The single-model control is Opus 4.7**, currently a top-tier model. Against a weaker control (sonnet, gpt-4o-mini), the boardroom would likely win more often. The cost comparison would also tilt favourably.
6. **All 30 questions were English.** Cortex's bilingual nature (HR/EN, plus 21 other ISO codes) was not tested here.
7. **One unfixed pre-existing bug** showed up: o3 was originally the configured judge and returned empty responses on the first 3 questions (its reasoning tokens ate the 600-token output budget). We switched the judge to sonnet mid-run and re-judged those 3 afterwards. The adapter could be fixed to handle o-series properly, which would let o3 serve as a third independent judge.

## Reproducing

```bash
# Full benchmark (~90 min, ~€13)
php benchmark/run.php

# Fill any missing verdicts with the configured judge (sonnet by default)
php benchmark/rejudge.php

# Cross-judge all 30 with a second judge model
php benchmark/cross_judge.php --judge=gpt-4o

# Consolidate and print the analysis
php benchmark/analyze.php
```

Inputs and outputs in this directory:

- `questions.json` — the 30 questions, with category.
- `run.php`, `rejudge.php`, `cross_judge.php`, `analyze.php` — scripts.
- `results.json` — per-question boardroom answer, single-model answer, sonnet verdict, costs, timings.
- `verdicts_gpt_4o.json` — gpt-4o judge's verdict per question.
- `analysis.json` — consolidated stats used to write this report.
- `run.log` — runner progress log.

## Honest takeaway

If you came here expecting "Cortex's multi-model boardroom beats GPT-5", **the answer is no — it does not.** It is *competitive* on the right kind of problem; it is *wasteful* on the wrong kind.

The more interesting finding is the **Claude-judge bias**: a Claude judge consistently preferred a Claude single-model answer over a mixed-provider boardroom, by 9 to 2 in the disagreement set. Anyone benchmarking multi-model systems should use judges from a different provider family than the model under test.

Cortex remains a thoughtful, polished tool for **structured multi-perspective brainstorming**. It is not a free lunch. Use it when the problem genuinely benefits from multiple angles. For everything else, a strong single model is faster, cheaper, and — by these judges, on these questions — a more frequent winner.

---

*Benchmark run: 2026-05-20. Cortex commit: see git log. Questions, raw answers and verdicts: this directory.*
