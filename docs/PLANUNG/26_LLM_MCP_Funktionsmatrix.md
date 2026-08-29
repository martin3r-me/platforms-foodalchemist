# Food Alchemist — LLM- und MCP-Funktionsmatrix

- **Status:** aktive Capability- und Abnahmeliste
- **Stand:** 17.08.2026
- **Quellen:** Prompt-Registry, `AiGatewayService`, Toolklassen und Service Provider
- **Business-Bezug:** [Business-Case-Funktionsmatrix](25_Business_Case_Funktionsmatrix.md)
- **Steuerung:** [Umsetzungsplan zum Zielbild](24_Zielbild_2029_Umsetzungsplan.md)

## 1. Zweck

Diese Matrix schließt die Lücke zwischen Business-Funktion und maschineller
Capability. Sie beantwortet:

- welche LLM-Funktionen und Prompt-Keys existieren,
- welche Wissens-, DNA-, Kosten- und Freigaberegeln für jeden Call gelten,
- welche MCP-Tools tatsächlich registriert sind,
- welcher Nachweis über reine Registrierung hinaus erforderlich ist,
- wann Web-, LLM- und MCP-Pfad fachlich dieselbe Funktion ausführen.

## 2. Bestandsaufnahme

| Capability | Anzahl | Aktuelle Aussage |
|---|---:|---|
| Prompt-Keys in `config/foodalchemist.php` | 64 | Registry vorhanden; 11 Keys ohne direkte statische Referenz in `src/` (die 5 seit 01.08. ergänzten Keys sind alle direkt referenziert) |
| MCP-Toolklassen mit `foodalchemist.*`-Name | 404 | Provider registriert 404 (203 Bestand + 17 D1/Phase 0 + 13 D2 + 18 D3 + 27 D4 + 35 D5 + 10 D6 + 14 D7 + 8 D8 + 13 D9 + 10 D10 + 13 D11 + 8 D12 + 15 D13 Vokabular/Taxonomie-SAFE; Stand 2026-08-29). Offen: D13-Rest (~8 inline-DB-Panel-Vokabulare brauchen Service-Extraktion) + Phase N Navigation. §7-Register wird nachgezogen (Alt-Liste = 169) |
| Embedding-Pools mit Observern | 6 | GP, Rezept, Lieferant, Konzept, Foodbook, Lab Note |
| Wissens-/Retrieval-Schichten | 2 | deterministisch plus optional semantisch |
| dokumentierte vollständige MCP-Tenant-Abnahme | 0 | einzelne Tests vorhanden, keine 157-Tool-Matrix |
| dokumentierte reale LLM-Abnahme je Prompt | 0 | technische Tests vorhanden, kein vollständiges Golden Set je Prompt |

## 3. Verbindlicher Capability-Vertrag

### Für jeden LLM-Aufruf

1. Prompt-Key ist registriert und versionierbar.
2. Team- und Nutzerkontext sind korrekt.
3. wirksamer Wissens- und DNA-Kontext ist nachvollziehbar.
4. Antwortschema wird validiert; unbrauchbares JSON wird nicht persistiert.
5. Quelle, Modell/Tier, Kosten, Latenz und Fehler werden protokolliert.
6. Confidence `0` ist erlaubt und führt zu „unbekannt“, nicht zu einer Erfindung.
7. haftungsrelevante Werte bleiben Vorschlag bis zur Freigabe.
8. Provider-Ausfall erzeugt einen sichtbaren Fehler oder deterministischen Fallback.
9. Retry erzeugt keinen doppelten Write.
10. ein Real- oder Golden-Set misst fachliche Qualität, nicht nur JSON-Form.

### Für jedes MCP-Tool

1. Tool ist registriert und im Healthcheck sichtbar.
2. Name, Beschreibung, Eingabe- und Ausgabeschema sind stabil.
3. Teamkontext stammt ausschließlich aus `ToolContext`.
4. Reads respektieren globale/Ancestry-Sichtbarkeit.
5. Writes und Deletes erlauben nur eigene Datensätze.
6. alle Payload-IDs werden team-scoped neu geladen.
7. der Toolpfad ruft denselben Fachservice wie die Weboberfläche auf.
8. Write-Tools haben Idempotenz- oder Wiederholungsverhalten dokumentiert.
9. Fehler sind strukturiert und werden nicht als scheinbarer Erfolg zurückgegeben.
10. Team A/B, Global, Parent, ungültige ID und Retry sind getestet.

## 4. LLM-Querschnittsfunktionen

