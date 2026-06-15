---
typ: Testkatalog (konsolidiert)
stand: 2026-06-11
status: ausgearbeitet
---

# 09 — Testkatalog

> **Abnahme-Dokument des Ports.** „Parität mit der Alt-App erreicht" ⇔ alle hier registrierten Golden-Tests grün + Seed-Verifikation ohne unerklärte Diffs. Dieses Dokument **dupliziert keine Testdaten** — die §5-Sektionen der GL-Specs (`04_GRUNDLOGIKEN/`) bleiben die normative Quelle jedes einzelnen Falls. Hier stehen Aggregation, Organisation und die Checks, die in keiner einzelnen GL leben.
>
> **Verbindlichkeits-Hierarchie** (gilt überall): Golden-Testfall > Entscheidungstabelle > Pseudocode (GL-04 §-Kopf, GL-10 §5).

## 0. Test-Harness (M0-05-Entscheid, 2026-06-11)

**Entscheid: Tests laufen über die Host-App, nicht über orchestra/testbench.**
Begründung: platforms-core bootet nicht standalone (undeklarierte composer-Deps, Passport,
Host-App-Pflichten — `_SANDBOX_NOTES.md` Befunde 1–5); ein Testbench-Harness müsste den
kompletten Sandbox-Bootstrap duplizieren. Lokal ist die Host-App die Sandbox
(`~/GIT.HUB/sandbox-food-alchemist`), später kann dieselbe Suite in jeder Host-App laufen,
die das Modul installiert hat.

**Aufbau:**
- Tests liegen **im Modul-Repo** unter `tests/` — Namespace `Platform\FoodAlchemist\Tests\`
  (deklariert im Modul-`composer.json` `autoload-dev`; sie wandern mit dem Modul).
- Feature-Tests erben von `Platform\FoodAlchemist\Tests\TestCase` (bootet die Host-App über
  Laravels Autoloader-Pfad-Inferenz — kein `createApplication()`-Override nötig).
  Pest-Files binden das per `uses(TestCase::class)` **pro Datei** (kein zentrales Pest.php nötig).
- Die Host-App mountet die Suite: phpunit.xml-Testsuite `FoodAlchemist` →
  `vendor/martin3r/platform-foodalchemist/tests` + `autoload-dev`-PSR-4-Mapping auf denselben Pfad.

**Ausführen:** `cd sandbox-food-alchemist && vendor/bin/pest --testsuite=FoodAlchemist`
(oder ohne `--testsuite` für alles). Pest 4 ist in der Sandbox als dev-Dependency installiert.

**Bekannte Lücken (bewusst offen):**
1. Sandbox-Test-DB ist SQLite `:memory:` — Pflicht-Regel 2 (§1: Feature-Tests gegen
   **PostgreSQL** wegen NULL-Sortierung) ist damit noch nicht erfüllt. Postgres-Test-Connection
   spätestens mit den GL-03-Tests (M3-06) nachrüsten.
2. ~~`RefreshDatabase` gegen den vollen Migrations-Satz scheitert an den MySQL-only-Core-Migrationen~~
   → **gelöst durch M0-06** (2026-06-11): `tests/Support/SeedsTeamHierarchy` migriert **selektiv**
   (Cores 2 teams-Migrationen per `--realpath`-Dateipfad + komplettes Modul-Migrations-Verzeichnis)
   in die `:memory:`-DB und seedet Root + 2 Geschwister-Kinder. Kein `RefreshDatabase` nötig —
   jeder Feature-Test bekommt ohnehin eine frische `:memory:`-Connection. Muster für weitere
   DB-Fixtures: gleiche selektive `--path`-Liste erweitern. Achtung: setzt No-op-`LogsActivity`
   voraus (Sandbox-Stub); mit echtem Activity-Log dessen Migration ergänzen.

## 1. Test-Strategie — drei Ebenen

| Ebene | Was | Werkzeug | DB |
|---|---|---|---|
| **(a) Golden-Unit-Tests** | Deterministische Kernlogik als PHPUnit-Datasets, 1:1 aus den GL-§5-Tabellen (Tokenizer, Score-Modell, Slugs, Render, Kategorisierung, Merge-Matrizen, Compose, Fence-Stripping) | PHPUnit `#[DataProvider]`, pure Functions | keine |
| **(b) Feature-Tests** | Services gegen Seed-Fixtures: Aggregations-Pipelines (GL-01/02/08/09), Lead-LA (GL-03), LA-Match (GL-05), Hüllen-Resolver (GL-06), KI-Lebenszyklus (GL-07), Pairing (GL-10), GL-04-Integrations-Cases, Domänen-Akzeptanz (§6) | PHPUnit Feature / Pest, `RefreshDatabase` | ja |
| **(c) Seed-Verifikation** | Einmalige + wiederholbare Artisan-Checks nach dem ETL: Row-Counts, Diff-Reports, Spotchecks, Symmetrie-Gates (§7) | `foodalchemist:seed-verify`-Kommando | Ziel-DB voll |

