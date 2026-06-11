---
typ: Grundlogik-Spec
gl_id: GL-01
stand: 2026-06-10
status: ausgearbeitet
---

# GL-01 — Allergen-Aggregation (ALL-MAXIMAL)

## 1. Zweck & fachliche Quelle

Jedes Rezept trägt 14 EU-Allergen-Felder, die NICHT manuell gepflegt, sondern aus den Zutaten **aggregiert** werden. Die Kette läuft über vier Ebenen:

```
LA (item_allergens, Necta-Stammdaten)
  → GP (MAX über alle LAs des GP, optional GP-Override)
    → Rezept (MAX über alle Zutaten)
      → Eltern-Rezept (Sub-Rezept-Werte werden wie Zutaten gemerged, Propagation nach oben)
```

Prinzip **ALL-MAXIMAL** (worst case): auf jeder Ebene gewinnt pro Allergen der höchste Rang. Eine einzige Zutat mit `enthalten` macht das ganze Rezept `enthalten`.

**Normative Quellen (Regelwerk gewinnt bei Konflikt mit Ist-Code):**
- `docs/regelwerke/Regelwerk_Lieferantenartikel.md` §10 — ALL-MAXIMAL-Hierarchie + Konfidenz-Logik
- `docs/regelwerke/Regelwerk_Grundprodukte.md` §16 — Vererbungs-Pfad LA→GP→Rezept; §16 Sonderfall + §11.2 — LIVE-Vererbung für Derivat-GPs
- `docs/regelwerke/Regelwerk_Basisrezepte.md` §7 — F7.1 (ungemappte Zutat ⇒ alles `unbekannt`), F7.2 (eine LA mit `spuren` reicht), F7.3 (Stub = alles `unbekannt`)

**Ist-Implementierung (Tauri/Rust, einzige Code-Referenz):** `src-tauri/src/commands.rs`
- `recompute_recipe_allergens` — Z. 6968–7185 (Kern-Aggregation)
- `recompute_recipe_aggregations` — Z. 6868–6954 (setzt vorab `n_zutaten_ungemappt` + `allergene_konfidenz`, ruft danach Allergen-/Zusatz-/Kosten-/Nährwert-Aggregation)
- `recompute_recipe_and_propagate` — Z. 7979–8017 (Propagation zu Eltern-Rezepten, BFS, Safety-Tiefe 10)
- `topo_sort_recipes` (Kahn) — Z. 7782 ff.; `recompute_all_recipes` — Z. 8064–8078 (Bulk, Kinder vor Eltern)
- Wert↔INT-Mapping — Z. 1028–1045 (`str_to_allergen_int` / `int_to_allergen_str`)

## 2. Eingaben / Ausgaben / Invarianten

**Eingaben (Ziel-Schema lt. `02_DATENMODELL.md`):**

| Quelle | Felder | Bedeutung |
|---|---|---|
| `foodalchemist_recipe_ingredients` | `gp_v2_id` (FK GP) ODER `referenced_recipe_id` (FK Sub-Rezept), `match_method`, `match_confidence`, `is_optional` | Zutatenliste; bestimmt Filter + Pfad |
| `foodalchemist_item_allergens` | `gluten, crustaceans, egg, fish, peanut, soy, milk, nuts, celery, mustard, sesame, sulfites, lupin, molluscs` — INTEGER 0–3 | LA-Ebene (Necta): 0=unbekannt, 1=nicht_enthalten, 2=spuren, 3=enthalten |
| `foodalchemist_supplier_item_structures` | `gp_v2_id`, `supplier_item_id` | LA↔GP-Brücke |
| `foodalchemist_gps` | `allergen_<X>` (14× TEXT, nullable), `allergene_quelle` (`manual`/`ai_inferred`), `is_derivat`, `derivat_von_gp_id` | GP-Override + Derivat-Verweis |
| `foodalchemist_recipes` (Sub-Rezept) | `allergen_<X>` (14× TEXT), persistiert | Sub-Rezept-Beitrag |

