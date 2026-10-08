# Spec 79 · Trendradar nach Sarah Spork — Inspiration · Hype · Trend

Stand 2026-10-08 · Branch `feat/trendradar-v2` · Quelle: Sarah Spork, *BHG Trend Radar* (Projektarbeit 2026, Click Dummy 3)

## Warum

Der alte Trendradar las Wissens-Dossiers aus dem Vault (`07.03_Trend_Scouting`) und ließ sie von der KI clustern.
Die Dossiers waren inhaltlich wertlos, das Clustering „schön, aber nicht zielgerichtet" (Dominique). Spec 79 ersetzt
das durch Sarahs Modell: Trends werden von Menschen gesichtet, mit Belegen abgelegt und nach festen Kriterien eingeordnet.

## Drei Stufen

1. **Inspiration (Fundstück):** etwas Gesehenes — Instagram-Post, Foto im Restaurant, Link. Noch kein Trend, keine
   Einordnung. Liegt in der **Team-Pinnwand** (Reiter „Inspiration“), ablegen darf jedes Teammitglied, Kind-Teams sehen
   die Pinnwand der Eltern. Schlagworte bündeln Fundstücke; ab 2 offenen zum selben Schlagwort zeigt die Pinnwand eine
   **Häufung** („vielleicht entsteht hier ein Hype oder Trend“).
2. **Hype / Trend:** wer kuratiert, macht aus Fundstücken einen Trend („Trend daraus machen“) oder hängt sie an einen
   bestehenden („An Trend hängen“) — dann sind sie Belege und zählen in die Konfidenz.
3. **Radar:** eingeordnete Hypes und Trends mit bestätigendem Beleg.

Technisch ist ein Fundstück ein Beleg mit `fundstueck = true`; `trend_id = NULL` heißt offen in der Pinnwand.

## Modell

| Feld | Werte | Herkunft bei Sarah |
|---|---|---|
| Typ | trend · hype | Kap. 2.1 (Tiefe/Bedürfnis/Dauer vs. Oberfläche/medial/kurzlebig) |
| Ebene | mode · konsum · mega · meta | Kap. 2.2, Trendhierarchie nach Imbeck |
| Kategorie (was) | food · getraenke · deko („Ambiente & Deko“) · format („Service & Format“, bis 08.10. „event“) | Kap. 4–5 |
| Sparten (für wen) | event_bankett · betriebsgastronomie · care · bildung · restaurant_hotel · delivery — Mehrfachauswahl, leer = alle | Nachtrag Dominique 08.10. (Sarah ist Event-Sicht) |
| Food-Cluster | 4 Imbeck-Cluster | Kap. 4.1 |
| Sicht | veranstalter · teilnehmer · beide (nur bei Kategorie format) | Kap. 5.3 |
| Gartner-Phase | 5 Phasen | Kap. 2.2 (nur Technik) |
| Status | gesichtet → geprüft → auf dem Radar → in Umsetzung · archiviert · verworfen | Briefing Dominique |

Tabellen: `foodalchemist_trends`, `foodalchemist_trend_belege` (Quelle, Link, Notiz, Fundort, Datum, Anteil, Datei über
Core-ContextFile), `foodalchemist_trend_signale` (Messreihen, Kosten). Vokabular: `src/Support/TrendVokabular.php`.

## Regeln

**Konfidenz (Kap. 3.3), berechnet aus den Belegen** — `TrendService::bewertung()`:
- hoch: Marktforschung, Kaufverhalten oder Befragung **und** eine weitere Quelle
- mittel: eine davon allein, Literatur, oder zwei verschiedene sonstige Quellen
- niedrig: sonst — Instagram, Social Media und Google Trends bestätigen nie allein

