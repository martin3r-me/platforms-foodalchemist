# KI-Erstell-/Verbesser-Flächen — Bestandsaufnahme + Lücken-Plan (Stand 2026-07-16)

> ## 🔍 Audit an HEAD `a55ced3` (2026-07-25) — verbindlicher Ist-Stand
> Code-verifiziert, überschreibt widersprechende Aussagen im Text unten:
>
> | Lücke | Ist | Beleg |
> |---|---|---|
> | **L1** | ✅ **KOMPLETT 2026-07-26** (L1a + L1b) | L1a: Revise in **beiden** Modals über den geteilten `RecipeReviseService`, Prompt `vk.ueberarbeiten`, Facetten-Erhalt zweistufig. L1b: `✨ Alles anreichern` im `VkModal` mit eigener Schrittfolge `SCHRITTE_VK` (`description|wording|plating|speisen_klasse`), Klassen-Accept durch `SpeisenKlassenService`, Lauf-Typ `enrich_vk`, Ebenen-Schutz zweifach |
> | **L2** | ✅ **KOMPLETT 2026-07-26** (L2a + L2b) | L2a: Prompt **`foodbook.kundentext`** in Registry + `FOOD_DNA_KEYS`, `FoodbookService::kiKundentextVorschlag`, Button `:226` scharf mit echter Vorschau-Stufe. L2b: Feld „Hinführung“ im Kapitel-Kopf + `kiKapitelKundentextVorschlag` (`ebene: 'kapitel'`) + Kapitel-Text erstmals in der Dokument-Projektion (`dokumentDaten` → PDF + Präsentation) + `foodbook_kapitel_ohne_text` scharf (Spec 21 Tranche D, `info`) |
> | **L3** | ✅ **via Spec 19** | Kickoff in `Foodbooks/Index`, `PlanningFrameService`, `FoodbookService` + `ConceptGeneratorService::generiereAusBrief`. Kein eigener L3-Bau mehr — nur noch Bau-Referenz |
> | **L4** | ⚪ offen | nur manueller `fillSlot` (`Concepts/Index.php:256/262`, `ConceptSlotsPostTool:94`); `zielpreisBerechnen`/`zielVorschlag` im Concepter-Editor sind **Preis**-Vorschlag, nicht Slot-Inhalt |
> | **L5** | ⚪ offen | `src/Tools/` hat `ConceptsGenerateTool`, aber **kein** `RecipesGenerateTool` |
> | **L6** | ✅ **komplett** — L6a Service+MCP (`3c0ed84`), L6b UI in beiden Modals | `RecipeReviewService` + `RecipesReviewTool` + `Livewire/Concerns/HatRezeptCopilot` + `components/copilot-box.blade.php`; Prompts `recipe.review`/`vk.review` haben ihren Konsumenten. Offen: Browser-Klickstrecke (manuell), Befund-Ablage → V-031 (Voraussetzung für 21·S5) |
> | **L7** | ⚪ offen | kein `voll_anreichern`/`vollAnreichern`/`oneShot` im Modul |
> | **L8** | ⚪ offen | `MargeService` genutzt in SalesRecipeService/PaketService/MargeImpactService/SignalDetektorService — in **keinem** Generator; `target_food_cost_pct` liegt in `FoodAlchemistTeamSetting:44` bereit |
>
> **Blocker-Reset:** „Qualitäts-Gate braucht Key live" ist hinfällig — LLM-Key + Deploy sind self-service. Bau-Reihenfolge jetzt nach Größe in [_Fahrplan_Routine_Umsetzung.md](_Fahrplan_Routine_Umsetzung.md).

> **Auftrag (Notiz Dominique):** „Rezept Erstellung/Verbesserung … Concepter vorhanden? … Foodbook KI-Button Erstellung vorhanden?" — Board geprüft + Code kartiert. Ergebnis: **mehr vorhanden als erinnert**, aber 5 klare Lücken.
> **Quellen:** Dev-Board 53/54 (Suche Generator/Foodbook/KI), Code-Kartierung platforms-foodalchemist HEAD 2026-07-16 (alle Fundstellen verifiziert), Issues #369/#492/#505/#508.

---

## ⏫ Update 2026-07-17 — Fundament verschoben (#508 done, #507 E1–E5 gebaut)

Seit der Erstfassung ist gebaut + gepusht (`main` `9c1bae2`+`ebc1aa4`), das ändert den Zuschnitt mehrerer Lücken:

- **#508 (Revise-Grounding) = DONE** — `RecipeService::syncIngredients` groundet KI-Zutaten jetzt zentral (gp_v2_fk/recipe_ref statt `unmatched`) + `RecipeModal::matchVorschau` liefert die Hard-Stop-**Vorschau** (matched/grounded/hardstop). → **L1 + L6 bauen jetzt AUF dieser Strecke** (nicht mehr „warten auf #508"): VK-Revise (L1) und Copilot-„fehlt"-Matching (L6) rufen dieselbe, bereits geshippte `syncIngredients`-Grounding-Mechanik. Der Hard-Stop-Vorschau-Baustein aus dem Revise ist die Kopiervorlage.
- **#507 E1–E5 (semantischer Hybrid-Layer) = GEBAUT** — `SemanticRetrievalService` sitzt additiv in `IngredientMatchService::candidatesFor`. → hebt das Grounding **aller** Generator-/Revise-/Copilot-Flächen automatisch (bessere Reuse-Kandidaten), sobald der Flag auf demo an ist (E6/Martin). Nicht mehr „paralleler Blocker", sondern **vorhandenes Fundament**. Für L7 (One-Shot) ist der semantische Reuse-Pass jetzt ein fertiger Baustein neben #505 Slice 1+2.
- **Neue verwandte Spec: [06_Convenience_Highlights_GP_Liste.md](06_Convenience_Highlights_GP_Liste.md)** — opt-in-Generierungs-Modus „bevorzugt aus meiner Convenience-GP-Liste", landet an **denselben Generator-Modals** wie L7/L8 (Rezept + Konzept). Beim One-Shot-Umbau (L7) mitdenken: der Convenience-Toggle ist ein zusätzlicher, gegenläufiger Grounding-Input (verengt statt erweitert) — Default aus.

