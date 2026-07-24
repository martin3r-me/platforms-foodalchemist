# 19 — Foodbook-Leitstelle A–Z: Phasen-Cockpit + volle Kaskaden-Verdrahtung

> **Status:** 🟢 bau-reif, freigegeben Dominique 2026-07-23. Nachfolger der Foodbook-Hälfte von Spec 08 (dort Banner).
> **Betrieb:** Autonome Routine arbeitet die Teilschritt-Checkliste (§Ende) ab — **diese Datei ist der Übergabepunkt zwischen Läufen.** Jeder Lauf: Checkliste lesen → EINEN Teilschritt bauen → verifizieren → Checkliste fortschreiben → committen.

## Kontext — warum

Das Foodbook-Cockpit (Phasen 1–6, gepusht) ist gebaut, aber die Planung ist **flach** und **konvergent**: Slots = freie Labels, Ziele hängen am flachen Frame-Slot, jedes übernommene Gericht landet sofort in einem Konzept, Kreativ-Ideen ohne DB-Anlage gibt es nicht. Dominiques reales Doing (Broich Foodbook 2026: 4 Ebenen tief, 139 Überschriften, Excel-Preis-Cockpit mit WE-Ampel, Duality Pakete/Einzelartikel) ist ein **mehrphasiger Baukasten**. Ziel: Das Foodbook wird die echte Leitstelle — 7 Arbeits-Phasen führen von Bedarf bis Preis, alle Dimensionen werden am Foodbook/Kapitel definiert und kaskadieren nach unten (Foodbook ⊃ Kapitel(n-tief) ⊃ Konzept/Gericht ⊃ Rezept ⊃ GP/LA), und **nichts wird angelegt, bevor ein Kapitel freigegeben ist**.

Regeln: nur dieses Modul + Sandbox; gezielt stagen (NIE `add -A`); ROADMAP + Office-Dev-Package 23 mitziehen; MCP-Lockstep als DoD; Migrationen additiv/idempotent (Live-Daten!).

## Verbindliche Entscheidungen (Dominique, 2026-07-23)

1. **Kapitel-Struktur beliebig tief** (n Ebenen) — Kapitel-Baum (`parent_id`) existiert, es fehlt Bedienung + tiefen-feste Coverage.
2. **Freigabe PRO KAPITEL** — das Go-Gate ist kapitelweise.
3. **Phasen soft mit EINEM harten Gate** — 7 Phasen als Checkliste (springen erlaubt); hart ist nur: ohne Kapitel-Go wird nichts angelegt.
4. **Eigenes Zielgruppen-Vokabular** (Kapitel wählt 1–n); Outlet nur optionaler Tag, KEINE primäre Ebene.
5. **Kapitel tragen Konzepte UND direkte Gerichte**: Konzept = Paket mit gebündeltem €/Gast-Preis; Einzelgericht = `recipe_ref`-Block direkt am Kapitel (€/Position). Invariante wird „0–n Konzepte + 0–n Einzel-Blöcke pro Kapitel"; ROADMAP-Entscheid „Weg B exklusiv" teilrevidiert.
6. **Vokabular-Pflicht — keine freien Klassifikations-Strings**: Jede Klassifikations-Dimension referenziert Vokabular/Klasse per FK bzw. kanonische Konstante. Freitext nur für echten INHALT (Ideen-Titel/Beschreibung, Namen, Notizen, Wording). Zielgruppen-Stempelung über Pivot `concept_target_groups` — NICHT als Text ins Legacy-Feld `concepts.target_group` (bleibt unangetastet; Prompts lesen aus `leitplanken()`).

## Ziel-Architektur

- **5er-Phasen-Maschine bleibt unangetastet** (`PhaseService::PHASEN`). Die **7 User-Phasen sind eine abgeleitete Checkliste** (nie persistiert): 1 Bedarf→kontext · 2 Struktur/3 Tiefe/4 Kapitel-Aufbau→struktur · 5 Kreativ/6 Anlegen→befuellung · 7 Preise→kalkulation · Versand→freigabe (bestehendes Coverage-Gate).
- **Ziele wandern vom Slot ans Kapitel** (kein Slot-Spiegelbaum, kein Hybrid): das Kapitel IST der n-tiefe Baum. Frame bleibt Foodbook-globaler Rahmen (Kopf-Preis p.P., frame-weite Quoten, Kickoff-Landeplatz, R2.4-Constraint-Quelle); `strukturAusGeruest` stempelt Slot-Ziele einmalig aufs Kapitel.
- **Kreativ = Skizzen-Ebene** (`dish_ideas` + `dish_idea_groups`): frei geschrieben ODER Bestand-Ref, Einzel ODER Paket-Gruppe — Status entwurf, erdet NICHTS.
- **Kapitel-Go „Anlegen"** (UI-Wording NIE „Freigabe"): Paket-Gruppen → je EIN Konzept + Slots + concept_ref-Block; Einzel-Ideen → `recipe_ref`-Block; Freitext → KI-Queue (L7/L8, provider-gated; partielle Materialisierung ist der DoD-Fall).
- **Dimensionen kaskadieren**: Kapitel(+Eltern) → Foodbook-Default → Segment. Servierform wirkt automatisch auf Preise (Konzept-Stempel → `DarreichungResolver`-Fallback; Einzel-Pfad → `blocks.presentation_id` + additive `DarreichungResolver::fuerBlock`).

