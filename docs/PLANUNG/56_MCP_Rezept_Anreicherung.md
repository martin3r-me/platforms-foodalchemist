# MCP-Rezeptpflege: Garverlust, Posten und PDF

Stand: 2026-09-19. Bezug: RE-07, PR-07 und Matrix 26 (`recipes.GET/PUT`,
`recipe_ingredients.PUT`, `recipe.posten`, `recipes.PDF`).

## Problem und Verhalten

- `recipes.GET` lieferte Zutaten ohne IDs und Garverluste. Ein MCP-Voll-Sync nach
  einem GP-Wechsel konnte dadurch Anreicherungswerte verlieren.
- `recipes.PUT` quittierte unbekannte Felder mit Erfolg und erhöhte die Version,
  obwohl z.B. ein Garverlust am Rezeptkopf nicht geschrieben wurde.
- Der Default-Posten wurde nur über Wortüberschneidungen mit Postennamen ermittelt.
- Das Plattform-PDF war nur über eine Webroute erreichbar.

## Änderung

Zutaten-IDs und Garverluste werden gelesen. `recipe_ingredients.PUT` erhält bei
bestehender ID ausgelassene optionale Felder; explizites null löscht nullable Werte.
Ohne ID bleibt die Neuanlage-Semantik bestehen, ausgelassene Zeilen werden weiterhin
entfernt. Ungültige oder doppelte IDs werden abgewiesen; Garverlust muss 0–100 sein.
Unverändert zurückgeschriebene Garverluste behalten ihre Quelle.

Der Reife-Check verweist für Garverlust auf `recipe_ingredients.PUT` und meldet
den fehlenden Herstellungsposten als wichtige Lücke (nur Basisrezepte).

Unbekannte Rezeptkopffelder werden vor dem Schreiben abgewiesen, mit Hinweis auf
den richtigen Zutaten-Endpunkt. Der Posten wird bei Komplett-Anreicherung durch
`recipe.posten` aus sichtbaren aktiven IDs fachlich zugeordnet. Gepflegte Zuordnungen
bleiben erhalten. Kein Treffer/Providerfehler bleibt als offen/fehler sichtbar.
Verkaufsgerichte erhalten weiterhin keinen eigenen Herstellungsposten.
`production_stations.GET` macht die IDs für manuelle MCP-Korrekturen auffindbar.

`recipes.PDF` rendert denselben Report wie die Webroute. Standard ist ein zehn Minuten
gültiger signierter Download-Link auf einen autorisierten PDF-Snapshot im Cache.
Der Link benötigt keinen Login und gewährt seinem Besitzer bis zum Ablauf Zugriff;
manipulierte und abgelaufene Links sind gesperrt. Keine dauerhaft öffentliche Datei.
Alternativ liefert `transport=base64` die Bytes direkt im Tool-Ergebnis.
Profile: kurz, produktion, kalkulation, voll; maximal 2 MiB PDF. Native Dateianzeige
hängt vom Client ab. Dieser Transport ist kein Freigabeschritt.

Die 15 Rezept-Inhaltsfilter überschreiben die Profildefaults einzeln. Zielgewicht
oder Anzahl/Darreichung nutzen dieselbe Hochrechnung wie der Webreport. Unbekannte
Filter und ungültige Werte werden abgewiesen; wirksame Optionen werden zurückgegeben.

## Nachweise

`McpRecipeEnrichmentRepairTest`: GP-Wechsel ohne Wertverlust, explizites Löschen,
Recompute, unbekanntes Kopffeld ohne Versionsänderung, ungültige Zutatenwerte/IDs,
Postenkatalog und Fremdteam-Abweisung, fachliche KI-Zuordnung ohne Namensüberschneidung,
Erhalt bestehender Zuordnung, PDF-Decodierung, gültiges PDF mit Statuskennzeichnung, Tenantzugriff und
signierter Download ohne Login (inklusive Manipulations-/Ablauftests).
`RecipeOneShotTest` prüft zusätzlich die vollständige Anreicherungskette.
Kein Nachweis für den produktiven Zustand oder die historischen Läufe von Rezept 3780.

## Nachbesserung: MySQL-Cache (2026-09-19)

Der erste Download-Pfad speicherte rohe PDF-Bytes im Cache. Laravels
DatabaseStore kodiert solche Werte für SQLite/PostgreSQL bei Nullbytes automatisch,
für MySQL jedoch nicht. Serialisierte PDFs sind kein gültiges UTF-8 und können
daher in der MySQL-Textspalte abgewiesen werden (`PDF_DOWNLOAD_FAILED`).
Der Snapshot wird jetzt vor dem Cache-Schreiben Base64-kodiert; der Download
dekodiert strikt. Bestehende binäre Snapshots bleiben bis zum TTL-Ablauf lesbar.

Regression: Der echte MySQL-Cache-Serializer muss gültiges UTF-8 liefern; der
HTTP-Download muss exakt den ursprünglichen PDF-Bytes entsprechen. Dieser Test
schlägt vor der Änderung fehl. Die vorherige Gesamtsuite benutzte den Array-Cache
und hatte diese Backend-Differenz nicht abgedeckt.