**Neuer Quer-Gap (2026-07-18): [07_LA_First_GP_Mint_ueberall.md](07_LA_First_GP_Mint_ueberall.md)** — der LA-First-GP-Mint (`versucheLaZuGp`, #505 Slice 2) ist `private` im Generator eingesperrt; alle anderen Pfade (Revise/E3, `gps.MATCH`, MCP) dead-enden bei GP-Lücken. **L7 (One-Shot) braucht den überall-verfügbaren Mint**, sonst bricht die Kaskade an jeder fehlenden Zutat. Doktrin: kein GP ohne LA — Mint IST LA-belegt (kein Guardrail-Bruch); Staging-Proposals = Sourcing-Wunsch, kein GP-Staging.

**Reihenfolge-Effekt:** die #508-Vorbedingung entfällt (erledigt). Empfohlener Start unverändert L5+L2 → dann **L1+L6** (Strecke steht bereits, reine Portierung) → **L7+L8** (setzt den 07-Mint voraus) → L4 → L3.

---

## 1. Bestandsaufnahme — was EXISTIERT (die Antwort auf die Notiz)

| Fläche | Status | Einstieg (Code-Beleg) |
|---|---|---|
| **Basisrezept per KI erstellen** | ✅ vorhanden | Rezept-Browser `✨ KI-Rezept` (`recipes/browser.blade.php:123`) → `GeneratorModal` → `RecipeGeneratorService::generiere()`. Input: Pflicht-Beschreibung + Richtungs-Pills (convenience/frische/bestand-Modus, bio, level, sektor, harte Diäten, Aroma) |
| **VK-Gericht per KI erstellen** | ✅ vorhanden | Verkaufs-Browser `✨ KI-Rezept` (`verkauf/browser.blade.php:109`) → `VkGeneratorModal` (`vkModus: true`, Accept setzt `is_sales_recipe` + Klasse/AK) |
| **Basisrezept per KI verbessern** | ✅ vorhanden | RecipeModal `✨ KI-Überarbeiten` mit Freitext-Anweisung (`recipe-modal.blade.php:151-161`, Prompt `recipe.ueberarbeiten`) + `✨ Alles anreichern` (Einzelrezept, `:15`) + `✨ Bulk anreichern` (Browser `:161`, `BulkEnrichService`) — ✅ **#508 gefixt (2026-07-17):** Revise groundet neue Zutaten jetzt (`syncIngredients` zentral) + Hard-Stop-Vorschau (`matchVorschau`) |
| **Concepter: Konzept aus Brief** | ✅ vorhanden | Concepts-Browser `✨ Konzept aus Brief` (`concepts/index.blade.php:59`) → `ConceptGeneratorService::generiereAusBrief()` — R6.1, gebaut 2026-07-13 (#492, Blindtest offen) |
| **Concepter: Konzept aus Gerüst** | ✅ vorhanden | Foodbook-Editor `✨ Konzept aus diesem Gerüst generieren` (`foodbooks/index.blade.php:189`) → `generiereAusGeruest()`, läuft OHNE LLM (deterministisch) |
| **Concepter: Wording per KI** | ✅ vorhanden | Concepter-Editor `✨ Wording` (`concepter/editor.blade.php:167`, Prompt `concept.wording` — Brand-Voice-Namen + Intro) |
| **Foodbook: KI-Kundentext je Block** | ✅ vorhanden | `✨`-Button je Concept-Block (`foodbooks/index.blade.php:365`, Prompt `vk.marketing`) |
| **MCP: Konzept-Generierung** | ✅ vorhanden | `foodalchemist.concepts.GENERATE` (`ConceptsGenerateTool`) |
| Feldweise KI (Umfeld, ~15 Buttons) | ✅ vorhanden | GP-Builder/Anreicherung/Allergen-Nährwert-Schätzung, VK-Klassifikator/Eignung/Pairing/Plating/Sensorik, Garverlust-Vorschlag, LA→GP-Mapping, ReviewQueue-Bulk |

**Kurzantwort auf die Notiz:** Rezept-Erstellung ✅ (Basis + VK, je eigener Generator-Button) · Rezept-Verbesserung ✅ (nur Basisrezept) · Concepter ✅ (Brief + Gerüst + Wording) · Foodbook-KI-Button **teils** (Kundentext ja, Kapitel-Text-Button existiert aber DISABLED, kein Erstell-Flow).

---

## 2. Die 5 Lücken (verifiziert)

| # | Lücke | Beleg | Board-Status |
|---|---|---|---|
| **L1** | **VK-Gericht „KI-Überarbeiten" fehlt komplett** — kein Freitext-Revise im `VkModal` (grep `ueberarbeit` in `Livewire/Verkauf` + Blades = 0 Treffer), auch kein „Alles anreichern"-Button dort; nur feldweise `ki()`-Aktionen | `VkModal.php` | ❌ kein Issue |
| **L2** | **Foodbook Kapitel-Text-KI deaktiviert** — Button `✨ KI-Text (folgt)` ist `disabled` (`foodbooks/index.blade.php:143`, „M11-08, LLM offen") | Blade | ✅ getrackt in **#369** („OFFEN — extern blockiert: KI-Text-Befüllung, LLM-Key") |
| **L3** | **Kein „Foodbook per KI erstellen"-Flow** — Foodbook nur manuell (`Foodbooks/Index.php::neu()`); KI kann Konzepte generieren, aber kein Kapitel-Gerüst/Gesamt-Foodbook aus Brief | `FoodbookService` ohne AiGateway | ❌ kein Issue |
| **L4** | **Concepter-Editor: keine KI-Slot-Füllung** — `fillSlot()` ist reiner manueller Picker; die deterministische Slot-Auswahl-Logik existiert im `ConceptGeneratorService`, ist aber im Editor je Slot nicht abrufbar („Schlag mir für diesen Slot was vor") | `Concepter/Editor.php` | ❌ kein Issue |
| **L5** | **MCP-Lockstep-Lücke: kein `recipes.GENERATE`** — `RecipeGeneratorService::generiere()` hängt NUR an den beiden UI-Modals; der Concepter hat sein MCP-Tool, der Rezept-Generator nicht (in #505 als „künftiges recipes.GENERATE" bereits benannt) | `src/Tools/` | ⚠️ nur als Nebensatz in #505 |
| **L6** | **Rezept-Copilot (proaktiver Verbesserungs-Prüf-Pass) fehlt komplett** — die CJ-App hat `ai_review_recipe` (Button „🧑‍🍳 Copilot", `commands.rs:15122`, Modal `RecipeReviewModal.tsx`): read-only Findings-Pass mit Kategorien `menge/einheit/entfernen/fehlt/hinweis` + Confidence + `gesamt_urteil`; „fehlt"-Vorschläge werden SOFORT gegen GP-/Sub-Pool gematcht (`auto_applicable` nur wenn matched); 1-Klick-Übernahme je Finding + „Alle übernehmen", nach jeder Übernahme `recompute_and_propagate`. Existiert in CJ für Basis UND VK (eigener VK-Prompt: Portion/Komposition/Klasse/Plating/Anlass). **In FA: 0 Pendant** — nur Freitext-Revise (der re-generiert alles) | CJ `commands.rs:15040-15412` | ❌ kein Issue |
| **L7** | **One-Shot-Vollerstellung fehlt** (präzisiert Dominique 2026-07-16): „Erstell mir ein Rezept" soll in EINEM Durchlauf ein **volles Rezept** liefern — Rezeptur komplett auf reale GPs/Artikel geerdet UND **voll angereichert** (Beschreibung, Zubereitung, Eigenschaften, Garverluste, Anker/Pairing, Sensorik, Sektor/Niveau, Klassifikation). Heute endet der Generator beim geerdeten Zutaten-Gerüst + Aggregation; Anreicherung ist ein SEPARATER manueller Klick („Alles anreichern") — in CJ genauso (manuelle 13-Schritt-CTA). Das Feature ist also in BEIDEN Systemen offen — FA kann es zuerst haben, alle Bausteine existieren | Kartierung 2026-07-16 | ❌ kein Issue (Teil von #512) |
| **L8** | **Wirtschaftlichkeits-Glied (R2) in der KI-Kaskade** (Nachtrag Dominique 2026-07-16: „ohne das wird der KI-Knopf schwierig"): Ein per KI erstelltes VK-Gericht muss die Kaskade bis zum **Preis** durchlaufen — heute endet sie vor der Wirtschaftlichkeit. Auto-VK braucht Portionsgröße (`quantity_per_unit_g`) + Aufschlagsklasse + Standard-Darreichung (R1.2-Mechanik: Cost-plus via MargeService); setzt der Generator diese nicht, bleibt das Gericht unbepreist + margen-blind → der „volle" One-Shot ist wirtschaftlich leer. Die R2-Maschine existiert (R2.1 Preis-Alarm ✅, R2.2 Simulation ✅, R2.6 Feedback ✅, R2.7 Benchmark ✅) — sie muss ans KI-Erzeugnis ANGESCHLOSSEN werden | ROADMAP R2 + R1.2 | ❌ kein Issue (Teil von #512) |

---

## 3. Plan je Lücke

### L1 — VK-Revise: „KI-Überarbeiten" + „Alles anreichern" ins VkModal · Größe M · **empfohlene Prio 1**

Das Basisrezept-Muster existiert komplett (`RecipeModal::kiUeberarbeiten`/`ueberarbeitungUebernehmen`) — Portierung, keine Neuentwicklung. ABER: **im Verbund mit #508 bauen**, nicht davor — sonst portieren wir den Grounding-Fehler (Revise persistiert `unmatched`) auf eine zweite Fläche.

**DoD:**
- [x] **L1a ✅ 2026-07-26:** `VkModal`: `✨ KI-Überarbeiten` mit Freitext-Anweisung + eigener Prompt `vk.ueberarbeiten` (Tier A, 8000 Tokens, in `FOOD_DNA_KEYS`). **Facetten-Erhalt zweistufig, nicht einstufig:** Klasse/Diätform/Darreichungs-Formen/Verkaufseinheit/Portion/Aufschlagsklasse gehen als *Vorgabe* in den Prompt (mit der Anweisung, sie nicht auszugeben und Widersprüche in `aenderungs_notiz` zu melden) **und** der Schreibpfad kennt sie gar nicht. Nur den Schreib-Umfang zu beschränken hätte „mach es vegan" formal geschützt und inhaltlich unterlaufen
- [x] **L1a ✅:** Revidierte Zutaten laufen durch den #508-Pfad — und die Strecke ist **extrahiert statt kopiert**: neuer `src/Services/RecipeReviseService.php` (`vorschau()` + `syncZeilen()`), beide Modals fahren ihn, `RecipeModal::matchVorschau` bleibt Durchgriff. Wörtliche „Portierung" hätte 65 Zeilen dupliziert und den #508-Fix auf zwei Orte verteilt
- [x] **L1b ✅ 2026-07-26 (`b693a8a`):** `✨ Alles anreichern` im VkModal — mit eigener Schrittfolge `BulkEnrichService::SCHRITTE_VK = description|wording|plating|speisen_klasse`. **Die Spec-Annahme „nur Button/Wiring" war falsch und ist korrigiert:** `SCHRITTE` = `description|category|geschmack` ist die Basisrezept-Ebene (`category` = 186er-Rezept-Kategorie). Der Klassen-Schritt geht durch `SpeisenKlassenService::classify`/`acceptKlasse` statt eigener Logik (Taxonomie + Aktiv-Filter + Besitzer-Regel D1 + Accept-Stempel liegen dort). Ebenen-Schutz zweifach (`starteVk()` schneidet auf `->verkauf()`, `proposeFeld` wirft bei den `NUR_GERICHT`-Schritten auf einem Basisrezept). Lauf-Typ `enrich_vk` statt `enrich`. `tests/Feature/VkBulkEnrichTest.php` (7 Tests): Schritt-Menge · Accept mit Lineage-Trio je Feld · Override-First (Wording **und** Klasse) · beide Ebenen-Schutz-Wege · Modal-Roundtrip. **MCP-Lockstep: kein Tool** — die ganze Bulk-Proposal-Fläche hat heute keinen (weder Rezept- noch GP-Seite), das ist eine eigene Fähigkeit und nicht L1b; kein Schema-Change (nur neue Werte in `bulk_proposals.field` / `bulk_runs.type`)
  - **Nebenbefund → V-029:** die Review-Liste rendert `bulk_proposals.value` roh — bei `category` (bare ID) steht dort „category · 42" und der Reviewer entscheidet über eine Zahl. `speisen_klasse` legt deshalb `{dish_class_id, klasse_name}` ab; damit liegen drei Wert-Formen in einer Spalte
  - **Zweiter Fundort für V-028:** die ✨-Einzelknöpfe (`ki('wording')`/`ki('plating')`) schreiben in `$form` → beim Speichern stempelt `updateVk` diese Felder auf `manual` und blockt damit per Override-First jeden späteren KI-Accept auf dem Feld. Genau deshalb hat der Bulk-Weg eine eigene Proposal-Strecke statt den Einzelknopf zu fahren
- [x] **L1a ✅:** Vorschau + explizites Übernehmen, Lineage `ki` je Feld (`description_source`/`plating_source`/`sales_wording_source`), `manual` gewinnt (Override-First), nie Auto-Persistenz
- [x] **L1a ✅:** `tests/Feature/VkReviseTest.php` (6 Tests): Revise-Roundtrip + Facetten-Erhalt (schickt Facetten-Felder bewusst MIT und beweist, dass keins ankommt) + Override-First + leere Anweisung + Vorschau-ohne-Persistenz + Render. **MCP-Lockstep: kein Tool, bewusst** — ein Revise-Tool wäre entweder Auto-Persistenz (gibt den Vorschau-/Übernehmen-Schnitt und damit GL-07 auf) oder eine Vorschau, die kein Client übernehmen kann (Accept-Zustand lebt in der Livewire-Komponente). Der schreibende Weg existiert granular: `recipe_ingredients.PUT` + `recipes.PUT`
- **Nebenbefund L1a → V-027:** der Alt-Revise nullte beim Übernehmen `role`/`is_value_relevant`/`quantity_max`/`trimming_loss_pct`/`cooking_loss_source` (`syncIngredients` baut `$attrs` bei UPDATE wie bei INSERT aus dem Payload). Im neuen Service per Original-Fallback geschlossen — die Wurzel trifft als nächstes **L6** (granularer Copilot-Apply braucht per Definition ein Teil-Update)

### L2 — Foodbook Kapitel-Text-KI scharfstellen · Größe S · hängt an: LLM-Provider (lokal geht sofort)

Bereits in **#369** getrackt — kein neues Issue. Der Block „LLM-Key" ist inzwischen weicher: `AiGatewayService` + Prompt-Registry existieren, lokal läuft ein Provider; nur demo hängt an Martin (#499).

**DoD (als #369-Nachtrag):**
- [x] Prompt **`foodbook.kundentext`** (Kontext: Gliederung über die Wording-Kette + Roh-Briefing + Leitplanken; Brand Voice über `FOOD_DNA_KEYS`). **Umbenannt gegenüber der Spec-Zeile:** ein Key für beide Ebenen (`ebene: foodbook|kapitel`) — der Auftrag ist derselbe in anderem Zuschnitt, und zwei Keys hätten die Tonalität an zwei Orten definiert (vgl. V-004: Prompt-Keys, die nur registriert sind, verrotten)
- [x] Button enabled, graceful bei fehlendem Provider (KiDeaktiviert/KiNichtVerfuegbar → Hinweiszeile, Feld unverändert; Test dafür)
- [x] Vorschau + Übernehmen — Überschreib-Schutz als **eigene Stufe**: der Vorschlag lebt in `kiTextVorschau` und berührt `form.description` nie; „Ersetzen" (statt „Übernehmen") + Warnzeile, wenn schon Text im Feld steht. *(Ein `notizen_manual`-Gegenstück gibt es an `foodbooks`/`foodbook_chapters` nicht — der Schutz ist deshalb der Ablauf, nicht ein Feld.)*
- [x] **L2b ✅ 2026-07-26:** dasselbe an der Kapitel-Ebene — Feld „Hinführung“ im Kapitel-Kopf (`foodbook_chapters.description`; `KAPITEL_FELDER` konnte es längst, der Editor kannte nur title/consumer_title/price_mode) + `FoodbookService::kiKapitelKundentextVorschlag` über denselben Prompt mit `ebene: 'kapitel'` (Gliederung auf DIESES Kapitel geschnitten, Buch-Briefing getrennt als `rahmen_einleitung`, damit die Hinführung die Einleitung nicht wiederholt) + **ein** geteilter Vorschau-Zustand für beide Flächen (`kiTextZiel` routet das Übernehmen; Kapitel-Wechsel verwirft den Vorschlag). **Nachtrag über die Spec-Zeile hinaus, ohne den nichts davon ankommt:** `description` wurde von **keiner** Projektion gelesen — `dokumentDaten` gab es nicht heraus, PDF und Präsentation druckten es nie. Ohne diesen Nachzug hätte L2b ein Feld gebaut, das der Kunde nicht sieht, und das Signal hätte eine wirkungslose Lücke gemeldet. Jetzt `'text'` in der Kapitel-Zeile + Ausgabe in `dokumente/foodbook.blade.php` und `foodbooks/praesentation.blade.php`. Der verwandte Fall `claim` bleibt offen → **V-025**
- [x] **`foodbook_kapitel_ohne_text` scharf** (Spec 21 Tranche D, fünfter Typ): befülltes Kapitel ohne Kundentext, Severity `info`, Arbeitsmenge `foodbooksInGebrauch` — schließt sich mit `foodbook_kapitel_leer` gegenseitig aus (dort fehlt Inhalt, nicht Text)

### L3 — „Foodbook aus Brief" (Gesamt-Flow) · Größe L · **Entscheid Dominique: Scope**

Der große Hebel, aber KEIN Neubau — Komposition existierender Teile: Brief → (R4.1) Planungs-Gerüst → Kapitel-Struktur-Vorschlag → je Kapitel `generiereAusGeruest`/`generiereAusBrief` (R6.1) → Foodbook-Draft mit Coverage-Ampel (R4.2) dran.

> ⚠️ **L3-v1 revidiert 2026-07-23 durch Spec [19](19_Foodbook_Leitstelle_A-Z.md):** Der „Foodbook aus Brief"-Flow wird nicht mehr als eigenständiger L3-Sonderweg gebaut, sondern **fällt mit dem Foodbook-Cockpit-Kickoff-Wizard (Brief → KI-Gerüst) + Kapitel-Go zusammen**. Der Kickoff erzeugt das Kapitel-Gerüst; die Kapitel-Anlage (E7) erdet je Kapitel Konzepte/Einzelgerichte. L3 bleibt als **Bau-Referenz** (Brief-Sanitizing, „keine Erfindungen in der Auswahl") bestehen, aber der UI-Einstieg lebt im Cockpit, nicht in einem separaten „✨ Foodbook aus Brief"-Button. Neu ist die **Duality**: ein Kapitel trägt 0–n Paket-Konzepte **und** 0–n Einzel-`recipe_ref`-Blöcke (nicht mehr „je Kapitel genau ein Konzept").

**✅ ENTSCHIEDEN (Dominique 2026-07-18): v1 schlank** — Brief → Kapitel-Baum + je Kapitel ein generiertes Konzept, Status draft, Coverage sofort sichtbar; Kapitel-Texte via L2 nachziehen (kein Voll-Flow in v1). Erst nach R6.1-Blindtest (#492) starten — der validiert die Konzept-Qualität, auf der dieser Flow aufbaut. *(→ siehe Revision oben: Einstieg über Cockpit-Kickoff.)*

**DoD (v1):**
- [ ] Foodbook-Browser: `✨ Foodbook aus Brief` → Brief-Modal (wie Concepts-Browser)
- [ ] KI baut NUR Gerüst/Kapitel-Struktur (sanitized, wie `concept.brief_geruest`); Gericht-Auswahl bleibt deterministisch (R6.1-Prinzip „keine Erfindungen")
- [ ] Ergebnis: Foodbook draft + `created_via`, Kapitel + Konzepte verlinkt, Coverage-Panel zeigt ehrlich Lücken
- [ ] Blindtest-Kriterium analog #492: 1 echter Kunden-Brief → „mit Anpassung verwendbar"

### L4 — Concepter-Editor: KI-Slot-Vorschlag · Größe S–M

Deterministische Wiederverwendung: die Slot-Ranking-Logik aus `ConceptGeneratorService` (Slot-Semantik → Pairing-Kanten → Anker-Dichte → Preis-Nähe) als per-Slot-Button im Editor.

**DoD:**
- [ ] `✨ Vorschlag` je leerem Slot → Top-3-Kandidaten mit Begründung (Ranking-Faktoren sichtbar), Übernahme per Klick
- [ ] Läuft OHNE LLM (deterministisch, wie Gerüst-Pfad) — kein Provider-Blocker
- [ ] Respektiert Gerüst-Regeln des Konzepts (No-Gos/Diät/Preisrahmen), Slot ohne zulässigen Treffer sagt es ehrlich
- [ ] Pest: bekannter Fixture-Fall rankt erwartungsgemäß

### L5 — MCP `recipes.GENERATE` · Größe S · Lockstep-Schuld aus #505

- [ ] `RecipeGenerateTool` (`foodalchemist.recipes.GENERATE`): Beschreibung + Parameter (wie GeneratorModal-Pills) → Draft-Rezept via `RecipeGeneratorService`; `created_via=mcp`, immer draft, Kohärenz-/Grounding-Verhalten identisch zur UI
- [ ] VK-Modus als Parameter (`vk: true`) statt zweitem Tool
- [ ] Tool-Description mit Grounding-Hinweis (gps.MATCH-Pflicht bleibt beim LLM-Client für manuelle Wege)
- [ ] Pest + Cross-Team-Negativtest (Tenancy-Muster #504)

---

### L6 — Rezept-Copilot (Verbesserungs-Prüf-Pass) portieren · Größe M–L · **Kern-Nachtrag Dominique 2026-07-16**

Die CJ-Referenz ist vollständig kartiert — Portierung nach bekanntem Muster (wie Revise/Generator), kein Neudesign:

**CJ-Soll-Verhalten (verifiziert):**
- Read-only Analyse-Call (`json_mode`, temp 0.2): Rezept + Zutaten + Zubereitung + Layer-Stack; VK-Zweig mit eigenem Prompt (Portion/Komposition/Speisen-Klasse/Plating/Anlass), Basis-Prompt = „Sous-Chef" (Mengen/Einheiten/fehlende Schlüsselkomponenten Säure-Salz-Fett-Bindung/Überflüssiges)
- Output: `gesamt_urteil` + Findings-Liste, je Finding `art ∈ menge|einheit|entfernen|fehlt|hinweis` + Begründung + Confidence
- **„fehlt" wird sofort gematcht** (IngredientMatchService-Pendant) → `auto_applicable` nur bei Match; `no_match` → Hinweis „erst GP anlegen" (Hard-Stop-Prinzip, kein Raten)
- Übernahme granular je Finding (UPDATE/DELETE/INSERT) + „Alle übernehmen" (nur auto_applicable), **nach jeder Übernahme Recompute+Propagation**

**FA-Umsetzung (DoD):**
- [x] `RecipeReviewService` ✅ **L6a** — Prompt-Keys sind die **reservierten** `recipe.review` / `vk.review` (nicht `recipe.copilot*`: die Registry führte sie seit Anlage, ein zweites Key-Paar hätte vier Keys für zwei Fähigkeiten bedeutet); ihr Vertrag war `{befunde:[{schwere,text}]}` und wurde durch den Befund-Vertrag ersetzt. Kontext bewusst sparsam: Rezept + Zutaten + Zubereitung (+ VK-Facetten als Massstab), **kein** Pairing/Vault
- [x] Button `🧑‍🍳 Copilot` in RecipeModal UND VkModal ✅ **L6b** — beide Flächen im selben Commit; `data-copilot` / `data-vk-copilot` im Zutaten-Sektionskopf neben `✨ KI-Überarbeiten`
- [x] **L6b** Findings-UI ✅ — `resources/views/components/copilot-box.blade.php` (EINE anonyme Komponente für beide Modals, nur `prefix`/`zeilenWort` unterscheiden sich) + `Livewire/Concerns/HatRezeptCopilot` (EIN Trait statt zweier Kopien — die Ebenen-Wahl fällt im Service über `is_sales_recipe`). Karten mit art-Farbe/Konfidenz/Begründung, Einzel-Apply, „Alle übernehmen" nur `auto_applicable`, nicht anwendbare Befunde bleiben **sichtbar** mit dem WARUM (Hard-Stop-Zeile „erst GP anlegen"/„erst Basisrezept (Stub) anlegen"). Das `fehlt`-Matching liegt unverändert im Service (L6a). **Neu gegenüber der Spec:** nach jedem Apply werden die übrigen Befunde über `RecipeReviewService::bewerte()` neu bewertet (ohne KI-Call) — ein `entfernen` macht die Zielzeile eines anderen Befunds ungültig, dessen Knopf darf nicht grün bleiben
- [x] Jede Übernahme → `recomputeAndPropagate` ✅ **L6a** (hängt am `syncIngredients`-Pfad, deshalb geht der volle Bestand mit — V-027). Die #511-Event-Kette ✅ **L6b** (`zutatenVersion`++ + `recipe-gespeichert` je Apply)
- [x] Lineage ✅ (L6a/L6b): Call-Audit über das Gateway (`ai_call_log`) beim Prüf-Pass. **Einschränkung, bewusst:** eine übernommene Zutaten-Zeile trägt keine `*_source='ki'`-Spalte — die gibt es an `recipe_ingredients` nicht (nur an den Text-/Klassifikations-Feldern des Rezepts). Wer „diese Menge kam vom Copilot" wissen will, braucht die Befund-Ablage aus **V-031**
- [x] Anti-Leak: die Befunde werden beim Rezept-Wechsel verworfen (`copilotZuruecksetzen()` in `ladeRezept`/`formZuruecksetzen`) ✅ **L6b** — gleicher Schutz wie bei der VK-Revise-Vorschau. **Bug-Fund dabei:** `RecipeModal::ladeRezept()` tut das für die L1a-Vorschau **nicht** (nur `VkModal` resettet sie) → Vorschau von Rezept A überlebt den Sprung ins Sub-Rezept B; verifiziert, gehört aufs Bugs-Board
- [x] MCP-Lockstep: `recipes.REVIEW` ✅ **L6a** — read-only (`risk_level: read`, `side_effects: []`), Apply bleibt UI bzw. explizit über `recipe_ingredients.PUT`
- [x] Pest ✅ **L6a** — `RecipeReviewServiceTest` (7: Normalisierung/Anwendbarkeit, Namens-Rückfall, Mengen-Apply ohne Kollateralschaden, entfernen+fehlt, Schreib-Sperre für nicht-anwendbare Befunde, VK-Zweig, Provider-Ausfall) + `McpRecipesReviewTest` (4, #504-Tenancy); ✅ **L6b** — `RecipeCopilotUiTest` (8: beide Knöpfe da, Prüfen füllt Karten ohne Schreiben, Einzel-Apply + `zutatenVersion`, Schreib-Sperre + Hard-Stop-Zeile, „Alle übernehmen" lässt den Rest stehen, Neubewertung nach `entfernen`, kein Leck ins nächste Rezept, VK-Zweig über dieselbe Strecke)

> **Zweiter Konsument (2026-07-23): Kapitel-Anlage (Spec [19](19_Foodbook_Leitstelle_A-Z.md), E7.4).** Der Kapitel-Go materialisiert Freitext-Ideen (`dish_ideas`) über die **gleiche One-Shot-Generier-Queue** wie L7 (+ Wirtschaftlichkeits-Glied L8). L7/L8 sind damit nicht nur der „Erstell mir ein Rezept"-Button, sondern auch die Erdungs-Maschine hinter dem Kapitel-Go. Verdrahtung provider-gated (ohne Provider: `generation_status='queued'` + „wartet auf KI", Go scheitert nicht).

### L7 — One-Shot-Vollerstellung („volles Rezept in einem Durchlauf") · Größe M–L · **präzisiert Dominique 2026-07-16**

**Soll:** Beschreibung rein → **fertiges Rezept raus**: Rezeptur vollständig auf reale GPs geerdet (inkl. Artikel-Kette: GP → Lead-LA → EK), alle Aggregationen gerechnet, UND **voll angereichert** — kein manueller „Alles anreichern"-Klick danach.

**Ehrliche Einordnung:** Das ist MEHR als CJ — die App macht Anreicherung/Kohärenz nach dem Accept manuell (13-Schritt-CTA `EnrichAllModal`, Kohärenz on-demand). FA hat aber alle Bausteine schon einzeln: Generator + Grounding (#505 Slice 1+2 inkl. LA→GP-Auto-Write `versucheLaZuGp` = die „Artikel"-Kette), volle Accept-Aggregation (`recomputeAndPropagate`), `BulkEnrichService`, VK-Kohärenz. **Fehlt nur die Verkettung als ein Flow.**

**Die Ziel-Kaskade (FA, ein Durchlauf):**
```
Beschreibung + Pills → Generieren (Grounding-Prompt #505)
  → pro Zutat: Match gp/sub — none: LA→GP-Auto-Write (tentative) — sonst Hard-Stop-Zeile
  → Accept (draft, created_via) → volle Aggregation (EK/Allergene/Yield/Nährwerte/Darreichung)
  → AUTO-ENRICHMENT-PASS (neu): Beschreibung/Zubereitung-Politur, Eigenschaften/Tags,
    Garverluste, Anker-Erdung + Pairing, Sensorik, Sektor/Niveau, Klassifikation (GL-07 Bauart)
  → Kohärenz-Check (VK) → fertig: status=draft, ALLE Felder gefüllt, Review-fertig
```

**DoD:**
- [ ] Generator-Modal (Basis + VK): Toggle „⚡ Voll anreichern" (Default AN) — nach Accept läuft die Enrichment-Kaskade automatisch durch (Queue-Job, nicht blockierend; Fortschritt sichtbar)
- [ ] Enrichment-Pass nutzt die BESTEHENDE `BulkEnrichService`-Strecke + Anker-Erdung + Klassifikator — keine Parallel-Implementierung; fehlende Einzelschritte gegenüber der CJ-13er-Kaskade (Garverluste, Sensorik, Sektor/Niveau) ergänzt statt neu erfunden
- [ ] Artikel-Kette garantiert: jede Zutat endet auf GP mit Lead-LA-Auflösung ODER ehrlich geflaggt (unbepreist-Warnung aus #511-F2; tentative GPs aus LA→GP-Auto-Write in der ReviewQueue)
- [ ] Sub-Rezept-Stubs: v1 = Stub + Flag „ausrezeptieren offen" (KEINE automatische Rekursiv-Generierung; max. Tiefe = Regelwerk §4, Entscheid für v2)
- [ ] Alles bleibt `status=draft` + Lineage je Feld (GL-07) — Vollerstellung ≠ Auto-Freigabe; user-editierte Felder werden NIE überschrieben
- [ ] MCP-Lockstep: `recipes.GENERATE` (L5) bekommt denselben `voll_anreichern`-Parameter — ein LLM-Client bekommt dasselbe volle Rezept
- [ ] Graceful: Provider-Ausfall mitten in der Kaskade → Rezept bleibt konsistent (Kern steht, Enrichment-Rest als offene Signale), nie halbes Wrack
- [ ] Pest: One-Shot-Roundtrip (Beschreibung → Draft mit gefüllten Enrichment-Feldern + geerdeten Zutaten), Abbruch-Fall, Überschreib-Schutz
- [ ] Mini-Paritäts-Check am Rand (aus dem alten Audit übernommen): Hard-Stop-Inline-Anlage („GP anlegen"/„Stub anlegen") + „Meintest du?"-Disambig im FA-GeneratorModal vorhanden — sonst nachziehen

### L8 — Wirtschaftlichkeit (R2) in die KI-Kaskade · Größe M · **gehört zu L7, eigenes Glied**

**Soll:** Der KI-Knopf liefert nicht nur ein volles, sondern ein **bepreistes, margen-geprüftes** Gericht. Die L7-Ziel-Kaskade bekommt nach dem Enrichment-Pass das Wirtschaftlichkeits-Glied:

```
… Auto-Enrichment → Kohärenz-Check
  → WIRTSCHAFTLICHKEIT (neu): Portion (aus Generator-Proposal, CJ liefert portion_g)
    + Aufschlagsklasse (Default-AK Klasse-vor-HG, existiert)
    + Standard-Darreichung (ensureStandard, existiert)
    → Auto-VK (Cost-plus via MargeService, R1.2-Mechanik)
    → W%-Ampel-Check: Marge im Zielband? sonst Signal (R2.1-Muster)
  → fertig: draft, voll, BEPREIST, margen-transparent
```

**DoD:**
- [ ] Generator-Proposal liefert/erfragt Portionsgröße verbindlich (VK-Pfad): `quantity_per_unit_g` gesetzt — ohne sie kein Auto-VK; fehlend → sichtbare Lücken-Zeile, nicht still
- [ ] Accept-Kaskade: Aufschlagsklasse (Default-AK-Fallback Klasse-vor-HG) + Standard-Darreichung + Auto-VK laufen automatisch — das per KI erstellte Gericht hat nach dem One-Shot EK **und** VK **und** W%
- [ ] W%-Check gegen Zielband (TeamSettings): Ausreißer → Signal (bestehendes R2.1-Signale-Muster), im Generator-Ergebnis sichtbar („Marge 22 % — unter Zielband")
- [ ] Optional als Generator-Input: **Ziel-VK/Zielmarge als Pill** („Gericht für 8,50 € VK") → fließt als Constraint in den Prompt + wird nach der Kalkulation ehrlich gegen das Ergebnis gehalten (Brücke zu R2.4-Solver-Denke, KEIN Solver in v1)
- [ ] Kein Auto-Publish von Preisen: Auto-VK bleibt `auto`-Mode (überschreibbar), R2.5-Trennung interne Marge ↔ veröffentlichter VK bleibt unberührt
- [ ] Unbepreiste Zutaten (Park-GPs) schlagen sichtbar bis ins Generator-Ergebnis durch (EK partiell → VK als „vorläufig" markiert) — verzahnt mit #511-F2-Warnung
- [ ] Pest: One-Shot-VK-Roundtrip (Beschreibung → Draft mit VK + W%), Zielband-Signal-Fall, Portion-fehlt-Fall
- [ ] MCP-Lockstep: `recipes.GENERATE` (L5) liefert im Ergebnis EK/VK/W% mit

## 4. Reihenfolge + Abhängigkeiten

```
#508 (Revise-Grounding) ──► ✅ DONE 2026-07-17 → L1 VK-Revise + L6 Copilot bauen AUF der Strecke
#492 R6.1-Blindtest (braucht LLM auf demo/lokal) ──► L3 Foodbook aus Brief
L2 (Kapitel-Text) + L5 (recipes.GENERATE): unabhängig, jederzeit — lokal sofort baubar
L4 (Slot-Vorschlag): unabhängig, ohne LLM
RAG-Plan E1–E5 (#507): ✅ GEBAUT → hebt Grounding ALLER Flächen automatisch (Flag/E6 = Martin), kein Blocker
```

**Empfohlene Bau-Reihenfolge:** L5 + L2 (klein, unabhängig, sofort) → L1 + L6 im Verbund mit #508 (alle drei teilen die Matching-/Apply-Strecke: Revise-Re-Matching, Copilot-fehlt-Matching, VK-Revise) → **L7 + L8 zusammen** (One-Shot-Vollerstellung inkl. Wirtschaftlichkeits-Glied — ein Kaskaden-Umbau, nicht zwei; profitiert von #508-Matching + RAG-E2-Bausteinen, blockiert aber auf keinem von beiden) → L4 → L3 nach R6.1-Blindtest.

## 5. Bewusste Nicht-Ziele

- Kein „KI erstellt und aktiviert" — überall draft + menschliche Freigabe (globale DoD).
- Kein LLM in der Gericht-AUSWAHL (L3/L4) — deterministisch bleibt deterministisch (R6.1-Prinzip).
- Keine Duplikation der Revise-Strecke — L1 wartet auf die #508-Mechanik statt sie zu kopieren.

---

*Erstellt 2026-07-16 (Planungs-Session). Verwandt: [02_RAG_System_FoodAlchemist.md](02_RAG_System_FoodAlchemist.md) (Qualitäts-Layer unter allen Generatoren) + [01_Editor_Strecke_Bugs_511_509.md](01_Editor_Strecke_Bugs_511_509.md). Dev: #369 (L2 dort getrackt), #492, #505, #508; für L1/L3/L4/L5 existierte KEIN Issue → Sammel-Issue angelegt (s. Dev-Modul).*
