# Produktion: Rezeptanalyse, Arbeitszeit und Kapazitaet

## Zweck dieser Wissensdomain

Diese Wissensdomain unterstuetzt die Analyse eines Rezeptes anhand von Zutaten, Yield und strukturierten Zubereitungsschritten. Sie hilft dabei, aktive Produktionszeit, passive Durchlaufzeit, mengenabhaengige Arbeit und Vorproduzierbarkeit fachlich einzuordnen.

Die Domain liefert Vorschlaege. Sie ersetzt keine menschliche Freigabe, keine betrieblichen HACCP-Regeln und keine physisch gepflegten Geraete-, Topf- oder Postengrenzen.

## Reihenfolge der Rezeptanalyse

Vor einer Schaetzung werden die Informationen in dieser Reihenfolge gelesen:

1. Rezeptname und kulinarische Funktion.
2. Zutaten mit Mengen und Einheiten.
3. Yield in kg, Stueck oder Portionen.
4. Vollstaendige Step-by-Step-Zubereitung.
5. Explizite Zeiten, Temperaturen und Ruhephasen aus den Schritten.
6. Bereits gepflegte Produktionswerte.
7. Passendes Unternehmenswissen aus dem Wissensmodul.

Explizite Rezeptangaben haben Vorrang vor allgemeinem Wissen. Fehlende Angaben werden vorsichtig geschaetzt und als Vorschlag gekennzeichnet.

## Personenminuten

Alle aktiven Arbeitszeiten werden als Personenminuten verstanden:

```text
1 Person arbeitet 10 Minuten = 10 Personenminuten
2 Personen arbeiten 10 Minuten = 20 Personenminuten
30 Minuten passive Garzeit = 0 Personenminuten
```

Die Anzahl der Personen wird nur eingerechnet, wenn der Arbeitsschritt erkennbar mehrere gleichzeitig arbeitende Personen benoetigt.

## Ruestzeit je Produktionslauf

`setup_time_min` ist aktive Personenzeit, die einmal je Produktionslauf entsteht:

- Arbeitsplatz und Geraete vorbereiten.
- Werkzeuge, Behaelter und Zutaten bereitstellen.
- Maschine aufbauen oder umruesten.
- einmalige Abschluss- und Reinigungsarbeit.

Ein Produktionslauf ist eine zusammenhaengende Produktionszeile an einem Datum und Posten. Wird die Menge auf mehrere Tage oder Posten verteilt, faellt die Ruestzeit je Teilproduktion erneut an.

## Arbeitszeit je Produktionsvorgang

`work_time_min` ist die aktive Personenzeit je Kochvorgang oder Batch:

- Ansatz herstellen.
- Topf oder Maschine befuellen und entleeren.
- aktiv ruehren, aufschlagen, braten oder bearbeiten.
- batchbezogen abschmecken und kontrollieren.
- chargenbezogene Zwischenreinigung.

Die Rezeptart entscheidet nicht, ob Zeit linear oder batchweise skaliert. Entscheidend ist der beschriebene Produktionsprozess.

## Variable Personenminuten

`variable_work_time_min` beschreibt aktive Arbeit, die mit der Menge steigt. Die Bezugsart `variable_work_time_basis` ist `kg`, `piece` oder `portion`.

Typische variable Arbeit:

- Schneiden oder putzen je kg.
- Formen oder fuellen je Stueck.
- Portionieren, anrichten oder verpacken je Portion.
- Dekorieren oder kontrollieren je Einheit.

Eine Taetigkeit darf nicht gleichzeitig vollstaendig in `work_time_min` und in der variablen Zeit enthalten sein.

## Passive Standzeit

`standzeit_min` ist passive Durchlaufzeit:

- Garen ohne dauernde Bedienung.
- Kuehlen oder Gefrieren.
- Marinieren, Quellen, Ruhen oder Reifen.
- kontrolliertes Ziehenlassen.

Standzeit verlaengert die Durchlaufzeit, erzeugt aber keine Lohnkosten und belastet keine Personalkapazitaet. Aktive Kontrollen, Wenden oder Umfuellen werden getrennt als Personenminuten erfasst.

## Batch und Produktionsvorgaenge

Eine Gesamtmenge wird in mehrere Produktionsvorgaenge geteilt, wenn sie die wirksame Batchgrenze ueberschreitet:

```text
Produktionsvorgaenge = aufrunden(Gesamtmenge / effektive Batchgrenze)
```

