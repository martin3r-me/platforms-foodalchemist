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
Der Nutzer hat am 05.11.2026 bestätigt, noch keinen Zugang zu besitzen.

## RSS

Nur eng gefasste fachfremde Meldungsmuster werden vor der KI ausgeschlossen.
Vorschau und Import verwenden dieselbe Prüfung. Sport-Catering, Stadionverpflegung,
Gastro-Personalien und Insolvenzen bleiben als potenzielle Branchennews erhalten.
Keine rückwirkende Löschung oder pauschale Quellen-/Themenaktivierung.

## Abnahme

Parser/Fehlerfälle, fehlender Token ohne HTTP-Aufruf, Probelauf ohne Writes,
Idempotenz, Revision, Nullwerte, Einheit, Tenant-/Leserechte, MCP und mobile Ansicht
testen. API-Live-Abnahme mit echten Werten bleibt bis zum GENESIS-Zugang offen.
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
bestehende Datenbank verändert. MySQL-Abnahme, vollständige Modulsuite vor Release
und authentifizierter API-Live-Pilot bleiben ausdrücklich offen. Kein Deployment.
