# UI-Inventar Food.Alchemist (fa-pass)

> Arbeitsliste für die Komplett-Überarbeitung der Oberfläche. Stand 2026-10-05.
> Ziel (Dominique): Der Food.Alchemist funktioniert alleinstehend, überzeugt in der Oberfläche,
> und jede Seite ist **optisch und logisch** durchgearbeitet. Funktionen bleiben, ihre Darstellung
> und Anordnung darf sich ändern.
>
> Regeln und Bausteine: [`DESIGN.md`](../DESIGN.md) · Musterseite `/foodalchemist/_ui`

**Status:** offen · in Arbeit · umgebaut (Bausteine) · geprüft (Logik + Browser + Suite)

## Durchgang je Seite

1. **Logik:** Wofür kommt man her? Was ist die Hauptaufgabe, was ist Nebensache? Reihenfolge,
   Gruppierung, Begriffe (Küchensprache statt Entwicklersprache), leere/fehlerhafte Zustände.
2. **Optik:** nur Bausteine `x-fa::`, Regel-Test grün, Baseline neu einfrieren.
3. **Prüfen:** Browser hell (und Werkbank, wo Editor), Vorher/Nachher-Screenshot, betroffene Tests.

## Seiten

| Welle | Seite | Route | Status | Befund (Logik/Optik) |
|---|---|---|---|---|
| 1 | Hülle + Navigation | Layout | umgebaut | eigene Hülle, Logo, Betriebswahl in Kopfzeile · Terminal (Chat/Agenda) wieder drin, vorerst aus core |
| 0 | Designsystem | `/_ui` | umgebaut | Musterseite aller Bausteine |
| 2 | Basisrezepte | `/rezepte` | in Arbeit | Kategorien abgeschnitten · Status-Dropdown je Zeile · Präfix-Wiederholung · dunkle Seitenleiste |
| 2 | Grundprodukte | `/gps` | offen | |
| 2 | Gerichte | `/gerichte` | offen | |
| 2 | Lieferanten | `/lieferanten` | offen | |
| 2 | Geschirr | `/geschirr` | offen | |
| 2 | Favoriten | `/favoriten` | offen | |
| 2 | Formate | `/formate` | offen | |
| 2 | Concepter (Browser) | `/concepter` | offen | |
| 2 | Concepts / Pakete | `/concepts`, `/pakete` | offen | prüfen, ob noch eigenständig nötig (Concepter vereinheitlicht) |
| 3 | Rezept-Editor | Modal | offen | Summenzeile bricht um · Entwicklersprache („Save-Recompute") · zwei leere Filterspalten · Löschen neben Speichern · 3 KI-Knöpfe · Werkbank-Modus |
| 3 | Gericht-/VK-Editor | Modal | offen | Werkbank-Modus |
| 3 | GP-Editor | Modal | offen | |
| 3 | Lieferantenartikel | Modal | offen | |
| 3 | Concepter-Editor | Seite/Modal | offen | größte View (1.550 Zeilen) |
| 3 | Formate-Editor | `formate/editor` (457 Z.) | offen | |
| 3 | Foodbook-Editor | `foodbooks/index` (996 Z.) + Leitstelle-Rail | offen | Editor steckt in der Index-View |
| 3 | Speisekarten-Editor | `speisekarte/index` (547 Z.) + Rubrik/Vorschau | offen | Editor steckt in der Index-View |
| 3 | Speiseplan-Editor | `speiseplan/editor` (1.146 Z.) + Zelle/Vorschau | offen | |
| 3 | Angebots-Editor | `angebote/editor` (925 Z.) + Zuschlagskalkulation | offen | |
| 3 | Bestell-Editor | `orders/editor` (992 Z.) | offen | |
| 3 | Produktion: Tagesplan-Editor | `produktion/tagesplan` (1.491 Z.) + editor-ziele/-zeilen/-einkauf/-vorschau | offen | |
| 3 | Wandmonitor | `/produktion/wandmonitor` (Kiosk-Layout) | offen | eigene Regeln (Fernsicht, große Schrift) |
| 3 | Zutaten-Editor (gemeinsam: Rezept-Editor + Worker) | `recipes/ingredient-editor` | offen | zwei leere Filterspalten → eine Zeile „Zutat hinzufügen" · Summenzeile bricht um · Entwicklersprache („Save-Recompute", „§1.2") |
| 3 | Planung: Worker / Kaskade (Stufen und Freigabe) | `planung/partials/step-zeile`, `ergebnis`, `board-worker-kopf` | offen | „Stufen &amp;amp; Freigabe" doppelt escaped · Gericht-Name und Kopf fast unsichtbar (helles Grau) · „Entwurf/Entwürfe" statt Pluralisierung · Rollen roh („aroma_treiber") · eingebettete Zutaten-Tabelle mit leeren Filterspalten, Rolle/Yield/Hinweis abgeschnitten · grüner Sonder-Knopf „Zutaten speichern" · Freigabe-Aktionen als verstreute Farblinks, keine Hauptaktion · EK/VK/Marge zu leise · JEDES Ergebnis (Gericht, Basisrezept) braucht eine klar abgegrenzte Karte mit lesbarem Namen und Status; Gericht → zugehörige Basisrezepte als erkennbarer Baum (Dominique 2026-10-05) · roher Datei-Input „Datei auswählen / Keine ausgewählt" sichtbar · „Kanon (0) · Recherche (25)" Fachjargon · Aktionen je Ergebnis (prüfen, ansehen, wiederverwenden, Sourcing-Lücke, Favoriten) ohne Ordnung |
| 3 | Planung (Leitstelle) | `/planung` | offen | Formular uneinheitlich (Chips vs. Dropdown, 16-px-Labels, Emoji) · Go-Leiste überdeckt Inhalt · leere linke Spalte · leere linke Fläche neben dem Formular · Reiterleiste blass/kaum lesbar · Englisch (Constraints, Draft, Go, Worker) · Emoji (⚡📷⭐) · Mono-Schrift in Platzhaltern · „(egal)“-Optionen · 3.342 Zeilen in 14 Views (planung/*, planning/*) |
| 5 | Foodbooks | `/foodbooks` | offen | Druck-/PDF-Layouts getrennt behandeln |
| 5 | Speisekarte | `/speisekarte` | offen | |
| 5 | Speiseplan | `/speiseplan` | offen | |
| 5 | Angebote | `/angebote` | offen | |
| 6 | Dashboard | `/` | offen | Einstieg: was ist heute zu tun? |
| 6 | Signale | `/zu-pruefen` | offen | 35 Typ-Chips als Wand · Meta-Signale („Qualität verschlechtert sich") vor echten Befunden · Kritisch nicht oben · Reiter Signale/Vorschläge/Pflege unklar · Seitenbalken |
| 6 | Controlling | `/controlling` | offen | |
| 6 | Wissen | `/wissen` | offen | |
| 6 | Trendradar | `/trendradar` | offen | |
| 6 | Food DNA | `/food-dna` | offen | liegt fachlich in Einstellungen |
| 6 | Einstellungen | `/einstellungen/{sektion}` | offen | viele Unterseiten |
| 6 | Bestellungen / Produktion | `/bestellungen`, `/produktion` | offen | |
| 6 | Demnächst | `/demnaechst` | offen | Emoji-Liste; für ein verkaufsfähiges Produkt entfernen oder als Roadmap |


## Welle P: Pairing (eigene Welle, nach Welle 3, Dominique 2026-10-05)

Bis dahin am Pairing-Netz NICHTS ändern (kein Doppel-Umbau).

1. **Logik prüfen:** läuft alles, ist es fachlich stimmig? Insbesondere: Basisrezepte erscheinen als Anker (grüne Knoten, z. B. „BBQ-Sauce: Mc Rib Style", „Fond: Kalbsfond mediterran") — gewollt und richtig gerechnet?
2. **Design festlegen** aus der Fachbedeutung: Pairing-Profile = harmonieren Aromen miteinander. Grundlage zuerst lesen: `07_WISSEN/07.02_Flavor_Pairing/` (Index, docs/aromakomponenten, top-pairings, verbund-pairings).
3. Befunde aus dem UI-Durchgang:
Stärke auf den ersten Blick nicht lesbar: Legende (blaue Punkte) passt nicht zu den Linien (Gold/Orange), gestrichelt vs. durchgezogen unerklärt, alle Linien ähnlich · großer oranger Mittelknoten ohne Beschriftung · Speichen-Knäuel, Beschriftungen überlappen/abgeschnitten · Sterne + Englisch („Best/Good/Match") · Ziel: Stärke über Ringe (innen = sehr stark) UND Liniendicke, eine Farbfamilie, deutsche Stufen, Hover/Fokus hebt Partner hervor, daneben eine Rangliste als Lesehilfe · Dominique konnte nicht ablesen, ob Simmentaler Rind zu A1-Sauce passt (Antwort steckte in der violetten „Brücke"-Linie + Tooltip: kein direktes Pairing, ~6 geteilte Partner) → KERNAUSSAGE ALS SATZ über dem Graphen je Anker-Paar („direkt gemessen: sehr stark" / „kein direktes Pairing, verbunden über N gemeinsame Partner: …") · Mittelknoten = Gericht beschriften · Bundle neu bauen (build.mjs)
   · Fokus auf einen Anker dimmt alles andere so stark, dass Beschriftungen kaum lesbar sind (Screenshot 2026-10-05).
   · Der Composer (Planung) hat bereits den Kasten „Passt das zusammen?" (Anker-Paare über gemeinsame Partner, Anzahl stark, stärkste Brücken) — DARAUF aufbauen: direkt über das Netz, als klarer Satz statt „1/1 Anker-Paare".
   · Hinweis: der Screenshot mit stark gedimmtem Fokus stammt aus der Demo (alter Stand), lokal ist das Netz lesbarer; Legende ≠ Linienfarben und unbeschrifteter Mittelknoten bleiben Befund.
   · Darstellungsoptionen (Chat 2026-10-05): A Brücken-Ansicht, B Matrix ab 3 Ankern, C Ringe — Entscheidung in dieser Welle.

## Querschnitt (betrifft viele Seiten)

| Thema | Status | Notiz |
|---|---|---|
| Filter-Zeilen (`filter-row`, `filter-ast`) | in Arbeit | 12 Filter-Spalten; Seitenbalken → Akzentfüllung |
| Tabellenzeile (`table-row`) | in Arbeit | alle Browser-Tabellen |
| Seitenblätterung | in Arbeit | `components/fa/pagination` |
| Allergen-Deklaration | umgebaut | `x-fa::deklaration`, 3 Orte |
| Modal-Rahmen | offen | Werkbank-Modus für Editoren |
| Kennzahl-Kacheln (`kpi-tiles`) | offen | → `x-fa::kpis` |
| Emoji (229) | offen | → Heroicons |
| Druck/PDF (dokumente/, presentation/) | offen | eigene Regeln, nach den Arbeitsseiten |

## Plattform-Ablösung (Spur 2)

Läuft getrennt, siehe Plan im Chat 2026-10-05: Team/User/Login, MCP-Tool-Verträge, KI-Provider,
Aktivitätslog, CRM-Kunden. Ziel: kein `platform-core` mehr in `composer.json`.

## Daten-Nacharbeit (ganz zum Schluss, Dominique)

Beim UI-Durchgang aufgefallen, NICHT Teil des UI-Umbaus:

- Gerichte: Desserts mit Speisen-Klasse „Fleisch" (z. B. „[DES] Butterwaffeln | Milchreis | Kirschgrütze", „[DES] Espresso-Panna Cotta") → Klasse treibt den VK-Faktor, VK dadurch falsch.
- Basisrezepte: „Geflügel Toskana mit Kichererbsen" 0,23 €/kg (unplausibel niedrig).
- Gerichte: „[FIN] Bao Bun Ente | …" Wareneinsatz 281,8 % bei VK 0,11 €.
- Signale: Texte aus dem Code mit Technik („is NOT NULL", „excel_raw_preparation") → Text-Durchgang in den Services.

## Befunde für die echte Umgebung (main, nicht durch den Umbau)

- 8 Speiseplan-Tests scheitern auch auf dem unveränderten main (2026-10-05): `McpSpeiseplanVervollstaendigungTest` (6 × „Genau einen Inhalt angeben: Concept, Paket oder Gericht."), `SpeiseplanUmbauMengenTest` (kopieren ohne Dublette: 2 statt 1), `SpeiseplanAusgabeBedarfTest` Paket 6 (vegan erwartet, fleisch geliefert).

## Lehre aus Welle 2

- Farb-Massenersetzungen NUR in Arbeits-Views, nie in `src/` (Branding-Standards für Kunden-Dokumente) und nie in `dokumente/`, `presentation/`, `layouts/presentation` (Druck/PDF/Präsentation haben eigene Regeln). 2026-10-05 zurückgesetzt: 29 Zeilen in 16 src-Dateien, 63 Zeilen in Druckvorlagen.

## Nacharbeit Welle 3A (nach Abschluss der Agenten, selbst erledigen)

- **Format-Editor:** ERLEDIGT (Zwischenstand während des Agenten-Laufs; nach Prüfung + CSS-Neubau zweispaltig korrekt, Browser 2026-10-05). War: Kasten „Einfügen" überlagert die Spalte „Aufbau" (Leertext in schmalen Streifen gequetscht) → zweispaltig ohne Überlappung (Aufbau links breit, Einfügen rechts schmal) oder Einfügen als aufklappbarer Bereich.
- **Zutaten-Editor:** ERLEDIGT 2026-10-05: Einfügen wie im Original (links Grundprodukte mit Filtern + Treffern, Mitte Suche + Tabelle, rechts Basisrezepte), Filter untereinander; neue Verbesserungen (Rollen lesbar, Summe, Ausbeute) behalten. War: Suche zeigt ohne Eingabe bereits alle Treffer (7.819 Grundprodukte + 2.220 Basisrezepte) und schiebt die Zutaten-Tabelle aus dem Blick → Tabelle zuerst; Treffer erst beim Tippen als kompakte Liste unter dem Feld, nach dem Einfügen zu. Die eine Suchzeile statt zwei leerer Filterspalten bleibt.
- **Reiter (editor-tabs):** prägnanter gemacht (14 px, aktiver Reiter Akzentfarbe + fett + Fläche) — erledigt 2026-10-05.

## Welle 3B — Abschluss (2026-10-05)

Umgebaut + verifiziert (8/8 Prüfer „Funktion erhalten"): Concepter-Editor, Planung (Leitstelle, Leitplanken,
Worker/Kaskade, Ergebnis), Foodbook, Speisekarte, Produktion, Tagesplan/Küchenmonitor. Regel-Stand neu
eingefroren: 106 Dateien mit Altlasten (vorher 145, Ausgang 194).

Browser-Abnahme 1280 / 1440 / 1920 — dabei selbst behoben:
- Werkbank-Modus: `--nx-*`/`--ui-*` im dunklen Block neu gebunden (auf `:root` aufgelöst vererbten sie Hell —
  Sprachbefehl-Feld war weiß im dunklen Editor).
- `detail-sidebar`: unter 1536 px Schublade über dem Inhalt statt Quetschen (Concepter 1280 px: Mitte ~90 px);
  ab 1536 px daneben, höchstens 30 % Fensterbreite. Wirkt auf alle 32 Seiten mit Detail-Panel.
- Tagesplan-Dashboard: Karten-Raster nach Platz (`auto-fit`), nicht nach Fensterbreite — Titel abgeschnitten.
- Concepter-Liste: 0,00 € bei vorhandenen Positionen → „Preis fehlt"; Spalte „Eventtyp · Servierform" → „Einsatz"
  (wie Filtergruppe), Zelle bricht um — Preisspalte war abgeschnitten.
- Planung: Klick in Liste/Board öffnet jetzt das Detail-Panel (vorher nur Markierung bei zugeklapptem Panel).

Offen:
- Actionbar (Plattform `x-ui-page-actionbar`): Brotkrumen werden von Aktionen verdrängt → eigene FA-Leiste (Spur 2).
- Worker-Zutaten-Tabelle: rechte Spalte („Preis"-Plakette) knapp abgeschnitten bei 1440.
- Modal-Kopf auf Laptops kompakter (Titel + Aktionen + KPIs + Reiter ≈ 250 px).
- Sensorik-Radar (max. 360 px) in GP-, Rezept-, Gericht-Editor prüfen.

## Rote Tests behoben (2026-10-05) — Befunde auch für main relevant

- **Code-Fehler Diät-Kennzeichnung (`SpeiseplanService::diaetMerkmale`):** unbekannte Diät-Angaben (spec_is_* = NULL,
  GP ohne Tags) wurden auf dem Aushang zu „Fleisch". Jetzt nur „Fleisch", wenn mind. ein Gericht belegt nicht
  vegetarisch ist (`fleisch_belegt` im Rollup von `ConcepterAggregateService`). Wahrscheinliche Ursache der
  „Desserts als Fleisch" in der Daten-Liste. Regressionstest in `SpeiseplanAusgabeBedarfTest`.
  Offen (bewusst nicht geändert, Kennzahl-Vertrag): `SpeiseplanService` Diät-Statistik zählt unbekannt als „omnivor".
- **Test-Fixture veraltet (`McpSpeiseplanVervollstaendigungTest`, 6):** Eintrag ohne Inhalt — seit Spec 57 · 0.1 gesperrt.
  Fixture legt jetzt ein Gericht an.
- **Test-Fixture (`SpeiseplanAusgabeBedarfTest` Paket 6):** vegan am Gericht gesetzt, Recompute leitet aus GP-Tags ab
  und überschreibt → Tags am GP gesetzt.
- **`kopiereEintrag` Dubletten-Prüfung:** `where('entry_date', 'Y-m-d')` griff unter SQLite nicht (Datum mit Uhrzeit);
  MySQL (DATE) war korrekt. Jetzt `whereDate` — datenbankunabhängig.

Planung: Name des Ergebnisses jetzt einheitlich in Liste, Board, Details und Editor-Kopf (Dominique 2026-10-05).
Das Titel-Eingabefeld behält den gespeicherten Planungstitel.

## Welle 4 — Abschluss (2026-10-05)

Umgebaut + geprüft (6/6 „Funktion erhalten"): Einstellungen (alle Sektionen), Controlling (Cockpit + Panels),
Wissen, Trendradar, Food DNA, Kalkulator, Simulation, Canvas, Demnächst, Rezept-Detail, Generator, Feedback,
Vorlage, Sprachbefehl, Hinweis-Bausteine. Regel-Stand: 40 Dateien mit Altlasten (vorher 106) — Rest fast nur
Druck/PDF (`dokumente/`, `presentation/`) und Pairing.

Dunkler Rahmen (Dominique 2026-10-05): Chat-Leiste + alle Detail-Panels im Werkbank-Modus (Navigation, Chat,
Detail dunkel — Arbeitsfläche hell). Chat über `--t-*`-Brücke in foodalchemist-pass.css, ohne Core-Änderung.

Zur Entscheidung (Dominique):
- Einstellungen-Navigation jetzt in 5 Gruppen — widerspricht der Entscheidung vom 2026-08-28 (bewusst keine Gruppen,
  weil manche Sektionen in zwei Töpfe gehören). Rückbau: `Settings\Index::GRUPPEN` leeren.
- Umbenannte Einstellungen: Rezept-Kategorien, Gerichte-Kategorien, Konzept-Merkmale, Kunden-DNA, Einkauf & Lieferanten,
  Rollen & Stundensätze.

Offen aus Welle 4:
- Kalkulator bleibt stillgelegt (#379, Dominique 2026-10-05 bestätigt) — Route leitet auf Controlling/Simulation.
- Hover-only-Aktionen (Behälter, Konzept-Merkmale, Taxonomie-Listen) auf Touch unsichtbar.
- Texte aus Services mit Entwicklerbegriffen: WissensProfilService-Befunde, LeadLaStrategie::description (GL-03/M1-06),
  Demnaechst::DOMAENEN (Emoji), Planung\Index::RICHTUNGEN („(egal)", „From Scratch").
- save-bar: Hauptaktion steht links (Baustein, Anatomie-Regel sagt rechts).
- Präsentations-Designs: rechte Leiste (Aufbau/Farben/CSS) rutscht bei 1280/1440 unter die Vorschau.

## Hinweistexte, Detail-Panels, Druck intern (2026-10-05/06)

- Hinweistexte (Signale, Wissen/KI, Enums/Labels, Meldungen) in Küchensprache; Wortlaut-Abhängigkeiten geprüft,
  ausgewertete Texte unverändert. Komplette Suite danach grün (4.851 / 6 übersprungen).
- Detail-Panels: alle 16 auf Anatomie (`x-fa::detail-kopf`, `x-fa::menu-item`), dunkler Rahmen. Produktionsauftrag:
  nächster Arbeitsschritt = Hauptaktion. Wissen: Aktivieren/Löschen nur in der Mitte.
- Druck intern: Muster `report.blade.php` + Rezept-Knoten (Logo, Logo-Blau, kompakt, Mengen kurz, Allergene gruppiert);
  Produktionsschein, Blatt, Anleitung, Tagesplan-Blatt (jetzt mit PDF), Bestellung, Bestelllauf, Report-Rest nachgezogen.
  Begriffe: „Bestellwert netto/gesamt", „Einkaufswert gesamt".
- Offen: Kunden-Dokumente (Speisekarte, Speiseplan, Angebot, Concept-Karte, Foodbook, Format) — Kunden-Branding bleibt.
- Daten-Runde: GP „Achelse Kluis Brown" Allergen-Konfidenz „Hoch" bei 14/14 ohne Angabe (Widerspruch).
- Betrieb: PDF volle Kaskade braucht ~155 MB (PHP-Standard 128 MB) — Server-memory_limit für den Standalone-Betrieb anheben.
