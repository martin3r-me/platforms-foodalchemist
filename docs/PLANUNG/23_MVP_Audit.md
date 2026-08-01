# MVP- und Codequalitäts-Audit Food Alchemist

**Stand:** 2026-07-28 · **Umsetzungsstand nachgeführt:** 2026-07-30
**Status:** vollständig geprüft; Stabilisierung läuft (Paket R, Branch `fix/paket-r-rezepte-gerichte`)
**Ziel:** Ein zeitnah stabiles Produkt, dessen Kernfunktion ohne KI funktioniert.
**Audit-Scope:** Review; der ursprüngliche Audit änderte weder Produktcode noch Masterdaten.

> **Umsetzungsstand 2026-07-30 (Paket R · Basisrezepte + Gerichte).** Geschlossen mit
> Testnachweis: MVP-042/043/048 (Facetten-Zähler), MVP-044/049/050 (Gerichte-Editor Modell A
> + Referenz-Autorisierung, 2 P0), MVP-046 (Zutaten-Datenverlust, 1 P0), MVP-022/023/024
> (Statusfehler + Labels). **MVP-045 ist als nicht reproduzierbar geschlossen** (ungültige
> `offsetParent`-Sonde — Details im Befund). Ergänzt: der Testdatenbank-Guard (A-05) fehlte im
> Code und ist scharfgestellt. Verbleibende P0 (Lieferanten, Wissen, Einstellungen, Concepter,
> Foodbook) sind offen. Live-Status je Befund → Abschnitt 9.

## 1. Ergebnis

Der Nicht-KI-Kern ist in allen 17 Bereichen grundsätzlich erkennbar und in der
lokalen Sandbox aufrufbar. Angebote ist im geprüften Umfang am nächsten an
MVP-stabil. Das Gesamtmodul ist dennoch **nicht MVP-stabil**. Vertiefte
End-to-End-Nachläufe für Basisrezepte, Gerichte, Concepter und Foodbook haben
zusätzliche Autorisierungs-, Deep-Link-, Speicher- und Zählerfehler belegt.
Insgesamt liegen 10 P0-, 26 P1- und 23 P2-Befunde vor.

| Priorität | Anzahl | Kernaussage |
|---|---:|---|
| P0 | 10 | Autorisierung/Datenisolation sowie potenziell rezeptübergreifendes Überschreiben |
| P1 | 26 | falsche Arbeitsvorräte, Klickziele, stille Fehler, Deep-Links und gebrochene Klassifikation |
| P2 | 23 | Wartbarkeit, Speicherklarheit, Barrierefreiheit, Sprache und Performance |
| P3 | 0 | Politur wurde gegenüber Stabilitätsrisiken bewusst nachrangig behandelt |

### Belastbare Stabilitätseinschätzung

- **Nicht stabil:** Lieferanten, Basisrezepte, Gerichte, Concepter,
  Foodbook/Portfolio, Wissen, Einstellungen.
- **Eingeschränkt nutzbar:** Dashboard, Signale, Grundprodukte, Geschirr,
  Favoriten, Preissimulation, Speiseplan, Produktion und Bestellungen.
- **Im geprüften read-only Umfang MVP-stabil:** Angebote. Schreibpfade sind durch
  vorhandene Komponenten-/Servicetests, nicht durch einen vollständigen
  Browser-E2E-Pfad abgesichert; deshalb ist dies keine Produktionsfreigabe.

## 2. Vorgehen, Grenzen und Evidenz

- Gelesen: Root-`README.md`, Modul-`README.md`, `LLM_GUIDE.md`,
  Sandbox-Hinweise sowie relevante Routen, Livewire-Komponenten, Views,
  Services, Modelle und Tests.
- Lokal geprüft über `http://localhost:8765` nach Sandbox-Login. Die
  Modulrouten sind an die Domain `localhost` gebunden; `127.0.0.1` liefert
  erwartungsgemäß 404 und ist kein Moduldefekt.
- Alle 17 Bereiche wurden in der geforderten Reihenfolge per echtem Browserpfad
  geöffnet. Gefahrlos read-only geprüft wurden Navigation, Links, Suche/Filter,
  Auswahl, Pagination/Leerzustände, URL-Kontext, sichtbare Zähler,
  Browser-Zurück sowie kleine Breite. Formulare wurden nicht abgesendet.
- Die Live-Referenz `https://demo.bhgdigital.de/foodalchemist` leitet auf
  `/login` mit Microsoft-Anmeldung um. Es lag keine authentifizierte Sitzung
  vor. Daher waren live weder Navigation noch fachliche UI read-only
  vergleichbar; es wurden keine Login- oder Schreibversuche unternommen.
- Schreibpfade wurden ausschließlich anhand vorhandener isolierter Tests und
  statischer Transaktions-/Autorisierungsevidenz bewertet. Keine Migration,
  kein Composer-Update und keine Änderung vorhandener Masterdaten.
- Browserkonsole lokal: wiederholtes `Laravel Echo cannot be found`. Das ist
  nach aktuellem Stand eine Sandbox-/Host-Integration und wird nicht als
  Food-Alchemist-Produktfehler gezählt.
- Ein vollständiger `pest --testsuite=FoodAlchemist`-Lauf wurde in der isolierten
  Testdatenbank abgeschlossen; Ergebnis siehe Abschnitt 7.

## 3. Modul-Matrix

Die Zählung ordnet jeden Befund genau einem Bereich zu; übergreifende Auswirkungen
werden im jeweiligen Befund genannt.

| Nr. | Bereich | Status | Geprüfte Pfade | Offener Blocker | P0/P1/P2/P3 |
|---:|---|---|---|---|---:|
| – | Übergreifend | eingeschränkt | Regeln, Routen, Tests, Desktop, 390 px | keine E2E-Schicht; Mobil-Layout | 0/3/0/0 |
| 1 | Dashboard | eingeschränkt | Kacheln, Zähler, Links, Zurück, Mobil | Arbeitsvorräte stimmen nicht mit Zielen überein | 0/3/2/0 |
| 2 | Signale | eingeschränkt | Liste, Filter, Detail, Aktionen read-only | leere Detailverträge; schwere Renderlogik | 0/1/3/0 |
| 3 | Lieferanten | nicht stabil | Lieferant/Artikel, Suche, Pagination, Aktionen read-only | geerbte Löschung und teamfremde Vorschläge | 2/1/1/0 |
| 4 | Grundprodukte | eingeschränkt | Suche, Status, Filter, Pagination, Neu-Modal read-only | stille Statusfehler; Mobil-Layout | 0/1/1/0 |
| 5 | Geschirr | eingeschränkt | Lieferant, Suche, Liste, Neu/Edit read-only | URL-/Beschriftungs- und Skalierungsdefizit | 0/0/1/0 |
| 6 | Favoriten | eingeschränkt | Suche, Pin-Filter, Liste | harte 100er-Grenze und falscher Kontext | 0/1/1/0 |
| 7 | Basisrezepte | nicht stabil | Einstieg, HG/Kategorien, Kombifilter, Deep-Link, Detail/Editor, Neu-Kaskade, Reload read-only | fremde Kategorie-IDs; globaler Zutaten-Save; Edit-Modal unsichtbar | 2/4/2/0 |
| 8 | Gerichte | nicht stabil | Einstieg, HG/Klassen, Kombifilter, Detail/Editor, Neu, Klassifikationskaskade read-only | fremde Klassen/AK; Klassifikation und Edit-Klick gebrochen | 1/2/1/0 |
| 9 | Concepter | nicht stabil | Concepts/Pakete, Suche/Leerzustand, Facetten, Auswahl, Deep-Link/Reload, Detail/Editor und Speichern statisch/read-only | fremde Dimensions-IDs; Deep-Link; nicht atomarer Misch-Save | 1/3/2/0 |
| 10 | Foodbook/Portfolio | nicht stabil | Auswahl, Suche/Leerzustand, Phase, Buch/Kapitel, Deep-Link/Reload, Tabs, Dokumentlinks und Speichern statisch/read-only | Kapitel-/Block-Datenleck; leere Deep-Link-Formulare; Misch-Save | 1/2/4/0 |
| 11 | Angebote | im read-only Umfang stabil | Liste, Filter, URL-Auswahl, Detail, Neu read-only | Live-Vergleich und Browser-Schreibpfad fehlen | 0/0/0/0 |
| 12 | Preissimulation | eingeschränkt | Scope, Referenz, Delta, Ergebnis read-only | Artikel nur per interner ID; Scope-Lücke | 0/2/1/0 |
| 13 | Speiseplan | eingeschränkt | Suche, URL-Auswahl, Zyklus/Matrix read-only | Matrix-Bedienung nicht barrierearm | 0/0/1/0 |
| 14 | Produktion | eingeschränkt | Suche, Status/Datum, Liste, Neu read-only | Vollmenge im PHP-Speicher | 0/0/1/0 |
| 15 | Bestellungen | eingeschränkt | Schienen, Filter, Detail/Leerzustand, Neu read-only | stille Ladefehler; keine skalierende Liste | 0/1/1/0 |
| 16 | Wissen | nicht stabil | 1.021 Dokumente, Suche, Filter, Auswahl read-only | teamfremdes Lesen/Ändern; Vollrendering | 2/1/0/0 |
| 17 | Einstellungen | nicht stabil | 17 Sektionen, URL-Navigation, Formulare read-only | geerbte Klassen veränder-/löschbar | 1/1/1/0 |

## 4. Befunde

### Übergreifend

#### MVP-001 · Modul-README beschreibt ein veraltetes Template

| Feld | Wert |
|---|---|
| Bereich / Priorität / Art | Übergreifend · P1 · Codequalität/Dokumentation |
| Fundort | `README.md:1-55`, `LLM_GUIDE.md:1-88` |
| Reproduktion | Modul-README gegen registrierte 32 Food-Alchemist-Routen und reale Navigation vergleichen. |
| Ist-Verhalten | Dokumentiert werden im Wesentlichen Dashboard, Testseite und Sidebar eines Templates. |
| Erwartet | Architektur, 17 Bereiche, Sandbox-Start, Hostbindung, Teststrategie und Grenzen sind aktuell dokumentiert. |
| Ursache/Evidenz | Dokumente wurden seit dem Ausbau des Templates nicht als Produktdokumentation nachgeführt. |
| Empfehlung | README auf Ist-Architektur umstellen; `LLM_GUIDE.md` von generischen Regeln trennen und veraltete Aussagen entfernen. |

#### MVP-002 · Keine durchgehende Browser-E2E-Testschicht

| Feld | Wert |
|---|---|
| Bereich / Priorität / Art | Übergreifend · P1 · Testlücke |
| Fundort | `tests/`; keine Dusk-, Panther-, Playwright- oder vergleichbare Klickpfad-Suite gefunden |
| Reproduktion | Tests nach Navigation → Modal → abhängige Auswahl → Persistenz → Reload/Zurück durchsuchen. |
| Ist-Verhalten | Viele Service-, Livewire- und Featuretests prüfen Teilstücke, nicht die reale Strecke. |
| Erwartet | Pro Bereich mindestens ein manueller Nicht-KI-Happy-Path plus wichtigste Fehler-/Rechtepfade automatisiert. |
| Ursache/Evidenz | Isolierte Komponentenabdeckung ersetzt keinen Browservertrag; mehrere reale URL-/Layoutfehler bleiben dadurch grün. |
| Empfehlung | Kleine E2E-Smoke-Suite mit Seed-Fixtures, Team-A/B und Desktop/Mobil aufbauen. |

#### MVP-003 · Gemeinsames Layout ist bei 390 px nicht nutzbar

