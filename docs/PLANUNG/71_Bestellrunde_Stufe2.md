# Spec 71 · Bestellrunde Stufe 2 — Lager-Abgleich, Positionen steuern, Lesemodus

Stand 2026-10-08 · Wunsch Dominique nach dem ersten Praxistest der Bestellrunde.

## Anlass
- Ein Artikel ließ sich nicht aus der Runde nehmen.
- Lieferanten-Gruppen waren nicht klappbar, lange Runden wurden unübersichtlich.
- Die Menge war nicht von Hand änderbar (Gebinde ±).
- Der Lagerbestand wurde nicht vom Bedarf abgezogen.
- Die Strategie-Wahl schien nichts zu ändern: Die Vorschau rechnete erst nach „Vorschau berechnen" neu.
- Nach „Bestellungen speichern" blieb die Runde offen, statt wie ein Rezept einzufrieren.

## Umsetzung
Alles läuft über `OrderService::nachbearbeitePreview`. Das gilt für Vorschau **und** Speichern, es gibt also keinen zweiten Rechenweg. Gesteuert wird über `overrides`:

| Schlüssel | Wirkung |
|---|---|
| `lager_abgleich` (bool, Standard aus) · `lager_pos[schluessel]` (bool, schlägt den Gesamtwert) | Bedarf (`needed_base_g`) minus Lagerbestand des GP. Grundlage: alle aktiven Lagerorte, Stk über `piece_default_g`, je GP nur einmal verteilt. Gebinde werden neu gerechnet. Voll gedeckt → `aus_lager`. Nur für Rezept- und GP-Quellen, nicht für feste Artikel. |
| `skip[positions_schluessel]` | Position → `ausgelassen` (wiederherstellbar). |
| `menge[positions_schluessel]` | Gebinde von Hand. Behält `qty_packs_berechnet` und setzt `menge_von_hand`. Beim Speichern bekommt die Bestellzeile `is_manual_qty = true`. |

- **Positions-Schlüssel:** `override_key` (Quelle|gp:ID), sonst `la:<source_ref>|<lead_la_id>`. Er wird als `position_key` in jede Position geschrieben.
- **Leere Lieferanten-Gruppen** fallen weg. Die Summen werden neu gerechnet.
- **UI (`Orders/Editor`):**
  - `cockpitSkip`, `cockpitMengen`, `cockpitLagerAbgleich` (Standard aus), `cockpitLagerPos`; der Bestand je Artikel (`lager_verfuegbar_g`) wird immer angezeigt, abgezogen nur per Knopf (Wunsch Dominique), `rundeGesperrt`.
  - Strategie-Wechsel und Lager-Schalter rechnen die Vorschau sofort neu.
  - Gruppen klappen über Alpine auf und zu, ohne Server. Sie bleiben auch im Lesemodus bedienbar.
- **Lesemodus:** Nach dem Speichern und beim Öffnen einer Runde gilt `rundeGesperrt = true`. Der Inhalt liegt dann in `<fieldset disabled>`, das Muster stammt aus Spec 65. „Bearbeiten" entsperrt die Runde.

## Offen
- Lager-Abgleich auf **Rezept-Ebene**: Eigenproduktion im Lager (Spec 69) soll den Rezeptbedarf kürzen, bevor er in Zutaten aufgelöst wird.
- Reservierung: Lagerbestand, der schon in einer offenen Runde angerechnet ist, wird heute noch einmal angerechnet. Der Abgleich ist eine Momentaufnahme.
