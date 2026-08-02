# Food Alchemist — Business-Case-Funktionsmatrix

- **Status:** aktive Vollständigkeits- und Abnahmeliste
- **Stand:** 01.08.2026
- **Quelle:** Produktoberflächen, Routen, Specs 01–30, MVP-Audit und Zielbild 2029
- **Steuerung:** [Umsetzungsplan zum Zielbild](24_Zielbild_2029_Umsetzungsplan.md)

## 1. Warum diese Matrix existiert

Die großen Zielbild-Gates reichen nicht aus, um ein Produkt abzunehmen. Ein
Kunden-Foodbook kann am Ende an einer kleinen Funktion scheitern: einem falschen
Filter, einer nicht speichernden Auswahl, einem unbemerkten alten Preis oder einem
fehlenden Deep-Link.

Diese Matrix macht deshalb auch die kleinen Business-Case-Funktionen sichtbar. Sie
ist gleichzeitig:

- Funktionsinventar,
- Abnahmekatalog,
- Lückenliste zwischen automatisiertem Test und realer Nutzerstrecke,
- Eingang für die Priorisierung des Zielbild-Plans.

## 2. Status- und Evidenzregeln

### Status

| Status | Bedeutung |
|---|---|
| `gebaut` | Implementierung und mindestens ein technischer Test sind erkennbar |
| `teilweise` | Kern existiert, aber Teilfunktion, Integration oder UX-Vertrag fehlt |
| `offen` | keine belastbare vollständige Implementierung nachgewiesen |
| `instabil` | Funktion existiert, besitzt aber einen P0/P1-Befund im relevanten Pfad |
| `real validiert` | mit repräsentativen Kundendaten und kompletter Nutzerstrecke bestanden |

### Evidenz

| Kürzel | Evidenzart |
|---|---|
| `U` | Unit-/Formeltest |
| `F` | Feature-, Service-, Livewire- oder Tooltest |
| `T` | negativer Tenant-/Ownership-Test |
| `B` | echter Browserpfad inklusive Speichern und Reload |
| `M` | MySQL-Smoke |
| `R` | repräsentativer Realfall/Kundendatensatz |
| `P` | Performance- oder Mengennachweis |

Ein vorhandener `F`-Test ersetzt weder `B` noch `R`. „Real validiert“ verlangt den
in der jeweiligen Zeile angegebenen Abnahmetest.

### Bestandsaufnahme

| Einordnung | Anzahl | Aussage |
|---|---:|---|
| gebaut | 99 | technische Implementierung erkennbar, fachliche Vollabnahme meist offen |
| teilweise | 35 | relevante Teilfunktion oder Integration fehlt |
| offen | 8 | noch keine belastbare vollständige Implementierung |
| instabil | 23 | vorhandene Funktion besitzt im relevanten Pfad einen P0/P1-Befund |
| real validiert | 0 | derzeit ist keine Zeile mit vollständiger `B`-/`R`-Evidenz belegt |
| **Summe** | **165** | eindeutige kleine Business-Funktionen |

Die Null bei „real validiert“ bedeutet nicht, dass keine Funktion praktisch
benutzbar ist. Sie bedeutet, dass die strenge Nachweiskette aus automatisiertem
Test, kompletter Browserstrecke und gegebenenfalls echten Kundendaten noch nicht
für eine einzelne Zeile dokumentiert wurde.

## 3. Übergreifende Bedien- und Arbeitsfunktionen

| ID | Kleine Business-Funktion | Geschäftlicher Nutzen | Ist-Status | Noch erforderlicher Abnahmetest | Phase |
|---|---|---|---|---|---|
| UX-01 | Dashboard zeigt richtige Arbeitsvorräte | Nutzer beginnt beim wichtigsten Problem | instabil | Zähler anklicken; Zielmenge und Filter müssen exakt identisch sein (`B`) | A |
| UX-02 | Allergen-Lücke direkt öffnen | Problem ohne erneute Suche bearbeiten | teilweise | Kachel öffnet genau die gezählte Lückenmenge (`B`) | A/C |
| UX-03 | Gericht ohne Klasse direkt öffnen | fehlende Klassifikation schließen | teilweise | URL und sichtbarer Filter zeigen nur unklassifizierte Gerichte (`B`) | A |
| UX-04 | Lieferantenartikel-Arbeitsvorrat | ungemappte/unbepreiste Artikel finden | teilweise | eigener Filter und reproduzierbare Menge (`B`) | B |
| UX-05 | Globale Suche und Filter bleiben in URL | Arbeit teilbar und wiederaufnehmbar | teilweise | Deep-Link, Reload, Zurück für alle 17 Bereiche (`B`) | A |
| UX-06 | Auswahl bleibt nach Reload erhalten | kein Verlust des Arbeitskontexts | teilweise | selektiertes Objekt per URL reproduzierbar (`B`) | A |
| UX-07 | Fehler werden sichtbar statt geschluckt | Nutzer kann reagieren und Support versteht Ursache | instabil | Servicefehler zeigt Meldung und alten Zustand (`F+B`) | A |
| UX-08 | Bedienung auf 390-px-Breite | Nutzung mobil beziehungsweise im kleinen Fenster | instabil | repräsentative Stammdaten- und Foodbook-Strecke (`B`) | D |
| UX-09 | Leere Zustände nennen nächste Aktion | Onboarding ohne Expertenhilfe | teilweise | je Kernbereich leerer Tenant mit CTA (`B`) | D |
| UX-10 | KI-Flächen verschwinden ohne Provider | Nicht-KI-Kern bleibt vollständig nutzbar | teilweise | Capability aus; kompletter Golden Path bleibt bedienbar (`B`) | A/C |

## 4. Lieferanten, Artikel und Grundprodukte

