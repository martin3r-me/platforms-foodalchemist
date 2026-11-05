# Gastronomie-Observatorium

Das Observatorium ergänzt die Recherche um eine lesbare Ausgabe für Branchennews, Food-Ideen und Marktbewegungen. Navigation und Aussehen verwenden die bestehende Foodalchemist-Shell: globale Modulwahl außen, einklappbare Bereichsnavigation in der mittleren Spalte. Die bisherige Trendradar-Oberfläche heißt im Menü „Trend Research“; ihre Routen und Freigabemechanik bleiben erhalten.

## Umfang

- Entdecken, Branchennews, Ideenwelt, Trendradar, Markt & Chancen und persönliche Merkliste.
- Suche, 17 Branchen, Deutschland/Österreich/Schweiz/Region offen, 7/30/90 Tage und Archiv; zwölf Meldungen pro Seite.
- Details mit Quelle, Datum, Kurzbeschreibung, gegebenenfalls eigener KI-Einordnung und Ideenimpuls. Sichere Originalverweise und weitere gleich betitelte Fundstellen.
- Persönliches Merken und Vormerken zur Trendprüfung sind je Team und Benutzer getrennt. Das Vormerken erzeugt keinen Trendbeleg und keine Radarfreigabe.
- Berechtigte Kuratoren können einen eigenen Beitrag auf die bestehende Inspirationspinnwand legen. Die Handyansicht bleibt für redaktionelle Änderungen gesperrt; persönliche Merker sind möglich.
- Das Radar verwendet ausschließlich die bestehenden freigegebenen Trends und deren vorhandene Positionierung.

## Daten und Auswertung

Die additive Migration ergänzt den vorhandenen Eingang um Observatorium-Metadaten, einen Titel-/URL-Gruppierungsschlüssel und Herausgeberinformationen. Eine zusätzliche Tabelle speichert persönliche Merker. Bestehende Meldungen werden lokal anhand von Titel, Teaser und vorhandenen Sparten eingeordnet. Dabei werden keine KI-Aufträge ausgelöst, Trendstatus geändert oder Freigaben erneuert.

Bei zukünftiger Eingangsauswertung liefert der vorhandene KI-Aufruf zusätzlich zulässige Verwendungen, Branchen, eine eigene Einordnung und einen optionalen Ideenimpuls. Eine brauchbare Branchenmeldung darf erhalten bleiben, auch wenn sie kein Trendsignal darstellt. Bestehender Auswertungsweg, Stufe und Budget werden nicht umgestellt; es gibt keinen zusätzlichen KI-Aufruf, das Antwortlimit steigt von 900 auf 1200 Token. Stichwortbasierte Altdaten sind als solche erkennbar. Regionszuordnung erfolgt nur bei ausdrücklichem Ortsbezug, nicht allein aufgrund des Google-Suchmarktes.

Google-News-Suchfeeds mit `when:1d/7d/30d/90d` werden bereits vor der KI gegen Veröffentlichungsdatum geprüft, auch in der Vorschau. Offensichtliche fachfremde Sport-, Polizei- oder Börsentreffer werden aus der Observatorium-Ausgabe ausgeschlossen. Der RSS-Herausgeber wird für neue Fundstellen gespeichert; bei der Quellenvielfalt zählt nach Möglichkeit die ursprüngliche Herausgeberdomain statt Google News. Derselbe gruppierte Inhalt zählt nicht mehrfach als unabhängiges Signal.

## Bewusste Grenzen

„Mein Briefing“ zeigt die ersten fünf Treffer der aktuellen Filter. Es ist kein automatisch versandter Wochenbericht. Gruppierung erkennt gleiche längere Titel beziehungsweise gleiche URLs, keine semantischen Duplikate. Die lokale Einordnung ist konservativ und ersetzt keine fachliche Prüfung. Neue KI-Einordnungen werden nur im bisherigen Auswertungsprozess erstellt. Es sind weder neue externe APIs noch neue Abonnements erforderlich.

## Prüfung und Rollout

