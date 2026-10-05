# 57 · Speiseplan: Aufmachung und Betrieb

**Stand 2026-09-28 · Branch `docs/spec57-speiseplan-aufmachung` off main (`02e2dfc7`) · Status: umgesetzt, gemergt (main `9157a922`, 2026-10-05), auf demo — Abnahme offen**

Klickbarer Entwurf mit Beispieldaten: <https://claude.ai/artifact/JoRo6cwY5XWPmR5UXQYJru>
(privat, Freigabe über das Share-Menü). Die Ideen-Nummern im Entwurf entsprechen den Paketen unten.

## Anlass

Max (2026-09-28): Die gesamte Aufmachung des Speiseplan-Moduls soll überarbeitet werden. Als
Referenz diente ein produktiver Menüplaner (meinbusiness „Recipe & Menu Engineering“:
Menüplan, Mengenplanung, Bedarfsplanung, Plan/Ist). Er wurde nur lesend angesehen. Vorgabe für
diese Runde: **alles erst planen**, dann ein Mockup abstimmen, danach paketweise bauen.

Diese Spec ergänzt `docs/speiseplan.md` (Nutzersicht) sowie Spec 42 (Planung lebt in der
Leitstelle, der Speiseplan ist Ausgabeform) und Spec 43 (Präsentation). Beide bleiben gültig:
Die KI-Planung startet weiter aus der Leitstelle, der Speiseplan-Editor kuratiert, rechnet und
gibt aus.

## Ist-Stand in einem Absatz

Plan (`foodalchemist_menu_plans`) → Linien (`_menu_plan_lines`: nur `name`, `color`,
`is_vegetarian`, `sort_order`) → Einträge (`_menu_plan_entries`: `entry_date`, `meal`, `line_id`,
genau einer von `concept_id`/`package_id`/`sales_recipe_id`, `pax`). Der Editor
(`src/Livewire/Speiseplan/Editor.php`) zeigt eine Matrix Linien × Mo–Fr für eine Mahlzeit.
Jede Zelle hat nur Name, Pax-Feld und ✕ (`editor.blade.php:122-130`). Rechts liegt eine
Kennzahlen-Rail (VK/EK, Budget, Kostformen, LMIV, DGE-Ø, Abwechslung, Wiederholungen). Alle
Schreibpfade laufen über `SpeiseplanService`. Dazu kommen rund 20 MCP-Tools und die
Voll-Kaskade über `PlanningCascadeService::starteSpeiseplanVollkaskade`.

## Was wir aus der Referenz übernehmen und was nicht

| Referenz | Übernehmen? |
|---|---|
| Eintrag zeigt Wording, VK, Soll-Wareneinsatz in € und % gegen ein **Zielband je Linie** mit Ampel | Ja, Paket 1 und 2 |
| Linie = Verkaufsartikel mit Kassennummer, Preisstufe und festem VK | Teilweise. Rolle, Kassen-Nr. und Zielband ja, Preisstufen nein (neue Domäne) |
| Mengenplanung Linie × Tag mit Vorperiode, „übernehmen“, Skalierungsfaktor, Umsatz | Ja, Paket 3 |
| Bedarfsplanung aus Plan × Mengen, „Bedarf anfordern“ | Ja, Paket 4, über vorhandene Einkaufslogik |
| Zeitraum auf Zieldatum kopieren, optional zusammenführen | Ja, Paket 5 |
| Mehrere Word-Vorlagen je Speisekarte (Aushang, Tischaufsteller, HACCP), Excel-Export, EN-Übersetzung | Formate ja (Paket 6), Word-Vorlagen und EN erst nach Entscheid |
| Speisekarte als Vorlage, Betriebe abonnieren | Als Option, Paket 7, Entscheid nötig |
| Plan/Ist mit Kassendaten | Ausblick, Paket 8, Datenquelle fehlt |
| Kennzeichnung als Pseudo-Zutat („Kennzeichnung Vegan 1 g“) | **Nein.** Wir leiten Kennzeichnung aus Rezepten ab |
| Woche als lange Liste, Komponenten dominieren | **Nein.** Matrix bleibt, Komponenten nur in der Detail-Dichte |

---

## Paket 0 · Befunde vor dem Ausbau (Voraussetzung)

Diese Punkte sind im Code belegt und sollten vor oder mit Welle A behoben werden.

