---
typ: Grundlogik-Spec
gl_id: GL-09
stand: 2026-06-10
status: ausgearbeitet
---

# GL-09 — Zusatzstoff-Aggregation (18 LMIV-Kennzeichnungen)

## 1. Zweck & fachliche Quelle

Aggregiert pro Rezept 18 kennzeichnungspflichtige Zusatzstoff-/Hinweis-Flags (LMIV/ZZulV: „mit Farbstoff", „mit Konservierungsstoff", „geschwefelt", „koffeinhaltig" …) aus den **`declarations`-Stammdaten** der Lieferantenartikel. Logik ist das Schwester-Verfahren zu GL-01: **ALL-MAXIMAL** über alle Zutaten, gleiche Kette LA → GP → Rezept → Eltern-Rezept, gleicher F7.1-Guard. Unterschiede: INTEGER- statt TEXT-Werte, kein GP-Override-Layer, kein eigenes Konfidenz-Feld.

**Normative Quellen:**
- `docs/regelwerke/Regelwerk_Basisrezepte.md` §7 — „18 Zusatzstoffe aus declarations-Tabelle: 0/1/NULL" (zur tatsächlichen Wertedomäne siehe ⚠ A1 in §4.1), F7.1-Guard gilt identisch
- `docs/regelwerke/Regelwerk_Lieferantenartikel.md` §10 — ALL-MAXIMAL-Prinzip (sinngemäß übertragen)

**Ist-Implementierung:** `src-tauri/src/commands.rs`
- `recompute_recipe_zusatzstoffe` — Z. 8080–8238 (Doku-Kommentar ab 8080, Funktion ab 8087)
- Aufruf-Kontext: `recompute_recipe_aggregations` Z. 6946 (direkt nach Allergenen); Propagation/Topo wie GL-01

## 2. Eingaben / Ausgaben / Invarianten

**Eingaben (Ziel-Schema lt. `02_DATENMODELL.md`):**

| Quelle | Felder | Bedeutung |
|---|---|---|
| `foodalchemist_recipe_ingredients` | `gp_v2_id` / `referenced_recipe_id`, `match_method`, `match_confidence`, `is_optional` | Zutatenliste (Filter identisch GL-01, inkl. KEINER `qs`-Ausnahme) |
| `foodalchemist_item_declarations` | 18 INTEGER-Spalten (siehe Mapping 4.2), Werte 0/1/3/NULL | LA-Ebene (Necta) |
| `foodalchemist_supplier_item_structures` | `gp_v2_id`, `supplier_item_id` | LA↔GP-Brücke (Ist: ohne `is_discontinued`-Filter, wie GL-01) |
| `foodalchemist_recipes` (Sub) | 18× `zusatz_*` (persistiert) | Sub-Rezept-Beitrag |
| `foodalchemist_recipes` (selbst) | `n_zutaten_ungemappt` | F7.1-Guard (muss vorher frisch sein) |

**Ausgaben (auf `foodalchemist_recipes`):** 18× `zusatz_*` INTEGER (nullable) + `zusatz_aggregiert_am` (Timestamp, bei jedem Lauf — auch im Guard-Fall).

**Invarianten:**
1. Reine Ableitung — kein direkter Schreibpfad über UI/API.
2. `n_zutaten_ungemappt > 0` ⇒ alle 18 Spalten NULL (= unbekannt). NULL ist hier das Pendant zu `'unbekannt'` bei GL-01 (INTEGER-Spalten kennen keinen String).
3. Es gibt KEIN `zusatz_konfidenz`-Feld — `allergene_konfidenz` (GL-01 §4.4) gilt als Proxy, da Filter und Guard identisch sind.
4. Kein Override-Layer auf GP-Ebene (anders als Allergene): die GP-Auflösung ist immer `MAX` über die LA-declarations.
5. Sub-Rezept-Werte müssen vor dem Eltern-Lauf frisch sein (Topo-Sort / Propagation, GL-01 §3).

## 3. Algorithmus (Pseudocode, sprachneutral)