**Pflicht-Regeln:**

1. **Schwellen-Assertions wörtlich übernehmen** (`≥ 0.85`, `< 0.5` …), NIE auf Punktwerte „verschärfen" — die konkreten F1-Werte sind kein API-Vertrag, nur die Bänder (GL-04 §5 PHPUnit-Hinweis).
2. **Feature-Tests gegen PostgreSQL ausführen, nicht SQLite** — NULL-Sortierung ist Teil der getesteten Logik (GL-03 A-2: SQLite NULLS FIRST würde die Port-Falle re-importieren). Ausnahme: GL-04-Integrations-Fixtures dürfen `:memory:` nutzen (keine NULL-Ordnung beteiligt), MÜSSEN aber stabil `ORDER BY id ASC` iterieren (GL-04 Invariante 7 / W-4).
3. Pro GL ein Test-File; jeder Dataset-Eintrag trägt die GT-ID aus der GL als Name (Rückverfolgbarkeit Fehlschlag → Spec-Zeile).

**Paritäts-Definition (präzise):** Parität ist erreicht, wenn
(a) alle **206 Golden-Cases** (Matrix §2) grün sind — wobei die SOLL-Kategorie (§3) gegen die **Spec** verifiziert wird, nie gegen die Alt-App;
(b) die Domänen-Akzeptanz-Szenarien (§6) grün sind;
(c) die Seed-Verifikation (§7) durchläuft und **jede** Abweichung Ziel ↔ Quell-DB einem dokumentierten SOLL-Delta zuordenbar ist (Lead-LA-NULLS-LAST, Derivat-LIVE, Zusatzstoff-Normalisierung, …). Unerklärte Diffs = keine Parität.

## 2. Golden-Test-Matrix (206 Cases über 13 GLs)

Anzahl pro GL **nachgezählt** gegen die §5-Sektionen (Stand 2026-06-11). „Quelle": R = portierte Rust-Tests · DB = gegen `wawi_1494.sqlite` verifizierte Realfälle · S = SOLL-konstruiert/synthetisch.