| # | Befund | Stelle | Fix-Richtung |
|---|---|---|---|
| 0.1 | `addEintrag` prüft weder „genau ein Inhalt“ noch, ob Concept, Paket oder Gericht für das Team sichtbar sind. Der Editor reicht die ID ungeprüft durch. Das prüft nur das MCP-Tool. | `SpeiseplanService.php:325-350`, `Editor.php:502-513`, `SpeiseplanEintraegePostTool.php:63-72` | Prüfung in den Service ziehen: IDs team-scoped laden (`visibleToTeam`), genau einer Pflicht. Tool nutzt dann den Service-Guard. Negativer Cross-Tenant-Test. |
| 0.2 | „→ Produktion“ ist nicht idempotent. Jeder Klick legt je Werktag neue Aufträge an. | `SpeiseplanService.php:732-777`, `ProductionOrderService.php:139-159` | Upsert über den vorhandenen `source_ref` (`menuplan:{plan}:{ymd}:…`). Ein zweiter Klick aktualisiert Mengen statt zu verdoppeln. |
| 0.3 | `vorlageAusrollen` übernimmt `pax` nicht und prüft Dubletten je Inhalt statt je Zelle. | `SpeiseplanService.php:1007-1058` | Pax mitnehmen. Zellregel festlegen (siehe Entscheidung E5). |
| 0.4 | Veröffentlichen im Editor übergibt weder Woche noch Mahlzeit. Der Aushang friert immer Startwoche und Mittag ein. | `Editor.php:138-152`, `PresentationService.php:366-368` | Aktuelle Editor-Woche und Mahlzeit übergeben. Option „immer die laufende Woche“ siehe Paket 6. |
| 0.5 | Aushang zeigt vermutlich den internen Namen statt des Wordings. `detail()` lädt beim Gericht nur `id,name,sales_net,ek_total_eur`, `WordingResolver` liest `sales_wording_standard`. Im Strict-Mode droht eine Exception. | `SpeiseplanService.php:86`, `:503-513`, `WordingResolver.php:36`, `routes/web.php:527` | Spaltenliste ergänzen. Auf demo gegenprüfen. |
| 0.6 | Die Budget-Ampel summiert EK über alle Linien eines Tages, obwohl ein Gast meist eine Linie isst. Sie zeigt dadurch zu hohe Werte. | `editor.blade.php:493-507`, `SpeiseplanService.php:442-462` | Mit Paket 1/2 auf „je Gast und Linie“ umstellen (Entscheidung E2). |
| 0.7 | Status-Sperre uneinheitlich. MCP erlaubt Einträge nur im Entwurf, UI und Service in jedem Status. | `SpeiseplanEintraegePostTool.php:60-62` | Eine Regel festlegen (E6) und in den Service ziehen. |
| 0.8 | Reife-Adapter und Test behaupten, es gäbe kein MCP-PUT für den Plankopf. `speiseplaene.PUT` gibt es seit b369eebc. | `SpeiseplanReifeAdapter.php:11-13, :109-121`, `McpReifeContainerTest.php:127` | Adapter und Test nachziehen. |
| 0.9 | Aufräumen: `veggieCheck` ist toter Code (Schlüssel `aktiv`/`active`). N+1 über die Aliase `paket`/`gericht` in `inhaltName()`. Tote Status-Maps in `partials/detail.blade.php`. Veraltete Kommentare (`routes/public.php:9-10`, Roadmap 38 „Cap 30“). | `SpeiseplanService.php:468-486`, `FoodAlchemistSpeiseplanEintrag.php:93-96` | Mit dem ersten Paket erledigen. |

Aufwand: S–M. Keine Produktentscheidung nötig außer E5 und E6.

---

## Paket 1 · Zelle „auf einen Blick“

**Ziel.** Man sieht in der Matrix, ohne zu klicken, was auf dem Teller liegt, was es kostet und ob
es passt.

**Anzeige je Eintrag** (Dichte „Kompakt“):
- Name und Wording (`WordingResolver::fuerGericht`), zweizeilig
- Kostform-Kürzel und Allergen-Buchstaben (Aggregat aus `ConcepterAggregateService:416-478`)
- VK, Wareneinsatz-% mit Ampel gegen das Zielband der Linie (Paket 2), Pax

Dichte „Detail“ zeigt zusätzlich die Komponenten mit Grammatur und kcal je Portion.
**Tagesfuß** je Spalte: Summe Hauptgänge (Pax), Umsatz netto, Ø Wareneinsatz.

