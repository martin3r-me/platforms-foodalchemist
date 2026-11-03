# Spec 94b · Konfigurator Stufe 7: Planungsdetails, Einzelabrechnung, Zusammenfassung

Stand 03.11.2026 · **Entwurf, wartet auf Dominiques Abnahme** (Frage 1 entschieden: Planung später in OLIVIA). Gebaut wird erst danach.
Baut auf [Spec 94](94_Konfigurator_AllIn_Preise.md) auf (Stufen 1–6 und 5b live).

**Leitregel (Dominique): Nichts wird fest codiert.** Was eine Leistung ausmacht, ergibt sich aus dem, was an der Vorlage
bzw. Leistung hinterlegt ist. Das gilt für die Kategorien, die Planungsangaben, die Abrechnungsart und die Gruppen der
Zusammenfassung. Es gibt keine festen Feldlisten je Kategorie und keinen eigenen Parallel-Katalog.

## Warum

Ein Konfigurator rechnet heute einen All-in-Preis pro Person. Drei Dinge fehlen, damit daraus ein planbares und
abrechenbares Event wird:

1. **Planung:** Was steckt hinter „1 Servicekraft je 30 Gäste“ oder „Buffet-Ausstattung“? Welche Teile, welche Rolle,
   welche Schicht? Das steht heute nur im Kopf oder in Excel.
2. **Einzelabrechnung:** Manches gehört nicht in den Preis pro Person, z. B. Garderobe nach Aufwand, Überstunden
   oder Sonderlogistik. Heute gibt es nur „alles im All-in“.
3. **Überblick:** Der Reiter Kalkulation zeigt je Paket All-in, Speisen, Leistungen, EK und DB. Wohin das Geld je
   Kategorie geht, sieht man nicht.

## Ist-Stand (Code gelesen 03.11.2026)

| Thema | Heute | Beleg |
|:--|:--|:--|
| Kategorien der Leistungen | fest im Code: `personal`, `equipment`, `logistik`, `sonstiges` (**vier** Werte, nicht drei) | `Models/Concerns/HatLeistungsRegel::KATEGORIEN` |
| Kategorie in Preis und Snapshot | wird nicht gelesen | `KonfiguratorPricingService`, `KonfiguratorSnapshot` |
| Werte je Leistung bzw. Kategorie | `matrix()` liefert nur Summen je Fall (`leistungen_vk_pp`, `ek_pp` gesamt) | `KonfiguratorPricingService::matrix` |
| Geschirr-Katalog | **zurückgebaut**; Geschirr ist ein Non-Food-Grundprodukt (WG 16), Gefäß an der Darreichung (`vessel_gp_id`), Servier-Typ `serving_vehicle_vocab_id` | `2026_11_08_100000_drop_geschirr_db`, `FoodAlchemistGp::istNonFood` |
| Behälter | Vokabular `foodalchemist_vocab_containers`; Bedarf **je Rezept und kg**, nicht je Gast | `BehaelterBedarfService`, `behaelter_bedarf.GET` |
| Koch-Equipment | Vokabular `foodalchemist_vocab_kitchen_equipment` und Bestand im Teamprofil | `FoodAlchemistTeamProfileEquipment` |
| Anfrage → Angebot | legt nur den Kopf an (Name, Gäste, Datum, Ort, Text, Gesamtpreis, `konfiguration_json`), **keine Positionen** | `KonfiguratorAnfrageService::anfragen` |
| Freie Angebotsposition | gibt es nicht; am nächsten kommt `header_preis` (Preis je Person oder pauschal, **ohne Menge, EK = 0**) | `FoodAlchemistOfferBlock::BLOCK_TYPES` |
| Leistungen im Kundenlink | nur im Preis pro Person; der Snapshot ist eine Allowlist ohne Leistungszeilen (Test sichert das ab) | `Spec94KundenlinkTest` |
| Vokabular-Muster | team-pflegbar mit Vererbung: `team_id` nullable, `slug`, `name`, `sort_order`, `is_inactive`, `unique(team_id, slug)`, `BelongsToTeamHierarchy` | `FoodAlchemistVocabEinheit` + `VocabularyService` + `Settings/Einheiten` |

## 1 · Kleinfehler (schon erledigt)

„1 je 1 Gäste“ heißt jetzt „1 je Gast“ (Singular bei n = 1, Bezug auf eine Leistung: „1 je „Servicekraft““). Das steckt in
PR #717 und ist nicht Teil dieser Stufe.

## 2 · Kategorien als Vokabular