| ID | Funktion | Ist | Erforderlicher Nachweis |
|---|---|---|---|
| LLM-01 | Provider über Platform-Core oder Fake auswählen | gebaut | Verbindung, Timeout, Provider-Ausfall und Fake-Parität |
| LLM-02 | Prompt-Tier A–D auf Modell abbilden | gebaut | effektives Modell je Call im Log; Deployment-Konfiguration geprüft |
| LLM-03 | strukturiertes JSON-Envelop validieren | gebaut | falsches JSON, Fence, fehlende Werte und Confidence 0 |
| LLM-04 | Temperaturtreppe und Backoff | gebaut | Retryfolge, Maximalversuche und kein Doppelwrite |
| LLM-05 | KI-Kill-Switch je Team | gebaut | ausgeschaltet bedeutet keine Providerkosten und klarer UI-Zustand |
| LLM-06 | Food-DNA-Kette injizieren | gebaut | Team→Kunde→Foodbook-Vorrang sichtbar und reproduzierbar |
| LLM-07 | gebundenes Wissen injizieren | gebaut | Prompt-/Bereichsbindung, Team-Scope und effektiver Kontext |
| LLM-08 | deterministisches Wissen immer laden | gebaut | Cross-Cutting-Regeln unabhängig von semantischer Suche |
| LLM-09 | semantischen Wissens-Fallback nutzen | teilweise | VEC-01–14, Golden Set, Schwelle, Provider-/Store-Ausfall und kein falscher Anker |
| LLM-10 | Call-Log mit Token/Kosten/Latenz | gebaut | Kosten gegen Providerabrechnung; teambezogener Monatsreport |
| LLM-11 | agentischen Tool-Loop ausführen | teilweise | Tool-Allowlist, Schrittlimit, Tenantkontext und Audit-Trail |
| LLM-12 | Embeddings erzeugen/aktualisieren/löschen | teilweise | produktiver Vector Store, Backfill, Dual-Write, Delete, Partition und Wiederholung |
| LLM-13 | hybride Pool-Suche | teilweise | BC-12: Lexik vs. semantisch am Golden Set; Tenant-, Query- und Latenzbudget |
| LLM-14 | Sprache zu Text/Intent | teilweise | Audiofehler, Datenschutz, Vorschau und keine stille Ausführung |
| LLM-15 | KI-Vorschlag prüfen/übernehmen/verwerfen | gebaut | kompletter Lifecycle mit Audit und manueller Korrektur |
| LLM-16 | KI-Batch kontrolliert auslösen | gebaut | Budgetgrenze, Run-Status, Resume und keine ungeplanten Nachtkosten |
| LLM-17 | Promptqualität und Drift messen | offen | Golden Set, Akzeptanzquote, Korrekturquote und Versionsvergleich |
| LLM-18 | KI-Kosten je Kunde und Business Case messen | offen | reale Monatswerte für G2/M-28 |

## 5. Prompt-Registry

### 5.1 Grundprodukte und Lieferantenartikel

| Prompt-Key | Funktion | Business-IDs | Statushinweis |
|---|---|---|---|
| `gp.suggest` | GP-Namingfelder ableiten | ST-19–21 | direkt genutzt |
| `gp.condition` | Produktzustand bestimmen | ST-19 | direkt genutzt |
| `gp.allergene` | LMIV-Allergen-Vorschlag | ST-22, C-03–05 | direkt genutzt; Freigabe zwingend |
| `gp.naehrwerte` | Nährwert-Fallback schätzen | ST-23, C-03–05 | direkt genutzt; Schätzung kennzeichnen |
| `gp.domain` | Wissensdomain zuordnen | KN-04 | keine direkte `src/`-Referenz gefunden |
| `gp.piece_default_g` | Stückgewicht schätzen | ST-23 | statische Referenz vorhanden |
| `gp.zaehl_einheiten` | Zähleinheiten vorschlagen | ST-23 | keine direkte `src/`-Referenz gefunden |
| `gp.anker` | Aroma-Anker bestimmen | PA-01–03 | keine direkte `src/`-Referenz gefunden |
| `gp.role` | kulinarische Rolle bestimmen | RE-04, PA-01 | keine direkte `src/`-Referenz gefunden |
| `gp.la_suggest` | LA→GP-Zuordnung vorschlagen | ST-16–18 | keine direkte `src/`-Referenz gefunden |
| `gp.term_la_rank` | LA-Kandidaten ranken | ST-13–18 | keine direkte `src/`-Referenz gefunden |
| `gp.tags` | GP-Tags vorschlagen | ST-19–20 | direkt genutzt |

### 5.2 Basisrezepte

| Prompt-Key | Funktion | Business-IDs | Statushinweis |
|---|---|---|---|
| `recipe.generator` | vollständiges Basisrezept erzeugen | RE-01–10, RE-19 | direkt genutzt |
| `recipe.description` | Beschreibung erzeugen | RE-17 | direkt genutzt |
| `recipe.category` | Produktionskategorie vorschlagen | RE-03 | direkt genutzt; Kategorie team-scopen |
| `recipe.garverlust` | Garverlust schätzen | RE-07 | statische Referenz vorhanden |
| `recipe.name_putzen` | Namen normalisieren | RE-01, RE-11 | statische Referenz vorhanden |
| `recipe.titel_vorschlag` | nüchterner Basisrezept-Titel aus Brief (§1-Syntax) | RE-01, RE-11 | direkt genutzt (`TitelVorschlagService`) |
| `recipe.sektor` | Sektoreignung bewerten | RE-20 | statische Referenz vorhanden |
| `recipe.level` | Niveaueignung bewerten | RE-20 | statische Referenz vorhanden |
| `recipe.sub_typ` | Subrezepttyp bestimmen | RE-03 | keine direkte `src/`-Referenz gefunden |
| `recipe.production_depth` | Fertigungstiefe bestimmen | RE-20 | direkt genutzt |
| `recipe.preparation` | Produktionszubereitung schreiben | RE-01, RE-17 | direkt genutzt |
| `recipe.steps` | Zubereitung als Schrittfolge (Step-by-step, Spec 27) | RE-01, RE-17 | direkt genutzt |
| `recipe.eigenschaften` | Haltbarkeit und Eigenschaften | RE-20 | direkt genutzt |
| `recipe.geschmack` | Geschmacksprofil ableiten | RE-20, PA-01 | direkt genutzt |
| `recipe.sensorik` | Sensorik messen/ableiten | RE-20, PA-01 | direkt genutzt |
| `recipe.review` | Rezeptbefunde erzeugen | RE-20, QL-01 | dynamisch genutzt |
| `recipe.bauart` | Rezeptbauart klassifizieren | RE-20 | statische Referenz vorhanden |
| `recipe.pairing` | Pairing beurteilen | PA-01–02 | statische Referenz vorhanden |
| `recipe.anker` | Rezeptanker bestimmen | PA-01–02 | keine direkte `src/`-Referenz gefunden |
| `recipe.equipment` | Equipment vorschlagen | RE-20 | direkt genutzt |
| `recipe.ueberarbeiten` | Rezept per Freitext revidieren | RE-18 | direkt genutzt |
| `recipe.extract` | Rezept aus Text extrahieren | RE-24 | keine direkte `src/`-Referenz gefunden |