| ID | Kleine Business-Funktion | Geschäftlicher Nutzen | Ist-Status | Noch erforderlicher Abnahmetest | Phase |
|---|---|---|---|---|---|
| ST-01 | Lieferant anlegen und bearbeiten | kommerzielle Quelle pflegen | gebaut | Anlage, Reload, Fremdteam-Verbot (`F+T+B`) | A |
| ST-02 | Lieferant suchen und auswählen | große Lieferantenmenge bedienen | gebaut | 500 Lieferanten mit Pagination/Querybudget (`B+P`) | A |
| ST-03 | Lieferantenartikel manuell anlegen | fehlenden Katalogeintrag ergänzen | gebaut | Artikel mit Einheit, Gebinde, Preis und Deklaration (`F+B`) | B |
| ST-04 | Lieferantenartikel bearbeiten | Kondition und Daten korrigieren | gebaut | eigene/geerbte/fremde Variante (`T+B`) | A |
| ST-05 | Lieferantenartikel löschen | Fehleintrag bereinigen | instabil | ausschließlich eigener Artikel löschbar; Batch atomar (`T+B`) | A |
| ST-06 | Artikel nach Nummer/EAN erkennen | Doppelanlage vermeiden | gebaut | identischer Reimport und EAN-Fallback (`F+R`) | B |
| ST-07 | Artikel importieren: Dry-Run | Auswirkung vor Persistenz verstehen | gebaut | reale Datei zeigt Create/Update/Skip/Conflict korrekt (`R`) | B |
| ST-08 | Artikelimport ausführen und fortsetzen | große Kataloge zuverlässig laden | teilweise | Abbruch/Wiederaufnahme/Idempotenz mit Fremdkatalog (`R+P`) | B |
| ST-09 | manuelle Werte vor Import schützen | Kuration bleibt erhalten | instabil | Konfliktmatrix je Feld und zweiter Import (`F+R`) | B |
| ST-10 | Preis inklusive Gültigkeit importieren | belastbare Kalkulation | teilweise | gültig, zukünftig, abgelaufen, null und Konditionspreis (`F+R`) | B |
| ST-11 | Preisalter sichtbar machen | Kunde erkennt Handlungsbedarf | offen | UI, Kalkulation und Export zeigen denselben Stand (`F+B`) | B |
| ST-12 | fehlenden Preis eskalieren | kein stiller unbepreister Output | teilweise | Signal, Arbeitsvorrat und Export-Gate (`B`) | B/C |
| ST-13 | Lead-Lieferantenartikel wählen | definierte Preis- und Bezugswahrheit | gebaut | Wechsel propagiert Preis; Begründung sichtbar (`F+B`) | B |
| ST-14 | Lead-LA automatisch neu wählen | Ausfall/Preiswechsel behandeln | gebaut | deterministischer Re-Pick mit Auditnachweis (`F`) | B |
| ST-15 | Grundprodukt aus Artikel erzeugen | LA-First-Kuration beschleunigen | gebaut | Einzel- und Bulk-Mint, kein Fremdteamzugriff (`F+T+B`) | A/B |
| ST-16 | Artikel einem Grundprodukt matchen | konkrete Ware mit Fachzutat verbinden | gebaut | Accept/Reject/Rematch mit Quelle und Confidence (`F+B`) | B |
| ST-17 | Match-Vorschläge teamrichtig anzeigen | keine fremde Kunden-IP offenlegen | instabil | Team A/B und Parent/Child in Liste und Zähler (`T+B`) | A |
| ST-18 | ungemappte Artikel als Arbeitsvorrat | Datenlücke systematisch schließen | teilweise | Zähler, Filter, Bulk-Aktion und Reload (`B`) | B |
| ST-19 | Grundprodukt anlegen/bearbeiten | fachliche Zutatenwahrheit | gebaut | Taxonomie, Status, Synonyme und Reload (`F+B`) | A/B |
| ST-20 | Grundprodukt klassifizieren | Suche, Disposition und Matching | gebaut | fremder Katalog und manueller Override (`F+R`) | B |
| ST-21 | Grundprodukt-Aliase pflegen | Lieferantennamen korrekt zuordnen | gebaut | Kollisions-, Fremdteam- und Rematch-Fall (`F+T`) | A/B |
| ST-22 | Allergene/Zusatzstoffe am Artikel pflegen | belastbare Deklarationsbasis | gebaut | Herstellerquelle, unbekannt, explizit frei und Änderung (`F+B`) | C |
| ST-23 | Nährwerte am Artikel pflegen/importieren | berechenbare Nährwerte | teilweise | Einheiten, 100-g-Bezug, unbekannte Werte und Quelle (`F+R`) | B/C |
| ST-24 | Lieferantenbedingungen und Absprachen | Einkaufsrealität abbilden | gebaut | MOQ, Lieferfenster und Notiz im Bestellfall (`F+B`) | D |
| ST-25 | Spend-/Nutzungsübersicht je Lieferant | Verhandlung und Bündelung | teilweise | echte Nutzungs- oder Einkaufsdaten statt Proxy (`R`) | D |

## 5. Basisrezepte und Verkaufsgerichte

| ID | Kleine Business-Funktion | Geschäftlicher Nutzen | Ist-Status | Noch erforderlicher Abnahmetest | Phase |
|---|---|---|---|---|---|
| RE-01 | Basisrezept anlegen | wiederverwendbare Produktion | instabil | komplette Anlage mit Kategorie und Reload (`T+B`) | A/C |
| RE-02 | Basisrezept bearbeiten | Rezeptur aktuell halten | instabil | genau ein gewähltes Rezept wird gespeichert (`F+B`) | A |
| RE-03 | Kategorie/Hauptgruppe wählen | Bestand organisieren | instabil | nur sichtbare Kategorie-ID; Fremdteam-ID abgewiesen (`T+B`) | A |
| RE-04 | Zutat als Grundprodukt hinzufügen | Kosten und Deklaration berechnen | gebaut | Menge/Einheit ändern, speichern, reloaden (`F+B`) | C |
| RE-05 | Unterrezept hinzufügen | Komponenten wiederverwenden | gebaut | Zyklusverbot, max. Tiefe und Propagation (`F`) | C |
| RE-06 | Zutatenreihenfolge ändern | reale Produktionsabfolge | gebaut | Drag/Save/Reload ohne Mengenverlust (`B`) | C |
| RE-07 | Yield/Garverlust berechnen | richtiger Einstand je Ausgabemenge | gebaut | Golden Cases inklusive null/ungültig (`U+F`) | C |
| RE-08 | Grammatur/Bäckerprozente nutzen | skalierbare Rezeptentwicklung | gebaut | echte UI-Klickstrecke und Rundung (`B`) | C |
| RE-09 | Rezeptmenge skalieren | Produktion für Personenzahl | gebaut | Subrezepte, Stückartikel und Rundung (`F+B`) | C |
| RE-10 | Rezeptstatus und Freigabe | Draft von freigegebener Wahrheit trennen | teilweise | erlaubte Übergänge, Fehleranzeige, Audit (`F+B`) | C |
| RE-11 | Rezept suchen/filtern/deeplinken | Bestand effizient kuratieren | instabil | kombinierte Filter, Edit-Deep-Link, Reload (`B`) | A |
| RE-12 | Gericht anlegen | verkaufsfähiges Ergebnis | gebaut | Anlage aus GP und Basisrezept, Reload (`F+B`) | C |
| RE-13 | Gericht bearbeiten | Angebot korrigieren | instabil | Editor öffnet gewähltes Gericht und speichert atomar (`B`) | A/C |
| RE-14 | Gerichtsklasse/Aufschlagsklasse wählen | Preis- und Portfolio-Logik | instabil | nur sichtbare/erlaubte IDs; Parent read-only (`T+B`) | A |
| RE-15 | Darreichung und Portion pflegen | EK/VK je Verkaufsform | gebaut | mehrere Formen, Standardform, Mengenänderung (`F+B`) | C |
| RE-16 | Ziel-VK und Marge berechnen | wirtschaftliche Entscheidung | gebaut | Golden-Formel in UI, Tool und Export identisch (`F+B`) | C |
| RE-17 | Beschreibung/Kundentext erzeugen | verkaufsfähige Kommunikation | gebaut | ohne KI manuell; mit KI Vorschau/Übernahme (`F+B`) | C |
| RE-18 | Rezept/Gericht per Freitext überarbeiten | schnelle Variantenbildung | gebaut | vegan/allergenfrei mit Grounding und Diff (`F+B`) | C |
| RE-19 | vollständige KI-Anreicherung | weniger manuelle Einzelschritte | gebaut | One-Shot endet bepreist, stoppt ehrlich bei Hardstop (`F+R`) | C |
| RE-20 | Rezept-Copilot prüfen lassen | Qualitätsfehler finden, nicht umschreiben | gebaut | Befund mit Ursache, Objekt und manueller Aktion (`F+B`) | C |
| RE-21 | Zutat austauschen und propagieren | Kosten/Verfügbarkeit optimieren | gebaut | Vorschau, Übernahme, Recompute und Undo/Revision (`F+B`) | C |
| RE-22 | Favorit markieren/filtern | häufig genutzte Gerichte schneller finden | teilweise | mehr als 100 Treffer, korrekter Kontext (`B+P`) | A/D |
| RE-23 | Rezeptvorlage instanziieren | standardisierte Rezepttypen schnell erzeugen | gebaut | Slot-Vorschläge, manuelles Match, Instanziierung und Reload (`F+B`) | C |
| RE-24 | Rezept per Sprache/Text erfassen | Rezeptaufnahme in der Küche beschleunigen | teilweise | Audio/Text, Vorschau, Teilübernahme, Fehler- und Datenschutzfall (`F+B+R`) | D |

