---
typ: Grundlogik-Spec
gl_id: GL-05
stand: 2026-06-10
status: ausgearbeitet
quellen_normativ: "regelwerke/Regelwerk_Lieferantenartikel.md §3, §5, §6, §12 (eingefroren 2026-06-10)"
quellen_ist: "cooking-jarvis/src-tauri/src/commands.rs + wawi_1494.sqlite (wawi_la_structured)"
---

# GL-05 — LA↔GP-Match-Hierarchie & Auto-Match-Schwellen

## 1. Zweck & Quellen

Diese Grundlogik regelt, **wie ein Lieferantenartikel (LA) einem Grundprodukt (GP) zugeordnet wird**: die Match-Methoden-Kaskade, die Schwellen für automatisches (unbeaufsichtigtes) Mappen, die Disambiguierung bei Mehrdeutigkeit und die `needs_review`-Mechanik. Sie ist die Kernlogik der Domäne D-2 (Lieferanten/LA) und Voraussetzung für GL-01 (Allergen-Aggregation), GL-03 (Lead-LA-Wahl) und GL-11 (Preislogik).

**Normativ** (Regelwerk schlägt Code):
- `Regelwerk_Lieferantenartikel.md` **§3** Match-Schlüssel-Hierarchie, **§5** Auto-Match-Schwellen, **§6** Disambiguierungs-Filterkette, **§12** `match_method`-Vokabular. Ergänzend §10 (Allergen-Konsistenz als Gate), §11 (Anti-Patterns), §14 (Re-Mapping/Sticky).

**Ist-Implementierung** (Rust, `src-tauri/src/commands.rs`):
- `ai_match_la_to_gp` (Z. 17833) — Einzel-LA-Wizard: Gemini strukturiert den LA, dann `weighted_la_to_gp_match` (Z. 9580) liefert Top-3-GP-Kandidaten; Fallback `strict_structured_match` (Z. 9464).
- `accept_la_gp_match` (Z. 17991) / `reject_la_gp_match` (Z. 18026) — expliziter User-Entscheid, UPSERT in `wawi_la_structured`, Audit via `ai_call_log`.
- `ai_bulk_match_unmapped_las` (Z. ~19059) — Bulk-Variante mit Auto-Accept-Gate: `score ≥ 0.95` (Default) UND keine Allergen-Kollision (`la_gp_allergen_conflict`, Z. 19014).
- `bulk_set_la_gp` (Z. 3302) — manuelles Bulk-Mapping (`klassifikator='manual_bulk'`).
- Phantom-GP-Reverse-Match (`phantom_matrix_match`, Z. ~18900) — GP sucht LAs, gleiche Gates.

> **Wichtige historische Einordnung für den Laravel-Dev:** Das Regelwerk (v1.0, 2026-04-29) beschreibt die Legacy-Pipeline auf Tabelle `wawi_gp_la` (Skripte 24/26/43/46). Diese Tabelle wurde am **2026-05-19 gedroppt** (LA-First-Reset). Die heutige Implementierung arbeitet auf `wawi_la_structured` (1 Zeile pro LA, `gp_v2_id`-FK) und persistiert die Methode im Feld `klassifikator` statt `match_method`. **Die Regelwerk-Logik (§3/§5/§6/§12) bleibt normativ** — sie wird im Laravel-Ziel wieder vollständig umgesetzt (siehe Abweichungen in Abschnitt 6).

## 2. Eingaben / Ausgaben / Invarianten

**Ziel-Tabellen** (Namen aus `02_DATENMODELL.md`):

| Rolle | Ziel-Tabelle | relevante Felder |
|---|---|---|
| LA (Quelle, read-only) | `foodalchemist_supplier_items` | `id`, `supplier_id`, `article_number`, `ean_packaging_unit`, `ean_ordering_unit`, `designation`, `unit_id`, `is_discontinued` |
| Mapping (1 Zeile pro LA) | `foodalchemist_supplier_item_structures` | `supplier_item_id` (UNIQUE), `gp_id` (FK, nullable), `match_method` (Enum, §12), `match_confidence`, `needs_review` (bool), `review_grund`, `klassifikator`, `klassifiziert_am` |
| GP (Ziel) | `foodalchemist_gps` | `id`, `gp_key`, `gp_name`, `warengruppe`, `hauptzutat_slug`, `zustand`, `status` (approved/tentative/merged), Allergen-Block (14×), `n_las_total`, `lead_la_supplier_item_id` |
| Stamm-Lieferant-Matrix | `foodalchemist_core_suppliers` / `_core_supplier_wgs` | steuert Reihenfolge der Lieferanten-Abarbeitung (LA-First) |
| KI-Audit | `foodalchemist_ai_call_log` | `accepted_at`, `rejected_at`, `target_table`, `target_id` |

