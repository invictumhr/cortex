# Eurojackpot — povijesna statistika za panel-analizu

## Osnovne informacije o igri
- Format: korisnik bira **5 glavnih brojeva (1–50)** + **2 euro broja (1–12)**.
- Prvo izvlačenje: 23.03.2012. Trenutno (19.05.2026.): **956 odigranih krugova**.
- Učestalost: utorkom i petkom (od 03/2022); prije samo petkom.
- Povijesne izmjene raspona euro brojeva:
  - 03/2012 – 09/2014: euro brojevi 1–8
  - 10/2014 – 02/2022: euro brojevi 1–10
  - 03/2022 – danas: euro brojevi 1–12

Posljedica: euro brojevi 11 i 12 imaju izrazito manje izvlačenja jer
postoje tek od 03/2022 (~420 krugova), dok 1–10 postoje cijelih 956 krugova.

## Frekvencija glavnih brojeva (1–50) — svih 956 krugova

Sortirano od najčešćeg prema najrjeđem (broj → puta izvučen):

```
20 → 112    34 → 108    35 → 108    11 → 107    17 → 107
21 → 107    16 → 106    18 → 106    49 → 105     1 → 103
 8 → 102    23 → 102    30 → 102    41 → 102    13 → 101
39 → 101     7 → 100    15 →  99     9 →  98    14 →  98
19 →  98    37 →  98    38 →  97    46 →  97    12 →  95
29 →  95     6 →  94    22 →  94    43 →  94    32 →  93
47 →  93     4 →  92    31 →  92    40 →  92     2 →  91
 3 →  91    33 →  91    44 →  91    10 →  89    26 →  88
24 →  87    27 →  85    42 →  85     5 →  83    28 →  83
36 →  83    25 →  82    50 →  82    48 →  75 (NAJRJEĐI)
```

(Broj 45 nije precizno potvrđen u izvoru — pretpostavljena srednja vrijednost ~95.)

Teorijski očekivana frekvencija po broju: 956 × 5/50 ≈ 95,6.
**Standardna devijacija oko prosjeka je ±10 izvlačenja**, što znači da
razlika između najčešćeg (20: 112×) i najrjeđeg (48: 75×) jest statistički
zamjetna, ali NIJE drastična — svi brojevi ostaju u okviru očekivanja
nasumične distribucije nakon 956 izvlačenja.

## Frekvencija euro brojeva (1–12)

```
 5 → 203 (NAJČEŠĆI)     3 → 194     8 → 190     7 → 184
 6 → 180                4 → 179     1 → 173     2 → 165
 9 → 159               10 → 145    12 →  78    11 →  62 (NAJRJEĐI)
```

VAŽNO: euro brojevi 11 i 12 imaju manje izvlačenja jer postoje tek od
03/2022. Za pošteno uspoređivanje frekvencije treba normalizirati na
broj krugova u kojima je broj uopće mogao biti izvučen:
- Brojevi 1–10: 956 krugova × 2/10 = ~191 očekivano
- Brojevi 11–12 (~420 krugova): × 2/12 = ~70 očekivano

Nakon normalizacije, 11 i 12 nisu posebno "hladni" — u svom razdoblju
funkcioniraju u okviru prosjeka.

## Statistički obrasci (svi krugovi)

- Parno/neparno (glavni brojevi): **3 neparna + 2 parna** je najčešći
  obrazac (~31% izvlačenja). Slijede 2N+3P (~28%) i 4N+1P (~17%).
- Parno/neparno (euro brojevi): **1 neparan + 1 paran** (~54%); oba
  ista (oba parna ili oba neparna) ~46%.
- Najčešći zbroj 5 glavnih brojeva: ~120 (najgušći raspon: 95–160).
  Teorijski očekivani zbroj 5 nezavisnih brojeva iz 1–50 je 127,5.
- Najčešći zbroj 2 euro broja: ~12.
- Decadni balans: tipična kombinacija sadrži brojeve iz **3–4 različite
  desetice** (1–10, 11–20, 21–30, 31–40, 41–50). Sve iz iste desetice
  ili samo dvije desetice je statistički vrlo rijetko.
- Consecutive brojevi (uzastopni, npr. 17-18): pojavljuju se u oko 35%
  izvlačenja barem jednom.

## Dobitne kombinacije — 2026. (40 najnovijih krugova)

Format: DATUM | 5 glavnih | 2 euro