## 6. Konzepte, Pakete und Foodbooks

| ID | Kleine Business-Funktion | Geschäftlicher Nutzen | Ist-Status | Noch erforderlicher Abnahmetest | Phase |
|---|---|---|---|---|---|
| CO-01 | Konzept anlegen und bearbeiten | Angebotsgerüst aufbauen | instabil | Anlage/Reload/Fremddimensionen/atomarer Save (`T+B`) | A/C |
| CO-02 | Paket anlegen und bearbeiten | Gerichte bündelbar verkaufen | teilweise | Positionen, Preise, Reload und Tenantfall (`T+B`) | A/C |
| CO-03 | Facetten und Dimensionen setzen | Anlass, Stil und Zielgruppe steuern | instabil | Fremdteam-ID abweisen; Vererbung sichtbar (`T+B`) | A |
| CO-04 | Slots/Positionen definieren | Konzept planbar machen | gebaut | Rolle, Muss/Kann, Menge, Reihenfolge (`F+B`) | C |
| CO-05 | Gericht manuell in Slot setzen | menschliche Planung bleibt möglich | gebaut | Picker, Filter, Übernahme, Reload (`B`) | C |
| CO-06 | Slot-Vorschlag deterministisch | passende Bestandsgerichte nutzen | gebaut | Kriterien und Ablehnungsgrund sichtbar (`F+B`) | C |
| CO-07 | Brief in Konzept übersetzen | Anfrage schneller strukturieren | teilweise | echter Brief, Vorschau, Korrektur und Übernahme (`R+B`) | C/D |
| CO-08 | Coverage Soll/Ist anzeigen | Lücken im Konzept erkennen | gebaut | Solländerung aktualisiert Befund und Arbeitsweg (`F+B`) | C |
| CO-09 | Konzept bewerten | Qualität und Wirtschaftlichkeit vergleichen | gebaut | Formel/Signale nachvollziehbar, keine leere Kennzahl (`F+B`) | C |
| CO-10 | Zutaten-/Artikeltausch im Konzept | Kostenoptimierung über mehrere Gerichte | gebaut | Vorschau, Teilübernahme, Recompute (`F+B`) | C |
| CO-11 | Konzeptstatus/Phase führen | Zusammenarbeit und Freigabe | gebaut | Statuswechsel, Rechte, Reload und Audit (`F+T+B`) | C |
| FB-01 | Foodbook anlegen und auswählen | Kundenportfolio starten | gebaut | leerer Tenant, Anlage, Deep-Link, Reload (`B`) | C |
| FB-02 | Zielgruppe und Leitplanken setzen | Kundennutzen steuern | gebaut | Defaults, Override und Vererbung (`F+B`) | C |
| FB-03 | Kapitelbaum anlegen/einrücken | komplexes Dokument strukturieren | instabil | n-tief, Reload, Fremdkapitel-ID verboten (`T+B`) | A/C |
| FB-04 | Kapitelziele definieren/vererben | Sollvorgaben kontrollieren | gebaut | Parent/Child-Vorrang und Stale-Signal (`F+B`) | C |
| FB-05 | Block/Text anlegen und bearbeiten | Kundeninhalt schreiben | instabil | Fremdblock lesen/schreiben verboten; Reload (`T+B`) | A/C |
| FB-06 | Gericht oder Paket referenzieren | bepreiste Inhalte einsetzen | gebaut | Einzelgericht/Paket, Dedup und Preisbasis (`F+B`) | C |
| FB-07 | Skizzenideen gruppieren | Divergenz vor Festlegung | gebaut | Gruppe, XOR, Übernahme, Löschen und Reload (`F+B`) | C |
| FB-08 | Kreativmodus wählen | Balance Bestand/KI/Kreativität | gebaut | datenbank/hybrid/voll kreativ jeweils im Browser (`F+B`) | C |
| FB-09 | Pairing-Inspiration abrufen | kulinarische Differenzierung | gebaut | Vorschlag geerdet, erklärbar und übernehmbar (`F+B`) | C |
| FB-10 | Kapiteltext per KI erzeugen | redaktionelle Arbeit reduzieren | gebaut | Vorschau, manuelle Änderung, Übernahme, Quelle (`F+B`) | C |
| FB-11 | Leitstelle/Phasencheckliste | Nutzer durch vollständige Erstellung führen | gebaut | jeder Schritt navigiert zu echter Aktion (`B+R`) | C |
| FB-12 | Kapitel-Go/Freigabe | unfertige Inhalte nicht veröffentlichen | teilweise | harte Gates für Preis und Deklaration ergänzt (`F+B`) | C |
| FB-13 | Wareneinsatz-Ampel | Wirtschaftlichkeit je Kapitel | gebaut | Preisänderung propagiert und erklärt Abweichung (`F+B`) | C |
| FB-14 | Branding pflegen | kundengerechtes Dokument | gebaut | Logo/Farben/PDF/Reload/Tenantfall (`T+B`) | C |
| FB-15 | Vorschau und PDF erzeugen | Ergebnis präsentieren | gebaut | kompletter Referenzfall, Seitenprüfung und Freigabestatus (`B+R`) | C |
| FB-16 | Angebot aus Foodbook erzeugen | Verkaufsergebnis ableiten | teilweise | Anlage, Version, Preis, PDF und Status (`B+R`) | C/D |
| FB-17 | Angebot suchen/filtern/öffnen | Vertriebsarbeit wiederaufnehmen | gebaut | read/write Browser-E2E statt nur read-only (`B`) | C |

