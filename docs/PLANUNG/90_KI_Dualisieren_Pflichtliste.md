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
| 2 | `src/Jobs/ClassifyLaJob.php::handle` | gp.suggest | 1 | a | P2 | Job nach LA-Import | offen | Job-Wiederholung: Auftrag + Job erneut dispatchen |
| 3 | `src/Jobs/NonFoodKiBatchJob.php::handle` | la.nonfood | 1 | a | P2 | Job | offen | Batch, Paketbetrieb |
| 4 | `src/Jobs/ReviseDishProposalJob.php::handle` | planning.dish_proposal_revise | 1 | b | P2 | planung_kaskade.* | offen | Job schreibt nach Antwort; Idempotenz prüfen |
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
| 29 | `src/Services/AngebotService.php::kiKundentextVorschlag` | foodbook.kundentext | 1 | a | P2 | AngebotKundentextTool | offen |  |
| 30 | `src/Services/AngebotService.php::kiKapitelKundentextVorschlag` | foodbook.kundentext | 1 | a | P2 | AngebotKundentextTool | offen |  |
| 31 | `src/Services/BriefingLeitplankenService.php::ausBriefing` | planung.leitplanken | 1 | a | P2 | planung_leitplanken.EXTRACT, planung_kaskade.START, planung_vorschlag.POST | offen |  |
| 32 | `src/Services/BulkEnrichService.php::proposeFeld` | dyn. $key (Rezept-Schritte) | 1 | b | P2 | recipes.ENRICH | angeschlossen | Keys über config-Prompt-Liste abgesichert Extern über recipes.ENRICH/EnrichRecipeJob (Tabelle D, KiExternEnrichTest). |
| 33 | `src/Services/BulkEnrichService.php::proposeDichteklasse` | recipe.dichteklasse | 1 | a | P2 | recipes.ENRICH | angeschlossen | Extern über recipes.ENRICH/EnrichRecipeJob (Tabelle D, KiExternEnrichTest). |
| 34 | `src/Services/BulkEnrichService.php::proposeRegeneration` | recipe.regeneration | 1 | a | P2 | recipes.ENRICH | angeschlossen | Extern über recipes.ENRICH/EnrichRecipeJob (Tabelle D, KiExternEnrichTest). |
| 35 | `src/Services/BulkEnrichService.php::proposeGarverlust` | recipe.garverlust | 1 | a | P2 | recipes.ENRICH | offen |  |
| 36 | `src/Services/BulkEnrichService.php::proposeGpFeld` | dyn. $key (gp.condition, gp.tags, gp.anker, gp.sensorik, gp.allergene, gp.naehrwerte) | 1 | b | P1 | gps.ENRICH | offen | P1-Kern |
| 37 | `src/Services/CoherenceService.php::judge` | vk.kohaerenz | 1 | a | P2 | recipe_coherence.POST | angeschlossen | MCP-Umleitung (Tabelle D). |
| 38 | `src/Services/CoherenceService.php::tellerHeber` | vk.teller_heber | 1 | a | P2 | recipe_coherence.POST | angeschlossen | MCP-Umleitung (Tabelle D). |
| 39 | `src/Services/ConceptGeneratorService.php::generiereAusBrief` | concept.brief_geruest | 1 | c | P2 | concepts.GENERATE | offen | Kaskade mit Schreibzugriffen dazwischen |
| 40 | `src/Services/ConceptGeneratorService.php::fuelleCanvasAusPlan` | concept.plan | 1 | c | P2 | concepts.GENERATE | offen | Schleife über Slots, Schreiben je Slot |
| 41 | `src/Services/ConceptGeneratorService.php::geruestAusBriefFuerOwner` | dyn. $promptKey (foodbook.grundgeruest, format.grundgeruest …) | 1 | b | P2 | 4 *_plan_from_brief-Tools | offen |  |
| 42 | `src/Services/ConceptService.php::generateWording` | concept.wording | 1 | a | P2 | ConceptWordingGenerateTool | offen |  |
| 43 | `src/Services/Conformance/GpConformanceAdapter.php::revise` | gp.conformance_revise | 1 | b | P2 | Zuordnung prüfen | offen |  |
| 44 | `src/Services/Conformance/RecipeConformanceAdapter.php::revise` | dyn. $promptKey | 1 | b | P2 | Zuordnung prüfen | offen |  |
| 45 | `src/Services/ConformanceService.php::pruefeAdapter` | conformance.check | 1 | b | P2 | Zuordnung prüfen | offen |  |
| 46 | `src/Services/FoodbookService.php::kapitelWordingRegenerieren` | concept.wording | 1 | a | P2 | FoodbookKapitelWordingTool | offen |  |
| 47 | `src/Services/FoodbookService.php::kiKundentextVorschlag` | foodbook.kundentext | 1 | a | P2 | FoodbookKundentextTool | offen |  |
| 48 | `src/Services/FoodbookService.php::kiKapitelKundentextVorschlag` | foodbook.kundentext | 1 | a | P2 | FoodbookKapitelKundentextTool | offen |  |
| 49 | `src/Services/GpAbgleich/GpAbgleichService.php::entscheiderKi` | gp.la_abgleich | 1 | b | P2 | LaGpAbgleichTool | offen |  |
| 50 | `src/Services/GpAbgleich/LaFelderService.php::fuer` | gp.suggest | 1 | a | P2 | LaGpAbgleichTool | offen |  |
| 51 | `src/Services/GpAromaService.php::schaetze` | gp.sensorik | 1 | a | P1 | Backfill, gps.ENRICH | angeschlossen | Backfill ANGESCHLOSSEN (#611); Tool-Weg offen. 29.10.: 7 Achsen + Grenzen nach der Antwort (Nachverarbeitung beim Aufrufer, `SensorikService::begrenze`); Anzahl bleibt 1, Einstufung a, Backfill `--neu-schaetzen` |
| 52 | `src/Services/GpFormService.php::estimateKi` | gp.zaehl_einheiten | 1 | a | P2 | GpFormsEstimateTool | angeschlossen | MCP-Umleitung (Tabelle D). |
| 53 | `src/Services/IdeenService.php::kiDivergenzConcept` | foodbook.kapitel_ideen | 1 | b | P2 | KapitelIdeen*Tool | offen |  |
| 54 | `src/Services/IdeenService.php::kiDivergenzSession` | foodbook.kapitel_ideen | 1 | b | P2 | KapitelIdeen*Tool | offen |  |
| 55 | `src/Services/OfferCompositionService.php::kapitelWordingRegenerieren` | concept.wording | 1 | b | P2 | Zuordnung prüfen (offer_chapter/offer_block) | offen | Schleife über Kapitel |
| 56 | `src/Services/Pairing/AnkerWissenAuslese.php::lese` | anker.wissen_auslese | 1 | a | P1 | Artisan AnkerWissenCommand | offen | Anker-Pfad |
| 57 | `src/Services/PresentationDesignService.php::generateCss` | praesentation.design_css | 1 | a | P2 | PresentationDesignsGenerateCssTool | angeschlossen | MCP-Umleitung (Tabelle D). |
| 58 | `src/Services/Produktpass/ProduktpassLeser.php::liefere` | produktpass.extract | 1 | b | P2 | kein Tool (nur UI) | offen | ANHANG (PDF/Bild): Entscheidung Punkt 5 |
| 59 | `src/Services/RecipeBauartService.php::pruefe` | recipe.bauart | 1 | a | P2 | im Generator | offen |  |
| 60 | `src/Services/RecipeExtractService.php::extrahiere` | recipe.extract | 1 | b | P2 | RecipesExtractTool | offen | ANHANG (Bild/PDF): Entscheidung Punkt 5 |
| 61 | `src/Services/RecipeGeneratorService.php::generiereImLaufKern` | dyn. (vk.generator / recipe.generator) | 1 | c | P2 | RecipesGenerateTool, StubAusrezeptierenJob (Import-Hülle, 29.10.) | angeschlossen | Kaskade, mehrere propose + Schreiben Tool-Weg: recipes.GENERATE extern (Tabelle D, KiExternGenerateTest). Job-Weg (GenerateRecipeJob/Planung) folgt in 2c-2. |
| 62 | `src/Services/RecipeKomponentenPlanService.php::plane` | recipe.komponenten_plan | 1 | a | P2 | im Generator | offen |  |
| 63 | `src/Services/RecipeOneShotService.php::fertigungsGlied` | recipe.production_depth | 1 | c | P2 | RecipesGenerateTool (One-Shot-Kette) | angeschlossen | Glied einer Kette Extern über recipes.ENRICH/EnrichRecipeJob (Tabelle D, KiExternEnrichTest). |
| 64 | `src/Services/RecipeOneShotService.php::eigenschaftenGlied` | recipe.eigenschaften | 1 | c | P2 | RecipesGenerateTool (One-Shot-Kette) | angeschlossen | Glied einer Kette Extern über recipes.ENRICH/EnrichRecipeJob (Tabelle D, KiExternEnrichTest). |
| 65 | `src/Services/RecipeOneShotService.php::equipmentGlied` | recipe.equipment | 1 | c | P2 | RecipesGenerateTool (One-Shot-Kette) | angeschlossen | Glied einer Kette Extern über recipes.ENRICH/EnrichRecipeJob (Tabelle D, KiExternEnrichTest). |
| 66 | `src/Services/RecipeOneShotService.php::stationVorschlag` | recipe.posten | 1 | c | P2 | RecipesGenerateTool (One-Shot-Kette) | offen | Glied einer Kette |
| 67 | `src/Services/RecipeOneShotService.php::stepGlied` | recipe.steps | 1 | c | P2 | RecipesGenerateTool (One-Shot-Kette) | angeschlossen | Glied einer Kette Extern über recipes.ENRICH/EnrichRecipeJob (Tabelle D, KiExternEnrichTest). |
| 68 | `src/Services/RecipeOneShotService.php::eignungsGlied` | recipe.sektor, recipe.level | 2 | c | P2 | RecipesGenerateTool (One-Shot-Kette) | angeschlossen | 2 propose in einer Methode Extern über recipes.ENRICH/EnrichRecipeJob (Tabelle D, KiExternEnrichTest). |
| 69 | `src/Services/RecipeReviewService.php::pruefeImLauf` | dyn. $feature (recipe.review / vk.review) | 1 | b | P2 | RecipesReviewTool | offen |  |
| 70 | `src/Services/RecipeReviseService.php::freitextVorschlag` | recipe.ueberarbeiten | 1 | a | P2 | RecipesReviseTool | offen |  |
| 71 | `src/Services/RecipeReviseService.php::vkFreitextVorschlag` | vk.ueberarbeiten | 1 | a | P2 | VerkaufsrezepteReviseTool | offen |  |
| 72 | `src/Services/ReplacementSuggestionService.php::forGp` | component.replacement_suggest | 1 | a | P2 | kein Tool (nur UI) | offen |  |
| 73 | `src/Services/SensorikService.php::schaetzeRezept` | recipe.sensorik | 1 | a | P2 | RecipeSensorikPostTool | angeschlossen | MCP-Umleitung (Tabelle D). 29.10.: KI-Aufruf aus `bewerteRezept` hierher gezogen (bewerteRezept = schaetzeRezept + Speichern; der Abnahme-Befehl `gp-sensorik-referenz` liest nur). Nährwerte als Fakt im Kontext, Grenzen nach der Antwort (Nachverarbeitung); Einstufung a; Backfill `--neu-schaetzen=rezept` |
| 74 | `src/Services/SignalFixService.php::assist` | dyn. $plan (signal.*) | 1 | a | P2 | SignaleFixTool | offen |  |
| 75 | `src/Services/SpeisekarteService.php::kiWordingVorschlag` | foodbook.kundentext | 1 | a | P2 | SpeisekarteWordingTool | offen |  |
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
| `signal.supplier_inquiry` | `SignalFixService::assist` (Tabelle A) — der Key kommt aus `SignalCockpit::planFor()` (Signal-Typ → Prompt), Aufruf mit dynamischem `$plan['prompt']` | gedeckt durch Tabelle A (Assist-Zeile), wird mit dieser angeschlossen |
| `signal.margin_levers` | `SignalFixService::assist` (Tabelle A) — der Key kommt aus `SignalCockpit::planFor()` (Signal-Typ → Prompt), Aufruf mit dynamischem `$plan['prompt']` | gedeckt durch Tabelle A (Assist-Zeile), wird mit dieser angeschlossen |
| `signal.vk_release_advice` | `SignalFixService::assist` (Tabelle A) — der Key kommt aus `SignalCockpit::planFor()` (Signal-Typ → Prompt), Aufruf mit dynamischem `$plan['prompt']` | gedeckt durch Tabelle A (Assist-Zeile), wird mit dieser angeschlossen |
| `signal.serving_form_suggest` | `SignalFixService::assist` (Tabelle A) — der Key kommt aus `SignalCockpit::planFor()` (Signal-Typ → Prompt), Aufruf mit dynamischem `$plan['prompt']` | gedeckt durch Tabelle A (Assist-Zeile), wird mit dieser angeschlossen |
| `signal.recipe_category_suggest` | `SignalFixService::assist` (Tabelle A) — der Key kommt aus `SignalCockpit::planFor()` (Signal-Typ → Prompt), Aufruf mit dynamischem `$plan['prompt']` | gedeckt durch Tabelle A (Assist-Zeile), wird mit dieser angeschlossen |
| `signal.recipe_naming_suggest` | `SignalFixService::assist` (Tabelle A) — der Key kommt aus `SignalCockpit::planFor()` (Signal-Typ → Prompt), Aufruf mit dynamischem `$plan['prompt']` | gedeckt durch Tabelle A (Assist-Zeile), wird mit dieser angeschlossen |
| `price.plausi` | `SignalFixService::assist` (Tabelle A) — der Key kommt aus `SignalCockpit::planFor()` (Signal-Typ → Prompt), Aufruf mit dynamischem `$plan['prompt']` | gedeckt durch Tabelle A (Assist-Zeile), wird mit dieser angeschlossen |
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
| `foodalchemist.recipes.GENERATE` | ausgenommen (Klasse c) | Generator-Kaskade |
| `foodalchemist.concepts.GENERATE` | ausgenommen (Klasse c) | Kaskade mit Schreibzugriffen dazwischen |
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
| `GenerateRecipeJob` / `EnrichGeneratedRecipeJob` / `ConformanceCheckJob` / Sub-Rezept-Kaskade | offen (2c-2) | Job-Weg der UI und der Planungs-Kaskade: Status `error` im Cache bei „wartet“, Folge-Jobs ohne `afterCommit`, Extern-Flag muss in die Kinder (`RecipeDependencyWorkflowService`) |