**Ausgaben (auf `foodalchemist_recipes`):**
- 14× `allergen_<X>` TEXT, CHECK/Enum: `enthalten | spuren | nicht_enthalten | unbekannt`, NOT NULL, Default `unbekannt`
- `allergene_konfidenz` Enum: `high | medium | low | unknown` (wird in `recompute_recipe_aggregations` gesetzt, nicht in der Allergen-Funktion selbst)
- Folge-Feld `spec_is_gluten_free` (1/0/NULL) — direkt aus `allergen_glutenhaltiges_getreide` abgeleitet (Z. 7177–7183)

**Invarianten:**
1. Rezept-Allergenfelder sind **reine Ableitungen** — kein UI-/API-Pfad darf sie direkt schreiben.
2. Default und Fallback ist immer `unbekannt` (kein false-confident, §7 Basisrezepte).
3. Aggregation läuft **nach jedem** Zutaten-/Rezept-Write (`recompute_recipe_and_propagate`), danach alle transitiven Eltern (Kinder vor Eltern; im Bulk via Topo-Sort, bei Zyklen Abbruch mit Fehler).
4. Sub-Rezept-Tiefe max. 3 (Regelwerk Basisrezepte §4, `SUBRECIPE_MAX_DEPTH`, Z. 8020); Propagation hat Safety-Limit 10 Ebenen.
5. F7.1-Guard: sobald `n_zutaten_ungemappt > 0` ⇒ ALLE 14 Felder `unbekannt`, Konfidenz `low`, keine Teilaggregation.
6. Ist-Detail: `allergene_aggregiert_am` wird vom Recompute NICHT aktualisiert (nur von KI-Accept-Pfaden, Z. 10637) — im Ziel beim Recompute mitschreiben (Konsistenz mit `zusatz_aggregiert_am`).

## 3. Algorithmus (Pseudocode, sprachneutral)

```
funktion recompute_recipe_allergens(recipe_id):
    # Voraussetzung: recompute_recipe_aggregations lief vorher
    # (n_zutaten_total, n_zutaten_ungemappt, allergene_konfidenz sind frisch)

    wenn recipes.n_zutaten_ungemappt > 0:                      # F7.1-Guard
        setze alle 14 allergen_<X> = 'unbekannt'
        setze spec_is_gluten_free = NULL
        return

    zutaten = SELECT aus recipe_ingredients WHERE recipe_id = :recipe_id
              UND match_method != 'ignored'
              UND is_optional = 0
              UND ( (gp_v2_id IS NOT NULL
                     UND (match_method != 'gemini_proposed' ODER match_confidence >= 0.85))
                    ODER referenced_recipe_id IS NOT NULL )

    für jede zutat, für jedes der 14 allergene X:
        wenn zutat.gp_v2_id gesetzt:                           # GP-Pfad (gewinnt vor Sub-Pfad)
            wenn gp.allergen_X IS NOT NULL:                    # Override-First
                rang = text_zu_rang(gp.allergen_X)             # 'unbekannt'→0 … 'enthalten'→3
            sonst wenn gp.is_derivat = 1:                      # SOLL §16 — siehe ⚠ Abweichung A2
                rang = aufgelöster Rang des Mutter-GP (derivat_von_gp_id), LIVE
            sonst:
                rang = MAX(item_allergens.X) über ALLE LAs des GP   # INT 0–3; NULL wenn keine Daten
        sonst:                                                 # Sub-Rezept-Pfad
            rang = text_zu_rang(sub_recipe.allergen_X)         # 'unbekannt' → NULL (= kein Beitrag)

    für jedes allergen X:
        rezept_rang = MAX(rang aller zutaten)                  # SQL-MAX ignoriert NULL
        recipes.allergen_X = rang_zu_text(rezept_rang)         # NULL → 'unbekannt'

    recipes.spec_is_gluten_free =
        CASE allergen_glutenhaltiges_getreide
            'nicht_enthalten' → 1; 'enthalten' / 'spuren' → 0; 'unbekannt' → NULL
```

Propagation (`recompute_recipe_and_propagate`, Z. 7979): nach Recompute des Rezepts werden über die Kanten `child → parents` (aus `referenced_recipe_id`) alle transitiven Eltern per BFS schichtweise neu gerechnet (max. 10 Ebenen, Fehler pro Eltern-Rezept werden geloggt, blocken den Edit nicht). Bulk-Recompute läuft topologisch sortiert (Kinder zuerst), damit Eltern immer frische Sub-Werte lesen.

## 4. Entscheidungstabellen (normativ)