## 7. Food DNA und Kundenkontext

| ID | Kleine Business-Funktion | Geschäftlicher Nutzen | Ist-Status | Noch erforderlicher Abnahmetest | Phase |
|---|---|---|---|---|---|
| FD-01 | Team-Food-DNA pflegen | Marken- und Küchenidentität als stehende Referenz | gebaut | Canvas anlegen, sortieren, speichern, reloaden und teamisolieren (`T+B`) | C/D |
| FD-02 | Küchenprofil als Generator-Default | Vorschläge passen zum Produktionsmodell | gebaut | kein Profil, Profil, expliziter Override und Generatorvergleich (`F+B`) | C |
| FD-03 | Kunden-DNA pflegen | Kommunikation und Erwartungen je Kunde erhalten | teilweise | CRM-Kunde verknüpfen, Canvas speichern und in zwei Foodbooks wiederverwenden (`T+B`) | D |
| FD-04 | Foodbook-Kontext über DNA-Kette erben | Team→Kunde→Foodbook ohne widersprüchliche Prompts | teilweise | Vorrangregel und effektiven Kontext sichtbar vergleichen (`F+B+R`) | C/D |

## 8. Kalkulation, Planung, Produktion und Einkauf

| ID | Kleine Business-Funktion | Geschäftlicher Nutzen | Ist-Status | Noch erforderlicher Abnahmetest | Phase |
|---|---|---|---|---|---|
| WI-01 | EK bottom-up neu berechnen | Preiswahrheit über alle Ebenen | gebaut | Golden-Fall plus Änderung/Propagation (`F+M`) | C |
| WI-02 | HK1/HK2 und Fixkosten berechnen | vollständige Kostenentscheidung | gebaut | Einheiten/Rundung/UI/Export identisch (`U+F+B`) | C |
| WI-03 | Preissimulation durchführen | Auswirkung vor Änderung sehen | teilweise | Artikelsuche statt interner ID; Team-Scope (`T+B`) | A/C |
| WI-04 | Preisalarm und Marge-Impact | relevante Preisänderung priorisieren | gebaut | echter Preiswechsel erzeugt richtigen Arbeitsweg (`F+R`) | B/C |
| WI-05 | Zielpreis berechnen | Angebot an Budget ausrichten | gebaut | Portion, Zielmarge und Hardstop (`F+B`) | C |
| WI-06 | Saison-Autopricing vorschlagen | Preisänderung rechtzeitig erkennen | gebaut | Vorschlag, Begründung, manuelle Annahme (`F+B`) | D |
| WI-07 | Portfolio-Benchmark | Gerichte wirtschaftlich vergleichen | gebaut | aussagefähig nur bei genug Daten; leere Fläche ausblenden (`B+R`) | D |
| WI-08 | Feedback je Gericht erfassen | reale Akzeptanz zurückführen | gebaut | Küche/Kunde/Event, Rechte und Auswertung (`F+B`) | D |
| WI-09 | Menü-Kandidaten filtern | passende Auswahl vor Solver | gebaut | Zielkriterien, Preise und Querybudget (`F+P`) | C |
| WI-10 | margenoptimale Assemblierung | wirtschaftliches Menü erzeugen | teilweise | Solververtrag SOL-01–09; exakt/heuristisch korrekt markiert; Referenzfall (`F+R`) | C |
| WI-11 | Solver-Erklärung und Lockerung | Entscheidung nachvollziehen | gebaut | bindende Regel und Wert der Lockerung korrekt (`F+B`) | C |
| WI-12 | Zielvolumen bis ~1.000 Gerichte | planbare Laufzeit | offen | Benchmark mit Laufzeit-/Speicherbudget (`P`) | C |
| WI-13 | freie Kalkulation mit Positionen | Ad-hoc-Angebote und Sonderfälle rechnen | gebaut | Kopf/Positionen, Referenzobjekt, Update, Löschen und Reload (`F+B`) | C/D |
| PL-01 | Speiseplan anlegen/auswählen | Gerichte zeitlich planen | gebaut | Anlage, Zyklus, Reload und Rechte (`F+T+B`) | C/D |
| PL-02 | Gerichte in Matrix verteilen | Wochen-/Mahlzeitenplanung | gebaut | Tastaturbedienung, Save/Reload (`B`) | D |
| PL-03 | Abwechslung prüfen | Wiederholungen vermeiden | teilweise | definierte Regel und sichtbare Lösung (`F+B`) | D |
| PR-01 | Produktionsauftrag aus Ziel erzeugen | berechneten Bedarf ausführbar machen | gebaut | Rezept/Konzept/Foodbook-Kapitel, Mengen und Rundung (`F+B`) | C/D |
| PR-02 | mehrere Produktionen pro Tag | reale Küchenorganisation | gebaut | benennen, filtern, kombinieren, reloaden (`F+B`) | D |
| PR-03 | Produktionsstatus führen | Fortschritt kontrollieren | gebaut | erlaubte Übergänge, Rechte und Audit (`F+T+B`) | D |
| PR-04 | Produktionsblatt/PDF/CSV | Küche erhält Arbeitsunterlage | gebaut | Referenzfall, Freigabe und CSV-Sicherheit (`B+R`) | A/C |
| PR-05 | Ansätze je Zeile überschreiben | Küchen-Korrektur ohne die Rechnung zu zerstören | gebaut | Override überlebt Recompute, berechneter Wert bleibt sichtbar, kein Durchschlag auf Einkauf (`F+T+B`) | C/D |
| PR-06 | Zeile streichen / freie Position | reale Auftragspflege neben der Explosion | gebaut | gestrichene Zeile raus aus Summen und Druck, drin im Panel; freie Position überlebt auch ohne Ziele (`F+T+B`) | C/D |
| PR-07 | Posten und Kapazität pflegen | Arbeit auf Arbeitsplätze verteilen | gebaut | CRUD, Wochentag-Kapazität, Referenzschutz, team-strikte Auslastung (`F+T+B`) | D |
| PR-08 | Zeile zuteilen (Posten, Verantwortlich, Vorlauf) | Disposition auch im laufenden Service | gebaut | Zuteilung im `in_progress` erlaubt, plan_date folgt dem Liefertag (`F+T+B`) | D |
| PR-09 | Tagesplan über alle Aufträge | Küchen-Sicht auf den Tag statt auf den Beleg | gebaut | Zeitfenster, Posten-Filter, Auslastungsbalken, Lücken-Ausweis bei fehlender Arbeitszeit (`F+B`) | D |
| PR-10 | Zeilen abhaken | Ausführung protokollieren, ohne Ist-Mengen zu erfinden | gebaut | nur im laufenden Auftrag, Fortschritt abgeleitet, kein Auto-Weiterschalten des Auftragsstatus (`F+T+B`) | D |
| PR-11 | Auftrag löschen | Fehlanlage entfernen, ohne Protokoll zu verlieren | gebaut | nur geplant/storniert; laufend wird storniert, fertig bleibt (`F+T+B`) | D |
| OR-01 | Bedarf in Bestellung übernehmen | Einkauf aus Planung ableiten | gebaut | Quellenbeiträge, Rundung und keine Doppelübernahme (`F+B`) | D |
| OR-02 | Bestellschienen je Lieferant | Bedarf korrekt bündeln | gebaut | mehrere Lieferanten/MOQ/Liefertermin (`F+B`) | D |
| OR-03 | manuelle Bestellzeile ergänzen | Sonderbedarf erfassen | gebaut | eigener Artikel, Menge, Preis und Reload (`F+B`) | D |
| OR-04 | Alternativartikel/Strategie wechseln | Beschaffung flexibel optimieren | gebaut | Neu-Quellen mit Preis-/Deklarationsvergleich (`F+B`) | D |
| OR-05 | Bestellstatus führen | Bestellung kontrolliert abschließen | gebaut | Übergänge, Rechte, Fehleranzeige (`F+T+B`) | D |
| OR-06 | Bestellung als PDF/CSV/mailto | Lieferant praktisch beauftragen | teilweise | reales Dokument, CSV-Schutz, Zustellnachweisgrenze (`B+R`) | A/D |