## Datenmodell (5 Migrationen, additiv; ALTERs auf Bestandstabellen mit `unsignedBigInteger+index` statt FK)

**M1 `create_foodalchemist_target_groups_tables`** (Muster `2026_07_03_000006`): `foodalchemist_target_groups` (id, uuid, team_id, name, description, sort_order, is_inactive, timestamps, softDeletes, unique(team_id,name); Seeds idempotent z.B. Tagungsgast/Bankett-Gast/Mitarbeiter/VIP-Gala) + Pivots `foodbook_target_groups` (Default 1–n), `chapter_target_groups` (Kapitel 1–n), `concept_target_groups` (Stempel-Ziel, Entscheidung 6).

**M2 `add_dimension_defaults_to_foodalchemist_foodbooks`**: `foodbooks` += `default_event_type_id`, `default_serving_form_id`, `target_food_cost_pct` decimal(5,2), `food_cost_tolerance_pp` decimal(5,2) (Code-Default 5.0) + Pivot `foodbook_service_moments` (Tagesablauf 1–n).

**M3 `add_ziele_und_anlage_to_foodalchemist_foodbook_chapters`**: SOLL-Spalten `target_count` int, `price_anchor/price_min/price_max` decimal(10,2), `niveau` string (KANONISCH — Übergang zu `concept.level` IMMER via `denormNiveauFuerConcept`), `service_moment_id`, `serving_form_id`, `pricing_mode` string(12) (paket|einzel|gemischt, Model-Const, weiche Prüfung), `target_food_cost_pct`; Anlage-Spalten `released_at`, `released_by` (users.id ohne Cross-Modul-FK), `release_note`, `release_result` json. `chapters.status` (draft|sent|archived) bleibt Versand-Status.

**M4 `create_foodalchemist_dish_ideas_tables`**: `dish_idea_groups` (id, uuid, team_id, chapter_id/concept_id FK nullable XOR, name → wird concept.name, target_price_pp decimal(10,2), position, materialized_concept_id, timestamps, softDeletes) + `dish_ideas` (id, uuid, team_id, chapter_id/concept_id XOR, position, titel, beschreibung, sales_recipe_id (nur echte VK-Gerichte), ziel_form string(12) default 'einzel' (einzel|paket; paket ⇒ group_id), group_id FK nullOnDelete, status entwurf|verworfen|freigegeben, created_via, quelle_meta json, generation_status (null|queued|erstellt|fehlgeschlagen), generated_recipe_id, materialized_at, materialized_ref json ({block_id} einzel / {concept_id,concept_slot_id} paket), timestamps, softDeletes). **Invariante: Ideen erzeugen NIE Rezepte/GPs/Konzepte — erst das Go.**

**M5 `add_presentation_to_foodalchemist_foodbook_blocks`**: `foodbook_blocks` += `presentation_id`. Optional abkoppelbar: `foodalchemist_outlets` (Vokabular + `color` string(7)) + `chapters.outlet_id` — reines Tag, NICHT in leitplanken().

**Kein-Migrations-Fund (E1):** `recipe_ref` existiert in Schema + ALLEN Lesepfaden (`blockPreis` FoodbookService ~:500, Coverage `$block->dish` ~:193, Dokument/Vorschau/Picker `gerichtKandidaten` ~:714). Gesperrt NUR Schreibpfad: `BLOCK_TYPES` ~:342 + MCP-Enum. Unlock + Doktrin-Kommentar :337–341 + `docs/foodbook.md` im selben Commit.

## Services (Kern-Signaturen)