| Feld | Wert |
|---|---|
| Bereich / Priorität / Art | Übergreifend · P1 · Bug/Barrierefreiheit |
| Fundort | Browserprüfung Dashboard und `/foodalchemist/gps`; gemeinsames dreispaltiges Layout |
| Reproduktion | Viewport 390 × 844 öffnen; Dashboard beziehungsweise GP-Browser laden. |
| Ist-Verhalten | 288-px-Sidebar bleibt offen; Dashboardkarten kollabieren zu schmalen Spalten. Im GP-Browser beginnen Neu-Knopf und Tabelle bei x≈632 außerhalb des 390-px-Dokuments. |
| Erwartet | Navigation klappt ein; Hauptinhalt bleibt vollständig erreichbar und bedienbar. |
| Ursache/Evidenz | Desktop-Spalten/Mindestbreiten ohne responsive Umschaltung; `documentElement.scrollWidth` blieb 390, also kein erreichbarer Dokument-Scroll zur Tabelle. |
| Empfehlung | Mobilen Shell-Breakpoint einführen, Sidebar als Drawer, Tabellen als erreichbare Scrollregion/Karten; zwei repräsentative Mobil-E2E-Tests. |

### Dashboard

#### MVP-004 · Review-Zähler und Klickziel verwenden verschiedene Mengen

| Feld | Wert |
|---|---|
| Bereich / Priorität / Art | Dashboard · P1 · Bug |
| Fundort | `src/Livewire/Dashboard.php`; Kachel „Im Review“ |
| Reproduktion | Dashboard: 82 „Im Review“ anklicken; Ziel `/rezepte?status=review` zeigt 56. |
| Ist-Verhalten | Kachel zählt Basis- und Verkaufsrezepte, Link öffnet nur Basisrezepte. |
| Erwartet | Sichtbarer Zähler, Filter und Zielmenge sind identisch. |
| Ursache/Evidenz | Aggregation über die gemeinsame Rezepttabelle, Zielroute ist ausschließlich `recipes.index`. |
| Empfehlung | Getrennte Kacheln für Basisrezepte/Gerichte oder gemeinsames, exakt gleich gefiltertes Ziel. |

#### MVP-005 · Allergen-Lücken öffnen eine ungefilterte Liste

| Feld | Wert |
|---|---|
| Bereich / Priorität / Art | Dashboard · P1 · Bug/UX-Schuld |
| Fundort | Dashboard-Kachel „Allergen-Lücken“ und Link auf Basisrezepte |
| Reproduktion | Kachel mit aktuell 1 Lücke anklicken. |
| Ist-Verhalten | Basisrezept-Browser öffnet ohne passenden aktiven Filter. |
| Erwartet | Genau die gezählte Lückenmenge wird als reproduzierbarer Arbeitsvorrat angezeigt. |
| Ursache/Evidenz | Für niedrige/unbekannte Konfidenz beziehungsweise ungemappte Zutaten existiert kein URL-Filtervertrag. |
| Empfehlung | Stabile Filterdefinition ergänzen und Kachel/Ziel per Integrationstest koppeln. |

#### MVP-006 · „VK ohne Klasse“ öffnet keinen passenden Filter

| Feld | Wert |
|---|---|
| Bereich / Priorität / Art | Dashboard · P1 · Bug/UX-Schuld |
| Fundort | Dashboard-Kachel „VK ohne Klasse“ |
| Reproduktion | Kachel anklicken; Gerichte-Browser beobachten. |
| Ist-Verhalten | Alle Gerichte werden geöffnet. |
| Erwartet | Ausschließlich Gerichte ohne Klasse, mit sichtbarem URL-/UI-Filter. |
| Ursache/Evidenz | Zielroute erhält keinen Filterparameter. |
| Empfehlung | URL-Filter implementieren und Zählerabfrage mit derselben Query absichern. |

#### MVP-007 · Lieferantenartikel-Kachel hat kein eindeutiges Ziel

| Feld | Wert |
|---|---|
| Bereich / Priorität / Art | Dashboard · P2 · UX-Schuld/Produktentscheidung |
| Fundort | Kachel „Lieferantenartikel“ |
| Reproduktion | Lieferantenartikel- und Grundprodukte-Kachel nacheinander öffnen. |
| Ist-Verhalten | Beide führen in den Grundprodukt-Browser, ohne den versprochenen Artikelkontext. |
| Erwartet | Eigenständiger Arbeitsvorrat oder eine Benennung, die das tatsächliche Ziel erklärt. |
| Ursache/Evidenz | GP-LA-Beziehung wird als technische Navigation verwendet, aber fachlich nicht sichtbar gemacht. |
| Empfehlung | Produktentscheidung treffen; bei gemeinsamem Browser einen aktiven Artikelfilter setzen. |

#### MVP-008 · Nicht handlungsfähige Flächen verdrängen operative Aufgaben

| Feld | Wert |
|---|---|
| Bereich / Priorität / Art | Dashboard · P2 · UX-Schuld/Nicht-KI |
| Fundort | KI-Nutzung und Portfolio-Benchmark |
| Reproduktion | Dashboard ohne Peer-Vergleich und ohne benötigte KI betrachten. |
| Ist-Verhalten | Benchmark zeigt überwiegend Striche; KI-Nutzung belegt prominent Fläche ohne Kernaktion. |
| Erwartet | Nicht-KI-Arbeitsvorräte und Datenlücken stehen zuerst; leere Sekundärblöcke sind verborgen. |
| Ursache/Evidenz | Capability-/Datenmengen-abhängige Darstellung fehlt. |
| Empfehlung | Blöcke nur bei aussagefähigen Daten/aktivierter KI zeigen und operatives Dashboard priorisieren. |

**Positiv geprüft:** Route, Bestandszahlen und alle Kachellinks laden; der
`status=review`-URL-Filter wird im Basisrezept-Browser übernommen.

### Signale

#### MVP-009 · „Reinschauen“ öffnet bei aggregierten Signalen 0 Objekte

| Feld | Wert |
|---|---|
| Bereich / Priorität / Art | Signale · P1 · Bug/Erwartungsvertrag |
| Fundort | erstes Signal „approved-GPs ohne Lead-LA“, Detailpanel |
| Reproduktion | `/zu-pruefen` → erstes aggregiertes Signal → „Reinschauen“. |
| Ist-Verhalten | Detail öffnet, zeigt aber „Betroffene Objekte: 0“ und erst dort die fehlende Einzelauflösung. |
| Erwartet | Objektliste oder eine ehrliche Erklär-/Navigationsaktion statt „Reinschauen“. |
| Ursache/Evidenz | Aggregat besitzt keine aufgelösten Objekt-IDs, verwendet aber denselben Aktionsvertrag. |
| Empfehlung | Signaltypen mit Objektauflösung ausstatten oder CTA je Typ differenzieren. |

#### MVP-010 · Englische Schemawerte gelangen in die deutsche UI

| Feld | Wert |
|---|---|
| Bereich / Priorität / Art | Signale · P2 · UX-Schuld |
| Fundort | Texte wie „approved-GPs“, „tentative GPs“ |
| Reproduktion | Signalliste und Detailtexte lesen. |
| Ist-Verhalten | Interne Enum-/Schemawörter mischen sich mit deutscher Fachsprache. |
| Erwartet | Zentrale, konsistente deutsche Labels. |
| Ursache/Evidenz | Rohwerte werden in Meldungstemplates interpoliert. |
| Empfehlung | Label-Mapper für Status/Typen zentralisieren und Snapshot-Test für UI-Texte. |

#### MVP-011 · Inaktive Tabs werden im Renderpfad mitberechnet

| Feld | Wert |
|---|---|
| Bereich / Priorität / Art | Signale · P2 · Codequalität/Performance |
| Fundort | `src/Livewire/ReviewQueue.php::render()` |
| Reproduktion | Komponente statisch verfolgen beziehungsweise einen beliebigen Tab rendern. |
| Ist-Verhalten | Überblick, Signale, Vorschläge, Matches, Pflege, Zähler und Listen werden gemeinsam aufgebaut. |
| Erwartet | Schwere Abfragen nur für den aktiven Tab; kleine gemeinsame Kennzahlen separat. |
| Ursache/Evidenz | Read-Queries und Tab-Orchestrierung sind in einer großen Render-Methode gekoppelt. |
| Empfehlung | Tab-spezifische Query-Services/Unterkomponenten und Abfragebudget-Tests. |

#### MVP-012 · KI-Aktionen dominieren einen manuellen Qualitätsbereich

| Feld | Wert |
|---|---|
| Bereich / Priorität / Art | Signale · P2 · UX-Schuld/Nicht-KI |
| Fundort | „KI-Befunde sammeln“, wiederholte „KI erledigen lassen“-Aktionen |
| Reproduktion | Signalliste ohne KI-Bedarf/Provider betrachten. |
| Ist-Verhalten | KI-CTAs sind gleich- oder höherwertig als deterministische manuelle Wege. |
| Erwartet | Kernablauf bleibt sichtbar vollständig manuell; KI ist optionale Beschleunigung. |
| Ursache/Evidenz | Capability-abhängige Priorisierung/Ausblendung fehlt. |
| Empfehlung | KI nur bei konfigurierter Capability zeigen und manuelle Aktionen zuerst platzieren. |

**Positiv geprüft:** Route, Status-/Typfilter und Detailpanel funktionieren; für
Lebenszyklus und Detailpanel existieren Komponenten-/Servicetests.

### Lieferanten

#### MVP-013 · Geerbte Lieferantenartikel können gelöscht werden

| Feld | Wert |
|---|---|
| Bereich / Priorität / Art | Lieferanten · P0 · Bug/Autorisierung |
| Fundort | `src/Livewire/Suppliers/Index.php:233-237` |
| Reproduktion | Als Kindteam eine geerbte, via `visibleToTeam()` sichtbare Artikel-ID in `bulkAuswahl` setzen und `bulkLoeschen()` aufrufen. |
| Ist-Verhalten | Sichtbarkeit genügt für `findOrFail(...)->delete()`; Eigentum/Curate-Recht wird nicht geprüft. |
| Erwartet | Nur eigene Datensätze dürfen gelöscht werden; geerbte Datensätze sind read-only oder explizit kuratiert. |
| Ursache/Evidenz | `visibleToTeam()` ist ein Lesescope und wird fälschlich als Schreibautorisierung verwendet. |
| Empfehlung | Serverseitig `isOwnedBy()`/Policy prüfen, Transaktion verwenden und negativen Team-A/B-Test ergänzen. |

#### MVP-014 · Match-Vorschläge sind nicht nach Team gescopt

| Feld | Wert |
|---|---|
| Bereich / Priorität / Art | Lieferanten · P0 · Bug/Datenleck |
| Fundort | `src/Livewire/Suppliers/Index.php:318-325` |
| Reproduktion | Zwei Teams mit Vorschlägen zum selben sichtbaren Lieferanten anlegen; Browser als Team A rendern. |
| Ist-Verhalten | Query filtert über `item.supplier_id`, aber nicht über Team/Ancestry der Vorschläge. |
| Erwartet | Nur für das aktive Team sichtbare Vorschläge und Zähler. |
| Ursache/Evidenz | Team-Scope fehlt vollständig in der Renderabfrage. |
| Empfehlung | Vorschlagsmodell mit `visibleToTeam()`/explizitem Team-Scope abfragen; Leak-Test ergänzen. |

#### MVP-015 · Bulk-Aktionen können teilweise persistieren

| Feld | Wert |
|---|---|
| Bereich / Priorität / Art | Lieferanten · P1 · Bug/Race Condition |
| Fundort | `src/Livewire/Suppliers/Index.php:268-280` |
| Reproduktion | Bulk-Aktion mit mehreren IDs ausführen, bei einer späteren ID einen Servicefehler provozieren. |
| Ist-Verhalten | Schleife führt Einzelwrites ohne umfassende Transaktion aus; frühere Änderungen können bestehen bleiben. |
| Erwartet | Atomarer Erfolg oder vollständiger Rollback mit sichtbarer Fehlermeldung. |
| Ursache/Evidenz | Transaktionsgrenze umfasst nicht den gesamten Batch. |
| Empfehlung | Gesamten Batch transaktional validieren/schreiben; Konflikt- und Teilfehler-Test. |

#### MVP-016 · Lieferantennavigation lädt die Vollmenge

| Feld | Wert |
|---|---|
| Bereich / Priorität / Art | Lieferanten · P2 · Performance/UX-Schuld |
| Fundort | Lieferanten-Browser, linke Liste; `src/Livewire/Suppliers/Index.php` |
| Reproduktion | Lokale Instanz mit 120 Lieferanten öffnen. |
| Ist-Verhalten | Alle 120 Lieferanten einschließlich vieler 0-Artikel-Einträge werden ohne Pagination gerendert. |
| Erwartet | Suchbare, virtualisierte/paginierte Liste mit klarer aktiver Auswahl. |
| Ursache/Evidenz | Vollmengenliste wird für die Sidebar geladen. |
| Empfehlung | Server-Pagination/virtuelle Liste; Defaultauswahl explizit in URL normalisieren. |