### 5.3 Verkaufsgerichte

| Prompt-Key | Funktion | Business-IDs | Statushinweis |
|---|---|---|---|
| `vk.generator` | vollständiges Verkaufsgericht erzeugen | RE-12–19 | direkt genutzt |
| `vk.speisen_klasse` | Gerichtsklasse vorschlagen | RE-14 | direkt genutzt; IDs team-scopen |
| `vk.rollen` | Komponentenrollen verteilen | RE-20, PA-01 | statische Referenz vorhanden |
| `vk.plating` | Anrichte-/Serviceanweisung | RE-15, RE-17 | direkt genutzt |
| `vk.name_putzen` | VK-Namen normalisieren | RE-12, RE-17 | keine direkte `src/`-Referenz gefunden |
| `vk.titel_vorschlag` | nüchterner Gericht-Titel aus Brief (§4.4-Pipe-Syntax) | RE-12, RE-11 | direkt genutzt (`TitelVorschlagService`) |
| `vk.marketing` | Marketing-/Kundentext | RE-17, FB-05 | direkt genutzt |
| `vk.wording` | kundengerechtes Wording | RE-17 | direkt genutzt |
| `vk.behaelter` | passenden Behälter vorschlagen | RE-15, AD-18 | direkt genutzt |
| `vk.regeneration` | Regeneration vorschlagen | RE-15, PR-04 | direkt genutzt |
| `vk.servier_vehikel` | Servierform vorschlagen | RE-15 | direkt genutzt |
| `vk.review` | Gerichtsbefunde erzeugen | RE-20, QL-01 | dynamisch genutzt |
| `vk.ueberarbeiten` | Gericht per Freitext revidieren | RE-18 | direkt genutzt |
| `vk.kohaerenz` | Gerichtskohärenz prüfen | RE-20, PA-01 | direkt genutzt |
| `vk.teller_heber` | gezielte Aufwertung vorschlagen | RE-20, PA-02 | direkt genutzt |

### 5.4 Konzepte, Foodbook, Preis und Signale

| Prompt-Key | Funktion | Business-IDs | Statushinweis |
|---|---|---|---|
| `concept.brief_geruest` | Brief in Konzeptgerüst übersetzen | CO-07 | direkt genutzt |
| `concept.plan` | kreative Konzept-Leitidee/Canvas aus Brief (KI-Kopf) | CO-01, FD-01–04 | direkt genutzt (`ConceptGeneratorService::planAusBrief`) |
| `concept.wording` | Konzepttext erzeugen | CO-01, FB-05 | direkt genutzt |
| `foodbook.kapitel_ideen` | Kapitelideen erzeugen | FB-07–10 | direkt genutzt |
| `foodbook.kundentext` | Kundentext erzeugen | FB-05, FB-10 | direkt genutzt |
| `price.plausi` | Preis plausibilisieren | WI-03–05 | dynamisch genutzt |
| `signal.supplier_inquiry` | Lieferantenanfrage formulieren | QL-02–03, ST-12 | dynamisch genutzt |
| `signal.margin_levers` | Margenhebel vorschlagen | QL-02–03, WI-04 | dynamisch genutzt |
| `signal.vk_release_advice` | Freigabehinweis formulieren | QL-02–03, FB-12 | dynamisch genutzt |
| `signal.serving_form_suggest` | Darreichung vorschlagen | QL-02–03, RE-15 | dynamisch genutzt |
| `signal.recipe_category_suggest` | Rezeptkategorie vorschlagen | QL-02–03, RE-03 | dynamisch genutzt |
| `signal.recipe_naming_suggest` | Rezeptnamen vorschlagen | QL-02–03, RE-11 | dynamisch genutzt |
| `trend.cluster_label` | gesichtete Trends in zweistufige Taxonomie einordnen/labeln | TR-01 | direkt genutzt (`TrendClusterCommand`) |
| `chat.message` | allgemeine Chatnachricht | kein freigegebener Business Case | keine direkte `src/`-Referenz gefunden |
| `demo.echo` | Provider-/JSON-Smoke | Betrieb/Test | technische Referenz vorhanden; kein Produktfeature |

### 5.5 Entscheidung für 11 statisch unreferenzierte Keys

Für `gp.domain`, `gp.zaehl_einheiten`, `gp.anker`, `gp.role`, `gp.la_suggest`,
`gp.term_la_rank`, `recipe.sub_typ`, `recipe.anker`, `recipe.extract`,
`vk.name_putzen` und `chat.message` gilt:

- dynamische Verwendung explizit nachweisen und testen,
- oder als geplante Capability mit Owner/Meilenstein markieren,
- oder aus Registry und Dokumentation entfernen.

„Vorsorglich registriert“ ist kein dauerhafter Status, weil ungenutzte Prompts
Wartungs-, Kosten- und Sicherheitsannahmen erzeugen.

### 5.6 Vector-Database-Capabilities

Der vollständige Migrations- und Betriebsplan steht unter `C-VECTOR` mit
VEC-01–14 in Plan 24. Für LLM und MCP gelten zusätzlich folgende
Capability-Verträge:

