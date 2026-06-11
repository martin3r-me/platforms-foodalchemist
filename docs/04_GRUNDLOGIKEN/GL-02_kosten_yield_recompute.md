---
typ: Grundlogik-Spec
gl_id: GL-02
stand: 2026-06-10
status: ausgearbeitet
---

# GL-02 — Kosten-/Yield-Recompute mit Sub-Rezept-Topologie

> **Normative Quellen:** Regelwerk_Basisrezepte §6 (Mengen/Einheiten/Yield, F6.1–F6.4) + §4 (Sub-Rezept-Hierarchie, max. 3 Ebenen)
> **Implementierungs-Quelle (Ist):** `commands.rs` — `recompute_recipe_aggregations` (6868), `recompute_recipe_costs` (7197), `recompute_recipe_and_propagate` (7979), `topo_sort_recipes` (7782), `load_subrecipe_edges` (7722), `SUBRECIPE_MAX_DEPTH` (8020), `would_create_cycle` (7923), `recompute_all_recipes` (8065). Python-Spiegel: `206_recompute_all_recipes.py`.

## 1. Zweck & fachliche Quelle

Jedes Rezept persistiert aggregierte Kennzahlen, die aus seinen Zutaten berechnet werden: **Ausbeute** (`yield_kg`), **EK-Gesamtkosten** (`ek_total_eur`), **EK pro kg** (`ek_per_kg_eur`) sowie Zähler (`n_zutaten_total`, `n_zutaten_ungemappt`, `ek_n_ingredients_total`, `ek_n_ingredients_priced`). Rezepte können andere Rezepte als Zutat referenzieren (Sub-Rezepte, max. 3 Ebenen). Ein Sub-Rezept fließt mit seinem **persistierten** `ek_per_kg_eur` in die Eltern-Kalkulation ein — daher müssen Kinder **vor** Eltern berechnet werden (topologische Ordnung) bzw. nach einer Änderung alle transitiven Eltern nachgezogen werden (Propagation nach oben).

Der Recompute ist Teil einer festen Pipeline pro Rezept (Reihenfolge verbindlich, weil Kosten/Nährwerte das frische `yield_kg` brauchen):

1. Yield + Zähler + Konfidenz (diese GL) → 2. Allergene (GL-01) → 3. Zusatzstoffe (GL-09) → 4. **Kosten (diese GL)** → 5. Nährwerte (GL-08) → 6. Spec-Flags.

Trigger im Ist: jeder Zutaten-/Rezept-Edit, Rezept-Duplikation, GP-Accept-Flows — überall via `recompute_recipe_and_propagate` (commands.rs:2727, 6740, 8348, 8426, 8454, 6268).

## 2. Eingaben / Ausgaben / Invarianten

**Feld-Mapping Quelle → Ziel** (vollständig in `02_DATENMODELL.md`):

| Quelle | Ziel |
|---|---|
| `recipes` | `foodalchemist_recipes` |
| `recipe_ingredients` (`menge`, `menge_max`, `einheit_vocab_id`, `putzverlust_pct`, `garverlust_pct`, `is_optional`, `match_method`, `match_confidence`, `gp_v2_id`, `referenced_recipe_id`) | `foodalchemist_recipe_ingredients` |
| `vocab_einheit` (`slug`, `dimension`, `default_in_g`, `default_in_ml`) | `foodalchemist_vocab_einheit` |
| `wawi_gp_v2.stk_default_g` | `foodalchemist_gps.stk_default_g` |
| `wawi_gp_count_unit_defaults` (`default_g` je GP×Einheit) | `foodalchemist_gp_count_unit_defaults` |

**Eingaben:** alle Zutatenzeilen des Rezepts; Einheiten-Vokabular; GP-Stückgewichte; Preis-pro-Einheit des Lead-LA bzw. LA-Durchschnitt (Normalisierung → **GL-11**); persistierte `ek_per_kg_eur` der referenzierten Sub-Rezepte.

