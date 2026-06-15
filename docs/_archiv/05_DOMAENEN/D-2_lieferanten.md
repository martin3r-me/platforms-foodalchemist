---
typ: Domänen-Spec
domaene: D-2
stand: 2026-06-10
status: ausgearbeitet
mvp: MVP
---

# D-2 — Lieferanten & LA

> **Services (stateless):** `SupplierService`, `SupplierItemService`, `PriceService`, `LaGpMatchService`
> **Hängt ab von:** D-1 (Vokabulare/Lookups) · **MVP-Status (⚠D5):** MVP
> **Kurzbeschreibung:** Stammdaten-Pflege (Read-only-Quelle ⚠D1), LA↔GP-Matching mit Review-Queue, Lead-LA-Wahl, Preise + Anomalie-Erkennung, Stamm-Lieferant-Matrix. 46 Alt-Commands. Trägt die meiste Geschäftslogik der MVP-Frühphase.

Diese Spec **referenziert** die Grundlogiken statt sie zu duplizieren: Match-Hierarchie/Schwellen → **GL-05**, Lead-LA-Kaskade → **GL-03**, Preislogik/aktiver Preis → **GL-11**, Allergen-Aggregation → **GL-01**, KI-Hüllen → **GL-06**, KI-Lebenszyklus → **GL-07**. Datenmodell → `02_DATENMODELL.md` A.1/A.2.

## 1. Scope & Ressourcen

Filter `domain_id == D-2` aus `_tools/inventory.csv` (46 Commands, alle MVP). Vier Services nach Verantwortung geschnitten.

| Ressource (Stamm) | Alt-Commands | Ziel `Service::methode` | Livewire-View/Action | MVP |
|---|---|---|---|---|
| **Lieferant** | `list_suppliers`, `get_supplier`, `create_supplier`, `update_supplier`, `set_supplier_inactive`, `list_supplier_types`, `get_supplier_stamm` | `SupplierService::list/get/create/update/setInactive` | `Suppliers/Index` (Sidebar+Detail) · Supplier-Editor-Modal | ✅ |
| **Stamm-Lieferant-Matrix** | `list_stamm_lieferanten`, `add_stamm_lieferant`, `remove_stamm_lieferant`, `set_stamm_lieferant_wg`, `derive_stamm_lieferant_wg` | `SupplierService::listCore/addCore/removeCore/setCoreWg/deriveCoreWg` | `Suppliers/CoreMatrix` (Lieferant×WG) | ✅ |
| **Lieferantenartikel (LA)** | `list_supplier_items`, `search_supplier_items_global`, `get_supplier_item`, `create_supplier_item`, `update_supplier_item`, `delete_supplier_item`, `set_supplier_item_discontinued`, `bulk_set_la_discontinued`, `bulk_delete_la`, `list_unmapped_las` | `SupplierItemService::list/searchGlobal/get/create/update/delete/setDiscontinued/bulkSetDiscontinued/bulkDelete/listUnmapped` | `Suppliers/Index` (Artikel-Tabelle) · `SupplierItems/GlobalSearch` · LA-Editor-Modal | ✅ |
| **LA-Allergene / -Deklarationen** | `get_la_allergens`, `set_la_allergens`, `get_la_declarations`, `set_la_declarations` | `SupplierItemService::getAllergens/setAllergens` (→ GL-01) `/getDeclarations/setDeclarations` | LA-Editor-Modal (Allergen-/Zusatzstoff-Tab) | ✅ |
| **Preise** | `get_la_prices`, `create_la_price`, `update_la_price`, `delete_la_price`, `detect_price_anomalies`, `ai_plausi_check_price` | `PriceService::listForLa/create/update/delete` (→ GL-11) `/detectAnomalies/aiPlausiCheck` (→ §5) | LA-Editor (Preis-Tab) · `Prices/Anomalies` · PriceAnomaly-Modal | ✅ |
| **LA↔GP-Mapping (manuell)** | `set_la_gp_mapping`, `bulk_set_la_gp`, `unlink_la_from_gp` | `LaGpMatchService::setMapping/bulkSetGp/unlink` (→ GL-05/GL-03) | LA-Editor (GP-Zuordnung) · Bulk-GP-Assign-Modal | ✅ |
| **LA↔GP-Matching (KI + Accept)** | `ai_match_la_to_gp`, `ai_bulk_match_unmapped_las`, `ai_bulk_match_phantoms_via_matrix`, `ai_rank_las_for_term`, `accept_la_gp_match`, `reject_la_gp_match`, `accept_gp_la_suggestions` | `LaGpMatchService::aiMatch/aiBulkMatchUnmapped/aiBulkMatchPhantoms/aiRankForTerm/accept/reject/acceptGpSuggestions` (→ §5) | `Matching/ReviewQueue` (V-10) · LA-Match-Wizard-Modal · BulkLaMatch-Modal | ✅ |
| **Lead-LA** | `set_gp_lead_la`, `apply_lead_la`, `recompute_all_lead_las` | `LaGpMatchService::setLeadLa/applyLeadLa/recomputeAllLeadLas` (→ **GL-03**) | GP-Detail (Lead-Badge, in D-3) · Bulk-Aktion (Queue-Job) | ✅ |
| **Diagnose** | `db_info` | `SupplierService::dbInfo` (Zähler fürs Dashboard) | Dashboard-Kachel | ✅ |

