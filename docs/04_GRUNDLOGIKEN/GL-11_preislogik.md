---
typ: Grundlogik-Spec
gl_id: GL-11
stand: 2026-06-10
status: ausgearbeitet
---

# GL-11 — Preislogik (aktiver Preis, Einheiten-Normalisierung, Historie)

> **Normative Quellen:** View-Definition `v_active_prices` (wawi_1494.sqlite) — wird laut `02_DATENMODELL.md` Nr. 3 als Eloquent-Scope/abgeleitetes Attribut portiert · Vault-Konvention „Preise immer netto"
> **Implementierungs-Quelle (Ist):** `commands.rs` — Preis-Subqueries der Kosten-Aggregation (7224–7255), aktive-Preis-Prüfung in `pick_lead_la` (3177–3185), Anzeige-Lookups (465–499, 3653 ff., 8525 ff.).

## 1. Zweck & fachliche Quelle

Alle EK-Kalkulationen (GL-02), die Lead-LA-Wahl (GL-03) und jede Preis-Anzeige beziehen ihre Werte aus der Necta-Stammtabelle `prices` (→ `foodalchemist_prices`, read-only Seed-Daten, 221.591 Zeilen). Diese GL definiert drei Dinge: (1) welcher Preis eines LA als **aktiv** gilt (Kategorisierungs-Logik der View `v_active_prices`), (2) wie ein Gebindepreis auf eine **Kalkulationseinheit** normalisiert wird (€/g, €/Stk inkl. Brücken), (3) wie **Historie** und Netto-Konvention behandelt werden.

Die View selbst ist bewusst dünn: Sie filtert **nicht** und aggregiert **nicht** — sie annotiert jede Preiszeile 1:1 mit einer abgeleiteten `preis_kategorie`. „Aktiv" entsteht erst beim Konsumenten durch den Filter `preis_kategorie IN ('standard_ek','aktion')`. Im Ziel: PHP-Enum `PriceCategory` + berechnetes Attribut am `Price`-Model + Scope `Price::active()`.

## 2. Eingaben / Ausgaben / Invarianten

**Feld-Mapping Quelle → Ziel** (`prices` → `foodalchemist_prices`):

| Quell-Spalte | Bedeutung | Port-Hinweis |
|---|---|---|
| `supplier_item_id` | FK auf LA | bleibt |
| `status` (TEXT `'0'`/`'2'`) | Necta-Listungsstatus: 0 = gelistet, 2 = Aktions-/Auslauf-Satz | als Enum casten; Quell-Werte sind Strings! |
| `price` | **Netto-Gebindepreis** in € (ganzes Gebinde, nicht je Einheit) | `decimal`; negativ = Service-Zuschlag |
| `price_partial`, `promotional_price`, `promotional_price_valid_until` | Teil-/Aktionspreisfelder | mitnehmen; im Ist **ungenutzt** für Kalkulation (A-3) |
| `valid_to`, `change_date`, `creation_date` | Gültigkeits-/Änderungsdaten | `timestampTz`; Basis der Historie (§3.3) |
| `tenant_id` | Necta-Artefakt, alle NULL | fällt weg (nicht mit `team_id` verwechseln, ⚠D1) |

Kontext aus `supplier_items` (→ `foodalchemist_supplier_items`): `qty` = Gebinde-Inhalt, `unit_id` → `lookup_unit.code` ∈ {`kg`, `l`, `Stk`} (reale Verteilung: 97.602 / 27.358 / 94.569), `is_discontinued`.

**Ausgaben:** `preis_kategorie` pro Preiszeile; normalisierter Preis (€/g bzw. €/Stk); „aktiver Preis eines LA" für GL-02/GL-03.

**Invarianten:**

