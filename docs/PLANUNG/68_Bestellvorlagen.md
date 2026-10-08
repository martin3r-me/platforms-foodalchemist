# Spec 68 · Bestellvorlagen („Musterbestellung / Musterproduktion")

Stand 2026-10-08 · Entscheid Dominique: Vorlagen gehören ins Bestellwesen, getrennt vom Lager.
Basis ist das **Grundprodukt**, nicht der Lieferantenartikel. Auch **Rezepte** sollen hinein, damit
eine Musterproduktion automatisch eine Bestellung ergibt.

## Idee
Eine Vorlage ist eine **gespeicherte Bestellrunde**. Die Bestellrunde (`OrderService::previewFromSources`
/ `generateDraftsFromSources`) kann schon Grundprodukte, feste Artikel und Rezepte aufnehmen, den
Artikel nach Lead-Strategie wählen, eine Vorschau zeigen und je Position den Artikel tauschen. Die
Vorlage speichert nur die Quellen. Anwenden öffnet die Runde vorbefüllt oder legt die Entwürfe direkt an.

## Positionen
| Art | Bezug | Menge | Artikelwahl beim Anwenden |
|---|---|---|---|
| Grundprodukt | `gp_id` | kg · g · stk | Lead-Strategie (Stammlieferant, günstigster …) |
| Rezept / Gericht | `recipe_id` | Portionen (Gericht) bzw. Ansätze (Basisrezept) oder kg | Bedarf aus der Rezeptur, je Zutat Lead-Strategie |
| Fester Artikel | `supplier_item_id` | Gebinde | genau dieser Artikel (Ware ohne GP, Markenware) |

Gleiche Logik wie die Bestellrunde, kein zweiter Bestellweg. Mengen sind beim Anwenden je Position
überschreibbar, 0 lässt eine Position aus.

## Datenmodell
- `foodalchemist_order_templates`: team_id, name, note, weekday (1–7, „üblicher Bestelltag"), last_used_at, created_by, softDeletes.
- `foodalchemist_order_template_lines`: template_id, team_id, type (gp | recipe | supplier_item), gp_id, recipe_id, supplier_item_id, qty, unit, note, position.

## Anlegen
- Leer, dann Positionen per Suche: Grundprodukt, Rezept/Gericht, Artikel.
- Aus einer **Bestellrunde** speichern (die aktuellen Quellen).
- Aus einer **Bestellung**: Zeilen mit Grundprodukt werden zur GP-Position (Menge = Gebinde × Inhalt), Zeilen ohne GP zum festen Artikel.

## Oberfläche (Bestellwesen)
- Neue Sicht **Vorlagen**: Liste mit Name, Bestelltag, Positionen und zuletzt genutzt.
- Detail: Positionen mit Menge und Einheit, darunter die **Vorschau** (je Lieferant Artikel, Gebinde, Preis) und Hinweise (kein Artikel, keine Menge).
- „Bestellung anlegen" mit Liefertag → Entwürfe. „In Bestellrunde öffnen" → Cockpit vorbefüllt.

## MCP
`order_templates.GET` (mit Vorschau), `.POST` (auch `from_order_id`), `.PUT`, `.DELETE`, `.APPLY`.

## Nicht in Spec 68
Automatisches Auslösen am Bestelltag (Scheduler), Vorlage aus Speiseplan-Woche.
