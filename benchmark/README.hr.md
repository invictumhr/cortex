# Cortex Boardroom — Benchmark

**Pobjeđuje li multi-model AI boardroom jedan jak model? Testirali smo na 30 otvorenih pitanja s dva neovisna suca. Iskreni odgovor: ne by default.**

## TL;DR

| Mjera | Vrijednost |
|---|---|
| Pitanja | 30 (otvorena, teška, više-perspektivna) |
| Boardroom | 5 persona (Viktor, Helena, Ana, Petra, Marco) na 5 različitih modela kroz 4 providera, 2 kruga + Scribe + Chair |
| Single-model kontrola | Claude Opus 4.7 |
| Suci | claude-sonnet-4-6 *i* gpt-4o (dva neovisna suca, slijepi A/B) |
| **Boardroom wins (sonnet sudac)** | **7 / 30 = 23.3%** |
| **Boardroom wins (gpt-4o sudac)** | **12 / 30 = 40.0%** |
| **Prosječni boardroom win rate** | **31.7%** |
| Sudci se slažu | 19 / 30 = 63.3% |
| Trošak boardrooma po pitanju | €0.32 |
| Trošak single modela po pitanju | €0.11 |
| **Boardroom je skuplji** | **2.8×** |
| Ukupna potrošnja na benchmark | €12.87 + ~€3 za cross-sudca |
| Ukupno vrijeme izvođenja | 92 min |

**Boardroom NE pobjeđuje jedan jak model sustavno.** Konkurentan je na više-dimenzionalnim design i dijagnostičkim problemima; gubi na jasnim odlukama i sinteznim pitanjima gdje jedan jak model proizvodi oštriji odgovor. Uz ~3× troška za ~32% win-rate, **nije** isplativa zamjena za dobar single model — ali jest istinski koristan za određene oblike problema.

## Metodologija

Za svako od 30 pitanja:

1. **Pokreće se Cortex boardroom** — 5 fiksnih persona (Viktor `grok-3`, Helena `claude-sonnet-4-6`, Ana `o3`, Petra `deepseek-chat`, Marco), 2 kruga, Scribe radi finalnu strukturiranu sintezu, Chair forsira jednu odluku.
2. **Claude Opus 4.7 sam** odgovara na isto pitanje. Promptan je kao "vrhunski stručnjak" i traženo je da iznese preporuku, ključne rizike i trade-offove.
3. **Oba odgovora idu sucu**, A/B-nasumično tako da sudac ne može pogoditi koja je strana boardroom. Sudac dobiva uputu da odabere koji odgovor bolje iznosi prave napetosti, trade-offove, rizike i ne-očite kutove, i da NE nagrađuje duljinu ni samouvjerenost. Vraća strogi JSON s pobjednikom, ocjenama i obrazloženjem.

Koriste se **dva neovisna suca** da se ublaži single-judge bias:

- **claude-sonnet-4-6** (Anthropic) — ista obitelj providera kao single-model kontrola.
- **gpt-4o** (OpenAI) — različita obitelj providera.

(Pokušali smo i `gemini-2.5-pro` kao trećeg suca. Vratio je prazan odgovor u 28 od 30 poziva — poznati problem s Gemini-2.5 koji troši izlazni budžet na interno "thinking", a naš adapter to ne handluje za Pro tier. Isključili smo Gemini iz analize.)

Sve — kod benchmarka, 30 pitanja, neobrađeni boardroom i single-model odgovori, obrazloženja oba suca — u ovom je direktoriju. Bilo tko može reproducirati.

## Rezultati — headline

```
                           sonnet sudac      gpt-4o sudac
boardroom dobio            7  (23.3%)        12 (40.0%)
single-model dobio        23  (76.7%)        18 (60.0%)
```

**Prosječni boardroom win rate kroz oba suca: 31.7%.**

Single model jasno dobiva pod oba suca, ali **gpt-4o je 17 postotnih bodova draglji prema boardroomu nego sonnet**. Taj raspon je najzanimljivije otkriće cijelog eksperimenta — vidi "Claude-sudac bias" niže.

## Slaganje sudaca

```
oba suca se složila            19 / 30  (63.3%)
  - oba → boardroom               4
  - oba → single model           15
sudci se razišli               11 / 30  (36.7%)
  - sonnet → single, gpt-4o → boardroom   9
  - sonnet → boardroom, gpt-4o → single   2
```

## Claude-sudac bias je stvaran

11 neslaganja **nije** slučajno:

> Od 11 neslaganja, **9 puta je sonnet odabrao single model dok je gpt-4o odabrao boardroom**. Samo **2** su išla u drugom smjeru.

