---
typ: Grundlogik-Spec
gl_id: GL-08
stand: 2026-06-10
status: ausgearbeitet
---

# GL-08 — Nährwert-Aggregation (LMIV pro 100 g)

## 1. Zweck & fachliche Quelle

Berechnet pro Rezept die fünf LMIV-Kernwerte **pro 100 g**: Energie (kcal), Eiweiß, Fett, Kohlenhydrate, Salz — als **mengen-gewichtete Mittelung** über die Zutaten. Datenquelle sind die Nährwert-Stammdaten der Lieferantenartikel (Necta/BLS, jeweils „pro 100 g des Lebensmittels"). Sub-Rezepte bringen ihre bereits persistierten `nutri_*_per_100g`-Werte ein (deshalb zwingend Kinder-vor-Eltern-Reihenfolge, siehe GL-02-Topologie).

**Normative Quellen:**
- LMIV (VO (EU) 1169/2011) — fachlicher Rahmen „Big 5 pro 100 g"; kein eigener Regelwerk-§ im Korpus
- `docs/regelwerke/Regelwerk_Basisrezepte.md` §6 — Mengen-/Einheiten-Logik (Mittelwert bei Mengen-Bereich, Einheiten-Vokabular); §7 — Filter-Philosophie (optionale/ignorierte Zutaten zählen nicht)

**Ist-Implementierung:** `src-tauri/src/commands.rs`
- `recompute_recipe_nutritional` — Z. 7335–7503 (Doku-Kommentar ab 7335, Funktion ab 7352)
- Aufruf-Kontext: `recompute_recipe_aggregations` Z. 6950 (nach Yield-Berechnung); Propagation/Topo siehe GL-01 §1
- Konfidenz-Schwellen: Z. 7488–7496

## 2. Eingaben / Ausgaben / Invarianten

**Eingaben (Ziel-Schema lt. `02_DATENMODELL.md`):**

| Quelle | Felder | Bedeutung |
|---|---|---|
| `foodalchemist_recipe_ingredients` | `gp_v2_id` / `referenced_recipe_id`, `menge`, `menge_max`, `einheit_vocab_id`, `match_method`, `match_confidence`, `is_optional` | Zutaten + Mengen |
| `foodalchemist_vocab_einheit` | `slug`, `dimension`, `default_in_g`, `default_in_ml` | Einheiten-Umrechnung (`qs` = ohne Menge, `count`-Dimension ohne Gramm-Default) |
| `foodalchemist_item_nutritionals` | `energy_kcal`, `protein`, `fat`, `carbs_absorbable`, `sodium` (mg) — jeweils pro 100 g | LA-Nährwerte |
| `foodalchemist_supplier_item_structures` + `foodalchemist_supplier_items` | `gp_v2_id`, `supplier_item_id`, `is_discontinued` | LA↔GP-Brücke; nur aktive LAs |
| `foodalchemist_recipes` (Sub) | `nutri_kcal_per_100g`, `nutri_protein_g_per_100g`, `nutri_fat_g_per_100g`, `nutri_carbs_g_per_100g`, `nutri_salt_g_per_100g` | Sub-Rezept-Beitrag (persistiert) |

**Ausgaben (auf `foodalchemist_recipes`):**

| Feld | Rundung | NULL wenn |
|---|---|---|
| `nutri_kcal_per_100g` | 1 Nachkommastelle | `n_mapped = 0` oder `total_g ≤ 0` |
| `nutri_protein_g_per_100g` / `nutri_fat_g_per_100g` / `nutri_carbs_g_per_100g` | 2 | dito |
| `nutri_salt_g_per_100g` | 3 | dito |
| `nutri_konfidenz` | Enum `high/medium/low/unknown` | nie (immer gesetzt) |
| `nutri_n_ingredients_mapped` / `nutri_n_ingredients_total` | INT | nie (Default 0) |
| `nutri_aggregiert_am` | Timestamp | nie (bei jedem Lauf) |

**Invarianten:**
1. `nutri_n_ingredients_total` zählt NUR die filter-relevanten Zutaten (nicht-optional, nicht-ignored, nicht-`qs`, gemappt bzw. Sub-Ref) — kann also kleiner sein als `n_zutaten_total` des Rezepts.
2. **Kein F7.1-Totalreset** (anders als Allergene/Zusatzstoffe): ungemappte Zutaten werden schlicht ausgefiltert; das Rezept bekommt trotzdem Nährwerte, die Unsicherheit steckt allein in `nutri_konfidenz` + `mapped/total`-Zählern.
3. Bezugsbasis ist die **rohe Einsatzmasse** `total_g = Σ menge_g` aller relevanten Zutaten — OHNE Putz-/Garverlust (bewusste Abweichung von der Yield-Formel, siehe §6).
4. Ungemappte-aber-relevante Zutaten (z. B. `count`-Einheit) gehen mit `menge_g` in die Basis ein, liefern aber 0-Beitrag — sie „verdünnen" das Ergebnis (konservativ Richtung Unterschätzung).
5. Pro Nährstoff unabhängige LA-Mittelung: AVG nur über LAs, die DIESEN Wert gepflegt haben (NULL-Werte fallen aus dem AVG, nicht auf 0).
6. Sub-Rezept-Werte müssen vor dem Eltern-Lauf frisch sein (Topo-Sort, GL-02).

## 3. Algorithmus (Pseudocode, sprachneutral)

```
funktion recompute_recipe_nutritional(recipe_id):
    zutaten = SELECT aus recipe_ingredients WHERE recipe_id = :recipe_id
              UND match_method != 'ignored'
              UND is_optional = 0
              UND einheit.slug != 'qs'
              UND ( (gp_v2_id IS NOT NULL
                     UND (match_method != 'gemini_proposed' ODER match_confidence >= 0.85))
                    ODER referenced_recipe_id IS NOT NULL )

    für jede zutat:
        menge_avg = wenn menge_max gesetzt: (menge + menge_max) / 2 sonst menge     # §6.4
        menge_g   = menge_avg * COALESCE(einheit.default_in_g, einheit.default_in_ml, 0)
                    # Volumen ≈ Masse mit Dichte 1.0; count/stk → 0 (kein Gramm-Default)

        wenn zutat.referenced_recipe_id gesetzt:               # Sub-Pfad gewinnt vor GP-Pfad
            wert_X = sub.nutri_X_per_100g                      # X ∈ {kcal, protein, fat, carbs, salt}
        sonst:                                                 # GP-Pfad
            für jeden Nährstoff X einzeln:
                wert_X = AVG(item_nutritionals.X) über alle LAs des GP
                         mit is_discontinued = 0 UND X IS NOT NULL
            # Salz-Sonderfall: Quelle ist sodium (mg/100g) →
            #   salt_g_per_100g = sodium_mg * 0.0025   (Na→NaCl-Faktor 2.5, mg→g /1000)

        beitrag_X     = wert_X * menge_g / 100                 # absoluter Beitrag in kcal bzw. g
        is_mapped     = (menge_g > 0 UND wert_kcal IS NOT NULL)  # kcal = Leit-Indikator

    total_g  = Σ menge_g            # ALLE relevanten Zutaten, auch unmapped
    sum_X    = Σ COALESCE(beitrag_X, 0)
    n_total  = COUNT(zutaten); n_mapped = Σ is_mapped

    wenn n_mapped > 0 UND total_g > 0:
        nutri_X_per_100g = ROUND(sum_X * 100 / total_g, stellen(X))
    sonst:
        nutri_X_per_100g = NULL

    nutri_konfidenz = siehe Tabelle 4.3
    nutri_n_ingredients_mapped/total + nutri_aggregiert_am setzen
```

## 4. Entscheidungstabellen (normativ)

### 4.1 Pfad-Wahl + Wertquelle pro Zutat

| Fall | Wertquelle | `is_mapped` |
|---|---|---|
| Sub-Rezept-Zutat, Sub hat `nutri_kcal_per_100g` | persistierte Sub-Werte (alle 5 direkt, Salz schon in g) | 1 (wenn `menge_g > 0`) |
| Sub-Rezept-Zutat, Sub-Werte NULL (z. B. Stub) | kein Beitrag | 0 |
| GP-Zutat mit ≥1 aktivem LA mit Nährwerten | pro Nährstoff AVG über aktive LAs (NULL-Werte fließen nicht ein) | 1 (wenn kcal vorhanden und `menge_g > 0`) |
| GP-Zutat ohne LA-Nährwerte (z. B. Derivat, `requires_la = 0`) | kein Beitrag, `menge_g` zählt in Basis | 0 |
| `count`-Einheit (Stk) ohne Gramm-Default | `menge_g = 0` ⇒ kein Beitrag, zählt nicht in Basis | 0 |
| `qs` / optional / ignored / gemini < 0.85 | komplett ausgefiltert (zählt auch nicht in `n_total`) | — |

### 4.2 Mengen- und Salz-Ableitung

| Regel | Formel |
|---|---|
| Mengen-Bereich (§6.4) | `menge_avg = (menge + menge_max) / 2` |
| Masse | `menge_g = menge_avg × default_in_g` |
| Volumen | `menge_g = menge_avg × default_in_ml × 1.0` (Wasser-Dichte) |
| Stück (`piece` mit Default, z. B. `bl` = 1.7 g) | `menge_g = menge_avg × default_in_g` |
| Stück (`count` ohne Default) | `menge_g = 0` — **kein** Fallback auf `gps.stk_default_g` (Lücke, siehe §6) |
| Salz aus GP-Pfad | `salt_g = sodium_mg × 0.0025` |
| Salz aus Sub-Pfad | direkt `sub.nutri_salt_g_per_100g` |

### 4.3 Konfidenz-Ableitung (Z. 7488–7496)

| Bedingung (erste zutreffende gewinnt) | `nutri_konfidenz` |
|---|---|
| `n_total = 0` | `unknown` |
| `n_mapped = 0` | `unknown` |
| `n_mapped = n_total` (100 %) | `high` |
| `n_mapped / n_total ≥ 0.8` | `medium` |
| sonst (> 0 %) | `low` |

### 4.4 NULL-Semantik der Ausgabe

| Situation | Ausgabe |
|---|---|
| `n_mapped > 0` und `total_g > 0` | alle 5 Werte gesetzt (auch wenn einzelne Nährstoffe quellenseitig fehlen → dann faktisch 0-Anteil, siehe GT-02) |
| sonst | alle 5 Werte NULL, Zähler + Konfidenz trotzdem gesetzt |

## 5. Golden-Testfälle (verbindliche Wahrheit; Quelle: `wawi_1494.sqlite`, Stand 2026-06-10)

**GT-01 — Voll durchgerechnete Mittelung.** Input: Rezept 220 „Nass-Marinade: Soja-Mirin", 3 Zutaten: 100 ml Mirin (GP 4534, LA-AVG 236.0 kcal/100g), 40 g Ketjap Manis (GP 3686, 211.0), 60 ml Sojasauce (GP 5818, 73.83). Rechnung: `menge_g` = 100 / 40 / 60 ⇒ `total_g = 200`; `sum_kcal = 236.0×1.0 + 211.0×0.4 + 73.83×0.6 = 364.698`; `kcal/100g = 364.698×100/200 = 182.349 → 182.3`. Expected: `nutri_kcal_per_100g = 182.3`, `nutri_konfidenz = 'high'`, `mapped/total = 3/3`. (DB-verifiziert.)

**GT-02 — Fehlender Einzel-Nährstoff wird NICHT zu NULL, sondern zu 0-Anteil.** Input: gleiches Rezept 220 — keiner der 3 GPs hat gepflegte `sodium`-Werte. Expected: `nutri_salt_g_per_100g = 0.0` (nicht NULL!), obwohl Sojasauce real salzig ist. Normativ für Parität; als fachliche Schwäche in §6 adressiert.

**GT-03 — Leeres Rezept.** Input: Rezept 727 (0 Zutaten). Expected: alle 5 Werte NULL, `nutri_konfidenz = 'unknown'`, `mapped/total = 0/0`. (DB-verifiziert.)

**GT-04 — Count-Zutat + ungemappte Zutat, kein Totalreset.** Input: Rezept 1115 „SORBET SÜSS – BLITZREZEPT", 4 Zutaten: „Fruchtpüree" `gemini_proposed` conf 0.8 < 0.85 ⇒ ausgefiltert (zählt nicht in `n_total`); „1 Stk. Eiweiß" (`count`, kein Gramm-Default) ⇒ relevant, aber `menge_g = 0` ⇒ unmapped; Puderzucker + Zitronensaft gemappt. Expected: `nutri_n_ingredients_total = 3`, `mapped = 2`, Quote 0.667 < 0.8 ⇒ `nutri_konfidenz = 'low'`, `nutri_kcal_per_100g = 376.7` (berechnet aus den 2 gemappten Zutaten ÷ deren Massebasis). Kontrast zu GL-01/GL-09: Allergene und Zusatzstoffe desselben Rezepts stehen wegen F7.1 komplett auf `unbekannt`/NULL. (DB-verifiziert.)

**GT-05 — Sub-Rezept-Pfad.** Input: Rezept 1330 „PARMESANFETT" referenziert Sub 315 „Heller Fond: Parmesanwasser" (`nutri_kcal_per_100g = 265.3`). Expected: Sub-Beitrag = `265.3 × menge_g/100`; bei 1330 ist der Fond massedominant ⇒ Ergebnis 265.3, `nutri_konfidenz = 'high'`. Invariante: Wird Sub 315 geändert, MUSS 315 vor 1330 recomputed werden (Topo-Sort) — sonst rechnet 1330 mit veralteten Werten. (DB-verifiziert.)

**GT-06 — Konfidenz-Schwellen (synthetisch).** Input: Rezept mit 5 relevanten Zutaten. Expected: 5/5 mapped ⇒ `high`; 4/5 = 0.8 ⇒ `medium` (Grenze inklusiv); 3/5 = 0.6 ⇒ `low`; 0/5 ⇒ `unknown` + alle Werte NULL.

## 6. Offene Weichen + Verbesserungen

**Dokumentierte Ist-Eigenheiten (kein Regelwerk-Konflikt — es existiert kein Nährwert-§ — aber bewusst zu entscheiden):**
- **Rohmasse statt Yield als 100g-Basis:** Putz-/Garverluste werden ignoriert (`total_g` ≠ `yield_kg×1000`). Bei Reduktionen/Fonds wird die Nährstoffdichte des fertigen Produkts systematisch unterschätzt. Verbesserungs-Kandidat: Basis auf `yield_g` umstellen ODER beide Sichten ausweisen („pro 100 g Einsatz" vs. „pro 100 g Fertigprodukt"). Entscheidung vor Port nötig — Golden-Tests oben fixieren das IST (Rohmasse).
- **Per-Nährstoff-0-Substitution (GT-02):** fehlende Einzelwerte erscheinen als 0.0 statt NULL — für LMIV-Angaben riskant (falsche Sicherheit bei Salz). Verbesserung: per-Nährstoff-Coverage mitführen (z. B. `nutri_salt_coverage_pct`) oder Wert auf NULL lassen, wenn < x % der Masse abgedeckt.
- **Count-Zutaten nicht abbildbar:** kein Fallback auf `gps.stk_default_g` / `gp_count_unit_defaults` — die Kosten-Logik (GL-02) kann das bereits (Z. 7213–7220). Verbesserung: gleiche Stück→Gramm-Brücke auch hier nutzen; hebt viele `low`-Rezepte auf `high`.
- **AVG über LAs statt Lead-LA:** Kosten rechnen Lead-LA-first (GL-03/GL-02), Nährwerte mitteln über alle aktiven LAs. Inkonsistenz ist vertretbar (Nährwerte streuen weniger als Preise), aber im Ziel dokumentiert beibehalten oder bewusst auf Lead-LA-first harmonisieren — nicht stillschweigend mischen.
- **⚠D1:** `item_nutritionals` sind globale Stammdaten (team_id nullable); Ergebnis-Felder liegen team-eigen auf `foodalchemist_recipes`.
- Transaktion + Reihenfolge: Nährwert-Lauf braucht frisches `yield_kg`-Umfeld nicht direkt, wohl aber frische Sub-Werte ⇒ immer als Teil des Gesamt-Recompute (GL-02) ausführen, nie isoliert für Eltern-Rezepte (V-07).
