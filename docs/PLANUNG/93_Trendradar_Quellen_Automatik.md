# Spec 93 · Trendradar: Quellen, Eingang und automatische Einordnung

Stand 2026-10-30 · Auftraggeber Dominique · baut auf [Spec 79](79_Trendradar_nach_Sarah_Spork.md) auf · Kurator prüft und merged

## Warum

Heute wird der Trendradar von Hand bestückt: Fundstücke ablegen, Trend anlegen, einordnen, Belege anhängen. Das kostet
Dominique „einen halben Tag“, bevor überhaupt etwas auf dem Radar steht. Ziel (Dominique 30.10.): **Das System bestückt
den Radar selbst.** Es holt Artikel aus hinterlegten Quellen, bündelt sie zu Themen, erkennt Häufungen, ordnet nach
Sarah Sporks Kriterien ein (Hype oder Trend, Ebene, Kategorie, Sparten), legt Trends an, hängt Belege an und setzt sie
aufs Radar, wenn die Radar-Regel erfüllt ist. Der Mensch korrigiert, statt alles selbst zu tun.

Die Pinnwand bleibt, was sie ist: Inspiration (Bilder, Instagram, Gesehenes). Systematisch erfasste Fachpresse läuft
über einen eigenen **Eingang** und landet nicht als Kachel in der Pinnwand.

## Abgrenzung Pinnwand ↔ Eingang

| | Pinnwand (bleibt wie Spec 79) | Eingang (neu) |
|---|---|---|
| Wofür | Inspiration: Gesehenes, Bilder, Instagram, Foto im Restaurant | systematische Erfassung: Fachpresse, Branchenquellen, Feeds |
| Wer legt ab | ein Mensch (Laptop oder Handy, „Fundstück ablegen“) | das System (Abruf aus den Quellen) oder der MCP-Auftrag |
| Ansicht | Bildkacheln | Liste wie eine Prüfliste |
| Automatik | befüllt die Pinnwand **nie** und ändert keine Pinnwand-Karte | arbeitet alles automatisch ab |

Drei Regeln, mehr nicht:
1. **Nichts Automatisches landet in der Pinnwand.** Ein Artikel aus dem Eingang kommt nur per Klick „Auf die Pinnwand“
   dorthin (z. B. ein bildstarker Artikel, den man als Inspiration behalten will).
2. **Was ein Mensch in die Pinnwand legt, zählt mit.** Ein Pinnwand-Fundstück ist eine Sichtung: Es geht in die
   Kennzahlen des passenden Themas ein (Häufung, Monate, Quellenarten), die Karte selbst bleibt unverändert.
3. **Zuordnen von Pinnwand-Karten zu Trends bleibt Handarbeit.** Passt eine Pinnwand-Karte zu einem automatisch
   erkannten Trend, meldet das System das als Vorschlag (Signal), es hängt sie nicht selbst um.

Technisch nutzen automatische Themen dieselbe Tabelle wie die Inspirationen (Spalte `herkunft`); die Pinnwand zeigt
nur `herkunft = manuell`, die automatischen Themen erscheinen im Reiter „Eingang“.

## Grundsätze

1. **Automatik ist sichtbar und umkehrbar.** Alles, was das System einordnet oder anlegt, trägt
   `einordnung_quelle = ki` (gibt es schon) bzw. `herkunft = automatisch` und eine Begründung in Klartext. Jede
   Entscheidung lässt sich mit einem Klick korrigieren; eine manuelle Korrektur überschreibt die Automatik dauerhaft
   (das System fasst manuell gesetzte Felder nie wieder an).
2. **Regeln sind Daten, keine Prompt-Prosa.** Schwellen stehen als einstellbare Werte je Team (Muster Spec 81).
   Die KI liefert Merkmale (Zusammenfassung, Thema, Tiefe/Oberfläche, Kategorie), die **Entscheidung** Hype/Trend/Ebene/
   Radar trifft eine deterministische Regel über diese Merkmale und die Zählwerte. So ist jede Einordnung
   nachvollziehbar und testbar.
