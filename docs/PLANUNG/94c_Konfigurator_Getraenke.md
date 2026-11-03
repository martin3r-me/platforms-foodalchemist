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
| Alkohol am Rezept oder Concept | **gibt es nicht**, nur am Lieferantenartikel (`supplier_items.is_alcohol`) |
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
  Das Concept steht wie bei Hauptkonzepten **je Tageszeit** in `lunch_concept_id` bzw. `dinner_concept_id` (Entscheid 4: optional).
  Ist nur eines gesetzt, gilt es für beide Tageszeiten, z. B. ein eigenes Lunch-Paket ohne Alkohol.
- Je Getränkepaket wird festgelegt:
  - `getraenk_abrechnung`: `pauschale` oder `verbrauch`
  - `getraenk_dauer`: `event` (je Gast fürs ganze Event) oder `stunde` (je Gast und Stunde, Stunden aus der Einsatzzeit) — je Paket wählbar, ohne Staffel (Entscheid 1)
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

- **Alkohol:** Neu ist eine abgeleitete Kennzeichnung „enthält Alkohol“. Sie wird nach demselben Muster wie die Allergen-Aggregation
  hochgereicht: Lieferantenartikel `is_alcohol`, dann Lead-LA des GP, dann Rezept, dann Concept. Es gibt keine Handpflege an jedem Getränk. Am Concept-Slot ist eine Überschreibung möglich, wenn ein Artikel fehlt.
- **Im Link:**
  - Gerichte mit Alkohol tragen ein Symbol bzw. den Code „Alk.“ in der Legende.
  - Ein Paket mit Alkohol zeigt den **Alkohol-Hinweis des Teams** (Entscheid 7). Das ist ein neues Feld in den Team-Einstellungen mit Standardtext
    „Enthält alkoholische Getränke. Ausschank nicht an Personen unter 16 bzw. 18 Jahren.“, je Team änderbar.
  - Gibt es im Paket alkoholfreie Positionen, steht „alkoholfreie Alternativen enthalten“ dabei. Das wird aus den Positionen ohne Alkohol abgeleitet, nicht gepflegt.
    Eine alkoholfreie Alternative ist **keine Pflicht** (Entscheid 8). Fehlt sie, warnt nur der Editor.
- **Allergene** wie bei Speisen (Codes je Gericht, Legende, `ConcepterAggregateService`), z. B. Sulfite im Wein und Gluten im Bier.

## Datenmodell (Skizze)

```
foodalchemist_konfigurator_konzepte   =  rolle: haupt | addon | getraenk (string 8, bleibt)
                                      +  getraenk_abrechnung (string 12, null): pauschale | verbrauch
                                      +  getraenk_dauer (string 8, null): event | stunde
                                      +  verbrauch_deckel (decimal 10,2, null)
foodalchemist_konfiguratoren          +  getraenke_pflicht (boolean, default false)
foodalchemist_team_settings           +  alkohol_hinweis (text, null = Standardtext)
foodalchemist_recipes                 +  enthaelt_alkohol (boolean, null = unbekannt; abgeleitet, Cache)
foodalchemist_concepts                +  enthaelt_alkohol (boolean, null; abgeleitet, Cache)
foodalchemist_concept_slots           +  alkohol_override (boolean, null)
```

Alle Migrationen sind additiv. `enthaelt_alkohol` wird beim Recompute der Rezepte bzw. Concepts mitgerechnet, wie die Allergene.

## Betroffene Services