## 8a. Controlling (Spec 32)

Befund und Hebel an einem Ort. Die Lese-Funktionen bestanden vorher verstreut (WI-03/04/07,
FB-13) — neu sind hier die Handlungen und die gesamte Erlösseite.

| ID | Kleine Business-Funktion | Geschäftlicher Nutzen | Ist-Status | Noch erforderlicher Abnahmetest | Phase |
|---|---|---|---|---|---|
| CT-01 | Controlling-Werkbank öffnen | eine Fläche statt fünf Seiten | gebaut | Tab-Vorwahl, Deep-Link, Alt-Routen ohne Verlust (`F+B`) | C/D |
| CT-02 | Bezugsquelle aus dem Preisvergleich umstellen | vom Preisbefund zur Maßnahme ohne Ortswechsel | gebaut | Lead wechselt, EK propagiert, Begründung protokolliert (`F+T+B`) | C |
| CT-03 | Bezugsquellen im Batch umstellen | Einsparpotenzial in einem Zug heben | gebaut | Vorschau nennt die betroffene Menge; genau ein Recompute-Lauf (`F+P+B`) | C |
| CT-04 | Preis-Ausreißer sichten | Fehlbuchungen finden, die alle Zahlen verzerren | gebaut | Trend- vs. Median-Basis unterscheidbar; kein Auto-Fix (`F+R`) | C/D |
| CT-05 | Lieferanten-Szenario simulieren | „X kündigt 5 % an" beantworten | gebaut | Scope trifft nur GPs mit Lead bei diesem Lieferanten (`F+B`) | C |
| CT-06 | Zielwerte inline setzen | Ampel verstellen, während man sie liest | gebaut | schreibt denselben Pfad wie die Einstellungen (`F+B`) | D |
| CT-07 | Verkaufspreise als Batch freigeben | kein stiller Preissprung beim Kunden | gebaut | ohne Freigabe bleibt der veröffentlichte VK unverändert; fremde Darreichung wird übersprungen (`F+T+B`) | C/D |
| CT-08 | Verkaufs-Ist importieren | Erlösseite überhaupt erst messbar | gebaut | Trockenlauf schreibt nichts; Re-Import idempotent; Spalten frei zuordenbar (`F+R+B`) | D |
| CT-09 | Offene Verkaufszeilen zuordnen | kein stiller Umsatzverlust in der Auswertung | gebaut | Handzuordnung überlebt den Re-Import (`F+B`) | D |
| CT-10 | Menu-Engineering-Matrix | Renner/Penner erkennen | gebaut | hand-gerechneter Fall reproduziert die Quadranten; Popularitäts-Quelle ausgewiesen (`F+R`) | D |
| CT-11 | Wareneinsatz Ist gegen Rezeptur | die echte Quote statt der kalkulierten | gebaut | verweigert die Aussage unter 80 % Zuordnungs-Abdeckung (`F+R`) | D |
| CT-12 | Abweichungs-Signal | Auffälligkeit kommt zu einem, nicht man zu ihr | gebaut | Schwelle in pp; unterhalb still; knopflos (`F`) | D |

## 8b. Portfolio-Steuerung (Spec 33)

Die Mehrbetriebs-Sicht über die drei Ausgabeformen. Vorher gab es zwar Status-Felder, aber
niemanden, der sie abfragt — „läuft heute" war keine beantwortbare Frage.

| ID | Kleine Business-Funktion | Geschäftlicher Nutzen | Ist-Status | Noch erforderlicher Abnahmetest | Phase |
|---|---|---|---|---|---|
| PF-01 | Ausgabe aktiv/inaktiv schalten | eine Karte vom Netz nehmen, ohne sie abzuschließen | gebaut | ein Statusfeld, kein Parallelzustand; Versenden setzt auf aktiv; fremde id schaltet nichts (`F+T+B`) | D |
| PF-02 | Gültigkeitsfenster je Ausgabe | das Portfolio wächst nicht endlos | gebaut | abgelaufenes Fenster läuft NICHT trotz Status aktiv; Status wechselt nie von selbst (`F`) | D |
| PF-03 | Betrieb und Kunde zuordnen | zwei Kantinen im selben Team unterscheidbar | gebaut | beide Achsen an allen drei Formen, beide optional; fremder Betrieb nicht zuweisbar (`F+T`) | D |
| PF-04 | Portfolio-Matrix mit Stichtag | „wer fährt gerade was" — und was im September läuft | gebaut | zwei Brillen auf einer Matrix; Lücke, Parallellauf und Nicht-Zugeordnete sichtbar (`F+B`) | D |
| PF-05 | Grund nennen, warum etwas nicht läuft | Entwurf, inaktiv und abgelaufen sind drei Sachverhalte | gebaut | vier Fixtures, vier unterscheidbare Gründe; Anzeigen ändert nichts (`F`) | D |
| PF-06 | Umsatz je laufender Ausgabe | was eine Karte tatsächlich bringt | gebaut | exklusiver Anteil bei Mehrfachzuordnung; Abdeckung unter 80 % wird benannt; **Realdaten-Lauf offen** (`F+R`) | D |

## 9. Qualität, Wissen, KI, Pairing und Trends

