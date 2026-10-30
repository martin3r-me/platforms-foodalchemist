# 90 – KI dualisieren (#1002): Pflichtliste aller KI-Aufrufe

Stand: 2026-10-29, Basis origin/main e595ae03. Der Wächter-Test `Spec90KiPflichtlisteTest` liest Tabelle A: Spalte 2 = `Datei::Methode`, Spalte 3 = Prompt-Keys, Spalte 4 = Anzahl der `propose()` in dieser Methode.

## Zählung (gemessen)

- `->propose(` in `src/`: **90 Treffer**, davon **88 echte KI-Aufrufe** über `AiGatewayService::propose`, verteilt auf **82 Methoden** (Tabelle A). Die 2 übrigen Treffer sind `GpProposalService::propose` (`Tools/GpProposalsPostTool`, `Services/HardstopResolveService::beschaffungAnstossen`): GP-Vorschläge ohne KI, Falschtreffer.
- `callWithTools(`: Vanessas „5“ = **1 Aufruf in `src/`** (`VoiceCommandService`) + 4 Treffer in `tests/Feature/VoiceGlobalPolicyTest.php`. In der Produktion gibt es einen agentischen Loop.
- Weitere LLM-Wege ohne `propose`: Bildgenerierung, Embeddings (Tabelle B).
- **Zielzahl:** Tabelle A (88 Aufrufe) + 3 Zusatzwege = 91 statt der 95 aus der DoD. Abnahme der Zahl durch Dominique nötig.
- Prompt-Keys in `config/foodalchemist.php` (`ai.prompts`): 77. Der Wächter prüft auch, dass jeder Key in dieser Datei vorkommt (fängt neue Keys hinter dynamischen `$key`-Aufrufen).

## Einstufung

- **a (getrennt)**: ein `propose()`, Nachverarbeitung beim Aufrufer nach dem Rückgabewert, keine Schreibzugriffe davor. Die Variante „Wiederholung“ trägt das ohne Umbau.
- **b (leicht trennbar)**: mehrere `propose()` nacheinander oder Schreibzugriffe nach der Antwort, aber idempotent bzw. in einer Methode kapselbar.
- **c (schwer)**: Kaskaden/Schleifen mit Schreibzugriffen zwischen mehreren `propose()` (Generator, Planung, One-Shot-Kette): braucht idempotente Zwischenschritte oder eine Antwort-Folge je Lauf.
- Die Einstufung ist eine Lesart der Methodennamen und Signaturen, nicht jeder Aufrufer wurde im Detail geprüft; sie wird beim Anschließen je Eintrag bestätigt.

Status: `angeschlossen` · `offen` · `Ausnahme (Vorschlag)`. Ausnahmen gelten erst nach Abnahme durch Dominique.

## A · `propose()`-Aufrufe

