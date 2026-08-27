# Kalkulation im Food Alchemist

## Anleitung und Nachschlagewerk für Katalogpreise, Herstellkosten und Angebote

Stand: 26. August 2026

Dieses Dokument erklärt die Kalkulation im Food Alchemist vollständig aus Anwendersicht. Es beschreibt, welche Daten an welcher Stelle gepflegt werden, wie der automatische Verkaufspreis entsteht und weshalb die Kalkulation zwischen einem allgemeinen Katalogpreis und einem konkreten Auftrag mit Personenzahl unterscheidet.

---

## 1. Das wichtigste Prinzip

Im Food Alchemist gibt es zwei getrennte Rechenkreisläufe:

```text
Katalog ohne Pax:
MEK je Darreichung
→ dynamischer Unternehmens-Basissatz
→ relativer Klassenfaktor
→ Auto-Vorschlag
→ gültiger Katalog-VK

Auftrag mit Pax:
Concept-Mengen × Pax
→ Rezeptbedarf, Ansätze, Produktionsvorgänge und Zeiten
→ MEK, Lohn und Gemeinkosten
→ auftragsspezifischer HK2
→ Mindestpreis und Zielpreis
```

Diese Trennung ist bewusst:

- Ein Gericht im Katalog muss einen Preis haben, obwohl noch keine Gästezahl bekannt ist.
- Erst bei einem konkreten Angebot ist bekannt, ob für 20, 100 oder 5.000 Personen produziert wird.
- Rüstzeiten, Topfgrößen, Batches und Postenbelastung können deshalb erst im Auftrag realistisch bewertet werden.
- Der Katalogpreis bleibt bei unterschiedlichen Pax identisch.
- Die tatsächlichen Produktionskosten dürfen sich mit der Auftragsgröße verändern.

Der Auto-Modus ist keine starre Formel wie `Einkaufspreis × 5,20`. Er berechnet einen nachvollziehbaren Vorschlag aus der aktuellen Kostenstruktur des Betriebs.

---

## 2. Begriffe und Abkürzungen

| Begriff | Bedeutung |
|---|---|
| MEK | Materialeinzelkosten beziehungsweise Wareneinsatz. Das sind die direkten Rohwarenkosten. |
| MGK | Materialgemeinkosten, zum Beispiel Einkauf, Lager und Warenannahme. |
| FEK | Fertigungseinzelkosten. Im Auftrag sind das die berechneten Produktionslöhne. |
| FGK | Fertigungsgemeinkosten, zum Beispiel Produktionsmiete, Energie, Spüle und Maschinen. |
| Direkte Kosten | Direkt zurechenbare Kosten wie Verpackung oder rezeptbezogene Zusatzkosten. |
| HK | Herstellkosten vor Verwaltung und Logistik. |
| HK2 | Vollkosten beziehungsweise Selbstkosten nach Verwaltung, Vertrieb und Logistik. |
| VK netto | Verkaufspreis ohne Mehrwertsteuer. |
| VK brutto | Verkaufspreis einschließlich Mehrwertsteuer. |
| Deckungsbeitrag | Verkaufspreis minus HK2. |
| Wareneinsatzquote | MEK geteilt durch Verkaufspreis. |
| Gewinnaufschlag | Zuschlag auf HK2. Das ist nicht dasselbe wie die Umsatzmarge. |
| Preisklasse | Relative Abweichung eines Produkttyps vom Unternehmens-Basissatz. |
| Darreichung | Die tatsächlich verkaufte Form eines Gerichts, zum Beispiel Portion, Glas oder Stück. |
| Pax | Anzahl der Personen beziehungsweise Gäste eines Auftrags. |

### Gewinnaufschlag und Umsatzmarge

Ein Gewinnaufschlag von 15 % auf HK2 bedeutet:

```text
Zielpreis = HK2 × 1,15
```

Die daraus entstehende Umsatzmarge beträgt nicht 15 %, sondern:

```text
Umsatzmarge = (Zielpreis - HK2) / Zielpreis
             = 15 / 115
             = 13,04 %
```

Das System hält diese Begriffe getrennt, damit Aufschlag und Marge nicht verwechselt werden.

---

## 3. Empfohlene Einrichtungsreihenfolge

Für nachvollziehbare Ergebnisse sollten die Stammdaten in dieser Reihenfolge gepflegt werden:

1. Mehrwertsteuer und Rundung
2. Team-Stundensatz sowie optional Rollen und Posten
3. Herstellkostenblöcke und Fixkosten
4. Monatliche Bezugsbasen
5. Preisklassen
6. Rezept-Yield, Zutatenpreise und Darreichungen
7. Produktionszeiten und Batchgrenzen
8. Pakete und Concepts
9. Pax-Simulation und Angebote

Die Kalkulation funktioniert bereits bei unvollständigen Daten mit sichtbaren Fallbacks. Ein Fallback ist aber eine Hilfsrechnung und sollte nicht mit einer vollständig gepflegten Kostenstruktur verwechselt werden.

---

