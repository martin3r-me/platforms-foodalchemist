# Spec 85 · Wartbarkeit und technische Schulden

Stand 2026-10-18 · Status: **v1.0, vom Kurator freigegeben 18.10.; Umsetzung ab 19.10.** · Branch `docs/spec85-wartbarkeit`
Bezug: Spec 84 (Teil C ausgelagert hierher; Team-Sicht bleibt in 84), Specs 53–83 (offene Punkte geprüft)
Messbasis: Modul `platforms-foodalchemist` **eaad31b7** (main 18.10.2026). Quellen der Inventur: Code-Durchsicht (Marker, toter Code), Test-Harness und Wächter-Listen, Specs 53–84 gegen den Code geprüft, Schuldenliste von Session „test“ (`00_INBOX/_Spec85_Schuldenliste_test_2026-10-18.md`), Lücken aus der Session vom 18.10. (Kurator).

> **Einstieg für die bauende Session:**
> 1. §2 ist die **Inventur** aller bekannten Schulden (Ort, Schwere, Beleg, Zuständigkeit, Vorschlag). §3 ist der **Plan** daraus.
> 2. Zuerst Welle 1 (§3): Größen-Wächter, Löschen `alleFrei`/`composerGeneriere`, Test-Ballast. Dann C1 (§5).
> 3. Alles Pfadfinder-Markierte wird beim nächsten fachlichen Eingriff in die Datei erledigt; der Wächter verhindert bis dahin Wachstum.
> 4. Team-Sicht-Themen (Spec 84 B3–B5, A-4, E-1/E-3) stehen hier nur als Querverweis.
> 5. Push/PR läuft über den Kurator.

## §0 · Warum

Dominique, 18.10.2026: „Senior-Dev-Standard: das System muss wartbar sein.“ Diese Spec bringt **keine neue Funktion**. Sie sammelt, was im System nicht sauber ist, bewertet es und legt fest, in welcher Reihenfolge und nach welchen Regeln es aufgeräumt wird. Spec 84 endet mit der Team-Sicht (Stufe 4, A-4/B4 werfen, E-3, B5); ihr Teil C lebt hier weiter.

**Schwere:** **hoch** = kann still falsche Daten, Preise, Allergene oder Mandanten-Lecks erzeugen oder blockiert Betrieb · **mittel** = Wartungslast, doppelte Wahrheit, Lücke in der Absicherung · **niedrig** = Kosmetik, Ballast, Komfort.

## §1 · Regeln

Aus Spec 84 §5.0, verbindlich für jeden Aufräum-PR:

- **Reines Verschieben** ohne Verhaltensänderung, **ein Schnitt je PR**. Route, äußerer Komponentenname, Events und Event-Namen bleiben.
- **Prüfbar als Verschiebung:** `git diff --color-moved`. Logik-Auslagerungen in **eigene Commits**, getrennt von der Verschiebung.
- **Gleiche Tests vorher und nachher**, umgeschriebene Tests im PR mit alt → neu; danach ein **breiter Lauf** über alle grep-betroffenen Klassen (nur wenn der Rechner frei ist).
- **Sperrklinke:** Jeder Schnitt-PR senkt die Basis im Größen-Wächter (§4).
- **Vorher offene Arbeit prüfen:** offene PRs und parallele Sessions an der Datei klären.
- **Erst entscheiden, dann löschen** statt verschieben. Toter Code wandert in kein Kind. „Nur in Tests aufgerufen“ ist ein Anlass zu fragen, kein Freibrief: vor dem Löschen Spec und PR-Historie prüfen.
- **Eine Zusammenführung ist keine Verschiebung** (eigener PR).
- **Prüffrage (Spec 84 §6.8):** Wächst eine Livewire-Komponente über ~800 Zeilen, fragt der Review nach Nicht-UI-Logik.
- **MySQL-Prüfung bei Abfrage-Umbauten:** Pest läuft nur auf SQLite. Neue `GROUP BY`-Abfragen und Migrationen vor der Meldung gegen die lokale MySQL prüfen (nur lesend bzw. hin/zurück), siehe I2-1.
- **Daten-Änderungen auf io nur durch den Kurator:** immer Trockenlauf → Backup → apply → verify, mit Protokoll für den Rückweg (so bei #493).

## §2 · Inventur

Quelle: **V** = Vanessa (Code-Durchsicht/Session), **T** = Session „test“ (Nummer seiner Liste), **K** = Kurator-Lückenliste 18.10., **S** = Spec-Durchsicht 53–84.

### I1 · Sicherheit und Mandanten-Trennung

| # | Ort | Befund | Schwere | Beleg | Zuständig | Vorschlag | Quelle |
|---|---|---|---|---|---|---|---|
| I1-1 | `src/Tools/**` (MCP-Schreibtools) | Bearbeitungssperre wird nicht geprüft; KI/MCP schreibt in einen gesperrten Datensatz | **hoch** | `BearbeitungssperreService` in 0 Tool-Dateien | Vanessa | Sperr-Prüfung in der gemeinsamen Tool-Hülle (`FaRechteToolHuelle`), Antwort `gesperrt` | S (Spec 65) |
| I1-2 | `BearbeitungssperreService` | keine Versionsprüfung beim Speichern (Schutz 2 aus Spec 65): paralleles Speichern überschreibt still | **hoch** | Service kennt nur sperren/haelt/verlaengern/freigeben | Vanessa | Versionsfeld (`updated_at`-Vergleich) im Speichern der Editoren | S (Spec 65) |
| I1-3 | Spec 65 Pakete B–E | Teil-Editoren speichern sofort statt als Entwurf | mittel | keine B–E-Commits | Kurator/Dominique | entscheiden, ob B–E noch gebraucht werden, sonst Spec 65 schließen | S |
| I1-4 | `tests/Feature/FormatDimensionenTest.php:62` | Cross-Team-Attach-Test läuft **nie** (braucht `TeamFactory`, die es nicht gibt) | **hoch** | `->skip(! class_exists(TeamFactory))`, 0 Factories im Repo | Vanessa | Team über den Seed-Helfer anlegen, Skip raus | V |
| I1-5 | `tests/Feature/WissenMasterUnsichtbarP2Test.php:105` | Sicherheitstest überspringt sich, wenn das Bindings-Tool umbenannt wird | mittel | Skip per Tool-Name | Vanessa | fehlschlagen statt überspringen | V |
| I1-6 | ~90 direkte `Gp::find`/`SupplierItem::find`-Lesestellen | Stufe 3 deckt sie nur, soweit die Suite sie durchläuft | mittel | Spec 84 Stufe 3 | test | Sweep mit Urteil je Stelle, Test aus fremdem Kontext | T1, K |
| I1-7 | `TeamScope::applyVisible` / rohe `DB::table`-Lesestellen (IngredientEditor:675, RecipeReview/Revise, Sensorik, Pairing, DataQuality) | TeamSicht greift dort nicht | mittel | Liste T3 | test | je Stelle `applyVisible` oder Begründung | T3 |
| I1-8 | eigene Kettenlogik (`TeamSettingsService::ahnenZeilen`, `BriefTemplateService::sichtbar`, `CanvasService`/DnaResolver, `PostenAufloesung`) | eine Sichtregel, mehrere Umsetzungen | mittel | T4 | test | zentraler Helfer `TeamSicht::kette` | T4 |
| I1-9 | 133 `'verweis'`-Relationen | neue Preis-/Rechtewege darüber umgehen den Riegel | mittel | T6 | test | Konvention festhalten + Wächter „Preiswege nur über sichtbare Klassen“ | T6 |
| I1-10 | `RecipeGeneratorService` (`DishMainGroup::find`, `klasseAusVorschlag`) | ungefiltert gelesen | niedrig | T8 | test | `visibleToTeam` | T8 |
| I1-11 | `markupKaskade` Zweig „gesetzt“ | Rezept mit fremder Klasse behält sie (io: 0 Rezepte) | niedrig | T7 | test | nach dem Rezept-Import Daten prüfen | T7 |
| I1-12 | `tests/Support/Spec84SichtAusnahmen.php` | 120 Abfragen ohne Sicht-/Eigentumsprüfung in 40 Dateien, „bis B3 greift“ | mittel | Liste | test | über B3/B5 abbauen (Spec 84) | V |
| I1-13 | `Feature/PolicyTest.php:80–86` | Sichtweite `BulkProposal` „als Befund hochgegeben, nicht entschieden“ | niedrig | Docblock | Kurator/Dominique | entscheiden | V |

**Querverweise Spec 84 (dort zuständig):** Stufe-4-Sweep der strikten `where('team_id', …)`-Lesestellen (T2), `team_sicht.stufe` einschalten (im Code Standard 0), B4 „werfen“ (heute nur Log, T21), `TeamKontextFehlt` in Console/Queue streng (T22), A-3 Widerspruch Job-/Versand-Team nur Warnung, B5 1.136 redundante `visibleToTeam` entfernen, Stufe-4-Kappung bei Baumänderung (T5).

### I2 · Tests und Test-Harness

| # | Ort | Befund | Schwere | Beleg | Zuständig | Vorschlag | Quelle |
|---|---|---|---|---|---|---|---|
| I2-1 | `tests/TestCase.php:60–76` | Pest läuft nur auf SQLite; MySQL-Fehler rutschen durch (#496: `only_full_group_by`, Seite 500 auf io) | **hoch** | Hotfix #497; Laufzeit-Guard gegen persistente DB | Vanessa | (a) MySQL-Spur für ausgewählte Klassen (eigene Test-DB), (b) Wächter je Abfragetyp wie in #497 (keine `tabelle.*` in GROUP-BY-Abfragen) | K, V |
| I2-2 | Fixtures `master_team_id = rootTeam` (71 Stellen), Probe-Schalter `FA_PROBE_STAMM_PAKET` nicht committet | Fixtures bilden den io-Aufbau nicht ab; 114 Tests rot unter der Stamm-Paket-Verteilung | mittel | Spec 84 v1.3 | test | zweite Fixture-Familie „io-Aufbau“, Probe-Modus regulär, die 114 Tests bewusst markieren | T13, T14, K |
| I2-3 | `Livewire::test` | layout-blind; kein Browser-Check in der Kette (#496-Seite war im Test grün) | mittel | Memory „Test-Harness layout-blind“ | Kurator | nach Deploy Pflicht-Sichtprüfung neuer Seiten; mittelfristig ein Ganzseiten-GET je Route | K |
| I2-4 | Vollsuite | 2 h 35 min; `fa_test.sh` nicht versioniert | mittel | T17 | test | Runner versionieren, Suite in schnell/langsam teilen | T17, K |
| I2-5 | Skips „Bedingung ohne Wirkung“ | `isAiUser`-Prüfungen (PlanGebuehren:135, Mandanten:77, PlattformSteuerung:113), die es längst gibt | niedrig | Core `User::isAiUser` vorhanden | Vanessa | Bedingungen entfernen | V |
| I2-6 | Skips per `Route::has` (OrderService:1398/1414, ProductionOrderService:299/618, Spec82AboOberflaeche:186) | vermutlich überholt, seit Routen im Harness registriert werden | niedrig | SeedsTeamHierarchy:166–176 | Vanessa | mit Lauf bestätigen, dann Skips raus | V |
| I2-7 | Excel-Import (PreislistenUpload:57/73, PreislisteVorlage:57) | lokal ungetestet, openspout fehlt in der Sandbox | mittel | vendor ohne openspout; `PreislistenImportService.php:485` „vorerst CSV“ | Kurator | klären, ob openspout auf io/demo da ist; in die Sandbox aufnehmen | V |
| I2-8 | `WissenssteuerungUiTest:158`, `Spec82AboOberflaecheTest:186` | Ganzseiten-Render braucht Passport-Tabellen (fehlen im Harness) | niedrig | SeedsTeamHierarchy:140–146 | Vanessa | `oauth_access_tokens` in die Core-Allowlist | V |
| I2-9 | `EignungPflegeTest:97` (M6-05), `ProduktionAbarbeitenTest:216` (Spec 35), `DossierVorspannTest:85` | Skips ohne Bedingung, an offene Entscheidungen gebunden | niedrig | Skip-Gründe | Kurator/Dominique | entscheiden oder Test löschen | V |
| I2-10 | `Fixtures/ui_regeln_baseline.json` | 681 UI-Verstöße in 45 Views (Hex 431, statischer style 126 …); in `dokumente/*` teils gewollt (Branding) | niedrig | UiRegelnTest | Pfadfinder | Druckvorlagen ausnehmen, Rest beim Eingriff abbauen | V |
| I2-11 | `PolicyTest.php:~107–228` | 15 Ausnahme-Zweige inline statt in einer Liste | niedrig | V | Vanessa | in eine benannte Liste wie die anderen Wächter | V |
| I2-12 | `SeedsTeamHierarchy.php:17, 30–38` | Docblock „LogsActivity ist No-op-Stub“ veraltet (seit #308 echt) | niedrig | V | Vanessa | Doku korrigieren | V |
| I2-13 | `ohneTeamSicht('test')` in Test-Helfern | Workaround | niedrig | T15 | test | Helfer setzen den Kontext ausdrücklich | T15 |
| I2-14 | Pest `--filter` | trifft den Worktree-Namespace | niedrig | T16 | test | nur auf `Klasse::` filtern, dokumentieren | T16 |
| I2-15 | Zeitstempel-Flakes (`*_at` ±1 s) | – | niedrig | T18 | test | `Carbon::setTestNow` | T18 |
| I2-16 | `Spec84VersandOhneRequestTest:59` (`->todo`) | A-4 nach einer Woche io-Log | – | Spec 84 | test | Querverweis Spec 84 | V |

### I3 · Doppelte Wahrheit, Altspalten, Übergangs-APIs

| # | Ort | Befund | Schwere | Beleg | Zuständig | Vorschlag | Quelle |
|---|---|---|---|---|---|---|---|
| I3-1 | VK-Altspalten `recipes.sales_net` u. a. neben der Standard-Darreichung | überall Rückfall auf die Altspalte: SpeisekarteService:25/564, MenuEngineeringService:142/167, MenuCandidatePoolService:71–160, ConcepterAggregateService:85/247, SalesRecipeService:491–573 (spiegelt), DarreichungService:91/177–211/671, VkModal:498; `MoneyTruthReport` existiert nur wegen dieser Doppelung | **hoch** | Kommentare „Legacy-Fallback“ | Kurator/Dominique | Stichtag: MoneyTruthReport auf io = 0 Abweichungen → Rückfälle entfernen, Spiegeln einstellen, Spalten droppen (eigene Spec-Etappe) | V |
| I3-2 | deutsch-englische Alias-Relationen, 19 Models (Recipe 10, Concept 8 …) | `@deprecated` (Refactor „#486 Phase 1“, alter Ticket-Bezug, nicht PR #486), deutsche Namen noch als Eager-Load-Strings genutzt (AngebotService:397, ConceptService:76, GpService:60, SalesRecipeService:279/282, RecipeService:151/174, MenuCandidatePoolService:52/108 …) | mittel | 48 Aliase | Pfadfinder | Phase 2: Aufrufer umstellen (Rector/PHPStan für Allerweltsnamen), dann Aliase löschen; `Recipe::standardDarreichung()` hat schon 0 Aufrufer | V |
| I3-3 | `WordingResolver.php:82–96`, `FoodbookService:2004/2241` | `block.customer_text` als Legacy-Stufe vor `wording` | mittel | V | Pfadfinder | Daten migrieren, Stufe entfernen | V |
| I3-4 | `GpFormService:16/100/108` | Form „stk“ wird ins Legacy-Feld `piece_default_g` gespiegelt | mittel | V | Pfadfinder | Leser umstellen, Spiegel weg | V |
| I3-5 | `RebateService:52/130/204/396`, supplier-detail.blade:14 | flacher `suppliers.rebate_pct` „Bestandsschutz“ neben Staffeln | niedrig | V | Pfadfinder | Bestand migrieren | V |
| I3-6 | `RecipeImageService:252`, `ReportExportService:1222`, `FoodAlchemistMediaService:38` | Legacy-`pfad` auf der public-Disk neben ContextFile | mittel | V | Pfadfinder | Restbestand umziehen, Pfad-Zweig löschen | V |
| I3-7 | `RecipeService:740–767` | `AUSGABE_REFERENZEN_LEGACY` (`package_dishes.sales_recipe_id`) mit `Schema::hasTable` | niedrig | V | Pfadfinder | prüfen, ob die Tabelle auf io noch existiert; sonst Zweig löschen | V |
| I3-8 | `Settings/Behaelter.php:358/389` | `legacyReferenzen()`: `*_vocab_id` nie nachgezogen, `legacy_id` einziger Beleg | mittel | V | Pfadfinder | Verweise nachziehen | V |
| I3-9 | `TeamSettingsService:510/527` | Alias `pct_we` → `pct_mek` | niedrig | V | Pfadfinder | Bestand umbenennen, Alias weg | V |
| I3-10 | `FoodAlchemistRecipeStepPhoto:30` (`schritt_nr`), `SpeisekarteRubrik:54` (`format_id` „schlafend“), `FormatEditionsToSlotsCommand:14` (`concepts.format_id`) | tote Spalten bleiben „vorerst“ | niedrig | V | Pfadfinder | Drop-Migration mit Vorprüfung | V |
| I3-11 | `RecipeModal:142`, `RecipePdfDownloadController:15` | Legacy-Snapshot-Code „bis alte Snapshots verschwunden sind“ (TTL) | niedrig | V | Vanessa | nach TTL löschen | V |
| I3-12 | `AiGatewayService:494` `boundBudgetFuer()` | `@deprecated`, aber 4 Aufrufer; darüber ein verwaister Docblock (487–493) | niedrig | V | Pfadfinder | Aufrufer umstellen, Methode + Docblock weg | V |
| I3-13 | `PairingService:1417` (`$vorschlaegeProAnker` ignoriert), `ZukaufBasisrezeptService:228` (`$zeilen` ungenutzt), `KnowledgeContextService:67` (`ALWAYS_LOAD_CROSS_CUTTING` Legacy-Default), `:1476`/`KnowledgeSearchService:140` (Discovery-Übergang), `Pairing/AnkerGraph:45` (Übergangs-Gewichte) | Übergangs-Signaturen | niedrig | V | Pfadfinder | beim Eingriff bereinigen | V |
| I3-14 | `WarengruppeSubkategorie` | hängt per Text-Code statt Fremdschlüssel an der Warengruppe | niedrig | T24 | Pfadfinder | FK auf die WG-ID | T24 |
| I3-15 | `FoodAlchemistTargetGroup:16` | Freitext `concepts.target_group` neben dem Pivot | niedrig | V | Pfadfinder | migrieren | V |

### I4 · Toter Code

Methode: Token-Zählung über `src`, `resources`, `routes`, `config` ohne Kommentare; „tot“ = kein Vorkommen außerhalb der Definition. Gegenprobe mit drei bekannten Treffern bestanden. **Grenzen:** dynamische Aufrufe (`->{$m}`, `wire:click="x_{{ $f }}"`, JS-Strings), Host-/Core-Aufrufe und Allerweltsnamen werden nicht erkannt; gleichnamige Bezeichner gelten als Aufruf (Liste eher zu kurz als falsch).

| # | Ort | Befund | Schwere | Zuständig | Vorschlag | Quelle |
|---|---|---|---|---|---|---|
| I4-1 | `Planung/Index::alleFrei`, `::composerGeneriere` (+ `$composerSeed`) | nur Tests | niedrig | Vanessa | **löschen (entschieden C-b)**, eigener PR vor C1 | V |
| I4-2 | `MargeService::effektiverVk` (+ `vkVorschlag`-Pfad) | nur Golden-Test | niedrig | Kurator/Dominique | löschen oder als bewusste Naht markieren | V |
| I4-3 | 51 Service-/Support-Methoden ohne Aufrufer (davon ~20 ganz ohne Test), z. B. `EtikettService::haltbarkeitSetzen`, `KnowledgeService::bindExisting`, `SpeisekarteLeitstelleService::preisAmpeln`, `SensorikService::fuerConcept`, `ConcepterAggregateService::cacheConcept`, `TeamSettingsService::topfDeckelFuer/mindestMarginPct/seasonMarginBand`, `ProductionCapacityService::alsNaechstes/letzteAenderungen`, `PairingService::recipeAnkers`, `ProduktpassService::allergeneBestaetigen/Verwerfen`, `CanvasService::updateEntry`, `ConceptService::categoryCounts` … (vollständige Liste: Anhang A) | Ballast, teils verwaiste Funktionen samt Tests (`FoodbookService::kapitelFreigeben` 5 Testdateien, `IdeenService::kiDivergenz` 3) | mittel | Vanessa | je Kandidat Spec/PR-Historie prüfen, Liste an Dominique, dann ein Lösch-PR je Bereich | V |
| I4-4 | 24 Livewire-Aktionen ohne View-Bezug, z. B. `Angebote/Editor::neuesMenue/uebernehmeMenue/entferneMenue/formatPreisModus/geruest*`, `Orders/Editor::saveApproval`, `Orders/Index::versandfaehigeAusloesen`, `Settings/PraesentationsDesigns::bloeckeNeuOrdnen/tokenSetzen`, `Settings/Taxonomie::hgSort`, `Produktion/Editor::zielAusListe`, `Suppliers/ItemModal::allergene-/deklarationen-/naehrwerteSpeichern`, `GpModal::manual_zustand/manual_tags`, `RecipeModal::clear_/manual_kategorie` (Anhang A) | Ballast | niedrig | Vanessa | wie I4-3 | V |
| I4-5 | Artisan-Kommandos und MCP-Tools | **sauber**: alle 88 Kommandos registriert, alle 609 Tools gelistet | – | – | – | V |

### I5 · Angekündigt, aber nicht gebaut

| # | Ort | Befund | Schwere | Beleg | Zuständig | Vorschlag | Quelle |
|---|---|---|---|---|---|---|---|
| I5-1 | `Regeln/RegelBuch.php:13/53` | liest nur globale Regeln; Team-Regeln im Schema vorbereitet, nicht im Lesepfad (Mandanten-Hausstandards greifen nicht) | mittel | `whereNull('team_id')` | Kurator/Dominique | Auflösung Zeile > Team > global (Spec 81) | S |
| I5-2 | Spec 64 §2/§4 | Lieferanten-Kontakte in Alttabelle außerhalb CRM; `FoodbookReifeAdapter.php:57` prüft das alte Feld `customer` (tote Prüfung); `zusatz_empfaenger`, Portfolio „je Kunde“, Formate-Kunde als Freitext | mittel | Grep | Kurator | Spec 64 neu terminieren; Reife-Adapter sofort auf CRM-Link umstellen | S |
| I5-3 | `MandantExportService.php:22` | Export ohne Dateien, ohne Rotation, ohne Knopf (Spec 82) | mittel | „NICHT enthalten: Fotos, Logos“ | Kurator | vor erstem Mandanten-Ausstieg vervollständigen | S |
| I5-4 | `MatchHeuristics` (Spec 80 K4/K3) | Matcher liest Verarbeitung/Form nicht; `gp-felder-backfill` nicht angewendet | mittel | Z.354/446 | Kurator | Backfill (Freigabe) + Matcher-Erweiterung | S |
| I5-5 | MCP-Lockstep | `order_mails.GET` (Spec 63) und `supplier_crm_links` (Spec 64) fehlen | niedrig | kein Tool | Vanessa | mit dem jeweiligen Feature nachziehen | S |
| I5-6 | `SignalDetektorService.php:17–18` | „Skelett (TODO)“: veraltete Preise, Preis-Anomalie, Marge unter Ziel | niedrig | Kommentar | Pfadfinder | prüfen, ob durch Signal-Kaskade erledigt, sonst Kommentar korrigieren | V |
| I5-7 | `FoodbookService:299/320/2390` | Kapitel-Overrides „vorerst Foodbook-Default“; `kiAndockKontext` „blockiert“ und ohne Aufrufer | niedrig | V | Pfadfinder | entscheiden oder entfernen | V |
| I5-8 | `Services/Websuche/NichtVerfuegbarTransport` | Web-Recherche zu Artikeln hängt am Platzhalter, bis der Core Websuche mit Quellen liefert; KI-Klasse später (#490) | mittel | Provider:408 | Martin (Core) | Core-Websuche, dann Transport binden | K, V |
| I5-9 | Spec 55 | Scope „Format“ ohne Regler-Struktur und Pflicht-Leitplanken; Agent-Panel auf Ausgabe-Tabs | niedrig | `Planung/Index:390` | Kurator | eigenes Vorhaben | S |
| I5-10 | Spec 80 D9a/F6/G6 | Kosten je Stufe, Struktur-Elemente im Angebot, Regelwerk-Präfix im Befund-Label | niedrig | S | Kurator | eigenes Vorhaben | S |
| I5-11 | `layouts/standalone.blade.php:6/73` | Login/Teams/Terminal „vorerst aus platform-core“ | niedrig | V | Spur 2 | bewusst geplante Herauslösung | V |
| I5-12 | `PreislistenImportService:485` | Excel auf dem Server nicht verfügbar („vorerst CSV“) | mittel | siehe I2-7 | Kurator | openspout installieren | V |

### I6 · Leistung

| # | Ort | Befund | Schwere | Beleg | Zuständig | Vorschlag | Quelle |
|---|---|---|---|---|---|---|---|
| I6-1 | `ReportExportService::preisFuer` (gpDaten/rezeptDaten) | N Abfragen bei großen Rezept-/GP-Drucken (Lieferanten-Bericht ist seit #502 Bulk) | mittel | T9, K | test | Bulk-Abfrage wie supplierDaten | T9, K |
| I6-2 | `supplierDaten` | lädt den ganzen Lieferanten in den Speicher (117.000 Artikel > 512 MB) | mittel | T10 | test | in Blöcken laden bzw. streamen | T10 |
| I6-3 | `catalogPrice` | 1–2 `exists()` je Darreichung | niedrig | T11 | test | je Request und Team cachen | T11 |
| I6-4 | Query-Builder-Massen-Schreibzugriffe (~210 `DB::table(…)->update/insert/delete`, ~470 `->update([…])` auf Buildern) | feuern keine Modell-Ereignisse → B4-Protokoll blind | mittel | grober grep | test | Liste je Bereich; schreibende Wege über Modelle oder `FremdSchreiben::erlaubt` mit Grund | K |
| I6-5 | Spec 84 E-1/E-3/E-6 | Messwoche, Indizes, Redis | – | – | – | Querverweis Spec 84 | S |

### I7 · Daten und Modell

| # | Ort | Befund | Schwere | Beleg | Zuständig | Vorschlag | Quelle |
|---|---|---|---|---|---|---|---|
| I7-1 | Unique `(team_id, code/slug)` bei `team_id NULL` | MySQL setzt sie nicht durch; globale Dubletten möglich (Plattform-Basis prüft heute selbst) | mittel | T23, #493 | Pfadfinder | generierte Spalte `COALESCE(team_id, 0)` im Unique oder Wächter | T23, V |
| I7-2 | Kalkulation/OrderTemplate | ohne Freigabe-Hülle, vorläufig in `STUFEN[4]` | mittel | T25 | Kurator/Dominique | InhaltsFreigabe-Typ ja/nein | T25 |
| I7-3 | `RecipeConformanceAdapter.php:166` `const TIERARTEN`, kein Tierart-Feld (Spec 57/59/81) | Fisch ohne Fisch-Allergen zählt als Fleisch | niedrig | S | Kurator | Tierart als Regel-Daten/Feld | S |
| I7-4 | `NectaMapper.php:14/121` | Platzhalterwert `TODO_country_<id>` in `origin_country` | niedrig | V | Pfadfinder | Länder-Mapping oder NULL | V |
| I7-5 | `ImportSliceCommand` | Altwerkzeug legt team-eigene Einheiten/WG an (seit Plattform-Basis falsch); Docblock nennt nicht existierendes `import-legacy` | niedrig | Z.18, Z.980–1044 | Vanessa | als Altwerkzeug sperren (nur mit `--force`) oder löschen | K, V |
| I7-6 | `IngestStatusService` (Lücken „ohne Preis“, ~Z.177) | zählt für Mandanten zu optimistisch (entschieden: ohne Fix) | niedrig | T12, K | – | im Code dokumentieren | T12, K |
| I7-7 | Spec 79 | tote Schema-Reste `source_knowledge_document_id`, `trend_auto_*` | niedrig | S | Pfadfinder | Drop-Migration | S |

### I8 · Betrieb und Werkzeuge

| # | Ort | Befund | Schwere | Beleg | Zuständig | Vorschlag | Quelle |
|---|---|---|---|---|---|---|---|
| I8-1 | Core `OpenAiService.php:18/51` | feste Base-URL, globaler Schlüssel; eigener Schlüssel je Mandant zurückgestellt | mittel | Core-Code | Martin | Core-Ticket | K |
| I8-2 | `tinker <datei>` | hängt im REPL ohne `< /dev/null` bzw. `--execute` | niedrig | T19 | test | Artisan-Befehl `foodalchemist:sicht-vergleich` | T19 |
| I8-3 | io-CLI | höchstens 512 MB | niedrig | T20 | – | dokumentieren, Analysen in Blöcken | T20 |
| I8-4 | Spec 84 §7 Statustabelle | veraltet (C5, D1, E-2, E-4/C3b, E-5, B4-Log, B3 Regel B als „offen/lokal“, sind gemergt #473–#486) | niedrig | S | Vanessa | nach dem Stufe-4-PR mit der Auslagerung korrigieren | S |
| I8-5 | io-Queue `failed_jobs` | fehlgeschlagene `GenerateEmbeddingJob`-Einträge vom 16.10. (leeres OpenAI-Guthaben); Re-Embed Wissen offen | niedrig | Montag-Liste | Kurator | aufräumen + Re-Embed off-peak | K |

### I9 · Große Dateien

19 Dateien über 1.500 Zeilen → Größen-Wächter (§4) und Schnittpläne (§5–§9).

## §3 · Plan

**Welle 1 (sofort, klein, je ein PR):**
1. Größen-Wächter (§4) — Vanessa, Merge nach dem Stufe-4-PR von test.
2. Löschen `alleFrei`/`composerGeneriere` (I4-1, entschieden C-b) — Vanessa, vor C1.
3. Test-Ballast: I1-4 (FormatDimensionen läuft nie), I1-5 (fehlschlagen statt Skip), I2-5, I2-6 (mit Lauf bestätigt), I2-8, I2-11, I2-12 — Vanessa.
4. Spec 84 Teil C → „ausgelagert in Spec 85“ + Statustabelle korrigieren (I8-4) — nach dem Stufe-4-PR.

**Welle 2 (Absicherung, hoch):**
5. I1-1 + I1-2 Bearbeitungssperre für MCP und Versionsprüfung — Vanessa (eigener kleiner Plan vorab).
6. I2-1 MySQL-Spur bzw. Abfrage-Wächter — Vanessa.
7. C1 PR-1 … PR-4 (§5) — Vanessa.
8. I3-1 VK-Altspalten: Stichtag mit MoneyTruthReport festlegen — Entscheidung Dominique, dann eigene Etappe.

**Welle 3 (nach Entscheidung oder beim Eingriff):** I4-3/I4-4 Lösch-PRs je Bereich (Liste an Dominique), I3-2 Alias-Phase 2, I5-x je Feature, I6-x Leistung (test), I7-x, C2 (test, nach Spec 84), C3b/C4/C6 Pfadfinder.

## §4 · Größen-Wächter

**Regel:** Keine PHP-Datei unter `src/` und `resources/views/` (Blade eingeschlossen) wird größer als **1.500 Zeilen**.

**Bau:**
- `tests/Support/DateigroessenBasis.php`: Liste `Pfad => [Zeilen, Grund/Ziel]` aller Dateien darüber.
- `tests/Feature/DateigroessenWaechterTest.php` scannt `src/**/*.php` und `resources/views/**/*.php`. **Rot**, wenn (1) eine Datei über 1.500 nicht gelistet ist; (2) eine gelistete über ihre Basis wächst; (3) eine gelistete schrumpft, ohne dass die Basis gesenkt wurde (Sperrklinke, „Basis auf N senken“); (4) ein Eintrag auf eine fehlende Datei oder eine unter 1.500 zeigt („Eintrag entfernen“). Meldung je Datei mit Ist, Basis, Grund. Gegenprobe im PR.
- **Merge** nach dem Stufe-4-PR von test; Basis beim Merge frisch messen.

**Basis (main eaad31b7, 18.10.):**

| Datei | Zeilen | Grund / Ziel |
|---|---|---|
| `src/Services/OrderService.php` | 3.739 | C2 (§6) |
| `src/Livewire/Planung/Index.php` | 3.641 | C1 (§5), PR-1 … PR-5 |
| `src/Services/PlanningCascadeService.php` | 3.556 | §9 |
| `src/Services/SpeiseplanService.php` | 2.874 | §9 |
| `src/Services/FoodbookService.php` | 2.784 | §9, mit C4 (§8) |
| `src/Services/PairingService.php` | 2.624 | §9 |
| `src/Services/Ai/KnowledgeContextService.php` | 2.171 | §9 |
| `src/Services/DataQualityService.php` | 2.060 | §9 |
| `src/Livewire/Angebote/Editor.php` | 1.869 | C4 (§8) |
| `resources/views/livewire/produktion/tagesplan.blade.php` | 1.646 | C6 (§9) |
| `src/Livewire/Concepter/Editor.php` | 1.637 | C6 (§9) |
| `src/Livewire/Foodbooks/Index.php` | 1.624 | C4 (§8) |
| `resources/views/livewire/concepter/editor.blade.php` | 1.605 | C6 (§9) |
| `src/Services/RecipeRecomputeService.php` | 1.581 | §9 |
| `src/Services/ConceptService.php` | 1.567 | §9 |
| `src/Services/RecipeService.php` | 1.557 | §9 |
| `src/Livewire/Verkauf/VkModal.php` | 1.546 | C6 (§9) |
| `src/Services/RecipeGeneratorService.php` | 1.544 | §9 |
| `src/Livewire/Produktion/Tagesplan.php` | 1.534 | C6 (§9) |

## §5 · C1 · `Livewire/Planung/Index.php` (3.641 Z.) + `planung/index.blade.php` (1.365 Z.)

**Stand:** C1-1 gemergt (#465; 4.596 → 3.641). E5 erledigt (#464). C-b entschieden (I4-1).

**Größenrechnung:** `Brief` ≈ 1.050, `KaskadenLauf` ≈ 840 — beide unter 1.500. Der Parent behielte ≈ 1.750 (5× `*AusBrief` ≈ 480, Import ≈ 160, Composer ≈ 140, Trendradar ≈ 70, Props/Konstanten ≈ 500). Deshalb alle Schritte. Ziel: Parent ≈ 1.400 nach PR-4, ≈ 900 nach PR-5; kein Kind über 1.500, möglichst um 800.

**Reihenfolge (entschieden, C-a), je ein PR:**

| PR | Kind | Inhalt | ≈ Zeilen | Kopplung |
|---|---|---|---|---|
| PR-1 | `Planung\RezeptImport` | `importExtrahieren`, `importAnlegen`, `importReset`, `importZutatenRein`, Props `import*`, Blade-Tab `import` (791–930), `WithFileUploads` | ≈ 200 | `recipe-gespeichert` bleibt; Meldungen lokal |
| PR-2 | `Planung\Trendradar` | `schnellTrendradar`, `trendradarUebernehmen`, Props `trend*`, Trend-Block aus `render()`, Blade-Tab `trendradar` (1091–1164) | ≈ 120 | schreibt die Session; Parent erhöht `$briefStand` |
| PR-3 | `Planung\KaskadenLauf` | `pruefeLauf`, `laufAbbrechen/Fortsetzen/WiederAufnehmen`, `gibFrei`, `verwirf`, `erzeugeGeplant`, `ergaenzeSubRezept`, `verwirfGeplant`, `toggleZutaten`, `alleNeuenAnreichern`, `alleVerwerfen`, `haengeKonzeptNach`, `sourcingLueckenMelden`, `favoritVorschlaegeLaden/Pinnen`, `gibStufeFrei`, `stepsUnterAufschlagsklasse`, `margenWarnungText`, `toggleKommentar`, `neuGenerieren`, `vorschlagUeberarbeiten`, `stufenAusSteps`, `stufen`, `refreshLaeuft`, `anreicherungOffen`, `neuAnreichern`, `bilderNeu`, `foto*` (6), `konformitaetPruefen`, `ladeLetztenLauf`; Trait `FortschrittAnsicht`, `WithFileUploads`; Props `laufId`, `laeuft`, `anreicherungLaeuft`, `hinweis`, `margenWarnung`, `deckelHinweis`, `zutatenOffen`, `favoritVorschlaege`, `kommentarOffen`, `speiseKommentar`, `neuerSubName`, `fotoUploads`, `fotoPickerStep`; `render()`-Teil Lauf/Cockpit/Poll/Foto-Picker; Blade-Tab `worker` (1047–1090) | ≈ 840 | siehe Events |
| PR-4 | `Planung\Brief` | **eine** Komponente für alle drei Scopes: `reglerPill`, `vorlagenFuer`, `briefVorlage`, `alsVorlageSpeichern`, `loeschenVorlage`, `titelVorschlagen`, `suchbegriff*` (4), `leitplankenAusBriefing`, `updatedBriefAudio`, `brief-/agentDiktatUebernehmen`, `diktat*`, `regler*`/`menue*`/`zielVkEur`/`effektiverBrief`, `wissenVorschau`, `kiKopf`, `ladePlanForm`, `planFeldSpeichern`, `planVorschau`, `conceptSpeisen`, `planVerwerfen`, `goKaskade`, `rehydriereReglerAusParams`, `seedBriefingAusTrendSession`, `voiceKomponentenUebernehmen`, `updated()`; Props `eingabe`, `regler`, `reglerVonAgent`, `wissenVorschau`, `aktiveVorlage`, `vorlageName`, `planConceptId`, `planForm`, `briefAudio`; Blade-Tabs `basisrezept/gericht/concept` (931–1046) | ≈ 1.050 → ≈ 850 | siehe Events |
| PR-5 | 5 × `*AusBrief` 1:1 | `foodbook-/speisekarte-/speiseplan-/angebot-/formatAusBrief`, 5× `updated*OwnerId`, `aktiviereOwnerSession`, 25 Props `fb*/sk*/sp*/offer*/fmt*`; Zusammenführung erst in eigenem PR | ≈ 600 → ≈ 250 | `planung.oeffne(id, tab)` statt `$this->oeffne()` |

**PR-4 zusätzlich (C-c):** `goKaskade` (139 Z.) und `leitplankenAusBriefing` (79 Z.) in Support-Klassen nach dem Vorbild C1-1, als **eigene Commits**.

**Brief als EINE Komponente:** `eingabe`/`regler` sind Arrays je Scope; eine Komponente ist Verschiebung, drei Instanzen wären Zusammenführung. **Konstanten bleiben in `Index`** (Partials und `ReglerParams` lesen sie statisch über `Index::class`).

**Events:**

| Richtung | Event / Weg | Zweck |
|---|---|---|
| KaskadenLauf → Parent | `planung.lauf-status(laufId, laeuft)` | Tab-Leiste, Board-Poll; Parent hält eine schreibgeschützte Kopie |
| Brief → Parent | `planung.lauf-gestartet(laufId, startTab)` | Parent setzt `laufId`, öffnet den Worker-Tab |
| Kind → Parent | `planung.meldung(text, art)` | Kinder behalten eigene `fehler`/`meldung`; Parent zeigt wie bisher im Modal-Kopf |
| Parent → KaskadenLauf | Props `sessionId`, `laufId`, `:key="'lauf-'.$sessionId.'-'.$laufId"` | `kaskadeGestartet`/`oeffne` bleiben im Parent |
| Parent → Brief | Prop `sessionId`, `:key="'brief-'.$sessionId.'-'.$briefStand"` | Trendradar/`schnell*` schreiben die Session, Brief mountet neu |
| Composer → Brief | `planung.brief-uebernehmen(scope, werte)` | Composer schreibt nicht in die Session |
| Voice ↔ Brief | `#[On('voice.diktat-transkribiert')]`, `#[On('voice.komponenten-uebernahme')]`; Render-Dispatch `voice.formularstand-aktualisiert` | ziehen mit; Risiko: Dispatch nur noch bei Brief-Renders (VoiceInterfaceTest prüft) |

Der Poll `wire:poll.1500ms="pruefeLauf"` zieht in `KaskadenLauf` (weniger Last).

**Vor PR-4:** zeilengenaue Aufteilung von `ladeForm`/`speichern` (form vs. eingabe/regler) an den Kurator.

**Tests (31 Dateien, ≈ 255 `Livewire::test(Index…)`):** KaskadenLauf ≈ 55 Aufrufe + 19× `set('laufId')` (PlanungLeitstelleTest, PlanningCascadeTest, Spec80Fortschritt/Robustheit, ConformanceLeitstelle, Spec84FotoFremdesRezept, WissenSichtStepZeile, Leitstelle*Lifecycle); Brief ≈ 80 Aufrufe + 70× `set('eingabe')` + 37× `set('regler')` (PlanungLeitstelleTest, VoiceInterfaceTest 51, PlanungBriefingUiTest, VoiceGlobalPolicy, SchnellstartVorlagen, Spec80Suchbegriffe/KomponentenPlan, Spec84BriefVorlagenJeTeam, DossierHinweis); RezeptImportTest (6) mit PR-1, TrendradarSpec79Test (11) mit PR-2. Ketten-Tests über zwei Komponenten nacheinander; jeder PR listet alt → neu.

**Risiken:** Kinder rendern auch bei verstecktem Alpine-Tab → `lazy` bzw. Poll nur bei aktivem Lauf; Uploads nur mit `WithFileUploads`; jedes Kind mit `key`; kein `#[On]` in Traits.

## §6 · C2 · Bestell-Cluster

(aus Spec 84 §5.3) `OrderService` 3.739 + `WareneingangService` 730, `LieferantenRechnungService` 578, `OrderMailService` 231, `ProductionOrderService` 1.461.

- **Vier Zyklen und Bruch:** A Wareneingang (drei Methoden an `WareneingangService`; `OrderZeilenAbleitung`; expliziter Ablauf `WareneingangAbschluss`; **kein Event**), B Rechnung (analog), C Mail (`OrderDokumentService` ohne Mail-Abhängigkeit), D Produktion (Wertobjekt `ProduktionsQuelleRef`, expliziter Storno-Ablauf).
- **Laufzeit-Schleife** `WE::abschliessen` → `setStatus(Delivered)` → `fillReceiptFromOrder` → `WE::kurzwegMenge` → `schreibeWareneingangAbleitung`, hält nur über `nichtErfassteAufNull`. Charakterisierungstest gemergt (#458).
- **Fassade zuerst:** `OrderService` bleibt dünne Fassade, bis alle Aufrufer umgezogen sind; Ziele `OrderKern`, Entwurf/Bedarf, Bestellrunde/Versand/Status, Produktionsbedarf, Wareneingang, Rechnung/Zahlung/Freigabe, Reklamation/Kontingent, Herkunft (Read-Model), Dokument, Ampeln; gemeinsamer Baustein `OrderSummaries`. Hauptkunde `Livewire/Orders/Editor` (≈ 47 Aufrufe). Tabellen: Spec 84 §5.3.
- **Tests:** OrderServiceTest 101, ProductionOrderServiceTest 39, Wareneingang 11 + Kurzweg 8, TripleMatch 7, Mail 7, Bestellrunde 11; **dünn** Reklamation/Kontingent, Herkunft, Mail/Dokument → vorher Charakterisierungstests.
- **Zuständig:** test, nach Abschluss von Spec 84.

## §7 · C3 · ServiceProvider (Rest)

**Erledigt:** C3a (#454: Tool-Liste `src/Tools/_liste.php`, catch loggt, Dublette raus), C3b/E-4 Scan-Cache mit Rückfall (#480). Provider 809 Zeilen. **Offen:** zusätzliche Registrierung `foodalchemist.orders.editor` (Z. 762) prüfen; Sub-Provider (`FaSchedulerProvider`, `FaLivewireGuardProvider`, `FaObserverProvider`, `FaQueueLifecycleProvider`, `FaToolProvider`, `FaLivewireProvider`) als Pfadfinder.

## §8 · C4 · Gemeinsame „Komposition“

(aus Spec 84 §5.5) `Angebote/Editor` (1.869) und `Foodbooks/Index` (1.624) zu rund 70 % namensgleich; `Speiseplan/Editor` teilt Branding/Veröffentlichen. **Nur Inventur, Entscheidung danach:** je Methode gleich / abweichend / nur hier; erst dann, ob gemeinsame Kinder taugen. Nebenfund aus 84 erledigt (#460).

## §9 · C6 · Übrige große Editoren und Services (beim nächsten Eingriff)

| Datei | Zeilen | Empfehlung |
|---|---|---|
| `Concepter/Editor` (+ Blade 1.605) | 1.637 | Slots und Paket-Positionen als Kinder, Picker als Trait |
| `Produktion/Tagesplan` (+ Blade 1.646) | 1.534 | Feedback und Anleitung als Kinder; Aufbereitung in einen Query-Service |
| `Speiseplan/Editor` | 1.446 | Branding/Veröffentlichen aus C4; Eintrag-Detail als Kind |
| `Orders/Editor` | 1.397 | Cockpit und Wareneingang/Rechnung als eigene Komponenten (zu C2) |
| `Verkauf/VkModal` | 1.546 | Darreichung und Regeneration als Kinder |
| `PlanningCascadeService` | 3.556 | nach Stufen (Rezept/Gericht/Konzept/Ausgabe) |
| `SpeiseplanService` | 2.874 | Lifecycle, Aushang/Druck, Auswertung trennen |
| `FoodbookService` | 2.784 | mit C4: Kapitel/Blöcke vs. Veröffentlichen/Druck |
| `PairingService` | 2.624 | Lesen vs. Bewerten vs. Pflege |
| `Ai/KnowledgeContextService` | 2.171 | Routing, Budget, Prompt-Bau trennen |
| `DataQualityService` | 2.060 | Metriken je Bereich hinter einer Registry |
| `RecipeRecomputeService`, `ConceptService`, `RecipeService`, `RecipeGeneratorService` | 1.544–1.581 | Nicht-Kernlogik auslagern |

Startpunkte, kein fertiger Schnittplan: vor dem Schnitt wie in §5 vermessen. C5 (Modal-Writes über Services) ist erledigt (#473).

## §10 · Entscheidungen

| # | Frage | Stand |
|---|---|---|
| W-a | Sperrklinke im Wächter | **Entschieden 18.10.: ja**, jeder Schnitt-PR senkt die Basis |
| W-b | Merge-Zeitpunkt Wächter | **Entschieden 18.10.:** nach dem Stufe-4-PR von test, Basis beim Merge messen |
| C-a | Reihenfolge C1 | **Entschieden 18.10.:** Import → Trendradar → KaskadenLauf → Brief → später AusBrief |
| C-b | `alleFrei`, `composerGeneriere` (+ `$composerSeed`) | **Entschieden 18.10.: löschen**, eigener PR vor C1 |
| C-c | Support-Klassen für `goKaskade`/`leitplankenAusBriefing` | **Entschieden 18.10.: ja**, eigene Commits in PR-4 |
| D-1 | I3-1 VK-Altspalten: Stichtag und Abbau | **offen** (Dominique) |
| D-2 | I4-3/I4-4 tote Methoden löschen (Liste Anhang A) | **offen** (Dominique, je Bereich) |
| D-3 | I1-3 Spec 65 Pakete B–E noch gebraucht? | **offen** |
| D-4 | I7-2 Kalkulation/OrderTemplate Freigabe-Typ | **offen** |
| D-5 | I2-9 Skips an Entscheidungen (M6-05, Spec 35, Dossier-Vorspann) | **offen** |
| D-6 | I1-13 Sichtweite BulkProposal | **offen** |
| D-7 | I5-1 Team-Regeln im RegelBuch | **offen** |

## §11 · Reihenfolge und Zuständigkeit

| Schritt | Wer | Stand |
|---|---|---|
| Spec 85 (dieses Dokument) | Vanessa | Docs-PR nach Freigabe des Entwurfs |
| Welle 1 (§3: Wächter, C-b, Test-Ballast) | Vanessa | ab 19.10. |
| Welle 2 (Sperre für MCP + Version, MySQL-Spur, C1) | Vanessa | nach Welle 1 |
| I1-6 bis I1-11, I2-2, I2-4, I2-13–I2-15, I6-1 bis I6-4, C2 | test | nach Abschluss von Spec 84 |
| Entscheidungen D-1 bis D-7 | Kurator / Dominique | – |
| I8-1, I5-8 (Core) | Martin | Core-Tickets |
| Spec 84 Teil C → „ausgelagert in Spec 85“ + Statustabelle (I8-4) | Vanessa | nach dem Stufe-4-PR von test |

## Anhang A · Toter Code (Kandidaten, grep-basiert, vor dem Löschen je Stück prüfen)

**Services/Support (51):** MargeService::effektiverVk · VkSnapshotService::publishedFor · ProductionOrderService::einkaufsIndikatoren · SalesRecipeService::distinctCustomerNames · EtikettService::haltbarkeitSetzen · TrendService::radarPosition · KnowledgeService::bindExisting · SpeisekarteLeitstelleService::preisAmpeln · PaketService::verwendetInConcepts · SensorikService::fuerConcept · ConcepterAggregateService::cacheConcept · IdeenService::kiDivergenz · BulkEnrichService::starteVk · ComponentEquivalentService::setSwapLocked · SupplierAgreementService::dueForFollowUp · LeitstelleService::preiseBaum · TeamSettingsService::voiceAgentDauerhaftAktiv / ausweichKetteAnzeigen / topfDeckelFuer / mindestMarginPct / seasonMarginBand · ConformanceService::offeneFuer · RecipeExtractService::pdfText · ProductionCapacityService::alsNaechstes / letzteAenderungen · ProportionService::extraPercent · PairingService::bestIdentityAnchor / recipeAnkers · RecipeService::deleteGeneratorStub · InspireImportService::existingInspireAnchors · FaRechte::pruefeKontingent · WorkerHealthService::istGesund · VocabularyService::istKanonischeWarengruppe / createDishClass · CanvasService::findFoodDna / updateEntry · ConceptService::categoryCounts · FoodbookService::kapitelFreigeben / anlageZuruckziehen / verarbeiteFreitextQueue / kiAndockKontext · Matching/TokenEngine::wantsCanned · Regeln/Arten/Vokabular::gruppeVon · KnowledgeCanonService::achsenKey · Wissensart::darfInPrompt · KnowledgeEmbeddingService::searchSlugs · ProduktpassService::allergeneBestaetigen / allergeneVerwerfen · GeschirrRueckbau::pruefeOderAbbrechen · ScanCache::schluesselVergessen · DossierText::vorspannLaenge · FaBereiche::routeZugeordnet · SignalCockpit::wegRegistry · NonFoodMasse::mmAusCm.
Hinweis: `FaRechte::pruefeKontingent`, `FaBereiche::routeZugeordnet`, `SignalCockpit::wegRegistry` sind vermutlich bewusste Nähte für Wächter-Tests — nicht ungeprüft löschen.

**Livewire (24):** Planung/Index::alleFrei / composerGeneriere (entschieden) · Gps/GpModal::manual_zustand / manual_tags · Recipes/RecipeModal::clear_kategorie / manual_kategorie · Suppliers/ItemModal::allergeneSpeichern / deklarationenSpeichern / naehrwerteSpeichern · Angebote/Editor::neuesMenue / uebernehmeMenue / entferneMenue / formatPreisModus / geruestSlotNeu / geruestSlotLoeschen / geruestKickoff · Settings/PraesentationsDesigns::bloeckeNeuOrdnen / tokenSetzen · Settings/Taxonomie::hgSort · Produktion/Tagesplan::waehleAuftrag · Produktion/Editor::zielAusListe · Orders/Index::bedarfPlanen / versandfaehigeAusloesen · Orders/Editor::saveApproval.

## Changelog

- 2026-10-18 · v1.0: aus Spec 84 Teil C ausgelagert und erweitert (Dominique: „Wartbarkeit und technische Schulden“). Inventur I1–I9 aus Code-Durchsicht, Test-Harness, Specs 53–84 (gegen Code geprüft), Liste „test“ (25 Punkte, 2/21/22 als Querverweis Spec 84) und Kurator-Lücken (Nachtrag Kurator: I8-5, §1 io-Datenregel). Plan in drei Wellen; Größen-Wächter mit Sperrklinke (Basis 19 Dateien auf eaad31b7); C1 PR-1 … PR-5; C2/C3/C4/C6 übernommen; C5 erledigt (#473); Entscheidungen W-a, W-b, C-a, C-b, C-c; D-1 bis D-7 offen.
