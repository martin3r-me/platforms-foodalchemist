---
title: Einkauf
order: 8
---

# 🛒 Einkauf

Der Einkauf ist das **Handeln**: bestellen, Konditionen pflegen, den Wareneingang buchen. Das
Auswerten — Preisvergleich, Wareneinsatz, Simulation — liegt im [Controlling](controlling).
Zwei Tätigkeiten, zwei Orte.

---

## Bestellschienen

Eine Bestellung entsteht nicht als Formular, sondern als **Schiene je Lieferant**: ein offener
Entwurf, in den Bedarf hineinläuft, bis du ihn absendest.

Bedarf kommt aus drei Richtungen:

| Woher | Wie |
|-------|-----|
| **Produktion** | Aus dem Planungsblatt: „＋ Bedarf in Bestellschiene" rechnet die Rezepturen auf Gebinde herunter. |
| **Preisvergleich** | Aus dem Controlling: „→ Schiene" legt einen einzelnen Artikel dazu. |
| **von Hand** | Sonderbedarf direkt in der Bestellung erfassen. |

Gerechnet wird in **Gebinden**, nicht in Kilogramm — bestellt wird, was der Lieferant liefert.
Die Rundung passiert auf dem Gesamtbedarf je Grundprodukt, nicht je Quelle; sonst würde zweimal
aufgerundet, wenn derselbe Artikel aus Produktion und Handeingabe kommt.

Eine Ampel zeigt, ob die Mindestbestellmenge erreicht ist.

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