### 4.1 Rang-Skala (Basis aller Merges)

| Wert | Rang | INT in `item_allergens` |
|---|---|---|
| `enthalten` | 3 (höchster) | 3 |
| `spuren` | 2 | 2 |
| `nicht_enthalten` | 1 | 1 |
| `unbekannt` | 0 (niedrigster) | 0 oder NULL |

> Achtung: `unbekannt` ist der NIEDRIGSTE Rang — ein konkreter Wert gewinnt immer gegen `unbekannt` (LA-Regelwerk §10: „konkreter gewinnt"). Die konservative Absicherung gegen Unwissen leistet NICHT die Merge-Matrix, sondern der F7.1-Guard (ungemappte Zutat ⇒ Totalreset auf `unbekannt`). Hinweis: der Rust-Quellcode-Kommentar Z. 6961 behauptet fälschlich `unbekannt > nicht_enthalten` — Implementierung UND Regelwerk sagen das Gegenteil; der Kommentar ist irreführend, nicht der Code.

### 4.2 Merge-Matrix 4-Wert-Modell (vollständig; symmetrisch, MAX-Rang gewinnt)

| Merge von ↓ + → | enthalten | spuren | nicht_enthalten | unbekannt |
|---|---|---|---|---|
| **enthalten** | enthalten | enthalten | enthalten | enthalten |
| **spuren** | enthalten | spuren | spuren | spuren |
| **nicht_enthalten** | enthalten | spuren | nicht_enthalten | nicht_enthalten |
| **unbekannt** | enthalten | spuren | nicht_enthalten | unbekannt |

Gilt identisch für: LA+LA → GP, Zutat+Zutat → Rezept, Sub-Rezept+Zutat → Rezept. Leere Menge (keine Beiträge) ⇒ `unbekannt`.

### 4.3 Auflösung pro Zutat (Prioritätskette)

| Prio | Bedingung | Quelle des Rangs |
|---|---|---|
| 1 | GP-Zutat + `gps.allergen_X IS NOT NULL` | GP-Override (absolut — er wird NICHT mit LA-Werten gemerged; ein Override `nicht_enthalten` schlägt eine LA mit `enthalten`) |
| 2 | GP-Zutat + Derivat (`is_derivat=1`) | **SOLL:** LIVE vom Mutter-GP (`derivat_von_gp_id`) auflösen (Regelwerk §16). **⚠ A2: Ist-Implementierung weicht ab** — kein Mutter-Join; Derivat ohne Override liefert NULL (= kein Beitrag) |
| 3 | GP-Zutat, normal | `MAX(item_allergens.X)` über alle LAs des GP (Ist: OHNE `is_discontinued`-Filter — anders als Nährwert/Kosten; im Ziel vereinheitlichen, siehe §6) |
| 4 | Sub-Rezept-Zutat | persistierter `recipes.allergen_X` des Sub; `unbekannt` ⇒ kein Beitrag (NULL) |
| — | Zutat fällt durch Filter (ignored / optional / gemini < 0.85 ohne Mapping) | kein Beitrag; gemini < 0.85 zählt zusätzlich als „ungemappt" und triggert F7.1 |

### 4.4 Konfidenz-Ableitung Rezept-Ebene (Ist, Z. 6930–6937 — normativ für Parität)

| Bedingung (erste zutreffende gewinnt) | `allergene_konfidenz` |
|---|---|
| `n_zutaten_total = 0` (Stub, F7.3) | `unknown` |
| `n_zutaten_ungemappt > 0` (F7.1) | `low` |
| ≥1 Zutat via `gemini_proposed` mit confidence ≥ 0.85 gemappt | `medium` |
| alle Zutaten high-confidence gemappt (override/manual/recipe_ref) | `high` |

### 4.5 Konfidenz GP-Ebene (Regelwerk LA §10 — SOLL, ⚠ A1: im Ist NICHT implementiert)

| Situation | GP-`allergene_konfidenz` |
|---|---|
| Alle aktiven LAs identisches Profil | HIGH |
| LAs unterscheiden sich nur `unbekannt` vs. konkreter Wert | HIGH (konkreter gewinnt) |
| LAs unterscheiden sich auf gleicher Hierarchie-Stufe | MED |
| Konflikt `enthalten` ↔ `nicht_enthalten` (ohne `spuren`-Mittelweg) | LOW + GP-Status `needs_allergen_review` |
| GP ohne LA mit gepflegten Allergenen | NONE |

## 5. Golden-Testfälle (verbindliche Wahrheit; Quelle: `wawi_1494.sqlite`, Stand 2026-06-10)

> Normativität: Golden-Test > Entscheidungstabelle > Pseudocode. IDs beziehen sich auf die Quell-DB (werden beim Seed mitmigriert).

**GT-01 — Leeres Rezept (Stub, F7.3).** Input: Rezept 727 „Catch-All: Garzeiten & Kerntemperaturen", 0 Zutaten. Expected: alle 14 = `unbekannt`, `allergene_konfidenz = 'unknown'`. (DB-verifiziert.)

**GT-02 — Ungemappte Zutat / Gemini-Schwelle (F7.1).** Input: Rezept 1115 „SORBET SÜSS – BLITZREZEPT", 4 Zutaten; „500 g Fruchtpüree" ist `gemini_proposed` mit confidence **0.8 < 0.85** ⇒ zählt als ungemappt (`n_zutaten_ungemappt = 1`), die übrigen 3 sind sauber gemappt. Expected: ALLE 14 = `unbekannt` (auch die, die aus den 3 gemappten Zutaten ableitbar wären), `allergene_konfidenz = 'low'`, `spec_is_gluten_free = NULL`. (DB-verifiziert.)

**GT-03 — Sub-Rezept-Vererbung + Derivat-Zutat (Ist-Verhalten).** Input: Rezept 313 „Gel: Gestocktes Hühnerfett", 4 Zutaten: (a) Sub-Rezept 638 „Heller Fond: Geflügel" mit `milch/gluten/sellerie = enthalten`; (b) GP 1767 Agar (`gemini_proposed`, conf 1.0); (c) GP 2014 Blattgelatine (`override_gp`); (d) GP 7816 „Huehnerfett: frisch" — **Derivat** (`is_derivat=1`, 0 LAs, kein Override ⇒ Beitrag NULL). Expected: `allergen_milch = allergen_glutenhaltiges_getreide = allergen_sellerie = 'enthalten'` (vom Sub geerbt), `allergene_konfidenz = 'medium'` (wegen gemini-Zutat ≥ 0.85). (DB-verifiziert.)

**GT-04 — LA→GP-Merge.** Input: GP 3672 „Cornflakes: trocken" mit 3 LAs, `gluten`-Werte {3, 3, 2}. Expected GP-Auflösung für eine Rezept-Zutat: Rang MAX(3,3,2)=3 ⇒ `enthalten`. Synthetische Ergänzung: LAs {1, 0} ⇒ `nicht_enthalten` (konkreter Wert schlägt unbekannt); LAs {0, 0} ⇒ `unbekannt`. (DB-verifiziert.)

**GT-05 — Merge-Matrix-Randfall (kontraintuitiv, normativ).** Input (synthetisch): Rezept, 2 gemappte Zutaten — Zutat A löst zu `nicht_enthalten` (Rang 1), Zutat B löst zu `unbekannt` (Rang 0, z. B. GP-Override `unbekannt` oder alle LA-Werte 0). Expected: Rezept-Wert `nicht_enthalten`, NICHT `unbekannt` (Matrix 4.2, Zeile nicht_enthalten×unbekannt). Konfidenz nach 4.4 unverändert (mapping-basiert).

**GT-06 — Derivat LIVE-Vererbung (SOLL, Regelwerk §16/§11.2).** Input (synthetisch, da Quell-DB hier abweicht): Derivat-GP „Huehnerfett: frisch" (7816) mit `derivat_von_gp_id = 7827` („Haehnchen: frisch, ganz"); Mutter-GP löst für `soja` zu `spuren` auf. Rezept mit einziger Zutat 7816. Expected (Ziel-Implementierung): `allergen_soja = 'spuren'` — LIVE vom Mutter gelesen, kein Snapshot; ändert sich der Mutter, ändert sich das Rezept beim nächsten Recompute. **Ist-Implementierung würde `unbekannt` liefern (⚠ A2)** — der Golden-Test fixiert das SOLL.

