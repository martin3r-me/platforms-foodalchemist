# KI-Erstell-/Verbesser-Flächen — Bestandsaufnahme + Lücken-Plan (Stand 2026-07-16)

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
- [ ] `VkModal`: `✨ KI-Überarbeiten` mit Freitext-Anweisung, Prompt-Variante für VK (Gericht-Kontext: Servierform/Klasse/Diät bleiben konsistent, Facetten nicht kaputt-revidieren)
- [ ] Revidierte Zutaten laufen durch den **#508-Re-Matching-Pfad** (IngredientMatchService + Hard-Stop) — geteilte Strecke, nicht dupliziert
- [ ] `✨ Alles anreichern` im VkModal (BulkEnrichService kann VK schon — nur Button/Wiring)
- [ ] Vorschau + explizites Übernehmen, Lineage `ki` (GL-07), nie Auto-Persistenz
- [ ] Pest: Revise-Roundtrip VK + Facetten-Erhalt; MCP-Lockstep-Check (kein neues Tool nötig, `recipe.ueberarbeiten` ist UI-only — bewusst begründen oder L5 mitlösen)

### L2 — Foodbook Kapitel-Text-KI scharfstellen · Größe S · hängt an: LLM-Provider (lokal geht sofort)

Bereits in **#369** getrackt — kein neues Issue. Der Block „LLM-Key" ist inzwischen weicher: `AiGatewayService` + Prompt-Registry existieren, lokal läuft ein Provider; nur demo hängt an Martin (#499).

**DoD (als #369-Nachtrag):**
- [ ] Prompt `foodbook.kapitel_text` (Kontext: Kapitel-Titel + enthaltene Concepts/Gerichte + Kunden-Wording-Kette + Brand Voice)
- [ ] Button enabled, graceful bei fehlendem Provider (KiNichtVerfuegbarException-Muster aus #499)
- [ ] Vorschau + Übernehmen, `notizen_manual`-Schutz (user-editierte Texte nie überschreiben — Backup-Lehre 2026-06-30)

### L3 — „Foodbook aus Brief" (Gesamt-Flow) · Größe L · **Entscheid Dominique: Scope**

Der große Hebel, aber KEIN Neubau — Komposition existierender Teile: Brief → (R4.1) Planungs-Gerüst → Kapitel-Struktur-Vorschlag → je Kapitel `generiereAusGeruest`/`generiereAusBrief` (R6.1) → Foodbook-Draft mit Coverage-Ampel (R4.2) dran.

**✅ ENTSCHIEDEN (Dominique 2026-07-18): v1 schlank** — Brief → Kapitel-Baum + je Kapitel ein generiertes Konzept, Status draft, Coverage sofort sichtbar; Kapitel-Texte via L2 nachziehen (kein Voll-Flow in v1). Erst nach R6.1-Blindtest (#492) starten — der validiert die Konzept-Qualität, auf der dieser Flow aufbaut.

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
- [ ] `RecipeReviewService` + Prompts `recipe.copilot` / `recipe.copilot_vk` (Prompt-Registry; Kontext via `KnowledgeContextService` sparsam — CJ injiziert hier bewusst KEIN Pairing/Vault, nur Layer + Rezept)
- [ ] Button `🧑‍🍳 Copilot` in RecipeModal UND VkModal (beide Flächen von Anfang an — nicht die L1-Lücke wiederholen)
- [ ] Findings-UI: Karten mit art-Farbe + Confidence, 1-Klick-Apply je Finding, „Alle übernehmen" nur auto_applicable; `fehlt`-Zeilen durch `IngredientMatchService` (+ #508-Strecke/`versucheLaZuGp` wo passend)
- [ ] Jede Übernahme → `recomputeAndPropagate` (+ die #511-Event-Kette, damit das UI es auch zeigt)
- [ ] Lineage: Übernahmen als `ki`-Quelle (GL-07), Call-Audit analog `ai_call_log`-Muster (FA: bestehendes Gateway-Logging)
- [ ] MCP-Lockstep: `recipes.REVIEW` (read-only, liefert Findings-JSON — Apply bleibt UI/explizit) oder bewusst UI-only begründet
- [ ] Pest: Findings-Parse, fehlt-Matching, Apply-Roundtrip inkl. Recompute; graceful ohne Provider

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
