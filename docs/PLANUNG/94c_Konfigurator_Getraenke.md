# Spec 94c · Konfigurator: Getränke

Stand 03.11.2026 · **Alle Fragen von Dominique entschieden (03.11.2026)**. Gebaut wird nach der Abnahme durch den Kurator, vor 7c Teil 2 (Spec 94b).
Baut auf [Spec 94](94_Konfigurator_AllIn_Preise.md) und [94b](94b_Konfigurator_Planungsdetails_Einzelabrechnung.md) auf.

Leitregel wie in 94b: **nichts fest codieren, keine parallele Welt.** Getränke nutzen vorhandene Strukturen: Concepts
aus dem Concepter, Grundprodukte der Warengruppe 15 und den Aufwands-Mechanismus aus 7b/7c.

## Entscheide Dominique (03.11.2026, vorab)

- Abrechnung **als Pauschale und nach Verbrauch**, je Getränkepaket wählbar.
- Im Kundenlink ein **eigener Schritt „Getränke“**.
- Getränkepakete werden **als Concepts im Concepter** gebaut.
- Im Konfigurator-Editor ein **eigener Reiter „Getränke“**.

## Ist-Stand (Code gelesen 03.11.2026)

| Thema | Heute |
|:--|:--|
| Getränke als Ware | Grundprodukte der Warengruppe 15 (GP-Regelwerk §3), Lieferantenartikel mit `is_alcohol` |
| Alkohol am Rezept | 8a: `spec_contains_alcohol` aus der Kette Artikel → GP → Rezept. `supplier_items.is_alcohol` ist auf io flächig 0 (bei Necta „keine Angabe“), deshalb fester Wert am GP |
| Getränke in Ausgaben | Speisekarte kennt `karten_typ` getraenkekarte/weinkarte und Rubrik `art = getraenke` |
| Konfigurator-Konzepte | `rolle` haupt oder addon, Concept je Tageszeit (lunch/dinner), Preistabelle `k…`, `dn…`, `a…` |
| Aufwand | Leistungen `abrechnung = aufwand` (7b) und Angebots-Position `price_basis = aufwand` (7c) |

## 1 · Was ist ein Getränke-Concept?

**Kein neuer Typ, keine eigene Bauart.** Ein Getränkepaket ist ein normales Concept (`kind = concept`) mit
Positionen wie jedes andere:
- **Positionen** sind VK-Gerichte bzw. Rezepte, deren Zutaten Getränke-GPs der Warengruppe 15 sind, z. B. „Hauswein weiß 0,1 l“
  oder „Softdrinks 0,2 l“. Menge und Einheit je Gast stehen wie bei Speisen am Slot.
- **Preis:** EK aus den Lieferantenartikeln, VK wie bei Speisen über Basissatz und Aufschlagsklasse. Es gibt keine zweite Preislogik.
- **Erkennung:** Getränkepakete erkennt man über die vorhandene Concept-**Kategorie** (`category_id`, team-pflegbar), etwa „Getränke“,
  nicht über einen Code-Wert. Der Reiter „Getränke“ im Editor filtert danach. Ist keine Kategorie gesetzt, kann man trotzdem
  jedes Concept wählen.

Damit bleiben Concepter, Wording, Bilder, Allergene, Detail-Sheet und Kalkulation dieselben wie bei Speisen.

## 2 · Getränke im Konfigurator

- Neue `rolle = getraenk` an `foodalchemist_konfigurator_konzepte`. Es gibt keine neue Tabelle, weil die Rolle-Spalte das schon trägt.
  Das Concept steht wie bei Hauptkonzepten **je Zeitfenster** in `lunch_concept_id` (tagsüber) bzw. `dinner_concept_id` (abends).
  **Je Zeitfenster ein eigenes Paket** (Dominique 03.11.2026, korrigiert Entscheid 4): Ein Paket mit nur einem Concept gilt nur in seinem Zeitfenster,
  bei „Tag und Abend“ zählen Tag-Teil + Abend-Teil.
- Je Getränkepaket wird festgelegt:
  - `getraenk_abrechnung`: `pauschale` oder `verbrauch`
  - `getraenk_dauer`: `event` (je Gast fürs ganze Event) oder `stunde` (je Gast und Stunde, Stunden aus der Einsatzzeit) — je Paket wählbar (Entscheid 1)
  - **optional Staffel:** `grund_stunden` und `folgestunde_satz` (VK je Gast und weitere Stunde). Der Concept-Preis gilt dann als Grundpreis für
    `grund_stunden`, jede weitere Stunde der Einsatzzeit kostet `folgestunde_satz` (z. B. 4 Std. 18 €, jede weitere 3,50 €).
    Ohne `folgestunde_satz` gilt `getraenk_dauer` wie oben.
  - `verbrauch_deckel` (nur bei Verbrauch, optional): Höchstbetrag für das Event (Entscheid 6)
  - Bindung an ein Hauptkonzept wie bei Add-ons (`gebunden_an_id`)