## 2. Datenmodell-Ausschnitt

Aus `02_DATENMODELL.md` A.1 (globale Stammdaten, `team_id` nullable ⚠D1) + A.2:

- **`foodalchemist_suppliers`** (120) · **`foodalchemist_supplier_items`** (264.515 — größte Tabelle; `designation` → `LOWER()`-Index; `qty`, `unit_id`, `is_discontinued`, `article_number`, `ean_packaging_unit`, `ean_ordering_unit`).
- **`foodalchemist_prices`** (221.591, **Read-only**, Necta-`tenant_id` fällt weg). Sichtbarkeit pro Team = **offene D1-Detailfrage** (Rückvergütungs-Konditionen sensibel).
- **`foodalchemist_item_allergens`** (139.012, → GL-01) · **`foodalchemist_item_declarations`** (Zusatzstoffe) · **`foodalchemist_item_nutritionals`** (127.644) · **`foodalchemist_supplier_priorities`** (Umsatz-Ranking).
- **`foodalchemist_core_suppliers` / `_core_supplier_wgs`** — Stamm-Lieferant×WG-Matrix; **steuert GL-03 (Lead-LA Stufe 0) und GL-05 (LA-First-Reihenfolge)**.
- **`foodalchemist_supplier_item_structures`** (9.803, ex `wawi_la_structured`): 1 Zeile pro LA, `supplier_item_id` UNIQUE, `gp_id` FK (nullable), `match_method` (Enum §12 GL-05), `match_confidence`, `klassifikator` (technische Herkunft), `needs_review` (bool), `review_grund`. **597 `needs_review=1`** im Bestand → treibt V-10.
- **`foodalchemist_gps`** (Ziel des Mappings; Pflege in D-3) trägt `lead_la_supplier_item_id`, `n_las_total`.
- **`foodalchemist_ai_call_log`** — Audit jedes KI-Match-Vorschlags (auch abgelehnt; +`user_id`+`team_id` für V-09/V-16).
- **Views als Eloquent-Scopes** (02_DATENMODELL E.3): `v_active_prices`-Logik → `Price::active()`-Scope (GL-11), `v_supplier_full`/`v_current_items` → Query-Builder.

## 3. Services & Methoden

Signaturen-Niveau (keine Implementierung). Schreib-Operationen mit Multi-Step-Charakter in DB-Transaktion (V-07); Fehler typisiert mit Code (V-06).