| Stelle | Änderung |
|:--|:--|
| `KonfiguratorPricingService` | Fall `getraenk` je Tageszeit (lunch, dinner, Tag und Abend): Pauschale VK/EK pro Person (× Stunden bei `stunde`, Kombi-Rabatt bei Tag und Abend), Verbrauch als `einzel` mit Deckel |
| `KonfiguratorSnapshot` | `getraenke` (Karten, Detail, Alkohol-Hinweis aus den Team-Einstellungen), Preistabelle `g{id}:t`, `zzgl` für Verbrauch, Gruppe „Getränke“ in der Aufteilung |
| `_konfigurator_script` / Blöcke | neuer Block `konfig_getraenke`; `konfiguratorLayout` ergänzt ihn wie die anderen Pflichtblöcke |
| `KonfiguratorAnfrageService` | Auswahl `getraenk`; Pauschale im Preis, Verbrauch als `position` (`aufwand`, `art = verbrauch`) |
| Alkohol-Aggregation | Rezept- und Concept-Recompute; `ConcepterAggregateService::gerichtCodes` um „Alk.“ |
| Editor | Reiter „Getränke“ (eigenes Partial, Editor-Klasse unter der Dateigrößen-Grenze, ggf. eigenes Livewire-Kind) |
| MCP | `konfigurator_konzepte.POST/PUT` mit `rolle = getraenk` und den neuen Feldern; Preis-Tool liefert `g…` |

## Tests

- Ein Getränkepaket als Pauschale (event bzw. stunde) erhöht den Preis pro Person um den Concept-VK (× Stunden). Die Aufteilung zeigt „Getränke“.
- Verbrauch: kein Preis pro Person. Die Schätzung = Menge je Gast × Gäste × VK steht als `zzgl` im Link und als `aufwand`-Position im Angebot.
- Mehrere Pakete sind kombinierbar, die Preise addieren sich. „Ohne Getränke“ geht nur ohne `getraenke_pflicht`. Die Bindung an ein Hauptkonzept greift.
- Ein eigenes Lunch-Concept gilt mittags, sonst gilt das eine Concept für beide Tageszeiten.
- Bei Tag und Abend gilt der Kombi-Rabatt auch auf die Getränke-Pauschale (anteilig).
- Deckel: Die Schätzung über dem Deckel wird auf den Deckel begrenzt, und „höchstens … €“ steht im Link und an der Angebots-Position.
- Der Alkohol-Hinweis kommt aus den Team-Einstellungen, der Standardtext greift, wenn nichts gepflegt ist.
- Alkohol: ein Artikel mit `is_alcohol` → Rezept → Concept → Hinweis im Link. Gibt es alkoholfreie Positionen, steht „alkoholfreie Alternativen“ dabei.
- Leak-Test: kein EK und keine Mengen je Gast im Snapshot (nur die Schätzung je Paket wie in 94b).
- Wächter: wie in 94b plus Spec90 bei neuem KI-Weg (es ist keiner geplant).

## Stufen

| Stufe | Inhalt |
|:--|:--|
| 8a | Alkohol-Aggregation (Rezept, Concept, Codes) |
| 8b | `rolle = getraenk`, Preis (Pauschale), Reiter „Getränke“, Schritt im Link |
| 8c | Verbrauch auf dem Aufwands-Mechanismus, Anfrage → Angebot |

## Entscheidungen (Dominique, 03.11.2026)

1. **Pauschale:** je Paket wählbar, fürs ganze Event pro Gast oder pro Gast und Stunde (Einsatzzeit). Keine Staffel nach Dauer.
2. **Pakete:** mehrere kombinierbar, z. B. Softdrinks + Wein + Bier.
3. **Erkennung:** über die Concept-Kategorie „Getränke“ (team-pflegbar). Der Reiter filtert danach.
4. **Tageszeit:** optional ein eigenes Lunch-Concept am Paket, sonst eines für beide.
5. **Kombi-Rabatt:** gilt auch für die Getränke-Pauschale.
6. **Verbrauch:** mit optionalem Höchstbetrag (Deckel) je Paket, angezeigt im Link und im Angebot.
7. **Alkohol-Hinweis:** je Team pflegbar, mit Standardtext.
8. **Alkoholfreie Alternative:** nur Hinweis, keine Pflicht; ohne sie warnt der Editor.

## Offene Fragen

Keine.