## 4. Einstellungen: Mehrwertsteuer und Rundung

Pfad:

```text
Food Alchemist → Einstellungen → Kalkulation
```

### Mehrwertsteuer

Zentral gepflegt werden:

- regulärer Steuersatz
- ermäßigter Steuersatz
- Standardprofil des Teams

An Gerichten und Darreichungen wird kein freier Prozentsatz mehr eingegeben. Dort wird nur ein Profil gewählt:

- `regulär`
- `ermäßigt`
- Team-Default

Auflösungsreihenfolge:

```text
Darreichungsprofil
→ Preisklassenprofil
→ Team-Default
```

Ändert sich ein gesetzlicher Steuersatz, wird er zentral angepasst. Aktuelle automatische Preise verwenden anschließend den neuen Satz. Historische Dokumente und abgeschlossene Angebote bleiben Snapshots.

### Rundung

Das Team legt Nachkommastellen und Rundungsmodus fest:

- kaufmännisch
- aufrunden
- abrunden

Eine Preisklasse kann die Team-Rundung überschreiben. Fehlt eine Klassenregel, gilt die Team-Regel.

---

## 5. Einstellungen: Herstellkosten und Zuschläge

Pfad:

```text
Food Alchemist → Einstellungen → Herstellkosten & Zuschläge
```

Die Seite besteht aus drei fachlichen Bereichen:

1. mehrstufige Zuschlagskalkulation
2. globale Ziele und Lohnquelle
3. Fixkosten und monatliche Bezugsbasen

### 5.1 Kostenblöcke

Die Standardblöcke sind:

| Block | Übliche Basis | Wirkung |
|---|---|---|
| Lohn / Produktion | €/h | Stundensatz für die Produktionsarbeit |
| Verpackung | €/Portion | direkte Kosten je Person oder Einheit |
| Schwund | % auf MEK | Zuschlag auf Wareneinsatz |
| Material-Gemeinkosten | % auf MEK | Einkauf, Lager, Warenannahme |
| Fertigungs-Gemeinkosten | % auf FEK | Spüle, Energie, Maschinen, Produktionsumfeld |
| Verwaltung & Vertrieb | % auf HK | Verwaltung, Software, Versicherungen, Vertrieb |
| Logistik | % auf HK | Fahrzeuge, Touren und nicht direkt zurechenbare Logistik |

Jeder Block hat:

- Aktivstatus
- Berechnungsbasis
- Modus
- Satz oder Wert

### 5.2 Modus „automatisch“

Bei einem automatischen Gemeinkostenblock wird der Prozentsatz aus Fixkosten und Bezugsbasis abgeleitet:

```text
Zuschlag in % = Fixkosten des Blocks pro Monat
                / passende monatliche Bezugsbasis
                × 100
```

Beispiel:

```text
Fertigungs-Gemeinkosten: 8.700 € pro Monat
Fertigungslohn-Basis:    24.000 € pro Monat

FGK-Satz = 8.700 / 24.000 × 100 = 36,25 %
```

### 5.3 Modus „manuell“

Ein manueller Prozentsatz ist eine Ausnahme. Er kann eingesetzt werden, wenn noch keine belastbare Monatsbasis vorhanden ist oder ein bewusst festgelegter Kalkulationssatz gelten soll.

Manuell bedeutet nicht genauer. Sobald belastbare Unternehmensdaten vorliegen, sollte geprüft werden, ob der automatische Modus sinnvoller ist.

### 5.4 Marge

Das Feld „Marge (→ VK-Vorschlag)“ ist fachlich ein Gewinnaufschlag auf HK2:

```text
Zielpreis = HK2 × (1 + Gewinnaufschlag / 100)
```

Dieser Wert wirkt:

- im dynamischen Unternehmens-Basissatz
- im Zielpreis eines Auftrags
- nicht als zusätzlicher Aufschlag auf einen bereits fertigen Verkaufspreis

### 5.5 Ziel-Wareneinsatzquote

Die Ziel-Wareneinsatzquote hat zwei Aufgaben:

1. Sie ist das Food-Cost-Ziel für Ampeln und Wirtschaftlichkeitssignale.
2. Sie liefert den Fallback-Basissatz, wenn Monatsbasen fehlen.

```text
Fallback-Basissatz = 100 / Ziel-Wareneinsatzquote
```

Beispiel bei 30 %:

```text
100 / 30 = 3,333
```

Ein Gericht mit 2,00 € MEK erhält bei neutraler Preisklasse zunächst:

```text
2,00 € × 3,333 = 6,67 € VK netto
```

Die Oberfläche kennzeichnet diese Quelle sichtbar als „aus Ziel-Wareneinsatz“.

### 5.6 Lohnnebenkosten

Der Lohnnebenkosten-Zuschlag wird genau einmal auf die aufgelösten Produktionslöhne angewendet:

```text
FEK einschließlich Lohnnebenkosten
= berechneter Produktionslohn
  × (1 + Lohnnebenkosten-Zuschlag / 100)
```

