# Spec 94 · Konfigurator: All-in-Preise je Concept als Kundenlink

Stand 02.11.2026 · Branch `docs/spec94-konfigurator-allin` · **Freigegeben von Dominique am 02.11.2026**
Entscheide Dominique 02.11.: eigenes Modul unter Angebote · Leistungen über Vorlagen · EK und VK je Leistung, VK über Aufschlagsklasse mit Basissatz wie Speisen · Vorlagen vererbbar · nur Deutsch · Anfrage per Mail und Signal · eigenes Lunch-Concept · Editor wie im Angebot, CI über die Präsentations-Designs
Vorbild: DOEC „Diva.licious" (Konfigurator-Demo v0.4.1) und `DOEC_AllIn_Preise_v2.xlsx` (Kunde DOEC Catering, Projekt Oceandiva 2026)

## Warum

Für Event-Caterer wie DOEC zählt beim Kunden ein Preis: **All-in pro Person**, also Speisen plus Personal,
Equipment und Logistik. Heute entsteht dieser Preis außerhalb des Food Alchemist:

1. Eine Excel rechnet Speisen und Servicekosten je Pax-Staffel zusammen.
2. Ein handgebautes HTML (Konfigurator) zeigt dem Kunden die Konzepte mit fest eingetragenen Preisen.

Ziel: Der Food Alchemist rechnet All-in-Preise **je Concept** selbst und stellt sie als **Konfigurator-Link**
bereit. Speisenpreise kommen aus dem Concepter, Personal/Equipment/Logistik werden als Freitext-Positionen mit
EK und VK gepflegt, und zwar einmal als Vorlage statt bei jedem Konfigurator neu. So sieht man jederzeit,
was ein Event kostet und was es bringt.

## Ist-Stand Vorbild DOEC

### Rechenweg der Excel (v2)

Die Excel enthält keine Formeln, nur feste Werte. Der Rechenweg dahinter:

```text
Speisen-VK / Pax   = Speisen-EK × 2,5
Service gesamt     = Personal + Logistik + Equipment        (je Pax-Staffel, fürs ganze Event)
Service / Pax      = Service gesamt / angenommene Pax × 2,5  (Annahme 150 / 350 / 650 / 1.000)
All-in / Pax       = Speisen-VK + Service / Pax
Add-on / Pax       = Speisen-EK × 2,5                        (kein Service-Anteil)
```

Beispiel Captain Breakfast, 100–199 Pax: 6,78 × 2,5 = 16,95 · 4.632 / 150 × 2,5 = 77,20 · **All-in 94,15 €**.

Parameter der Excel: LKW 808 € je Tour (1 / 2 / 2 / 3 Touren je Staffel), Equipment 6,20 € EK je Pax,
8 h vor Ort, Service 1:30, Küchenchef fix, Sous Chef 1 unter 300 / 2 ab 300 Pax, F&B-Manager ab 200 Pax,
Auf-/Abbau-Crew Pax/200 (mind. 2).

### Rechenweg des Konfigurators (HTML)

- 4 Hauptkonzepte, je Konzept ein Preis für Lunch und Dinner pro Pax-Staffel (Lunch = 0,85 × Dinner).
- Day-into-Night: Lunch-Konzept + Dinner-Konzept, **5 % Kombi-Rabatt** nur auf die Konzepte.
- Add-ons in Abschnitten (Frühstück, Pausen, Fingerfood, Mitternachtsnack), teils an ein Konzept gebunden.
- Kombinationen außerhalb des Foodbooks sind als „Indikativ" markiert.
- Teilen per URL, PDF, Anfrage per Mail. Mindestens 50 Pax.

### Schwächen, die das Modul beheben soll

1. **Zwei Preisquellen laufen auseinander:** Culture 100–199 Pax steht im HTML mit 77,35 €, in der Excel v2 mit 99,03 €.
2. **Feste Pax-Annahme je Staffel, ohne Sicht aufs Risiko:** Bei 101 Pax kosten die Servicekosten tatsächlich 45,86 € pro Person
   (4.632 € / 101), gerechnet werden 30,88 € (4.632 € / 150). Die Excel zeigt nicht, wo ein Paket unter Kosten rutscht.
   Die Pakete selbst werden von Hand geschnürt.
3. **Service wird wie Ware aufgeschlagen** (× 2,5 auch auf Personal und Logistik), ohne eigene Klasse.
4. **Day-into-Night zählt den Service doppelt** (zweimal All-in, dann 5 % Rabatt), obwohl LKW, Auf- und Abbau nur einmal anfallen.
5. **Keine Wiederverwendung:** Personal- und Logistiksätze stehen in jeder Datei neu.

## Ziel und Nicht-Ziel

Ziel:

- Eigenes Modul **Konfiguratoren** unter Angebote, eigene Rechenlogik, getrennt vom Angebots-Editor.
- **Leistungs-Vorlagen** (Personal, Equipment, Logistik, Sonstiges) mit EK und VK einmal pflegen und in Konfiguratoren übernehmen.
- Speisen-EK und Speisen-VK kommen aus dem Concept. Alle übrigen Preise (Personal, Equipment, Logistik, Sonstiges)
  werden **von Hand oder per MCP** eingetragen. Es gibt keine automatische Preisquelle für Leistungen.
- Das Modul **schnürt Pax-Pakete selbst**: aus den Regeln der Leistungen entsteht je Pax-Paket die Zusammenstellung
  (wie viele Servicekräfte, Touren, Sets), in der Oberfläche und per MCP.
- All-in-Preis je Concept und Pax-Paket, intern immer mit EK, VK und Deckungsbeitrag sichtbar.
- Ein **Editor wie im Angebot**: alles an einer Stelle bearbeiten, einfügen, rechnen und den Link erzeugen.
- Veröffentlichung als **Kundenlink** auf Basis der bestehenden Präsentationen (`/p/{type}/{token}`), wie beim Foodbook.
- **CI** über die vorhandenen Präsentations-Designs (Einstellungen) und das Branding wie bei Foodbook und Angebot.
  Es entsteht keine eigene CI-Verwaltung; die Einstellungsseite Präsentations-Designs wird um den Konfigurator erweitert.
- Kundenanfrage aus dem Link wird ein normales **Angebot mit Status `anfrage`**, Meldung per Mail und Signal.

Nicht-Ziel:

- Keine Änderung am Angebots-Editor (Kapitel, Blöcke) in dieser Spec; der Konfigurator-Editor übernimmt nur Aufbau und Bausteine.
- Keine Produktionsplanung, kein Auftrags-HK2 aus dem Konfigurator.
- Keine Online-Buchung oder Bezahlung: der Link liefert eine Anfrage, kein verbindliches Angebot.
- Vorerst nur Deutsch.

## Begriffe