To je 9:2 nagib. Sudac iz Claude obitelji (sonnet) je dosljedno preferirao odgovor single modela iz Claude obitelji (Opus 4.7) nad mixed-provider boardroom panelom koji nije imao niti jedan Claude glas u sebi. Isti parovi odgovora, ocijenjeni ne-Claude sucem (gpt-4o), preokrenuli su se na boardroom 9 od 11 puta.

**Ovo je ozbiljan caveat koji bi svako tko mjeri multi-agent sustave trebao usvojiti:** Claude sudac koji ocjenjuje Claude odgovore nije slijep. "Pravi" boardroom win rate vjerojatno je bliži gpt-4o brojki (40%) nego sonnet brojki (23%). Ali nijedna ne pomiče glavni zaključak — boardroom **ne** pobjeđuje jedan jak model sustavno na ovom skupu pitanja.

## Gdje boardroom dobiva

**4 pitanja** gdje su se oba suca jednoglasno složila u korist boardrooma:

- **q7** *(tech_strategy)*: Kako biste arhitektirali real-time notification sustav za 10 milijuna korisnika s 99.9% delivery i sub-1-second latencijom?
- **q11** *(business)*: Build vs buy za interni tooling u kompaniji od 100 ljudi — koji faktori stvarno odlučuju?
- **q12** *(business)*: North Star metrika je daily active users ali revenue ne prati. Dijagnosticiraj uzroke i preporuči popravak.
- **q17** *(eng_management)*: Dizajniraj code review proces za tim od 20 ljudi koji hvata prave bugove bez izgaranja inženjera.

**Pattern**: više-dimenzionalni dizajn i dijagnostički problemi gdje različite stvarne funkcije (arhitektura, product, engineering, QA, operacije) svaka donosi nešto što druge propuštaju. Boardroom rasprava prirodno isplivava kutove koje bi jedan glas izgladio.

## Gdje boardroom gubi

**15 pitanja** gdje su se oba suca jednoglasno složila u korist Opusa 4.7. Reprezentativan spektar:

- **Jasne strateške odluke**: q1 (Rails → microservices), q3 (kriteriji za rewrite), q9 (zaposli vs povisi cijenu), q13 (runway vs growth), q14 (self-serve vs sales).
- **Argumenti za-i-protiv eseji**: q4 (feature flags), q20 (AI content liability).
- **Trend analiza / predviđanja**: q26 (interface 2030), q27 (AI bubble), q30 (AI agent startup failures).
- **Definiranje letvice**: q19 (L4 → L5 engineer promotion bar).

**Pattern**: kad jedan jak model može proizvesti čist, dobro organiziran odgovor, boardroom rasprava dodaje šum i verbositet. Scribe-ova strukturirana sinteza pomaže ali ne nadmašuje oštar pojedinačni glas. Chair-ova kratka odluka je dobra ali rijetko oštrija od onoga što bi Opus sam napisao.

## Rezultati po kategoriji

| Kategorija | n | Sonnet boardroom | GPT-4o boardroom | Prosjek B% | Jednoglasno B |
|---|---:|---:|---:|---:|---:|
| eng_management | 5 | 1 | 3 | 40.0% | 1 |
| business | 6 | 2 | 2 | 33.3% | 2 |
| tech_strategy | 8 | 2 | 3 | 31.2% | 1 |
| policy | 5 | 1 | 2 | 30.0% | 0 |
| open_analysis | 6 | 1 | 2 | 25.0% | 0 |

Boardroom najbolje radi na **engineering management** i **business** pitanjima, najgore na **open analysis** (interpretativna / prediktivna pitanja gdje multi-perspektivna rasprava izgleda razrjeđuje umjesto da izoštri).

## Trošak

```
Boardroom ukupno          €9.46  (€0.32 po pitanju)
Single model ukupno       €3.41  (€0.11 po pitanju)
Boardroom množitelj       2.8×
```

Plaćaš **otprilike 3× troška za ~32% win rate**. To je loš omjer trošak/korist za opću upotrebu. Razumno je za specifične oblike problema gdje boardroom uvjerljivo pobjeđuje.

## Kad koristiti Cortex boardroom?

Na temelju ovog 30-pitanja eksperimenta:

**DA, isplati se za:**

- **Više-stakeholder dizajn problemi** (arhitektura, dizajn procesa) gdje različite stvarne funkcije (engineering, product, QA, ops) zaista vide različita ograničenja.
- **Dijagnostički problemi** gdje kvar može biti na više mjesta i trebaš različite leće da isplivaš pravu hipotezu (q12 je školski primjer: "DAU raste ali revenue ne" treba marketing, product, engineering i finance kutove).
- **Kad već imaš jak stav** i želiš ga izazvati iz kutova koje možda nisi razmotrio. Boardroom je uređaj za prisilnu perspektivu, ne stroj za mudrost.