| ID | Kleine Business-Funktion | Geschäftlicher Nutzen | Ist-Status | Noch erforderlicher Abnahmetest | Phase |
|---|---|---|---|---|---|
| QL-01 | Datenqualitätssignale erzeugen | Probleme systematisch finden | gebaut | realer Datenbestand; erwartete Signalmenge (`R+P`) | C |
| QL-02 | Signal filtern und Objekt öffnen | vom Befund zur Korrektur | instabil | aggregierte und objektbezogene Signale mit richtigem CTA (`B`) | A/C |
| QL-03 | Fix-Vorschau und Teil-Bulk | Korrektur ohne Blindflug | gebaut | Dry-Run, Teilmenge, atomarer Fehlerfall (`F+B`) | C |
| QL-04 | Signal schließen/wieder öffnen | Arbeitsvorrat verwalten | gebaut | Lifecycle, Drift und Reaktivierung (`F+B`) | C |
| QL-05 | Qualitätsverlauf/Rausch-Guard | echte Veränderung statt Signalflut | gebaut | Zeitreihe am realen Bestand (`F+R`) | C/D |
| KN-01 | Wissensdokument suchen/lesen | Regeln und Fachwissen nutzbar machen | instabil | Team A/B, globale Dokumente und Pagination (`T+B+P`) | A |
| KN-02 | Wissensdokument anlegen/bearbeiten | Fachwissen ohne Code pflegen | instabil | Ownership, Version und Reload (`T+B`) | A/C |
| KN-03 | Aliase pflegen | robustes Retrieval | instabil | Fremddokument verboten; Kollisionsfall (`T+F`) | A |
| KN-04 | Wissen an Einsatzort binden | Prompt erhält richtigen Kontext | instabil | Bind/Unbind mit Ownership und effektiver Vorschau (`T+F+B`) | A/C |
| KN-05 | Textsuche und semantische Suche | relevantes Wissen finden | teilweise | VEC-01–14, Golden Set, kein Provider und Store-Ausfall (`F+R+P`) | C |
| KN-06 | Regelwerk importieren | Modulwissen reproduzierbar halten | gebaut | idempotent, Versionskonflikt und Import-Guard (`F`) | C |
| AI-01 | KI-Provider konfigurieren | kontrollierte Aktivierung | gebaut | Rechte, Secret-Maskierung und Verbindungstest (`T+B`) | A |
| AI-02 | KI-Aufruf protokollieren | Kosten und Fehler nachvollziehen | gebaut | Team, Zweck, Token, Preis, Latenz und Fehler (`F`) | C/D |
| AI-03 | Retry und Ausfallschutz | temporäre Providerfehler überleben | gebaut | Timeout, Retrylimit, kein Doppelwrite (`F`) | A/C |
| AI-04 | Vorschlag prüfen/übernehmen/verwerfen | Mensch behält Kontrolle | gebaut | vollständiger Lifecycle und Audit (`F+B`) | C |
| AI-05 | KI-Kosten je Kunde messen | Erlösmodell belastbar machen | offen | reales Monatsreporting (`R`) | D |
| PA-01 | Pairing-Kohäsion berechnen | kulinarische Stimmigkeit prüfen | gebaut | Golden Set und Erklärung (`F`) | C |
| PA-02 | passende/Brücken-Zutat vorschlagen | Gericht gezielt verbessern | gebaut | Vorschlag, Evidenz, Ausschlüsse und Übernahme (`F+B`) | C |
| PA-03 | Aroma-treue Substitution | Kosten/Verfügbarkeit ohne Stilbruch | gebaut | realer Rezeptfall und Recompute (`F+R`) | C |
| PA-04 | Gericht rückwärts analysieren | Inspiration aus Zielgericht | gebaut | Grounding und keine erfundenen Zutaten (`F+R`) | C |
| PA-05 | Überschuss zu Gericht | Resteverwertung unterstützen | teilweise | echter Bestandscontract statt Mock (`R`) | D/E |
| PA-06 | Hypothesen-/Widerspruchsmodus | R&D und Differenzierung | gebaut | nachvollziehbare Evidenz und Lab-Note (`F+B`) | D |
| TR-01 | Trend erfassen | zentralen Impuls pflegen | teilweise | Quelle, Zeitraum, Rechte und Lifecycle (`F+B`) | C/D |
| TR-02 | Trend in Konzept übersetzen | Differenzierer praktisch nutzen | teilweise | klickbarer End-to-End-Prototyp (`B`) | C |
| TR-03 | Trend betrieblich kontextualisieren | Vorschlag passt zu Kunde und Sektor | offen | Sektor, Preisniveau, Stil und Convenience im Vergleich (`R`) | D |
| TR-04 | Trend vorab bepreisen/beschaffbar machen | kein unbepreister Vorschlag | offen | GP-/LA-/Preis-Vorlauf und Sperre (`R`) | B/D |

## 10. Administration, Onboarding und Betrieb

| ID | Kleine Business-Funktion | Geschäftlicher Nutzen | Ist-Status | Noch erforderlicher Abnahmetest | Phase |
|---|---|---|---|---|---|
| AD-01 | Team mit Defaults anlegen | schneller Kundenstart | gebaut | leerer Kunde bis arbeitsfähige Oberfläche (`F+B`) | D |
| AD-02 | globale/Parent-Werte lesen, nicht ändern | Master-Katalog sicher verteilen | instabil | vollständige Settings- und Stammdatenmatrix (`T+B`) | A |
| AD-03 | Einstellungsbereiche öffnen/deeplinken | Administration wiederaufnehmbar machen | teilweise | alle 17 Sektionen per URL, Reload und Berechtigung (`T+B`) | A |
| AD-04 | Kalkulationsdefaults pflegen | kundenindividuelle Wirtschaftlichkeit | gebaut | Teamoverride, Vererbung und Recompute (`F+B`) | C/D |
| AD-05 | Geschirr pflegen | Darreichung vollständig kalkulieren | gebaut | Lieferant, Preis, Suche und Skalierung (`F+B+P`) | D |
| AD-06 | Nutzerrolle/Berechtigung | Verantwortung trennen | teilweise | Rollenmatrix für Lesen, Kuratieren, Freigeben, Admin (`T+B`) | D/E |
| AD-07 | Onboarding-Fortschritt anzeigen | Kunde erkennt fehlende Voraussetzungen | offen | leerer und teilweise gepflegter Kunde (`B`) | D |
| AD-08 | Import-/Jobstatus anzeigen | lange Vorgänge ohne Support verständlich | teilweise | queued/running/failed/completed, Retry und Detail (`F+B`) | B/D |
| AD-09 | Backup/Restore-Runbook | Betriebsfehler beherrschbar machen | offen | dokumentierte Restore-Übung (`R`) | D/E |
| AD-10 | Export/Löschung eines Mandanten | Datenschutz und Portabilität | offen | vollständiger Export und verifizierte Löschung (`R`) | E/F |
| AD-11 | Tool-Capabilities prüfen | Agents arbeiten verlässlich | teilweise | erwartete Tools registriert; Fehler sichtbar (`F`) | A |
| AD-12 | Betrieb ohne KI | Kernprodukt bleibt verfügbar | teilweise | kompletter G1-Golden-Path mit KI aus, soweit fachlich möglich (`B+R`) | C |
| AD-13 | Einheiten pflegen | konsistente Mengen und Umrechnung | gebaut | Anlage, Inaktivierung, Nutzungsschutz und Tenantfall (`T+B`) | A/B |
| AD-14 | GP-Taxonomie und Warengruppen pflegen | Katalog kundengerecht klassifizieren | gebaut | Hierarchie, Sortierung, Rename, belegtes Löschen und Tenantfall (`T+B`) | A/B |
| AD-15 | VK-Taxonomie pflegen | Gerichtsklassen strukturiert halten | gebaut | Hauptgruppe/Klasse, Inaktivierung, Nutzungsschutz (`T+B`) | A/C |
| AD-16 | Aufschlagsklassen pflegen | Preisstrategie konfigurieren | instabil | globale/Parent-Werte read-only; eigene CRUD (`T+B`) | A |
| AD-17 | Concepter-Dimensionen pflegen | Planungsvokabular anpassen | gebaut | Anlass/Servierform/Facetten, Nutzungsschutz und Tenantfall (`T+B`) | A/C |
| AD-18 | Behälter und Einsatzorte pflegen | Produktion/Darreichung korrekt planen | gebaut | CRUD, Inaktivierung, Referenzschutz (`T+B`) | C/D |
| AD-19 | Herstellkostenblöcke/Fixkosten pflegen | kundengerechte Vollkosten | gebaut | automatische und manuelle Blöcke, Recompute und Teamoverride (`F+B`) | C |
| AD-20 | Einkaufsprioritäten und Stammlieferanten pflegen | Beschaffungsstrategie steuern | gebaut | Reihenfolge, Warengruppe, Fallback und Bestellung (`F+B`) | B/D |
| AD-21 | Schreibstile pflegen | Kundentexte markengerecht erzeugen | gebaut | CRUD, Inaktivierung und Verwendung im Foodbook (`T+B`) | C/D |
| AD-22 | Wissenskategorien pflegen | Retrieval und Kuration strukturieren | gebaut | CRUD, Aktivierung, Nutzungsschutz und Tenantfall (`T+B`) | A/C |