```
19.05.2026 | 10 36 37 39 47 |  5  6
15.05.2026 |  1 32 33 36 37 |  7 12
12.05.2026 |  7 15 19 28 35 |  3 11
08.05.2026 |  3 17 18 31 41 |  6 12
05.05.2026 |  1 30 33 34 43 |  5 10
01.05.2026 | 10 11 13 16 27 |  5  7
28.04.2026 | 19 20 41 43 46 |  5  7
24.04.2026 |  6 21 29 39 44 |  1  5
21.04.2026 | 31 32 36 39 47 |  7  8
17.04.2026 | 16 31 35 43 44 |  2  9
14.04.2026 | 13 22 32 46 47 |  6  7
10.04.2026 |  1  6 11 18 48 | 10 12
07.04.2026 |  2  4 16 23 27 |  5  8
03.04.2026 |  9 10 18 22 37 |  1 11
31.03.2026 |  5 15 18 20 35 |  7  8
27.03.2026 | 21 23 25 38 40 |  7 11
24.03.2026 |  9 15 23 43 48 |  3  5
20.03.2026 |  2 17 21 25 30 |  2  6
17.03.2026 | 12 13 16 17 37 |  4 11
13.03.2026 |  7 23 37 44 47 |  2  6
10.03.2026 |  2  3 17 18 28 |  4 10
06.03.2026 |  8 17 26 31 47 |  1  6
03.03.2026 |  1  9 14 35 49 |  2 10
27.02.2026 |  7 17 19 28 47 |  2  7
24.02.2026 |  4  5 26 38 48 |  2  9
20.02.2026 | 11 17 23 36 40 |  5  6
17.02.2026 |  8 23 39 40 44 |  6  7
13.02.2026 |  1 21 44 45 46 |  2  7
10.02.2026 | 12 19 34 39 47 |  4  5
06.02.2026 |  8 14 38 41 48 |  1 11
03.02.2026 |  3 20 27 37 44 |  1  2
30.01.2026 |  8 13 15 17 37 |  3  7
27.01.2026 | 13 18 19 29 32 |  8  9
23.01.2026 | 18 36 39 45 50 |  6  9
20.01.2026 | 16 26 32 37 45 |  2  3
16.01.2026 |  8 16 37 39 48 |  5 11
13.01.2026 |  2 16 27 33 47 |  6 12
09.01.2026 |  1 17 19 25 41 |  6 12
06.01.2026 | 21 23 30 33 38 |  8 12
02.01.2026 | 10 15 29 34 38 |  2  9
```

## Dobitne kombinacije — 2025. (104 kruga, kompletno)