**Ausgaben (persistiert am Rezept):** `yield_kg` (ROUND 3, kg; NULL wenn Summe 0), `n_zutaten_total`, `n_zutaten_ungemappt`, `allergene_konfidenz` (siehe GL-01), `ek_total_eur` (ROUND 2; NULL wenn 0), `ek_per_kg_eur` (ROUND 2; NULL wenn kein EK oder kein Yield), `ek_n_ingredients_total`, `ek_n_ingredients_priced`, `updated_at`.

**Invarianten:**

- **I1 — Topologie:** Beim Bulk-Recompute werden Rezepte in topologischer Ordnung berechnet (Blätter ohne Sub-Refs zuerst). Ein Eltern-Recompute liest nie veraltete Sub-Werte.
- **I2 — Zyklenfreiheit:** Der Sub-Rezept-Graph ist azyklisch. Zyklen werden beim Verknüpfen verhindert (Prüfung vor Save); der Topo-Sort bricht bei dennoch vorhandenen Zyklen mit Fehler + Liste der beteiligten `recipe_id`s ab (commands.rs:7867–7878).
- **I3 — Max. Tiefe 3:** Sub-Rezept-Hierarchie max. 3 Ebenen (Regelwerk §4, `SUBRECIPE_MAX_DEPTH = 3`). Im Ist nur Warn-Flag im Verknüpfungs-Inspector (`exceeds_limit`), kein harter Block — Ziel: Service-Validierung mit Block + Override-Recht.
- **I4 — Idempotenz:** Recompute beliebig oft ausführbar, gleiche Eingaben → gleiche Ausgaben.
- **I5 — Mapping-Gate:** `match_method='gemini_proposed'` zählt nur mit `match_confidence ≥ 0.85` als gemappt; darunter gilt die Zutat als ungemappt (zählt in `n_zutaten_ungemappt`, fällt aus der Kosten-Berechnung).
- **I6 — Mittelwert bei Bereich (F6.4):** `menge_avg = (menge + menge_max) / 2` wenn `menge_max` gesetzt, sonst `menge`. Gilt für Yield UND Kosten.
- **I7 — Rundungs-Reihenfolge:** `ek_per_kg_eur = ROUND(ek_total_ungerundet / yield_kg_gerundet, 2)` — Zähler ungerundet, Nenner ist das bereits persistierte (auf 3 Stellen gerundete) `yield_kg`. Golden-Tests hängen daran (GT-2).
- **I8 — Propagation:** Nach Einzel-Recompute werden alle transitiven Eltern per BFS (dedupliziert, Safety-Tiefe 10) ebenfalls recomputet; Fehler an einem Eltern-Rezept blockieren den auslösenden Edit nicht (best effort, loggen).

## 3. Algorithmus (Pseudocode)

### 3.1 Yield-Berechnung (ein Rezept)

```
yield_g = 0; n_total = 0; n_ungemappt = 0
für jede Zutat z des Rezepts:
    wenn z.match_method == 'ignored':            weiter (zählt nirgends)
    n_total += 1
    wenn NICHT gemappt(z) (siehe I5):            n_ungemappt += 1
    wenn z.is_optional ODER einheit.slug == 'qs': weiter (Yield-Beitrag 0)

    menge_avg = (z.menge + z.menge_max)/2  falls menge_max, sonst z.menge
    g_faktor  = COALESCE(einheit.default_in_g,
                         einheit.default_in_ml,          -- Volumen ≈ Dichte 1.0 (Wasser)
                         (gp.stk_default_g falls einheit.slug == 'stk'),
                         0)                               -- unbekannt → Beitrag 0
    yield_g += menge_avg * g_faktor
             * (1 - COALESCE(z.putzverlust_pct,0)/100)    -- ⚠ A-1, siehe §6
             * (1 - COALESCE(z.garverlust_pct,0)/100)

yield_kg = ROUND(yield_g/1000, 3) falls yield_g > 0, sonst NULL
```

**Wichtig:** Ungemappte Zutaten tragen zum Yield bei (Masse ist unabhängig vom GP-Mapping) — nur `ignored`/`is_optional`/`qs` fallen raus.