**Umsetzung.**
- Neue Service-Methode `zellenKennzahlen(Team, Plan, montag, mahlzeit)`. Sie liefert je Eintrag
  ein fertiges Array und je Tag die Summen. Sie nutzt vorhandene Bausteine (`eintragPreis`,
  Kennzeichnungs-Aggregat, `WordingResolver`) und rechnet nicht neu.
- Eager Loading mit allen benötigten Spalten in einem Zug. Damit sind 0.5 und das N+1 aus 0.9
  erledigt. `render()` ruft heute acht Aggregat-Methoden auf, die werden zusammengefasst.
- Wareneinsatz-% nur berechnet, nicht gespeichert (wie `SpeisekarteService:611-642`).
- Blade: neue Zell-Partial, Dichte-Umschalter als Livewire-State.

**Lücken in den Daten.**
- Fleischart: Es gibt nur `spec_contains_pork`/`spec_contains_beef`. Geflügel und Fisch fehlen
  (Entscheidung E3).
- kcal gibt es nur je 100 g, je Portion wird über `sales_quantity_per_unit_g` gerechnet.

**Tests.** Kennzahlen je Eintrag und Tag (Concept, Paket, Gericht). Ampel an Bandgrenzen.
Ein Fremdteam-Gericht erscheint nicht.

Aufwand: M.

## Paket 2 · Linie als Ausgabestelle

**Ziel.** Die Linie trägt, was an der Ausgabe gilt: Rolle, Kassen-Nr., Standard-VK, Zielband für
den Wareneinsatz, optional Standard-Pax und „Dauerangebot“.

**Migration (additiv, `foodalchemist_menu_plan_lines`):**
- `role` (suppe|hauptgang|salat|beilage|dessert|sonstiges, nullable)
- `plu` (string, nullable)
- `price_mode` (auto|manuell, Default auto) und `price_value` (decimal, nullable), gleiches
  Muster wie die Speisekarte-Position (`SpeisekarteService:440-444`)
- `target_wes_min_pct` und `target_wes_max_pct` (nullable; leer bedeutet: Fallback auf
  `TeamSettingsService::zielWareneinsatzPct(team, outlet)`)
- `default_pax` (nullable)
- `is_standing` (bool, „Dauerangebot“: von der Wiederholungsregel ausgenommen)

**Schreibpfade, die mitgezogen werden:**
- `addLinie`, die Startlinien in `create`, die Whitelist in `updateLinie`
- `dupliziere`: neue Felder dort **explizit** ergänzen
- MCP `speiseplan_linien.POST/PUT`
- `dokumentDaten` und `PresentationService::normalizeSpeiseplan`
- `wiederholungen()`: Dauerangebote ausnehmen

**Regel für den Preis (Vorschlag).** Bei `price_mode=auto` gilt wie heute der VK des Gerichts. Bei
`manuell` gilt der Linienpreis für Anzeige und Kennzahlen. Das Gericht behält seinen VK, die
Drift-Prüfung vergleicht gegen den Linienpreis. Damit gibt es immer genau eine Wahrheit je
Eintrag (Entscheidung E1).

Aufwand: M. Preisstufen oder Subventionen (Mitarbeiter/Gast) sind **nicht** Teil davon, das wäre
eine neue Produktdomäne.

## Paket 3 · Mengen-Tab

**Ziel.** Mengen je Linie und Tag an einem Ort planen, statt Pax in jede Zelle zu tippen.

**Anzeige.** Matrix Linie × Tag. Spalten: Vorwoche, Ø 4 Wochen, Mo–Fr (bzw. Öffnungstage), Σ,
Anteil, Wareneinsatz, VK netto, Umsatz. Dazu die Aktionen „Aus Vorwoche übernehmen“ und
„Skalieren“.

**Datenmodell (Vorschlag).** Keine neue Tabelle. Die Matrix schreibt in `entries.pax`. Für Zellen
ohne Eintrag gilt `lines.default_pax` bzw. `plans.default_pax`. „Vorwoche“ und „Ø 4 Wochen“
kommen zunächst aus den **Planwerten** früherer Wochen. Ist-Werte kommen erst mit Paket 8.

**Umsetzung.**
- Service-Methoden `mengenMatrix(...)`, `setzeMengen(...)` (Batch), `uebernehmeVorwoche(...)`
  und `skaliere(...)`.
- MCP `speiseplan_mengen.GET/PUT`.
- `vorlageAusrollen` nimmt Pax mit (0.3).