Der Rollen- oder Team-Stundensatz sollte deshalb eindeutig definiert sein: entweder bereits als vollständiger Arbeitgeber-Kostensatz oder als Ausgangssatz zuzüglich des hier gepflegten Zuschlags. Doppeltes Einrechnen muss vermieden werden.

### 5.7 Lohnquelle im Auftrag

Es gibt zwei Modi:

#### Team-Stundensatz

Alle aktiven Personenminuten werden mit dem globalen Produktionsstundensatz bewertet.

Dieser Modus ist sinnvoll, wenn:

- Rollen noch nicht vollständig gepflegt sind
- eine einfache Durchschnittskalkulation gewünscht ist
- Posten noch keine belastbare Besetzung besitzen

#### Rollen des Postens

Der Stundensatz wird aus der Rollenbesetzung des Standardpostens abgeleitet:

```text
Posten-Stundensatz
= Summe(Rollenanzahl × Rollenstundensatz)
  / Anzahl besetzter Personen
```

Beispiel:

```text
1 Küchenchef × 50 €/h
2 Köche       × 35 €/h
1 Küchenhilfe × 20 €/h

Posten-Stundensatz = (50 + 70 + 20) / 4 = 35 €/h
```

Fehlt ein Posten oder eine Rollenbesetzung, verwendet das System sichtbar den Team-Stundensatz als Fallback.

---

## 6. Fixkosten und Bezugsbasen

Die Fixkosten stehen auf derselben Seite unter:

```text
Fixkosten (Gemeinkosten) → abgeleitete Sätze
```

### 6.1 Bezugsbasen

Die drei Felder sind erwartete Monatswerte:

| Feld | Bedeutung |
|---|---|
| Ø Wareneinsatz / Monat | Einkaufswert der monatlich verarbeiteten Ware |
| Ø Fertigungslohn / Monat | Summe der Küchen- und Produktionslöhne |
| Ø Herstellkosten / Monat | MEK + FEK + direkte Produktionskosten vor Verwaltung und Logistik |

Empfohlene Quelle:

- Durchschnitt der letzten drei repräsentativen Monate oder
- bestätigter Planwert bei neuen Betrieben

### 6.2 Was bedeutet eine Basis von 0?

Eine positive Fixkostensumme mit Bezugsbasis `0` ergibt absichtlich keinen erfundenen Satz:

```text
Fixkosten > 0 und Bezugsbasis = 0
→ Zuschlag bleibt 0 %
→ Warnung „Bezugsbasis fehlt“
```

Deshalb können 11.070 € Fixkosten erfasst sein und die Kalkulation trotzdem `0,00 %` anzeigen. Erst die passende Bezugsbasis macht aus der Fixkostensumme einen verwendbaren Zuschlagssatz.

### 6.3 Catering-Beispielwerte

Für einen leeren Fixkostenbereich steht „Catering-Beispiel rechnen“ zur Verfügung. Der Beispielsatz enthält editierbare Kostenarten:

- Produktionsmiete und Nebenkosten
- Energie, Wasser und Spüle
- Reinigung und Entsorgung
- Einkauf, Lager und Warenannahme
- Verwaltung, Software und Versicherungen
- Vertrieb und Marketing
- Fahrzeuge und Logistik

Die hinterlegten Beispielbasen sind:

```text
MEK-Monat: 30.000 €
FEK-Monat: 24.000 €
HK-Monat:  60.000 €
```

Die Werte dienen nur dazu, die Kalkulation vollständig durchzurechnen. Sie sind keine Branchen-Norm und müssen anschließend auf den eigenen Betrieb angepasst werden.

Sind bereits Fixkosten vorhanden, werden Beispielwerte nicht dazugemischt. Dadurch entstehen keine stillen Doppelansätze.

### 6.4 Zuordnung der Fixkosten

Jede Fixkostenzeile muss dem fachlich richtigen Block zugeordnet werden:

| Kostenart | Empfohlener Block |
|---|---|
| Einkauf, Lager, Warenannahme | Material-Gemeinkosten |
| Produktionsmiete, Energie, Spüle, Maschinen | Fertigungs-Gemeinkosten |
| Verwaltung, Software, Versicherungen, Vertrieb | Verwaltung & Vertrieb |
| Fahrzeuge und allgemeine Tourkosten | Logistik |

Direkt auftragsbezogene Kosten gehören nicht pauschal in Fixkosten. Eine nur für einen Auftrag benötigte Sonderverpackung ist eine direkte Auftragskostenposition.

---

## 7. Dynamischer Unternehmens-Basissatz

Der Basissatz beantwortet die Frage:

> Welcher Netto-Verkaufspreisfaktor ist für einen Euro Wareneinsatz nötig, wenn die aktuelle Kostenstruktur und der Gewinnaufschlag berücksichtigt werden?

### 7.1 Berechnung aus vollständigen Monatsbasen