| Capability | heutiger Stand | Zielnachweis |
|---|---|---|
| GP-Pool | Observer/Backfill auf Core-Store vorhanden | neue Storegeneration, Shadow-Read, Golden Set |
| Rezept-Pool | Observer/Backfill auf Core-Store vorhanden | Embed-Text-Version und Recall für Beschreibung/Zubereitung |
| Lieferanten-Pool | Observer vorhanden | Tenantpartition und Suchqualität |
| Konzept-Pool | Observer vorhanden | Facetten-/Brief-Recall und Teamfilter |
| Foodbook-Pool | Observer vorhanden | Kundendaten-Minimierung und Teamfilter |
| Lab-Note-Pool | Observer vorhanden | R&D-Rechte und Retention |
| Wissens-Pool | semantischer Fallback vorhanden | Golden Set, Anker-Floor und Store-Fallback |
| Supplier-Item-Pool | durch Store-Skala blockiert | vollständiger LA-Backfill, Import-Observer, Recall@K und p95 |

Betroffene MCP-Reads sind insbesondere `artikel.SEARCH`, `gps.SEARCH`,
`recipes.SEARCH`, `concepts.SEARCH`, `knowledge.SEARCH`, `pairings.GET` und
`pairings.SUGGEST`. Für sie gilt:

- Vector-Treffer werden anschließend gegen MySQL und Tenantrechte rehydriert.
- Response nennt `via=lexical|semantic|hybrid` und optional den Score.
- Store-Ausfall fällt auf Lexik zurück und kennzeichnet den degraded Mode.
- Modell-, Dimensions- oder Indexgeneration-Mismatch wird nicht still als
  Nulltreffer behandelt.
- Suchlogs enthalten keine unnötigen sensiblen Volltexte.
- BC-12 prüft Last, Qualität, Löschung, Tenantleaks, Cutover und Rollback.

## 6. MCP-Domänenübersicht

| Domäne | Anzahl | Tool-Funktion | Business-IDs |
|---|---:|---|---|
| Angebote | 4 | lesen, listen, suchen, anlegen | FB-16–17 |
| Artikel/GPS/Lieferanten | 17 | Katalog, Matching, Lead, Volumen, Vereinbarungen | ST-01–25 |
| Rezepte/Verkaufsrezepte | 15 | CRUD, Suche, Generierung, Review, Zutaten, Klasse, Zubereitungsschritte | RE-01–24 |
| Konzepte/Planung/Canvas | 21 | CRUD, Slots, Varianten, Coverage, Phase, DNA, Planungs-Session, Kaskaden-Status/Start/Freigabe | CO-01–11, FD-01–04 |
| Foodbook/Leitstelle | 14 | Buch, Kapitel, Blöcke, Ideen, Freigabe, Zielgruppen | FB-01–17 |
| Speisekarte | 9 | Karte, Rubriken, Positionen, Duplikat, Suche, Leitstelle | noch ohne Business-IDs |
| Kalkulation/Assemblierung | 8 | Kalkulation, Simulation, Benchmark, Proportion, Solver | WI-01–13 |
| Produktion/Bestellung | 34 | Bedarf, Aufträge, Zeilen (Override/Zuteilung/Abhaken/Blockieren), Start/Finish, Status, Löschen, Dokumente, Bedarfsfreigabe, Einkaufs-Auswertungen, Rückvergütung | PR-01–04, OR-01–06 |
| Wissen/Pairing/R&D | 22 | Wissen, Kategorien, Routings, Aktiv-Schalten, Bindings, Pairing, Substitution, Lab Notes | KN-01–06, PA-01–06 |
| Qualität/Signale/Runs | 15 | Listen, Ursachen, Policy, Fix, Trend, Qualitätsläufe | QL-01–05 |
| Controlling/Verkaufs-Ist | 5 | Verkaufsjournal lesen, CSV-Import (Trockenlauf-Default), Menu-Engineering, Portfolio-Steuerung, Promotion-Umsatz | CT-08–12, PF-01–02 |
| Navigation/Settings/Favoriten | 5 | UI öffnen, Settings lesen, Favoriten lesen/schreiben | UX-05, RE-22, AD-03 |

Die Gruppensumme wird gegen die 169 Toolnamen automatisiert geprüft. Die Übersicht
ist fachlich gruppiert; der verbindliche technische Schlüssel ist immer der volle
Toolname im folgenden Register.

## 7. Vollständiges MCP-Register — 169 Tools

