# Destatis-Marktdaten und RSS-Präzision

Stand 05.11.2026. Erweiterung zu TR-01 / Spec 93 und Observatorium.

## Entscheidung

RSS entdeckt Meldungen, MCP erschließt Reports, die kostenlose GENESIS-API liefert
monatliche Vergleichsdaten. Pilot: Verbraucherpreisindizes für Nahrungsmittel und
alkoholfreie Getränke sowie Gaststättendienstleistungen (61111-0004).
Verbraucherpreise sind ausdrücklich keine Einkaufs- oder Betriebskosten.
Umsatz/Beschäftigung und Eurostat folgen erst nach einem verifizierten Pilotlauf.

Eigenes, teamgebundenes Marktdaten-Snapshot-Modell mit Monatswerten, Originalzeilen,
Einheit/Basis, Abrufzeit und Fehlerstatus. Keine Artikelkopien, KI-Aufträge oder
Radarfreigaben. Vorjahresvergleich nur bei identischer Einheit und vorhandenem,
positivem Vorjahreswert; fehlende Werte bleiben unbekannt. Revisionswerte werden
beim erneuten Abruf ersetzt, ein fehlgeschlagener Abruf erhält den letzten Stand.
Reads beachten Teamvererbung, Writes ausschließlich das aktuelle Team.

## Zugang und Betrieb

Offizielle API nur per POST an festen HTTPS-Endpunkt, keine Weiterleitungen.
Kostenloser GENESIS-Zugang nötig; Token ausschließlich serverseitig über
FOODALCHEMIST_GENESIS_TOKEN, nie in MCP, Browser, Quell-URL oder Logs.
MCP `marktdaten.ABRUF` startet als Probelauf. `apply=true` speichert explizit;
kein automatischer Scheduler im Pilot.
Fehlender Zugang ist ein sichtbarer Zustand, keine leere Erfolgsmeldung.
Der Nutzer hat am 05.11.2026 einen GENESIS-Token lokal bereitgestellt. Ein
authentifizierter Probelauf mit dem echten Foodalchemist-Client ist erfolgreich;
der Token wurde dabei nur im Prozessspeicher verwendet und nicht veröffentlicht.

## RSS

Nur eng gefasste fachfremde Meldungsmuster werden vor der KI ausgeschlossen.
Vorschau und Import verwenden dieselbe Prüfung. Sport-Catering, Stadionverpflegung,
Gastro-Personalien und Insolvenzen bleiben als potenzielle Branchennews erhalten.
Keine rückwirkende Löschung oder pauschale Quellen-/Themenaktivierung.

## Abnahme

Parser/Fehlerfälle, fehlender Token ohne HTTP-Aufruf, Probelauf ohne Writes,
Idempotenz, Revision, Nullwerte, Einheit, Tenant-/Leserechte, MCP und mobile Ansicht
testen. API-Probelauf mit echten Antworten erfolgreich, produktive Aktivierung noch offen.
Deployment über food-alchemist.de/update.sh nach gesonderter Freigabe.

Offizielle Referenz: https://github.com/StatistischesBundesamt/GENESIS-Online

## Lokaler Nachweis (05.11.2026)

53 unterschiedliche betroffene Feature-/Regressionstests grün (422 Assertions).
Nach der letzten gemeinsamen Vorfilterkorrektur 23 Markt-/Observatoriumtests
erneut grün (176 Assertions). PHP-Syntax, Pint, Composer-Validierung, JS- und
CSS-Build erfolgreich. CSS-Build meldet zwei bestehende dynamische Klassenwarnungen;
keine generierten Assets oder Dependency-Dateien werden geändert. Lokale
Bildschirmprüfung bei 390 und 1365 Pixeln ohne horizontalen Überlauf, fehlender
Zugang und fiktive Monatswerte geprüft. Keine echten Marktdaten importiert.

MySQL-Smoke wurde versucht, der lokale MySQL-Server war nicht erreichbar; keine
bestehende Datenbank verändert. MySQL-Abnahme und vollständige Modulsuite vor Release bleiben ausdrücklich offen.
Kein Deployment und keine produktive Datenübernahme.

## Authentifizierter Pilot und Antwortformat

05.11.2026: Beide offiziellen API-Anfragen HTTP 200, jeweils 36 CSV-Monatszeilen
2024–2026. ZIP-Antwort auch bei `compress=false`: genau eine CSV, höchstens 2 MiB
entpackt, keine Dateipfad-Extraktion; temporäres ZIP wird zuverlässig entfernt.
Im realen PHP-Client zusätzlich erfolgreicher Dry-Run in isolierter SQLite-DB
(1 Test, 4 Assertions), keine gespeicherten Snapshots und keine KI-Aufträge.
Originalschema in kurzen, öffentlichen CSV-Fixtures festgehalten, keine Tokens.

Monatsreihen enthalten bereits Platzhalter für noch nicht vorliegende Werte.
Die Übersicht verwendet den letzten Zahlenwert (beide September 2026), erhält
Nullwerte im Verlauf und weist auf noch fehlende neuere Monate hin. Dezember
2026 wird am Testdatum als Zukunft ausgeschlossen. API-Ergebnis unterscheidet
heruntergeladene Monate und letzten Monat mit Zahlenwert.

Abschluss nach Pilotkorrekturen: 10 Marktdaten-Featuretests grün (71 Assertions),
zusätzlich 15 bestehende Observatoriumtests im begleitenden Lauf grün.
ZIP-Grenzen, echte Antwortstruktur, letzter verfügbarer Wert und Hinweis auf
noch unbekannte Folgemonate sind abgedeckt.