`konfidenz_manuell` übersteuert sichtbar („von Hand gesetzt").

**Radar-Regel** — `TrendService::radarHindernis()`: auf dem Radar nur mit Typ, Ebene, Kategorie und mindestens einem
Beleg, der kein reines Social-/Suchsignal ist. Die Ablehnung nennt, was fehlt.

**Rechte**: Fundstücke ablegen und Belege anhängen darf jedes Teammitglied. Trend anlegen, Fundstücke zuordnen,
Einordnen, Status, Löschen, Messen: Kuratieren. Eigene Fundstücke/Belege darf jeder wieder löschen. Schreiben nur im eigenen Team, Kind-Teams sehen die Trends der Eltern.

## Oberfläche `/trendradar`

Radar wie Click Dummy 3: Ringe = Ebene (innen Mode), links Food, rechts Getränke/Deko/Veranstaltungen, gefüllt = Trend,
gestrichelt = Hype, Goldring = Befragung bestätigt, Deckkraft = Konfidenz. Lage im Ring per stabilem Hash (ohne
Bedeutung). Liste mit Status-Filter als Prüf-Queue. Detail mit Belegen (Bild-Vorschau), Einordnen, Status, Beleg
anhängen (Datei), Google-Trends-Kurve. Hauptknopf „Fundstück ablegen“ (Screenshot, Titel, Quelle, Link, Fundort, Notiz,
Schlagworte); „Trend anlegen“ nur für Kuratieren. Reiter „Inspiration“: Pinnwand als Bildkacheln, Filter offen /
zugeordnet / alle, Häufungen.

## MCP

`foodalchemist.fundstuecke.GET|POST|PUT` (Pinnwand, Häufungen, Ablegen mit Datei, Zuordnen / Trend daraus machen / Lösen),
`foodalchemist.trends.GET|POST|PUT|DELETE`, `foodalchemist.trend_belege.POST|DELETE` (Datei als base64 oder URL),
`foodalchemist.trends.MESSEN` (kostenpflichtig, confirm). Befragungsergebnisse aus Office/Hatch (Vorlage
„Trend-Radar BHG – Interview") kommen per MCP als Beleg `quelle=befragung` mit `anteil`.

## Planung aus dem Trendradar

Leitstelle → „Neu erstellen“ → **Aus dem Trendradar (Trend, Hype, Inspiration)** öffnet eine Planung auf dem neuen
Reiter **Trendradar** (neben Composer). Dort Trends, Hypes und Fundstücke frei **kombinieren** (max. 8). Rechts entsteht
das Briefing deterministisch (`PlanningSessionService::trendKombination`, keine KI):

```
Aus diesen Impulsen aus dem Trendradar ein Konzept/Gericht/Basisrezept entwickeln.
Trend (Megatrend, Food): Vegane Ernährung — …
Hype (Mode, Food): Dubai-Schokolade — …
Inspiration: Pistazien-Kataifi-Tarte — … (gesehen: …)
Die Impulse verbinden, nicht nebeneinanderstellen.
```

„Als Gericht / Concept / Basisrezept“ schreibt es in den Reiter (Lead per `briefFuerScope` je Ebene, andere Ebenen
nur wenn leer) und wechselt dorthin — weiter wie immer mit Leitplanken und Go (normaler Kaskadenweg). Herkunft:
`planning_sessions.source_trend_refs` {trend_ids, fundstueck_ids}; die erste Analyse-Zeile ist der Anzeigename.
Vom Trendradar aus: „In Planung öffnen“ am Trend (Detail-Menü) und am Fundstück (Pinnwand). MCP:
`planung_session.POST` mit `trend_ids` / `fundstueck_ids`.

Ursprung in der Concept-Erfindung: `IdeenService::ursprungsTrendBlock` baut aus `source_trend_refs` der Planung
denselben Kombinations-Text als Block „URSPRUNG AUS DEM TRENDRADAR“ (über `planning_session_id` durch die Kaskade).
Artefakte tragen `created_via=plan_go`; die Trend-Herkunft steht an der Session.

## Google Trends über DataForSEO

`Services/Trends/DataForSeoGoogleTrends` nutzt `DataForSeoApiService::getGoogleTrendsExplore` des Integrations-Moduls
(keine Änderung dort). Je Suchbegriff eine Live-Abfrage (~0,009 $), höchstens 3 Begriffe je Trend. Monatsbudget je
Team (Standard 5 $), wöchentlicher Lauf Di 06:10 nur bei `trend_dataforseo_enabled`. Ergebnis: Kurve, Richtung
(±10 Punkte letztes gegen erstes Viertel), „Spitze ohne Sockel" als Hype-Indiz, Beleg „Google Trends" (weich).

## Nachtrag 08.10. · Zwei Achsen

Sarahs Arbeit ist aus Event-Sicht geschrieben; die BHG-Teams arbeiten in verschiedenen Sparten. Deshalb: **Kategorie = was** (eine Auswahl, trägt die Radar-Sektoren; „event" → „format", weil Service- und Veranstaltungsformate nicht nur Events betreffen) und **Sparten = für wen** (Mehrfachauswahl, Filter links; ein Trend ohne Sparte gilt für alle). Migration `2026_10_10_100300` (Spalte `sparten`, event→format). Trends spielt Dominique später per MCP ein (`trends.POST` mit `sparten`, `suchbegriffe` für die Messung).

## Startbestand

`php artisan foodalchemist:trends-startbestand --team=<ID>` übernimmt Sarahs 27 Trends (4-Tage-Woche entfernt, 08.10.)
(`database/data/trends_startbestand_sarah_spork.json`) mit Belegen. Bubble Tea und Kurkuma-Tonic bleiben „geprüft"
(nur Google/Instagram). Sarahs eigene Konfidenzwerte werden nicht übernommen — die Regel rechnet aus den Belegen.

## Rückbau Alt-Pfad (2026-10-08)

Entfernt: `TrendRadarService`, `TrendClusterCommand`, `TrendKonzepteCommand` (08:00-Automatik + Settings-Schalter
`trend_auto_*`/`trend_signal_enabled`), `TrendRefreshJob`, MCP `trendradar.IMPORT`, Prompt `trend.cluster_label`,
Vault-Import/-Export `07.03_Trend_Scouting`, `KnowledgeContextService::trendBlock` + Routing `trend`, `ausTrend` (+ Brief-
Helfer), Ursprung über `source_knowledge_document_id`. Migration `2026_10_10_100200_retire_legacy_trend_knowledge`:
Routing-Zeilen `trend` gelöscht, Dossiers `category=trend` deaktiviert (nicht gelöscht), `trend_meta`/`trend_taxonomy`
gedroppt. Bleiben im Schema (nicht mehr befüllt): `source_knowledge_document_id` (Sessions/Rezepte/Konzepte),
`trend_auto_*`-Spalten; Signaltyp `trend_konzept_vorschlag` bleibt lesbar für Altbestand.

## Offen (Folgeschritte)

- KI-Einordnungs-Vorschlag beim Erfassen (Core-LLM-Contract), nur einsortieren.
- Instagram-Hashtag-Beobachtung (Meta-Freigabe fehlt), Marktforschungs-PDF-Upload mit Extraktion, Kaufverhalten aus FA-Daten.