```text
foodalchemist.angebote.GET
foodalchemist.angebote.LIST
foodalchemist.angebote.POST
foodalchemist.angebote.SEARCH
foodalchemist.artikel.LIST
foodalchemist.artikel.SEARCH
foodalchemist.assemblierung.APPLY
foodalchemist.assemblierung.POST
foodalchemist.benchmark.GET
foodalchemist.bestellvorschlag.GET
foodalchemist.canvas.GET
foodalchemist.canvas.PUT
foodalchemist.concept_slot_variante.POST
foodalchemist.concept_slots.POST
foodalchemist.concepts.DELETE
foodalchemist.concepts.GENERATE
foodalchemist.concepts.GET
foodalchemist.concepts.LIST
foodalchemist.concepts.POST
foodalchemist.concepts.SEARCH
foodalchemist.coverage.GET
foodalchemist.dish.REVERSE
foodalchemist.einkauf_anomalien.GET
foodalchemist.einkauf_optimierung.GET
foodalchemist.einkauf_preisvergleich.GET
foodalchemist.einkauf_spend.GET
foodalchemist.einkaufsliste.GET
foodalchemist.favorites.GET
foodalchemist.favorites.PUT
foodalchemist.foodbook.GET
foodalchemist.foodbook_blocks.DELETE
foodalchemist.foodbook_blocks.POST
foodalchemist.foodbook_kapitel.POST
foodalchemist.foodbook_kapitel.PUT
foodalchemist.foodbooks.POST
foodalchemist.foodbooks.SEARCH
foodalchemist.gp_lead.GET
foodalchemist.gp_lead.PUT
foodalchemist.gp_proposals.POST
foodalchemist.gps.GET
foodalchemist.gps.LIST
foodalchemist.gps.MATCH
foodalchemist.gps.MINT_FROM_LA
foodalchemist.gps.SEARCH
foodalchemist.ingest.IMPORT
foodalchemist.ingest.STATUS
foodalchemist.kalkulation.GET
foodalchemist.kapitel_freigabe.GET
foodalchemist.kapitel_ideen.GET
foodalchemist.kapitel_ideen.POST
foodalchemist.kapitel_ideen.PUT
foodalchemist.knowledge.BIND
foodalchemist.knowledge.GET
foodalchemist.knowledge.HYPOTHESIZE
foodalchemist.knowledge.LIST
foodalchemist.knowledge.POST
foodalchemist.knowledge.PUT
foodalchemist.knowledge.SEARCH
foodalchemist.knowledge.SET_ACTIVE
foodalchemist.knowledge.UNBIND
foodalchemist.knowledge_categories.GET
foodalchemist.knowledge_categories.POST
foodalchemist.knowledge_routings.GET
foodalchemist.knowledge_routings.PUT
foodalchemist.lab_notes.POST
foodalchemist.lab_notes.SEARCH
foodalchemist.leitstelle.GET
foodalchemist.menu_engineering.GET
foodalchemist.orders.ADD_LINE
foodalchemist.orders.ADD_NEED
foodalchemist.orders.CREATE
foodalchemist.orders.GET
foodalchemist.orders.RESOURCE
foodalchemist.orders.SET_STATUS
foodalchemist.orders.UPDATE
foodalchemist.orders.UPDATE_LINE
foodalchemist.pairing_inspiration.GET
foodalchemist.pairings.GET
foodalchemist.pairings.SUGGEST
foodalchemist.phase.PUT
foodalchemist.planning.GET
foodalchemist.planning.PUT
foodalchemist.planung_kaskade.FREIGABE
foodalchemist.planung_kaskade.GET
foodalchemist.planung_kaskade.START
foodalchemist.planung_session.GET
foodalchemist.planung_session.POST
foodalchemist.planung_session.PUT
foodalchemist.portfolio.GET
foodalchemist.portfolio_promotion.GET
foodalchemist.process_anchors.GROUND
foodalchemist.production_orders.ADD_TARGET
foodalchemist.production_orders.DELETE
foodalchemist.production_orders.FINISH
foodalchemist.production_orders.GET
foodalchemist.production_orders.RELEASE_DEMAND
foodalchemist.production_orders.LINE_ASSIGN
foodalchemist.production_orders.LINE_BLOCK
foodalchemist.production_orders.LINE_OVERRIDE
foodalchemist.production_orders.LINE_STATUS
foodalchemist.production_orders.LINE_UNBLOCK
foodalchemist.production_orders.REMOVE_TARGET
foodalchemist.production_orders.SET_STATUS
foodalchemist.production_orders.START
foodalchemist.production_orders.UPDATE
foodalchemist.production_orders.UPDATE_LINE
foodalchemist.produktionsblatt.GET
foodalchemist.proportion.APPLY
foodalchemist.proportion.CALC
foodalchemist.quality_run.POST
foodalchemist.recipe_feedback.POST
foodalchemist.recipe_feedback.SEARCH
foodalchemist.recipe_findings.PUT
foodalchemist.recipe_findings.SEARCH
foodalchemist.recipe_findings_run.POST
foodalchemist.recipe_ingredients.PUT
foodalchemist.recipe_klasse.POST
foodalchemist.recipe_steps.GET
foodalchemist.recipe_steps.PUT
foodalchemist.recipes.GENERATE
  - Ein Tool für Basisrezepte und VK-Gerichte (`vk=true`).
  - Die Tool-API bleibt gemeinsam, die Wissens-/Agenten-Workflows sind getrennt: `workflow.rezept_anlegen_mcp` für Basisrezepte, `workflow.gericht_anlegen_mcp` für Gerichte/VK-Rezepte.
  - `voll_anreichern=true` füllt Text-/Klassifikations-Lücken.
  - `complete_coverage=true` ist nur mit `voll_anreichern=true` gültig und synchronisiert zusätzlich operative Detail-Bausteine: Fertigungstiefe, Arbeitszeit/Eigenschaften, Equipment, belastbar ableitbaren Default-Posten, Prozessanker, Step-by-step und Sensorik. Step-by-step und Sensorik werden dabei bewusst neu geschrieben, damit geänderte Rezepte/Gerichte wieder konsistent sind.
foodalchemist.recipes.GET
foodalchemist.recipes.LIST
foodalchemist.recipes.POST
foodalchemist.recipes.PUT
foodalchemist.recipes.REVIEW
foodalchemist.recipes.SEARCH
foodalchemist.reference.GET
foodalchemist.runs.GET
foodalchemist.sales_facts.GET
foodalchemist.sales_import.POST
foodalchemist.settings.GET
foodalchemist.signal_causes.GET
foodalchemist.signal_policies.GET
foodalchemist.signal_policy.PUT
foodalchemist.signal_trend.GET
foodalchemist.signale.FIX
foodalchemist.signale.LIST
foodalchemist.signale.PUT
foodalchemist.signale.SEARCH
foodalchemist.simulation.POST
foodalchemist.speisekarten.GET
foodalchemist.speisekarte_leitstelle.GET
foodalchemist.speisekarte_positionen.DELETE
foodalchemist.speisekarte_positionen.POST
foodalchemist.speisekarte_rubrik.POST
foodalchemist.speisekarte_rubrik.PUT
foodalchemist.speisekarten.DUPLICATE
foodalchemist.speisekarten.POST
foodalchemist.speisekarten.SEARCH
foodalchemist.speiseplaene.POST
foodalchemist.speiseplan_eintraege.POST
foodalchemist.substitution.SUGGEST
foodalchemist.supplier_agreements.POST
foodalchemist.supplier_rebate.GET
foodalchemist.supplier_rebate.PUT
foodalchemist.suppliers.GET
foodalchemist.suppliers.PUT
foodalchemist.suppliers.SEARCH
foodalchemist.suppliers.VOLUME
foodalchemist.surplus.SUGGEST
foodalchemist.terminology.LIST
foodalchemist.terminology.POST
foodalchemist.ui.OPEN
foodalchemist.verkaufsrezepte.LIST
foodalchemist.verkaufsrezepte.SEARCH
foodalchemist.vk_snapshots.GET
foodalchemist.vk_snapshots.RELEASE
foodalchemist.zielgruppen.GET
foodalchemist.zielgruppen.POST

# ── MCP-Steuerbarkeit 2026-08 (Phase 0 + D1) — additiv zur Alt-Liste ──
# (Programm: gesamter FA über MCP; Domäne für Domäne. Planungsebene ausgenommen.)
foodalchemist.team_settings.PUT
foodalchemist.gps.POST
foodalchemist.gps.PUT
foodalchemist.gps.STATUS
foodalchemist.gps.DELETE
foodalchemist.gps.REPLACE
foodalchemist.gps.ENRICH
foodalchemist.gp_enrich.RESOLVE
foodalchemist.gp_forms.PUT
foodalchemist.gp_forms.DELETE
foodalchemist.gp_forms.ESTIMATE
foodalchemist.gp_la.PUT
foodalchemist.component_equivalents.POST
foodalchemist.component_equivalents.DELETE
foodalchemist.platzhalter.POST
foodalchemist.platzhalter.PUT
foodalchemist.platzhalter.DELETE

# ── MCP-Steuerbarkeit D2 (Basisrezepte) 2026-08 ──
foodalchemist.recipes.DELETE
foodalchemist.recipes.STATUS
foodalchemist.recipes.DUPLICATE
foodalchemist.recipes.TEMPLATE_TOGGLE
foodalchemist.recipes.RECOMPUTE
foodalchemist.recipes.INSTANTIATE
foodalchemist.recipes.REVISE
foodalchemist.recipe_eignung.PUT
foodalchemist.recipe_anchors.PUT
foodalchemist.recipe_pairings.PUT
foodalchemist.recipe_sensorik.POST
foodalchemist.recipe_feedback.DELETE
foodalchemist.recipe_feedback.DEVELOP

# ── MCP-Steuerbarkeit D3 (Verkauf/Gerichte VK) 2026-08 ──
foodalchemist.verkaufsrezepte.GET
foodalchemist.verkaufsrezepte.POST
foodalchemist.verkaufsrezepte.PUT
foodalchemist.verkaufsrezepte.DELETE
foodalchemist.verkaufsrezepte.STATUS
foodalchemist.recipe_darreichung.POST
foodalchemist.recipe_darreichung.PUT
foodalchemist.recipe_darreichung.DELETE
foodalchemist.recipe_darreichung.STANDARD
foodalchemist.recipe_darreichung_delta.PUT
foodalchemist.recipe_regeneration.PUT
foodalchemist.recipe_regeneration.DELETE
foodalchemist.recipe_regeneration.REORDER
foodalchemist.recipe_customer_names.POST
foodalchemist.recipe_customer_names.DELETE
foodalchemist.recipe_rollen.POST
foodalchemist.recipe_coherence.POST
foodalchemist.verkaufsrezepte.REVISE

# ── MCP-Steuerbarkeit D4 (Lieferanten/Artikel/Geschirr) 2026-08 ──
foodalchemist.suppliers.POST
foodalchemist.suppliers.PUT
foodalchemist.suppliers.STATUS
foodalchemist.suppliers.DEACTIVATE
foodalchemist.supplier_conditions.PUT
foodalchemist.supplier_contacts.POST
foodalchemist.supplier_documents.POST
foodalchemist.artikel.POST
foodalchemist.artikel.DELETE
foodalchemist.artikel.DISCONTINUE
foodalchemist.artikel_allergene.PUT
foodalchemist.artikel_deklarationen.PUT
foodalchemist.artikel_naehrwerte.PUT
foodalchemist.artikel_preise.POST
foodalchemist.artikel_preise.DELETE
foodalchemist.artikel.PUT
foodalchemist.artikel_preise.PUT
foodalchemist.match.RUN
foodalchemist.match_proposals.PUT
foodalchemist.geschirr_suppliers.LIST
foodalchemist.geschirr_suppliers.POST
foodalchemist.geschirr_suppliers.PUT
foodalchemist.geschirr_suppliers.DEACTIVATE
foodalchemist.geschirr_items.LIST
foodalchemist.geschirr_items.POST
foodalchemist.geschirr_items.PUT
foodalchemist.geschirr_items.DEACTIVATE

# ── MCP-Steuerbarkeit D5 (Concepter/Concepts/Pakete) 2026-08 ──
foodalchemist.concepts.PUT
foodalchemist.concepts.STATUS
foodalchemist.concepts.DUPLICATE
foodalchemist.concepts.RECOMPUTE
foodalchemist.concepts.PRICE_TARGET
foodalchemist.concepts.SEKTOR
foodalchemist.concepts.TEMPLATE_SAVE
foodalchemist.concepts.TEMPLATE_FORK
# D5b: Slots/Blocks/Varianten/Paket (Editor-Parität)
foodalchemist.concept_slots.PUT
foodalchemist.concept_slots.DELETE
foodalchemist.concept_slots.REORDER
foodalchemist.concept_slots.GESCHIRR
foodalchemist.concept_slots.DARREICHUNG
foodalchemist.concept_blocks.POST
foodalchemist.concept_blocks.PUT
foodalchemist.concept_slot_variante.SWAP
foodalchemist.concept_slot_variante.RESET
foodalchemist.concept_paket.BUILD
# D5c: Konzept-Kategorien + Wording (W-Grounding cross_cutting) + Kohäsion-Read
foodalchemist.concept_categories.POST
foodalchemist.concept_categories.PUT
foodalchemist.concept_categories.DELETE
foodalchemist.concept_wording.GENERATE
foodalchemist.concepts.COHESION
# D5d: Pakete-Ressource (physische Pakete, spiegelt Livewire\Pakete\Index) + Positionen
foodalchemist.pakete.GET
foodalchemist.pakete.LIST
foodalchemist.pakete.SEARCH
foodalchemist.pakete.POST
foodalchemist.pakete.PUT
foodalchemist.pakete.DELETE
foodalchemist.pakete.DUPLICATE
foodalchemist.pakete.RECOMPUTE
foodalchemist.paket_gerichte.SET
foodalchemist.paket_gerichte.MENGE
foodalchemist.paket_gerichte.GESCHIRR
foodalchemist.paket_gerichte.REORDER
# D6: Format-Aufbau (Status + Slots/Blöcke + Bildwelt; Concept-Insert/Delete = format_editions.*; Binär-Upload deferred)
foodalchemist.formats.STATUS
foodalchemist.format_slots.REORDER
foodalchemist.format_slots.MOVE
foodalchemist.format_slots.WORDING
foodalchemist.format_blocks.POST
foodalchemist.format_blocks.PUT
foodalchemist.format_images.HERO
foodalchemist.format_images.CAPTION
foodalchemist.format_images.REORDER
foodalchemist.format_images.CLEAR
# D7: Foodbook-Vervollständigung (kein Buch-Delete; Struktur-Edits nur im Entwurf; Kundentext W-geerdet)
foodalchemist.foodbooks.LIST
foodalchemist.foodbooks.PUT
foodalchemist.foodbooks.STATUS
foodalchemist.foodbooks.BRANDING
foodalchemist.foodbooks.CUSTOMER_LINK
foodalchemist.foodbook_kapitel.DELETE
foodalchemist.foodbook_kapitel.REORDER
foodalchemist.foodbook_kapitel.MOVE
foodalchemist.foodbook_kapitel.WORDING_GENERATE
foodalchemist.foodbook_blocks.PUT
foodalchemist.foodbook_blocks.REORDER
foodalchemist.foodbook_blocks.VARIANT_GROUP
foodalchemist.foodbook.KUNDENTEXT_GENERATE
foodalchemist.foodbook_kapitel.KUNDENTEXT_GENERATE
# D8: Speisekarte-Vervollständigung (kein Karten-Delete; GET→Plural vereinheitlicht)
foodalchemist.speisekarten.LIST
foodalchemist.speisekarten.STATUS
foodalchemist.speisekarte.BRANDING
foodalchemist.speisekarte.CUSTOMER_LINK
foodalchemist.speisekarte_rubrik.DELETE
foodalchemist.speisekarte_rubrik.MOVE
foodalchemist.speisekarte_positionen.PUT
foodalchemist.speisekarte_wording.GENERATE
# D9: Speiseplan-Vervollständigung (Read-Lücke geschlossen; kein Plan-Delete; ANPRODUKTION → D11)
foodalchemist.speiseplaene.GET
foodalchemist.speiseplaene.LIST
foodalchemist.speiseplaene.PUT
foodalchemist.speiseplaene.STATUS
foodalchemist.speiseplan.BRANDING
foodalchemist.speiseplan.CUSTOMER_LINK
foodalchemist.speiseplan_linien.POST
foodalchemist.speiseplan_linien.PUT
foodalchemist.speiseplan_linien.DELETE
foodalchemist.speiseplan_linien.MOVE
foodalchemist.speiseplan_eintraege.DELETE
foodalchemist.speiseplan_eintraege.PAX
foodalchemist.speiseplan.AUSROLLEN
# D10: Angebote-Vervollständigung (DELETE bleibt — frühe Entwürfe; ANPRODUKTION → D11)
foodalchemist.angebote.PUT
foodalchemist.angebote.DELETE
foodalchemist.angebote.STATUS
foodalchemist.angebote.CUSTOMER_LINK
foodalchemist.angebote.RECOMPUTE
foodalchemist.angebot_menue.POST
foodalchemist.angebot_menue.PROMOTE
foodalchemist.angebot_menue.DELETE
foodalchemist.angebot_concept_ref.POST
foodalchemist.angebot_concept_ref.DELETE
# D11: Bestell-Belegfacetten + Zeilen-Ops + Versand (outward) + Produktionsplaner + Anproduktion
foodalchemist.orders.UPDATE_INVOICE
foodalchemist.orders.UPDATE_PAYMENT
foodalchemist.orders.UPDATE_APPROVAL
foodalchemist.orders.CONFIRM_SUPPLIER
foodalchemist.orders.RECEIPT
foodalchemist.orders.CLAIM
foodalchemist.orders.REMOVE_LINE
foodalchemist.orders.SWITCH_ARTICLE
foodalchemist.orders.DISPATCH
foodalchemist.production_plan.SUGGEST
foodalchemist.production_plan.APPLY
foodalchemist.speiseplan.ANPRODUKTION
foodalchemist.angebote.ANPRODUKTION
# D12: Knowledge-Löschen/Alias + Canvas-Einträge + Controlling + Trendradar + Präsentations-Designs
foodalchemist.knowledge.DELETE
foodalchemist.knowledge.ALIAS
foodalchemist.canvas.ENTRY_ADD
foodalchemist.canvas.ENTRY_REMOVE
foodalchemist.sales_facts.MAP
foodalchemist.trendradar.IMPORT
foodalchemist.presentation_designs.DUPLICATE
foodalchemist.presentation_designs.GENERATE_CSS
# D13: Vokabular/Taxonomie SAFE-additiv (POST/PUT/TOGGLE/REORDER, KEIN Delete; global/kanonisch read-only)
foodalchemist.vocab_einheiten.POST
foodalchemist.vocab_einheiten.PUT
foodalchemist.vocab_einheiten.TOGGLE
foodalchemist.vocab_warengruppen.POST
foodalchemist.vocab_warengruppen.PUT
foodalchemist.vocab_warengruppen.REORDER
foodalchemist.vocab_subkategorien.POST
foodalchemist.vocab_subkategorien.PUT
foodalchemist.vocab_subkategorien.REORDER
foodalchemist.vocab_recipe_maingroups.POST
foodalchemist.vocab_recipe_maingroups.PUT
foodalchemist.vocab_recipe_maingroups.REORDER
foodalchemist.vocab_dish_maingroups.POST
foodalchemist.vocab_dish_maingroups.PUT
foodalchemist.vocab_dish_maingroups.REORDER
```

