# Wirtschaftlichkeits-Intelligenz — R2-Rest (Analyse · Optimierung · Preis-Governance)

> **ROADMAP-Bezug:** R2.3 + R2.4 + R2.5 (Track „Wirtschaftlichkeits-Maschine"). Der Rest der Engine — R2.1 Preis-Alarm, R2.2 Simulation, R2.6 Feedback, R2.7 Benchmark sind **gebaut**.
> **Rote Linie:** aus den Zahlen **lesen** (R2.3), aus dem Portfolio **lösen** (R2.4), Preise **verantwortet veröffentlichen** (R2.5). Alles intern-vorschlagend, nie Auto-Commit.
> **Reifegrad: R2.5 🟢 · R2.4 🟢 · R2.3 🟡** (Code-Kartierung 2026-07-19; R2.3 bleibt gated auf Q2-Format-Spec). Vorher ⚪ Dossier.

---

## 0. Code-Kartierung (verifiziert 2026-07-19)

**Margen-/Impact-Kern (reuse pur):**
- `MargeService::marge(vkNetto, ekTotal)` `:62` — DB-Kern (`marge_eur`, `marge_pct`, `wareneinsatz_pct`); `vkVorschlag(...)` `:30` (Cost-plus; `deckungsbeitrag`-Formel wirft noch, D6 offen).
- `MargeImpactService::impactFuerGps(team, gpIds, ratio, max)` `:132` + memoiertes `gpSetExposure` mit **Memory-Eviction** `:114` → **die Perf-Vorlage für den R2.4-Solver-Scorer**.
- `SignalDetektorService` — R2.1 `preisSprungMargeImpact` `:51`, plus `margeUnterZiel` `:550`, `wareneinsatzUeberZiel` `:589`, `veraltetePreise` `:418` = **Band-Trigger-Vorlagen für R2.5**. `SignalService::erzeuge` `:23` (dedup-Inbox).
- `SimulationService::simuliere` `:28` (Portfolio-Iteration, erbt Eviction) + `SimulationPostTool` (read-only POST) = **Template für `assemblierung.POST`**.

**R2.5-Crux — VK ist zu 100 % LIVE gerechnet, keine Publish/Live-Trennung:**
- Preis-Wahrheit an `foodalchemist_recipe_presentations` (`ek_portion`, `sales_net`, `sales_gross`, `price_mode`); `DarreichungService::recomputePreise` `:177` rechnet bei jedem Edit neu; `spiegleStandardVk` `:275` = Anzeige-Cache auf `recipes.sales_net`. **Keine `published_*`/`snapshot_*`/`released_at`-Spalte.**
- Snapshot-Präzedenz existiert (Kopiervorlage): `foodalchemist_foodbook_kapitel.snapshot_at/snapshot_json` (Versand-Freeze) + `KalkulationDokService::snapshot()` `:240`. → **NEU für R2.5:** ein veröffentlichter-VK-Snapshot-Layer.

**R2.3-Crux — kein Verkaufs-Ist, keine Fact-Tabelle:**
- Keine sales/verkauf-ist/transaction-Tabelle. `2026_06_12_000029_create_foodalchemist_sales_layer_tables.php` = **false friend** (Stammdaten: Aufschlagsklassen/Taxonomie, KEINE Ist-Umsätze).
- Popularität heute = `FeedbackService` (Mensch, **ohne** Verkaufsdaten; Doc-Kommentar sagt es explizit). → **NEU für R2.3:** Sales-Ist-Importer (Wording-Matcher-Muster Skript 250, Vault) + Fact-Tabelle + `MenuEngineeringService`.

**R2.4-Constraint-Quelle (reuse):** `PlanningFrameService` (R4.1) — `setHead` (`target_price_pp`, `price_min/max_pp`), Slots (`target_count`, `price_anchor`, `price_min/max`, `slot_type`), Rules (`RULE_TYPES=[diet_quota,season_coverage,nogo_ingredient,nogo_allergen,allergen_line]`, `DIET_FORMS`, `OPERATORS`, `UNITS`). `CoverageService::coverage` `:42` (Ampel) = Feasibility-Validierung/Gate. **Kandidaten-Pool:** `ConceptGeneratorService::kandidatenPool` `:235` (nur echte VK-Gerichte) + `filterFuerSlot` `:278` (harte No-Go-/Preis-Band-Filter). Der Assembler ist heute **greedy** `:152` → R2.4 ersetzt den greedy-Schritt, reutzt Pool+Filter+Bänder.

**TeamSettings:** `zielWareneinsatzPct` `:319` (`target_food_cost_pct`, Default 30), `margePct` `:311`, `preisAlarmSchwellePct` `:327`. **Kein Margen-Zielband (min/max)** → NEU für R2.5 (Migration-Muster: `2026_07_05_000001_add_price_alarm_threshold...`).

---

## 1. R2.5 — Saison-Auto-Pricing (intern-vorschlagend) · M · Etappe S1 · 🟢 unblocked

Trennung: interne Live-Marge ↔ veröffentlichter, freigegebener VK.

**Bau (code-verankert):**
- NEU `foodalchemist_vk_price_snapshots` (`presentation_id`, `sales_net`, `sales_gross`, `released_at`, `released_by`, `team_id`) — Kopiervorlage `foodbook_kapitel.snapshot_json`. Live-`recomputePreise` bleibt unberührt.
- NEU `SignalTyp::VkAnpassungEmpfohlen` + Detektor `vkAnpassungEmpfohlen(Team)` (vergleicht Live-Marge vs. Snapshot-Band, Muster `wareneinsatzUeberZiel`), in `laufen()` verdrahtet.
- NEU TeamSettings `season_margin_band_min_pct`/`_max_pct` + `max_vk_delta_pct` + `mindestmarge_pct` (Migration + Accessor).
- Batch-Freigabe-Action (Livewire) → schreibt Snapshot; Kundensicht (R3.2) liest nur Snapshot.

**DoD:**
- [ ] Saubere Trennung: interne Marge (EK live) ↔ veröffentlichter VK = freigegebener Snapshot.
- [ ] Trigger: Marge verlässt Zielband → Signal „VK-Anpassung empfohlen: N Gerichte, Richtung + Delta" (R2.1-Muster).
- [ ] Freigabe menschlich + als Batch (ein Klick veröffentlicht Snapshot; kein stiller Kunden-Preissprung).
- [ ] Kundensicht (R3.2) zeigt nur freigegebenen VK; Verfügbarkeit/Allergene live.
- [ ] Leitplanken konfigurierbar: Mindestmarge, max. VK-Delta je Freigabe.
- [ ] MCP: `vk_snapshots.GET` (read) + Freigabe explizit (write, isOwnedBy).
- [ ] Test: EK-Sprung → Signal korrekt; OHNE Freigabe bleibt veröffentlichter VK unverändert.

## 2. R2.4 — Marge-optimale Menü-Assemblierung · XL · Etappe S2 · 🟢 (R1 ✅ + R4.1 ✅)

Aus dem Portfolio **lösen**: Rahmen rein → DB-maximale Kombination raus.

**Bau:** NEU `MenuAssemblyService` — optimiert DB über `kandidatenPool`/`filterFuerSlot` unter Frame-Bändern + Diät-Quoten; validiert über `CoverageService`; Perf über die `MargeImpactService`-Eviction. **Algorithmus (E-Entscheid):** slot-unabhängige DB-Max wo Slots unabhängig; bei menü-weiten Constraints (Diät-Quote) **bounded exhaustive/Branch-and-Bound für kleine Slot-Zahlen (exakt), Constraint-aware-Greedy + Local-Swap bei Skala** (kein externer Solver-Lib in v1).

**DoD:**
- [ ] Solver: Zielpreis p.P. + Gästezahl + Coverage-Constraints (Diät-Quoten, Gang-/Stations-Gerüst, Preisspannen) → DB-maximale Kombination **nur aus echten VK-Gerichten** (`kandidatenPool`).
- [ ] Keine Halluzination; Slot ohne zulässigen Treffer bleibt leer + Begründung.
- [ ] Lösung erklärt sich: welche Constraints bindend, wie weit vom Optimum bei Lockerung X.
- [ ] MCP `assemblierung.POST` (read-only-Semantik, Template `SimulationPostTool`).
- [ ] Übernahme nur explizit (`status=draft`), kein Auto-Commit.
- [ ] Perf: Portfolio ~1.000 Gerichte < 15 s (Eviction-Muster).
- [ ] Test: kleiner Constraint-Satz mit hand-gerechneter Optimallösung **exakt** reproduziert (Branch-and-Bound-Pfad).

## 3. R2.3 — Menu-Engineering mit Ist-Zahlen · XL · Etappe S3 · 🟡 gated auf Q2

Popularität × Deckungsbeitrag über echtes Verkaufs-Ist.

⚠️ **HARTES Gate:** Verkaufs-Ist-Import-Format-Spec muss stehen ([13](13_Preis_Katalog_Ingest_Q2.md)/Q2). **Interim-Wert ohne Gate:** die Stars/Renner/Schläfer/Penner-Matrix kann **v0 auf `FeedbackService`-Popularität** (Mensch) laufen → sofort nutzbar; Sales-Ist ersetzt die Popularitäts-Achse, sobald Q2 steht.

**Bau:** NEU Fact-Tabelle `foodalchemist_sales_facts` (`dish_id`, `period`, `qty_sold`, `revenue`, `source`); NEU Sales-Ist-Importer (Fuzzy-Zeile→Gericht, Wording-Matcher-Muster 250, Unmatched → Review-Queue); NEU `MenuEngineeringService` (Popularität × `MargeService::marge`).

**DoD:**
- [ ] Import-Format-Spec dokumentiert + Beispieldatei eines echten Caterers geladen (Q2-Gate).
- [ ] Matching Verkaufsposition → VK-Gericht mit Review-Queue für Unmatched (Skript-250-Muster).
- [ ] Stars/Renner/Schläfer/Penner-Matrix (Popularität × DB) je Konzept/Zeitraum — **v0 auf Feedback-Popularität, v1 auf Sales-Ist**.
- [ ] DB-Ranking + W%-Ampeln übers Portfolio, facetten-filterbar.
- [ ] ≥1 echter BHG-Caterer-Datensatz durchgelaufen, mit Dominique plausibilisiert.

## 4. Reihenfolge + Reuse-vs-Neu

```
R2.1 ✅ + R3.1 ✅ ──► S1 R2.5 (unblocked)
R1 ✅ + R4.1 ✅ ──► S2 R2.4 (unblocked)
Q2-Format-Spec ──► S3 R2.3 (HARTES Gate; v0 auf Feedback-Popularität vorher)
```
**Empfehlung:** S1 R2.5 zuerst (kleiner, unblocked) → S2 R2.4 (Solver) → S3 R2.3 (nach Q2; v0-Matrix interim).

| Reuse | Neu |
|---|---|
| MargeService, MargeImpactService (+Eviction), SimulationService/-Tool, SignalService/Detektor-Muster, PlanningFrameService+Rules, CoverageService, `kandidatenPool`/`filterFuerSlot`, TeamSettings, Snapshot-Muster (foodbook) | R2.5: `vk_price_snapshots`+Release+`VkAnpassungEmpfohlen`+Band-Settings · R2.4: `MenuAssemblyService`+`assemblierung.POST` · R2.3: `sales_facts`+Sales-Importer+`MenuEngineeringService` |

## 5. Bewusste Nicht-Ziele
- Kein Auto-Publish von Preisen — Freigabe menschlich, als Batch (R2.5).
- Keine Halluzination im Solver — nur echte VK-Gerichte, Lücke bleibt Lücke.
- Kein Import ins Blaue — R2.3 erst mit dokumentierter Format-Spec (v0-Matrix auf Feedback ausgenommen).
- Kein Vertriebs-/Angebots-Funnel in FA (Grenze zum Event-Modul; s. [10](10_Angebots_Funnel_Brief_Parser_R6-2.md)).

*Verzahnt: R1 (Masse), R4.1 (Constraints), R2.1/R3.1 (gebaut), [13](13_Preis_Katalog_Ingest_Q2.md)/Q2 (Gate für R2.3), [08](08_Planungs_und_Kreativ_Ebene.md) (Solver am „Go"). Dossier 2026-07-18, bau-reif (R2.4/R2.5) 2026-07-19.*