- **I1 — Netto:** Alle Preise sind Netto-EK in EUR. Keine MwSt-Logik in dieser GL (`vat` liegt am LA, reine Anzeige).
- **I2 — Ableitung statt Pflege:** `preis_kategorie` wird nie gespeichert, immer aus (`price`, `status`) abgeleitet (T1). Deterministische, totale Funktion — jede Zeile bekommt genau eine Kategorie.
- **I3 — Aktiv-Definition:** Ein LA „hat einen aktiven Preis" ⇔ mind. eine Preiszeile mit Kategorie `standard_ek` oder `aktion`. Bei mehreren aktiven Zeilen gilt für Vergleiche (GL-03) `MIN(price)`, für die Kalkulation (GL-02) die **neueste** Zeile (§3.3, ⚠ A-2).
- **I4 — Normalisierung nur mit `qty` > 0:** Ohne gültige Gebindemenge gibt es keinen Preis pro Einheit (NULL, niemals Division durch 0/NULL). Folge-Verhalten in GL-02 (unpriced) und GL-03 (NULLS LAST).
- **I5 — Service-Zuschläge sind keine Warenpreise:** `price < 0` (real nur 2 Zeilen, z. B. „Zuschlag für Wertstofftransport je Rolli", −9,00 €) darf nie Kalkulationsbasis werden.
- **I6 — Read-only:** Preiszeilen werden vom Modul nie editiert — nur per Import angefügt (§3.3).

## 3. Algorithmus (Pseudocode)

### 3.1 Kategorisierung (= portierte View-Logik, 1:1 normativ)

```
preis_kategorie(zeile):
    wenn zeile.price < 0:                                return 'service_charge'
    wenn zeile.status == '0' und price IS NOT NULL:      return 'standard_ek'
    wenn zeile.status == '2' und price IS NOT NULL:      return 'aktion'
    wenn zeile.status == '2' und price IS NULL:          return 'eingestellt'
    wenn zeile.status == '0' und price IS NULL:          return 'datenluecke'
    sonst:                                               return 'unbekannt'
```

Laravel-Ziel: `PriceCategory`-Enum als Accessor; `scopeActive($q)` = `whereIn(kategorie, [standard_ek, aktion])` — ausformuliert als SQL-Bedingung `price >= 0 AND price IS NOT NULL AND status IN ('0','2')`, damit der Scope index-fähig bleibt.

### 3.2 Preis-pro-Einheit-Normalisierung (Gebinde → Kalkulationseinheit)

```
preis_pro_basis(la, preis):
    wenn la.qty IS NULL oder la.qty == 0: return NULL          -- I4
    nach la.unit.code:
      'kg':  return preis / (la.qty * 1000)                    -- €/g
      'l':   return preis / (la.qty * 1000)                    -- €/g, Dichte 1.0 (Wasser-Näherung)
      'Stk': return preis / la.qty                             -- €/Stk
      sonst: return NULL                                       -- unbekannte Einheit

-- Brücken zwischen Mengen- und Stück-Welt (Konsument: GL-02 T3):
stk_zu_g(preis_pro_stk, gp): preis_pro_stk / gp.stk_default_g   falls stk_default_g > 0, sonst NULL
g_zu_stk(preis_pro_g, gp):   preis_pro_g * stueckgewicht_g       -- via gp_count_unit_defaults bzw. stk_default_g
```

### 3.3 Aktiver Preis eines LA + Historie

```
aktiver_preis(la):                       -- Soll (Port)
    zeilen = preiszeilen(la) mit kategorie ∈ {standard_ek, aktion}
    wenn leer: return NULL
    return zeile mit neuestem valid_to, Tiebreak: höchste id   -- "neueste gewinnt"
```

**Staffelpreise:** existieren in der Quelle **nicht**. Die strukturell dafür vorgesehenen Necta-Spalten `discount`, `discount_type` und `fix_price` sind über alle 221.591 Preiszeilen NULL; `price_partial` (111.543 Zeilen befüllt) ist der Necta-**Anbruch-/Teilgebinde-Preis**, kein Mengen-Staffelpreis, und wird im Ist nirgends kalkulatorisch ausgewertet (vgl. A-3). Port: keine Staffelpreis-Logik bauen; sollte Necta künftig Staffeln liefern → eigene Tabelle (`foodalchemist_price_tiers`) statt Überladung von `prices`.

**Historie:** Die Quelle führt de facto **keine** Historie — 221.586 von 221.588 LAs haben genau eine Preiszeile; `prices` ist ein Snapshot des letzten Necta-Imports. Historie entsteht im Ziel durch die Import-Strategie: **Append-only** — jeder Import fügt bei Preisänderung eine neue Zeile an (alte Zeile bekommt `valid_to` = Import-Datum), Updates nur an Metadaten. `aktiver_preis()` bleibt dadurch automatisch korrekt („neueste aktive Zeile"); Preis-Verlauf pro LA = alle Zeilen sortiert nach `valid_to`. (Konsument: `preis_monitor`-Funktionalität, Feature-Inventar.)

**⚠ A-2 (Ist):** Die Kosten-Aggregation (commands.rs:7224–7240) liest die neueste Zeile **ohne Kategorie-Filter** direkt aus `prices` (`ORDER BY valid_to DESC, id DESC LIMIT 1`), während `pick_lead_la` korrekt auf aktive Kategorien filtert. Bei Single-Zeilen-Datenlage harmlos, mit echter Historie falsch (eine `eingestellt`-Zeile würde den Preis NULLen). Spec-Soll: **beide** Konsumenten nutzen `aktiver_preis()` aus 3.3.

## 4. Entscheidungstabellen (normativ)

**T1 — `preis_kategorie`** (erste zutreffende Zeile gewinnt; Spalten = Eingangszustand):

| `price` | `status` | Kategorie | aktiv? | Verwendung |
|---|---|---|---|---|
| < 0 | egal | `service_charge` | nein | nie kalkulieren (I5); Anzeige als Zuschlag |
| ≥ 0, NOT NULL | `'0'` | `standard_ek` | **ja** | Standard-EK |
| ≥ 0, NOT NULL | `'2'` | `aktion` | **ja** | Aktionspreis, gleichberechtigt zu standard_ek |
| NULL | `'2'` | `eingestellt` | nein | LA ausgelaufen / kein Preis mehr |
| NULL | `'0'` | `datenluecke` | nein | gelistet, aber Preis fehlt → Review |
| sonst (anderer status) | — | `unbekannt` | nein | defensiver Default |

Reale Verteilung (Quell-DB 2026-06-10, Plausibilitäts-Anker für Seed-Tests): `eingestellt` 109.629 · `standard_ek` 104.225 · `aktion` 7.319 · `datenluecke` 416 · `service_charge` 2.

**T2 — Einheiten-Normalisierung** (`qty` = Gebinde-Inhalt in der LA-Einheit):

| `lookup_unit.code` | Bedeutung von `qty` | Zielgröße | Formel | NULL-Fälle |
|---|---|---|---|---|
| `kg` | kg im Gebinde | €/g | `price / (qty × 1000)` | `qty` NULL/0 → NULL |
| `l` | Liter im Gebinde | €/g (Dichte 1.0) | `price / (qty × 1000)` | dito |
| `Stk` | Stückzahl im Gebinde | €/Stk | `price / qty` | dito |
| NULL / sonst | — | — | NULL | immer NULL |

**T3 — Brücken-Matrix** (wenn Rezeptzeilen-Dimension ≠ Preis-Dimension; vgl. GL-02 T3):

| Rezeptzeile | verfügbarer Preis | Umrechnung | Voraussetzung |
|---|---|---|---|
| Masse/Volumen | nur €/Stk | `€/Stk ÷ gp.stk_default_g` → €/g | `stk_default_g > 0` |
| count (Stk, Zehe, Bund …) | nur €/g | `menge_g × €/g`, `menge_g` über `gp_count_unit_defaults`/`stk_default_g` | Stückgewicht bekannt |
| beides unmöglich | — | unpriced (GL-02 T4) | — |

## 5. Golden-Testfälle (verbindliche Wahrheit)

Alle Realfälle gegen `wawi_1494.sqlite` (Stand 2026-06-10) verifiziert.

**GT-1 — Kategorisierung (real, je Zeile `(price, status) → kategorie`):**

| LA | price | status | Expected |
|---|---|---|---|
| 29344887 (Limettensaft 0,75 l, Chefs Culinar) | 2.69 | '0' | `standard_ek` (aktiv) |
| 31141191 (Delta Fleisch) | 47.5 | '2' | `aktion` (aktiv) |
| 23614830 (Chefs Culinar) | NULL | '2' | `eingestellt` (nicht aktiv) |
| synthetisch | NULL | '0' | `datenluecke` |
| 31303090 („Zuschlag Wertstofftransport je Rolli") | −9.0 | '0' | `service_charge` — trotz status '0' NICHT `standard_ek` (price<0-Regel zuerst) |

**GT-2 — kg-Normalisierung (real):** LA 30513524 „Zucker Bio", 42,00 € je 25-kg-Gebinde → `42 / (25×1000) = 0.00168 €/g` = 1,68 €/kg. (Konsumiert in GL-02 GT-1.)

**GT-3 — l-Gebinde (real):** LA 34392290 (BOS Food, 2,4-l-Gebinde, 22,50 €) → `22.5 / 2.4 = 9.375 €/l` bzw. `22.5/(2.4×1000) = 0.009375 €/g`. LA 29344887 (0,75 l, 2,69 €) → `3.5867 €/l` = `0.0035867 €/g`.

**GT-4 — Stk-Normalisierung (real):** LA 31212011 (EPOS Bio, qty 1.0, Einheit `Stk`, aktiver Preis 1,72 €) → `1.72 €/Stk`. Kein €/g ableitbar ohne GP-Stückgewicht (T3).

**GT-5 — `qty` NULL (real, Port-kritisch):** LA 31141191: aktiver Preis 47,50 € vorhanden, aber `qty` NULL → Preis pro Einheit = **NULL** (I4), kein Fehler. Downstream: GL-03 sortiert ihn ans Ende (NULLS LAST), GL-02 wertet ihn als unpriced. (Im SQLite-Ist gewann genau dieser LA die Lead-Wahl — siehe GL-03 GT-1/A-2.)

**GT-6 — Stk→g-Brücke (real):** GP 4247 „Lorbeerblätter" (`stk_default_g` 0.2): Lead-LA 35070083 ist kg-bepreist (1,84 €/0,05 kg → 0.0368 €/g); Rezeptzeile „2 stk" → `menge_g = 0.4` → Kosten 0.01472 € (count→mass-Richtung, vgl. GL-02 GT-1). Umgekehrte Richtung synthetisch: nur-Stk-bepreister LA 0,50 €/Stk, `stk_default_g = 50` → `0.01 €/g`.

**GT-7 — Aktiver Preis bei Historie (synthetisch, Soll-Verhalten 3.3):** LA mit drei Zeilen: (10,00 €, '0', valid_to 2026-01-31) · (12,00 €, '0', valid_to 2026-12-31) · (NULL, '2', valid_to NULL). Expected: `aktiver_preis` (Kalkulation, GL-02) = **12,00 €** — neueste aktive Zeile; die `eingestellt`-Zeile ist unsichtbar. Der Ist-Vergleichswert in GL-03 wäre dagegen `MIN` über aktive Zeilen = **10,00 €**. Diese Differenz (10 vs. 12) ist kein Tippfehler, sondern die offene Detail-Weiche W-1 (§6) — nach Entscheid wird der Test auf einen Wert fixiert.

## 6. Offene Weichen + Verbesserungen

**⚠ Ist-Implementierung weicht ab:**

- **A-1 View-Name führt in die Irre:** `v_active_prices` filtert weder nach Gültigkeit noch nach Aktivität (kein `valid_to`-Check, kein Kategorie-Filter) — sie ist eine reine Kategorisierungs-Annotation. Der Port übernimmt die **Logik** (T1) als Scope/Accessor, nicht den Namen als Versprechen.
- **A-2 Inkonsistente Preiszeilen-Auswahl:** Kalkulationspfad nimmt „neueste Zeile egal welcher Kategorie", Lead-Wahl nimmt „MIN über aktive Kategorien" (Details §3.3). Spec-Soll: einheitlich `aktiver_preis()` (neueste aktive Zeile) für die Kalkulation; für GL-03-Vergleich siehe W-1.
- **A-3 Aktionspreis-Felder ungenutzt:** `promotional_price` + `promotional_price_valid_until` werden im Ist nirgends ausgewertet; `aktion` entsteht allein aus `status='2'` + befülltem `price`. Port: Felder mitnehmen, Auswertung als bewusstes Nicht-Feature dokumentieren (Kandidat fürs Verbesserungs-Register, nicht stillschweigend „reparieren").

**Offene Weichen:**

- **⚠D1 — Preis-Sichtbarkeit pro Team** (`08_ENTSCHEIDUNGEN.md` D1 + `02_DATENMODELL.md` E.2): Preise/Rückvergütungs-Konditionen sind sensibel. Offen: sehen alle Teams alle Preise (global, `team_id` NULL) oder Freischaltung pro Lieferant×Team? Folgewirkung auf GL-02 (`ek_total_eur` team-abhängig?) und GL-03 (Lead pro Team?). Arbeits-Annahme bis Entscheid: global sichtbar, ein Preisstand.
- **W-1 — MIN vs. neueste aktive Zeile** für den GL-03-Preisvergleich (GT-7): Vorschlag — überall „neueste aktive Zeile", da `MIN` über die Historie nach Append-only-Importen systematisch veraltete Tiefstpreise bevorzugen würde. Entscheid in `08_ENTSCHEIDUNGEN.md` nachtragen.

**Verbesserungen:** **V-07** (Preis-Import: Append + `valid_to`-Stempelung der Vorgänger-Zeile + nachgelagerte GL-03/GL-02-Bulk-Läufe als eine Transaktion/Job-Kette) · **V-22** (Seed-Gates: 416 `datenluecke`-Zeilen, LAs mit `qty` NULL/0 trotz aktiven Preises und die 2 `service_charge`-Zeilen beim Import flaggen statt still übernehmen).
