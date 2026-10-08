# Spec 74 · Lagerartikel und Mindestbestand

Stand 2026-10-08 · Anlass Dominique: In der Bestellrunde gehen immer alle Gewürze und ähnliches mit. Diese Artikel werden vom Vorrat genommen und bräuchten stattdessen ein Signal „kurz vor leer" mit Mindestmengen.

## Modell
- `foodalchemist_gp_lagerartikel` (Migration `2026_10_08_180000`): je Betrieb und Grundprodukt `ist_lagerartikel`, `mindestbestand`, `sollbestand` und `base_unit` (g/ml/Stk). Die Basiseinheit kommt aus dem vorhandenen Bestand oder dem Lead-Artikel.
- `LagerartikelService`:
  - `setzen` und `markieren` (Massenmarkierung)
  - `ids`
  - `liste`: Bestand über alle aktiven Lagerorte, Ampel leer | unter_min | ohne_min | ok
  - `unterMindest`

## Bestellrunde
- Lagerartikel aus dem Rezeptbedarf gehen **nicht** in die Bestellung. Sie stehen in der Liste „Lagerartikel aus dem Vorrat", jeweils mit „trotzdem bestellen" (`overrides.vorrat_bestellen`).
- Knopf „N unter Mindestbestand nachfüllen" fügt die Quelle `nachfuellen` hinzu. Damit werden alle Lagerartikel unter dem Minimum auf den Sollbestand aufgefüllt. Ohne Sollbestand wird auf das Doppelte des Mindestbestands aufgefüllt.
- Diese Positionen laufen nicht über den Lager-Abzug.

## Signal und Pflege
- **Lager:** Reiter **Lagerartikel** mit Tabelle, Mindest-/Sollbestand inline, Status und Ergänzen per Suche. Auf allen anderen Reitern erscheint der Hinweis „N Lagerartikel unter Mindestbestand".
- **Einrichten:** markierte Grundprodukte → „Als Lagerartikel markieren".
- **Grundprodukt → Reiter Lager:** Lagerartikel ja/nein, Mindestbestand, Auffüllen auf. Das ist ein Betriebsdatum und ohne „Bearbeiten" pflegbar.
- **MCP:** `lagerartikel.GET` (`nur_unter_mindest`), `lagerartikel.PUT`.

## Dashboard und Vorschlag
- **Dashboard:** Aufgabe „Lagerartikel unter Mindestbestand" mit Zahl, Link in den Lager-Reiter „Lagerartikel".
- **Vorschlag nach Warengruppe** (Reiter „Lagerartikel"):
  - Je Warengruppe die Grundprodukte, die der Betrieb nutzt (Bestand oder in 365 Tagen bestellt) und die noch kein Lagerartikel sind.
  - Typische Vorrats-Gruppen kommen zuerst (Name enthält Gewürz, Öl, Essig, Salz, Fett, Zucker, Mehl …).
  - Ein Klick markiert alle Grundprodukte der Gruppe.

## Ausbuchen mit Darreichungs-Deltas (Nachtrag zu Spec 72)
- Die Produktions-Auflösung schreibt je Zutat `menge_g` in den Schnappschuss der Zeile: Brutto-Gramm × Ansätze, inklusive Darreichungs-Delta.
- `ProduktionsVerbrauchService` bucht nach diesem Schnappschuss. Eine Handkorrektur der Ansätze skaliert ihn (wirksame ÷ gerechnete Ansätze).
- Ältere Schnappschüsse ohne `menge_g` buchen wie bisher Rezept × Ansätze.
