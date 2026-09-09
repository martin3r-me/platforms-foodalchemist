# Spec 52 — Wissens-Architektur: Entwirrung der Steuerschicht

> **Tracking:** Office Dev-Package 23, Features-Board (`dev_board_id=53`).
> **Status:** Diagnose abgeschlossen, Plan freigegeben von Dominique 2026-09-07. Etappe A läuft.
> **Branch:** `feat/wissen-architektur` (Worktree `15_GITHUB/wt-wissen-architektur`).
> **Basis-Commit:** `a9f23c50` (origin/main, PR #47). Alle Befunde wurden auf `c0fbfafd`
> (PR #45) erhoben und gegen `a9f23c50` gegengeprüft — im Wissens-Code keine Änderung
> dazwischen (neu ist nur `conformance.konzentrat_anteil_max`, unbeteiligt).
>
> **Umbau-Umfang in einem Satz:** die **Steuer- und Zusammenbau-Schicht** wird umgebaut,
> **Inhalt und Dokumentenschicht bleiben**. Kein Modul-Neubau — Begründung im Abschnitt
> „Warum kein Modul-Umbau".


## Umsetzung 2026-09-09 — B1/D4 (Branch feat/wissen-budget)

- Eine Budgetquelle `ai.knowledge_budget` pro Prompt-Key zählt die gerenderten Kanon- und Retrieval-Blöcke einschließlich Überschriften und Trennern. Die Startwerte führen überwiegend die bisherigen beiden Obergrenzen zusammen; sie sind keine neue Live-Korpus-Messung.
- Pflichtkanon wird vor Retrieval reserviert. Übersteigt die kombinierte Pflichtmenge das Budget, stoppt der Modellaufruf mit einem expliziten Befund. Optionale Quellen passen vollständig oder entfallen; spätere kleinere Quellen bleiben möglich.
- Keine Dossier-Kürzung mehr im Kontextbau. `max_chars_per_doc` bleibt als inaktives Kompatibilitätsfeld erhalten. Der explizit gekürzte MCP-GET-Leseauszug ist davon unabhängig.
- Gateway und Vorschau wenden dasselbe Gesamtbudget an, auch auf übergebenes Rohwissen. Der typisierte Kontextvertrag aus Etappe C bleibt offen. Das Budget betrifft Wissenszeichen, nicht den gesamten Prompt oder das Tokenlimit.
- Profil, Versorgung, Einstellungen und Vorschau zeigen das gemeinsame Budget. Arten/Achsen sind in der Basis enthalten; Korpus-Kuration und Live-Validierung stehen aus.
- Basis `f1ff7c72`: vollständige Suite grün (4.243 Tests, 4.237 bestanden, 6 übersprungen). Erster B1/D4-Gesamtlauf: 4.249 Tests, 4.226 bestanden, 7 fehlgeschlagen, 10 Fehler, 6 übersprungen (26 Minuten).
- Nacharbeit: gemeinsamer Kanon-Textrenderer löst die Budgetmessung vom KI-Gateway; dadurch bleiben Gateway-Testdoubles und die Rezeptanreicherung funktionsfähig. Vier Testverträge wurden auf vollständige Dossiers, automatische Kanon-Erkennung und expliziten Budgetabbruch umgestellt; der DB-Abfragetest bekommt Budget für drei vollständige Gewinner.
- Alle 17 beanstandeten Fälle bestehen in den gezielten Nachläufen: zunächst 78 von 79, anschließend der korrigierte Abfragetest samt seinen zwei Nachbartests (3 von 3). Vollständiger Wiederholungslauf steht noch aus. Noch nicht deployt.


## Context

Symptom (Dominique, 2026-09-07): Beim Erstellen eines **Basisrezepts** oder eines **Gerichts**
greift die KI nicht das richtige Wissen ab. Verdacht: die „Bindung" stammt aus einer alten
Struktur und läuft parallel zum neuen Kanon → Doppelung, die das System verkompliziert.
Zusatzbeobachtung: die Diktier-Eingabe hat eine eigene Knowledge-Search-Funktion.

Ziel dieses Dokuments: erst **Diagnose belegen** (Ist-Zustand, Live-Daten + Code), dann einen
**Entwirrungs-Plan** mit einer einzigen Wahrheitsquelle pro Frage.

---

## Befund A — Live-Steuerdaten auf demo (Team 6, 2026-09-07, per MCP gelesen)

### A1 · Kanon: 28 Zeilen, aber nur 3 Prompt-Keys

`knowledge_canon.GET` → `total: 28`, alle `scope=prompt_key`, `role=root`, `mode=pflicht`:

| scope_key | Zeilen | Inhalt |
|---|---|---|
| `recipe.generator` | 13 | Basis §1.0–1.2, §1.3–1.5 (2 Teile), §2, §3, §4, §6 · workflow-Dossier · geschmacksbalance ×2 · mengen_defaults ×2 |
| `vk.generator` | 12 | VK §1/§1.2a/§2 · Basis §2/§3/§4/§6 · workflow-Dossier · gb ×2 · md ×2 |
| `concept.brief_geruest` | 3 | Regelwerk Concept §1+2 / §3+4 / §5+6 |

→ **Jeder andere Prompt-Key hat keinen Kanon.**

### A2 · Routings: 73 Zeilen — und zwei Generationen von Schlüsseln

`knowledge_routings.GET` → `total: 73`, 19 „features" in **zwei Namensräumen**:

- **alt (`ai_*`)**: `ai_extract_recipe`, `ai_generate_recipe`, `ai_infer_ankers`,
  `ai_plan_dishes`, `ai_suggest_pairings`
- **neu (Prompt-Key, gepunktet)**: `concept.brief_geruest`, `concept.plan`, `concept.wording`,
  `foodbook.grundgeruest`, `foodbook.kundentext`, `foodbook.plan`, `format.grundgeruest`,
  `recipe.eigenschaften`, `recipe.review`, `recipe.steps`, `recipe.ueberarbeiten`,
  `vk.review`, `vk.ueberarbeiten`

**Die zwei Keys, die den ganzen Kanon tragen — `recipe.generator` und `vk.generator` —
haben NULL Routing-Zeilen.** Das Discovery-Wissen für die Rezept-Erstellung hängt
stattdessen unter dem Altnamen `ai_generate_recipe` (13 Zeilen, `regelwerk: none`,
`pairing: none`, `cross_cutting: discovery 6×8000`, `domain` ungekappt, …).
→ Das ist **kein Versehen, sondern gebaut so** — siehe Befund B5.

`concept.brief_geruest` ist der **einzige** Key mit BEIDEM: Kanon (3 × regelwerk `pflicht`)
**und** Routing (`regelwerk` discovery 3×8000) → derselbe Korpus zweimal im selben Prompt,
wenn nicht dedupliziert wird.

### A3 · Kategorie-Vokabular vs. Routing-Kategorien

`knowledge_categories.GET` → 21 aktive Kategorien.

- **`pairing`** steht in 3 Routing-Zeilen (`ai_infer_ankers` grounding, `ai_suggest_pairings`
  grounding, `ai_generate_recipe` none) — ist aber **keine Kategorie im Vokabular** → veraltete
  Routing-Schlüssel.
- **`workflow`** (die Handlungs-Workflow-Dossiers) hat **kein einziges Routing** in keinem
  Feature. Erreichbar nur über Kanon (genau 1 Slug: `workflow.basisrezept_erstellungs_dossier`)
  oder `knowledge.SEARCH`. Deckt sich mit dem Etappe-8-Befund („`workflow` hat kein Routing,
  30.000 Zeichen Kalkulationswissen kamen nie in einen Prompt").
- Bewusst search-only laut Beschreibung: `referenz_rezepte`, `foodcontent`.

### A4 · Drei Steuer-Ebenen für dieselbe Frage

| Ebene | Schlüsselraum | Schreib-Tool | Status |
|---|---|---|---|
| `knowledge_routings` | feature × **category** | `knowledge_routings.PUT` | aktiv, 73 Zeilen, 2 Namensgenerationen |
| `bind_layers` (Bindung) | slug × **target_key** (Bereich `gp/recipe/vk/concept` ODER Einzel-Prompt `recipe.geschmack`, `vk.plating`) | `knowledge.BIND` / `.UNBIND`, `bind_layers` in `knowledge.POST/PUT` | Tool aktiv — **Alt-Struktur, Verdachtskern** |
| `knowledge_canon` | scope(`feature`\|`prompt_key`) × scope_key × **slug** | `knowledge_canon.PUT` | aktiv, 28 Zeilen, neue Struktur |

Alle drei beantworten: „welches Dossier gehört in welchen Prompt".

### A5 · `ablauf.GET` / `regelwerk.GET` sind Sichten, keine vierte Quelle

`regelwerk.GET(prompt_key=recipe.generator)` → `quelle: "kanon"`, exakt die 13 Kanon-Slugs.
`ablauf.GET(vorgang=gericht_anlegen)` → `prompt_keys: ["vk.generator"]`, `regelwerke.quelle: kanon`,
die 12 Kanon-Slugs + 4 `workflow.*`-Dossiers (Weg A/B/Abschluss/Regeln). Also **keine
Datendoppelung**, sondern eine Agenten-Sicht auf den Kanon. Sie gilt aber nur für MCP-Agenten,
nicht für den In-App-Generator.

---

## Befund B — Zwei Injektionspunkte, vier Steuertabellen (Doku + Commit-Historie)

### B1 · Das dokumentierte Modell (`docs/wissen.md`) kennt den Kanon gar nicht

`docs/wissen.md:33-51,91-105` beschreibt als Wirk-Mechanismus:

| Tabelle | Zweck laut Doku |
|---|---|
| `knowledge_layers` | Einsatzorte: **Bereiche** (grob: `gp`, `recipe`, `vk`, `concept`, `preis`, `chat`) + **Prompt-Keys** (fein, aus der Registry) |
| `knowledge_bindings` | Doc → Einsatzort (`binding_type='layer'`, mode, weight, source) |
| `knowledge_routings` | „grobe Auto-Ebene": Feature × Kategorie |

Und den Injektionspunkt: **„Der zentrale Trick: Injektion im Gateway"** —
`AiGatewayService::propose($promptKey)` lädt gebundenes Wissen automatisch „für **jeden** der
~48 Prompts", Match **exakt (Prompt-Key) ODER dessen Bereich (Präfix vor dem Punkt)**.

Der Kanon steht in dieser Doku nicht. Das ist die Doppelung in Reinform: **ein zweiter
Injektionspfad ist dazugekommen, der erste wurde nie abgeräumt.**

### B2 · Der Kanon ist ein Per-Key-Override, kein Ersatz

Commit `7224f4a1` („Welle 2: Kanon-Consumer im Gateway ersetzt always-Bindings **je
Prompt-Key**"):

- `AiGatewayService::selectKanon()` liest `KnowledgeCanonService::documentsFor('prompt_key', …)`
- **„Bindings (#469) nur noch Fallback für Prompt-Keys ohne Kanon-Zeilen"**
- Anlass wörtlich: *„Spec-50-Split — **Bindings zeigen auf die 155 Originale, die deaktiviert
  werden; ohne Consumer fiele der Regelwerk-Block still auf null.**"*

→ Für `recipe.generator` / `vk.generator` / `concept.brief_geruest` gewinnt der Kanon.
**Für alle anderen Prompt-Keys gilt weiter die Alt-Struktur** — und deren Slugs sind durch den
Split entwertet worden.

### B3 · Die Deckungslücke, quantifiziert

Prompt-Registry laut `docs/PLANUNG/26_LLM_MCP_Funktionsmatrix.md:104-149`: **22 `recipe.*` +
15 `vk.*`** Keys (Spec 50 §4.5 bestätigt die Zahl).

| Steuerung | recipe.* | vk.* |
|---|---|---|
| **Kanon** | 1 (`generator`) | 1 (`generator`) |
| **Routing** | 4 (`eigenschaften`, `review`, `steps`, `ueberarbeiten`) | 2 (`review`, `ueberarbeiten`) |
| **weder Kanon noch Routing** | **17** | **12** |

⚠ **Präzision:** „weder Kanon noch Routing" ist **nicht** gleich „kein Wissen". Der Gateway
fällt bei fehlendem Kanon auf `knowledge_bindings` zurück (`target_key` = Prompt-Key **oder**
Bereichs-Präfix). Deren Bestand ist ungemessen — es gibt kein Lesetool (B7). Der belastbare
Befund lautet: **die Versorgung ist uneinheitlich und für 29 Keys nicht nachgewiesen.** Ob ein
konkreter Schritt real ohne Wissen läuft, zeigt nur die Messung am fertig zusammengesetzten
Aufruf.

Ungesteuert u. a.: `description`, `category`, `geschmack`, `sensorik`, `garverlust`,
`production_depth`, `equipment`, `preparation`, `titel_vorschlag`, `bauart`, `pairing`,
`anker` · `plating`, `wording`, `marketing`, `rollen`, `speisen_klasse`, `regeneration`,
`servier_vehikel`, `kohaerenz`, `teller_heber`, `titel_vorschlag`.

**Das sind genau die Schritte, die „KI-Erstellen" nach dem Draft fährt**
(`BulkEnrichService::SCHRITTE` = description|category|geschmack, `SCHRITTE_VK` =
description|wording|plating|speisen_klasse, plus `RecipeOneShotService::coverageGlieder()`).
→ Symptom „Wissen fehlte komplett" trifft die Anreicherung, nicht den Draft.

### B4 · Zwei Schlüssel-Generationen in `knowledge_routings`

Die 5 `ai_*`-Features (24 der 73 Zeilen) tragen Altnamen für Prompt-Keys, die heute gepunktet
heißen: `ai_generate_recipe` ↔ `recipe.generator`/`vk.generator` · `ai_extract_recipe` ↔
`recipe.extract` · `ai_suggest_pairings` ↔ `recipe.pairing` · `ai_infer_ankers` ↔
`recipe.anker`/`gp.anker` · `ai_plan_dishes` ↔ `concept.plan`/`foodbook.plan`.

**Genau in `ai_generate_recipe` liegt die ganze Discovery-Erdung** (cross_cutting 6×8000,
domain **ungekappt**, kueche 2×2500, weltkueche/niveau/signatur_kuechen/ernaehrung/
kreativ_input/prasentation_service je 1) — und `recipe.generator`/`vk.generator` haben **keine
eigene Routing-Zeile**. Welche Hälfte lebt, entscheidet die Schlüssel-Auflösung im Code
(→ Befund B5).

Beides ist erklärungsfähig für **„thematisch unpassendes Wissen"**: `domain` ist ungekappt bei
166 Docs, `cross_cutting` zieht 6 aus 162, und `regelwerk` discovery 3×4000 wählt aus 61 Docs
(Memory-Vorfall: „vk bekam Basisrezepte statt Verkaufsgerichte").

### B5 · Der Kern in einer Zeile: **ein Call trägt zwei Identitäten**

`src/Services/RecipeGenerationContextService.php:88`

```php
$wissen = $this->knowledge->contextFor(
    $team,
    'ai_generate_recipe',                    // ← Routing-Schlüssel: hartkodierter ALT-Name
    $description, …,
    $parameter + ['…', '_kanon_prompt_key' => $genKey]   // ← Kanon-Schlüssel: recipe.generator | vk.generator
);
```

Damit ist Befund A2 präzisiert: die `ai_*`-Zeilen sind **nicht toter Ballast**, sondern der
**zweite, parallele Schlüsselraum** desselben Aufrufs. Konsequenzen:

1. **Basisrezept und Verkaufsgericht teilen sich EINE Routing-Politik.** Beide laufen unter
   `ai_generate_recipe`. Ein VK-Gericht bekommt die Discovery-Politik des Basisrezepts; eine
   VK-eigene Politik ist ohne Code-Änderung nicht setzbar. `knowledge_routings.PUT` auf
   `vk.generator` würde stumm ins Leere schreiben — die Zeile entstünde, wirkte aber nie.
2. **`domain` ist bei `ai_generate_recipe` ungekappt** (`max_docs: null`) bei 192 domain-Docs —
   in Kombination mit Befund C (lexikalisch-zuerst) die zweite Quelle für „thematisch
   unpassendes Wissen".
3. Denselben Alt-Namen trägt auch `VorgangsRegisterService.php:61,81,113,121,140,148`
   (`'feature' => 'ai_generate_recipe'`), d. h. das neue Vorgangs-Register aus Etappe 8 hat den
   Alt-Schlüssel schon wieder mit übernommen.

### B5a · Steuerdaten-Drift: der Wiederaufbau-Seed erzeugt eine ANDERE Politik

`src/Console/KnowledgePolicySeedCommand.php::ROUTINGS` (die Politik für frische DB /
Disaster Recovery / neuen Kunden) hat **36 Tupel — live stehen 73.** Abgleich:

| feature × category | Seed | Live |
|---|---|---|
| `ai_generate_recipe` × `cross_cutting` | `always` | `discovery 6×8000` |
| `concept.brief_geruest` × `regelwerk` | `always 1×9000` | `discovery 3×8000` |
| `recipe.steps` × `cross_cutting` | `always` | `discovery 4×8000` |
| `recipe.steps` × `niveau` | `discovery 1×3000` | `discovery 1×8000` |
| `foodbook.plan` × `cross_cutting` | `always` | `discovery 5×8000` |
| `concept.plan` × `cross_cutting` | `always` | `discovery 5×8000` |
| `recipe.eigenschaften` × `produktion_kapazitat` | `always 3×7000` | `discovery 3×6000` |
| `foodbook.plan` × **`trend`** | `discovery 5×1500` | **fehlt** |
| `concept.brief_geruest` × **`trend`** | `discovery 5×1500` | **fehlt** |
| ~37 Live-Zeilen (`ai_plan_dishes`, `format.grundgeruest`, `foodbook.grundgeruest`, …) | **fehlen** | vorhanden |

`insertOrIgnore` + „NICHT überschreiben, nur melden" ist richtig gebaut — aber die Liste ist
inhaltlich veraltet: **`trend` ist keine Kategorie mehr** (nicht im 21er-Vokabular), und ein
frisches Team bekämme in 7 Tupeln eine andere Lade-Politik als demo. Der Kommentar im Kopf
warnt genau davor („zwei Listen, die dasselbe behaupten, driften sonst auseinander") — die
Drift ist eingetreten.

Zusätzlich dokumentiert derselbe Kopf den Grund, warum `always` gefährlich ist:
**„`regelwerkBlock()` holt per `->first()` genau EIN Dossier"** — bei 61 Regelwerks-Splits ist
`always` damit eine Zufallsauswahl.

### B5b · Die Rangfolge im Gateway, wörtlich

`AiGatewayService.php:150-204`: Kanon zuerst (`selectKanon()`, nur `scope='prompt_key'`,
**bewusst ohne Bereichs-Präfix**), Bindungen nur `if ($kanonBlock === null)` — dann mit
`target_key IN [promptKey, Bereich]`, also **mit** Präfix-Erbe (`recipe`, `vk`).
Kommentar im Code: *„Kanon und Bindings schliessen sich aus — es steht also genau EIN
Regelwerk-Block."* Gepinnt von `WissenKanonBlockTest`.

**Die Reihenfolge im fertigen Prompt** (nicht die Berechnungsreihenfolge):
`[system]` Voice-Hülle → Feld-Hülle → JSON-Umschlag → **Kanon- ODER Bound-Block** ·
`[user]` task + **`contextFor()`-Retrieval-Block** + Kontext-JSON.
Der Kanon landet also **vor** dem Retrieval im Prompt, obwohl er im Code danach aufgelöst wird.

### B6 · Der Doppelpflege-Beleg

Commit `253b30d4` band `geschmacksbalance` gezielt an **beide Generatoren** und
`produktion-arbeitszeit-und-personenminuten` an `recipe.eigenschaften`. Die
`geschmacksbalance`-Splits stehen heute zusätzlich als `pflicht` im Kanon von
`recipe.generator` **und** `vk.generator` (je 2 Zeilen). Dieselbe Aussage an zwei Orten
gepflegt; der Kanon unterdrückt die Bindung nur zufällig, weil dieser Key einen Kanon hat.

### B7 · Die Alt-Struktur ist per MCP schreibbar, aber nicht lesbar

`knowledge.BIND` / `knowledge.UNBIND` schreiben Bindungen (target_key = Bereich **oder**
Prompt). Es gibt **kein** `knowledge_bindings.GET`. Der Bindungszustand ist damit weder per
MCP prüfbar noch von einem Wächter erfassbar — nur im Browser-UI sichtbar. Das ist der Grund,
warum diese Klasse Fehler still bleibt.

### B8 · Strategischer Widerspruch in den Specs

`docs/PLANUNG/48_Wissen_Token_Programm.md:45-57` hält als **Architektur-Entscheid** fest:
Wissen nach Funktion trennen — (1) achsen-gebunden → nachschlagen, (2) **Regelwerke →
durchsetzen statt in den Prompt legen**, (3) offenes Material → Suchfall.
`46_Kanon_Entscheidungsvorlage.md` legt dann §1+§10 **doch** in den Prompt (mit
Break-even-Rechnung) — und heute stehen 13 bzw. 12 Regelwerks-Dossiers als `pflicht` im Kanon
(33.116 Z. gemessen). Beides ist einzeln begründet, zusammen ergibt es keine Linie.
Dasselbe Regelwerk fließt zusätzlich ungekappt in `ConformanceService::ladeRegelwerke()`
(per `slug LIKE`), also ein dritter, slug-gemusterter Zugriffsweg.

## Befund C — Die Discovery-Suche ist lexikalisch-zuerst, nicht hybrid-fusioniert

Vier Live-Proben gegen `knowledge.SEARCH` auf demo (2026-09-07). Jede Zeile trägt `via`
(`lexical`|`semantic`) und `score` — daraus liest sich die Regel:

| Anfrage | Ergebnis | Deutung |
|---|---|---|
| „Wie viel Gramm Hauptkomponente pro Person beim mehrgängigen Menü ansetzen" | **10/10 `lexical`**, Score 2–1. Platz 1+2 = **Behälter-Füllmengen** (`produktion_kapazitat`). Das richtige Dossier `mengen_defaults--hauptgang-komponenten` erst auf **Platz 7** | Prosa-Anfrage: „wie viel"/„pro" sättigen alle Slots mit Rauschen |
| „Naming-Syntax Basisrezept Typ-Vokabular" | 10/10 `lexical`, die zwei §1-Dossiers Score 4 auf 1+2 | mit trennscharfen Begriffen funktioniert lexikalisch gut |
| „Erdapfel" | **10/10 `semantic`**, Score 0. `kartoffel-kochtypen-sortenwahl` auf **Platz 9** | ohne lexikalischen Treffer springt Semantik ein — mit schwachem Ranking |
| „Akkord-Theorie" | **1× `lexical`** (Score 2, korrekt) **+ 9× `semantic`** (Score 0) | **die Regel: lexikalisch zuerst, Semantik füllt nur die Restplätze auf** |

**Das ist keine fusionierte Hybrid-Suche, sondern ein Auffüller.** Liefert die lexikalische
Hälfte so viele Treffer wie der Deckel groß ist, kommt die Semantik **gar nicht** zum Zug. Der
lexikalische Score ist offenbar eine Begriffszählung — bei natürlicher Sprache gewinnen damit
Dossiers, die häufige deutsche Wörter oft wiederholen.

**Wirkung auf die Rezept-Erstellung:** die Discovery-Routings arbeiten mit `max_docs` **1 bis
6** (`niveau` 1, `kreativ_input` 1, `weltkueche` 1, `kueche` 2, `regelwerk` 3, `cross_cutting`
6). Was bei Deckel 10 auf Platz 7 landet, existiert bei Deckel 6 nicht mehr. Bei `max_docs: 1`
entscheidet ein einziger Rauschtreffer den ganzen Wissensblock.

**Reichweite dieser Messung — wichtige Einschränkung:** Sie trifft
`KnowledgeContextService::searchDocuments()` (Token-Schnittmenge + Alias×2, ab
`KnowledgeContextService.php:1618`). Der **In-App-Generator benutzt einen ANDEREN Scorer** —
`discoverGenericBlock()`/`discoverDomains()` (`:1165-1265`, `:1312-1375`, Jaccard +
Substring-Bonus + Alias-Bonus, Tokenizer ≥3 Zeichen). Die gemessene Rangfolge gilt also
belegt für den **MCP- und Voice-Pfad**; für den UI-Pfad ist sie ein Indiz, kein Beweis. Was
sie ohne Einschränkung belegt: **derselbe Korpus wird von verschiedenen Einstiegen
verschieden bewertet** (→ Befund C2).

**Widerlegt:** Meine Zwischenannahme, die Team-6-Splits seien semantisch unsichtbar (die
Ausblick-Notiz in `docs/wissen.md:107` zur globalen Partition), stimmt nicht — die
„Erdapfel"-Probe liefert Team-6-Splits (`obst_kernobst--*`, `synonyme--gemuese`,
`referenz-rezept-*`). Die Partition ist in Ordnung, das **Ranking** ist das Problem.

### C2 · Vier Relevanz-Formeln für einen Korpus

| Einstieg | Formel | Fundstelle |
|---|---|---|
| **Generator-Discovery** | Jaccard + Substring-Bonus + Alias-Bonus, Tokenizer ≥3 Z. | `KnowledgeContextService.php:1165-1265,1312-1375` |
| `knowledge.SEARCH` / Agent / Voice | Token-Schnittmenge + Alias×2 | `KnowledgeContextService.php:1618-1695` |
| **Bindungs-Auswahl** | eigener Tokenizer ≥4 Z., Score = `always×1000 + Treffer×10 + weight` | `AiGatewayService.php:463-538,541-558` |
| **Wissens-Browser** (Mensch) | rohes SQL `LIKE` **oder** rein semantisch — **kein Hybrid** | `Livewire/Knowledge/Browser.php:436-476` |

Dieselbe Frage („welches Dossier passt zu X") hat vier Antworten. **Der Kurator im Browser
sieht damit nie, was der Generator sieht** — Kuratieren ist Blindflug.

### C3 · Tenancy-Divergenz: die KI sieht mehr als der Mensch

`config/foodalchemist.php:699` → `knowledge_team_scope` **Default `false`**.
`KnowledgeContextService::nurSichtbar()` (`:510-517`) filtert nur, wenn der Schalter an ist →
**alle KI-/MCP-Pfade lesen den gesamten Korpus über alle Teams**. Der Browser ruft
`TeamScope::applyVisible()` dagegen **unbedingt** (`Browser.php:138-144,451-457`).
Zwei Antworten auf „was ist sichtbar", aus zwei unabhängigen Code-Stellen.

## Befund D — Die Diktier-Eingabe ist ein fünfter, gegenläufiger Weg

Zwei Diktat-Arten, nur eine mit eigenem Wissenspfad:

⚠ **Die zwei Mikrofone auseinanderhalten** (Präzisierung nach Rückfrage Dominique):
das Mikrofon in der **Planungs-Leitstelle** transkribiert nur — das ist D2 und daran ist
nichts zu ändern. Gemeint ist das **globale Mikrofon links in der Sidebar**, und das ist ein
Agent mit Werkzeugzugriff: `resources/views/livewire/sidebar.blade.php:30` mountet
`foodalchemist.voice-modal` **einmal global**, jede Seite öffnet es per Event
(`voice-modal.oeffnen`). Der Docblock nennt den Grund: *„das Mikrofon steuert seit Phase C2
den ganzen FoodAlchemist … das Mikrofon ist der eigentliche MCP-Agent im System"*.

**D1 · Globales Sidebar-Mikrofon** (`Livewire/VoiceModal.php:23-104` → `VoiceCommandService.php:38-148`):
läuft **nicht** über `contextFor()`, sondern über `AiGatewayService::callWithTools()`
(`:594-699`). Dieser Pfad baut **nur** System-Message + Tool-Katalog —
**kein Kanon-Block, kein Bindungs-Block, kein Routing.** Das Modell muss sich sein Wissen
selbst holen, indem es `knowledge.SEARCH`/`.GET` als Tool aufruft (erlaubt über
`darfNutzen():86-97`, jedes `read_only`-Tool im `foodalchemist.*`-Namespace).

→ **Das ist ein Pull-Modell gegen das Push-Modell des restlichen Systems.** Am Mikrofon
gelten Kanon und Regelwerk **nicht** — es sei denn, das Modell sucht von selbst danach. Für
„Rezept per Sprache anlegen" heißt das: der Naming-§ ist nur dann im Spiel, wenn das Modell
sich entscheidet, ihn zu suchen. Und die Suche, die es dann benutzt, ist die aus Befund C
gemessene (lexikalisch-zuerst).

**D2 · Feld-Diktat in der Leitstelle** (`resources/views/livewire/planung/partials/diktat.blade.php:1-15`,
`Livewire/Recipes/StepEditor.php:62-80`): reines STT („kein Tool-Loop"), speist denselben
deterministischen Pfad wie getippter Text (`StepEditor::stepWissen():543-561` → `contextFor()`).
**Unbedenklich.**

## Befund E — Der Kanon⇄Discovery-Schutz greift nur an 2 von ~14 Stellen

`contextFor()` nimmt `_kanon_prompt_key`, um Kanon-Pflichtdossiers nicht zusätzlich selbst zu
ziehen (`KnowledgeContextService.php:212-225`). Gesetzt wird der Parameter **nur** von
`RecipeGenerationContextService.php:88` und `ConceptGeneratorService.php:231,660`.

**Nicht gesetzt** von: `RecipeOneShotService.php:802` · `FoodbookService.php:2423,2463` ·
`ConceptService.php:1270` · `RecipeModal.php:652,961` · `IdeenService.php:299,409,531` ·
`RecipeReviseService.php:159,201` · `RecipeReviewService.php:72` ·
`AngebotService.php:835,874` · `StepEditor.php:554`.

Die zweite Schutzschicht (`selectKanon()`/`selectBoundKnowledge()` deduplizieren gegen
`options['knowledge_used']`, `AiGatewayService.php:433-439,465-471`) vergleicht **exakte
Slugs**. Zwei Dokumente mit demselben Fachinhalt und verschiedenem Slug — genau der Fall
Monolith vs. §-Splits — werden **nicht** erkannt.

`RecipeOneShotService.php:802` ist dabei der wichtigste Ausfall: das ist der
Anreicherungs-Pfad hinter „KI-Erstellen".

## Befund F — Weitere Doppelpflege im selben Modul

| Doppelt | Wo |
|---|---|
| `_sections` / `_chunks` = **totes Schema** (Producer `knowledge-sectionize` schreibt, niemand liest) | Migration `2026_09_05_000010_…:20-23` sagt es wörtlich |
| **Bindungs-Schreibpfad** zweimal: `KnowledgeService::bindExisting()` (mit Layer-Prüfung + Soft-Delete-Revive) vs. `Browser::addBinding()` (roher Insert, ohne diese Garantien) | `KnowledgeService.php:228-275` vs. `Browser.php:372-420` |
| **Kategorie-Anlage** zweimal implementiert, „bewusst gespiegelt" statt geteilt | `KnowledgeService::createCategory():352-359` vs. `Settings/Wissenskategorien.php:32-155` |
| **Vorgang→Dossier** zweimal: `VORGAENGE[…]['doc_slugs']` (Code) vs. `gilt_fuer_vorgang`-Frontmatter (Daten); nur durch den Wächter `wissen-deckel-check` zusammengehalten — mit mindestens einem realen Auseinanderlaufen (`workflow.gericht_abschluss` fehlte) | `VorgangsRegisterService.php:49-152` vs. `WissenDeckelCheckCommand.php:10-30,116-140` |
| **Substitutionen/Synonyme** zweimal: Markdown-Dossier (für den Prompt) und PHP-Konstanten `ALIAS_GROUPS`/`ANTI_MARKERS` (für den Matcher) — laut Docblock aus derselben Vault-Quelle, **ohne Abgleich** | `TerminologyService.php:19-24,153-218` |
| **Tenancy-Regel** kopiert statt aufgerufen („Die Regel lebt in TeamScope::mayWrite — hier stand sie kopiert") | `Settings/Einsatzorte.php:36-40` |
| `regelwerkBlock()`-Pfad (`->first()` = EIN Monolith) bleibt vollständig vorhanden und reaktivierbar, falls eine Routing-Zeile je auf `always` zurückfällt | `KnowledgeContextService.php:936-991` |

---

## Befund G — Was die Code-Analyse zusätzlich fand

### G1 · Migration ⇄ Seed definieren dasselbe Routing mit ENTGEGENGESETZTEM Modus

Für drei Prompt-Keys existieren **zwei widersprechende Definitionen im Repo**:

| Prompt-Key | Migration (älter) | `KnowledgePolicySeedCommand` (neuer) | Live demo |
|---|---|---|---|
| `recipe.eigenschaften` × regelwerk | **always**, 1 Doc, 6000 Z. (`2026_08_27_140000_…:25`) | **discovery**, 3×4000 (`:96`) | discovery 3×4000 |
| `recipe.ueberarbeiten` × regelwerk | **always**, 1 Doc, 7000 Z. (`2026_08_29_000001_…:21`) | **discovery**, 3×4000 (`:78`) | discovery 3×4000 |
| `vk.ueberarbeiten` × regelwerk | **always**, 1 Doc, 7000 Z. (`2026_08_29_000002_…:16`) | **discovery**, 3×4000 (`:79`) | discovery 3×4000 |

Das ist nicht Drift in Zahlen, sondern **zwei verschiedene Auswahl-Algorithmen**:
`always` → `regelwerkBlock()` nimmt per `->first()` **EIN** Dossier (bei 61 Regelwerks-Splits
also praktisch zufällig); `discovery` → `discoverGenericBlock()` rankt 3 per Jaccard.

Und weil der Seed bewusst **nicht** überschreibt (`:111-124`), entscheidet allein die
**Reihenfolge der Skripte**, welcher Zustand gilt: auf einer frischen DB legt die Migration
`always` an, der Seed lässt es stehen. **Ein neuer Kunde bekommt damit den `->first()`-Pfad,
demo den Discovery-Pfad.** Zwei Umgebungen, zwei Verhalten, ein Repo.

### G2 · Der Cross-Cutting-Legacy-Default ist eine scharfe Falle (auf demo entschärft)

`KnowledgeContextService.php:54-60` sagt über `ALWAYS_LOAD_CROSS_CUTTING` (7 Slugs) selbst:
*„⚠ LEGACY-DEFAULT … alle 7 Originale sind auf demo DEAKTIVIERT (in Ein-Thema-Splits
zerlegt)"*. `ai_generate_recipe` hat **keinen** `cross_cutting_slugs`-Override
(`config/foodalchemist.php:499-502` kennt nur `foodbook.kundentext` und `concept.wording`).

**Wichtige Präzisierung:** dieser Pfad feuert nur bei `mode='always'`. Live steht
`ai_generate_recipe × cross_cutting` auf **`discovery 6×8000`** — auf demo läuft der
Cross-Cutting-Kanal also über Discovery und **liefert** (die Messung aus Welle 2 zeigt
retrieval 12.028 Z. / 15 Slugs). Aber: der **Seed setzt `always`** (Befund B5a) → auf einer
frischen DB lädt `crossCuttingDocs()` die 7 deaktivierten Alt-Slugs und der
Cross-Cutting-Block ist **leer, ohne Fehlermeldung** (Invariante „fehlende Quelle = leerer
Kontext, nie Fehler"). Dieselbe Falle wie G1, andere Zeile.

### G3 · Cross-Cutting hängt am `feature`, der Kanon am `prompt_key`

`crossCuttingSlugs(string $feature)` (`:1119-1127`) wird mit `'ai_generate_recipe'` gerufen —
**identisch für Basisrezept und Gericht**. Eine unterschiedliche Cross-Cutting-Bestückung für
`recipe.generator` vs. `vk.generator` ist über diesen Mechanismus **nicht möglich**, während
der Kanon genau das kann (13 vs. 12 Dossiers). Zwei Granularitäten im selben Call — die
zweite Hälfte von Befund B5.

### G4 · Ein dritter Schlüssel-Bruch: `recipe.dichteklasse`

`RecipeModal.php:961-971` / `BulkEnrichService.php:372-390` rufen
`contextFor($team, 'recipe.eigenschaften', …)` — aber
`propose('recipe.dichteklasse', …)`. Folge: das **Retrieval** arbeitet mit dem
`recipe.eigenschaften`-Budget (27.500 Z., `config:605`), der **Kanon-/Bound-Kanal** mit dem
konservativen Default (3 Docs / 1.400 Z. / 4.200 gesamt, `AiGatewayService.php:52-56`), weil
`recipe.dichteklasse` in `bound_knowledge_budget` gar nicht gelistet ist.

### G5 · Dieselbe Regel wird pro Erstellung drei- bis viermal ausgeliefert

`ConformanceService::ladeRegelwerke()` (`:152-198`) lädt per
`where('slug','like', $praefix.'%')` **alle** §-Dossiers **ungekappt** — an Kanon, Routing und
Bindungen komplett vorbei. Präfixe hartkodiert in den Adaptern
(`RecipeConformanceAdapter.php:70,72`). Damit läuft pro Rezept-Erstellung:

1. **Generator-Call** — Kanon-Auszug (13 bzw. 12 Dossiers, gedeckelt)
2. **`conformance.check`** — dieselben Regelwerke als **voller Text, ungekappt**
   (automatisch nach der Generierung, `GenerateRecipeJob.php:242-252`)
3. **Selbstheil-Call** `recipe.ueberarbeiten`/`vk.ueberarbeiten` bei Befunden — dritte
   Auflösung, eigener Kanon-/Routing-Durchlauf (`RecipeConformanceAdapter.php:86-99`)
4. bei Basisrezepten zusätzlich `recipe.review` (`kohaerenzGate()`)

**Keine gemeinsame Dedup-Buchhaltung** zwischen `ConformanceService` und
`KnowledgeCanonService`. Drei Prompts, drei Kappungsgrade, dieselbe Regel.

### G6 · Der Kontext-Inspektor zeigt nur ein Drittel

`RecipeKiKontextService::GENERATOR_FEATURES` (`:25`) filtert auf
`feature IN ('recipe.generator','vk.generator')`. Die Calls aus G5 Nr. 2–4 landen zwar im
`foodalchemist_ai_call_log`, sind im Rezept-Detail-Panel aber **unsichtbar**. Wer im UI
nachsieht, „welches Wissen hat die KI benutzt", sieht den Generator-Call — nicht die drei
anderen.

### G7 · Die strukturelle Ursache: es gibt keinen Engpass

**Es existiert kein Eloquent-Model für Wissensdokumente.** 27 Dateien sprechen direkt
`DB::table('foodalchemist_knowledge_documents')`. Es gibt keine Repository-Schicht, durch die
jeder Zugriff müsste. Genau deshalb konnte jede neue Funktion ihren eigenen Zugriff bauen —
und genau deshalb ist keine der Doppelungen aus Befund C/F ein Versehen einzelner Personen,
sondern die zwangsläufige Folge einer fehlenden Naht.

## Befund I — Das Budget kappt die Discovery, still und mitten im Text

Nachgerechnet 2026-09-07 (Dominiques Frage „3×4000 kommt ja nicht rein oder?" — sie stimmt):

| Prompt-Key | Routing baut bis | `ai.knowledge_budget` | Verlust |
|---|---|---|---|
| `recipe.ueberarbeiten` | 3×4000 = **12.000** | **8.000** | ~4.000 |
| `vk.ueberarbeiten` | 3×4000 = **12.000** | **8.000** | ~4.000 |
| `recipe.review` / `vk.review` | 3×4000 = **12.000** | kein Eintrag → `MAX_KNOWLEDGE_CHARS_DEFAULT` **12.000** | null Luft; Block-/Doc-Header kippen es drüber |
| `concept.brief_geruest` | regelwerk 3×8000 + geschaeftsmodell 2×8000 = **40.000** | **10.000** | ~30.000 |
| `recipe.eigenschaften` | produktion_kapazitat 3×6000 + regelwerk 3×4000 = **30.000** | 27.500 | ~2.500 |

Nur `ai_generate_recipe` bekommt `$recipeBudget = true` und damit den Pro-Doc-Deckel
`RECIPE_MAX_CHARS_PER_DOC` (2.400). Alle anderen Features nehmen den Routing-Wert
`max_chars_per_doc` unverändert — deshalb baut `3×4000` dort wirklich 12.000.

**I1 · Das Budget ist für die ALTE Routing-Lage bemessen.** Über der 8.000 steht in
`config/foodalchemist.php` wörtlich `// regelwerk:always 1 × 7000`. Als Spec 50 die
Live-Tabelle von `always 1×7000` auf `discovery 3×4000` drehte, wanderte das Budget nicht mit.
Derselbe Schlüsselraum-Bruch wie B5/G1, nur in Zeichen.

**I2 · Geschnitten wird mitten im Text.** `KnowledgeContextService.php:454-456` macht
`truncate($block, $budget)` auf den **fertig zusammengesetzten** Block — nicht „das dritte
Dossier weglassen". Ergebnis: halbe Tabelle. Spec 46 §2d hat den Satz selbst geschrieben:
*„Ein Tabellen-Anschnitt ist kein Wissen, nur Kosten."*

**I3 · Der Verlust ist im Call-Log unsichtbar.** `knowledge_dropped_chars` wird nur von
`RecipeGeneratorService.php:108` und `RecipeOneShotService.php:755` an `propose()` übergeben.
`RecipeReviseService` und `RecipeReviewService` übergeben nur `knowledge` + `knowledge_used`
→ `prompt_parts.dropped` bleibt **0**, während 4.000 Zeichen fehlen.

**I4 · Der Wächter ist für Discovery blind.** `pflichtZeichen()` (`:598-617`) summiert
`->where('mode', 'always')`. Die W0-5-Invariante „Budget ≥ Pflichtmenge" prüft also
ausschließlich `always`-Zeilen. Als Spec 50 auf `discovery` umstellte, fielen diese Keys aus
dem Sichtfeld des Wächters. **Das ist der Grund, warum es niemand gemerkt hat.**

**I5 · Beim Selbstheilen hilft kein Budget — dort wird kein Retrieval geladen.**
`RecipeConformanceAdapter.php:97` ruft `propose('recipe.ueberarbeiten', [...])` mit **einem
einzigen Argument**: kein `contextFor()`, kein `'knowledge'`. Das `discovery 3×4000`-Routing,
das Spec 50 genau für diesen Zweck gesetzt hat, feuert auf diesem Pfad **nie**.

⚠ **Präzision (nicht überziehen):** damit ist belegt, dass **Retrieval** fehlt — und der
**Kanon** liefert dort nachweislich auch nichts, weil für `recipe.ueberarbeiten` keine
Kanon-Zeile existiert (live geprüft: 28 Zeilen, nur 3 Prompt-Keys). Bleibt der
**Bindungs-Fallback** (`target_key IN ['recipe.ueberarbeiten', 'recipe']`) — **ungemessen**.
Der belastbare Satz ist also „kein Retrieval, kein Kanon; Bindung offen", nicht „ohne den §".

**I6 · Und die Discovery-Query ist die falsche Frage.**
`RecipeReviseService.php:159` rankt gegen `$r->description ?: $r->name` — die
**Rezeptbeschreibung**, nicht die Anweisung und nicht den Befund. Anweisung „Menge auf 4
Portionen" → welche 3 von 61 Regelwerks-Dossiers kommen, entscheidet der Text „Cremiges
Karottenpüree mit Ingwer".

---

## Befund J — Die Kurations-UI weist den Menschen aktiv in die tote Struktur

Gefunden von Dominique, 2026-09-07. In
[`resources/views/livewire/knowledge/browser.blade.php:130-136`](15_GITHUB/wt-anreicherung-recall/resources/views/livewire/knowledge/browser.blade.php:130)
steht bei **jedem** `cross_cutting`-Dossier, das nicht in der 7er-Legacy-Liste ist — also bei
**158 von 165** — diese Warnung:

> „Die Laufzeit lädt automatisch nur die 7 Kern-cross_cutting-Files (Substitutionen,
> Saisonkalender, Synonyme, Sauce-Mutterstrukturen, Mengen-Defaults, Techniken, Brühen/Fonds).
> Dieses Doc gehört **nicht** dazu → es wirkt erst, wenn du es unten an einen Einsatzort
> bindest."

**Dreifach falsch:**

1. **Die sieben genannten Dateien existieren nicht mehr als aktive Dokumente.** Der Code sagt
   es über seiner eigenen Konstante (`KnowledgeContextService.php:54-60`): *„alle 7 Originale
   sind auf demo DEAKTIVIERT (in Ein-Thema-Splits zerlegt)"*.
2. **Der beschriebene Mechanismus gilt für die Generatoren nicht.** `ALWAYS_LOAD_CROSS_CUTTING`
   wird nur bei `mode='always'` gelesen; live steht `ai_generate_recipe × cross_cutting` auf
   **`discovery 6×8000`** — es wird über alle 165 gesucht. Den rohen Default nutzt heute
   **kein** Feature mehr (`concept.wording`/`foodbook.kundentext` haben einen Override auf
   Split-Slugs).
3. **Der Handlungsrat führt in die stumme Struktur.** „an einen Einsatzort binden" = Bindung —
   und für `recipe.generator`, `vk.generator` und `concept.brief_geruest` sind Bindungen
   **stumm**, weil der Kanon gewinnt (B2). Der Kurator wird angewiesen, etwas zu tun, das an
   den zwei wichtigsten Prompts nachweislich nichts bewirkt.

**Das ist die schädlichste Form der Doppelung**, weil sie nicht still ist, sondern aktiv
falsch anleitet — und sie erklärt einen Teil des ursprünglichen Bauchgefühls: das Produkt
beschreibt seit Welle 2 ein System, das es nicht mehr gibt. Behandelt in `A4` (Diagnose-Texte
ehrlich machen), `E3` (Vorschau statt Behauptung) und `F7` (Kurations-UI auf Profile).

---

## Befund H — Steuerdaten: vier Schreiber, ein Test, kein Sollzustand im Code

### H1 · Vier unabhängige Wege erzeugen Routing-Zeilen

| Weg | Umfang | Verhalten |
|---|---|---|
| **Migrationen** (13 Daten-Migrationen 07-27 … 08-29) | 23 Zeilen | `insertOrIgnore`, kumulativ |
| **`KnowledgePolicySeedCommand::ROUTINGS`** | 36 Zeilen | manuell, `insertOrIgnore`, überschreibt nie |
| **`WissenSteuerdatenW0Command::ROUTINGS`** | 9 Zeilen (nur `ai_generate_recipe`) | manuell, `--apply` macht **UPDATE** |
| **MCP `knowledge_routings.PUT`** | beliebig | jederzeit live, an allen drei vorbei |

Ein fünfter Weg (`KnowledgeImportCommand::seedRoutings`) wurde entfernt — ein Test pinnt, dass
er nicht wiederkommt. Aber: **die vier verbleibenden haben keinen gemeinsamen Sollzustand.**
`--verify` läuft montags, `--apply` ist ein Handgriff.

### H2 · Der Drift-Test deckt nur ein Feature ab

`WissenSteuerdatenPolitikTest` hält `KnowledgePolicySeedCommand::ROUTINGS` und
`WissenSteuerdatenW0Command::ROUTINGS` **nur für `ai_generate_recipe`** gegeneinander. Der
Widerspruch aus G1 (`recipe.ueberarbeiten` / `vk.ueberarbeiten` / `recipe.eigenschaften`:
`always 1×7000` vs. `discovery 3×4000`) ist damit **von keinem Test abgedeckt**.

### H3 · Zeilen, die es nur im Seed gibt

In **keiner** Migration, nur in `KnowledgePolicySeedCommand`: `recipe.review`, `vk.review`,
`ai_extract_recipe`, `ai_suggest_pairings`, `ai_infer_ankers` und 8 `ai_generate_recipe`-
Kategorien (`cross_cutting`, `domain`, `pairing`, `referenzgericht`, `weltkueche`,
`signatur_kuechen`, `ernaehrung`, `prasentation_service`).
→ Eine rein migrierte DB hat für diese Paare **gar keine Routing-Zeile**. Für
`ai_extract_recipe` ist das ausdrücklich gewollt (Golden-Test „Inv. 7"); für
`recipe.review`/`vk.review` — den Copilot-Prüfpass — ist unklar, ob es je gesetzt wurde.

### H4 · Sechs Kategorien existieren im Routing, aber nicht im Vokabular-Seed

`produktion_kapazitat`, `referenzgericht`, `weltkueche`, `signatur_kuechen`, `ernaehrung`,
`prasentation_service` wurden nie per Migration in `knowledge_categories` geseedet, und
`KnowledgeService::assertKategorie()` prüft **strikt**. Auf demo existieren sie als
**`scope: team`** (nachträglich per `knowledge_categories.POST`) — bei einem neuen Kunden
wären die Routing-Zeilen **Vorwärtsdeklarationen ohne Wirkung**, und ein `knowledge.POST` in
diesen Kategorien würde mit „Unbekannte Kategorie" scheitern.

### H5 · Zwei parallele Budget-Bäume mit widersprüchlichen Zahlen

| Config | Ebene | Bedient |
|---|---|---|
| `ai.bound_knowledge_budget` (`config:524`) | **prompt_key** | Bindungen **und** Kanon |
| `ai.knowledge_budget` (`config:567`) | **feature** | nur `contextFor()`-Retrieval |

`recipe.eigenschaften` trägt gleichzeitig `knowledge_budget = 27.500` und
`bound_knowledge_budget.total = 8.000` — zwei Antworten auf „wie viel Wissen darf rein", je
nach Kanal.

### H6 · `mengen_defaults` ist dreifach verankert

`KnowledgeContextService::ALWAYS_LOAD_CROSS_CUTTING` (`:62`) **und**
`WissenSteuerdatenW0Command::ALWAYS_SLUGS_UNIVERSAL` (`:201`) **und** als Split-Nachfolger
zweimal im Kanon von `recipe.generator`/`vk.generator`. Das Original-Dokument selbst ist
**inaktiv**. Drei Code-Strukturen zeigen auf eine Regel, von der zwei auf eine Leiche zeigen.

### H7 · Der Kanon steht nirgends im Code — nur in der Live-DB

Kanon-Zeilen entstehen **ausschließlich** über MCP `knowledge_canon.PUT`
(`KnowledgeCanonService::set()`, einziger Aufrufer `KnowledgeCanonPutTool`). Kein Seeder, kein
Migrations-Insert, **keine UI**. Die Migration lässt die Tabelle bewusst leer.

**Damit ist der Disaster-Recovery-Fall der schlimmste Fall:** eine frische DB hat
**keinen Kanon** → der Gateway fällt auf **Bindungen** zurück → die zeigen (laut Commit
`7224f4a1`) auf die **deaktivierten Original-Monolithen** → und `regelwerk`-Routing steht aus
der Migration auf **`always` + `->first()`**. Ein neuer Kunde bekommt also im schlechtesten
Fall ein zufälliges Einzel-Dossier statt 13 kuratierter §-Dossiers — ohne Fehlermeldung.

**Und die Asymmetrie, die dein Bauchgefühl erklärt:** die **alte** Struktur (Bindungen) hat
eine UI im Wissens-Browser. Die **neue** (Kanon) hat keine. Wer im UI kuratiert, pflegt den
Mechanismus, der nur noch Fallback ist.

### H8 · Sechs Tests schreiben die Alt-Struktur weiter fest

`KnowledgeBindToolTest`, `WissenTenantTest`, `DossierRoutingZielTest` (pinnt `UMBINDEN` auf der
Bindungs-Tabelle), `WissensVokabularSchreibrechtTest`, Teile von `WissenTokenWelle0Test`,
`RegelwerkKnowledgeRoutingTest` (pinnt den `regelwerkBlock()`-`always`-Pfad). Alle grün, alle
gepflegt, **kein Deprecation-Marker**. Etappe F (F6) muss diese Verträge mitziehen, sonst wird die
Suite rot in fremden Dateien ([[feedback_teilsuite_deployt_roten_test]]).

---

## Urteil: ja, die Architektur ist nicht sauber — und zwar auf eine bestimmte Weise

Kein einzelner Mechanismus ist falsch. Jeder ist mit Messung und Begründung gebaut, oft mit
einem Kommentar, der die Doppelung von damals ausdrücklich benennt. Der Schaden entsteht
durch das **Muster**: jede neue Ebene wurde **neben** die alte gesetzt und die alte „als
Fallback" behalten.

Bilanz für die eine Frage „welches Wissen kommt in diesen Prompt":

- **3 Steuertabellen** — `knowledge_canon`, `knowledge_routings`, `knowledge_bindings`(+`_layers`)
- **2 Injektionspunkte** — `AiGatewayService::propose()` (Kanon + Bindung, feuert immer) und
  `KnowledgeContextService::contextFor()` (Routing + Discovery, feuert nur wenn der Aufrufer will)
- **2 Schlüsselräume im selben Call** — `feature='ai_generate_recipe'` fürs Routing,
  `prompt_key='recipe.generator'` fürs Kanon (Befund B5) — plus ein dritter Bruch bei
  `recipe.dichteklasse` (G4)
- **4 Relevanz-Formeln** auf denselben Korpus (C2), **3 Tokenizer** mit verschiedenen
  Mindestlängen und Stoppwortlisten
- **4 Schreiber** für dieselben Routing-Steuerdaten, ohne gemeinsamen Sollzustand (H1) — und
  **3 Zeilen, die zweimal mit entgegengesetztem Modus definiert sind** (G1), davon **keine
  einzige von einem Test abgedeckt** (H2)
- **2 Budget-Bäume** mit widersprüchlichen Zahlen für dasselbe Feature (H5)
- **5 Zugriffsmodi** — Push (Generator), Pull (Voice/MCP-Agent), Register (`ablauf.GET`),
  Critic (`slug LIKE`, ungekappt), Browser (Mensch)
- **1 totes Schema** (`_sections`/`_chunks`) mit lauffähigem Producer und ohne Leser
- **0 Engpässe** — kein Eloquent-Model, kein Repository; 27 Dateien greifen direkt per
  `DB::table()` auf den Korpus zu (G7). Das ist die strukturelle Ursache, nicht die Folge.
- **0 Stellen, die sagen, was ein Prompt tatsächlich bekommt** — `regelwerk.GET` antwortet bei
  ungesteuerten Keys „schau bei `ablauf.GET`", und `ablauf.GET` liest denselben Kanon. Der
  UI-Inspektor filtert auf die zwei Generator-Keys und blendet die drei Folge-Calls aus (G6).
- **und die Asymmetrie, die es falsch anfühlen lässt:** die **alte** Struktur hat eine
  Kurations-UI, die **neue** keine (H7).

Deshalb sind beide Symptome erklärbar, ohne dass jemand einen Fehler gemacht hat:
**„Wissen fehlte komplett"** = 29 von 37 Rezept-/Gericht-Prompt-Keys haben keine Steuerung
(Befund B3), darunter alle Anreicherungsschritte hinter „KI-Erstellen".
**„Thematisch unpassendes Wissen"** = ungekappte `domain`-Discovery über 192 Dossiers, bewertet
von einer der vier Formeln, deren Rangfolge niemand sieht (Befund C).

---

## Empfehlung: eine Frage, eine Tabelle, ein Rechner

Das System muss genau drei Fragen beantworten. Jede bekommt **einen** Besitzer:

| Frage | Besitzer künftig | heute |
|---|---|---|
| Welches Dossier **muss** in diesen Prompt? | `knowledge_canon` | Kanon **und** Bindungen |
| Welche Kategorie **darf** gesucht werden, wie tief? | `knowledge_routings`, auf **Prompt-Key** | Routings auf zwei Schlüsselräumen |
| Welches Dossier **passt** zu diesem Text? | **ein** Scorer | vier Formeln, drei Tokenizer |

### Grundsatz A — vier Wissensarten unterscheiden

Heute ist fast alles ein Dossier, das gesucht wird. Das ist der Kategorienfehler unter den
Symptomen:

| Art | Beispiel bei uns | Wie sie gehört |
|---|---|---|
| **Verbindliche Regeln** | Naming-§§, Pflichtfelder, erlaubte Kategorien | gezielt laden **und** den maschinell prüfbaren Teil im Code erzwingen |
| **Strukturierte Fachdaten** | GPs, Einheiten, Preise, vorhandene Basisrezepte, **Mengen-Standards** | über IDs/Achsen **auflösen**, nie suchen |
| **Fachwissen** | Bindeverhalten, Garverfahren, Geschmacksbalance, `workflow.basisrezept_erstellungs_dossier` (Mutterstruktur einer Sauce, ein Leitgeschmack im Püree) | nach Aufgabe und Zutaten gezielt suchen |
| **Referenz & Inspiration** | Vergleichsrezepte, Küchenstile, Plating | optional suchen, als Anregung gekennzeichnet |
| **Ablauf-Anleitung** ⟵ *fünfte Art, 2026-09-07 ergänzt* | `workflow.basisrezept_regeln` / `_erzeugen` / `_komponenten` / `_abschluss` — „Regel 1: alles ist Entwurf", „Schritt 1: Rahmen laden", „`primaer=lieferantenartikel_waehlen`" | **ausdrücklich KEIN Prompt-Inhalt.** Geht an Agenten über `ablauf.GET`; im Generator-Prompt wäre es Rauschen, weil der Generator keine Werkzeuge ruft, sondern JSON produziert |

**Warum die fünfte Art dazukam — und was sie an meinem eigenen Plan korrigiert.** Ich hatte
gefordert, die vier neuen `workflow.basisrezept_*`-Dossiers (10.746 Z., alle vom 2026-09-07)
müssten den In-App-Generator erreichen, weil `workflow` kein Routing hat. Das war auf eine
**Zeichenzahl** gebaut, nicht auf den Inhalt. Beim Lesen: die vier sind Agenten-Anleitungen und
sollen den Generator gar nicht erreichen — sie erreichen Agenten über `ablauf.GET`, und das tun
sie. **Dass `workflow` kein Routing hat, ist richtig, nicht defekt** — die Forderung nach einem
`workflow`-Routing ist damit gestrichen.

Ebenfalls durch Nachsehen geklärt statt entschieden: `workflow.rezept_anlegen_mcp` (stand in der
`ENTBUNDEN`-Liste, war auf dev aber aktiv an 23 Keys gebunden) **existiert auf demo gar nicht
mehr** — 19 Dossiers mit `include_inactive`, es ist gelöscht. Die Entscheidung ist dort vollzogen;
nur die Dev-MySQL ist stehengeblieben. Für die Bindungs-Triage (`F1`) heißt das: dieser Eintrag
ist kein Fall, sondern ein Rest.

Und genau das ist der beste Beleg für diesen Grundsatz: **die Kategorie `workflow` mischt zwei
Arten** — Handwerkswissen für den Prompt (`basisrezept_erstellungs_dossier`, zu Recht im Kanon)
und Ablauf-Anleitung für den Agenten. Ein Routing auf die Kategorie hätte beides in jeden Prompt
gezogen. Der Fix ist das `art`-Feld (`H1`), nicht ein Routing.

**Der Testsatz:** ein verbindlicher Mengen-Standard darf nicht davon abhängen, ob die Suche
ihn unter den ersten drei Treffern findet. Er muss über Gang × Komponentenrolle ×
Portionskontext aufgelöst werden.

Bei uns ist das belegbar falsch gelöst. `46_Kanon_Entscheidungsvorlage.md` §4 schreibt unter
„Was ich NICHT vorschlage": *„`substitutionen`/`mengen_defaults` in den Kanon. Zutatenabhängig;
gehören ins Chunk-Retrieval, nicht in jeden Prompt."* Heute stehen
`mengen_defaults--hauptgang-komponenten` (3.834 Z.) und `--format-multiplikatoren` (1.135 Z.)
als **`pflicht` in beiden Generatoren** — gegen die Empfehlung des Papiers, das den Kanon
begründet hat. Befund C zeigt die andere Hälfte: als Suchfall landet dasselbe Wissen auf Platz 7.

**Der Mechanismus existiert schon:** `achsenBlock()` (`:519-580`) mit
`config('foodalchemist.ai.knowledge_axis_map')` löst Anlass und Sektor deterministisch auf —
ohne Suche, ohne Routing. `mengen_defaults` benutzt ihn nur nicht. Umzugs-Aufgabe, keine
Neubau-Aufgabe.

Damit löst sich der Spec-Widerspruch aus B8: nicht „Regeln in den Prompt **oder** in den Code",
sondern **nach Art getrennt** — die KI bekommt die Regeln zur Erstellung, der Code erzwingt die
eindeutig prüfbaren, beide gegen dieselbe veröffentlichte Regelversion.

### Grundsatz B — strukturell, nicht vereinbart

Ein zentraler Kontext-Aufbau, den „alle Aufrufer verwenden müssen", wäre eine Konvention. Und
Konventionen sind genau das, was hierher geführt hat: `_kanon_prompt_key` an 2 von 14 Stellen
(E), `knowledge_dropped_chars` an 2 von 14 Stellen (I3), sechs Kategorien nur als Team-Zeilen
(H4), vier Schreiber ohne Sollzustand (H1).

**Der Durchsetzungsmechanismus hat zwei Hälften. Die Option `knowledge` verschwindet aus der
Aufrufsignatur — und der Auftrag wird getippt.** Das Feld zu entfernen schließt die
beschriftete Tür, nicht die Wand: `propose($promptKey, $kontext, $options)` nimmt `$kontext`
als **freies Array** und serialisiert es als `"Kontext:\n{json}"`. Da kann jeder Aufrufer
Regeltext hineinlegen. Erst ein getippter Auftrag macht es strukturell.

**Der Eingabevertrag muss vollständig sein**, sonst kann der zentrale Aufbau nicht auswählen:

| Feld | Zweck |
|---|---|
| Schritt / `prompt_key` | Profil, Suche, Budget, Protokoll — **ein** Schlüssel (B5) |
| Auftrag / Änderungswunsch | die eigentliche Discovery-Query (heute: die Rezeptbeschreibung, I6) |
| Rezeptstand | Zutaten, Mengen, Texte |
| **Prüfbefunde als Regel-ID + Befund** | der Dienst lädt die Regelversion **selbst** — nicht der Aufrufer den Regeltext |
| Benutzer-, Team-, Profilkontext | Sichtbarkeit und Profilauflösung |

**Provenienz-Invariante:** die Trennung existiert bei uns schon — der Kanon steht als
**system**-Message („VERBINDLICHES REGELWERK"), Retrieval in der **user**-Message. Sie muss zur
Regel werden: **nur der zentrale Aufbau schreibt in den System-Regelblock.** Was aus Suche oder
Nutzereingabe kommt, bleibt in der User-Message und bleibt als solches beschriftet — es wird
nie zur verbindlichen Systemregel. (Gleiche Familie wie
[[feedback_prompt_wortlaut_ist_keine_schnittstelle]]: Marker strukturell, nicht textlich.)

Drei Wege müssen dabei zusammen, nicht nur einer:

| Weg | heute | Ziel |
|---|---|---|
| `propose()` | Aufrufer übergibt `'knowledge'`, Gateway ergänzt Kanon/Bindung | baut den Kontext selbst, nimmt kein Wissen von außen |
| `callWithTools()` (Sprache) | **gar kein** Kontext-Aufbau (D1) | derselbe Aufbau |
| `ConformanceService` | lädt Regelwerke per `slug LIKE` und übergibt sie **an** `propose()` (G5) | bezieht sie aus demselben Aufbau |

Ohne den dritten Weg entsteht beim Umbau eine Doppelung statt einer Vereinheitlichung.

**Die Reihenfolge ist zwingend:** erst den Aufbau ins Gateway ziehen, **dann** dem Gateway das
eigene Nachladen wegnehmen. Umgekehrt tauscht man „manchmal doppelt" gegen „manchmal nichts" —
der stillere und schlimmere Fehler.

### Grundsatz E — alles in der UI einstellbar, alles per MCP bedienbar

**Vorgabe Dominique, 2026-09-07.** Jede Steuerung, die diese Spec baut, braucht **beide**
Oberflächen: einen Platz in der UI für den Menschen und ein Tool für den Agenten. Kein
Kommando-only, kein MCP-only.

Das ist nicht Komfort, sondern die Behebung der Asymmetrie aus Befund H7: die **alte** Struktur
(Bindungen) hat eine Kurations-UI, die **neue** (Kanon) hat keine — deshalb kuratiert ein Mensch
am Fallback und ein Agent an der Wahrheit. Wer die neue Steuerung ohne UI baut, wiederholt das.

Ist-Stand der Flächen (2026-09-07), und was fehlt:

| Steuerung | MCP | UI | Kommando |
|---|---|---|---|
| Kanon (`knowledge_canon`) | ✅ GET/PUT/DELETE | **fehlt** | — |
| Routings (`knowledge_routings`) | ✅ GET/PUT | **fehlt** (steht seit `docs/wissen.md` als „Ausblick") | — |
| Bindungen (Alt) | ✅ BIND/UNBIND + **neu** `knowledge_bindings.GET` | ✅ Browser | — |
| Versorgungs-Bericht (`A2`) | **fehlt** | **fehlt** | ✅ `wissen-versorgung` |
| Grundlinie (`A1`) | **fehlt** | **fehlt** | ✅ `wissen-grundlinie` |
| `art` / Achsen / Querverbindungen (`H1`/`H2`/`H6`) | zu bauen | zu bauen | — |
| Profile & Regelpakete (`C0`/`D5`) | zu bauen | zu bauen | — |

**Zusatz-Arbeitspakete daraus** (in die jeweilige Etappe eingehängt, nicht als eigene):
`A2`/`A1` bekommen je ein Lese-Tool und eine Sicht in den Einstellungen · `D5`/`C0` werden von
Anfang an mit UI **und** MCP gebaut, nicht nachgerüstet · `F7` (Kanon-/Profil-UI) rutscht damit
aus „Aufräumen" nach vorn: sie ist Bedingung, nicht Nachlese.

### Grundsatz C — Kandidatensuche ≠ Endauswahl

`max_docs` darf die **endgültige Auswahl** begrenzen, nicht die Kandidatenermittlung. Lexikalisch
und semantisch werden unabhängig gesucht, dann gemeinsam bewertet (RRF), dann auf das Budget
zugeschnitten. Findet sich kein brauchbarer Treffer, bleibt optionales Fachwissen **leer** —
statt den besten Rauschtreffer zu nehmen (heute: `DISCOVERY_MIN_SCORE` = 0,05).

---

### Grundsatz D — Budget-Verhalten festlegen, nicht Budget erhöhen

„`pflicht` wird nie gekappt" löst nichts — es verlagert den Überlauf nur. Das Verhalten muss
eindeutig sein:

| Situation | Verhalten |
|---|---|
| Pflichtwissen passt | vollständig übernehmen |
| Optionales Wissen übersteigt den Rest | **ganze, fachlich zusammenhängende Dossiers weglassen** |
| Pflichtwissen allein > Budget | betroffenen Schritt mit verständlichem Fehler stoppen — Profil verkleinern oder Aufgabe teilen |
| Pflichtquelle fehlt oder ist inaktiv | als Konfigurationsfehler melden |

**Nie mitten in einer Tabelle schneiden.** Heute macht `KnowledgeContextService.php:454-456`
genau das (`truncate($block, $budget)` auf den fertigen Block). Das ist der eigentliche Fix zu
Befund I2 — eine Budget-Erhöhung ist die Sofortmaßnahme, nicht die Lösung.

## Spec-Liste — Arbeitspakete mit Definition of Done

**Umbau-Umfang, ehrlich benannt:** die **Steuer- und Zusammenbau-Schicht** wird umgebaut
(3 Tabellen → ein Profil-Begriff, 4 Rechner → einer, 2 Budget-Bäume → einer). **Inhalt und
Dokumentenschicht bleiben** (Dossier-Korpus, `knowledge_documents` + Aliase + Kategorien +
Import-Guard, `knowledge_canon` als „Muss"-Tabelle, `KnowledgeEmbeddingService`, der
Konformitäts-Critic als eigener Pass). Kein Modul-Neubau — Begründung siehe „Warum kein
Modul-Umbau" unten.

**Reihenfolge ist bindend:**

```
A (messen)  →  H1/H2 (Felder: art + Achsen)  →  B (Riegel)  →  C (Durchstich, Abnahme)
                                                                      ↓
                                              D (Schlüsselraum) · E (Rechner) · H3/H4 (Bestand)
                                                                      ↓
                                                          F (Alt-Struktur weg)   ·   G (Zugriff)
```

> **Änderung 2026-09-07 (Dominique):** Der Dossier-**Inhalt** wird von ihm neu aufgebaut und
> steht deshalb **am Ende**. Vorgezogen werden dafür die **Felder** (`H1`/`H2`/`H6`) und
> **`D6` + `C0`** — sonst leert der Umbau den Kanon still, weil er auf Slugs zeigt.
> Zusätzlich gilt durchgehend **Grundsatz E**: alles in der UI einstellbar, alles per MCP
> bedienbar.

- **A zuerst**, ausnahmslos: nichts wird auf einer Schätzung gebaut. **Aber `B3` kann vor `A1`
  nötig sein:** erfassen die heutigen Logs die ausgelassenen Inhalte nicht vollständig (I3 —
  nur 2 von 14 Aufrufern geben `knowledge_dropped_chars` weiter), lässt sich die Grundmessung
  daraus nicht rekonstruieren. Dann wird **erst die Beobachtung ergänzt**, ohne eine fachliche
  Auswahl zu ändern.
- **H1/H2 vor B**, weil `B6`/`B7` (Mengen-Standard als Datenwerk) die Felder brauchen.
- **C ist die Abnahme — und C braucht Teile von D.** Der Durchstich verlangt Profilversion je
  Aufruf (C1), gleiche Regelversionen im Lauf (C4) und Wiederherstellung (C9). Deshalb sind
  **`C0` (minimale Profil-/Veröffentlichungsstruktur mit Versions-Fingerprint + Export/Import)**,
  **`D1` (ein Prompt-Key)** und **`D4` (ein Budget)** ausdrücklich **Teil von C** — begrenzt auf
  die Keys des Basisrezept-Ablaufs. Ohne sie beweist C die Architektur nicht.
- **Der Rest von D–G beginnt nach der Abnahme von C.** Das ist die Übertragung auf die
  übrigen Funktionen, nicht die Erfindung des Mechanismus.
- **H3/H4** (1.105 Dossiers markieren, Kategorien schneiden) erst nach C — erst beweisen,
  dann den Bestand anfassen.
- **G** ist ein eigener Entscheid (Zugriffsmodell), fachlich unabhängig vom Rest.

**Migrations-Regel für den ganzen Umbau:** pro Ablauf gilt ausdrücklich **die alte ODER die
neue** Ausführung. Innerhalb eines Laufs gibt es **keinen stillen Rückfall** auf die alte
Wissensversorgung. Nicht migrierte Funktionen behalten den bisherigen Pfad, bis sie migriert
sind — sichtbar, nicht heimlich.

**Ziel-Ablageort:** `docs/PLANUNG/52_Wissens_Architektur_Entwirrung.md` (52 ist frei, höchste
vorhandene Nummer ist 51). Tracking wie üblich: Office Dev-Package 23, Features-Board
`dev_board_id=53`.

### Etappe A — Grundlinie messen (vor jedem Fix)

| ID | Arbeitspaket | Definition of Done |
|---|---|---|
| **A1** | Referenzfälle festlegen: je 3 gute + 3 problematische Basisrezepte, heutigen Ablauf **vollständig** erfassen (Generator, Anreicherung, `conformance.check`, Selbstheilung, Review) | Ein Baseline-Dokument nennt je Fall und je Modellaufruf: `feature`, `prompt_chars`, `prompt_parts` (kanon/bound/retrieval/task/kontext/huelle/dropped), `knowledge_used`, `knowledge_channels`, Befunde. Reproduzierbar aus `foodalchemist_ai_call_log`. |
| **A2** | Kommando `foodalchemist:wissen-deckung` (read-only) über **`config('foodalchemist.prompts')`**, nicht über die Doku | Eine Zeile je Registry-Key mit: Kanon-Zeilen · Routing-Zeilen · Bindungen · effektiver Routing-Schlüssel · Σ Zeichen · Rechner. Keys **ohne jede** Steuerung erscheinen als **Befund**. Läuft grün auf demo und in der Suite. |
| **A3** | MCP-Lesetool `knowledge_bindings.GET` | Liefert je Bindung: `target_key`, Dossier-Slug, `mode`, `weight`, `active`, **und ob das Dossier noch aktiv ist**. Damit ist der Bestand erstmals messbar (Voraussetzung für F1). |
| **A4** | **Diagnose-Texte ehrlich machen** — `regelwerk.GET` / `ablauf.GET` **und** die Kurations-UI (Befund J) | (a) Bei ungesteuerten Keys `quelle: "ungesteuert"` + Liste dessen, was per Routing käme; der Verweis auf ein Tool, das dieselbe Quelle liest, ist weg. (b) Die `cross_cutting`-Warnung in `browser.blade.php:130-136` ist entfernt oder sagt die Wahrheit: sie behauptet heute bei **158 von 165** Dossiers eine 7er-Liste, die deaktiviert ist, einen `always`-Mechanismus, der nicht mehr greift, und empfiehlt eine Bindung, die an den Generatoren stumm ist. Test pinnt die Zustände. |
| **A5** | Bindungs-Bestand messen und protokollieren | Zahl der Bindungen, Verteilung über `target_key`, **Zahl der Bindungen auf inaktive Dossiers**. Ergebnis steht im Spec-Dokument — F1 wird ohne diese Zahl nicht geplant. |

**DoD Etappe A:** Es existiert eine Zahl für jede Behauptung in den Befunden A–I. Nichts in
B–G startet auf einer Schätzung.

#### ✅ A2 erledigt 2026-09-07 — und korrigiert zwei meiner Zahlen

`foodalchemist:wissen-versorgung` gebaut (`src/Console/WissenVersorgungCommand.php`, 7 Tests).
Erste Messung, **auf der Dev-MySQL** — und die ist der interessantere Fall, siehe unten:

| Kennzahl | Wert |
|---|---|
| Registry-Keys | **71** (nicht ~48 — die Zahl in `docs/wissen.md` war überholt) |
| davon `recipe.*` / `vk.*` | **23 / 13** (nicht 22/15 — `26_LLM_MCP_Funktionsmatrix.md` ist an der Stelle veraltet, u. a. steht `vk.behaelter` noch drin) |
| **UNGESTEUERT** | **42** |
| nur über die Alt-Struktur versorgt | **18**, alle `recipe.*` |
| gesteuert | 11 |

**Die Präfix-Streuung, jetzt gezählt:** `geschmacksbalance` und `workflow.rezept_anlegen_mcp`
hängen über das Bereichs-Ziel `recipe` an **je 23 Prompt-Keys** — unabhängig von jeder
Relevanz. Das ist die Blindleistung aus Spec 46 §2d, erstmals als Zahl.
Nebenbefund: `workflow.rezept_anlegen_mcp` steht in der `ENTBUNDEN`-Liste von
`WissenSteuerdatenW0Command`, ist hier aber aktiv gebunden — der `--apply`-Lauf ist in diesem
Zustand nie gefahren.

**★ Die Dev-MySQL IST der Wiederherstellungs-Fall aus Befund G1.** Sie trägt **keine
Kanon-Zeile**, `regelwerk:always 1×7000` bei `recipe.ueberarbeiten`, `always 1×6000` bei
`recipe.eigenschaften` und `cross_cutting:always` bei `recipe.steps` — also exakt den
Migrations-Stand, nicht den handgedrehten demo-Stand. Was ich als Risiko für „neuer Kunde,
neuer Rechner" beschrieben habe, ist lokal reproduzierbar. Zusätzlich stand dort noch das
**v1-Kanon-Schema** (`knowledge_section_id`); die Kanon-Migration `2026_09_05_000010` war nie
gelaufen. Konsequenz, die niemandem aufgefallen war: **auf der Dev-MySQL konnte der Kanon-Pfad
nie ausgeführt werden** — jede Kanon-Query bricht dort ab. Gezielt migriert (Tabelle hatte 0
Zeilen), die 12 übrigen offenen Migrationen bewusst nicht angefasst (Fremdmodule).

**Der Alias ist zentralisiert:** `KnowledgeContextService::ROUTING_ALIAS` +
`routingFeatureFuer()` — Bericht und Auskunft geben dieselbe Antwort, und mit `D1` verschwindet
die Tabelle an genau einer Stelle. Hätte ich sie im Kommando gelassen, hätte ich die zweite
Wahrheit gebaut, die diese Spec anklagt.

#### ✅ Die demo-Messung, 2026-09-07 nach dem Deploy von Etappe A

**Bindungs-Bestand auf demo: 9 Zeilen — und alle neun sind wirkungslos.** Jede sitzt auf
`recipe.generator` oder `vk.generator`, beide haben einen Kanon, also stellt der Gateway sie
stumm (`AiGatewayService:178`). `ziel_art` ist neunmal `prompt`, **nie `bereich`**.

Damit korrigiert sich einer meiner Befunde: **die Präfix-Streuung ist auf demo schon abgeräumt.**
`geschmacksbalance` und `workflow.rezept_anlegen_mcp` an je 23 Keys war ein **rein dev-lokaler**
Zustand — das `UMBINDEN` aus W0 wurde auf demo vollzogen, auf der Dev-MySQL nie. Die verbliebenen
9 sind das alte `ALWAYS_SLUGS_BAU`-Set (§2/§3/§4/§6 + Erstellungs-Dossier), komplett vom Kanon
überholt.

→ **`F1` (Bindungs-Triage) schrumpft auf einen Aufräum-Schritt.** Kein fachlicher Einzelfall
darunter, keine Entscheidung „gehört `geschmacksbalance` an `recipe.geschmack`". Neun Zeilen
löschen.

**Deckung auf demo: 16 von 71 Prompt-Keys gesteuert, 55 nicht.** Verteilung:

| Bereich | Keys | gesteuert |
|---|---|---|
| `recipe.*` | 23 | 6 |
| `vk.*` | 13 | 3 |
| `concept.*` / `foodbook.*` / `format.*` | 7 | 7 |
| **`gp.*`** | **13** | **0** |
| `signal.*` | 6 | 0 |
| Rest (chat, price, trend, planung, praesentation, …) | 9 | 0 |

★ **Kein einziger GP-Prompt bekommt das GP-Regelwerk.** Im Korpus liegen GP §3 (Warengruppen),
§6 (Benennungsschema), §6.1 (Singular-Pflicht), §7+§8 (Grammatur/Pflichtangaben) — und
`gp.suggest`, `gp.domain`, `gp.tags` und die zehn anderen erreichen **keines** davon.
Gleichzeitig zieht der GP-**Critic** über `GpConformanceAdapter` das komplette
`regelwerk-gp-`-Präfix. Dieselbe Asymmetrie wie beim Rezept: **der Prüfer kennt die Regeln, der
Ersteller nicht.** GP-Namen, die gegen §6.1 verstoßen, haben hier ihre Ursache.

⚠ **Vorbehalt:** diese 16/71 sind aus Kanon + Routings + Bindungen **von Hand** gerechnet, weil
`wissen-versorgung` ein Artisan-Kommando ist und demo keine Shell hat. Genau diese Lücke ist der
Anlass für die MCP-Fläche unten — danach ist die Zahl maschinell verifiziert.

#### ✅ A2 bekommt seine MCP-Fläche (Grundsatz E), 2026-09-07

Grundsatz E hat sofort bei meinem eigenen Werkzeug gebissen: ein Bericht, den man nur in einer
lokalen Sandbox fahren kann, **misst die falsche Umgebung** — meine ersten Zahlen (42 von 71)
kamen aus der Dev-MySQL, die dem Frisch-DB-Zustand entspricht.

Gebaut: `WissensVersorgungService` (die Rechnung) + `foodalchemist.knowledge_versorgung.GET`
(MCP) + das Kommando als reine Darstellung. **Eine Antwort, zwei Flächen** — zwei
Implementierungen wären die Doppelung, die diese Spec abbaut.

**Und noch ein Schlüssel-Bruch, den die Messung selbst gefunden hat — der dritte:**
`foodbook.plan` ist ein Routing-Feature, das `IdeenService` direkt an `contextFor()` übergibt,
**ohne dass es eine Prompt-Registry-Zeile dazu gibt**. Nach dem Alias (`recipe.generator` →
`ai_generate_recipe`) und dem Feld-Mismatch (`recipe.dichteklasse`) ist das die dritte Variante:
ein Aufrufer-Eigenname. Dokumentiert in `KnowledgeContextService::CONTEXT_ONLY_FEATURES`.

**Dabei habe ich eine eigene Überziehung korrigiert:** meine erste Fassung hieß
`toteRoutingFeatures()` und meldete `foodbook.plan` als tot — es ist live. Ob ein Feature
**wirklich** keinen Aufrufer hat, ist statisch **nicht** entscheidbar, weil mehrere Stellen mit
einer Variablen rufen (`contextFor($team, $promptKey, …)` in `ConceptGeneratorService`,
`contextFor($this->team(), $prompt, …)` in `StepEditor`). Der Bericht trennt jetzt drei Aussagen:
`ohne_aufrufer_gemessen` (ein `grep`-Befund vom 2026-09-07, als **Datum** geführt),
`aufrufer_eigenname` (dokumentiert) und `ohne_prompt_key` (unklar, nur im Code entscheidbar).

**11 von 73 Routing-Zeilen haben keinen Aufrufer:** `ai_plan_dishes` (8 Zeilen, inkl.
`cross_cutting 5×8000` — eine komplett konfigurierte Suchmatrix ohne Aufruf),
`ai_suggest_pairings`, `ai_infer_ankers`, `ai_extract_recipe`. Besonders bitter beim letzten:
`ai_extract_recipe × cross_cutting = none` ist eine **bewusste** Entscheidung („dieser Schritt
braucht kein Wissen"), hinterlegt unter einem Namen, den niemand ruft — der eigentliche Key
heißt `recipe.extract` und hat keine Zeile. Die Entscheidung existiert und wirkt nicht.

**Abgrenzung, die ich erst beim Bauen gefunden habe:** `foodalchemist:wissen-deckung` (W2-4)
existiert schon und prüft die **Korpus**-Richtung des §-Problems — „nennt ein Prompt einen §,
den kein Dossier hat" (der §12-Fall). Mein Bericht heisst deshalb `wissen-versorgung` und
prüft die **Versorgungs**-Richtung. Für `H7` (hängende §-Verweise im zusammengesetzten Prompt)
ist W2-4 die halbe Antwort: es fehlt die Richtung „Dossier verweist auf ein § außerhalb
desselben Prompts". Also `H7` erweitert W2-4, statt ein drittes Kommando zu bauen.

### Etappe B — Sofort-Riegel gegen stille Verluste

| ID | Arbeitspaket | Definition of Done |
|---|---|---|
| **B0** | **Drei Größen trennen**, die heute vermischt sind | Es gibt getrennt: **Kandidatenlimit** (wie viele Treffer je Suchverfahren untersucht werden — eigenes, großzügiges Limit), **Endauswahl** (welche Quellen übernommen werden), **Kontextbudget** (was insgesamt ans Modell geht). Ein Test belegt, dass ein Verstellen der einen die anderen nicht verschiebt. |
| **B1** | Budget-Kappung stoppen (Befund I): `recipe.ueberarbeiten` / `vk.ueberarbeiten` 8.000 → ~13.000 · `recipe.review` / `vk.review` **explizit** eintragen · `concept.brief_geruest` entscheiden (Budget ~26.000 **oder** Routing runter) | **Nur das Pflichtwissen muss ins Budget passen** — per Test **gegen die Live-Tabelle**, nicht gegen die Migration. Optionales Wissen **darf** größer sein als das Restbudget; es wird abschnittsweise vollständig aufgenommen, solange Platz ist, der Rest wird protokolliert. **Eine große optionale Kandidatenmenge ist für sich kein Konfigurationsfehler.** |
| **B2** | Kappung **dokumentweise** statt mitten im Text (Grundsatz D) | `truncate($block, $budget)` ist ersetzt: es fällt immer ein **ganzes** Dossier weg, nie ein Teil. Test: ein Block mit 3 Dossiers und einem Budget für 2 liefert exakt 2 vollständige Dossiers + `dropped_chars` = Größe des dritten. |
| **B3** | `knowledge_dropped_chars` an **allen** `contextFor()`-Aufrufern durchgeben (Befund I3) | Ein Test zählt die Aufrufstellen und schlägt fehl, wenn eine ohne die Option existiert. `prompt_parts.dropped` ist bei allen Features aussagekräftig. **◐ Teil-erledigt 2026-09-07 — siehe unten.** |
| **B4** | W0-5-Invariante schärfen (Befund I4: `pflichtZeichen()` prüft nur `mode='always'`) | Der Wächter prüft **die Pflichtmenge gegen das Budget** — und zwar auch dort, wo die Pflicht heute per Kanon statt per `always`-Routing entsteht. Er meldet **nicht** mehr, dass eine große optionale Discovery-Menge das Budget übersteigt (das ist der Normalfall, s. B1). Auf demo Exit 0, mit künstlich zu großer **Pflicht** Exit ≠ 0. |
| **B5** | Discovery-Dämpfer: `domain` bei `ai_generate_recipe` deckeln (heute `max_docs: null` bei 192 Dossiers) · `DISCOVERY_MIN_SCORE` (0,05) **messen** und begründet setzen | Messprotokoll: Trefferzahl und Rang-Position der fachlich richtigen Dossiers für 10 Anfragen, vor/nach. Die Schwelle ist mit dieser Messung begründet, nicht geraten. |
| **B6** | **Ein strukturierter Mengen-Standard als Datenwerk** — nicht ein per Achse gefundenes Markdown-Dossier. Ein gefundenes Dossier erfüllt den Vertrag **nicht** | Jeder Eintrag trägt: **Geltungsbedingungen** (Gang, Komponentenrolle, Format, Niveau) · **Wert oder Wertebereich** · **Einheit + Bezugsgröße** (pro Portion vs. pro Ansatz, Rohgewicht vs. verzehrfertig — genau die Verwechslung, die Regelwerk_Basisrezepte §6 regelt) · **Quelle + Version**. Präzedenz im Repo: `ProportionService::BLOOM_BLATTGELATINE` (Wert, Range, Quelle, „Herstellerangabe hat Vorrang") — **prüfen, ob `ProportionService` der Ort ist, bevor ein neuer Speicher entsteht.** |
| **B7** | Definiertes Verhalten bei **keinem** und bei **mehreren widersprüchlichen** Treffern | Kein Treffer → kein erfundener Wert, die Lücke bleibt sichtbar und wird gemeldet. Mehrere widersprüchliche → Unklarheit bleibt sichtbar (kein stilles „erster gewinnt"). Beides mit Test. Damit gilt auch: **`mengen_defaults` verlässt den Kanon erst, wenn B6+B7 stehen** — sonst tauschen wir Prosa gegen Lücke. |

**DoD Etappe B:** Kein Modellaufruf verliert mehr Wissen, ohne dass es in `prompt_parts.dropped`
steht. Kein Dossier wird mehr angeschnitten. Die Referenzfälle aus A1 sind erneut gemessen und
nicht schlechter.

#### ◐ B3 Teil-erledigt 2026-09-07 — Rezept-Kette ja, Rest bewusst offen

Statt 18 Aufrufstellen von Hand zu flicken (und die nächste vergisst es wieder) gibt es jetzt
`KnowledgeContextService::proposeOptionen($wissen)`: **ein Helfer, der alle Messfelder
mitnimmt.** Das ist derselbe Schritt wie beim Routing-Alias — Naht statt Konvention. Er lässt
`knowledge_channels` **bewusst** aus: an diesem Feld ist in W0-3b schon einmal der Bound-Kanal
gestorben, weil ein Anzeige-Spiegel auf das Feld schrieb, das die Auswahl-Logik liest. Wer
Kanäle liefert, tut es weiterhin selbst.

**Umgestellt (die Kette, die A1 messen muss):** `RecipeReviewService`, `RecipeReviseService`
(2×), `BulkEnrichService`, `RecipeModal` (2×), `StepEditor`. `RecipeGeneratorService` und
`RecipeOneShotService` gaben das Feld schon vorher weiter — das waren die 2 von 18.

**Bewusst offen (B3-Rest):** `IdeenService` (3×), `ConceptGeneratorService` (3×),
`ConceptService`, `AngebotService` (2×), `FoodbookService` (2×). Diese Features liegen
außerhalb des senkrechten Durchlaufs; sie ohne Messbedarf anzufassen wäre Risiko ohne Ertrag.
Sie kommen mit ihrer jeweiligen Migration. **Der Wächter-Test deckt deshalb heute die
Rezept-Kette ab, nicht alle Aufrufer** — das steht so im Test, damit niemand ihn für mehr hält.

`ConformanceService:130` gehört nicht dazu: dort ist `knowledge` ein **String** aus
`ladeRegelwerke()`, kein `contextFor()`-Ergebnis. Das ist `C4`.

⚠ **Der Wächter-Test scannt Quelltext** und ist damit grob — Marker statt Bedeutung. Er fängt
„neue Aufrufstelle vergisst das Feld", nicht jede Umformulierung. Mit `C2` verschwinden Helfer
und Test gemeinsam, weil `propose()` den Kontext dann selbst baut.

### Etappe C — Der senkrechte Durchlauf (Basisrezept) · **der Beweis**

Ablauf: **erstellen → anreichern → prüfen → einen gezielt eingebauten Verstoß korrigieren.**
Scope: **Team 6**, nur Basisrezept, über **Formular, Sprache und MCP**.

| ID | Arbeitspaket | Definition of Done |
|---|---|---|
| **C0** | **Minimale Profil-/Veröffentlichungsstruktur** — nur für die Keys des Basisrezept-Ablaufs. Vorgezogen aus D5–D7, weil C1/C4/C9 sie brauchen | Ein Profil je Schritt, **veröffentlichbar mit Versions-Fingerprint** der referenzierten Dossiers, **exportierbar und importierbar**. Ein Schreibdienst für UI und MCP. Die vollständige Paket-Struktur und die Übertragung auf alle Features bleibt D5–D7. |
| **C0b** | **Ein Prompt-Key** (`D1`) und **ein Budget** (`D4`) für die Keys dieses Ablaufs — vorgezogen, sonst beweist C die Architektur nicht | `RecipeGenerationContextService:88` übergibt `$genKey`. ⚠ **Seam:** das ist **ein** Code-Pfad mit `$vkModus`-Flag — VK wird zwangsläufig mitberührt. Deshalb werden die 13 `ai_generate_recipe`-Zeilen **gleichzeitig auf `recipe.generator` und `vk.generator` gespiegelt**, damit das VK-Verhalten unverändert bleibt. Belegt durch einen Vorher/Nachher-Vergleich an einem VK-Referenzfall. |
| **C1** | Getippter `RecipeAuftrag` + **Lauf-ID** über den ganzen Vorgang | Der Auftrag trägt: Schritt/`prompt_key`, Auftrag/Änderungswunsch, Rezeptstand, **Prüfbefunde als Regel-ID + Befund**, Benutzer-/Team-/Profilkontext. Alle Aufrufe eines Vorgangs (Generator, `conformance.check`, Selbstheilung, Review) tragen dieselbe Lauf-ID im Call-Log und sind über sie abfragbar. |
| **C2** | Kontext-Aufbau ins Gateway ziehen; für die migrierten Keys nimmt es **kein** Wissen von außen | **Scope-treu:** für den neuen Basisrezept-Ablauf gilt der Eingabevertrag vollständig, und es gibt **keinen Rückfall** auf die alte Versorgung. Nicht migrierte Funktionen (Gerichte, Konzepte, Foodbook, Angebot) behalten ausdrücklich den bisherigen Pfad. **Nachweis am Verhalten der Schnittstelle:** ein übergebenes `knowledge` wird für einen migrierten Key **abgewiesen** (Exception, nicht ignoriert) — eine Textsuche im Quellcode ist höchstens Zusatzkontrolle. Die **globale Entfernung der Option** ist `F2`, nicht hier. **Reihenfolge:** erst Aufbau im Gateway, dann Entzug — nie umgekehrt. |
| **C3** | **Sidebar-Mikrofon** (`voice-modal` / `callWithTools()`) auf denselben Aufbau — Befund D1: heute weder Kanon noch Routing. **Das Leitstellen-Diktat bleibt unverändert** (D2, reines STT, speist schon den richtigen Pfad) | Ein Sprach-Auftrag „Basisrezept für Pilzragout, 80 Personen" wird in einen strukturierten `RecipeAuftrag` übersetzt und läuft dann **exakt** wie über das Formular: im Call-Log **dieselben** Pflichtquellen und dieselbe Profilversion. Freie Wissensfragen und Recherche bleiben Tool-Loop wie heute. Schreibaktionen bleiben Proposal mit Bestätigen-Knopf (GL-07). |
| **C4** | `ConformanceService` bezieht die Regelwerke aus demselben Aufbau statt per `slug LIKE` (Befund G5) | `ladeRegelwerke()` ist entfernt oder ruft den zentralen Aufbau. Der Critic-Prompt enthält dieselben Regelversionen wie der Generator-Prompt desselben Laufs — per Fingerprint belegt. |
| **C5** | Selbstheilung erden (Befund I5/I6): Regel-ID + Befund gehen hinein, der Dienst lädt die Regelversion | `RecipeConformanceAdapter::revise()` ruft nicht mehr ohne Kontext. Der Selbstheil-Prompt enthält **den verletzten §** + Grundregeln — nicht 3 per Jaccard gewürfelte aus 61. Umfang nach Anlass: Titeländerung ≠ vollständige Überarbeitung ≠ Fehlerkorrektur. |
| **C6** | Provenienz-Invariante | Nur der zentrale Aufbau schreibt in den System-Regelblock. Suchergebnisse und Nutzereingaben stehen in der User-Message und sind beschriftet. Test: ein Aufrufer, der Regeltext über ein Kontextfeld einschleusen will, landet nicht im System-Block. |
| **C7** | Fünf sichtbare Felder je Modellaufruf | Im Inspektor **und** im Call-Log je Aufruf: Auftrag · Profilversion · übermittelte Quellen (Slug@Version) · **ausgelassene Inhalte** · Prüfergebnis. `GENERATOR_FEATURES` filtert nicht mehr die drei Folge-Calls weg (Befund G6). |
| **C8** | **Abnahme-Lauf mit eingebautem Fehler** | Ein Basisrezept wird erzeugt, angereichert, geprüft; ein gezielt eingebauter §-Verstoß wird erkannt **und** korrigiert; höchstens 2 automatische Korrekturrunden, danach bleibt ein Entwurf mit **offenen Punkten** erhalten. Der ganze Lauf ist über die Lauf-ID nachlesbar. |
| **C9** | Wiederherstellungs-Probe, solange der Ablauf klein ist | Eine leere Umgebung stellt den veröffentlichten Stand **samt Quellen** her und baut denselben fachlichen Kontext auf. Kriterium: gleiche Regeln, gleiche Datenzugriffe, bestandene fachliche Prüfungen — **nicht** identische KI-Formulierungen. |

**DoD Etappe C — die Abnahme der ganzen Spec:**
1. Derselbe Auftrag über Formular, Sprache und MCP erhält **dieselben Pflichtregeln und
   dieselben Datenzugriffsrechte** (Kanal-Äquivalenz, als Test).
2. Kein erforderliches Wissen verschwindet still durch Budget (B2/B3 greifen).
3. Jeder Schritt zeigt übermittelte Quellen **mit Version** und ausgelassene Inhalte.
   **Und keinen hängenden §-Verweis** (`H7`): kein übermitteltes Dossier verweist auf ein §,
   das nicht im selben Prompt steht.
4. Änderungen an Mengen oder Zutaten lösen die nötigen Folgeberechnungen und -prüfungen aus.
5. Eine frisch aufgesetzte Umgebung lädt denselben veröffentlichten Regelstand.
6. Die Referenzrezepte aus A1 bestehen die vorher festgelegten fachlichen Prüfungen.
7. **Die Zahl gepflegter Prompt-Keys ist kein Abnahmekriterium.**

### Etappe D — Ein Schlüsselraum, ein Sollzustand

| ID | Arbeitspaket | Definition of Done |
|---|---|---|
| **D1** | Routing-Schlüssel = **Prompt-Key**: `RecipeGenerationContextService:88` übergibt `$genKey` statt `'ai_generate_recipe'` (Befund B5) | `vk.generator` ist eigenständig routbar. Ein Test belegt, dass ein Routing auf `vk.generator` wirkt (heute schreibt es stumm ins Leere). |
| **D2** | Steuerdaten migrieren: 13 `ai_generate_recipe`-Zeilen auf `recipe.generator` **und** `vk.generator` spiegeln; `ai_extract_recipe`/`ai_suggest_pairings`/`ai_infer_ankers`/`ai_plan_dishes` auf ihre Nachfolger; **`pairing` und `trend` entfernen** (keine Kategorien mehr) | `knowledge_routings` enthält keinen `ai_*`-Schlüssel und keine Kategorie außerhalb des 21er-Vokabulars mehr. A2-Bericht ist frei von Waisen. |
| **D3** | `VorgangsRegisterService` (6 Stellen) auf den Prompt-Key umstellen | `grep -n "ai_generate_recipe" src/` liefert nur noch Doku/Beispiele in Tool-Beschreibungen. |
| **D4** | **Ein** Budget-Baum: `ai.knowledge_budget` (feature) und `ai.bound_knowledge_budget` (prompt_key) zusammenführen (Befund H5) | Kein Feature hat mehr zwei Zahlen. `recipe.eigenschaften` 27.500 vs. 8.000 ist aufgelöst und begründet. |
| **D5** | **Profil-Entität + Regelpakete + Veröffentlichung.** ⚠ Schema-Arbeit: `scope`/`role` in `knowledge_canon` sind zwei Enum-Spalten, keine Paketzuordnung | Es existieren: Paket-Entität, Paket→Dossier, Prompt-Key→Paket, Veröffentlichungsstempel mit **Versions-Fingerprint** der referenzierten Dossiers, definierte Auflösungsreihenfolge bei Widersprüchen. Dieselbe Naming-Regel wird **einmal** gepflegt und von n Keys benutzt. |
| **D6** | Drei explizite Zustände | Profil vorhanden → Schritt läuft · bewusst `none` → Schritt läuft, Entscheidung dokumentiert · **Profil oder Pflichtquelle fehlt → Konfigurationsfehler zur Laufzeit**. Ein fehlendes Profil aktiviert **nie** still eine alte Bindung. Test je Zustand. |
| **D7** | Seeder wird Installationswerkzeug, nicht zweite Wahrheit (Befund H1/H2, Widerspruch G1) | Die **veröffentlichte Profilversion** ist der verbindliche Stand; Oberfläche und MCP schreiben Entwürfe über **einen** Schreibdienst; Installation/Wiederherstellung **importieren** veröffentlichte Versionen. Die drei widersprüchlichen Routing-Migrationen (`2026_08_27_140000`, `2026_08_29_000001/2`) sind durch eine korrigierende überholt. |
| **D8** | Sechs Kategorien global seeden (Befund H4) | `produktion_kapazitat`, `referenzgericht`, `weltkueche`, `signatur_kuechen`, `ernaehrung`, `prasentation_service` existieren global. `knowledge.POST` in diesen Kategorien funktioniert in einem frischen Team. |
| **D9** | Drift-Test auf **alle** Features und **Code gegen DB** (heute: nur `ai_generate_recipe`, nur Code gegen Code) | Der Test schlägt fehl, wenn Live-Tabelle und veröffentlichter Sollzustand für **irgendein** Feature auseinanderlaufen. |

**DoD Etappe D:** Ein Call trägt **einen** Schlüssel. Es gibt genau **einen** Ort, der sagt,
was ein Schritt bekommt — und genau **einen** Sollzustand, aus dem eine leere Umgebung ihn
herstellt.

### Etappe E — Ein Rechner

| ID | Arbeitspaket | Definition of Done |
|---|---|---|
| **E1** | Vier Formeln → **eine**: lexikalisch und semantisch unabhängig ermitteln, dann fusioniert bewerten (RRF); **ein** Tokenizer, **eine** Stoppwortliste (Befund C2) | `discoverGenericBlock`, `searchDocuments`, `selectBoundKnowledge` und der Browser rufen denselben Rechner. Die vier Proben aus Befund C sind Regressionstest: „Wie viel Gramm Hauptkomponente pro Person" liefert `mengen_defaults--hauptgang-komponenten` auf **Platz 1–3**, nicht 7. |
| **E2** | Kandidatensuche ≠ Endauswahl (Grundsatz C, Größen aus `B0`) | `max_docs` begrenzt die **Endauswahl**. Die Kandidatenermittlung hat ihr **eigenes, großzügiges Limit** — nicht unbegrenzt, aber deutlich über der Endauswahl, damit es etwas zu wählen gibt. Ohne brauchbaren Treffer bleibt optionales Fachwissen **leer**, statt den besten Rauschtreffer zu nehmen. |
| **E3** | Browser-**Vorschau** mit demselben Auftrag und Profil | Der Kurator wählt einen Schritt + Auftrag und sieht genau die Auswahl, die dieser Schritt bekäme — inklusive der ausgelassenen Inhalte. |
| **E4** | Browser benutzt denselben Rechner (heute rohes `LIKE` **oder** rein semantisch, kein Hybrid) | Gleiche Anfrage → gleiche Rangfolge in Browser, Generator und MCP. Test vergleicht die drei Einstiege. |

**DoD Etappe E:** Dieselbe Frage hat **eine** Antwort, egal wer fragt. Der Mensch kuratiert
gegen dieselbe Rangfolge, die die KI sieht.

### Etappe F — Alt-Struktur abräumen (erst nach A5 + C)

| ID | Arbeitspaket | Definition of Done |
|---|---|---|
| **F1** | **Bindungs-Triage** — keine Umbuchung. Je Bindung eine Entscheidung: verbindliche Regel → Regelpaket · Mengen-/Zahlen-Standard → Resolver · Fachwissen → Suche · Inspiration → optional · veraltet/doppelt → **entfernen** | Jede Bindung aus A5 hat eine dokumentierte Entscheidung. **Bereichs-Präfix-Bindungen (`recipe`, `vk`) sind aufgelöst** — die haben in Spec 46 §2d 16.952 Zeichen Blindleistung erzeugt. |
| **F2** | Bindungs-Zweig aus `AiGatewayService::propose()` entfernen | `prompt_parts.bound` = 0 bei **jedem** Prompt-Key. `selectBoundKnowledge()` und der dritte Tokenizer sind weg. |
| **F3** | `knowledge.BIND`/`.UNBIND` deprecated; Browser-UI „Einbinden" auf Profil umstellen; roher Schreibpfad `Browser::addBinding()` mit erledigt (Befund F) | Registry-Count-Tests mitgezogen. Kein Schreibpfad auf `knowledge_bindings` außerhalb der Migration. |
| **F4** | `regelwerkBlock()` + `REGELWERK_SLUG_LIKE` + `->first()`-Pfad **löschen**, nicht entschärfen | Vorher ziehen `concept.brief_geruest` und `foodbook.grundgeruest` auf Profile um. `RegelwerkKnowledgeRoutingTest` ist mit abgeräumt. Danach kann keine `always`-Zeile mehr ein Monolith-Dossier reaktivieren. |
| **F5** | `_sections` + `_chunks` + `knowledge-sectionize` droppen (Migration `2026_09_05_000010` nennt es als eigenen Entscheid) | Tabellen weg, Command weg, `KnowledgeSectionizerTest` weg. |
| **F6** | Sechs Tests der Alt-Struktur mitziehen (Befund H8) | `KnowledgeBindToolTest`, `WissenTenantTest`, `DossierRoutingZielTest`, `WissensVokabularSchreibrechtTest`, Teile von `WissenTokenWelle0Test`, `RegelwerkKnowledgeRoutingTest` sind angepasst oder entfernt. **Volle Suite grün** vor Deploy. |
| **F7** | **Profil-UI** — die neue Struktur bekommt eine Kurationsoberfläche (heute hat nur die alte eine, Befund H7) | Ein Mensch kann Profile und Regelpakete pflegen, veröffentlichen und die Versionshistorie sehen — ohne MCP. |
| **F8** | `knowledge.DELETE` der 155 Originale (offener Punkt aus Spec 50) nach Vault-Spiegel | Erst nach explizitem Go und gesichertem Vault-Spiegel der 452. |

**DoD Etappe F:** Es gibt **eine** Steuertabelle für „muss", **eine** für „darf gesucht
werden", **keine** Bindungen, **kein** reaktivierbarer Monolithen-Pfad und **kein** totes Schema.

### Etappe G — Zugriffsmodell (eigener Entscheid, nach C)

| ID | Arbeitspaket | Definition of Done |
|---|---|---|
| **G1** | **Bestand + Eigentümer + Lesefreigaben + Dokumentzuordnung** — statt „alles global" oder erfundener `parent_team_id`-Hierarchie | BHG.DIGITAL besitzt den kuratierten Kochwissens-Bestand, die 8 Caterer-Teams haben Lesefreigabe, Kundenrezepturen bleiben getrennt. |
| **G2** | `knowledge_team_scope` **und** die eigene `applyVisible`-Logik des Browsers **ersetzen** — nicht daneben treten | Es gibt genau **eine** Antwort auf „was ist sichtbar", für Browser, KI und MCP bei gleichem Benutzer- und Teamkontext. |
| **G3** | Datenmigration vor der Umstellung prüfen | Messung vorher/nachher: kein Team verliert Zugriff auf Dossiers, die es fachlich braucht. Der dokumentierte 598→6-Fall (1-%-Korpus) tritt nicht ein. |

**DoD Etappe G:** Eigentum und Lesefreigabe sind getrennt. Kein Team sieht 1 % des Korpus,
und keine Org-Hierarchie behauptet eine Beziehung, die fachlich nicht besteht.

---

### Etappe H — Wissen nach ART und ACHSE ablegen, nicht nur nach Thema

**Der Anlass, gemessen:** `cross_cutting` hat **165 Dossiers** — und die Kategorie **ist** die
Routing-Einheit. `ai_generate_recipe × cross_cutting discovery 6×8000` heißt wörtlich: wähle
6 aus 165 per Jaccard. In dieser einen Kategorie liegen mindestens **sieben verschiedene Arten**
von Wissen:

| Was drin liegt | Art | Wie es benutzt werden müsste |
|---|---|---|
| `mengen_defaults`, Garverluste | Zahlen-Tabelle | **auflösen** über Gang × Rolle × Portion |
| `synonyme`, `anti_marker`, `substitutionen` | Vokabular/Mapping | **auflösen** über Zutat |
| `geschmacksbalance`, `foodpairing-prinzip` | Sensorik-Theorie | suchen, aufgabenbezogen |
| `sauce_mutterstrukturen`, `techniken`, `bruehen_fonds` | Handwerk | suchen, zutatenbezogen |
| `menue_architektur`, `anlass_serviceformen` | Komposition | auflösen über Anlass/Format |
| `saisonkalender` | Zeitbezug | **auflösen** über Monat |
| `allergen_patterns` | Kennzeichnung | Code erzwingt es ohnehin (§7) |

**Die Diagnose:** das Feld `category` macht **zwei Jobs, die sich widersprechen** — thematische
Ablage für Menschen (Browsen, Kuratieren) **und** Routing-Einheit für die Maschine
(feature × category → Modus/Deckel). Solange ein Feld beides tut, entsteht die
Sammelschublade zwangsläufig. Und aus einer Schublade mit sieben Arten kann keine Suche
sinnvoll sechs ziehen.

**Der falsche Fix wäre, `cross_cutting` in acht Unterkategorien zu schneiden.** Dann sind es
~30 Kategorien × ~15 Features = bis zu **450 zu pflegende Routing-Zeilen** (Befund H1 im
Quadrat) — und die Maschine weiß weiterhin nicht, *wie* sie das Wissen benutzen soll. Sie
sucht weiter nach einer Zahlentabelle.

**Der richtige Fix ist eine zweite, orthogonale Achse.** Nicht feinere Kategorien, sondern zwei
Felder plus Achsenwerte:

- **`art`** ∈ `regel | datenwerk | fachwissen | referenz` → entscheidet den **Mechanismus**
  (Grundsatz A). Vier Werte, nicht dreißig.
- **`category`** → bleibt die **thematische Ablage** für Mensch und Suchfilter.
- **Achsenwerte am Dossier** (Gang, Komponentenrolle, Niveau, Anlass, Sektor, Saison,
  Warengruppe) → dort, wo das Dossier fachlich gilt.
- **Typisierte Querverbindungen** (`H6`) → die dritte Dimension. Achsen beantworten
  *„welches Dossier gilt hier"*, Links beantworten *„ohne welches ist es unvollständig"*.
  Zwei verschiedene Fragen — die Achse ersetzt den Link nicht.

Damit wird Retrieval für `datenwerk` ein **Join statt einer Suche** — zuverlässig im
**Auswählen des richtigen Eintrags**, kein
Jaccard, keine Budget-Lotterie. Das ist der Mechanismus, den `achsenBlock()` +
`config('foodalchemist.ai.knowledge_axis_map')` **schon haben** — heute mit genau **zwei**
Achsen (`occasion`, `sektor`), ~10 Zuordnungen und einer im Code dokumentierten Lücke
(`restaurant` hat kein Segment-Dossier, bewusst nicht umgebogen).

⚠ **Ein Join wählt zuverlässig das richtige Dossier — er garantiert keinen richtigen Wert.**
Bei einem Mengen-Standard entscheiden Bezugsgröße und Einheit (pro Portion vs. pro Ansatz,
Rohgewicht vs. verzehrfertig); bei Garverlusten Produkt und Verfahren. Deshalb braucht
`datenwerk` einen **strukturierten Eintrag**, kein per Achse gefundenes Markdown — siehe `B6`
und `B7`.

| ID | Arbeitspaket | Definition of Done |
|---|---|---|
| **H1** | Feld **`art`** am Dossier + Vokabular. Routing entscheidet künftig über **Arten** („dieser Schritt darf Fachwissen suchen"), nicht über Feature×Kategorie-Paare | Jedes Dossier hat genau eine `art`. Das Routing-Schema kennt Arten. Ein Test verhindert `datenwerk` im Suchpfad und `fachwissen` im Resolver-Pfad. |
| **H2** | **Achsenwerte am Dossier** + Ausbau von `knowledge_axis_map` über die heutigen zwei Achsen hinaus (Gang, Komponentenrolle, Niveau, Saison, Warengruppe) | Für jede ausgebaute Achse gilt: das zuständige Dossier erreicht den Prompt **per Join**, unabhängig vom Suchrang. Belegt an den A1-Referenzfällen. Die `restaurant`-Lücke ist geschlossen oder als Lücke bestätigt. |
| **H3** | Bestand markieren: 1.105 Dossiers mit `art` + Achsenwerten versehen — **KI-Vorschlag, menschliche Freigabe** | Muster existiert im Vault: `110_destillate_aktivieren.py` hat 73 Destillate per Gemini mit Frontmatter-Feldern angereichert. Kein Dossier wird ohne Freigabe scharf. Fortschritt ist zählbar (markiert / offen). |
| **H4** | Dossiers aufteilen, **nicht Kategorien nach Art trennen** — erst nach H1 | ⚠ **Korrektur meiner ersten Fassung:** gemischte Arten in einer Kategorie sind **erlaubt und richtig**. Eine Kategorie „Saucen" enthält legitim eine Regel (Anforderungen an eine Saucenrezeptur), ein Datenwerk (Dosiertabelle Bindemittel), Fachwissen (warum eine Emulsion bricht) und eine Referenz (Beispielrezept). Was aufgeteilt wird, ist ein **einzelnes Dokument**, das Inhalte verschiedener Arten mischt und getrennt benutzt werden muss. **Keine Obergrenze pro Kategorie** — die Dokumentzahl sagt nichts über die Auswahlqualität (Grundsatz C). DoD: jedes Dossier mit gemischten Arten ist geteilt, jedes Teil trägt eine Art. |
| **H6** | **Typisierte Querverbindungen** zwischen Dossiers — die dritte Dimension neben Art und Achse. Achsen beantworten *„welches Dossier gilt hier"*, Links beantworten *„ohne welches ist es unvollständig"* | Drei Typen: **`ergaenzt`** (A ist ohne B unvollständig — maschinell nutzbar, Hüllenbildung) · **`ersetzt`** (Nachfolger; hätte die stille Regression beim 155-Cutover verhindert, wo Slugs auf deaktivierte Dossiers zeigten) · **`siehe_auch`** (Nachbarschaft, nur UI-Navigation). **Ernten statt kuratieren:** der Vault hat die Kanten schon als `[[Wikilinks]]` + `referenzen:`-Frontmatter, `broken_link_check.py` validiert sie wöchentlich — `knowledge-import` liest sie mit. ⚠ **Harte Regel: Links sind KEIN Retrieval-Mechanismus.** Folgt die Suche Kanten, zieht ein Treffer die Nachbarschaft mit und das Budget explodiert — dasselbe Muster wie die Präfix-Bindungen (16.952 Z. Blindleistung), nur über eine andere Straße. `ergaenzt` gilt **nur für `art=regel`**, typisiert und gedeckelt; `siehe_auch` wirkt **nur** in der UI. |
| **H7** | **Hüllen-Prüfung: kein hängender §-Verweis im zusammengesetzten Prompt** — der Defekt, den der Split erzeugt hat. ⚠ **Erweitert `foodalchemist:wissen-deckung` (W2-4)**, baut kein drittes Kommando: das prüft schon „Prompt nennt § → Korpus hat Dossier"; hier fehlt „Dossier verweist auf § → § steht im selben Prompt" | Vorher war `Regelwerk_Basisrezepte` **ein** Dokument, §2 konnte inline auf §4 und §11 verweisen. Nach dem Split ist der Verweis ein Textstring ohne Ziel. Belegbar: der Kanon von `recipe.generator` trägt §1.0–1.5, §2, §3, §4, §6 — **nicht** §11 (Derivate), **nicht** §1.10/§1.11 (Anti-Patterns), auf die §2 verweist. DoD: ein Prüfer meldet jeden §-Verweis in einem übermittelten Dossier, dessen Ziel nicht im selben Prompt steht. Auflösung je Fall: Ziel mitliefern (`ergaenzt`), Verweis auflösen, oder Verweis entfernen — **nie** hängen lassen. |
| **H5** | Kategorie-Vokabular aufräumen | `pairing` (3 Routing-Zeilen, keine Kategorie) und `trend` (2 Seed-Zeilen, keine Kategorie) sind weg (→ D2). Die sechs nur-Team-Kategorien sind global (→ D8). Vokabular und Routing-Kategorien sind deckungsgleich — der A2-Bericht beweist es. |

**DoD Etappe H:** Jedes Dossier trägt eine Art. Verbindliche Zahlen und Vokabulare erreichen
den Prompt **deterministisch über Achsen**, nicht über Suchrang. Keine Kategorie mischt Arten.
Die Zahl der Routing-Zeilen ist **kleiner** als heute, nicht größer.

**Reihenfolge — NEU GESETZT 2026-09-07 (Dominique baut die Dossiers inhaltlich um):**

Dominique wird den Korpus **inhaltlich neu aufbauen**: die Dossiers genügen seinem Anspruch
nicht, sie sind nicht sauber einem Thema zugeordnet, und Themen überlappen sich. Damit dreht
sich die Reihenfolge innerhalb von H:

- **`H1` + `H2` + `H6` nach vorn (Felder zuerst).** `art`, Achsenwerte und typisierte
  Querverbindungen müssen **existieren, bevor** er schreibt — dann tragen die neuen Dossiers
  sie von Anfang an, statt hinterher markiert zu werden. Beide auch als **UI + MCP**
  (Grundsatz E), denn er pflegt sie.
- **`H3` (1.105 Dossiers markieren) entfällt weitgehend.** Bestand markieren, der ersetzt wird,
  ist Arbeit für die Tonne. Was bleibt: markieren, was den Umbau überlebt.
- **`H4` macht der Umbau selbst.** Unsere Aufgabe ist nicht das Schneiden, sondern der
  **Wächter**, der gemischte Arten in einem Dokument nicht zurückkommen lässt.
- **`H7` bleibt** als Wächter — hängende §-Verweise entstehen beim Umbau genauso wie beim Split.

> ★ **Reihenfolge-Konsequenz, die ich für die wichtigste dieser Runde halte:
> `D6` muss VOR dem Dossier-Umbau stehen.**
>
> Der Kanon referenziert **Slugs**. Ein inhaltlicher Neuaufbau, der Slugs umbenennt oder Themen
> neu schneidet, **leert den Kanon still** — genau die Falle, in die der 155-Originale-Cutover
> gelaufen ist (Bindungen zeigten auf deaktivierte Dossiers, `crossCuttingDocs()` übersprang
> lautlos). Ohne `D6` („Profil oder Pflichtquelle fehlt → **Konfigurationsfehler zur
> Laufzeit**") merkt niemand, dass die Generatoren nach dem Umbau ohne Regelwerk laufen. Es
> würde nur schlechtere Rezepte geben.
>
> Deshalb: **`D6` vorziehen, gemeinsam mit `C0`** — und dazu eine Slug-Zuordnung führen
> (alt → neu), damit der Umbau die Kanon-Zeilen mitnimmt statt sie zu verwaisen. Das ist
> derselbe Mechanismus wie `H6`s `ersetzt`-Kante.

## ✅ Etappe C0 + D6 — Profil, Fingerabdruck, drei Zustände (2026-09-07)

**Vorgezogen, weil der Korpus-Umbau ansteht.** Ohne diese Prüfung liefe er in dieselbe stille
Falle wie der 155-Originale-Cutover.

### ★ Korrektur meiner eigenen Warnung: der Kanon hängt an IDs, nicht an Slugs

Ich hatte geschrieben „der Kanon zeigt auf Slugs → ein Umbau leert ihn still". Das ist
**falsch**. `foodalchemist_knowledge_canon.knowledge_document_id` ist ein FK auf die Doc-ID.
Daraus folgt eine andere, präzisere Risikolage:

| Was du beim Umbau tust | Was mit dem Kanon passiert |
|---|---|
| Dossier **umbenennen** (Slug ändern) | ✅ **nichts** — die Zeile trägt mit |
| Dossier **inhaltlich überarbeiten** | ✅ trägt mit (der Fingerabdruck ändert sich, weil `version` eingeht) |
| Dossier **deaktivieren** | ⚠️ Zeile fällt still aus `documentsFor()`, `hasCanon()` sagt weiter `true` → **weniger Pflichtwissen, kein Hinweis** |
| Dossier **soft-deleten** | ⚠️ Zeile ist über `zeilenQuery()` **nirgends** mehr sichtbar, auch nicht in `list()` |
| Dossier **löschen + neu anlegen** | ⛔ `cascade` nimmt die Zeile mit → `hasCanon()` wird `false` → **der Gateway schaltet die alten Bindungen wieder scharf** |

Der letzte Fall ist der gefährlichste und war vorher nirgends beschrieben: ein Umbau, der
Dossiers ersetzt statt bearbeitet, **reaktiviert stillschweigend die Alt-Struktur**. Auf demo
wären das die 9 Bindungen auf den zwei Generator-Keys — statt 13 kuratierter §-Dossiers käme
`recipe.generator` dann mit 5 durch.

→ **Praktische Konsequenz für den Umbau:** wo möglich **bearbeiten statt ersetzen**
(`knowledge.PUT`), und die 9 Bindungen aus `F1` **vor** dem Umbau löschen, damit es keinen
stillen Rückfall gibt.

### Was gebaut ist

| Baustein | Inhalt |
|---|---|
| `KnowledgeCanonService::unaufloesbareZeilen()` | sieht bewusst **ohne** die beiden Doc-Filter nach — die drei unsichtbaren Zustände oben werden meldbar |
| `WissensProfilService` | aufgelöstes Profil je Prompt-Key: Pflicht (mit Version), wenn_platz, Routing, beide Budgets, Zustand, Befunde, **Fingerabdruck** (16 Stellen über Kanon+Versionen+Routing+Budget) |
| Vier Zustände (D6) | `gesteuert` · `bewusst_leer` · `ungesteuert` · **`fehlerhaft`** — der neue: hinterlegt, aber löst nicht auf. Sah vorher wie `gesteuert` aus und lieferte weniger. |
| Befund `bindung_wuerde_scharf` | warnt genau vor dem `cascade`-Fall, **bevor** er eintritt |
| Laufzeit (`AiGatewayService`) | eine `Log::warning`-Zeile je Call mit unauflösbarer Kanon-Zeile — bewusst **kein Abbruch**: ein Wissensproblem darf keine Generierung zerreissen. Der laute Kanal ist der Wächter. |
| `foodalchemist:wissen-profil` + `knowledge_profil.GET` | beide Flächen (Grundsatz E), Exit 1 bei blockierendem Befund |
| **UI-Sektion „Wissens-Steuerung"** | Überblick + Profile + Befunde **und** der Routing-Editor. Damit hat die neue Ebene erstmals eine Oberfläche — vorher hatte nur die Alt-Struktur eine. |

**Nur `blockiert` kippt den Zustand**, ein `hinweis` nicht — sonst stünden die drei gesunden
Kanon-Keys als fehlerhaft da und die Meldung wäre nach einer Woche Rauschen.

### Nebenbefund: drei echte Lücken im Test-Harness geschlossen

Der Ganzseiten-Test deckte auf, dass **jeder** `$this->get(route(...))` im Harness mit 500
geantwortet hätte: Cores `layouts/app.blade.php` braucht `user_ui_preferences`,
`team_core_ai_models` und `team_invitations`. Alle drei sind jetzt in der Allowlist. Die Kette
endet bei `oauth_access_tokens` (Passport) — dort ist Schluss, der Test bleibt übersprungen und
die Seite ist stattdessen **gegen die Dev-MySQL** verifiziert (Status 200). Das ist genau der
Weg, den `feedback_fa_test_harness_layout_blind` vorschreibt.

---

## ✅ Etappe H1 — die Wissensart als Feld (2026-09-07)

**Vorgezogen, damit die neuen Dossiers sie von Anfang an tragen.** Bestand markieren, der
ohnehin ersetzt wird, wäre Arbeit für die Tonne — deshalb `nullable`, **ohne Backfill**.

### Warum die Kategorie als Steuergrösse nicht reicht

Die Kategorie sagt, *worum* es geht. Sie sagt nicht, *wie* das Wissen benutzt werden darf —
und daran ist die Steuerung bisher gescheitert:

- **`workflow`** mischt Handwerkswissen für den Prompt
  (`workflow.basisrezept_erstellungs_dossier`, Mutterstruktur einer Sauce) mit
  Agenten-Anleitungen (`workflow.basisrezept_regeln`, „Regel 1: alles ist Entwurf",
  `primaer=lieferantenartikel_waehlen`). Ein Routing auf die Kategorie hätte beides in jeden
  Prompt gezogen — **deshalb hat `workflow` bis heute gar kein Routing.**
- **`cross_cutting`** mischt Nachschlagewerke (`mengen_defaults` — eine Tabelle, die man über
  Gang × Rolle × Portion auflöst) mit echtem Suchmaterial (`geschmacksbalance`).

### Fünf Arten, als Code-Konstante

`Services\Knowledge\Wissensart`: `regel` · `datenwerk` · `fachwissen` · `referenz` · `ablauf`.

**Bewusst kein pflegbares Vokabular.** Der Code entscheidet anhand dieser Werte — wäre die
Liste zur Laufzeit erweiterbar, könnte er sich nicht darauf verlassen, dass `ablauf` „niemals
in einen Prompt" heisst, und ein frei erfundener Wert wäre still wirkungslos. Die Kategorie
bleibt pflegbar, die Art nicht.

### Was das Feld am ersten Tag TUT

Damit es nicht dekorativ ist: **`ablauf` kommt in keinen Prompt.** Gefiltert an den drei
Stellen, die Prompts bauen — `crossCuttingDocs()`, `alwaysCategoryBlock()`,
`discoverGenericBlock()` (über den neuen `nurFuerPrompt()`-Helfer) und
`KnowledgeCanonService::documentsFor()`.

★ **Und es verschwindet nicht still:** steht ein `ablauf`-Dossier im Kanon, meldet
`unaufloesbareZeilen()` es als **blockierenden** Befund `art_nie_im_prompt` mit dem Hinweis,
dass Agenten es über `ablauf.GET` erreichen. Genau die Lehre dieser Spec — was der Prompt-Bau
weglässt, muss irgendwo auftauchen.

**Der Filter sitzt bewusst NICHT in `nurSichtbar()`**, obwohl das die eine Stelle wäre, die
alle 20 Query-Sites erwischt: `knowledge.SEARCH` und der Browser müssen `ablauf`-Dossiers
weiter **finden** — genau darüber holen sich Agenten ihre Anleitung. Ein Blanket-Filter hätte
H1 den Agenten-Weg gekappt. Ein Test pinnt beides.

`art IS NULL` bleibt überall erlaubt — sonst fiele der gesamte, noch nicht eingeordnete
Bestand aus jedem Prompt.

### Flächen (Grundsatz E)

`knowledge.POST` / `.PUT` nehmen `art` (Leerstring = zurücksetzen), `knowledge.LIST` gibt sie
zurück, und der **Wissens-Browser** hat einen Auswahl-Knopf mit den fünf Arten samt
Erklärung. Der Vault-Re-Import überschreibt sie nicht (`KnowledgeImportCommand:172` schreibt
nur Inhalt/Titel/Version/Hashes).

---

## ✅ Etappe H2 — Achsen als dritter Kanon-Scope (2026-09-08)

★ **Kein viertes Steuer-Konstrukt.** Eine Achsen-Bindung beantwortet dieselbe Frage wie der
Kanon („welches Dossier gilt verbindlich"), nur mit einem anderen Schlüssel. Also erweitert
sie den Kanon statt daneben zu stehen: `scope='achse'`, `scope_key='<achse>:<wert>'`, etwa
`occasion:dinner`. `ord` trägt die Kandidaten-Reihenfolge, `mode` bleibt `pflicht`.

**Der Payoff der Wiederverwendung:** Tenancy, `unaufloesbareZeilen()`, **alle drei
MCP-Tools** und die künftige Kanon-UI gelten sofort mit — alle drei Tools lesen
`KnowledgeCanonService::SCOPES`, ein `achse` in der Konstante genügte. Eine eigene Tabelle
hätte all das gedoppelt, also genau das Muster erzeugt, das diese Spec abbaut. Keine Migration.

### Was sich am Verhalten ändert

`achsenBlock()` liest jetzt über `achsenKandidaten()`: **Kanon-Zeile gewinnt, Config ist
Fallback** — dasselbe Muster wie beim Kanon selbst. Achsen-*Namen* kommen aus Config ∪ Kanon,
damit eine ganz neue Achse **ohne Deploy** verdrahtet werden kann (Grundsatz E). Und
`achsenBlock()` filtert jetzt über `nurFuerPrompt()`, also auch hier kein `ablauf`.

Bestandsschutz ist gepinnt: ohne Kanon-Zeile läuft der Config-Baum unverändert. Wäre er nach
dieser Änderung tot, verlören alle Anlässe ihr Wissen, ohne dass ein Test rot wird.

### Neuer Befund `datenwerk_ohne_achse`

Ein Dossier, das als `datenwerk` deklariert ist, aber an keiner Achse hängt, macht das
Gegenteil des Gewollten: es liegt als Prosa im Suchtopf und konkurriert um Rangplätze — der
Fall, den die Messung mit „Mengen-Standard auf Platz 7" gezeigt hat. Gemeldet als **Hinweis**,
nicht als Fehler: solange die Achse fehlt, ist die Suche immerhin ein Weg. Sichtbar im
Kommando, im MCP-Bericht und auf der Steuerungs-Seite.

### Was ich NICHT gebaut habe — und warum

**`B6` (`mengen_defaults` in einen Resolver) bleibt liegen.** Ein Mengen-Standard wird nicht
über *einen* Achsenwert bestimmt, sondern über **Gang × Komponentenrolle × Portionskontext** —
das ist eine Tabelle, kein Dossier, und der ehrliche Fix heisst: Zahlen aus der Prosa
herauslösen und dem Generator als Werte geben statt als Text.

Genau diese Prosa wird aber gerade neu geschrieben. Eine Tabelle aus Inhalt zu extrahieren,
der in Tagen ersetzt wird, ist dieselbe „Arbeit für die Tonne", aus der `H3` gestrichen wurde.
**Voraussetzung für B6 ist der fertige Korpus** — die Achsen-Mechanik steht jetzt bereit, und
sobald die neuen Mengen-Dossiers existieren, sind sie ohne Deploy verdrahtbar.

Die dokumentierte Lücke `sektor:restaurant` (kein Segment-Dossier, bewusst nicht umgebogen)
kann Dominique damit selbst schliessen, sobald er das Dossier schreibt.

---

## ✅ Etappe H6 — Verbindungen zwischen Dossiers (2026-09-08)

**Der Anlass:** beim Neuschnitt werden aus einem Dossier zwei und aus dreien eins. Diese
Information lässt sich hinterher **nicht rekonstruieren** — anders als eine vergessene
Kategorie, die man nachträgt, indem man das Dossier liest. Deshalb vor dem Umbau.

### Warum hier eine eigene Tabelle richtig ist

Diese Spec argumentiert durchgehend gegen neue Tabellen — bei `H2` habe ich Achsen deshalb in
den Kanon gelegt. Hier ist es anders: die drei Steuertabellen tragen alle die Form
**Schlüssel → Dossier** (`feature`/`prompt_key`/`achse` → doc, `target_key` → doc). Eine Kante
**Dossier → Dossier** passt in keine davon, und `knowledge_aliases` ist Begriff → Dossier.
Wiederverwendung wäre hier ein Formfehler, keine Sparsamkeit.

Vier Arten, wieder als Code-Konstante: `ersetzt` · `verfeinert` · `siehe_auch` ·
`widerspricht`. `widerspricht` ist bewusst festhaltbar, statt zur Auflösung zu zwingen — ob
zwei Dossiers sich widersprechen dürfen, ist eine fachliche Frage.

### ★ Was `ersetzt` am ersten Tag tut

Zeigt eine Kanon-Zeile auf ein abgelöstes Dossier, **nennt der Integritäts-Bericht jetzt den
Nachfolger**: „Nachfolger laut Verbindung: X — Kanon-Zeile dorthin umhängen." Aus einer
Fehlermeldung wird eine Handlungsanweisung. Dazu die Liste `abgeloest_ohne_nachfolger` — beim
Umbau genau das, was man abarbeiten will.

Ein Kreis-Riegel verhindert Nachfolge-Schleifen (sonst liefe die Empfehlung endlos).
`siehe_auch` darf dagegen gegenseitig sein — nur `ersetzt` braucht die Richtung.

**Schreibrecht:** die Kante gehört dem **Ausgangs**-Dossier. Ein Team darf damit seine eigenen
Dossiers auf den geerbten Master-Katalog beziehen (`verfeinert` wäre für Kundenteams sonst
tot), aber niemand hängt Kanten an fremdes Wissen.

### Der Nachtrag, den die Migration gleich mitnimmt

`2026_09_07_000001_split_global_workflow_dossiers` hat zwei Monolithen stillgelegt und durch je
vier Ein-Thema-Dossiers ersetzt — **die Zuordnung stand danach nur im Docblock jener
Migration.** Genau die Sorte Information, für die diese Tabelle da ist. Die H6-Migration trägt
sie nach, solange sie noch bekannt ist.

`workflow.rezept_anlegen_mcp` ist damit belegt. `workflow.gericht_anlegen_mcp` bleibt offen:
seine vier Nachfolger sind **team-eigene** Dossiers, die keine Migration anlegt — auf demo
existieren sie, in der Test-Fixture nicht. Der Nachtrag überspringt fehlende Slugs still,
statt zu scheitern.

**Nebenbefund aus dem Bau:** meine ersten zwei Tests behaupteten `toBe([])` auf die Liste der
abgelösten Dossiers — und wurden rot, weil die Fixture die Migrationen mitbringt. Das war kein
Testfehler in der Sache, sondern ein Test gegen den Migrationsstand statt gegen den
Mechanismus ([[feedback_testfixture_zeigt_migrationsstand]]). Umgestellt auf die eigenen Slugs,
plus ein eigener Test für den Nachtrag.

### Eine eigene Korrektur im selben Zug

Der Befund `pflicht_ueber_budget` stand als `blockiert` in einer Zusammenfassung, die sagte
„Pflichtwissen kommt dort nicht an". **Das stimmt für diesen Code nicht** — `pflicht` ignoriert
das Budget per Vertrag, das Wissen kommt an. Falsch ist die Config, nicht die Lieferung. Der
Sammeltext unterscheidet die Fälle jetzt.

Gefunden hat das der eigene Wächter auf demo: **`concept.brief_geruest` hat 10.399 Z.
Pflichtwissen bei `budget_bound` 4.200** — der konservative Default, weil dieser Key in
`ai.bound_knowledge_budget` schlicht **keinen Eintrag** hat. Der Montags-Wächter sieht das
nicht, er prüft nur die zwei Generator-Keys.

---

## ✅ Paket 2 — ein Schlüsselraum (2026-09-08)

Die Wurzel der Ausgangsfrage. `RecipeGenerationContextService:88` übergibt jetzt den
**Prompt-Key** statt des hartkodierten `'ai_generate_recipe'`.

### ★ Die Falle, die den naiven Austausch verhindert hätte

`$recipeBudget` hing an genau diesem String-Vergleich — und gatete **acht** Verhaltensweisen,
darunter jeden Pro-Dossier-Deckel (`RECIPE_MAX_CHARS_PER_DOC` 2.400 statt der Kategorie-
Defaults 1.800/2.500). Den Namen einfach zu tauschen hätte **jeden Rezept-Prompt anders
gekappt**, still, ohne dass ein Test rot wird — genau die Fehlerklasse, die diese Spec abbaut.

Jetzt ein expliziter Satz `REZEPT_BUDGET_KEYS`, und der wichtigste Test der Etappe vergleicht
**byte-identisch**, dass beide Wege dasselbe liefern (`block`, `files_used`, `total_chars`,
`dropped_chars`).

### Was das freischaltet

`routingZeilen()` löst mit **Rückfall auf den Alt-Namen** auf: eigene Zeilen gewinnen, sonst
gilt der Alias. Damit
- wirkt ein `knowledge_routings.PUT` auf `vk.generator` **erstmals** (vorher schrieb es stumm
  ins Leere),
- sind Basisrezept und Gericht getrennt steuerbar (vorher zwangsweise eine Politik),
- bleiben die 13 Alt-Zeilen wirksam, ohne Migration. Bestandsschutz gepinnt.

### Drei latente Fehler in der Regelwerk-Rückfallkarte

`REGELWERK_SLUG_LIKE` hatte einen **Blind-Default** (`?? ['ai_generate_recipe']` → `%basisrezept%`),
der dem Prinzip direkt über der Karte widersprach („ein falsches wäre schlimmer als keines"):

| Vorgang | bekam | bekommt |
|---|---|---|
| `vk.generator` / Gericht anlegen | **Basisrezepte**-Regelwerk | `%verkaufsgerichte%` |
| `gp_aus_la_anlegen` | **Basisrezepte**-Regelwerk | `%regelwerk-gp%` |
| Angebot · Speiseplan · Preis-Monitoring | **Basisrezepte**-Regelwerk | **keines** (`quelle: keine`) |

Auf demo verdeckt der Kanon das; auf einer frischen DB (`regelwerk always`) hätte es
zugeschlagen. Dieselbe Fehlerklasse wie der dokumentierte Fall „vk bekam Basisrezepte statt
Verkaufsgerichte", nur an anderer Stelle. **Der Blind-Default ist weg.**

⚠ Die Auswahl *innerhalb* eines Musters bleibt beliebig (`orderBy('slug')->first()` über 61
Dossiers, für VK also §1.2a statt §1). Dieser Pfad gehört weg — Paket 3. Hier steht nur, dass
er bis dahin nicht das *falsche* Regelwerk trifft.

### Der Drift-Wächter prüft jetzt Code gegen DB

Der bestehende Test hielt zwei **Code-Listen** gegeneinander, und nur für `ai_generate_recipe`.
Der neue vergleicht die Seed-Politik mit dem **tatsächlichen DB-Stand über alle Features** —
er hätte `G1` gefunden.

Die drei widersprüchlichen Zeilen (`recipe.eigenschaften`, `recipe.ueberarbeiten`,
`vk.ueberarbeiten`) richtet eine Migration auf den Seed-Wert, **nur wo der Alt-Wert noch
steht** — ein bewusst abweichender Bestand wird nicht überfahren.

★ **Und eine vierte Abweichung bleibt bewusst offen:** `ai_generate_recipe × regelwerk`,
Migration `always 1×9500` gegen Seed `none`. Der Seed hat recht, **solange ein Kanon
existiert**. Ohne Kanon — der Wiederherstellungs-Fall — bekäme der Generator bei `none`
**gar kein** Regelwerk, während `always` wenigstens ein beliebiges liefert. Die Zeile ist
deshalb kein Versäumnis, sondern der Beleg, dass der Kanon einen Wiederherstellungs-Pfad
braucht (`H7`). Sie steht als bedingt erlaubt im Test, mit Begründung — nicht still.

### Zwei kleinere Löcher zu

- **`concept.brief_geruest`** hat einen `bound_knowledge_budget`-Eintrag (12.000): der Key
  fehlte und fiel auf den konservativen Default 4.200 zurück, während sein Kanon 10.399 Z.
  Pflichtwissen trägt. Gefunden vom eigenen Wächter auf demo.
- **Sechs Kategorien** (`ernaehrung`, `weltkueche`, `signatur_kuechen`,
  `prasentation_service`, `produktion_kapazitat`, `referenzgericht`) stehen im Routing, waren
  aber nie global geseedet. Auf demo existieren sie als Team-Zeilen — bei einem neuen Kunden
  wären die Routing-Zeilen Vorwärtsdeklarationen ohne Wirkung, und `knowledge.POST` scheiterte
  an „Unbekannte Kategorie". Migration seedet **nur, wenn der Slug nirgends existiert**, damit
  auf demo keine Dubletten neben den Team-Zeilen entstehen.

### ★ Nebenbefund, der Paket 4 neu einordnet: die lexikalische Discovery bewertet nur den SLUG

Beim Testen fielen drei Fälle durch, und der Grund war der Befund selbst:

```
score = jaccard(queryTokens, slugTokens) + 0,1 × substringTreffer + (alias ? 1,0 : 0)
```

Der **Inhalt geht nicht ein.** Ein Dossier ist lexikalisch nur findbar, wenn sein *Slug* Wörter
der Anfrage trägt — oder ein gepflegter Alias passt (der wiegt 1,0 und dominiert damit alles).
`semantic_search.enabled` ist per Default **aus** (ENV `FOODALCHEMIST_SEMANTIC_SEARCH`); auf
demo ist es an, dort wird semantisch **vor** die Lexik gereiht.

Also: **auf demo** entscheidet Semantik (Titel + Inhalt bis zum Embedding-Fenster) mit
lexikalischer Ergänzung; **auf einer frischen Installation** allein der Slug. Für den
Korpus-Umbau sind das zwei Hebel in Dominiques Hand — Slug-Benennung und der Dossier-Anfang.

> **Korrektur 2026-09-08 (meine, nicht die des Codes):** hier stand „erste ~2.000 Zeichen".
> Falsch. Ich hatte den Config-Default gelesen statt der Live-ENV. Das Fenster steht auf demo
> seit 2026-09-07 auf **4.000** — gleich dem Dossier-Deckel, ein Dossier steckt also
> vollständig im Vektor. Gemessen mit `wissen-recall-probe --team=6 --k=10 --fenster=2000`:
>
> | | Schwanz | Kopf | Lücke |
> |---|---|---|---|
> | Fenster 2000, n=40 | 47,5 % | 85,0 % | 37,5 |
> | Fenster 4000, n=120 | **55,0 %** | 81,7 % | **26,7** |
>
> Zwei Folgen für diese Spec: (a) der von mir gemeldete „Widerspruch Deckel 4.000 vs. Fenster
> 2.000" **besteht auf demo nicht** — er bestand im **Repo**, wo der Default weiter 2.000 war
> und eine frische Umgebung still das halbe Fenster bekommen hätte. Genau das Muster aus `H7`,
> nur in einer anderen Zeile; mit Paket 3 ist der Default auf 4.000 gezogen und ein Test pinnt
> Konstante ⇄ Config gegeneinander. (b) Die **26,7 Punkte Restlücke** sind damit nachweislich
> **kein** Fenster-Problem mehr. Was sie verursacht, gehört in Paket 4 zum Rechner (`E1`),
> nicht zum Index.
>
> ★ Und die teuerste Zeile der Messung: **n=40 ist zu klein.** Ein Treffer = 2,5 Punkte, der
> Schwanz sprang zwischen n=40 (50,0 %) und n=120 (55,0 %) um 5 Punkte — mehr als der Effekt.
> `wissen-recall-probe` hat deshalb jetzt `--limit=120` als Default.

### ★ Nachtrag: ein Konstruktionsfehler von mir, beim Verifizieren gefunden

Meine erste Fassung von `routingZeilen()` gab die eigenen Zeilen zurück, **sobald es welche
gab** — also alles-oder-nichts pro Feature. Wer EINE VK-Zeile setzt, hätte damit still die
anderen elf verloren: `vk.generator` hätte plötzlich nur noch `weltkueche` gehabt, ohne dass
irgendwo steht, dass `domain`, `kueche` und `cross_cutting` weg sind. Genau die Fehlerklasse,
gegen die diese Spec antritt — selbst gebaut.

Das brach auch das Muster, das überall sonst gilt: der Kanon überschreibt die Bindungen **pro
Prompt-Key**, eine Achsen-Zeile die Config **pro Achsenwert** — immer pro Element, nie pro
Gruppe. Der Rückfall wirkt jetzt **pro Kategorie**.

Und „VK soll diese Kategorie nicht" wird ausdrücklich mit `mode = none` gesagt, nicht durch
Weglassen — sonst wäre „bewusst leer" von „noch nicht gepflegt" nicht unterscheidbar, also
genau die Unterscheidung kaputt, die der Versorgungs-Bericht trifft. Beide Richtungen gepinnt.

**Praktische Folge:** eine einzelne VK-Zeile genügt, der Rest bleibt geerbt. Ohne den Fix hätte
man erst alle zwölf Zeilen kopieren müssen, und die Strafe fürs Vergessen wäre lautlos gewesen.

### ★ Und ein zweiter: der Bericht log über die Laufzeit

Beim Beweis am echten System: eine auf demo gesetzte `vk.generator`-Routing-Zeile wirkte im
Prompt-Bau, und `knowledge_profil.GET` zeigte weiter den **geerbten Alias-Wert**. Grund: die
beiden Berichts-Dienste fragten die Routings über eine **eigene Query** ab — also eine zweite
Wahrheit neben der, die der Generator benutzt.

Ein Diagnose-Werkzeug, das über die Laufzeit lügt, ist schlimmer als keines. Prompt-Bau,
Profil-Bericht und Versorgungs-Bericht teilen jetzt **eine** Auflösung
(`KnowledgeContextService::wirksameRoutings()`) — das Prinzip „Eine Formel pro fachlicher
Wahrheit" aus `docs/ARCHITEKTUR.md`.

In derselben Korrektur steckte gleich der nächste: ich übergab der geteilten Auflösung zuerst
den **Alias** statt des Prompt-Keys, womit sie die eigenen VK-Zeilen wieder nicht gesehen
hätte. Vor dem Behaupten durchgerechnet.

### Was diese drei Selbstkorrekturen über das Modul sagen

Drei in einem Paket — Rückfall pro Feature statt pro Kategorie, Bericht mit eigener Query,
Alias statt Prompt-Key — und **alle drei mit derselben Signatur: eine zweite Stelle, die
dieselbe Frage anders beantwortet.** Genau das Muster, das diese Spec abbaut, eingebaut
während des Abbauens.

Das ist der stärkste Beleg dafür, dass die **Wächter wichtiger sind als die Einzelfixes**: der
Versorgungs- und der Profil-Bericht haben zwei der drei Fehler selbst gefunden, und zwar erst
am echten System. Ein Fix, den niemand nachmisst, ist eine Behauptung.

### Nicht in diesem Paket

Die **volle Zusammenlegung der zwei Budget-Bäume** (`ai.knowledge_budget` feature-gekeyt vs.
`ai.bound_knowledge_budget` prompt-key-gekeyt). Der akute Fall ist zu, der Umbau selbst ist ein
eigener Schnitt — und er gehört zu Paket 3, wo auch der Bindungs-Zweig und damit der zweite
Abnehmer von `bound_knowledge_budget` verschwindet.

Ebenso das **Duplizieren der 13 Alt-Zeilen** auf beide Generator-Keys: der Rückfall macht sie
unnötig, und sie gehören zum Abräumen des Alias (Paket 3).

---

## ✅ Paket 3 · Schnitt 1 — der Kanon bekommt einen Rückweg (2026-09-08)

**Warum das zuerst kommt.** Paket 3 räumt die Alt-Struktur ab: die neun stummen Bindungen, den
Bindungs-Zweig im Gateway, `regelwerkBlock()` samt `->first()`. Jeder dieser Schritte nimmt dem
System einen Fallback weg — und der Fallback fängt heute genau einen Fall auf, den sonst nichts
auffängt: **eine Umgebung ohne Kanon.**

`H7` hat den Kreis beschrieben, er schliesst sich still:

```
frische DB  →  kein Kanon (nur MCP schreibt ihn, kein Seeder)
            →  hasCanon() = false
            →  Gateway schaltet die Bindungen scharf
            →  die gibt es dort auch nicht
            →  Generator läuft ohne Regelwerk. Kein Fehler. Nur schlechtere Rezepte.
```

Wer erst die Bindungen entfernt und dann den Kanon absichert, hat zwischendurch nichts. Also
andersherum.

### Was gebaut ist

| Fläche | Was sie kann |
|---|---|
| `KanonSicherungService` | die ganze Rechnung: `zeilen()`, `lade()`, `abgleich()`, `spielEin()` |
| `foodalchemist:wissen-kanon-sicherung export\|pruefen\|import` | Shell-Weg, `import` schreibt erst mit `--apply` |
| `foodalchemist.knowledge_kanon_sicherung.GET` | MCP-Weg (Grundsatz E) — auf demo der einzige, dort gibt es keine Shell |
| `database/kanon/kanon-team-6.json` | der gesicherte Stand: **28 Zeilen**, Team 6, 2026-09-08 |

Die Rechnung liegt im Dienst und nicht im Kommando — wie bei `WissensVersorgungService`. Zwei
Implementierungen derselben Frage wären die Doppelung, gegen die diese Spec antritt.

**Kein Seeder mit Liste im Code.** Der Kanon ist Kuration, keine Politik: welche §-Dossiers
verbindlich sind, entscheidet ein Mensch und ändert sich. Eine Liste im Code wäre der fünfte
Routing-Schreiber (`H1`) in grün. Stattdessen eine exportierte Datei, die das Repo trägt — und
ein Test, der sie gegen genau den Parser hält, der sie beim Neuaufbau lesen muss.

### Drei Entscheidungen, die nicht offensichtlich waren

**1. Gesichert wird die Kuration, nicht ihr Ergebnis.** Der Export läuft über
`list(includeInactive: true)`, nicht über `documentsFor()`. Eine bewusst stillgelegte Zeile ist
Teil der Entscheidung; über `documentsFor()` verlöre die Sicherung genau das, was der
Integritäts-Bericht als Befund meldet.

**2. `active` und `global` müssen mit.** ★ Meine erste Fassung schrieb beim Import nur
scope/key/role/ord/mode/slug. Eine stillgelegte Kanon-Zeile wäre damit **scharf** zurückgekommen
und eine globale als Team-Zeile — die Sicherung hätte die Kuration verändert statt sie zu retten.
Gefunden, weil ich `set()` gelesen habe, bevor ich es benutzt habe; jetzt gepinnt.

**3. „Deckungsgleich" und „einspielbar" sind zwei Fragen.** Der Abgleich trennt vier Aussagen:

| Feld | Heisst |
|---|---|
| `nur_live` | kuratiert, aber nicht gesichert → beim Neuaufbau weg |
| `nur_datei` | gesichert, live entfernt → entweder Absicht (neu exportieren) oder still verloren |
| `abweichend` | dieselbe Zeile, anderes `mode`/`ord`/`active` |
| `ohne_dossier` | **die Sicherung nennt einen Slug, den es hier nicht gibt** |

Die letzte Zeile ist die wichtige: eine perfekt deckungsgleiche Sicherung ist wertlos, wenn ihre
Slugs neu geschnitten wurden. Nach dem anstehenden Korpus-Umbau ist genau das der Normalfall —
deshalb ein eigener Befund und kein Fehler, und deshalb der Verweis auf `knowledge_links.SET`
mit `art=ersetzt`.

### Zwei Doppelungen nebenbei mitgenommen

- **`gesichert` hiess zweierlei.** Im Abgleich die Zeilen-Zahl, im Tool die Ja/Nein-Frage. `+`
  behält den linken Operanden, also gewann die Zahl und das Tool meldete `1` statt `true`. Ein
  Test hat es gefangen. Die Zähler heissen jetzt `datei_zeilen` / `live_zeilen`.
- **`KnowledgeCanonService::DOCS` ist public.** Der neue Dienst hätte sonst den 28. rohen
  Tabellennamen getippt (`G7`: kein Model, kein Repository, 27 Dateien greifen direkt zu). Eine
  geteilte Konstante ist kein Engpass, aber der kleinste Schritt dorthin.

### Nebenbefund, ausserhalb des Schnitts: das Embedding-Fenster stand im Repo auf 2.000

Beim Nachrechnen einer eigenen Behauptung aufgefallen — ich hatte den Config-Default zitiert und
als Aussage über demo hingestellt. Dominique hat korrigiert, und die Messung gibt ihm recht:

| `wissen-recall-probe --team=6 --k=10 --fenster=2000` | Schwanz | Kopf | Lücke |
|---|---|---|---|
| Fenster 2000, n=40 (Baseline) | 47,5 % | 85,0 % | 37,5 |
| Fenster 4000, n=120 | **55,0 %** | 81,7 % | **26,7** |

Umgestellt und entschieden am 2026-09-07 („4000 BEHALTEN"), aber **nur per ENV auf demo** — der
Code-Default, die Konstante `DOMAIN_LEAD_CHARS` und zwei weitere hartkodierte Rückfälle standen
weiter auf 2.000. Eine frische Umgebung hätte still das halbe Fenster bekommen. Dasselbe Muster
wie `H7`, nur in einer anderen Zeile: demo handgerichtet, Repo hinterher.

Nachgezogen: Default und Konstante auf 4.000, die beiden Rückfälle lesen jetzt `leadChars()`
statt die Zahl neu zu tippen, und ein Test hält Konstante ⇄ Config gegeneinander. `WissenEmbedFensterTest`
pinnte den **alten** Beschluss samt alter Messung — er pinnt jetzt den neuen, mit beiden
Messungen im Docblock, damit niemand die eine gegen die andere ausspielt. Die 8000-Messung von
W1-1 bleibt gültig: sie galt Monolithen von 10–50k, nicht Ein-Themen-Dossiers.

**Zwei Folgen für den Rest der Spec:** (a) der von mir gemeldete Widerspruch „Deckel 4.000 vs.
Fenster 2.000" bestand **auf demo nicht**, im Repo schon — jetzt beides zu. (b) Die **26,7 Punkte
Restlücke** sind nachweislich **kein** Fenster-Problem. Sie gehören in Paket 4 zum Rechner (`E1`).

★ Und die teuerste Zeile der Messung: **n=40 ist zu klein.** Ein Treffer = 2,5 Punkte, der
Schwanz sprang zwischen n=40 (50,0 %) und n=120 (55,0 %) um 5 Punkte — mehr als der gemessene
Effekt. `wissen-recall-probe` hat deshalb jetzt `--limit=120` als Default, und der Docblock sagt
ausserdem, dass `--fenster` zwischen Vorher- und Nachher-Lauf **gleich bleiben muss**: es ist die
Grenze der Messung, nicht das Live-Fenster.

### Was in Paket 3 noch offen ist

Kanon-UI · `regelwerkBlock()` samt `->first()` **löschen**, nicht entschärfen ·
`_sections`/`_chunks` droppen · die zwei Budget-Bäume zusammenlegen.

---

## ✅ Paket 3 · Schnitt 2 — die Bindungen sind weg (2026-09-08)

**Die vertagte Entscheidung, jetzt mit Zahl.** Der Plan hat `F1`/`F2` bewusst offen gelassen,
bis der Bestand gemessen ist — und ich hatte notiert, die Bindungen seien „der Pfad, der heute
für ~45 Prompt-Keys der einzige ist". `knowledge_bindings.GET` auf demo, 2026-09-08:

| | |
|---|---|
| Bindungen gesamt | **9** |
| davon wirkungslos | **9** |
| Bereichs-Präfix-Bindungen (`recipe`, `vk`) | **0** |
| auf inaktive Dossiers | 0 |

**Meine Annahme war falsch.** Alle neun hängen an `recipe.generator` / `vk.generator` — genau
den zwei Keys mit Kanon — und alle auf Slugs, die dort ohnehin als `pflicht` stehen. Für die 45
ungesteuerten Keys existiert **keine einzige** Bindung. Der Kanal war kein Fallback, sondern
Doppelpflege mit einer Falle: bricht ein Kanon weg, schalten sich die neun still scharf.

### Was entfernt ist

**Laufzeit (`F2`):** der Bindungs-Zweig in `propose()`, `selectBoundKnowledge()`, der **dritte
Tokenizer** (Mindestlänge 4, eigene Stoppwortliste) und die **dritte Relevanz-Formel**
(`always×1000 + Treffer×10 + weight`). Befund `C2` ist damit von vier Formeln auf drei.
`prompt_parts.bound` bleibt als Schlüssel mit 0 — strukturell, nicht zufällig; ein
verschwundener Schlüssel machte alte und neue Läufe unvergleichbar.

**Schreibpfade (`F3`, zwingend im selben Schnitt):** `knowledge.BIND` gelöscht ·
`bindLayer()`/`bindExisting()` werfen · `bind_layers` aus `knowledge.POST/PUT` ·
`Browser::addBinding()` samt „+ einbinden"-Knopf · `Einsatzorte` aus der Settings-Navigation ·
die bindungsschreibende Hälfte des W0-Wächters. Hätte ich `F2` allein deployt, wären
Schreibpfade übrig geblieben, die **still ins Leere schreiben** — schlechter als der Zustand
vorher, und exakt die Fehlerklasse aus Befund `J`.

Deshalb wirft `bind_layers`, statt ignoriert zu werden: ein `success` ohne Wirkung ist der
Kern des Problems, nicht seine Lösung. Und `knowledge.BIND` ist **gelöscht** statt verweigernd
— ein Werkzeug, das nur scheitern kann, ist Rauschen in dem Katalog, den die Agenten
durchsuchen. `knowledge.UNBIND` bleibt: der Rückweg für die neun Alt-Zeilen muss offen sein.

### Drei Aussagen übersetzt statt gestrichen

**1. `ENTBUNDEN` im W0-Wächter.** Prüfte „ist dieses Dossier an keinen Layer gebunden". Die
Invariante war aber *„gehört nicht in den Prompt"* — und der Weg dorthin ist heute der Kanon.
Also wird gegen den Kanon geprüft. Gleiche Aussage, richtige Stelle.

**2. Leerer Kanon = Fehler.** Vorher hiess „kein Kanon" stillschweigend „dann eben Bindungen".
Der Wächter meldet das jetzt als Drift ins Signale-Cockpit und nennt
`wissen-kanon-sicherung import` als Reparatur. Der Kreis aus `H7` ist damit nicht nur zu,
sondern **sichtbar** — vorher war genau das der stille Pfad.

**3. Der teuerste Fund der Welle 0 wandert mit.** `files_used` ging als `knowledge_used` an
`propose()` und war dort der **Dedup-Eingang**: von 7 Pflicht-Dossiers kam null an, weil die
Transparenz-Anzeige den Kanal abschaltete, den sie sichtbar machen wollte.
★ `selectKanon()` hat **denselben Dedup-Eingang**. Die Falle ist nicht mit dem Kanal
verschwunden, nur umgezogen — der Test bleibt, jetzt am Kanon.

### Zwei Berichte mussten ihre Wahrheit ändern

| Bericht | vorher | jetzt |
|---|---|---|
| `wissen-versorgung` | Verdikt `nur-bindung` | fällt weg — solche Keys sind ehrlich `UNGESTEUERT`; Bindungen erscheinen als Ballast |
| `wissen-profil` | Befund `bindung_wuerde_scharf` | `bindung_altlast` — kein Zukunftsrisiko mehr, nur Ballast, mit `UNBIND` als Rückweg |
| `regelwerk.GET` | `quelle: "bindung"` | `ungesteuert` + eine Erklärung, warum der Browser trotzdem eine Verdrahtung zeigt |

Die letzte Zeile ist die wichtigste: eine Auskunft, die „bindung" sagt, während der Gateway die
Tabelle nicht liest, ist genau die Sorte Lüge, gegen die `A4` antritt.

### Tests: 14 Dateien, nicht 6

Befund `H8` hatte sechs Dateien genannt, die die Alt-Struktur festschreiben. Es waren
**vierzehn**. Zwei sind gelöscht bzw. ersetzt (`KnowledgeBindToolTest` →
`WissenBindungAbgeschafftTest`, das jetzt die Gegenrichtung pinnt), fünf Welle-0-Tests laufen
auf den Kanon um, vier Fixtures stellen den Altbestand nur noch per Insert, drei pinnen die
gedrehten Verdikte.

★ **Und der erste volle Lauf ist an mir gescheitert, nicht an der Sache:** zwei Testdateien
deklarierten beide ein globales `w0Kanon()` → `Cannot redeclare`. Der Kopf von `fa_test.sh`
warnt wörtlich davor, und der **parallele** Lauf zeigt es nicht (jeder Worker lädt nur seine
eigenen Dateien) — nur der sequentielle. Der Helfer liegt jetzt als `tests/Support/SeedsKanon`
dort, wo geteilte Helfer hingehören.

## ✅ Paket 3 · Schnitt 3 — `regelwerkBlock()` gelöscht, Kanon-UI, totes Schema weg (2026-09-08)

### F4 — der `->first()`-Pfad ist weg

`regelwerkBlock()` wählte das Regelwerk per Slug-Muster und nahm `orderBy('slug')->first()`.
Bei `%basisrezept%` sind das rund zwanzig §-Dossiers: keine Auswahl, ein Los.

**Drei Dinge kamen anders als geplant, alle aus der Messung:**

**1. `REGELWERK_SLUG_LIKE` bleibt — der Plan wollte sie löschen.** Sie trägt zwei Dinge unter
einem Namen: eine *Injektions-Regel* (tot) und ein *Nachschlagen* (lebt). `ablauf.GET` und
`regelwerk.GET` beantworten damit „welches Regelwerk gehört fachlich zu diesem Bereich" — eine
Auskunft, kein Prompt-Bau. Weg ist stattdessen das `->first()`: `regelwerkDossiersFuer()` gibt
**alle** Treffer zurück. Einem Agenten §1.0 von zwanzig zu nennen war schlimmer als keine
Antwort — er hält es für vollständig.

**2. Das Löschen hätte ein neues Loch gerissen.** Der generische Pfad verarbeitet
ausschliesslich `discovery`. Eine `regelwerk:always`-Zeile hätte ab sofort **gar nichts**
geladen — kein Fehler, kein Log. Also braucht F4 drei Dinge, die nicht im Plan standen:

| | |
|---|---|
| Migration `000004` | stellt Bestandszeilen um, mit **expliziter Ziel-Liste** je Feature |
| Befund `routing_always_tot` | `wissen-profil` meldet jede künftige `always`-Zeile als blockierend |
| `pflichtZeichen()` | zählt `regelwerk:always` mit **0** statt Budget für Wissen zu reservieren, das nie kommt |

★ Mein erster Migrations-Entwurf setzte *jedes* `always` auf `none`. Damit wäre
`concept.brief_geruest` durch eine **Aufräum**-Migration ärmer geworden. Der Drift-Test hat es
gefangen. Jetzt: `none` nur, wo der Kanon trägt (`ai_generate_recipe`, `foodbook.grundgeruest`),
sonst `discovery` — eine Aufräum-Migration darf nicht wegnehmen.

**3. ★ Der Header-„Umzug" war ein Fehler, den Dominique gefunden hat.** Ich hatte den
gelöschten Code-Header ersetzen wollen und die Regel „NIE «Vorspeisen/Hauptgänge/Desserts» als
Kapitel" in den Prompt-Task geschrieben. Seine Frage: *„dadurch kann ich den nicht mehr
anpassen über das Wissen oder?"* — genau so. Das Dossier `regelwerk-foodbook-grundgerust` trägt
die Regel in **§5 Anti-Pattern** längst, und es kommt über den Kanon in den Prompt. Meine
Kopie hätte die Hoheit über eine kuratierbare Regel in den Code geholt: die Doppelung, die
diese Spec abbaut, im Abbau selbst gebaut. Zurückgenommen, und ein Test pinnt beide Richtungen
(die Regel FEHLT im Prompt und KOMMT über den Kanon).

Damit ist auch der Rest des Headers erledigt: §1–§5 des Dossiers decken alles ab, was er sagte.
`regelwerkBlock()` zu löschen verliert **nichts**, nicht nur „fast nichts".

### F5 — `_sections`/`_chunks` gedroppt

Ein Schema mit lauffähigem Producer (`knowledge-sectionize`) und **ohne jeden Leser**. W1-4/W3-3
planten die Retrieval-Einheit vom Dossier auf den Abschnitt umzustellen; bevor der erste Leser
entstand, entschied Spec 50 Strang III den anderen Weg („ein Dossier = ein Thema"). Migration
`000010` schrieb damals ausdrücklich: *„Drop ist eine eigene Entscheidung."* Das ist sie.

Weg: zwei Tabellen, `KnowledgeSectionizeCommand`, `KnowledgeSectionizer`, `KnowledgeChunker`,
`KnowledgeSectionizerTest`. Die Migration **zählt vor dem Drop** und bricht bei Zeilen ab.

### Kanon-UI — Grundsatz E eingelöst

Der Docblock der `Wissenssteuerung` hatte ihn selbst angekündigt: *„Routing ist einfache
Zeilen-Pflege, der Kanon braucht eine Dossier-Suche und kommt als eigener Schritt."*

Je Prompt-Key: Pflicht- und wenn_platz-Dossiers mit ✕, ein Dossier-Wähler (Suche ab 2 Zeichen
über Slug **und** Titel — 1.100 Treffer sind kein Wähler), Modus und Reihenfolge. Über
`KnowledgeCanonService::set()`, **nicht** per Insert: dort leben Tenancy, Enum-Prüfung,
Changelog-Guard und Deckel-Hinweis. Der Wissens-Browser hatte in der Gegenrichtung genau
diesen Fehler (`Browser::addBinding()`, roher Insert an den Garantien vorbei, Befund `F`).

Und die Hinweise des Service werden **nicht geschluckt**: „Dossier ist inaktiv" bzw. „über dem
Deckel" sind die Fälle, in denen die Zeile entsteht, im Bericht nach Versorgung aussieht und
nichts liefert. Ein eigener Test pinnt, dass eine **globale** Zeile nicht angefasst wird und
die UI das sagt, statt Erfolg zu melden.

### Bewusst NICHT dabei: D4 (Budget-Bäume)

Die zwei Bäume sind nicht dasselbe — einer deckelt den Kanon, einer das Retrieval. Der echte
Defekt aus `H5` ist, dass sie verschieden verschlüsselt sind (feature vs. prompt_key) und
**niemand die Summe deckelt**. Das sauber zu lösen heisst: neue Config-Form, alle Aufrufer
umziehen, und dabei ändert sich die Prompt-Grösse jedes Generators. Das gehört in einen eigenen
Schnitt mit Messung davor/danach, nicht in ein Bündel.

---

### Was jetzt noch an der Tabelle hängt

Die neun Alt-Zeilen auf demo sind **nicht** gelöscht — sie sind inert, und sie wegzuräumen ist
ein eigener, umkehrbarer Handgriff (`knowledge.UNBIND`), der zum Korpus-Umbau passt. Ebenso
bleiben `knowledge_bindings` und `knowledge_layers` als Tabellen: `knowledge_bindings.GET`
liest sie als Bestandsnachweis, und der Wissens-Browser löst die Labels daraus auf. Der
Tabellen-Drop gehört zu `F5`, zusammen mit `_sections`/`_chunks`.

---

## ▶ Arten und Achsen — Umsetzung 2026-09-09

**Auftrag:** Arten und Achsen vor dem Korpus-Umbau nutzbar machen. Lokaler Branch
`feat/wissen-arten-achsen`, auf `feat/wissen-rechner` (`dd7db2b6`). Keine Umklassifizierung
bestehender Dossiers und keine erfundenen Mengenwerte. Noch nicht deployt.

### H1-Rest: Art steuert den Verwendungsweg

Die vorhandenen fünf Arten bleiben unverändert. `knowledge_routings` bekommt einen
alternativen Selektor `art`; eine Zeile hat entweder Kategorie oder Art. Kein paralleler
Steuerspeicher. Für Arten sind ausschließlich folgende Kombinationen zulässig:

| Art | Modus | Wirkung |
|---|---|---|
| fachwissen | discovery / none | Kategorieübergreifende Suche oder bewusst aus |
| referenz | discovery / none | Optionale, ausdrücklich als Inspiration markierte Suche |
| datenwerk | resolve / none | Strukturierte Werte nach Geltung oder bewusst aus |
| regel | kein Arten-Routing | Expliziter Kanon, einschließlich Achsen-Kanon |
| ablauf | kein Arten-Routing | Bleibt im Agenten-Zugang, kein Retrieval-Kontext |

Sobald ein Schritt Arten-Routings hat, bedienen seine Kategorie-Routings nur noch
`art IS NULL`. Ohne Arten-Routing bleiben Fachwissen/Referenzen über die bisherigen
Kategorie-Routings verfügbar. Regeln und Datenwerk-Prosa gelangen nicht mehr über diese
Suchpfade in den Kontext. Die freie Inventarsuche in Browser/MCP zeigt weiterhin alle Arten.
Globale Arten-Routings sind über Einstellungen und MCP mit derselben Master-Schreibgrenze
pflegbar. Profile/Fingerprints und Versorgungsberichte zeigen den Art-Selektor mit an.

### H2-Ausbau: Geltung am Dossier

Das Feld `geltung` gehört zum Dossier und seiner Version. Unterstützt sind `gang`,
`komponentenrolle`, `portionskontext`, `niveau`, `saison`, `warengruppe`, `occasion`, `sektor`
und `format`. UND zwischen Achsen, ODER innerhalb der Werte einer Achse. Fehlende
Auftragsparameter erfüllen eine Bedingung nicht. `level` wird als Niveau akzeptiert.

Die Filterung erfolgt vor der Rangbildung/Top-K. Datenwerke werden ohne Suchrang anhand
der Bedingungen aufgelöst. Achsen-Kanon und bisheriger Anlass-/Segment-Fallback bleiben
für nicht umgestellten Bestand erhalten. Für Regeln bleibt die explizite Kanon-Zuordnung
maßgeblich; Geltungsfilter am Dokument werden für Regel/Ablauf deshalb nicht scheinbar
wirksam gespeichert. Die Restaurant-Lücke bleibt unbesetzt, bis ein fachlich passendes
Dossier existiert — kein automatisches Umbiegen auf einen anderen Sektor.

### B6/B7: Strukturierte Werte und ehrliche Lücken

`datenwerte` ist eine Liste am Datenwerk, keine extrahierte Markdown-Tabelle. Ein Eintrag
enthält Kennzahl, Minimum/Maximum (gleich für Einzelwert), Einheit, Bezugsgröße,
Quelle/Fundstelle und optional zusätzliche Geltung. Dossier-Slug und Version ergänzen die
Provenienz zur Laufzeit. Dossier- und Eintragsbedingungen müssen beide erfüllt sein.

`DatenwerkResolver` führt keine Formeln aus; `ProportionService` bleibt der vorhandene
Grammaturen-Rechner. Der Resolver wählt kuratierte Werte aus. Gleiche Werte behalten alle
Quellen; unterschiedliche Werte, Einheiten oder Bezugsgrößen derselben Kennzahl ergeben
`widerspruch` ohne gewählten Wert. Kein Treffer bzw. fehlende strukturierte Einträge ergeben
`luecke`. Die Modellnachricht benennt beides ausdrücklich. Datenwerte und Lückenhinweise
werden als Pflichtanteil budgetiert, nicht still weggeschnitten.

UI und `knowledge.POST/PUT/GET/LIST` nutzen denselben Einordnungsvertrag. Die Vorschau
(UI/MCP) erlaubt alle Achsen und zeigt Werte, Konflikte und Lücken. Änderungen der
Einordnung/Datenwerte erhöhen die Dossierversion. Import schreibt die neuen Felder nicht
zurück auf leer. `mengen_defaults` bleibt im Kanon; ein produktiver Ersatz setzt die
kuratierte Befüllung und fachliche Referenzprüfung voraus.

### Abnahmegrenze

Die vollständige Suite des vorangehenden Suchrechner-Stands ist grün: 4.217 Tests,
4.211 bestanden, 6 übersprungen. Für Arten/Achsen sind 25 neue Regressionen enthalten;
der abschließende gezielte Lauf mit Achsen-/Profiltests ist grün (42 Tests, 95 Assertions).
Die danach ergänzte Erhaltung des UI-Suchindex-Updates ist separat grün (1 Test, 5 Assertions).
Der zusätzliche Gesamtlauf ist beendet: 4.242 Tests, 4.234 bestanden, 6 übersprungen,
ein Fehler und eine fehlgeschlagene Erwartung. Die Index-Erwartung lief noch gegen
den während des Laufs älteren UI-Pfad; der spätere Einzeltest bestätigt die Korrektur.
Der zweite Befund betraf die unnötige Übernahme aller zulässigen IDs in die nachgelagerte
Volltext-Abfrage. Diese lädt jetzt ausschließlich die bereits ausgewählten Gewinner.
Der gezielte Nachlauf für Speichergrenze, Arten/Achsen und gemeinsamen Rechner ist grün
(36 Tests, 93 Assertions). Eine vollständig grüne Gesamtsuite dieses neuen Stands wird
weiterhin nicht behauptet. Der Mechanismus
ersetzt nicht die noch ausstehende Kuratierung des Livebestands. Ebenso bleiben der
zentrale Auftragsvertrag, Critic/Selbstheilung/Sprache (C), das gemeinsame Budget (D4) und
hängende Paragraphenverweise (H7) eigenständige offene Arbeiten.

## ▶ Fortsetzung 2026-09-09 — Riegel und tatsächliche Quellenauswahl

**Reihenfolge geklärt (Dominique):** Der Dossier-Umbau beginnt **erst, wenn das Wissensmodul
steht**. Deshalb zuerst die korpus-unabhängigen Riegel und der gemeinsame Rechner. Der
Ausbau von Arten/Achsen und der Datenwerk-Vertrag bleiben Voraussetzung für den späteren
Korpus-Umbau; aus der Antwort folgt keine Freigabe, bestehende Dossiers jetzt umzuordnen.

**Arbeitsstand:** Branch `feat/wissen-riegel`, Worktree `15_GITHUB/wt-wissen-riegel`, eigene
Test-Sandbox `15_GITHUB/sandbox-wissen-riegel`. Basis `cb826dc2` (PR #61). Noch nicht deployt.

### B2 — Gesamtbudget an strukturellen Quellgrenzen

- Alle Retrieval-Kanäle liefern jetzt `KnowledgeContextBlock` mit getrenntem Vorspann,
  Quellen und Trenner. Auch Achsen, Niveau und Pairing behalten ihre Quellgrenzen bis zur
  Endauswahl. Markdown-Überschriften oder `---` im Dossier werden **nicht** als Grenzen geparst.
- Das Gesamtbudget nimmt vollständige **vorbereitete Quellenblöcke** auf oder lässt sie aus.
  Nach einem zu großen optionalen Block können kleinere Quellen noch passen. Leere
  Kategorieüberschriften verschwinden. Bei ausreichendem Budget bleibt der Text unverändert.
- `files_used` und `used_by_category` nennen nur noch tatsächlich gesendete Quellen.
  `files_dropped` nennt ausgelassene Quellen; deren `herkunft.sent` wird 0. `dropped_chars`
  zählt die Differenz des gerenderten Textes einschließlich entfallener Hüllen und Trenner.
- `always`-Quellen werden vor der optionalen Auswahl reserviert. Die **reale gerenderte**
  Pflichtmenge ist maßgeblich, nicht `max_docs × max_chars_per_doc`: die alte Schätzung
  reservierte selbst bei leeren Kategorien Zeichen und vergaß gleichzeitig die Überschriften.
  Konfiguriertes Budget zu klein → `KnowledgeBudgetExceeded` mit Key, Bedarf und Budget.
  Ein kleiner Kompositions-Override wird auf die reale Pflichtmenge angehoben.

**B2 noch nicht vollständig geschlossen:** Die vorgelagerten **Pro-Dossier-Deckel**
(`truncate(content_md, max_chars_per_doc)` und die spezialisierten Konstanten) sind weiterhin
aktiv. Sie können eine einzelne große Quelle bereits vor dem Zusammenbau kürzen. Ihr Ersatz
gehört mit den realen Korpus-Größen in B1/D4; ohne diese Messung entweder alle Grenzen zu
entfernen oder alle übergroßen Dossiers wegzulassen wäre eine ungemessene Versorgungsänderung.
Der aktive Defekt `truncate(fertiger_Block, Gesamtbudget)` ist dagegen entfernt.

### B4 — Pflicht messen und alle gepflegten Kanon-Keys prüfen

- `wissen-steuerdaten-w0 --verify` prüft zusätzlich zu den zwei obligatorischen Generatoren
  **alle aktiven Kanon-Keys mit `scope=prompt_key`, `role=root`** im sichtbaren Team-Kontext.
- Die Kanon-Messung verwendet die Textformatierung des Gateways, einschließlich Überschriften,
  Trennern und Entfernung des Provenienz-Vorspanns. Eine eigene Budget-Default-Zahl im Wächter
  wurde durch `AiGatewayService::boundBudgetFuer()` ersetzt.
- Retrieval-Pflicht wird über `KnowledgeContextService::pflichtBudgetFuer()` mit denselben
  Quellen und demselben Renderer wie der Laufzeitpfad gemessen, ohne Discovery oder Modellcall.
  Große **optionale** Kandidatenmengen und leere `always`-Kategorien sind kein Budgetfehler.
- B1/D4 bleiben offen: insbesondere die gemeinsame Obergrenze für Kanon + Retrieval und die
  Laufzeitbehandlung eines zu großen **Kanon**-Blocks (derzeit weiter vollständig gesendet).

### Korrektur für B5/E — Domain ist bereits begrenzt, aber falsch sortiert

Auf `cb826dc2` kappt `discoverDomains()` mit `array_slice(..., DOMAIN_TOP_K)` bereits auf
**vier** Dokumente, unabhängig von `max_docs: null`. Das Generator-Routing kann anschließend
weiter verkleinern. Die Aussage „domain ungekappt bei 192 Dossiers“ beschreibt den aktuellen
Code daher nicht mehr. Stattdessen geht die Rangfolge vor dieser Auswahl durch
`sort($slugList)` verloren: die Endauswahl ist alphabetisch, einschließlich der hinzugefügten
semantischen Treffer. B0/E2 muss den Vorab-Deckel aus der Kandidatenermittlung entfernen und
B5 muss die reale gemeinsame Rangfolge messen, statt bloß einen weiteren Deckel einzubauen.

### Schnitt für den gemeinsamen Rechner (E/B0), gegen aktuellen Code geprüft

Der gemeinsame Ranking-Dienst muss sowohl `discoverGenericBlock()` als auch
`discoverDomains()`, `searchDocuments()` und die Browser-Suche versorgen. Die Sonderwege
für **deterministische** Achsen/Niveau und den **Pairing-Graphen** sind davon zu unterscheiden:
sie suchen keine konkurrierenden Dossiers und werden nicht in den allgemeinen Suchrang
umgedeutet. `selectBoundKnowledge` aus der ursprünglichen E1-Liste existiert seit F2 nicht mehr.

Für die Umsetzung konkret:

1. Ein eigener Kandidatendeckel pro Suchverfahren, unabhängig vom `max_docs` der Endauswahl.
   Lexik und Semantik immer beide ermitteln; weder „nur wenn Lexik zu wenig“ (MCP) noch
   „alle semantischen Treffer vor Lexik“ (generischer Generator) bleiben bestehen.
2. Eine lexikalische Bewertung für Slug, **Titel** und Aliase, ein Tokenizer/eine Stoppliste.
   Der generische Generator berücksichtigt heute nicht einmal den Titel. Volltext erst für
   die ausgewählten Quellen laden; keine Kopie sämtlicher Dossier-Inhalte pro Kategorie.
3. Rangfusion mit nachvollziehbaren `lexical_rank`, `semantic_rank`, Gesamtrang und Auswahlgrund.
   Kategorie, inaktive Quellen, `_knowledge_scope` und Kanon-Ausschlüsse **vor** der Endauswahl
   berücksichtigen. Der Kandidatendeckel darf nicht von unsichtbaren/falschen Kategorien
   verbraucht werden. Kein neuer Tokenizer nur für den Browser.
4. Browser-Suche auf dieselbe Bewertung führen; die bestehenden Zugriffsregeln dabei erhalten
   (Etappe G ist weiter vertagt). Ein identischer Suchrang ist nur bei identischen Filtern
   und identischer sichtbarer Dokumentmenge ein sinnvoller Vergleich.
5. Die Vorschau benötigt Prompt-Key **und** Auftrag/Leitplanken. Eine freie Suchanfrage allein
   ist keine Vorschau des Generator-Kontexts. Quellenauswahl und Auslassungen kommen aus dem
   bestehenden Kontext-Aufbau und später aus dem zentralen Auftrag (C), nicht aus einem
   zweiten Browser-Nachbau der Routing-Logik.

**Lokaler Umsetzungsstand 2026-09-09:** Der gemeinsame `KnowledgeSearchService` ist jetzt
in generischer Discovery, Domain-Discovery, MCP-Suche und Browser eingebunden. Ein
`KnowledgeTokenizer` liefert die Textnormalisierung. Lexik (Slug, Titel, Aliase) und
Semantik werden unabhängig ermittelt und per RRF vor der Endauswahl fusioniert.
`knowledge_search.candidate_limit` begrenzt jede Kandidatenliste auf 100; die semantische
Suche lädt bei ausgeschlossenen Treffern innerhalb eines begrenzten Fensters nach
(`semantic_scan_limit`, 5.000). Dieses Fenster ist weiterhin eine Recall-Grenze.

`KnowledgePreviewService`, Browser und `foodalchemist.knowledge.PREVIEW` verwenden den
echten Kontext-Aufbau und dieselbe Kanon-Auswahl wie das Gateway. Auftrag, Prompt-Key
und fachliche Leitplanken sind Eingaben; Quellenauswahl, Budget-Auslassungen und Zeichen
sind Ausgabe. Die Vorschau bildet die übergebenen Angaben ab; eine bereits durchlaufene
Zutatenauflösung mit Hauptzutat-Slugs wird hier noch nicht simuliert.

Neun neue Regressionstests sind grün, darunter unabhängige Rangfusion trotz voller
lexikalischer Endauswahl, gleiche Rangfolge an den drei Einstiegen, Domain-Relevanz,
Aliasnormalisierung, Filterung sowie UI/MCP-Vorschau und Abgleich mit dem Gateway-Audit.
Die vollständige Suite für diesen Stand läuft; E ist **noch nicht live abgenommen oder
deployt**. Ohne Team-6-Referenzmessung wird keine Verbesserung der genannten
Recall-Prozentpunkte behauptet. Die vorhandene
`wissen-recall-probe` misst ausschließlich den Embedding-Pfad und wäre alleine noch kein
Nachweis für den neuen Hybrid-Rechner.

### Verifikation und verbleibende Live-Grenze

Die gezielten Tests für Quellgrenzen/Pflichtschutz sowie Wächter/Kanon sind grün; die
vollständige Modulsuite wird für diesen Stand ausgeführt. Die neuen Regressionen decken
3→2 Quellen, Tabellen/Markdown-Trenner im Inhalt, leeres Restbudget, kleinere Folgetreffer,
späte Pflichtquellen, Pflichtüberlauf und den Kanon eines Nicht-Generator-Prompts ab.

Der BHG-MCP-Connector antwortet in dieser Sitzung mit **401 Unauthenticated** (Endpoint
`office.bhgdigital.de/mcp`). Es wurde damit **keine** Live-Referenzmessung gegen demo durchgeführt
und es wurden **keine** Live-Steuerdaten verändert. Die obigen Codebefunde sind lokal belegt;
B1/D4/B5 und die Abnahme nach Deploy benötigen weiterhin den Team-6-Live-Abgleich.

---

## ▶ Der Rest, vollständig — Stand 2026-09-08

**Wir sind bei etwa der Hälfte.** Paket 1–3 waren die **Steuerschicht**: drei Tabellen auf
eine, vier Rechner auf drei, zwei Injektionspunkte auf einen, ein totes Schema weg. Was
bleibt, ist der **Zusammenbau** — wer den Prompt baut, woraus, und wie man verhindert, dass
es wieder auseinanderläuft.

### Erledigt

| Etappe | |
|---|---|
| **A** Grundlinie + Werkzeuge | ✅ PR #48/#49 |
| **D** Ein Schlüsselraum | ✅ Paket 2 (D1–D3, D6, D8, D9) — offen nur **D4** |
| **F** Alt-Struktur abräumen | ✅ Paket 3 (F1–F5, F7); F8 durch den Korpus-Umbau erledigt |
| **H** Art & Achse | ✅ H1 (Feld), H2 (Kanon-Scope), H6 (Verbindungen) |
| **C0/C0b** Profil + Fingerabdruck | ✅ |

### Offen, in der Reihenfolge, die die Abhängigkeiten vorgeben

---

**0 · Arten & Achsen scharf machen — VOR dem Korpus-Umbau** ⟨H1-Rest · H2-Ausbau · B6 · B7⟩

Der einzige Punkt mit hartem Termin. Heute steuerst du VERDRAHTUNG: 71 Prompt-Keys × 21
Kategorien plus je Key eine Kanon-Liste. Bei 2.600 Dossiers nicht mehr pflegbar — nicht weil
die UI fehlt, sondern weil die Frage falsch gestellt ist.

Zielmodell: **das Dossier sagt, was es ist; der Schritt sagt, was er benutzen darf.**

| Art | Weg in den Prompt | Wer pflegt |
|---|---|---|
| `regel` | Kanon — explizit, klein | Mensch, einmal je Regel |
| `datenwerk` | **Join über Achsen**, keine Suche | Mensch beim Schreiben |
| `fachwissen` | Suche, wenn der Schritt es darf | niemand |
| `referenz` | Suche, optional, gekennzeichnet | niemand |

**Warum der Termin hart ist:** `art` und Achsenwerte sind LEER (alle 1.100 Dossiers
`art: null`). Wer die Dossiers ohnehin neu schreibt, ordnet sie dabei fast kostenlos ein.
Hinterher sind es 2.600 Einzelentscheidungen — das ist `H3`, das damit weitgehend entfällt.

Zu bauen: Routing über **Arten** statt Kategorie-Paare · `knowledge_axis_map` über die
heutigen zwei Achsen hinaus · **Resolver für Datenwerke** (`B6`/`B7`): ein per Achse
gefundenes Markdown erfüllt den Vertrag nicht, es braucht strukturierte Einträge mit
Bezugsgröße und Einheit. ⚠ `mengen_defaults` verlässt den Kanon erst, wenn das steht — sonst
tauschen wir Prosa gegen Lücke.

---

**1 · Etappe B — die Riegel gegen stille Verluste** ⟨B0 · B1 · B2 · B4 · B5⟩

Klein, unabhängig, und einer davon ist ein aktiver Defekt.

★ **B2 ist der wichtigste: `truncate($block, $budget)` schneidet mitten im Text.** Halbe
Tabelle statt ein Dossier weniger. Spec 46 §2d hat den Satz selbst geschrieben: *„Ein
Tabellen-Anschnitt ist kein Wissen, nur Kosten."*

Dazu: `B0` drei Grössen trennen (Kandidatenlimit ≠ Endauswahl ≠ Kontextbudget) · `B1` die
Budget-Zahlen, damit Pflichtwissen hineinpasst · `B4` den W0-5-Wächter schärfen · `B5`
`domain` bei den Generatoren deckeln (heute `max_docs: null` bei 192 Dossiers) und
`DISCOVERY_MIN_SCORE` messen statt raten.

`B3` (dropped_chars überall) ist teil-erledigt.

---

**2 · Etappe E — EIN Rechner** ⟨E1 · E2 · E3 · E4⟩ · „Paket 4"

Korpus-unabhängig, wirkt auf jede Suche. Das ist der Rest der **26,7-Punkte-Recall-Lücke**:
das Embedding-Fenster hat 10,7 Punkte gebracht (Schwanz 47,5 % → 55,0 % bei n=120), der Rest
liegt am **Ranking**, nicht am Index.

Heute drei Formeln auf einem Korpus (war 4 vor `F2`): Generator-Discovery (Jaccard +
Substring + Alias), `knowledge.SEARCH` (Token-Schnittmenge + Alias×2), Browser (rohes `LIKE`
ODER rein semantisch, kein Hybrid). Ziel: lexikalisch und semantisch unabhängig ermitteln,
dann fusioniert bewerten (RRF), EIN Tokenizer, EINE Stoppwortliste. Dazu `E3`/`E4`: **der
Kurator sieht im Browser, was der Generator sieht** — heute ist Kuratieren Blindflug.

---

**3 · Etappe C — der senkrechte Durchlauf** ⟨C1–C8⟩ · **der grösste Block**

In der Spec ausdrücklich **„der Beweis"**, und der Teil, der verhindert, dass alles
zurückdriftet.

★ **Warum das kein Aufräumen ist:** `propose()` nimmt das Wissen weiterhin **vom Aufrufer**
entgegen, und `$kontext` ist ein freies Array, in das jeder Regeltext schreiben kann. Wir
haben aufgeräumt, WER was liefert — die Tür steht offen. Grundsatz B heisst wörtlich
*strukturell, nicht vereinbart*: Konventionen sind genau das, was hierher geführt hat
(`_kanon_prompt_key` an 2 von 14 Stellen, `knowledge_dropped_chars` an 2 von 14).

Drei **aktive Defekte** stecken hier, keine Hygiene:

| | |
|---|---|
| `C4` | Der Konformitäts-Critic lädt die Regelwerke per `slug LIKE` **ungekappt**, an Kanon/Routing/allem vorbei (Befund `G5`) |
| `C5` | Die **Selbstheilung** bekommt gar keinen Kontext — kein Retrieval, kein Kanon (Befund `I5`), und rankt gegen die Rezeptbeschreibung statt gegen den Befund (`I6`) |
| `C3` | Das **Sidebar-Mikrofon** hat weder Kanon noch Routing (Befund `D1`) — am Mikrofon gelten die Regeln nicht |

Dazu die strukturellen: `C1` getippter Auftrag + Lauf-ID über den ganzen Vorgang · `C2`
Kontext-Aufbau ins Gateway, ein übergebenes `knowledge` wird **abgewiesen** statt ignoriert ·
`C6` Provenienz-Invariante (nur der zentrale Aufbau schreibt in den System-Regelblock) ·
`C7` fünf sichtbare Felder je Aufruf, auch für die drei Folge-Calls (`G6`) · `C8` Abnahme-Lauf
mit eingebautem Fehler.

**`C9` gestrichen** — die Kanon-Sicherung (Paket 3/1) deckt die Wiederherstellung ab.

---

**4 · D4 — die zwei Budget-Bäume**

Eigener Schnitt mit Messung davor/danach, weil er die Prompt-Grösse jedes Generators ändert.
★ Die zwei sind NICHT dasselbe: `ai.bound_knowledge_budget` deckelt den Kanon,
`ai.knowledge_budget` das Retrieval. Der echte Defekt: verschiedene Schlüsselräume, und
**niemand deckelt die Summe**. Überschneidet sich mit `B1` — zusammen schneiden.

---

**5 · H7 — hängende §-Verweise**

Der Defekt, den der Split erzeugt hat. Vorher war `Regelwerk_Basisrezepte` EIN Dokument, §2
konnte inline auf §4 und §11 verweisen. Nach dem Split ist der Verweis ein Textstring ohne
Ziel: der Kanon von `recipe.generator` trägt §1.0–1.5, §2, §3, §4, §6 — **nicht** §11
(Derivate), **nicht** §1.10/§1.11 (Anti-Patterns), auf die §2 verweist. Ein Prüfer muss jeden
§-Verweis melden, dessen Ziel nicht im selben Prompt steht.

---

**6 · Kleinkram, wo er reinpasst**

Die **9 Alt-Bindungen** per `knowledge.UNBIND` (zwei Minuten; inert, aber sie stehen als
`bindung_altlast` in jedem Bericht) · den Riegel `routing_always_tot` auf **alle** toten
Modus-Kombinationen ausweiten (`grounding` ausserhalb `pairing`, `always` bei Kategorien ohne
Handler) · `H5` Kategorie-Vokabular aufräumen.

---

**7 · Nach dem Korpus-Umbau: die 56 ungesteuerten Keys triagieren** ⟨H4 dazu⟩

Je Key eine Entscheidung: Kanon-Zeile, Routing-Zeile oder ausdrücklich `none`. **Die andere
Hälfte des Ausgangssymptoms** („Wissen fehlte komplett"), und sie hängt am Korpus, nicht an
der Architektur. Unter den 56: `recipe.description`, `recipe.geschmack`, `recipe.sensorik`,
`recipe.name_putzen`, `recipe.titel_vorschlag` — genau die Schritte hinter „KI-Erstellen".

---

**Vertagt (Entscheidung Dominique):** Etappe **G** — Zugriffsmodell/Tenancy, bis zum Umzug in
die echte Umgebung. Empfehlung bleibt „Eigentum und Lesefreigabe trennen", nicht global und
keine erfundene `parent_team_id`-Hierarchie.

---

## Runbook — Messung auf demo (nach jedem Deploy dieser Etappe)

Immer **mit `--team=6`**: ohne Nutzer greift nur die globale Partition, und der Bericht
behauptete eine Deckungslücke, die es nicht gibt (die Beinahe-Fehldiagnose aus der
Semantik-Messung).

```bash
php artisan foodalchemist:wissen-versorgung --team=6 --json > /tmp/versorgung_demo.json
php artisan foodalchemist:wissen-versorgung --team=6 --nur-befunde
php artisan foodalchemist:wissen-grundlinie --team=6 --limit=6
php artisan foodalchemist:wissen-steuerdaten-w0 --verify --team=6
php artisan foodalchemist:wissen-deckel-check
```

Bindungs-Bestand über MCP (`knowledge_bindings.GET`), drei Abfragen:
`{}` für alles · `{"nur_wirkungslos": true}` für den Aufräum-Bestand ·
`{"target_key": "recipe"}` und `{"target_key": "vk"}` für die Präfix-Streuung.

**Was der Vergleich dev ⇄ demo zeigen muss:** dev ist der Frisch-DB-Zustand (kein Kanon,
`always`-Routings), demo der handgedrehte. Weichen sie ab, ist das kein Messfehler, sondern
**Befund G1/H1** — und die Differenz ist genau das, was ein neuer Kunde anders bekäme.

### Vorlage für die Bindungs-Triage (`F1`, Entscheidung 2)

Eine Zeile je Bindung, vier Spalten — so wird sie Dominique vorgelegt:

| Dossier | heutige Reichweite | Art (Grundsatz A) | Vorschlag |
|---|---|---|---|
| z. B. `geschmacksbalance` | Ziel `recipe` → 23 Prompt-Keys | fachwissen | Kanon-Zeile an `recipe.geschmack` + `recipe.sensorik`, sonst weg |
| z. B. `workflow.*_regeln` | Ziel `recipe` → 23 Keys | **ablauf** | aus jedem Prompt heraus — gehört zu `ablauf.GET` |
| z. B. Bindung auf inaktives Doc | — | — | löschen |

Ausgänge: **Kanon-Zeile** (namentlich, an genannte Keys) · **Resolver** (Datenwerk, über Achsen)
· **Suche** (Routing auf die Kategorie) · **`none`** (bewusst leer) · **löschen**.
Das Bereichs-Ziel ist **kein** Ausgang mehr — Entscheidung 1 vom 2026-09-07.

---

## Warum kein Modul-Umbau

Das Modul hat drei Schichten, und **eine** ist beschädigt:

| Schicht | Zustand | Entscheidung |
|---|---|---|
| **Inhalt** — 1.105 Dossiers, 415 kuratierte Splits, ein Thema, ≤ 4.000 Z. | gut, und der teure Teil | **erhalten** |
| **Dokumentenschicht** — Slug/Version/`content_hash`/`active`, Aliase, Kategorien-Vokabular, Import-Guard, Embedding-Pool, Browser, MCP-CRUD | gut, getestet, bewusst gebaut | **erhalten** |
| **Steuerung & Zusammenbau** — 3 Tabellen, 2 Injektionspunkte, 4 Rechner, 3 Tokenizer, 2 Budget-Bäume, kein Engpass | **der Schaden** | **umbauen** (Etappen B–G) |

Ein Modul-Neubau würde die zwei guten Schichten wegwerfen und neu verdienen, mit dem Risiko
beim Inhalt (1.105 Slugs, auf die der Kanon zeigt). Und er würde das Problem nicht lösen: die
Fehler sind nicht „schlecht gebaut", sondern „zwei Mechanismen entscheiden unabhängig". Diese
Eigenschaft baut man in ein neues Modul genauso wieder ein — es sei denn, die Durchsetzung ist
ein **getippter Vertrag** statt einer Vorgabe (C1/C2/C6).

**Der Beleg liegt im eigenen Repo:** `_sections`/`_chunks` war ein Umbau der Retrieval-Einheit,
vollständig gebaut, mit Producer und Tests — und **überholt, bevor es einen Leser hatte**. So
sieht ein Umbau aus, wenn das Problem woanders lag.

**Was durch Löschen billiger ist als durch Migrieren:** `knowledge_bindings` + `_layers` (F1–F3),
`_sections` + `_chunks` (F5), `regelwerkBlock()` + `->first()` (F4), drei von vier Rechnern (E1),
zwei von drei Tokenizern (E1), einer von zwei Budget-Bäumen (D4), der eigene Schreibpfad des
Browsers (F3).

---

## Verifikation

Messbar, nicht gefühlt — alle Sonden existieren schon:

1. **Der eigentliche Nachweis ist der Referenzfall, nicht die Zeilenzahl.** Die Rezepte aus
   Schritt 0 müssen die vorher festgelegten fachlichen Prüfungen bestehen — Naming-§,
   Mengenplausibilität, geerdete Zutaten, Kohärenz. **Die Zahl gepflegter Prompt-Keys ist
   Hygiene, kein Erfolgsnachweis** (Deckungs-Bericht: 29 → 0, jede Zeile gesteuert oder
   ausdrücklich `none`).
2. **`recipes.GENERATE` gibt `kontext` zurück** (Nachzug A5): pro Testlauf
   `kontext.prompt.kanon / bound / retrieval / dropped` + `kontext.wissen.kanon` protokollieren.
   Erwartung nach F2: `bound = 0` bei **jedem** Prompt-Key, nicht nur bei den Generatoren.
3. **Anreicherungs-Lauf messen:** ein Basisrezept und ein Gericht über „KI-Erstellen" mit
   `complete_coverage`, dann im Call-Log (`foodalchemist_ai_call_log`) je Schritt prüfen, ob
   Wissen ankam. **Was heute ankommt, ist offen** — Kanon und Routing fehlen bei diesen Keys
   (B3), der Bindungs-Fallback ist ungemessen (A5). Genau deshalb ist A1 die Referenzmessung
   und keine Bestätigung einer Erwartung.
4. **Discovery-Rangfolge** (B5 / E1): die vier Proben aus Befund C als Regressionstest
   festschreiben — „Wie viel Gramm Hauptkomponente pro Person" muss
   `mengen_defaults--hauptgang-komponenten` auf Platz 1–3 liefern, nicht auf 7.
5. **Volle Suite** vor jedem Deploy (`./fa_test.sh`, ~35 Min / 3.646+ Tests) — Vertragsänderungen
   am Kontext-Service brechen Tests in fremden Dateien
   ([[feedback_teilsuite_deployt_roten_test.md]]).
6. **Wächter-Läufe** nach Deploy: `foodalchemist:wissen-steuerdaten-w0 --verify --team=6`
   (Mo 06:30) und `foodalchemist:wissen-deckel-check` (Mo 06:45) müssen Exit 0 liefern —
   und der neue Deckungs-Wächter dazu.
7. **Kanal-Äquivalenz:** derselbe Auftrag über Formular, Sprache und MCP muss dieselben
   Pflichtregeln und dieselben Datenzugriffsrechte erhalten. Heute ist das nachweislich nicht
   so (Befund D1: das Mikrofon bekommt weder Kanon noch Routing). Das ist ein Test, kein
   Bericht.
8. **Pflichtwissen verschwindet nie still:** kein `pflicht`-Dossier darf durch Budget gekappt
   werden, und jede Kappung muss in `prompt_parts.dropped` landen — an **allen** Aufrufern, nicht
   an zwei (I3). Als Regressionstest gegen die Zahlen aus Befund I.
9. **Test-Fixture gegen demo prüfen**, nicht nur die Sandbox
   ([[feedback_testfixture_zeigt_migrationsstand]]): die Steuerdaten-Drift aus Befund B5a ist
   genau der Fall, den eine grüne Suite nicht sieht.

## Entscheidungen & Mandat

### ✅ GESETZT von Dominique, 2026-09-07

| Entscheidung | Beschluss |
|---|---|
| **Regeln im Prompt oder Code** | **Nach Art getrennt** (Grundsatz A): relevante Regeln gehen zur Erstellung in den Prompt, die eindeutig prüfbaren werden **zusätzlich** im Code erzwungen — beide gegen dieselbe veröffentlichte Version. Der Widerspruch Spec 46 ⇄ Spec 48 (Befund B8) ist damit entschieden und gehört in eine der beiden Specs nachgetragen. |
| **Sprache** | **Sidebar-Mikrofon** (`voice-modal`, der Tool-Loop-Agent) übersetzt Rezeptaufträge in einen strukturierten Auftrag und fährt dann **denselben Ablauf** wie das Formular — mit Kanon und Regelwerk (`C3`). Freie Wissensfragen und Recherche bleiben Pull. **Das Leitstellen-Diktat bleibt unverändert** — es transkribiert nur und speist schon den richtigen Pfad. |
| **Anzahl Prompt-Keys** | **Im Durchlauf messen.** Keine spekulative Umstrukturierung; in Etappe C wird gemessen, ob ein gemeinsamer Aufruf für zusammengehörige Felder besser und billiger ist. Entscheidung dann mit Zahlen. |
| **Wiederherstellung** | **Vorziehen in Etappe C.** `C0` baut die Profilstruktur mit Versions-Fingerprint und Export/Import, `C9` prüft die leere Umgebung — solange der Ablauf klein ist. |

### ⏳ Vertagt, weil sie ohne Zahlen blind wären

| Entscheidung | Wann, und was sie vorher braucht |
|---|---|
| ~~**Alte Bindungen abschaffen**~~ | ✅ **entschieden 2026-09-07, nach der demo-Messung.** (1) Präfix-Erbe **abgeschafft** — es wandert nicht in den Kanon. (2) Triage: auf demo **9 Bindungen, alle stumm**, kein fachlicher Einzelfall → reiner Aufräum-Schritt statt Entscheidungsliste. (3) `workflow.rezept_anlegen_mcp`: keine Entscheidung nötig, auf demo gelöscht. (4) Zeitpunkt: **gestaffelt**, je migriertem Ablauf, der Gateway-Zweig fällt zuletzt — meine Entscheidung als Kurator. |
| **Zugriff auf den Korpus** (Etappe G) | Nach der Datenmigrations-Analyse. Empfehlung bleibt **Eigentum und Lesefreigabe trennen** — nicht global, keine erfundene `parent_team_id`-Hierarchie. Der dokumentierte 598→6-Fall muss vorher ausgeschlossen sein. |

### 🔑 Mandat (Dominique, 2026-09-07)

Autonom durcharbeiten · Merge und `./update.sh` auf demo erlaubt · Steuerdaten (`knowledge_routings.PUT`,
`knowledge_canon.PUT`) live setzen erlaubt · Test-Generierungen fahren und aufräumen erlaubt ·
Wissens-Dossiers per MCP schreiben erlaubt (`H3`).

**Selbstauflagen dazu:** volle Suite vor jedem Deploy (~35 Min, **nie** eine Teil-Suite — eine
Vertragsänderung am Kontext-Service bricht Tests in fremden Dateien) · Deploy **off-peak** und
vorher angesagt · jede Steuerdaten-Änderung mit **Vorher-Wert protokolliert**, damit sie in
einem Befehl rückstellbar ist · Test-Drafts mit IDs protokolliert und gelöscht · **keine
erfundenen Werte** (`B6`: unklare Bezugsgröße → Review-Liste, keine Schätzung) · Branch vor
jedem Befehl geprüft, nur eigene Dateien gestaget · Haupt-Clone (`feat/spec51-…`) und
`wt-anreicherung-recall` (uncommittete Parallel-Arbeit) werden **nicht** angefasst · eigene
Test-Sandbox per `cp -al`, der bestehende Symlink wird **nicht** umgebogen.

> **Das ist die letzte Design-Runde.** Drei Review-Durchgänge haben den Plan echt verbessert,
> aber er wird nicht kleiner, und die letzten Runden liefern zunehmend Zielbild statt Befund.
> Was danach noch strittig ist, entscheidet der senkrechte Durchlauf mit dem eingebauten
> Fehler — nicht die vierte Meinung.

**Detail zu den beiden vertagten Punkten:**

- **Bindungen (Etappe F):** radikal ist sauberer, aber es ist der Pfad, der heute für ~45
  Prompt-Keys der einzige ist — und der Bestand ist ungemessen (kein Lesetool, B7). Deshalb
  erst `A3` (Lesetool) → `A5` (Bestand) → Triage `F1`, dann entscheiden.
- **Zugriff (Etappe G):** die Frage ist nicht „sieht die KI weniger oder der Mensch mehr",
  sondern **wem der kuratierte Bestand gehören und für wen er freigegeben sein soll**. Drei
  Wege: Bestand auf `team_id = NULL`, `parent_team_id`-Hierarchie (heute bei allen 8 Teams
  NULL — würde eine Beziehung erfinden), oder **Bestand + Eigentümer + Lesefreigaben**
  (empfohlen). Alle drei sind eine Migration, keine Config-Zeile — der dokumentierte
  598→6-Fall muss vorher ausgeschlossen sein.

---

## Was ich NICHT geprüft habe (damit niemand darauf aufbaut)

- **Der Bindungs-Bestand ist ungemessen.** Es gibt kein Lesetool (B7) und ich habe keinen
  DB-Zugriff. Wie viele Bindungen es live gibt, an welchen `target_key`s sie hängen und wie
  viele auf inaktive Dokumente zeigen, ist **Annahme aus Commit-Text und Code** — nicht
  gemessen. A3/A5 existieren genau deshalb, und Etappe F sollte erst danach entschieden
  werden.
- **Die Discovery-Rangfolge im UI-Pfad ist nicht gemessen**, nur die des MCP-Pfads (Befund C).
  Für den Generator-Scorer (`discoverGenericBlock`) braucht es einen echten Generierungslauf mit
  `kontext`-Rückgabe — das ist Verifikations-Schritt 3.
- **Ob `pruefeUndHeile()` ein Versuchslimit hat**, habe ich nicht nachgelesen. Der Vorschlag
  „höchstens zwei automatische Korrekturrunden, danach ein Entwurf mit offenen Punkten" ist
  vernünftig — aber prüfen, bevor gebaut wird, ob es das schon gibt.
- **Ob `max_docs` die Kandidatenliste oder erst die Endauswahl kappt**, habe ich nur für
  `max_chars_per_doc` geklärt (wird pro Dossier angewandt, Routing-Wert außer bei
  `ai_generate_recipe`). Grundsatz C setzt voraus, dass es die Endauswahl ist — das ist zu
  verifizieren, nicht anzunehmen.
- **Die Budget-Zahlen in Befund I sind Obergrenzen** („baut bis"), keine gemessenen Ist-Werte.
  Was real gebaut wird, hängt daran, wie viele Dossiers über `DISCOVERY_MIN_SCORE` kommen. Die
  Kappung tritt also nicht bei jedem Call ein — aber sie kann, und niemand würde es sehen (I3).
- **Die Zahl „29 ungesteuerte Keys"** stammt aus dem Abgleich Registry-Doku
  (`26_LLM_MCP_Funktionsmatrix.md`) gegen die Live-Steuerdaten. Die Doku ist an mindestens
  einer Stelle veraltet (`vk.behaelter` existiert laut Spec 50 nicht mehr). Der Deckungs-Bericht
  aus A2 muss gegen `config('foodalchemist.prompts')` laufen, nicht gegen die Doku.
