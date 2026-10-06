---
title: Speiseplan
order: 7
---

# 🗓️ Speiseplan

Der Speiseplan verteilt deine Bausteine über die **Zeitachse** — welches Gericht, Paket oder Concept an welchem Tag, zu welcher Mahlzeit und an welcher Ausgabe (Linie) auf den Tisch kommt.

Ein Plan ist ein Raster aus **Linie × Tag** für eine Mahlzeit (Frühstück, Mittag, Abend, Snack), organisiert in einem **Wochen-Zyklus** (z. B. 4 Wochen), der sich wiederholen lässt. Jeder Eintrag enthält **genau einen** Baustein: ein Concept, ein Paket oder ein Gericht.

---

## Linien = Ausgabestellen

Jede Linie ist eine Ausgabe (Menü 1, Vegetarisch, Wok, Salatbar, Dessert …). Im Tab **Menü-Linien** pflegst du je Linie:

- **Rolle** (Suppe, Hauptgang, Salat, Beilage, Dessert). Hauptgang-Linien zählen die **Gäste** des Tages.
- **Mahlzeit** — leer heißt: gilt für alle Mahlzeiten; sonst erscheint die Linie nur dort (z. B. Frühstücksbuffet).
- **Kassen-Nr.** für Aushang, Schilder und CSV.
- **Preis**: „vom Gericht“ oder ein **fester Linienpreis** (netto). Das Gericht behält seinen eigenen VK.
- **Zielband Wareneinsatz** (von–bis %). Leer = das Wareneinsatz-Ziel des Teams bzw. Betriebs.
- **Essen je Tag** als Standard der Linie und **Dauerangebot** (z. B. Salatbar — zählt nicht für die Wiederholungsregel).

## Kalender

Die Wochen-Matrix zeigt je Eintrag Wording, Kostform (Vg vegan, Vt vegetarisch, Sw Schwein, Rd Rind, Fi Fisch, Fl Fleisch ohne gepflegte Tierart), LMIV-Codes, VK, **Wareneinsatz mit Ampel gegen das Zielband der Linie** und die Essen. Unter jedem Tag steht der **Tagesfuß** (Gäste, Umsatz, Wareneinsatz, EK je Gast). „Detail“ blendet die Komponenten mit Mengen und kcal ein.

- **Belegen:** „+“ in einer Zelle öffnet den Picker (Gericht, Concept, Paket mit Facetten).
- **Umbauen:** Einträge per **Drag & Drop** in eine andere Zelle ziehen — oder den Eintrag anklicken: im Detail **ersetzen**, **verschieben**, **auf andere Tage kopieren** oder entfernen (auch per Tastatur).
- **Woche kopieren:** die sichtbare Woche auf eine andere Woche — belegte Zellen ersetzen oder zusammenführen, Mengen optional.
- **Öffnungstage** (Stammdaten) bestimmen die Spalten: Standard Mo–Fr, auch mit Wochenende.
- **→ Produktion** legt je Öffnungstag einen Produktionsauftrag an; ein zweiter Klick aktualisiert ihn, statt ihn zu verdoppeln.

## Mengen und Bedarf

- **Mengen:** Essen je Linie × Tag an einem Ort, mit Vorwoche, Ø der letzten vier Wochen, Summe, Anteil, Wareneinsatz und Umsatz. „Aus Vorwoche übernehmen“ und „Skalieren“ setzen die ganze Woche.
- **Bedarf:** Zutaten der Woche oder eines Tages aus Plan × Essen, bis zum Grundprodukt aufgelöst, mit Lieferant, Gebinden und EK. An den Einkauf geht der Bedarf über die Produktion („Bedarf freigeben“ im Auftrag) — so wird nichts doppelt bestellt.

## Abwechslung steuern

Die **Wiederholungsregel** (Mindestabstand in Tagen) meldet in der rechten Spalte, wenn dasselbe Gericht zu eng beieinander steht. Dort stehen auch Kostformen je Tag, LMIV-Kennzeichnung der Woche, DGE-Durchschnitt und das Budget je Gast.

## Voll-Kaskade (KI)

„Voll-Kaskade …“ zeigt zuerst, wie viele Zellen leer sind und wie viele KI-Läufe ein Lauf startet — erst „Starten“ legt los. Jede Zelle bekommt Leitplanken aus dem Plan mit: was in der Nähe schon steht (nicht wiederholen), ein fehlendes veganes Gericht am Tag, Preis und Wareneinsatz-Ziel der Linie. Die Gerichte kommen als Entwurf und werden in der Leitstelle freigegeben.

## Ausgabe & Aushang

- **Druck & Export** der sichtbaren Woche: Wochenaushang A4, Tischaufsteller (ein Tag), Linienschilder (A5 quer), Buffetschilder, Allergen- und Komponentenliste (Woche/Tag) und CSV. Jede Vorlage lässt sich als PDF laden; Preise optional.
  - **Tischaufsteller** = Zeltkarte A4 quer, mittig gefalzt, obere Hälfte kopfstehend; alle Linien des Tages, ab 9 Einträgen eine weitere Zeltkarte.
  - **Buffetschilder** = je Gericht ein Zeltkärtchen (6 pro A4, gefalzt ca. 10 × 5 cm). Pakete und Concepts werden in ihre Gerichte aufgelöst; Unterrezepte (eine Ebene, z. B. Jus) bekommen ein eigenes Kärtchen „Komponente zu …“ (abschaltbar). Allergene ausgeschrieben; unbewertete Allergene → Hinweis „bitte beim Personal nachfragen“.
  - **Gäste-Drucke** (Aufsteller, Linien-, Buffetschilder) tragen Logo und Footer aus dem Branding und Farben/Schrift aus dem Präsentations-Design; gedruckt wird immer auf Weiß (dunkle Designs liefern nur den Akzent, abgedunkelt bis lesbar).
  - Einträge ohne Linie erscheinen als „Weitere Gerichte“ (auch in der Allergenliste). Die Legende gilt jeweils nur für die gezeigten Gerichte.
- **Digitaler Aushang** (Spec 43): login-freier Link mit LMIV-Kennzeichnung, Kostformen und DGE-Ø, preislos. Veröffentlicht wird die gerade sichtbare Woche und Mahlzeit — oder **immer die laufende Woche** (jeden Montag automatisch neu eingefroren).

## Plan/Ist

Der Tab **Plan/Ist** vergleicht geplante Essen und Umsatz je Gericht mit dem **Verkaufsjournal** (Import unter Controlling). Das Journal kennt noch keinen Betrieb; gezählt werden alle Verkaufsstellen des Teams.

## Vorlage für Betriebe

Ein Plan kann **Vorlage** sein (Stammdaten). Für jeden Betrieb des Teams entsteht daraus eine **verknüpfte Kopie**, die Preise, Mengen und Öffnungstage selbst pflegt. Der **Abgleich** in der Kopie zeigt, was die Vorlage seither geändert hat („aus der Vorlage“) und wo der Betrieb bewusst abweicht („lokal“) — Änderungen der Vorlage übernimmst du zellenweise oder alle auf einmal.

---

> **Gut zu wissen:** Weil der Speiseplan auf denselben Bausteinen steht wie der Rest des Moduls, kennt jeder Planungstag automatisch seinen Wareneinsatz, seine Kennzeichnung und seinen Zutatenbedarf — Planung, Kosten und Einkauf bleiben verbunden. Details zum Ausbau: `PLANUNG/57_Speiseplan_Aufmachung_und_Betrieb.md`.