```text
FEK-Verhältnis
= FEK-Monat / MEK-Monat

Direkt-Verhältnis
= max(0, (HK-Monat - MEK-Monat - FEK-Monat) / MEK-Monat)

Norm-MEK    = 1,00
Norm-FEK    = FEK-Verhältnis
Norm-Direkt = Direkt-Verhältnis

Norm-MGK
= Norm-MEK × Summe der Zuschläge auf MEK

Norm-FGK
= Norm-FEK × Summe der Zuschläge auf FEK

Norm-HK
= Norm-MEK + Norm-FEK + Norm-Direkt + Norm-MGK + Norm-FGK

Norm-HK2
= Norm-HK × (1 + Summe der Zuschläge auf HK)

Basissatz
= Norm-HK2 × (1 + Gewinnaufschlag / 100)
```

### 7.2 Beispiel mit Catering-Beispielwerten

Monatsbasen:

```text
MEK 30.000 €
FEK 24.000 €
HK  60.000 €
```

Beispiel-Fixkosten:

```text
MGK:  1.200 €
FGK:  8.700 €
HK-Zuschläge Verwaltung + Logistik: 5.100 €
Gewinnaufschlag: 15 %
```

Daraus folgen ungefähr:

```text
FEK-Verhältnis:     0,800
Direkt-Verhältnis:  0,200
MGK normiert:       0,040
FGK normiert:       0,290
Norm-HK:            2,330
Norm-HK2:           2,528
Basissatz:          2,907
```

Der Basissatz wird unter „Einstellungen → Preisklassen“ angezeigt und mit der Quelle „aus Kostenstruktur“ gekennzeichnet.

### 7.3 Fallback

Fehlt mindestens eine belastbare Monatsbasis, verwendet das System:

```text
100 / Ziel-Wareneinsatzquote
```

Die Quelle wird als „aus Ziel-Wareneinsatz“ gekennzeichnet. Fehlt zusätzlich eine gültige Zielquote, entsteht kein Nullpreis, sondern ein unvollständiger Vorschlag mit Warnung.

---

## 8. Preisklassen

Pfad:

```text
Food Alchemist → Einstellungen → Preisklassen
```

Preisklassen ersetzen die früheren harten Aufschlagsklassen.

Eine Preisklasse enthält:

- Code
- Bezeichnung
- Klassenfaktor in Prozent
- MwSt-Profil
- optionale Rundungsabweichung
- Aktivstatus

### 8.1 Bedeutung des Klassenfaktors

Der Klassenfaktor ist relativ zum Unternehmens-Basissatz:

| Faktor | Bedeutung |
|---:|---|
| 100 % | neutral, unveränderter Unternehmens-Basissatz |
| 90 % | 10 % unter dem Basissatz |
| 110 % | 10 % über dem Basissatz |
| 125 % | 25 % über dem Basissatz |

Er ist kein vollständiger EK-Aufschlag.

### 8.2 Preisformel

```text
Auto-VK netto ungerundet
= MEK der Darreichung
  × Unternehmens-Basissatz
  × Klassenfaktor / 100
```

Beispiel:

```text
MEK der Darreichung: 2,00 €
Basissatz:            2,907
Klassenfaktor:        120 %

Auto-VK netto
= 2,00 × 2,907 × 1,20
= 6,98 € nach Rundung
```

### 8.3 Gesamtfaktor

Die Preisklassen-Seite zeigt zusätzlich den Gesamtfaktor:

```text
Gesamtfaktor = Basissatz × Klassenfaktor / 100
```

Dadurch ist sichtbar, wie stark ein Produktpreis insgesamt auf seinen MEK reagiert, ohne dass der Klassenfaktor selbst zu einem starren Gesamtaufschlag wird.

---

## 9. Gericht und Darreichung als Preis-Wahrheit

Pfad:

```text
Food Alchemist → Gerichte → Gericht öffnen → Kalkulation
```

Die Darreichung ist die Preis-Wahrheit des Gerichts. Ein Rezept kann unterschiedlich verkauft werden, zum Beispiel:

- Portion
- Stück
- Glas
- Schale
- Kilogramm

Für jede Darreichung werden aufgelöst:

- MEK der Darreichung
- Preisklasse
- Klassenfaktor
- Unternehmens-Basissatz
- Auto-Vorschlag netto
- gültiger VK netto
- MwSt-Profil und aktueller Satz
- VK brutto
- Preisquelle und Warnungen

### 9.1 Warum Darreichungs-MEK statt Rezept-Gesamt-EK?

Der Verkaufspreis muss sich auf die tatsächlich verkaufte Einheit beziehen.

Beispiel:

```text
Rezept-EK gesamt: 20 €
Rezept ergibt:     10 Portionen

MEK je Portion:   2 €
```

Eine Darreichung mit eigener Grammatur oder einem Delta kann davon abweichen.

### 9.2 Kein manueller VK nötig

Im Auto-Modus genügt:

- vollständiger Darreichungs-MEK
- gültiger Basissatz oder Fallback
- optional eine Preisklasse