## 11. Priorisierung der kleinen Funktionen

### P0 — blockiert jede Kundenfreigabe

- sämtliche `instabil` markierten Tenant-/Ownership-Pfade,
- Testdatenbank-Hard-Guard,
- sichere Rezept-, Gerichts-, Konzept- und Foodbook-Speicherpfade,
- Deklarationsfreigabe und Export-Gate,
- Import-Vorrang und Preisaktualität,
- sichtbare Fehler statt leerer Catch-Pfade.

### P1 — blockiert G1 beziehungsweise M-22

- Deep-Link/Reload in der Golden-Path-Strecke,
- alle kleinen Foodbook-Funktionen FB-01 bis FB-15,
- realer Fremdkatalog und unbepreiste Artikel,
- korrekter Recompute über Rezept und Gericht,
- Browserabnahme von Konzept/Foodbook/PDF,
- Nicht-KI-Fallback des Kernpfads.

### P2 — nach G1, vor Skalierung

- mobile Bedienung,
- große Sidebar-/Listenmengen,
- Speiseplan-Barrierefreiheit,
- echter Spend, Feedback und Benchmark,
- Onboarding-Fortschritt, Rollenmatrix und Betriebsrunbooks,
- Trend-Kontextualisierung und Enterprise-Funktionen.

## 12. Abnahmepakete statt Einzeltest-Chaos

Die Funktionen werden nicht nur isoliert, sondern in zehn zusammenhängenden
Business-Szenarien abgenommen:

| Paket | Szenario | Enthaltene IDs | Pflicht-Evidenz |
|---|---|---|---|
| BC-01 | neuer Kunde und sichere Vererbung | AD-01–04, ST-01–05 | `T+B` |
| BC-02 | realer Lieferantenkatalog bis bepreistes GP | ST-06–25 | `F+T+B+R+P` |
| BC-03 | Basisrezept von Anlage bis Freigabe | RE-01–11, QL-01–04 | `F+T+B+M` |
| BC-04 | Gericht von Rezeptur bis Ziel-VK | RE-12–24, WI-01–05, FD-01–02 | `F+T+B+R` |
| BC-05 | Brief bis vollständiges Konzept | CO-01–11, PA-01–03 | `F+T+B+R` |
| BC-06 | Kunden-Foodbook bis PDF | FB-01–17, FD-03–04, C-Gate | `F+T+B+R` |
| BC-07 | Foodbook bis Produktion und Bestellung | PR-01–11, OR-01–06 | `F+T+B+R` |
| BC-08 | Preisänderung bis Kundenwirkung | ST-10–14, WI-01–07, FB-13 | `F+B+R+P` |
| BC-09 | Wissen/KI/Pairing mit und ohne Provider | KN-01–06, AI-01–05, PA-01–06 | `F+T+B+R` |
| BC-10 | Trend bis bepreistes Konzept | TR-01–04, ST-11–12 | `F+B+R` |
| BC-11 | skalierbare Menü-Assemblierung | WI-09–12, SOL-01–10 | `U+F+B+R+P` |
| BC-12 | semantischer Recall am Katalogvolumen | KN-05, ST-16–20, VEC-01–14 | `F+T+R+P` |

### Vorhandene automatisierte Evidenz und ihre Grenze

Die folgenden Tests sind wichtige Bausteine, decken aber nicht automatisch das
gesamte Business-Szenario ab:

| Paket | vorhandene automatisierte Evidenz, Auswahl | Entscheidende Lücke |
|---|---|---|
| BC-01 | [`TeamOnboardingTest`](../../tests/Feature/TeamOnboardingTest.php), [`CurateGateTest`](../../tests/Feature/CurateGateTest.php), [`GeschwisterLeakSuiteTest`](../../tests/Feature/GeschwisterLeakSuiteTest.php), [`PolicyTest`](../../tests/Feature/PolicyTest.php) | keine systematische Action-für-Action-Ownership-Matrix; bekannte Leaks bleiben |
| BC-02 | [`FileArticleImportTest`](../../tests/Feature/FileArticleImportTest.php), [`SupplierBrowserTest`](../../tests/Feature/SupplierBrowserTest.php), [`GpMappingTest`](../../tests/Feature/GpMappingTest.php), [`ActivePriceTest`](../../tests/Feature/ActivePriceTest.php) | kein vollständiger unbearbeiteter Fremdkatalog mit Konflikt- und Wiederholungslauf |
| BC-03 | [`RecipeCrudTest`](../../tests/Feature/RecipeCrudTest.php), [`IngredientEditorTest`](../../tests/Feature/IngredientEditorTest.php), [`CostYieldRecomputeTest`](../../tests/Feature/Golden/CostYieldRecomputeTest.php), [`GpAllergenBackfillTest`](../../tests/Feature/GpAllergenBackfillTest.php) | reale Browserstrecke und bekannte Kategorie-/globaler-Save-Probleme |
| BC-04 | [`SalesEditorTest`](../../tests/Feature/SalesEditorTest.php), [`DarreichungServiceTest`](../../tests/Feature/DarreichungServiceTest.php), [`MargeServiceTest`](../../tests/Feature/Golden/MargeServiceTest.php), [`RecipeOneShotWirtschaftlichkeitTest`](../../tests/Feature/RecipeOneShotWirtschaftlichkeitTest.php) | Browser-Editor, Tenant-IDs und real bepreistes Verkaufsgericht nicht end-to-end |
| BC-05 | [`ConcepterServiceTest`](../../tests/Feature/ConcepterServiceTest.php), [`ConcepterEditorTest`](../../tests/Feature/ConcepterEditorTest.php), [`CoverageTest`](../../tests/Feature/CoverageTest.php), [`ConcepterSlotVorschlagTest`](../../tests/Feature/ConcepterSlotVorschlagTest.php) | Deep-Link, atomarer Misch-Save, fremde Dimensions-IDs und echter Brief fehlen als Gesamtstrecke |
| BC-06 | [`FoodbookServiceTest`](../../tests/Feature/FoodbookServiceTest.php), [`FoodbookStrukturTest`](../../tests/Feature/FoodbookStrukturTest.php), [`FoodbookLeitstelleTest`](../../tests/Feature/FoodbookLeitstelleTest.php), [`FoodbookUiTest`](../../tests/Feature/FoodbookUiTest.php) | Kapitel-/Block-Leaks, kompletter Browserlauf, Haftungsfreigabe und realer PDF-Abgleich |
| BC-07 | [`ProductionOrderServiceTest`](../../tests/Feature/ProductionOrderServiceTest.php), [`ProduktionEditorKapitelTest`](../../tests/Feature/ProduktionEditorKapitelTest.php), [`OrderServiceTest`](../../tests/Feature/OrderServiceTest.php) | Foodbook→Produktion→Bestellung nicht als durchgängiger Browser-/Realpfad |
| BC-08 | [`PriceLifecycleTest`](../../tests/Feature/PriceLifecycleTest.php), [`LeadPreisWahrheitGoldenTest`](../../tests/Feature/LeadPreisWahrheitGoldenTest.php), [`SimulationTest`](../../tests/Feature/SimulationTest.php), [`MoneyTruthReportTest`](../../tests/Feature/MoneyTruthReportTest.php) | Preisalter und Auswirkung bis zum freigegebenen Kundendokument fehlen |
| BC-09 | [`KnowledgeBrowserTest`](../../tests/Feature/KnowledgeBrowserTest.php), [`AiGatewayTest`](../../tests/Feature/AiGatewayTest.php), [`PairingCohesionTest`](../../tests/Feature/Golden/PairingCohesionTest.php), [`McpToolsTest`](../../tests/Feature/McpToolsTest.php) | bekannte Knowledge-Tenant-Lücken; kein kompletter Lauf mit/ohne Provider und Kostenmessung |
| BC-10 | [`SignalTrendTest`](../../tests/Feature/SignalTrendTest.php), [`ConceptGeneratorTest`](../../tests/Feature/ConceptGeneratorTest.php), [`PairingInspirationTest`](../../tests/Feature/PairingInspirationTest.php) | kein realer Trend mit GP-/LA-/Preis-Vorlauf bis zum Kundenkonzept |
| BC-11 | [`MenuCandidatePoolTest`](../../tests/Feature/MenuCandidatePoolTest.php), [`MenuAssemblyTest`](../../tests/Feature/MenuAssemblyTest.php), [`MenuAssemblyErklaerungTest`](../../tests/Feature/MenuAssemblyErklaerungTest.php), [`MenuAssemblySlotSemantikTest`](../../tests/Feature/MenuAssemblySlotSemantikTest.php) | kein Branch-and-Bound-Orakelvergleich und kein Zielvolumenbenchmark bis etwa 1.000 Gerichte |
| BC-12 | [`EmbeddingServiceTest`](../../../platforms-core/tests/Feature/EmbeddingServiceTest.php), [`PoolEmbeddingTest`](../../tests/Feature/PoolEmbeddingTest.php), [`HybridRetrievalTest`](../../tests/Feature/HybridRetrievalTest.php), [`SemanticGoldenSetTest`](../../tests/Feature/SemanticGoldenSetTest.php) | kein produktiver ANN-Store, kein vollständiger LA-Pool, kein Shadow-Cutover und kein Last-/Leak-Nachweis |

### Testurteil am 28.07.2026

- Die vorhandene Suite besitzt eine breite technische Abdeckung.
- Sie beweist nicht, dass alle 148 Funktionen als Business Cases funktionieren.
- Insbesondere fehlen eine durchgehende Browser-E2E-Schicht, systematische
  Tenant-Angriffstests, reale Fremdkataloge und der M-22-Kundenfall.
- Ein grüner Pest-Lauf ist daher ein notwendiges technisches Gate, aber keine
  Produktions- oder Zielbildfreigabe.
- Die tatsächlichen Ergebnisse vollständiger Läufe werden mit Datum, Commit,
  Datenbanktyp, Testzahl, Assertions, Laufzeit und Fehlern unterhalb dieses
  Abschnitts protokolliert.

### Protokoll vollständiger Modulläufe

| Datum | Commit | Datenbank | Ergebnis | Laufzeit | Einordnung |
|---|---|---|---|---|---|
| 2026-07-28 | `59cb268` | SQLite In-Memory | 1.716 Tests; 1.712 bestanden; 4 übersprungen; 8.921 Assertions | 852,827 s | vorhandener Auditlauf; keine Browser-/Realabnahme |
| 2026-07-28 | `59cb268` + reine Dokuänderungen | SQLite In-Memory | 1.716 Tests; 1.712 bestanden; 4 übersprungen; 8.921 Assertions | 834,711 s | erneuter vollständiger Lauf bestanden; weiterhin keine Browser-/Realabnahme |

## 13. Pflege der Matrix

- Ein Statuswechsel benötigt einen Link auf Test oder Abnahmeprotokoll.
- Neue öffentliche Funktionen werden vor Implementierung als neue Zeile ergänzt.
- Entfernte Funktionen werden mit Entscheidung archiviert, nicht still gelöscht.
- Der monatliche Planungsreview prüft alle Zeilen mit `instabil` und `offen`.
- G1 darf erst auf „erfüllt“ wechseln, wenn BC-01 bis BC-06 und BC-11 für den
  realen M-22-Foodbook-Raum sowie BC-12 für M-21 bestanden sind. Der vollständige
  1.000-Gerichte-Benchmark von BC-11 ist spätestens vor G2 Pflicht.
- Ein vorhandener automatisierter Test ohne Browser- oder Realnachweis bleibt
  ausdrücklich `gebaut`, nicht `real validiert`.
