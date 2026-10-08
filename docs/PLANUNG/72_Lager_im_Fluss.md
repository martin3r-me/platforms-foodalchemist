# Spec 72 · Lager im Fluss — Eigenproduktion in der Bestellung, Produktion bucht aus, Inventur-Editor

Stand 2026-10-08 · Wünsche Dominique nach Spec 69/71:
- „Wenn ich einen Gulasch eingefroren habe, darf das nicht in die Bestellung kommen."
- „Wenn die Produktion abgearbeitet ist, muss es aus dem Lager gebucht werden, auch kleinste Mengen."
- „Kann ich das auch in der normalen Bestellung machen?"
- „Inventur im Editor, vorne nur die Liste."

## 1. Eigenproduktion kürzt den Rezeptbedarf (Bestellrunde)
- `PlanungsblattService::explodiere` bekommt einen optionalen **Lager-Topf** `recipe_id => [menge, base, abziehen, bedarf, genutzt]`. Er wird per Referenz übergeben und über alle Quellen einer Runde nur einmal verteilt.
- Liegt ein Rezept im Lager und ist Abziehen an, wird sein Bedarf **vor der Auflösung** gekürzt. Es gibt dann weniger Ansätze und damit weniger Zutaten und Sub-Rezepte. Das gilt für Gerichte (Portionen) und Basisrezepte (g). Beispiel: 1 kg Sauce im TK bei 1,5 kg Bedarf ergibt einen Ansatz statt zwei.
- **Ein Ansatz entspricht:** Yield bei g, `yield_pieces` bei Stk, Portionen je Ansatz bei Port. Ohne Ertrag wird nichts abgezogen.
- **Anzeige und Knopf** wie beim GP (Spec 71): Block „Eigenproduktion im Lager" mit Name, im Lager, Bedarf und abgezogen. Je Rezept gibt es „vom Bedarf abziehen" / „nicht abziehen". `overrides.lager_rezept[recipe_id]` schlägt `lager_abgleich`.
- Andere Aufrufer (Produktionsblatt, Einkaufsliste, Bestellvorschlag ohne Topf) bleiben unverändert.

## 2. Produktion „fertig" bucht den Verbrauch aus
- Auslöser: `ProductionOrderService::setStatus(Done)`. Der Status ist final, also gibt es kein Zurückbuchen.
- Ausgeführt wird der Verbrauch von `ProduktionsVerbrauchService::ausbuchen`.
- **Grundprodukte:** Zutatenmenge (brutto g) × wirksame Ansätze jeder Zeile. Gestrichene und übersprungene Zeilen zählen nicht.
  - Gebucht wird exakt, auch Kleinstmengen.
  - Die Reihenfolge ist: aktive Lagerorte, Standard-Lagerort zuerst. Stk über das Stückgewicht.
  - **Nie unter 0.** Was fehlt, steht im Ereignis `order_status_changed.payload.lager_verbrauch.fehlt`.
- **Eigenproduktion:** Ein Sub-Rezept, das in diesem Auftrag **nicht** produziert wurde, kommt aus dem Lager. Typisch ist eine gestrichene Zeile mit dem Grund „aus dem TK". Gebucht wird per FIFO-Entnahme (Verbrauch).
- **Bewegungen:** Quelle `produktion` bzw. `entnahme`, mit `production_order_id`. Das ist die Idempotenz-Sperre: ein zweiter Aufruf bucht nichts mehr.
- **Controlling:** Diese Bewegungen zählen nicht als erklärter Abgang. Sie senken den Soll-Bestand der nächsten Inventur, die Inventur-Differenz ist danach echter Schwund.
- **Migration** `2026_10_08_160000`: `inventory_movements.production_order_id`, nullable, additiv.

## 3. Einzelbestellung
Die Zeile zeigt schon „Lager: X verfügbar, Restbedarf Y". Neu ist der Knopf **„auf N Gebinde kürzen"**. Er rechnet aus `InventoryService::lineStockSummary.packs_fuer_rest`, wie viele Gebinde der Restbedarf braucht.

## 4. Inventur im Editor
- Reiter *Inventuren* zeigt vorne nur „Neue Inventur" und die Liste.
- Öffnen oder Anlegen startet einen Vollbild-Editor (`lager-inventur`), mit Stichtag und Lagerort im Titel. Dort wird gezählt, ergänzt, die Liste gedruckt, verworfen und gebucht.
- Schließen (✕/Escape) führt zur Liste zurück. Ein Direktlink `?reiter=inventur&inventur=ID` öffnet den Editor.

## Offen
- **Reservierung:** Lager, das in einer offenen Runde angerechnet ist, zählt in der nächsten Runde wieder.
- **Darreichungs-Deltas:** Je Zutat werden sie beim Ausbuchen nicht berücksichtigt. Gebucht wird nach Rezept × Ansätze.