**Eingabe:** ein LA (oder Batch ungemappter LAs: `gp_id IS NULL AND is_discontinued = 0`).
**Ausgabe:** genau eine von drei Entscheidungen pro LA:
1. **Auto-Match** — `gp_id` gesetzt, `needs_review = 0`, `match_method` dokumentiert die Stufe.
2. **Review-Queue** — Kandidatenliste persistiert/angezeigt, `needs_review = 1` + `review_grund`.
3. **no_match** — bewusst unzugeordnet (`gp_id = NULL`), Begründung Pflicht (z. B. `non_food`, `convenience_pending_v2`, `kein_passendes_gp`).

**Invarianten:**
- **I1:** Ein LA zeigt auf max. 1 GP (`supplier_item_id` ist PK/UNIQUE der Mapping-Tabelle). Ein GP hat 0–n LAs.
- **I2:** `match_method = 'manual'` ist **sticky** (§14) — kein Skript/Job darf den Eintrag überschreiben, nur ein expliziter neuer User-Entscheid.
- **I3:** Auto-Match schreibt NIE bei verletztem §5-Gate (Score, Gap, Allergen, Plausibilität) — dann immer Review-Queue. **NIE "ersten Kandidaten nehmen"** (§6.5).
- **I4:** Ausgelistete LAs (`is_discontinued = 1`) behalten ihr Mapping (Historie/Reaktivierung), werden aber nicht neu gematcht und zählen nicht für Lead-LA (GL-03).
- **I5:** Jeder KI-gestützte Match-Vorschlag wird in `ai_call_log` auditiert (Prompt, Modell, Tokens, accepted/rejected) — auch abgelehnte.
- **I6:** Nach jedem Mapping-Schreibvorgang: `n_las_total` am GP neu zählen + Lead-LA-Neuwahl anstoßen (GL-03 `pick_lead_la`).
- **I7:** `Brand`/`Manufacturer` sind KEINE Match-Keys (§4) — in Necta nicht gepflegt (2 % / 0 %). Sie dürfen nur als Kontext in den KI-Prompt.

## 3. Pseudocode (erklärend)

