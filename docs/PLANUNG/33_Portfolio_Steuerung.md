# Spec 33 — Portfolio-Steuerung (Mehrbetriebs-Sicht)

- **Status:** P0–P8 gebaut, Tenant-Nachweis erbracht; Browser-Abnahme und demo-Deploy offen
- **Stand:** 02.08.2026
- **Bezug:** Business-Case PF-01…PF-06 · baut auf [Spec 32 — Controlling-Zentrum](32_Controlling_Zentrum.md) (Verkaufsjournal C3)
- **Handbuch:** [Portfolio](../portfolio.md) · [Controlling](../controlling.md)

## 1. Auslöser

Food Alchemist hat drei Ausgabeformen — Foodbook (Catering), Speisekarte (Gastronomie),
Speiseplan (GV). Zusammen sind sie das Portfolio eines Betriebs. Was fehlte, war die Antwort auf
die einfachste Frage der Mehrbetriebs-Steuerung: **wer fährt gerade was — je Standort und je
Kunde?**

Der `PhaseService` (kontext → struktur → befüllung → kalkulation → freigabe) bildet den *Bau*-
Fortschritt ab. Nach „freigabe" endete das Modell; einen Zustand „läuft jetzt im Betrieb" gab es
nicht. Die Felder dafür existierten teilweise, wurden gespeichert und waren editierbar — aber
**niemand fragte sie ab**.

Und das Fundament trug nicht. Der Bestand vor P0:

| | Status | Gültigkeit | Zuordnung |
|---|---|---|---|
| Foodbook | `draft`; Kommentar sagte `aktiv`, das UI schrieb `active` | nur `jahr` | CRM-Kunde; Outlet nur je Kapitel |
| Speisekarte | `entwurf·aktiv·veroeffentlicht·archiviert` (Array-Konstante) | `gueltig_von`/`gueltig_bis` ✅ | `outlet_id` ✅ |
| Speiseplan | `draft`; UI kannte `draft·active·archiviert` | `entry_date` je Eintrag | nichts außer `team_id` |

Kein Enum, kein Scope, keine Validierung. „aktiv", „active" und „veroeffentlicht" standen
nebeneinander in derselben Datenbank — eine Übersicht darauf hätte falsch gezählt. Deshalb war
die Vereinheitlichung Etappe 0 und nicht Beiwerk.

**Kein Tenancy-Thema.** Tenancy regelt, *wer was sehen darf*. Hier geht es um *was läuft gerade
wo und für wen* — Lebenszyklus und Zuordnung. (Der Bau hat trotzdem einen Tenant-Fund
produziert, siehe §8.)

## 2. Entscheidungen (Dominique, 02.08.2026)

| # | Entscheidung | Konsequenz |
|---|---|---|
| 1 | Leitbild ist die **Mehrbetriebs-/Konzern-Sicht** | Nicht „ein Objekt Portfolio", sondern eine Sicht über die drei vorhandenen Formen |
| 2 | „Aktiv" = **Status-Schalter + Gültigkeitsfenster**; „läuft heute" leitet das System ab | Kein zweites Feld, kein Ankreuzen |
| 3 | **Beobachten im Controlling, schalten an der Ausgabe** | Neuer Tab im Cockpit; der Schalter sitzt im jeweiligen Editor |
| 4 | Der Speiseplan bekommt eine **Outlet-Zuordnung** | Zwei Kantinen im selben Team waren vorher nicht unterscheidbar |
| 5 | **Beide Achsen gelten für alle drei Formen**, beide optional | Ein Foodbook kann an einem Outlet hängen; Karte und Plan können für einen Kunden sein (Betreibermodell). Zwei Brillen statt zwei Flächen |
| 6 | **Ein Statusfeld, kein Parallelzustand** — „Versenden wird aktiv" | `versendet`/`veroeffentlicht` verschwinden aus dem Vokabular |
| 7 | Dazu **`inaktiv`** als manueller Aus-Schalter | Gegenstück zum Fenster: bewusst vom Netz, ohne abzuschließen |

Zu Entscheidung 6: das geht ohne Informationsverlust, weil der Versand nie am Kopf hing.
Eingefroren wird **je Kapitel/Rubrik** über `snapshot_at`/`snapshot_json` und `status='sent'`
(`foodalchemist_foodbook_chapters`, `_menu_card_sections`; harte Grenze in
`FoodbookService::anlageZuruckziehen`). Der Kopf-Wert `versendet` war eine Dropdown-Auswahl ohne
Logik dahinter. Die Kopf-Spalte ist damit reiner Lebenszyklus, das Versand-Ereignis bleibt, wo es
gemessen wird.