**FoodbookService:**
- `leitplanken(Team, $fb, ?Concept = null, ?Kapitel = null): array` — += `zielgruppen[]`, `event_type_id`, `serving_form_id`, `service_moment_ids[]`, `quellen[]`; Kaskade Kapitel(+Eltern) → Foodbook → Segment (Segment-Boden nur niveau/convenience). DER Auflösungs-Punkt für Vorschläge, Kickoff, Canvas, Anlage-Stempel.
- `kapitelZiele(Team, Kapitel): array` — aufgelöste SOLL-Sicht mit Vererbung + Quellen.
- `uebernehmeGericht(Team, $fbId, $chapterId, $recipeId, ?$rolle, $createdVia, ?int $conceptId = null)` — Kern aus `uebernehmeVorschlag`; `$conceptId` null = heutiges Verhalten (Wrapper bit-identisch), gesetzt = Gericht in DIESES Konzept. **Dedup kapitelweit** (Konzept-Slots ∪ recipe_ref-Blöcke), quer nur WEICH melden.
- `kapitelFreigeben(Team, $kapitelId, ?$note, ?$userId): array` — transaktional, **idempotent**. Routing: Paket-Gruppe → Konzept (name/target_price_per_person aus Gruppe; Stempel serving_form_id/event_type_id FK, Einsatzmomente-Pivot-Sync, Zielgruppen via `concept_target_groups`-Pivot; `created_via='kapitel_freigabe'`) + Slots + concept_ref-Block; einzel+Ref → recipe_ref-Block (type+sales_recipe_id+opt. wording+presentation_id); Freitext → `generation_status='queued'` (GenerateRecipeJob-Muster → L7/L8; ohne Provider typisierte Exception → „wartet auf KI", **Go scheitert nicht**). Setzt released_* + Activity-Log. Return: {kapitel_id, konzepte[], bloecke_einzel, materialisiert, queued, uebersprungen, protokoll[]}.
- `wareneinsatzAmpel(Team, $fb, Kapitel, ?$pax): array` — IST aus `kapitelAggregat().food_cost_percent`; SOLL chapters→foodbooks→`zielWareneinsatzPct()` (30); grün ≤Ziel / gelb ≤Ziel+Toleranz / rot / unbekannt. Pauschal-Blöcke ohne EK → „partiell"-Hinweis.
- `blockPreis()`: recipe_ref respektiert `price_basis` (person|pauschal) + `fuerBlock(...)?->sales_net ?? dish->sales_net`.
- `strukturAusGeruest`: stempelt Slot-Ziele einmalig aufs neue Kapitel (protokoll += ziele_uebernommen). `KAPITEL_FELDER` erweitern.

**DarreichungResolver** (additiv, Muster `fuerPaketGericht`): `fuerBlock(Block, ?$servingFormId): ?Darreichung` — 1. block.presentation_id → 2. Gericht-Darreichung zur Kapitel-/Foodbook-Servierform → 3. standardFuer(). **Alles null ⇒ bit-identisch heute** (keine sichtbaren Preisänderungen im Bestand).

**CoverageService:** Blocker-Fix Tiefe — `istFoodbook` iteriert flach + zählt nur direkte Blöcke, `vk_pp` ist rekursiv → Ziel-/Slot-Scope = „Kapitel + alle Nachfahren" (`descendantKapitelIds` existiert ~:315); Model-Kommentar `FoodAlchemistFoodbook.php:32` fixen. Neu `pruefeKapitel(Kapitel,$ist)` (Befund-Shape identisch, chapter_id additiv) + WE-Ampel-Sektion. **Vorrangregel:** Kapitel-Ziel gewinnt → `pruefeSlot` skippt Menge/Preis für Slots, deren Kapitel eigene Ziele hat.

**PlanningFrameService:** `replaceStructure`-**Rerun-Guard** — Kickoff-Rerun löscht heute Slots samt chapter_id → Duplikat-Kapitel; Label-Match erhält chapter_id, sonst Warnung.

**Neu `IdeenService`:** liste/add/update/setStatus (nur entwurf|verworfen), addGruppe/updateGruppe, `kiDivergenz(Team,$chapterId,$anzahl)` → Entwurfszeilen `created_via='ai_gateway'` via Prompt-Key **`foodbook.kapitel_ideen`** (Kontext = leitplanken() + promptKontext() + KnowledgeContextService). Erledigt Spec-08-P6-Routings (`foodbook.plan`/`concept.plan`).

**Neu `LeitstelleService`:** `checkliste(Team,$fb)` (7 Schritte abgeleitet, Sprungziele tab+anker), `kapitelStand(Kapitel)`, `speisenBaum($fb)` (Kapitel → [Paket→Gerichte | Gericht direkt | Ideen], Badges). Panels als **Nested-Livewire** (Muster `KundeDnaPanel`).

## UX

1. **Checkliste + Stepper:** Stepper von Briefing-Karte (index.blade.php ~:131) auf Tab-Leisten-Ebene; 7-Chip-Checkliste (offen/teil/erledigt, klickbar → Tab+Anker via Alpine-Event-Bus; `freigabe` NIE über Checkliste). Partial `leitstelle-checkliste.blade.php`.
2. **Rechte Rail — KONTEXTSENSITIV** (User-Vorgabe per Screenshot): **Kopf gewählt** = Umschalter Fortschritt (Checkliste + Kapitel-Matrix, [Go]-Shortcut, Komplexitäts-Hinweis >8 Kapitel/>12 Positionen) · Speisen (heterogener Baum, Badges [Paket]€/Gast·[Einzel]€/Pos·Entwurf/angelegt/bepreist/KI-Queue) · Kalkulation (Portfolio + WE-Ampel je Kapitel); Auto-Default je Tab nur ohne manuellen Pin (localStorage). **Kapitel gewählt** = Kapitel-Planung: Zielgruppen-Chips, Niveau-Override, Einsatzmoment, Servierform, Mengenziel, Preisziel, WE-Ziel (M3-Spalten) + Kapitel-Coverage (Scope-Param) + Kapitel-Kalkulation + Ideen-Stand mit [Go]. Hauptfläche bleibt Kapitel-Kopf + Blöcke; Unterkapitel im linken Baum.
3. **Kreativ-Tab = Skizzenfläche** (per-Slot-Vorschläge im Planung-Tab ENTFERNT/weitergeleitet — „Übernehmen" erzeugt künftig Entwurf-Idee, KEIN Konzept): [+ Idee] frei · [aus Bestand] (Reuse `slotVorschlaege`) · [KI frei] (provider-gated, disabled+Tooltip). Paket-Bündelung per Mehrfachauswahl → „zu Paket bündeln" (Muster `markiere()`/`wahlGruppeBilden()`); „aus Paket lösen"/„Paket auflösen". Kapitel-Fill fragt [als Einzelpositionen]/[als ein Paket] (Vorbelegung aus slot_type).
4. **Anlage-Modal** (Wording „Kapitel anlegen"): Zusammenfassung beider Wege, ☑-Liste je Paket/Idee, Ziel-/Niveau-Anzeige. Undo „Anlage zurückziehen" solange draft + created_via='kapitel_freigabe' + kein Snapshot/Versand.
5. **Preise-Tab (neu):** Kapitel-Baum-Tabelle, Art Paket €/G vs. Einzel €/Pos · EK · VK · WE% · Ampel · VK-Editor-Link; nach Foodbook-Freigabe R2.5-Snapshot-Badges.
6. **Vorschau/Dokument:** depth-basierte Überschriften (h3/h4/h5); Entwurf-Ideen NIE in Kundensicht; €/Gast vs. €/Position konsistent. `dokumentDaten` rendert Baum+recipe_ref schon — Regression absichern.
7. **Bestands-Foodbooks:** Slot-Konzepte bleiben gültig, Anzeige als [Paket] („Slot-Übernahme"); Matrix „angelegt — Ideen-Phase übersprungen". Keine Datenmigration.

## Prinzip: KI-/MCP-Führung durch die Planungsebenen (verbindlich)

1. **Ein Kontext-Vertrag:** `leitplanken()` + `kapitelZiele()` — JEDER KI-Aufruf im Foodbook-Umfeld bekommt den aufgelösten Block, immer mit Kapitelbezug wenn ein Kapitel im Spiel ist; dazu DNA-Kette (`cascadeKontext`).
2. **MCP-Lesefläche:** neues `foodalchemist.leitstelle.GET(foodbook_id, ?chapter_id)` — Checkliste, Kapitel-Matrix, pro Kapitel aufgelöster Planungs-Kontext (Ziele, Zielgruppen, Dimensionen, Coverage, WE-Ampel, Ideen-Stand). Eine KI kann den User durch die Phasen führen.
3. **MCP-Schreibfläche je Phase:** P1 `foodbooks.PUT`-Defaults + `zielgruppen.*` · P2/3 `planning.PUT` + `foodbook_kapitel.POST/PUT` · P4 `foodbook_kapitel.PUT` (Ziele) · P5 `kapitel_ideen.*` (nur Entwürfe) · P6 human-only (nur `kapitel_freigabe.GET`) · P7 `coverage.GET`-WE + VK-Tools. Jedes neue Feld im selben Commit im MCP-Schema.

## Prinzip: Erst kreativ, dann erden — mit Anpassungs-Schleife

1. **Divergenz produkt-blind:** Kreativ-Phase erlaubt freie Ideen OHNE Bestandsprüfung; KI-Divergenz darf erfinden (Anker-Graph als Inspiration); Bestands-Vorschläge daneben, ersticken die Divergenz nicht.
2. **Erdung mit Anpassung statt Hard-Fail (E7):** Nicht erdbare Freitext-Idee → **Anpassungs-Schleife**: KI schlägt angepasste Version aus verfügbaren Produkten vor (kandidatenPool/#507/LA-First; Aroma-Treue via Anker-Graph), DRAFT mit sichtbarem Delta; alternativ LA-First-Mint (tentative GP + Beschaffungs-Hinweis). Beide Wege im Anlage-Protokoll.
3. **Kein stiller Kreativitätsverlust:** Original bleibt in `dish_ideas`/`quelle_meta`; Sortimentslücken-Signal als Follow-up (Signale-Cockpit).

## MCP-Lockstep

- `foodbook_blocks.POST`: +recipe_ref-Enum, +sales_recipe_id-Validierung, +presentation; **price_basis-Enum angleichen** (Tool `pro_person|pro_stueck|pauschal` ≠ Code `person|pauschal|staffel`).
- `foodbooks.POST/GET`: Dimension-Defaults + Fortschritts-/Anlage-Stand.
- `foodbook_kapitel.POST` + **NEU PUT**: Ziele + Zielgruppen + pricing_mode.
- **NEU** `leitstelle.GET`, `zielgruppen.GET(/POST)`, `kapitel_ideen.GET/POST/PUT`, `kapitel_freigabe.GET`. **Kapitel-Go OHNE MCP-Trigger.**
- `coverage.GET`: Kapitel-Befunde + WE-Sektion. `planning.*`/`phase.PUT` unverändert (Slots bleiben flach).

## Doku-Abgleich

Spec 08 Banner (Foodbook-Hälfte superseded; Go PRO KAPITEL; gültig Concept-standalone + P6) · Spec 03 (L7/L8 zweiter Konsument „Kapitel-Anlage (19)"; L3-v1 revidieren) · Spec 00 (Phase-4 splitten: E1–E5 deterministisch VOR L7/L8) · Spec 12 („Solver am Kapitel-Go/Preise-Phase") · ROADMAP Update-Absatz (Weg B nicht mehr exklusiv, ~237/~257) + Code-Doku FoodbookService:337–341 im E1-Commit · `_Spec_Status_Matrix` +19.

## Verifikation

- **Pest** (aus `sandbox-food-alchemist`): je Etappe Filter `Foodbook|FoodbookLeitstelle|Coverage|Concept` + neue Suiten (KapitelZiele, Skizzen, KapitelAnlage, ZielgruppenVokabular); MCP je mit Cross-Team-Negativtest; Etappen-Ende breite Suite ohne Regression.
- **MySQL-Smoke:** DarreichungResolver-Zwei-Darreichungen, Backfill-Idempotenz, Kaskaden-Deletes, JSON-Spalten.
- **Browser-Klickstrecke** (Sandbox, Fake-Provider): Kickoff → Struktur → Unterkapitel → Ziele → Einzel-Block + Paket-Fill → Skizzen → Anlage-Go → Panels → Vorschau → Konsole leer.
- **fb=1-Protokoll:** Vorher-Dump 8 Tabellen + SELECT-Protokoll; nach Migrationen Byte-Vergleich (alles opt-in); Feature-Durchlauf an NEUEM Test-Foodbook; Mixed-Test an fb=1 nur bewusst + mit Rücksetzung.

## Top-Risiken

1. Coverage-Tiefe (istFoodbook flach vs. kapitelAggregat rekursiv) — Fix E2 mit Eltern-Ziel/Enkel-Gericht-Test. 2. Doppel-Befunde Slot↔Kapitel — Vorrangregel konsistent. 3. Zwei Gate-Begriffe — „Anlegen" (Kapitel) vs. „Freigabe" (Foodbook); 7 Phasen NIE in PHASEN. 4. uebernehmeVorschlag-Wrapper bit-identisch. 5. fuerBlock nur bei gesetzter presentation/Servierform. 6. Verhaltensbruch Kreativ → „bereits angelegt"-Zustand. 7. Niveau-Vokabular-Falle (denormNiveauFuerConcept). 8. Keine neuen frei-slugs (Sektor-Kanonisierung = Follow-up).

## Nicht-Ziele

Kein platform-core/crm; KI-Neu-Erstellung = L7/L8 (nur Verdrahtung/Queue); R2.4-Solver = Follow-up; „+Topping", Farbcode-Ampel-UI, Slots-Hierarchisierung: raus.

---

## Fortschritts-Checkliste (Übergabepunkt der Routine — pro Lauf EIN Teilschritt, strikt der Reihe nach; nach Abschluss ☐→✅ + Datum + Commit-Hash)

### E0 — Setup + Doku-Abgleich
- [x] E0.1 Spec 19 angelegt (2026-07-23, diese Datei)
- [x] E0.2 Doku-Abgleich: `_Spec_Status_Matrix` +Zeile 19 · Spec-08-Banner · Spec 03 (L7/L8-Konsument, L3-v1) · Spec 00 (Phase-4-Split) · Spec 12 (Solver-Referenz) — ✅ 2026-07-23

### E1 — recipe_ref-Schreibpfad (S)
- [x] E1.1 `BLOCK_TYPES` + recipe_ref, Validierung (sales_recipe_id = echtes VK-Gericht, verkauf()-Scope, keine Slot-Variante — Muster `gerichtKandidaten`), Doktrin-Kommentar :337–341 + `docs/foodbook.md` + ROADMAP-Nachtrag — ✅ 2026-07-23
- [x] E1.2 `blockPreis`: price_basis (person|pauschal) für recipe_ref respektieren; Pest Mischkapitel (Konzept €/Gast + Einzel pauschal → Leiste korrekt) — ✅ 2026-07-23
- [x] E1.3 UI-Picker im Block-Editor („+ Gericht einfügen" neben „+ Concept einfügen", nutzt `gerichtKandidaten`) — ✅ 2026-07-23
- [x] E1.4 MCP `foodbook_blocks.POST`: recipe_ref-Enum + sales_recipe_id + price_basis-Enum-Angleich (`pro_stueck`-Falle); Pest MCP-Roundtrip + Tenancy-Negativ — ✅ 2026-07-24
- [x] E1.5 Dedup kapitelweit in `uebernehmeVorschlag` (Konzept-Slots ∪ recipe_ref-Blöcke); Pest — ✅ 2026-07-24

### E2 — Kapitel-Baum + Coverage-Tiefe (M)
- [x] E2.1 Unterkapitel-UI (anlegen unter Kapitel, verschieben; Service `addKapitel(parentId)`/`moveKapitel` existieren) — ✅ 2026-07-24
- [x] E2.2 Coverage Nachfahren-Scope: `istFoodbook`-Rollup (Kinder-Aggregation) + `slotScope` = Kapitel+Nachfahren; Model-Kommentar `FoodAlchemistFoodbook.php:32` fixen; Pest Eltern-Ziel/Enkel-Gericht → erfüllt — ✅ 2026-07-24
- [ ] E2.3 `replaceStructure`-Rerun-Guard (Label-Match erhält chapter_id); Pest „Kickoff-Rerun zerstört keine Kapitel-Links"

### E3 — Zielgruppen + Foodbook-Defaults (M, parallel zu E1/E2 möglich)
- [ ] E3.1 M1: target_groups + 3 Pivots (inkl. concept_target_groups) + Seeds; Models
- [ ] E3.2 M2: Foodbook-Default-Spalten + foodbook_service_moments-Pivot; Models/FELDER
- [ ] E3.3 Settings-CRUD (`ConcepterDimensionen::VOKABULARE` + zielgruppen) + Briefing-Tab Bedarf-Sektion (Defaults + Einsatzmomente-Pills)
- [ ] E3.4 `leitplanken()`-Erweiterung (Kapitel-Param + neue Dimension-Keys + quellen); alle Aufrufer prüfen; Pest Kaskade
- [ ] E3.5 MCP `zielgruppen.GET/POST` + `foodbooks.POST/GET`-Defaults; Pest + Tenancy
- [ ] E3.6 (optional, abkoppelbar) Outlets-Vokabular + chapters.outlet_id als Tag

### E4 — Kapitel-Ziele + WE-Ampel (L)
- [ ] E4.1 M3: Ziel- + Anlage-Spalten; `KAPITEL_FELDER`; `strukturAusGeruest`-Stempel (ziele_uebernommen)
- [ ] E4.2 `kapitelZiele()` (Vererbung Kapitel→Eltern→Slot→Foodbook, quellen)
- [ ] E4.3 `pruefeKapitel` + Vorrangregel (Kapitel-Ziel gewinnt, pruefeSlot skippt) + Kinder-Rollup; Pest inkl. keine Doppel-Befunde
- [ ] E4.4 `wareneinsatzAmpel` (Ziel-Kaskade + Toleranz + partiell-Hinweis); Pest
- [ ] E4.5 Backfill-Command Slots→Kapitel-Ziele (--dry-run/--apply, idempotent); MySQL-Smoke
- [ ] E4.6 MCP `foodbook_kapitel.PUT` (Ziele+Zielgruppen+pricing_mode) + `coverage.GET`-Erweiterung; Pest

### E5 — Checkliste + Leitstelle-Rail (M)
- [ ] E5.1 `LeitstelleService` (checkliste/kapitelStand/speisenBaum); Pest
- [ ] E5.2 Checkliste-UI + Stepper auf Tab-Leisten-Ebene + Sprung-Event-Bus
- [ ] E5.3 Rail-Panels als Nested-Livewire: Kopf = Fortschritt/Speisen/Kalkulation (Umschalter+Pin); Kapitel = Kapitel-Planung (Ziele-Editing) + Coverage + Kalkulation + Ideen-Stand
- [ ] E5.4 MCP `leitstelle.GET`; Pest + Tenancy

### E6 — Kreativ-Skizzen (L)
- [ ] E6.1 M4: dish_ideas + dish_idea_groups; Models
- [ ] E6.2 `IdeenService` (CRUD, Gruppen, XOR-Guard, Bestand-Übernahme-als-Idee); Pest
- [ ] E6.3 Kreativ-Tab-Skizzenfläche (3 Quellen, Paket-Bündelung per Mehrfachauswahl, verwerfen/reaktivieren) + Planung-Tab-Rückbau der Vorschlags-UI + „bereits angelegt"-Zustand für Bestands-Foodbooks
- [ ] E6.4 Prompt `foodbook.kapitel_ideen` + Routings foodbook.plan/concept.plan + `kiDivergenz` (gegen Fake-Provider verdrahtet)
- [ ] E6.5 MCP `kapitel_ideen.GET/POST/PUT` (ziel_form, paket_gruppe, paket_zielpreis_pp; GET gruppiert); Pest + Tenancy

### E7 — Kapitel-Go „Anlegen" (L; neu-Zweig L7/L8-gated)
- [ ] E7.1 M5: blocks.presentation_id + `DarreichungResolver::fuerBlock` (null ⇒ bit-identisch); Pest + MySQL-Smoke Zwei-Darreichungen
- [ ] E7.2 `uebernehmeGericht`-Refactor (+$conceptId; Wrapper bit-identisch); Regression-Pest Leitstellen-Kaskade
- [ ] E7.3 `kapitelFreigeben` (Routing paket→Konzept/einzel→recipe_ref, Stempel via Pivots, idempotent, released_*, Protokoll); Pest
- [ ] E7.4 Freitext-Queue (GenerateRecipeJob-Muster → L7/L8) + Anpassungs-Schleife-Verdrahtung (Graceful ohne Provider, „wartet auf KI"); Pest Graceful
- [ ] E7.5 Anlage-Modal + Undo „Anlage zurückziehen"; Browser-Klick
- [ ] E7.6 MCP `kapitel_freigabe.GET`; Pest

### E8 — Preise + Abschluss (M)
- [ ] E8.1 Preise-Tab (Kapitel-Baum-Tabelle, Paket/Einzel, WE-Ampel, VK-Links)
- [ ] E8.2 Rail-Kalkulation-Ampel + R2.5-Snapshot-Badges
- [ ] E8.3 Vorschau/PDF depth-Überschriften + €/Gast-€/Pos-Konsistenz + Regression
- [ ] E8.4 Abschluss: breite Pest-Suite, Browser-Klickstrecke komplett, fb=1-Protokoll, ROADMAP-Abschluss-Update + Office-Dev-Issue Done, Memory-Update

> **Lauf-Protokoll** (jeder Routine-Lauf hängt eine Zeile an): `| Datum | Teilschritt | Ergebnis | Commit | Notizen |`

| Datum | Teilschritt | Ergebnis | Commit | Notizen |
|---|---|---|---|---|
| 2026-07-23 | E0.1 | ✅ Spec angelegt | — | Session „Foodbook-Leitstelle A–Z"-Plan |
| 2026-07-23 | E0.2 | ✅ Doku-Abgleich | s. Git-Log „E0.2" | 5 Touchpoints: Matrix +Z19 · Spec-08-Supersede-Banner · Spec 03 L7/L8-Zweitkonsument + L3-v1-Revision · Spec 00 Phase-4-Split (4a determ. vor L7/L8 / 4b provider-gated) · Spec 12 Solver am Kapitel-Go. Reine Doku, kein Code. |
| 2026-07-23 | E1.1 | ✅ recipe_ref-Schreibpfad frei | (s. Commit) | `recipe_ref` in `BLOCK_TYPES`; `pruefeRecipeRef()` (verkauf()-Scope, visibleToTeam, keine Slot-Variante) in addBlock+updateBlock; Doktrin-Kommentar :337ff. auf Entscheidung 5 umgeschrieben; docs/foodbook.md + ROADMAP nachgezogen. Stale-Test invertiert (BLOCK_TYPES→toContain) + neuer Guard-Test. Pest Foodbook 60/60, Coverage\|Concept 122/122 grün. MCP-Enum-Angleich ist E1.4 (separat). |
| 2026-07-23 | E1.2 | ✅ recipe_ref price_basis | (s. Commit) | `blockPreis()`: recipe_ref respektiert `price_basis` — `pauschal` → VK in flachen Anteil (kein ×Pax), EK ungezählt (konsistent zu header_frei_preis/pauschal, WE-Ampel „partiell" folgt E4.4); `person`/Default bit-identisch (Per-Person). Docblock aktualisiert. 2 Pest-Tests: Mischkapitel (Konzept 4,50 €/Gast + Einzel 2,50×3 pauschal → gesamt_vk 457,50 @100Pax) + Einzel per-Person bleibt ×Pax. Pest Foodbook 62/62, Coverage\|Concept 122/122. DarreichungResolver::fuerBlock-Anteil bleibt E7.1. |
| 2026-07-24 | E1.4 | ✅ MCP recipe_ref + price_basis-Angleich | (s. Commit) | `FoodbookBlocksPostTool`: type-Enum +`recipe_ref`, neues `sales_recipe_id` (Schema + $daten + Tenancy-Check via `verkauf()`+`variant_source_recipe_id`-Guard, spiegelt `pruefeRecipeRef`, typisiertes NOT_FOUND vor Write). price_basis-Angleich: `PRICE_BASIS_MAP` (pro_person→person · **pro_stueck→pauschal** · pauschal→pauschal + kanonische Pass-throughs) → Model-Const `FoodAlchemistFoodbookBlock::PRICE_BASES` (Vokabular-Pflicht). Unbekannte Basis = VALIDATION_ERROR. Beschreibung/Description auf Duality Paket/Einzel umgeschrieben. 2 Pest (Roundtrip: pro_stueck→pauschal DB-verifiziert + pro_person→person + recipe_ref-ohne-id=VALIDATION_ERROR · Tenancy: Kind-A-Gericht als Root=NOT_FOUND). Pest McpTools 6/6, Foodbook\|Coverage\|Concept 167/167. |
| 2026-07-24 | E2.1 | ✅ Unterkapitel-UI (Ein-/Ausrücken) | (s. Commit) | Anlegen-unter-Kapitel (`kapitelNeu($parentId)`) + Reorder ▲▼ waren schon da; Lücke war Parent-Wechsel. Neu in `Foodbooks\Index`: `kapitelEinruecken` (neuer Parent = vorheriges Geschwister, erstes Geschwister nicht einrückbar) + `kapitelAusruecken` (neuer Parent = Großelternteil/Top, Top nicht ausrückbar), gemeinsamer `kapitelUnter()` (nutzt Service `moveKapitel` inkl. Zyklus-Schutz + hängt via `reorderKapitel` ans Ende der neuen Geschwister, da moveKapitel `position` nicht anfasst). Blade: ⬅/➡-Buttons in der Tree-Zeile (⬅ disabled bei parent_id===null). Kein Service-/MCP-Change (Slots bleiben flach, MCP unverändert). 1 Livewire-Pest (Ein-/Ausrücken + beide Rand-Guards). Pest Foodbook 67/67, Coverage\|Concept\|Leitstelle 126/126. |
| 2026-07-24 | E1.5 | ✅ Dedup kapitelweit | (s. Commit) | `uebernehmeVorschlag`: neue `gerichtImKapitel()`-Helper prüft Union aus recipe_ref-Blöcken (`sales_recipe_id`) + Slots ALLER concept_ref-Konzepte des Kapitels. Prüfung läuft VOR jeder Anlage → Treffer legt weder leeres Konzept noch Slot an (concept_id = führendes Kapitel-Konzept oder 0). Alter Check sah nur EIN Konzept. Quer-Kapitel-Meldung bleibt WEICH → E7.2 (`uebernehmeGericht`). 2 Pest (Doppel-Übernahme = 1 Slot + gleiches Konzept · recipe_ref-Block dedupt kapitelweit, kein Konzept/Slot). Pest FoodbookLeitstelle 5/5, Foodbook\|Coverage\|Concept 169/169. **E1 komplett** → breite Suite. |
| 2026-07-23 | E1.3 | ✅ Gericht-Picker im Block-Editor | (s. Commit) | UI-only, kein neuer Service/MCP (MCP = E1.4). Livewire `Foodbooks\Index`: `$gerichtSuche` + `gerichtHinzu()` (spiegelt `conceptHinzu`, addBlock type=recipe_ref → pruefeRecipeRef validiert) + render-Data `gerichtKandidaten` (lazy: nur bei Suche+Kapitel). Blade: Button „+ Gericht einfügen" neben „+ Concept einfügen"; Modal `fb-gericht` (Freitext-Suche, kein Kategorie-Tree); recipe_ref-Switch-Case in Block-Zeile ([Gericht]-Pill + €/Pos·pauschal + Wording); Inline-Editor recipe_ref: Wording-Override + price_basis-Select (person/pauschal, E1.2). Empty-State-Hinweis ergänzt. `php -l` ok, Blade compileString ok, Pest Foodbook 62/62 + Coverage\|Concept 122/122 grün. |
| 2026-07-24 | E2.2 | ✅ Coverage Nachfahren-Rollup | 62cdef5 | `CoverageService::istFoodbook` in 2 Durchläufe geteilt: (1) direkte Gerichte je Kapitel + parent→children-Kanten (`$kinder[$parent_id ?? 0][]`); (2) `kapitelMap[$cid]['gerichte']` = Kapitel + alle Nachfahren (`unique('id')`), `vk_pp` bleibt aus `kapitelAggregat` (schon rekursiv). Neue private `nachfahrenIds()` (iterativ, spiegelt `FoodbookService::descendantKapitelIds`, aber DB-frei — Baum ist geladen). `slotScope`-Docblock ergänzt (Kapitel-Verweis = Kapitel+Nachfahren). Model-Kommentar `FoodAlchemistFoodbook.php:32` korrigiert (Relation liefert ALLE Kapitel flach, nicht nur Top). Kein MCP-Change (Slots/Coverage-Read-Shape unverändert; `coverage.GET`-WE-Sektion + `pruefeKapitel`-Vorrangregel bleiben E4.3/E4.4). 1 Pest (Eltern-Slot ohne eigene Blöcke zählt 2 Enkel-Gerichte → erfuellt + Enkel-Basisfall). Pest Coverage 12/12, Foodbook\|FoodbookLeitstelle\|Concept 161/161. |
