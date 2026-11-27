# 98 Umsetzungsplan Code-Qualität (Wellen 1–3)

Stand: 2026-11-19 (nach Gegenprüfung durch Codex), Welle-3-Stand 2026-11-27 · Grundlage: [97 Code-Qualitäts-Audit](97_Code_Qualitaet_Audit.md) · Status: Entwurf

Befund-IDs (K1, B3, U2 …) verweisen auf Bericht 97. Aufwand: S ≤ ½ Tag, M 1–3 Tage, L 1–2 Wochen (jeweils mit Claude-Unterstützung).

## Grundregeln für alle Wellen

- **Test zuerst.** Jeder Fehler bekommt einen Test, der heute rot ist. Die Mess-Tests aus 97 (Abschnitt „Nächste Schritte“) entstehen dabei mit.
- **Sperrklinke statt Großumbau.** Architektur- und UI-Regeln werden als Tests mit Ausgangszählung eingeführt. Die Zahl darf nur sinken, nie steigen. So wird ab Welle 1 nichts mehr schlechter, auch wenn der Abbau Wochen dauert.
- **Ein PR je Paket.** Kleine PRs, je eigener Worktree. Pakete, die viele Blade-Dateien anfassen (1.4, 2.5), laufen allein, nicht parallel zu anderen UI-Arbeiten.
- **Headless-Regel ab sofort:** Neue Funktion = Service-Methode + Tool + UI. Ohne Tool gilt sie als nicht fertig.
- **Testläufe (ab 23.11.2026):** Die volle Suite läuft in GitHub Actions im Fork `DOBE2024-lab/platforms-foodalchemist` (Workflow `suite.yml` auf dem Fork-Branch `ci/suite`, Start per `workflow_dispatch` mit `ref`), nicht lokal. Im Original liegt kein Workflow, und es gibt keine PRs aus dem Fork. Fertige Pakete werden zu Sammel-Chargen gestapelt, der Kopf wird einmal in der Cloud getestet und dann gemergt. Riskante Stufen kommen ans Ende des Stapels. Datenlöschende Pakete (z. B. 2.6c) laufen als eigene kleine Charge. Lokal nur Syntax-Prüfung und kleine Einzeldatei-Läufe mit einem Prozess.

---

## Welle 1 – Richtigkeit, Leitplanken, Fundament (ca. 2 Wochen)

### 1.1 Falsche Zahlen und Sicherheitslücken