| GL | Thema | Tests | Quelle (R/DB/S) | PHPUnit-Ziel-Klasse (`Platform\FoodAlchemist\Tests\…`, s. §0) | Besondere Port-Fallen |
|---|---|---|---|---|---|
| GL-01 | Allergen-Aggregation | **8** (GT-01…08) | 5 DB / 2 S / 1 SOLL | `Feature\Golden\AllergenAggregationTest` | **Merge-Rangfolge:** `unbekannt` ist der NIEDRIGSTE Rang (Rust-Kommentar Z. 6961 behauptet das Gegenteil — Code/Regelwerk gewinnen, GL-01 §4.1); GP-Override ist absolut, wird nie gemax-t (GT-07); F7.1-Guard = Totalreset |
| GL-02 | Kosten/Yield/Topologie | **8** (GT-1…8) | 2 DB / 5 S / 1 offen (GT-5) | `Feature\Golden\CostYieldRecomputeTest` | **Rundungs-Reihenfolge I7:** Nenner = bereits gerundetes `yield_kg` (GT-2: 1.22, nicht 1.23); Kahn-Topo-Sort verbindlich (Python-Spiegel A-4 sortiert NICHT); GT-5 erst nach A-1-Entscheid fixieren |
| GL-03 | Lead-LA-Wahl | **7** (GT-1…7) | 2 DB / 5 S | `Feature\Golden\LeadLaPickerTest` | **NULLS LAST explizit deklarieren** (A-2, GT-1: Soll-Lead 29344887 ≠ Ist-Lead 31141191); Stufe 5 `supplier_item_id ASC` für Determinismus (I1); Stufen sind Sortier-Kriterien, keine Filter (I3) |
| GL-04 | Zutat→GP/Sub-Matching | **96** (GT-T01…T94c) | 96 R (84 recipe_matching.rs + 7 stemming.rs + Fixtures) | `Unit\Golden\IngredientMatching\{Tokenizer,ScoreModel,Threshold,SubTypHint,PoolPriority,Tiebreaker,DefaultAlias,Shortlist}Test` | **ORDER BY id ASC-Determinismus** (Inv. 7/W-4, GT-T62 hängt daran); KEIN Akzent-Folding portieren (A-5/W-1 erst NACH Paritäts-Suite); Konstanten-Namen beibehalten (Inv. 8); `match_method`-Enum-Cast (Bug A-10 `override_sub` nicht portieren) |
| GL-05 | LA↔GP-Match-Gate | **12** (GT-05-01…12) | 5 DB (reale LA/GP-IDs) / 7 S, davon 2 SOLL | `Feature\Golden\LaGpMatchGateTest` | Score-Skala intern 0.0–1.0, Anzeige 0–100 (A4); Gap-Kriterium + EAN-Stufen sind SOLL (§3); `manual` ist sticky (I2); `klassifikator` ≠ `match_method` (A1) |
| GL-06 | Hüllen-Resolver | **10** (GT-06-1…10) | aus `layers.rs` abgeleitet, gegen Hüllen-Seed (14/21) | `Feature\Golden\SemanticLayerResolverTest` | Nur `active_version_id`-Zeiger entscheidet, nie höchste semver (GT-06-10); `ORDER BY key` pro Scope; unparsbares `enabled_modules` ⇒ konservativ raus (GT-06-7); GT-06-2 dokumentiert Ist-Drift → §3 |
| GL-07 | KI-Vorschlags-Lebenszyklus | **11** (GT-07-1…11) | code-verifiziert (commands.rs) | `Feature\Golden\AiProposalLifecycleTest` | Override-First-Check VOR jedem Write, eine TX (GT-07-1/2); Confidence-Clamp [0,1]; zeilenbasiertes Cap-Budget zählt manuelle Zeilen mit (GT-07-5: Budget 3−2=1); Gap-Surfacing statt stillem Verwerfen (GT-07-7) |
| GL-08 | Nährwert-Aggregation | **6** (GT-01…06) | 5 DB / 1 S | `Feature\Golden\NutritionAggregationTest` | **KEIN F7.1-Guard** (Kontrast zu GL-01/09, GT-04!); fehlender Einzel-Nährstoff = 0.0, nicht NULL (GT-02); Salz = `sodium_mg × 0.0025`; Rundung 1/2/3 Stellen je Feld; Basis Rohmasse, nicht Yield |
| GL-09 | Zusatzstoff-Aggregation | **6** (GT-01…06) | 5 DB / 1 S | `Feature\Golden\AdditiveAggregationTest` | Wertedomäne **{0, 1, 3, NULL}** mit 3 = ja (A1 — nicht „0/1/NULL" aus dem Regelwerk-Wortlaut); beide NULL-Wege (Guard vs. leerer MAX) müssen identisch enden (GT-04); bei Tri-State-Normalisierung gelten Tests mit übersetzten Werten |
| GL-10 | Pairing-Kohäsion/Graph | **9** (T1…T9) | 5 DB (T4–T8, CLI-reproduzierbar) / 4 Logik | `Feature\Golden\PairingCohesionTest` | Queries setzen **Kanten-Symmetrie voraus** (Inv. 4, V-23); Gewichte 1.0/0.75/0.5 ≠ Sortier-Priorität 1/2/3; Identitäts-Anker ist GERICHTET (T3); unbewertet ≠ Clash (Inv. 5) |
| GL-11 | Preislogik | **7** (GT-1…7) | 6 DB / 1 S (GT-7 = SOLL, W-1 offen) | `Unit\Golden\PriceCategoryTest` + `Feature\Golden\ActivePriceTest` | `status` sind **Strings** `'0'`/`'2'`; `price < 0`-Regel greift VOR status (GT-1 LA 31303090); `qty` NULL/0 ⇒ NULL, nie Division (I4/GT-5) |
| GL-12 | GP-Naming & Slugs | **15** (GT-12-01…15) | 4 R / 6 §19-Beispiele / 4 §12-Anti-Patterns / 1 Stemmer | `Unit\Golden\GpNamingSlugTest` | **`slugify` byte-identisch portieren, NICHT `Str::slug()`** (I6 — sonst kollidieren gp_keys mit 7.774 Seed-GPs); zwei getrennte Slug-Funktionen behalten (A3); gp_key immer 3 Slots (GT-12-09) |
| GL-13 | Wissenskontext | **11** (GT-13-1…11) | 5 R / 6 code-abgeleitet | `Unit\Golden\KnowledgeContextTest` (Tokenize/Filter) + `Feature\Golden\KnowledgeDiscoveryTest` | Budgets exakt (4.000/6.000/1.200/1.400 Z.) + Kürzungs-Marker wörtlich (GT-13-7); fehlende Quelle = leerer Block, nie Fehler (GT-13-9); Stil-Filter scannt nur `###`-Untersektionen (GT-13-5) |

**Σ = 206 Golden-Cases.** Dazu kommen (nicht in der GL-Zählung): die **7 Fence-Stripping-Datasets** aus `gemini.rs:788–859` → `Unit\Golden\JsonFenceStrippingTest` (06_KI_SPEZIFIKATION §3.4 Nr. 2 verlangt die Übernahme hierher) sowie die Gateway-/Audit-Tests aus §5.

## 3. SOLL-Tests (Ist weicht ab) — NIEMALS gegen die Alt-App verifizieren

Diese Golden-Tests fixieren **SOLL-Verhalten, das im Rust-/SQLite-Ist fehlt oder falsch ist**. Wer sie gegen die Alt-App oder die Quell-DB „nachprüft", bekommt rot — das ist korrekt und gewollt. Im Code mit PHPUnit-Group `#[Group('soll')]` markieren.

| Test | SOLL (Ziel) | Ist-Verhalten | Anker |
|---|---|---|---|
| GL-01 **GT-06** | Derivat-GP erbt Allergene LIVE vom Mutter-GP (`derivat_von_gp_id`, eine Ebene) | kein Mutter-Join ⇒ Derivat liefert keinen Beitrag (`unbekannt`) | GL-01 ⚠A2, Regelwerk GP §16/§11.2 |
| GL-02 **GT-5** | Verlust-Formel — **offen**: Regelwerk additiv `(1−putz−gar)` = 700 g vs. Ist multiplikativ = 720 g; Test erst NACH Entscheid auf genau einen Wert fixieren | multiplikativ aus Zutat-Feldern | GL-02 ⚠A-1 → `08_ENTSCHEIDUNGEN.md` |
| GL-02 **GT-7** | Tiefe > 3 wird beim Speichern GEBLOCKT | nur Warn-Flag `exceeds_limit` | GL-02 A-5/I3 |
| GL-02 **GT-8** | VK-Vorschlag als abgeleitetes Attribut (ALC-Rechnung) + Invariante I9 (Recompute schreibt `vk_*` nie) | keine Auto-Berechnung im Ist (Editor nie gebaut) | GL-02 §3.6, D-6 AT-1 |
| GL-02 **A-3** (kein GT — Feature-Test ergänzen) | `yield_kg_manual` nullable mit Vorrang: Anzeige = `COALESCE(yield_kg_manual, yield_kg)`; Recompute überschreibt den Override nie | Spalte existiert nicht, `yield_kg` wird immer überschrieben | GL-02 ⚠A-3, Regelwerk F6.1 |
| GL-03 **GT-1** | NULLS LAST: Lead für GP 6723 = LA **29344887** (3,59 €/l) | SQLite NULLS FIRST: Lead = 31141191 (qty NULL) | GL-03 ⚠A-2; Seed-Diff-Report §7.2 |
| GL-03 **GT-4** (Zusatz-Assertion) | Gewinner verletzt §8-Filter ⇒ Flag `needs_lead_la_review` | Flag nicht implementiert | GL-03 T2/A-4, V-10 |
| GL-05 **GT-05-05** | Gap-Kriterium: Top1−Top2 < 0.15 ⇒ kein Auto-Match trotz Score ≥ 0.95 | Gap nicht implementiert (nur Score + Allergen-Gate) | GL-05 A2, D-2 AT-02 |
| GL-05 **GT-05-10** | EAN-Stufen deterministisch VOR KI; EAN-Bluff ⇒ Review | EAN-Stufen im LA-First-Ist gar nicht aktiv | GL-05 A5, D-2 AT-03 |
| GL-11 **GT-7** | aktiver Preis = neueste aktive Zeile (12,00 €) — Detail-Weiche W-1 (MIN vs. neueste) vor Fixierung entscheiden | Kalkulation liest neueste Zeile OHNE Kategorie-Filter; Lead-Wahl MIN | GL-11 ⚠A-2/W-1 |
| GL-12 **GT-12-04** | Eingangs-Normalisierung `tiefgekuehlt`→`TK`, dann valide | Hard-Error §9 bei Langform (Rust-Test!) | GL-12 A2 |
| GL-06 **GT-06-2** | Ist-Test dokumentiert die Modul-Key-Drift (`rezept` ⇒ nur globale Hülle). **Ziel invertiert das:** unbekannter Modul-Key ⇒ typisierte Exception — GT-06-2 wird im Ziel durch den Ablehnungs-Test ersetzt | Drift schluckt Modul-Hüllen still | GL-06 4.3, D-4 AT-2, V-06 |
| GL-09 **GT-06 (b)** | Bei Tri-State-Normalisierung (`3→true, 1→false, 0→NULL`) gelten die Matrix-Fälle mit übersetzten Werten | Quell-Kodierung {0,1,3,NULL} | GL-09 4.1 |

Nachgelagerte Feature-Schritte mit eigenen künftigen Golden-Tests (NICHT Teil der Paritäts-Suite): GL-04 W-1 Akzent-Folding (crème fraîche), V-05 Decompounding (GL-04 §6.2 Nr. 4 listet die Testfälle).

## 4. Fixture-Strategie (Seed-Fixtures für die Feature-Tests)

Die in den GLs referenzierten **realen** Fälle werden als minimale, versionierte Fixtures extrahiert (Seeder `Tests\Fixtures\Golden*Seeder`) — nicht die ganze Quell-DB laden:

| Fixture | Inhalt (Quell-IDs, wandern per Seed mit) | Genutzt von |
|---|---|---|
| **Mini-GP-Pool „Varianten/Zustand/Kalbsfond/Alias"** | In-Memory-Pools exakt wie in den Rust-Tests definiert (GL-04 §5.6-Kopf + GT-T51/T83/T91) | GL-04 Integrations-Cases |
| **Stub-Rezept 727** (0 Zutaten) | leeres Rezept | GL-01 GT-01, GL-08 GT-03, GL-09 GT-04 |
| **Rezept 1115 „SORBET SÜSS"** | 4 Zutaten, eine `gemini_proposed` conf 0.8 < 0.85 | F7.1-Kontrast-Trio: GL-01 GT-02, GL-08 GT-04, GL-09 GT-03 |
| **Rezept 313 + Sub 638** | Sub-Vererbung + Derivat-GP 7816 (Hühnerfett), GPs 1767/2014 | GL-01 GT-03, GL-09 GT-05; Derivat-SOLL GT-06 (Mutter 7827) |
| **Mornay-Kette 1561 → 1560 (Béchamel)** | GPs 3192/2556/2193 + 6265 (Mehl, gluten=3) + 3235 | GL-01 GT-08, D-5 Generator-E2E, Propagations-/Topo-Tests |
| **Rezept 1612 „ROTE-BETE-FOND"** | 6 Zutaten, alle Kosten-Pfade (GPs 5372/1587/5397/6403/4247/7692) | GL-02 GT-1, GL-11 GT-2/GT-6 |
| **Rezept 1340 „ROTE BETE GEL"** | referenziert 1612 + GP 1767 (Agar) | GL-02 GT-2 (Rundungs-Invariante I7, Topologie) |
| **GP 6723 „Limettensaft" mit 14 LAs** | komplette Kandidaten-Tabelle aus GL-03 GT-1 inkl. Preiszeilen | GL-03 GT-1, GL-11 GT-1/3/4/5, AT-D2-04, Diff-Report |
| **GP 2151 „Brotkonfekt" + Stamm-Matrix WG 09** | Edna Backwaren als Stamm | GL-03 GT-2, AT-D2-05 |
| **GP 3672 „Cornflakes" (3 LAs, gluten {3,3,2})** | LA→GP-Merge | GL-01 GT-04, D-3 AT-5, Spotcheck §7.3 |
| **Rezept 220 „Nass-Marinade"** | GPs 4534/3686/5818 mit Nährwert-AVGs | GL-08 GT-01/02 (Salz-0.0-Falle) |
| **Rezepte 195 + 175** | declarations-Profile | GL-09 GT-01/02 |
| **Rezept 1330 + Sub 315** | Nährwert-Sub-Pfad | GL-08 GT-05 |
| **Pairing-Set: Rezepte 1571, 174, 612** + Graph-Ausschnitt (erdbeere/basilikum/balsamico-Kanten) | Kohäsion/Bridge/Suggest real | GL-10 T4–T8, D-7 AT-1 |
| **LA-Trio Frischeteam 28516985/86/77 + GPs 29/30/22** | Match-Kaskade real | GL-05 GT-05-01…04 |
| **Hüllen-Seed: 14 `ai_layer` / 21 Versionen** | komplettes Inventar GL-06 §4.3 | GL-06 GT-06-1…10, D-4 AT-1 |
| **Knowledge-Set: 7 Cross-Cutting-Docs + Salbei-Pairing-Fixture** | GL-13 GT-13-3-Struktur (Klassisch/Modern/Kontrast/Verbund/Notizen) | GL-13 GT-13-3…11 |
| **Foodbook „2027"** (8 Kapitel / 7 Blöcke) | Aggregat-Golden-Dataset | D-8 AT-2 |
| **Aufschlagsklassen-Stammdaten** (ALC 420 % …) | VK-Vorschlag | GL-02 GT-8, D-6 AT-1/3 |

Regel: Fixtures tragen die Quell-IDs im Namen; ändert der finale Seed eine ID, bricht der Test sichtbar statt still falsch zu laufen.

## 5. Gateway- & KI-Audit-Tests (aus `06_KI_SPEZIFIKATION.md`)

Audit-Pflichten werden als Testfälle erzwungen (Klasse `Feature\AiGatewayAuditTest` u. a.):

1. **Log-Pflicht Erfolgsfall** — jeder Call genau 1 `ai_call_log`-Zeile VOR Rückgabe, `callLogId` im DTO (GL-07 GT-07-10; KI-Spec §5 Nr. 1).
2. **Log-Pflicht Fehlerpfad** — Timeout/JSON-Parse ⇒ trotzdem Log-Zeile mit befülltem `error` (try/finally im Gateway; Ist-Lücke!). Deckungsgleich mit D-4 AT-3.
3. **`accepted_at`-Stempel-Pflicht** in der generischen Accept-Action — Regression gegen die drei Ist-Features ohne Stempel (KI-Spec §5 Nr. 3).
4. **Fence-Stripping:** 7 Rust-Datasets (`gemini.rs:788–859`) 1:1 portieren — Tiefen-Zähler, String-Escapes, Trailing-Müll, unbalancierte Truncation bleibt ehrlicher Parse-Fehler (§3.4).
5. **Finish-Reason-Matrix:** `SAFETY`/`RECITATION` ⇒ typisierte Exception ohne Retry; `MAX_TOKENS` akzeptiert; leere candidates ⇒ `AiUnexpectedResponseException` + Audit (§3.6).
6. **Backoff + Modell-Fallback:** Treppe 1/3/10 s, danach EINMALIGER Fallback (nicht vom Fallback aus); `AiResult.model` = tatsächlich genutztes Modell (§3.1/3.2).
7. **Degenerations-/Struktur-Retry:** Temperatur 0.3→0.5→0.7 bei Feature-Flag; `structuralRetry` max 3 bei leerem Pflicht-Array (§3.3).
8. **Prompt-Kompositions-Reihenfolge 1→6** (Hüllen → Ad-hoc-System → Wissen → Task → Daten → Attachments) per Snapshot-Test; volatile Blöcke stehen hinten (§6).
9. **Bulk-Resume-Idempotenz:** abgebrochener Bulk-Run über 100 Ziele verarbeitet beim Resume exakt die restlichen; Override-First pro Item (§4; D-4 AT-4).
10. **Kosten/Billables:** Dashboard-Summe == Summe Log-Zeilen pro Team/Feature; jeder Call inkrementiert genau einmal (§5; D-4 AT-5/6).

## 6. Akzeptanz-Szenarien pro Domäne (Titel + Verweis — normative Texte in den D-Specs §7)

| Domäne | Szenarien | Kern-Themen |
|---|---|---|
| **D-1 Vokabulare** | AT-D1-01…08 (8) | Merge-Transaktion, Delete-Guard, Rename-Propagation, Inaktiv-statt-Löschen, V-20-CRUD, Review-Queue-Klassifikation, Lookup-Dropdowns, Enum-Typsicherheit |
| **D-2 Lieferanten** | AT-D2-01…07 (7) | Review-Queue V-10 (597 LAs), volles §5-Gate (SOLL), EAN vor KI (SOLL), Lead-NULLS-LAST (SOLL), Stamm schlägt Preis, sticky manual, Read-only-Policies ⚠D1 |
| **D-3 Grundprodukte** | 5 | Dubletten-Guard (gp_key), Allergen-Autopilot E2E (eine TX), Derivat-LIVE (SOLL), Merge ohne halbe Zustände, Aggregat-Parität GP 3672 |
| **D-4 KI-Infrastruktur** | 6 | Resolver-Parität, Drift-Härtung (Exception statt stiller Degradation), Fehlerpfad-Audit, Bulk-Resume, Kosten-Abrechnung, Dry-Run-Sicherheit |
| **D-5 Basisrezepte** | 9 | GL-04-Paritäts-Suite (96!), GL-02-Suite, Recompute-Pipeline, Mornay-Generator-E2E, V-07-Rollback, Stub-Lebenszyklus, Hierarchie-Guards, UI-Verträge, Naming |
| **D-6 Verkaufsrezepte** | 9 | GT-8 + I9, Marge-Cockpit-Vertrag, W-1-Guard (deckungsbeitrag ⇒ Exception), Pipe-Naming, Speisen-Klassen-Lebenszyklus, V-19-Regeneration, Verwendungsnachweise, Scope-Härte, Vokabular-Schutz |
| **D-7 Pairing** | 8 | GL-10 T1–T9, Caps als Service-Regel, manual überlebt, Symmetrie-Gate (V-23), Determinismus + < 200 ms, Graph-UI, Neutral-Semantik, Team-Scoping |
| **D-8 Foodbook** | 8 | Baum-Invarianten, rekursives Aggregat (Fixture „2027"), Block-Atomik, Live-Kombination, Chat-Lebenszyklus, Approval-Pflicht für Schreib-Tools, Tool=Service-Parität, Snapshot/PDF ohne `interne_bemerkung`-Leak |

Σ 60 Akzeptanz-Szenarien. Sie referenzieren die GL-Golden-Tests, duplizieren sie nicht.

## 7. Seed-Verifikations-Checks (`foodalchemist:seed-verify`)

### 7.1 Row-Count-Abgleich (Plausibilitäts-Anker, Stand Quell-DB 2026-06-10 — Stichtags-Zahlen beim finalen Seed neu ziehen und im Report festschreiben)

| Ziel-Tabelle | Erwartung |
|---|---|
| `gps` | 7.774 gesamt / 7.694 aktiv im Matching-Pool (approved+tentative, nicht platzhalter) |
| `recipes` (Sub-Rezept-Pool) | 1.402 (Filter GL-04 §2.2) |
| `supplier_item_structures` | 9.803 (9.679 mit GP / **597 `needs_review`** → V-10-Startbestand) |
| `prices` | 221.591; Kategorien-Verteilung: eingestellt 109.629 / standard_ek 104.225 / aktion 7.319 / datenluecke 416 / service_charge 2 (GL-11 T1) |
| `vocab_pairing_anker` / `pairing_anker_edges` | 767 / 23.951 (12.435 klassisch / 7.902 modern / 3.614 kontrast) |
| `gp_ankers` / `recipe_anker_mapping` / `recipe_prozess_anker` / `recipe_pairings` | 9.509 / 3.575 / 303 / 24.616 |
| `ai_layer` / `ai_layer_version` | 14 / 21 (GL-06 §4.3-Inventar exakt) |
| `recipe_ingredients` nach `match_method` | gemini_proposed 5.410 · override_gp 2.569 · manual 1.289 · gp_v2_fk 154 · recipe_ref 92 · override_subrecipe 76 (GL-04 §2.3) |

### 7.2 Diff-Reports (Pflicht VOR Produktiv-Übernahme)

1. **Lead-LA-Diff (GL-03 A-2 — der wichtigste!):** `pick_lead_la()` (Ziel, NULLS LAST + Normalisierung) pro GP gegen `lead_la_supplier_item_id` (Ist) rechnen → Report aller Abweichungen. Jede Differenz muss einem A-2-/A-3-Fall zuordenbar sein (GT-1-Muster: qty-NULL-Gewinner, Einheiten-Mix). Danach GL-02-Bulk ⇒ **EK-Diff-Report**: Abweichungen bei `ek_total/ek_per_kg` nur dort erlaubt, wo der Lead sich geändert hat oder ein dokumentierter Entscheid (A-1 Verlust-Formel) greift.
2. **`klassifikator → match_method`-Mapping** (GL-05 A1): nach dem Seed 0 Zeilen außerhalb des §12-Enums; Audit-Query `GROUP BY match_method` gegen Tabelle D. Analog `recipe_ingredients.match_method` gegen das GL-04-§2.3-Vokabular (Regression A-10: kein `override_sub`).
3. **Zusatzstoff-Normalisierung** (GL-09 4.1): Stichproben-Abgleich Quelle {0,1,3,NULL} ↔ Ziel-Tri-State; GT-01/02-Werte als Anker.

### 7.3 Spotchecks & Gates

- **Allergen-Spotchecks:** nach Voll-Recompute im Ziel müssen die Golden-Werte exakt stehen (GP 3672 `gluten=enthalten`; 1560/1561 `gluten`+`milch=enthalten`, Konfidenz `high`; 1115 alle 14 `unbekannt`/`low`); zusätzlich Zufalls-Stichprobe n=50 Rezepte: 14 Felder gegen Quell-DB — Abweichungen NUR bei Rezepten mit Derivat-Zutaten erlaubt (SOLL-Delta GL-01 A2, im Report ausweisen).
- **Kanten-Symmetrie (V-23):** Verifikations-Query (Kanten ohne Gegenrichtung) = **0** nach Seed UND nach jedem `importEdges()` (D-7 AT-4); die ~175 asymmetrischen Alt-Kanten werden beim Seed repariert.
- **V-22-Datenqualitäts-Gates:** geflaggt statt still übernommen — Rezepte ohne Zutaten/EK/Yield (GL-02 T4-Randfälle = Review-Queue), GPs ohne `zustand`, 416 `datenluecke`-Preiszeilen, LAs mit aktivem Preis aber `qty` NULL/0, die 2 `service_charge`-Zeilen.
- **gp_key-Integrität (GL-12 I6):** alle 7.774 gp_keys mit der portierten `slugify` aus den strukturierten Feldern re-rechnen → byte-identisch zur Quelle; jede Differenz = Transliterations-Fehler im Port.
- **Hüllen-/Wissens-Seed:** Resolver-Smoke (GT-06-1 gegen echten Seed); `knowledge_documents`-Charcounts gegen Budget-Erwartung (GL-13 §4.3).

---

**Querverweise:** Testdaten normativ in `04_GRUNDLOGIKEN/GL-*.md` §5 · Akzeptanz-Texte in `05_DOMAENEN/D-*.md` §7 · Gateway-Pflichten `06_KI_SPEZIFIKATION.md` §3–§5 · Seed-Pipeline `07_MIGRATION_SEED.md` · offene Entscheide `08_ENTSCHEIDUNGEN.md` (A-1 Verlust-Formel, W-1 Preiszeile, D1/D3/D4/D5) · Verbesserungen `10_VERBESSERUNGS_REGISTER.md` (V-07, V-10, V-22, V-23).
