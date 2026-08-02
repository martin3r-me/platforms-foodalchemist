---
title: Portfolio
order: 7
---

# 🗂️ Portfolio

Das Modul hat drei Ausgabeformen: [Foodbook](foodbook) (Catering), [Speisekarte](speisekarte)
(Gastronomie), [Speiseplan](speiseplan) (Gemeinschaftsverpflegung). Zusammen sind sie das
**Portfolio** eines Betriebs.

Dieses Kapitel beantwortet die einfachste Frage der Mehrbetriebs-Steuerung, für die es bis dahin
keine Antwort gab: **wer fährt gerade was — an welchem Standort und für welchen Kunden?**

> **Beobachtet wird im [Controlling](controlling), Reiter „Portfolio". Geschaltet wird an der
> Ausgabe selbst.** Dieselbe Trennung wie zwischen Einkauf (handeln) und Controlling (auswerten).

---

## „Läuft" ist nichts, was man ankreuzt

Es gibt **ein** Statusfeld mit vier Werten — für alle drei Formen dasselbe:

| Status | Bedeutung |
|---|---|
| **Entwurf** | in Arbeit, war nie draußen |
| **Aktiv** | läuft |
| **Inaktiv** | war draußen, ist bewusst vom Netz — pausiert, nicht abgeschlossen |
| **Archiviert** | abgeschlossen, aus dem Portfolio raus |

**Versenden bzw. Veröffentlichen setzt auf Aktiv.** Es gibt keinen Zustand daneben und keinen
zweiten Klick.

Ob etwas *läuft*, leitet das System ab — aus **Status Aktiv UND Datum im Gültigkeitsfenster**:

```
läuft am Stichtag  =  Status ist Aktiv
                      und (kein Von-Datum  oder  Von ≤ Stichtag)
                      und (kein Bis-Datum  oder  Stichtag ≤ Bis)
```

Ein offenes Fenster heißt unbefristet. Beim Speiseplan wird das Fenster **nicht extra gepflegt**,
sondern aus dem ersten und letzten Eintrag abgeleitet — sonst gäbe es zwei Wahrheiten, die
auseinanderdriften.

### Warum es zwei Bremsen gibt

Weil Versenden auf Aktiv setzt und nichts von selbst abläuft, stünden nach zwei Saisons fünf
„laufende" Karten je Standort. Dagegen zwei Mittel, beide ohne Automatik:

- **Das Gültigkeitsfenster** bremst von selbst: abgelaufenes Bis-Datum heißt „läuft nicht", auch
  bei Status Aktiv. Die Übersicht führt das als *abgelaufen, noch nicht archiviert*.
- **Inaktiv** ist der bewusste Griff: etwas kurzfristig vom Netz nehmen, ohne es abzuschließen.

Der Status wechselt dabei **nie von selbst**. Das Fenster ändert nur die Anzeige, nicht die
Daten.

---

## Zwei Achsen, beide freiwillig

Jede Ausgabe kann an einem **Betrieb** hängen und/oder für einen **Kunden** gemacht sein —
beides an allen drei Formen, beides optional:

- Ein Foodbook für einen Kunden, aber auch eines, das an einem Standort hängt.
- Eine Speisekarte im eigenen Restaurant, aber auch eine für einen Kunden (Betreibermodell: der
  Betrieb führt die Kantine eines Kunden).
- Ein Speiseplan für den eigenen Standort, für einen Kunden, oder für beides.

Betriebe pflegst du unter [Einstellungen → Betriebe](einstellungen). Sie gehören dem Team, das
sie anlegt — ein Betrieb eines anderen Teams ist nicht zuweisbar.

Eine Ausgabe **ohne beides** bleibt erlaubt. Sie landet im Block „ohne Zuordnung", damit sie
nicht still verschwindet.

---

## Was die Übersicht zeigt

Im Controlling-Reiter „Portfolio": eine Matrix aus **Zeilenachse × Ausgabeform**, mit einem
Stichtags-Regler (Standard: heute). Der Regler beantwortet auch die Planungsfrage — „was läuft im
September?" ist derselbe Handgriff wie „was läuft heute?".

Umschalten lässt sich die **Brille**: Betrieb oder Kunde. Dieselbe Matrix, andere Zeilenachse —
nicht zwei getrennte Flächen, sonst driften sie auseinander.

| Befund | Was er heißt |
|---|---|
| leere Zelle | An diesem Standort läuft diese Ausgabeform gerade nicht. |
| zwei Einträge | Parallellauf. **Hinweis, kein Fehler** — Übergangsphase oder Sonderkarte kann gewollt sein. |
| Block „ohne Zuordnung" | Weder Betrieb noch Kunde. In keiner Brille sichtbar, deshalb eigener Block. |
| „abgelaufen, noch nicht archiviert" | Status Aktiv, Fenster vorbei. Ein Klick archiviert. |

Beim Aktivsetzen weist die Ausgabe darauf hin, wenn dadurch ein Parallellauf entsteht — sie
verhindert ihn nicht.

---

## Was die laufenden Ausgaben bringen

Im Reiter „Erfolg", direkt über dem Menu-Engineering: Umsatz je laufender Ausgabe, jeweils im
eigenen Gültigkeitsfenster, aus dem eingelesenen [Verkaufs-Ist](controlling).

Zwei Dinge musst du beim Lesen wissen:

1. **Die Umsatzspalte ist nicht summierbar.** Steht ein Gericht in zwei laufenden Ausgaben, zählt
   sein Umsatz bei beiden. Die Summe übersteigt deshalb den Gesamtumsatz — das ist kein
   Rechenfehler, sondern die Natur der Frage. Für eine Summe die Spalte **„davon exklusiv"**
   nehmen.
2. **Was keinem Gericht zugeordnet ist, ist auch keiner Ausgabe zurechenbar.** Die Abdeckung
   steht daneben; unter 80 % sagt die Fläche das ausdrücklich.

Ohne eingelesenes Verkaufs-Ist bleibt die Liste leer. Sie zeigt dann keine Nullen — die wären
eine Aussage, die die Daten nicht hergeben.

---

> **Was das Portfolio nicht ist:** kein Bau-Fortschritt. Ob ein Foodbook fertig *gebaut* ist,
> steht in seinen Phasen (Kontext → Struktur → Befüllung → Kalkulation → Freigabe). Ob es
> *läuft*, steht hier. Zwei verschiedene Fragen, zwei getrennte Felder.
