# Handy-Ansicht: Liste aller Ansichten (Issue #1003)

Stand 30.10.2026 (Entscheidungen Dominique 29./30.10.).

## Leitsätze

- **App-Gefühl:** leicht bedienbar, Infos schnell findbar. Untere Leiste in der Daumenzone, schlanke
  Kopfzeile, globale Suche als Einstieg, Listen als Karten, Detail als Vollbild mit Zurück.
- **Handbuch-Stil, übersichtlich:** je Bildschirm ein Thema; Titel → Kernwerte (Allergene, Menge, Preis) →
  Details auf Antippen. Keine breiten Tabellen, keine Chip-Wolken, keine Doppel-Infos. Prüffrage je
  Screenshot: Findet man das Wichtigste in 2 Sekunden?
- **Detailseiten lesbar + eigene Handbuch-Ansicht wo nötig:** Alle Detailseiten werden am Handy lesbar.
  Wo sie für die Küche nicht reichen (Rezept: Schritte mit Bildern, Regeneration; Gericht: Darreichungsformen
  mit VK, Bilder), gibt es eine eigene Handy-Ansicht im Handbuch-Stil. Die Desktop-Detailseite bleibt dafür
  unverändert.
- **Erst nur Lesen:** Ändern-Aktionen am Handy aus, Hinweis „Am Computer bearbeiten“. Zentral freischaltbar
  für spätere kleine Änderungen am Handy (erster Kandidat: Signal abhaken). **Ausnahme Planung:** voll bedienbar.
- **Am Laptop ändert sich nichts.** Handy-Oberfläche = Touch-Gerät ohne Hover und schmaler als 768 px, oder
  bewusst gewählt; nie ein schmales Laptop-Fenster. Notausgang „Desktop-Ansicht“.

Geprüft wird bei 375 px und 390–430 px, Desktop vorher/nachher bei 1440 und 1024 px.

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
| Kopfzeile oben (io-Hülle, Host food-alchemist.de) | schlank (Logo, Titel, Benutzer); eigener PR im Host |
| Untere Leiste (Daumenzone) | Suche · Rezepte/Gerichte · Planung · Signale (mit Zähler offener Signale) · Mehr |
| „Mehr“ | Dashboard ganz oben, dann Grundprodukte, Lieferanten, Produktion, Bestellungen, Concepter, Formate, Projekte, Speiseplan, Speisekarten, Foodbooks; Mandant wechseln (vorhandener Umschalter); Desktop-Ansicht |
| Globale Suche | Rezept, Gericht, GP, Lieferant, Artikelnummer/EAN über die vorhandenen Such-Dienste; darunter „Zuletzt angesehen“ (pro Gerät) und Favoriten |
| Mandantenwechsel | vorhandener Core-/Host-Umschalter, danach dieselbe Seite bzw. Startseite des Bereichs |
| App auf dem Startbildschirm (PWA Stufe 1) | Manifest, Icons, Vollbild ohne Browserleiste, iOS-Hinweis „Zum Home-Bildschirm“ einmalig; kein Service Worker (Stufe 2 → 92_PWA_Offline_Push.md) |

## Seiten

| Seite | Pfad | Livewire | Art | Detailseite | PR |
|---|---|---|---|---|---|
| Rezepte (Basisrezepte) | `/rezepte` | Recipes\Browser | Lesen + Handbuch-Ansicht (Schritte mit Bildern, Regeneration) | Recipes\DetailPanel | 2 |
| Gerichte (Verkauf) | `/gerichte` | Verkauf\Browser | Lesen + Handbuch-Ansicht (Darreichungsformen mit VK, Bilder) | Verkauf\DetailPanel | 2 |
| Grundprodukte | `/gps` | Gps\Browser | Lesen | Gps\DetailPanel | 3 |
| Lieferanten + Artikel | `/lieferanten` | Suppliers\Index | Lesen | Suppliers\SupplierDetail | 3 |
| Concepter | `/concepter` | Concepter\Browser | Lesen | Concepter\DetailPanel | 4 |
| Formate | `/formate` | Formate\Browser | Lesen | Formate\DetailPanel | 4 |
| Projekte | `/projekte`, `/projekte/{id}` | Projekte\Index, Projekte\Detail | Lesen | Projekte\Detail | 4 |
| Produktion | `/produktion` | Produktion\Browser | Lesen | Produktion\DetailPanel | 5 |
| Bestellungen | `/bestellungen` | Orders\Index | Lesen | Orders\DetailPanel | 5 |
| Signale (Zu prüfen) | `/zu-pruefen` | ReviewQueue | Lesen, prominent (untere Leiste, Zähler); „abhaken“ als erste Freischaltung vorgemerkt | Signale\DetailPanel | 5 |
| Speiseplan | `/speiseplan` | Speiseplan\Index | Lesen (Ansicht, nicht Editor) | – | 6 |
| Speisekarten | `/speisekarten` | Speisekarte\Index | Lesen (Ansicht, nicht Editor) | – | 6 |
| Foodbooks | `/foodbooks` | Foodbooks\Index | Lesen (Ansicht, nicht Editor) | – | 6 |
| Planung | `/planung` | Planung\Index | Planung | Reiter Auftrag → Ergebnis → Kontext | 7 |
| Rezept-Import (Foto) | in Planung | Planung\RezeptImport | Planung | – | 7 |
| Dashboard (Leitstelle) | `/` | Dashboard | Lesen, prominent (ganz oben in „Mehr“ bzw. Startseite) | – | 1b |
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