### 3.2 Kosten-Berechnung (ein Rezept; läuft NACH 3.1)

```
ek_total = 0; n_cost_total = 0; n_priced = 0
für jede Zutat z mit: nicht 'ignored', nicht optional, gemappt (GP gem. I5 ODER Sub-Ref):
    n_cost_total += 1
    menge_avg = wie oben (I6)
    menge_g   = menge_avg * COALESCE(einheit.default_in_g, einheit.default_in_ml,
                    count_unit_default_g(gp, einheit) falls dimension=='count',
                    gp.stk_default_g                  falls dimension=='count',
                    0)
    -- Preisquellen (Normalisierung → GL-11):
    p_g   = lead_price_per_g(gp)    sonst avg_price_per_g(gp)     -- €/g  (kg/l-LAs, nicht discontinued)
    p_stk = lead_price_per_stk(gp)  sonst avg_price_per_stk(gp)   -- €/Stk (Stk-LAs)
    p_sub = sub.ek_per_kg_eur / 1000                              -- €/g  (nur bei Sub-Ref)

    wenn einheit.dimension == 'count':
        wenn p_stk != NULL:        kosten = menge_avg * p_stk
        sonst wenn menge_g > 0:    kosten = menge_g * COALESCE(p_g, 0)      -- count→mass-Brücke
        sonst:                     kosten = 0 (unpriced)
    sonst:  -- mass / volume / pinch / piece
        kosten = menge_g * COALESCE(p_g,
                                    p_stk / gp.stk_default_g falls stk_default_g > 0,  -- Stk→g-Brücke
                                    p_sub, 0)
    n_priced += 1 falls eine Preisquelle griff
    ek_total += kosten

ek_total_eur  = ROUND(ek_total, 2)            falls > 0, sonst NULL
ek_per_kg_eur = ROUND(ek_total / yield_kg, 2) falls ek_total > 0 und yield_kg > 0, sonst NULL
```

### 3.3 Propagation nach oben (nach Einzel-Edit)

```
recompute_pipeline(recipe_id)                     -- 3.1 + GL-01/09 + 3.2 + GL-08 + Spec
kanten = lade child→[parents] aus recipe_ingredients.referenced_recipe_id
besucht = {recipe_id}; ebene = [recipe_id]; tiefe = 0
solange ebene nicht leer und tiefe < 10:
    naechste = alle noch nicht besuchten Eltern der ebene (dedupliziert)
    für p in naechste: recompute_pipeline(p)      -- Fehler: loggen, NICHT eskalieren (I8)
    ebene = naechste; tiefe += 1
```

### 3.4 Bulk-Recompute mit Topo-Sort (Kahn) + Zyklen-Schutz

```
in_degree[r] = Anzahl DISTINCT Sub-Rezepte, die r referenziert   -- Eltern haben in_degree > 0
queue = alle r mit in_degree == 0                                 -- Blätter zuerst
order = []
solange queue nicht leer:
    node = queue.pop(); order.append(node)
    für jeden parent, der node referenziert:
        in_degree[parent] -= 1
        wenn in_degree[parent] == 0: queue.push(parent)
wenn len(order) < Anzahl Rezepte:
    ABBRUCH mit Fehler "Zyklus" + Liste aller r mit in_degree > 0  -- Zyklen-Schutz
für r in order: recompute_pipeline(r)                              -- Kinder garantiert vor Eltern
```

### 3.5 Verknüpfungs-Guards (vor dem Speichern einer Sub-Referenz parent→sub)

- `parent == sub` → Selbstreferenz, ablehnen.
- BFS von `sub` abwärts durch dessen Sub-Refs: wird `parent` erreicht → Zyklus, ablehnen (commands.rs:7923).
- Projizierte Tiefe = `max(tiefe(parent), tiefe(sub) + 1)`; `> 3` → Regelwerk-§4-Verstoß flaggen (Ist: Warnung; Ziel: blocken).

### 3.6 VK-Marge-Bezug (nachgelagert — NICHT Teil des Recompute)