## 8. MCP-Abnahmematrix

Jedes Tool erhält künftig im Testregister eine Zeile mit:

| Feld | Inhalt |
|---|---|
| Toolname | stabiler `foodalchemist.*`-Schlüssel |
| Klasse | Implementierung unter `src/Tools` |
| Operation | Read, Write, Delete, Generate oder Run |
| Business-IDs | betroffene Zeilen aus Matrix 25 |
| Fachservice | gemeinsame Web-/Tool-Wahrheit |
| Tenantfälle | own, sibling, parent, global, fehlender Kontext |
| Wiederholung | idempotent, Konflikt oder explizit nicht wiederholbar |
| Fehlervertrag | Code, Meldung und keine Teilpersistenz |
| Test | konkreter Testname |
| Status | registriert, technisch getestet, tenant-getestet, real validiert |

### Pflichtreihenfolge

1. Registry-Healthcheck für exakt 157 Namen.
2. Tools nach Risiko klassifizieren: Delete, Write, Generate/Run, Read.
3. alle Delete- und Write-Tools zuerst tenant-adversarial testen.
4. Read-Tools auf Datenlecks, Pagination und Querybudget prüfen.
5. Generate-/Run-Tools auf Budget, Idempotenz und Run-Status prüfen.
6. Web-/MCP-Parität in BC-01 bis BC-10 abnehmen.

