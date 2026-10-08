# Spec 73 · Bestellrunde: löschen, Reservierung, Konzept/Paket-Quellen, Vorlagen im Editor

Stand 2026-10-08 · Wünsche Dominique nach dem Praxistest von Spec 71/72.

## 1. Runde löschen
- `OrderService::deleteRound`: geht nur, solange keine Bestellung der Runde versendet ist. Sonst kommt die Meldung „erst stornieren".
- Ablauf:
  1. Die Beiträge der Runde werden über `source_refs` aus den Entwürfen entfernt.
  2. Entwürfe, die nur zu dieser Runde gehören, werden ganz gelöscht.
  3. Leere Entwürfe fallen weg, die Runde wird gelöscht (soft delete).
- **UI:** „Runde löschen" im Runden-Editor und in der Runden-Ansicht der Bestellliste.
- **MCP:** `order_rounds.DELETE`.

## 2. Runde öffnet vollständig wieder
- Die Runde speichert `sources`, `overrides` (Auslassen, Handmengen, Lager-Knöpfe, Artikelwechsel) und `source_refs`. Migration `2026_10_08_170000`.
- Beim Öffnen wird der ganze Arbeitsstand wiederhergestellt.
- **Altbestand** (vor dem 08.10. gespeichert) kennt seine Quellen nicht. Der Editor zeigt dann „Bestellungen dieser Runde" mit Öffnen je Bestellung. Das gilt für jede Runde.
- Bewusst **keine** Rekonstruktion aus Bestellzeilen: Ein erneutes Speichern würde Mengen verdoppeln.

## 3. Lager nicht doppelt anrechnen (Reservierung)
- Eine gespeicherte Runde hält in `lager_reserviert` fest, was sie aus dem Lager angerechnet hat. Grundprodukte in g, Eigenproduktion in Basiseinheit.
- Andere Runden sehen nur den freien Rest. Die Position zeigt „X kg im Lager schon reserviert für Runde …".
- **Laufzeit:** Die Reservierung gilt bis zum Liefertag der Runde. Ohne Liefertag gilt sie 14 Tage ab Anlage. Beim Löschen der Runde entfällt sie.
- Die Runde selbst sieht ihre eigene Reservierung beim Wiederöffnen (`overrides.round_id`).

## 4. Konzept / Paket als Quelle
- Neue Quelltypen `concept` und `paket` × Personen, in der Bestellrunde und in Vorlagen.
- `PlanungsblattService::topsAus` kann jetzt `paket_id` auflösen. Die Gerichte laufen wie im Konzept-Slot über `positionTop` und die Darreichung.
- Quellen-Reiter in der Bestellrunde: Artikel · Grundprodukt · Rezept · **Konzept/Paket** · **Vorlage** · Produktion. „Vorlage einfügen" und „Als Vorlage speichern" sind in den Reiter gewandert.

## 5. Bestellvorlagen im Editor
- **Seite:** vorne nur „Neue Vorlage" und die Liste, nach **Kategorie** gruppiert. Dazu Suche und Kategorie-Filter. Migration `2026_10_08_190000`: `kategorie`, Positionen mit `concept_id` und `paket_id`.
- **Editor** `Bestellvorlagen\Editor`, Vollbild-Modal:
  - Bearbeiten → Speichern / Abbrechen, mit Spec-65-Sperre (`order_template`).
  - Positionen ändern nur im Bearbeiten-Modus.
  - Bestellen (Vorschau, In Bestellrunde öffnen, Direkt anlegen) geht auch beim Lesen.

## 6. Einzelbestellung öffnen
- Ein Klick auf eine Zeile der Bestellliste öffnet die Bestellung im Editor. Vorher landete die Auswahl nur in der oft eingeklappten Detailspalte.
- Gehört die Bestellung zu einer Runde, zeigt der Editor „Teil der Bestellrunde … · Ganze Runde öffnen".

## Abgegrenzt
Wareneingang/Lieferschein/Rechnung als Triple Match → Spec 75 (eigene Session, Branch `feat/wareneingang-triple-match`).