**Positiv geprüft:** zwei Suchebenen, Inaktivfilter, Artikelfilter,
100er-Pagination, leerer Artikelzustand und manuelle Neu-/Editierwege sind
vorhanden.

### Grundprodukte

#### MVP-017 · Statusänderungsfehler bleiben unsichtbar

| Feld | Wert |
|---|---|
| Bereich / Priorität / Art | Grundprodukte · P1 · Bug/Fehlerzustand |
| Fundort | `src/Livewire/Gps/Browser.php:90-94`; analog `Recipes/Browser.php` und `Verkauf/Browser.php` |
| Reproduktion | Statuswechsel mit fachlich ungültigem Übergang oder Service-Exception auslösen. |
| Ist-Verhalten | `RuntimeException` wird gefangen, ohne Fehlermeldung oder sichtbaren Rollback. |
| Erwartet | Toast/Inlinefehler; Auswahl springt verlässlich auf persistierten Status zurück. |
| Ursache/Evidenz | Leerer Catch-Pfad. |
| Empfehlung | Gemeinsamen Status-Action-Handler mit Fehlermapping und Integrationstest verwenden. |

#### MVP-018 · Inline-Statusfelder sind nicht eindeutig beschriftet

| Feld | Wert |
|---|---|
| Bereich / Priorität / Art | Grundprodukte · P2 · Barrierefreiheit/UX-Schuld |
| Fundort | GP-Tabelle, Status-`select` je Zeile |
| Reproduktion | Seite per Tastatur/Accessibility-Snapshot durchlaufen. |
| Ist-Verhalten | Wiederholte Selects besitzen keinen kontextuellen zugänglichen Namen. |
| Erwartet | „Status für [Grundprodukt]“ und sichtbarer Fokus/Fehlerkontext. |
| Ursache/Evidenz | Tabellenkontext wird nicht per Label/`aria-label` an das Feld gebunden. |
| Empfehlung | Zeilenbezogene Labels ergänzen; Tastatur-Smoke-Test. |

**Positiv geprüft:** 189 Treffer, Statuszähler 183/5/1/0, Suche, Facetten,
Pagination, URL-Parameter und manuelles Neu-Modal laden ohne KI.

### Geschirr

#### MVP-019 · Auswahlkontext und Eingabebeschriftung sind lückenhaft

| Feld | Wert |
|---|---|
| Bereich / Priorität / Art | Geschirr · P2 · UX-Schuld/Barrierefreiheit |
| Fundort | `src/Livewire/Geschirr/Index.php`, Geschirr-Browser |
| Reproduktion | Route ohne Lieferantenparameter laden; Accessibility-Snapshot der Formfelder prüfen. |
| Ist-Verhalten | Erster Lieferant wird intern gewählt, URL bleibt unspezifisch; mehrere Inputs/Selects haben keinen eindeutigen Namen. Vollständige Lieferantenliste wird geladen. |
| Erwartet | Reproduzierbarer URL-Kontext, klar beschriftete Felder, skalierende Navigation. |
| Ursache/Evidenz | Defaultauswahl erfolgt während Rendern; Kontext- und Labelvertrag fehlt. |
| Empfehlung | Kanonische URL nach Auswahl setzen, Labels ergänzen und Lieferantenliste paginieren/virtualisieren. |

**Positiv geprüft:** Suche, Lieferantenauswahl, Pagination, leerer Zustand sowie
manuelle Neu-/Editier-/Deaktivierwege sind vorhanden; Servicegrenzen sind
übersichtlicher als in mehreren Nachbarbereichen.

### Favoriten

#### MVP-020 · Harte 100er-Grenze verfälscht Liste und Pin-Zähler

| Feld | Wert |
|---|---|
| Bereich / Priorität / Art | Favoriten · P1 · Bug/UX-Schuld |
| Fundort | `src/Livewire/Favorites/Index.php`; `limit = 100`, `suggest(..., $limit)` |
| Reproduktion | Bei mehr als 100 passenden Grundprodukten `/favoriten` öffnen und Zähler/Ergebnisse vergleichen. |
| Ist-Verhalten | Maximal 100 Ergebnisse, keine Pagination; `anzahlGepinnt` zählt nur den aktuellen Slice. |
| Erwartet | Vollständige paginierte Zielmenge und global korrekter Pin-Zähler. |
| Ursache/Evidenz | Ergebnislimit wird zugleich als Datenmenge und Zählerbasis verwendet. |
| Empfehlung | Separaten Count-Query und Cursor-/Seitenpagination einführen. |

#### MVP-021 · Suche/Filter verlieren URL-Kontext; Sternknöpfe sind mehrdeutig

| Feld | Wert |
|---|---|
| Bereich / Priorität / Art | Favoriten · P2 · UX-Schuld/Barrierefreiheit |
| Fundort | `src/Livewire/Favorites/Index.php`; `q` und `nurGepinnt` ohne `#[Url]`; View-Zeilen 18-30 |
| Reproduktion | Suche/Pinfilter setzen, reload/zurück; per Tastatur durch Sternknöpfe navigieren. |
| Ist-Verhalten | Kontext geht verloren; bis zu 100 Buttons heißen identisch „★ pinnen“. |
| Erwartet | Teilbarer/wiederherstellbarer Filter und „[GP] an-/abpinnen“. |
| Ursache/Evidenz | URL-Binding und kontextuelle Accessible Names fehlen. |
| Empfehlung | `#[Url]` ergänzen, Buttons mit GP-Namen beschriften; Textfehler „jeder Grundprodukt“ korrigieren. |

**Positiv geprüft:** manueller Pin-/Unpin-Weg, Suche, Nur-gepinnt-Filter und
serverseitige Eigentumsprüfung `isOwnedBy()` sind vorhanden.

### Basisrezepte

#### MVP-022 · Statusfehler werden auch im Rezeptbrowser verschluckt

| Feld | Wert |
|---|---|
| Bereich / Priorität / Art | Basisrezepte · P1 · Bug/Fehlerzustand |
| Fundort | `src/Livewire/Recipes/Browser.php` Statusaktion |
| Reproduktion | Ungültigen Übergang oder Servicefehler auslösen. |
| Ist-Verhalten | Exception wird ohne Nutzerfeedback abgefangen. |
| Erwartet | Sichtbarer Grund und Rollback auf den persistierten Wert. |
| Ursache/Evidenz | Derselbe leere Catch-Ansatz wie bei GPs/Gerichten. |
| Empfehlung | Gemeinsame robuste Statuskomponente; negative Livewire-Tests. |

#### MVP-023 · Interne Enumwerte und versteckte Modal-DOM schwächen die UI

| Feld | Wert |
|---|---|
| Bereich / Priorität / Art | Basisrezepte · P2 · UX-Schuld/Barrierefreiheit |
| Fundort | Rezeptliste/-Modal; Werte `from_scratch`, `medium`, `Stub` |
| Reproduktion | Liste visuell und per Accessibility-Snapshot prüfen. |
| Ist-Verhalten | Englische/interne Werte erscheinen; versteckte Modals liefern zusätzliche Controls/Headings im DOM. |
| Erwartet | Fachliche deutsche Labels; inaktive Dialoge sind für Assistenztechnik verborgen/nicht gemountet. |
| Ursache/Evidenz | Rohwertausgabe und dauerhaft gerenderte Dialoge. |
| Empfehlung | Zentrale Label-Mapper und lazy gemountete beziehungsweise korrekt `aria-hidden` Dialoge. |

#### MVP-042 · Zwei Basisrezepte fehlen im Hauptgruppen-Zähler

| Feld | Wert |
|---|---|
| Bereich / Priorität / Art | Basisrezepte · P1 · Bug/Zähler |
| Fundort | `src/Services/RecipeService.php:22-29`; `resources/views/livewire/recipes/browser.blade.php:51-80` |
| Reproduktion | `/foodalchemist/rezepte` ohne Filter öffnen; Tabellenmenge und „Alle Hauptgruppen“ vergleichen. |
| Ist-Verhalten | Tabelle zeigt **64 Treffer**, „Alle Hauptgruppen“ nur **62**. Zwei Rezepte ohne Kategorie sind sichtbar, aber über den Baum nicht auffindbar. |
| Erwartet | Gesamtzähler entspricht der Tabelle; „Ohne Kategorie“ ist ein eigener anklickbarer Arbeitsvorrat. |
| Ursache/Evidenz | Count-Query führt einen Inner Join auf Kategorien aus und verliert `category_id = null`. |
| Empfehlung | Gesamtcount aus derselben Browserquery bilden und Null-Kategorie als explizite Facette ergänzen. |

#### MVP-043 · Kategorie-Zähler ignorieren aktive Filter und führen zu 0 Treffern

| Feld | Wert |
|---|---|
| Bereich / Priorität / Art | Basisrezepte · P1 · Bug/Klickroute |
| Fundort | `src/Services/RecipeService.php:33-41`; `src/Livewire/Recipes/Browser.php:217-222` |
| Reproduktion | `?status=review&hg=31` öffnen: Hauptgruppe zeigt 3 Treffer, Unterkategorien zeigen 1 + 1 + 2. „Glace de Viande 1“ anklicken. |
| Ist-Verhalten | Ziel-URL lautet `?status=review&hg=31&kat=189`, Ergebnis **0 Treffer**, obwohl der angeklickte Zähler 1 verspricht. |
| Erwartet | Unterkategorie-Zähler berücksichtigen Suche, Status, Geschmack, Fertigung und Templatefilter wie die Zielquery. |
| Ursache/Evidenz | `kategorieCounts()` ruft `browserQuery($team, [])` auf und verwirft alle aktiven Filter. |
| Empfehlung | Aktuelle Filter bis auf HG/Kategorie übergeben; Count- und Zielquery in einem gemeinsamen Facettenvertrag testen. |

#### MVP-044 · Kategorie-IDs werden beim Speichern nicht serverseitig autorisiert

| Feld | Wert |
|---|---|
| Bereich / Priorität / Art | Basisrezepte · P0 · Bug/Autorisierung/Datenintegrität |
| Fundort | `src/Services/RecipeService.php:125-147,186-190`; `src/Livewire/Recipes/RecipeModal.php:329-342` |
| Reproduktion | **Statisch belegt:** als Team A eine fremde Kategorie-ID in `form.category_id` einspeisen und `speichern()` aufrufen. |
| Ist-Verhalten | UI-Auswahl ist gescopt, Create/Update übernehmen jedoch die rohe ID; auch KI-Accept nutzt ungescoptes `FoodAlchemistRecipeCategory::find()`. |
| Erwartet | Kategorie gehört zur sichtbaren Team-Kette und zur übergebenen Hauptgruppe; fremde ID wird abgewiesen. |
| Ursache/Evidenz | Serverseitige Validierung verlässt sich auf die UI-Optionsliste. |
| Empfehlung | Kategorie über `visibleToTeam($team)` auflösen, HG-Konsistenz prüfen und manipulierten Team-A/B-Livewire-Test ergänzen. |

#### MVP-045 · Namensklick lädt den Editor, öffnet ihn aber nicht sichtbar — ⚠ NICHT REPRODUZIERBAR (2026-07-30)

