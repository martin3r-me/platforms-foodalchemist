# Spec 50 — Schicht 4: Vollständigkeit, Ablauf-Wissen für den Alltags-Weg, granulares Wissen

> **Tracking:** Office Dev-Package 23, Features-Board. Neuer Service (`ReifeService` + Adapter
> je Artefakt-Typ) + read-only Reife-Tools + `reife`/`naechste_schritte` in den Write-Antworten
> der Kette + Vorgangs-Register + Kanon-Leser/-Schreiber. **DB-Schema:** keine neue Tabelle für
> Schicht 4; Strang III befüllt die vorhandenen `foodalchemist_knowledge_sections` und
> `foodalchemist_knowledge_canon`.
>
> Komplementär zu **Spec 41** (Grounding — liefert Wissen IN den Prompt), **Spec 36**
> (Matching — welche GP/LA werden verdrahtet) und **Spec 43** (Konformität — hält das
> Erzeugte die Regelwerke ein). Diese Spec ist die **Vollständigkeits-Achse**: ist überhaupt
> alles da, was dazugehört — und weiß der Erzeuger das? Fortsetzung von **Spec 48**
> (Token-Programm) für Strang III, Entscheidungsgrundlage dort:
> `46_Kanon_Entscheidungsvorlage.md`.

**Status:** 🚧 in Umsetzung, begonnen 2026-09-04. Branch `feat/schicht4-vollstaendigkeit`.

> **Drei Stränge, in dieser Reihenfolge abzuarbeiten:**
> **(I) Etappen 0–7** — die stillen Fehler, vollständige Anreicherung, Header, und die
> Vollständigkeits-Messung, die am Schreibpfad mitspricht (Pakete A–D).
> **(II) Etappe 8** — das Ablauf-Wissen ausliefern (Paket E-1 bis E-6).
> **(III) Etappen 9–10** — Wissen granular machen: Kanon für die vier `always`-Kategorien
> (E-7), Chunk-Retrieval für die fünf `discovery`-Kategorien (E-8). Strang III trägt
> Strang II, ist aber nicht seine Voraussetzung — die Tools funktionieren mit ganzen Docs
> und werden §-genau, sobald der Kanon steht.

> Kursiv gesetzte `snake_case`-Verweise (z. B. `feedback_verify_before_claiming`) zeigen auf
> Notizen im Vault-Gedächtnis (`13_MEMORY/`), nicht auf Dateien in diesem Repo.

## Anlass

Zwei Beobachtungen aus derselben Woche, die dasselbe Loch beschreiben.

**(1) Eine echte MCP-Session, 2026-09-03.** Ein Agent baute per MCP ein Concept mit
6 Gerichten und 26 Basisrezepten, deterministisch, alle Zutaten per `gps.MATCH` geerdet.
Fachlich brauchbar — aber der Agent musste die Kette Concept → Gericht → Basisrezept → GP
selbst zusammenreimen (Schema lesen, Reihenfolge raten), wusste nicht, was „fertig" heißt,
und reichte die Befunde am Ende **selbst** nach: VK 15,40 € gegen Zielpreis 48 €, Lohn 0 €,
keine Aufschlagsklasse, keine Darreichung, keine Sensorik, kein Pairing, keine
Speisen-Klasse, Brisket-EK 0,06 €.

**(2) Der Befund von Dominique dazu.** „Beim Anreichern von Gerichten oder Basisrezepten
oder auch Concepten oder Formaten wird im ganzen Concept / Gericht / Basisrezept nicht
alles angereichert. Bei der Anlage von Concepten werden keine Header gesetzt, auch bei MCP
nicht — da ist der Agent und die KI blind."

Das ist **nicht** dasselbe wie „der Agent kannte den Ablauf nicht". Es ist die härtere
Aussage: **auch der KI-Weg reichert nicht vollständig an.** Wer dem Agenten also nur
mitteilt, was der KI-Weg heute füllt, teilt ihm eine zu kurze Liste mit. Beides muss in
einem Zug: die Soll-Liste **vervollständigen** und sie dann **ausliefern**.

## Einordnung: die vierte Qualitätsschicht

Die Qualitäts-Architektur des Moduls hat drei Schichten. Der Anlass beschreibt eine vierte.

| Schicht | Frage | Wo | Status |
|---|---|---|---|
| **1 Grounding** | Welches *Wissen* liest die KI beim Schreiben? | `KnowledgeContextService::contextFor` (`regelwerkBlock`, Dossiers) | Spec 41, live |
| **2 Matching** | Welche *GP/LA* werden an die Zutat verdrahtet? | `IngredientMatchService` | Spec 36, live |
| **3 Konformität** | Hält das *Erzeugte* die Regelwerke ein? | `ConformanceService` + Adapter je Artefakt-Typ | Spec 43, live (nur `recipe`/`gp`/`la`) |
| **4 Vollständigkeit** | Ist überhaupt *alles da*, was dazugehört — und **weiß der Erzeuger das**? | — | **fehlt → diese Spec** |

Der Unterschied zu Schicht 3 ist der Kern der Spec: **Konformität prüft, ob das Vorhandene
richtig ist. Vollständigkeit prüft, ob das Fehlende fehlt.** Ein Concept ohne einen einzigen
Header verstößt gegen kein §, weil es keinen Header gibt, den man prüfen könnte. Genau
darum ist die Lücke bisher unsichtbar geblieben.

Zweiter, ebenso wichtiger Punkt: **Schicht 3 endet beim Rezept.** `src/Services/Conformance/`
enthält Adapter für `RecipeConformanceAdapter` (Basisrezept + VK), `GpConformanceAdapter`,
`LaConformanceAdapter` — und **keinen** für Concept, Format, Foodbook, Speisekarte,
Speiseplan, Angebot. Die Container-Ebene ist in keiner Qualitätsschicht vertreten. Dort
sitzt der Header-Befund.

## Leitentscheidungen

Aus der Rückfrage 2026-09-04 und dem Anlass:

- **Der Server redet mit — er blockiert nicht.** Keine harten Gates, kein Orchestrator, der
  die Kette für den Agenten fährt, keine serverseitige Durchsetzung von Leitplanken in
  dieser Runde. Jede Write-Antwort sagt, was offen ist; ein read-only Reife-Tool sagt es auf
  Abruf. Rein additiv. Begründung: dieselbe Reibungsarmut, mit der Spec 43 die Durchsetzung
  als Selbstheil-Loop statt als Hardstop gebaut hat.
- **Reichweite: die ganze Kette bis zur Ausgabe** — GP, Basisrezept, VK-Gericht, Concept,
  Format, Foodbook, Speisekarte, Speiseplan, Angebot.
