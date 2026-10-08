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

## Gestaltung durch den Kunden
Anlass Dominique: „Ein Kunde wird fragen, ob man das Etikett-Design ändern kann." Ja, von Anfang an.
- Basis sind die **Designs (Spec 43, `presentation_designs`)**: Logo, Farben, Schrift als Tokens, `output_types` um `etikett` erweitert. Ein Kunde mit gepflegtem Design bekommt passende Etiketten ohne Zusatzarbeit.
- **Etikett-Vorlagen** je Betrieb (`label_templates`): Name, Format (A4-Bogen 24/40 · Rolle 62 mm · Dymo 54×25), Typ **intern | verkauf**, Felder an/aus + Reihenfolge, Schriftgröße, Rahmen, Hervorhebung „verbrauchen bis", Allergen-Darstellung (Kürzel | Klartext | beides, Spuren getrennt), eigener Fußtext, Design-Bezug. Mehrere Vorlagen je Betrieb (z. B. TK-Ware, Kühlhaus, To-Go).
- **Datumsfelder je Feld einstellbar** (Dominique: „je nach Kunde wird man das Datum händisch eintragen"):
  `vorbelegt` (heute bzw. aus der Haltbarkeit, beim Druck überschreibbar) oder `leer` (Schreiblinie
  „hergestellt am: ______" für Etiketten auf Vorrat, groß genug für Kugelschreiber auf Gefrierbeutel).
  Gilt für hergestellt, eingefroren, geöffnet, verbrauchen bis, auch gemischt. Allergene und Bezeichnung
  sind nie Handschrift-Felder.
- **Live-Vorschau** im Einstellen mit echten Daten eines gewählten Rezepts.
- **Pflichtfelder sind nicht abwählbar:** Bezeichnung, „verbrauchen bis", Allergene. Typ `verkauf` erzwingt zusätzlich die LMIV-Pflichtangaben für vorverpackte Ware (Zutatenliste mit hervorgehobenen Allergenen, Menge, Hersteller/Anschrift). Gestaltung frei, Pflichtinhalt nicht.
- MCP: `label_templates.GET/POST/PUT`, damit die KI eine Vorlage „im Stil von Kunde X" anlegen kann.

## Offene Entscheidung
Druckweg: A4-Bogen, Etikettendrucker (welches Modell?) oder beides wählbar in den Einstellungen.

## Reihenfolge
Nach Spec 67 (Lagerbewegungen) und 68 (Bestellvorlagen). Danach Spec 69 (Chargen) auf dieser Basis.