## 9. Risikobasierte MCP-Priorität

### P0 — zuerst

- `foodbook_blocks.DELETE`,
- sämtliche `*.PUT`, `*.POST`, `*.UPDATE*`, `*.SET_STATUS`, `*.APPLY`,
  `*.FIX`, `*.BIND`, `*.UNBIND`, `*.HANDOVER`, `*.RELEASE`,
- `concepts.DELETE`,
- Tools mit frei übergebenen Kapitel-, Block-, Kategorie-, Klassen-,
  Dimensions-, Knowledge- oder Teamobjekt-IDs.

### P1 — danach

- `*.GET`, `*.LIST`, `*.SEARCH` mit Kunden-IP oder Vollmengen,
- `ingest.IMPORT`, `quality_run.POST`, `recipe_findings_run.POST`,
- Generatoren, Solver und semantische Suche,
- PDF-/Bestell-/Produktionsressourcen.

### P2 — vor Skalierung

- Querybudgets und Pagination,
- Schema-Versionierung und Deprecation,
- Tool-Kosten und Ausführungsmetriken,
- Capability-Dokumentation für externe Integratoren.

### Sondervertrag für die Solver-Tools

`foodalchemist.assemblierung.POST` und `foodalchemist.assemblierung.APPLY` teilen
den Solververtrag aus Plan 24:

