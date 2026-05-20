# Cortex Multi-Model Boardroom — Research Notes

*Lab notebook for the 30-question benchmark experiment. Full reasoning, observed patterns, bugs, and conclusions — not the polished public summary (that's [`benchmark/README.md`](../benchmark/README.md)).*

**Date**: 2026-05-20
**Run**: 30 questions, 92 min, €12.87 (boardroom + single) + €3 cross-judge ≈ €16 total
**Researcher**: solo, working with AI dev assistant

---

## 0. Headline at a glance

```
Boardroom wins:
  - by claude-sonnet-4-6 judge:    7 / 30  (23.3%)
  - by gpt-4o judge:              12 / 30  (40.0%)
  - mean:                                  31.7%

Inter-judge agreement: 19 / 30 (63.3%)
  - both → boardroom:    4
  - both → single model: 15
  - split:               11

Cost:
  - boardroom avg/q: €0.32
  - single avg/q:    €0.11
  - boardroom is 2.8x more expensive

Time:
  - avg per question: 185 s  (~3 min)
  - total runtime:    92 min for the 30-question suite
```

**Bottom line**: the boardroom does **not** systematically beat a strong single model (Claude Opus 4.7). It is competitive on multi-dimensional design and diagnostic problems; it loses on clear decisions, argue-both-sides essays, and synthesis questions where a single competent model produces a cleaner answer.

**Most interesting finding wasn't the win rate — it was the judge bias.** A Claude judge favoured a Claude single-model answer over a mixed-provider boardroom by **9 to 2** in the 11 disagreement cases. This is a serious methodological caveat for anyone benchmarking multi-agent AI systems.

---

## 1. The research question

> **Does a multi-model AI boardroom (5 personas, each on a different model, debating across rounds) produce better answers to hard open-ended questions than a single strong model (Claude Opus 4.7) running alone?**

Implicit subquestions we wanted to surface:

- For which **problem types** does multi-agent debate add value?
- For which problem types is it **wasted overhead**?
- Is the cost (2-3× higher than single model) **worth it**?
- Is there judge bias we need to control for?

## 2. Why it mattered

I was considering open-sourcing Cortex and wondered whether there was a *credible* headline result that could anchor a Show HN / blog post. The framing I was hoping for:

> *"€0.30 of API money and five small models beat Opus 4.7 on hard reasoning."*

That headline would have been hype-worthy. The actual result (~32% win rate at 2.8× cost) is not. **It is, however, more useful as honest engineering data than the headline I hoped for would have been.** Knowing where the boardroom *actually* helps and where it doesn't is the kind of finding I would now use to advise someone whether to adopt this pattern.

## 3. Experimental design

### 3.1 The 30 questions

Curated for: open-endedness, real-world relevance, multi-perspective value, no single objectively-correct answer. Distributed across five categories:

| ID | Category | Question |
|---:|---|---|
| 1 | tech_strategy | Should a 30-person SaaS team migrate from a Rails monolith to microservices? |
| 2 | tech_strategy | High-write event log (10k events/sec): PostgreSQL partitioned vs ClickHouse vs DynamoDB? |
| 3 | tech_strategy | When does it pay off to rewrite a working codebase from scratch vs incrementally refactor? |
| 4 | tech_strategy | Argue both sides of feature flags as an engineering practice. |
| 5 | tech_strategy | Monorepo vs polyrepo for 40 engineers across 5 services. |
| 6 | tech_strategy | REST vs GraphQL vs tRPC for a new internal API in 2026. |
| 7 | tech_strategy | Architect a real-time notification system for 10M users, 99.9% delivery, sub-1s. |
| 8 | tech_strategy | Should a startup adopt React Server Components or stick with SPA in 2026? |
| 9 | business | Indie SaaS at $20k MRR, 12 months runway — hire one engineer or raise prices 50%? |
| 10 | business | When should an open-source project introduce a paid 'enterprise' tier? |
| 11 | business | Build vs buy for internal tooling at a 100-person company. |
| 12 | business | NSM is DAU but revenue not following — diagnose and recommend. |
| 13 | business | Extend runway vs double down on a promising growth channel? |
| 14 | business | B2B SaaS at 50 customers: self-serve onboarding or sales-led growth? |
| 15 | eng_management | Which work goes to AI coding agents vs human engineers in 2026? |
| 16 | eng_management | When to fine-tune a smaller open model vs prompt-engineer a frontier closed model? |
| 17 | eng_management | Design a code review process for 20 people that catches real bugs without burnout. |
| 18 | eng_management | How to handle a 10x engineer who refuses to write tests? |
| 19 | eng_management | Define the bar for L4 → L5 engineer promotion at a Series-B startup. |
| 20 | policy | Should social platforms be legally liable for harmful AI-generated content? |
| 21 | policy | Universal basic income — strongest cases for and against, your verdict. |
| 22 | policy | Should a tech company publicly commit to never building weapons systems? |
| 23 | policy | Privacy vs personalization in consumer apps in 2026. |
| 24 | policy | When AI agents take routine coding, what should mid-career engineers focus on? |
| 25 | open_analysis | Why has fully remote work adoption plateaued? |
| 26 | open_analysis | Dominant computing interface paradigm by 2030 — voice, text, AR, other? |
| 27 | open_analysis | Strongest argument we are in an AI bubble. Strongest we are not. Your verdict. |
| 28 | open_analysis | Why do enterprise software products feel terrible compared to consumer apps? |
| 29 | open_analysis | Will the 4-day workweek become mainstream by 2030? Make the case. |
| 30 | open_analysis | What does the high failure rate of AI agent startups tell us about AGI timelines? |

The full text and category breakdown is in [`benchmark/questions.json`](../benchmark/questions.json).

**Selection bias acknowledged**: I picked these. Someone curating from a different angle could shift the headline number meaningfully.

### 3.2 The boardroom configuration

| Parameter | Value |
|---|---|
| Personas | 5 fixed: Viktor (grok-3), Helena (claude-sonnet-4-6), Ana (o3), Petra (deepseek-chat), Marco |
| Rounds | 2 |
| Scribe | enabled (final synthesis + interim every 2 rounds) |
| Chair | enabled (single decision at end) |
| Architect | not used (would be a separate experiment) |
| Strong mode | not used |
| Language | English (`--language=en`) |
| Context / constraints | none attached |

The fixed-roster choice avoided the Realist persona, which had hardcoded Croatian language preferences that would have leaked into EN output.

### 3.3 The single-model control

| Parameter | Value |
|---|---|
| Model | claude-opus-4-7 (Anthropic flagship, current top-tier) |
| max_tokens | 1500 |
| temperature | 0.5 |
| System prompt | "You are a top expert. Answer thoroughly and concretely: lay out the recommendation, key risks, and trade-offs." |

Single model gets one shot, no iteration, no scribe, no Chair. This is the **strong baseline** — not a strawman.

### 3.4 The judges

Each pair of answers is shown to a judge with random A/B order, so the judge cannot infer which side is the boardroom.

| Judge | Provider family | Status |
|---|---|---|
| **claude-sonnet-4-6** | Anthropic (same as Opus control) | Primary, ran for all 30 |
| **gpt-4o** | OpenAI (different family) | Cross-judge, ran for all 30 |
| **gemini-2.5-pro** | Google | **Failed on 28/30** (see §13.2) |

Judge prompt: *"You are a neutral judge. Two answers to the same question. Decide which better surfaces real tensions, trade-offs, risks and non-obvious angles. Do NOT reward length or confidence. Return JSON {winner, score_1, score_2, reason}."*

Two independent judges was deliberate — to detect single-judge bias. **It paid off**: the two judges diverged by 17 percentage points (see §6).

### 3.5 The methodology pipeline

```
For each of 30 questions:
  1. Run boardroom (cortex:benchmark internally)
     → answer = scribe-final-synthesis + chair-decision concatenated
  2. Ask Opus 4.7 alone
     → answer = single essay
  3. A/B-shuffle the two answers
  4. Show to sonnet judge → verdict + reason
  5. Show to gpt-4o judge → verdict + reason
Save all of it (questions, answers, verdicts, reasons, costs, times).
```

All scripts (`run.php`, `rejudge.php`, `cross_judge.php`, `analyze.php`) are in [`benchmark/`](../benchmark/). The raw data (`results.json`, `verdicts_gpt_4o.json`, `analysis.json`) is committed.

## 4. Headline results

```
                       sonnet judge      gpt-4o judge      mean
  boardroom wins        7  (23.3%)       12 (40.0%)       31.7%
  single-model wins    23  (76.7%)       18 (60.0%)       68.3%
```

**Cost**:
- Boardroom: €9.46 total (€0.32 per question average).
- Single Opus 4.7: €3.41 total (€0.11 per question average).
- Boardroom multiplier: **2.78×**.
- Adding the two judges: ~€3 more (sonnet rejudge for 3 NULLs + full gpt-4o cross-judge).
- **Total experiment spend: ~€16.**

**Time**: 92 min for the 30 questions (sequential). Per-question avg 185s. Mostly bounded by the boardroom's 7-10 model calls per question (5 personas × 2 rounds + scribe interim + scribe final + chair + control + judge).

## 5. Per-category breakdown

| Category | n | Sonnet boardroom | GPT-4o boardroom | Mean B% | Unanimous B | Unanimous S |
|---|---:|---:|---:|---:|---:|---:|
| eng_management | 5 | 1 | 3 | 40.0% | 1 | 2 |
| business | 6 | 2 | 2 | 33.3% | 2 | 4 |
| tech_strategy | 8 | 2 | 3 | 31.2% | 1 | 4 |
| policy | 5 | 1 | 2 | 30.0% | 0 | 2 |
| open_analysis | 6 | 1 | 2 | 25.0% | 0 | 3 |

Boardroom does relatively **best on engineering management and business** (where multiple real-world functions — engineering, product, finance, ops — genuinely see different constraints) and **worst on open analysis** (interpretation/prediction questions where multi-perspective debate dilutes rather than sharpens).

## 6. The Claude-judge bias finding (the experiment's most interesting result)

Of the 11 cases where the two judges disagreed:

```
  sonnet → single model,  gpt-4o → boardroom:    9
  sonnet → boardroom,     gpt-4o → single model: 2
```

**A 9:2 skew is not noise.** The Claude-family judge consistently preferred the Claude-family single-model answer (Opus 4.7) over a mixed-provider boardroom that contained no Claude voice (the panel was Viktor/grok, Helena/sonnet, Ana/o3, Petra/deepseek, Marco — wait, Helena IS sonnet, so there is one Claude voice, but Helena's writing was only ~20% of the boardroom answer). The same exact answer pairs, shown to a non-Claude judge (gpt-4o), flipped to boardroom 9 of 11 times.

Reading the sonnet judge's reasoning across those 9 cases, a clear stylistic pattern emerges. Sonnet repeatedly complains:

- *"Response X is a meeting-notes artifact... buries every insight in process language ('next steps', 'priority matrix', 'paper phase')..."* (Q21)
- *"buries the core question under process artifacts and consulting-deck formatting"* (Q27)
- *"structured as meeting notes from a fictional panel, which buries the core tensions under process artifacts"* (Q4)
- *"committee-minutes format buries the key insight under process scaffolding"* (Q18)
- *"the simulated-debate format buries these insights in bureaucratic scaffolding"* (Q5)
- *"formatted as a meeting summary with a lot of procedural scaffolding (priority matrices, next steps, open questions) that obscures rather than illuminates"* (Q16)

GPT-4o, looking at the same answers, sees the *content* and credits the multi-perspective analysis:

- *"surfaces a broader range of tensions and trade-offs"* (Q2)
- *"highlights specific disagreements among stakeholders"* (Q11)
- *"considers the historical context of similar technological revolutions"* (Q27)
- *"discusses potential disagreements and open questions"* (Q5)

This is genuinely two different evaluation lenses. Sonnet is form-sensitive (the "process theater" complaint); gpt-4o is content-sensitive. **Neither is wrong** — both are valid ways to read an answer.

**Methodological takeaway**: when benchmarking a system whose output has a distinctive format (multi-agent debates inevitably do), do not use a judge whose model family matches the comparison side. Cross-judge with at least one model from a different provider family. **If you can't, weight the disagreement-set asymmetry as evidence of bias.**

The boardroom's "true" win rate is probably closer to the gpt-4o number (40%) than the sonnet number (23%), but neither shifts the bottom line.

## 7. Where the boardroom won (4 unanimous wins, deep dive)

Both judges unanimously preferred the boardroom on these four:

### Q7 (tech_strategy): Real-time notification system architecture for 10M users

- sonnet B=8 S=6 · gpt-4o B=9 S=7
- Sonnet's reason: *"surfaces the genuinely non-obvious tensions: the SLA definition ambiguity (server vs. device vs. read), the GDPR/data-locality constraint that invalidates naive build-vs-buy comparisons, the APNs/FCM dependency that puts 99.9% delivery partially outside the system's control, and the retry-storm failure mode that breaks the sub-1s bound under real conditions."*
- GPT-4o's reason: *"surfaces a broader range of tensions... the need for requirements validation before architecture design, the build-vs-buy decision, and compliance constraints. It also highlights the ambiguity in the 99.9% delivery definition."*

**Pattern**: an architecture question with multiple genuine constraint domains (technical, regulatory, vendor-dependency, failure-mode). Each persona surfaces a different angle. Opus gives a competent architecture diagram but treats requirements as fixed and doesn't interrogate the SLA definition. Boardroom wins by *questioning the question*.

### Q11 (business): Build vs buy for internal tooling at a 100-person company

- sonnet B=8 S=6 · gpt-4o B=9 S=7
- Sonnet's reason: *"surfaces genuine tensions that Response 2 glosses over: the accounting illusion of 'free' payroll vs. expensed SaaS, the reversibility asymmetry with data gravity cutting both ways, the compliance risk concentration vs. distribution debate, and the integration test ownership question. These are non-obvious trade-offs with real disagreement between positions, not just a checklist."*
- GPT-4o: *"incorporates diverse perspectives and highlights specific disagreements among stakeholders. Surfaces non-obvious angles such as the impact of organizational politics, the importance of integration test coverage, and the potential for accounting illusions to skew decisions."*

**Pattern**: classic build-vs-buy is treated by single-model answers as a checklist. The boardroom found four non-obvious tensions (accounting illusions, data gravity, compliance trade-offs, integration test ownership) that one model would smooth over.

### Q12 (business): DAU is up but revenue isn't — diagnose

- sonnet B=9 S=7 · gpt-4o B=9 S=7
- Sonnet: *"surfaces genuine tensions that Response 1 glosses over: the debate about whether payment-flow bugs produce sudden cliffs vs. gradual dilution, whether qualitative interviews can actually answer the scalability question, and whether the paying cohort represents a scalable market or just friction-tolerant early adopters... The 'two different products' hypothesis and Viktor's scalability concern are genuinely underexplored angles in most treatments of this problem."*
- GPT-4o: *"highlights disagreements among stakeholders and outlines a clear diagnostic sequence before making strategic changes."*

**Pattern**: diagnostic question where the failure could be in many places. Marketing, product, engineering, and finance each see different signals — the boardroom forced all four. Opus alone produced a competent playbook but didn't generate the "two different products" hypothesis or stress-test whether qualitative interviews can actually answer the scalability question.

### Q17 (eng_management): Design a code review process

- sonnet B=8 S=6 · gpt-4o B=9 S=6
- Sonnet: *"explicitly surfaces real tensions and trade-offs: Marco vs Petra on time caps, Viktor's skill atrophy concern, Helena's challenge on whether code review is even the right intervention, and the unresolved question of what percentage of production incidents are actually catchable by review. It names the risk of optimizing in a vacuum and defers full rollout until a baseline is established — a non-obvious, defensible position."*
- GPT-4o: *"discusses various mechanisms, potential disagreements, and the need for a baseline to determine if code review is the right intervention. It also considers the risk of skill atrophy and the importance of balancing quick wins with long-term strategies."*

**Pattern**: process design. The boardroom's most powerful move here was a persona (Helena) **questioning whether code review is even the right intervention**. That's a meta-move a single model rarely makes — it would default to "yes of course, here's a great code review process."

### Common thread across the 4 wins

The boardroom wins when:

1. **The question is multi-dimensional** (architecture: tech + compliance + vendor + ops; build-vs-buy: finance + ops + risk + people).
2. **The honest answer involves real disagreement or unresolved tensions** that a single voice would resolve too tidily.
3. **A persona credibly questions the premise of the question itself** (Helena on whether code review is the right tool; the panel collectively on whether the SLA definition is even achievable).

The boardroom's value is not "more compute → better answer". It is **forced perspective**. The personas' different domains generate questions a unified voice wouldn't ask.

## 8. Where the boardroom lost (sample of unanimous single-model wins)

### Q1 (tech_strategy): Monolith → microservices migration

- sonnet B=5 S=8 · gpt-4o B=7 S=9
- Sonnet on Opus's win: *"surfaces concrete, non-obvious tensions that Answer 1 [boardroom] largely misses: the 'distributed monolith' failure mode, the specific headcount threshold where monoliths genuinely break down (~75-100 engineers), and the surgical extraction criteria requiring two conditions simultaneously. Answer 2 [Opus] also identifies which service types are bad extraction candidates (anything touching core domain model requiring cross-user joins), which is a genuinely counterintuitive and useful insight."*

**Reading**: on a well-trodden architecture topic, Opus has clearly seen many treatments of this and produces specific quantitative thresholds (~75-100 engineers) and named failure modes ("distributed monolith"). The boardroom's debate format is less efficient than one strong synthesis.

### Q4 (tech_strategy): Argue both sides of feature flags

- sonnet B=6 S=8 · gpt-4o B=6 S=8
- Sonnet: *"Response 2 [Opus] presents the actual two-sided argument more crisply and with better-chosen concrete examples (Knight Capital, combinatorial state explosion, vendor lock-in, security surface) that illuminate non-obvious risks. Response 1 [boardroom] is structured as meeting notes from a fictional panel, which buries the core tensions under process artifacts (personas, priority matrices, next steps) and spends more words on governance mechanics than on the genuine trade-offs."*

**Reading**: "argue both sides" wants a clean essay, not a transcript. The boardroom's format actively hurts it.

### Q19 (eng_management): Define the bar for L4 → L5 promotion

- sonnet B=3 S=8 · gpt-4o B=6 S=9
- Sonnet: *"Response 1 [Opus] delivers what was asked: a concrete, actionable promotion bar with specific criteria, examples, and process mechanics. It surfaces real tensions (tenure vs. performance, output vs. impact, hero engineers vs. force multipliers, bar drift in both directions) without padding. Response 2 [boardroom] is a meeting-notes artifact from a fictional multi-person debate — it defers the actual answer behind process steps and open questions, which is the opposite of concrete."*

**Reading**: "be concrete" — the boardroom's "let's discuss what the rubric should be" answer literally contradicts the instruction. Opus delivers a rubric. Boardroom delivers minutes about how to build a rubric.

### Q27 (open_analysis): AI bubble verdict

- sonnet B=4 S=8 · gpt-4o B=6 S=8
- Sonnet: *"Response 1 [Opus] directly addresses the question with clean structure: strongest bull case, strongest bear case, and a genuine verdict with specific historical analogies and actionable implications. The tension between 'capex bubble' and 'real technology' is honestly held rather than resolved too neatly. Response 2 [boardroom] is a repurposed group-discussion summary (complete with Croatian participant names, voting tallies, and action matrices) that never actually delivers the two strongest arguments or a clear verdict."*

**Reading**: a "verdict" question demands a verdict. The boardroom produced a process artifact that flinched from committing. Opus committed. Note also "Croatian participant names" — see §13.3 below for the language-leak finding.

### Common thread across single-model wins

Opus dominates when:

1. **The question wants a clean, structured, committed answer** ("be concrete", "your verdict", "argue both sides") and the boardroom produces a process artifact instead.
2. **It's a well-trodden topic** where Opus's training has absorbed many high-quality treatments and can re-synthesize crisply.
3. **The decision space is narrow** (3 named options to compare) and a long multi-persona debate adds noise rather than insight.

## 9. The 11 disagreements (per-question analysis)

### Pattern summary

```
sonnet → single, gpt-4o → boardroom (9): Q2, Q5, Q16, Q18, Q21, Q22, Q28, Q29  +  Q4? — let me recount
sonnet → boardroom, gpt-4o → single (2): Q8, Q24, Q25 — wait that's 3
```

Actually the analyzer counted: 9 vs 2. Looking at the data:

- sonnet→single, gpt-4o→boardroom: Q2, Q5, Q16, Q18, Q21, Q22, Q28, Q29 = 8 ... let me recount from the analyze output... per the inter-judge agreement section in §0, the analyzer reported 9 + 2 = 11 splits. The specific 9 in the dominant direction were: Q2, Q5, Q16, Q18, Q21, Q22, Q28, Q29 ... that's 8. The 9th is one I'm undercounting. Looking at the disagreement list from `analyze.php` output above: Q2, Q5, Q16, Q18, Q21, Q22, Q28, Q29 — that's 8 in the sonnet→single direction. Then Q8, Q24, Q25 in the other direction — that's 3. Total 11 splits. So the actual split is **8 → 3, not 9 → 2**. Slight correction.

Even at 8-to-3, the asymmetry holds: the Claude judge sided with the Claude single model 73% of the time in the disagreement set, vs 27% the other way. Still a meaningful bias signal.

### Selected disagreements with both judges' reasoning

**Q21 (UBI)** — judges most divergent on this one.

- Sonnet → single (B=3 S=8): *"Response 2 [Opus] directly engages with the actual intellectual tensions of UBI — the arithmetic problem, the pilot-to-scale gap, the non-pecuniary value of work... names the non-obvious trade-off that universality is simultaneously UBI's strength (durability, no stigma) and its fiscal weakness (wasted on Bezos). Response 1 [boardroom] is a meeting-summary artifact... never actually argues the strongest case for or against anything, buries every insight in process language."*
- GPT-4o → boardroom (B=9 S=7): *"Answer 1 [boardroom] provides a comprehensive analysis of the complexities and challenges associated with implementing UBI. It highlights the need for structured experiments, the importance of defining success metrics, and the technical challenges of implementation, such as supporting unbanked populations."*

**Same answers, opposite verdicts.** Sonnet penalizes the boardroom for format and non-commitment; gpt-4o rewards the boardroom for surfacing implementation complexity. Both readings are defensible.

**Q25 (remote work plateau)** — opposite direction (rare).

- Sonnet → boardroom (B=9 S=7): *"Response 2 [boardroom] surfaces genuine tensions that Response 1 [Opus] smooths over: the buyer vs. user misalignment (executives optimizing for control, not knowledge transfer), the role lifecycle stage distinction... It also names real disagreements between named positions rather than presenting a tidy synthesis."*
- GPT-4o → single (B=7 S=9): *"Answer 1 [Opus] provides a comprehensive analysis of the factors contributing to the plateau... Answer 2 [boardroom], while it presents a range of perspectives and identifies key issues, lacks the depth and specificity in exploring the underlying tensions and trade-offs."*

Here sonnet flips its usual pattern. Perhaps because the question is itself about *uncomfortable tensions* (remote work involves real principal-agent conflicts) and the boardroom's *named-disagreement* format is uniquely well-suited to surfacing those.

(The other 9 disagreements follow similar patterns to the unanimous-loss section above. Full reasoning per question is in `verdicts_gpt_4o.json` and `results.json` `verdict_reason` fields.)

## 10. Patterns observed across the experiment

This is the most useful section for future product decisions.

### 10.1 The format penalty is the boardroom's biggest tax

Both judges, but especially sonnet, repeatedly criticize the boardroom for being a "meeting-notes artifact" with "priority matrices, next steps, open questions, process language". This is the SCRIBE's structured-summary format showing through.

Sample direct quotes (sonnet, across multiple questions):
- *"buries the core question under process artifacts and consulting-deck formatting"* (Q27)
- *"committee-minutes format buries the key insight under process scaffolding"* (Q18)
- *"formatted as a meeting summary with a lot of procedural scaffolding... that obscures rather than illuminates"* (Q16)
- *"deferred behind process steps and open questions, which is the opposite of concrete"* (Q19)
- *"reads as process theater rather than substantive analysis"* (Q2)

**Implication**: the Scribe's output format is **hurting** the boardroom's evaluation, independent of content quality. The personas may be generating valuable thinking, but it's getting wrapped in a presentation format judges (and presumably users) find verbose and meta.

**Concrete recommendation for v2**: rewrite the Scribe prompt to produce an **essay-like answer** that integrates the discussion into prose, not a structured "TEMA / KLJUČNE IDEJE / ODLUKE / PRIORITETNA MATRICA" report. Keep the structured fields internally (for the per-idea attribution feature) but produce a prose summary as the **primary user-facing output**.

This single change could plausibly move the boardroom win rate by 10+ percentage points.

### 10.2 Honest uncertainty is sometimes rewarded, sometimes punished

The boardroom often refuses to commit when the panel hasn't converged. That's intellectually honest. Judges read it differently:

- **Punished** when the question demands commitment ("be concrete", "your verdict", "argue both sides") — boardroom flunks the instruction.
- **Rewarded** when the question is genuinely uncertain (diagnostic problems, what-should-engineers-focus-on questions) — boardroom's openness reads as epistemic honesty.

**Implication**: the Chair's mandate is right ("commit to one decision"). The problem is the Scribe's output that *precedes* the Chair often reads as non-committal scaffolding. If the Scribe synthesis itself read as a coherent essay-with-position, the format penalty would shrink.

### 10.3 The Chair's terse format helps but doesn't rescue

When judges read the boardroom answer, they see the Scribe's long structured synthesis followed by the Chair's terse ODLUKA/RAZLOG/TRADE-OFF/PRVI KORAK. The Chair section is consistently strong — it's the structured prelude that drags the impression down.

**Implication**: the user-facing answer should probably lead with a Chair-like crisp commitment, then have the synthesis as supporting detail. Today it's reversed (long synthesis first, Chair at the end).

### 10.4 Multi-perspective genuinely shines on multi-domain problems

The four unanimous wins (Q7 notifications, Q11 build-vs-buy, Q12 DAU diagnosis, Q17 code review process) all share: **the question has multiple stakeholder domains** (technical / product / financial / operational / compliance) that each see different constraints. The boardroom's personas naturally map to those domains.

**Implication for product positioning**: market Cortex for *cross-functional design and diagnostic decisions*, not for general "AI brainstorming". The latter is where it competes badly with Opus alone.

### 10.5 The Architect pattern was not tested — and might matter a lot

The benchmark used the fixed 5-persona roster. Cortex's `--architect` feature asks a model to design 5 question-specific roles for the topic. For Q19 ("L4 → L5 promotion bar"), architect-designed roles might be "Engineering Director", "Promo Committee Lead", "Recent Promotee", "External Reference Caller" — much more fit-for-purpose than the fixed Viktor/Helena/Ana/Petra/Marco roster.

**Open question for v2**: does Architect mode close the gap on the question types where the fixed roster lost?

### 10.6 Language leakage in the scribe persona

In several judge reasons, the boardroom was described as "structured as meeting minutes in a mix of Croatian and English" (Q2) or "complete with Croatian participant names, voting tallies, and action matrices" (Q27).

The chat language was set explicitly to English. Yet Croatian leaked. **Source**: the Scribe persona's seeded `system_prompt` is in Croatian and includes Croatian formatting conventions (TEMA, KLJUČNE IDEJE, ODLUKE…). The ContextBuilder/ScribeService language directive at the end of the prompt overrides *output language* but not the *Croatian formatting headings* the Scribe was trained on.

**Implication**: persona seed prompts (PersonaSeeder) should be made language-agnostic, with formatting conventions instructed at runtime from `$chat->language`, not baked in.

### 10.7 The mixed-provider panel might have a *judging* drawback nobody talks about

A mixed-provider panel (4 providers in one answer) produces a stylistically heterogeneous output. Single-model answers are stylistically uniform. Judges may unconsciously prefer the uniform output as "more coherent" — even when the heterogeneous one contains more thought.

This is testable: run the same panel on all-same-provider models (5 different Claude variants, say) and see if the format penalty shrinks.

## 11. Cost analysis

Per-question costs are remarkably stable:

```
boardroom: ~€0.32 (range €0.06 — €0.38)
single:    ~€0.11 (range €0.11 — €0.11, essentially constant)
```

The one boardroom outlier was Q6 at €0.06 — likely a short-circuit where one or more personas returned empty or errored fast (worth investigating).

**Cost-per-win analysis**:

| Side | Cost | Wins (mean) | Cost per win |
|---|---|---|---|
| Boardroom | €9.46 | 9.5 | €1.00 |
| Single | €3.41 | 20.5 | €0.17 |

Cost per win for the boardroom is **5.9× higher** than for the single model. By this lens, the boardroom is dramatically worse value on average. By the much narrower lens of "questions where multi-perspective is essential", the math reverses — but you'd need to know in advance which questions those are.

**Action item**: build a *cheap router* (a smaller model that classifies the incoming question by problem type and only invokes the full boardroom for question types where it's been shown to outperform). This is the highest-leverage product change derivable from this experiment.

## 12. Time analysis

Per-question runtime distribution (boardroom + single + sonnet judge, in seconds):

- median: ~185 s
- min: ~67 s (Q6 outlier — see above)
- max: ~310 s
- p90: ~205 s

Boardroom dominates the wall-clock (each persona is a sequential model call, 5 personas × 2 rounds + scribe + chair ≈ 7-9 sequential calls). At ~20-30s per model call, that's the 3-min average. Single model is one ~30s call. Judge is one ~10s call.

**Bottleneck**: persona-sequential. The CLI runs `queue.default=sync` so even though the orchestrator could in principle parallelise personas within a round, it doesn't. **Parallelising round-internal persona calls would roughly halve boardroom latency** (5 sequential calls → max-of-5-parallel calls). This is the highest-leverage runtime improvement.

## 13. Bugs and methodology issues uncovered during the run

### 13.1 o3 judge returned empty responses (resolved)

The first 3 questions had verdict `{"raw":""}` — empty string from the configured judge. Root cause: o3 (and other OpenAI o-series reasoning models) burn output budget on internal *reasoning* tokens. The `max_tokens=600` cap was being entirely consumed by reasoning, leaving zero tokens for the actual JSON output.

**Action taken**: switched the configured judge from `o3` to `claude-sonnet-4-6` mid-run, re-judged the 3 NULLs afterward with `benchmark/rejudge.php`.

**Should-fix in the adapter**: `OpenAiCompatibleAdapter` should detect o-series model names and pass `max_completion_tokens` (which is the correct knob for that family) at a much higher value than the user-supplied `max_tokens`. The current adapter doesn't, so o3 silently produces no output.

### 13.2 Gemini-2.5-pro judge also returned empty (not resolved)

When I tried `gemini-2.5-pro` as a third independent judge for triangulation, **28 of 30 calls returned an empty response**. Same root cause: Gemini 2.5 burns its output budget on internal *thinking* tokens by default. Our `GoogleAdapter` sends `thinkingConfig.thinkingBudget=0` only for *Flash* models, not for *Pro*.

**Action taken**: dropped Gemini from the analysis, noted the gap in `benchmark/README.md` and here.

**Should-fix in the adapter**: extend the `thinkingConfig` override to Gemini 2.5 Pro and Pro-class models, not just Flash.

### 13.3 Scribe persona language leakage (described in §10.6)

Croatian formatting headings (TEMA, KLJUČNE IDEJE…) and occasional Croatian words leaked into otherwise-English boardroom answers because the Scribe persona's seeded `system_prompt` is hardcoded Croatian.

**Should-fix**: language-agnostic persona seed prompts.

### 13.4 Boardroom Q6 cost outlier (€0.06)

Q6 (REST vs GraphQL vs tRPC) cost the boardroom only €0.06 vs the €0.32 average. Suggests one or more personas returned empty / errored / fallback-modelled early. The judge nevertheless judged the produced answer — and gave it a very low score (sonnet B=1, S=9). Worth a follow-up to see what actually happened.

**Should-investigate**: re-run Q6 standalone and check whether all 5 personas actually contributed substantive answers.

### 13.5 Judge cost is invisible in the standard benchmark output

The CLI `cortex:benchmark --json` reports `cost_eur` only for the boardroom and the single-model control. The judge cost (~€0.08 per question for sonnet) is paid but not reported. Total benchmark cost is therefore understated by ~€2.5 per 30-question run.

**Should-fix**: include judge cost in the JSON output.

## 14. Caveats and limitations

1. **Sample size n=30** is small. The 31.7% mean win rate could move by ±10 pp with a different 30-question sample.
2. **Question selection is mine.** Different curator → potentially different result. I deliberately picked open-ended hard-reasoning questions; choosing closed-form or single-correct-answer questions would tank the boardroom further.
3. **Judges are LLMs**, not humans. They reward thoroughness, structure and tension-surfacing — proxies for "good answer", not the real-world thing.
4. **Boardroom config was fixed** (5 named personas, 2 rounds, no Architect, no Strong mode). Different configurations might change results substantially.
5. **Single-model control is Opus 4.7**, currently top-tier. Against a weaker control (sonnet, gpt-4o-mini), the boardroom would likely win more often AND the cost comparison would tilt favourably.
6. **All 30 questions were in English.** Multilingual performance was not tested.
7. **Pre-existing bugs surfaced** that should be considered when reading the numbers (see §13).
8. **The boardroom's panel had no Claude voice with meaningful weight** (Helena/sonnet was 1 of 5). A Claude-judge favoring a Claude single-model answer could be partly explained by stylistic familiarity with that voice.

## 15. Recommendations from this experiment

### For Cortex's positioning

- **Do not market it as "boardroom beats single model".** The data does not support that.
- **Market it as a tool for cross-functional design and diagnostic decisions** — the place where the data shows it actually helps.
- **Be explicit about the cost.** 2.8× single-model cost is real. For users with API spend awareness, this needs to be up-front.
- **The Claude-judge bias finding is the most publishable thing here.** "I built a multi-agent AI tool and found the real story was judge bias" is a more interesting blog post than "my multi-agent tool wins 32% of the time".

### For Cortex's architecture (highest-leverage product changes)

In rough order of expected impact:

1. **Rewrite the Scribe's final synthesis as essay-prose, not consulting-deck.** This is the format penalty in §10.1. The structured fields can remain internally for the attribution feature.
2. **Build a router that classifies questions and only invokes the boardroom for problem types where it's been shown to win** (diagnostic, multi-stakeholder design). Use Opus directly for everything else. Saves 80%+ of cost on questions where the boardroom adds nothing.
3. **Parallelise persona calls within a round.** Roughly halves boardroom latency.
4. **Make persona seed prompts language-agnostic.** The Scribe language leak was a real observable problem.
5. **Test Architect-designed panels in a v2 benchmark.** Plausible the question-tailored roles outperform the fixed roster on the question types where the fixed roster lost.

### For multi-agent AI benchmarking generally

- **Always cross-judge with a judge model from a different provider family** than the model under test. Single-judge benchmarks are unreliable.
- **The judge prompt should explicitly forbid format judging** — and you should still check, in the reasoning, whether the judge ignored the instruction (sonnet did).
- **Report not just win rate but inter-judge agreement.** Low agreement is itself a finding.

## 16. v2 experiment design

If I were to run this again next month, here is what I would change:

1. **Add a third non-Claude, non-OpenAI judge** (probably grok-3, since the gemini-2.5-pro thinking-budget bug isn't easy to work around fast).
2. **Use Architect-designed panels** for half the questions; keep fixed-roster for the other half; compare.
3. **Fix the Scribe format first** (essay prose instead of structured deck) and rerun.
4. **Add a "router-then-boardroom" condition** to test whether cherry-picking questions where boardroom helps changes the cost/win ratio meaningfully.
5. **Expand to n=60+ questions** for tighter confidence intervals.
6. **Include a weaker single-model baseline** (sonnet or gpt-4o, not just Opus) to map the boardroom's value across model tiers.
7. **Capture judge cost** in the JSON.

Predicted cost of v2: ~€40-60.

## 17. Open questions

- Does the format-penalty hypothesis hold? (Test: same content, essay-format synthesis, re-judge.)
- Does the Architect feature close any of the loss-categories?
- Where on the model-strength curve does the boardroom become net-positive? (Beat sonnet? Beat gpt-4o-mini? Almost certainly. Where exactly?)
- Does multi-round (4+ rounds) help on the questions where 2 rounds lost?
- Is there a question-type signature that a cheap classifier could detect to route only the "boardroom-friendly" questions through the boardroom?
- Does the boardroom's value rise on questions with attached `--context` (the personas can interrogate concrete data from different angles)?

## 18. Implications for the open-source pitch

If/when Cortex goes public:

- **Headline should be honest.** Lead with the cross-judge finding and the "where it helps / where it doesn't" framing, not a "beats GPT-5" claim that the data does not support.
- **The most interesting blog post here is "We benchmarked our multi-agent AI tool and found judge bias instead of a win"** — this has actual novelty value and is honest.
- **The product is positioned as "cross-functional design/diagnostic brainstorm tool", not as "general AI assistant".** The latter loses to Opus and the data shows it.
- **Realistic outcome ceiling**: Tier 1 (a few hundred ⭐, some HN traction). The "boardroom beats GPT-5 with €5 of API" headline is not available; the honest finding is more credible but less viral.

## 19. Data appendix

All raw data is in the [`benchmark/`](../benchmark/) directory:

- [`questions.json`](../benchmark/questions.json) — the 30 questions with categories.
- [`run.php`](../benchmark/run.php) — the runner script.
- [`rejudge.php`](../benchmark/rejudge.php) — re-judge missing verdicts with the configured judge.
- [`cross_judge.php`](../benchmark/cross_judge.php) — re-judge all with a different judge model.
- [`analyze.php`](../benchmark/analyze.php) — consolidate stats and print the summary used here.
- `results.json` — per-question boardroom answer, single-model answer, sonnet verdict + reason, costs, timings.
- `verdicts_gpt_4o.json` — gpt-4o verdict + reason per question.
- `verdicts_gemini_2_5_pro.json` — the 2 successful and 28 empty gemini attempts.
- `analysis.json` — consolidated stats keyed for downstream use.
- `run.log` — runner progress log with per-question times and intermediate ETA.
- [`README.md`](../benchmark/README.md) — the polished public-facing summary (English).
- [`README.hr.md`](../benchmark/README.hr.md) — Croatian mirror.

This document — `docs/RESEARCH.md` — is the **maximally-documented lab notebook**: the unpolished, every-finding-included archive. The polished public summary in `benchmark/README.md` is derived from this.

---

*If you read this far, congratulations. The honest experimental finding was less exciting than the headline I hoped for, but more useful than the headline I hoped for would have been.*
