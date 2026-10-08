# Spec 67 · Lagerbewegungen von Hand

Stand 2026-10-08 · Entscheid Dominique: Hand-Buchungen im Lager, aus Spec 66 Stufe 2 vorgezogen.
Bestellvorlagen laufen getrennt als Spec 68.

## A · Lagerbewegungen von Hand

Bisher entstehen Bewegungen nur aus Wareneingang und Inventur. Ware ohne Bestellung, Verderb oder
Umlagerung tauchen erst bei der nächsten Inventur als unerklärte Differenz auf.

### A.1 Arten
| Art | `source` | Richtung | Gründe (`reason`) |
|---|---|---|---|
| Zugang | `zugang` | in | ohne_bestellung · ruecknahme · marktkauf · korrektur |
| Abgang | `abgang` | out | verderb · bruch · schwund · personal · probe · korrektur |
| Umlagerung | `umlagerung` | out am Quell-, in am Ziel-Lagerort | — |
| Storno | `storno` | Gegenrichtung der stornierten Buchung | — |

- Menge in kg / l / Stk, oder in Karton + Einheit + lose, wenn der Lead-Artikel ein Gebinde kennt (gleiche Logik wie die Zählliste, Spec 66b).
- Basiseinheit = die des vorhandenen Bestands am Lagerort, sonst die des Lead-Artikels.
- Bewertung: `price_per_base` beim Buchen eingefroren, bei Zugängen optional von Hand (€ je kg/l/Stk), sonst aktueller EK. `value_eur` = Menge × Preis.
- Der Bestand wird sofort fortgeschrieben, auch ins Negative. Das ist ein periodisches Lager, die nächste Inventur korrigiert.
- Storno: Gegenbuchung, das Original bleibt als Beleg stehen (`storno_of_id`). Wareneingang und Inventur werden nicht hier storniert, sondern an ihrer Quelle.
- Umlagerung: zwei Bewegungen mit gemeinsamer `transfer_ref`. Storno einer Umlagerung storniert beide.

### A.2 Neue Spalten an `foodalchemist_inventory_movements`
`reason`, `price_per_base`, `value_eur`, `booked_by`, `transfer_ref`, `storno_of_id` (alle nullable).

### A.3 Oberfläche
- Reiter **Bewegungen**: Knopf „Bewegung buchen", Formular mit Art, Grundprodukt, Lagerort (Ziel bei Umlagerung), Menge, Grund, Notiz, Datum.
- Filter: Quelle und Grund. Spalten Grund und Wert. Storno bei Hand-Buchungen.

### A.4 Controlling
Die Wareneinsatz-Analyse weist **benannten Schwund** aus: Σ `value_eur` der Abgänge mit Grund verderb/bruch/schwund im Zeitraum. Personal und Probe werden extra ausgewiesen. Das ist ein Teil der Abweichung, der nicht mehr unerklärt ist.

### A.5 MCP
`inventory_movements.POST` (Arten wie oben) und `inventory_movements.STORNO`. `inventory.GET` mit `bewegungen=true` liefert Grund und Wert.

## Nicht in Spec 67
Bestellvorlagen → Spec 68 (eigener PR). Entnahme durch die Produktion, Mindestbestand → Vorschlag.