```text
function matchLaToGp(la):
    # ── Stufe 0: Vorbedingungen ───────────────────────────────────────────
    if la.is_discontinued: return SKIP
    if existingMapping(la).match_method == 'manual': return SKIP        # I2 sticky

    # ── Stufe 1: artno+supplier (§3.1, deterministisch) ───────────────────
    prior = findMapping(article_number = la.article_number, supplier_id = la.supplier_id)
    if la.article_number != null and prior != null:
        return AUTO(gp = prior.gp_id, method = 'artno+supplier')

    # ── Stufe 2/3: EAN (§3.2 / §3.3, deterministisch) ──────────────────────
    for (field, method) in [(ean_packaging_unit,'ean_packaging'), (ean_ordering_unit,'ean_ordering')]:
        hits = gpsKnownForEan(la[field])
        if count(hits) == 1: return AUTO(gp = hits[0], method = method)
        if count(hits) > 1:  goto DISAMBIGUIERUNG(hits)                 # §6; „EAN-Bluff" → nie Auto (§11)

    # ── Stufe 4: name_fuzzy, KI-gestützt (§3.4) ────────────────────────────
    struct = aiStructureLa(la.designation, la.brand, la.supplier_name)  # Gemini, JSON: hauptzutat,
                                                                        # zustand, verarbeitung, form, …
    if struct.ausschluss_grund != null:                                 # non_food / service
        return NO_MATCH(grund = struct.ausschluss_grund)
    if hasConvenienceMarker(la.designation) and not existsConvenienceGp(struct):
        return NO_MATCH(grund = 'convenience_pending_v2')               # §11/§13: nie auf Roh-GP

    candidates = weightedMatch(struct, topN = 3)
        # Filter: gleicher plural-reduzierter hauptzutat_slug (Stemmer, GL-12)
        # Score:  gewichteter Token-Jaccard — Hauptzutat-Token 2.0x,
        #         Klammer-Disambiguatoren 1.5x, Rest 1.0x; approved vor tentative
    if candidates.isEmpty():
        candidates = strictStructuredMatch(struct)                      # gp_key-exakt, dann Jaccard breit

    if candidates.isEmpty(): return NO_MATCH(grund = 'kein_passendes_gp')
    if count(candidates) > 1: candidates = DISAMBIGUIERUNG(candidates)  # §6-Filterkette, s. Tabelle C

    # ── §5-Auto-Match-Gate (alle 4 Bedingungen, sonst Review) ─────────────
    top = candidates[0]
    gap = top.score - (candidates[1].score ?? 0)
    if  top.score >= 95            # Skala 0–100 (Ist-Code: 0.95 auf 0–1)
    and gap       >= 15
    and not allergenConflict(la, top.gp)          # §10: enthalten ↔ nicht_enthalten = Konflikt
    and plausibilityOk(la, top.gp):               # Klasse + Stückgewicht/Einheit (§6.3/§6.4)
        return AUTO(gp = top.gp, method = 'auto_eindeutig_v1', confidence = top.score)
    else:
        return REVIEW(candidates, grund = firstViolatedGate())          # needs_review = 1

function DISAMBIGUIERUNG(hits):                   # §6 — Filterkette, Reihenfolge fix
    hits = filterBySupplier(hits)                 # 1. aktueller Lieferant gewinnt
    hits = filterByAllergenConsistency(hits)      # 2. inkompatible Profile raus
    hits = filterByKlasse(hits)                   # 3. TK/frisch/geraeuchert/mariniert ↔ GP-Warengruppe
    hits = filterByStueckgewichtUndEinheit(hits)  # 4. „180g"/„6x200g" + unit_id ↔ kalkulationseinheit
    if count(hits) == 1: return hits
    return REVIEW(hits, method = 'needs_manual_review')   # 5. NIE Default auf ersten Kandidaten

function acceptLaGpMatch(la, gp, user):           # accept_la_gp_match, Z. 17991
    upsertMapping(la → gp, method = 'manual' bei User-Override, sonst Stufen-Wert)
    aiCallLog.markAccepted(); recount n_las_total; pickLeadLa(gp)       # I5, I6
```

## 4. Entscheidungstabellen (normativ)

### Tabelle A — Match-Kaskade (§3, strikte Reihenfolge, erste Stufe mit Treffer gewinnt)

| Stufe | Schlüssel | Bedingung | Konfidenz | `match_method` |
|---|---|---|---|---|
| 1 | `(article_number, supplier_id)` | ArtNr gepflegt (~95 % der LAs) + bestehendes Mapping mit identischem Tupel | HIGH | `artno+supplier` |
| 2 | `ean_packaging_unit` | EAN gepflegt (~10 %), Match gegen GP-bekannte EANs | HIGH bei 1:1; MED bei EAN-Duplikaten | `ean_packaging` |
| 3 | `ean_ordering_unit` | Bestell-EAN gepflegt (~30 %) — identifiziert Verpackungseinheit, nicht zwingend Produkt | MED | `ean_ordering` |
| 4 | Name-Fuzzy (KI-strukturiert + gewichteter Token-Score) | `designation` immer befüllt (100 %) | LOW; MED ab Score ≥ 80; HIGH ab ≥ 95 + Allergen-OK | `auto_eindeutig_v1` (bei §5-Gate) sonst `needs_manual_review` |
| 5 | User-Override | expliziter User-Entscheid | HIGH (per Definition) | `manual` (sticky, §14) |
| 6 | bewusst unzugeordnet | Begründung Pflicht | — | `no_match` / `convenience_pending_v2` |

### Tabelle B — Auto-Match-Gate (§5: ALLE 4 müssen erfüllt sein)

| # | Bedingung | Schwelle | Ist-Code (Abweichung s. Abschn. 6) |
|---|---|---|---|
| 1 | Score (Top-1) | ≥ 95 (Skala 0–100) | `min_score` Default 0.95 ✓ (Z. 19066) |
| 2 | Score-Gap (Top-1 − Top-2) | ≥ 15 | **nicht implementiert** → im Ziel Pflicht |
| 3 | Allergen-Konsistenz (§10) | pass — kein harter Widerspruch `enthalten` ↔ `nicht_enthalten` in einem der 14 EU-Allergene; `unbekannt` widerspricht nie | `la_gp_allergen_conflict` ✓ (Z. 19014) |
| 4 | Plausibilität | Klassen-Filter (§6.3) + Stückgewicht/Einheit (§6.4) | nur indirekt via Hauptzutat-Slug-Filter → im Ziel explizit |