```php
// — SupplierService —
public function list(SupplierFilter $f): Collection;
public function get(int $id): Supplier;
public function create(SupplierInput $i): int;
public function update(int $id, SupplierInput $i): void;
public function setInactive(int $id, bool $inactive): void;
public function listCore(): Collection;                          // Stamm-Lieferanten
public function setCoreWg(int $supplierId, array $warengruppen): array; // Matrix-Zeile
public function deriveCoreWg(int $supplierId): array;            // WGs aus vorhandenen LAs ableiten (Vorschlag)
public function dbInfo(): DbInfo;

// — SupplierItemService (Stammdaten Read-only ⚠D1: Schreib-Pfade Admin-gated) —
public function list(int $supplierId, ItemFilter $f): Paginator; // onlyActive/includeInactive/Suche
public function searchGlobal(string $q): Collection;             // lieferantenübergreifend
public function get(int $id): SupplierItem;
public function create(SupplierItemInput $i): int;
public function update(int $id, SupplierItemInput $i): void;
public function setDiscontinued(int $id, bool $v): void;
public function bulkSetDiscontinued(array $ids, bool $v): int;
public function bulkDelete(array $ids): int;                     // kaskadiert Preise/Allergene/Struktur
public function listUnmapped(UnmappedFilter $f): Paginator;      // gp_id IS NULL, aktiv
public function getAllergens(int $id): LaAllergens;              // GL-01
public function setAllergens(int $id, LaAllergens $a): void;     // GL-01
public function getDeclarations(int $id): LaDeclarations;
public function setDeclarations(int $id, LaDeclarations $d): void;

// — PriceService (→ GL-11) —
public function listForLa(int $itemId): Collection;             // inkl. preis_kategorie-Accessor
public function create(int $itemId, PriceInput $i): int;        // Append-only-Historie (GL-11 §3.3)
public function update(int $priceId, PriceInput $i): void;
public function delete(int $priceId): void;
public function detectAnomalies(?float $thresholdPct, ?int $limit): Collection;
public function aiPlausiCheck(int $itemId): AiResult;           // → §5

// — LaGpMatchService (→ GL-05/GL-03/GL-07) —
public function setMapping(int $itemId, ?int $gpId, ?string $reviewGrund): void;  // manual, sticky (I2)
public function bulkSetGp(array $itemIds, ?int $gpId): int;
public function unlink(int $itemId): void;                       // löst Mapping, triggert Lead-Neuwahl (GL-03 I4)
public function aiMatch(int $itemId): LaMatchSuggestion;         // Einzel-Wizard, Top-3 (§5)
public function aiBulkMatchUnmapped(BulkMatchOptions $o): BulkLaMatchResult; // Queue-Job (V-15)
public function aiBulkMatchPhantoms(PhantomMatchOptions $o): PhantomMatchResult;
public function aiRankForTerm(string $term): array;             // LAs zu Suchbegriff ranken
public function accept(AcceptLaMatchDto $i): int;                // UPSERT + ai_call_log + n_las_total + pickLeadLa
public function reject(int $callLogId): void;
public function acceptGpSuggestions(AcceptGpLaSuggestDto $i): int;
public function setLeadLa(int $gpId, ?int $itemId): void;        // manueller Override, Validierung GL-03 I2
public function applyLeadLa(int $gpId): ?int;                    // GL-03 pick_lead_la
public function recomputeAllLeadLas(): int;                     // Bulk + nachgelagerter GL-02-Recompute (Job-Kette)
```

## 4. Livewire-Komponenten & UI-Fluss

**Ausgangslage Alt-App (`Lieferanten.tsx`):** Master-Detail — links Lieferanten-Sidebar (Liste mit Count, Suche, Inaktiv-Toggle), rechts Artikel-Tabelle des gewählten Lieferanten + separate **lieferantenübergreifende Artikelsuche**. Editoren als Modals (Supplier-Editor, LA-Editor). Bulk über Checkbox-Selektion: GP zuweisen (Such-Modal), löschen, ausgelistet setzen, Lead-Toggle. Zwei spezialisierte Modals: `BulkLaMatchModal`, `PriceAnomalyModal`. KI-/Hilfs-Aktionen rechts im Section-Header.

Ziel (Livewire, `x-ui-*`, jede Entität = URL/V-17):