**Achtung.** Pax bedeutet je Inhalt etwas anderes. Beim Concept sind es Personen, beim Paket
Portionen je Gericht (`SpeiseplanService:755-763`). Die Matrix muss das anzeigen.

Aufwand: M. Eine echte Prognose ist XL und braucht eine Datenquelle (E8).

## Paket 4 · Bedarf

**Ziel.** Den Zutatenbedarf der Woche aus Plan × Mengen sehen und an den Einkauf geben.

**Umsetzung.** Die Rechnung gibt es schon: Die Zielliste wird so gebaut wie in
`wocheAnProduktion` (`SpeiseplanService:751-763`) und an `PlanungsblattService::einkaufsliste`
übergeben. Das löst bis auf GP-Ebene auf, rechnet in der Basiseinheit, gruppiert nach Warengruppe
und Lead-Lieferantenartikel und rundet auf Gebinde.

- v1 ist ein reiner Lese-Tab: Woche oder je Tag, gruppiert nach Warengruppe, mit
  Lieferantenzuordnung.
- Die Übergabe an den Einkauf läuft **über die Produktion** (vorhandener Weg „Bedarf freigeben“,
  Quelltyp `production`). So gibt es keine Doppelzählung (Entscheidung E7).

**Risiken.** GPs ohne Lead-LA landen in „ohne Lieferant“. Die Auflösung über viele Wochen muss
gemessen werden. Bestand und Vorlauf sind in v1 nicht berücksichtigt.

Aufwand: S–M für den Lese-Tab.

## Paket 5 · Kopieren und Umbauen

**Ziel.** Einträge verschieben, ersetzen und kopieren, Tage und Wochen kopieren, Einträge aus
„Ohne Linie“ zuordnen.

**Service (neu):**
- `verschiebeEintrag(team, id, datum, lineId)`
- `ersetzeEintrag(team, id, inhalt)`
- `kopiereEintrag(team, id, daten[])`
- `kopiereWoche(team, plan, vonMontag, nachMontag, zusammenfuehren, mitPax)`
- `sortiereZelle(...)`

Jede Methode prüft den Besitzer (`guard`), stellt sicher, dass die Linie zum Plan gehört (wie
`:332`), pflegt `weekday` mit und läuft durch die Prüfung aus 0.1.

**UI.** HTML5-Drag&Drop mit Alpine nach dem Muster der Speisekarte
(`speisekarte/partials/rubrik.blade.php:15-48`, `SpeisekarteService::movePosition`). **Immer mit
Tastatur-Alternative** (Eintrag fokussieren → „Verschieben …“ mit Zielauswahl). Das schließt den
Befund MVP-032 für die Matrix. Spec 40 hat sich für verschachtelte Strukturen bewusst gegen
Drag&Drop entschieden. Die Matrix ist flach (Zelle → Zelle), deshalb ist Drag&Drop hier
vertretbar (Entscheidung E4).

**MCP:** `speiseplan_eintraege.PUT` (ersetzen, Linie, Datum), `speiseplan_eintraege.GET`
(Einträge endlich lesbar), `speiseplan.WOCHE_KOPIEREN`.

Aufwand: M.

## Paket 6 · Ausgabe-Formate

**Ziel.** Aus einem Plan die Ausgaben, die im Betrieb gebraucht werden. Die Kennzeichnung kommt
immer aus den Rezepten.

| Format | Stand | Aufwand |
|---|---|---|
| Wochenaushang A4 | vorhanden (`dokumente/speiseplan.blade.php`), Wording-Fix 0.5 | S |
| Tischaufsteller (ein Tag, alle Linien) | neu, gleiche Datenbasis `dokumentDaten` | S–M |
| Linienschild (Linie × Tag, mit Preis) | neu | S |
| Allergen- und Komponentenliste (Ordner an der Ausgabe) | neu, `report-declaration`-Partial wiederverwenden | M |
| CSV-Export der Woche | neu, `fputcsv` + BOM wie Produktionsschein (`routes/web.php:673-701`) | S |
| Digitaler Aushang | vorhanden; Woche/Mahlzeit wählbar (0.4), Option „immer die laufende Woche“ | S–M |
| HACCP-Doku (R5.3) | nicht in dieser Runde, eigene fachliche Abnahme | M–L |
| Englische Namen | **zurückgestellt**, neue Domäne (Übersetzungsfelder, Pflege, KI) | XL |

„Immer die laufende Woche“ bedeutet: Der veröffentlichte Snapshot wird wöchentlich neu erzeugt
(Job) statt einmal eingefroren. Das ist mit dem Snapshot-Prinzip aus Spec 43 abzustimmen
(Entscheidung E9).