| Feld | Wert |
|---|---|
| Bereich / Priorität / Art | Basisrezepte · P1 · Bug/Klickroute — **geschlossen als nicht reproduzierbar** |
| Fundort | `resources/views/livewire/recipes/browser.blade.php:191-200`; `src/Livewire/Recipes/Browser.php:60-65`; `RecipeModal.php:46-57,78-113` |
| Reproduktion | Basisrezeptname beziehungsweise dessen Namenszelle anklicken. |
| Ist-Verhalten | **Der Editor öffnet sichtbar.** Nachgemessen in der Sandbox (Viewport 1280×844, Rezept 461): `Alpine.$data(dialog).open === true`, Wrapper füllt den Viewport, `document.elementFromPoint()` auf der Mitte des Speichern-Knopfes trifft den Knopf selbst. Gegenprobe mit unverändertem Code: identisches Verhalten. |
| Ursache der Fehldiagnose | Die Evidenz `offsetParent = false` ist eine ungültige Sonde: `offsetParent` ist für `position: fixed`-Elemente laut Spezifikation **immer** `null`, unabhängig von der Sichtbarkeit — der Modal-Wrapper ist fixed. Belastbar sind `checkVisibility()` und ein Hit-Test per `elementFromPoint`. Zweite Falle: Messung direkt nach dem Klick misst mitten im Livewire-Roundtrip (Serverzustand/Markup noch der Stand von vorher). |
| Konsequenz für die Messreihe | Die Preview-Pane meldete beim Nachmessen zeitweise ein 0×0-Viewport, in dem jede Geometriemessung wertlos ist. **Alle geometriebasierten Befunde derselben Messreihe brauchen eine Nachprüfung, bevor daran gearbeitet wird — insbesondere MVP-003** (390-px-Layout: „Neu-Knopf und Tabelle beginnen bei x≈632"). |
| Status | Fix-Versuch (`766e90f`) zurückgebaut (`a76c8d5`). Behalten: der korrigierte `modal.closed`-Kommentar in VkModal und ein Öffnen-Vertragstest (`EditorOeffnenVertragTest`) auf der Kette Namensklick → Öffnen-Event → geladener Editor → Rückweg. |

#### MVP-046 · Globales Zutaten-Speicherevent kann mehrere Rezepte überschreiben

| Feld | Wert |
|---|---|
| Bereich / Priorität / Art | Basisrezepte/Gerichte · P0 · Bug/Datenverlust/Race Condition |
| Fundort | `recipe-modal.blade.php:9,512`; `ingredient-editor.blade.php:177-181`; `recipes/browser.blade.php:105`; `recipe-modal.blade.php:222`; analog `verkauf/browser.blade.php:99` und `vk-modal.blade.php:282` |
| Reproduktion | **Statisch vollständig nachvollziehbar:** Standalone-Zutateneditor für Rezept A öffnen/schließen, Voll-Editor für Rezept B öffnen und dessen globales „Speichern“ auslösen. |
| Ist-Verhalten | Jede montierte Alpine-Instanz lauscht auf das ungescopte Window-Event `zutaten-speichern` und ruft ihren eigenen `$wire.speichern(payload)` auf. Der Standalone-Editor setzt `recipeId` beim Schließen nicht zurück. Damit können A und B gleichzeitig mit unterschiedlichen/stalen Payloads gespeichert werden. |
| Erwartet | Genau der zum sichtbaren Editor gehörende Zutatenstand wird einmal gespeichert. |
| Ursache/Evidenz | Globaler Broadcast ohne Rezept-/Instanz-ID trifft parallel montierte Standalone- und Embedded-Editoren. |
| Empfehlung | Save-Command mit eindeutiger Editor-/Rezept-ID adressieren oder Zutaten als orchestrierten Child-Command aufrufen; Standalone-State beim Close löschen; Race-Test mit zwei Rezepten. |

#### MVP-047 · Komplexer Speichern-Abschluss ist nicht atomar und ohne Dirty-State

| Feld | Wert |
|---|---|
| Bereich / Priorität / Art | Basisrezepte/Gerichte · P2 · UX-Schuld/Transaktion |
| Fundort | `RecipeModal.php:115-163`; `IngredientEditor.php:52-75`; `recipe-modal.blade.php:9,512`; `vk-modal.blade.php:8,435-470` |
| Reproduktion | Stammdaten und Zutaten ändern, dann Haupt-Speichern; in einem Child-Write eine Validierung provozieren. |
| Ist-Verhalten | Parent- und Zutaten-Save laufen als getrennte Requests/Transaktionen. Der Parent kann schließen, obwohl der Child fehlschlägt. Es gibt keinen sichtbaren Dirty-/Saving-/Saved-Zustand; im langen Basis-Editor existieren zwei gleichnamige Save-Buttons. Darreichungen autosaven separat per `wire:change`. |
| Erwartet | Komplexer Editor hat einen klaren Abschluss, bleibt bei Teilfehler offen und zeigt geänderten/speichernden/gespeicherten Zustand. |
| Ursache/Evidenz | UI-Event koordiniert mehrere unabhängige Commands ohne gemeinsamen Completion-Vertrag. |
| Empfehlung | **Kein pauschales Autosave.** Stammdaten, Kategorie/Klasse, Texte und Zutaten explizit als eine orchestrierte Save-Unit abschließen; sticky Save statt doppelter Aktion, Dirty-State und Fehlerübersicht. Darreichungs-Autosave nur für atomare Felder beibehalten, mit Toast und Rollback. |

**Positiv geprüft:** 64 Basisrezepte, Suche, Status-/Geschmacks-/
Produktionsfilter, Pagination und URL-Persistenz laden. Hauptgruppe → Kategorie
funktioniert im Neu-Editor und setzt die Kategorie beim HG-Wechsel korrekt zurück.
Der manuelle Anlageweg benötigt keine KI. Der Bearbeitungs- und gemeinsame
Speicherpfad ist wegen MVP-045 bis MVP-047 nicht stabil.

### Gerichte

#### MVP-024 · Status- und Schwierigkeitswerte sind sprachlich/interaktiv inkonsistent

| Feld | Wert |
|---|---|
| Bereich / Priorität / Art | Gerichte · P2 · UX-Schuld |
| Fundort | Gerichte-Browser; `medium`, `high`, rohe Statuswerte |
| Reproduktion | Filter, Zeilenwerte und Statusaktion mit Basisrezepten vergleichen. |
| Ist-Verhalten | Deutsche und englische Rohwerte sowie abweichende Interaktionsmuster werden gemischt. |
| Erwartet | Einheitliche Labels, Statusdarstellung und Fehlerbehandlung über beide Rezeptarten. |
| Ursache/Evidenz | Getrennte Browser duplizieren Label-/Statuslogik. |
| Empfehlung | Gemeinsame Präsentationsschicht für Enums und Statusaktion, ohne fachliche Editoren zusammenzuzwingen. |

#### MVP-048 · Hauptgruppen- und Klassen-Zähler ignorieren kombinierte Filter

| Feld | Wert |
|---|---|
| Bereich / Priorität / Art | Gerichte · P1 · Bug/Klickroute |
| Fundort | `src/Services/SalesRecipeService.php:62-84`; `src/Livewire/Verkauf/Browser.php:130-145` |
| Reproduktion | Klasse „Vegan 9“ anklicken; danach weiterhin sichtbare Hauptgruppe „[KAE] Käse 1“ anklicken. |
| Ist-Verhalten | Kombinierte URL `?class=61&hg=10` liefert **0 Treffer**. Hauptgruppen-Counts ignorieren Klasse, Suche, Status und Geschmack; Klassen-Counts berücksichtigen nur optional die HG. |
| Erwartet | Jede sichtbare Facettenzahl entspricht der Menge nach Klick unter allen übrigen aktiven Filtern. |
| Ursache/Evidenz | Count-Methoden erhalten nicht denselben Filtersatz wie `paginateBrowser()`. |
| Empfehlung | Symmetrische Facettenqueries aus demselben Filterobjekt; Tests für Klasse × HG × Status/Geschmack/Suche. |

#### MVP-049 · Gerichte-Editor verwendet ein veraltetes Klassenmodell

| Feld | Wert |
|---|---|
| Bereich / Priorität / Art | Gerichte · P1 · Bug/Kernfunktion |
| Fundort | `Verkauf/Browser.php:48-58,142-145`; `Verkauf/VkModal.php:110,222-224,787-790`; `SalesRecipeService.php:114-123` |
| Reproduktion | Gericht `[AMU] …` mit sichtbarer Klasse „Vegan“ bearbeiten; Klassifikationssektion prüfen und „Amuse-Bouche“ wählen. |
| Ist-Verhalten | Browser behandelt HG und vier Root-Diätklassen als unabhängige Achsen. Editor leitet HG dagegen aus `dishClass.dish_main_group_id` ab: HG ist leer, Klasse deaktiviert. Nach Wahl „Amuse-Bouche“ bleibt die Klassenliste leer. `hauptgruppeId` ist kein gespeichertes VK-Feld; die tatsächliche `dish_main_group_id` kann dort nicht geändert werden. |
| Erwartet | Editor zeigt die bestehende HG/Klasse korrekt und kann beide gemäß demselben Datenmodell wie Browser und Service ändern. |
| Ursache/Evidenz | Browser wurde auf „Modell A“ umgestellt, VkModal blieb auf einer HG→Klasse-Kaskade. |
| Empfehlung | Ein Taxonomiemodell festlegen und Browser, Editor, Datenmigration/Seeds sowie Tests gemeinsam umstellen; bis dahin Klassifikationssave sperren statt Werte zu verlieren. |

#### MVP-050 · Klassen und Aufschlagsklassen sind im Gerichte-Editor ungescopt

| Feld | Wert |
|---|---|
| Bereich / Priorität / Art | Gerichte · P0 · Bug/Autorisierung/Datenintegrität |
| Fundort | `src/Livewire/Verkauf/VkModal.php:787-790`; `src/Services/SalesRecipeService.php:114-133` |
| Reproduktion | **Statisch belegt:** fremde `dish_class_id` oder `markup_class_id` in das öffentliche Form-Payload eines eigenen Gerichts einsetzen und speichern. |
| Ist-Verhalten | Editorqueries verwenden ungescoptes `FoodAlchemistDishClass::where(...)` und `FoodAlchemistMarkupClass::where(...)`; `updateVk()` übernimmt die Fremdschlüssel ohne Team-/Sichtbarkeitsprüfung. |
| Erwartet | Nur globale/ancestry-sichtbare und fachlich zulässige Vokabeln dürfen gelesen und zugeordnet werden. |
| Ursache/Evidenz | UI-Auswahl und Write-Service besitzen keine serverseitige Referenzautorisierung. |
| Empfehlung | Beide IDs via `visibleToTeam($team)`/Policy validieren, HG/Klasse-Konsistenz prüfen und manipulierte Team-A/B-Tests ergänzen. |

**Positiv geprüft:** 31 Gerichte, Suche, Statusmengen 0/5/26/0, Pagination,
Zeilenklick → Detail und URL `?rezept=2616` sowie der manuelle Neu-Einstieg
laden. Der optionale KI-Pfad ist getrennt. Klassen-/HG-Klickziele,
Bearbeitungsöffnung und Klassifikationsspeichern sind jedoch nicht stabil.

### Concepter

#### MVP-025 · Servierform-Facette ist nicht teamgescopt

| Feld | Wert |
|---|---|
| Bereich / Priorität / Art | Concepter · P1 · Bug/Datenleck |
| Fundort | `src/Livewire/Concepter/Browser.php:230-231` |
| Reproduktion | Team-spezifische Servierform in einem fremden Team anlegen; Concepter-Facette als anderes Team laden. |
| Ist-Verhalten | `FoodAlchemistServierform::where(...)` wird ohne `visibleToTeam()` abgefragt. |
| Erwartet | Nur globale/ancestry-sichtbare Vokabeln des aktiven Teams. |
| Ursache/Evidenz | Der sonst verwendete Team-Scope fehlt in genau dieser Facettenquery. |
| Empfehlung | `visibleToTeam($team)` ergänzen; Team-A/B-Leak-Test. |

#### MVP-026 · Concepter-Editor ist ein Wartbarkeitsmonolith

| Feld | Wert |
|---|---|
| Bereich / Priorität / Art | Concepter · P2 · Codequalität/Performance |
| Fundort | `src/Livewire/Concepter/Editor.php` ca. 1.343 Zeilen; View ca. 1.234 Zeilen |
| Reproduktion | Zustände, Validierung, Slots, Kalkulation und Persistenz in der Komponente verfolgen. |
| Ist-Verhalten | Viele fachliche Verantwortungen und Renderzustände sind eng gekoppelt. Beim Öffnen des geprüften Concepts rendert der Dialog bereits 60 Basisrezepte und 31 Gerichte samt Kalkulations-/Bewertungsdaten und Slot-Unterzeilen; der Browser-DOM wird dadurch sehr groß. |
| Erwartet | Editor-Orchestrator plus abgegrenzte Slot-, Kalkulations- und Persistenzdienste/Unterkomponenten. |
| Ursache/Evidenz | Wachstum einer Livewire-Komponente ohne klare Query-/Command-Grenzen; `render()` berechnet Cockpit, Aggregat, Kalkulation, Tauschoptionen und mehrere Picker-Pools gemeinsam. |
| Empfehlung | Nach Use Cases schneiden, Picker erst beim Öffnen laden und paginieren/virtualisieren; Query-/DOM-Budget ergänzen. Keine pauschale Autosave-Umstellung. |

#### MVP-051 · Concepter-Auswahl ist nach Deep-Link/Reload leer

| Feld | Wert |
|---|---|
| Bereich / Priorität / Art | Concepter · P1 · Bug/Klickroute |
| Fundort | `src/Livewire/Concepter/Browser.php:63-71,99-103`; `resources/views/livewire/concepter/browser.blade.php:122-125` |
| Reproduktion | Concept `#486 Smoke Concept` wählen: URL wird `?sel=990001`, Detail erscheint. Exakt diese URL neu laden. |
| Ist-Verhalten | Nach Reload bleibt `sel=990001` in der URL, das Detail zeigt trotzdem „Concept auswählen.“; der Editor wird ebenfalls nicht wiederhergestellt. |
| Erwartet | Ein gültiger URL-Schlüssel lädt nach Reload/Deep-Link dasselbe autorisierte Detail; ungültige IDs werden sichtbar verworfen. |
| Ursache/Evidenz | `mount()` dispatcht `concepter-selected`, bevor der getrennte `DetailPanel`-Listener zuverlässig bereit ist; es existiert keine datengetriebene Initialisierung aus `selectedId`. |
| Empfehlung | Detail direkt aus dem autorisierten URL-State laden oder initialen Zustand als Child-Parameter übergeben; Browser-E2E für Auswahl → Reload → Zurück. |

#### MVP-052 · Concepter-Save akzeptiert fremde Dimensions-IDs

| Feld | Wert |
|---|---|
| Bereich / Priorität / Art | Concepter · P0 · Bug/Autorisierung/Datenintegrität |
| Fundort | `src/Services/ConceptService.php:98-146`; `src/Livewire/Concepter/Editor.php:225-240,1280-1291` |
| Reproduktion | **Statisch belegt:** in das öffentliche Editor-Payload eines eigenen Concepts fremde `serving_form_id`, `event_type_id`, `writing_style_id`, `category_id`, Einsatzmoment- oder Saison-IDs einsetzen und `speichern()` auslösen. |
| Ist-Verhalten | `update()` übernimmt die Fremdschlüssel roh; `syncEinsatzmomente()` und `syncSaisons()` synchronisieren rohe IDs. Mehrere Optionsqueries sind zwar gescopt, Servierformen jedoch nicht; keine serverseitige Referenzautorisierung schützt den Write. |
| Erwartet | Jede Dimensions-ID ist global oder über `visibleToTeam($team)` sichtbar und fachlich zulässig; manipulierte IDs werden atomar abgewiesen. |
| Ursache/Evidenz | Der Service schützt das Concept-Ownership, validiert aber die referenzierten Vokabulare nicht und vertraut der UI-Optionsliste. |
| Empfehlung | Alle Referenz-IDs vor dem Write teamgescopt auflösen, Mehrfach-IDs als exakte sichtbare Menge validieren und negative Team-A/B-Livewire-/Servicetests ergänzen. |

#### MVP-053 · Concepter mischt Abschluss-Save und Sofortwrites ohne atomaren Vertrag

| Feld | Wert |
|---|---|
| Bereich / Priorität / Art | Concepter · P1 · Bug/UX-Schuld/Transaktion |
| Fundort | `src/Livewire/Concepter/Editor.php:225-248,250-266,474-533,967-1010`; `resources/views/livewire/concepter/editor.blade.php:17,76-123,454-555,857` |
| Reproduktion | Im langen Concept-Editor Stammdaten ändern, danach Servierform, Slotmenge oder Wording bedienen und einen Fehler in einem späteren Save-Schritt provozieren. |
| Ist-Verhalten | Der prominente „Speichern“-Knopf suggeriert einen Abschluss, während Servierform, Eventtyp, Slots, Blöcke, Mengen und Wording teilweise sofort schreiben. Der Haupt-Save führt Concept-Update und zwei Pivot-Syncs ohne gemeinsame Transaktion aus. Es gibt keinen Dirty-/Saving-/Saved-Zustand; ein später Fehler kann einen früheren Teilwrite stehen lassen. |
| Erwartet | Komplexe Concept-/Paketänderungen haben einen klaren Abschluss und konsistente Fehlerbehandlung; nur atomare, reversible Einzelwerte autosaven mit sichtbarem Status. |
| Ursache/Evidenz | Historisch gewachsene Command-Mischung in einer Komponente; `speichern()` orchestriert drei Writes sequenziell. |
| Empfehlung | **Kein Voll-Autosave.** Stammdaten und Dimensionspivots in eine transaktionale Save-Unit legen, Dirty-State/sticky Abschluss ergänzen. Slot-/Wording-Autosave nur bei atomaren Commands beibehalten, mit Saving/Saved/Fehler und Rollback. |

#### MVP-054 · Concepter zeigt weder Treffer- noch Facetten-Zielmengen

| Feld | Wert |
|---|---|
| Bereich / Priorität / Art | Concepter · P2 · UX-Schuld/Testlücke |
| Fundort | `resources/views/livewire/concepter/browser.blade.php:30-108,128-195` |
| Reproduktion | Concepts/Pakete öffnen, Suche und Facetten kombinieren; sichtbare Menge vor/nach Klick vergleichen. |
| Ist-Verhalten | Liste, Pagination und Filter funktionieren, zeigen aber weder Gesamt-Trefferzahl noch Zielmengen an den Facetten. Nutzer können deshalb nicht prüfen, welche Menge ein Klick tatsächlich öffnet. |
| Erwartet | Mindestens aktuelle Trefferzahl; bei Facettenzahlen müssen diese unter allen übrigen aktiven Filtern exakt sein. |
| Ursache/Evidenz | Paginator-Metadaten und symmetrische Facettenqueries werden in der View nicht ausgegeben. |
| Empfehlung | Trefferzahl ergänzen; Facettenzahlen nur mit gemeinsamem Filterobjekt implementieren und per Kombifiltertest absichern. |

**Positiv geprüft:** Concepts und Pakete wechseln mit URL-State; Suche und
Leerzustand funktionieren, die Auswahl schreibt `?sel=990001`, Detail und
Editor öffnen, die manuellen Picker-/Strukturpfade sind ohne KI erreichbar.
Reload/Deep-Link, Referenzautorisierung und Speichervertrag verhindern dennoch
eine stabile Einstufung.

### Foodbook / Portfolio

#### MVP-027 · Fremde Kapitel- und Block-IDs können Daten in Formulare laden

| Feld | Wert |
|---|---|
| Bereich / Priorität / Art | Foodbook/Portfolio · P0 · Bug/Autorisierung/Datenleck |
| Fundort | `src/Livewire/Foodbooks/Index.php:123-124,178-179,834,875-919,1023,1088-1138` |
| Reproduktion | **Statisch belegt:** als Team A `kapitelWaehle(fremdeId)` beziehungsweise `blockBearbeiten(fremdeId)` als öffentliche Livewire-Action aufrufen. |
| Ist-Verhalten | `ladeKapitelForm()` und `blockBearbeiten()` verwenden globales `find()` und kopieren Titel, Kundentext, Preise und interne Bemerkungen in öffentliche Component-Properties. Nachgelagerte Service-Writes verwenden `ownedKapitel()`/`ownedBlock()` und blocken Änderungen, verhindern aber das vorangehende Lesen nicht. Weitere Frame-/Move-Hilfsqueries sind ebenfalls ungescopt. |
| Erwartet | Jede untergeordnete ID wird über das aktuell autorisierte Foodbook aufgelöst. |
| Ursache/Evidenz | Direkte Modellqueries in öffentlichen UI-Actions umgehen die vorhandene `FoodbookService`-Aggregate-/Ownership-Grenze. |
| Empfehlung | Reads und Writes ausschließlich über autorisierte `detail/ownedKapitel/ownedBlock`-Methoden führen, Kapitel zusätzlich an `selectedId` binden; negative Team-A/B-Livewire-Tests für Prefill, Move, Visibility und Frame-Slots. |

#### MVP-028 · Foodbook-Komponente bündelt Editor, Dokument und Struktur

| Feld | Wert |
|---|---|
| Bereich / Priorität / Art | Foodbook/Portfolio · P2 · Codequalität/UX-Schuld |
| Fundort | `src/Livewire/Foodbooks/Index.php` ca. 1.319 Zeilen; View ca. 1.111 Zeilen |
| Reproduktion | Tabs, Chapter/Block-Operationen, KI, Dokumente und Speichern verfolgen. |
| Ist-Verhalten | Zwei generische „Speichern“-Knöpfe, unklarer Dirty-State und stark gekoppelte Zustände. Buchkopf, Kapitel, Planung, Kreativteil, Branding, Dokumentdaten und Picker werden gemeinsam gerendert. |
| Erwartet | Klar benannte Abschlüsse pro Editorbereich und sichtbarer Ungespeichert-/Gespeichert-Zustand. |
| Ursache/Evidenz | Mehrere Editoren leben in einer Komponente. |
| Empfehlung | Struktur-, Inhalt- und Publikationsbereiche trennen. Explizites Speichern beibehalten; Autosave nur für atomare reversible Metadaten. |

#### MVP-055 · Foodbook-Deep-Link rendert leere Buch- und Kapitelformulare

| Feld | Wert |
|---|---|
| Bereich / Priorität / Art | Foodbook/Portfolio · P1 · Bug/Datenverlustgefahr/Klickroute |
| Fundort | `src/Livewire/Foodbooks/Index.php:474-483,553-569,805-841,1173-1198` |
| Reproduktion | Foodbook „Spec20 Klickstrecke FB“ und Kapitel „Test-Kapitel“ wählen (`?fb=1&kap=32`); exakt diese URL neu laden. Auch `?fb=8` separat prüfen. |
| Ist-Verhalten | Das autorisierte Foodbook/Kapitel und deren Panels werden gefunden, aber `form` und `kapitelForm` bleiben auf leeren Defaults. Vorhandene Bezeichnung `Spec20 Klickstrecke FB`, Kunde und Kapiteltitel `Test-Kapitel` erscheinen als leere Eingaben. Der obere Save würde die leeren Buchfelder persistieren; Kapitel-Felder autosaven beim Verlassen. |
| Erwartet | Deep-Link/Reload hydriert Buch- und Kapitel-Formular aus derselben autorisierten Entität oder sperrt Speichern bis zur erfolgreichen Initialisierung. |
| Ursache/Evidenz | Es gibt keinen `mount()`-/hydrate-Pfad für URL-Properties. `waehle()` und `ladeKapitelForm()` befüllen Formulare nur nach einem Livewire-Klick; `render()` lädt zwar Modelle, nicht aber die Form-Arrays. |
| Empfehlung | Autorisierte Initialisierung bei Mount/URL-Änderung zentralisieren, Form-State mit geladener ID kennzeichnen und Save bei nicht initialisiertem State blockieren; E2E für Auswahl → Reload → unveränderte Werte → Save. |

#### MVP-056 · Foodbook hat mehrere überlappende Speicherverträge ohne gemeinsamen Abschluss

| Feld | Wert |
|---|---|
| Bereich / Priorität / Art | Foodbook/Portfolio · P1 · Bug/UX-Schuld/Transaktion |
| Fundort | `src/Livewire/Foodbooks/Index.php:40-63,582-596,844-852,1073-1079`; `resources/views/livewire/foodbooks/index.blade.php:110-125,137-145,687,821-841,943-973` |
| Reproduktion | Buchkopf, Branding und ein Kapitel bearbeiten; sichtbare Save-Aktionen und Persistenzzeitpunkte vergleichen, anschließend einen ungültigen Brandingwert betrachten. |
| Ist-Verhalten | Der obere generische „Speichern“-Knopf speichert Buchkopf **und** Branding; im Branding-Tab existiert ein zweiter gleichnamiger Knopf. Kapiteltexte autosaven auf Blur/Change, Blockeditoren schließen mit „OK“. `speichern()` aktualisiert zuerst das Foodbook und ruft danach Branding auf; Branding fängt Fehler intern ab, sodass der Buchkopf trotz Brandingfehler bereits persistiert sein kann. Dirty-/Saving-/Saved-Anzeige fehlt. |
| Erwartet | Klar benannte fachliche Abschlüsse mit erkennbarem Scope und konsistentem Teilfehlerverhalten. |
| Ursache/Evidenz | Mehrere Editoren teilen eine globale Livewire-Komponente und wurden mit unterschiedlichen Persistenzmustern zusammengeführt. |
| Empfehlung | **Kein pauschales Autosave.** Buchkopf/Briefing und Branding als explizite, klar benannte Save-Scopes oder transaktional orchestrierten Gesamtabschluss führen; Kapitel-Blur-Autosave nur als atomaren, reversiblen Command mit Status/Fehler beibehalten; Blockeditor behält „OK“ plus Dirty-State. |

#### MVP-057 · Foodbook-Tabzustand fehlt in URL und Browser-Historie

| Feld | Wert |
|---|---|
| Bereich / Priorität / Art | Foodbook/Portfolio · P2 · UX-Schuld/Klickroute |
| Fundort | `resources/views/livewire/foodbooks/index.blade.php:99-116` |
| Reproduktion | `?fb=1&kap=32` öffnen, „Branding/CI“ oder „Preise“ wählen, URL und Browser-Zurück/Reload prüfen. |
| Ist-Verhalten | Der aktive Cockpit-Tab lebt nur in lokalem Alpine-State (`tab: 'briefing'`). URL bleibt unverändert; Zurück kann den Tabwechsel nicht rückgängig machen und Reload landet stets im Briefing. |
| Erwartet | Der fachlich relevante Arbeitskontext ist deep-linkbar und per Zurück/Reload reproduzierbar. |
| Ursache/Evidenz | Tabzustand ist nicht an Livewire/URL gebunden. |
| Empfehlung | Stabilen `tab`-URL-Parameter mit Allowlist einführen; Buch-/Kapitel- und Tab-Kontext gemeinsam testen. |

#### MVP-058 · Kapitel- und Blockstruktur ist per Tastatur/Screenreader nicht verständlich

| Feld | Wert |
|---|---|
| Bereich / Priorität / Art | Foodbook/Portfolio · P2 · Barrierefreiheit/UX-Schuld |
| Fundort | `resources/views/livewire/foodbooks/index.blade.php:47-60,895-937` |
| Reproduktion | Kapitelbaum und Blockliste per Tastatur beziehungsweise Accessibility-Snapshot bedienen. |
| Ist-Verhalten | Wiederholte Aktionen besitzen als zugänglichen Namen nur `▲`, `▼`, `⬅`, `➡`, `＋`, `✕`, `←`, `→`, `👁` oder `✎`; Kapitelaktionen sind zusätzlich nur bei `group-hover` sichtbar. Der Name nennt den betroffenen Datensatz nicht. |
| Erwartet | Jeder Button ist fokussichtbar und heißt z. B. „Kapitel Test-Kapitel nach oben“ beziehungsweise „Block X entfernen“. |
| Ursache/Evidenz | `title` und Symbol ersetzen kontextspezifische `aria-label`s; Sichtbarkeit reagiert nicht auf `focus-within`. |
| Empfehlung | Kontextuelle `aria-label`s, `focus-within:opacity-100`, klare Fokusreihenfolge und Keyboard-Smoke für Baum/Blöcke. |

#### MVP-059 · Foodbook-Liste zeigt keine Treffer- oder Phasenmengen

| Feld | Wert |
|---|---|
| Bereich / Priorität / Art | Foodbook/Portfolio · P2 · UX-Schuld/Testlücke |
| Fundort | `resources/views/livewire/foodbooks/index.blade.php:18-38`; `src/Services/FoodbookService.php:37-52` |
| Reproduktion | Suche `___audit_keine_treffer___` oder eine Phase wählen; sichtbare Zielmenge prüfen. |
| Ist-Verhalten | Suche, URL `?q=...`, Phasenfilter und Leerzustand funktionieren, aber es gibt keine aktuelle Trefferzahl und keine Mengen je Phase. |
| Erwartet | Aktuelle Gesamtmenge ist sichtbar; optionale Phasenzahlen entsprechen der kombinierten Suche. |
| Ursache/Evidenz | Der Paginator liefert die Gesamtmenge, die Sidebar rendert sie nicht; separate Facettenzahlen existieren nicht. |
| Empfehlung | `total()` anzeigen; Phasenmengen nur aus derselben gefilterten Query ableiten und mit Suche × Phase testen. |

**Positiv geprüft:** zwei Foodbooks, sinnvoller leerer Auswahl- und
Such-Leerzustand, URL-Auswahl `?fb=8`, Kapitelwahl `?fb=1&kap=32`, sieben
Cockpit-Tabs, Dokument-/Präsentationslinks, Buch-, Kapitel-, Concept- und
Gerichtpfade. Die manuellen Nicht-KI-Flächen sind vorhanden; Deep-Link,
Autorisierung und Speichervertrag verhindern eine stabile Einstufung.

### Angebote

**Kein eigener P0-P2-Befund im geprüften read-only Umfang. Positiv geprüft:**
drei Angebote, Suche/Filter, leerer Detailzustand, URL-Auswahl `?sel=17`,
Detaildaten, Preis-/CRM-/Menübereiche, manueller „Neue Anfrage“-Pfad und
bestätigtes Löschen. Der Nicht-KI-Kern ist sichtbar vollständig. Offene Grenze:
kein Formular wurde im Browser abgesendet und live war nicht authentifiziert.

### Preissimulation

#### MVP-029 · Artikelsimulation verlangt eine interne Datenbank-ID

| Feld | Wert |
|---|---|
| Bereich / Priorität / Art | Preissimulation · P1 · UX-Schuld/Kernpfad |
| Fundort | `/foodalchemist/kalkulation`, Scope „Artikel“ |
| Reproduktion | Scope Artikel wählen und Referenzfeld bedienen. |
| Ist-Verhalten | Nutzer muss eine rohe `supplier_item_id` numerisch kennen/eingeben. |
| Erwartet | Suchbarer Lieferant-/Artikelpicker mit Preis, Einheit und eindeutigem Treffer. |
| Ursache/Evidenz | Technischer Serviceparameter wurde direkt als UI-Vertrag übernommen. |
| Empfehlung | Teamgescopten Combobox-Picker einführen; ID nur intern übertragen. |

#### MVP-030 · GP-Direktreferenz prüft Team-Sichtbarkeit nicht

| Feld | Wert |
|---|---|
| Bereich / Priorität / Art | Preissimulation · P1 · mögliche Autorisierungslücke |
| Fundort | `src/Services/Kalkulation/SimulationService.php`, `gpsFuerScope()` |
| Reproduktion | **Vermutung, statisch:** fremde GP-ID bei Scope `gp` direkt an die Livewire-Aktion übergeben. |
| Ist-Verhalten | Methode gibt `[(int) $ref]` zurück, ohne die ID gegen `teamAncestryIds` zu validieren; der sichtbare Picker selbst ist gescopt. |
| Erwartet | Jede serverseitig empfangene Referenz wird unabhängig von der UI autorisiert. |
| Ursache/Evidenz | Autorisierung ist für Artikel indirekt vorhanden, für GP-Direkt-ID nicht. |
| Empfehlung | Referenz gegen sichtbare GP-Query auflösen; Team-A/B-Test. Bei nachgewiesenem Datenaustritt P0. |

#### MVP-031 · Substitutionen erzeugen eine Query pro Äquivalent

| Feld | Wert |
|---|---|
| Bereich / Priorität / Art | Preissimulation · P2 · Performance |
| Fundort | `src/Services/Kalkulation/SimulationService.php:70-83` |
| Reproduktion | GP mit vielen Äquivalenten simulieren; Queryzahl messen. |
| Ist-Verhalten | Namen werden je Äquivalent separat geladen (N+1). |
| Erwartet | Ein Bulk-Load, danach Mapping im Speicher. |
| Ursache/Evidenz | Query innerhalb der Transformationsschleife. |
| Empfehlung | IDs sammeln und `whereIn`/Eager Load nutzen; Querybudget-Test. |

**Positiv geprüft:** Scope, Referenz, Delta und Ergebnis sind als ausdrücklich
read-only Simulation umgesetzt; der Kern benötigt keine KI.

### Speiseplan

#### MVP-032 · Matrixaktionen sind per Tastatur/Assistenztechnik nicht eindeutig

| Feld | Wert |
|---|---|
| Bereich / Priorität / Art | Speiseplan · P2 · Barrierefreiheit/UX-Schuld |
| Fundort | Speiseplanmatrix und Liniensteuerung |
| Reproduktion | `?sp=1` öffnen und per Tastatur/Accessibility-Snapshot durch fünf „+“-Zellen und Iconbuttons gehen. |
| Ist-Verhalten | Mehrere Aktionen heißen nur „+“ oder besitzen keinen sprechenden Namen; Zieltag/Linie ist nicht erkennbar. |
| Erwartet | „Gericht zu [Linie], [Tag] hinzufügen“, beschriftete Reihenfolge-/Löschaktionen und sichtbarer Fokus. |
| Ursache/Evidenz | Visueller Matrixkontext wird nicht in Accessible Names übertragen. |
| Empfehlung | Kontextlabels, Fokusführung und Tastatur-Integrationstest ergänzen. |

**Positiv geprüft:** Suche, sechs Pläne, URL-Auswahl `?sp=1`, Name, Start,
Zyklus, Mindestabstand, Status, explizites Speichern, Matrix und Rollout sind
manuell vorhanden. Komplexe Planung sollte nicht pauschal autosaven.

### Produktion

#### MVP-033 · Produktion filtert eine vollständig geladene Collection in PHP

| Feld | Wert |
|---|---|
| Bereich / Priorität / Art | Produktion · P2 · Performance/Codequalität |
| Fundort | `src/Livewire/Production/Browser.php:70-80` |
| Reproduktion | Datenmenge erhöhen; Renderpfad verfolgen. |
| Ist-Verhalten | Gesamtliste wird geladen, anschließend in PHP nach Suche/Status/Datum gefiltert; Zeilen/Indikatoren werden zusätzlich für die Menge geladen. Keine Pagination. |
| Erwartet | Datenbankfilter, begrenzte Seite und gezieltes Eager Loading/Aggregate. |
| Ursache/Evidenz | Service liefert Collection statt paginierbarer Query/Read-DTO. |
| Empfehlung | Query-Service mit serverseitiger Pagination und Querybudget-Test. |
| **Status** | **behoben (Spec 30 E4, 01.08.2026)** — `ProductionOrderService::paginateBrowser()` filtert und paginiert in der Datenbank; Zähler laufen über denselben Filtersatz. Test: `ProduktionBrowserTest`. |

**Positiv geprüft:** drei Aufträge, URL-gebundene Suche/Status/Datum,
Detailauswahl und manueller Neu-Auftrag mit Zieltyp; KI ist kein Kernbestandteil.

### Bestellungen

#### MVP-034 · Ladefehler werden still verworfen

| Feld | Wert |
|---|---|
| Bereich / Priorität / Art | Bestellungen · P1 · Bug/Fehlerzustand |
| Fundort | `src/Livewire/Orders/Index.php:112-114,386-388` |
| Reproduktion | Fehler beim Laden eines Kopfes beziehungsweise einer ausgewählten Schiene provozieren. |
| Ist-Verhalten | `Throwable` wird ohne Feedback gefangen; Auswahl wird im Renderpfad teilweise nur zurückgesetzt. |
| Erwartet | Verständlicher Fehler, unveränderte persistierte Daten und nachvollziehbarer Auswahlzustand. |
| Ursache/Evidenz | Leere Catch-Pfade verstecken Infrastruktur-, Rechte- und Datenfehler gleichermaßen. |
| Empfehlung | Erwartete Exceptions explizit behandeln, unerwartete loggen/reporten, UI-Fehlerzustand testen. |

#### MVP-035 · Bestellschienen werden vollständig geladen und in PHP gefiltert

| Feld | Wert |
|---|---|
| Bereich / Priorität / Art | Bestellungen · P2 · Performance/Codequalität |
| Fundort | `src/Livewire/Orders/Index.php:345-367`; Komponente ca. 454, View ca. 404 Zeilen |
| Reproduktion | Viele Schienen/Lieferanten anlegen und Browser rendern. |
| Ist-Verhalten | `listForTeam()` liefert Vollmenge; Filter laufen in PHP, keine Pagination. |
| Erwartet | Teamgescopte Datenbankfilter und paginierte Liste. |
| Ursache/Evidenz | Read-Service-Grenze gibt Collection statt Query/Page zurück. |
| Empfehlung | Paginierter Read-DTO/Query-Service; große-Datenmengen-Test. |

**Positiv geprüft:** acht Schienen, Status-/Lieferant-/Suchfilter,
URL-Kontext, hilfreicher leerer Detailzustand sowie manuelle Direkt- und
schienenbasierte Bestellwege.

### Wissen

#### MVP-036 · Wissensdokumente werden teamübergreifend gelesen

| Feld | Wert |
|---|---|
| Bereich / Priorität / Art | Wissen · P0 · Bug/Datenleck |
| Fundort | `src/Livewire/Knowledge/Browser.php:54,243-269` |
| Reproduktion | Team-spezifisches Dokument in Team B anlegen; Bereich als Team A laden oder fremde ID auswählen. |
| Ist-Verhalten | Basis-/Selected-Queries laden Dokumente ohne `visibleToTeam()`; lokal werden 1.021 Dokumente vollständig angeboten. |
| Erwartet | Nur globale und ancestry-sichtbare Dokumente; fremde ID ergibt 404/Autorisierungsfehler. |
| Ursache/Evidenz | Team-Scope fehlt in Listen- und Auswahlquery. |
| Empfehlung | Alle Dokumentqueries zentral scopen, Route/Livewire-ID autorisieren, Team-A/B-Leak-Test vor Release. |

#### MVP-037 · Aliase und Bindings lassen sich ohne Eigentumsprüfung ändern

| Feld | Wert |
|---|---|
| Bereich / Priorität / Art | Wissen · P0 · Bug/Autorisierung/Datenkorruption |
| Fundort | `src/Livewire/Knowledge/Browser.php:150-214` |
| Reproduktion | Fremde Dokument-/Alias-/Binding-ID an `addAlias`, `removeAlias`, `addBinding` oder `removeBinding` übergeben. |
| Ist-Verhalten | Öffentliche Aktionen prüfen weder Eigentum noch Team-Sichtbarkeit des Ziels. |
| Erwartet | Nur eigene/kuratiert erlaubte Beziehungen veränderbar; geerbte/global/fremde Daten read-only. |
| Ursache/Evidenz | Dokument-Save besitzt teilweise Checks, untergeordnete Relation-Aktionen umgehen sie. |
| Empfehlung | Policy auf Aggregate und jede Child-ID anwenden, Transaktionen und negative Manipulationstests ergänzen. |

#### MVP-038 · 1.021 Wissensdokumente werden ohne Pagination gerendert

| Feld | Wert |
|---|---|
| Bereich / Priorität / Art | Wissen · P1 · Performance/Kern-UX |
| Fundort | Wissens-Browser; `Knowledge/Browser.php` Listenquery |
| Reproduktion | Lokale Route öffnen; Dokumentbuttons zählen. |
| Ist-Verhalten | 1.021 Buttons/Datensätze werden gleichzeitig geladen und gerendert. |
| Erwartet | Server-Pagination/virtuelle Suche mit Gesamtzahl und schnellem Leerzustand. |
| Ursache/Evidenz | Vollmengenquery plus vollständiges DOM-Rendering. |
| Empfehlung | Cursor-Pagination, debounced Datenbanksuche, begrenzte Facetten und Performancebudget. |

**Positiv geprüft:** Suche, Kategorie-/Statusfilter und manueller Editor sind
vorhanden; bestehende Tests prüfen semantisches Ranking/Fallback. Gerade die
CRUD-/Autorisierungspfade fehlen jedoch in diesen Tests.

### Einstellungen

#### MVP-039 · Geerbte/globale Aufschlagsklassen sind veränder- und löschbar

| Feld | Wert |
|---|---|
| Bereich / Priorität / Art | Einstellungen · P0 · Bug/Autorisierung/Datenkorruption |
| Fundort | `src/Livewire/Settings/Aufschlagsklassen.php:30-60,89-106`; `resources/views/livewire/settings/aufschlagsklassen.blade.php:48-52` |
| Reproduktion | Als Kindteam sichtbare globale/Ancestor-Klasse bearbeiten, deaktivieren oder löschen. |
| Ist-Verhalten | `find`/`findOrFail` sind ungescopt; View zeigt Aktionen für alle sichtbaren Klassen. Delete blockiert nur ein abweichendes nicht-null `team_id`, sodass globale `null`-Zeilen nicht geschützt sind. |
| Erwartet | Nur eigene Klassen mutierbar; globale/geerbte Klassen read-only oder explizit kuratierbar. |
| Ursache/Evidenz | Sichtbarkeit und Schreibberechtigung werden verwechselt; serverseitige Policy fehlt. |
| Empfehlung | Eigentums-/Curate-Policy für jede Aktion, globale Zeilen unveränderlich, Team-A/B- und Global-Fixture-Tests. |

#### MVP-040 · Verwendungszähler ignoriert Teamgrenzen

| Feld | Wert |
|---|---|
| Bereich / Priorität / Art | Einstellungen · P1 · Bug/Datenleck |
| Fundort | `src/Livewire/Settings/Aufschlagsklassen.php:150-152` |
| Reproduktion | Dieselbe sichtbare Klasse in mehreren Teams verwenden und Zähler als Kindteam anzeigen. |
| Ist-Verhalten | Count läuft über Verwendungen aller Teams; kann fremde Nutzung verraten und lokale Löschentscheidungen falsch blockieren. |
| Erwartet | Fachlich definierter Scope; mindestens keine Offenlegung fremder Teamnutzung. |
| Ursache/Evidenz | Referenzcount besitzt keinen Team-/Ancestry-Filter. |
| Empfehlung | Scope fachlich festlegen, Query begrenzen und Cross-Team-Zählertest ergänzen. |

#### MVP-041 · Dokumentierter Rechtevertrag und UI-Werte widersprechen der Realität

| Feld | Wert |
|---|---|
| Bereich / Priorität / Art | Einstellungen · P2 · Dokumentation/UX-Schuld |
| Fundort | `src/Livewire/Settings/Index.php:13-14`; Einheitenansicht (`mass`, `volume`, `count`) |
| Reproduktion | Kommentar „jede Sektion row-gated“ mit Aufschlagsklassen-Code vergleichen; Einheiten öffnen. |
| Ist-Verhalten | Rechtebehauptung ist falsch; interne englische Dimensionswerte erscheinen in deutscher UI. |
| Erwartet | Dokumentierter und getesteter Rechtevertrag; lokalisierte Labels. |
| Ursache/Evidenz | Kommentar/Views wurden nicht mit Implementierung synchronisiert. |
| Empfehlung | Falschen Kommentar nach Policy-Fix ersetzen; zentrale Dimensionslabels verwenden. |

**Positiv geprüft:** alle 17 Einstellungssektionen sind erreichbar und
URL-navigierbar; mehrere neuere Sektionen wie Schreibstile und
Concepter-Dimensionen enthalten bei ihren eigenen Settings-Mutationen bereits
explizite Eigentumsprüfungen. Das ersetzt nicht die fehlende
Referenzvalidierung im Concepter-Save (MVP-052).

## 5. Positive Nicht-KI-Kernprüfung

In jedem Bereich existiert ein manueller Grundweg: Datensätze/Arbeitsvorräte
anzeigen und filtern; Neu-/Editorwege sind für Lieferanten, Grundprodukte,
Geschirr, Concepter, Foodbooks, Angebote, Speisepläne, Produktion,
Bestellungen, Wissen und Einstellungen sichtbar. Bei Basisrezepten und Gerichten
ist die manuelle **Anlage** sichtbar, die Bearbeitungsöffnung über den
angekündigten Namensklick aber defekt (MVP-045). KI ist funktional eine
Zusatzoption und blockiert keinen sichtbaren Neueinstieg. Nicht nachgewiesen ist
damit noch nicht die fehlerfreie Persistenz jedes komplexen Editors; dafür
fehlen browserbasierte End-to-End-Tests.

Ein expliziter Abschluss ist bei Concepter, Foodbook, Speiseplan und anderen
mehrteiligen Editoren grundsätzlich angemessen. Die aktuellen Concepter- und
Foodbook-Verträge sind wegen überlappender Sofortwrites, fehlender Atomarität
und fehlendem Dirty-State dennoch nicht stabil (MVP-053/MVP-056). Autosave gilt
nur für atomare, reversible Felder (Toggle, einzelner Status, kleine Zuordnung)
und muss Saving/Saved, Fehler-Rollback und Konfliktverhalten sichtbar machen.

## 6. Priorisierte Stabilisierungspakete

### Paket A · P0 Team-Isolation vor jeder Freigabe

1. Lieferanten: Bulk-Löschung auf Eigentum/Policy begrenzen und
   Match-Vorschläge team-scopen.
2. Wissen: Listen/Selected/Relationen vollständig scopen; Alias-/Binding-Aktionen
   autorisieren.
3. Einstellungen: Aufschlagsklassen global/Ancestor read-only machen und jede
   Mutation serverseitig prüfen.
4. Basisrezepte: Kategorie-IDs serverseitig scopen und HG-Konsistenz erzwingen.
5. Gerichte: Klassen-/Aufschlagsklassen-IDs serverseitig scopen.
6. Globalen `zutaten-speichern`-Broadcast durch einen eindeutig adressierten,
   koordinierten Save-Command ersetzen.
7. Concepter-Dimensions-IDs serverseitig teamgescopt validieren; Foodbook-
   Kapitel-/Block-Prefill ausschließlich über das autorisierte Aggregat laden.
8. Für alle betroffenen Bereiche negative Team-A/B-, Global-/Ancestor- und
   Zwei-Rezepte-Race-Tests
   verpflichtend machen.

**Abnahmekriterium:** Manipulierte Livewire-IDs und öffentliche Methoden liefern
keine fremden Daten und verändern keine fremden/geerbten Zeilen.

### Paket B · P1 Nicht-KI-Arbeitsvorräte und Fehlertransparenz

1. Rezept-/Gerichte-Namensklick zuverlässig zum sichtbaren Editor führen.
2. Rezept-HG/Kategorie- und Gerichte-HG/Klassen-Facetten auf identische
   Filterqueries bringen; „Ohne Kategorie“ ergänzen.
3. Gerichte-Editor auf dasselbe unabhängige HG-/Klassenmodell wie Browser und
   Persistenz umstellen.
4. Komplexes Rezept-/Gerichtespeichern koordinieren: Dirty-State, ein klarer
   Abschluss, kein Schließen bei Child-Fehler.
5. Concepter- und Foodbook-URL-State beim Mount vollständig hydrieren; Save bei
   nicht initialisiertem Form-State sperren.
6. Concepter-Save transaktionalisieren; Foodbook-Save-Scopes klar trennen oder
   als koordinierten Gesamtabschluss führen.
7. Dashboard-Zähler/Links auf identische Queries und URL-Filter bringen.
8. Signal-Detailvertrag korrigieren.
9. Statusaktionen in GP/Rezept/Gericht sichtbar fehlschlagen lassen.
10. Wissensliste paginieren; Favoritenlimit/Zähler korrigieren.
11. Artikelsimulation durch fachlichen Picker ersetzen.
12. Bestell-Ladefehler anzeigen und Supplier-Bulk transaktional machen.
13. GP-/Preissimulations-IDOR-Vermutungen durch gezielte Tests bestätigen oder
   schließen.

### Paket C · Browser-E2E und responsive Shell

1. Je Bereich ein isolierter Nicht-KI-Happy-Path inklusive Persistenz, Reload,
   Browser-Zurück und URL-Kontext.
2. Gemeinsamen Shell-Breakpoint für 390 px; Dashboard und GP als Gate.
3. Accessibility-Smokes für Tabellenstatus, Favoriten, Speiseplanmatrix,
   Dialoge sowie Foodbook-Kapitel-/Blockbaum.
4. Keine Live-/Masterdaten als Fixture; transaktionale Team-A/B-Testdaten.

### Paket D · Skalierung und Komponentenentkopplung

1. Vollmengen in Lieferanten, Produktion, Bestellungen und Wissen paginieren.
2. N+1 in Preissimulation entfernen; Querybudgets etablieren.
3. Concepter- und Foodbook-Monolithen entlang fachlicher Use Cases teilen.
4. ReviewQueue nur für den aktiven Tab laden.

### Paket E · Konsistenz und Dokumentation

1. Zentrale deutsche Labels für Status, Dimension, Schwierigkeit und
   Produktionsart.
2. Root-/Modul-Dokumentation und tatsächlichen Rechtevertrag synchronisieren.
3. KI-Flächen capability-abhängig und nachrangig darstellen.

## 7. Testprotokoll

| Prüfung | Ergebnis |
|---|---|
| `php artisan route:list --path=foodalchemist` | 32 Modulrouten registriert, Domain `localhost` |
| Lokale Browser-Smokes | alle 17 Bereiche erreichbar; zusätzlich vollständiger Nachlauf für Basisrezepte/Gerichte sowie Concepter/Foodbook einschließlich Concepts/Pakete, Suche/Leerzustand, Facetten/Phase, Buch/Kapitel, Detail/Editor, Tabs, Deep-Link und Reload |
| Kleine Breite | Dashboard und GP bei 390 × 844 reproduzierbar nicht nutzbar |
| Live-Referenz | erreichbar, aber Redirect zu `/login`; mangels Authentifizierung nicht fachlich prüfbar |
| Bestehende Tests | breite Service-/Livewire-/Feature-Abdeckung; keine vollständige Browser-E2E-Schicht gefunden |
| `pest --testsuite=FoodAlchemist` | **grün:** 1.716 Tests, 1.712 bestanden, 4 übersprungen, 8.921 Assertions, 852,827 s |

## 8. Definition of Done für MVP-Stabilität

Ein Bereich gilt erst als MVP-stabil, wenn:

- alle sichtbaren Aktionen ein erwartbares Ergebnis haben,
- Zähler, Filter, URL und Zielmenge übereinstimmen,
- Anlegen/Bearbeiten ohne KI inklusive Persistenz und Reload nachgewiesen sind,
- Fehler sichtbar sind und atomare Änderungen sauber zurückrollen,
- fremde/geerbte Daten weder gelesen noch verändert werden können,
- Desktop und kleine Breite per Tastatur grundlegend bedienbar sind,
- mindestens ein kompletter Nicht-KI-Happy-Path und die zentralen Rechte-/
  Fehlerpfade automatisiert sind und
- komplexe Editoren expliziten Abschluss plus Dirty-State besitzen, während
  Autosave nur atomare reversible Änderungen betrifft.

## 9. Rückverfolgbarkeit in den aktiven Umsetzungsplan

Die Business-IDs verweisen auf die
[Business-Case-Funktionsmatrix](25_Business_Case_Funktionsmatrix.md), die
Arbeitspakete auf den
[Zielbild-Umsetzungsplan](24_Zielbild_2029_Umsetzungsplan.md). Ein Fund gilt erst
als geschlossen, wenn in der Spalte „Nachweis“ ein Test oder Abnahmeprotokoll
verlinkt ist.

| Audit | Business-IDs | Arbeitspaket | Status | Nachweis zum Schließen |
|---|---|---|---|---|
| MVP-001 | A-10, A-11 | Dokumentations-Baseline | behoben | neue README, Architektur, Dokuindex und Matrizen; Linkprüfung grün |
| MVP-002 | UX-01–10, BC-01–10 | A-07, Phase C | offen | automatisierte Browser-E2E-Suite |
| MVP-003 | UX-08 | Phase D | offen | 390-px-Browserabnahme |
| MVP-004 | UX-01 | Phase A | offen | Zähler-/Zielquery-Integrationstest |
| MVP-005 | UX-02 | Phase A/C | offen | Allergen-Arbeitsvorrat im Browser |
| MVP-006 | UX-03 | Phase A | offen | Ohne-Klasse-URL-/Filtertest |
| MVP-007 | UX-04 | Phase B | offen | eindeutiger LA-Arbeitsvorrat |
| MVP-008 | UX-09, UX-10 | Phase A/D | offen | capability- und datenabhängiges Dashboard |
| MVP-009 | QL-02 | Phase A/C | offen | Signal-CTA-Vertrag mit Objekt-/Aggregatfall |
| MVP-010 | UX-07, QL-02 | Phase A | offen | zentrale Label-Snapshots |
| MVP-011 | QL-02 | Phase A/D | offen | aktiver Tab plus Querybudget |
| MVP-012 | UX-10, AD-12 | Phase A/C | offen | kompletter Nicht-KI-Pfad |
| MVP-013 | ST-05 | A-02–A-04 | behoben | `LieferantenBulkTenantTest` (geerbter Artikel nicht löschbar, eigener schon) · `acebc04` |
| MVP-014 | ST-17 | A-03, A-04 | behoben | `LieferantenBulkTenantTest` (fremde Vorschläge nicht in Review-Liste) |
| MVP-015 | ST-05 | A-03 | behoben | `LieferantenBulkTenantTest` (gemischter Bulk rollt atomar zurück) |
| MVP-016 | ST-02 | Phase A/D | offen | Pagination und Mengenbudget |
| MVP-017 | UX-07, ST-19 | Phase A | offen | sichtbarer Statusfehler und Rollback |
| MVP-018 | ST-19 | Phase A/D | offen | Accessibility-Snapshot Statusfeld |
| MVP-019 | AD-05 | Phase D | offen | zugängliche Eingaben und URL-Kontext |
| MVP-020 | RE-22 | Phase A/D | offen | >100 Favoriten, korrekter Zähler |
| MVP-021 | UX-05, RE-22 | Phase A | offen | URL-/Reload-/Zurück-Test |
| MVP-022 | UX-07, RE-10 | Phase A/C | behoben | `StatusUndLabelsTest` (sichtbarer Fehler + Rollback) · `766e90f`-Reihe, Commit R5 |
| MVP-023 | RE-10, UX-07 | Phase A/C | behoben | `StatusUndLabelsTest` (Labels-Snapshot, keine Rohwerte) + Sandbox-Browser |
| MVP-024 | RE-10 | Phase A/C | behoben | `StatusUndLabelsTest` (VK-Statusfehler + zentrale Labels) |
| MVP-025 | CO-03, AD-18 | A-02–A-04 | behoben | `ConcepterReferenzTenantTest` + Servierform/basisKategorien visibleToTeam · Commit R9 |
| MVP-026 | CO-01–11 | Phase A/C | offen | Zerlegung entlang Use Cases plus Regression |
| MVP-027 | FB-03, FB-05 | A-02–A-04 | behoben | `FoodbookPrefillTenantTest` (fremdes Kapitel/Block nicht prefillbar, eigene weiterhin) · Commit R10 |
| MVP-028 | FB-01–17 | Phase A/C | offen | getrennte Komponenten und Business-E2E |
| MVP-029 | WI-03 | Phase A/C | offen | fachlicher Artikelpicker im Browser |
| MVP-030 | WI-03, ST-19 | A-03, A-04 | offen | fremde GP-ID wird abgewiesen |
| MVP-031 | PA-03 | Phase C | offen | konstantes Querybudget |
| MVP-032 | PL-02 | Phase D | offen | Tastatur-/Screenreader-Matrix |
| MVP-033 | PR-02 | Phase D | behoben (Spec 30 E4) | Lasttest gegen echte Menge steht noch aus |
| MVP-034 | OR-05, UX-07 | Phase A/D | offen | sichtbarer Ladefehler |
| MVP-035 | OR-02 | Phase D | offen | DB-Filter/Pagination und Lasttest |
| MVP-036 | KN-01 | A-02–A-04 | behoben | `WissenTenantTest` (Liste/select/Trace nur sichtbare Docs) · Commit R8 |
| MVP-037 | KN-03, KN-04 | A-02–A-04 | behoben | `WissenTenantTest` (Alias/Binding-Add+Remove nur am eigenen Doc) |
| MVP-038 | KN-01 | Phase A/D | offen | Pagination — bewusst eigenes Paket (zieht WithPagination + Test-Umbau nach); 1024 Docs bestätigt |
| MVP-039 | AD-16 | A-02–A-04 | behoben | `AufschlagsklassenTenantTest` (geerbt/global read-only, eigene mutierbar) · Commit R7 |
| MVP-040 | AD-16 | A-03 | behoben | `AufschlagsklassenTenantTest` (Zähler ohne Fremd-Team) + Löschsperre visibleToTeam |
| MVP-041 | AD-03, AD-16 | A-10, A-02 | teilweise | falscher row-gated-Kommentar in Settings/Index korrigiert (Server-Guard geführt); Dimensions-Labels der Einheiten offen |
| MVP-042 | RE-11 | Phase A | behoben | `FacettenVertragTest` (Gesamtzähler == Tabelle, „Ohne Kategorie") · `16ea773` |
| MVP-043 | RE-11 | Phase A | behoben | `FacettenVertragTest` (Kategorie-Zähler mit aktiven Filtern) |
| MVP-044 | RE-03 | A-02–A-04 | behoben | `ReferenzAutorisierungTest` (fremde Kategorie abgewiesen, Eltern akzeptiert) · `cc9e94a` |
| MVP-045 | RE-02, RE-11 | Phase A/C | nicht reproduzierbar | Sandbox-Messung + Gegenprobe; Revert `a76c8d5`. Siehe Befund oben. |
| MVP-046 | RE-02 | A-03 | behoben | `ZutatenSaveVertragTest` (Zwei-Rezepte, Ziel-Guard) + Sandbox-Klickstrecke · `6702780` |
| MVP-047 | RE-02, RE-10 | Phase A/C | offen | atomarer Save, Dirty-State, Child-Fehler |
| MVP-048 | RE-11, RE-14 | Phase A | behoben | `FacettenVertragTest` (VK-Facetten kombiniert) · `16ea773` |
| MVP-049 | RE-13, RE-14 | Phase A/C | behoben | `ReferenzAutorisierungTest` (HG setzbar) + Sandbox (HG 2→14) · `cc9e94a` |
| MVP-050 | RE-14 | A-02–A-04 | behoben | `ReferenzAutorisierungTest` (fremde Klasse/AK abgewiesen) + Sandbox-Angriff · `cc9e94a` |
| MVP-051 | CO-01 | Phase A/C | offen | Deep-Link/Reload hydriert Auswahl |
| MVP-052 | CO-03 | A-02–A-04 | behoben | `ConcepterReferenzTenantTest` (fremde Dimension/Pivot abgewiesen, geerbt akzeptiert) |
| MVP-053 | CO-01, CO-11 | Phase A/C | offen | atomarer Gesamtabschluss und Fehlerfall |
| MVP-054 | CO-01 | Phase A/C | offen | Treffer-/Facettenmengen gegen Query |
| MVP-055 | FB-01, FB-03 | Phase A/C | offen | Foodbook-Deep-Link/Reload |
| MVP-056 | FB-05, FB-12 | Phase A/C | offen | eindeutiger Speichervertrag und Rollback |
| MVP-057 | UX-05, FB-01 | Phase A/C | offen | Tab in URL, Zurück/Vorwärts |
| MVP-058 | FB-03 | Phase C/D | offen | Tastatur-/Screenreader-Kapitelbaum |
| MVP-059 | FB-01, FB-11 | Phase A/C | offen | Treffer-/Phasenmengen gegen Query |
