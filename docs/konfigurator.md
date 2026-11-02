---
title: Konfigurator
order: 20
---

# Konfigurator: All-in-Preise als Kundenlink

Der Konfigurator rechnet **All-in-Preise pro Person** je Concept und Gästezahl: Speisen aus dem Concepter plus Personal,
Equipment und Logistik. Der Kunde bekommt einen Link, wählt Konzept, Tageszeit, Add-ons und Gästezahl, sieht den Preis
und schickt eine Anfrage. Die Anfrage wird ein normales Angebot.

Zu finden unter **Ausgabe → Konfiguratoren** und **Ausgabe → Leistungs-Vorlagen**. Spec: `docs/PLANUNG/94_Konfigurator_AllIn_Preise.md`.

## 1. Leistungs-Vorlagen pflegen

Personal, Equipment, Logistik und Sonstiges legst du einmal als Vorlage an und übernimmst sie in jeden Konfigurator.

| Feld | Bedeutung |
|---|---|
| Mengenregel | **fest** (immer n Stück) · **Schlüssel** (1 je n Gäste oder je n einer anderen Leistung, mit Mindest-/Höchstzahl und Reserve) · **Stufen** (Anzahl bis zu einer Gästezahl) |
| Greift ab/bis | Leistung nur in diesem Gästebereich, z. B. F&B-Manager ab 200 Gäste |
| Preis je | Stück/Einsatz oder Stunde. Stunden aus der Einsatzzeit des Konfigurators (plus Zuschlag) oder fest |
| Häufigkeit | je Event (LKW, Equipment) oder je Tageszeit (fällt bei Day into Night zweimal an) |
| EK-Satz | was dich die Leistung kostet, netto |
| VK | aus der **Leistungs-Aufschlagsklasse** (EK × Basissatz × Klassenfaktor) oder fest mit Begründung |

Leistungs-Aufschlagsklassen legst du unter **Einstellungen → Aufschlagsklassen** mit „Gilt für: Leistungen“ an. Sie
erscheinen nie in Concepter oder Darreichungen. Vorlagen des Oberteams sind im Unterteam lesbar und übernehmbar, ändern
kann sie nur das Oberteam.

## 2. Konfigurator anlegen

Der Editor ist aufgebaut wie der Angebots-Editor: oben die Kennzahlen (All-in ab, schwächster Deckungsbeitrag,
Warnungen, Link), darunter die Reiter.

1. **Übersicht:** Name, Titel für den Kunden, Ort, Gültigkeit, wer die Anfragen bekommt.
2. **Konzepte:** Concept suchen und als Hauptkonzept (Lunch oder Dinner) oder als Add-on verknüpfen. Ein Hauptkonzept kann
   ein Lunch- und ein Dinner-Concept haben. Der Speisenpreis kommt aus dem Concept.
3. **Leistungen:** Vorlagen übernehmen (Kopie) oder frei anlegen. Eine Zusatz-Leistung eines Konzepts kann eine
   Grundausstattung ersetzen, z. B. Tischservice 1:12 statt Buffetservice 1:30.
4. **Pax-Pakete:** Paketgrenzen festlegen (z. B. 100–199) oder **Paketgrenzen vorschlagen** lassen. **Pakete schnüren**
   rechnet die Mengen je Paket. In der Mengen-Matrix kannst du jede Zahl von Hand überschreiben, sie bleibt beim
   nächsten Schnüren erhalten.
5. **Kalkulation:** Einsatzzeiten, Kombi-Rabatt für Day into Night, Rundung, Toleranz. Darunter je Paket der Preis pro
   Person, EK, Deckungsbeitrag und die Kontrolle an Unter- und Obergrenze des Pakets.
6. **Branding und Präsentation:** Design wählen, Farben setzen oder von einem Foodbook, Angebot oder Betrieb übernehmen,
   Vorschau ansehen, veröffentlichen.

## 3. So wird gerechnet

- Preis pro Person im Paket = Speisen-VK + Summe der Leistungs-VK / Referenz-Pax (Standard: Mitte des Pakets).
- Am Rand des Pakets rechnet die Kalkulation die Mengen für genau diese Gästezahl nach. Weicht der Paketpreis mehr als
  die Toleranz ab oder wird der Deckungsbeitrag negativ, erscheint eine Warnung.
- Day into Night: Leistungen je Event einmal (mit den Day-into-Night-Stunden), Leistungen je Tageszeit für Lunch und
  Dinner getrennt; der Kombi-Rabatt gilt auf den Konzeptanteil.
- Add-ons: Speisenpreis plus eigene Zusatz-Leistungen, kein Anteil an der Grundausstattung.

## 4. Kundenlink und Anfragen

Der Link zeigt nur die fertigen Preise je Paket, nie EK, Klassen oder Mengen. Der Kunde stellt die Gästezahl ein, der
Preis kommt aus der beim Veröffentlichen eingefrorenen Tabelle. Änderungen erscheinen erst nach erneutem Veröffentlichen.

Eine Anfrage wird ein **Angebot mit Status Anfrage** (Auswahl, Gäste, Preis und Kontakt im Briefing). Die verantwortliche
Person bekommt eine Mail, im Team erscheint ein Signal. Das Signal schließt sich, sobald das Angebot den Status Anfrage
verlässt.

## 5. Designs

Unter **Einstellungen → Präsentations-Designs** gibt es die Ausgabeform „Konfigurator“ mit fünf Bausteinen (Konzepte,
Tageszeit, Add-ons, Preis und Gäste, Anfrage) und die Vorlage „Konfigurator“. Ein Design kann für Foodbook und
Konfigurator zugleich gelten. Fehlt einem Design ein Konfigurator-Baustein, ergänzt das System ihn beim Anzeigen.

## 6. MCP

Alle Schritte gehen auch per MCP (`foodalchemist.leistung_vorlagen.*`, `foodalchemist.konfiguratoren.*` mit
`SCHNUEREN`, `PAKETE_VORSCHLAGEN`, `PAKETE_VORSCHLAG_UEBERNEHMEN`, `PREISE`, `BRANDING`,
`foodalchemist.konfigurator_konzepte.*`, `konfigurator_leistungen.*` mit `AUS_VORLAGE` und `VORLAGE_ABGLEICH`,
`konfigurator_pakete.*`, `konfigurator_paket_mengen.PUT`, `konfigurator_presentation.GET/PUBLISH/WITHDRAW`).
Gerechnet wird immer im selben Service wie in der Oberfläche.

## 7. Beispiel DOEC

`php artisan foodalchemist:konfigurator-beispiel-doec --team=<id> --anlegen` legt Vorlagen nach den DOEC-Parametern,
den Konfigurator „Diva.licious 2026 (Beispiel DOEC)“ und die vier DOEC-Pakete an. Die Stundensätze sind Beispielwerte.