- Am Konfigurator steht `getraenke_pflicht`: mindestens ein Paket muss gewählt werden, sonst ist „ohne Getränke“ erlaubt.
- **Mehrere Pakete sind kombinierbar** (Entscheid 2), z. B. Softdrinks + Wein + Bier. Jedes gewählte Paket kommt dazu.
- **Pauschale** zählt in den All-in-Preis pro Person. Die Preisschlüssel stehen je Paket und Tageszeit in der Preistabelle:
  `g{id}:lunch`, `g{id}:dinner`, `g{id}:dn`.
  - Bei „Tag und Abend“ ist das Tag-Concept + Abend-Concept, bei `stunde` mit der Day-into-Night-Einsatzzeit.
  - Der **Kombi-Rabatt gilt auch für die Getränke-Pauschale** (Entscheid 5), anteilig wie bei den Konzept-Zeilen (94b §5).
- **Nach Verbrauch** baut **auf dem Aufwands-Mechanismus** auf (7b/7c) und hat keine zweite Logik:
  - Die Schätzung je Paket ist die Menge je Gast aus dem Concept × Gäste der Preisstufe (× Stunden bei `stunde`) × VK-Satz der Positionen.
  - Im Link erscheint sie als `zzgl`-Zeile („zzgl. nach Verbrauch: ca. … ≈ … €“), außerhalb der Summe, im Snapshot eingefroren.
  - Mit `verbrauch_deckel` steht dabei „höchstens … €“. Die Schätzung wird auf den Deckel begrenzt angezeigt, und im Angebot
    trägt die Position den Deckel (`payload_json.deckel`). Bei der Abrechnung nach dem Event gilt min(tatsächlich, Deckel).
  - Im Angebot aus der Anfrage wird sie eine `position` mit `price_basis = aufwand`. Die Bezeichnung lautet „nach Verbrauch“ statt „nach Aufwand“ und kommt aus `payload_json.art = verbrauch`. Es gibt keinen neuen Rechenweg.
- **Leistungen zum Getränk** (Barkeeper, Gläser, Kühlung) hängen wie bei Add-ons als Zusatz-Leistungen am Getränke-Konzept
  (`konfigurator_konzept_id`). Wird das Paket gewählt, zählen sie mit.

## 3 · Editor: Reiter „Getränke“

- Eigener Reiter neben Konzepte und Leistungen. Er sucht Concepts (gefiltert auf die Kategorie „Getränke“, falls gesetzt) und fügt sie als Getränkepaket hinzu.
- Je Paket: Titel und Claim für den Kunden, Abrechnung (Pauschale oder Verbrauch), Dauer (Event oder je Stunde), Pflicht oder optional, Bindung, Bild.
- Dazu Gerichte und Wording im Kundenlink mit KI-Wording, dieselbe Oberfläche wie bei Konzepten und Extras (Partial `konfigurator/wording`).
- Die Kalkulation zeigt die Gruppe „Getränke“ in der Zusammenfassung (94b §5) mit EK, VK und DB. Verbrauch steht im Block Einzelabrechnung.

## 4 · Kundenlink: Schritt „Getränke“

- Eigener Schritt nach „Konzept“ und vor „Extras“. Er erscheint nur, wenn es Getränkepakete gibt.
- Karten wie bei Konzepten: Bild, Claim, Preis, „Details“ öffnet das Speisen-Detail (`_konfigurator_detail`) mit den Getränken.
  - Pauschale: „+ 18,00 € pro Person“ bzw. „+ 4,50 € pro Person und Stunde“
  - Verbrauch: „nach Verbrauch, ca. 1.400 € bei 150 Gästen“
- Wahl: **mehrere Pakete kombinierbar** (Haken wie bei Extras). „Ohne Getränke“ geht, wenn `getraenke_pflicht` aus ist. Bindung an ein Hauptkonzept wie bei Extras.
- Preisleiste und Zusammenstellung: Pauschale im Preis pro Person, die Aufteilung (94b §5) bekommt eine Zeile „Getränke“. Verbrauch steht in „zzgl.“.