## Paket 7 · Vorlage für Betriebe

**Ziel.** Ein zentral gepflegter Plan, den mehrere Betriebe nutzen und lokal anpassen.

**Ist.** Betriebe (`FoodAlchemistOutlet`) sind flach und gehören einem Team. Ein Plan kann schon
heute je Betrieb einen eigenen Aushang-Link haben (`Editor.php:177-212`). Ein Abo mit Anpassungen
und laufendem Abgleich gibt es nirgends im Modul. `dupliziere` verlangt Besitz, deshalb kann ein
Kind-Team den Plan seines Eltern-Teams nicht kopieren.

**Optionen:**
- (a) Bei einem Plan mit Aushang je Betrieb bleiben. Preise laufen über den Betrieb. Das gibt es
  schon.
- (b) Kopie mit `source_plan_id` und einer Abgleich-Ansicht („3 Änderungen in der Vorlage“), analog
  `AusgabeDriftService`.
- (c) Live-Vererbung mit Überschreibungen je Betrieb.

**Vorschlag.** Erst klären, ob (a) reicht. Falls nicht, (b). (c) nicht, weil es gegen D1 läuft
(Kinder lesen live, schreiben nicht). Entscheidung E10, mit Dominique und ggf. Martin
(Team-Hierarchie ist Core).

Aufwand: L–XL.

## Paket 8 · Plan/Ist (Ausblick)

**Ist.** `foodalchemist_sales_facts` (`recipe_id`, `qty_sold`, `revenue_net`, `sold_at`,
`source_scope_label`) mit CSV-Import (`SalesImportService`). Der Betrieb steht nur als Rohtext
darin, `outlet_id` fehlt. Essenszahlen fehlen ganz.

**Machbar ohne neue Quelle.** Ein Lese-Abgleich je Tag × Gericht: Einträge (`entry_date`,
`sales_recipe_id`, `pax`) gegen `sales_facts`. Concepts und Pakete werden vorher in Gerichte
aufgelöst.

**Braucht Entscheidung (E8).**
- Kassenanbindung bzw. Format
- `outlet_id` in `sales_facts` oder eine Zuordnung von `source_scope_label`
- Essenszahlen als eigene Ist-Größe

Aufwand: M (read-only je Gericht) bis XL (Kasse live).

## Paket 9 · Öffnungstage, Wochenende und Mahlzeiten

**Ziel.** Der Plan kennt seine Öffnungstage. Mo–Fr ist nur noch der Standard.

**Ist.** Mo–Fr ist an mehreren Stellen fest verdrahtet:
- `Editor.php:623-626`
- `SpeiseplanService.php:640` (Aushang) und `:743` (Produktion)
- Voll-Kaskade `PlanningCascadeService.php:719`

Per MCP angelegte Einträge für Sa/So sind nirgends sichtbar. Die Kaskade plant nur Mittag
(`:687`).

**Umsetzung.**
- Neues Feld am Plan: `opening_days` (JSON, Standard [1..5]).
- Eine zentrale Hilfsmethode liefert die Tage einer Woche. Alle vier Stellen nutzen sie.
- Mahlzeiten: Linien bekommen optional `meal` (Frühstück, Abend …). Sonst zeigt jede
  Mahlzeit-Ansicht alle Linien, was für Frühstück selten passt (Entscheidung E11).

Aufwand: S–M.

## Paket 10 · Voll-Kaskade planweit

**Ziel.** Die KI füllt einen Plan so, dass er als Ganzes stimmt, und startet erst nach
Bestätigung.

**Schritt 1 (S).** Bestätigungsschritt am Knopf mit Anzahl der leeren Zellen und dem Hinweis auf
KI-Kosten. Heute startet der Knopf sofort (`editor.blade.php:16`). Dazu Backlog #54: keine
verwaiste Session, wenn der Plan keine Linien hat.