3. **Die Radar-Regel aus Spec 79 bleibt unverändert** (`TrendService::radarHindernis`): Typ, Ebene, Kategorie und
   mindestens ein Beleg, der kein reines Social-/Suchsignal ist. Fachpresse, Branchenquelle und Literatur erfüllen das
   bereits; Marktforschung ist **nicht** nötig. Die Konfidenz-Regel (`bewertung()`) bleibt ebenfalls unverändert:
   ohne harte Quelle (Marktforschung, Kaufverhalten, Befragung) bleibt die Konfidenz höchstens „mittel“ — das ist
   ehrlich und auf dem Radar über die Deckkraft sichtbar.
4. **KI läuft dual** (Spec 90 / #1002): jeder KI-Schritt ist ein `propose()` über das Gateway in einem
   `KiExternWiederholbar`-Job bzw. einem Tool in `KiUmleitung::TOOLS`. Kunden-Teams nutzen die Plattform-KI (Kosten je
   Team, Budget), Dominique kann denselben Lauf per MCP über Claude fahren.
5. **Rechtlich:** gespeichert werden nur Titel, Link, Quelle, Datum und eine **eigene** Kurz-Zusammenfassung — nie der
   Volltext, nie fremde Bilder (bei Instagram nur der Link).
6. **Team-Sicht:** Quellen, Eingang, Themen und automatisch angelegte Trends gehören dem Team, das den Lauf fährt
   (Schreiben im eigenen Team, Lesen wie Spec 79: Kind-Teams sehen die Eltern).

## Ablauf

```
Quellen (je Team) ──► Abruf ──► Eingang (1 Zeile je Artikel, Dublettenschutz über URL)
                                   │  KI: erschließen (Zusammenfassung, Thema, Kategorie, Sparten, Tiefe)
                                   ▼
                              Thema (Bündel gleicher Artikel = Inspiration mit herkunft=automatisch)
                                   │  Zählwerte: Sichtungen, Quellen, Quellenarten, Monate, Verlauf
                                   ▼
                              Regel-Einordnung ──► Trend/Hype anlegen oder aktualisieren, Belege anhängen,
                                   │               Status setzen (bis auf_radar je Automatik-Stufe)
                                   ▼
                              Signale + Ableitung (was heißt das für die Caterer, Ideen → Planung)
```

### 1. Quellen

Neue Tabelle `foodalchemist_trend_quellen` (team_id, name, url, art ∈ `TrendVokabular::QUELLEN`, feed_typ
`rss|atom|uebersicht|manuell`, feed_url, schwerpunkt_kategorien json, schwerpunkt_sparten json, stichworte_ein json,
stichworte_aus json, aktiv, letzter_abruf_at, letzter_fehler, fehler_in_folge, gefiltert_letzter_abruf,
gefiltert_gesamt, pruef_hinweis, abruf_weg `plattform|mcp`, rhythmus, uuid, softDeletes). „uebersicht“ ersetzt das
frühere „seite“ (Kurator 30.10.): einzelne Seiten oder Artikel reicht man über `trend_eingang.POST` ein. UI: Reiter „Quellen“ im Trendradar (Kuratieren). MCP:
`foodalchemist.trend_quellen.GET|POST|PUT|DELETE`.

**Rhythmus je Quelle** (Dominique 30.10.): Spalte `rhythmus ∈ taeglich|woechentlich|monatlich|aus` je Quelle, in der
Quellen-Liste einstellbar (Standard aus der Team-Einstellung, die selbst Standard `aus` hat, bis Dominique einschaltet).
Gilt für beide Abruf-Wege: bei `plattform` steuert er den Abruf, bei `mcp` die Fälligkeit in `trend_quellen.GET` und das
Signal „überfällig“.

**Abruf:** Command/Job `foodalchemist:trend-quellen-abrufen {--team}` (Scheduler täglich, holt nur fällige
`plattform`-Quellen nach ihrem Rhythmus; Scheduler-Schalter `foodalchemist.scheduler.trend_quellen_enabled`, Standard
**aus** — kein Lauf allein durch den Deploy). Holt RSS/Atom-Einträge (Titel, Link, Datum, Teaser) bzw. bei
`uebersicht` die Artikel-Links der Übersichtsseite. Höchstens **20 neue Einträge je Quelle und Abruf** (Config
`trends.quellen.max_neue_je_abruf`, *Startwert, nicht belegt*; Dubletten und Gefiltertes zählen nicht mit).
**Sicherheit:** über `Support\SichererAbruf` (#671): nur http/https, keine privaten/lokalen Adressen (SSRF-Schutz: DNS auflösen,
private/Loopback/Link-Local-Bereiche sperren, keine Weiterleitung dorthin), Timeout, Größengrenze, eigener
User-Agent, Fehler je Quelle in `letzter_fehler` (+ `fehler_in_folge`) statt Abbruch. Kein KI-Aufruf im Abruf.
**Bot-Sperre** (401/403/429) wird nie umgangen (kein User-Agent-Spoofing): Fehler an der Quelle „Abruf gesperrt
(HTTP …) – auf MCP umstellen“.

**Übersichtsseite (`uebersicht`, Kurator 30.10.)** — deterministisch, ohne KI (`Services\Trends\FeedLeser`):
Kandidaten sind `<a>` in oder um eine Überschrift (h1–h4) sowie der erste Titel-Link in einem `article`-/Teaser-
Container. Aussortiert: `nav`, `header`, `footer`, `aside`, fremde Domains (www. egal), die Übersicht selbst,
Kategorie-/Schlagwort-/Tag-/Autor-/Blätter-Links (Pfadteile wie `schlagwort`, `tag`, `kategorie`, `page/2`),
Linktexte unter 12 Zeichen („Mehr“). Je Treffer Titel, Link, Datum (`<time>`) und erster Absatz als Teaser.
*Abweichung von „Pfad tiefer als die Übersicht“:* Artikel liegen oft nicht unter dem Rubrik-Pfad
(`/schlagwort/food-trends/` → `/artikel-slug/`). Darum gilt „gleiche Domain, nicht die Übersicht, kein
Rubrik-/Blätter-Pfad“ statt „Pfad beginnt mit dem Übersichtspfad“. **Ehrlichkeit:** 0 Treffer = Fehler „keine Artikel
erkannt – auf MCP umstellen“, `fehler_in_folge` zählt hoch — kein stiller Leerlauf.

**Automatische Wahl beim Anlegen** (Dominique 30.10., UI und `trend_quellen.POST`, wenn weder `feed_typ` noch
`abruf_weg` mitgegeben ist; alles über SichererAbruf, ohne KI):
1. Die URL ist ein Feed, oder die Seite nennt ihn per `<link rel="alternate" type="application/rss+xml|atom+xml">`,
   oder einer der üblichen Pfade (`<url>/feed/`, `/feed/`, `/rss`, `/rss.xml`) liefert ihn → `rss`/`atom`, `plattform`.
2. Kein Feed → Probelauf der Übersicht; ab **3 Artikeln** (Config `trends.quellen.uebersicht_mindest_treffer`,
   *Startwert, nicht belegt*) → `uebersicht`, `plattform`.
3. Sonst (0–2 Treffer, Bot-Sperre, Fehler) → `mcp`, `manuell`, Hinweis „kein Feed, Seite nicht lesbar – wird per MCP
   gesichtet“.
Das Ergebnis steht in der Antwort (`pruefung`: erkannt, gewählt, Grund) und an der Quelle (`pruef_hinweis`).
„Quelle neu prüfen“ (UI, `trend_quellen.PUT pruefen=true`) wählt neu, z. B. wenn eine Übersicht kaputtgeht.
Für Seiten ohne Feed ist MCP (Claude) der KI-Weg; im Abruf läuft keine Plattform-KI.

**Stichwort-Filter vor dem Eingang** (Dominique 30.10., gegen „Riesenrauschen“), deterministisch:
- Je Quelle `stichworte_ein` / `stichworte_aus`; je Liste greift der Team-Standard (Trendradar-Einstellungen), wenn die
  Quelle keine eigene hat.
- Durch kommt ein Artikel, wenn Titel + Teaser mindestens **ein** Ein-Stichwort enthalten (leere Liste = alles) und
  **kein** Aus-Stichwort. Ohne Groß-/Kleinschreibung, Umlaute normalisiert (ä = ae, ß = ss), Wortanfang genügt
  („vegan“ trifft „veganer“), Phrasen in Anführungszeichen exakt als ganze Wortfolge.
- Kein Treffer → **keine** Eingang-Zeile, nur Zähler an der Quelle (`gefiltert_letzter_abruf`, `gefiltert_gesamt`).
  Gilt für den Plattform-Abruf und `trend_eingang.POST`; die MCP-Antwort meldet je Artikel „gefiltert: Stichwort“. Bei
  MCP zählt `gefiltert_letzter_abruf` je Kalendertag.
- Die Listen bleiben leer, bis Dominique sie füllt. Beispiel (keine Vorgabe): ein = Trend, Food, Catering,
  Gastronomie, Getränk, Konzept; aus = Personalie, Insolvenz, Stellenanzeige.
- UI: Stichworte je Quelle editierbar, Probelauf „Was käme durch?“ (holt Feed bzw. Übersicht, zeigt durchgelassen vs.
  gefiltert, speichert nichts; MCP `trend_quellen.PUT probelauf=true`). Die KI-Relevanzprüfung in B bleibt als
  zweiter Filter danach.

**Abruf-Weg je Quelle** (Dominique 30.10.): Spalte `abruf_weg ∈ plattform|mcp` (Standard `plattform`).
- `plattform`: der Abruf-Command holt die Quelle (Feed/Seite, ohne KI).
- `mcp`: die Plattform ruft die Quelle **nie** selbst ab. Ein MCP-Client (Dominiques geplante Aufgabe in Claude) holt
  sich mit `trend_quellen.GET` (Filter `abruf_weg=mcp`, fällige zuerst) die Liste, sichtet und sucht dort selbst
  (z. B. Instagram-Accounts, Seiten ohne Feed, freie Recherche) und reicht über `trend_eingang.POST` mit `quelle_id`
  ein. Das setzt `letzter_abruf_at` der Quelle. Ist eine MCP-Quelle länger als ihr Rhythmus nicht besucht worden,
  meldet ein Signal „MCP-Quelle überfällig“.
Die **Einordnung** danach (Erschließen, Bündeln, Regeln, Ableitung) läuft für alle Einträge gleich über den
Plattform-Weg (KI dual wie üblich: plattform-angestoßen = Plattform-KI).

### 2. Eingang

Neue Tabelle `foodalchemist_trend_eingang` (team_id, quelle_id nullable, url, url_hash, titel, veroeffentlicht_am,
teaser (gekürzt), zusammenfassung (eigene, KI), thema (normalisierte Schreibweise), kategorie, sparten json,
tiefe ∈ `beduerfnis|oberflaeche|unklar`, relevant bool, begruendung, inspiration_id nullable, status
`neu|erschlossen|gebuendelt|verworfen|fehler`, eingereicht_von ∈ `abruf|mcp|hand`, uuid, timestamps).
**Dublettenschutz:** unique (team-Familie, url_hash) — normalisierte URL (Schema, Host klein, ohne Tracking-Parameter
`utm_*`, `fbclid`, `igsh` u. ä., ohne abschließenden Slash). Gleiche URL = keine neue Zeile, nur `zuletzt_gesehen_at`.
Umsetzung (Kurator 30.10.): DB-Riegel `unique(team_id, url_hash)`, die Familienprüfung (`TrendService::fundstueckFamilie`)
macht der Service. Treffer im eigenen Team → `zuletzt_gesehen_at`; Treffer in einem anderen Team der Familie → nur
Meldung „Dublette (Team X)“, keine Zeile, kein Schreiben in das fremde Team.

UI: Reiter „Eingang“ als Liste (Prüfliste-Muster): Titel, Quelle, Datum, Zusammenfassung, Thema, Einordnung,
Status; Filter offen/verworfen/alle; Aktionen Verwerfen / Wiederherstellen / Thema ändern / als Inspiration auf die
Pinnwand. MCP: `foodalchemist.trend_eingang.GET|POST` (einzeln oder bis 50 je Aufruf) und `.ENTSCHEIDEN`.

### 3. Erschließen (KI, je Artikel)

Job `TrendEingangErschliessenJob` (KiExternWiederholbar), Prompt `trend.eingang_erschliessen` (Klasse leicht, ein Aufruf je
Artikel), Eingabe Titel + Teaser + Quelle + vorhandene Themen- und Trendnamen des Teams (sortiert, stabiler
Fingerabdruck). **Auslöser (Kurator 30.10.):** jeder neue Eingang-Eintrag automatisch — Plattform-Abruf → Plattform-KI,
MCP-Einreichung → KI-Auftrag; dazu `trend_eingang.ERSCHLIESSEN` (ids oder alle offenen, höchstens 50 je Aufruf) und
der Knopf „Erschließen“ im Reiter (Plattform-KI). Die Grenze N = 20 je Quelle und Abruf gilt damit auch fürs
Erschließen. Kosten bis C über den allgemeinen KI-Deckel des Teams; das eigene Trend-KI-Budget kommt mit C.
Ungültige Antwort → Status `fehler` mit Begründung (nicht still). Ausgabe (JSON, validiert): relevant (für Food & Beverage /
Catering / Gastronomie), zusammenfassung (≤ 400 Zeichen, eigene Worte), thema (kurz, Singular, deutsch, an
vorhandene Themen angleichen, wenn es dasselbe ist), kategorie, sparten, tiefe + Begründung, passt_zu_trend_id
(nur aus der mitgegebenen Liste, sonst null). Nicht relevant → `verworfen` mit Begründung.

### 4. Bündeln zu Themen

Ein Thema ist eine **Inspiration** (Tabelle gibt es seit Spec 79 Nachtrag 09.10.) mit neuer Spalte
`herkunft ∈ manuell|automatisch` (Standard manuell, Bestand bleibt manuell) und `thema_schluessel` (normalisierte
Schreibweise: klein, Umlaute ausgeschrieben, Satzzeichen weg). Zuordnung eines erschlossenen Artikels:
1. gleiche normalisierte `thema`-Schreibweise im Team → dieselbe Inspiration (der Prompt bekommt die vorhandenen
   Themennamen und übernimmt deren Schreibweise, wenn es dasselbe ist);
2. sonst Ähnlichkeit über die vorhandene Vektorsuche gegen Themen der letzten 12 Monate, ab Schwelle
   `buendel_aehnlichkeit` → dieselbe Inspiration. **Stand B: aus** (`trends.quellen.buendel_aehnlichkeit = null`).
   **Wert wird auf demo gemessen, dann gesetzt** — mit `foodalchemist:trend-themen-aehnlichkeit --team=<ID>`
   (zeigt die ähnlichsten Themenpaare eines Teams mit Score, nur dieses Team, schreibt nichts, auch nicht in den
   Vektor-Index);
3. sonst neue Inspiration (herkunft automatisch).
Der Eingang merkt sich das Thema in `thema_inspiration_id`; `inspiration_id` bleibt die Pinnwand-Inspiration aus
„Auf die Pinnwand“ (beides kann gleichzeitig gelten). „Thema ändern“ setzt `thema_quelle = manuell` (die KI fasst es nie
wieder an) und hängt einen gebündelten Artikel ins neue Thema um; leere automatische Themen verschwinden. „Verwerfen“
nimmt den Artikel aus seinem Thema. Manuelle Inspirationen werden nie gebündelt, ihr Schlüssel ist aber berechenbar
(`TrendThemenService::schluessel`), damit C Pinnwand-Fundstücke als Sichtung zählen kann (Regel 2).
Der Artikel wird zusätzlich als Beleg (`fundstueck = false`, quelle = art der Quelle) an die Inspiration gehängt.
Die Pinnwand zeigt nur `herkunft = manuell`; die automatischen Themen erscheinen im Reiter „Eingang“ (siehe Abgrenzung).

### 5. Einordnung nach Regeln

Je Thema rechnet `TrendAutomatikService::kennzahlen()`: Sichtungen gesamt / letzte 30 Tage, Anzahl verschiedener
Quellen, Quellenarten, Kalendermonate mit Sichtung in den letzten 12 Monaten, Anteil sozialer Quellen, Anteil
`tiefe = beduerfnis`, Sparten-Breite, Kategorie (Mehrheit), Google-Trends-Befund falls gemessen
(„Spitze ohne Sockel“ aus Spec 79).

`TrendAutomatikService::einordnen()` (deterministisch) entscheidet:

| Entscheidung | Regel (Schwellen aus den Einstellungen) |
|---|---|
| Kandidat | ≥ `kandidat_sichtungen` Sichtungen aus ≥ `kandidat_quellen` Quellen innerhalb `kandidat_tage` |
| Hype | Kandidat **und** (≥ `hype_sichtungen_30t` in 30 Tagen **oder** Google-Trends „Spitze ohne Sockel“) **und** Anteil `tiefe=beduerfnis` < `tiefe_schwelle` |
| Trend | Kandidat **und** Sichtungen in ≥ `trend_monate` verschiedenen Monaten **und** ≥ `trend_quellenarten` Quellenarten, davon ≥ 1 aus presse/branchenquelle/literatur **und** Anteil `tiefe=beduerfnis` ≥ `tiefe_schwelle` |
| Hype → Trend | ein automatischer Hype erfüllt später die Trend-Regel → Typ wechselt, Signal |
| Ebene | `mode` Standard; `konsum` ab ≥ `konsum_sparten` Sparten oder ≥ `konsum_monate` Monaten; `mega`/`meta` setzt die Automatik **nie** selbst (Megatrends sind langlebige Großbewegungen) — sie hängt das Thema als Beleg an einen vorhandenen Mega-/Metatrend, wenn die KI `passt_zu_trend_id` geliefert hat |
| Abklingen | keine Sichtung seit `abklingen_monate` → Signal „abgeklungen“, Vorschlag archivieren (nie automatisch löschen) |

Folge je Entscheidung:
- Kandidat ohne passenden Trend → **Trend anlegen** (typ, ebene, kategorie, sparten, einordnung_quelle=ki,
  Begründung), Inspiration zuordnen (alle Artikel werden Belege), `neuBewerten()`.
- Kandidat mit `passt_zu_trend_id` → an den bestehenden Trend hängen, Typ/Ebene nur ändern, wenn sie nicht manuell
  gesetzt sind.
- **Status je Automatik-Stufe** (Team-Einstellung `trend_automatik`):
  - `aus` — nur Eingang und Erschließen, keine Trends;
  - `bis_geprueft` — Trends bis Status `geprueft`;
  - `radar` (Dominiques Wunsch, Standard für das Master-Team) — erfüllt der Trend `radarHindernis() === null`,
    setzt die Automatik `auf_radar`, sonst `geprueft` mit dem Hindernis als Begründung.
- Felder, die ein Mensch gesetzt hat (Typ, Ebene, Kategorie, Status, Konfidenz manuell), fasst die Automatik nie an
  (Merker je Feld oder `einordnung_quelle = manuell` + Status-Historie).

**Schatten-Modus:** Einstellung `trend_automatik_schatten` (Standard **an** für die ersten Läufe): die Automatik
rechnet alles und zeigt in einer Ansicht „Was die Automatik tun würde“ (Thema, Kennzahlen, Entscheidung, Begründung),
schreibt aber keine Trends. Dominique kalibriert die Schwellen daran und schaltet dann scharf. Die Liste ist
reproduzierbar (gleicher Datenstand = gleiche Liste).

**Stand C (gebaut 30.10., Kurator-Entscheide):**
- **Reihenfolge des Tages:** Quellen-Abruf 05:50 → Erschließen (Queue, je neuem Artikel) → Automatik
  `foodalchemist:trend-automatik` 06:40. Alle drei Scheduler-Schalter ab Werk **aus**; Stufe ab Werk `aus`, Schatten ab Werk an.
  Dazu `trend_automatik.LAUF` und der Knopf „Lauf jetzt“ im Reiter „Automatik“.
- **Kennzahlen:** Sichtungen = Artikel des Themas + Pinnwand-Fundstücke der Teamfamilie, deren Titel oder Schlagwort den
  gleichen Schlüssel hat (Regel 2; die Karte bleibt unverändert). Quellen = Host der URL, sonst Fundort. Quellenarten,
  Monate, Anteil sozial über 12 Monate; Anteil „Bedürfnis“ nur über Artikel mit Tiefe (Pinnwand hat keine).
  „Spitze ohne Sockel“ nur, wenn das Thema schon an einem gemessenen Trend hängt.
- **Kandidat ohne Typ** (weder Hype- noch Trend-Regel): kein Trend, in der Liste „Kandidat, Typ offen“.
- **Schutz:** Bestandstrends (`herkunft = manuell`, z. B. Sarahs 27) bekommen nur Belege und `neuBewerten()`, ihre
  Felder ändert die Automatik nie. Eigene Trends (`herkunft = automatisch`): nur Felder ohne Merker
  (`manuell_felder`, gesetzt von jedem menschlichen Schreibweg), Status nur anheben, nie mega/meta.
- **Trend löschen** lässt die Artikel des Themas bestehen (nur die Zuordnung fällt weg).
- **Trend-KI-Budget** (`trend_ki_budget_eur`, null = kein eigener Deckel): erreicht → Erschließen setzt
  „fehler: Trend-KI-Budget erreicht“ und ruft den Provider nicht; der MCP-Weg zählt nicht.
- **Signale** (über `SignalDetektorService::laufen()`, Bereich „Konzepte“, je Team und Ereignis, selbst schließend):
  neuer Hype / neuer Trend / Hype wird Trend / aufs Radar (schließen, sobald ein Mensch den Trend anfasst), Thema
  abgeklungen, Quelle ab **3** Fehlern in Folge (`quelle_fehler_signal_ab`, *Startwert, nicht belegt*), MCP-Quelle
  fällig, Pinnwand-Karte passt zu einem Trend der Automatik (Vorschlag, nie selbst umhängen), Kandidaten warten (aus
  dem letzten scharfen Lauf, `trend_automatik_letzter_lauf`).

### 6. Signale und Ableitung

Signale (SignalDetektor, eins je Team und Ereignis, schließen sich selbst): „Neuer Hype erkannt“, „Neuer Trend
erkannt“, „Hype wird Trend“, „Auf das Radar gesetzt“, „Thema abgeklungen“, „Quelle liefert seit N Abrufen Fehler“.

Ableitung (KI, Job `TrendAbleitungJob`, Prompt `trend.ableitung`, KiExternWiederholbar): sobald ein automatischer
Trend `geprueft` oder `auf_radar` erreicht, schreibt die KI einen Abschnitt „Was heißt das für uns“ (Bedeutung je
Sparte, 3–5 Gericht-/Konzept-Impulse) an den Trend; „In Planung öffnen“ übernimmt ihn (Spec 79 Planung).

## Einstellungen und Startwerte

Alle Werte je Team in den Trendradar-Einstellungen, per MCP `foodalchemist.trend_automatik.GET|PUT`.

> **Herkunft der Startwerte:** Sarah Sporks Arbeit nennt **keine** Schwellen (Dominique 30.10.). Die folgenden Werte
> sind **Annahmen des Kurators**, keine belegten Zahlen. Sie sind bewusst vorsichtig gewählt und werden im
> Schatten-Modus an echten Läufen kalibriert. Im UI stehen sie mit dem Hinweis „Startwert, nicht belegt“.

| Schlüssel | Startwert |
|---|---|
| `kandidat_sichtungen` / `kandidat_quellen` / `kandidat_tage` | 3 / 2 / 60 |
| `hype_sichtungen_30t` | 5 |
| `trend_monate` / `trend_quellenarten` | 3 / 2 |
| `tiefe_schwelle` | 0,5 |
| `konsum_sparten` / `konsum_monate` | 2 / 6 |
| `abklingen_monate` | 6 |
| `buendel_aehnlichkeit` | im Bau an Testdaten messen und begründen (nicht raten) |
| `max_neue_trends_je_lauf` | 5 (Schutz vor Flut; Rest bleibt Kandidat mit Signal) |
| `quelle_fehler_signal_ab` (Signal „Quelle liefert Fehler“) | 3 |
| `abruf_rhythmus` (Team-Standard für neue Quellen) | aus (bis Dominique einschaltet) |
| `max_neue_je_abruf` (je Quelle und Abruf, alle Feed-Typen) | 20 |
| `uebersicht_mindest_treffer` (Auto-Wahl) | 3 |
| Stichworte ein/aus (Team-Standard) | leer (Beispiel in der UI, keine Vorgabe) |
| KI-Budget je Team und Monat | analog `trend_dataforseo` (Standard klein, einstellbar) |

## Bauabschnitte (je eigener PR)

- **A · Quellen + Eingang:** Migrationen (additiv), Quellen-UI/MCP, Abruf-Command mit SSRF-Schutz, Eingang-UI/MCP,
  Dublettenschutz. Keine KI. *Gebaut 30.10. (Branch `feat/spec93-a-quellen-eingang`):* dazu Abruf-Weg und Rhythmus je
  Quelle, automatische Wahl, Übersichtsseiten, Stichwort-Filter; Migrationen `2026_12_01_100000` (Quellen),
  `…100100` (Eingang), `…100200` (Team-Standards Rhythmus + Stichworte); Test `Spec93AQuellenEingangTest`.
- **B · Erschließen + Bündeln:** Prompt, Job (dual), Inspiration `herkunft`, Bündel-Logik, Pinnwand-Filter. *Gebaut 30.10.
  (Branch `feat/spec93-b-erschliessen-buendeln`):* Migrationen `2026_12_01_100300` (Inspiration herkunft,
  thema_schluessel), `…100400` (Eingang thema_inspiration_id, passt_zu_trend_id, thema_quelle, erschlossen_at); Tool
  `trend_eingang.ERSCHLIESSEN`; Mess-Befehl `trend-themen-aehnlichkeit`; Pflichtliste Spec 90 Zeile 87; Test
  `Spec93BErschliessenBuendelnTest`.
- **C · Einordnung + Automatik:** `TrendAutomatikService`, Einstellungen + MCP, Schatten-Ansicht, Status-Stufen,
  Schutz manueller Felder, Signale. *Gebaut 30.10. (Branch `feat/spec93-c-einordnung-automatik`):* Migrationen
  `2026_12_01_100500` (Trend herkunft, manuell_felder, automatik_ereignisse), `…100600` (Team: Stufe, Schatten, Regeln,
  Budget, letzter Lauf); Tools `trend_automatik.GET|PUT|LAUF`; Reiter „Automatik“; Test `Spec93CEinordnungAutomatikTest`.
- **D · Ableitung:** Prompt + Job (dual), Anzeige am Trend, Übergabe an die Planung.

## Tests (Pflicht)

- Dublettenschutz: gleiche URL mit/ohne utm_/Slash → eine Zeile; anderes Team der Familie → ebenfalls Dublette.
- SSRF: localhost, 127.0.0.1, 10.x, 169.254.x, Weiterleitung auf private Adresse → abgelehnt, Fehler an der Quelle.
- Erschließen: Provider-Fake liefert Merkmale → Eingang `erschlossen`; nicht relevant → `verworfen`; MCP-Weg:
  Auftrag statt Provider-Aufruf (werfender Plattform-Provider, KiExtern-Muster).
- Bündeln: gleiche Thema-Schreibweise → gleiche Inspiration; anderes Thema → neue.
- Einordnung je Regelzeile mit Grenzfällen (genau auf der Schwelle / eins darunter); Ebene mega/meta nie automatisch;
  manuell gesetzte Felder bleiben nach erneutem Lauf unverändert; Stufe `bis_geprueft` setzt nie `auf_radar`;
  Stufe `radar` nur bei `radarHindernis() === null`; Schatten-Modus schreibt keine Trends;
  `max_neue_trends_je_lauf` greift.
- Signale über `SignalDetektorService::laufen()` (nicht die Methode direkt), schließen sich selbst.
- Team-Sicht: Team B sieht Quellen, Eingang, Themen, Trends von A nicht.
- Wächter fest im Kreis: alle Spec84 Waechter/Fehlertypen/TeamSicht, Spec90 (neue Prompts in der Pflichtliste),
  KiUmleitung, McpTools, Spec84ToolListe, Policy, Spec77b, UiRegeln, Dateigrößen, Blade, EnumStatusVergleich.

## Nicht in dieser Spec

Externe Marktforschungs-Quellen (keine bekannt), Volltext-Speicherung, Bild-Übernahme fremder Inhalte, automatisches
Löschen, „Teilen an Food Alchemist“ (eigenes Paket nach dem Deploy), Handy-Ansicht der neuen Reiter (folgt dem
Handy-Muster aus #1003, nach Abschnitt C).