| Paket | Inhalt | Ort | Abnahme | Aufwand |
|---|---|---|---|---|
| 1.1a K1 | `kalkulation.GET` mit `package_id` auf Concept `kind=paket` umstellen | `Tools/KalkulationGetTool.php:80` | Test: Paket aus `pakete.POST` wird kalkuliert; alte ID liefert kein falsches Paket | S |
| 1.1b K2 | **Kosten-Folge härten** (der Auslöser existiert seit 18.11.: `KostenFolgeJob`). Fehler aus `recomputeMany` auswerten, damit der Job fehlschlägt und wiederholt wird statt still „erfolgreich“ zu enden; Eindeutigkeit je Team/GP-Satz (`ShouldBeUnique`) | `Jobs/KostenFolgeJob.php:86`, `RecipeRecomputeService.php:180` | Test: Fehler in einem Rezept → Job-Fehlschlag; zwei gleiche Aufträge → ein Lauf | S |
| 1.1c K3 | Sichtbarkeits- und Typprüfung in `ConceptService::fillSlot` für alle drei Zielarten (eingebettetes Concept, altes Paket, Rezept). Die Slot-Beziehungen lösen absichtlich ohne Teamsicht auf (`ohneTeamSicht('verweis')`, Spec 82: Fassungen/Abos) und bleiben so; ein Lese-Leck entsteht nur durch ungeprüftes Schreiben. **Prüfregel: Sicht (`visibleToTeam`/`sichtBedingung` inkl. Vererbung, Inhaltsfreigabe, Abo-Fassungen), nie Besitz** – Mandanten abonnieren Rezepte und bekommen Grundprodukte/Lieferanten über Freischaltung (Stamm-Paket-Opt-in, Stamm-Lieferanten); das ist Fachlogik. Danach Inventur aller Service-Schreibmethoden mit Fremd-IDs (eigenes Paket, s. 1.1i) | `ConceptService.php:463` | Fremd-Team-Tests für Schreiben **und** für Detail-, Kalkulations- und Export-Antworten | M |
| 1.1d U1 | Allergen-Kachel: Rohwert `none` → „unbekannt“ (Label aus dem Model) | GP-Modal | UI-Test auf Label | S |
| 1.1e Zyklen | **Ein transaktionaler Graph-Schreibweg** für alle Kantenänderungen (Zutat → Unterrezept), auch für die heutigen Query-Builder-Updates (`AboKuendigungService.php:893`, `FassungsUmstellung.php:44`), die ein `saving`-Hook nicht sähe. Sperre über beide Rezepte in fester Reihenfolge (bzw. `GET_LOCK`), damit A→B und B→A nicht gleichzeitig durchgehen. `topoOrder` und `explodiere` (`PlanungsblattService.php:727`) melden Zyklen statt still weiterzurechnen | `Rezept/RezeptGraphService.php`, Aufrufer | Mess-Test 3 + 4 aus 97; Parallelitäts-Test auf MySQL (nicht nur SQLite) | M |
| 1.1f U2 | Auswahllisten und Zähler mit gleicher Status-Logik; Entwürfe **wählbar mit Entwurf-Kennzeichnung** (E1). **Freigabe-Sperre neu bauen:** heute prüft `RecipeService::setStatus`/`bulkStatus` die Komponenten nicht – ein Gericht lässt sich mit Entwurfs-Basisrezepten freigeben. Künftig (entschieden 19.11.): **Sperre** – Freigabe von Rezept/Gericht/Concept nur, wenn alle Komponenten (rekursiv) freigegeben sind, sonst Liste der offenen Komponenten. Dazu Knopf **„Alles mitfreigeben“** mit Vorschau der betroffenen Rezepte, nur mit Rolle Kuratieren. Prüfung zentral im Service, damit Einzel-Freigabe, Mehrfachauswahl, Concept und MCP sie alle durchlaufen. Platzhalter (Stub) und Veraltet bleiben in den Auswahllisten ausgeblendet | `IngredientEditor.php:471`, `PaketService.php:533`, `RecipeService.php:475–500` | Chip-Zahl = Listenlänge; Test pro Picker; Test „Freigabe mit Entwurfs-Komponente wird abgelehnt“ (UI, Mehrfachauswahl, MCP); Test „Alles mitfreigeben“ nur mit Kuratieren | M |
| 1.1g B15 | Zählungen ohne Fassungen | `Dashboard.php:55`, `KpiService.php:41` | Test | S |
| 1.1h **neu** | **Aggregate für das neue Paketmodell:** Allergen-/Diät-Rollup, Nährwerte, EK und Arbeitszeit eines Concepts ignorieren eingebettete Pakete (`embedded_concept_id`); nur der Preis berücksichtigt sie. Allergenangaben können dadurch unvollständig sein | `ConcepterAggregateService.php:175`, `ConceptService.php:873` (vgl. `:735`) | Test: Concept mit eingebettetem Paket, das ein Allergen enthält → Allergen im Rollup | M |
| 1.1i **neu** | Inventur: alle Service-Schreibmethoden, die IDs aus Formular oder Tool annehmen; je fehlende Prüfung ein Ticket. Bekannter erster Fall: `RecipeService::bulkStatus` prüft die Rolle „Kuratieren“ nicht, `setStatus` schon | `src/Services`, `RecipeService.php:475` | Liste + Tests für die kritischen | M |
| 1.1j **neu** | **Mandanten-Prüfungen (aus Inventur 1.1i, hoch):** Sicht- und Typprüfung in `FoodbookService::addBlock/updateBlock` (concept_ref), `OfferCompositionService::updateBlock`, `PaketService::syncGerichte`, `RecipeService::syncIngredients` (referenced_recipe_id/gp_id/unit_vocab_id); Status-Änderung über `ConceptService::update` nur mit Rolle; Concept-Export lädt eingebettete Pakete (`ReportExportService::conceptDaten`) | s. Inventur 19.11. | Tests je Feld: Team ohne Sicht → abgelehnt; abonniertes Rezept/Fassung und geerbter Master-Inhalt → erlaubt; Export enthält Pakete und Allergene | M |


### 1.2 Aufräumen