**Schritt 2 (M–L).** Jede Zelle bekommt statt nur „Session-Brief + Linienname“ einen
**Zellen-Brief aus dem Planzustand**:
- bereits belegte und in diesem Lauf erzeugte Gerichte (Wiederholungsregel über den Plan)
- noch fehlende Kostformen des Tages
- Zielband bzw. Ziel-VK der Linie (Paket 2, Backlog #90)
- Abwechslung der Hauptgruppen

Die Zellen werden dafür nicht mehr völlig unabhängig erzeugt. Dazu gibt es zwei Möglichkeiten:
- ein Vorplanungs-Schritt, der alle Zell-Briefs auf einmal verteilt
- eine gestaffelte Abarbeitung je Tag

Der Durchsatz (Spec 53, „skaliert für Speiseplan nicht“) ist dabei mitzudenken. Heuristisch
bleibt heuristisch: Das Ergebnis weist aus, welche Leitplanken verletzt blieben (LLM_GUIDE §3).

Aufwand: S (Schritt 1) plus M–L (Schritt 2). Backlog #80 (Copy-Code der drei Trigger) wird dabei
mit aufgeräumt.

---

## Reihenfolge (Vorschlag)

| Welle | Pakete | Warum zuerst |
|---|---|---|
| A | 0, 1, 9, 10 Schritt 1 | Behebt belegte Fehler und bringt den größten sichtbaren Effekt ohne neue Domäne |
| B | 2, 5, 3 | Linie trägt das Zielband für Paket 1, Umbauen und Mengen bauen darauf auf |
| C | 4, 6 | Nutzt vorhandene Einkaufs- und Dokumentlogik |
| D | 7, 8, 10 Schritt 2, EN | Braucht Entscheidungen oder eine Datenquelle |

Jede Welle ist ein eigener Branch und PR. Die Doku wird je Welle mitgezogen: `docs/speiseplan.md`
(Nutzersicht, heute sehr dünn), Matrix 25/26 (Funktions- und Tool-IDs), diese Spec.

## Offene Entscheidungen

| # | Frage | Vorschlag | Wer |
|---|---|---|---|
| E1 | Preis: Linie oder Gericht? | Gericht, Linie nur bei `price_mode=manuell` | Dominique |
| E2 | Budget/Wareneinsatz je Gast und Linie statt Summe aller Linien? | Ja; Budget-Feld (€/Person) bleibt, Zielband in % kommt dazu | Dominique |
| E3 | Fleischart: neue Flags (Geflügel, Fisch) oder aus GP-Warengruppe ableiten? | Ableiten, mit Konfidenz; Flags nur, wenn die Ableitung nicht reicht | Dominique |
| E4 | Drag&Drop in der Matrix trotz Spec-40-Entscheid? | Ja, flache Matrix, mit Tastatur-Alternative | Dominique |
| E5 | Ausrollen/Woche kopieren: überschreiben oder ergänzen? | Wahl im Dialog, Standard „ersetzen“ | Max |
| E6 | Einträge nur im Entwurf änderbar oder in jedem Status? | In jedem Status, Änderungen an aktiven Plänen markieren (Drift) | Dominique |
| E7 | Bedarf direkt an den Einkauf oder nur über die Produktion? | v1 nur über Produktion | Dominique |
| E8 | Datenquelle für Ist-Werte und Prognose (Kasse, Essenszahlen)? | Offen, vor Paket 8 klären | Max / Dominique / Martin |
| E9 | Digitaler Aushang „immer die laufende Woche“ trotz Snapshot-Prinzip? | Ja, wöchentlicher Job erzeugt einen neuen Snapshot | Dominique |
| E10 | Vorlage für Betriebe: reicht ein Plan mit Aushang je Betrieb? | Erst prüfen, sonst Kopie mit `source_plan_id` | Dominique / Martin |
| E11 | Linien je Mahlzeit? | Ja, optionales `meal` an der Linie | Max |
| E12 | Tests ohne lokale Sandbox: wo läuft die Pest-Suite vor dem Merge? | Sandbox einmal aufsetzen oder Suite bei Dominique laufen lassen | Max |

## Nicht-Ziele dieser Runde

- keine Kassenanbindung, keine Preisstufen oder Subventionen
- keine Word-Vorlagen und keine xlsx-Abhängigkeit ohne eigenen Entscheid
- keine Übersetzungsdomäne
- nichts an Core oder UI-Modul; Team-Hierarchie wird genutzt, nicht verändert

## Entscheidungen (Max, 2026-09-28)

Die Vorschläge aus der Tabelle oben gelten als entschieden; Dominique kann im jeweiligen PR
widersprechen. Präzisierungen beim Bau:

- **E3:** keine geratene Tierart. Aus vorhandenen Flags: vegan, vegetarisch, Schwein, Rind, Fisch
  (Allergen „Fisch“ enthalten); alles andere mit Fleisch heißt „Fleisch“ (Tierart nicht gepflegt).
- **E5:** Ausrollen lässt belegte Zellen stehen (wie die Oberfläche es verspricht), optional
  „ersetzen“. Woche kopieren ersetzt standardmäßig, optional „zusammenführen“.
- **E6:** Service und UI erlauben Änderungen in jedem Status; MCP bleibt bewusst strenger (nur
  Entwürfe), weil Agenten Vorschläge machen und nicht in laufende Pläne schreiben.
- **Englisch:** zurückgestellt, eigene Spec.
- **E10:** Vorlage für Betriebe = verknüpfte Kopie je Betrieb im selben Team, mit Abgleich.

## Definition of Done je Paket

- Schreibpfad komplett über `SpeiseplanService` (Livewire und MCP ohne eigene Fachlogik)
- Positiv-, Fremdteam-, Parent- und Global-Test für jede neue öffentliche Aktion
- Migrationen additiv und rückrollbar; MySQL-Smoke bei Schemaänderungen
- MCP-Tools und Matrix 26 mitgezogen, `docs/speiseplan.md` aktualisiert
- `php -l`, Blade kompiliert, `npm run build`, passende Pest-Suite grün (E12)
- Abnahme auf demo durch Dominique

## Umsetzungsstand

| Welle | Pakete | Branch | Stand |
|---|---|---|---|
| A | 0, 1, 2, 9, 10.1 | `feat/spec57-welle-a` | gebaut, Tests geschrieben (`SpeiseplanAusgabestelleTest`), lokal nur `php -l` und Blade-Kompilierung — Pest-Lauf steht aus (E12) |
| B | 5, 3 | `feat/spec57-welle-b` | gebaut, Tests geschrieben (`SpeiseplanUmbauMengenTest`), Pest-Lauf steht aus |
| C | 4, 6 | `feat/spec57-welle-c` | gebaut, Tests geschrieben (`SpeiseplanAusgabeBedarfTest`), Pest-Lauf steht aus |
| D | 7, 8, 10.2 | `feat/spec57-welle-d` | gebaut, Tests geschrieben (`SpeiseplanVorlagePlanIstTest`), Pest-Lauf steht aus |

Welle A im Detail:
- 0.1 `addEintrag` prüft Inhalt (genau einer, sichtbar) im Service — Editor und MCP laufen darüber.
- 0.2 `wocheAnProduktion` aktualisiert offene Aufträge statt zu verdoppeln, lässt laufende stehen.
- 0.3 Ausrollen je Zelle, Pax wandern mit, optional „ersetzen“.
- 0.4 Veröffentlichen übergibt Woche und Mahlzeit des Editors.
- 0.5 `detail()` lädt die Gerichte vollständig (Wording im Aushang).
- 0.8 Reife-Adapter zeigt auf `speiseplaene.PUT`; 0.9 `veggieCheck` entfernt, N+1 in `inhaltName()` behoben.
- Paket 1: `SpeiseplanService::zellenKennzahlen()` + `partials/zelle.blade.php`, Dichte kompakt/detail, Tagesfuß.
- Paket 2: Migration `2026_09_28_000001` (Rolle, PLU, Preis-Modus, Zielband, Standard-Essen, Dauerangebot, Mahlzeit), Linien-Tab, Linien-Ampel in der Rail, Budget je Gast (`budgetAmpel`).
- Paket 9: `opening_days` am Plan; Matrix, Aushang, Produktion, Aggregate und Kaskade nutzen `wochenTage()`.
- Paket 10.1: `vollKaskadePruefen` + Bestätigungsfeld; Backlog #54 (keine verwaiste Session) behoben.

Welle B im Detail:
- Paket 5: `verschiebeEintrag`, `ersetzeEintrag`, `kopiereEintrag`, `kopiereWoche`, `eintragsListe` im Service. Editor: Drag & Drop (Alpine, Muster wie Speisekarte) und Eintrag-Detail als Tastatur-Weg (Ersetzen über den Picker, Verschieben, auf Tage kopieren, Entfernen), „Woche kopieren“ (ersetzen oder zusammenführen, Pax optional).
- Paket 3: `mengenMatrix`, `setzeZellenPax`, `uebernehmeVorwoche`, `skaliereWoche`; Tab „Mengen“ mit Vorwoche, Ø 4 Wochen (Planwerte), Summe, Anteil, WES, Ø VK, Umsatz.
- MCP neu: `speiseplan_eintraege.GET`, `speiseplan_eintraege.PUT` (nur Entwürfe, E6), `speiseplan.WOCHE_KOPIEREN` (confirm), `speiseplan_mengen.GET`, `speiseplan_mengen.PUT`. Reife: `eintrag_ohne_linie` zeigt auf `speiseplan_eintraege.PUT`.
Welle C im Detail:
- Paket 4: `wochenBedarf()` baut dieselben Ziele wie die Produktion (`produktionsZiele()`, aus `wocheAnProduktion` herausgezogen) und ruft `PlanungsblattService::einkaufsliste()` — keine zweite Rechnung. Tab „Bedarf“ (Woche oder Tag, erst auf Knopfdruck), MCP `speiseplan_bedarf.GET`. Übergabe an den Einkauf über die Produktion (E7).
- Paket 6: Dokument-Route mit `?format=tag|schild|liste|csv` (+ `tag`, `linie`, `preise`, `pdf`), Vorlage `dokumente/speiseplan-format.blade.php`; Wochenaushang zeigt optional Preise. Editor-Tab heißt jetzt „Ausgabe & Aushang“ mit „Druck & Export“.
- E9: Option „immer die laufende Woche“ (Editor + MCP-PUBLISH `laufende_woche`); `foodalchemist:speiseplan-aushang-rollieren` friert Montags 00:20 neu ein (Standard-Link; Betriebs-Links bleiben fest).
- Englisch: weiterhin zurückgestellt.
Welle D im Detail:
- Paket 7: Migration `2026_09_28_000002` (`is_template`, `source_plan_id`, `source_synced_at`, `source_line_id`). `setzeVorlage`, `betriebsKopieAnlegen` (nur eigene Betriebe, je Betrieb einmal), `vorlagenAbgleich` (je Zelle ab heute, `vorlage_geaendert` über Zeitstempel seit dem letzten Abgleich, sonst `lokal_abweichend`; neue Linien), `ausVorlageUebernehmen` (alle Vorlage-Änderungen oder gewählte Zellen, Pax des Betriebs bleiben). Stammdaten-Abschnitt „Vorlage für Betriebe“, MCP `speiseplan_vorlage.PUT` (setzen/kopie/liste) und `speiseplan_vorlage.ABGLEICH` (anzeigen/übernehmen mit confirm).
- Paket 8: `planIst()` je Gericht gegen `foodalchemist_sales_facts` (strikt eigenes Team, alle Verkaufsstellen, Mo–So), Tab „Plan/Ist“, MCP `speiseplan_planist.GET`. Concepts/Pakete als „nicht vergleichbar“ ausgewiesen.
- Paket 10.2: `speiseplanZellLeitplanken()` hängt je Zelle Leitplanken aus dem Planzustand an den Brief (nicht wiederholen in ±max(6, Mindestabstand) Tagen, genau eine Zelle je Tag ohne veganes Gericht fragt nach vegan — bevorzugt die vegetarische Linie, Linienpreis und Wareneinsatz-Ziel samt EK-Obergrenze). Heuristisch: parallele Zellen eines Laufs sehen einander nicht; verbleibende Wiederholungen zeigt die Rail.
- Nutzer-Doku `docs/speiseplan.md` neu geschrieben.

Offen nach Spec 57: Englische Namen (eigene Spec), Kassenanbindung/Betrieb im Verkaufsjournal, Betriebs-Links mit „laufender Woche“, Tierart Geflügel/Lamm als Datenfeld.

### Merge und Deploy (2026-10-05)

- Alle vier Wellen samt Spec sind über `main` gemergt (Merge-Commits #159–#163, Spitze `9157a922`). Auf GitHub sind #159 und #163 als gemergt markiert; #160–#162 stehen noch offen, ihr Inhalt ist aber vollständig in `main` und kann geschlossen werden.
- demo läuft seit 2026-09-30 per FA-Pin auf `a98c9ba5` (demo-Commit `ff025fb`) — inhaltlich identisch mit `main`. Der nächste reguläre `update.sh` zieht demo auf `main`.
- Migrationen `2026_09_28_000001`/`000002` auf demo gelaufen; lesender MCP-Smoke ok (Einträge, Mengen, Bedarf, Plan/Ist).
- **Offen:** Pest-Suite (nie gelaufen, keine Sandbox auf dem Bau-Rechner), Abnahme durch Dominique, Datenbefunde demo (Plan 1: „Baguette | Ciabatta“ ~191,90 € VK/Portion, Eintrag 10 auf gelöschtes Concept).