```
funktion recompute_recipe_zusatzstoffe(recipe_id):
    wenn recipes.n_zutaten_ungemappt > 0:                      # F7.1-Guard
        setze alle 18 zusatz_* = NULL
        setze zusatz_aggregiert_am = jetzt()
        return

    zutaten = SELECT aus recipe_ingredients WHERE recipe_id = :recipe_id
              UND match_method != 'ignored'
              UND is_optional = 0
              UND ( (gp_v2_id IS NOT NULL
                     UND (match_method != 'gemini_proposed' ODER match_confidence >= 0.85))
                    ODER referenced_recipe_id IS NOT NULL )

    für jede zutat, für jedes der 18 flags F:
        gp_wert  = wenn gp_v2_id gesetzt:
                       MAX(item_declarations.F) über alle LAs des GP    # 0/1/3; NULL wenn keine Daten
                   sonst NULL
        sub_wert = wenn referenced_recipe_id gesetzt:
                       sub_recipe.zusatz_F                              # persistiert, 0/1/3/NULL
                   sonst NULL
        beitrag_F = COALESCE(gp_wert, sub_wert)                # GP-Pfad gewinnt vor Sub-Pfad

    für jedes flag F:
        recipes.zusatz_F = MAX(beitrag_F aller zutaten)        # SQL-MAX ignoriert NULL
                                                               # alle NULL → NULL (unbekannt)
    zusatz_aggregiert_am = jetzt()
```

Hinweis Derivat-GPs: wie bei GL-01 existiert KEINE Mutter-Auflösung — Derivat-Zutaten (`requires_la = 0`, keine LAs) liefern NULL-Beitrag. Da das GP-Regelwerk die LIVE-Vererbung nur für Allergene (§16) formuliert, ist das hier keine Regelwerks-Abweichung, aber dieselbe fachliche Lücke; im Ziel zusammen mit GL-01 ⚠A2 lösen (Mutter-Auflösung für beide Aggregationen).

## 4. Entscheidungstabellen (normativ)

### 4.1 Wertedomäne (⚠ A1 — Ist-Domäne weicht von Regelwerk-/Schema-Kommentar ab)

Regelwerk Basisrezepte §7 und der Schema-Kommentar sagen „0/1/NULL" — die tatsächliche Necta-Domäne in `declarations` und damit in `zusatz_*` ist **{0, 1, 3, NULL}** (DB-verifiziert: `with_dye` = 0: 63.141 / 1: 45.829 / 3: 3.635):

| Quell-Wert | Bedeutung | Rang im MAX |
|---|---|---|
| 3 | **ja** — Zusatzstoff enthalten / Hinweis pflichtig | 3 (höchster) |
| 1 | **nein** — explizit deklariert „ohne" | 1 |
| 0 | keine Angabe (Necta-Platzhalter) | 0 |
| NULL | keine Daten (LA ohne declarations-Zeile / ungemappt-Reset) | kein Beitrag (von MAX ignoriert) |