Der EK aus 3.2 ist die Eingangsgröße des Verkaufslayers (Schema aus `200_migration_phase_1b.py`): `recipes.aufschlagsklasse_id` (FK), `vk_netto`, `vk_brutto`, `mwst_satz` + Portionierung (`vk_einheit_vocab_id`, `vk_menge_pro_einheit_g`, `vk_anzahl_einheiten`, `vk_wording_standard`). Stammdaten: `aufschlagsklassen` → `foodalchemist_markup_classes` (`code`, `rohaufschlag_pct`, `bedienung_pct`, `profit_pct`, `mwst_satz`, `formel_typ` ∈ {`aufschlag`, `deckungsbeitrag`}; Seed real: ALC 420 % / BAN 260 % / BUF 220 % / TAW 180 % / EXT 200 % / MAV 80 %).

Ist: reine **manuelle** Pflege-Felder (Schreibpfade commands.rs:5763 ff., 6685 ff.), keine Auto-Berechnung im Backend; Befüllung marginal (2/1407 `vk_netto`, 4/1407 Klasse — der Editor war als Tauri-Phase-2 geplant und kam nie). **Invariante I9:** Der Recompute schreibt `vk_*` NIEMALS — Verkaufspreise sind User-Hoheit; eine EK-Änderung macht den VK nur *veraltet*, nie automatisch neu.

Ziel-Soll: VK-Vorschlag als **abgeleitetes, nicht persistiertes** Attribut:

```
ek_basis = ek_per_kg_eur × vk_menge_pro_einheit_g / 1000            -- EK je VK-Einheit
'aufschlag':       vk_netto_vorschlag = ek_basis × (1 + rohaufschlag_pct/100)
'deckungsbeitrag': Formel nirgends definiert/genutzt → Weiche W-1 (§6)
vk_brutto = vk_netto × (1 + mwst_satz/100)     -- mwst_satz am Rezept, Default aus Klasse
```

## 4. Entscheidungstabellen (normativ)

**T1 — Gramm-Faktor pro Zutat** (erste zutreffende Zeile gewinnt):

| # | Bedingung | Faktor (g pro Mengeneinheit) |
|---|---|---|
| 1 | `einheit.default_in_g` gesetzt (g, kg, msp, prise, bl, …) | `default_in_g` |
| 2 | `einheit.default_in_ml` gesetzt (ml, l, EL=15, TL=5, …) | `default_in_ml` × 1.0 (Wasser-Dichte) |
| 3 | Kosten-Pfad: `dimension='count'` + Eintrag in `gp_count_unit_defaults` für (GP, Einheit) | `default_g` des Eintrags (z. B. Knoblauch „Zehe" 5 g vs. „Knolle" 40 g) |
| 4 | Kosten-Pfad: `dimension='count'`; Yield-Pfad: nur `slug='stk'` (⚠ A-2) | `gp.stk_default_g` |
| 5 | sonst | 0 (kein Beitrag) |

> Hinweis Begrifflichkeit: Das Regelwerk nennt die Stückgewichts-Quelle `vocab_stk_default` (F6.3, Lookup Zutat-Name→g). Implementiert ist dieselbe Semantik GP-basiert: `gp.stk_default_g` + `gp_count_unit_defaults` — die F6.3-Seed-Werte stecken in den GP-Feldern (z. B. Lorbeerblatt 0,2 g = GP 4247). Siehe A-7.

**T2 — Zähl-/Beitragsregeln pro Zutat:**

| Zutat-Eigenschaft | `n_zutaten_total` | Yield-Beitrag | Kosten-Zeile |
|---|---|---|---|
| `match_method='ignored'` (Parser-Müll) | nein | nein | nein |
| `is_optional=1` | ja | nein | nein |
| Einheit `qs` | ja | nein | ja (Faktor 0 → unpriced) |
| ungemappt / `gemini_proposed` < 0.85 | ja (+ `n_ungemappt`) | **ja** | nein |
| gemappt (GP oder Sub-Ref) | ja | ja | ja |

**T3 — Kostenquellen-Kaskade** (pro Zutat, erste verfügbare gewinnt):

