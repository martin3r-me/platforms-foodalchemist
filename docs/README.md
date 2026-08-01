# Dokumentation — Food Alchemist

Diese Seite ist der Einstieg für Entwickler, Produktverantwortliche und externe
Mitarbeitende. Sie erklärt, welches Dokument welche Frage beantwortet und welche
Quelle bei Widersprüchen gilt.

## Schnellzugriff

| Ich möchte … | Lies zuerst |
|---|---|
| das Modul lokal verstehen und ausführen | [README des Moduls](../README.md) |
| Architektur oder Datenfluss verstehen | [ARCHITEKTUR.md](ARCHITEKTUR.md) |
| eine Codeänderung sicher durchführen | [LLM_GUIDE.md](../LLM_GUIDE.md) |
| Produktstrategie und Nordstern verstehen | [Zielbild 2029](Zielbild_2029_und_Huerden_Food_Alchemist.md) |
| den nächsten Umsetzungsschritt auswählen | [Zielbild-Umsetzungsplan](PLANUNG/24_Zielbild_2029_Umsetzungsplan.md) |
| eine kleine Business-Funktion oder ihren Abnahmetest finden | [Business-Case-Funktionsmatrix](PLANUNG/25_Business_Case_Funktionsmatrix.md) |
| einen Prompt oder ein MCP-Tool prüfen | [LLM-/MCP-Funktionsmatrix](PLANUNG/26_LLM_MCP_Funktionsmatrix.md) |
| den aktuellen Reife- und Risikostand prüfen | [MVP-Audit](PLANUNG/23_MVP_Audit.md) |
| das Produkt bedienen | [Benutzerhandbuch](index.md) |
| eine bestehende Detail-Spezifikation finden | [Planungsordner](PLANUNG/) |

## Dokumentationshierarchie

Bei Widersprüchen gilt diese Reihenfolge:

1. **Zielbild 2029** für Mission, Markt, Zielzustand und strategische Gates
2. **Architektur** für technische Invarianten, Systemgrenzen und Datenbesitz
3. **aktueller Zielbild-Umsetzungsplan** für Priorität, Reihenfolge und Abnahme
4. **aktive Detail-Spezifikationen** für die konkrete Implementierung
5. **Benutzerhandbuch** für beobachtbares Produktverhalten
6. **Archiv** ausschließlich als historische Referenz

Ein neueres Datum allein macht ein Dokument nicht verbindlicher. Entscheidend ist
seine Rolle in dieser Hierarchie.

## Dokumentklassen

### Verbindliche Orientierung

| Dokument | Beantwortet | Pflegeauslöser |
|---|---|---|
| `../README.md` | Was ist das Modul, wie starte und teste ich es? | Setup, Struktur oder Befehle ändern sich |
| `ARCHITEKTUR.md` | Wie ist es gebaut, welche Regeln dürfen nicht verletzt werden? | Systemgrenze, Datenbesitz oder Kernfluss ändert sich |
| `../LLM_GUIDE.md` | Wie führe ich Änderungen sicher durch? | Arbeits- oder Prüfregeln ändern sich |
| `Zielbild_2029_und_Huerden_Food_Alchemist.md` | Warum existiert das Produkt und wann ist es erfolgreich? | strategische Entscheidung |
| `PLANUNG/24_Zielbild_2029_Umsetzungsplan.md` | Was wird in welcher Reihenfolge umgesetzt? | Gate erreicht, Risiko verändert oder Priorität entschieden |
| `PLANUNG/25_Business_Case_Funktionsmatrix.md` | Welche kleine Funktion existiert und wie wird sie fachlich abgenommen? | Funktion, Status oder Abnahmeevidenz ändert sich |
| `PLANUNG/26_LLM_MCP_Funktionsmatrix.md` | Welche Prompts und Tools existieren und wie werden sie sicher abgenommen? | Prompt, Tool, Schema oder Capability-Status ändert sich |

### Benutzerhandbuch

`index.md`, `stammdaten.md`, `rezepte.md`, `concepter.md`, `foodbook.md`,
`kalkulation.md`, `speiseplan.md`, `produktion.md`, `wissen.md` und `einstellungen.md` beschreiben das
Produkt aus Anwendersicht. Sie sollen keine internen Klassen- oder
Migrationserklärungen enthalten.

### Planung und Nachweise

`PLANUNG/` enthält nur noch die aktiven Steuerungs- und Nachweisdokumente 23–26.
Eine neue, begrenzte Umsetzungsspezifikation wird dort erst beim Start eines
konkreten Arbeitspakets angelegt und verweist auf Funktions- und Capability-IDs.

Die zentrale Statussicht ist der Zielbild-Umsetzungsplan. Frühere Spezifikationen
und chronologische Arbeitsdetails liegen im datierten Archiv.

### Historischer Kontext

- Die frühere `ROADMAP.md`, `VISION.md` und `GOALS.md` liegen unter
  `_archiv/2026-07-28_dokumentationsbereinigung/`.
- Specs 00–22, Journale, Statusmatrizen und Übergaben liegen im dortigen
  Unterordner `PLANUNG/`.
- `_archiv/` ist nicht verbindlich und darf nicht als Basis für neue
  Implementierungen verwendet werden, ohne die Aussage gegen aktuelle Quellen zu
  prüfen.

## Pflegekonventionen

- Jedes verbindliche Dokument nennt Zweck, Zielgruppe und Stand.
- Relative Links werden gegenüber Kopien von Inhalten bevorzugt.
- Statusaussagen verlinken einen überprüfbaren Nachweis.
- Fachbegriffe werden konsistent verwendet: Lieferantenartikel, Grundprodukt,
  Basisrezept, Verkaufsgericht, Konzept, Foodbook.
- Globale Aussagen zur Mandantenfähigkeit nennen Lesen und Schreiben getrennt.
- „Gebaut“, „getestet“, „am Demo-Datensatz geprüft“ und „mit echtem Kunden
  abgenommen“ sind unterschiedliche Statuswerte.
- Neue Langzeitplanung wird im Zielbild-Plan und bei Bedarf in einer klar
  begrenzten aktiven Detail-Spezifikation gepflegt.
- Abgelöste Dokumente werden mit einem Hinweis versehen oder ins Archiv verschoben;
  sie werden nicht still gelöscht.

## Definition of Done für Dokumentation

Eine Änderung an Architektur oder Produktfluss ist dokumentarisch abgeschlossen,
wenn:

- Einstieg und Links weiterhin stimmen,
- betroffene Invarianten in der Architektur aktualisiert sind,
- Benutzerverhalten im Handbuch korrekt ist,
- Plan oder Spezifikation den Status samt Nachweis enthält,
- keine gegenteilige Aussage in einem höher priorisierten Dokument verbleibt.
