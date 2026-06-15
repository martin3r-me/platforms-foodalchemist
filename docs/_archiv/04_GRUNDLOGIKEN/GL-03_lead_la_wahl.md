---
typ: Grundlogik-Spec
gl_id: GL-03
stand: 2026-06-10
status: ausgearbeitet
---

# GL-03 — Lead-LA-Wahl pro Grundprodukt

> **Normative Quellen:** Regelwerk_Lieferantenartikel §8 (Lead-LA-Heuristik: Vollständigkeit > Aktualität > Preis/Einheit > alphabetisch) + Stamm-Lieferant-Erweiterung (Skripte 212/213, 2026-06 — nach Regelwerk-Freeze beschlossen, verbindlich)
> **Implementierungs-Quelle (Ist):** `commands.rs` — `pick_lead_la` (3164–3192), `set_gp_lead_la` (3124), `apply_lead_la` (3196), `unlink_la_from_gp` (3211), `recompute_all_lead_las` (3271). Python-Spiegel: `213_recompute_lead_las.py` (identisches SQL).

## 1. Zweck & fachliche Quelle

Pro Grundprodukt (GP) wird genau **ein** Lieferantenartikel (LA) als **Lead-LA** ausgezeichnet. Der Lead-LA liefert den Default-EK-Preis für die Rezept-Kalkulation (GL-02, Preisquelle 1 in deren T3), den Default-Lieferanten in der GP-Anzeige und das Default-Allergen-Profil (GL-01). GPs ohne LA („Phantome", z. B. Derivate mit `requires_la=0`) haben keinen Lead-LA.

Fachliche Basis ist Regelwerk_Lieferantenartikel §8. Das dort referenzierte Feld `wawi_gp_la.bevorzugt` existiert nicht mehr (Tabelle beim LA-First-Reset 2026-05-19 gedroppt); Ist-Träger ist `wawi_gp_v2.lead_la_supplier_item_id` → Ziel: `foodalchemist_gps.lead_la_supplier_item_id`. Die **Stamm-Lieferant-Präferenz** (Lieferant×Warengruppen-Matrix) wurde 2026-06-01/02 als Vorstufe VOR die §8-Kaskade gesetzt und ist Soll-Verhalten.

## 2. Eingaben / Ausgaben / Invarianten

**Feld-Mapping Quelle → Ziel:**

| Quelle | Ziel | Rolle |
|---|---|---|
| `wawi_gp_v2.lead_la_supplier_item_id`, `.warengruppe` | `foodalchemist_gps.*` | Ergebnis-Feld + WG für Stamm-Match |
| `wawi_la_structured.gp_v2_id` | `foodalchemist_supplier_item_structures` | Kandidaten-Menge (LA↔GP-Mapping, GL-05) |
| `supplier_items.supplier_id`, `.qty`, `.unit_id`, `.is_discontinued` | `foodalchemist_supplier_items` | Aktivität + Preis-Normalisierung |
| `suppliers.name` | `foodalchemist_suppliers` | letzter Tiebreaker |
| `stamm_lieferant_wg (supplier_id, warengruppe)` | `foodalchemist_core_supplier_wgs` | Stamm-Präferenz |
| `v_active_prices` (Logik) | Eloquent-Scope „aktiver Preis" | siehe GL-11 |

**Eingabe:** `gp_id`. **Ausgabe:** `supplier_item_id` des Gewinners oder NULL (kein LA verknüpft).

**Invarianten:**

- **I1 — Determinismus:** Gleiche Daten → gleicher Gewinner. Die Kaskade endet mit einem totalen Ordnungskriterium (Lieferantenname); bei identischem Namen entscheidet im Ziel zusätzlich `supplier_item_id ASC` (im Ist undefiniert — Ergänzung für Reproduzierbarkeit).
- **I2 — Kandidaten-Menge:** Nur LAs, die dem GP zugeordnet sind (`gp_id`-FK in der Struktur-Tabelle). Validierung beim manuellen Setzen: LA muss zum GP gehören, sonst Fehler (commands.rs:3130–3145).
- **I3 — Weiche Kriterien:** Alle Kaskaden-Stufen sind **Sortier-Kriterien, keine Filter** — auch ein discontinued LA ohne Preis wird Lead, wenn es keinen besseren gibt. (⚠ A-1: Regelwerk formuliert harte Filter.)
- **I4 — Re-Trigger:** Neu wählen bei (a) LA↔GP-Verknüpfung/Entknüpfung — beim Entknüpfen des aktuellen Leads sofortige Neuwahl (commands.rs:3253–3261), (b) Bulk-Lauf nach Preis-/Stammdaten-Import, (c) Änderung der Stamm-Matrix. Nach jedem Bulk-Lauf: Rezept-Recompute (GL-02), damit Kalkulationen den neuen Lead ziehen.
- **I5 — NULL-Preis-Ordnung (Port-kritisch!):** LAs ohne berechenbaren Preis/Einheit sortieren **hinter** dem teuersten bepreisten LA (NULLS LAST). ⚠ A-2: SQLite-Ist macht das Gegenteil.

## 3. Algorithmus (Pseudocode)

```
pick_lead_la(gp_id):
    kandidaten = alle LAs mit struktur.gp_id == gp_id
    wenn leer: return NULL                                  -- Phantom-GP

    sortiere kandidaten aufsteigend nach Tupel:
      ( stamm_rang,        -- 0 wenn la.supplier_id in stamm_matrix für gp.warengruppe, sonst 1
        discontinued,      -- 0 wenn COALESCE(is_discontinued,0)=0 (aktiv), sonst 1
        kein_aktiv_preis,  -- 0 wenn ≥1 Preiszeile mit kategorie ∈ {standard_ek, aktion} (GL-11), sonst 1
        preis_je_einheit,  -- MIN(aktiver preis) / NULLIF(qty,0); NULL → ans ENDE (NULLS LAST, I5/A-2)
        lieferant_name,    -- alphabetisch
        supplier_item_id ) -- Ziel-Ergänzung für Determinismus (I1)
    return kandidaten[0].supplier_item_id

apply_lead_la(gp_id):            gp.lead_la_supplier_item_id = pick_lead_la(gp_id)
recompute_all_lead_las():        für jeden GP mit ≥1 LA: apply_lead_la(gp)   -- danach GL-02-Bulk!
unlink_la_from_gp(la_id):        Mapping lösen; n_las_total aktualisieren;
                                 war la_id der Lead → apply_lead_la(gp)
set_gp_lead_la(gp_id, la_id?):   manueller Override; la_id muss zum GP gehören (I2); NULL erlaubt
```

`preis_je_einheit` = Gebindepreis ÷ `qty` — also €/kg, €/l bzw. €/Stk je nach `lookup_unit.code` des LA (Normalisierung und „aktiver Preis" → **GL-11**). Einheiten werden hierbei NICHT auf eine gemeinsame Basis gebracht (⚠ A-3).

## 4. Entscheidungstabellen (normativ)

**T1 — Tiebreaker-Kaskade** (Stufe n+1 nur bei Gleichstand in Stufe n):

| Stufe | Kriterium | Gewinner | Quelle |
|---|---|---|---|
| 0 | **Stamm-Lieferant** für die Warengruppe des GP (Matrix Lieferant×WG) | Stamm vor Nicht-Stamm | Erweiterung 2026-06 (Skript 212/213) |
| 1 | **Aktivität** `is_discontinued` | aktiv vor ausgelistet | §8 Nr. 1 (Ist: weich, A-1) |
| 2 | **Hat aktiven Preis** (Kategorie `standard_ek` oder `aktion`, GL-11) | bepreist vor unbepreist | §8 Nr. 2 (teilweise, A-1) |
| 3 | **Preis pro Einheit** `MIN(aktiver Preis)/qty` | günstigster; NULL ans Ende (I5) | §8 Nr. 4 |
| 4 | **Lieferantenname** alphabetisch | A vor Z | §8 Nr. 5 |
| 5 | `supplier_item_id` aufsteigend | kleinste ID | Ziel-Ergänzung (I1) |

**T2 — Regelwerk §8 vs. Ist** (Abweichungs-Matrix, Details in §6):

| §8-Vorgabe | Ist-Verhalten | Spec-Soll |
|---|---|---|
| Aktivitäts-**Filter** `ausgelistet=0` | Sortier-Kriterium (Stufe 1) | Sortier-Kriterium beibehalten (robuster: GP behält Lead statt NULL) — Abweichung dokumentiert |
| Vollständigkeit: EK-Preis **und alle 14 Allergene gepflegt** | nur Preis-Existenz (Stufe 2), Allergene ungeprüft | Allergen-Vollständigkeit als Stufe 2b ergänzen (Regelwerk gewinnt) |
| Aktualität: Preis-Update ≤ 365 Tage | nicht implementiert | als Stufe 2c ergänzen, Quelle `prices.change_date` |
| `bevorzugt_lock=1` (manueller Lock überlebt Re-Runs) | nicht implementiert — Bulk-Lauf überschreibt manuelle Wahl | `lead_la_locked_at`/`locked_by` ergänzen; Heuristik überspringt gelockte GPs |
| Audit: GPs ohne Lead → `needs_lead_la_review` | nicht implementiert | Review-Queue-Flag im Ziel (vgl. V-10) |

**T3 — Re-Trigger-Matrix:**

| Ereignis | Aktion |
|---|---|
| LA an GP verknüpft (GL-05 Accept) | `apply_lead_la(gp)` |
| Lead-LA entknüpft | sofortige Neuwahl (I4) |
| Nicht-Lead-LA entknüpft | nur `n_las_total` aktualisieren |
| Preis-Import / Stamm-Matrix geändert | Bulk `recompute_all_lead_las()` + danach GL-02-Bulk |
| Manueller Override (`set_gp_lead_la`) | direkt setzen, Validierung I2; mit Lock (T2) gegen Bulk schützen |

## 5. Golden-Testfälle (verbindliche Wahrheit)

**GT-1 — Realfall GP 6723 „Limettensaft: konserviert" (WG 10), 14 LA-Kandidaten** (Quell-DB Stand 2026-06-10; Spalten = Sortier-Tupel):

| LA | Lieferant | stamm | disc | aktiver Preis | €/Einheit | Bemerkung |
|---|---|---|---|---|---|---|
| 31141191 | Delta Fleisch | 0 | 0 | ja (47,50 €, `aktion`) | **NULL** (qty fehlt) | **Ist-Lead (SQLite NULLS FIRST)** |
| 29344887 | Chefs Culinar West | 0 | 0 | ja (2,69 €) | 3.5867 €/l (0,75 l) | **Soll-Lead (NULLS LAST)** |
| 35071775 | Hanos Venlo | 0 | 0 | ja | 6.59 €/l | |
| 35658678 | BOS Food | 0 | 0 | ja | 9.20 €/l | |
| 34392290 | BOS Food | 0 | 0 | ja (22,50 €) | 9.375 €/l (2,4 l) | |
| 34391408 | BOS Food | 0 | 0 | ja | 12.325 €/l | |
| 23614830 | Chefs Culinar West | 0 | 0 | nein (`eingestellt`) | — | Stufe 2 verliert |
| 31141183 / 31141255 | Delta Fleisch | 0 | 0 | nein | — | |
| 35058231 / 35058244 / 35071869 | Hanos Venlo | 0 | 0 | nein | — | |
| 31212011 | EPOS Bio Partner | **1** | 0 | ja | 1.72 €/Stk | kein Stamm für WG 10 → Stufe 0 verliert trotz Tiefstpreis |
| 29388798 | Handelshof MG | **1** | 0 | ja | 7.895 €/l | |

Expected (Spec-Soll, NULLS LAST): **29344887**. Ist (SQLite): 31141191 — siehe ⚠ A-2. Zweite Lehre: EPOS (1,72 €/Stk) verliert allein durch Stufe 0, obwohl billigste Einheit — Stamm schlägt Preis. Dritte Lehre (A-3): 1,72 €/**Stk** und 3,59 €/**l** wären gar nicht vergleichbar.

**GT-2 — Stamm schlägt Preis (real):** GP 2151 „Brotkonfekt Doppelweck: TK" (WG 09): Lead = LA 1586231 (Edna Backwaren, Stamm für WG 09), obwohl Nicht-Stamm-Kandidaten existieren. Erwartung: jeder LA eines WG-Stamm-Lieferanten gewinnt gegen jeden Nicht-Stamm-LA, unabhängig von Preis und Aktivität.

**GT-3 — Phantom-GP (synthetisch):** GP ohne verknüpfte LAs (z. B. Derivat, `requires_la=0`). Expected: `pick_lead_la` → NULL; `lead_la_supplier_item_id` = NULL; kein Fehler.

**GT-4 — Nur discontinued Kandidaten (synthetisch):** GP mit genau einem LA, `is_discontinued=1`, ohne aktiven Preis. Expected: dieser LA wird Lead (weiche Kriterien, I3) — GP fällt nicht auf NULL zurück. Ziel zusätzlich: Flag `needs_lead_la_review` (T2).

**GT-5 — Lead-Entknüpfung (synthetisch):** GP mit LAs X (Lead) und Y (aktiv, bepreist). Entknüpfe X. Expected: `gp.lead_la_supplier_item_id = Y` ohne weiteren Aufruf; `n_las_total` dekrementiert. Entknüpfe danach Y → Lead NULL.

**GT-6 — Manueller Override mit Validierung (synthetisch):** `set_gp_lead_la(gp_A, la_von_gp_B)` → Fehler „LA nicht mit GP verknüpft", kein Schreibvorgang. `set_gp_lead_la(gp_A, NULL)` → erlaubt (Lead bewusst leeren).

**GT-7 — Gleichstand bis Stufe 4 (synthetisch):** Zwei aktive, bepreiste LAs desselben Preises je Einheit von „Anton GmbH" und „Berta GmbH". Expected: Anton (alphabetisch). Bei identischem Namen: kleinere `supplier_item_id` (Stufe 5).

## 6. Offene Weichen + Verbesserungen

**⚠ Ist-Implementierung weicht ab:**

- **A-1 Filter vs. Sortierung:** Regelwerk §8 definiert Aktivität/Vollständigkeit/Aktualität als harte **Filter** (Kandidat fliegt raus), Ist sortiert nur. Spec-Soll: Sortier-Ansatz beibehalten (sonst verlieren GPs ihren Lead komplett), aber Audit-Flag `needs_lead_la_review`, wenn der Gewinner einen §8-Filter verletzt. Abweichung vom Regelwerk-Wortlaut hiermit explizit dokumentiert + begründet.
- **A-2 NULLS FIRST (SQLite) — Port-Falle:** In SQLite sortiert `NULL` bei `ASC` **zuerst** → LA 31141191 (kein berechenbarer €/Einheit, weil `qty` NULL) gewinnt real gegen 3,59 €/l (GT-1). Das widerspricht §8-Intention („niedrigster normierter Preis"). PostgreSQL sortiert per Default NULLS LAST — der Port korrigiert das Verhalten **automatisch**; explizit `NULLS LAST` deklarieren und beim Seed dokumentieren, dass sich dadurch Leads (und damit EK-Werte, GL-02) gegenüber dem Ist-Bestand ändern. Migration: Diff-Report Ist-Lead vs. Soll-Lead vor Übernahme.
- **A-3 Einheiten-Mix im Preisvergleich:** `preis/qty` vergleicht €/kg, €/l und €/Stk unnormalisiert miteinander (GT-1: 1,72 €/Stk „günstiger" als 3,59 €/l). §8 verlangt „kg/l/Stk-normiert" in der GP-Kalkulationseinheit. Spec-Soll: Normalisierung über die Brücken aus GL-11 (Stk→g via `stk_default_g`); wo unmöglich → NULL (ans Ende).
- **A-4 Fehlende §8-Stufen:** Allergen-Vollständigkeit, 365-Tage-Aktualität, `bevorzugt_lock`, `needs_lead_la_review` sind im Ist nicht implementiert (Matrix T2). Regelwerk gewinnt: im Ziel ergänzen.
- **A-5 Bulk ohne Transaktion + ohne Lock-Respekt:** `recompute_all_lead_las` schreibt GP-weise ohne Klammer und überschreibt manuelle Overrides (kein Lock-Konzept) → V-07 + T2-Lock.

**Offene Weichen:** **⚠D1** (Preis-Sichtbarkeit pro Team, `08_ENTSCHEIDUNGEN.md`): Wenn Preise/Konditionen team-gefiltert werden, ist Stufe 2/3 team-abhängig → entweder team-spezifischer Lead-LA (Spalte wandert in eine Team-Pivot-Tabelle) oder globaler Lead auf Basis des globalen Preisstands. Arbeits-Annahme bis Entscheid: **ein globaler Lead-LA** (wie Ist); Kunden-/Team-Präferenzen überschreiben nur in der Kalkulations-Sicht (vgl. §8-Hinweis Kunden-Ebene).

**Verbesserungen:** **V-07** (Bulk-Neuwahl + nachgelagerter GL-02-Recompute als eine transaktionale/queue-basierte Einheit — heute zwei manuelle Schritte, vergisst man Schritt 2, rechnen Rezepte mit altem Lead) · **V-22** (Seed-Gate: GPs, deren Lead einen §8-Filter verletzt oder `qty IS NULL` hat, beim Import flaggen).

**V-27 — Strategie + Substitutionskette (User-Anforderung 2026-06-11, ändert die SOLL-Kaskade):**
- Die Kaskade (T1) bekommt einen **Strategie-Parameter**: `stamm_lieferant` (Default = Ist-Verhalten, Stufe 1 aktiv) vs. `guenstigster_preis` (Stufe 1 wird übersprungen, Preis entscheidet zuerst). Einstellung team-weit, optional je Warengruppe.
- `pick_lead_la` liefert nicht mehr nur den Sieger, sondern die **vollständige Rangliste** (Kette) — Rang 1 = Lead, Rest = Ausweich-LAs. Persistiert, manuell umsortierbar.
- **Effektiver Lead** = erster Rang, der weder team-gesperrt noch entknüpft ist; ein **Pin** (= `bevorzugt_lock`, schließt A-4-Lücke) fixiert einen Rang als Lead und überlebt Bulk-Neuwahl.
- Sperren/Pins leben in einem **team-scoped Overlay** (`gp_la_preferences`) über der globalen Kette — damit ist auch die ⚠D1-Frage oben beantwortet: EIN globaler Default-Lead, Team-Abweichungen als Overlay. Wechsel des effektiven Leads triggert GL-02-Recompute des betroffenen Teams.