**GT-07 — GP-Override ist absolut.** Input (synthetisch): GP mit einem LA `milk = 3` (enthalten) und GP-Override `allergen_milch = 'nicht_enthalten'` (`allergene_quelle = 'manual'`). Rezept mit dieser einen Zutat. Expected: `allergen_milch = 'nicht_enthalten'` — Override ersetzt die LA-Aggregation vollständig (Prioritätskette 4.3, Prio 1), wird nicht gemax-t.

**GT-08 — Klassische 2-Ebenen-Kette Mornay → Béchamel → Mehl.** Input: Rezept 1561 „Sauce Mornay" = Sub-Rezept 1560 „Béchamel: Klassisch" (`recipe_ref`) + GPs Gruyère (3192), Eigelb (2556), Butter (2193). Béchamel selbst: 6 GP-Zutaten, darunter GP 6265 „Weizenmehl: trocken, Type 1050, Bio" mit LA-`gluten = 3` und GP 3235 „Milch: konserviert, 1,5 % Fett" mit `gluten = 1`. Expected: 1560 ⇒ `allergen_glutenhaltiges_getreide = 'enthalten'` (MAX(3,1,…) über die Zutaten), `allergen_milch = 'enthalten'`; 1561 erbt beides über den persistierten Sub-Wert ⇒ ebenfalls `enthalten`, `allergene_konfidenz = 'high'` auf beiden Ebenen (alle Zutaten high-conf gemappt). Ändert sich das Mehl-LA, MUSS der Recompute 1560 VOR 1561 laufen (Topo-Sort) bzw. nach Edit an 1560 via Propagation 1561 nachziehen. (DB-verifiziert.)

