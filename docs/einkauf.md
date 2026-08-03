---
title: Einkauf
order: 8
---

# 🛒 Einkauf

Der Einkauf ist das **Handeln**: bestellen, Konditionen pflegen, den Wareneingang buchen. Das
Auswerten — Preisvergleich, Wareneinsatz, Simulation — liegt im [Controlling](controlling).
Zwei Tätigkeiten, zwei Orte.

---

## Bestellungen

Eine Bestellung entsteht nicht als Formular, sondern als offener Entwurf je **Lieferant und
Liefertag**, in den Bedarf hineinläuft, bis du ihn absendest. Der **Liefertag** trennt die
Bestellungen: derselbe Lieferant kann für Montag und für Donnerstag je eine eigene offene
Bestellung haben. Bedarf, der aus der Produktion kommt, landet automatisch in der Bestellung des
passenden Liefertags (= Produktions-/Einsatztag des Auftrags).

Bedarf kommt aus drei Richtungen:

| Woher | Wie |
|-------|-----|
| **Produktion** | Aus dem Planungsblatt: „＋ Bedarf in Bestellung" rechnet die Rezepturen auf Gebinde herunter (Liefertag = Produktionstag). |
| **Preisvergleich** | Aus dem Controlling: „→ Bestellung" legt einen einzelnen Artikel dazu. |
| **von Hand** | Sonderbedarf direkt in der Bestellung erfassen. |

Gerechnet wird in **Gebinden**, nicht in Kilogramm — bestellt wird, was der Lieferant liefert.
Die Rundung passiert auf dem Gesamtbedarf je Grundprodukt, nicht je Quelle; sonst würde zweimal
aufgerundet, wenn derselbe Artikel aus Produktion und Handeingabe kommt.

Eine Ampel zeigt, ob die Mindestbestellmenge erreicht ist.

### Übersicht: nach Liefertag

Der Bestell-Browser stellt die **einzelnen Bestellungen** in den Vordergrund, nicht den
Lieferanten. Gefiltert und sortiert wird nach **Liefertag** (Standard, nach Tag gruppiert) oder
umgeschaltet nach **Bestelldatum** (angelegt). Ein Zeitraum-Schnellfilter (heute, diese/nächste
Woche) und ein freies von/bis-Fenster grenzen die Liste ein; Lieferant, Status und Freitext
bleiben als Zusatzfilter. Bearbeitet wird jede Bestellung im Vollbild-Editor.

---

## Status und Versand

`Entwurf → gesendet → bestätigt → geliefert` (oder `storniert`). Nur der Entwurf ist
bearbeitbar; mit dem Absenden frieren die Preise ein — was danach im Katalog passiert, ändert
die abgesendete Bestellung nicht mehr.

Raus geht die Bestellung als Druckansicht, PDF, CSV oder als vorbereitete E-Mail an die
hinterlegte Bestelladresse.

---

## Was hier zusammenläuft

- **Konditionen** je Lieferant (Liefertage, Bestellschluss, Vorlaufzeit, Mindestmenge,
  Frei-Haus-Grenze, Rückvergütung) pflegst du am Lieferanten in den
  [Stammdaten](stammdaten).
- **Welcher Lieferant** ein Grundprodukt liefert, entscheidet die Bezugsquelle (Lead-Artikel).
  Umgestellt wird sie im [Controlling → Preise](controlling) — dort steht der Vergleich daneben,
  auf dem die Entscheidung beruht.
- Eine abgesendete beziehungsweise gelieferte Bestellung spiegelt sich ins **Einkaufsjournal**.
  Das ist die Ist-Datenbasis, aus der das Controlling Spend, Einsparpotenziale und die gemessene
  Wareneinsatzquote rechnet.

---

> **Kein Lager.** Das Modul führt keinen Bestand und keine Inventur. Es ist ein
> Bestellassistent: es weiß, was gebraucht wird und was es kostet — nicht, was noch im Regal steht.
