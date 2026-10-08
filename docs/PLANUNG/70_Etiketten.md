# Spec 70 · Etiketten (Entwurf, nicht gebaut)

Stand 2026-10-08 · Dominique: „Etiketten drucken aus den Basisrezepten oder wo es passt, für das Lager."
Vorgezogener erster Teil von Spec 69 (Eigenproduktion). Das Etikett nützt sofort, auch ohne
Lagerbuchung. Mit Spec 69 erzeugt der Druck aus der Produktion zugleich die Charge.

## Etikett-Arten
| Art | Druck aus | Inhalt |
|---|---|---|
| Eigenproduktion | Basisrezept, Gericht, Produktionsauftrag (je Zeile) | Name, hergestellt am, eingefroren am (optional), **verbrauchen bis**, Menge, Kürzel, **Allergene** (Rezept-Kaskade), Lagerhinweis gekühlt/TK, später Chargen-Nr. |
| Stellplatz / Regal | Lager → Einrichten | Stellplatz, Zone, Grundprodukte des Platzes |
| Anbruch / Umfüllung | Grundprodukt | Name, geöffnet am, verbrauchen bis, Allergene |

## Bausteine
- Rezept: `haltbarkeit_gekuehlt_tage`, `haltbarkeit_tk_tage` (Vorschlag für „verbrauchen bis"; beim Druck überschreibbar).
- Druckansicht wie die Zählliste (HTML + DomPDF), Layouts als Vorlagen:
  - A4-Bogen (z. B. 3×8 = 24, 4×10 = 40, frei wählbarer Startplatz für angebrochene Bögen),
  - Rolle 62 mm (Brother QL) bzw. 54 × 25 mm (Dymo), ein Etikett je Seite.
- Anzahl je Druck (z. B. 6 Behälter Suppe → 6 Etiketten), Mengenangabe je Behälter.
- Allergene als Kürzel und Klartext, Spuren getrennt. Quelle ist dieselbe Kaskade wie die Deklaration.
- MCP: `labels.POST` (Art, Bezug, Daten, Anzahl → Druck-Link).

## Offene Entscheidung
Druckweg: A4-Bogen, Etikettendrucker (welches Modell?) oder beides wählbar in den Einstellungen.

## Reihenfolge
Nach Spec 67 (Lagerbewegungen) und 68 (Bestellvorlagen). Danach Spec 69 (Chargen) auf dieser Basis.