| Nr | Datei::Methode | Prompt-Key(s) | Anz. | Einst. | Phase | MCP-Einstieg | Status | Hinweis |
|---|---|---|---|---|---|---|---|---|
| 1 | `src/Console/RecipePackagingUnitsCommand.php::beurteile` | recipe.verpackungsmasse | 1 | a | P2 | Artisan-Befehl | offen | Massenbefehl: --anbieter=extern wie Backfill |
| 2 | `src/Jobs/ClassifyLaJob.php::arbeite` | gp.suggest | 1 | a | P2 | Job nach LA-Import | angeschlossen | `KiExternWiederholbar` (`classify-la:{team}:{la}`); Extern-Flag erbt der Job aus dem MCP-Aufruf (`KiExternModus::fuerDispatch`). KiExternLaJobTest |
| 3 | `src/Jobs/NonFoodKiBatchJob.php::arbeite` | la.nonfood | 1 | a | P2 | Job | angeschlossen | `KiExternWiederholbar` je Stapel (`nonfood-ki:{hash}`); Start `foodalchemist:nonfood-erkennen --ki --anbieter=extern`. KiExternLaJobTest |
| 4 | `src/Jobs/ReviseDishProposalJob.php::arbeite` | planning.dish_proposal_revise | 1 | b | P2 | planung_kaskade.* | angeschlossen | Job-Weg Kaskade (KiExternWiederholbar, `dish-revise:{team}:{step}`), Step bleibt `running` bis zur Antwort, Rollback-Rumpf; KiExternKaskadeTest. |
| 5 | `src/Livewire/Angebote/Editor.php::kiKundentext` | vk.marketing | 1 | a | P2 | AngebotKundentextTool | offen |  |
| 6 | `src/Livewire/Foodbooks/Index.php::kiKundentext` | vk.marketing | 1 | a | P2 | FoodbookKundentextTool | offen |  |
| 7 | `src/Livewire/Gps/DetailPanel.php::kiNaehrwerte` | gp.naehrwerte | 1 | a | P1 | gps.ENRICH (naehrwerte) | offen |  |
| 8 | `src/Livewire/Gps/GpModal.php::verlusteKiSchaetzen` | gp.verluste | 1 | a | P2 | Zuordnung prüfen (gp_forms.ESTIMATE?) | offen |  |
| 9 | `src/Livewire/Gps/GpModal.php::ai_zustand` | gp.condition | 1 | a | P1 | gps.ENRICH (condition) | offen |  |
| 10 | `src/Livewire/Gps/GpModal.php::ai_tags` | gp.tags | 1 | a | P1 | gps.ENRICH (tags) | offen |  |
| 11 | `src/Livewire/Gps/GpModal.php::einordnungKiVorschlagen` | gp.suggest | 1 | a | P2 | kein Tool (nur UI) | offen |  |
| 12 | `src/Livewire/Gps/GpModal.php::kiVorschlagNaming` | gp.suggest | 1 | a | P2 | kein Tool (nur UI) | offen |  |
| 13 | `src/Livewire/Gps/GpModal.php::nameAusLeadLa` | gp.suggest | 1 | b | P2 | kein Tool (nur UI) | offen | Nachverarbeitung inline |
| 14 | `src/Livewire/Recipes/IngredientEditor.php::garverlustVorschlag` | recipe.garverlust | 1 | a | P2 | kein Tool (nur UI) | offen |  |
| 15 | `src/Livewire/Recipes/RecipeModal.php::ai_beschreibung` | recipe.description | 1 | a | P2 | recipes.ENRICH (Schritt) | offen |  |
| 16 | `src/Livewire/Recipes/RecipeModal.php::ai_kategorie` | recipe.category | 1 | a | P2 | recipes.ENRICH (Schritt) | offen |  |
| 17 | `src/Livewire/Recipes/RecipeModal.php::kiFertigung` | recipe.production_depth | 1 | a | P2 | recipes.ENRICH (Schritt) | offen |  |
| 18 | `src/Livewire/Recipes/RecipeModal.php::kiEigenschaften` | recipe.eigenschaften, recipe.geschmack | 2 | b | P2 | recipes.ENRICH (Schritt) | offen | 2 propose in einer Methode |
| 19 | `src/Livewire/Recipes/RecipeModal.php::kiEquipment` | recipe.equipment | 1 | a | P2 | recipes.ENRICH (Schritt) | offen |  |
| 20 | `src/Livewire/Recipes/RecipeModal.php::namePutzen` | recipe.name_putzen | 1 | a | P2 | kein Tool (nur UI) | offen |  |
| 21 | `src/Livewire/Recipes/RecipeModal.php::kiDichteklasse` | recipe.dichteklasse | 1 | a | P2 | recipes.ENRICH (Schritt) | offen |  |
| 22 | `src/Livewire/Recipes/StepEditor.php::kiSchritte` | dyn. $prompt (recipe.steps / recipe.ruestschritt) | 1 | a | P2 | recipes.ENRICH (steps) | offen |  |
| 23 | `src/Livewire/Verkauf/DetailPanel.php::kiEignung` | dyn. $prompt (recipe.sektor / recipe.level) | 1 | a | P2 | kein Tool (nur UI) | offen |  |
| 24 | `src/Livewire/Verkauf/VkModal.php::ki` | vk.wording, vk.plating, vk.servier_vehikel | 3 | b | P2 | verkaufsrezepte.ENRICH | offen | 3 propose nacheinander: Antwort-Folge je Lauf |
| 25 | `src/Livewire/Verkauf/VkModal.php::uebernehmeEinordnung` | recipe.geschmack, recipe.production_depth | 2 | b | P2 | verkaufsrezepte.ENRICH | offen |  |
| 26 | `src/Livewire/Verkauf/VkModal.php::kiBehaelter` | recipe.dichteklasse | 1 | a | P2 | verkaufsrezepte.ENRICH | offen |  |
| 27 | `src/Livewire/Verkauf/VkModal.php::uebernehmeEigenschaften` | recipe.eigenschaften, recipe.geschmack | 2 | b | P2 | verkaufsrezepte.ENRICH | offen |  |
| 28 | `src/Livewire/Verkauf/VkModal.php::kiRegeneration` | vk.regeneration | 1 | a | P2 | verkaufsrezepte.REVISE | offen |  |
| 29 | `src/Services/AngebotService.php::kiKundentextVorschlag` | foodbook.kundentext | 1 | a | P2 | AngebotKundentextTool | angeschlossen | MCP-Umleitung angebot.KUNDENTEXT_GENERATE (Tabelle D, KiUmleitungTest). |
| 30 | `src/Services/AngebotService.php::kiKapitelKundentextVorschlag` | foodbook.kundentext | 1 | a | P2 | AngebotKundentextTool | angeschlossen | MCP-Umleitung angebot.KUNDENTEXT_GENERATE (Tabelle D, KiUmleitungTest). |
| 31 | `src/Services/BriefingLeitplankenService.php::ausBriefing` | planung.leitplanken | 1 | a | P2 | planung_leitplanken.EXTRACT, planung_kaskade.START, planung_vorschlag.POST | angeschlossen | MCP-Umleitung planung_leitplanken.EXTRACT / planung_vorschlag.POST (Tabelle D, KiUmleitungTest). |
| 32 | `src/Services/BulkEnrichService.php::proposeFeld` | dyn. $key (Rezept-Schritte) | 1 | b | P2 | recipes.ENRICH | angeschlossen | Keys über config-Prompt-Liste abgesichert Extern über recipes.ENRICH/EnrichRecipeJob (Tabelle D, KiExternEnrichTest). |
| 33 | `src/Services/BulkEnrichService.php::proposeDichteklasse` | recipe.dichteklasse | 1 | a | P2 | recipes.ENRICH | angeschlossen | Extern über recipes.ENRICH/EnrichRecipeJob (Tabelle D, KiExternEnrichTest). |
| 34 | `src/Services/BulkEnrichService.php::proposeRegeneration` | recipe.regeneration | 1 | a | P2 | recipes.ENRICH | angeschlossen | Extern über recipes.ENRICH/EnrichRecipeJob (Tabelle D, KiExternEnrichTest). |
| 35 | `src/Services/BulkEnrichService.php::proposeGarverlust` | recipe.garverlust | 1 | a | P2 | recipes.ENRICH | offen |  |
| 36 | `src/Services/BulkEnrichService.php::proposeGpFeld` | dyn. $key (gp.condition, gp.tags, gp.anker, gp.sensorik, gp.allergene, gp.naehrwerte) | 1 | b | P1 | gps.ENRICH | offen | P1-Kern |
| 37 | `src/Services/CoherenceService.php::judge` | vk.kohaerenz | 1 | a | P2 | recipe_coherence.POST | angeschlossen | MCP-Umleitung (Tabelle D). |
| 38 | `src/Services/CoherenceService.php::tellerHeber` | vk.teller_heber | 1 | a | P2 | recipe_coherence.POST | angeschlossen | MCP-Umleitung (Tabelle D). |
| 39 | `src/Services/ConceptGeneratorService.php::generiereAusBrief` | concept.brief_geruest | 1 | c | P2 | concepts.GENERATE | angeschlossen | Tool-Umleitung (Tabelle D, KiUmleitungTest, KiExternKonzepteTest Vergleich Plattform/MCP). Kaskade läuft rundenweise: die Tool-Transaktion rollt bis zur Antwort zurück, jede Wiederholung baut dieselben Prompts. Nur DB-Schreiben. |
| 40 | `src/Services/ConceptGeneratorService.php::fuelleCanvasAusPlan` | concept.plan | 1 | c | P2 | kein Tool (UI Planung + GenerateConceptJob) | angeschlossen | GenerateConceptJob (KiExternWiederholbar, Cache-Spiegel afterCommit); Vollkaskade-Wächter KiExternKaskadeTest (`concept.plan` als Auftrag). |
| 41 | `src/Services/ConceptGeneratorService.php::geruestAusBriefFuerOwner` | dyn. $promptKey (foodbook.grundgeruest, format.grundgeruest …) | 1 | b | P2 | 4 *_plan_from_brief-Tools | offen |  |
| 42 | `src/Services/ConceptService.php::generateWording` | concept.wording | 1 | a | P2 | ConceptWordingGenerateTool | angeschlossen | MCP-Umleitung concept_wording.GENERATE (Tabelle D, KiUmleitungTest). |
| 43 | `src/Services/Conformance/GpConformanceAdapter.php::revise` | gp.conformance_revise | 1 | b | P2 | Zuordnung prüfen | offen |  |
| 44 | `src/Services/Conformance/RecipeConformanceAdapter.php::revise` | dyn. $promptKey | 1 | b | P2 | Zuordnung prüfen | offen |  |
| 45 | `src/Services/ConformanceService.php::pruefeAdapter` | conformance.check | 1 | b | P2 | Zuordnung prüfen | offen |  |
| 46 | `src/Services/FoodbookService.php::kapitelWordingRegenerieren` | concept.wording | 1 | a | P2 | FoodbookKapitelWordingTool | angeschlossen | Tool-Umleitung foodbook_kapitel.WORDING_GENERATE (Tabelle D, KiUmleitungTest + KiExternKonzepteTest; Fixture Kapitel mit Schreibstil und Konzept-Block). Fail-soft je Block bleibt, Wartezustand über den Modus. |
| 47 | `src/Services/FoodbookService.php::kiKundentextVorschlag` | foodbook.kundentext | 1 | a | P2 | FoodbookKundentextTool | angeschlossen | MCP-Umleitung foodbook.KUNDENTEXT_GENERATE (Tabelle D, KiUmleitungTest). |
| 48 | `src/Services/FoodbookService.php::kiKapitelKundentextVorschlag` | foodbook.kundentext | 1 | a | P2 | FoodbookKapitelKundentextTool | angeschlossen | MCP-Umleitung foodbook_kapitel.KUNDENTEXT_GENERATE (Tabelle D, KiUmleitungTest). |
| 49 | `src/Services/GpAbgleich/GpAbgleichService.php::entscheiderKi` | gp.la_abgleich | 1 | b | P2 | LaGpAbgleichTool | angeschlossen | MCP-Umleitung la.GP_ABGLEICH (Tabelle D, zweite Runde). KiExternLaTest |
| 50 | `src/Services/GpAbgleich/LaFelderService.php::fuer` | gp.suggest | 1 | a | P2 | LaGpAbgleichTool | angeschlossen | MCP-Umleitung la.GP_ABGLEICH (erste Runde). KiExternLaTest |
| 51 | `src/Services/GpAromaService.php::schaetze` | gp.sensorik | 1 | a | P1 | Backfill, gps.ENRICH | angeschlossen | Backfill ANGESCHLOSSEN (#611); Tool-Weg offen. 29.10.: 7 Achsen + Grenzen nach der Antwort (Nachverarbeitung beim Aufrufer, `SensorikService::begrenze`); Anzahl bleibt 1, Einstufung a, Backfill `--neu-schaetzen` |
| 52 | `src/Services/GpFormService.php::estimateKi` | gp.zaehl_einheiten | 1 | a | P2 | GpFormsEstimateTool | angeschlossen | MCP-Umleitung (Tabelle D). |
| 53 | `src/Services/IdeenService.php::kiDivergenzConcept` | foodbook.kapitel_ideen | 1 | b | P2 | kein Tool (Aufrufer PlanningCascadeService::fanoutConceptInvention) | angeschlossen | FanoutConceptJob (KiExternWiederholbar); Vollkaskade voll_kreativ im Wächter (`foodbook.kapitel_ideen` als Auftrag). |
| 54 | `src/Services/IdeenService.php::kiDivergenzSession` | foodbook.kapitel_ideen | 1 | b | P2 | kein Tool (Aufrufer GenerateDishProposalJob) | angeschlossen | GenerateDishProposalJob (KiExternWiederholbar, `dish-proposal:{team}:{step}`); KiExternKaskadeTest. |
| 55 | `src/Services/OfferCompositionService.php::kapitelWordingRegenerieren` | concept.wording | 1 | b | P2 | kein Tool (nur UI: Angebote-Editor) | offen | Geprüft 30.10.: kein MCP-Tool ruft diese Methode (per grep), nur `Angebote/Editor.php`. Bleibt Plattform-KI (UI). |
| 56 | `src/Services/Pairing/AnkerWissenAuslese.php::lese` | anker.wissen_auslese | 1 | a | P1 | Artisan AnkerWissenCommand | offen | Anker-Pfad |
| 57 | `src/Services/PresentationDesignService.php::generateCss` | praesentation.design_css | 1 | a | P2 | PresentationDesignsGenerateCssTool | angeschlossen | MCP-Umleitung (Tabelle D). |
| 58 | `src/Services/Produktpass/ProduktpassLeser.php::liefere` | produktpass.extract | 1 | b | P2 | kein Tool (nur UI) | offen | ANHANG (PDF/Bild): Entscheidung Punkt 5 |
| 59 | `src/Services/RecipeBauartService.php::pruefe` | recipe.bauart | 1 | a | P2 | im Generator | offen |  |
| 60 | `src/Services/RecipeExtractService.php::extrahiere` | recipe.extract | 1 | b | P2 | RecipesExtractTool | offen | ANHANG (Bild/PDF): Entscheidung Punkt 5 |
| 61 | `src/Services/RecipeGeneratorService.php::generiereImLaufKern` | dyn. (vk.generator / recipe.generator) | 1 | c | P2 | RecipesGenerateTool, StubAusrezeptierenJob (Import-Hülle, 29.10.) | angeschlossen | Kaskade, mehrere propose + Schreiben Tool-Weg: recipes.GENERATE extern (Tabelle D, KiExternGenerateTest). Job-Weg (GenerateRecipeJob/Planung) folgt in 2c-2. |
| 62 | `src/Services/RecipeKomponentenPlanService.php::plane` | recipe.komponenten_plan | 1 | a | P2 | im Generator | angeschlossen | GenerateRecipePlanJob (KiExternWiederholbar, `recipe-plan:{team}:{step}`); `recipe.komponenten_plan` als Auftrag im Job-Weg. |
| 63 | `src/Services/RecipeOneShotService.php::fertigungsGlied` | recipe.production_depth | 1 | c | P2 | RecipesGenerateTool (One-Shot-Kette) | angeschlossen | Glied einer Kette Extern über recipes.ENRICH/EnrichRecipeJob (Tabelle D, KiExternEnrichTest). |
| 64 | `src/Services/RecipeOneShotService.php::eigenschaftenGlied` | recipe.eigenschaften | 1 | c | P2 | RecipesGenerateTool (One-Shot-Kette) | angeschlossen | Glied einer Kette Extern über recipes.ENRICH/EnrichRecipeJob (Tabelle D, KiExternEnrichTest). |
| 65 | `src/Services/RecipeOneShotService.php::equipmentGlied` | recipe.equipment | 1 | c | P2 | RecipesGenerateTool (One-Shot-Kette) | angeschlossen | Glied einer Kette Extern über recipes.ENRICH/EnrichRecipeJob (Tabelle D, KiExternEnrichTest). |
| 66 | `src/Services/RecipeOneShotService.php::stationVorschlag` | recipe.posten | 1 | c | P2 | RecipesGenerateTool (One-Shot-Kette) | offen | Glied einer Kette |
| 67 | `src/Services/RecipeOneShotService.php::stepGlied` | recipe.steps | 1 | c | P2 | RecipesGenerateTool (One-Shot-Kette) | angeschlossen | Glied einer Kette Extern über recipes.ENRICH/EnrichRecipeJob (Tabelle D, KiExternEnrichTest). |
| 68 | `src/Services/RecipeOneShotService.php::eignungsGlied` | recipe.sektor, recipe.level | 2 | c | P2 | RecipesGenerateTool (One-Shot-Kette) | angeschlossen | 2 propose in einer Methode Extern über recipes.ENRICH/EnrichRecipeJob (Tabelle D, KiExternEnrichTest). |
| 69 | `src/Services/RecipeReviewService.php::pruefeImLauf` | dyn. $feature (recipe.review / vk.review) | 1 | b | P2 | RecipesReviewTool | angeschlossen | MCP-Umleitung recipes.REVIEW (Tabelle D, KiUmleitungTest). |
| 70 | `src/Services/RecipeReviseService.php::freitextVorschlag` | recipe.ueberarbeiten | 1 | a | P2 | RecipesReviseTool | angeschlossen | MCP-Umleitung recipes.REVISE (Tabelle D, KiUmleitungTest). |
| 71 | `src/Services/RecipeReviseService.php::vkFreitextVorschlag` | vk.ueberarbeiten | 1 | a | P2 | VerkaufsrezepteReviseTool | angeschlossen | MCP-Umleitung verkaufsrezepte.REVISE (Tabelle D, KiUmleitungTest). |
| 72 | `src/Services/ReplacementSuggestionService.php::forGp` | component.replacement_suggest | 1 | a | P2 | kein Tool (nur UI) | offen |  |
| 73 | `src/Services/SensorikService.php::schaetzeRezept` | recipe.sensorik | 1 | a | P2 | RecipeSensorikPostTool | angeschlossen | MCP-Umleitung (Tabelle D). 29.10.: KI-Aufruf aus `bewerteRezept` hierher gezogen (bewerteRezept = schaetzeRezept + Speichern; der Abnahme-Befehl `gp-sensorik-referenz` liest nur). Nährwerte als Fakt im Kontext, Grenzen nach der Antwort (Nachverarbeitung); Einstufung a; Backfill `--neu-schaetzen=rezept` |
| 74 | `src/Services/SignalFixService.php::assist` | dyn. $plan (signal.*) | 1 | a | P2 | SignaleFixTool | angeschlossen | MCP-Umleitung signale.FIX (Tabelle D, KiUmleitungTest, KiExternSignaleTest je Assist-Signaltyp). Nur der Assist-Plan ruft die KI; deterministische Pläne, Weg-Absagen und dry_run bleiben unverändert. |
| 75 | `src/Services/SpeisekarteService.php::kiWordingVorschlag` | foodbook.kundentext | 1 | a | P2 | SpeisekarteWordingTool | angeschlossen | Tool-Umleitung speisekarte_wording.GENERATE über `speisekarteWordingRegenerieren` (Tabelle D, Test mit Karte mit Schreibstil und Position). Je Position ein Auftrag, fail-soft je Position bleibt. |
| 76 | `src/Services/SpeisekarteService.php::kiKartenText` | foodbook.kundentext | 1 | a | P2 | SpeisekarteWordingTool | offen |  |
| 77 | `src/Services/SpeisenKlassenService.php::classify` | vk.speisen_klasse | 1 | a | P2 | RecipeKlassePostTool | angeschlossen | MCP-Umleitung (Tabelle D). |
| 78 | `src/Services/SpeisenKlassenService.php::verteileRollen` | vk.rollen | 1 | a | P2 | RecipeRollenPostTool | angeschlossen | MCP-Umleitung (Tabelle D). |
| 79 | `src/Services/SpeiseplanService.php::kiWordingVorschlag` | foodbook.kundentext | 1 | a | P2 | kein Tool (nur UI) | offen |  |
| 80 | `src/Services/TitelVorschlagService.php::titelVorschlag` | dyn. $promptKey (recipe.titel_vorschlag / vk.titel_vorschlag) | 1 | a | P2 | kein Tool (nur UI) | offen |  |
| 81 | `src/Services/Websuche/CoreWebSucheTransport.php::suche` | artikel.web_recherche (include_web_search) | 1 | b | P2 | kein Tool (nur UI) | offen | WEBSUCHE: eigener Weg, Entscheidung Punkt 5 |
| 82 | `src/Services/ZukaufBasisrezeptService.php::baue` | recipe.ruestschritt | 1 | a | P2 | kein Tool (nur UI) | offen |  |

## B · Zusatzwege

| Nr | Stelle | Weg | Einst. | Phase | MCP-Einstieg | Status | Hinweis |
|---|---|---|---|---|---|---|---|
| 83 | `src/Services/VoiceCommandService.php` → `AiGatewayService::callWithTools` | Voice-Agent (Tier-D-Tool-Loop) | c | P2 | — | Ausnahme (Vorschlag) | AGENTISCHER LOOP: Claude ist selbst der Loop, ruft MCP-Tools direkt. Ausnahme-Vorschlag. Dominique 29.10.: der Voice-Agent ist nur ein Gadget, Ausnahme unkritisch |
| 84 | `src/Services/RecipeImageService.php::generateAndStore` | Bildgenerierung (Core ImageGenerationService) | c | P2 | RecipeImageGenerateTool | Ausnahme (Vorschlag) | Ausnahme-Vorschlag: Claude erzeugt keine Bilder, bleibt Plattform |
| 85 | `src/Services/Ai/SemanticRetrievalService.php`, `PoolEmbeddingService.php`, `KnowledgeEmbeddingService.php` | Embeddings (text-embedding-3-large) | — | — | — | Ausnahme (Vorschlag) | AUSNAHME (Entscheidung Dominique): bleibt OpenAI |
| 86 | `src/Services/Websuche/CoreWebSucheTransport.php` (`include_web_search`, Eintrag auch in Tabelle A) | Websuche über den Plattform-Provider | b | P2 | — | offen | Entscheidung Punkt 5: Claude sucht selbst (extern) oder bleibt Plattform |

## Summen

- Methoden nach Einstufung: a = 53, b = 20, c = 9.
- Phase P1 (gp.sensorik, gp.anker, BulkEnrich-GP-Schritte, Anker-Auslese): 6 Methoden. Phase P2: 76.
- Angeschlossen: 1 Methode (`GpAromaService::schaetze` über Backfill `--anbieter=extern`, #611). Alle anderen offen.
- Methoden ohne MCP-Einstieg (12): per MCP nicht erreichbar. Entweder wird ein Einstieg gebaut (Tool oder Artisan) oder Dominique erklärt die Zeile zur Ausnahme (UI-Knopf ohne Gegenstück).

## C · Prompt-Keys ohne eigene `propose()`-Stelle in Tabelle A (13)

Der Wächter verlangt, dass jeder Key aus `config/foodalchemist.php` (`ai.prompts`) in dieser Datei steht. Diese 13 kommen in Tabelle A nicht als Literal vor. Geklärt am 29.10. (Aufrufer per grep nachgewiesen): 7 Keys laufen über `SignalFixService::assist`, 5 sind tot, `demo.echo` ist ein Testprompt.

| Prompt-Key | Vermuteter Aufrufer | Status |
|---|---|---|
| `signal.supplier_inquiry` | `SignalFixService::assist` (Tabelle A) — der Key kommt aus `SignalCockpit::planFor()` (Signal-Typ → Prompt), Aufruf mit dynamischem `$plan['prompt']` | gedeckt durch Tabelle A (Assist-Zeile), angeschlossen (Paket Signale) |
| `signal.margin_levers` | `SignalFixService::assist` (Tabelle A) — der Key kommt aus `SignalCockpit::planFor()` (Signal-Typ → Prompt), Aufruf mit dynamischem `$plan['prompt']` | gedeckt durch Tabelle A (Assist-Zeile), angeschlossen (Paket Signale) |
| `signal.vk_release_advice` | `SignalFixService::assist` (Tabelle A) — der Key kommt aus `SignalCockpit::planFor()` (Signal-Typ → Prompt), Aufruf mit dynamischem `$plan['prompt']` | gedeckt durch Tabelle A (Assist-Zeile), angeschlossen (Paket Signale) |
| `signal.serving_form_suggest` | `SignalFixService::assist` (Tabelle A) — der Key kommt aus `SignalCockpit::planFor()` (Signal-Typ → Prompt), Aufruf mit dynamischem `$plan['prompt']` | gedeckt durch Tabelle A (Assist-Zeile), angeschlossen (Paket Signale) |
| `signal.recipe_category_suggest` | `SignalFixService::assist` (Tabelle A) — der Key kommt aus `SignalCockpit::planFor()` (Signal-Typ → Prompt), Aufruf mit dynamischem `$plan['prompt']` | gedeckt durch Tabelle A (Assist-Zeile), angeschlossen (Paket Signale) |
| `signal.recipe_naming_suggest` | `SignalFixService::assist` (Tabelle A) — der Key kommt aus `SignalCockpit::planFor()` (Signal-Typ → Prompt), Aufruf mit dynamischem `$plan['prompt']` | gedeckt durch Tabelle A (Assist-Zeile), angeschlossen (Paket Signale) |
| `price.plausi` | `SignalFixService::assist` (Tabelle A) — der Key kommt aus `SignalCockpit::planFor()` (Signal-Typ → Prompt), Aufruf mit dynamischem `$plan['prompt']` | gedeckt durch Tabelle A (Assist-Zeile), angeschlossen (Paket Signale) |
| `gp.piece_default_g` | Config-Key, in `Regeln/RegelPromptBlock` als Regel-Ziel geführt; kein `propose()`-Aufrufer in `src/` | tot (nicht löschen in diesem Paket) |
| `gp.domain` | nur Config und `PromptRegistryTest`; kein Aufrufer in `src/` | tot (nicht löschen in diesem Paket) |
| `gp.la_suggest` | nur Config und `PromptRegistryTest`; kein Aufrufer in `src/` | tot (nicht löschen in diesem Paket) |
| `gp.term_la_rank` | nur Config und `PromptRegistryTest`; kein Aufrufer in `src/` | tot (nicht löschen in diesem Paket) |
| `demo.echo` | Test-/Diagnoseprompt (`Spec85WebsucheTransportTest`, in `KiKlassenEinstellung` ausgeschlossen) | kein Produktivaufruf, bleibt Plattform |
| `chat.message` | nur Config und `PromptRegistryTest`; kein Aufrufer in `src/` | tot (nicht löschen in diesem Paket) |


## D · MCP-Umleitung je Tool (`Support/KiUmleitung`)

Ein MCP-Aufruf eines umgeleiteten Tools liefert `status: ki_auftrag_offen` mit Auftrags-ID statt eines Provider-Calls; der Client holt den Auftrag (`ki_auftraege.GET`), beantwortet ihn (`ANTWORT`) und wiederholt den Aufruf. Umgeleitet wird zentral in `FaRechteToolHuelle::execute`, nur für Tools aus `KiUmleitung::TOOLS`. Der Lauf steht in einer Transaktion: wartet er auf einen Auftrag, Rollback, danach Auftrag anlegen (kein Halbzustand). Der Rollback deckt nur die Datenbank: Tools mit Nebenwirkungen außerhalb der DB vor oder zwischen `propose()` bleiben Klasse c und ausgenommen. Schalter `foodalchemist.ai.mcp_extern` (Standard an). Je Tool ein Test in `tests/Feature/KiUmleitungTest.php`.

| Tool | Status | Begründung |
|---|---|---|
| `foodalchemist.gp_forms.ESTIMATE` | umgeleitet | ein `propose()` (`gp.zaehl_einheiten`), danach nur DB-Schreiben |
| `foodalchemist.presentation_designs.GENERATE_CSS` | umgeleitet | ein `propose()`, schreibt nicht |
| `foodalchemist.recipe_coherence.POST` | umgeleitet | ein `propose()` je Modus, danach DB-Schreiben |
| `foodalchemist.recipe_klasse.POST` | umgeleitet | ein `propose()`, optional DB-Übernahme; fängt `Throwable`, darum meldet die Hülle über den Modus |
| `foodalchemist.recipe_rollen.POST` | umgeleitet | ein `propose()`, optional DB-Übernahme |
| `foodalchemist.recipe_sensorik.POST` | umgeleitet | ein `propose()`, danach DB-Schreiben |
| `foodalchemist.gps.ENRICH` | Lauf-Flag (`KiUmleitung::LAUF_FLAG`) | Queue: `propose()` läuft im Worker. Der externe MCP-Aufruf setzt `extern` am Lauf (`starteGp`) und am Job; der Worker legt KI-Aufträge an (ohne Leer-Vorschlag), nach ANTWORT den Aufruf wiederholen. Ohne MCP-Herkunft Plattformweg |
| `foodalchemist.recipes.ENRICH` | Job-Mechanik (P2a/P2b) | `EnrichRecipeJob` ist `KiExternWiederholbar`: Rumpf in Sammel-Transaktion, Rollback bis zur Antwort, `ki_auftraege.ANTWORT` startet den Lauf neu, ein Lauf je Rezept (`enrich-recipe:{team}:{rezept}`). Inventur der Nicht-DB-Nebenwirkungen: Wartezustand/Fortschritt nur DB; Cache nur im Modal-Pfad ohne Step (nicht MCP); KI-Bilder und Produktfoto bleiben Plattform (im Extern-Weg aus); die Wache (`KiExternLauf::$nebenwirkungen`) meldet Queue-Dispatch/Cache/Mail im Test und im Log |
| `foodalchemist.gp_aroma_backfill.RUN` | Service-Weg (#630) | startet `GpAromaBackfillService` (derselbe wie der Befehl); per MCP immer extern (`KiUmleitung::externFuerLauf`), KI-Aufträge je Lücke, Aufruf nach ANTWORT wiederholen; Trockenlauf Standard, Scope: eigenes Team (Master zusätzlich globale Stamm-GPs) |
| `foodalchemist.recipes.GENERATE` | ausgenommen (Klasse c) | Generator-Kaskade |
| `foodalchemist.concepts.GENERATE` | umgeleitet (mehrere Runden) | Brief-Pfad: `concept.brief_geruest`, danach Gerüst-Befüllung und Wording-Pass (`concept.wording`, fail-soft); Rollback bis alles beantwortet, nur DB-Schreiben, keine Queue-Jobs |
| `foodalchemist.foodbook_kapitel.WORDING_GENERATE` | umgeleitet | je Konzept-Block ein `propose()` (`concept.wording`), danach Overrides am Block; ohne Kapitel-Schreibstil kein KI-Aufruf |
| `foodalchemist.speisekarte_wording.GENERATE` | umgeleitet | je Position ein `propose()` (`foodbook.kundentext`), fail-soft je Position; ohne Schreibstil der Karte kein KI-Aufruf |
| `foodalchemist.{foodbook,angebot,format,speisekarte}.PLAN_FROM_BRIEF` | umgeleitet + Job-Weg | Gerüst-`propose()` im Tool-Aufruf (Rollback bis beantwortet); danach startet `starteKaskade` die Kaskade: ihre Jobs erben `extern` aus dem MCP-Aufruf (`KiExternJobTeil`, Dispatch aus externem Rumpf erst nach dem Commit) und legen die weiteren Aufträge an. Wächter: Vollkaskade (datenbank + voll_kreativ) mit werfendem Plattform-Provider, 0 Aufrufe |
| `foodalchemist.speiseplan.PLAN_FROM_BRIEF` | Job-Weg | kein Gerüst-Aufruf im Tool; die KI läuft nur in `MaterializeSpeiseplanCellJob` (KiExternWiederholbar), das Tool läuft normal und die Jobs erben `extern` aus dem MCP-Aufruf |
| `foodalchemist.la.GP_ABGLEICH` | umgeleitet (2 Runden) | `gp.suggest` (Felder), danach `gp.la_abgleich`; der zweite Prompt hängt von der ersten Antwort ab. Schreibt nur DB (Abgleich-Log, bei `apply` Verknüpfung/neues GP). `ki=false` = Sparsamkeit: nie ein Auftrag |
| MCP-Herkunft → Folge-Jobs | Merker `KiExternModus::imMcp` (Hülle, jedes FA-Tool) | Ein externer MCP-Aufruf markiert den Lauf; Jobs, die er anstößt (`ClassifyLaJob`, `GpAromaNachholenJob`, `SensorikNachholenJob`, `ConformanceCheckJob` bei GP), laufen mit `extern=true` (`fuerDispatch`). Kein Auftrag, keine Transaktion im Tool selbst |
| `signale.FIX` (Assist) | `KiUmleitung::TOOLS` | Ein `propose()` je Signal-Typ (`signal.supplier_inquiry`, `signal.margin_levers`, `price.plausi`, `signal.vk_release_advice`, `signal.serving_form_suggest`, `signal.recipe_category_suggest`, `signal.recipe_naming_suggest`), Entwurf ohne Schreiben und ohne Schließen. Deterministische Pläne und `dry_run` rufen keine KI. `SignalFixJob` bleibt deterministisch ohne KI (geprüft per Code-Lesen: nur `SignalFixService::execute`, kein Gateway-Aufruf) |
| Folge-Aufträge sichtbar | `KiUmleitung::mitFolgeHinweis`, `SignalDetektorService::kiAuftraegeOffen` (`SignalTyp::KiAuftraegeOffen`) | Tool-Ergebnis meldet `folgeauftraege.jobs_angestossen` (Hinweis auf `ki_auftraege.GET`, 24-h-Ablauf). Cockpit-Signal je Team und Feature (nicht je Auftrag): offen > 2 h = Info, abgelaufen in 7 Tagen = Warnung; schließt sich selbst. Abgelaufene Aufträge werden nicht verworfen und lösen keinen Plattform-Fallback aus: die Lücke bleibt in den Daten, der nächste Nachhol-Lauf findet sie wieder (Test) |
| alle übrigen KI-Tools (Kundentext, Wording, Revise, Extract, Review, Planung, Signale, …) | noch nicht bewertet | Nebenwirkungen außerhalb der DB je Tool prüfen, dann in `KiUmleitung::TOOLS` aufnehmen (Phase 2) |


## E · Fingerabdruck und Zähler (externer Weg)

`messages_hash` = SHA-256 über alle Prompt-Nachrichten. Er ordnet die Antwort der Wiederholung zu; ändert sich der Prompt dazwischen, entsteht ein zweiter Auftrag und die erste Antwort bleibt unverbraucht.

| Quelle der Instabilität | Stand |
|---|---|
| Anker-Vokabular (`BulkEnrichService::gpAnkerKandidaten`), unsortiertes `whereIn` | behoben: `orderBy('slug')`, Test `KiExternGpTest` |
| Semantische Kandidaten (`KnowledgeEmbeddingService::searchAnkerSlugs`, OpenAI-Embedding) | offen: Ist OpenAI gesperrt, bleibt die Liste rein lexikalisch (anderer Hash als bei erreichbarem OpenAI). Auftrag und Wiederholung müssen im selben Zustand laufen |
| Zeit/Zufall im Prompt | keine gefunden (Kern setzt `with_context=false`) |
| Nachbar-GPs im Sensorik-Kontext (`orderBy g.name limit 3`) | Gleichstand bei gleichem Namen unbestimmt; erst nach der Sensorik-Umstellung (#617) betrachten |

Zähler (`KiAuftragService::zaehler`, im Backfill `--anbieter=extern` am Ende und im Protokoll): angelegt, beantwortet, verbraucht (je ab Lauf-Beginn), Bestand offen / beantwortet-liegt / abgelaufen, `verwaist` (abgelaufen + über 1 h beantwortet und unverbraucht) `doppelt` (verschiedene Prompts je Feature und Ziel; > 0 heißt: Fingerabdruck instabil oder Kontext hat sich geändert) und `wiederholt` (derselbe Prompt mehrfach: Re-Roll der Antwortprüfung `structural_retry`, bis 3 Versuche; erwartet, kein Fehler).

Tabelle D, Nachtrag P2b: Nicht belegt bleiben in der Enrich-Kette `recipe.posten` (`stationVorschlag`) und `recipe.garverlust` (im Test nicht durchlaufen: keine Zutaten). Sie hängen am selben Job, sind mechanisch erfasst, aber nicht durch einen Test belegt. Zähler `wiederholt`: `recipe.steps` wurde im Test 3× mit gleichem Prompt beauftragt (Re-Roll der Antwortprüfung, die Test-Antwort war unbrauchbar) — erwartet.

Tabelle D, Nachtrag P2c-1:

| Tool / Job | Status | Begründung |
|---|---|---|
| `foodalchemist.recipes.GENERATE` | umgeleitet (Tool-Hülle) | synchron: Rezept, Zutaten und Matching entstehen im Aufruf; Transaktion, Rollback bis zur Antwort, mehrere KI-Glieder = mehrere Runden. Vergleichstest Plattform = MCP (`KiExternGenerateTest`) |
| `SensorikNachholenJob` (Kind des Generators/der Rezept-Anlage) | `KiExternWiederholbar` | Fund: der Generator löst per `afterCommit` einen Job aus, der später den Plattform-Provider rief (`SensorikNachholen::ausloesen`). Jetzt erbt das Kind den Extern-Modus des auslösenden Laufs (`extern: KiExternModus::aktiv()`), legt einen eigenen Auftrag (`recipe.sensorik`, Lauf-Schlüssel `sensorik-nachholen:{team}:{rezept}`) an und ruft den Plattform-Provider nie (Test) |
| `GenerateRecipeJob` / `EnrichGeneratedRecipeJob` / `ConformanceCheckJob` | `KiExternWiederholbar` (P2c-2) | Job-Weg der UI und der Planungs-Kaskade: Wartestatus im Cache `pending` + `wartet_auf` (nie `error`, auch nicht zwischendurch), Folge-Jobs (Enrich, Konformität) und Sub-Rezept-Kinder per `afterCommit` mit Extern-Flag; `_extern` steht in `KIND_ERBT` und im Parameter-Bündel, `PlanningCascadeService` liest `$jobParams['_extern']`. Einstiegs-Tools, die `_extern` setzen (planung_kaskade.*), folgen in 2c-3 |


Tabelle D, Nachtrag P2c-2: Fund im Test — `BulkEnrichService::verarbeiteRezept` fängt Ausnahmen je Feld und legt dabei mehrere Aufträge je Lauf an (alle Glieder warten parallel, ein Rollback nimmt die Leer-Vorschläge zurück). Das ist im externen Lauf in Ordnung (ein Paket Aufträge je Runde), aber nicht schön: der GP-Zwilling `verarbeiteGp` kennt seit P1b einen eigenen Pfad ohne Leer-Vorschlag. Der Rezept-Zwilling bleibt unverändert (Plattformweg bytegleich).

Tabelle D, Nachtrag P2c-3 (erste Charge, alle über die Tool-Hülle, je Tool ein Test in `KiUmleitungTest`):

| Tool | Status | Begründung |
|---|---|---|
| `foodalchemist.recipes.REVIEW` | umgeleitet | read-only, ein `propose()` (`recipe.review`/`vk.review`) |
| `foodalchemist.recipes.REVISE` | umgeleitet | ein `propose()` (`recipe.ueberarbeiten`), optional DB-Übernahme; Folge-Job `SensorikNachholen` erbt den Extern-Modus (P2c-1) |
| `foodalchemist.verkaufsrezepte.REVISE` | umgeleitet | wie `recipes.REVISE` (`vk.ueberarbeiten`) |
| `foodalchemist.planung_leitplanken.EXTRACT` | umgeleitet | ein `propose()` (`planung.leitplanken`), schreibt höchstens Regler der Sitzung |
| `foodalchemist.planung_vorschlag.POST` | umgeleitet | wie oben, schreibt nichts. Das Tool fängt nur `InvalidArgumentException`: der Plattformweg mit dem Fake-Provider wirft „strukturell unbrauchbar“ — bekannt, kein Teil der Umleitung |
| `foodalchemist.concept_wording.GENERATE` | umgeleitet | `propose()` je Slot/Kopf, danach DB-Schreiben |


Tabelle D, Nachtrag P2c-3b (Kundentexte, über die Tool-Hülle, je Tool ein Test): `foodbook.KUNDENTEXT_GENERATE`, `foodbook_kapitel.KUNDENTEXT_GENERATE`, `angebot.KUNDENTEXT_GENERATE` umgeleitet (ein `propose()` `foodbook.kundentext`, liefern nur einen Vorschlag, schreiben nichts). Noch NICHT belegt, deshalb in `KiUmleitung::AUSGENOMMEN`: `foodbook_kapitel.WORDING_GENERATE` und `speisekarte_wording.GENERATE` — bei leerem Kapitel/leerer Karte entsteht kein KI-Aufruf, der Test braucht Fixtures mit belegten Slots/Positionen. Die Services haben kein dispatch/Cache/Storage.

## F · Inventur ALLER Token-Verbraucher (Stand 29.10., Dominique: „alles, was Plattform-KI-Token kostet, muss per MCP über Claude laufen können“)

Quellen: die 77 Prompt-Keys aus `config/foodalchemist.php`, Tabellen A–D dieser Datei, Code-Suche nach Jobs, Artisan-Befehlen und Zeitplan, `foodalchemist_ai_call_log` der **lokalen Dev-DB** (`foodalchemist`, 2.612 Aufrufe, 12.06.–16.10.2026). **Grenze:** das ist KEINE Produktivnutzung und KEINE letzten 30 Tage — demo/io-Zahlen stehen mir nicht zur Verfügung (siehe F5: `ki_verbrauch.GET` zeigt für das Master-Team 0). Die Spalten „Aufrufe / Ø Token“ zeigen daher nur, welche Features überhaupt im Log vorkommen und wie schwer ein Aufruf ist; `kosten_usd` ist lokal nicht befüllt (0).

### F1 · Je Prompt-Key (Rang nach lokalen Gesamt-Token)
| Prompt-Key | lokal: Aufrufe | Ø Token/Aufruf | Token gesamt | Einstieg (Methode / Tool) | Status heute | Paket |
|---|---|---|---|---|---|---|
| `recipe.generator` | 307 | 28592 | 8.777.592 | RecipesGenerateTool. StubAusrezeptierenJob (Import-Hülle. 29.10.) | angeschlossen | 2d (übrige) |
| `conformance.check` | 332 | 17944 | 5.957.396 | – | Plattform | Konformität: Job angeschlossen (2c-2), Tool-Einstieg offen (2d) |
| `vk.generator` | 123 | 35763 | 4.398.888 | RecipesGenerateTool. StubAusrezeptierenJob (Import-Hülle. 29.10.) | angeschlossen | 2d (übrige) |
| `recipe.steps` | 94 | 15843 | 1.489.199 | RecipesGenerateTool (One-Shot-Kette); recipes.ENRICH (steps) | teilweise | 2d (übrige) |
| `recipe.ueberarbeiten` | 124 | 10019 | 1.242.373 | RecipesReviseTool | angeschlossen | 2d (übrige) |
| `foodbook.kapitel_ideen` | 43 | 18734 | 805.576 | kein Tool (Kaskade) | angeschlossen | Kaskade |
| `concept.plan` | 31 | 20770 | 643.869 | GenerateConceptJob; planAusBrief (Planung UI) | angeschlossen | Kaskade |
| `recipe.review` | 82 | 5440 | 446.046 | RecipesReviewTool | angeschlossen | 2d (übrige) |
| `recipe.eigenschaften` | 87 | 4139 | 360.056 | RecipesGenerateTool (One-Shot-Kette); recipes.ENRICH (Schritt); verkaufsrezepte.ENRICH | teilweise | 2d (übrige) |
| `recipe.sensorik` | 70 | 3732 | 261.262 | RecipeSensorikPostTool | angeschlossen | 2d (übrige) |
| `recipe.category` | 54 | 4537 | 245.015 | recipes.ENRICH (Schritt) | Plattform | 2d (übrige) |
| `concept.brief_geruest` | 52 | 4016 | 208.828 | concepts.GENERATE (generiereAusBrief); geruestAusBriefFuerOwner (PLAN_FROM_BRIEF, Planung UI) | angeschlossen | Konzepte + Kaskade |
| `recipe.equipment` | 70 | 2872 | 201.062 | RecipesGenerateTool (One-Shot-Kette); recipes.ENRICH (Schritt) | teilweise | 2d (übrige) |
| `recipe.production_depth` | 69 | 2438 | 168.204 | RecipesGenerateTool (One-Shot-Kette); recipes.ENRICH (Schritt); verkaufsrezepte.ENRICH | teilweise | 2d (übrige) |
| `recipe.level` | 42 | 3485 | 146.379 | DetailPanel::kiEignung; RecipesGenerateTool (One-Shot-Kette) | teilweise | 2d (übrige) |
| `recipe.sektor` | 42 | 3361 | 141.168 | DetailPanel::kiEignung; RecipesGenerateTool (One-Shot-Kette) | teilweise | 2d (übrige) |
| `gp.zaehl_einheiten` | 133 | 867 | 115.350 | GpFormsEstimateTool | angeschlossen | Paket LA-Wege (vorgezogen) |
| `recipe.komponenten_plan` | 56 | 1695 | 94.943 | GenerateRecipePlanJob; im Generator | teilweise | Kaskade angeschlossen; Generator-Weg 2d (übrige) |
| `recipe.verpackungsmasse` | 126 | 725 | 91.390 | Artisan-Befehl | Plattform | 2d (übrige) |
| `vk.plating` | 36 | 2145 | 77.223 | verkaufsrezepte.ENRICH | Plattform | 2d (übrige) |
| `concept.wording` | 26 | 2736 | 71.146 | ConceptWordingGenerateTool; FoodbookKapitelWordingTool; – (offer_chapter/offer_block, nur UI) | teilweise | Konzepte angeschlossen; Angebote-Editor (UI) bleibt Plattform |
| `gp.suggest` | 44 | 1606 | 70.671 | GpModal::einordnungKiVorschlagen; GpModal::kiVorschlagNaming; GpModal::nameAusLeadLa; Job nach LA-Import; LaGpAbgleichTool | teilweise (LaGpAbgleichTool, Job nach LA-Import); UI-Knöpfe Plattform | LA-Wege (UI bleibt) |
| `recipe.dichteklasse` | 26 | 2655 | 69.038 | recipes.ENRICH; recipes.ENRICH (Schritt); verkaufsrezepte.ENRICH | teilweise | 2d (übrige) |
| `recipe.geschmack` | 24 | 2499 | 59.978 | recipes.ENRICH (Schritt); verkaufsrezepte.ENRICH | Plattform | 2d (übrige) |
| `planung.leitplanken` | 22 | 2471 | 54.352 | planung_leitplanken.EXTRACT. planung_kaskade.START. planung_vorschlag.POST | angeschlossen | 2c-3c/d (Konzepte. Kaskade) |
| `vk.wording` | 32 | 1542 | 49.332 | verkaufsrezepte.ENRICH | Plattform | 2d (übrige) |
| `vk.kohaerenz` | 32 | 1534 | 49.095 | recipe_coherence.POST | angeschlossen | 2d (übrige) |
| `format.grundgeruest` | 3 | 14236 | 42.708 | 4 *_plan_from_brief-Tools | Plattform | 2c-3c/d (Konzepte. Kaskade) |
| `recipe.extract` | 7 | 5659 | 39.616 | RecipesExtractTool | Plattform | 2d-1 (Text-Pfad). Bild/PDF Plattform |
| `vk.speisen_klasse` | 33 | 1181 | 38.968 | RecipeKlassePostTool | angeschlossen | 2d (übrige) |
| `foodbook.kundentext` | 10 | 3141 | 31.411 | AngebotKundentextTool; FoodbookKapitelKundentextTool; FoodbookKundentextTool; SpeisekarteWordingTool; SpeiseplanService::kiWordingVorschlag | teilweise | 2c-3c/d (Konzepte. Kaskade) |
| `vk.review` | 18 | 1445 | 26.001 | RecipesReviewTool | angeschlossen | 2d (übrige) |
| `recipe.name_putzen` | 15 | 1707 | 25.610 | RecipeModal::namePutzen | Plattform | 2d (übrige) |
| `vk.ueberarbeiten` | 3 | 6535 | 19.604 | VerkaufsrezepteReviseTool | angeschlossen | 2d (übrige) |
| `gp.conformance_revise` | 11 | 1668 | 18.349 | – | Plattform | Konformität: Job angeschlossen (2c-2), Tool-Einstieg offen (2d) |
| `foodbook.grundgeruest` | 6 | 2495 | 14.968 | 4 *_plan_from_brief-Tools | Plattform | 2c-3c/d (Konzepte. Kaskade) |
| `component.replacement_suggest` | 4 | 3714 | 14.854 | ReplacementSuggestionService::forGp | Plattform | 2d (übrige) |
| `praesentation.design_css` | 2 | 3303 | 6.606 | PresentationDesignsGenerateCssTool | angeschlossen | angeschlossen (Hülle) |
| `vk.servier_vehikel` | 10 | 639 | 6.393 | verkaufsrezepte.ENRICH | Plattform | 2d (übrige) |
| `recipe.regeneration` | 5 | 1192 | 5.962 | recipes.ENRICH | angeschlossen | 2d (übrige) |
| `vk.regeneration` | 4 | 1293 | 5.170 | verkaufsrezepte.REVISE | Plattform | 2d (übrige) |
| `recipe.titel_vorschlag` | 3 | 1715 | 5.146 | TitelVorschlagService::titelVorschlag | Plattform | 2d (übrige) |
| `recipe.garverlust` | 5 | 835 | 4.175 | IngredientEditor::garverlustVorschlag; recipes.ENRICH | Plattform | 2d (übrige) |
| `recipe.description` | 3 | 1167 | 3.501 | recipes.ENRICH (Schritt) | Plattform | 2d (übrige) |
| `vk.teller_heber` | 2 | 1197 | 2.394 | recipe_coherence.POST | angeschlossen | 2d (übrige) |
| `planning.dish_proposal_revise` | 1 | 2281 | 2.281 | planung_kaskade.* | angeschlossen | Kaskade |
| `gp.allergene` | 4 | 426 | 1.703 | gps.ENRICH | Plattform | 2d (übrige) |
| `gp.condition` | 2 | 685 | 1.370 | gps.ENRICH; gps.ENRICH (condition) | Plattform | 2d (übrige) |
| `gp.naehrwerte` | 4 | 334 | 1.337 | gps.ENRICH; gps.ENRICH (naehrwerte) | Plattform | 2d (übrige) |
| `gp.tags` | 4 | 326 | 1.302 | gps.ENRICH; gps.ENRICH (tags) | Plattform | 2d (übrige) |
| `vk.titel_vorschlag` | 1 | 1215 | 1.215 | TitelVorschlagService::titelVorschlag | Plattform | 2d (übrige) |
| `gp.anker` | 1 | 531 | 531 | gps.ENRICH | Plattform | 2d (übrige) |
| `vk.rollen` | 3 | 132 | 395 | RecipeRollenPostTool | angeschlossen | 2d (übrige) |
| `la.nonfood` | 12 | 0 | 0 | Job | angeschlossen (`--anbieter=extern`) | LA-Wege |
| `recipe.posten` | 1 | 0 | 0 | RecipesGenerateTool (One-Shot-Kette) | Plattform | 2d (übrige) |
| `demo.echo` | 0 | – | 0 | – | tot | tot / Test (nicht löschen in diesem Paket) |
| `artikel.web_recherche` | 0 | – | 0 | CoreWebSucheTransport::suche | Plattform | Paket LA-Wege (vorgezogen) |
| `recipe.ruestschritt` | 0 | – | 0 | ZukaufBasisrezeptService::baue; recipes.ENRICH (steps) | Plattform | 2d (übrige) |
| `gp.domain` | 0 | – | 0 | – | tot | tot / Test (nicht löschen in diesem Paket) |
| `gp.piece_default_g` | 0 | – | 0 | – | tot | tot / Test (nicht löschen in diesem Paket) |
| `gp.verluste` | 0 | – | 0 | – (gp_forms.ESTIMATE?) | Plattform | 2d (übrige) |
| `anker.wissen_auslese` | 0 | – | 0 | Artisan AnkerWissenCommand | Plattform | Artisan. Phase 1 |
| `gp.sensorik` | 0 | – | 0 | Backfill. gps.ENRICH; gps.ENRICH | teilweise | 2d (übrige) |
| `gp.la_suggest` | 0 | – | 0 | – | tot | tot / Test (nicht löschen in diesem Paket) |
| `gp.la_abgleich` | 0 | – | 0 | LaGpAbgleichTool | angeschlossen | LA-Wege |
| `gp.term_la_rank` | 0 | – | 0 | – | tot | tot / Test (nicht löschen in diesem Paket) |
| `recipe.bauart` | 0 | – | 0 | im Generator | Plattform | 2d (übrige) |
| `produktpass.extract` | 0 | – | 0 | ProduktpassLeser::liefere | Plattform | ausgenommen (Anhang/Storage) |
| `vk.marketing` | 0 | – | 0 | AngebotKundentextTool; FoodbookKundentextTool | Plattform | 2d (übrige) |
| `price.plausi` | 0 | – | 0 | SignaleFixTool | angeschlossen | Signale |
| `chat.message` | 0 | – | 0 | – | tot | tot / Test (nicht löschen in diesem Paket) |
| `signal.supplier_inquiry` | 0 | – | 0 | SignaleFixTool | angeschlossen | Signale |
| `signal.margin_levers` | 0 | – | 0 | SignaleFixTool | angeschlossen | Signale |
| `signal.vk_release_advice` | 0 | – | 0 | SignaleFixTool | angeschlossen | Signale |
| `signal.serving_form_suggest` | 0 | – | 0 | SignaleFixTool | angeschlossen | Signale |
| `signal.recipe_category_suggest` | 0 | – | 0 | SignaleFixTool | angeschlossen | Signale |
| `signal.recipe_naming_suggest` | 0 | – | 0 | SignaleFixTool | angeschlossen | Signale |

Summe lokal: 2.612 Aufrufe. 28.218.296 Token. Top-3 lokal: `recipe.generator` 307× Ø 28592, `conformance.check` 332× Ø 17944, `vk.generator` 123× Ø 35763.

### F2 · Verbraucher ohne `propose()`
| Verbraucher | Einstieg | Status | Empfehlung |
|---|---|---|---|
| Websuche (`include_web_search`, `artikel.web_recherche`, `CoreWebSucheTransport`) | Artikel-Recherche (UI/Tool) | Plattform | anschließen (2d-2): `ANTWORT.quellen` → `sources`; eine Antwort OHNE Quellen bei einem Websuche-Auftrag wird abgelehnt |
| Voice-Agent (`AiGatewayService::callWithTools`) | `VoiceCommandService` (UI) | Plattform | ausgenommen (Gadget, Claude ist selbst der Loop) |
| Bildgenerierung (`RecipeImageService` → Core `ImageGenerationService`) | `recipe_images.GENERATE`, `EnrichRecipeJob` (KI-Bilder) | Plattform | ausgenommen (Claude erzeugt keine Bilder); im Extern-Lauf aus |
| Embeddings (Qdrant, `text-embedding-3-large`) | semantische Suche, Wissens-/Pool-Index | Plattform | ausgenommen (Entscheid Dominique); nicht im `ki_verbrauch` erfasst |
| Bild-/PDF-Anhänge (`recipe.extract` mit Bild, `produktpass.extract`) | Rezept-Import, Produktpass | Plattform | ausgenommen (siehe 2d-1: Text-Pfad anschließen, Bild liest Claude selbst) |

### F3 · Jobs, Befehle, Zeitplan
| Job / Befehl | Auslöser | Prompt-Keys | Status | Paket |
|---|---|---|---|---|
| `BulkEnrichGpJob` | `gps.ENRICH`, UI, Backfill | gp.condition/tags/anker/sensorik/naehrwerte | angeschlossen | P1b |
| `GpAromaBackfillCommand` (`foodalchemist:gp-aroma-backfill`) | manuell (Forge/SSH) | gp.anker, gp.sensorik | angeschlossen (`--anbieter=extern`); MCP-Werkzeug zum Starten ohne Forge: `foodalchemist.gp_aroma_backfill.RUN` (#630) | P1 |
| `EnrichRecipeJob` | `recipes.ENRICH`, UI, Kaskade | recipe.* (Anreicherung) | angeschlossen | 2b |
| `SensorikNachholenJob` | Rezept-Anlage/-Änderung (afterCommit, 90 s verzögert) | recipe.sensorik | angeschlossen | 2c-1 |
| `GenerateRecipeJob`, `EnrichGeneratedRecipeJob`, `ConformanceCheckJob` | Generator-UI, Planungs-Kaskade | recipe.generator, vk.generator, conformance.check, recipe.* | angeschlossen (Job-Weg; erben `extern` aus MCP-Aufruf/Lauf per Konstruktor, `_extern` am Einstieg nicht mehr nötig) | 2c-2, Einstiege 2c-3d |
| `ClassifyLaJob` | `LaFirstGpService` (LA-First/Import), UI | gp.suggest | angeschlossen (MCP-Herkunft erbt `extern`) | LA-Wege |
| `NonFoodKiBatchJob` / `NonFoodErkennenCommand` | Artisan `foodalchemist:nonfood-erkennen` | la.nonfood | angeschlossen (`--anbieter=extern`) | LA-Wege |
| `GpAromaNachholenJob` | GP-Anlage (`GpAromaNachholen`) | gp.sensorik, gp.anker | angeschlossen (MCP-Herkunft erbt `extern`, `gp-aroma-nachholen:{team}:{gp}`) | LA-Wege |
| `la.GP_ABGLEICH` (`GpAbgleichService`, `LaFelderService`) | Tool, UI (LA-Detail) | gp.suggest, gp.la_abgleich | angeschlossen (Umleitung, 2 Runden) | LA-Wege; ≈ 24.000 Token je Artikel (2 Calls, io gemessen) |
| `ImportArticlesJob` (Necta-/Artikel-Import) | `ArticleImportTriggerService`, Zeitplan `NectaAbgleichCommand` | – (kein direkter `propose()` gefunden; löst über `LaFirstGpService` ggf. `ClassifyLaJob` aus) | prüfen | LA-Wege |
| `SignalFixJob` / `signale.FIX` | Review-Queue (UI), Tool | signal.*, price.plausi (nur Assist, per Tool) | Tool: Plattform und MCP; `SignalFixJob` ruft keine KI (rein deterministisch) | Signale (angeschlossen) |
| `SignaleDetektorCommand` (täglich 03:20) | Zeitplan | – (deterministisch; die KI-Stufe `price.plausi` „folgt in #375“, heute keine Token) | keine KI | Signale (beobachten) |
| `StubAusrezeptierenJob` | `RecipeExtractService` (Rezept-Import) | recipe.generator | angeschlossen (KiExternWiederholbar, `stub-ausrezeptieren:{team}:{stub}`) | Kaskade; Einstieg Rezept-Import 2d-1 |
| `BuildZukaufRecipeJob` | `RecipeDependencyWorkflowService` | recipe.ruestschritt, recipe.generator | angeschlossen (KiExternWiederholbar, `build-zukauf:{team}:{step}`) | Kaskade |
| `GenerateConceptJob`, `FanoutConceptJob`, `GenerateDishProposalJob`, `GenerateRecipePlanJob`, `ReviseDishProposalJob`, `MaterializeConceptIdeaJob`, `MaterializeSpeisekartePositionJob`, `MaterializeSpeiseplanCellJob` | `PlanningCascadeService` (Kaskade) | concept.*, planung.*, planning.*, foodbook.* | angeschlossen (alle KiExternWiederholbar, Wächter KiExternKaskadeTest) | Kaskade |
| `BulkEnrichJob` | UI-Bulk-Läufe Rezepte | recipe.* | Plattform | 2d |
| `RecipeFindingsRunJob`, `QualityRunJob` | `QualityRunService` (UI) | recipe.review/vk.review (zu prüfen) | prüfen | 2d |
| `RezeptFolgeJob`, `BausteinFolgeJob` | Folge-Services (afterCommit) | – (kein KI-Dienst gefunden) | prüfen | – |
| `ProduktpassLesenJob`, `ProduktpassRechercheJob` | Produktpass (UI) | produktpass.extract, Web | Plattform | ausgenommen |
| `AnkerWissenCommand`, `RecipePackagingUnitsCommand`, `GeneratorEvalCommand`, `GpSensorikReferenzCommand` | manuell (Artisan) | anker.wissen_auslese, recipe.verpackungsmasse; Eval-/Referenz-Werkzeuge | Plattform | 2d (Befehle `--anbieter=extern`) |
| Zeitplan sonst: `NectaAbgleichCommand`, `WechselkursHolenCommand`, `PlanNutzungZaehlenCommand`, `WissenSteuerdatenW0Command`, `WissenDeckelCheckCommand`, `WissenKanonSicherungCommand`, `AboHuelleNachfuehrenCommand`, `SpeiseplanAushangRollierenCommand` | Zeitplan (nachts/wöchentlich) | – | keine KI-Token (kein propose/Gateway im Befehl) | – |

### F5 · Nebenbefund `ki_verbrauch.GET` zeigt für das Master-Team 0
`KiAbrechnungService::fuerTeam` zählt nur Zeilen mit `verursacher = mandant` UND `abrechnung_team_id = Kunden-Hauptteam`. Aufrufe des Master-Teams (io Team 1, BHG.DIGITAL) bekommen in `KiVerursacher::aufloesen` den Verursacher `plattform` und `abrechnung_team_id = NULL` (`MasterTeam::istMaster($haupt)`). Die Mandanten-Sicht filtert sie deshalb heraus: **0 Aufrufe / 0 €** ist für das Master-Team die erwartete Ausgabe der Mandanten-Sicht, nicht eine leere Erfassung. Die Aufrufe stehen im Log und in `KiAbrechnungService::auswertung()` unter „Plattform“. Fix-Vorschlag (eigener kleiner Punkt): für das Master-Team in `fuerTeam` die `plattform`-Zeilen mit `team_id` im Teilbaum zählen (oder das Tool weist „Master-Team: Verbrauch unter Plattform“ aus). **Erledigt** (`fix/ki-verbrauch-master`): `fuerTeam` zählt für das Master-Team die `plattform`-Zeilen seines Teilbaums, ohne Deckel; das Tool weist `plattform: true` und einen Hinweis aus.