## 5 · Alkohol und Allergene

- **Alkohol bei Gerichten** (8a, Quelle korrigiert 03.11.2026; Dominique: für Kita sehr hilfreich): Die Kette aus 8a bleibt — `GpAggregateService::enthaeltAlkohol`
  → Recompute `spec_contains_alcohol` (Warn-Regel, auch über Sub-Rezepte) → Code „Alk.“ in `gerichtCodes` und Legende, Badge an der Rezept-Deklaration.
  - **Quelle:** fester Wert am GP, `gps.alkohol` (ja / nein / NULL = offen) mit Herkunft `alkohol_source` (manuell | abgeleitet), **für alle Warengruppen**.
    Er hat Vorrang vor den Artikeln. Ohne ihn zählt nur ein sichtbarer Artikel mit `is_alcohol = 1` als ja; `is_alcohol = 0` heißt bei Necta „keine Angabe“
    und gilt als NULL. Aus den Artikeln wird nie ein nein.
  - **Rezept:** „ohne Alkohol“ nur, wenn alle Zutaten ein echtes nein haben, sonst NULL. Für Kita erscheint „unbekannt“ nie als alkoholfrei.
  - **Pflege:** im GP-Modal (Feld „Alkohol“), über `gps.POST/PUT` (`alkohol = ja | nein | offen`), `gps.GET` liefert Wert und Herkunft. Jede Pflege setzt `manuell`.
    Eine GP-Änderung stößt keinen Recompute an; der läuft mit Ansage.
  - **Einmalige Vorbelegung:** `php artisan foodalchemist:alkohol-vorbelegen` als getrennter Lauf, nur auf Ansage und mit Backup, danach Recompute mit Ansage.
    Probelauf mit Zählung als Standard, `--apply` schreibt (Herkunft `abgeleitet`, manuell gepflegte Werte bleiben), `--offen` listet die offenen Getränke.
    Geschrieben wird **nur WG 15**, nach `Support/AlkoholAngabe::ausGp`, das Erste, was zutrifft:
    1. vol-% in Pflichtangabe bzw. Name (tolerant gegen „Vol%“, „vol.-%“; „vol“ nur mit Zahl) oder „alkoholfrei“: ≥ 0,5 = ja, sonst nein (alkoholfreies Bier in 15.2 = nein).
    2. Unterkategorie 15.5 Wasser, 15.6 Säfte, 15.7 Erfrischungsgetränke, 15.8 Heißgetränke, 15.9 Sirupe = nein.
    3. Unterkategorie 15.1 Wein, 15.3 Spirituosen und Liköre = ja, auch ohne vol-% (Kochwein).
    4. Sonst offen (z. B. Bier ohne vol-%).
  - `--vorschlaege` listet Koch-Alkoholika **außerhalb WG 15** ohne Wert (Kochwein WG 10, Mirin, Rum-Aroma …) mit Rezept-Zahl, als Vorschlag zur Freigabe durch
    Dominique. Der Befehl schreibt sie nie; freigegeben wird im Modal bzw. über `gps.PUT`.
- **Getränke im Konfigurator: kein Alkohol-Teil** (Dominique 03.11.2026). Es gibt keinen Alkohol-Hinweis im Link, kein „alkoholfreie Alternativen“ und kein
  `team_settings.alkohol_hinweis`. Die Entscheide 7 und 8 sind damit gestrichen.
- **Allergene** wie bei Speisen (Codes je Gericht, Legende, `ConcepterAggregateService`), z. B. Sulfite im Wein und Gluten im Bier.

## Datenmodell (Skizze)

```
foodalchemist_konfigurator_konzepte   =  rolle: haupt | addon | getraenk (string 8, bleibt)
                                      +  getraenk_abrechnung (string 12, null): pauschale | verbrauch
                                      +  getraenk_dauer (string 8, null): event | stunde
                                      +  grund_stunden (decimal 5,2, null), folgestunde_satz (decimal 10,2, null)
                                      +  verbrauch_deckel (decimal 10,2, null)
foodalchemist_konfiguratoren          +  getraenke_pflicht (boolean, default false)
foodalchemist_gps                     +  alkohol (boolean, null = offen; alle WG), alkohol_source (manuell | abgeleitet)   [8a, Korrektur]
foodalchemist_recipes                    spec_contains_alcohol (aus 8a, bleibt)
```