## 6. Offene Weichen + Verbesserungen

**Dokumentierte Abweichungen Ist ↔ Regelwerk:**
- **⚠ A1 — GP-Konfidenz + Review-Status fehlen:** Regelwerk LA §10 fordert `allergene_konfidenz` auf GP-Ebene (Tabelle 4.5) inkl. `needs_allergen_review` bei `enthalten`↔`nicht_enthalten`-Konflikt und Konflikt-Logging. Ist: nicht implementiert; Konfidenz existiert nur rezept-seitig und ist rein mapping-basiert. Ziel: GP-Konfidenz nach 4.5 implementieren, Review-Fälle in die Review-Queue (V-10).
- **⚠ A2 — Derivat-LIVE-Vererbung fehlt in der Aggregation:** Regelwerk GP §16/§11.2 fordert LIVE-Lesen vom Mutter-GP. Ist (`recompute_recipe_allergens`): kein Join auf `derivat_von_gp_id` ⇒ Derivat-Zutaten liefern keinen Allergen-Beitrag. Ziel: Mutter-Auflösung gemäß 4.3 Prio 2 (eine Ebene reicht — Derivat-von-Derivat ist verboten, `create_derivat_gp` Z. 2916).
- **Inkonsistenz Discontinued-Filter:** Allergen-Pfad aggregiert über ALLE LAs, Nährwert/Kosten nur über nicht-discontinued. Für Allergene ist „alle LAs" die konservativere (sichere) Wahl — im Ziel explizit so festschreiben und begründen, nicht versehentlich „vereinheitlichen" in die unsichere Richtung.

**Verbesserungen / Weichen:**
- **V-08 (GP-Allergen-Lücke, Prio hoch/Compliance):** nur 16 von 7.774 GPs haben gepflegte Override-Werte; alles andere hängt an LA-Daten. Entscheid vor Seed: Bulk-Befüllung ODER GP-Ebene offiziell „nur Vererbung" deklarieren. Bis dahin liefern GPs ohne LA-Allergen-Daten stillschweigend keinen Beitrag — kombiniert mit Matrix 4.2 kann ein Rezept dann `nicht_enthalten` zeigen, obwohl eine Zutat faktisch unbewertet ist (siehe GT-05). → `10_VERBESSERUNGS_REGISTER.md` V-08.
- **⚠D1:** `item_allergens`/`gps` sind globale Stammdaten (team_id nullable) — Aggregation liest global, schreibt team-eigene `recipes`.
- `allergene_aggregiert_am` beim Recompute setzen (Invariante 6) — Mini-Fix, kostenlos im Port.
- Transaktionssicherheit: Recompute + Propagation in eine DB-Transaktion (V-07).