- **`Suppliers/Index`** — Sidebar (`x-ui-list`) + Detail mit Artikel-`x-ui-table` (paginiert; Filter onlyActive/includeInactive, Suche). Section-Header rechts: „Bulk-Match", „Preis-Anomalien". Bulk-Leiste bei Selektion (GP zuweisen / löschen / ausgelistet / Lead).
- **`SupplierItems/GlobalSearch`** — lieferantenübergreifende Suche als eigene Route (war in Alt-App ein Sub-Bereich der Tabelle).
- **`Suppliers/CoreMatrix`** — Stamm-Lieferant×Warengruppen-Matrix; `deriveCoreWg` als „Vorschlag aus LAs"-Aktion. (In Alt-App ein Tab in `Klasse.tsx` — hierher verschoben.)
- **Supplier-Editor- / LA-Editor-Modal** (in `<x-ui-page>`): LA-Editor mit Tabs Stammdaten · GP-Zuordnung · Allergene (GL-01) · Deklarationen · Preise (GL-11).
- **`Matching/ReviewQueue`** (V-10, neu, First-Class): Liste der `needs_review`-LAs mit **Zählern pro `review_grund`**, Filter (Lieferant/Warengruppe), Frist-Tracking (14 Tage, §12), Bulk-Aktionen (accept Top-1 / umhängen / no_match).
- **LA-Match-Wizard-Modal**: KI-Top-3 mit Konfidenz-Anzeige, editierbar vor Übernahme, Begründung + Gap sichtbar (GL-07-UX); Gap-Surfacing für unbekannte/uneindeutige Fälle.
- **`Prices/Anomalies`** + PriceAnomaly-Modal: Ausreißer-Liste (GL-11), KI-Plausi-Check je Zeile.

## 5. KI-Features dieser Domäne

Alle KI-Features laufen über das Gateway (`AiGatewayContract`, D-4/06_KI), nutzen Hüllen (**GL-06**) und den EINEN Lebenszyklus (**GL-07**: propose → review → accept/reject, Lineage + `ai_call_log`-Audit, Override-First). Bulk-Läufe als Queue-Jobs mit Fortschritt+Resume (**V-15**).

| Feature | Alt-Command | Aufgabe | GL-Refs | Lebenszyklus | Modell-Tier (V-01) |
|---|---|---|---|---|---|
| **LA→GP Einzel-Match** | `ai_match_la_to_gp` | LA strukturieren (Gemini) → Top-3-GP-Kandidaten, §5-Gate | GL-05, GL-06, GL-07 | Wizard → accept/reject | **Tier A** (Qualität — semantisches Matching, teuerster Pfad GL-05) |
| **Bulk-Match ungemappte LAs** | `ai_bulk_match_unmapped_las` | Batch über `gp_id IS NULL`, Auto-Accept nur bei vollem §5-Gate | GL-05, GL-06, GL-07 | Job, Auto-Accept→GL-07 | **Tier A** (gleiche Match-Qualität wie Einzel) |
| **Phantom-Reverse-Match** | `ai_bulk_match_phantoms_via_matrix` | GP ohne LA sucht passende LAs über Stamm-Matrix | GL-05, GL-06, GL-07 | Job | **Tier A** |
| **LAs zu Suchbegriff ranken** | `ai_rank_las_for_term` | LA-Kandidaten zu Freitext-Term ordnen (Disambiguierung) | GL-06, GL-07 | Read-Vorschlag | **Tier B** (Ranking-Label, billig) |
| **Preis-Plausibilität** | `ai_plausi_check_price` | EK-Preis gegen Erwartungswert prüfen, Begründung | GL-06, GL-07, GL-11 | propose → review | **Tier B** (kurze strukturierte Bewertung) |

§5-Auto-Match-Gate (GL-05 Tabelle B): Score ≥0.95 **UND** Gap ≥0.15 **UND** keine Allergen-Kollision (GL-01) **UND** Plausibilität — sonst Review-Queue. KI-Match wird **NIE** „erster Kandidat" (GL-05 I3/§6.5).

## 6. Verbesserungen gegenüber Ist

