# Handy-Ansicht: Liste aller Ansichten (Issue #1003)

Stand 29.10.2026. Leitsatz: **Jede Detailseite ist am Handy vollständig lesbar.** Die Detailseite ist
die Wahrheit; am Handy wird sie nicht nachgebaut, sondern lesbar gemacht. Bearbeiten bleibt am Computer.

Handy heißt: Bildschirm schmaler als 768 px (Tailwind `md`). Geprüft wird bei 375 px und 390–430 px.

## Arten

| Art | Bedeutung am Handy |
|---|---|
| **Lesen** | Liste mit Suche → Antippen öffnet die Detailseite über die ganze Breite → Zurück führt in die Liste. Keine Knöpfe zum Ändern. |
| **Planung** | Bedienbar: Auftrag geben, Kaskade starten/abbrechen, Ergebnis lesen, Rezept-Import per Foto. |
| **Öffentlicher Link** | Seite ohne Anmeldung, muss am Handy einwandfrei sein. |
| **Desktop** | Kein kaputtes Layout, sondern ein Hinweis „Am Computer bearbeiten“. |

## Rahmen (gilt für alle Seiten)

| Baustein | Am Handy |
|---|---|
| Linke Leiste (Filter, Baum) `x-foodalchemist::page-sidebar` | zu, als schmale Leiste am Rand; Antippen öffnet sie als Schublade über dem Inhalt |
| Rechte Detail-Leiste `x-foodalchemist::detail-sidebar` | ganze Breite, öffnet beim Antippen eines Eintrags, Zurück schließt sie; ohne Auswahl keine Leiste |
| Kopfzeile oben (io-Hülle, Host food-alchemist.de) | eigener PR im Host |

## Seiten

| Seite | Pfad | Livewire | Art | Detailseite | PR |
|---|---|---|---|---|---|
| Rezepte (Basisrezepte) | `/rezepte` | Recipes\Browser | Lesen | Recipes\DetailPanel | 2 |
| Gerichte (Verkauf) | `/gerichte` | Verkauf\Browser | Lesen | Verkauf\DetailPanel | 2 |
| Grundprodukte | `/gps` | Gps\Browser | Lesen | Gps\DetailPanel | 3 |
| Lieferanten + Artikel | `/lieferanten` | Suppliers\Index | Lesen | Suppliers\SupplierDetail | 3 |
| Concepter | `/concepter` | Concepter\Browser | Lesen | Concepter\DetailPanel | 4 |
| Formate | `/formate` | Formate\Browser | Lesen | Formate\DetailPanel | 4 |
| Projekte | `/projekte`, `/projekte/{id}` | Projekte\Index, Projekte\Detail | Lesen | Projekte\Detail | 4 |
| Produktion | `/produktion` | Produktion\Browser | Lesen | Produktion\DetailPanel | 5 |
| Bestellungen | `/bestellungen` | Orders\Index | Lesen | Orders\DetailPanel | 5 |
| Zu prüfen (Signale) | `/zu-pruefen` | ReviewQueue | Lesen | Signale\DetailPanel | 5 |
| Speiseplan | `/speiseplan` | Speiseplan\Index | Lesen (Ansicht, nicht Editor) | – | 6 |
| Speisekarten | `/speisekarten` | Speisekarte\Index | Lesen (Ansicht, nicht Editor) | – | 6 |
| Foodbooks | `/foodbooks` | Foodbooks\Index | Lesen (Ansicht, nicht Editor) | – | 6 |
| Planung | `/planung` | Planung\Index | Planung | Reiter Auftrag → Ergebnis → Kontext | 7 |
| Rezept-Import (Foto) | in Planung | Planung\RezeptImport | Planung | – | 7 |
| Start | `/` | Dashboard | Lesen (Einstieg in die Bereiche) | – | 1 |
| Concepts (alt) | `/concepts` | Concepts\Index | Desktop | – | – |
| Pakete | `/pakete` | Pakete\Index | Desktop | – | – |
| Angebote | `/angebote` | Angebote\Index | Desktop | – | – |
| Kalkulation, Controlling | `/kalkulation`, `/controlling` | Controlling\Cockpit | Desktop | – | – |
| Favoriten | `/favoriten` | Favorites\Index | Desktop | – | – |
| Food-DNA | `/food-dna` | FoodDna\Index | Desktop | – | – |
| Wissen | `/wissen` | Knowledge\Browser | Desktop | – | – |
| Abos | `/abos` | Abos\Index | Desktop | – | – |
| Standortprofil | `/standortprofil` | Standortprofil\Index | Desktop | – | – |
| Trendradar | `/trendradar` | Trendradar\Index | Desktop | – | – |
| Tagesplan, Wandmonitor | `/produktion/tagesplan`, `/produktion/wandmonitor` | Produktion\Tagesplan | Desktop (Wandmonitor hat eigene Kiosk-Ansicht) | – | – |
| Wareneingang | `/wareneingang` | Wareneingang\Index | Desktop | – | – |
| Preislisten | `/preislisten` | Preislisten\Index | Desktop | – | – |
| Bestellvorlagen | `/bestellvorlagen` | Bestellvorlagen\Index | Desktop | – | – |
| Etiketten | `/etiketten` | Etiketten\Index | Desktop | – | – |
| Lager | `/lager` | Lager\Index | Desktop | – | – |
| Necta-Prüffälle, Marke offen, Marken | `/lieferanten/…` | Suppliers\NectaPruefaelle, MarkeOffen, Marken | Desktop | – | – |
| Einstellungen | `/einstellungen` | Settings\Index | Desktop | – | – |
| Designsystem | `/_ui` | UiKatalog | Desktop | – | – |
| Demnächst | `/demnaechst` | Demnaechst | Desktop | – | – |

Alle Editoren (Zutaten-Editor, Rezept-, Gericht-, GP-Dialoge, Concepter-Bearbeitung, Speiseplan-,
Speisekarten- und Foodbook-Editor) und alle Bearbeiten-Dialoge sind **Desktop**.

## Öffentliche Links

| Link | Ansicht | Art | PR |
|---|---|---|---|
| `/p/{type}/{token}` (Foodbook, Speisekarte, Speiseplan, Angebot) + App-Manifest, Share-Card | presentation/show | Öffentlicher Link | 6 |
| `/p/handbuch/{token}` | handbuch/show (seit #558 mit Handy-Ansicht) | Öffentlicher Link | 6 |
| `/_platform/fa-recipe-pdf/{token}` | Rezept-PDF | Öffentlicher Link (öffnen/teilen) | 6 |

## Bestandsaufnahme 375 px (29.10.2026, io-Hülle lokal)

- Linke Leiste offen und 320 px breit → Liste bleibt ca. 55 px schmal (Rezepte, Gerichte, GPs,
  Lieferanten, Produktion, Bestellungen, Concepter, Planung).
- Detail-Leiste 338 px, 37 px Rand; auf GPs und Planung liegt sie leer über der Liste.
- Kopfzeile oben läuft ca. 230 px über den Rand (Host).
- In den Detailseiten sind alle Knöpfe kleiner als 44 px; Knöpfe zum Ändern und KI-Knöpfe sind sichtbar.
- Inhalt der Detailseiten Rezept, Gericht, GP läuft nicht über den Rand.