Alle Migrationen sind additiv. `spec_contains_alcohol` wird beim Recompute der Rezepte mitgerechnet. Den Bestand füllt erst ein Recompute-Lauf, nur mit Ansage.

**Wo was gepflegt wird** (Dominique): Im Concepter steht nur der Inhalt des Getränkepakets (Positionen, Mengen je Gast, Wording, Bilder).
Alles Kommerzielle hängt an der **Konfigurator-Konzept-Zeile** (`rolle = getraenk`) und wird im Reiter „Getränke“ eingestellt:
Abrechnung, Dauer, Grundstunden und Folgestunde, Verbrauchsdeckel, Lunch- und Dinner-Zuordnung, Bindung. `getraenke_pflicht` steht am Konfigurator.

## Betroffene Services

| Stelle | Änderung |
|:--|:--|
| `KonfiguratorPricingService` | Fall `getraenk` je Tageszeit (lunch, dinner, Tag und Abend): Pauschale VK/EK pro Person (× Stunden bei `stunde`, Kombi-Rabatt bei Tag und Abend), Verbrauch als `einzel` mit Deckel |
| `KonfiguratorSnapshot` | `getraenke` (Karten, Detail), Preistabelle `g{id}:t`, `zzgl` für Verbrauch, Gruppe „Getränke“ in der Aufteilung |
| `_konfigurator_script` / Blöcke | neuer Block `konfig_getraenke`; `konfiguratorLayout` ergänzt ihn wie die anderen Pflichtblöcke |
| `KonfiguratorAnfrageService` | Auswahl `getraenk`; Pauschale im Preis, Verbrauch als `position` (`aufwand`, `art = verbrauch`) |
| Alkohol (8a, Quelle korrigiert) | `gps.alkohol` mit Vorrang in `GpAggregateService::enthaeltAlkohol`, GP-Modal, `gps.POST/PUT/GET`, Befehl `foodalchemist:alkohol-vorbelegen` |
| Editor | Reiter „Getränke“ (eigenes Partial, Editor-Klasse unter der Dateigrößen-Grenze, ggf. eigenes Livewire-Kind) |
| MCP | `konfigurator_konzepte.POST/PUT` mit `rolle = getraenk` und den neuen Feldern; Preis-Tool liefert `g…` |

## Tests

- Ein Getränkepaket als Pauschale (event bzw. stunde) erhöht den Preis pro Person um den Concept-VK (× Stunden). Die Aufteilung zeigt „Getränke“.
- Verbrauch: kein Preis pro Person. Die Schätzung = Menge je Gast × Gäste × VK steht als `zzgl` im Link und als `aufwand`-Position im Angebot.
- Staffel: 4 Std. Grundpreis 18 € plus 3,50 € je Folgestunde ergibt bei 6 Std. Einsatzzeit 25 € pro Person. Ohne Folgestunden-Satz gilt der Concept-Preis bzw. Preis × Stunden.
- Mehrere Pakete sind kombinierbar, die Preise addieren sich. „Ohne Getränke“ geht nur ohne `getraenke_pflicht`. Die Bindung an ein Hauptkonzept greift.
- Je Zeitfenster ein eigenes Paket: Das Lunch-Concept gilt tagsüber, das Dinner-Concept abends, Tag und Abend = beide Teile. Ohne Concept zum Zeitfenster ist das Paket dort nicht wählbar.
- Bei Tag und Abend gilt der Kombi-Rabatt auch auf die Getränke-Pauschale (anteilig).
- Deckel: Die Schätzung über dem Deckel wird auf den Deckel begrenzt, und „höchstens … €“ steht im Link und an der Angebots-Position.
- Alkohol (8a): GP-Wert schlägt Artikel, `is_alcohol = 0` = NULL; Rezept nein nur bei echtem nein aller Zutaten. Vorbelegung: Coca-Cola (15.7) nein, Kochwein (15.1) ja, alkoholfreies Bier 0,0 vol-% (15.2) nein, Bier ohne vol-% offen, außerhalb WG 15 nie geschrieben (nur Vorschlag); manuell bleibt.
- Leak-Test: kein EK und keine Mengen je Gast im Snapshot (nur die Schätzung je Paket wie in 94b).
- Wächter: wie in 94b plus Spec90 bei neuem KI-Weg (es ist keiner geplant).

## Stufen

