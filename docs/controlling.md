---
title: Controlling
order: 7
---

# 📊 Controlling

Das Controlling-Zentrum beantwortet eine Frage: **Wie steht der Betrieb wirtschaftlich da — und
was kann ich daran ändern?** Der zweite Teil ist der wichtigere. Jede Zahl hier steht neben dem
Hebel, der sie bewegt.

Der Klick in der Seitenleiste öffnet direkt die Werkbank: ein Vollbild mit sieben Reitern.
Schließt du sie, bleibt darunter das Lagebild — sechs Kacheln, die zurück in den passenden Reiter
springen.

---

## Die zwei Wareneinsätze

Der wichtigste Unterschied im ganzen Modul, und der häufigste Anlass für Missverständnisse:

| | Woher | Was er sagt |
|---|---|---|
| **kalkulierter** Wareneinsatz | Rezeptur gegen Verkaufspreis | Was ein Gericht kosten *sollte*. Steht überall im Modul — an der Ampel, im Concepter, in der Kalkulation. |
| **gemessener** Wareneinsatz | Einkaufsjournal gegen Umsatz | Was tatsächlich passiert ist. Steht **nur** im Controlling, Reiter „Wareneinsatz". |

Beide heißen „Wareneinsatz" und sind verschiedene Dinge. Die Differenz ist der eigentliche
Erkenntnisgewinn — siehe unten.

---

## Die Reiter

### Lage
Der Überblick: Ø Wareneinsatz gegen Ziel, EK-Abdeckung (wie viele Gerichte überhaupt einen
Einkaufspreis tragen — ohne diese Zahl ist der Durchschnitt daneben nicht einzuordnen), Einkauf
der letzten 30 Tage, Break-even und die offenen Geld-Signale. Dazu der Portfolio-Benchmark gegen
die anderen Betriebe der Gruppe (anonym, nur Kennzahlen) und der Signal-Verlauf: nicht „wie viele
offene Befunde", sondern „wird es besser oder schlechter".

### Preise
Pro Grundprodukt der günstigste und der teuerste Lieferant, die Spanne dazwischen, optional
inklusive Rückvergütung. Die Spalte **Bezug** zeigt, woher tatsächlich bezogen wird — grün, wenn
das schon der Günstigste ist.

Zwei Aktionen: **→ Schiene** legt den Artikel in die Bestellung dieses Lieferanten.
**⇄ Bezug** stellt die Bezugsquelle dauerhaft um; der Einkaufspreis rechnet sich sofort durch
alle Rezepte, die dieses Grundprodukt verwenden.

Darunter die auffälligen Buchungen: Positionen, die aus ihrem eigenen Preistrend ausbrechen.
Das sind meist Fehlbuchungen, und sie verzerren alle Zahlen darüber. Korrigiert wird an der
Quelle — hier wird nur markiert.

### Wareneinsatz
Oben **Ist gegen Rezeptur**: was wurde eingekauft, was hätten die Rezepte für den verkauften
Absatz gebraucht, und wie weit liegt das auseinander. Ohne Inventur ist das eine
Perioden-Rechnung — wer am Monatsende das Lager füllt, sieht Schwund, der keiner ist. Deshalb ist
der Vormonat vorbelegt, und bei zu vielen offenen Verkaufszuordnungen verweigert die Fläche die
Aussage, statt eine plausible Zahl zu erfinden.

Darunter **Ist gegen optimalen Bezug**: was der gleiche Einkauf beim jeweils günstigsten
Lieferanten gekostet hätte. „Lieferant ausklammern" spielt Szenarien durch.

Aus der Liste heraus lassen sich mehrere Bezugsquellen **auf einmal** umstellen. Vorher zeigt
eine Vorschau, wie viele Rezepte und Gerichte davon betroffen sind — eine Umstellung verschiebt
den Einkaufspreis überall, wo das Grundprodukt steckt.

### Simulation
„Was, wenn?" — Warengruppe, Grundprodukt, Artikel oder ein **ganzer Lieferant** ± X Prozent.
Das Ergebnis: Marge-Delta übers Portfolio und die am stärksten betroffenen Gerichte. Rein
rechnerisch, es wird nichts verändert.

### Erfolg
Hier kommt die Verkaufsseite herein.

1. **Verkaufs-Ist einlesen.** CSV aus Kasse oder Abrechnung. Die Spalten ordnest du selbst zu —
   es gibt kein einheitliches Exportformat. Erst Trockenlauf, dann schreiben.
2. **Offene Zuordnungen.** Zeilen, die keinem Gericht zugeordnet werden konnten, bleiben mit
   ihrem Originaltext liegen und lassen sich von Hand verbinden. Sie werden nie verworfen —
   sonst verschwände Umsatz still aus der Auswertung. Eine Handzuordnung überlebt jeden weiteren
   Import.
3. **Menu-Engineering.** Jedes Gericht gegen den Portfolio-Durchschnitt:

   | | ertragreich | ertragsarm |
   |---|---|---|
   | **beliebt** | Star — halten, sichtbar platzieren | Renner — Kosten senken oder Preis prüfen |
   | **unbeliebt** | Schläfer — bewerben, besser platzieren | Penner — überarbeiten oder streichen |

   Solange kein Verkaufs-Ist eingelesen ist, läuft die Popularität ersatzweise über das
   Praxis-Feedback. Das ist Akzeptanz, nicht Absatz — die Fläche sagt es dazu.

4. **Verkaufspreise freigeben.** Intern wird der Preis laufend nachgerechnet; nach außen gilt nur
   der freigegebene Stand. Zwei Listen: Preise, die ihrer Freigabe davongelaufen sind, und
   solche, die noch nie freigegeben wurden. Ohne Freigabe ändert sich für den Kunden nichts.

### Geld-Signale
Die sechs wirtschaftlichen Befunde aus der Signal-Inbox — Preis-Anomalie, Preis-Sprung, veraltete
Preise, Marge unter Ziel, Wareneinsatz über Ziel, VK-Anpassung empfohlen. Bearbeitet werden sie
auf der Signale-Seite; hier stehen sie nur gefiltert, damit man sie nicht zwischen den
Datenqualitäts-Befunden suchen muss.

### Kennzahlen
Zielmarge, Ziel-Wareneinsatz, effektiver Zuschlag, Fixkosten, Break-even, Mehrwertsteuer. Die
zwei Zielwerte, gegen die hier ständig gemessen wird, lassen sich direkt setzen. Das vollständige
Zuschlagsschema bleibt in den [Einstellungen](einstellungen).

---

> **Was dieses Modul nicht kann:** kein Lager, keine Inventur, kein Wareneingang. Die
> Abweichung zwischen Einkauf und Rezeptur ist deshalb eine Rechnung über einen Zeitraum,
> kein gemessener Schwund. Je länger der Zeitraum, desto belastbarer.