Fehlt eine Preisklasse, rechnet das System neutral mit `100 %` und zeigt einen Hinweis. Ein leeres manuelles VK-Feld darf die Weitergabe an Paket oder Concept nicht mehr blockieren.

---

## 10. Preiszustände Auto und Fixiert

Jede bepreiste Ebene trennt zwei Werte:

- Auto-Vorschlag: aktuell berechneter Vergleichspreis
- gültiger VK: tatsächlich verwendeter Verkaufspreis

### 10.1 Auto

```text
Auto-Vorschlag wird neu berechnet
→ gültiger VK folgt automatisch
```

Ändern sich Zutatenpreis, Darreichung, Basissatz, Preisklasse, MwSt oder Rundung, wird der Preis neu berechnet.

### 10.2 Fixiert

```text
Auto-Vorschlag wird weiter berechnet
→ gültiger VK bleibt unverändert
→ Abweichung wird sichtbar
```

Ein Fixpreis benötigt:

- Begründung
- Benutzer
- Zeitpunkt
- vorherigen Auto-Vergleichspreis
- optionales Ablaufdatum

Nach Ablauf fällt ein befristeter Fixpreis automatisch auf Auto zurück.

### 10.3 Wann ist Fixiert sinnvoll?

- vertraglich vereinbarter Kundenpreis
- zeitlich befristete Aktion
- strategischer Preis trotz abweichender Kostenstruktur
- bereits kommunizierter Preis, der vorübergehend gehalten werden muss

Fixiert ist nicht dafür gedacht, fehlende Stammdaten dauerhaft zu verdecken.

---

## 11. Preisweitergabe über Paket, Concept und Format

Automatische Preise rollen in dieser Reihenfolge weiter:

```text
Rezept-EK
→ Darreichungs-MEK und Darreichungs-VK
→ Paket
→ Concept
→ Format
→ offene Angebote
```

### Paket

Im Auto-Modus entspricht der Paketpreis der Summe seiner aufgelösten Positionen je Person.

### Concept

Im Auto-Modus entspricht der Conceptpreis der Summe seiner direkten Gerichte und Pakete je Person.

Ein Concept kann anzeigen:

- Gesamtpreis: ein Preis für das gesamte Concept
- Einzelpreise: Preis je Gericht oder Paket

### Format

Formate übernehmen die aktuellen Preise ihrer referenzierten Concepts. Sie besitzen keine unabhängige Produktionszeitrechnung.

### Offene Angebote

Anfragen, in Arbeit befindliche Angebote und noch nicht historisierte Angebotsstände können von der Preiskaskade aktualisiert werden. Versendete, angenommene und abgelehnte Angebote bleiben historische Snapshots.

---

## 12. Katalogsicht im Concepter

Pfad:

```text
Food Alchemist → Concepter → Concept öffnen → Kalkulation
```

Ohne Pax zeigt der Concepter:

- Katalog-VK pro Person
- Wareneinsatz pro Person
- Positionen und deren Einzelpreise
- Katalog-Kostenindikator
- aktuellen Vergleichsvorschlag

Wichtig: Der Katalog-Kostenindikator ist kein belastbarer Produktions-HK2. Ohne Pax fehlen reale Mengen, Produktionsvorgänge und Rüstzeiten.

Der Hinweis lautet deshalb sinngemäß:

> Ein belastbarer Produktions-HK2 entsteht erst in der Pax-Simulation oder im Angebot.

---

## 13. Produktionszeitmodell

Produktionszeiten werden im Rezept gepflegt und gelten unabhängig davon, ob es sich um ein Basis- oder Verkaufsrezept handelt.

### 13.1 Zeitbestandteile

| Feld | Bedeutung |
|---|---|
| Rüstzeit | aktive Personenminuten einmal je Produktionslauf |
| Arbeitszeit je Vorgang | aktive Personenminuten je Batch beziehungsweise Kochvorgang |
| Variable Arbeitszeit | aktive Personenminuten je kg, Stück oder Portion |
| Standzeit | passive Durchlaufzeit ohne Lohn- oder Personalkapazität |

### 13.2 Formel

```text
Aktive Personenminuten
= Rüstzeit
  + Arbeitszeit je Vorgang × Anzahl Produktionsvorgänge
  + variable Zeit × Gesamtmenge
```

### 13.3 Mögliche Modelle

| Modell | Pflege |
|---|---|
| reines Batchmodell | nur Arbeitszeit je Vorgang |
| lineares Modell | nur variable Arbeitszeit |
| hybrides Modell | Vorgangszeit plus variable Zeit |
| rüstlastiges Modell | Rüstzeit plus eine oder beide weiteren Komponenten |

Nullwerte sind erlaubt und schalten den jeweiligen Bestandteil faktisch aus.

### 13.4 Personenminuten

Alle aktiven Zeiten sind Personenminuten.

```text
30 Minuten Arbeit von 1 Person = 30 Personenminuten
30 Minuten Arbeit von 2 Personen = 60 Personenminuten
```

Die Rollenbesetzung eines Postens bestimmt Kosten und Kapazität. Sie darf nicht still die im Rezept gepflegte Dauer verdoppeln oder halbieren.