Die Kategorien werden ein team-pflegbares Vokabular nach dem Muster der Einheiten.

- Neue Tabelle `foodalchemist_vocab_leistungskategorien` mit den Spalten `id`, `uuid`, `team_id` (nullable = global), `slug`, `name`,
  `sort_order`, `is_inactive`, timestamps und softDeletes. Dazu `unique(team_id, slug)`.
  Das Model `FoodAlchemistVocabLeistungskategorie` nutzt `BelongsToTeamHierarchy` und `LogsActivity`.
- **Migration der vorhandenen vier Werte:** Sie werden als globale Zeilen angelegt (`team_id` NULL), mit denselben Slugs
  `personal`, `equipment`, `logistik`, `sonstiges`. Die Spalte `kategorie` an Vorlagen und Leistungen bleibt und hält
  weiterhin den Slug. Bestandsdaten müssen deshalb nicht umgeschrieben werden. Die Migration ist additiv.
- Die Prüfung in `LeistungsRegelPruefung` lautet nicht mehr „Schlüssel der Konstante“, sondern „aktive Kategorie, die das Team
  sieht“. Die Konstante `KATEGORIEN` entfällt. Ihre Nutzer (Select, Filter, Gruppen, MCP-Enum) lesen künftig das Vokabular.
- Pflege unter **Einstellungen → Leistungskategorien** über `VocabularyService` und eine eigene Settings-Komponente wie bei den
  Einheiten: anlegen, umbenennen, sortieren, deaktivieren. Ein Unterteam ergänzt eigene Kategorien; geerbte kann es nur
  lesen (`isOwnedBy`). Deaktivierte Kategorien bleiben an bestehenden Leistungen lesbar, sind für neue aber nicht wählbar.
- MCP: Das Enum im `regelSchema()` wird zu `string` mit Prüfung im Service (ohne Union-Typ). Neu ist
  `leistungskategorien.GET`, das die sichtbaren, aktiven Kategorien liefert.

## 3 · Planungsdetails je Vorlage und Leistung

Frei pflegbar, ohne feste Feldliste. Neues JSON-Feld `planung_json` an `foodalchemist_leistung_vorlagen` und
`foodalchemist_konfigurator_leistungen`:

```json
{
  "version": 1,
  "komponenten": [
    {"bezeichnung": "Stehtisch", "menge": 1, "je": "einheit", "gp_id": 812},
    {"bezeichnung": "Hussen weiß", "menge": 1, "je": "einheit", "gp_id": null},
    {"bezeichnung": "GN 1/1 65 mm", "menge": 2, "je": "einheit", "container_vocab_id": 14}
  ],
  "attribute": [
    {"schluessel": "Rolle", "wert": "Service"},
    {"schluessel": "Schicht", "wert": "Abend"},
    {"schluessel": "Kapazität", "wert": "8", "einheit": "Gäste"}
  ],
  "notiz_intern": "Hussen in der Waschstraße vorbestellen."
}
```

- **Komponenten:** Stückliste mit Menge je Einheit der Leistung (`je`: `einheit` | `gast` | `stunde`).
  Wo möglich, gibt es einen Bezug auf den **vorhandenen** Bestand, nämlich ein Non-Food-Grundprodukt (WG 16, Geschirr und Equipment) über
  `gp_id` oder einen Behälter aus `foodalchemist_vocab_containers` über `container_vocab_id`. Freitext ist erlaubt, wenn es
  keinen Bestand gibt. Es entsteht **kein eigener Katalog**.
- **Attribute:** frei definierbare Schlüssel-Wert-Paare, optional mit Einheit. Es gibt keine feste Liste. Die Eingabe schlägt Schlüssel
  vor, die das Team schon benutzt hat (z. B. „Rolle“, „Schicht“, „Kapazität“).
- **Interne Notiz:** erscheint nie im Kundenlink, nicht im Snapshot und nicht in Kunden-PDFs.
- Prüfung in `LeistungsRegelPruefung` (eine Stelle für Oberfläche und MCP), wie schon bei `stufen_json`:
  Liste bereinigen, `gp_id` muss ein sichtbares Non-Food-GP sein, `container_vocab_id` ein sichtbarer Behälter.
- `version` hält die Struktur stabil für die spätere Übergabe an OLIVIA (siehe unten). Ändert sich das Schema, steigt die
  Version, und alte Stände bleiben lesbar.
- Das Feld wird beim Übernehmen aus der Vorlage mitkopiert und ist Teil des Abgleichs „Vorlage geändert“.
  Es kommt **nicht** in die Snapshot-Allowlist. Ein Test sichert das ab, wie bei EK und Mengen.