91 einschlägige Feature- und Regressionstests wurden in isolierter SQLite-In-Memory-Datenbank erfolgreich geprüft (712 Assertions). Darunter Rechte/Teamtrennung, persönliche Merker, direkte ID-Manipulation, Handy-Sperre, Migration vorwärts/rückwärts, Filter/Pagination, RSS-Datumsprüfung und bestehende Trendautomatik. Desktop und Handy wurden zusätzlich am gerenderten lokalen Komponentenlayout geprüft; die lokale Browservorschau verwendet Testdaten und ist keine Live-Verbindung.

Deployment erst nach ausdrücklichem Go über das bestehende `food-alchemist.de/update.sh`, ausschließlich Foodalchemist. Anschließend additive Migration und Funktionskontrolle mit echten Daten. Keine Änderungen an anderen Plattformmodulen.

## Radar und Research-Navigation

Das Observatorium-Radar besitzt eigene URL-gebundene Filter für Name/Definition, Kategorie, Sparte, Trend/Hype, Hierarchie und Befragungsbestätigung. Grafik und Entwicklungsliste verwenden denselben gefilterten Bestand aus TrendService und ausschließlich freigegebene Status. Die Meldungsfilter bleiben davon unabhängig. Ein leeres Filterergebnis wird vom noch leeren Radar unterschieden. Symbolgrößen sind von der responsiven Grafik getrennt.

Research startet mit der Trendverwaltung. Pinnwand, Eingang, Suchthemen, Quellen und Automatik stehen in der vorhandenen einklappbaren Bereichsspalte. Kontextfremde Trendfilter und Kennzahlen entfallen in den Quellen-/Suchthemen-/Automatikansichten. Details öffnen erst nach Auswahl; direkte Links aus dem Radar öffnen die Einordnung. Die bisherige explizite Radar-URL bleibt für bestehende Links erreichbar, ist jedoch kein zweiter Navigationseintrag. „Veröffentlichtes Radar“ verweist ins Observatorium.

Die Pinnwand bleibt die gemeinsame Sammlung mit bisherigen Rechten und Zuordnungsaktionen. Die persönliche Merkliste bleibt getrennt. Direkte Bereichslinks aus dem Observatorium werden auch am Handy auf den passenden Research-Reiter abgebildet. Keine Migration und keine Änderungen an Auswertungsregeln oder gespeicherten Freigaben erforderlich.

## Eigene Handyansicht

Die vorhandene Geräteentscheidung über `HandyLesemodus` wählt die mobile Observatorium-Ausgabe. Sie verwendet die gesamte Inhaltsbreite ohne eingeklappte Desktop-Seitenleiste. Ein Bereichswähler und ein Filterknopf ersetzen die Bereichsspalte. Filter sind zunächst geschlossen, zeigen aktive Einschränkungen und bleiben über denselben Livewire-Zustand mit derselben serverseitigen Abfrage verbunden. Der Filterbereich öffnet inline und lässt sich mit „Ergebnisse ansehen“ oder Escape schließen.

Mobil erscheinen freigegebene Radarentwicklungen als Karten mit Kategorie, Einordnung, Konfidenz und Link zu den Belegen; die Ringgrafik bleibt am Computer. Dekorative Illustrationen entfallen auf dem Handy, damit Inhalte unmittelbar sichtbar werden. Persönliche Merkfunktionen, Research-/Pinnwand-Verweise, Detailansichten und Pagination bleiben erhalten. Die mobile Modulwahl unter „Mehr“ enthält das Observatorium bereits.

Keine Migration, keine Änderungen an Quellen, Freigaben, KI-Auswertung oder anderen Modulen. Der Handy-Test prüft Navigation ohne Seitenleiste, Meldungs-/Radarfilter, persönliche Merker, Detailwechsel und die weiterhin vorhandene Desktopgrafik. Layoutkontrolle mit 390 Pixeln Breite und geschlossenem/geöffnetem Filterbereich.

Radarfilter liegen ausschließlich im Inhaltsbereich der Radarseite, auf allen Geräten hinter „Radar filtern“ beziehungsweise dem mobilen Filterknopf. Die mittlere Observatorium-Spalte enthält nur Bereichsnavigation und Rechercheverweise. Auf anderen Observatorium-Seiten werden keine Radarfilter gerendert.