### 13.5 Standzeit

Standzeit ist passiv:

- Kühlen
- Ziehen
- Marinieren
- Garen ohne aktive Betreuung

Sie erhöht die Durchlaufzeit, aber nicht:

- Lohnkosten
- aktive Personalkapazität

---

## 14. Batchgrenzen und Topfdeckel

Die effektive Batchgrenze ist die kleinste positive Grenze aus:

```text
Rezeptgrenze
→ Postengrenze
→ Team-Standard
→ System-Fallback
```

System-Fallback:

- 20 kg
- 200 Stück

Berechnung:

```text
Produktionsvorgänge
= aufrunden(Gesamtmenge / effektive Batchgrenze)
```

Beispiel:

```text
Gesamtmenge:     44 kg
Batchgrenze:     20 kg
Vorgangszeit:    30 Minuten
Rüstzeit:        20 Minuten

Vorgänge:        aufrunden(44 / 20) = 3
Aktive Zeit:     20 + 30 × 3 = 110 Personenminuten
```

Eine größere Postengrenze hebt einen kleineren Team- oder Rezeptwert nicht auf. Es gilt immer die kleinste physische Grenze.

Topfdeckel beeinflussen ausschließlich Auftrag und Produktion. Sie verändern niemals direkt den Katalogpreis.

---

## 15. Eigenschaften-Assistent und KI

Der Eigenschaften-Assistent darf Vorschläge liefern für:

- Rüstzeit
- Vorgangszeit
- variable Zeit und Bezugsart
- Standzeit
- Vorproduzierbarkeit
- Temperatur
- Funktion

Die Werte werden zunächst sichtbar in die Formularfelder übernommen und erst nach menschlichem Speichern dauerhaft verwendet.

Die KI darf keine physischen Grenzen erfinden:

- keine Topfgröße
- keine Gerätegrenze
- keine Postenkapazität

Diese Werte müssen aus dem realen Betrieb kommen.

---

## 16. Auftragsspezifische Kalkulation mit Pax

Im Concepter kann unter „Kalkulation“ eine Personenzahl bei „Auftrag simulieren (Pax)“ eingegeben werden. Im Angebot ist die Pax Teil des Auftrags.

### 16.1 Bedarfsexplosion

```text
Concept-Menge pro Person × Pax
→ Darreichungseinheiten
→ Verkaufsrezepte
→ rekursive Basisrezepte
→ konsolidierter Rezeptbedarf
→ ganze Ansätze und Produktionsvorgänge
```

Gemeinsam benötigte Unterrezepte werden innerhalb eines Auftrags zusammengefasst. Dadurch werden Rohwaren und Arbeitszeiten nicht mehrfach gezählt.

### 16.2 Lohnkosten

```text
FEK
= aktive Personenminuten / 60
  × effektiver Stundensatz
```

Anschließend werden die Lohnnebenkosten genau einmal ergänzt.

### 16.3 HK2-Wasserfall

```text
MEK    = Rohwarenkosten des konsolidierten Bedarfs
FEK    = auftragsspezifische Produktionslöhne
Direkt = Verpackung und weitere direkte Kosten

MGK = Zuschläge auf MEK
FGK = Zuschläge auf FEK

HK  = MEK + FEK + Direkt + MGK + FGK
HK2 = HK + Verwaltung + Vertrieb + Logistik

Mindestpreis = HK2
Zielpreis     = HK2 × (1 + Gewinnaufschlag / 100)
```

### 16.4 Was zeigt die Pax-Simulation?

- Katalogpreis pro Person
- auftragsspezifischer HK2 pro Person
- Mindestpreis gesamt
- Zielpreis gesamt
- aktive Personenzeit
- Warnungen und Fallbacks
- Abweichung zwischen Katalog- und Zielpreis

Die Simulation verändert keine Stammdaten.

---

## 17. Angebot

Pfad:

```text
Food Alchemist → Angebote → Angebot öffnen
```

Ein Angebot mit Concept und Pax zeigt:

- Pax
- Katalogpreis pro Person und gesamt
- MEK
- FEK
- direkte Kosten
- HK und HK2
- Deckungsbeitrag
- Mindestpreis
- Zielpreis
- Zielpreis pro Person
- Produktionszeit und Mengen

### Unwirtschaftlicher Katalogpreis

Liegt der Katalogpreis unter dem Zielpreis:

- erscheint eine Warnung
- der Mindestpreis bleibt sichtbar
- der Zielpreis bleibt sichtbar
- der Angebotspreis wird nicht still erhöht

Der Benutzer kann den Zielpreis bewusst übernehmen oder eine begründete Abweichung fixieren.

---

## 18. Was löst eine Neuberechnung aus?

Die Katalogpreiskaskade startet unter anderem bei Änderungen an:

- Zutaten- oder Einkaufspreis
- Rezept-Yield
- Darreichungsgrammatur
- Darreichungsdelta
- Preisklasse oder Klassenfaktor
- Fixkosten
- monatlichen Bezugsbasen
- Kalkulationsblöcken
- Gewinnaufschlag
- Ziel-Wareneinsatzquote
- Mehrwertsteuer
- Rundung

Reihenfolge:

```text
Rezept-EK
→ Darreichung
→ Paket
→ Concept
→ Format
→ offene Angebote
```

Änderungen an Zeiten, Rollen oder Posten beeinflussen Auftragsvorschauen und Produktion. Sie verändern nicht direkt den Katalogpreis.

---

## 19. Datenqualität und Fallbacks

Das System versucht nicht, fehlende Daten mit Nullwerten zu verstecken.

| Situation | Verhalten |
|---|---|
| Monatsbasen fehlen | Basissatz aus Ziel-Wareneinsatzquote |
| Zielquote fehlt ebenfalls | kein Preisvorschlag, Warnung statt Nullpreis |
| Darreichungs-MEK fehlt | kein Nullpreis, unvollständiger Vorschlag |
| Preisklasse fehlt | neutraler Faktor 100 % plus Hinweis |
| Posten oder Rollen fehlen | Team-Stundensatz als sichtbarer Fallback |
| Produktionszeit fehlt | Zeitwarnung; keine erfundene Zeit |
| Fixkosten vorhanden, Basis 0 | Satz 0 % plus Warnung |

Ein sichtbarer Fallback ist kein Fehler. Er zeigt, welche Daten als Nächstes verbessert werden sollten.

---

## 20. Typische Fehlerbilder

### „Fixkosten sind eingetragen, aber der Satz bleibt 0,00 %“

Ursache:

- passende monatliche Bezugsbasis steht auf 0 oder
- Block ist nicht im automatischen Modus oder
- Fixkostenzeile ist dem falschen Block zugeordnet

Prüfung:

1. Fixkostenblock der Zeile prüfen.
2. Basis des Blocks prüfen.
3. Modus „automatisch“ prüfen.
4. Speichern.

### „Der Basissatz kommt aus Ziel-Wareneinsatz“

Mindestens eine der drei Monatsbasen MEK, FEK oder HK ist nicht positiv gepflegt. Sobald alle drei belastbar vorliegen, wechselt die Quelle auf „aus Kostenstruktur“.

### „Im Gericht erscheint kein VK“

Prüfen:

1. Hat die Darreichung einen MEK größer 0?
2. Gibt es einen Basissatz oder eine Ziel-Wareneinsatzquote?
3. Ist die Darreichung aktiv?
4. Ist die Preisklasse aktiv?
5. Gibt es eine Warnung in der Kalkulation?

Ein leeres manuelles VK-Feld ist im Auto-Modus kein Fehler.

### „Der Preis im Concept ist 0“

Prüfen:

- Haben alle verwendeten Gerichte eine aktive Darreichung?
- Haben die Darreichungen einen Auto- oder Fixpreis?
- Sind Paket und Concept im Auto-Modus?
- Ist die Position als preisrelevant eingebunden?

### „Die Pax-Simulation zeigt keine Lohnkosten“

Prüfen:

- Sind am Rezept aktive Zeiten gepflegt?
- Ist ein Standardposten zugeordnet?
- Ist der Team-Stundensatz größer 0?
- Bei Rollenmodus: besitzt der Posten eine Rollenbesetzung?

### „100 Pax und 5.000 Pax haben denselben Katalogpreis“

Das ist korrekt. Der Katalogpreis pro Person ist mengenunabhängig. Der auftragsspezifische HK2, die Anzahl der Batches und die aktive Personenzeit müssen sich dagegen unterscheiden.

### „Standzeit erhöht den Lohn“

Das wäre falsch. Standzeit ist passiv. Nur Rüstzeit, Vorgangszeit und variable aktive Zeit dürfen Lohnkosten erzeugen.

---

## 21. Praktischer Einführungscheck

### Einstellungen

- [ ] MwSt regulär und ermäßigt geprüft
- [ ] Team-Default für MwSt gewählt
- [ ] Rundungsmodus geprüft
- [ ] Team-Stundensatz gepflegt
- [ ] Lohnnebenkosten fachlich geklärt
- [ ] Lohnquelle gewählt
- [ ] Kostenblöcke aktiv und richtig zugeordnet
- [ ] Fixkosten pro Monat vollständig
- [ ] MEK-, FEK- und HK-Monatsbasis bestätigt
- [ ] Basissatz zeigt „aus Kostenstruktur“

### Preisklassen

- [ ] neutrale Klasse mit 100 % vorhanden
- [ ] Klassenfaktoren fachlich begründet
- [ ] MwSt-Profile geprüft
- [ ] Rundungsabweichungen nur bei Bedarf gesetzt

### Gerichte

- [ ] Zutatenpreise vollständig
- [ ] Yield plausibel
- [ ] Darreichung und Grammatur korrekt
- [ ] MEK der Darreichung plausibel
- [ ] Preisklasse gesetzt oder neutraler Fallback bewusst akzeptiert
- [ ] Auto-VK netto und brutto geprüft

