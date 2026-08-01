---
title: Produktion
order: 8
---

# 🍲 Produktion

Die Produktion beantwortet die Frage, die nach dem Angebot kommt: **was muss die Küche wann und wo
wirklich kochen?** Aus dem, was verkauft ist — ein Foodbook-Kapitel, ein Angebot, ein Concept, ein
einzelnes Gericht — wird ein Produktionsauftrag: eine Liste aus Rezepten, Ansätzen und Arbeitszeit,
verteilt auf Posten und Tage, abhakbar in der Küche.

---

## Vom Ziel zum Zettel

Ein Auftrag beginnt mit **Zielen** — nicht mit Rezepten. Du sagst „Kapitel *Buffet Herbst* für 120
Personen" oder „Brauner Fond, 6 kg", das Modul rechnet den Rest:

```
Ziele  →  Explosion  →  Zeilen  →  Zuteilung  →  Küche hakt ab
```

Die **Explosion** löst jedes Ziel bis auf seine Basisrezepte auf und fasst zusammen: Braucht dein
Kapitel dreimal dieselbe Sauce, entsteht **eine** Zeile mit dem Gesamtbedarf, nicht drei. Aus dem
Bedarf werden **ganze Ansätze** — eine halbe Ansatzmenge Fond kocht niemand.

> **Warum jede Änderung alles neu rechnet:** Weil aufgerundete Ansätze sich nicht addieren lassen
> (2× „ein halber Ansatz" ist ein Ansatz, nicht zwei), muss das Modul bei jeder Ziel-Änderung die
> ganze Rechnung neu aufstellen. Was du an den Zeilen von Hand gepflegt hast, überlebt das
> trotzdem — siehe unten.

**Puffer:** Über die Überproduktions-Prozente skalierst du alles auf einmal — mehr Ansätze *und*
mehr Einkauf. 10 % Puffer sind der Unterschied zwischen „reicht genau" und „reicht".

---

## Die Zeilen sind dein Arbeitsdokument

Jede Zeile ist anfassbar, solange der Auftrag **geplant** ist:

| Was | Wozu |
|---|---|
| **Ansätze überschreiben** | „Wir machen 2, nicht 3 — von gestern ist noch was da." Der berechnete Wert bleibt daneben stehen (*„berechnet wären 3 · zurücksetzen"*). |
| **Zeile streichen** | Fällt aus allen Summen und vom Zettel, bleibt aber sichtbar und wiederherstellbar. Gestrichen ≠ gelöscht: die Explosion würde sie sonst beim nächsten Rechnen sofort zurückbringen. |
| **Freie Position** | Was kein Rezept hat, aber trotzdem jemand tun muss: „Brot abholen", „Eismaschine vorkühlen". Steht neben der Rechnung und wird von ihr nie angefasst. |
| **Notiz** | Die Küchen-Notiz an der Zeile — landet auf dem Produktionszettel. |

> **Der Ansätze-Override ist eine Küchen-Korrektur, kein Bedarfs-Eingriff.** Er ändert den Einkauf
> **nicht**. Wer wirklich mehr Ware braucht, legt ein **Ziel** an — sonst bestellt niemand nach.

---

## Posten, Verantwortliche, Vorproduktion

**Posten** sind Arbeitsplätze: Patisserie, Garde-Manger, Heiße Küche. Du legst sie einmal unter
**Einstellungen → Posten & Kapazität** an und gibst ihnen — wenn du magst — eine **Kapazität in
Minuten pro Tag**, wahlweise je Wochentag abweichend.

Jede Zeile kann einem Posten zugeteilt werden, einen **Verantwortlichen** (freier Name) bekommen und
einen **Vorlauf in Tagen** vor dem Liefertag. Der Vorlauf ist ein Abstand, kein Datum: Verschiebt
sich das Event von Freitag auf Samstag, wandert der ganze Vorproduktions-Plan automatisch mit.

Der **Tagesplan** (Produktion → Tagesplan) zeigt über *alle* Aufträge hinweg, was an welchem Tag an
welchem Posten ansteht — nach Posten filterbar, mit Auslastungsbalken je Tag.

> **Die Auslastung ist opt-in.** Ein Posten ohne hinterlegte Kapazität warnt nie. Wo Kapazität
> gepflegt ist, zeigt der Balken bis 85 % grün, bis 100 % eng, darüber Überlast — als Hinweis, nicht
> als Sperre.

> **Die Zahlen sind nur so gut wie die Arbeitszeit an den Rezepten.** Fehlt sie, sagt das Modul das
> dazu („340 min · 6 Zeilen ohne Arbeitszeit") statt eine halbe Datenlage als Wahrheit zu verkaufen.
> Unverplante Arbeit erscheint als eigener Block „Nicht zugeteilt" — sie zählt gegen keine Kapazität,
> verschwindet aber auch nicht.

**Was hier bewusst nicht passiert:** kein Schichtplan, keine Verfügbarkeiten, keine
Personalstammdaten. Geplant werden Posten, nicht Menschen. Der Verantwortliche ist ein Etikett.

---

## Der Lebenszyklus

| Status | Was geht |
|---|---|
| **Geplant** | Alles: Ziele, Zeilen, Overrides, Streichen, Zuteilung. Löschen ist möglich. |
| **In Arbeit** | Die Rechnung ist eingefroren. Zuteilung bleibt änderbar (die Realität besetzt um), und **jetzt** wird abgehakt. |
| **Fertig** / **Storniert** | Nur noch lesen. |

**Abhaken** heißt genau das: ein Haken an der Zeile, ein Zeitstempel, fertig. Bewusst **keine
Ist-Mengen** — ein halb gepflegtes Zahlenfeld, dem später niemand traut, ist schlechter als keins.
Ein zweiter Klick nimmt den Haken zurück.

Der Fortschritt („7/12 erledigt") wird jedes Mal frisch aus den Zeilen gerechnet. Der
**Auftragsstatus springt nie von allein weiter**: „Fertig melden" bleibt deine Entscheidung. Meldest
du fertig, während noch Zeilen offen sind, fragt das Modul nach — und lässt die offenen Zeilen offen
stehen, statt sie stillschweigend abzuhaken.

---

## Was rausgeht

- **Produktionsblatt** — die Zeilen mit Ansätzen, Mengen und Zutaten.
- **Produktionsauftrag** — der Zettel für die Küche, wahlweise mit oder ohne Fotos.
- **Anleitung** — die Schritt-für-Schritt-Zubereitung je Rezept, ebenfalls mit Foto-Schalter.
- **Übergabe an den Einkauf** — der Bedarf wandert in Bestellschienen je Lieferant. Änderst du den
  Auftrag danach, sagt dir das Panel, dass die Übergabe veraltet ist.

---

> **Kein Lager, keine Bestände.** Die Produktion rechnet Bedarf, sie verwaltet keinen Bestand.
> „Wir haben noch 3 kg im Kühlhaus" gehört in den Ansätze-Override oder in ein gestrichenes Ziel —
> nicht in eine Bestandsführung, die es hier bewusst nicht gibt.