- **Eine Messlatte für Mensch, KI und Agent.** Kein separater „MCP-Modus". `CoverageService`
  hat diesen Anspruch schon im Docblock („DIESELBE Messlatte für Mensch und KI") — Schicht 4
  zieht ihn auf alle Artefakt-Typen durch.
- **Adapter-Muster von Schicht 3 übernehmen**, nicht neu erfinden: ein generischer Pass +
  ein kleiner Adapter je Artefakt-Typ. Das ist die bewährte Form für „artefakt-agnostisch
  mit artefakt-spezifischem Rand".
- **Das Soll wird abgeleitet, nicht abgeschrieben.** Die Aspekt-Liste je Artefakt-Typ speist
  sich aus Prompt-Registry, Schrittfolgen und Datenmodell — nicht aus einer handgepflegten
  Markdown-Liste. Begründung in §7.

## Was schon existiert (und nicht neu gebaut wird)

| Fähigkeit | Wo | Erreichbarkeit heute |
|---|---|---|
| VK-Vorbedingungen (`portion`, `aufschlagsklasse`, `darreichung`) + Food-Cost-Ampel | `RecipeOneShotService::wirtschaftlichkeitsGlied():1003` | nur über `recipes.GENERATE`; `private`; **schreibt** vor dem Messen |
| Lücken in den Anreicherungs-Zielfeldern | `BulkEnrichService::luecken()` + `ZIELFELDER` | intern |
| Anreicherungs-Schrittfolge auf echte Leerstellen geschnitten | `RecipeOneShotService::anreichern()` | intern |
| Datenqualität **live pro Objekt** | `DataQualityService::trifftObjekt($team,$metrik,$kind,$id)`; Metriken `gp_kein_la`, `gp_kein_preis`, `gp_kein_lead`, `gp_lead_ohne_preis`, `gp_allergen_konfidenz`, `gp_anker_fehlt`, `gp_tentative_genutzt`, `br_ek_null/teil`, `br_anker_fehlt`, `vk_ek_null/teil`, `vk_anker_fehlt`, `vk_servierform_unbestimmt` | intern + aggregiert im Signale-Cockpit |
| Naming-Befunde | `DataQualityService::namingBefundeFuer($team,$recipeId)` | intern |
| Soll/Ist gegen das Planungs-Gerüst (Menge, Diät-Quote, Preisband, Saison, Dramaturgie, No-Gos) | `CoverageService::coverage($team,$ownerType,$ownerId)` — `foodbook`/`speisekarte`/`concept` | `coverage.GET` ✔ |
| Konformitäts-Findings §-genau | `foodalchemist_conformance_findings` (artefakt-agnostisch), `ConformanceService` | Leitstelle + Signale |
| Persistierte Befunde am Objekt | `SignalObjectService::signaleAmObjekt`, `SignalCauseService::fuerObjekt`, `SignalFixService::assist`/`vorschau` | `signale.*`, `signal_causes.GET` |
| Ein Tool je Lücke zum Schließen | `recipe_klasse.POST`, `recipe_darreichung.POST/PUT`, `recipe_sensorik.POST`, `recipe_pairings.PUT`, `recipe_anchors.PUT`, `recipe_steps.PUT`, `recipe_coherence.POST`, `recipe_rollen.POST`, `recipe_regeneration.PUT`, `recipe_eignung.PUT`, `recipe_images.GENERATE`, `concept_blocks.POST/PUT`, `kalkulation.GET` | ✔ MCP |
| Leitplanken-Vokabular inkl. Werte-Enum | `FoodAlchemistPlanningSession::ALLOWED_GENERATION_PARAMS:92`, `bio_praeferenz` = `konventionell\|bio\|egal` (`:83`), `ziel_vk_eur` | nur KI-Pfad |

Die Messfähigkeit ist also weitgehend vorhanden. Neu ist: **sie vollständig machen, sie
bündeln und sie am Schreibpfad aussprechen.**

## Entschieden (Rückfragen 2026-09-04)

| Frage | Entscheid |
|---|---|
| Fehlende Anreicherungs-Aspekte: automatisch oder nur melden? | **Automatisch vollständig anreichern.** Die Schrittfolge wird auf alle Aspekte erweitert; `complete_coverage=true` heißt dann wirklich vollständig. |
| Ausnahme | **KI-Fotos auf Bedarf** — Bilder bleiben aus der Auto-Anreicherung heraus (`ki_bilder` bleibt Flag, `recipe_images.GENERATE` bleibt explizit). |
| Concept-/Container-Header | **Deterministisch setzen, KI-Wording darüber.** Beim Anlegen sofort ein sachlicher Header aus der Gerüst-Struktur (Rolle/Slot-Typ, Vokabular Regelwerk_Concept §4); danach kann `concept.wording` ihn zur Marken-Sprache verfeinern. Nie leer, nie erfunden. |
| Durchsetzung | **Server redet mit, blockiert nicht** (wie Spec 43: Selbstheil-Loop statt Hardstop). |
| Reichweite | ganze Kette bis zur Ausgabe. |

**Kosten-Konsequenz, offen benannt:** vollständige Auto-Anreicherung erhöht die
Provider-Calls je Artefakt, und die Kaskade multipliziert das über alle Slots — Spec 43 hat
denselben Effekt schon geflaggt („jede Kaskade feuert Rezept-Check + GP-Check je geminteten
GP"). Die Spec baut deshalb zwei Bremsen ein, ohne die Entscheidung zurückzudrehen:
`complete_coverage` bleibt als Leitplanke steuerbar (Default an), und §8 verlangt eine
Vor/Nach-Messung der Calls pro Lauf, damit die Kostenkurve sichtbar ist statt geschätzt.
Der KI-Kill-Switch je Team (`TeamSettingsService::kiAktiv`, geprüft in
`AiGatewayService:92`) bleibt die harte Notbremse.

## §4 Ist-Befunde

### §4.1 Der Alltags-Weg hat keinen Anreicherungs-Pfad — das ist die Wurzel

Der Befund, der Dominiques Beobachtung erklärt: **ein per MCP von Hand angelegtes Rezept
kann überhaupt nicht angereichert werden.**

- `RecipeOneShotService::anreichern():90` ist nur aus `RecipesGenerateTool` und aus Jobs
  erreichbar — also nur im **Generierungs**-Kontext, nie für ein Bestandsrezept.
- `PlanningCascadeService::enrichBestehendesRezept():2089` hat genau die richtige Semantik
  (Ein-Step-Run anlegen + `EnrichRecipeJob` inkl. Sub-Rezepte dispatchen) — einziger
  Aufrufer ist `src/Livewire/Planung/Index.php:3589`. **Livewire-only.**
- Ein `recipes.ENRICH` / `verkaufsrezepte.ENRICH` existiert nicht. `BulkEnrichService` ist
  per MCP nur für **GPs** erreichbar (`gps.ENRICH` + `gp_enrich.RESOLVE`), nicht für Rezepte.
- `planung_kaskade.START` setzt `voll_anreichern` **hart auf `false`**
  (`PlanungKaskadeStartPostTool.php:80`) — der eine MCP-Einstieg, der die Kaskade ohne
  Ausgabe-Owner startet, reichert also grundsätzlich nicht an.

Folge: bei jedem handgebauten Gericht bleibt zwangsläufig leer, was der Pass setzt — und
zwar nicht nur Kreativtexte. `work_time_min` wird von `RecipeOneShotService::eigenschaftenGlied():308`
geschätzt; ohne den Pass bleibt es null, und `KalkulationService::recipeHk` rechnet dann
`FEK = 0`, `FGK = 0`. **Das war der „Lohn 0 €"-Befund** — nicht ein fehlender Stundensatz:
`TeamSettingsService::stundensatz()` hat einen Code-Default von 35,00 €/h und wird nie 0.

### §4.2 Zwei echte Bugs auf demselben Pfad

| # | Befund | Ort | Größe |
|---|---|---|---|
| B1 | `markup_class_id` wird von `SalesRecipeService::createLeer():494` und `createFromBasis():468` an `RecipeService::create()` übergeben — dessen `create([...])`-Array enthält den Key **nicht**. Der Team-Default wird still verworfen. | `src/Services/RecipeService.php:~197` | 1-Zeilen-Fix |
| B2 | `sales_unit_count` bleibt bei `recipes.POST`/`verkaufsrezepte.POST` null (nullable, kein Default, wird nie gesetzt). `DarreichungService::recomputePreise:301-309` fällt dann auf `yield_kg*1000/max(1,sales_unit_count)` zurück = **die ganze Charge als eine Portion** → Chargenpreis als VK. | `src/Services/DarreichungService.php:301-309` | Falle, klein |

Beide sind stille Fehler: kein Wurf, keine Warnung. Genau die Klasse, die
`feedback_verify_before_claiming` und `feedback_still_scheiternde_apis` beschreiben.

### §4.3 Die Bio-Rückfrage war eine fehlende Schema-Zeile

`IngredientMatchService::matchIngredient():46-54` nimmt `mode`, `pref`, `preferRaw` und
`bio`; `MatchHeuristics::variantRankResolved:380-441` gewichtet Bio feldprimär mit
Token-Fallback. `GpsMatchTool.php:64` ruft aber nur
`matchIngredient($team, $zutat, $slug)` — also alles auf Default `neutral`. Das
Tool-Schema kennt die vier Parameter nicht. Dieselbe Lücke im Auto-Ground-Zweig
(`RecipeService::syncIngredients:~810`) und in `DishReverseService:47`,
`RecipeReviseService:62`, `RecipeReviewService:258`.

Der einzige Pfad, der `bio` durchreicht, ist `RecipeGeneratorService::generiere:122-126`
— also wieder nur der KI-Weg. Dass der Agent gestern „Bio oder nicht?" fragen musste und
ein Rezept nachkorrigierte, ist damit kein Bedienfehler, sondern eine Tool-Lücke.

### §4.4 Weitere belegte Lücken im Alltags-Weg

| Lücke | Befund |
|---|---|
| Konformitäts-Critic läuft auf dem MCP-Pfad nie | `ConformanceCheckJob` wird dispatcht von `GenerateRecipeJob`, `EnrichGeneratedRecipeJob`, `Livewire/Planung/Index:3664`, `GpService:223`, `LaFirstGpService:115` — **nicht** von `recipes.POST/PUT`, `recipe_ingredients.PUT`, `recipes.GENERATE`. Und `grep -rl conformance src/Tools/` = leer: es gibt kein MCP-Tool. |
| `dedupGate` läuft nur im Generator | `RecipeGeneratorService:730-798`, read-only Post-Check, nur in `recipes.GENERATE`. `recipes.POST` prüft nichts. |
| „Freigabe ist menschlich" ist umgehbar | `recipes.STATUS`/`verkaufsrezepte.STATUS`/`gps.STATUS` erlauben `approved` direkt; `RecipeService::setStatus:421-430` prüft nur Enum + Team — kein Gate auf ungemappte Zutaten, keine Transitions-Matrix. |
| „Kein GP ohne LA" gilt nur auf einem Weg | in `LaFirstGpService::mintFromLa:47-129` erzwungen; `gps.POST` (`GpsPostTool:84`) legt über `GpNamingService::createGp` GPs **ohne jede LA** an — nur der Beschreibungstext bittet um `gps.MATCH`/`gp_proposals.POST`. |
| Attach-Recovery nicht per MCP | `attach_fehler` ist in `planung_kaskade.GET` sichtbar, `haengeKonzeptNach` ist Livewire-only. |
| Kostenstruktur-Basissatz nicht per MCP setzbar | `calculation_schema` und `calculation_reference_bases` sind im `TeamSettingsPutTool`-Docblock:21-23 **bewusst ausgeschlossen**; `FixkostenService` hat kein Tool. Ohne Monatsbasen fällt `CatalogPricingService::enterpriseBaseRate:41` auf `100 / target_food_cost_pct`. |
| Vokabular-Altschuld | `frische`, `sektor`, `kompositions_stil` haben je zwei konkurrierende Wertesätze und werden deshalb bewusst **nicht** validiert (`FoodAlchemistPlanningSession.php:55-68`). `sektor=fine_dining` läuft durch und trifft im Achsen-Mapping ins Leere. |

### §4.5 Was `complete_coverage` heute schon abdeckt — und was in keinem Pass steckt

`RecipeOneShotService::coverageGlieder():260` fährt: Fertigungstiefe, Eigenschaften/Zeiten,
Equipment, Posten, Prozessanker, Aromaanker, Pairings, Eignung, Steps, Sensorik. Dazu
`kohaerenzGlied():911` und `wirtschaftlichkeitsGlied():1003`.

Die **Textschritte** sind dagegen auffällig knapp: `BulkEnrichService::SCHRITTE:29` =
`['description','category','geschmack']`, `SCHRITTE_VK:39` =
`['description','wording','plating','speisen_klasse']`. Gegenüber 22 `recipe.*`- und 15
`vk.*`-Prompt-Keys in der Registry ist das ein Bruchteil — Behälter, Regeneration,
Servier-Vehikel, Marketing-Text, Titel-Vorschlag, Teller-Heber, Rollen und Namens-Putzen
tauchen in keiner Schrittfolge auf.

### §4.6 Rezept/Gericht: was `anreichern()` fährt

`RecipeOneShotService::anreichern():90` in fünf Phasen:

| # | Phase | Bedingung |
|---|---|---|
| 1 | Textpass über `SCHRITTE`/`SCHRITTE_VK` (`laufAnlegen` → `verarbeiteRezept` → `alleUebernehmen`) | immer, aber bei `$schritte === []` komplett übersprungen (`:118`) — kein Run, kein Audit |
| 2 | `minteFehlendeGps():171` | nur `completeCoverage` |
| 3 | `kohaerenzGlied():911` | nur VK **und** ≥ 2 Zutaten |
| 4 | `wirtschaftlichkeitsGlied():1003` | nur VK — **kein** Provider-Call |
| 5 | `coverageGlieder():260` | nur `completeCoverage` |

`coverageGlieder` fährt 10 Glieder: `fertigung` (`recipe.production_depth`) · `eigenschaften`
(`recipe.eigenschaften` → u.a. `work_time_min`) · `equipment` · `posten` (deterministisch) ·
`steps` (`recipe.steps`) · `prozessanker` (Regex-Parser) · `aromaanker` (`recipe.anker`) ·
`pairings` (`recipe.pairing`) · `eignung` (`recipe.sektor` + `recipe.level`, 2 Calls) ·
`sensorik` (`recipe.sensorik`).

**Garantiert leer auch nach `completeCoverage: true`** — kein Anreicherungspfad berührt sie:

| Aspekt | Prompt-Key | Einziger Weg heute |
|---|---|---|
| Rollen (`recipe_ingredients.role`) | `vk.rollen` | `recipe_rollen.POST` / `VkModal:753` |
| Behälter je **Zweck** (`foodalchemist_recipe_containers`, `zweck ∈ abfuellen\|regenerieren\|ausgabe\|transport`) + `recipes.dichteklasse` | `recipe.dichteklasse` | `RecipeModal` (UI-Knopf) · `recipe_container.PUT` · Bedarf gerechnet in `BehaelterBedarfService` — **am Basisrezept, nicht am Gericht** |
| Servier-Vehikel (`serving_vehicle_vocab_id`) | `vk.servier_vehikel` | **nur** `VkModal:455` |
| Regeneration (`foodalchemist_recipe_regenerations`) | `vk.regeneration` | `recipe_regeneration.GET/PUT/DELETE/REORDER` (manuell) / `VkModal:577` |
| Teller-Heber | `vk.teller_heber` | `recipe_coherence.POST mode=heber` |
| Marketing-/Kundentext | `vk.marketing` | hängt am **Foodbook-/Angebots-Block**, nicht am Gericht |
| Titel / Namens-Normalisierung | `recipe.name_putzen`, `*.titel_vorschlag` | nur UI |
| Garverlust | `recipe.garverlust` | nur `IngredientEditor:147` |
| Review-Befunde | `recipe.review`, `vk.review`, `recipe.bauart` | Copilot-Panel, `recipes.REVIEW`, `recipe_findings_run.POST` |
| Bilder | — | nur `EnrichRecipeJob` bei `kiBilder=true` (`:106`) — **Entscheid: bleibt on demand** |
| Darreichungs-Deltas, weitere Darreichungen | — | `recipe_darreichung_delta.PUT` / `.POST` |
| Portionsgröße `sales_quantity_per_unit_g` | — | bewusst nicht geraten (`:1029`, V-041) → `luecken[]='portion'` → **kein Auto-VK** |

**Bedingt leer — läuft, kippt aber still:** Kohärenz (Basisrezept oder < 2 Zutaten → `null`) ·
Speisen-Klasse (nur Gericht) · `category_id` **am Gericht** (per Design nicht in `SCHRITTE_VK`) ·
Geschmacksrichtung **am Gericht** (nicht in `SCHRITTE_VK`, obwohl `VkModal:527` es kann) ·
Prozessanker (braucht `preparation`) · **Pairings (Kettenabhängigkeit: `uebersprungen_ohne_grounding`,
wenn `aromaanker` nichts fand, `:673`)** · Posten · Sensorik (`unveraendert` bei gleichem
`source_hash`) · Sektor/Level (nur `eignung==='geeignet'` wird geschrieben, `:736`).

> **Stand nachgezogen 2026-09-04 (48 Commits, Spec 51).** Die Behälter-Logik ist umgebaut:
> `vk.behaelter` **existiert nicht mehr**; an seine Stelle tritt `recipe.dichteklasse` am
> **Basisrezept**, Behälter liegen je Zweck in `foodalchemist_recipe_containers`, und die
> Anzahl wird gerechnet statt getippt (`BehaelterBedarfService`, `BehaelterRechner`; neue
> Tools `behaelter_bedarf.GET`, `behaelter_katalog.GET`, `recipe_container.PUT/DELETE`).
> **Der Befund dieser Spec bleibt trotzdem gültig — er sitzt nur eine Ebene tiefer:**
> `SCHRITTE`/`SCHRITTE_VK`/`SCHRITTE_GP` sind unverändert (3/4/4), und `dichteklasse` läuft
> in **keinem** Anreicherungs-Pfad (nur `RecipeModal`). Ein per MCP angelegtes Basisrezept
> hat also keine Dichteklasse — und ohne sie kann der Behälterbedarf nicht gerechnet werden.

**Drei Prompt-Waisen:** `recipe.sub_typ`, `recipe.preparation` (von `recipe.steps` abgelöst),
`vk.name_putzen` — bereits in `26_LLM_MCP_Funktionsmatrix.md` §5.5 als unreferenziert geführt.

#### Zwei Befunde, die über „unvollständig" hinausgehen

**B3 — die Lückenliste wird berechnet und weggeworfen.** `anreichern()` liefert
`$ergebnis['coverage']`; `EnrichRecipeJob:80` ruft die Methode ohne Zuweisungsziel. Die
Messung, die Schicht 4 braucht, läuft heute schon — sie verschwindet nur. Das ist der
billigste Einstieg in T6.

**B4 — `anreichern()` triggert nie `SCHRITTE_GP`.** `minteFehlendeGps` legt GPs im Status
`tentative` an, ohne sie durch die GP-Schrittfolge zu schicken; `RecipeRecomputeService`
läuft nur, wenn `$minted > 0` (`:209`). Der GP-Pfad hängt an einem **separaten** Lauf
(`starteGp` ← `gps.ENRICH`).

> **Teil-Widerruf (§4.9, Hinweis Dominique 2026-09-04):** die naheliegende Folgerung
> „deshalb aggregiert das Rezept Allergene als `unbekannt`" ist **falsch**. Allergene,
> Zusatzstoffe und Nährwerte kommen vom Lieferantenartikel und werden live abgeleitet;
> `LaFirstGpService:99` ruft beim Minten bereits `backfillAllergenKonfidenz(apply: true)`.
> Ein `'unbekannt'` ist eine **LA-Datenlücke**, kein fehlender Anreicherungsschritt. Es
> bleibt eine echte Lücke — aber nur bei den Feldern ohne LA-Quelle (Anker, Domain, Rolle).
> Details und die Gefahr eines Bulk-Laufs in §4.9.

**B5 — der Bulk-Knopf im Rezept-Browser ist ebenen-blind.** `src/Livewire/Recipes/Browser.php:246`
ruft `starte($team, $ids)` ohne Verzweigung, also immer `SCHRITTE`. Markiert man dort
Gerichte, landet die 186er-Basisrezept-`category_id` auf einem Gericht, und
`wording`/`plating`/`speisen_klasse` laufen nie. Nebenbei: `starteVk()` hat **keinen**
Produktionsaufrufer (nur `BulkEnrichService:149` + ein Test).

### §4.7 Concept/Format: der Header-Befund, exakt lokalisiert

**Die Wurzel ist eine Feld-Verwechslung.** `WordingResolver.php:165` rendert eine Überschrift
ausschließlich für `type ∈ {header, header_preis}` **mit** gefülltem `title`. Der Generator
schreibt aber `concept_slots.role` (`ConceptGeneratorService:993`,
`addSlot(..., ['role' => $frameSlot->label])`) und **nie** `title`. Das Gerüst *kennt* die
Gliederung (Gang-/Stations-Labels) — sie kommt nur nie im Kundendokument an. Ein KI-Concept
ist im Foodbook/Format/Angebot eine flache Gerichtliste.

`concept_blocks` ist keine eigene Tabelle: Blöcke sind Zeilen in
`foodalchemist_concept_slots` mit `type ∈ ['text','spacer','header','header_preis']`
(`ConceptService::STRUKTUR_TYPEN:401`).

**Alle vier `addBlock`-Aufrufer:**

| Ort | Auslöser | automatisch? | Header? |
|---|---|---|---|
| `ConceptGeneratorService:457` | `materialisiereLeereSlots`, `slot_type='station'` **und** `target_count ≥ 2` | ja (KI) | Stationsname — **ins Paket-Concept**, nicht ins Haupt-Concept |
| `Livewire/Formate/Editor.php:382` | Button „+ Gerüst" (`neueEdition`) | ja (UI) | 4 × aus `FormatService::SEKTIONS_GERUEST:354` = `['Amuse','Vorspeise','Hauptgang','Dessert']` |
| `Livewire/Concepter/Editor.php:1067` | Mensch klickt | nein | `title` initial leer |
| `Tools/ConceptBlocksPostTool.php:54` | MCP | nein | nur wenn der Agent `felder.title` mitgibt |

**Asymmetrie über die Wege:** Concepter „Neu" (`Concepter/Browser.php:199`) → kein Header ·
`generiereAusBrief`/`generiereAusGeruest`/`uebernehmeAssemblierung` → **kein einziger Block** ·
`planAusBrief` → nur Buffet-Stationen ≥2, nur im Paket · `concepts.POST` → keine Blöcke ·
`concepts.GENERATE` → keine Blöcke. **Am Format nie** — nicht UI, nicht KI, nicht Kaskade
(`FormatService::dokumentDaten:493-498` würde `header`-Slots rendern, bekommt aber keine).

#### Vier weitere Befunde auf dieser Ebene

**B6 — die MCP-Beschreibung nennt die falschen Feldnamen.** `ConceptBlocksPostTool:32`
beschreibt `felder` als `'Block-Felder (label, text, …)'`; `ConceptService::updateBlock:433`
akzeptiert nur `title`, `text_content`, `height`, `price_basis`, `price_value`. Ein Agent,
der der Description folgt und `label`/`text` schickt, **schreibt nichts** — der Block bleibt
titellos, ohne Fehler. Zweiter, unabhängiger Grund für „der Agent ist blind".

**B7 — `concept.wording` läuft in keinem Job.** Der Prompt existiert
(`config/foodalchemist.php:1487-1496`, liefert `{intro, slots}`); die einzigen Aufrufer sind
`ConceptWordingGenerateTool:55` und `Concepter/Editor:541`. Kein Kaskaden-Schritt ruft ihn →
nach einem KI-Lauf haben die Positionen **kein** Brand-Voice-Wording.

**B8 — `generateWording` überschreibt den Brief.** `ConceptService::generateWording:1278`
schreibt das KI-`intro` nach `description` — genau dorthin, wo `generiereAusBrief:247` /
`planAusBrief:325` den Brief abgelegt hatten. Die Spalte `concepts.brief` bleibt dabei NULL.
Stiller Datenverlust.

**B9 — kein Anreicherungs-Pfad für Concept/Format.** Es gibt kein Analogon zu
`anreichern()`: kein `BulkRunType`-Eintrag (`src/Enums/BulkRunType.php` kennt nur `Enrich`,
`EnrichVk`, `EnrichGp`), kein `luecken()`-Pendant, kein Vorschlags-Speicher. Alles passiert
**einmalig bei der Erzeugung**; was der Erst-Lauf nicht setzt, bleibt für immer leer.

**Vom Generator nie gesetzt, obwohl vorhanden:** `concepts.target_price_per_person` (der
Korridor landet nur am Frame — nie aus `menue_preis_ziel_pp`) · `price_display` ·
`consumer_name` · `claim` · `class`/`occasion`/`level` · `serving_form_id`/`event_type_id`/
`serviceMoments`/`seasons`/`targetGroups` · am Format zusätzlich `customer`, `note`, alle
Facetten, die ganze Bildwelt (`format_images` — Tool-Description sagt ausdrücklich „Bildwelt
bleibt manuell"). Bemerkenswert: `concept.plan` liefert mit `name_claim` das Material für
`consumer_name`/`claim` — es landet aber nur in der Canvas (`ConceptGeneratorService:402-409`),
nicht am Concept-Datensatz.

**Toter Code im Datenmodell:** `concepts.composition_source` (Default `'manual'`, 0 Treffer
in `src/`) · `ai_confidence` und `ai_reasoning` (nie geschrieben — die Proposal-Konfidenz
landet nur in einem Frame-`note`-String) · `phase`/`phase_override_note` (nur gelesen) ·
`concept_slots.level` (nie gesetzt).

#### Der fehlende Header ist ein §3-Verstoß, kein Feature-Wunsch

`Regelwerk_Concept.md` §3 (Status **VERBINDLICH**) fordert die Überschriften ausdrücklich:

> „Ein Concept-Gerüst besteht **immer** aus Sektions-/Gänge-Slots mit **Kapitel-Überschriften**
> + **Platzhalter-Slots** (`target_count` je Sektion), nicht aus einer einzelnen Zeile
> ‚Lunchbuffet' / ‚Menü'."

und nimmt der Gegenrede vorweg den Boden:

> „**Struktur ≠ Erfindung:** Das Sektionieren eines Containers in seine Standard-Sektionen
> ist **erlaubt und Pflicht** — es fällt NICHT unter ‚Du erfindest NICHTS' (das betrifft
> Gerichte/Preise/Fakten, nicht die Gerüst-Struktur)."

Das Vokabular ist ebenfalls belegt und kanonisch: §4.1 Menü-Gänge (3/5/7/9, Spannungsbogen
→ Hauptgang → Dessert-Abschluss), §4.2 Buffet-Sektionen in fester Reihenfolge (Kalte
Vorspeisen/Salate · Suppe optional · Warme Hauptkomponente(n) inkl. Carving-Station Pflicht
> 50 Pax · Sättigungsbeilagen · Dessert/Sweet-Table · Getränke; Breite 8–15 Positionen),
§4.3 Anlass-Overlays (Tagung = Pausen-Cluster + Lunch-Block).

C-1 setzt also ein bestehendes Pflicht-Vokabular um. Und es ist der Musterfall für Schicht 4
gegenüber Schicht 3: **der Verstoß ist für den Konformitäts-Critic unsichtbar, weil es
keinen Header gibt, den man gegen §3 prüfen könnte.** Nur eine Vollständigkeits-Messung
findet ihn.

### §4.8 Die übrigen Ausgabeformen

Das Soll-Vokabular ist auch hier schon geschrieben. `Regelwerk_Foodbook.md`:

- **§1:** „Ein Foodbook wird in KAPITEL gegliedert — nie in Gänge. Ein Kapitel = ein Menü,
  ein Thema, ein Anlass oder ein Service-Format. Ein einzelner Gang ist NIE ein eigenes
  Kapitel."
- **§2 Kapitel-Typen (real belegt):** Service-Format/Darreichungsform (Menü plated ·
  Menü-Buffet/Stationen · Flying · Fingerfood/Empfang · Grillbuffet · Foodstationen ·
  Midnight Munchies · Crewcatering) · Anlass/Tageszeit (Frühstück · Breaks · Tagungslunch ·
  Tagungsdinner · Mitternachtsnack) · Konzept/Marke · „Menü 01/02/03" · Rahmen/Nicht-Food.
- **§3:** Gänge liegen INNEN, Verschachtelung real bis 3 Ebenen (Kapitel › Menü/Stufe ›
  Gang). **§5 Anti-Pattern:** nie „Vorspeisen/Hauptgänge/Desserts" als Top-Level-Kapitel,
  nie ein einzelner Gang als Kapitel, nur so viele Kapitel wie der Brief hergibt.

**Es gibt drei getrennte Struktur-Ebenen** — (A) Kapitel-/Rubrik-Bäume, (B) Block-Listen mit
`type`-Diskriminator, (C) Presentation-Design-Layouts (`presentation_designs.layout_json`).
Automatisch **benannte** Struktur entsteht ausschließlich auf Ebene (A), dort deterministisch
aus den Gerüst-Slot-Labels. Auf Ebene (B) — also genau bei `header/text/spacer` — schreibt
**kein KI-Pfad und keine Kaskade jemals einen Header.**

| Form | Ebene A (benannt?) | Ebene B (Header automatisch?) |
|---|---|---|
| **Foodbook** | ✅ `strukturAusGeruest:602` → `addKapitel(['title' => $slot->label])`, via `foodbook.PLAN_FROM_BRIEF`. `consumer_title`/`claim`/`description` bleiben NULL | ❌ nie. Typen `concept_ref, recipe_ref, header_neutral, header_frei, header_frei_preis, spacer, text, image` (`FoodbookService::BLOCK_TYPES:1454`) |
| **Speisekarte** | ✅ `rubrikFuerSlot:394` (idempotent per Titel), aus `PlanningCascadeService:564` | ⚠️ nur bei „Format einfügen" (`insertFormatAlsRubrik:368`), und nur wenn das Format eigene header-Slots trägt |
| **Angebot** | ❌ kein `strukturAusGeruest`-Äquivalent — alles fällt in `defaultKapitel` mit Titel `'Menü'` (`OfferCompositionService:81`); Frame-Slot-Labels landen **nirgends** | ❌ nie — und der manuelle Weg ist kaputt, siehe B10 |
| **Speiseplan** | — **keine Struktur-Ebene** (kein `type`-Diskriminator, keine Sektions-Tabelle). `menu_plan_lines.name` ist eine Achse, keine Sektion | — |
| **Format** | ❌ `format.grundgeruest` erzeugt nur `concept`-Slots | ❌ nur der Livewire-Button `Formate/Editor:380-383` |
| **Concept** | ⚠️ Labels landen in `role`, nicht in `title` (§4.7) | ⚠️ nur Station ≥2, im Paket |

#### Die gute Nachricht: das Regelwerk ist deterministisch schon umgesetzt

`ConceptGeneratorService::expandiereContainerGeruest:701-722` setzt §3/§4 **exakt** um:
`buffetSektionsGeruest:761-777` liefert die sechs kanonischen Buffet-Sektionen,
`menueGangGeruest:786-800` die Gang-Leiter. Das Ergebnis landet als
`PlanningFrameSlot.label`. **C-1 ist damit kein Neubau, sondern ein Durchreichen:** das
Label muss als `concept_slots.title` mit `type=header` ankommen. Ein aus „Lunchbuffet für
80 Pax" generiertes Concept trägt heute sechs benannte Sektionen im Gerüst, ~11
role-getaggte Slots und **0 Header**.

#### B10 (neuer Bug) — im Angebot-Editor wird jeder Header still zu einem Text-Block

`Livewire/Angebote/Editor.php:1564` verdrahtet `FoodbookService::headerPresets()` — deren
Typen heißen `header_neutral` / `header_frei` / `header_frei_preis`. Der Offer-Service
filtert aber gegen `FoodAlchemistOfferBlock::BLOCK_TYPES` (`header` / `header_preis`):

```php
// src/Services/OfferCompositionService.php:210
$daten['type'] = in_array($in['type'] ?? '', FoodAlchemistOfferBlock::BLOCK_TYPES, true) ? $in['type'] : 'text';
```

→ **jeder Header-Klick im Angebot-Editor persistiert stillschweigend einen `text`-Block.**
Die Render-Zweige `angebote/editor.blade.php:389,392,453,456` können nie matchen.
Zusätzlich reicht `Editor.php:751` ein `header_source` durch, das weder in
`OfferCompositionService::BLOCK_FELDER:200-201` noch als Spalte existiert — still verworfen.
`Editor.php:1655` prüft korrekt gegen `['header','header_preis','text','spacer']`, ist also
die zweite, widersprüchliche Wahrheit im selben File.

#### Vier weitere Lücken auf dieser Ebene

- **`image` ist überall deklariert und nirgends beschreibbar.** In `FoodbookService:1454`,
  `FoodAlchemistSpeisekartePosition:28`, `FoodAlchemistOfferBlock:27` — `grep "=> 'image'"`
  über `src/` und `resources/` ist **leer**. Im Angebot-MCP-Enum (`OfferBlockPostTool:24`)
  ist er sichtbar: der Agent kann ihn setzen, es rendert nichts. Bilder leben real
  entitätsweit (`*_images`-Tabellen).
- **`concept_slots` hat kein `visible`.** Das von Spec 42 zugesagte Kuratieren
  „ein-/ausblenden" gibt es für Foodbook-, Speisekarte- und Offer-Blöcke, für
  Concept-Positionen nicht.
- **`headerPresets():1589-1621` ist das faktische Sektions-Vokabular und für KI und Agent
  unsichtbar:** 30+ kanonische Labels in vier Gruppen (Gänge/Service, Tageszeit,
  Konzept/Format mit `price_basis`-Vorbelegung, Intern mit `visible=false`) inklusive
  `header_source`-Slug für die KI-Lineage. Konsumenten sind nur zwei Livewire-Klassen. Über
  MCP gibt es nur `foodbook_blocks.POST` mit freiem `label` und ohne Vokabular-Hinweis — das
  ist genau der Inhalt, den E-3 ausliefern muss.
- **Kein Check verlangt Header — und einer verbietet sie sogar als Inhalt.**
  `grep header` über `SignalDetektorService` und `Services/Conformance/*` ist leer.
  Umgekehrt behandelt `DataQualityService` Struktur-Blöcke ausdrücklich als
  Nicht-Inhalt, gepinnt in `tests/Feature/KonzeptQualitaetSignaleTest.php:82-92`
  („Struktur-Slots sind kein Inhalt — ein Konzept aus nur Kopfzeilen bleibt leer") und
  `FoodbookQualitaetSignaleTest.php:121-123`. **Wichtig für D-1:** Schicht 4 darf diese
  Invariante nicht umdrehen. „Nur Kopfzeilen" bleibt leer; neu ist die *zusätzliche* Lücke
  „Inhalt vorhanden, aber keine Gliederung".

#### Woher der Header **nicht** kommen darf

`foodbook.kundentext` (`config:1532-1549`) schreibt wörtlich vor: „2–4 Sätze Fließtext,
**keine Überschrift**, keine Aufzählung, keine Anrede" — und persistiert ohnehin nichts
(`FoodbookKundentextTool:26`). `speisekarte_wording.GENERATE` überspringt Struktur aktiv
(`SpeisekarteService:1295-1297`). Header sind also Aufgabe der deterministischen Ebene plus
`concept.wording`, nicht der Kundentext-Prompts. Das deckt sich mit dem Entscheid
„deterministisch + KI-Wording darüber".

#### Doku-Drift, die Agenten in die Irre führt

- `2026_06_13_000045:69` behauptet `header|text|spacer|image`; der Code kennt
  `header_neutral|header_frei|header_frei_preis`.
- `FoodbookService:2304` beschreibt für `insertFormatAlsKapitel` eine Block-Expansion, die
  seit 2026-08-31 nicht mehr passiert (`:2339`).
- **`Regelwerk_Concept.md` §2:55 sagt „Concept-in-Concept-Verschachtelung ist im Datenmodell
  explizit ausgeschlossen" — der Code nutzt genau das** (`concept_slots.embedded_concept_id`,
  `ConceptService::fillSlot:456-463`, `ConceptGeneratorService:468`). Da das Regelwerk seit
  Spec 41 A3 im Wissensmodul die SSOT ist, gehört die Korrektur dorthin, nicht nur in den
  Vault-Spiegel.

### §4.9 GP-Anreicherung: die echte Lücke ist kleiner — und ein Bulk-Lauf wäre gefährlich

**Korrektur Dominique 2026-09-04: Nährwerte, Allergene und Zusatzstoffe kommen vom
Lieferantenartikel — sie werden nicht angereichert.** Das ist im Code die geltende
Architektur (`GpAggregateService`, Docblock `:17-21` + `:40`):

| Feld | Herkunft | KI? |
|---|---|---|
| **Allergene** (GL-01 §4.3) | Prio-Kette Override > Mutter (Derivat, eine Ebene) > **MAX über ALLE LAs** — `allergene()` liefert je Feld `value` + `source ∈ override\|mutter\|la\|keine`, **live abgeleitet**. Persistiert wird nur die Metadaten-Trias `allergens_confidence`/`_source`/`_aggregated_at`. | Notnagel |
| **Zusatzstoffe** (GL-09) | MAX über LA-Declarations, **kein Override-Layer**, alle LAs | **keine** |
| **Nährwerte** (GL-08 GP-Pfad) | je Nährstoff AVG über nicht-discontinued LAs; `naehrwerte($gp, $mitKiFallback = false)` — der KI-Zweig ist laut Kommentar `:187`/`:222` „**NUR Panel-Anzeige**", und nur wenn KEINE LA-Daten da sind | Fallback |

Der `gp.naehrwerte`-Prompt sagt das über sich selbst: „R10 (Ist-Feature): **Fallback ohne
LA-Daten**".

#### Damit fällt die Prämisse von B4/B-3

Der Mint-Pfad ist bereits korrekt: `LaFirstGpService:99` ruft
`backfillAllergenKonfidenz($gp, apply: true)` — mit einem Kommentar (`:93-94`) genau dazu,
den spuriosen „unbekannt"-Effekt zu vermeiden. Ein frisch geminteter GP bekommt die
Allergen-Metadaten also deterministisch.

Ein `'unbekannt'` in der Rezept-Aggregation heißt deshalb **nicht** „GP nicht angereichert",
sondern: **der LA trägt kein Allergenprofil** (`source: keine`, Konfidenz `none` → LOW,
`ALLERGEN_KONF_RANG`). Das ist eine **LA-Datenlücke** und gehört gemeldet (Paket D über die
bestehende Metrik `gp_allergen_konfidenz`), nicht geschätzt.

#### Die Gefahr, die dabei sichtbar wird (→ Paket A)

`backfillAllergenKonfidenz` überspringt GPs mit `allergens_source IN (manual|ki)` (`:124`) —
Provenienz-Schutz, und richtig so. Aber `SCHRITTE_GP` enthält `allergene` und `naehrwerte`.
Ein Bulk-KI-Lauf (`gps.ENRICH`, `Gps/GpModal`, `Gps/DetailPanel`) setzt damit
`allergens_source='ki'` und **schirmt den GP dauerhaft von der LA-Kaskade ab**: die im
Docblock `:116` benannte Eigenschaft „LA fixen → GP heilt" ist danach tot. Das ist dieselbe
Fehlerklasse, die `project_fa_allergen_kaskade_live_konfidenz` reparieren musste — nur von
der anderen Seite. Ein „vollständig anreichern"-Lauf über GPs würde sie flächig auslösen.

#### Die echte Lücke: die Aspekte ohne deterministische Quelle

`BulkEnrichService::SCHRITTE_GP:45` = `['condition', 'tags', 'allergene', 'naehrwerte']`,
und `gps.ENRICH` exponiert per MCP genau diese vier (`GpsEnrichTool:73` → `starteGp`).
Was fehlt, sind die Felder, für die es **keine** LA-Ableitung gibt
(Aufrufer-Bild via `grep -rl "'gp.<key>'" src/`):

| Prompt-Key | Zweck | Aufrufer | Feld heute |
|---|---|---|---|
| **`gp.anker`** | Aroma-Anker am GP | **keiner — Waise** | — |
| **`gp.domain`** | Domain-Zuordnung (Lebensmittel-Wissen) | **keiner — Waise** | — |
| **`gp.role`** | Rolle des GP | **keiner — Waise** | — |
| `gp.piece_default_g` · `gp.zaehl_einheiten` | Stückgewicht, Zähl-Einheiten | **wird gerade gebaut — siehe Abgrenzung unten** | nicht Teil dieser Spec |

Damit steigt die Waisen-Zahl von 3 (§4.6) auf **6**. Eine davon hängt in einer Kette, die
diese Spec an anderer Stelle schon als gebrochen notiert:

**W1 — `gp.anker` ist eine Waise, und die Ampel meldet die Lücke trotzdem.**
`DataQualityService` führt `gp_anker_fehlt`. Es gibt also einen Detektor für einen Zustand,
dessen einziger Behebungs-Prompt **von niemandem aufgerufen wird** — er kann strukturell nie
auf Null gehen. Und weiter oben steigt das `pairings`-Glied mit
`uebersprungen_ohne_grounding` aus, wenn `aromaanker` nichts fand
(`RecipeOneShotService:673`). **B-4 behandelt das Symptom am Rezept; hier sitzt die Ursache.**
Anker haben keine LA-Quelle — hier ist KI tatsächlich der richtige Weg.

**Abgrenzung: Stückgewicht und Zähl-Einheiten gehören NICHT in diese Spec** (Hinweis
Dominique 2026-09-04). Beides wird parallel auf `fix/ek-stk-bruecke-live` gebaut: dort werden
der Stück-Ertrag eines Sub-Rezepts und das GP-Stückgewicht bewusst getrennt („das galt früher
gemeinsam in EINEM Feld und war damit die Stelle, an der ‚Scheibe = ganzes Stück' entstand"),
`gp.zaehl_einheiten` zieht vom festen Neuner-Set auf das Team-Einheiten-Vokabular um, und der
neue Prompt `recipe.verpackungsmasse` kommt hinzu. **Nicht anfassen** — betroffene Dateien
dort: `GpFormService`, `RecipeRecomputeService`, `IngredientEditor`, `GpFormsPutTool`,
`GpFormsDeleteTool`, `ReportExportService`, `config/foodalchemist.php`.

**Und ein Muster von dort ist für §4.9 zu übernehmen.** Der Docblock zu
`recipe.verpackungsmasse` formuliert die Regel, die auch für Nährwerte gilt:

> „Die Gebindegroesse des Lieferantenartikels ist die BESSERE Quelle und wird deterministisch
> gerechnet — dieser Prompt liefert die zweite, unabhaengige Meinung aus Gastro-Wissen. Nur wo
> beide zusammenpassen, wird uebernommen; Uneinigkeit geht in die Review. […] eine Quelle
> allein irrt still."

Das ist nicht „KI raus", sondern **zwei Quellen mit Abgleich**. Für Nährwerte ist das der
richtige Zielzustand (LA rechnet, KI widerspricht oder bestätigt, Uneinigkeit → Review). Für
**Allergene** bleibt es beim LA-Vorrang: dort ist eine „zweite Meinung", die `source='ki'`
setzt, kein Gewinn, sondern der Kaskaden-Bruch aus dem Abschnitt darüber.

**W3 — Reihenfolge.** Solange `SCHRITTE_GP` die drei fehlenden Felder nicht kennt, bringt es
nichts, GPs durch die Kette zu schicken: sie bekämen vier Felder — zwei davon LA-Themen, die
dort gar nicht hingehören — und blieben ohne Anker, Domain und Rolle.

**Was NICHT dazugehört:** `gp.suggest`, `gp.la_suggest` und `gp.term_la_rank` sind
Matching-Helfer (Schicht 2, Spec 36); `gp.conformance_revise` ist Schicht 3. Beides bleibt
außen vor.

## §5 Bausteine

Fünf Pakete. Reihenfolge folgt dem Hausprinzip aus Etappe 22·H2, das
`MoneyTruthReportService` im Docblock zitiert: *„Messung zuerst, Umbau danach — die
Messzahl entscheidet, ob das ein stiller Dauerfehler oder ein Randfall ist."*

### Paket A — Fundament: die stillen Fehler zuerst

Ohne A misst Paket D die Symptome von Bugs statt echte Lücken.

| # | Was | Ort |
|---|---|---|
| A1 | `markup_class_id` in `RecipeService::create()` durchreichen (B1) | `src/Services/RecipeService.php:~197` |
| A2 | `sales_unit_count`: bei VK-Anlage explizit setzen oder als Lücke melden — **nicht** stillschweigend als 1 rechnen (B2). Die Vor-Messung aus Etappe 0 entscheidet, ob Default oder harte Lücke. | `RecipeService::create`, `DarreichungService:301-309`, `KalkulationService:131` |
| A3 | Feldnamen in den Block-Tool-Descriptions korrigieren: `label`/`text` → `title`/`text_content` (B6). Zusätzlich: unbekannte Keys in `updateBlock` **melden** statt schlucken — dieselbe Ergonomie wie die Einheiten-Auflösung (`FoodAlchemistTool:45-49` wirft mit Verfügbar-Liste). | `ConceptBlocksPostTool:32`, `ConceptBlocksPutTool:31`, `ConceptService::updateBlock:433` |
| A4 | `generateWording` darf den Brief nicht überschreiben (B8): `intro` in ein eigenes Feld, Brief nach `concepts.brief` (die Spalte existiert und ist immer NULL) | `ConceptService::generateWording:1278`, `ConceptGeneratorService:247,325` |
| A5 | Bulk-Knopf ebenen-bewusst machen (B5): Gerichte fahren `SCHRITTE_VK`. Damit bekommt `starteVk()` endlich einen Produktionsaufrufer. | `src/Livewire/Recipes/Browser.php:246`, `BulkEnrichService:149` |
| A6 | **Angebot-Header-Bug (B10):** Typ-Vokabular vereinheitlichen — `headerPresets()` liefert `header_neutral`/`header_frei`/`header_frei_preis`, `FoodAlchemistOfferBlock::BLOCK_TYPES` kennt `header`/`header_preis`, der Filter fällt auf `'text'` zurück. Entweder das Offer-Enum angleichen oder ein Mapping ziehen — und die stille Rückfall-Zeile durch einen ehrlichen Fehler ersetzen. Zusätzlich `header_source` entweder als Spalte führen oder nicht durchreichen. | `OfferCompositionService:210`, `Livewire/Angebote/Editor.php:1564,751,1655`, `FoodAlchemistOfferBlock:27` |
| A7 | `image`-Blocktyp entscheiden: aus den `BLOCK_TYPES`-Listen und dem Angebot-MCP-Enum entfernen (nichts schreibt und rendert ihn) oder implementieren. Heute kann ein Agent ihn im Angebot setzen und es rendert nichts. | `FoodbookService:1454`, `FoodAlchemistSpeisekartePosition:28`, `FoodAlchemistOfferBlock:27`, `OfferBlockPostTool:24` |
| **A8** | **LA-Kaskade vor KI-Abschirmung schützen** (§4.9): `SCHRITTE_GP` enthält `allergene` und `naehrwerte`; ein Bulk-Lauf setzt `allergens_source='ki'`, und `GpAggregateService::backfillAllergenKonfidenz:124` überspringt danach genau diese GPs — „LA fixen → GP heilt" ist tot. Fix: `allergene` aus der Bulk-Folge nehmen (Einzel-Pfad im Panel bleibt, für den LA-losen Fall) **oder** den Accept-Pfad hart gaten: kein `source='ki'`, solange der GP LAs mit Allergenprofil hat. Vor Paket B, sonst löst B einen Flächenschaden aus. | `BulkEnrichService:45`, `GpAggregateService:116,124`, `GpsEnrichTool:73` |

### Paket B — Anreicherung vervollständigen (Rezept + Gericht + GP)

Entscheid: automatisch vollständig, Bilder ausgenommen.

| # | Was |
|---|---|
| B-1 | `SCHRITTE_VK` erweitern um `rollen`, `servier_vehikel`, `regeneration`, `teller_heber`, `geschmack` (Behälter **nicht** — der ist seit Spec 51 ein Basisrezept-Thema, siehe B-10) — je Schritt ein `ZIELFELDER`-Eintrag (Feld + `*_source`), damit Override-First greift und `luecken()` schneidet. Vier davon haben schon einen Accept-Pfad (`SpeisenKlassenService::acceptRollen:137`, `VkModal:533/553/577`) — es fehlt nur die Bulk-Verdrahtung. |
| B-2 | `SCHRITTE` (Basisrezept) um `garverlust` erweitern (Zutaten-Ebene, eigener Accept-Pfad in `IngredientEditor:147`) und eine Titel-Normalisierung entscheiden. |
| B-3 | **~~GP-Anreicherung in die Kette~~ — Prämisse entfallen** (§4.9). `LaFirstGpService:99` ruft beim Minten schon `backfillAllergenKonfidenz(apply: true)`; Allergene sind live LA-abgeleitet, Zusatzstoffe rein LA, Nährwerte AVG über LAs. Ein `'unbekannt'` im Rezept ist eine **LA-Datenlücke**, kein fehlender Anreicherungsschritt — sie gehört gemeldet (Paket D über `gp_allergen_konfidenz`), nicht geschätzt. Bleibt: nach dem Minten die **drei** echten Felder aus B-7 nachziehen, sobald die dort existieren. |
| B-4 | Pairing-Kettenabhängigkeit entschärfen: `pairings` steigt heute mit `uebersprungen_ohne_grounding` aus, wenn `aromaanker` nichts fand (`:673`). Ein GP-erdeter Anker-Fallback über den Graphen (`pairing_lookup`-Logik) oder eine ehrliche Lücken-Meldung statt stillem Skip. **Das ist das Symptom; die Ursache ist B-7 (`gp.anker` ist eine Waise, §4.9 W1)** — B-4 bleibt trotzdem nötig, weil auch ein geerdeter GP-Anker fehlen kann. |
| B-5 | Prompt-Waisen bei Rezept/Gericht entscheiden (die vier GP-Waisen in B-7): `recipe.sub_typ` und `vk.name_putzen` verdrahten oder aus der Registry streichen; `recipe.preparation` ist von `recipe.steps` abgelöst → streichen. Registry-Count-Tests mitziehen (`feedback_fa_registry_tests_volle_suite`). |
| B-6 | Bilder bleiben **draußen** — `ki_bilder` Default aus, `recipe_images.GENERATE` bleibt der explizite Weg. |
| **B-7** | **`SCHRITTE_GP` um die drei quellenlosen Felder erweitern** (§4.9): `anker`, `domain`, `role` — je Schritt ein `ZIELFELDER_GP`-Eintrag (Feld + `*_source`), damit Override-First greift und `luecken()` schneidet. Für alle drei existiert nur der Prompt, kein Accept-Pfad; der ist mitzubauen (Muster `BulkEnrichService::uebernehmen()`). **Vorrang: `anker`** — löst W1 und erdet die Rezept-Pairings von unten. `allergene`/`naehrwerte` werden hier **nicht** ergänzt, sondern in A8 entschärft; Stückgewicht und Zähl-Einheiten laufen auf `fix/ek-stk-bruecke-live`. |
| **B-8** | **`gp_anker_fehlt` erst nach B-7 scharf lesen**: die Metrik meldet heute einen Zustand, dessen Behebungs-Prompt niemand aufruft — sie kann strukturell nicht auf Null gehen. Nach B-7 ist sie eine echte Ampel; in Etappe 0 wird sie als Baseline erhoben, nicht als Fehler interpretiert. |
| **B-10** | **`dichteklasse` in `SCHRITTE`** (Basisrezept, Stand-Nachtrag §4.6): `recipe.dichteklasse` hat einen Prompt und einen Accept-Pfad in `RecipeModal`, aber keine Bulk-Verdrahtung. Ohne sie rechnet `BehaelterBedarfService` für ein per MCP angelegtes Rezept nichts. `ZIELFELDER`-Eintrag ist da (`dichteklasse` + `dichteklasse_source`), also reine Verdrahtung. **Vor B-1**, weil der Behälterbedarf am Gericht auf den Basisrezepten aufsetzt. |
| **B-9** | **`gps.ENRICH` mitziehen**: das Tool exponiert heute genau die vier Alt-Schritte (`GpsEnrichTool:73` → `starteGp`). Nach A8 + B-7 bietet es `condition`/`tags`/`anker`/`domain`/`role` an — und `allergene`/`naehrwerte` nur noch explizit für den LA-losen Fall, nie als Teil eines „alles anreichern"-Laufs. Sonst bleibt der Alltags-Weg beim GP hinter dem UI-Weg zurück (Asymmetrie aus §4.1) oder richtet den Schaden aus A8 an. |

### Paket C — Concept und Format: Header + zweiter Pass

| # | Was |
|---|---|
| C-1 | **Header deterministisch aus dem Gerüst** (der Kernbefund) — und das ist ein **Durchreichen, kein Neubau**: `expandiereContainerGeruest:701-722` + `buffetSektionsGeruest:761-777` + `menueGangGeruest:786-800` setzen Regelwerk §3/§4 schon exakt um, das Ergebnis steht in `PlanningFrameSlot.label`. Es muss als `concept_slots.title` mit `type=header` ankommen — für **alle** Slot-Typen, nicht nur Buffet-Stationen ≥2, und im **Haupt**-Concept, nicht nur im Paket. Gilt für alle Erzeugungswege (`generiereAusBrief`, `generiereAusGeruest`, `planAusBrief`, `fuelleBestehendesKonzept`, `uebernehmeAssemblierung`) und für `concepts.POST`, wenn ein Gerüst vorliegt. |
| C-2 | **`concept.wording` in die Kaskade** (B7): nach der Slot-Befüllung läuft der Wording-Pass und verfeinert Header + Positionen zur Marken-Sprache. Damit ist der Entscheid „deterministisch + KI-Wording darüber" erfüllt: nie leer, aber auch nicht nüchtern. |
| C-3 | Kopf-Felder ans Concept schreiben, die die KI schon liefert: `consumer_name`/`claim` aus `concept.plan`s `name_claim` (landet heute nur in der Canvas, `:402-409`), `target_price_per_person` aus `menue_preis_ziel_pp`, `price_display` als bewusster Wert statt DB-Default. |
| C-4 | **`ConceptOneShotService::anreichern()`** — das fehlende Analogon (B9), Muster von `RecipeOneShotService`: `luecken()` über eine `ZIELFELDER`-Karte für Concept-Kopf und -Struktur, Run-Protokoll, Override-First. Dazu `BulkRunType::EnrichConcept`. |
| C-5 | Format analog: `SEKTIONS_GERUEST` (heute nur am UI-Button `Formate/Editor:382`) auch im KI-/Kaskaden-Pfad; Facetten und `customer`/`note` aus dem Brief, soweit `format.grundgeruest` sie liefert. Bildwelt bleibt bewusst manuell. |
| C-6 | Toten Code entscheiden: `composition_source`, `ai_confidence`, `ai_reasoning`, `phase`, `concept_slots.level` — füllen oder Spalte als tot markieren. `ai_confidence`/`ai_reasoning` zu füllen ist billig (die Werte liegen im Job-Cache) und macht die Provenienz sichtbar. |
| C-7 | **Angebot auf Foodbook-Niveau bringen** (§4.8): `strukturAusGeruest`-Äquivalent, damit Frame-Slot-Labels als Kapitel-Titel ankommen statt in `defaultKapitel` mit Titel `'Menü'` zu verschwinden. Dazu die fehlenden Tools (`offer_chapter.REORDER/MOVE`, `offer_block.REORDER`) und ein MCP-Weg für `AngebotService::kiKundentextVorschlag`/`kiKapitelKundentextVorschlag` (heute UI-only). Staffel für `header_preis` fehlt gegenüber Foodbook — entscheiden, ob nötig. |
| C-8 | `headerPresets()` als **Vokabular** verfügbar machen (§4.8): 30+ kanonische Labels inkl. `header_source`-Lineage stecken in `FoodbookService:1589-1621` und werden nur von zwei Livewire-Klassen gelesen. Als Service-/Vokabular-Read exponieren, damit C-1, `ablauf.GET` (E-3) und die Block-Tools daraus schöpfen statt freien Text zu erwarten. Analog `FormatService::SEKTIONS_GERUEST:354` aus dem Livewire-Button in eine Service-Methode heben (Lücke 6) — sonst kann der Agent „+ Gerüst" nicht reproduzieren. |
| C-9 | Speiseplan: entscheiden, ob er eine Struktur-/Textebene braucht. Heute hat er keinen `type`-Diskriminator, keine Sektionen und keinen `kundentext`-Einstieg; `speiseplan.PLAN_FROM_BRIEF` hat damit nichts zu benennen. Kann bewusst so bleiben — dann gehört es als „nicht anwendbar" in die Reife-Adapter, nicht als Lücke. |

### Paket D — Schicht 4: die Messlatte

| # | Was |
|---|---|
| D-1 | `ReifeService` + ein `ReifeAdapter` je Artefakt-Typ, Muster von `Services/Conformance/ConformanceAdapter` (`artifactType()`, `pruefauftrag()`, hier zusätzlich `sollAspekte()`/`messeLuecken()`). Artefakt-Typen: `gp`, `recipe`, `sales_recipe`, `concept`, `format`, `foodbook`, `speisekarte`, `speiseplan`, `angebot`. |
| D-2 | **Die vorhandene Messung nicht wegwerfen** (B3): `anreichern()` liefert `$ergebnis['coverage']`, `EnrichRecipeJob:80` verwirft es. Persistieren (bzw. an den Reife-Report reichen) ist der billigste Einstieg. |
| D-3 | Quellen komponieren, nichts nachbauen: `BulkEnrichService::luecken` · der extrahierte read-only Teil von `wirtschaftlichkeitsGlied` (§6, T2) · `DataQualityService::trifftObjekt` + `namingBefundeFuer` · `CoverageService::coverage` · `SignalObjectService::signaleAmObjekt` + `SignalFixService::assist` · `foodalchemist_conformance_findings` · `MoneyTruthReportService` (Blöcke A–C, heute nur Console). |
| D-4 | Reife-Tool(s) read-only + `reife` und `naechste_schritte` additiv in den Write-Antworten der Kette. Harte Auflage: **kein Provider-Call, kein Schreiben** — `cost_class` bleibt `local_db`. |
| D-5 | `ConformanceCheckJob` auch auf dem MCP-Schreibpfad anstoßen (§4.4) und einen read-only Conformance-Read als Tool. Adapter für `concept`/`format` sind der nächste Schritt — heute endet Schicht 3 beim Rezept. |
| D-6 | Team-Ebene: `team_fek_fehlt` und die Bezugsbasen-Lücke einmal je Team melden, nicht je Gericht, mit Zeiger auf `team_settings.PUT`. |

### Paket E — Ablauf-Wissen ausliefern

| # | Was |
|---|---|
| E-1 | **`recipes.ENRICH` / `verkaufsrezepte.ENRICH`** — die Wurzel aus §4.1: `PlanningCascadeService::enrichBestehendesRezept()` als MCP-Tool, mit `complete_coverage` (Default an) und `ki_bilder` (Default aus). Kein neuer Fachpfad, der Service ist erprobt. Dazu `concepts.ENRICH` aus C-4. |
| E-2 | `gps.MATCH` um `bio`/`pref`/`mode`/`prefer_raw` erweitern (§4.3) und dieselben Parameter in den Auto-Ground-Zweig von `recipes.POST`/`recipe_ingredients.PUT` durchreichen. |
| E-3 | `ablauf.GET(vorgang)` — Vorgangs-Register: Kette, Vorbedingungen, Soll-Aspekte, Pflicht-Regelwerke, Tool je Schritt, Anti-Patterns, Definition-of-Done. Prosa aus den 8 Workflow-Docs (aufgefrischt), Aspekt-Liste **abgeleitet** aus Prompt-Registry + `SCHRITTE*` + Reife-Codes + `PlanningCascadeService`, normative §-Abschnitte **aus dem Kanon** (E-7). |
| E-4 | `regelwerk.GET(vorgang\|prompt_key)` — liest den Kanon (E-7); solange dessen Zeilen fehlen, Fallback auf `KnowledgeContextService::regelwerkBlock`/`contextFor`, das heute nicht über MCP exponiert ist. Präzedenz: `signal_causes.GET` liefert schon `art:'regelwerk'` + Deep-Link. |
| E-5 | Einstieg: Semantic-Layer-Heuristik für Team 6 (reine Datenzeilen via `core.semantic_layer.versions.POST`) + Zeiger in den Descriptions der Write-Tools. Core-`skill_registry` bleibt draußen, solange kein Vault existiert — FA liefert sein Ablaufwissen über eigene Tools aus. |
| E-6 | Workflow-Docs auffrischen (alle auf `letzte_sync: 2026-07-14`, `Foodbook_anlegen_MCP.md:23` widerspricht `docs/foodbook.md:18`) und die Kopf-Felder **indexieren**: `code`, `trigger_phrases[]`, `required_tools[]`, `gilt_fuer_vorgang`. Heute stehen sie nur als Textkopf im `content_md`, deshalb matcht `knowledge.SEARCH` nicht auf „Gericht anlegen". |
| **E-7** | **Kanon-Leser bauen und den Kanon für die Weg-B-Vorgänge befüllen** — siehe §5.1. Das ist der Mechanismus, mit dem sich der Agent das Wissen selbst abholt. |

### §5.1 Kein neues Skill-Modul: der Kanon ist der Verteiler mit zwei Abnehmern

Die naheliegende Idee — eine neue Wissens-Kategorie oder ein eigenes FA-Skill-Modul — ist
geprüft und verworfen. Beides existiert schon:

- **Kategorie:** `2026_07_14_000020_rename_skill_to_workflow_category.php` hat `skill` →
  `workflow` umbenannt, ausdrücklich weil „skill" den Plattform-Begriff `skill_registry`
  überlud: „das sind FA-Handlungs-Abläufe". Die Kategorie für Abläufe gibt es also; eine
  zweite würde dieselbe Sache spalten.
- **Modul:** Das Wissensmodul *ist* das FA-eigene Skill-Modul — Dokumente mit
  Slug/Version/`active`/Aliases/Bindings, Kategorien zur Laufzeit pflegbar
  (`Livewire/Settings/Wissenskategorien`), Browser-UI (`Livewire/Knowledge/Browser`),
  Hybrid-Suche, Auto-Embedding, volle MCP-CRUD, Import/Export mit Reconciliation. Ein zweites
  Modul würde Versionierung, Aktivierung, Embedding-Pool und Browser doppeln.

**Seit 2026-09-03 gibt es zusätzlich die feingranulare Ebene** (W1-2/W1-5, gebaut:
`Services/Knowledge/KnowledgeSectionizer`, `KnowledgeChunker`, `KnowledgeSectionizeCommand`):

- `foodalchemist_knowledge_sections` — jedes Dokument in Abschnitte, mit `anchor` (`§6.1` |
  `abs-3` | `lead`), `heading_path` und `kind` ∈ `normativ | referenz | beispiel | changelog
  | meta | prosa` (indexiert).
- `foodalchemist_knowledge_canon` — der Verteiler: „welche Abschnitte ein Feature/Prompt-Key
  **verbindlich** mitbekommt, statt ‚ganzes Dossier oder nichts'". Felder: `scope` ∈
  `feature | prompt_key`, `scope_key`, `role` ∈ `root | child` („der Start-Aufruf vs. das
  Kaskaden-Kind, das weniger braucht"), `mode` ∈ `pflicht | wenn_platz`.

**Der Kanon hat heute keinen Leser und keinen Schreiber** (`grep knowledge_canon src/` =
leer) — die Tabelle wurde bewusst leer angelegt. Und genau das ist die Chance: `scope` und
`role` passen auf **beide** Wege. Der Generator zieht die Abschnitte in den Prompt (Weg A),
`ablauf.GET`/`regelwerk.GET` ziehen **dieselben Zeilen** in den Agenten-Kontext (Weg B).
Eine Kuration, zwei Konsumenten — „die KI-Wege sind die große Vorlage" nicht als Analogie,
sondern als geteilte Tabelle.

#### Warum die Tabellen leer sind — und warum wir nicht darauf warten

Zwei verschiedene, dokumentierte Gründe (`docs/PLANUNG/48_Wissen_Token_Programm.md:4,223,232`):

- **`sections` + `chunks` (Welle 1, W1-5):** „Produzent GEBAUT" — Sectionizer, Chunker und
  `foodalchemist:knowledge-sectionize`, 10 Tests. Offen ist der **Umschalter**:
  `min_score` auf die neue Score-Verteilung kalibrieren, Off-Peak-Re-Index (~5.800 serielle
  Roundtrips, ~$0,10), Purge gegen das Register. Bis dahin ist der Embedding-Pfad
  unverändert Doc-granular. Off-Peak ist Pflicht → `feedback_demo_heavy_jobs_offpeak`.
- **`canon` (Welle 2):** „wartet auf fachliche Abnahme". Dafür liegt eine fertig gemessene
  Vorlage bereit: `docs/PLANUNG/46_Kanon_Entscheidungsvorlage.md` (2026-09-03, „Zwei
  Entscheide für dich, einer davon fachlich", mit „Was ich brauche"-Abschnitt). Sie belegt an
  167 Konformitäts-Befunden über 68 Artefakte, dass **§1 Naming mit 65 Befunden (38,9 %)**
  der größte Block ist — und §1 heute nicht im Prompt gebunden ist. Offener Punkt dort:
  „Kanon §1 + §10 in den Prompt — WIRKLICH OFFEN" (`48_…:149`). Vgl.
  `project_fa_regelwerk_bindung_vs_durchsetzung`: Break-even für §1+§10 bei 10,2 %.

Die Tabellen sind also nicht vergessen, sondern hängen an einer unbeantworteten
Entscheidungsvorlage und an einem nicht umgelegten Schalter.

#### Was der Kanon fachlich löst (Kurzfassung für die Umsetzung)

Heute ist **das Dokument** die Adresse. Routing sagt „1 Regelwerk-Doc, max. 7.000 Zeichen"
(`ai_generate_recipe`, `recipe.ueberarbeiten`; 6.000 bei `recipe.eigenschaften`), der
Block-Builder nimmt `->first()` und `truncate()` kappt am Ende
(`KnowledgeContextService::REGELWERK_TRUNCATE_CHARS = 7000`; `pflichtZeichen()` sichert nur
ab, dass der Deckel nicht unter die `always`-Pflichtmenge fällt). Die §-Dossiers zusammen
sind laut Vorlage 46 ≈ **33.820 Zeichen** — es passt etwa ein Fünftel, und die Auswahl
entscheidet die Position im Dokument, nicht die Wichtigkeit. Gemessene Folge: §1, §10, §11
und §8 sind **nicht** im Prompt und tragen **112 von 167 Befunden**; §3 ist gebunden und
trägt **null**.

Der Kanon macht **den Abschnitt zur Adresse**. Eine Zeile heißt: *für diesen `scope_key`, in
dieser `role`, kommt dieser Abschnitt mit — `pflicht` immer, `wenn_platz` nur im Restbudget.*
Drei Dinge werden damit erst möglich:

1. §1 binden, ohne §3 mitzuschleppen — heute eine Alles-oder-nichts-Frage.
2. Ballast über `kind` ausschließen (`changelog`, `beispiel`, `meta` gehören in keinen
   Generier-Prompt und fressen das Budget, das §1 bräuchte).
3. `role = root|child` — der Einstiegs-Aufruf bekommt den vollen Vorgang, das Kaskaden-Kind
   nur das Nötige. Bei einer Vollkaskade über 30 Slots ist das der Unterschied zwischen
   einmal und dreißigmal bezahlen.

**Entscheidend für diese Spec:** der Kanon ist eine *Verteilungstabelle*, keine
Prompt-Funktion. Deshalb bedienen dieselben Zeilen beide Wege — Generator in den Prompt,
`ablauf.GET`/`regelwerk.GET` in den Agenten-Kontext. Einmal kuratieren, zwei Abnehmer.

#### Die Dokument-Aufteilung war der richtige Schritt — der Kanon ist der nächste

Dominique hat die Regelwerke bewusst einzeln angelegt, um Granularität zu bekommen. Das hat
gewirkt und ist die Voraussetzung für das heutige Verhalten: `regelwerkBlock` wählt **pro
Feature** über ein Slug-Muster (`%basisrezept%`, `%concept%`, `%foodbook%`) und extrahiert
„nur die tragende Region (nicht der ganze ~50k-Text — §2 beginnt erst bei ~17k, ein blinder
Head-Truncate verfehlt sie)". Ohne die Aufteilung gäbe es diese Zuordnung nicht.

Drei Decken bleiben, alle in derselben Methode sichtbar:

1. **Ein Dokument pro Feature.** Ein Vorgang, der zwei Regelwerke berührt — „Rezept anlegen"
   braucht Basisrezepte **und** Grundprodukte für die Zutaten-GPs — kann nur eines bekommen.
   `format.grundgeruest` trägt sogar einen Eintrag mit `slug_like => '%format%'`, der bewusst
   nichts trifft, nur um den Basisrezept-Fallback zu verhindern.
2. **Die §-Auswahl steht in PHP:** `extract => 'basisrezept' | 'concept' | 'foodbook'` sind
   hartcodierte Regionsnamen. Eine andere Auswahl ist eine Code-Änderung.
3. **Die Map ist ein PHP-Array**, samt Header-Texten. Neues Feature = neuer Code-Eintrag.

Der Kanon holt genau diese drei Dinge aus dem Code in die Daten: mehrere Dokumente pro
Feature, §-Auswahl als Zeilen, neues Feature als Zeilen statt Map-Eintrag.

#### Und das gilt nicht nur für Regelwerke

Der Sectionizer ist bereits **kategorie-agnostisch** (verifiziert): er verzweigt nur bei der
`kind`-Einstufung („im `regelwerk` ist ALLES normativ ausser Changelog/Notizen", sonst
`prosa`) und vergibt `§6.1`, wenn die Überschrift eine §-Nummer trägt, sonst eine laufende
Marke (`abs-3`, `lead`). Das Kommando lädt alle Docs ohne Kategorie-Filter; nur `--verify`
ist regelwerk-spezifisch.

Dieselben harten Decken stehen in allen Kanälen:

| Kategorie | Decke heute | Ort |
|---|---|---|
| `cross_cutting` | **7 feste Slugs, Routing-Werte werden ignoriert**, je 1.800 Z. | `CROSS_CUTTING_TRUNCATE_CHARS`, `crossCuttingSlugs()` |
| `domain` | 2.500 Z. je Doc | `DOMAIN_TRUNCATE_CHARS` |
| `concept` | 4.000 Z. je Doc, Doc-granular | `CONCEPT_TRUNCATE_CHARS` |
| `regelwerk` | 1 Doc, 7.000 Z., Region hartcodiert | `regelwerkBlock`-Map |
| Rezept-Budget | 2.400 Z. je Doc, überschreibt alles | `RECIPE_MAX_CHARS_PER_DOC` |

Dass `cross_cutting` und `domain` die Routing-Werte ignorieren, ist eine bekannte Falle
(`feedback_fa_knowledge_routing_fallen`) — der Kanon räumt sie mit auf, weil die Auswahl
dann nicht mehr aus Konstanten kommt.

#### Ziel ist „alle Kategorien granular" — aber über zwei Mechanismen

Der Schnitt läuft nicht entlang der Kategorie, sondern entlang des Routing-`mode`
(gezählt über `KnowledgeImportCommand::seedRoutings` + Migrationen):

| Modus | Kategorien | Auswahl hängt ab von | Granulares Gegenstück |
|---|---|---|---|
| `always` | `cross_cutting` (5) · `regelwerk` (4) · `concept` (2) · `produktion_kapazitat` (1) | **nichts** — feste Pflichtmenge je Feature | **Kanon** (E-7) |
| `discovery` | `domain` (3) · `trend` (2) · `niveau` (2) · `kueche` (2) · `kreativ_input` (1) | **dem Input** — „Steinpilz" → Pilz-Domain | **Chunk-Retrieval** (E-8) |
| — | `workflow` | kein Routing (search-only) | Abhol-Tool `ablauf.GET` (E-3) |

Der Kanon ist eine **feste Liste je Feature**. Für `always` ist das exakt richtig: „bei
Rezept-Erzeugung immer §1, §10, §12" hängt von nichts ab. Für `discovery` wäre es falsch —
dort ist die Relevanz zum konkreten Brief der ganze Sinn; ein fester Kanon würde die Auswahl
einfrieren und genau das zerstören, wofür Retrieval da ist.

Beide zusammen ersetzen **alle** hartcodierten Decken: `CROSS_CUTTING_TRUNCATE_CHARS`,
`DOMAIN_TRUNCATE_CHARS`, `CONCEPT_TRUNCATE_CHARS`, `RECIPE_MAX_CHARS_PER_DOC` und die
`regelwerkBlock`-Map.

Und sie schließen sich nicht aus: für die discovery-Kategorien ist der Endzustand vermutlich
**Kanon als Pflicht-Boden** (`mode=pflicht`) **plus Retrieval im Restbudget**
(`wenn_platz`) — die zwei Werte stehen dafür schon im Schema.

**Konsequenz für den Bau:** der Kanon-Leser (E-7b) wird von Anfang an
**kategorie-agnostisch** gebaut — er darf nicht auf `regelwerk` verdrahtet werden. Das
Befüllen bleibt gestuft (E-7d), aber die Zielmenge ist vollständig: alle vier
`always`-Kategorien.

#### E-7 — Kanon befüllen (Entscheid Dominique 2026-09-04)

Vier Teile, in dieser Reihenfolge:

| # | Was | Stand heute |
|---|---|---|
| E-7a | **Sectionize-Lauf**: `foodalchemist:knowledge-sectionize` — der Kanon referenziert `knowledge_section_id`, ohne Abschnitte gibt es nichts zu verteilen. Off-peak (`feedback_demo_heavy_jobs_offpeak`), ~5.800 serielle Roundtrips, ~$0,10. **Nicht** gekoppelt an den Embedding-Umschalter aus W1-5 — der bleibt eine eigene Entscheidung (Kalibrierung + Purge); für den Kanon reichen die Sections. | Produzent gebaut, Lauf offen |
| E-7b | **Leser** (`KnowledgeCanonService`) + Einbau in `KnowledgeContextService`: liegen Kanon-Zeilen für ein Feature vor, treten sie an die Stelle des bisherigen Block-Builders; liegen keine vor, bleibt alles wie heute. Damit ist der Übergang **je Feature und je Kategorie** schaltbar statt global. **Kategorie-agnostisch bauen** — nicht auf `regelwerk` verdrahten, sonst ist der zweite Schritt wieder ein Umbau. | existiert nicht (`grep knowledge_canon src/` leer) |
| E-7c | **Schreiber**: MCP-Tools `knowledge_canon.GET/PUT/DELETE` — die Kuration gehört dorthin, wo die Regelwerke seit Spec 41 A3 gepflegt werden. Im PUT die Invariante prüfen, die das Schema nicht erzwingen kann: eine Zeile mit `team_id` NULL darf **nur** Abschnitte mit `team_id` NULL referenzieren, sonst zieht ein globaler Kanon team-eigenes Wissen in fremde Prompts. | existiert nicht |
| E-7d | **Befüllen, gestuft und additiv — Zielmenge sind alle vier `always`-Kategorien.** (1) `regelwerk`: die vier Features mit der höchsten Befundlast, §1 Naming · §10 Anti-Patterns · §11 Derivate · §8 KI-Beschreibung, plus die Mehr-Regelwerk-Fälle, die heute unmöglich sind (Rezept-Vorgang = Basisrezepte **+** Grundprodukte). (2) `workflow`: die Weg-B-Vorgangs-Schlüssel für `ablauf.GET`. (3) `cross_cutting` — dort ist der Gewinn am größten, weil der Builder heute 7 feste Slugs nimmt und die Routing-Werte **ignoriert**. (4) `concept` + `produktion_kapazitat`. Bestehende `always`-Bindings bleiben stehen, bis ihr Feature umgestellt ist. | fachliche Auswahl = Vorlage 46 |

#### E-8 — die discovery-Hälfte: Chunks statt ganzer Docs

Damit „alle Kategorien granular" wirklich stimmt, fehlt das Gegenstück für `domain`,
`trend`, `niveau`, `kueche` und `kreativ_input`. Dort ist die richtige Granularität nicht
eine kuratierte Liste, sondern **Retrieval auf Abschnitts-/Chunk-Ebene** — der Produzent ist
gebaut (`KnowledgeChunker`), offen ist der Umschalter: `min_score` auf die neue
Score-Verteilung kalibrieren, Off-Peak-Re-Index, Purge gegen das Register
(`docs/PLANUNG/48_Wissen_Token_Programm.md:223,232`).

**Eigenes Paket, bewusst hinter Schicht 4.** Es bringt Retrieval-Risiken mit (eine falsch
kalibrierte Schwelle senkt den Recall still — vgl. `project_fa_welle1_w11_verworfen`, wo
ein größeres Fenster den Recall von 72 % auf 68 % **verschlechtert** hat, und
`feedback_semantik_messung_team_pflicht` zur Messfalle ohne `--team`). Paket D hängt an
nichts davon; E-7 auch nicht — der Kanon braucht nur die Sections, nicht die Chunk-Vektoren.

##### Was das für Qdrant bedeutet

Das Wissen liegt über Cores `EmbeddingService` im Store (`entity_type =
foodalchemist_knowledge_document`), global über den Sentinel `team_id = 0`, weil Cores Store
ein `int` verlangt — der offene Core-Wunsch nach nativem Global-Scope bleibt davon unberührt.

- **Etappe 9 (Kanon) fasst Qdrant nicht an.** Deterministische SQL-Auswahl, kein Ranking,
  keine Vektoren.
- **Etappe 10 ändert, was ein Punkt *ist*:** heute ein Vektor je Dokument, danach einer je
  Chunk. Der Chunker stellt `{Kategorie} · {Doc-Titel} · {heading_path}` **vor** das Fenster,
  damit jeder Vektor seinen Ort trägt — der Fix dafür, dass Discovery prozedurales Wissen
  heute nicht surfacet. Größen gemessen, nicht geraten: 900 Ziel / 1400 max / 150 Overlap.
- **Purge ist Pflicht, nicht Kosmetik:** bleiben die Doc-Punkte liegen, konkurrieren zwei
  Granularitäten im selben Pool und das Ranking wird unentscheidbar.
- **`min_score` neu kalibrieren** — die Score-Verteilung verschiebt sich mit der
  Einheitengröße. Das ist der Grund, warum der Umschalter offen steht.
- **Zwei Kategorien bleiben doc-granular:** `pairing` (kuratierte Oberflächenform aus Zutat +
  verifizierten Partner-Namen; Zerschneiden zerstört sie — einzige Kategorie mit eigenem
  Embed-Pfad) und `cross_cutting` (heute gar nicht indexiert, „always-load, kein
  Discovery"). Passt zur Aufteilung: `cross_cutting` gewinnt durch E-7 und wird von E-8 nicht
  berührt.

**Warum additiv:** so fällt keine Spec-41-Umkehrung,
`RegelwerkKnowledgeRoutingTest` bleibt grün, und die Wirkung ist an der Befundzahl messbar,
bevor der größere Schnitt entschieden wird. Die volle Umstellung — Kanon **ersetzt** das
„ganzes Dossier"-Routing auch für den Generator — bleibt die Runde aus Vorlage 46.

**Was E-7 nicht blockiert:** `ablauf.GET` und `regelwerk.GET` (E-3/E-4) werden so gebaut,
dass sie bei leerem Kanon ganze Docs liefern und bei befülltem Kanon §-genau — gleicher
Tool-Vertrag. Schicht 4 (Paket D) hängt an keinem der vier Teile.

Zwei Invarianten dabei, die das Schema nicht erzwingen kann und die im PUT zu prüfen sind:
eine Kanon-Zeile mit `team_id` NULL darf nur Abschnitte mit `team_id` NULL referenzieren
(sonst zieht ein globaler Kanon team-eigenes Wissen in fremde Prompts), und die
`workflow`-Kategorie wird **nicht** in Generator-Prompts geroutet — die Docs sagen im
Frontmatter selbst, sie seien „Orchestrierungs-Workflow für einen Agenten, KEIN
in-Prompt-Generierungswissen".

### Anhang — Core-Beobachtungen (kein Arbeitspaket, blockiert nichts)

Geprüft und verworfen als eigenes Paket: **keine der Etappen hängt daran.**
`tool_registry.SEARCH` ist in `McpSessionToolManager::DISCOVERY_TOOLS`, also sind
`ablauf.GET` und die Reife-Tools ohne Core-Änderung findbar; der Folgeweg kommt aus
`naechste_schritte` in der eigenen Write-Antwort (D-4); `skill_registry` ist in E-5 ohnehin
außen vor, solange kein Vault existiert. Grenze bleibt
`feedback_keine_fremdmodul_aenderungen`: gebaut wird nur in `platforms-foodalchemist`.

Drei Beobachtungen, die bei Gelegenheit an Martin gehen können:

1. `ToolMetadataResolver::applyExplicitMetadata():152` verwirft `related_tools` und
   `examples` — 396 der 431 FA-Tools pflegen sie. „Häufige Folge-Tools" kommt stattdessen
   aus Telemetrie (`ToolInsightsService`, Ko-Okkurrenz in `tool_executions`) und lernt damit
   den Weg, den Agenten *tatsächlich* gehen. **Das erledigt sich mit D-4 von selbst:** sobald
   die Write-Antwort den richtigen Folgeschritt nennt, wird das tatsächliche Verhalten das
   richtige, und die Telemetrie lernt es. F1 würde nur den Übergang beschleunigen.
2. `skill_registry.SEARCH/GET` und `tool_registry.GET` sind nicht in `DISCOVERY_TOOLS` —
   beworben in den Server-Instructions, aber nie in `tools/list`. Entweder aufnehmen oder
   die Bewerbung entfernen.
3. Skill-Vault-Infrastruktur (S3 + `platform.skills_vault_id`) nur relevant, falls
   `skill_registry` je der Kanal werden soll.

## §6 Etappen

**Etappe 0 — Messung (vor jedem Umbau).** Hausprinzip 22·H2. Ein Console-Command im Stil
von `MoneyTruthReportCommand`, das je Team zählt: wie viele Gerichte ohne
Aufschlagsklasse / ohne Portion / ohne Darreichung; wie viele Rezepte mit `work_time_min`
NULL; wie viele Concepts ohne einen einzigen Header-Block; wie viele GPs `tentative` ohne
Nährwerte/Allergene; und — für die Kostenentscheidung — **Provider-Calls pro
Anreicherungslauf im Ist**. Erst diese Zahlen entscheiden A2 (Default oder harte Lücke) und
belegen später, dass Paket B gewirkt hat.

**Etappe 1 — Paket A.** Die acht stillen Fehler (A1–A8). Klein, isoliert, je mit Test.
**A8 zuerst** — es ist das einzige Element in A, das ohne Fix einen Flächenschaden zulässt
(Paket B würde die KI-Abschirmung der LA-Kaskade über den ganzen GP-Bestand auslösen).

> ⚠ **Datei-Kollision beachten:** A1 (`markup_class_id` in `RecipeService::create()`) liegt in
> `src/Services/RecipeService.php` — dieselbe Datei trägt auf `fix/ek-stk-bruecke-live`
> uncommittete Arbeit (+7 Zeilen). Ebenso dort in Arbeit: `GpFormService`,
> `RecipeRecomputeService`, `IngredientEditor`, `GpFormsPutTool`, `GpFormsDeleteTool`,
> `ReportExportService`, `config/foodalchemist.php`. A1 erst nach dem Merge dieses Branches
> ziehen oder gezielt rebasen; nie im Haupt-Clone arbeiten
> (`feedback_fa_repo_parallele_session`, `feedback_git_stage_specific_files`).

**Etappe 2 — T2: den messenden Teil aus `wirtschaftlichkeitsGlied` herausziehen** (siehe
§7). Voraussetzung für D, weil die Methode heute schreibt, bevor sie misst.

**Etappe 3 — Paket D (Rezept + Gericht).** `ReifeService` + Adapter für `recipe`/
`sales_recipe`, D-2, Reife-Tool, `reife` in `recipes.POST/PUT`, `recipe_ingredients.PUT`,
`verkaufsrezepte.POST/PUT`. Ab hier melden sich die gestrigen Löcher beim Anlegen.

**Etappe 4 — E-1 + E-2.** `recipes.ENRICH`/`verkaufsrezepte.ENRICH` und die
`gps.MATCH`-Parameter. Damit ist der Alltags-Weg zum ersten Mal vollständig fahrbar.

**Etappe 5 — Paket B.** Anreicherung vervollständigen. Nach Etappe 3 messbar: die
Lückenliste muss kürzer werden, die Call-Zahl aus Etappe 0 darf nur so weit steigen, wie
neue Aspekte es erklären.

**Etappe 6 — Paket C.** Header + Concept-/Format-Anreicherung + `concepts.ENRICH`.

**Etappe 7 — Paket D für die Container.** `concept`/`format`/`foodbook`/`speisekarte`/
`speiseplan`/`angebot` über `CoverageService` und die Header-Codes; offene Dimensionen
ehrlich als `nicht_messbar`.

**Etappe 8 — E-3 bis E-6** (Vorgangs-Register, Regelwerk-Tool, Einstieg, Workflow-Docs
auffrischen und Kopf-Felder indexieren). Beide Tools mit dem Fallback „leerer Kanon ⇒ ganze
Docs", damit die Etappe für sich lauffähig ist.

**Etappe 9 — E-7: Kanon befüllen.** In der Reihenfolge a→d: Sectionize-Lauf über **alle**
Kategorien (off-peak), kategorie-agnostischer Leser, Schreiber-Tools mit der
`team_id`-Invariante, dann gestuft befüllen — erst `regelwerk` (§1/§10/§11/§8 + die
Mehr-Regelwerk-Fälle), dann `workflow` für `ablauf.GET`, später `cross_cutting`/`domain`/
`concept`. Voraussetzung für die erste Stufe ist die fachliche Auswahl aus
`docs/PLANUNG/46_Kanon_Entscheidungsvorlage.md`.

**Etappe 10 — E-8: die discovery-Hälfte.** Chunk-Retrieval für `domain`/`trend`/`niveau`/
`kueche`/`kreativ_input` scharfstellen. Erst danach ist „alle Kategorien granular"
vollständig und alle hartcodierten Zeichen-Decken sind ersetzt. Getrennt gehalten, weil
Retrieval-Kalibrierung eine eigene Messstrecke braucht — und weil nichts vor Etappe 10
darauf wartet.

## §7 Zwei Umsetzungsdetails, die leicht schiefgehen

**Der messende Teil des VK-Glieds muss extrahiert, nicht nachgebaut werden.**
`RecipeOneShotService::wirtschaftlichkeitsGlied():1003` ist `private` und **schreibt**: es
setzt `markup_class_id` aus der Fallback-Kette (`gesetzt > Klasse-Default > HG-Default`) per
`SalesService::updateVk` und legt über `ensureStandard` eine Standard-Darreichung an — erst
danach ermittelt es die Lücken. Read-only wiederverwenden geht so nicht, und die
Fallback-Kette ein zweites Mal zu schreiben verstößt gegen „Eine Formel pro fachlicher
Wahrheit" (`docs/ARCHITEKTUR.md:144`). Richtig: eine öffentliche
`vkVorbedingungen(Team, Recipe, ?zielVk): array` (Portion, Aufschlagsklasse inkl. Kette,
Standard-Darreichung, Wareneinsatz/Ampel über den bestehenden `MargeService::marge`), die
`wirtschaftlichkeitsGlied` anschließend selbst aufruft. Eine Wahrheit, zwei Aufrufer — genau
das Muster, mit dem `BulkEnrichService::laufAnlegen` für Spec 03 L7 extrahiert wurde.

**Das Soll wird abgeleitet, nicht abgeschrieben.** Eine handgepflegte Aspekt-Liste in
Markdown ist genau das, was seit 2026-07-14 nicht funktioniert: die 8 Workflow-Docs sind
fachlich gut und trotzdem wirkungslos, weil sie altern und niemand sie ausliefert. Die
Aspekt-Liste je Artefakt-Typ speist sich deshalb aus Prompt-Registry,
`BulkEnrichService::SCHRITTE*`, `ZIELFELDER` und dem Datenmodell; die Prosa (Anti-Patterns,
Fachsprache) bleibt in den Docs. Ändert sich ein Schritt im Code, ändert sich die Auskunft
mit.

## §8 Verifikation

- **Etappe 0 als Baseline festhalten** — die Zahlen sind der Vergleichsmaßstab für Etappe 5
  und 6. Ohne sie ist „vollständiger angereichert" eine Behauptung
  (`feedback_verify_before_claiming`).
- **Kosten sichtbar machen:** Provider-Calls pro Anreicherungslauf vor/nach Paket B,
  ausgewiesen je Artefakt-Typ. `complete_coverage` bleibt als Leitplanke steuerbar;
  der KI-Kill-Switch (`TeamSettingsService::kiAktiv`, geprüft in `AiGatewayService:92`)
  bleibt die harte Notbremse.
- **Unit/Feature:** `ReifeService` je Artefakt-Typ mit einem vollständigen und einem
  lückenhaften Datensatz; `nicht_messbar` statt Raten bei fehlendem Ist-Bezug; Team-A/B-,
  Parent- und Global-Fall pro neuem Tool (MCP-Vertrag,
  `docs/PLANUNG/26_LLM_MCP_Funktionsmatrix.md:46-57`); expliziter Nachweis, dass die
  Write-Antwort **keinen** Provider-Call auslöst.
- **Header-Nachweis am Renderer, nicht am Datensatz:** ein erzeugtes Concept muss in
  `WordingResolver`-Ausgabe eine `type=header`-Zeile liefern — genau der Punkt, an dem es
  heute scheitert. Für die drei Ausgabeflächen zusätzlich Browser-Abnahme
  (`feedback_fa_test_harness_layout_blind`: `Livewire::test` ohne Layout ist grün und im
  Browser 500).
- **Kein-Regress-Gate:** alle Write-Antworten additiv erweitert — Bestandstests der
  betroffenen Tools unverändert grün. Registry-Count-Tests bei B-5 mitziehen.
- **Volle Suite** vor dem Push (`./fa_test.sh`, ~35 Min / ~3.700 Tests, nur ein Lauf
  gleichzeitig, eigene Sandbox wegen paralleler Sessions); vorbestehende Rote vorher
  festhalten.
- **Der fachliche Abnahmetest — die gestrige Session gegenprüfen:** dasselbe Concept per MCP
  nachbauen. Erwartung nach Etappe 4: der Server meldet beim Anlegen fehlende
  Aufschlagsklasse, fehlende Portion/Darreichung, GP ohne belastbaren EK und fehlendes
  Team-FEK. Erwartung nach Etappe 6: das Concept trägt Header, die Gerichte tragen Sensorik,
  Anker, Rollen, Behälter und Servier-Vehikel — und der VK liegt im Zielkorridor, statt bei
  einem Drittel.
- **Kanon-Wirkung an der Befundzahl messen (E-7):** Vorlage 46 hat 167 Befunde auf 68
  Artefakte als Baseline. Nach dem Befüllen von §1/§10/§11/§8 muss deren Anteil sinken —
  §1 allein trägt 65 Befunde (38,9 %). Sinkt er nicht, war die Bindung nicht das Problem;
  das ist genau die Unterscheidung, die `project_fa_regelwerk_bindung_vs_durchsetzung`
  gemessen hat (§7/§12 sind entbunden und code-erzwungen → null Befunde). Zusätzlich
  `built_chars`/`dropped_chars` aus `regelwerkBlock` vor/nach vergleichen: der Kanon soll
  `dropped_chars` gegen null bringen, nicht nur umschichten.
- **A8 mit einem Regressionstest festnageln:** ein GP mit LAs, die ein Allergenprofil tragen,
  darf nach einem Bulk-Anreicherungslauf **nicht** `allergens_source='ki'` haben — sonst
  überspringt ihn `backfillAllergenKonfidenz` künftig. Gegenprobe: ein GP ganz ohne
  LA-Allergenprofil darf den KI-Wert bekommen. Das ist die Invariante „LA fixen → GP heilt",
  und sie ist heute nur im Docblock (`GpAggregateService:116`) beschrieben, nicht getestet.
- **Kanon-Tenancy explizit testen:** eine globale Kanon-Zeile darf keine team-eigenen
  Abschnitte referenzieren; Team-A/B- und Global-Fall am PUT.
- **Kanon-Leser gegen eine Nicht-Regelwerk-Kategorie testen** (z.B. `cross_cutting`), auch
  wenn diese Stufe noch nicht befüllt wird — sonst merkt niemand, dass der Leser doch
  regelwerk-verdrahtet ist, bis die zweite Stufe kommt. Und der Mehr-Dokument-Fall
  (Basisrezepte **+** Grundprodukte an einem Feature) gehört in den ersten Test: er ist
  heute unmöglich und der eigentliche Zugewinn gegenüber der Dokument-Aufteilung.
- **Doku mitziehen** (Pflicht bei jedem FA-Commit): ROADMAP + Office-Dev-Package 23.
  `26_LLM_MCP_Funktionsmatrix.md` ist als Tool-Liste veraltet (nennt 169/406/157 bei real
  431) — bei dieser Gelegenheit geradeziehen. Die drei Waisen aus B-5 dort ebenfalls
  nachtragen.

---

## §9 Etappe 0 — die Baseline (gemessen 2026-09-05, Dev-DB Team 6 „Demo")

Erhoben mit `php artisan foodalchemist:vollstaendigkeit-report --team=6 --json`
(read-only). Diese Zahlen sind der Vergleichsmaßstab für Etappe 5 und 6 — ohne sie ist
„vollständiger angereichert" hinterher eine Behauptung.

### A · VK-Vorbedingungen (950 Gerichte)

| Kennzahl | Wert |
|---|---|
| ohne Aufschlagsklasse | **926 (97,5 %)** |
| ohne Portion am Rezept | **925 (97,4 %)** |
| ohne jede Darreichung | 45 (4,7 %) |
| Standard steht auf `unbestimmt` (Review-Zustand) | 336 |
| ohne VK | 76 (8,0 %) |

Die 97,5 % sind der Ist-Beleg für B1 (`markup_class_id` wird still verworfen) **und** für
§4.1 (kein Anreicherungspfad für Bestandsgerichte). Kein Randfall — der Normalzustand.

### B · Anreicherung

| Ebene | Kennzahl | Wert |
|---|---|---|
| Basisrezept (2.335) | leer: description / category / geschmack | 5 · 55 · 11 |
| Gericht (950) | **leer: `sales_wording_standard`** | **939 (98,8 %)** |
| Gericht | leer: plating · speisen_klasse | 52 · 22 |
| beide | **`work_time_min` leer** | Basisrezept 51 · **Gericht 912 (96 %)** |
| beide | `dichteklasse` leer | 2.335 / 2.335 · 950 / 950 (**100 %**) |

**Der schärfste Beleg dieser Spec:** `wording` steht **in** `SCHRITTE_VK` — und ist trotzdem
bei 98,8 % der Gerichte leer. Die Schrittfolge existiert, sie läuft für Bestandsgerichte nur
nie (§4.1). Und `work_time_min` bei 96 % leer heißt: **FEK = 0 ist die Regel**, nicht der
Sonderfall aus der Session vom 03.09.

Coverage-Glieder (von 3.285 Rezepten ohne eine einzige Zeile):
`ohne_steps` 3.257 · `ohne_sensorik` 945 · `ohne_aromaanker` 3.282 · `ohne_pairings` 3.285 ·
`ohne_equipment` 1.121.

### C · Grundprodukte (7.948)

| Kennzahl | Wert |
|---|---|
| tentative | 791 |
| **ohne Aroma-Anker** | **7.948 (100,0 %)** |
| ohne Food-Domain | 294 (3,7 %) |
| **KI-abgeschirmt trotz LA-Profil (A8)** | **9** |

**100,0 % ohne Anker** ist die Bestätigung von §4.9 W1 in Reinform: `gp.anker` hat keinen
Aufrufer, also hat **kein einziger** GP einen Anker — und `DataQualityService::gp_anker_fehlt`
meldet seit jeher alle 7.948. Damit erklärt sich auch `ohne_pairings` = 3.285 von 3.285:
das `pairings`-Glied steigt mit `uebersprungen_ohne_grounding` aus, weil die Erdung von unten
nie entsteht. **Eine unverdrahtete Zeile in der Prompt-Registry kostet die komplette
Aroma-Erdung des Bestands.**

Der A8-Schaden ist real, aber noch klein: 9 GPs (u. a. `Schalotten: frisch, Wuerfel 5 mm`,
`Dill: frisch, ganz`, `Aepfel Pink Lady: frisch, ganz`) heilen nicht mehr mit, wenn ihr LA
korrigiert wird. Klein genug zum Reparieren, groß genug als Beleg, dass der Mechanismus greift
— und genau deshalb steht A8 **vor** Paket B.

### D · Concept-Struktur (27 Concepts)

| Kennzahl | Wert |
|---|---|
| ohne gerenderten Header | **23 (85,2 %)** |
| davon mit `role` statt `header` | 5 |

Die 5 sind der Beweis für §4.7: die Gliederung **ist** da, sie kommt im Kundendokument nur
nicht an, weil `WordingResolver` `type=header` + `title` verlangt und der Generator `role`
schreibt.

### E · KI-Kosten (Basis für den Vor/Nach-Vergleich)

318 Calls, 2.414.614 Tokens. Größte Posten: `recipe.generator` 45 Calls / 856k Tokens,
`vk.generator` 21 / 636k, `recipe.steps` 29 / 366k.

**Lese-Hinweis zur Spalte „offen":** nur `recipe.category` (15 angenommen) und
`vk.speisen_klasse` (1) durchlaufen den Proposal-/Accept-Lifecycle; alle übrigen Features
schreiben direkt und tragen deshalb weder `accepted_at` noch `rejected_at`. „Offen" ist dort
**kein Rückstand**, sondern bedeutet „kein Freigabe-Schritt vorhanden". Eine Akzeptanzquote
lässt sich aus diesen Zahlen also nur für die zwei Felder-KIs bilden — das ist zugleich der
Ist-Stand zu LLM-17 („Promptqualität und Drift messen") aus der Funktionsmatrix.

### Was die Baseline für die Reihenfolge bedeutet

1. **A8 zuerst** — 9 abgeschirmte GPs sind reparierbar; nach einem Bulk-Lauf über 7.948 GPs
   wäre es ein Flächenschaden.
2. **B-7 (`gp.anker`) ist der Hebel mit der größten Reichweite** — er sitzt vor der
   Aroma-Erdung von 3.285 Rezepten.
3. **A1 + E-1 zusammen** heben die 97,5 % ohne Aufschlagsklasse; einzeln bewirkt keines von
   beiden etwas (A1 setzt den Default, E-1 bringt ihn an Bestandsgerichte).
