# Spec 41 — Planungsmodul-Qualität: Struktur-Anker, GP-Grounding, Dedup & Wissensmodul-Integration

> **Tracking:** Office Dev-Package 23, Features-Board. Prompt-/Service-/Retrieval-Änderung + neue Regelwerk-Wissensdocs; **kleiner DB-Schema-Umbau** (evtl. `FoodAlchemistPlanningFrameSlot::SLOT_TYPES`-Erweiterung). Komplementär zu Spec 36 (Matching/Korrektur), Spec 37 (Grounding/Typ-Niveau) und Spec 40 (Leitstelle-Spine) — diese Spec = die **Struktur-/Reihenfolge-Seite** des Generierens + die **Wissensmodul-Lieferung** der Regelwerke.

**Status:** Entwurf, mit Dominique erarbeitet 2026-08-21 (Qualitäts-Session, Kaskade einmal komplett durchgetestet). Umsetzung phasenweise (P1 zuerst). **Laufender Fall-Nachweis:** Vault `07_WISSEN/07.01_Lebensmittel_und_Gastronomie/_Planungsmodul_Qualitaet_Log.md`.

---

## Anlass

Das Planungsmodul (Kaskade-Generator) erzeugt reproduzierbar Qualitätsfehler über **alle drei** Erzeugungs-Stufen. In der Session wurde je ein Brief pro Stufe gefahren und der Output gegen das vorhandene F&B-Wissen sowie gegen den echten Code geprüft. Ergebnis: **keine KI-Zufälle, sondern fehlende bzw. falsch verdrahtete Regeln.**

| Fall | Stufe | Brief (Achsen) | Kern-Defekt |
|---|---|---|---|
| 001 | Basisrezept | Tomatencremesuppe (Hybrid · From Scratch) | GP-Varianten „Tomaten: frisch, **geachtelt**" (36 kg / 56 %), „Zwiebeln: geachtelt", „Karotten: **mini, gemischt**" + Zutaten nach **Anteil-%** sortiert statt Koch-Reihenfolge |
| 002 | Gericht | Rinderfilet Herbst (Voll kreativ · Gehoben) | Komponenten nach Anteil-% · **Dubletten frisch angelegt** trotz Bestand (Kürbispüree/Rinderjus) · „**Adji Kresse**" (halluzinierter Garnitur-Name) |
| 003 | Concept | Lunchbuffet Tagung (Voll kreativ) | Gerüst kollabiert auf **1 Position „Lunchbuffet"** statt Menü-/Buffet-Sektionen |

**Cross-Stage-Befund (drei durchgehende Ursachen):**
1. **Strukturelle Soll-Regeln fehlen bzw. greifen nicht** (Reihenfolge, Menü-Archetyp) — auf keiner Ebene codifiziert/verdrahtet → der Generator fällt auf triviale Defaults (Anteil-%-Sort bzw. 1-Position-Kollaps).
2. **GP-/Namens-Grounding zu grob** — über-spezifizierte Varianten (geachtelt/mini) + erfundene Namen ohne GP-Abgleich.
3. **Dedup fehlt als Guard** — Wiederverwendung passiert arbiträr, „Voll kreativ" dupliziert still.

---

## Ist-Analyse (code-verifiziert, kanonischer Clone `platform/modules/platforms-foodalchemist`)