**V-10 — Review-Queue als eigene View (prioritär):** Die 597 `needs_review`-LAs zeigen, dass ein verstecktes Flag nicht reicht. Ziel: eigene `Matching/ReviewQueue`-Route mit Zählern pro `review_grund`, Frist-Tracking, Filter, Bulk-Auflösung — Hygiene-Gate vor Seed-Export.

**GL-05-Abweichungen A1–A6 — im Ziel richtig bauen (Bau-Aufträge):**

| Ref | Ist-Defizit | Bau-Auftrag im Ziel |
|---|---|---|
| A1 | `wawi_la_structured` hat nur `klassifikator` (Freitext), kein `match_method` | **Beide Felder führen:** `match_method` als Enum (§12, fachliche Stufe) + `klassifikator` (technische Herkunft); Seed-Mapping `klassifikator→match_method`. |
| **A2** | **Gap-Kriterium (≥0.15) fehlt** — Auto-Accept nur über Score | **Volles 4er-Gate** implementieren (Score + Gap + Allergen + Plausibilität). |
| A3 | Plausibilitäts-Filter (Klasse + Stückgewicht/Einheit) nur implizit | **Explizite, einzeln testbare Filterketten-Methoden** (GL-05 Tabelle C). |
| A4 | Score-Skala uneinheitlich (0–1 vs. 0–100) | Intern 0.0–1.0, Anzeige 0–100, Schwellen dokumentiert. |
| **A5** | **EAN-Stufen inaktiv** — Kaskade springt von ArtNr direkt zu KI-Fuzzy | **EAN-Stufen (§3.2/§3.3) reaktivieren** — deterministisch VOR jedem KI-Call (spart Tier-A-Kosten). |
| A6 | `wawi_gp_la_history`-Audit nie gebaut | entfällt — Plattform-`LogsActivity` auf dem Structure-Model übernimmt (V-13). |

**GL-03-Abweichungen (Lead-LA) — Bau-Aufträge:**

- **A-2 NULL-Preis-Sortier-Falle (Port-kritisch):** SQLite sortiert `NULL` bei `ASC` zuerst → ein LA ohne berechenbaren €/Einheit gewinnt fälschlich (Realfall GP 6723). PostgreSQL: **explizit `NULLS LAST`** deklarieren; beim Seed **Diff-Report Ist-Lead vs. Soll-Lead** erzeugen (ändert EK-Werte → GL-02).
- **A-3 Einheiten-Mix:** Preisvergleich €/kg vs. €/l vs. €/Stk unnormalisiert → über GL-11-Brücken normalisieren, sonst NULL (ans Ende).
- **A-4 fehlende §8-Stufen:** Allergen-Vollständigkeit, 365-Tage-Aktualität, `lead_la_locked_at` (manueller Lock überlebt Bulk), `needs_lead_la_review`-Flag ergänzen (Regelwerk gewinnt).
- **A-5 Bulk ohne Transaktion/Lock:** `recomputeAllLeadLas` + nachgelagerter GL-02-Recompute als **eine Job-Kette** (V-07), respektiert Locks.

**GL-11-Abweichungen (Preise):** einheitlich `aktiver_preis()` (neueste aktive Zeile) für Kalkulation UND Lead-Vergleich (A-2); **Append-only-Import** mit `valid_to`-Stempelung statt Überschreiben (echte Historie).