- `POST` verändert nichts und liefert Lösung, Status (`optimal`, `feasible`,
  `timed_out`, `no_solution`), Engine, Budget, Laufzeit und Erklärung.
- `APPLY` übernimmt nur eine Lösung mit passendem Input-/Preis-/Constraint-Hash.
- Veraltete Vorschauen werden abgewiesen statt gegen neue Preise übernommen.
- Wiederholung desselben Apply ist idempotent oder liefert einen eindeutigen
  Konflikt mit der bereits erzeugten Konzept-ID.
- jedes erzeugte Ergebnis startet als Draft und behält Solverstatus und Herkunft.
- Web und MCP verwenden identische Kandidatenfilter, Zielfunktion und Erklärung.
- der Nachweis läuft über BC-11 sowie SOL-01 bis SOL-10.

## 10. Definition of Done für LLM-/MCP-Parität

Eine Business-Funktion mit Web-, LLM- oder MCP-Zugang ist erst fertig, wenn:

- alle Zugänge dieselben Fachservices und Invarianten verwenden,
- der Webpfad keinen Wert erlaubt, den MCP verbietet, und umgekehrt,
- LLM-Vorschläge denselben Freigabestatus wie UI-Vorschläge erhalten,
- Tenant- und Ownership-Fälle je Tool automatisiert sind,
- Prompt- und Toolschema versioniert beziehungsweise kompatibel bleiben,
- ein Provider- oder Toolfehler sichtbar und wiederaufnehmbar ist,
- Kosten und Laufzeit messbar sind,
- Business-Case-Matrix und Testnachweis aufeinander verlinken.