Verletzung von ≥ 1 Bedingung ⇒ **kein Insert**, Eintrag in Review-Queue (`needs_review = 1`). Schwellen-Änderung = Governance-Eingriff (§15): Regelwerk + Code + Log gemeinsam ändern.

### Tabelle C — Disambiguierungs-Filterkette (§6, Reihenfolge fix, bis 1 Kandidat übrig)

| # | Filter | Regel |
|---|---|---|
| 1 | Lieferant | Kandidat des aktuellen Lieferanten gewinnt (Tupel mit `supplier_id` ist per Definition eindeutig) |
| 2 | Allergen-Konsistenz | Kandidaten mit inkompatiblem Allergen-Profil (§10) fallen raus |
| 3 | Klasse | Tokens in `designation` (`TK`, `frisch`, `geraeuchert`, `mariniert`, …) müssen zur GP-Warengruppe/zum GP-Zustand passen — verhindert Cross-Klassen-Matches („Lachsschinken" ist Schinken, nicht Lachs; §11) |
| 4 | Stückgewicht/Einheit | LA-Stückgewicht im Namen („180g", „6x200g") muss zum GP-Stückgewicht passen; `unit_id` (kg/l/Stk) muss zur GP-Kalkulationseinheit passen |
| 5 | Reviewer-Queue | wenn 1–4 nicht eindeutig: `needs_manual_review`, Frist 14 Tage. KEIN Default-Match |

### Tabelle D — `match_method`-Vokabular (§12, vollständig — wird PHP-Enum)

| `match_method` | Bedeutung | Stufe | Folge-Aktion |
|---|---|---|---|
| `artno+supplier` | sauber via Primär-Tupel (Legacy-Anteil: 98,6 % von 7.505 Mappings) | §3.1 | keine |
| `ean_packaging` | via Verpackungs-EAN | §3.2 | EAN-Duplikate regelmäßig prüfen |
| `ean_ordering` | via Bestell-EAN | §3.3 | Plausibilität bei nächster Audit-Welle |
| `auto_eindeutig_v1` | Skript-Auto-Match nach §5-Gate | §3.4+§5 | Sample-Audit alle 30 Tage |
| `manual` | User-Override | §3.5 | sticky, nie überschreiben (§14) |
| `no_artno` | LA ohne ArtNr, per Fallback gemappt | §3.4 | ArtNr-Pflege beim Lieferanten einfordern |
| `artno_only_ambiguous(N)` | mehrdeutig, N Kandidaten | §6 | Filterkette anwenden |
| `artno_only_unique` | ArtNr global eindeutig, ohne Lieferant-Abgleich | §3.1 | keine |
| `needs_manual_review` | keine sichere Skript-Entscheidung | §6.5 | Reviewer-Queue, Frist 14 Tage |
| `no_match` | bewusst unzugeordnet | §3.6 | Begründung Pflicht |
| `convenience_pending_v2` | Convenience-LA geparkt, nie auf Roh-GP | §11/§13 | wartet auf Convenience-Strategie |

**Pflicht-Audit (§12):** taucht ein neuer Wert in der DB auf, MUSS diese Tabelle (und der Enum) erweitert werden. Audit-Query: `SELECT match_method, COUNT(*) FROM … GROUP BY match_method`.

### Tabelle E — `needs_review`-Mechanik (Ist-Code, wird Ziel-Verhalten)

| Auslöser | Quelle (Ist) | `review_grund`-Muster |
|---|---|---|
| KI-Confidence < 0.7 | `ai_suggest_gp` (commands.rs Z. 9941) | `Low confidence: 0.60` |
| §8-Pflichtangabe fehlt in Designation (z. B. Kartoffel-Kochtyp) | Confidence-Cap auf 0.5 wenn `begruendung` mit `fehlt_§8` beginnt (Z. 9938) → unter 0.7-Schwelle | `fehlt_§8_…` / `Low confidence: 0.50` |
| Score < Auto-Accept-Schwelle im Bulk | `ai_bulk_match_unmapped_las` (Z. 19206/19231) | Kandidatenliste in Review-Response |
| Allergen-Konflikt LA↔GP | `la_gp_allergen_conflict` blockt Auto-Accept (Z. 18965, 19230) | `allergen_blocked`-Zähler |
| Non-Food/Service erkannt | `ausschluss_grund` aus KI | `AUSGESCHLOSSEN: non_food` / `AUSGESCHLOSSEN: service` |
| Auto-Link-Batch nachträglich markiert | Phase-12-Quickmatch | `Phase12 auto-link` |

Auflösung: User-Entscheid (accept/reject/Umhängen) setzt `needs_review = 0` und `klassifiziert_am` neu.

### Ist-Daten-Beleg (wawi_1494.sqlite, `wawi_la_structured`, Stand 2026-06-10)

9.803 strukturierte LAs, davon 9.679 mit GP, **597 `needs_review = 1`** (→ V-10):

| `klassifikator` | n | mit GP | needs_review |
|---|---|---|---|
| `broich_excel_import` | 7.176 | 7.176 | 0 |
| `gemini_2_5_flash` | 1.583 | 1.460 | 229 |
| `phase12_la_quickmatch` | 367 | 367 | 367 |
| `xlsx` / `csv` / `pdf_diff` | 614 | 614 | 0 |
| `gp_la_suggest` | 32 | 32 | 0 |
| `manual` / `manual_bulk` | 13 | 12 | 0 |
| `phantom_matrix_match` | 6 | 6 | 0 |
| `la_first_anlage` / `la_match_wizard` / sonst. | 12 | 12 | 1 |

## 5. Golden-Testfälle (verbindliche Wahrheit; Testfall > Entscheidungstabelle > Pseudocode)

Reale IDs aus `wawi_1494.sqlite` (Seed-Daten) — wandern 1:1 in PHPUnit-Datasets.

| # | Input | Expected |
|---|---|---|
| GT-05-01 | LA 28516985 „Apfel ganz geschält 5kg Gebinde", ArtNr 117, Lieferant Frischeteam Geschwister Schwering | → GP 29 „Aepfel: frisch, ganz, geschaelt", `needs_review = 0`. Verpackungsangabe „5kg Gebinde" beeinflusst NUR den LA, nie den GP (GP-Regelwerk §7.1) |
| GT-05-02 | LA 28516986 „Apfel gewürfelt ohne Schale 5mm" (gleicher Lieferant) | → GP 30 „Aepfel: frisch, Wuerfel 5 mm, geschaelt" — anderer Zuschnitt = anderes GP, NICHT GP 29 |
| GT-05-03 | LA 28516977 „Kartoffel gewürfelt 5mm", ArtNr 1446 | → GP 22 (Kartoffel vorwiegend festkochend, Würfel 5 mm) mit **`needs_review = 1`**: Kochtyp ist aus der Designation nicht ableitbar (§8.12-Pflichtangabe fehlt → Confidence-Cap) |
| GT-05-04 | Re-Import desselben LA (identisches Tupel ArtNr 117 + Frischeteam), Mapping existiert | → Stufe 1 greift: `match_method = 'artno+supplier'`, kein KI-Call, GP unverändert |
| GT-05-05 | Fuzzy-Match: Top-1-Score 96, Top-2-Score 88 (Gap 8 < 15), Allergene konsistent | → KEIN Auto-Match (Gate 2 verletzt) → Review-Queue mit beiden Kandidaten |
| GT-05-06 | Top-1-Score 97, Gap 20, aber LA-Allergen `milch = enthalten` und GP-Aggregat `milch = nicht_enthalten` | → Auto-Match geblockt (`allergen_blocked`), `needs_review = 1`. `unbekannt` auf einer Seite hätte NICHT geblockt |
| GT-05-07 | LA „Spaghetti Pesto Garnelen 350g" (Convenience-Marker, kein Convenience-GP vorhanden) | → `no_match` mit Grund `convenience_pending_v2`. NIE Soft-Match auf „Spaghetti: trocken, lang" (§11 Convenience-Tarnung) |
| GT-05-08 | Generischer LA „Triple Sec 0,7l 30%" | → matcht GP „Triple Sec generisch: fluessig, 30 vol-%", NIE GP „Cointreau: fluessig, 40 vol-%" (§11 Marken-Tarnung; Marke ist kein Match-Key) |
| GT-05-09 | LA hat `match_method = 'manual'` auf GP A; Bulk-Job errechnet Score 99 für GP B | → kein Update (I2 sticky). Nur expliziter User-Entscheid darf umhängen |
| GT-05-10 | Zwei Lieferanten reichen dieselbe EAN durch, Designations widersprechen sich („Olivenöl nativ" vs. „Sonnenblumenöl") | → KEIN Auto-Match auf EAN-Basis (§11 EAN-Bluff) → `needs_manual_review` |
| GT-05-11 | LA `non_food` (z. B. Servietten): KI liefert `ausschluss_grund = 'non_food'` | → `ist_lebensmittel = 0`, kein GP, `review_grund = 'AUSGESCHLOSSEN: non_food'` (Ist-Bestand: 119 Fälle) |
| GT-05-12 | User akzeptiert Wizard-Vorschlag (accept) | → UPSERT Mapping, `ai_call_log.accepted_at` gesetzt, `n_las_total` des GP neu gezählt, Lead-LA-Wahl (GL-03) getriggert |

## 6. Offene Weichen + Verbesserungen

**Abweichungen Regelwerk ↔ Ist-Code** (Regelwerk gewinnt, Ziel-Implementierung folgt Regelwerk):

| # | Abweichung | Ziel-Entscheid |
|---|---|---|
| A1 | Regelwerk-§12-Feld `match_method` existiert im Ist nur noch auf `recipe_ingredients` (GL-04); `wawi_la_structured` persistiert stattdessen `klassifikator` (Freitext-Herkunft) + `needs_review`/`review_grund` | Ziel führt BEIDES: `match_method` als Enum (§12, Tabelle D) für die fachliche Match-Stufe + `klassifikator` als technische Herkunft (Import/Wizard/Bulk/manual). Migration: Mapping `klassifikator → match_method` beim Seed (z. B. `manual*` → `manual`, `gemini_*`/`la_bulk_match` → `auto_eindeutig_v1` bzw. `needs_manual_review` je nach `needs_review`-Flag, Importe → `artno+supplier`) |
| A2 | **Gap-Kriterium (≥ 15) ist im Ist-Code nicht implementiert** — nur `score ≥ 0.95` + Allergen-Gate | Ziel implementiert das volle 4er-Gate (Tabelle B) |
| A3 | Plausibilitäts-Filter (§6.3/§6.4 Klasse + Stückgewicht/Einheit) im Ist nur implizit über Hauptzutat-Slug-Filter | Ziel: explizite Filterkette als eigene, einzeln testbare Service-Methoden |
| A4 | Score-Skala: Regelwerk 0–100, Ist-Code 0.0–1.0 | Ziel normiert intern auf 0.0–1.0, dokumentiert Schwellen als 0.95/0.15; Anzeige 0–100 |
| A5 | EAN-Stufen (§3.2/§3.3) sind im Ist-Code (LA-First) gar nicht aktiv — Kaskade springt von ArtNr direkt zu KI-Fuzzy | Ziel implementiert die EAN-Stufen wieder (deterministisch vor jedem KI-Call → Kostenersparnis) |
| A6 | `wawi_gp_la_history` (§14-Audit) war nie implementiert | entfällt als eigene Tabelle — Plattform-`LogsActivity` auf dem Structure-Model übernimmt das |

**Offene Weichen** (Anker auf `08_ENTSCHEIDUNGEN.md` / `10_VERBESSERUNGS_REGISTER.md`):

- **V-10 (prioritär für diese GL): Review-Queue als First-Class-Workflow.** 597 offene `needs_review`-LAs belegen, dass ein verstecktes Flag nicht reicht. Ziel: eigene Queue-UI mit Zählern pro `review_grund`, Frist-Tracking (14 Tage, §12), Bulk-Aktionen (accept Top-1 / umhängen / no_match) und Filter nach Lieferant/Warengruppe. Hygiene-Gate vor Seed-Export (02_DATENMODELL E.4).
- **D1:** Sind GP-Welt + Mappings global (team_id NULL) oder team-scoped? Betrifft, WER die Review-Queue abarbeitet (BHG-Admin-Team vs. jedes Team seine Lieferanten).
- **D3:** KI-Konvention der Plattform (zentraler Key, Rate-Limit, Kosten pro Team) — Stufe 4 ist der teuerste Pfad dieser GL.
- **Convenience-Strategie v2** (Regelwerk §13/§17): bis zum Entscheid bleibt `convenience_pending_v2` die einzig zulässige Antwort für Convenience-LAs ohne Convenience-GP.
- **EAN-Duplikate:** keine Logik für „gleiche EAN, verschiedene Produkte" — bis dahin gilt §11 EAN-Bluff (Review statt Auto).