| Begriff | Bedeutung |
|:--|:--|
| Konfigurator | Ein veröffentlichbares Paket aus Konzepten, Add-ons, Leistungen und Regeln, z. B. „Diva.licious 2026". |
| Hauptkonzept | Wählbarer Eintrag mit All-in-Preis; verweist auf ein Lunch-Concept und/oder ein Dinner-Concept aus dem Concepter. |
| Add-on | Zubuchbares Concept, Speisenpreis plus eigene Zusatz-Leistungen. |
| Leistungs-Vorlage | Team-weit gepflegte Position mit EK und VK, z. B. „Servicekraft 1:30, EK 30 €/h". |
| Vorlagen-Set | Benannte Gruppe von Vorlagen, z. B. „Schiff Köln Standard", in einem Schritt übernehmbar (Stufe 5). |
| Grundausstattung | Leistungen, die für jedes gewählte Hauptkonzept gelten. |
| Zusatz-Leistung | Leistung, die nur greift, wenn ein bestimmtes Concept oder Add-on gewählt ist (z. B. Grill-Equipment für „Fire"). |
| Einsatzzeit | Stunden vor Ort je Tageszeit (Lunch, Dinner, Day-into-Night), am Konfigurator gepflegt. |
| Pax-Paket | Pax-Bereich (z. B. 100–199) mit Referenz-Pax und fester Menge je Leistung. Jedes Paket hat einen eigenen All-in-Preis je Concept. |
| Pakete schnüren | Das Modul berechnet für jedes Pax-Paket die Mengen aller Leistungen aus deren Regeln. Von Hand geänderte Mengen bleiben erhalten. |

## Modulaufbau (Bedienung)

Navigation: **Angebote → Konfiguratoren** und **Angebote → Leistungs-Vorlagen**.

### Leistungs-Vorlagen

Liste je Kategorie (Personal, Equipment, Logistik, Sonstiges) mit Suche. Je Vorlage:
Bezeichnung (Freitext), Kategorie, Mengenregel, Preis je, **EK-Satz, Aufschlagsklasse, VK-Satz** (Vorschlag
aus der Klasse, fixierbar), Stunden-Quelle, Häufigkeit, Notiz, aktiv. Die Liste zeigt EK, VK und Aufschlag in Prozent nebeneinander.

Vorlagen folgen der Team-Hierarchie (Spec 82): Das Oberteam pflegt, Unterteams sehen und übernehmen sie und
können eigene ergänzen. Ein Unterteam ändert eine geerbte Vorlage nicht, es legt eine eigene an.

Umsetzung ohne eigene Logik über den bestehenden Trait `Models/Concerns/BelongsToTeamHierarchy`:
sichtbar ist das eigene Team plus Eltern-Kette (`scopeVisibleToTeam` bzw. Global Scope `TeamSicht`), editierbar nur
das eigene Team (`isOwnedBy`). Kein `withoutGlobalScope`; die einzige Umgehung ist `ohneTeamSicht('<grund>')` (Spec 84 B3,
geprüft von Spec84SichtWaechter und ServiceEigentumWaechter). Konfiguratoren, Pakete und Leistungszeilen nutzen denselben Trait.

Abgrenzung (Spec 82 §12 A): Die Vererbung gilt nur für Unter-Teams. Ein Mandant neben dem Master ist kein Nachfahre und
sieht die Vorlagen nicht. Leistungs-Vorlagen sind in Phase 1 **nicht im Abo und nicht im Stamm-Paket**.

### Konfigurator-Editor

Aufgebaut wie der Angebots-Editor (`Livewire/Angebote/Editor`): Liste der Konfiguratoren, ein Klick öffnet den
Editor im Vollbild. Oben eine Kennzahlen-Leiste, darunter Reiter. Ein **Speichern** sichert alle Reiter, wie im Angebot.

**Kennzahlen-Leiste:** Status, Anzahl Konzepte, Anzahl Pakete, All-in „ab“ (günstigstes Konzept im größten Paket),
schwächster Deckungsbeitrag mit Paket und Konzept, Zahl der Warnungen, Link-Status (live, abgelaufen, Design geändert seit Veröffentlichung).

| Reiter | Inhalt |
|:--|:--|
| **Übersicht** | Grunddaten: Name, Kundentitel, Claim, Intro, Kunde (CRM optional), Veranstaltungsort, Gültigkeit, verantwortliche Person für Anfragen. Darunter die letzten Anfragen aus dem Link mit Sprung ins Angebot. |
| **Konzepte** | Hauptkonzepte und Add-ons. Hinzufügen über denselben Concept-Picker wie im Angebot (Facetten, Suche). Je Hauptkonzept Lunch- und Dinner-Concept, Kundentitel, Claim, Bild, Indikativ je Tageszeit. Je Add-on Abschnitt (Frühstück, Pausen …), Bindung, Tageszeit. EK und VK je Person kommen aus dem Concept und werden nur angezeigt; „Menü bearbeiten“ springt in den Concepter wie im Angebot. Reihenfolge per Ziehen. |
| **Leistungen** | Grundausstattung und Zusatz-Leistungen je Concept oder Add-on, gruppiert nach Kategorie. Einfügen **aus Vorlage** (Mehrfachauswahl) oder frei. EK von Hand, VK mit Vorschlag aus der Aufschlagsklasse. Jede Zeile bleibt editierbar; Abgleich-Hinweis, wenn sich die Vorlage geändert hat. |
| **Pax-Pakete** | Pakete selbst anlegen (von / bis; Referenz-Pax rechnet das System, änderbar), optional **Paketgrenzen vorschlagen**, dann **Pakete schnüren**. Matrix Leistung × Paket mit Mengen; jede Zelle überschreibbar und dann als „von Hand“ markiert. Darunter EK, VK und Deckungsbeitrag je Paket. |
| **Kalkulation** | Regeln (Einsatzzeiten je Tageszeit, Kombi-Rabatt, Rundung, Paket-Toleranz) und die Preisvorschau: Matrix Konzept × Paket mit EK, VK und Deckungsbeitrag, getrennt nach Speisen und Leistungen, Deckungsbeitrag an Unter- und Obergrenze jedes Pakets, Warnungen. Ändert sich ein Wert, rechnet die Vorschau sofort neu. |
| **Branding und Präsentation** | wie im Angebot, siehe „CI und Design“: Design wählen, Branding, Live-Vorschau, Link erzeugen, Ablaufdatum, Zurückziehen. |

Alle Reiter holen ihre Zahlen nur aus dem `KonfiguratorPricingService`. Die Preisvorschau im Editor und die Preistabelle
im Link stammen aus derselben Rechnung.

### CI und Design

Der Konfigurator nutzt die vorhandene Präsentations-Maschinerie und bekommt **keine eigene CI-Verwaltung**.

**Design (Auswahl):** Im Reiter „Branding und Präsentation“ wählt man ein Design aus derselben Auswahl wie beim
Foodbook (`PresentationDesignService::pickerOptions($team, 'konfigurator')`): eingebaute Designs und die
team-eigenen Designs aus **Einstellungen → Präsentations-Designs**. Das Design ist wie heute live verknüpft
(`presentation_design = design:{id}`). Ändert jemand das Design nach der Veröffentlichung, zeigt der Editor den
bekannten Hinweis „Design geändert seit Veröffentlichung“ mit Knopf zum erneuten Veröffentlichen.

**Weiterentwicklung der Einstellungsseite Präsentations-Designs** (`Livewire/Settings/PraesentationsDesigns`, Spec 43):

1. Neue Ausgabeform `konfigurator` in `PresentationDesignService::OUTPUT_TYPES`. Ein Design kann für Foodbook und
   Konfigurator zugleich gelten; dann sehen beide Ausgaben dieselben Farben, Schriften und Abstände.
2. Neue Blöcke, die die Block-Palette nur bei Ausgabeform `konfigurator` anbietet:
   - `konfig_konzepte`: Konzeptkarten (Stil: Raster oder Liste, Bild an/aus, „Preis ab“ an/aus)
   - `konfig_tageszeit`: Schritt Tageszeit (ausgeblendet, wenn `tageszeit_aktiv` aus ist)
   - `konfig_addons`: Add-ons nach Abschnitten
   - `konfig_zusammenfassung`: Pax-Regler, Paket-Anzeige, Preis pro Person und gesamt, Indikativ-Hinweise, Drucken, Teilen
   - `konfig_anfrage`: Anfrageformular

   Dazu die vorhandenen Blöcke `cover`, `heading`, `text`, `image`, `spacer` und `legend`. Die Konfigurator-Blöcke
   sind Pflicht und kommen je genau einmal vor; der Builder verhindert Löschen und Verdoppeln.
3. Neues eingebautes Design `konfigurator` in `BUILTIN_SLUGS` mit Standard-Reihenfolge
   (Cover, Konzepte, Tageszeit, Add-ons, Zusammenfassung, Anfrage) als Startpunkt für „Neu aus Vorlage“.
4. Die Live-Vorschau des Builders zeigt einen Konfigurator mit Beispieldaten (zwei Konzepte, zwei Add-ons, drei Pakete),
   damit man das Design ohne echten Konfigurator gestalten kann.

Datengebunden wie bisher: Die Konfigurator-Blöcke legen nur Anordnung und Stil fest. Was sie zeigen, kommt aus der
Preistabelle im Snapshot. Ein Block kann deshalb keine EK-Werte oder Mengen anzeigen.

**Branding je Konfigurator:** dieselben Felder wie beim Angebot (Primärfarbe, Bandfarbe, Logo, Titelbild, Footer).
Knopf **„Branding übernehmen von …“** mit Auswahl Foodbook, Angebot oder Betrieb (bei Betrieb: Logo und Design).
Übernehmen kopiert die Werte einmalig; danach ist das Branding des Konfigurators unabhängig. Gesetztes Branding
übersteuert die Palette des Designs, wie heute bei Foodbook und Angebot (`brandingIdentifiers` → `resolveTokens`).

**Link erzeugen:** wie beim Foodbook über `PresentationService::publish` mit Typ `konfigurator`: Token, optional Slug,
Ablaufdatum aus `gueltig_bis`, Vorschau vor dem Veröffentlichen (`previewData`), Zurückziehen. Ein Link je
Konfigurator; eine Veröffentlichung je Betrieb (wie bei der Speisekarte) ist nicht Teil dieser Spec.

## Datenmodell

Alle Tabellen mit `id`, `uuid`, `team_id`, `timestamps`, `softDeletes` und Team-Sicht wie im restlichen Modul.

### `foodalchemist_leistung_vorlagen`

| Spalte | Typ | Notiz |
|:--|:--|:--|
| `kategorie` | string(16) | `personal` \| `equipment` \| `logistik` \| `sonstiges` |
| `bezeichnung` | string | Freitext, intern und Kundensicht |
| `notiz` | text null | intern |
| `mengen_regel` | string(16) | `fest` \| `schluessel` \| `stufen` (siehe „Mengenregeln") |
| `anzahl` | decimal(10,2) null | bei `fest` |
| `bezug` | string(16) | bei `schluessel`: `gaeste` \| `leistung` |
| `bezug_leistung_id` | FK self null | bei `bezug = leistung`, z. B. „Teamleitung je 8 Servicekräfte" |
| `schluessel_n` | decimal(10,2) null | Gäste bzw. Einheiten je 1 Stück, z. B. 30 (Service 1:30), 250 (LKW-Kapazität), 1 (je Gast) |
| `min`, `max` | decimal(10,2) null | Mindest- und Höchstanzahl |
| `ab_pax`, `bis_pax` | int null | Leistung greift nur in diesem Gästebereich, z. B. F&B-Manager ab 200 |
| `stufen_json` | json null | bei `stufen`: `[{bis_pax, anzahl}]` für Fälle, die kein Schlüssel abbildet |
| `reserve_pct` | decimal(5,2) null | Zuschlag vor dem Aufrunden, z. B. 10 % Geschirr-Reserve |
| `preis_je` | string(16) | `stueck` \| `stunde` |
| `stunden_quelle` | string(16) null | bei `stunde`: `einsatzzeit` (aus dem Konfigurator) \| `fest` (eigene Stunden) |
| `stunden` | decimal(6,2) null | bei `stunden_quelle = fest`, z. B. Auf-/Abbau 7 h |
| `stunden_zuschlag` | decimal(5,2) null | bei `einsatzzeit`: Vor- und Nachlauf, z. B. +1 h Briefing und Anfahrt |
| `haeufigkeit` | string(16) | `je_event` (LKW, Equipment, Auf-/Abbau) \| `je_tageszeit` (fällt für Lunch und Dinner getrennt an) |
| `ek_satz` | decimal(10,2) | EK je Einsatz / Stunde / Person |
| `markup_class_id` | FK null | Aufschlagsklasse, liefert den VK-Vorschlag |
| `vk_satz` | decimal(10,2) null | gültiger VK je Einheit |
| `vk_modus` | string(8) | `auto` (aus Klasse) \| `fixed` (fest, mit Begründung) — wie die Preiszustände der Kalkulation |
| `vk_begruendung` | string null | Pflicht bei `fixed` |
| `aktiv` | boolean | |
| `position` | int | |

### `foodalchemist_konfiguratoren`

| Spalte | Typ | Notiz |
|:--|:--|:--|
| `name` | string | intern |
| `kunden_titel`, `claim`, `intro` | string/text null | Kundensicht |
| `crm_company_id` | bigint null | optional |
| `ort` | string null | Freitext, z. B. „Köln, Anleger Rheinpark" |
| `status` | string(12) | `entwurf` \| `live` \| `archiv` |
| `verantwortlich_user_id` | FK null | bekommt Anfragen per Mail und Signal; Default: Ersteller |
| `pax_vorgabe` | int | Startwert des Pax-Reglers im Link; Pax-Bereich ergibt sich aus den Paketen |
| `stunden_lunch`, `stunden_dinner`, `stunden_day_night` | decimal(5,2) | Einsatzzeiten, z. B. 6 / 7 / 14 |
| `tageszeit_aktiv` | boolean | Schritt „Tageszeit" im Link anzeigen |
| `kombi_rabatt_pct` | decimal(5,2) | Day-into-Night, nur auf Konzeptanteile; je Konfigurator frei, Standard 0 % |
| `paket_toleranz_pct` | decimal(5,2) | erlaubte Abweichung Paketpreis zu genauem Preis, Standard 10 % (Vorschlag und Warnung) |
| `rundung` | decimal(4,2) | 0,01 \| 0,10 \| 0,50 \| 1,00 |
| `gueltig_bis` | date null | |
| Präsentations-Spalten | | wie `foodalchemist_offers` (Trait `HasPresentation`), darunter `presentation_design` |
| Branding-Spalten | | wie `foodalchemist_offers`: `brand_color`, `band_color`, `logo_path`, `logo_context_file_id`, `cover_image_path`, `cover_context_file_id`, `footer_text` |

### `foodalchemist_konfigurator_konzepte`

| Spalte | Typ | Notiz |
|:--|:--|:--|
| `konfigurator_id` | FK | |
| `rolle` | string(8) | `haupt` \| `addon` |
| `lunch_concept_id` | FK `foodalchemist_concepts` null | Hauptkonzept: eigenes Lunch-Concept aus dem Concepter |
| `dinner_concept_id` | FK `foodalchemist_concepts` null | Hauptkonzept: Dinner-Concept; Add-on: das Concept selbst |
| `abschnitt` | string null | nur Add-on: Frühstück, Pausen … |
| `addon_tageszeit` | string(8) null | nur Add-on: `lunch` \| `dinner` \| `beide` |
| `gebunden_an_id` | FK self null | Add-on nur sichtbar, wenn dieses Hauptkonzept gewählt ist |
| `indikativ_lunch`, `indikativ_dinner` | boolean | |
| `kunden_titel`, `claim` | string null | Override für Kundensicht |
| `bild_context_file_id`, `bild_path` | | wie Concept-Bilder |
| `position` | int | |

Ein Hauptkonzept braucht mindestens eines der beiden Concepts. Fehlt das Lunch-Concept, ist das Konzept im Link nur abends wählbar (und umgekehrt).
Die Concepts werden **verknüpft, nicht kopiert**: Speisenpreise laufen mit dem Concepter mit, bis veröffentlicht wird.

### `foodalchemist_konfigurator_leistungen`

Gleiche Rechen- und Preisfelder wie die Vorlage (**Kopie beim Übernehmen**), dazu:

| Spalte | Typ | Notiz |
|:--|:--|:--|
| `konfigurator_id` | FK | |
| `konfigurator_konzept_id` | FK null | null = Grundausstattung |
| `gilt_fuer` | string(8) null | bei Hauptkonzept: `lunch` \| `dinner` \| `beide`, damit Zusatzposten am Lunch- oder Dinner-Concept hängen können |
| `ersetzt_leistung_id` | FK self null | Zusatz-Leistung ersetzt eine Grundausstattungs-Leistung, solange dieses Konzept gewählt ist, z. B. „Tischservice 1:12" statt „Buffetservice 1:30" bei „Fire" |
| `vorlage_id` | FK null | Herkunft; bleibt nach Löschen der Vorlage leer |
| `vorlage_stand_at` | timestamp null | Stand der Vorlage beim Übernehmen |

Vorlagen werden **kopiert, nicht live verknüpft**: Ein veröffentlichter Preis darf sich nicht still ändern.
Ändert sich eine Vorlage später, zeigt der Editor „Vorlage geändert, übernehmen?" je Zeile und als Sammelaktion.
Das ist eine Kopie mit `vorlage_id` und `vorlage_stand_at`, verglichen mit `updated_at` der Vorlage, kein Live-Verweis.
Geprüft: Das Fassungs-Muster (`Concerns/HatFassungen`, `*_fassungen.UEBERNEHMEN_GET/POST` bei Rezept und Baustein)
dient dem Abo mit eingefrorenen Fassungen. Solange Vorlagen nicht im Abo sind, reicht die Kopie mit Stand;
kommen sie später ins Abo, wechseln sie auf `HatFassungen` statt eines dritten Wegs.

### `foodalchemist_konfigurator_pakete`

| Spalte | Typ | Notiz |
|:--|:--|:--|
| `konfigurator_id` | FK | |
| `bezeichnung` | string null | z. B. „100–199 Gäste"; leer = aus von/bis erzeugt |
| `von_pax`, `bis_pax` | int | lückenlos und ohne Überschneidung (Prüfung beim Speichern) |
| `referenz_pax` | int | Pax, mit dem Mengen und Preis je Person gerechnet werden; vom System gesetzt als Mitte des Bereichs (abgerundet), von Hand änderbar |
| `referenz_manuell` | boolean | `true` = Referenz-Pax von Hand gesetzt, wird bei geänderten Grenzen nicht neu gerechnet |
| `geschnuert_at` | timestamp null | letzter Lauf „Pakete schnüren“ |
| `position` | int | |

Die Paketgrenzen legt der Mensch fest (von Hand oder durch Übernehmen eines Vorschlags). Alles andere rechnet das
System: Referenz-Pax, Mengen, Preise, Deckungsbeiträge. Es gibt keine fest eingebauten Standard-Pakete.

### `foodalchemist_konfigurator_paket_mengen`

| Spalte | Typ | Notiz |
|:--|:--|:--|
| `paket_id` | FK | |
| `leistung_id` | FK `foodalchemist_konfigurator_leistungen` | |
| `anzahl` | decimal(10,2) | Ergebnis der Regel oder von Hand |
| `manuell` | boolean | `true` = von Hand gesetzt, wird beim Schnüren nicht überschrieben |
| `regel_anzahl` | decimal(10,2) | was die Regel ergeben würde (zum Vergleich) |
| `notiz` | string null | Begründung bei Handwert |
| `konfigurator_konzept_id` | FK null | null = gilt für alle Hauptkonzepte; gesetzt = abweichende Menge für dieses Konzept (durch Ersetzung oder Bezugskette) |

Eindeutig je (`paket_id`, `leistung_id`, `konfigurator_konzept_id`).

### Erweiterung `foodalchemist_offers`

`konfigurator_id` (FK null) und `konfiguration_json` (Auswahl des Kunden: Konzepte, Tageszeit, Add-ons, Pax, Preisstand).

### Neuer Signal-Typ

`SignalTyp::KonfiguratorAnfrage` (`konfigurator_anfrage`): eine offene Anfrage aus einem Kundenlink, verweist auf das neue Angebot.
Mit Eintrag in der Signal-Weg-Registry und in der Policy (Tests SignalWegRegistryTest, SignalPolicyDrift, SignalLifecycleTest).
Geschlossen wird über `SignalService::schliesseGemessen`, sobald das Angebot den Status `anfrage` verlässt.

Status: Das Angebot nutzt den vorhandenen `AngebotStatus::Anfrage`; es gibt keinen neuen Status.

## Rechenweg

### EK und VK der Leistungen

Jede Leistung trägt **EK und VK**. Beide Sätze werden **von Hand oder per MCP** eingetragen, in der Vorlage
oder direkt am Konfigurator. Es gibt keine automatische Preisquelle (kein Lieferanten- oder Lohnimport).
Für den VK gibt es einen Vorschlag aus der **Aufschlagsklasse** (dieselben Klassen wie im Concepter und bei den
Darreichungen); er lässt sich mit Begründung fest setzen.

Gleicher Weg wie bei den Speisen (`CatalogPricingService`, siehe `docs/kalkulation.md`). Die Formel wird nicht
neu geschrieben: Der `KonfiguratorPricingService` holt den Basissatz über `CatalogPricingService::enterpriseBaseRate`
und den Klassenfaktor über denselben Weg wie `catalogPrice`. Dafür wird die Formel dort in einen gemeinsamen Helfer
gezogen, den Speisen und Leistungen aufrufen (eine Wahrheit):

```text
VK-Satz (auto)  = EK-Satz × Basissatz × Klassenfaktor / 100
VK-Satz (fixed) = eingetragener Wert, Begründung Pflicht
```

Basissatz = dynamischer Unternehmens-Basissatz des Teams (bzw. Betriebs), Klassenfaktor = `class_factor_pct` der
gewählten Aufschlagsklasse. Ohne Klasse gilt der Team-Standard bzw. 100 % mit Warnung, wie bei den Darreichungen.

Hinweis: Der Basissatz enthält Gemeinkosten, die auf den Wareneinsatz bezogen sind. Für Personal oder LKW kann
er deshalb einen hohen VK ergeben. Gesteuert wird das über eigene Aufschlagsklassen für Leistungen
(z. B. „Personal", „Logistik", „Equipment") mit passendem Klassenfaktor. Die Preisvorschau zeigt je Leistung
den Aufschlag in Prozent, damit Ausreißer auffallen.

Damit sich Leistungs- und Speisenklassen nicht mischen, bekommt `foodalchemist_markup_classes` eine Spalte
`bereich` (`speisen` | `leistung`, Standard `speisen`, Bestand bleibt `speisen`). Concepter und Darreichungen zeigen nur
`speisen`, Vorlagen und Leistungszeilen nur `leistung`. Das ist die einzige Änderung an einer bestehenden Tabelle
außer `foodalchemist_offers`.

### Speisen

Speisen-VK und Speisen-EK je Person kommen fertig aus dem Concept im Concepter und werden im Konfigurator nicht eingegeben:

```text
Speisen-VK(c) = price_per_person_manual bei Preis-Modus manuell, sonst price_per_person_cache
Speisen-EK(c) = ek_per_person_cache
```

Lunch und Dinner haben je ein eigenes Concept. Es gibt keinen Lunch-Faktor.

### Mengenregeln

Die Regeln sind bewusst **nicht** die Excel-Regeln, sondern ein kleiner, allgemeiner Baukasten. Jede Leistung hat
genau eine Mengenregel; daraus entsteht die Anzahl je Pax-Paket. Ob es Personal, Equipment oder Logistik ist,
spielt für die Regel keine Rolle.

| Regel | Bedeutung | Beispiele |
|:--|:--|:--|
| `fest` | immer dieselbe Anzahl | 1 Küchenchef, 1 Reinigungspauschale |
| `schluessel` | 1 Stück je `n` Einheiten des Bezugs, mit Min/Max und Reserve | Service 1:30 Gäste · Crew 1:200 Gäste, mind. 2 · LKW 1 je 250 Gäste · Equipment-Set 1 je Gast + 10 % · Teamleitung 1 je 8 Servicekräfte |
| `stufen` | Anzahl aus einer Tabelle nach Gästezahl | unregelmäßige Fälle, die kein Schlüssel abbildet |

Dazu für jede Regel: `ab_pax` / `bis_pax` (Leistung greift nur in diesem Bereich, z. B. F&B-Manager ab 200 Gäste).

```text
aktiv(l, r)        = (ab_pax leer oder r ≥ ab_pax) und (bis_pax leer oder r ≤ bis_pax)

Bezug(l, P)        = gaeste:   r
                   | leistung: anzahl(bezug_leistung, P)

regel_anzahl(l, P) = 0, wenn nicht aktiv(l, r)
                   | fest:       anzahl
                   | schluessel: clamp(ceil(Bezug × (1 + reserve_pct) / schluessel_n), min, max)
                   | stufen:     anzahl der ersten Stufe mit r ≤ bis_pax

anzahl(l, P)       = Handwert, wenn manuell; sonst regel_anzahl(l, P)
```

Anzahlen werden immer auf ganze Stück aufgerundet. Leistungen mit Bezug auf eine andere Leistung werden nach
dieser berechnet. Ein Kreis (A hängt an B, B an A) wird beim Speichern abgelehnt.

**Konzeptabhängige Besetzung:** Eine Zusatz-Leistung eines Konzepts kann eine Grundausstattungs-Leistung
**ersetzen** (`ersetzt_leistung_id`). So braucht „Fire" mit Tischservice 1:12 statt Buffetservice 1:30 keine zweite
Grundausstattung. Leistungen, die per `bezug_leistung_id` an der ersetzten Leistung hängen, rechnen dann mit dem Ersatz.

Beim Übernehmen aus Vorlagen wird `bezug_leistung_id` auf die übernommene Leistung umgehängt. Fehlt die
Bezugs-Leistung im Konfigurator, warnt der Editor und rechnet mit 0.

### Pakete schnüren

„Pakete schnüren“ läuft auf Knopfdruck im Editor und per MCP. Es wendet die Mengenregeln auf jedes Paket `P`
mit seiner Referenz-Pax `r` an, und zwar je Hauptkonzept (wegen Ersetzungen und Zusatz-Leistungen).

Der Lauf schreibt `regel_anzahl` immer neu, `anzahl` nur dort, wo nicht `manuell` gesetzt ist. Er ist idempotent
und läuft automatisch nach dem Anlegen eines Pakets oder einer Leistung und nach einer Regeländerung. Weicht ein
Handwert von der neuen Regel ab, markiert der Editor die Zelle. Ohne Pakete gibt es keine Preise und keinen Link.

### Leistungen je Paket

Für Paket `P` (Referenz-Pax `r`) und Tageszeit `t` (`lunch`, `dinner` oder `day_night`):

```text
Stunden(l, t)    = stunden_quelle fest:        stunden
                 | stunden_quelle einsatzzeit: (stunden_lunch | stunden_dinner | stunden_day_night) + stunden_zuschlag

Menge(l, P, t)   = stueck: anzahl(l, P)
                 | stunde: anzahl(l, P) × Stunden(l, t)

EK(l, P, t)      = Menge × ek_satz
VK(l, P, t)      = Menge × vk_satz
```

### All-in je Konzept und Paket

Einzelne Tageszeit (Hauptkonzept `k`, Concept `c` = Lunch- bzw. Dinner-Concept von `k`):

```text
All-in(k, t, P) = Speisen-VK(c) + Σ VK(Grundausstattung + Zusatz von k für t) / r   → gerundet nach `rundung`
EK(k, t, P)     = Speisen-EK(c) + Σ EK(…) / r
DB(k, t, P)     = All-in − EK
```

Der Preis je Person ist innerhalb eines Pakets fest. Damit sichtbar bleibt, was das kostet, prüft die Preisvorschau
jedes Paket an der **Untergrenze** (`von_pax`) und der **Obergrenze** (`bis_pax`). Dort werden die Mengen **nach Regel
für genau diese Gästezahl** neu gerechnet (bei 100 Gästen weniger Servicekräfte als bei 199) und gegen den festen
Paketpreis gestellt:

```text
Genau(k, t, p)      = Speisen-VK(c) + Σ VK(Leistungen mit Mengen nach Regel für p) / p
Abweichung(k, t, p) = (Paketpreis(k, t, P) − Genau(k, t, p)) / Genau(k, t, p)
DB-Rand(k, t, p)    = Paketpreis(k, t, P) − (Speisen-EK(c) + Σ EK(Leistungen nach Regel für p) / p)
```

Handwerte gelten auch an den Rändern, sie sind fest gewollt. Liegt die Abweichung irgendwo im Paket über
`paket_toleranz_pct`, oder ist der DB am Rand negativ oder unter Ziel, warnt die Vorschau.

### Paketgrenzen vorschlagen

Optionale Hilfe beim Anlegen der Pakete, auf Knopfdruck und per MCP. **Deterministisch, ohne KI**
(Spec 90 greift nicht). Eingaben: Gästebereich (z. B. 50–1.200),
Toleranz (Standard `paket_toleranz_pct`), Raster für glatte Grenzen (Standard 25 Gäste), Mindestbreite (Standard 25).

```text
Für jedes p im Gästebereich: Genau(k, t, p) für alle Hauptkonzepte und Tageszeiten.
Ab Untergrenze u: bis so weit wie möglich nach oben schieben (im Raster), solange für jedes p in [u, bis]
und jedes (k, t) gilt: |Paketpreis(Referenz = Mitte von [u, bis]) − Genau(k, t, p)| / Genau ≤ Toleranz.
Nächstes Paket startet bei bis + 1. Wiederholen bis zur Obergrenze des Bereichs.
```

Das Ergebnis ist ein **Vorschlag**: Liste der Pakete mit größter Abweichung je Paket. Der Mensch übernimmt ihn
ganz, teilweise oder gar nicht. Beim Übernehmen werden die bisherigen Pakete ersetzt; vorhandene Handwerte
in der Paket-Matrix gehen dabei verloren, der Editor fragt vorher nach.

Weil Fixkosten (Küchenchef, LKW, Auf- und Abbau) bei kleinen Gruppen stark durchschlagen, werden die Pakete
dort eng (z. B. 50–74, 75–99, 100–149) und bei großen Gruppen breit (z. B. 500–799, 800–1.200). Die Pakete
folgen damit den Kosten.

**Day-into-Night** (Lunch von `k1`, Dinner von `k2`, auch `k1 = k2` möglich):

```text
Speisen         = Speisen-VK(Lunch-Concept k1) + Speisen-VK(Dinner-Concept k2)
Leistungen      = je_event-Leistungen einmal; Stunden aus stunden_day_night (eine durchgehende Schicht)
                + je_tageszeit-Leistungen je einmal für Lunch und für Dinner
                + Zusatz-Leistungen von k1 (lunch) und k2 (dinner)
Konzeptanteil   = (Speisen + Leistungen / r) × (1 − kombi_rabatt_pct)
Gesamt / Pax    = Konzeptanteil + Σ Add-ons
```

Damit fallen LKW, Equipment, Auf- und Abbau bei Day-into-Night nur einmal an, und das Personal arbeitet eine
durchgehende Schicht (z. B. 14 h) statt zweier getrennter. Das behebt Schwäche 4.

**Add-on:** `Speisen-VK(a) + Σ VK(Zusatz-Leistungen von a) / r`, kein Anteil an der Grundausstattung.

### Testfälle

Die Tests prüfen die Logik, nicht die Excel:

- je Mengenregel: `fest`, `schluessel` (Gäste, Leistung, Min/Max, Reserve, Aufrunden), `stufen`, Aktivbereich `ab_pax`/`bis_pax`
- Bezugskette (Teamleitung an Servicekräften) und Kreis-Ablehnung
- Ersetzung durch eine Konzept-Leistung, inklusive Leistungen, die an der ersetzten hängen
- Stunden aus Einsatzzeit plus Zuschlag und aus festen Stunden, Day-into-Night mit `je_event`/`je_tageszeit`
- Schnüren mit Handwerten (bleiben erhalten, Abweichung markiert), Idempotenz
- Referenz-Pax: Mitte vom System, Handwert bleibt bei geänderten Grenzen
- Randkontrolle rechnet Mengen nach Regel für `von_pax` und `bis_pax`
- Paketgrenzen vorschlagen: jedes vorgeschlagene Paket hält die Toleranz ein, Grenzen im Raster, lückenlos, enge Pakete bei hohem Fixkostenanteil
- Rundung, Kombi-Rabatt, Deckungsbeitrag an Unter- und Obergrenze
- Kundensnapshot ohne EK, Mengen und Leistungszeilen

**DOEC als Beispiel-Datensatz** (Seeder für Demo und Handbuch, kein Pflichtergebnis): Captain Breakfast mit den
Leistungen aus der Excel, nachgebaut mit dem Baukasten (Service `schluessel` 1:30, Crew `schluessel` 1:200 mind. 2,
F&B `fest` 1 ab 200 Gäste, LKW `schluessel` 1 je Kapazität, Equipment `schluessel` 1 je Gast). Er dient dazu,
die Ergebnisse gegen die Excel plausibel zu halten.

### Warnungen in der Preisvorschau

- Speisenpreis eines Concepts fehlt oder ist veraltet.
- Concept-VK liegt unter dem Zielpreis (bestehendes Muster der Kalkulation).
- Leistung ohne EK-Satz, ohne Aufschlagsklasse oder mit VK unter EK.
- Vorlage geändert seit Übernahme.
- Deckungsbeitrag am Rand eines Pakets negativ oder unter Ziel (z. B. 100 Gäste im Paket 100–199).
- Paketpreis weicht im Paket mehr als `paket_toleranz_pct` vom genauen Preis ab (Hinweis: Paket teilen oder Grenzen vorschlagen lassen).
- Pakete mit Lücke oder Überschneidung, Paket ohne Referenz-Pax im Bereich.
- Handwert weicht von der Regel ab (Zelle markiert, Liste in der Vorschau).
- Pakete nicht neu geschnürt seit Änderung einer Leistung oder Regel.
- Hauptkonzept ohne Lunch- und ohne Dinner-Concept.

## Kundenlink (Präsentationstyp `konfigurator`)

Neuer Typ `PresentationService::TYPE_KONFIGURATOR = 'konfigurator'` mit eigenem `normalize…`. Die Seite wird aus dem
gewählten Design gerendert (Layout und Tokens über `PresentationDesignService`, Branding darüber); die Schritte unten
sind die Konfigurator-Blöcke aus „CI und Design“.

Registrierung: Typ in `routes/public.php` (`/p/{type}/{token}`) und in `HasPresentation` eintragen.
Leak-Test analog Angebot: Snapshot ohne EK, ohne Aufschlagsklassen, ohne Mengen und ohne Leistungszeilen.

Ablauf wie im DOEC-Vorbild:

1. Konzept wählen (Karten mit Bild, Claim, Preis ab).
2. Tageszeit wählen (nur wenn `tageszeit_aktiv`), bei Day-into-Night optional zweites Konzept für den Abend.
3. Add-ons nach Abschnitten, gefiltert nach Bindung und Tageszeit.
4. Zusammenfassung: Pax-Regler (Bereich aus den Paketen, Anzeige des Pakets, z. B. „100–199 Gäste“), Preis pro Person und gesamt, Indikativ-Hinweise, Drucken/PDF, Konfiguration teilen (URL-Hash), **Anfrage senden**.

**Kein Leak:** Der Snapshot enthält **keine** EK-Werte, Aufschlagsklassen, Mengen oder einzelnen Leistungszeilen. Er enthält
eine serverseitig vorberechnete **Preistabelle**: je Pax-Paket (von / bis) und je Hauptkonzept, Tageszeit und Add-on
der VK pro Person, für Day-into-Night je Paar aus Lunch- und Dinner-Konzept. Der Browser sucht das Paket zur
eingestellten Gästezahl und liest nur ab. Ein Test prüft, dass im Snapshot kein EK steht.

**Anfrage:** Formular mit Name, Firma, E-Mail, Telefon, Datum, Nachricht und Datenschutz-Einwilligung.
Erster schreibender Endpunkt aus einem Präsentations-Link; Vorbild sind die POST-Routen des Lieferanten-Portals
(`/l/{token}/…` in `routes/public.php`):

- Route `POST /p/konfigurator/{token}/anfrage`, Token per `where` eingeschränkt, `NoCacheHeaders`.
- Eigener Limiter `throttle:fa-konfigurator-anfrage` (z. B. 5 je Minute und IP, 20 je Stunde und Token).
- Honeypot-Feld und Mindest-Ausfüllzeit; Treffer werden still verworfen.
- Validierung mit festen Größen: Texte max. 2.000 Zeichen, E-Mail-Format, Pax nur im Bereich der Pakete,
  Konzepte und Add-ons nur aus dem Snapshot dieses Links (IDs gegen den Snapshot geprüft).
- Der Preis wird serverseitig aus der Preistabelle neu bestimmt, nie aus dem Formular übernommen.
- Keine URLs oder Dateien annehmen, keine Abrufe nach außen auslösen (SSRF-Lehre aus #671).
- Abgelaufener oder zurückgezogener Link nimmt keine Anfrage an.

Ergebnis:

1. Neues Angebot (`status = anfrage`, `personen`, `event_date`, `brief` mit der Auswahl als Text, `total_price`, `konfigurator_id`, `konfiguration_json`).
2. **Mail** an die verantwortliche Person (`verantwortlich_user_id`, Adresse aus dem Benutzerkonto) mit Auswahl, Preis und Link zum Angebot. Ohne hinterlegte Adresse nur das Signal und eine Warnung im Konfigurator. Versand über den Mail-Weg, den das Modul schon hat (Muster `OrderMailService`); keine Änderung an platforms-core. Später kann Spec 92 (PWA/Push) die Meldung zusätzlich als Push tragen.
3. **Signal** `konfigurator_anfrage` im Team, schließt sich, wenn das Angebot den Status `anfrage` verlässt.

## MCP-Tools

Namensschema `foodalchemist.<ressource>.<VERB>` wie im Bestand; Klassen heißen `…GetTool`, `…PostTool` usw.

- `foodalchemist.leistung_vorlagen.GET / POST / PUT / DELETE`
- `foodalchemist.konfiguratoren.GET / POST / PUT / DELETE`
- `foodalchemist.konfiguratoren.BRANDING` (Farben, Footer; „übernehmen von“ Foodbook, Angebot oder Betrieb), wie `foodalchemist.foodbooks.BRANDING`
- `foodalchemist.konfigurator_konzepte.POST / PUT / DELETE / REORDER`
- `foodalchemist.konfigurator_leistungen.POST / PUT / DELETE`, `.AUS_VORLAGE` (Mehrfachübernahme), `.VORLAGE_ABGLEICH`
- `foodalchemist.konfigurator_pakete.GET / POST / PUT / DELETE`
- `foodalchemist.konfiguratoren.SCHNUEREN` (alle Pakete oder eines; Antwort: Mengen je Leistung, Abweichungen Handwert gegen Regel, EK/VK/DB je Paket)
- `foodalchemist.konfiguratoren.PAKETE_VORSCHLAGEN` (Gästebereich, Toleranz, Raster, Mindestbreite; liefert nur den Vorschlag, schreibt nichts) und `.PAKETE_VORSCHLAG_UEBERNEHMEN`
- `foodalchemist.konfigurator_paket_mengen.PUT` (Handwert setzen oder zurück auf Regel)
- `foodalchemist.konfiguratoren.PREISE` (Matrix Konzept × Paket mit EK, VK, DB inkl. Unter- und Obergrenze; nur intern)
- `foodalchemist.konfigurator_presentation.GET / PUBLISH / WITHDRAW` (mit Design-Wahl)
- bestehende `foodalchemist.presentation_designs.*`: Ausgabeform `konfigurator` und die neuen Blöcke im Vokabular

Jedes neue Tool steht in `src/Tools/_liste.php` (sonst Spec84ToolListeTest und McpToolsTest rot), hat `read_only`
korrekt gesetzt (GET, PREISE, PAKETE_VORSCHLAGEN: `true`) und läuft für die Rechte über `FaRechteToolHuelle`.

Die Rechenlogik liegt nur im `KonfiguratorPricingService`. Oberfläche und MCP-Tools rufen denselben Service auf;
es gibt keine zweite Rechnung im Tool oder im Browser. EK- und VK-Sätze der Leistungen kommen per POST /
PUT herein, Speisenpreise nie (die liest der Service aus dem Concept).

Typischer MCP-Ablauf: Konfigurator anlegen → Concepts verknüpfen → Leistungen aus Vorlagen übernehmen (oder frei mit
EK/VK anlegen) → Pakete anlegen (oder `PAKETE_VORSCHLAGEN` und übernehmen) → `SCHNUEREN` → `PREISE` prüfen → veröffentlichen.

## Umsetzung in Stufen

| Stufe | Inhalt | Fertig, wenn |
|:--|:--|:--|
| 1 | Datenmodell, `KonfiguratorPricingService` inkl. Pakete schnüren, Vorlagen-UI mit EK/VK/Aufschlagsklasse, Vererbung | alle Testfälle aus „Testfälle" grün, DOEC-Beispiel-Datensatz angelegt |
| 2 | Konfigurator-Editor wie im Angebot (Vollbild, Kennzahlen-Leiste, Reiter), Paket-Matrix und Preisvorschau | Konfigurator anlegen, Lunch-/Dinner-Concepts und Add-ons verknüpfen, Leistungen aus Vorlage übernehmen, Pakete schnüren und Mengen überschreiben, Vorschau stimmt mit Service überein |
| 3 | Präsentationstyp `konfigurator`; Einstellungsseite Präsentations-Designs erweitert (Ausgabeform, Konfigurator-Blöcke, eingebautes Design, Beispiel-Vorschau); Branding mit „übernehmen von“; Anfrage → Angebot, Mail, Signal | Design im Builder gestaltet und im Konfigurator gewählt, Branding aus einem Foodbook übernommen, Link live, Snapshot ohne EK (Test), Anfrage erzeugt Angebot, Mail und Signal |
| 4 | MCP-Tools, Handbuch-Seite, DOEC als erster echter Fall | Diva.licious im Food Alchemist nachgebaut, Kevin Gerling bekommt den Link |
| 5 (optional) | Vorlagen-Sets, Auswahl einer Anfrage als Kapitel ins Angebot übernehmen, weitere Sprachen | |

Je Stufe läuft der Testkreis der Wächter mit: Spec84* (Sicht, Tool-Liste, Service-Eigentum), Spec77b, Policy,
Dateigrößen (Editor-Komponenten nach Spec 84 aufteilen, z. B. ein Livewire-Teil je Reiter), BladeCompiles, UiRegeln,
EnumStatusVergleichWaechter. Die Handbuch-Seite (Stufe 4) folgt Spec 86.

Merge als reiner Docs-PR über den Kurator (Spec 82); Freigabe durch Dominique liegt vor (02.11.2026).

## Entscheidungen

Getroffen (Dominique, 02.11.2026):

- Eigenes Modul unter Angebote, eigene Logik, nicht als Tab im Angebots-Editor.
- Der Konfigurator-Editor ist aufgebaut wie der Angebots-Editor: bearbeiten, einfügen, rechnen und Link erzeugen an einer Stelle.
- CI über die Präsentations-Designs mit Auswahl wie beim Foodbook; weiterentwickelt wird die Einstellungsseite, keine eigene CI-Verwaltung.
- Leistungen werden als Vorlagen einmal gepflegt und in Konfiguratoren übernommen.
- Ziel ist ein All-in-Preis je Concept, bereitgestellt als Konfigurator-Link.
- Je Leistung EK und VK; VK über eine Aufschlagsklasse, damit die Kosten sichtbar bleiben.
- Der VK-Vorschlag der Leistungen rechnet wie bei den Speisen über Basissatz und Klassenfaktor.
- Der Kombi-Rabatt für Day-into-Night wird je Konfigurator frei eingestellt, Standard 0 % (DOEC: 5 %).
- Vorlagen erben in der Team-Hierarchie nach unten.
- Vorerst nur Deutsch.
- Anfragen gehen per Mail an die verantwortliche Person und als Signal ins Team.
- Lunch ist ein eigenes Concept aus dem Concepter, kein Faktor auf das Dinner.
- Speisen-EK und -VK kommen aus dem Concept; alle anderen Preise werden von Hand oder per MCP eingetragen.
- Das Modul schnürt die Pax-Pakete selbst (Oberfläche und MCP), mit derselben Rechenlogik.
- Mengenregeln als allgemeiner Baukasten (fest, Schlüssel, Stufen), nicht die Excel-Regeln; Tests prüfen die Logik, DOEC ist Beispiel-Datensatz.
- Paketgrenzen legt der Mensch an; Referenz-Pax (Standard Mitte), Mengen und Preise rechnet das System.
- Preis je Person fest je Paket; Randkontrolle an Unter- und Obergrenze mit Mengen nach Regel für genau diese Gästezahl.
- Das Modul kann Paketgrenzen nach Kosten vorschlagen (Toleranz Standard 10 %); übernommen wird nur auf Wunsch.
- Leistungs-Vorlagen in Phase 1 nicht im Abo und nicht im Stamm-Paket; Mandanten neben dem Master sehen sie nicht.
- Neue Spalte `bereich` an den Aufschlagsklassen, damit Leistungsklassen nicht im Concepter auftauchen.
- Spec freigegeben am 02.11.2026; die Umsetzung kann in Stufen beginnen.

Mit der Freigabe bestätigt (vorher Vorschlag der Spec):

- Handwerte in der Paket-Matrix bleiben beim Neu-Schnüren erhalten und werden markiert, wenn sie von der Regel abweichen.
- Leistungen als Grundausstattung plus Zusatz je Concept/Add-on.
- Vorlagen kopieren statt live verknüpfen, mit Abgleich-Hinweis.
- Day-into-Night über Einsatzzeiten am Konfigurator und Häufigkeit je Leistung (`je_event` / `je_tageszeit`).
- Preistabelle serverseitig, keine Kalkulationsdaten im Kundenlink.
- Branding wird aus Foodbook, Angebot oder Betrieb einmalig kopiert, nicht live gekoppelt. Das Design dagegen bleibt live verknüpft.
- Konfigurator-Blöcke sind Pflicht und kommen je einmal vor; nur Reihenfolge und Stil sind frei.
- Ein Link je Konfigurator, keine Veröffentlichung je Betrieb in dieser Spec.

## Offene Fragen

Keine. Die Spec ist freigegeben.