## 3. Was gebaut wurde

| Etappe | Inhalt |
|---|---|
| **P0** | `AusgabeStatus`-Enum (entwurf · aktiv · inaktiv · archiviert) + toleranter Cast + Trait `HatAusgabeStatus` (`scopeLaeuft`, `laeuftAm`, `laufZustand`, `laufGrund`); Normalisierungs-Migration für alle drei Tabellen |
| **P1** | `gueltig_von`/`gueltig_bis` am Foodbook; Speiseplan-Fenster **abgeleitet** aus `MIN/MAX(entry_date)` statt gespeichert |
| **P2** | Beide Zuordnungsachsen an allen drei Formen (`outlet_id` + `customer`/`crm_company_id`/`crm_contact_id`), Trait `HatAusgabeZuordnung` |
| **P3** | `PortfolioService` — ein Leser über die drei Formen: `uebersicht`, `laufendeAm`, `konflikte`, `luecken`, `ohneZuordnung`, `konfliktHinweis` |
| **P4** | Controlling-Tab „Portfolio": Brillen-Umschalter, Matrix mit Stichtags-Regler, Konflikte, Lücken, Block „ohne Zuordnung" |
| **P5** | Gemeinsames Blade-Bauteil `ausgabe-status` in den drei Editoren + Schnellschalter aktiv ⇄ inaktiv; dazu die **Betriebe-Verwaltung** in den Einstellungen |
| **P6** | `PromotionService` — Umsatz je laufender Ausgabe im eigenen Fenster, mit exklusivem Anteil und Zuordnungs-Abdeckung |
| **P7** | Signal-Verlauf raus aus „Lage" in einen eigenen Tab „Verlauf" |
| **P8** | MCP `portfolio.GET` + `portfolio_promotion.GET`; dieses Dokument + Handbuch-Kapitel |

### Der Kern in drei Zeilen

```
läuft am Stichtag  =  Status ist Aktiv
                      und (kein Von  oder  Von ≤ Stichtag)
                      und (kein Bis  oder  Stichtag ≤ Bis)
```

Der Status wechselt dabei **nie von selbst**. Das Fenster ändert nur die Anzeige, nicht die
Daten — sonst wäre es die Automatik, die ausdrücklich nicht bestellt war.

## 4. Zwei Bremsen gegen ein Portfolio, das nur wächst

Weil Versenden auf `aktiv` setzt und nichts automatisch abläuft, stünden nach zwei Saisons fünf
„laufende" Karten je Outlet und die Konfliktliste wäre unbrauchbar. Zwei Gegenmittel, beide ohne
Automatik:

- **Gültigkeitsfenster** — abgelaufenes `gueltig_bis` heißt „läuft nicht", auch bei Status
  `aktiv`; die Übersicht führt das als *abgelaufen, noch nicht archiviert* mit
  Ein-Klick-Archivierung.
- **`inaktiv`** — der bewusste Griff, etwas kurzfristig vom Netz zu nehmen, ohne es
  abzuschließen.

Beide führen zu „läuft nicht", aber aus verschiedenem Grund. Die Übersicht hält das auseinander
(`laufGrund()`), statt einen grauen Punkt zu zeigen.

## 5. Was der Nutzer davon hat

Nicht die Liste ist der Wert, sondern was daran nicht stimmt:

- **leere Zelle** — an diesem Standort läuft gerade nichts
- **zwei Einträge** — Parallellauf; Hinweis, kein Fehler
- **Block „ohne Zuordnung"** — weder Betrieb noch Kunde; ohne diesen Block in beiden Brillen
  unsichtbar, und genau das ist die Art stiller Lücke, die eine Übersicht wertlos macht

## 6. Zwei eingebaute Ehrlichkeiten bei der Promotion-Auswertung

1. **Mehrfachzuordnung.** Steht ein Gericht in zwei laufenden Ausgaben, zählt sein Umsatz bei
   beiden. Die Summe über alle Zeilen ist dann **größer** als der Gesamtumsatz — kein
   Rechenfehler, sondern die Natur der Frage. Jede Zeile weist deshalb `umsatz_exklusiv` aus, und
   das MCP-Ergebnis trägt zusätzlich ein ausdrückliches `summierbar: false`. Ohne diesen Ausweis
   würde man Ausgaben addieren, die sich überlappen.