**V-27 — Lead-LA-Strategie + Substitutionskette + Einkäufer-Workflow (Bau-Auftrag, User-Anforderung 2026-06-11):** Team-Einstellung `lead_strategie` (stamm_lieferant | guenstigster_preis, optional je WG) · pro GP persistierte LA-Rangliste (GL-03 V-27) · **Einkäufer-UI im GP-Detail:** Kette als sortierbare Liste mit Aktionen „🚫 Sperren (Grund, optional befristet)" und „📌 Pinnen" — Sperre macht den nächsten Rang zum wirksamen Lead („LA A will ich nicht, aber B geht"). Sperren/Pins team-scoped (`foodalchemist_gp_la_preferences`), Wechsel triggert Recompute-Job + LogsActivity. Rollen: Aktion nur für Einkäufer-Rolle (V-12).

**Plattform-Gewinne:** **V-12** Rollen (Einkauf pflegt Preise, Koch liest) · **V-13** Aktivitäts-Historie auf LA/Mapping/Preis · **V-15** Bulk-Match/Recompute als Queue-Jobs statt UI-blockierender Loops/launchd-Skripte · **V-09** KI-Kosten pro Team auf `ai_call_log` · **V-18** echte Parallelität (kein Single-Writer-Mutex). **⚠D1:** Preis-Sichtbarkeit pro Team noch offen — Arbeits-Annahme: global, ein Preisstand; Team-Abweichungen beim Lead laufen über das V-27-Overlay.

## 7. Akzeptanzkriterien & Golden-Tests

**Verweis auf GL-Tests (verbindliche Wahrheit, nicht dupliziert):** GL-05 GT-05-01…12 (Match-Kaskade, §5-Gate, sticky-manual, EAN-Bluff, Convenience/Marken-Tarnung, non_food), GL-03 GT-1…7 (Lead-Kaskade, NULLS-LAST, Stamm-schlägt-Preis, Phantom, Override-Validierung), GL-11 GT-1…7 (Kategorisierung, Normalisierung, aktiver Preis), GL-01 (Allergen-Aggregation/-Konsistenz).

Domänen-spezifische Abnahme-Szenarien:

1. **AT-D2-01 — Review-Queue ist sichtbar & abarbeitbar (V-10):** Seed-Bestand zeigt 597 `needs_review`-LAs mit Zählern je `review_grund`; ein `accept` setzt `needs_review=0`, schreibt `ai_call_log.accepted_at`, zählt `n_las_total` des GP neu und triggert Lead-LA-Neuwahl (GL-03) — als eine Transaktion (V-07).
2. **AT-D2-02 — Auto-Match-Gate vollständig (GL-05 A2):** LA mit Top-1 0.96 / Top-2 0.88 (Gap 0.08 < 0.15) → **kein** Auto-Match trotz Score ≥0.95, beide Kandidaten in Review. (Im Ist hätte er automatisch gemappt.)
3. **AT-D2-03 — EAN vor KI (GL-05 A5):** LA mit eindeutiger Verpackungs-EAN auf genau ein GP → deterministischer Match `ean_packaging`, **kein** Gemini-Call (Audit zeigt keinen ai_call_log-Eintrag).
4. **AT-D2-04 — Lead-LA NULLS LAST (GL-03 A-2):** Für GP 6723 wählt der Ziel-Algorithmus LA 29344887 (3,59 €/l) als Lead, nicht den preislosen LA 31141191 (qty NULL); Seed-Diff-Report listet die Lead-Änderung gegenüber dem SQLite-Ist.
5. **AT-D2-05 — Stamm schlägt Preis (GL-03 GT-2):** GP 2151: der LA des WG-Stamm-Lieferanten (Edna Backwaren, WG 09) wird Lead, obwohl billigere Nicht-Stamm-LAs existieren.
6. **AT-D2-06 — Manuelles Mapping ist sticky (GL-05 I2):** Ein `match_method='manual'`-Mapping bleibt bei einem Bulk-Lauf unverändert, selbst wenn die KI für ein anderes GP Score 0.99 errechnet.
7. **AT-D2-07 — Read-only-Stammdaten ⚠D1:** Ein Nicht-Admin-Team kann Lieferanten/LAs/Preise lesen, aber nicht schreiben (Policy V-12); Schreib-Pfade sind Admin-gated.
8. **AT-D2-08 — Substitutionskette (V-27):** GP mit Kette [A, B, C]. Einkäufer von Team 1 sperrt A (Grund: „Qualität reklamiert") → effektiver Lead für Team 1 = B, EK der Team-1-Rezepte rechnet mit B (Recompute-Job gelaufen); Team 2 kalkuliert unverändert mit A. Entsperren stellt A wieder her. Pin auf C überlebt eine Bulk-Neuwahl.