| Stufe | Inhalt |
|:--|:--|
| 8a | Alkohol-Kennzeichnung Artikel → GP → Rezept, Codes — umgesetzt 03.11.2026; Quelle korrigiert: fester GP-Wert mit Vorrang, Vorbelegung als Probelauf |
| 8b | `rolle = getraenk`, Preis (Pauschale), Reiter „Getränke“, Schritt im Link — umgesetzt 03.11.2026 (Umsetzungsnotizen unten) |
| 8c | Verbrauch auf dem Aufwands-Mechanismus, Anfrage → Angebot |

## Entscheidungen (Dominique, 03.11.2026)

1. **Pauschale:** je Paket wählbar, fürs ganze Event pro Gast oder pro Gast und Stunde (Einsatzzeit). Dazu **optional** eine Staffel:
   Grundpreis für X Stunden plus Satz je Folgestunde (Antwort an den Kurator, „Grundpreis + Folgestunde“). Ohne Folgestunden-Satz gilt die einfache Pauschale.
2. **Pakete:** mehrere kombinierbar, z. B. Softdrinks + Wein + Bier.
3. **Erkennung:** über die Concept-Kategorie „Getränke“ (team-pflegbar). Der Reiter filtert danach.
4. **Tageszeit:** je Zeitfenster ein eigenes Paket (korrigiert 03.11.2026: kein „eines für beide“). Tag und Abend = Tag-Paket + Abend-Paket.
5. **Kombi-Rabatt:** gilt auch für die Getränke-Pauschale.
6. **Verbrauch:** mit optionalem Höchstbetrag (Deckel) je Paket, angezeigt im Link und im Angebot.
7. ~~Alkohol-Hinweis~~ — gestrichen (Dominique 03.11.2026: Getränke im Konfigurator ohne Alkohol-Teil).
8. ~~Alkoholfreie Alternative~~ — gestrichen (ebenso).

## Offene Fragen

Keine.

## Umsetzungsnotizen 8b (03.11.2026)

- **Je Zeitfenster ein eigenes Paket** (Dominique 03.11.2026, per Auswahl):
  - Das Lunch-Concept gilt tagsüber, das Dinner-Concept abends. Es gibt keinen Rückfall auf das andere Zeitfenster. Ein Paket nur mit Dinner-Concept ist tagsüber nicht wählbar.
  - Tag und Abend = Tag-Teil + Abend-Teil, jeweils mit seiner Einsatzzeit (je Event, je Stunde oder Staffel). Ein fehlender Teil zählt nicht.
  - Im Editor wählt man beim Hinzufügen „Für Abend / Tag“ (`concept_id` im MCP = abends), das andere Zeitfenster kommt über Bearbeiten.
  - Danach gilt der Kombi-Rabatt wie bei den Konzepten (auch auf die Zusatz-Leistungen des Pakets).
- **Staffel-EK nach Verbrauch** (Dominique 03.11.2026): EK je Folgestunde = Grund-EK / Grundstunden, also Grund-EK + Folgestunden × Grund-EK / Grundstunden.
  Beispiel: 6 € für 4 Std., 2 Folgestunden ergeben 9 € EK. Es gibt kein eigenes EK-Feld. Enthalten die Folgestunden anderes als das Grundpaket, wird dafür ein eigenes Paket gebaut.
- **Verbrauch:** Er ist im Datenmodell vorgesehen (`getraenk_abrechnung`), aber bis 8c gesperrt. Die Prüfung meldet „folgt mit Stufe 8c“.
- **Preistabelle und Aufteilung:**
  - Schlüssel `g{id}:lunch`, `g{id}:dinner`, `g{id}:dn`. Fehlt der Concept-Preis, fehlt der Schlüssel, und das Paket ist zu dieser Tageszeit nicht wählbar.
  - Die Aufteilung bekommt die Zeile „Getränke“, die Kalkulation die Fälle „Getränke … Lunch / Dinner / Day-into-Night“.
- **Layout:** Ein eigenes Design ohne `konfig_getraenke` bekommt den Block vor den Extras (`konfiguratorLayout` setzt neue Blöcke vor den nächsten vorhandenen).
- **Editor:**
  - Der Reiter „Getränke“ zeigt die Concepts der Kategorie „Getränke“ samt Unterkategorien auch ohne Suche. Ohne diese Kategorie sucht er ab 2 Zeichen über alle Concepts.
  - „Getränke Pflicht“ steht im Reiter.
- **Druckfassung:** In „Eure Auswahl“ stehen die gewählten Pakete, dazu kommt ein eigener Abschnitt „Getränke“ zwischen Speisen und Extras. Je Paket zeigt er die Getränke des gewählten Zeitfensters, bei Tag und Abend beide.