### Produktion

- [ ] Rüstzeit gepflegt
- [ ] Arbeitszeit je Vorgang gepflegt
- [ ] variable Zeit nur bei echter linearer Arbeit gepflegt
- [ ] Standzeit getrennt erfasst
- [ ] Batchgrenzen aus realer Ausstattung gepflegt
- [ ] Standardposten zugeordnet

### Aufträge

- [ ] Test mit 100 Pax durchgeführt
- [ ] Test mit 5.000 Pax durchgeführt
- [ ] Katalogpreis pro Person bleibt gleich
- [ ] Batches und Produktionskosten verändern sich plausibel
- [ ] Mindestpreis und Zielpreis geprüft
- [ ] Warnungen auf Datenlücken geprüft

---

## 22. Empfohlener Testablauf mit einem Gericht

1. Ein einfaches Gericht mit vollständigen Zutatenpreisen wählen.
2. Yield und Standarddarreichung prüfen.
3. Darreichungs-MEK notieren.
4. Neutrale Preisklasse mit 100 % setzen.
5. Auto-VK prüfen und anhand der Formel nachrechnen.
6. Klassenfaktor testweise auf 120 % setzen und Preisänderung prüfen.
7. Faktor wieder auf den fachlich gewünschten Wert setzen.
8. Gericht in ein Paket einbauen und Paketpreis prüfen.
9. Paket in ein Concept einbauen und Conceptpreis prüfen.
10. Im Concepter 100 Pax simulieren.
11. Produktionsvorgänge, Zeit, HK2 und Zielpreis prüfen.
12. Mit 5.000 Pax wiederholen.
13. Prüfen, dass der Katalogpreis pro Person gleich bleibt.
14. Prüfen, dass Produktionsvorgänge, Personenzeit und Auftrags-HK2 plausibel reagieren.

---

## 23. Entscheidungsregeln im Alltag

### Wann ändere ich den Basissatz?

Nicht direkt. Der Basissatz entsteht aus Kostenstruktur, Bezugsbasen und Gewinnaufschlag. Ändere die zugrunde liegenden Unternehmensdaten.

### Wann ändere ich eine Preisklasse?

Wenn eine ganze Produktgruppe dauerhaft relativ zum Unternehmensstandard höher oder niedriger positioniert werden soll.

### Wann fixiere ich einen einzelnen Preis?

Wenn es einen konkreten geschäftlichen Grund für genau diesen Preis gibt. Die Begründung gehört in den Override.

### Wann nutze ich die Pax-Simulation?

Vor der Freigabe eines Katalogpreises für typische kleine und große Aufträge sowie bei konkreten Angebotsprüfungen.

### Wann ist ein Angebotspreis zu niedrig?

Wenn er unter HK2 liegt, ist er nicht kostendeckend. Liegt er zwischen HK2 und Zielpreis, ist er kostendeckend, erreicht aber den festgelegten Gewinnaufschlag nicht.

---

## 24. Kurzformeln zum Nachschlagen

```text
Automatischer Gemeinkostensatz
= Fixkosten/Monat ÷ Bezugsbasis/Monat × 100

Fallback-Basissatz
= 100 ÷ Ziel-Wareneinsatzquote

Auto-VK netto
= Darreichungs-MEK × Basissatz × Klassenfaktor ÷ 100

VK brutto
= VK netto × (1 + MwSt-Satz ÷ 100)

Produktionsvorgänge
= aufrunden(Gesamtmenge ÷ kleinste Batchgrenze)

Aktive Personenminuten
= Rüstzeit + Vorgangszeit × Vorgänge + variable Zeit × Menge

FEK
= aktive Personenminuten ÷ 60 × Stundensatz

HK
= MEK + FEK + direkte Kosten + MGK + FGK

HK2
= HK + Verwaltung + Vertrieb + Logistik

Mindestpreis
= HK2

Zielpreis
= HK2 × (1 + Gewinnaufschlag ÷ 100)

Deckungsbeitrag
= Verkaufspreis - HK2

Wareneinsatzquote
= MEK ÷ Verkaufspreis × 100
```

---

## 25. Zusammenfassung

Der Food Alchemist löst zwei unterschiedliche Fragen:

1. **Welcher allgemein gültige Preis gehört in den Katalog?**
2. **Ist dieser Preis für einen konkreten Auftrag mit bekannter Pax wirtschaftlich?**

Der Katalogpreis entsteht aus Darreichungs-MEK, dynamischem Unternehmens-Basissatz und relativer Preisklasse. Er benötigt keine fingierte Produktionszeit pro Portion.

Der Auftrags-HK2 entsteht erst mit Pax, Bedarfsexplosion, Batches, aktiven Personenminuten, Stundensätzen und Gemeinkosten. Er prüft den Katalogpreis, verändert ihn aber nicht still.

Damit bleibt der Katalog stabil, während Angebote trotzdem realistisch auf kleine und große Produktionsmengen reagieren.