| Paket | Inhalt | Abnahme | Aufwand |
|---|---|---|---|
| 1.2a | Sicher toten Code löschen (~2.050 Zeilen, Liste in 97) samt zugehöriger Tests | Suite grün, Routen-Liste unverändert | S |
| 1.2b | 41 Einmal-Commands + exklusive Services ins Archiv bzw. löschen; 25 Mess-Commands unter `foodalchemist:diagnose:*` bündeln | ServiceProvider kürzer, Suite grün | S–M |
| 1.2c | Root-Artefakte (Import-CSV/JSON, Python) aus dem Modul; `database/database.sqlite` aus Git; `.gitignore` | `git status` sauber | S |
| 1.2d | Command-Registrierung und Zeitplanung aus dem ServiceProvider in `_liste`-Dateien (wie bei den Tools) | weniger Konflikte im Provider | S |

### 1.3 Headless-Fundament

| Paket | Inhalt | Ort | Abnahme | Aufwand |
|---|---|---|---|---|
| 1.3a | **Architektur-Tests mit Sperrklinke**, getrennt nach Regelart: (1) Import-Regel: Services und Tools importieren nichts aus `Livewire\`; (2) Mutations-Regel: keine Schreibzugriffe auf Models/Query-Builder in Livewire und Tools – über einen PHP-Parser mit Empfänger-Erkennung, nicht per Textsuche (sonst trifft `$service->update()` fälschlich); (3) Lesezugriffe in Tools bleiben erlaubt. Ausgangszählung je Datei als Baseline; CI verhindert, dass die Baseline steigt | `tests/Unit/` (neben `UiRegelnTest`) | Test grün, Baseline eingecheckt | M |
| 1.3b | Livewire-Konstanten raus aus Services/Tools (B9) → Enums bzw. Config | `VoiceCommandService.php:336`, `KiKlassenGetTool.php:9` | Regel `livewire_import` bei 0 (inkl. `RecipeImageService.php:15/16`) | S |
| 1.3c | 8 Tools mit direkten Schreibzugriffen auf Services umstellen | `LabNotesPut/Delete`, `OutletPost`, `ProductionStationsPut`, `CanvasPut`, `ConceptsPost`, `LaGpAbgleich`, `PlanungKaskadeStartPost` | Regel `schreibzugriff` für Tools bei 0 (dazu `GpsPutTool.php:123`; `LaGpAbgleichTool:91` erkennt die Regel nicht → von Hand) | M |
| 1.3d | „X aus Brief“ als je eine Use-Case-Methode (`PlanungsEinstiegService`); `Planung/Index` und die 5 `*PlanFromBriefTool` rufen nur noch diese (B3) | `Planung/Index.php:838-1225`, `Tools/*PlanFromBriefTool.php` | Paritäts-Test: UI-Aktion und Tool erzeugen dasselbe Ergebnis | M |
| 1.3e | Schreiblogik aus der Oberfläche in Services: KI-Allergene am GP, Wissens-Bindung als Soft-Delete, Schritt-Editor (Einzel-Operationen in `RecipeStepService`) | `Gps/DetailPanel.php:322`, `Knowledge/Browser.php:454`, `Recipes/StepEditor.php` | Regel `schreibzugriff` sinkt; Tests je Service-Methode | M |

### 1.4 Tailwind-Fundament

| Paket | Inhalt | Abnahme | Aufwand |
|---|---|---|---|
| 1.4a | **Text-Rollen als Klassen** in `@layer components` (`.fa-t-muted`, `.fa-t-label`, `.fa-t-body`, `.fa-t-strong`, `.fa-t-h3/h2/h1`) + `x-fa::text`; in `Ui::maps()` als einzige Quelle verankert | Designsystem-Seite zeigt alle Rollen | S |
| 1.4b | **Codemod** (Skript, wiederholbar): die 10 häufigsten Typografie-Literale und alle 58 lokalen `$leise`-Definitionen auf die Klassen umstellen. Klassennamen immer vollständig ausgeschrieben (der Scanner in `build-css.mjs` erkennt keine zusammengesetzten Namen); responsive Varianten und Zustände erhalten | ~1.000 Stellen weniger; gebautes `resources/dist/foodalchemist.css` im PR; Screenshot-Vergleich von 10 Kernseiten **in der Plattform-Hülle und eigenständig** (Ebenen/Scoping aus `build-css.mjs`) | M |
| 1.4c | Bausteine `x-fa::menu` (64 Dropdowns) und `x-fa::checkbox` (157 rohe Checkboxen) bauen und einsetzen | Zählungen in 97 sinken entsprechend | M |
| 1.4d | `UiRegelnTest` hat schon dateiweise Zähler und Nulltoleranz für Komponenten; erweitern um Typografie-Literale, `$leise`-Definitionen, rohe `<button>`/`<input type=checkbox>`, alte Paletten-Klassen (`text-gray-*`). Ausnahmen für die Baustein-Dateien selbst; CI-Prüfung, dass Baselines nicht steigen | Test grün mit Baseline | S |

**Reihenfolge Welle 1:** 1.3a + 1.4d zuerst (Leitplanken), dann 1.1 vollständig, parallel 1.2. 1.4b/c als letztes Paket der Welle, allein.

---

## Welle 2 – Tempo, Doppelungen, Headless vollständig, UI-Konsistenz, Pakete (ca. 5–6 Wochen)

### 2.1 Rechenwege schneller

| Paket | Inhalt | Abnahme | Aufwand |
|---|---|---|---|
| 2.1a | Recompute: Lauf-Memo mit Schlüssel **Team + GP** (Preise sind teamabhängig, `RecipeRecomputeService.php:1402–1408`), Batch-Abfragen, `laProfile` nur einmal, LA-Cache je Lauf und Team statt je Rezept | Mess-Test 1: Abfragen je Gericht mit 12 Zeilen < 20 | M |
| 2.1b | Eltern-Neuberechnung nach dem Speichern per Queue-Job (unique, entprellt); eigenes Rezept bleibt synchron | Speichern am Fond antwortet ohne Eltern-Pipelines | M |
| 2.1c | `pruefeVerknuepfung` verschlanken (keine Tiefen, nur geänderte Zeilen); **ein** Graph-Dienst mit Besucht-Liste ersetzt alle 3er-Limits; `PROPAGATION_LIMIT` weg; Doku/Regelwerk auf A-5 | Mess-Test 7 (Ebene 5) grün | M |
| 2.1d | Planung: Poll in schlanke Status-Komponente; Daten nur für aktiven Tab; Lauf einmal laden | Mess-Test 5: < 10 Abfragen je Poll | M |
| 2.1e | Concepter Aufbau-Tab: Formen/Darreichungen aller Slots vorladen | Mess-Test 6 | S–M |

### 2.2 Doppelte Logik zentral

| Paket | Inhalt | Abnahme | Aufwand |
|---|---|---|---|
| 2.2a | Geld-Helfer: Wareneinsatz (18×), EK je Portion (3×), kg↔g (7×) über `MargeService`/`DarreichungResolver`/`vocab_units` | Grep-Sperre im Regel-Test | S |
| 2.2b | Ein Text-Normalisierer (`Support/Text::falte()` mit benannten Varianten) statt 27 Kopien; eine Allergen-Label-Quelle | Matching-Tests grün, Grep-Sperre | S–M |
| 2.2c | `RecipeModal` und `VkModal`: gemeinsamer Concern für Medien/KI/Sensorik | 23 Doppel-Methoden → 0 | M |

### 2.3 Headless vollständig

| Paket | Inhalt | Abnahme | Aufwand |
|---|---|---|---|
| 2.3a | Settings-CRUD (10 Seiten: Betriebe, Aufschlagsklassen, Behälter, Einkauf, Posten, Rollen, Schreibstile, Wissenskategorien, Wissenssteuerung, Concepter-Dimensionen) über Services | Regel `schreibzugriff` für Settings bei 0 | M |
| 2.3b | `Concerns/ManagesCanvas` → `CanvasService` | Regel `schreibzugriff` sinkt | S–M |
| 2.3c | Fehlende MCP-Tools: Foodbook löschen/duplizieren, Kapitelbild/Cover/Logo, manuelle Produktionszeilen, Concept-Kalkulation mit Gästezahl, Dichteklasse/Feld-Herkunft. Schritte: `RecipeStepsPutTool` ändert schon gezielt per Schritt-ID; nur Schrittfotos und die Status-Sperre (`:81`) prüfen | Tool-Liste + je ein Tool-Test. Löschen bewusst nicht per MCP (Entscheid human-only) | M |
| 2.3d | **Paritäts-Tests** je Kernablauf (Rezept, Gericht kalkulieren, Concept, Foodbook, Produktion): UI-Aktion und Tool → gleiches Ergebnis | 5 Tests grün | M |
| 2.3e | Regel `schreibzugriff` für Livewire auf 0 bringen (Rest nach 1.3e/2.3a/b) | Baseline-Datei leer | M |
| 2.3f | **Mandanten-Prüfungen Rest (mittel/niedrig aus Inventur 1.1i):** Status nur über setStatus in `AngebotService::update`/`FormatService::update`; `presentation_id` gehört zum Gericht (Angebot, Speisekarte); `moveKapitel` nur innerhalb desselben Foodbooks/Angebots; `PlanningFrameService::addSlot/updateSlot` chapter_id; Vokabular-IDs (service_moment, serving_form, writing_style, device, equipment); `crm_*` über `CrmKunden::pruefe` in Foodbook/Speisekarte/Speiseplan; `WareneingangService::pruefeZeilen`; Kategorie/Klasse parent_id; `FoodbookService::uebernehmeGericht/uebernehmeVorschlag`, `toggleEinsatzmoment/toggleZielgruppe`, `setKapitelZielgruppen`. Außerdem: `FormatService::create` übernimmt `status` direkt (Rolle prüfen); `OrderCostingService::costConcept` auf eingebettete Pakete prüfen (vgl. 1.1h). Noch nicht inventarisiert: Inventur, Lager, Etikett, Trend*, Ai/*, Pairing | Fremd-Team-Tests | M–L |

### 2.4 UI-Konsistenz

| Paket | Inhalt | Abnahme | Aufwand |
|---|---|---|---|
| 2.4a | Restliche Bausteine: `table`, `card`, `upload`, `segmented`, `page`; `modal`, `modal-section`, `ki-action` in den `x-fa`-Namensraum | Zählungen in 97 sinken; ein Namensraum | M |
| 2.4b | Detail-Muster bleibt wie heute (E2). Nur korrigieren: leeres GP-Panel kündigt „anklicken, dann erscheinen hier …“ an, der Klick öffnet aber das Modal → Hinweistext anpassen, leeres Panel eingeklappt | kein irreführender Hinweis | S |
| 2.4c | **Ein Anlege-Muster:** erst Dialog mit Name, dann Datensatz (Concept, Foodbook) | keine „Neues …“-Leichen mehr | S |
| 2.4d | **Löschen einheitlich:** im selben Menü wie die übrigen Aktionen, auch im Ansichtsmodus erreichbar, App-Dialog statt `confirm()` | U9 erledigt | S |
| 2.4e | **Zahlen-Konsistenz (U5):** Kopf-KPIs und Tabellen aus derselben Quelle; Live-Werte vor dem Speichern als „vorläufig“ kennzeichnen | Foodbook, Rezept, Gericht, GP-Pill ohne Widerspruch | M |
| 2.4f | Benennung (U8): ein Name je Seite in Navigation, Kopf und H1; „Bearbeiten“ nur für den Modus; Concept/Konzept vereinheitlichen; technische Kennungen ausblenden | Begriffsliste im Designsystem | S |
| 2.4g | Zutatensuche (U7): Treffer direkt unter dem Suchfeld, exakte Grundprodukte zuerst | „Butter“ → „Butter: frisch“ oben | S–M |

### 2.5 Taxonomie Teil 1

| Paket | Inhalt | Abnahme | Aufwand |
|---|---|---|---|
| 2.5a | Ein Sektor-Vokabular (heute 3 abweichende) | eine Quelle | S |
| 2.5b | Planung liest Saison, Anlass, Serviceform aus den DB-Vokabularen statt aus `Planung::*_OPTIONEN` | Konstanten weg | M |

### 2.6 Paket → Concept abschließen (vorgezogen, E3)

| Paket | Inhalt | Voraussetzung | Abnahme | Aufwand |
|---|---|---|---|---|
| 2.6a | Bestandsaufnahme **früh starten** (parallel zu Welle 1): Pakete, Abos, Kunden-Fassungen, Kündigungskopien (`PaketFassungService.php:237`, `AboKuendigungService.php:765`), Angebote mit `package_id`; ID-Mapping alt → neu; Migrationsskript mit Trockenlauf und Rückweg | 1.1a, **1.1h** | Trockenlauf-Report ohne Fehler | M–L |
| 2.6b | Daten migrieren, Abos und Fassungen umhängen; `package_id` ab dann nur noch lesend | 2.6a, Backup | Paritäts-Report alt = neu für Preise, Allergene/Diät, Nährwerte, Mengen/Einheiten, Darreichungen, Rechte, Fassungsherkunft und historische Referenzen | M–L |
| 2.6c | `/pakete`, `/concepts` und den alten Editor-Zweig abschalten; `PaketService`, `PaketPreisService`, `PaketFassungService` und die **alte** Persistenz entfernen. Paket-Tools, die schon das neue Modell bedienen (`PaketePostTool`, `PaketeDeleteTool` …), **bleiben**; nur Tools auf der alten Tabelle umstellen oder ablösen; Übergangsparameter `abo_paket_id` in `kalkulation.GET` (aus 1.1a) entfernen | 2.6b, 2 Wochen ohne Befund | 0 Treffer `FoodAlchemistPaket`; MCP-Paket-Tools weiter grün | M |

**Bestand io (19.11., nur lesend gezählt):** 3 Alt-Pakete (Team 3, „Green & Tasty“ ×3), 9 Positionen, 0 Fassungen, 0 angebotslokal; 0 Verweise aus Concept-Slots, Speiseplan, Bestellvorlagen, Abo-/Sammlungs-/Freigabe-Objekten. Die drei haben bereits Concept-Kopien (uuid-Reuse, Concepts 11–13), die aber am 13.11. **gelöscht** wurden (soft delete) – **Entscheid Dominique 19.11.: „Green & Tasty“ wird noch benötigt** → in 2.6b die drei Pakete sauber ins neue Modell überführen (Kopien 11–13 wiederherstellen oder per `pakete-to-concepts --id` neu erzeugen; vorher prüfen, ob die Alt-Pakete nach dem 24.08. noch geändert wurden und die Kopien damit veraltet sind) und Parität alt = neu nachweisen. Die Datenmigration ist damit klein; der Aufwand von 2.6 liegt im Code-Abbau (22 Services, 5 Tools, 6 Livewire-Komponenten, 56 Testdateien, Liste von Julia). Risiko Dezember: laufende Abos und Angebote. Deshalb 2.6b an einem ruhigen Tag mit Backup, 2.6c erst nach zwei Wochen Beobachtung. Gesamtaufwand 2.6 realistisch **L** (nicht 3 × M).

**Usertest Teil B** idealerweise zwischen 2.3 und 2.4: Dann sind die Abläufe stabil, und das Ergebnis steuert, welche UI-Pakete zuerst kommen. Vorher Sandbox-Mandant mit freigegebenen Testdaten füllen.

---

## Welle 3 – Struktur (ca. 5–7 Wochen, schrittweise, je Paket ein eigener Zeitplan)

| Paket | Inhalt | Voraussetzung | Abnahme | Aufwand |
|---|---|---|---|---|
| 3.1 | `package_id`-Spalten und alte Paket-Tabelle endgültig entfernen (nach 2.6) | 2.6c | Migration, 0 Treffer | S |
| 3.2 | **Fassungs-Basisklasse** für die 3 verbleibenden Fassungs-Services (Hash unverändert) | 2.6c (PaketFassung entfällt) | ~500 Zeilen weniger, Hash-Tests grün | M |
| 3.3 | **Große Klassen zerlegen** (nach 3.4): `Planung/Index` (Leitplanken, Board, Kaskaden-Lauf, Voice-Brücke), `PlanningCascadeService` je Ausgabeart, `OrderService`, `SpeiseplanService`, `FoodbookService`; `Produktion/Tagesplan` nach Modus trennen | 1.3d, 2.1d, **3.4** | keine Klasse > 1.500 Zeilen | L |
| 3.4 | **Zuerst:** Abhängigkeitsrichtung Rezept → Concepter → Foodbook und Schnittstellen festlegen, bevor zerlegt wird; bestätigter Kreis: `PlanningCascadeService.php:1305` ↔ `RecipeDependencyWorkflowService.php:282`, außerdem `RecipeRecomputeService` → `PaketService`. Rückkanäle über Events; als Architektur-Test | 1.3a | Kreis-Test grün | M |
| 3.5 | **Datenmodell bereinigen:** gespiegelte Darreichungsfelder am Rezept entfernen (B11/U6), `sales_recipe_id` → `recipe_id`, Fremdschlüssel + Waisen-Prüfung, 47 veraltete Aliase weg | 2.6c | Migrationen + Waisen-Report 0 | M–L |
| 3.6 | **Gemeinsamer Positions-Kern** (E4): Tabellen von Concept-Slot, Foodbook-Block, Angebots-Block bleiben getrennt; Preisbasis, Staffel, Darreichungswahl, Wahlgruppen und Validierung in einen gemeinsamen Baustein (Trait + `PositionPreisService`); MCP-Tools nutzen dieselbe Validierung. Format- und Planungsrahmen-Slots prüfen, ob sie dazugehören | 2.6c | gleiche Preis-Tests laufen für alle drei Zeilenarten; Foodbook und Angebot rechnen dasselbe Concept identisch | M–L |
| 3.7 | **Taxonomie Teil 2:** Rollen-Flags an Warengruppen (Getränk, Non-Food, kühl, fertig) statt Code-Literale `'15'`/`'16'`/`'09.6'` | – | 0 Literale; Regel-Test | M |
| 3.8 | **Ordnung:** Services in Fachordner (Rezept, Concepter, Foodbook, Produktion, Einkauf, Wissen, Planung); Qualitäts-Engines (DataQuality, SignalDetektor, Reife, Conformance) zu einer Prüf-Registry | 3.3 | flacher Root < 50 Dateien | L |
| 3.9 | Dunkle Editor-Ebene bleibt bewusst (E5); nur Kontraste nach WCAG AA prüfen und Übergang weicher machen | 2.4 | Kontrast-Check grün | S |

### Stand Welle 3 (27.11.2026, main `ded67c013`, alles auf io live)

| Paket | Stand | Abnahme |
|---|---|---|
| 3.1 | **abgeschlossen** (#1010, io 27.11., Migration `2027_01_13_100000_drop_alt_pakete`, Batch 85; volles Backup vorher) | Riegel 0 Treffer; `foodalchemist_packages`/`_package_dishes` und `package_id` an Slots und Speiseplan-Einträgen weg |
| 3.2 | **abgeschlossen** (#992) | Doppelungen weg, Inhalts-Hash bit-gleich (Zwillingstest) |
| 3.3 | **abgeschlossen** (Speiseplan, Foodbook, Kaskade; 27.11.: #1011–#1018 KnowledgeContext, RecipeGenerator, Order, DataQuality, Angebots-Editor, Foodbook-Editor, Pairing) | keine Klasse unter `src` > 1.500 Zeilen, `DateigroessenBasis` leer; je Schnitt PR0-Charakterisierung (Golden auf altem Code) |
| 3.4 | **abgeschlossen** | Rückkanten 2 → 0; Kreis-Klinke `klassen_in_kreisen` 60 → 51, größte Komponente 12 → 7 (darf nur sinken) |
| 3.5 | **abgeschlossen bis auf 1c** (Plan `98_3-5_Plan_Datenmodell.md`; io 27.11.): Schritte 1a/1b/2 (#1020, #1024), FKs der Kernkette (#1023, 48 FKs, restrict/set null), `sales_recipe_id` → `recipe_id` (#1025, MCP-Alias 4 Wochen), 6a Leser auf die Standard-Darreichung, Teiler fest 1 (#1026–#1030), 6b Schreiber gestoppt + 6c Spiegelfelder entfernt (#1031–#1033, Migration `2027_01_16_100000`, Batch 87, Sicherung 16 Zeilen in `foodalchemist_spiegelfelder_backup`; volles Backup vorher). Schritt 3 entfällt. **Offen 1c:** die 39 alten Aliase entfernen, nach Auswertung des `[fa-alias]`-Logs am 04.12. | Waisen-Report 0 ins Leere (614 Verweise auf soft-gelöschte Eltern, gewollt); `diagnose:spiegelfelder` meldet entfernt; Preis-Cache unverändert |
| 3.6 | **abgeschlossen** (PR0–PR6, #1003–#1009) | Konzept, Foodbook und Angebot rechnen über `PositionGericht`/`PositionPreisService`; eine Schreibprüfung `PositionValidierung`; EK-Lücke sichtbar (`ek_incomplete`) |
| 3.7 | **abgeschlossen** (`WgRolle`; Rest #1021: `SUB_KNABBEREIEN`/`SUB_SPROSSEN` als benannte Unterkategorien) | 0 Literale; Regel-Test |
| 3.8 | **abgeschlossen** | Root von `src/Services` = 3 Dateien; Prüf-Registry |
| 3.9 | **abgeschlossen** | WCAG AA, weicher Modal-Übergang |

---

## Entscheidungen

| ID | Frage | Entscheid | betrifft |
|---|---|---|---|
| E1 | Entwürfe in Auswahllisten | **entschieden 19.11.: wählbar mit Kennzeichnung; Freigabe-Sperre über alle Ebenen; „Alles mitfreigeben“ mit Vorschau; Umsetzung mit Welle 1** | 1.1f |
| E2 | Detail-Muster | **entschieden 19.11.: bleibt wie heute** | 2.4b |
| E3 | Zeitpunkt der Paket-Umstellung | **entschieden 19.11.: vorgezogen in Welle 2** | 2.6 |
| E4 | Positionsmodell | **entschieden 19.11.: Tabellen getrennt, gemeinsamer Code** | 3.6 |
| E5 | Hell oder dunkel | **entschieden 19.11.: dunkel bleibt bewusst** | 3.9 |

## Grobe Zeitleiste

| Zeitraum | Inhalt |
|---|---|
| KW 47–48 | Welle 1 |
| KW 49–KW 3/2027 | Welle 2 inkl. Paket-Umstellung 2.6 (über die Feiertage nur 2.1/2.2; 2.6b nicht in der Woche vor Weihnachten) |
| ab KW 48 | Welle 3 Paket für Paket, parallel zum Rest von Welle 2. Usertest Teil B entfällt (Entscheidung 23.11.2026) |

## Gegenprüfung durch Codex (2026-11-19)

Unabhängige Prüfung des Plans gegen den Code (Codex CLI, nur lesend). Eingearbeitet:

| Punkt | Ergebnis | Folge im Plan |
|---|---|---|
| K2 Preisänderung | Seit 18.11. gelöst (`KostenFolgeJob`, Commits `0bbc6056`, `35528f62`) | 1.1b nur noch Härten (Fehler, Eindeutigkeit) |
| Aggregate neues Paketmodell | **Neu, bestätigt:** Allergen-/Diät-/Nährwert-Rollup ignoriert eingebettete Pakete | neues Paket 1.1h, Voraussetzung für 2.6 |
| K3 | Auch Lese-Leck über Beziehungen ohne Teamsicht | 1.1c erweitert, Aufwand M; Inventur 1.1i |
| Zyklus-Hook | `saving`-Hook greift nicht bei Query-Builder-Updates; Sperre nur am Eltern-Rezept reicht nicht | 1.1e: ein Graph-Schreibweg, Sperre über beide Rezepte |
| Architektur-Sperrklinke | Textsuche trifft erlaubte Service-Aufrufe | 1.3a: Parser-basiert, Regeln getrennt, Aufwand M |
| UI-Sperrklinke | Infrastruktur existiert schon | 1.4d kleiner, CI-Prüfung ergänzt |
| Tailwind-Codemod | Scanner und CSS-Ebenen sind ein Risiko | 1.4b: Build und Screenshot-Vergleich in der Abnahme |
| GP-Memo | Preise teamabhängig | 2.1a: Schlüssel Team + GP |
| Paket-Umstellung | Sinnvoll, aber unterschätzt; neue Paket-Tools nicht löschen | 2.6: Aufwand L, frühere Bestandsaufnahme, breitere Parität, Tools bleiben |
| Wellenfolge | Abhängigkeitsrichtung vor dem Zerlegen festlegen | 3.4 vor 3.3 |
| Zählungen | `_liste.php` hat 722 Einträge (nicht 719); Einzelschritte per MCP teilweise möglich; Kreis Foodbook ↔ Kaskade nicht direkt bestätigt | in 97 und 2.3c korrigiert |