Die wirksame Grenze ist der kleinste positive Wert aus:

```text
Rezeptgrenze -> Postengrenze -> Team-Standard -> System-Fallback
```

Topf-, Kessel-, Geraete- und Postengrenzen sind physische Betriebsdaten. Die KI darf sie nicht erfinden oder automatisch speichern. Sie darf lediglich aus den Schritten erkennen, dass ein Prozess wahrscheinlich batchbezogen ist.

## Berechnung der aktiven Zeit

```text
Aktive Personenminuten
= Ruestzeit
+ Arbeitszeit je Vorgang x Produktionsvorgaenge
+ variable Personenminuten x Gesamtmenge
```

Beispiel:

```text
Ruestzeit:                   15 Minuten
Arbeitszeit je Vorgang:      20 Minuten
Produktionsvorgaenge:         3
Variable Zeit:                4 Minuten je kg
Gesamtmenge:                 10 kg

Aktive Personenminuten = 15 + 20 x 3 + 4 x 10 = 115
```

## Posten und Rollen

Ein Posten ist ein Arbeitsplatz oder Produktionsbereich, kein Mensch. Seine Besetzung liefert verfuegbare Personenminuten und einen Kostensatz. Die Besetzung darf nicht automatisch als Rezeptdauer interpretiert werden.

Der Standardposten dient dem Routing. Wenn kein Posten sicher aus Rezept oder Betriebswissen hervorgeht, bleibt die Zuordnung leer und muss manuell bestaetigt werden.

## Vorproduzierbarkeit

`max_vorlauf_tage` beschreibt, wie viele Tage vor der Verwendung eine Produktion betrieblich eingeplant werden kann. Beruecksichtigt werden:

- Herstellungsverfahren und Zutaten.
- vorgesehene Kuehlung oder Tiefkuehlung.
- Verpackung und Transport.
- Qualitaetsverlust.
- notwendige Regeneration.
- vorhandene betriebliche Freigaben.

Eine Haltbarkeit oder Vorproduzierbarkeit darf nicht allein aus allgemeinem Kuechenwissen abgeleitet werden. Fehlen belastbare Angaben, wird `0` beziehungsweise "nur am Produktionstag" vorgeschlagen oder der Wert bleibt offen.

## Temperatur, Funktion und Regeneration

Temperatur und Funktion werden aus Rezept und Schritten abgeleitet, zum Beispiel gekuehlt, warm, Beilage, Sauce, Hauptkomponente oder Topping.

Konkrete Gar-, Kuehl- oder Kerntemperaturen werden nur uebernommen, wenn sie im Rezept oder in freigegebenem Unternehmenswissen genannt sind. Regenerationsarbeit wird als eigener Prozess betrachtet und nicht still in die urspruengliche Produktionszeit eingerechnet.

## Regeln fuer KI-Vorschlaege

- Zutaten, Yield und alle Zubereitungsschritte muessen vor der Wissenssuche gelesen werden.
- Passendes Wissen wird ueber die Suchbegriffe des Rezeptes abgerufen.
- Rezeptangaben schlagen allgemeines Domainwissen.
- Aktive und passive Zeit werden getrennt ausgegeben.
- Ruest-, Vorgangs- und variable Zeit werden nicht doppelt gezaehlt.
- Unsicherheit und fehlende Datengrundlage werden sichtbar genannt.
- Keine Topf-, Geraete-, Batch- oder Postengrenzen erfinden.
- Keine Haltbarkeit oder Sicherheitstemperatur frei annehmen.
- Vorschlaege werden zuerst im Formular angezeigt und erst nach menschlicher Bestaetigung gespeichert.

## Erwartete Ausgabe

Die Analyse darf folgende Vorschlagsfelder liefern:

- `setup_time_min`
- `work_time_min`
- `variable_work_time_min`
- `variable_work_time_basis`
- `standzeit_min`
- `max_vorlauf_tage`
- `temperature`
- `function`
- Begruendung und Unsicherheit je geschaetztem Wert

Physische Kapazitaeten und Postenzuordnungen bleiben ausserhalb des automatischen KI-Vorschlags.

## Suchbegriffe

Arbeitszeit, Personenminuten, Ruestzeit, variable Zeit, Standzeit, Kochvorgang, Batch, Charge, Topf, Kessel, Posten, Kapazitaet, Vorproduktion, Kuehlung, Regeneration, Haltbarkeit, Transport, Durchlaufzeit, Portionieren, Verpacken, Anrichten.