### Wer die Planungsdetails liest

**Entscheid Dominique (03.11.2026): Die Event-Planung übernimmt später OLIVIA, das eigene Event-Modul (Venture OLIVIA).**
Der Food Alchemist baut deshalb keine eigene Planungsoberfläche und keine Packliste. Er pflegt `planung_json` als saubere,
stabile Übergabe-Struktur. Am Angebot genügt vorerst eine schlichte Lese-Ansicht.

- Sobald aus einer Anfrage ein Angebot wird, rechnet `KonfiguratorPlanungService::bedarf($konfigurator, $auswahl, $pax)`
  den Bedarf aus der vorhandenen Mengenlogik (`KonfiguratorMengenRechner` bzw. Paket-Mengen) mal den Komponenten. Das Ergebnis
  wird am Angebot eingefroren, in `konfiguration_json.planung`, im Format der Übergabe (siehe unten).
- **Lese-Ansicht am Angebot:** eine schlichte Liste je Leistung (Anzahl, Komponenten mit Menge, Attribute, interne Notiz),
  ohne Bearbeitung, ohne Summen über Leistungen hinweg, ohne Abgleich mit dem Bestand und ohne Druck.
- Die Speisen-Behälter bleiben **unverändert** in der Produktion (`BehaelterBedarfService`, je Rezept und kg). Sie sind
  nicht Teil von `planung_json`.

## Schnittstelle zu OLIVIA (später)

Nur als Rahmen festgehalten, **ohne Code in dieser Stufe**. Sie dient dazu, dass `planung_json` heute so gebaut wird, dass
OLIVIA es später ohne Umbau übernehmen kann.

- **Richtung:** FA liefert, OLIVIA plant. FA bleibt die Quelle für Leistungen, Mengenregeln, Preise und Komponenten,
  OLIVIA für Personal-Einsatz, Equipment-Disposition und Logistik am konkreten Event.
- **Einheit der Übergabe:** ein Angebot (aus einer Konfigurator-Anfrage) mit seinem eingefrorenen Bedarf, nicht der Konfigurator
  selbst. Damit ist es der Stand, den der Kunde angefragt hat.
- **Inhalt je Leistung:** stabile ID (`uuid` der Konfigurator-Leistung), `vorlage_uuid` (falls aus Vorlage), Kategorie-Slug,
  Bezeichnung, Anzahl nach Regel bzw. Hand, Stunden (bei Preis je Stunde), Abrechnungsart, dazu `planung_json` unverändert
  (`version`, `komponenten`, `attribute`, `notiz_intern`).
- **Verweise:** Komponenten verweisen über `uuid` der Non-Food-GPs bzw. Behälter, nicht über interne Zahl-IDs. FA gibt
  dafür beim Einfrieren die `uuid` mit aus.
- **Kopf:** Angebot-`uuid`, Team, Datum, Ort, Gäste, gewählte Konzepte (Concept-`uuid`) und Tageszeit.
- **Weg:** offen. Möglich wären MCP-Tool, Webhook oder Abruf durch OLIVIA. Das entscheidet sich mit OLIVIA, FA legt sich
  hier nicht fest.
- **Was FA nicht tut:** keinen Event-Kalender, keine Personal-Disposition, keinen Equipment-Bestand je Event, keine
  Rückmeldung von OLIVIA verarbeiten. Alles das bleibt in OLIVIA.

## 4 · Einzelabrechnung

Je Vorlage bzw. Leistung gibt es den Schalter **„im All-in“** (Standard) oder **„Einzelabrechnung (nach Aufwand)“**.

- Neue Spalten: `abrechnung` (string 12, `all_in` | `aufwand`, Standard `all_in`) und `abrechnung_einheit_vocab_id`
  (nullable, aus `foodalchemist_vocab_units`, also ebenfalls team-pflegbar).
- **Satz** ist der VK-Satz der Leistung. Er entsteht wie heute aus EK, Basissatz und Aufschlagsklasse oder wird fest mit Begründung gesetzt.
  Eine eigene Satz-Logik gibt es nicht (siehe offene Frage 3).
- **Preis:** `KonfiguratorPricingService::rechneFall` lässt Aufwands-Leistungen aus `vk_pp` und `ek_pp` heraus. Er gibt sie
  getrennt zurück als `einzel: [{leistung_id, bezeichnung, satz, einheit, menge_geschaetzt}]`. Die geschätzte Menge nach
  Regel dient der internen Zusammenfassung.