```
30.12.2025 | 10 18 20 23 27 |  1  6
26.12.2025 | 15 21 26 29 42 |  4 12
23.12.2025 | 24 29 35 40 41 |  6  7
19.12.2025 |  8  9 15 35 45 |  2  5
16.12.2025 | 12 22 28 30 31 |  4 11
12.12.2025 |  2 25 27 37 50 |  2 11
09.12.2025 |  2 30 32 33 37 |  2  9
05.12.2025 |  1  4 18 22 24 |  6 10
02.12.2025 | 14 30 34 35 40 |  4  6
28.11.2025 | 12 16 35 46 50 |  3  5
25.11.2025 |  1 23 30 35 46 |  4  8
21.11.2025 | 15 24 30 45 50 |  5  6
18.11.2025 | 19 25 27 41 49 |  3  9
14.11.2025 |  3  5 20 30 37 |  6 12
11.11.2025 |  8 24 25 41 50 |  8  9
07.11.2025 | 13 19 22 35 40 |  2  8
04.11.2025 |  3 21 22 33 39 |  1  9
31.10.2025 |  5 11 40 41 47 |  1  5
28.10.2025 |  3  4 22 45 50 |  9 12
24.10.2025 | 12 13 27 42 43 |  3  4
21.10.2025 |  6 21 30 40 46 |  3  4
17.10.2025 | 18 21 34 35 46 |  2  3
14.10.2025 |  8 12 13 49 50 |  2  4
10.10.2025 |  4  5 24 31 41 |  3 12
07.10.2025 | 10 22 38 42 48 |  2  9
03.10.2025 |  1  2  7 21 27 |  8 12
30.09.2025 |  8 11 13 24 27 |  3  7
26.09.2025 | 12 24 26 35 48 |  1  2
23.09.2025 |  7 18 31 32 33 | 10 11
19.09.2025 |  9 37 40 41 46 |  1 12
16.09.2025 |  8  9 14 37 39 |  1  9
12.09.2025 |  7 22 24 33 45 |  4 12
09.09.2025 | 14 18 24 27 50 |  8  9
05.09.2025 |  6 14 25 29 46 |  7 11
02.09.2025 |  1  5 12 38 47 |  7  8
29.08.2025 |  3  5 19 23 48 |  1  5
26.08.2025 |  8 14 21 26 35 |  4  8
22.08.2025 |  3 14 16 22 34 |  7 10
19.08.2025 |  3  4 11 33 47 |  6  9
15.08.2025 |  5 11 20 33 43 |  6 12
12.08.2025 | 11 16 29 37 42 |  1 11
08.08.2025 |  7 16 23 41 42 |  1  4
05.08.2025 |  1 18 21 22 34 |  1  6
01.08.2025 |  4 11 12 20 33 |  3  5
29.07.2025 | 20 21 38 43 49 |  6 11
25.07.2025 |  7  8 13 29 36 |  4  8
22.07.2025 |  5 20 42 46 48 |  7  8
18.07.2025 | 10 12 21 25 39 |  2  4
15.07.2025 | 13 28 33 37 45 |  6 11
11.07.2025 |  6 12 13 43 46 |  6 11
08.07.2025 | 21 27 29 34 43 |  6 10
04.07.2025 | 14 23 34 41 44 |  5 10
01.07.2025 |  1  9 10 12 14 |  6  8
27.06.2025 |  4 14 26 29 50 |  3 12
24.06.2025 | 20 31 35 40 44 |  3  4
20.06.2025 |  6 12 18 37 46 |  7  9
17.06.2025 | 10 13 15 33 35 |  7 12
13.06.2025 |  1 15 18 27 46 |  5  9
10.06.2025 |  1 17 20 28 42 |  2 12
06.06.2025 |  7  8 11 23 39 |  5 11
03.06.2025 |  6  8 19 26 30 |  1 12
30.05.2025 |  4  5 26 29 43 |  5  9
27.05.2025 |  6  9 17 25 41 |  4 10
23.05.2025 | 11 17 19 33 40 |  7 12
20.05.2025 |  8 19 20 21 28 |  7 10
16.05.2025 |  6  8 15 27 39 |  6 12
13.05.2025 | 14 16 19 33 34 |  5 12
09.05.2025 |  1  5 27 36 43 |  5  9
06.05.2025 |  1 21 22 46 49 |  9 10
02.05.2025 |  3 15 22 33 35 |  1  7
29.04.2025 | 17 21 27 30 34 |  8 11
25.04.2025 | 13 14 40 43 45 |  5  8
22.04.2025 | 10 16 23 29 38 |  3  5
18.04.2025 |  7  8 12 29 44 |  3 12
15.04.2025 |  8 11 13 33 35 |  1 10
11.04.2025 |  2 26 27 28 49 |  1 10
08.04.2025 | 17 39 40 41 47 |  5  8
04.04.2025 | 19 23 29 37 38 |  2  8
01.04.2025 | 12 17 39 41 50 |  9 12
28.03.2025 |  2 15 19 34 49 |  2  6
25.03.2025 |  3 11 30 35 50 |  4  5
21.03.2025 |  8  9 12 14 16 |  6 12
18.03.2025 |  1  7 14 47 50 |  3  7
14.03.2025 |  6 13 28 37 45 |  5 10
11.03.2025 | 15 18 22 23 44 |  1 11
07.03.2025 |  7 11 12 32 42 |  1  4
04.03.2025 |  4 12 35 37 48 |  4 10
28.02.2025 |  3  4 13 20 21 |  8 12
25.02.2025 | 28 31 38 42 48 |  3 10
21.02.2025 | 18 26 29 35 36 | 11 12
18.02.2025 |  1  9 14 19 44 |  2  3
14.02.2025 | 12 14 18 45 50 |  2 10
11.02.2025 |  3 12 22 28 47 |  1 12
07.02.2025 | 15 17 27 33 45 |  5  9
04.02.2025 | 10 18 21 41 42 |  3  9
31.01.2025 |  1 23 32 42 47 |  4 11
28.01.2025 |  2  7 28 43 46 |  5 12
24.01.2025 |  2  9 16 46 47 |  3  9
21.01.2025 |  3 17 22 28 40 |  4  9
17.01.2025 |  7  9 14 18 31 |  7  8
14.01.2025 | 10 11 17 20 30 |  2  6
10.01.2025 | 17 34 38 42 48 |  2 11
07.01.2025 |  1 16 20 23 44 |  5  9
03.01.2025 |  1 20 21 27 29 |  8 10
```

## Što panel treba znati o ZADATKU

Ovo NIJE prediktivni zadatak — Eurojackpot je nasumičan i očekivana
isplativost je negativna. Korisnik je toga svjestan.

Zadatak je intelektualna vježba: ako bismo MORALI odabrati 4 kombinacije,
koje bismo izabrali i s kojim opravdanjem? Cilj je da svaka od 4
kombinacije slijedi JASAN, DISKUTABILAN pristup koji panel može
međusobno argumentirati.

Korisnik traži:
- **Kombinacije A i B** — čista statistička frekvencija (top vrući brojevi).
- **Kombinacije C i D** — drugi heuristički parametri po izboru panela:
  due/overdue brojevi, gap analiza, balans desetica, target sum,
  parno-neparni omjer, izbjegavanje "očitih" obrazaca, popular-number
  avoidance (manje dijeljenja jackpota), itd.

Svaka kombinacija mora biti tehnički valjana:
- 5 različitih brojeva iz raspona 1–50
- 2 različita euro broja iz raspona 1–12