| Rezept-Dimension | 1. | 2. | 3. | 4. | 5. |
|---|---|---|---|---|---|
| `count` | Lead-€/Stk | AVG-€/Stk | count→mass: `menge_g × €/g` | — | 0 (unpriced) |
| mass/volume/pinch/piece | Lead-€/g | AVG-€/g | Stk→g-Brücke: `€/Stk ÷ stk_default_g` | Sub: `ek_per_kg ÷ 1000` | 0 (unpriced) |

**T4 — Ausgabe-Randfälle:**

| Situation | `yield_kg` | `ek_total_eur` | `ek_per_kg_eur` |
|---|---|---|---|
| keine (zählende) Zutat | NULL | NULL | NULL |
| Zutaten ohne Gramm-Faktor und ohne Preis | NULL | NULL | NULL |
| Yield > 0, alle Zutaten unpriced | Wert | NULL | NULL |
| EK > 0, Yield NULL/0 | NULL | Wert | NULL |

## 5. Golden-Testfälle (verbindliche Wahrheit)

> Reihenfolge der Wahrheit: Golden-Test > Entscheidungstabelle > Pseudocode. GT-1/GT-2 sind reale, gegen die Quell-DB (`wawi_1494.sqlite`, Stand 2026-06-10) verifizierte Fälle.