- **Kundenlink:** eine Zeile „zzgl. nach Aufwand“ je Leistung mit Bezeichnung, Satz und Einheit,
  z. B. „Garderobe · 2,50 € je Gast“. Die Snapshot-Allowlist bekommt dafür `zzgl: [{titel, satz, einheit}]`. Mengen, EK
  und Aufschlagsklasse bleiben draußen.
- **Angebot aus der Anfrage:** Aufwands-Leistungen werden eigene Positionen mit Menge, Einheit und Satz. Dafür braucht
  das Angebot eine Position mit Menge und EK, die es heute nicht gibt (siehe offene Frage 4).

## 5 · Zusammenfassung im Reiter Kalkulation

Je Paket und Fall gibt es eine aufklappbare Zusammenfassung mit EK, VK und DB pro Person und gesamt, gruppiert nach:

1. **Speisen** (eigene Gruppe aus den Concepts, wie heute „davon Speisen“).
2. **Je Kategorie, die in diesem Konfigurator bzw. Paket tatsächlich vorkommt.** Die Gruppen ergeben sich aus den Leistungen
   und dem Vokabular. Eine feste Liste gibt es nicht, und leere Kategorien erscheinen nicht.
3. **Einzelabrechnung** als eigener Block unter dem All-in: Satz, Einheit, geschätzte Menge nach Regel und
   geschätzter Betrag. Ausdrücklich nicht im Preis pro Person.

Technisch erweitert `matrix()` jeden Fall um `gruppen: [{art: speisen|kategorie, slug, name, ek_pp, vk_pp, db_pp}]` und
`einzel: [...]`. Die Werte kommen aus der vorhandenen Posten-Schleife in `rechneFall`. Gerechnet wird weiterhin nur
dort, die Oberfläche summiert nichts selbst.

## Datenmodell (Skizze)

```
foodalchemist_vocab_leistungskategorien   NEU   id, uuid, team_id?, slug, name, sort_order, is_inactive, ts, deleted_at
                                                unique(team_id, slug); Seed global: personal, equipment, logistik, sonstiges

foodalchemist_leistung_vorlagen           +     planung_json (json, null)
                                          +     abrechnung (string 12, default all_in)
                                          +     abrechnung_einheit_vocab_id (bigint, null)
                                          =     kategorie (string 16) bleibt: Slug aus dem Vokabular

foodalchemist_konfigurator_leistungen     +     planung_json, abrechnung, abrechnung_einheit_vocab_id (wie oben, Kopie)

foodalchemist_offer_blocks                ?     neue Position mit Menge, Einheit, Satz, EK (offene Frage 4)
```

Alle Migrationen sind additiv, mit `hasColumn`/`hasTable`-Guards. Der MySQL-Smoke läuft in einer Wegwerf-DB `fa_probe_*`.

## Betroffene Services und Stellen

| Stelle | Änderung |
|:--|:--|
| `HatLeistungsRegel` | `KATEGORIEN` entfällt; `REGEL_FELDER` + `planung_json`, `abrechnung`, `abrechnung_einheit_vocab_id` |
| `LeistungsRegelPruefung` | Kategorie gegen das Vokabular; Prüfung `planung_json`; Abrechnungsart |
| `VocabularyService` + neue `Settings/Leistungskategorien` | Pflege des Vokabulars |
| `KonfiguratorPricingService` | `rechneFall`: Aufwand heraus, `einzel` und Gruppen je Kategorie; `matrix()` gibt sie aus |
| `KonfiguratorSnapshot` | Allowlist + `zzgl`; `planung_json` ausdrücklich nicht |
| `KonfiguratorService` / `LeistungVorlageService` | Kopie und Abgleich der neuen Felder |
| neu `Konfigurator/KonfiguratorPlanungService` | Bedarf aus Auswahl × Mengen × Komponenten, im Übergabe-Format für OLIVIA |
| `KonfiguratorAnfrageService::anfragen` | Angebot mit Positionen (Aufwand), Bedarf eingefroren in `konfiguration_json.planung` |
| `OfferCompositionService` (Angebot) | neue Position mit Menge und EK, falls freigegeben |
| Oberfläche | Leistungs-Formular (`partials/leistung-regel`): Abschnitte Planung und Abrechnung; Reiter Kalkulation: Zusammenfassung; Kundenlink: `zzgl.`-Zeilen in Zusammenfassung bzw. Anfrage |
| MCP | `regelSchema()` um `planung_json` und `abrechnung` erweitert; neu `leistungskategorien.GET`; `konfiguratoren.MATRIX` liefert Gruppen und Einzelabrechnung |

