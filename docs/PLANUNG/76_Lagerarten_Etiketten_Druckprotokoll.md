# Spec 76 · Lagerarten am Rezept, Etiketten aus der Produktion, Druckprotokoll

Stand 2026-10-08 · Wünsche Dominique nach Spec 69/70:
- „Lagerung gehört in die Rezeptur, Mehrfachauswahl; beim Etikett nur noch sagen TK oder frisch."
- „Die Menge dürfte kein Freifeld sein."
- „Werden Etiketten gespeichert?" → Druckprotokoll.
- „In der Produktion die passenden Etiketten drucken" → beides: Sammeldruck und je Zeile.

## 1. Lagerarten am Rezept
- **Spalten** (Migration `2026_10_09_200000`): `storage_types` (json) und `shelf_life_dry_days`.
- **Backfill** aus `storage_type` und den vorhandenen Haltbarkeiten.
- **Rezept-Editor** (Stammdaten → Lagerung & Haltbarkeit): Tabelle Gekühlt / Tiefgekühlt / Trocken, je mit „möglich", Haltbarkeit in Tagen und Standard.
  - `storage_type` bleibt die Standard-Lagerart.
  - Ein Standard, der nicht erlaubt ist, wird durch die erste erlaubte Lagerart ersetzt.
- **Modell:** `lagerarten()` (ohne Angabe abgeleitet), `standardLagerart()`, `haltbarTage($art)`.
- **Etikett:** Lagerung per Schalter, nur aus den Lagerarten des Rezepts, mit Haltbarkeit („TK · 90 T."). „Verbrauchen bis" je Lagerart: TK ab Einfrieren, sonst ab Herstellung. Der Pflegeblock auf der Etiketten-Seite entfällt, stattdessen gibt es „Zum Rezept →".
- **Einlagern (Spec 69):** Auswahl nur aus den Lagerarten des Rezepts, Standard vorgewählt, Haltbarkeit auch für „trocken".
- **MCP** `recipes.PUT`: `storage_types`, `shelf_life_dry_days`.

## 2. Menge auf dem Etikett
- Zahl + Einheit (kg, g, l, ml, Portionen, Stück) statt Freitext. Leer = Schreiblinie.
- Vorbelegung: Gericht → Portionen, sonst kg.

## 3. Druckprotokoll
- `foodalchemist_label_prints` (Migration `2026_10_09_200100`): wer, wann, Quelle, Bezug, Vorlage, Anzahl, Eingaben, Bezeichnung, Lagerung, verbrauchen bis, Charge, Produktionsauftrag, `gruppe` (Sammeldruck).
- **Festgehalten** wird beim Öffnen der Druckansicht.
  - Vorschau-iframe zählt nicht.
  - Gleiche Person mit gleichen Angaben innerhalb von 2 Minuten zählt einmal.
- **Etiketten-Seite „Zuletzt gedruckt":** Einzeldrucke und Sammeldrucke (eine Zeile je Gruppe), jeweils mit „Nochmal drucken".

## 4. Etiketten aus der Produktion
- **Sammeldruck:** Knopf „Etiketten" im Auftrag, Auswahl aller Basisrezept-Zeilen (nicht gestrichen).
  - Standard: je Ansatz ein Etikett, Menge eines Ansatzes, hergestellt = Produktionstag, Lagerart = Standard des Rezepts.
  - Häkchen, Anzahl und Menge sind anpassbar, die Vorlage ist wählbar.
  - Route `etiketten/produktion/{order}`: alles in einem Druck (`EtikettService::druckListe`), Protokoll als eine Gruppe.
- **Je Zeile:** „Etikett" an jeder Basisrezept-Zeile → Etiketten-Seite vorbelegt (`?e[...]`).
- **Druckansicht** kann jetzt eine Liste verschiedener Etiketten ausgeben (`liste`), für Bogen und Rolle.
- **Nochmal drucken (Gruppe):** Route `etiketten/gruppe/{gruppe}`.