**GT-1 — Blatt-Rezept, 6 Zutaten, alle Pfade (real: recipe_id 1612 „ROTE-BETE-FOND"):**

| Zutat (GP) | Menge | Einheit | Preisquelle Lead-LA | €/Basis | Yield-Beitrag g | Kosten € |
|---|---|---|---|---|---|---|
| Rote Bete (5372) | 1000 | g | 0,76 €/1,0 kg | 0.00076 €/g | 1000 | 0.76 |
| Leitungswasser (1587) | 1000 | ml | 0,001 €/1,0 l | 0.000001 €/g | 1000 | 0.001 |
| Rotweinessig (5397) | 50 | ml | 8,14 €/2,0 l | 0.00407 €/g | 50 | 0.2035 |
| Zucker Bio (6403) | 30 | g | 42,00 €/25 kg | 0.00168 €/g | 30 | 0.0504 |
| Lorbeerblätter (4247, `stk_default_g`=0.2) | 2 | stk | 1,84 €/0,05 kg (kein Stk-Preis) | 0.0368 €/g | 0.4 | count→mass: 0.4×0.0368 = 0.01472 |
| Pfefferkörner schwarz (7692, `stk_default_g`=NULL, Lead ohne Einheit/qty) | 5 | stk | — | — | 0 | 0 (unpriced) |

Expected: `yield_kg = ROUND(2080.4/1000, 3) = 2.08` · `ek_total_eur = ROUND(1.02962, 2) = 1.03` · `ek_per_kg_eur = ROUND(1.02962/2.08, 2) = 0.5` · `ek_n_ingredients_total = 6`, `ek_n_ingredients_priced = 5`. ✓ identisch mit persistierten DB-Werten.

**GT-2 — 2-Ebenen-Sub-Rezept (real: recipe_id 1340 „ROTE BETE GEL" referenziert 1612):**

| Zutat | Menge | Einheit | Quelle | Kosten € |
|---|---|---|---|---|
| Sub-Rezept 1612 | 250 | ml | `ek_per_kg_eur`(1612)=0.5 → 0.0005 €/g | 250 × 0.0005 = 0.125 |
| Agar Agar (GP 1767, `gemini_proposed` conf ≥ 0.85) | 2.5 | g | Lead 36,90 €/0,5 kg → 0.0738 €/g | 2.5 × 0.0738 = 0.1845 |

Expected: `yield_kg = ROUND(252.5/1000, 3) = 0.253` · `ek_total_eur = ROUND(0.3095, 2) = 0.31` · `ek_per_kg_eur = ROUND(0.3095 / 0.253, 2) = 1.22` (Nenner = gerundetes `yield_kg`, **nicht** 0.2525 — sonst käme 1.23; Invariante I7). ✓ DB-identisch. Topologie-Probe: erst 1612, dann 1340 berechnen; Änderung an 1612 muss 1340 via Propagation aktualisieren.

**GT-3 — Mengen-Bereich (F6.4, synthetisch):** Zutat „1–2 EL Olivenöl" (`menge=1`, `menge_max=2`, EL=15 ml): `menge_avg = 1.5` → Yield-Beitrag `1.5 × 15 = 22.5 g`; Kosten mit `menge_g = 22.5`.

**GT-4 — Optional + QS (synthetisch):** Rezept mit (a) 100 g Mehl, (b) `is_optional=1` 50 g Butter, (c) Salz „qs". Expected: `n_zutaten_total = 3`, `yield_kg = 0.1` (nur a), Kosten nur aus (a) + (c) als Kosten-Zeilen — (c) unpriced (Faktor 0), (b) komplett ausgeschlossen.

**GT-5 — Putz-/Garverlust (⚠ Konfliktfall A-1):** 1000 g Zutat, `putzverlust_pct=20`, `garverlust_pct=10`. Regelwerk §6 F6.2 (normativ): `1000 × (1 − 0.20 − 0.10) = 700 g`. Ist-Implementierung: `1000 × 0.8 × 0.9 = 720 g`. → Entscheid nötig (§6 unten); der Golden-Test wird nach dem Entscheid auf genau einen Wert fixiert.

**GT-6 — Zyklen-Schutz (synthetisch):** A referenziert B, B referenziert A (per Direkt-SQL erzeugt). Expected: Bulk-Recompute bricht ab mit Fehlermeldung, die beide `recipe_id`s nennt; Verknüpfungs-Inspector hätte den Link vorab mit `creates_cycle=true` abgelehnt.

**GT-7 — Tiefen-Guard (synthetisch):** Kette A→B→C (Tiefe 3, erlaubt). Link C→D ergäbe projizierte Tiefe 4 → `exceeds_limit`; Ziel-Verhalten: Speichern blocken.

**GT-8 — VK-Vorschlag (synthetisch, §3.6):** Rezept mit `ek_per_kg_eur = 5.20`, `vk_menge_pro_einheit_g = 250`, Klasse ALC (`rohaufschlag_pct = 420`, `mwst_satz = 19`, `formel_typ = 'aufschlag'`): `ek_basis = 1.30 €` → `vk_netto_vorschlag = 1.30 × 5.2 = 6.76 €` → `vk_brutto = ROUND(6.76 × 1.19, 2) = 8.04 €`. Anschließender Recompute-Lauf: persistierte `vk_netto`/`vk_brutto` bleiben unverändert (I9).

## 6. Offene Weichen + Verbesserungen

**⚠ Ist-Implementierung weicht ab (Konflikt: Regelwerk gewinnt, Entscheid dokumentieren):**

- **A-1 Verlust-Formel + Quelle:** Regelwerk §6 F6.2: additiv `(1 − putz − gar)` mit Verlusten aus der **GP-Stammtabelle** (+ optionalem Zutat-Override). Ist: **multiplikativ** `(1−putz)×(1−gar)` aus den **Zutat-Feldern** `recipe_ingredients.putzverlust_pct/garverlust_pct`; `wawi_gp_v2` hat nur `garverlust_default_pct`, eine GP-Putzverlust-Spalte existiert gar nicht. Empfehlung an Fachseite: multiplikativ normieren (robust, nie < 0) und Zutat-Wert als Override über GP-Default — Entscheid in `08_ENTSCHEIDUNGEN.md` nachtragen, dann GT-5 fixieren.
- **A-2 Yield ≠ Kosten bei count-Einheiten:** Yield-Pfad löst Stückgewichte nur für `slug='stk'` über `gp.stk_default_g`; Kosten-Pfad nutzt zusätzlich `gp_count_unit_defaults` für **beliebige** count-Einheiten (Zehe, Bund, Scheibe …). Ziel: beide Pfade auf die T1-Kaskade vereinheitlichen (Kosten-Variante ist die vollständigere).
- **A-3 `yield_kg_manual` fehlt:** Regelwerk F6.1 sieht manuellen Override mit Vorrang vor — im Ist gibt es nur `yield_kg`, das bei jedem Recompute überschrieben wird. Ziel: `yield_kg_manual` (nullable) ergänzen, Anzeige-/Kalkulationswert = `COALESCE(yield_kg_manual, yield_kg)`.
- **A-4 Python-Spiegel ohne Topo-Sort:** `206_recompute_all_recipes.py` iteriert schlicht `ORDER BY recipe_id` — entgegen seiner Doku. Nur das Rust-`recompute_all_recipes` sortiert topologisch. Für den Port ist 3.4 (Kahn) verbindlich; ein Lauf in ID-Reihenfolge kann Eltern mit veralteten Sub-Werten hinterlassen.
- **A-5 Tiefe 3 nicht erzwungen:** Ist warnt nur (I3). Ziel: harter Block in der Service-Schicht.
- **A-6 Doppel-/Tot-Spalten in `recipe_ingredients` (Port-Bereinigung, lt. `02_DATENMODELL.md`):** Neben dem live genutzten Paar `putzverlust_pct`/`garverlust_pct` schleppt die Quelle Import-Altlasten: `prozent_garverlust` (9.590/9.590 befüllt, aber **ausnahmslos 0.0** — toter Parser-Default), `prozent_in_produkt` (konstant 100.0), `menge_in_g_computed` (komplett NULL). Der Recompute liest ausschließlich `garverlust_pct` (2.500 Zeilen mit echten Werten 5–20 %) und `putzverlust_pct` (Code-Pfad existiert, aktuell 0 Zeilen befüllt). Port: nur `putzverlust_pct` + `garverlust_pct` übernehmen, die drei toten Spalten **nicht** migrieren.
- **A-7 `vocab_stk_default` (F6.3) existiert nicht als Tabelle:** Regelwerk sieht ein Lookup Zutat-Name→g vor; implementiert ist die Semantik GP-basiert (`wawi_gp_v2.stk_default_g` + `wawi_gp_count_unit_defaults`, T1 Zeile 3/4). Port: GP-Modell übernehmen, keine separate Namens-Tabelle bauen — Abweichung vom Regelwerks-Wortlaut bewusst (GP-Bindung ist präziser als Name-Matching).

**Offene Weichen:**

- keine direkte D1-Betroffenheit; Preisquellen-Sichtbarkeit (⚠D1-Detailfrage) schlägt über GL-11 durch — wenn Preise team-gefiltert werden, wird `ek_total_eur` team-abhängig (dann: Aggregat pro Team oder globaler Einkaufs-Preisstand; Entscheid in `08_ENTSCHEIDUNGEN.md` D1).
- **W-1 — `formel_typ='deckungsbeitrag'`:** in den `aufschlagsklassen`-Stammdaten als CHECK-Wert vorgesehen, aber nirgends formelmäßig definiert oder genutzt (alle 6 Seed-Klassen sind `aufschlag`; `bedienung_pct`/`profit_pct` durchgängig 0). Formel-Entscheid (z. B. `vk = ek_basis / (1 − bedienung_pct/100 − profit_pct/100)`) in `08_ENTSCHEIDUNGEN.md` nachtragen; bis dahin nur `aufschlag` portieren.

**Verbesserungen (10_VERBESSERUNGS_REGISTER):**

- **V-07:** gesamte Pipeline pro Rezept (Yield→…→Kosten) + Propagation in **eine DB-Transaktion** je Rezept; im Ist Einzel-Executes ohne Klammer (Crash mittendrin = inkonsistente Aggregat-Spalten).
- **V-22:** Seed-ETL flaggt Rezepte ohne Zutaten/EK/Yield statt sie still zu übernehmen — die NULL-Randfälle aus T4 sind nach dem Seed Review-Queue, nicht Normalzustand.
- Kandidat (neu): Propagation asynchron als Queue-Job mit Re-Try statt Best-Effort-`eprintln` (I8) — Fehlerfälle werden heute nur geloggt.