**NE, koristi jedan jak model (Opus 4.7 ili ekvivalent) za:**

- **Jasne strateške odluke** s malim brojem dobro razumljivih opcija.
- **Argumenti za-i-protiv eseji** — jedan jak model može oba slučaja čisto raščlaniti.
- **Sinteza i artikulacija odluke** gdje je odgovor artikulacija, ne rasprava.
- **Trend analiza i predviđanja** — dodavanje glasova ne dodaje vidovitost.
- **Bilo što gdje bi prihvatio prvi pristojan odgovor**. 3× trošak se ne isplati za ~30% upside kad je "loss" single modela ipak obično sasvim dobar odgovor.

## Caveati

1. **Sample size je mali** (n=30). Prosjek od 31.7% mogao bi se pomaknuti za ±10 postotnih bodova s drugačijim skupom pitanja.
2. **Pitanja sam ja birao.** Odabrao sam otvorena teško-rasudbena pitanja; drugi kurator mogao bi nagnuti rezultat u oba smjera.
3. **Suci su LLM-ovi**, ne ljudi. Nagrađuju temeljitost, strukturu i isplivavanje trade-offova — proxy za "dobar odgovor", ne sama stvar. Stvarna vrijednost ovisi o tome djeluješ li na odgovoru i radi li djelovanje.
4. **Boardroom panel je bio fiksan** (5 persona, bez Architecta, 2 kruga). Rezultati bi mogli biti drugačiji s Architect-skrojenim panelima ili više krugova. Vrijedi za naknadni benchmark.
5. **Single-model kontrola je Opus 4.7**, trenutno top-tier model. Protiv slabije kontrole (sonnet, gpt-4o-mini), boardroom bi vjerojatno češće dobivao. Cost comparison bi se isto poljepšao.
6. **Svih 30 pitanja bilo je na engleskom.** Cortex-ova višejezičnost (HR/EN + 21 dodatni ISO kod) nije testirana ovdje.
7. **Jedan postojeći bug** isplivao je usput: o3 je originalno bio konfigurirani sudac i vraćao je prazan odgovor na prva 3 pitanja (reasoning tokeni pojeli su mu 600-token izlazni budžet). Promijenili smo suca na sonnet u tijeku, ona 3 pitanja re-sudili nakon. Adapter bi se mogao popraviti da pravilno handla o-seriju, što bi omogućilo o3 kao treći neovisni sudac.

## Reprodukcija

```bash
# Cijeli benchmark (~90 min, ~€13)
php benchmark/run.php

# Popuni nedostajuće verdicte konfiguriranim sucem (default sonnet)
php benchmark/rejudge.php

# Cross-sudi svih 30 drugim sucem
php benchmark/cross_judge.php --judge=gpt-4o

# Konsolidiraj i ispiši analizu
php benchmark/analyze.php
```

Ulazi i izlazi u ovom direktoriju:

- `questions.json` — 30 pitanja s kategorijom.
- `run.php`, `rejudge.php`, `cross_judge.php`, `analyze.php` — skripte.
- `results.json` — per-pitanje boardroom odgovor, single-model odgovor, sonnet verdict, troškovi, vremena.
- `verdicts_gpt_4o.json` — verdicti gpt-4o suca po pitanju.
- `analysis.json` — konsolidirana statistika korištena za pisanje ovog izvještaja.
- `run.log` — log napretka runera.

## Iskreni take-away

Ako si došao očekujući "Cortex-ov multi-model boardroom pobjeđuje GPT-5", **odgovor je ne — ne pobjeđuje.** *Konkurentan* je na pravoj vrsti problema; *rasipan* je na krivoj.

Zanimljiviji nalaz je **Claude-sudac bias**: Claude sudac dosljedno je preferirao Claude single-model odgovor nad mixed-provider boardroomom, 9 prema 2 u skupu neslaganja. Bilo tko tko mjeri multi-model sustave treba koristiti suce iz druge provider obitelji od modela koji se testira.

Cortex ostaje promišljen, ispoliran alat za **strukturirani više-perspektivni brainstorming**. Nije besplatni ručak. Koristi ga kad problem zaista profitira od više kutova. Za sve ostalo, jedan jak model je brži, jeftiniji i — po ovim sucima, na ovim pitanjima — češći pobjednik.

---

*Benchmark izveden: 2026-05-20. Cortex commit: vidi git log. Pitanja, neobrađeni odgovori i verdicti: ovaj direktorij.*