2. **Zuordnungs-Abdeckung.** Verkaufszeilen ohne Gericht sind keiner Ausgabe zurechenbar; unter
   80 % sagt die Fläche das, statt zu rechnen — dieselbe Zurückhaltung wie bei der
   Wareneinsatz-Abweichung in Spec 32 · C4.

Kein eigener Umsatzbegriff: gelesen wird `foodalchemist_sales_facts`, dieselbe Quelle wie
Menu-Engineering und Abweichungsanalyse.

## 7. Nicht-Ziele (gehalten)

- Kein Umbau der drei Ausgabe-Services zu einer gemeinsamen Basisklasse. `PortfolioService` legt
  einen Leser darüber und macht die Duplikation damit sichtbarer, nicht kleiner — bewusst.
- Keine Änderung am `PhaseService`. Bau-Fortschritt und Betriebs-Status bleiben getrennte Fragen
  mit getrennten Feldern.
- Keine Pflicht-Zuordnung; freistehende Ausgaben bleiben anlegbar.
- Keine Outlet-Hierarchie (Region → Betrieb → Küche). `foodalchemist_outlets` ist eine flache
  Liste.
- Keine automatischen Statuswechsel.

## 8. Tenant-Nachweis — und was er gefunden hat

`PortfolioTenantTest` (11 Fälle): Aktivschalter mit fremder Ausgabe-id, Zuordnung auf einen
fremden Betrieb über alle drei Formen bei `create` **und** `update`, Portfolio-Tab (weder fremde
Ausgaben noch fremde Betriebsnamen), beide MCP-Tools inkl. Filter-Sonde auf eine fremde
`outlet_id`, teamloser Kontext.

**Gefunden und gefixt:** `outlet_id` ging ungeprüft durch. Eine untergeschobene Betriebs-id
(Dropdown-Wert aus dem Browser, MCP-Argument) hängte die eigene Ausgabe in die Konzern-Sicht
eines fremden Betriebs. Der Guard fehlte nicht aus Nachlässigkeit, sondern weil `outlet_id` durch
zwei Raster fällt: die `FELDER`-Liste prüft, *ob* ein Feld gesetzt werden darf, nicht *worauf* es
zeigt — und der Datensatz-Guard greift nicht, weil ja die eigene Karte geschrieben wird, nur mit
fremdem Ziel.

Fix: Trait `Services\Concerns\PruefstOutletZuordnung`, angewandt bei `create`, `update` und
`dupliziere` aller drei Services. Eine fremde id fällt raus (Feld wird nicht geschrieben), statt
eine bestehende gültige Zuordnung stillschweigend zu löschen. Ausdrückliches Leeren bleibt
möglich.

Dieselbe Lehre wie in Spec 32: **„ein `visibleToTeam` steht im Code" ist kein Nachweis.** Jede
neue Fremdreferenz braucht einen Test, der sie mit fremden Daten füttert.

## 9. Offen

- [ ] **Browser-Abnahme** der Portfolio-Matrix und der drei Editoren (Shell-Rendering ist bei
      dieser Fläche nicht verlässlich).
- [ ] demo-Deploy inkl. Migrationen `2026_08_02_000007` (Status-Normalisierung), `000008`
      (Foodbook-Gültigkeit), `000009` (Zuordnungsachsen). **Backup vor dem Lauf** — die
      Normalisierung schreibt auf Bestandsdaten, und ein falsch normalisierter Status setzt eine
      laufende Kundenkarte auf Entwurf.
- [ ] P6 ist ohne Realdaten nur formal geprüft. Erst der Verkaufs-Ist-Lauf aus Spec 32 macht die
      Promotion-Überwachung fachlich beurteilbar.
- [ ] Der Konflikt-Hinweis beim Aktivsetzen erscheint nur an der Ausgabe. Ob er auch in der
      Matrix als Aktion (statt nur als Befund) gebraucht wird, zeigt die Nutzung.

## 10. Verzahnung

- **Spec 32 · C3:** liefert das Verkaufsjournal, auf dem P6 rechnet. Ohne Spec 32 wäre die
  Promotion-Überwachung ein leeres Gerüst.
- **Spec 32 · C4:** der Umgang mit unvollständiger Zuordnung ist bewusst derselbe — eine
  Abdeckungsquote neben der Zahl statt einer Zahl ohne Vorbehalt.
- **Spec 29/31:** die Editoren, in denen der Schalter jetzt sitzt.
- **Einstellungen:** die Betriebe-Verwaltung war die stille Voraussetzung — Outlets waren vor P5
  eine tote Achse (0 Datensätze, keine Oberfläche).