## Tests

- Vokabular:
  - Die vier Standardkategorien sind nach der Migration global sichtbar, ein Bestand mit `kategorie = personal` ist weiter gültig.
  - Ein Unterteam legt eine eigene Kategorie an. Das Oberteam sieht sie nicht, die geerbte kann das Unterteam nicht ändern.
  - Eine deaktivierte Kategorie ist für neue Leistungen nicht wählbar.
- Planung:
  - `planung_json` wird geprüft (fremdes GP abgewiesen) und beim Übernehmen kopiert, ein geänderter Stand erscheint im Abgleich.
  - Die interne Notiz und die Komponenten stehen nie im Snapshot (Allowlist-Test).
  - `KonfiguratorPlanungService::bedarf` bei 150 Gästen ergibt je Leistung Anzahl und Komponenten, mit `uuid`-Verweisen.
  - Die Anfrage friert den Bedarf am Angebot ein, und das Angebot zeigt ihn als Lese-Ansicht.
- Einzelabrechnung:
  - Eine Aufwands-Leistung ändert `vk_pp` nicht und steht in `einzel`.
  - Der Kundenlink zeigt „zzgl. nach Aufwand“ mit Satz, aber ohne Menge und EK.
  - Die Anfrage legt eine Position an (nach Entscheid zu Frage 4).
- Zusammenfassung:
  - Die Gruppen sind genau die vorkommenden Kategorien plus Speisen.
  - Die Summe der Gruppen ergibt `preis_pp` bzw. `ek_pp` des Falls.
  - Die Einzelabrechnung steht getrennt.
- Wächter: Spec84*, Spec77b, Policy, Dateigroessen, BladeCompiles, UiRegeln, McpToolSchemaExecutor (keine Union-Typen), Spec94*.

## Umsetzung in Stufen

| Stufe | Inhalt | Fertig, wenn |
|:--|:--|:--|
| 7a | Kategorien als Vokabular + Zusammenfassung im Reiter Kalkulation | Testfälle Vokabular und Zusammenfassung grün, Bestand unverändert gültig |
| 7b | Einzelabrechnung (Preis, Kalkulation, Kundenlink) | Aufwand nicht im Preis pro Person, im Link als „zzgl.“, Tests grün |
| 7c | Planungsdetails (`planung_json` als Übergabe-Struktur) + Lese-Ansicht am Angebot + Aufwands-Positionen im Angebot | Bedarf aus einer Anfrage am Angebot eingefroren und lesbar, Aufwands-Positionen im Angebot; keine Planungsoberfläche (OLIVIA) |

## Offene Fragen an Dominique

1. ~~Wo liest „die Planung“ die Planungsdetails?~~ **Entschieden 03.11.2026:** später OLIVIA. In FA gibt es nur die Übergabe-Struktur
   und eine Lese-Ansicht am Angebot (siehe „Schnittstelle zu OLIVIA“).
2. **Geschirr- und Equipment-Bezug:** Komponenten verweisen auf Non-Food-GPs (WG 16) bzw. auf das Behälter-Vokabular, weil der
   Geschirr-Katalog zurückgebaut ist. Freitext ist erlaubt, wo nichts passt. Einverstanden?
3. **Satz der Einzelabrechnung:** Gleich der VK-Satz der Leistung (mit Aufschlagsklasse wie bisher) oder ein eigener Satz nur für die Abrechnung?
   Vorschlag: der VK-Satz, damit es eine Rechnung bleibt.
4. **Position im Angebot:** Für Aufwands-Leistungen braucht das Angebot einen neuen Blocktyp mit Menge, Einheit, Satz und EK.
   `header_preis` hat weder Menge noch EK. Das berührt den Angebots-Editor. Freigabe für einen neuen Blocktyp `position`?
5. **Kategorien je Team:** Die vier Standards global, Unterteams ergänzen eigene. Soll ein Team geerbte Kategorien für sich
   ausblenden dürfen, oder genügt Deaktivieren im eigenen Team?
6. **Kundenlink:** Nur Bezeichnung, Satz und Einheit („Garderobe · 2,50 € je Gast“) oder zusätzlich eine geschätzte Menge?
   Vorschlag: ohne Menge, weil die Menge erst nach Aufwand feststeht.
7. **Zusammenfassung Speisen:** Eine Gruppe „Speisen“ oder getrennt nach Tag, Abend und Extras?