- **Reihenfolge:** keine Logik. Zutaten-`position` = **LLM-Emissions-Reihenfolge** (`RecipeService::syncIngredients` :618, Docblock „Reihenfolge = Array-Reihenfolge"). Das `role`-Feld (komponente/beilage/aroma_treiber/garnitur) wird erfasst (:208), aber **nie** zum Sortieren genutzt. Der beobachtete perfekt absteigende Anteil-%-Sort ist deshalb entweder LLM-Emission oder ein Anzeige-Sort → **muss verifiziert werden** (siehe P1-B2).
- **Concept-Gerüst:** kein Menü-Archetyp. Die Slot-Liste kommt komplett aus dem `concept.brief_geruest`-LLM (`config/foodalchemist.php:854`); der `system`-Prompt sagt wörtlich *„Du erfindest NICHTS: nur, was der Brief hergibt"*, `task` nur *„Gänge/Stationen aus dem Anlass ableiten (Menü→gang, Buffet→station)"*. `menueGaengeCap` (~:586) **kappt** die Gänge nur, expandiert nie. `struktur_typ='buffet'` ändert nur den Slot-Typ, nicht die Anzahl → Container-Brief → 1 Position.
- **GP-Grounding:** `IngredientMatchService::matchIngredient` (:46) / `candidatesFor` (:227); `prefer_raw` aus `convenience=from_scratch` (`RecipeGeneratorService:113`); §5-Alias `MatchHeuristics::DEFAULT_GP_ALIAS_SCORE` (:17) + 1-Token/prefer_raw-Guard (:505); Spezifitäts-Guard `TokenEngine:157`. **Basis-Gemüse (Tomate/Zwiebel/Karotte/Sellerie) fehlen in den §5-Defaults** → keine neutrale Grundform erzwungen → die über-spezifizierte Variante gewinnt reproduzierbar.
- **Dedup/Reuse:** kein eigener Schritt. Reuse passiert arbiträr über Name-Exakt-Match (Fall 002: „Braune Tellerlinsen" übernommen, Kürbispüree/Rinderjus frisch geplant). „Voll kreativ" = „Bestand ignorieren" absolut.
- **Wissensmodul (liefert Regeln aktuell NICHT sauber):** `regelwerk`-Abruf ist in `KnowledgeContextService::regelwerkBlock` (:422) hart verdrahtet auf **ein** Doc (`slug LIKE '%basisrezept%'`) + extrahiert nur das **§2–§5-Fenster** (`extrahiereRegelwerkKern`). Es existiert **nur** `regelwerk:always` (kein discovery/grounding-Handler). Der `concept.brief_geruest`-Feature zieht **gar kein** `regelwerk` (nur `trend:discovery`). Kategorie „Regelwerke" enthält aktuell **4** Docs (Basisrezepte · Grundprodukte · Lieferantenartikel · Verkaufsgerichte) — **kein Concept**. **Qdrant ist für `regelwerk` irrelevant** (rein lexikalisch aus MySQL); Qdrant/Discovery betrifft nur `domain`/`pairing`/generisch und ist durch `FOODALCHEMIST_SEMANTIC_SEARCH` (default false) gated.

---

## Sparring-Kern / Entscheidungen

„Qualität" hat hier eine strukturelle Wurzel, die quer über alle Stufen zieht: **die Regeln, die das Gerüst und die Reihenfolge bestimmen, wurden nie geschrieben — und der eine Ort, an dem Regeln stehen (das Wissensmodul), liefert sie nicht an den Generator.** Daraus folgt die Zielarchitektur.

| Frage | Entscheidung |
|---|---|
| Wo werden Regelwerke gepflegt (SSOT)? | **FA-Wissensmodul (UI/MCP)** = Single Source of Truth; Vault = Spiegel/Backup (Verschiebung ggü. vault-first — CLAUDE.md nachziehen) |
| Wie liefert das Modul Regeln? | **Zwei-Klassen:** Regelwerke (die Regeln/Technik) → **hart/„always" in den Feature-Prompt**; Rest (Domäne/Pairing/Trend) → **Discovery/Qdrant** |
| Concept-Regelwerk | **fehlt → neu anlegen.** Ein **Concept = Zusammenstellung** (wie WaWi-Menü-Bereich): klassisches Konzept/Menü · Lunchbuffet · **Paket** (wiederverwendbar, Einzelpreise + Paketpreis) |
| Gerüst-Durchsetzung (Concept) | Regelwerk_Concept **immer im Prompt** **+ deterministischer Code-Guard** (Container bricht garantiert in Sektionen) |
| Reihenfolge | SOLL = **logische Koch-/Verwendungs-Reihenfolge**; Hebel = Prompt-Direktive (LLM kennt die Sequenz) + generalisierter Regelwerk-Block, NICHT ein blinder Code-Sort |
| Dedup | **modus-unabhängiger immer-an-Guard** (DF-1): Datenbank=still reuse · Hybrid=reuse sonst neu · Voll kreativ=neu erlaubt, aber **Kollision flaggen**, nie still duplizieren |
| Umfang/Takt | **erst Regeln autoren, dann verdrahten** (Code+Deploy als eigene Session) |

---

## Zielarchitektur

```
Wissensmodul (SSOT, Pflege via UI/MCP)
├── Kategorie regelwerk   → HART / "always" in den Feature-Prompt (pro Feature das richtige Regelwerk)
│     ├── Basisrezepte  → ai_generate_recipe   (inkl. §12 Reihenfolge)
│     ├── Concept       → concept.brief_geruest (NEU)
│     ├── Grundprodukte, Lieferantenartikel, Verkaufsgerichte → jeweiliges Feature
└── domain / pairing / trend → DISCOVERY (semantisch, Qdrant), gated FOODALCHEMIST_SEMANTIC_SEARCH
```

Vault bleibt Autoren-/Backup-Spiegel; Verhalten kommt aus Prompt (hart-injizierte Regelwerke) + deterministischem Code-Guard.

---

## Werkpakete

### P1 — Struktur-/Reihenfolge-Anker + Wissensmodul-Integration  *(Priorität 1 — größter Hebel; adressiert RC-2/RC-4, Fälle D4/E1/C1)*

#### P1-A — Regelwerke autoren (SSOT, kein Deploy)

- **A0 — Grounding (read-only, keine Erfindung):** FA-Concept-/Paket-/Preis-Datenmodell sichten, bevor `Regelwerk_Concept` geschrieben wird: `FoodAlchemistPlanningFrameSlot::SLOT_TYPES` (`src/Models/FoodAlchemistPlanningFrameSlot.php:21`), Slot-Preisfelder (`price_anchor`/`price_min`/`price_max` aus `concept.brief_geruest`), ob **Paketpreis** vs. Einzelpreise im Concept-Datenmodell existiert.
- **A1 — §12 „Zutaten-/Komponenten-Reihenfolge" (in „Regelwerk Basisrezepte"):**
  - SOLL = logische Koch-/Verwendungs-Reihenfolge: mise-en-place → Basis/Fett → Aromaten → Hauptmasse → Flüssigkeit/Fond → Bindung → Würze/Säure/Finish; **Garnitur & Abschmecken zuletzt**. **NICHT** nach Menge/Anteil.
  - Gericht-Komponenten: Aufbau-/Plating-Reihenfolge (Vault `Menue_Architektur.md`: Sauce/Basis → Hauptkomponente → Beilage → Garnitur → Finishing); `role` = Sortier-Anker.
  - Abgrenzung **LMIV** (Deklaration = absteigende Menge, `Hygiene_HACCP.md`) = separater Export. Abgrenzung **VK-Name** (nach Wichtigkeit, `Regelwerk_Verkaufsgerichte`) = nur Name.
  - Anti-Pattern: Zutatenliste nach Anteil-% sortiert (Fälle D4/E1).
  - Pflege im Modul-Doc „Regelwerk Basisrezepte" (UI/MCP) + Vault-Spiegel; Backlog-Idee §12 → §13 umbenennen; v1.4 → v1.5 + Changelog.
- **A2 — Neues `Regelwerk_Concept`** (Modul via MCP `knowledge.POST`, `category=regelwerk`, `slug=regelwerk.concept`, team-owned; Vault-Spiegel `Regelwerke/Regelwerk_Concept.md`):
  - **§1 Definition:** Concept = Zusammenstellung (WaWi-Menü-Bereich-Äquivalent), ausgabeform-übergreifend; referenziert Gerichte/Basisrezepte/Pakete.
  - **§2 Archetypen:** (a) klassisches Konzept/Menü (Gänge), (b) Buffet/Lunchbuffet (Sektionen/Stationen), (c) **Paket** = wiederverwendbare Zusammenstellung, in anderen Concepts referenzierbar.
  - **§3 Gerüst-Regel:** Container (Menü/Buffet/Paket) ist **nie eine atomare Position** → immer Sektions-/Gänge-Gerüst mit Kapitel-Überschriften + Platzhalter-Slots (`target_count` je Sektion), jede Position einzeln absegn-/regenerierbar.
  - **§4 Vokabular:** Menü-Gänge 3/5/7/9 (`Menue_Architektur`) · Buffet-Stationen: Kalte Vorspeisen/Salate → Suppe (optional) → Warme Hauptkomponente(n) inkl. **Carving (Pflicht > 50 Pax)** → Sättigungsbeilagen (Stärke + Gemüse) → Dessert/Sweet-Table → Getränke; Breite **8–15 Positionen** (`Speisekarten_Engineering`) · Tagung = **Pausen-Cluster + Lunch-Block** (`Anlass_Serviceformen`).
  - **§5 Preislogik:** Einzelpreise je Position **+** Paketpreis; Anbindung an `price_anchor/min/max` (nach A0).
  - **§6 Abgrenzung:** Concept ≠ Einzel-Gericht ≠ Basisrezept.
- **A3 — SSOT-Shift dokumentieren:** CLAUDE.md-Sync-Notiz (Vault↔FA-Modul) auf „FA-Modul = Pflege-Home, Vault = Spiegel" anpassen; Versionierungs-/Backup-Regel für modul-gepflegte Regeln festhalten.

#### P1-B — Wissensmodul-Integration + Generator-Wiring (Build+Deploy-Session)

- **B1 — `regelwerkBlock` generalisieren** (`src/Services/Ai/KnowledgeContextService.php`): statt fixem `%basisrezept%` + §2–§5 → **pro Feature das richtige Regelwerk** hart injizieren (Map `ai_generate_recipe`→Basisrezepte, `concept.brief_geruest`→Concept), Extraktionsfenster erweitern/konfigurierbar (damit §12 und das Concept-Regelwerk vollständig ankommen). Routing-Zeile `concept.brief_geruest / regelwerk / always` ergänzen (`KnowledgeImportCommand::seedRoutings` + Runtime `KnowledgeRoutingService::set()`).
- **B2 — Reihenfolge verdrahten:**
  - Prompt-Direktive an `recipe.generator` (`config/foodalchemist.php` ~:451) + `vk.generator` (~:547): Zutaten in Koch-/Verwendungs-Reihenfolge, nicht nach Menge (§12).
  - **Anzeige-Sort-Check (MUSS):** `src/Livewire/Planung/Index.php::planVorschau()` (~:1518), `resources/views/livewire/planung/partials/step-zeile.blade.php` und das Zutaten-Grid auf `orderBy`/`sortBy` nach `menge`/`anteil` prüfen; falls vorhanden → auf `position` umstellen (sonst bleibt B2 in der Anzeige wirkungslos).
- **B3 — Concept-Gerüst: Regelwerk-hart + deterministischer Guard:**
  - Regelwerk_Concept via B1 always in `concept.brief_geruest`; `system` „erfindest NICHTS" so **präzisieren, dass strukturelles Sektionieren erlaubt** ist (Struktur ≠ Gerichte/Preise/Fakten erfinden).
  - **Code-Guard:** neuer Helper in `src/Services/ConceptGeneratorService.php` (neben `menueGaengeCap` ~:586 / `menueDiaetQuotenMerge` ~:628): erkennt Archetyp (Menü/Buffet/Paket aus Brief + `menueAchsen`) und **expandiert garantiert** in Sektions-/Gänge-Slots; anwenden nach `sanitizeGeruestWerte()` **vor** `frames->replaceStructure()` in **beiden** Pfaden (`generiereAusBrief` ~:264, `geruestAusBriefFuerOwner` ~:528). `FoodAlchemistPlanningFrameSlot::SLOT_TYPES` ggf. erweitern.
- **B4 — (optional/Ops) Discovery/Qdrant für „den Rest":** demo `FOODALCHEMIST_EMBEDDING_STORE=qdrant` + `FOODALCHEMIST_SEMANTIC_SEARCH=true` bestätigen/aktivieren; Backfill `php artisan foodalchemist:knowledge-embed` off-peak (Runbook `docs/PLANUNG/34_Qdrant_Hetzner_Hosting_Runbook.md`). Betrifft NUR discovery-Kategorien, nicht die Regelwerke.

### P2 — GP-/Namens-Grounding  *(Priorität 2 — adressiert RC-1/E4, Fälle D1/D2/D3/E4)*

- **FIX-1 — Basis-Gemüse-Defaults codifizieren** (Vault `Zutaten_Default_Logik.md` §3 + `Regelwerk_Basisrezepte` §5): Tomate/Zwiebel/Karotte/Sellerie → neutrale Form „frisch, ganz"; Frische/Convenience-Achse schaltet auf passiert/TK/konserviert. Als §5-Alias verdrahten (`MatchHeuristics`, `DEFAULT_GP_ALIAS_SCORE`).
- **FIX-2 — `prefer_raw` erweitern** (`MatchHeuristics` :505): bei From-Scratch Zuschnitts-Token (geachtelt/gewürfelt/mini/gemischt) auf Roh-Grundform reduzieren; Abdeckung Basis-Gemüse prüfen.
- **FIX-5 — Namens-GP-Abgleich:** Garnitur-/Zutat-Namen gegen GP-Katalog prüfen — kein freies Erfinden ohne GP (E4 „Adji Kresse").

### P3 — Dedup als modus-orthogonaler Guard  *(Priorität 3 — DF-1 entschieden; adressiert RC-3, Fälle E2/E3)*

- **Entscheidung DF-1 (fix):** Dedup = **modus-unabhängiger immer-an-Guard**. Datenbank=still reuse · Hybrid=reuse sonst neu · **Voll kreativ=neu erlaubt, aber Kollision flaggen** („existiert bereits als X — Variante anlegen / übernehmen?"), nie stilles Duplizieren.
- **FIX-4 — Dedup-Guard bauen** + Reuse-Robustheit klären (warum matchte nur „Tellerlinsen", nicht Kürbispüree/Rinderjus — `MenuCandidatePoolService` / `besterKandidat`, Name-Match-Logik). Voll-kreativ-Kollisions-Flag im UI.

### Offene Design-Frage (nicht blockierend)
- **DF-2 — Concept: direkter Entwurf vs. „KI-Kopf: Plan ausarbeiten" (`concept.plan`).** Das ausgearbeitete Gerüst-mit-Text existiert bereits via KI-Kopf; per-Position „Absegnen"/„mit Feedback neu generieren" existiert ebenfalls. Entscheidung: direkten Pfad fixen (P1-B3 tut das) und/oder KI-Kopf als Default bewerben.

---

## Umsetzungs-Reihenfolge

1. **P1-A** — Regelwerke autoren (A0 → A2) + SSOT-Doku (A3) + Logbuch/Memory. Kein Deploy.
2. **P1-B** — Wissensmodul-Integration + Wiring + Verifikation (eigene Build+Deploy-Session).
3. **P2** — GP-Grounding-Härtung (eigene Session).
4. **P3** — Dedup-Guard (eigene Session).

> **Prinzip:** erst Regeln schreiben, dann verdrahten. Verhalten wird erst nach der jeweiligen B-/Fix-Session in demo gemessen — nichts vorher als „gefixt" melden.

---

## Was ist Code (PR) vs. Modul/Vault vs. demo-Aktion

- **PR (deploy):** `KnowledgeContextService.php` (B1 regelwerkBlock-Generalisierung + Routing), `config/foodalchemist.php` (B2 Prompt-Direktiven + B3 system-Präzisierung), `Livewire/Planung/Index.php` + Blade/Grid (B2 Sort-Check), `ConceptGeneratorService.php` (B3 Guard), ggf. `FoodAlchemistPlanningFrameSlot.php` (SLOT_TYPES), `KnowledgeImportCommand::seedRoutings` (Routing-Seed), Tests, diese Spec. Später P2 (`MatchHeuristics`), P3 (Dedup-Service).
- **Modul (UI/MCP, SSOT):** §12 in „Regelwerk Basisrezepte"; neues `regelwerk.concept`; Routing `concept.brief_geruest/regelwerk/always`.
- **Vault (Spiegel):** `Regelwerk_Basisrezepte.md` §12, neues `Regelwerke/Regelwerk_Concept.md`, `Zutaten_Default_Logik.md` (P2), `_Planungsmodul_Qualitaet_Log.md` (Nachweis).
- **demo-Aktion:** ggf. `knowledge-embed`-Backfill (B4, off-peak); Re-Run der 3 Briefs zur Abnahme.

---

## Akzeptanzkriterien

- **P1:** `regelwerkBlock` injiziert pro Feature das korrekte Regelwerk (Basisrezepte→Rezept inkl. §12, Concept→`concept.brief_geruest`). Re-Run der 3 Briefs in demo: (1) Tomatencremesuppe Zutaten in Koch-Reihenfolge, (2) Rinderfilet Komponenten in Aufbau-Reihenfolge, (3) Lunchbuffet als **Mehr-Sektions-Gerüst** (nicht 1 Position). Screenshots im Log.
- **P2:** „Tomaten"/„Karotten" ohne Modifikator erden auf neutrale Grundform (nicht geachtelt/mini); From-Scratch reduziert Zuschnitte auf Roh-Form; keine erfundenen Garnitur-Namen ohne GP.
- **P3:** Voll kreativ dupliziert existierende Basisrezepte nicht mehr still, sondern flaggt Kollision.
- **Regression:** `./fa_test.sh` (parallel), `PlanungLeitstelleTest`, Recipe-/Concept-Smoke auf **MySQL** (nicht nur SQLite); kein `artisan optimize` in der Sandbox; Registry-/Routing-Tests mitziehen. Deploy per demo-Update-Mechanik.

## Offene Punkte
- Editierbarkeit importierter `regelwerk`-Docs via MCP (global-master `team_id NULL` read-only vs. team-owned) → Edit-Pfad für §12 (UI vs. Vault + `knowledge-import`).
- SSOT-Shift: Versionierung/Backup modul-gepflegter Regeln + CLAUDE.md-Rewrite.
- `Regelwerk_Concept` ↔ FA-Paket-/Preis-Datenmodell (Ergebnis A0).
- Reuse-Match-Robustheit (P3): Ursache „Tellerlinsen matched, Rest nicht".

## Referenzen
- **Fall-Log:** Vault `07_WISSEN/07.01_Lebensmittel_und_Gastronomie/_Planungsmodul_Qualitaet_Log.md` (Fälle 001–003, RC-1..4, FIX-1..6, DF-1/2, Cross-Stage-Synthese).
- **Wissen:** `Zutaten_Default_Logik.md`, `Regelwerk_Basisrezepte` (§2/§5/§12), `Menue_Architektur.md`, `Anlass_Serviceformen.md`, `Speisekarten_Engineering.md`.
- **Code-Anker:** `KnowledgeContextService.php` (:53/:112/:422), `ConceptGeneratorService.php` (:586/:264/:528), `RecipeGeneratorService.php` (:113), `RecipeService.php` (:541/:618), `IngredientMatchService.php` (:46/:227), `MatchHeuristics.php` (:17/:505), `TokenEngine.php` (:157), `config/foodalchemist.php` (:445/:542/:854).
- **Verwandte Specs:** 36 (Rezeptqualität/Korrektur), 37 (KI-Erstellen Typ/Niveau), 40 (Leitstelle-Spine), 34 (Qdrant-Runbook).
