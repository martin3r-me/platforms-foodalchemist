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

Die Produktions-Hauptseite ist das **Küchenchef-Dashboard**: Sie steuert den Betrieb, nicht die
einzelne Kochanleitung. Im großen Hauptpanel sieht der Küchenchef wahlweise **3 / 7 / 14 / 30 Tage**,
oder einen gezielten **Von/Bis-Zeitraum per Kalender**. So kann man von heute, morgen, einem
Event-Start oder einem beliebigen Monatsfenster nach vorne schauen. Sichtbar sind Tageshorizont, Auslastung nach
Posten, Klärfälle, offene Speisen/Zeilen und „Als nächstes". Zusätzlich bündelt der Leitstand vier
operative Karten: **Planung in Manntagen**, **Change Log** für kurzfristige Änderungen,
**Produktion-Ampeln** für pünktlich/eng/kritisch und **Performance & Engpässe** je Posten. Die
Auftragsliste bleibt darunter als Such- und Arbeitsliste.

Der **Tagesordnung Editor** (Produktion → Tagesplanung Details) ist die Koch-Arbeitsansicht im
gleichen Editor-Duktus wie der Produktionsauftrag-Editor: Dark-Editor-Fläche, KPI-Kopf,
Von/Bis-Kalender, Posten- und Gerichtssicht. Er zeigt über *alle* Aufträge hinweg, was an welchem Tag
an welchem Posten ansteht — nach Posten filterbar, mit Auslastungsbalken je Tag.

Mit dem Umschalter **Posten / Gericht** wechselst du zwischen der Arbeitsplatz-Sicht und dem
Zusammenhang eines Auftrags mit seinen Basisrezepten. Ein Klick auf eine Position öffnet die beim
Rechnen eingefrorene Zutaten- und Schrittfolge. Dieselbe Anleitung steht auf dem Posten- oder
Gericht-Blatt.

Die Klärfälle-Leiste macht unzugeteilte Positionen, fehlende Arbeitszeiten oder Anleitungen,
ungeprüftes Material, Überlast, Blocker und überfällige Arbeit sichtbar. Blocker sperren den
Produktionsstart; Warnungen erlauben den Start nur mit dokumentiertem Override-Grund.

Der **Wandmonitor** ist bewusst ein Tagesmonitor: Beim Wechsel in den Wandmodus springt das Fenster
auf einen Tag. Statt der Desktop-Tabelle zeigt er große Touch-Karten in Lanes nach Posten, oben die
kritischen Zahlen und Klärfälle, auf jeder Karte Start/Erledigt/Blocker/Entblocken/Anleitung und
unten die letzten Produktionsereignisse. Der Editor wird im Wandmodus nicht geladen; geplant wird
weiter in der normalen Tagesplan- oder Auftragsansicht. Für Küchenbildschirme gibt es einen eigenen
Link: `/foodalchemist/produktion/wandmonitor`.

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

Eine laufende Position kann zusätzlich **gestartet**, mit einem **Blocker** versehen oder mit einem
Pflichtgrund **übersprungen** werden. Blocker können direkt im Cockpit wieder gelöst werden.
Statuswechsel, Blocker, Overrides und Abschlussnotizen landen gemeinsam mit der Änderung in einem
append-only Produktionsprotokoll. Start- und Endzeitpunkte dienen der Nachvollziehbarkeit, nicht der
Messung einzelner Personen oder einer vermeintlich exakten Ist-Arbeitszeit.

Der Fortschritt („7/12 erledigt") wird jedes Mal frisch aus den Zeilen gerechnet. Der
**Auftragsstatus springt nie von allein weiter**: „Fertig melden" bleibt deine Entscheidung. Meldest
du fertig, während noch Zeilen offen oder blockiert sind, verlangt das Modul eine Abschlussnotiz —
und lässt die offenen Zeilen offen stehen, statt sie stillschweigend abzuhaken. Parallele
Statusänderungen werden über `updated_at` geschützt; ist ein Zettel im Browser veraltet, muss neu
geladen werden.

Das digitale Cockpit hat einen schlanken Kill-Switch:
`FOODALCHEMIST_PRODUCTION_COCKPIT=false` sperrt Tagesplan und Druckroute, ohne Produktionsdaten zu
ändern. Der versionierte Ausdruck bleibt der Rückfall bei Geräte- oder Netzausfall.

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