**Ziel-Normalisierung (Empfehlung für den Port, vgl. `02_DATENMODELL.md` „CHECKs → Enums"):** beim Seed-ETL auf echten Tri-State mappen — `3 → true`, `1 → false`, `0 → NULL`, `NULL → NULL` — und die Merge-Logik auf `ja > nein > unbekannt` umstellen. Die Golden-Tests in §5 sind in Quell-Werten formuliert; bei Normalisierung gelten sie mit übersetzten Werten (3→true usw.) unverändert.

### 4.2 Spalten-Mapping `item_declarations` → `recipes` (alle 18, 1:1 mit `zusatz_`-Prefix)

| # | Quelle (`declarations`) | Ziel (`recipes`) | Kennzeichnung (DE) |
|---|---|---|---|
| 1 | `with_dye` | `zusatz_with_dye` | mit Farbstoff |
| 2 | `with_preservative` | `zusatz_with_preservative` | mit Konservierungsstoff |
| 3 | `with_antioxidant` | `zusatz_with_antioxidant` | mit Antioxidationsmittel |
| 4 | `with_flavour_enhancer` | `zusatz_with_flavour_enhancer` | mit Geschmacksverstärker |
| 5 | `sulphurated` | `zusatz_sulphurated` | geschwefelt |
| 6 | `blackened` | `zusatz_blackened` | geschwärzt |
| 7 | `waxed` | `zusatz_waxed` | gewachst |
| 8 | `with_phosphate` | `zusatz_with_phosphate` | mit Phosphat |
| 9 | `with_sweetener` | `zusatz_with_sweetener` | mit Süßungsmittel(n) |
| 10 | `contains_phenylalanine` | `zusatz_contains_phenylalanine` | enthält eine Phenylalaninquelle |
| 11 | `excessive_consumption_laxative` | `zusatz_excessive_consumption_laxative` | kann bei übermäßigem Verzehr abführend wirken |
| 12 | `packaged_modified_atmosphere` | `zusatz_packaged_modified_atmosphere` | unter Schutzatmosphäre verpackt |
| 13 | `caffeinated` | `zusatz_caffeinated` | koffeinhaltig |
| 14 | `contains_milk_protein` | `zusatz_contains_milk_protein` | enthält Milcheiweiß |
| 15 | `contains_quinine` | `zusatz_contains_quinine` | chininhaltig |
| 16 | `taurine_containing` | `zusatz_taurine_containing` | taurinhaltig |
| 17 | `can_impair_attention_children` | `zusatz_can_impair_attention_children` | kann Aktivität/Aufmerksamkeit bei Kindern beeinträchtigen |
| 18 | `with_type_sugar_sweetener` | `zusatz_with_type_sugar_sweetener` | mit Zuckerart(en) und Süßungsmittel(n) |

### 4.3 Merge-Matrix (vollständig; symmetrisch, MAX gewinnt, NULL = kein Beitrag)

| Merge von ↓ + → | 3 (ja) | 1 (nein) | 0 (k. A.) | NULL |
|---|---|---|---|---|
| **3 (ja)** | 3 | 3 | 3 | 3 |
| **1 (nein)** | 3 | 1 | 1 | 1 |
| **0 (k. A.)** | 3 | 1 | 0 | 0 |
| **NULL** | 3 | 1 | 0 | NULL |

Gilt identisch für LA+LA → GP und Zutat+Zutat → Rezept. Leere Menge ⇒ NULL.

### 4.4 Auflösung pro Zutat (Prioritätskette)

| Prio | Bedingung | Quelle |
|---|---|---|
| 1 | GP-Zutat mit ≥1 LA mit declarations-Zeile | `MAX(declarations.F)` über alle LAs des GP — **GP-Pfad gewinnt vor Sub-Pfad** (theoretischer Fall, da eine Zutat nie beides ist — Validierung Z. 8241) |
| 2 | Sub-Rezept-Zutat | persistierter `recipes.zusatz_F` des Sub |
| 3 | GP ohne declarations-Daten (auch Derivate) | NULL — kein Beitrag |
| — | ignored / optional / gemini < 0.85 | ausgefiltert; gemini < 0.85 triggert zusätzlich den F7.1-Guard |

### 4.5 Guard- und Leerfall-Verhalten

| Situation | Ergebnis (alle 18) | Weg |
|---|---|---|
| `n_zutaten_ungemappt > 0` | NULL | F7.1-Reset (Early-Return) |
| 0 relevante Zutaten (Stub) | NULL | MAX über leere Menge |
| alle Zutaten-Beiträge NULL | NULL | MAX ignoriert NULL |

## 5. Golden-Testfälle (verbindliche Wahrheit; Quelle: `wawi_1494.sqlite`, Stand 2026-06-10)

**GT-01 — ALL-MAXIMAL mit NULL-Zutaten.** Input: Rezept 195 „Dip: Sambal Ketjang", 7 nicht-optionale Zutaten; GP-Auflösung `with_preservative`: Chilischoten 1, Erdnüsse NULL (keine declarations), Shrimp-Paste NULL, **Ketjap Manis 3**, Zitronensaft 1, Erdnussöl 1, Salz 1. Expected: `zusatz_with_preservative = MAX(1,3,1,1,1) = 3`; analog `zusatz_with_dye = 3` (ebenfalls von Ketjap Manis). NULL-Zutaten kippen das Ergebnis NICHT auf unbekannt. (DB-verifiziert.)

**GT-02 — Gemischtes Profil.** Input: Rezept 175 „Frischkäse-Dip: Kürbis-Apfel" (`n_zutaten_ungemappt = 0`). Expected: `zusatz_with_preservative = 3`, `zusatz_with_dye = 1`, `zusatz_sulphurated = 1` — pro Spalte unabhängiges MAX, ein „ja" bei einem Flag färbt andere Flags nicht ein. (DB-verifiziert.)

**GT-03 — F7.1-Guard.** Input: Rezept 1115 „SORBET SÜSS – BLITZREZEPT" mit `n_zutaten_ungemappt = 1` (gemini-Zutat conf 0.8 < 0.85). Expected: ALLE 18 `zusatz_*` = NULL, `zusatz_aggregiert_am` gesetzt — obwohl 3 von 4 Zutaten sauber gemappt sind. Kontrast: `nutri_*` desselben Rezepts ist berechnet (GL-08 GT-04 — Nährwerte kennen den Guard nicht). (DB-verifiziert.)

**GT-04 — Leeres Rezept (zweiter NULL-Pfad).** Input: Rezept 727 (0 Zutaten, `n_zutaten_ungemappt = 0` ⇒ Guard feuert NICHT). Expected: alle 18 NULL über den Leerer-MAX-Pfad. Beide NULL-Wege (Guard vs. leer) müssen im Ziel dasselbe Endergebnis liefern. (DB-verifiziert.)

**GT-05 — Sub-Rezept-Vererbung.** Input: Rezept 313 „Gel: Gestocktes Hühnerfett" referenziert Sub 638 „Heller Fond: Geflügel" mit `zusatz_with_preservative = 3`; die GP-Zutaten lösen auf zu Agar = 1, Blattgelatine = 1, Hühnerfett (Derivat) = NULL. Expected: `zusatz_with_preservative = MAX(1, 1, NULL, 3) = 3` — ausschließlich vom Sub geerbt. Kettenfähig: ändert sich ein LA tief unter 638, propagiert der Wert über 638 nach 313 (Kinder-vor-Eltern-Reihenfolge zwingend). (DB-verifiziert.)

**GT-06 — Merge-Matrix-Randfälle (synthetisch).** Input: Rezept mit 2 gemappten Zutaten. Expected gemäß 4.3: (a) Beiträge {1, 0} ⇒ 1 („explizit nein" schlägt „keine Angabe"); (b) {0, NULL} ⇒ 0; (c) {NULL, NULL} ⇒ NULL; (d) {3, 1} ⇒ 3. Bei Ziel-Normalisierung (4.1): (a) {false, NULL} ⇒ false; (b) {NULL, NULL} ⇒ NULL; (d) {true, false} ⇒ true.

## 6. Offene Weichen + Verbesserungen

**Dokumentierte Abweichungen:**
- **⚠ A1 — Wertedomäne (siehe 4.1):** Regelwerk §7 / Schema-Kommentar sagen „0/1/NULL", Ist-Daten sind {0, 1, 3, NULL} mit 3 = ja. Das Regelwerk beschreibt hier die Intention (Tri-State), nicht die Necta-Kodierung. Im Ziel per Seed-ETL normalisieren (Empfehlung 4.1) und das Regelwerk-Wording beim nächsten Versionsschritt präzisieren — die Merge-SEMANTIK (ja > nein > unbekannt) ist in beiden Welten identisch.
- **Derivat-Lücke (gemeinsam mit GL-01 ⚠A2):** keine Mutter-Auflösung für Derivat-GPs ⇒ NULL-Beitrag. Im Ziel zusammen mit der Allergen-LIVE-Vererbung implementieren (eine Auflösungs-Schicht für beide Aggregationen).

**Verbesserungen / Weichen:**
- **Kein Konfidenz-Feld:** anders als Allergene/Nährwerte gibt es keine `zusatz_konfidenz`. Da Filter + Guard identisch zu GL-01 sind, ist `allergene_konfidenz` ein korrekter Proxy — im Ziel entweder explizit so dokumentieren (View/Accessor) oder ein eigenes Feld spendieren (billig, klarer für API-Konsumenten).
- **Discontinued-Filter:** wie GL-01 aggregiert der Ist-Code über ALLE LAs (auch ausgelistete). Konservativ korrekt für Kennzeichnungen — im Ziel explizit beibehalten und mit GL-01 gemeinsam begründen.
- **V-10 (Review-Queue):** GPs, deren LAs widersprüchliche declarations liefern (z. B. {1, 3}), sind heute unsichtbar — das MAX schluckt den Konflikt. Verbesserung: Konflikt-Erkennung analog LA-Regelwerk §10 auch für Zusatzstoffe in die Review-Queue geben.
- **⚠D1:** `item_declarations` sind globale Stammdaten (team_id nullable); `zusatz_*` liegen team-eigen auf `foodalchemist_recipes`. Transaktionssicherheit wie GL-01 (V-07).
