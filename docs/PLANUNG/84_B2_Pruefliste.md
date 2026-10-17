# Spec 84 · B2 · Prüfliste „unsichtbar ≠ nicht vorhanden“ + Eigentum je Schreibweg

Stand 2026-10-16 · Basis main `81bb128f` · erstellt von Session „Vanessa“ · Eingang für **B3** (Global Scope) und **B4** (Schreibschutz).

## 1 · Was B3 an diesen Stellen ändert

B3 legt `visibleToTeam(Kontext)` automatisch auf Eloquent-Abfragen. Abfragen, die heute *ungefiltert* fragen „gibt es das schon?“ (`firstOrCreate`, `updateOrCreate`, `firstOrNew`, `exists()`), sehen danach nur noch die Sicht des betrachtenden Teams. Wo die Antwort über Teamgrenzen stimmen muss, entstehen Dubletten, Unique-Abstürze oder falsche Löschungen.

**Zählung:** 312 Stellen in `src/` (Scanner im Anhang). 222 automatisch entschieden, 90 von Hand.

| Entscheidung | Stellen |
|---|---|

| bleibt: Abfrage trägt schon die Sicht | 64 |
| bleibt: nur eigenes Team (eigene Zeilen sind immer sichtbar) | 58 |
| nicht betroffen: Query Builder (B3 greift nur auf Eloquent) | 56 |
| nicht betroffen: Modell ohne Team-Trait | 44 |
| bleibt: Kind eines geprüften Eltern-Datensatzes (Eltern-Schlüssel in der Abfrage) | 42 |
| bleibt: nur eigenes Team bzw. eigene Kette | 13 |
| bleibt: 1:1-Nebentabelle erbt `team_id` vom Eltern-Artikel (gleiche Sicht wie der Eltern) | 10 |
| **Risiko Abo-Kind:** Kind einer Abo-Fassung trägt `team_id` = Master → nach B3 für den Mandanten unsichtbar, Prüfung schlägt fehl. Kind-Tabelle nicht scopen (B3-Vorschlag 1) | 6 |
| `ohneTeamSicht('eindeutigkeit')`: global eindeutiger Wert (öffentlicher Link / Zuordnung über Teams) | 4 |
| bleibt: Konsole bzw. Job mit Auftrags-Kontext (Teil A), Wurzel-Modell | 3 |
| `ohneTeamSicht('verwendung')`: Verwendungs-Prüfung muss Zeilen ANDERER Teams sehen, sonst löscht B3, was ein anderes Team noch nutzt | 3 |
| bleibt (sicher): Fremd-Erkennung sieht nach B3 die fremde Zeile nicht mehr → „nicht gefunden“ statt „Zugriff verweigert“; Test-Erwartung ggf. anpassen | 2 |
| **Risiko Abo-Kind + Eindeutigkeit:** `updateOrCreate` über `recipe_id` findet die Master-Zeile der Fassung nicht → Insert → Unique-Absturz. Kind-Tabelle nicht scopen | 2 |
| nicht betroffen: Query Builder | 2 |
| bleibt mit Sicht: heute über ALLE Teams (Team-Übersprechen als Namens-Hinweis); B3 korrigiert das | 1 |
| bleibt: Wurzel-Modell GP per id, sichtbar | 1 |
| `ohneTeamSicht('unterteam-verwaltung')`: Oberteam schreibt die Zeile seines Unter-Teams; die Sicht geht nur aufwärts → nach B3 Dublette/Unique-Absturz | 1 |

## 2 · Ergebnis für B3 (gemessen)

**Abo-Kind-Zeilen sind unsichtbar.** Probe auf `81bb128f`: Der Master veröffentlicht ein Gericht mit Standard-Darreichung, Mandant A abonniert. `FoodAlchemistRecipe::visibleToTeam(A)` findet die Fassung, `FoodAlchemistRecipeDarreichung::visibleToTeam(A)` findet ihre Darreichung **nicht**. Der Abo-Zweig (`AboSicht::TYPEN`) und `InhaltsFreigabe::MODELLE` kennen nur die Wurzel-Typen; Kind-Tabellen mit Trait (Darreichung, ConceptSlot, PaketGericht, Coherence, Deltas, Zubehör, …) fallen auf die Team-Kette zurück, in der der Master nicht steht.

**Empfehlung B3:**
1. `TeamSicht` als Global Scope **nur auf Wurzel-Modellen** (Abo-/Inhalts-Typen, Stamm GP/LA/Lieferant, Einstellungen), **nicht** auf Kind-Tabellen. Kinder werden über den geprüften Eltern-Datensatz erreicht; ihre `team_id` ist die des Eltern.
2. Drei Ausnahme-Gründe für `ohneTeamSicht()`: `eindeutigkeit`, `verwendung`, `unterteam-verwaltung` (Fundstellen unten).
3. Kind-Tabellen-Abfragen mit Eltern-Schlüssel bleiben im Wächter (B0a) erlaubt.

## 3 · Von Hand entschiedene Stellen (90)

| Stelle | Aufruf | Modell | Entscheidung |
|---|---|---|---|
| `src/Tools/ArtikelPreisePutTool.php:56` | exists | FoodAlchemistPrice | bleibt (sicher): Fremd-Erkennung sieht nach B3 die fremde Zeile nicht mehr → „nicht gefunden“ statt „Zugriff verweigert“; Test-Erwartung ggf. anpassen |
| `src/Tools/ArtikelPreiseDeleteTool.php:51` | exists | FoodAlchemistPrice | bleibt (sicher): Fremd-Erkennung sieht nach B3 die fremde Zeile nicht mehr → „nicht gefunden“ statt „Zugriff verweigert“; Test-Erwartung ggf. anpassen |
| `src/Livewire/Settings/Einkauf.php:329` | exists | – | bleibt: Kind eines geprüften Eltern-Datensatzes (Eltern-Schlüssel in der Abfrage) |
| `src/Livewire/Produktion/Editor.php:376` | exists | FoodAlchemistRecipeDarreichung | **Risiko Abo-Kind:** Kind einer Abo-Fassung trägt `team_id` = Master → nach B3 für den Mandanten unsichtbar, Prüfung schlägt fehl. Kind-Tabelle nicht scopen (B3-Vorschlag 1) |
| `src/Livewire/Recipes/StepEditor.php:284` | exists | – | bleibt: Kind eines geprüften Eltern-Datensatzes (Eltern-Schlüssel in der Abfrage) |
| `src/Livewire/Concepter/Editor.php:1403` | exists | FoodAlchemistRecipeDarreichung | **Risiko Abo-Kind:** Kind einer Abo-Fassung trägt `team_id` = Master → nach B3 für den Mandanten unsichtbar, Prüfung schlägt fehl. Kind-Tabelle nicht scopen (B3-Vorschlag 1) |
| `src/Jobs/ClassifyLaJob.php:101` | updateOrCreate | FoodAlchemistSupplierItemStructure | bleibt: 1:1-Nebentabelle erbt `team_id` vom Eltern-Artikel (gleiche Sicht wie der Eltern) |
| `src/Jobs/ConformanceCheckJob.php:115` | exists | FoodAlchemistRecipe | bleibt: Konsole bzw. Job mit Auftrags-Kontext (Teil A), Wurzel-Modell |
| `src/Observers/GpLagerObserver.php:27` | exists | FoodAlchemistStorageBin | bleibt: Kind eines geprüften Eltern-Datensatzes (Eltern-Schlüssel in der Abfrage) |
| `src/Services/SalesRecipeService.php:491` | exists | – | bleibt: Kind eines geprüften Eltern-Datensatzes (Eltern-Schlüssel in der Abfrage) |
| `src/Services/PlanningFrameService.php:205` | exists | – | bleibt: Kind eines geprüften Eltern-Datensatzes (Eltern-Schlüssel in der Abfrage) |
| `src/Services/NonFoodService.php:98` | updateOrCreate | FoodAlchemistSupplierItemNonfood | bleibt: 1:1-Nebentabelle erbt `team_id` vom Eltern-Artikel (gleiche Sicht wie der Eltern) |
| `src/Services/NonFoodService.php:138` | updateOrCreate | FoodAlchemistSupplierItemNonfood | bleibt: 1:1-Nebentabelle erbt `team_id` vom Eltern-Artikel (gleiche Sicht wie der Eltern) |
| `src/Services/NonFoodService.php:152` | exists | FoodAlchemistSupplierItemNonfood | bleibt: 1:1-Nebentabelle erbt `team_id` vom Eltern-Artikel (gleiche Sicht wie der Eltern) |
| `src/Services/SpeisekarteService.php:296` | exists | FoodAlchemistSpeisekarteRubrik | bleibt: Kind eines geprüften Eltern-Datensatzes (Eltern-Schlüssel in der Abfrage) |
| `src/Services/SpeisekarteService.php:343` | exists | FoodAlchemistSpeisekarteRubrik | bleibt: Kind eines geprüften Eltern-Datensatzes (Eltern-Schlüssel in der Abfrage) |
| `src/Services/RecipeStepService.php:312` | exists | FoodAlchemistRecipeStep | bleibt: Kind eines geprüften Eltern-Datensatzes (Eltern-Schlüssel in der Abfrage) |
| `src/Services/RecipeStepService.php:359` | exists | FoodAlchemistRecipeStep | bleibt: Kind eines geprüften Eltern-Datensatzes (Eltern-Schlüssel in der Abfrage) |
| `src/Services/TrendService.php:854` | exists | FoodAlchemistTrendBeleg | `ohneTeamSicht('verwendung')`: Verwendungs-Prüfung muss Zeilen ANDERER Teams sehen, sonst löscht B3, was ein anderes Team noch nutzt |
| `src/Services/WareneingangService.php:322` | exists | FoodAlchemistDeliveryNoteLine | bleibt: Kind eines geprüften Eltern-Datensatzes (Eltern-Schlüssel in der Abfrage) |
| `src/Services/WareneingangService.php:422` | exists | FoodAlchemistDeliveryNoteLine | bleibt: Kind eines geprüften Eltern-Datensatzes (Eltern-Schlüssel in der Abfrage) |
| `src/Services/PaketService.php:278` | exists | FoodAlchemistRecipeDarreichung | **Risiko Abo-Kind:** Kind einer Abo-Fassung trägt `team_id` = Master → nach B3 für den Mandanten unsichtbar, Prüfung schlägt fehl. Kind-Tabelle nicht scopen (B3-Vorschlag 1) |
| `src/Services/LieferantenRechnungService.php:503` | exists | FoodAlchemistSupplierInvoiceLine | bleibt: Kind eines geprüften Eltern-Datensatzes (Eltern-Schlüssel in der Abfrage) |
| `src/Services/ProjektUebernahmeService.php:122` | exists | FoodAlchemistProjekt | `ohneTeamSicht('eindeutigkeit')`: global eindeutiger Wert (öffentlicher Link / Zuordnung über Teams) |
| `src/Services/TeamVkService.php:50` | exists | – | bleibt: Kind eines geprüften Eltern-Datensatzes (Eltern-Schlüssel in der Abfrage) |
| `src/Services/ConceptVariantService.php:142` | exists | FoodAlchemistConceptSlot | `ohneTeamSicht('verwendung')`: Verwendungs-Prüfung muss Zeilen ANDERER Teams sehen, sonst löscht B3, was ein anderes Team noch nutzt |
| `src/Services/GpNamingService.php:326` | exists | – | bleibt: nur eigenes Team bzw. eigene Kette |
| `src/Services/GpNamingService.php:432` | exists | FoodAlchemistGp | bleibt mit Sicht: heute über ALLE Teams (Team-Übersprechen als Namens-Hinweis); B3 korrigiert das |
| `src/Services/InventoryService.php:108` | exists | – | bleibt: nur eigenes Team bzw. eigene Kette |
| `src/Services/PresentationService.php:154` | firstOrNew | FoodAlchemistPresentation | bleibt: nur eigenes Team bzw. eigene Kette |
| `src/Services/PresentationService.php:274` | exists | FoodAlchemistPresentation | `ohneTeamSicht('eindeutigkeit')`: global eindeutiger Wert (öffentlicher Link / Zuordnung über Teams) |
| `src/Services/PresentationService.php:292` | exists | FoodAlchemistPresentation | `ohneTeamSicht('eindeutigkeit')`: global eindeutiger Wert (öffentlicher Link / Zuordnung über Teams) |
| `src/Services/PresentationService.php:1299` | exists | – | `ohneTeamSicht('eindeutigkeit')`: global eindeutiger Wert (öffentlicher Link / Zuordnung über Teams) |
| `src/Services/OfferCompositionService.php:61` | exists | FoodAlchemistOfferChapter | bleibt: Kind eines geprüften Eltern-Datensatzes (Eltern-Schlüssel in der Abfrage) |
| `src/Services/OfferCompositionService.php:91` | exists | FoodAlchemistOfferChapter | bleibt: Kind eines geprüften Eltern-Datensatzes (Eltern-Schlüssel in der Abfrage) |
| `src/Services/LeitstelleService.php:528` | exists | – | bleibt: Kind eines geprüften Eltern-Datensatzes (Eltern-Schlüssel in der Abfrage) |
| `src/Services/SpeiseplanService.php:327` | exists | FoodAlchemistSpeiseplan | bleibt: nur eigenes Team bzw. eigene Kette |
| `src/Services/SpeiseplanService.php:956` | exists | FoodAlchemistRecipeDarreichung | **Risiko Abo-Kind:** Kind einer Abo-Fassung trägt `team_id` = Master → nach B3 für den Mandanten unsichtbar, Prüfung schlägt fehl. Kind-Tabelle nicht scopen (B3-Vorschlag 1) |
| `src/Services/SpeiseplanService.php:986` | exists | – | bleibt: Kind eines geprüften Eltern-Datensatzes (Eltern-Schlüssel in der Abfrage) |
| `src/Services/TeamSettingsService.php:179` | firstOrNew | FoodAlchemistOutletSetting | bleibt: nur eigenes Team bzw. eigene Kette |
| `src/Services/ConformanceService.php:63` | exists | FoodAlchemistGp | bleibt: Wurzel-Modell GP per id, sichtbar |
| `src/Services/DataQualityService.php:1909` | exists | – | bleibt: nur eigenes Team bzw. eigene Kette |
| `src/Services/PurchaseJournalService.php:59` | updateOrCreate | FoodAlchemistPurchaseTransaction | bleibt: nur eigenes Team bzw. eigene Kette |
| `src/Services/PurchaseJournalService.php:87` | exists | – | bleibt: nur eigenes Team bzw. eigene Kette |
| `src/Services/RecipeService.php:667` | exists | – | bleibt: Kind eines geprüften Eltern-Datensatzes (Eltern-Schlüssel in der Abfrage) |
| `src/Services/RecipeService.php:670` | exists | – | bleibt: Kind eines geprüften Eltern-Datensatzes (Eltern-Schlüssel in der Abfrage) |
| `src/Services/RecipeService.php:697` | exists | – | bleibt: Kind eines geprüften Eltern-Datensatzes (Eltern-Schlüssel in der Abfrage) |
| `src/Services/RecipeService.php:700` | exists | – | `ohneTeamSicht('verwendung')`: Verwendungs-Prüfung muss Zeilen ANDERER Teams sehen, sonst löscht B3, was ein anderes Team noch nutzt |
| `src/Services/RecipeRollenService.php:30` | exists | FoodAlchemistRecipeRolle | bleibt: Kind eines geprüften Eltern-Datensatzes (Eltern-Schlüssel in der Abfrage) |
| `src/Services/ProjektService.php:131` | exists | FoodAlchemistProjektStandort | bleibt: Kind eines geprüften Eltern-Datensatzes (Eltern-Schlüssel in der Abfrage) |
| `src/Services/ProjektService.php:134` | exists | FoodAlchemistProjektStandort | bleibt: Kind eines geprüften Eltern-Datensatzes (Eltern-Schlüssel in der Abfrage) |
| `src/Services/ProjektService.php:152` | exists | FoodAlchemistProjektStandort | bleibt: Kind eines geprüften Eltern-Datensatzes (Eltern-Schlüssel in der Abfrage) |
| `src/Services/OrderTemplateService.php:113` | firstOrNew | FoodAlchemistOrderTemplateLine | bleibt: Kind eines geprüften Eltern-Datensatzes (Eltern-Schlüssel in der Abfrage) |
| `src/Services/FaRechte.php:192` | exists | FoodAlchemistTeamBereich | bleibt: nur eigenes Team bzw. eigene Kette |
| `src/Services/FaRechte.php:213` | exists | FoodAlchemistUserBereichSperre | bleibt: nur eigenes Team bzw. eigene Kette |
| `src/Services/FaRechte.php:296` | updateOrCreate | FoodAlchemistTeamKontingent | bleibt: nur eigenes Team bzw. eigene Kette |
| `src/Services/FaRechte.php:369` | exists | FoodAlchemistTeamMemberFlag | bleibt: nur eigenes Team bzw. eigene Kette |
| `src/Services/FormatService.php:435` | exists | FoodAlchemistFormatImage | bleibt: Kind eines geprüften Eltern-Datensatzes (Eltern-Schlüssel in der Abfrage) |
| `src/Services/StandortService.php:83` | updateOrCreate | FoodAlchemistTeamBetrieb | `ohneTeamSicht('unterteam-verwaltung')`: Oberteam schreibt die Zeile seines Unter-Teams; die Sicht geht nur aufwärts → nach B3 Dublette/Unique-Absturz |
| `src/Services/SupplierItemService.php:142` | updateOrCreate | FoodAlchemistItemAllergen | bleibt: 1:1-Nebentabelle erbt `team_id` vom Eltern-Artikel (gleiche Sicht wie der Eltern) |
| `src/Services/SupplierItemService.php:197` | updateOrCreate | FoodAlchemistItemNutritional | bleibt: 1:1-Nebentabelle erbt `team_id` vom Eltern-Artikel (gleiche Sicht wie der Eltern) |
| `src/Services/SupplierItemService.php:240` | updateOrCreate | FoodAlchemistItemDeclaration | bleibt: 1:1-Nebentabelle erbt `team_id` vom Eltern-Artikel (gleiche Sicht wie der Eltern) |
| `src/Services/AngebotEinfrierService.php:359` | exists | – | bleibt: Kind eines geprüften Eltern-Datensatzes (Eltern-Schlüssel in der Abfrage) |
| `src/Services/AngebotService.php:190` | exists | FoodAlchemistOfferBlock | bleibt: Kind eines geprüften Eltern-Datensatzes (Eltern-Schlüssel in der Abfrage) |
| `src/Services/OutletSettingsService.php:19` | firstOrNew | FoodAlchemistOutletSetting | bleibt: nur eigenes Team bzw. eigene Kette |
| `src/Services/DarreichungService.php:48` | exists | – | bleibt: Kind eines geprüften Eltern-Datensatzes (Eltern-Schlüssel in der Abfrage) |
| `src/Services/DarreichungService.php:104` | exists | – | bleibt: Kind eines geprüften Eltern-Datensatzes (Eltern-Schlüssel in der Abfrage) |
| `src/Services/DarreichungService.php:262` | exists | – | bleibt: Kind eines geprüften Eltern-Datensatzes (Eltern-Schlüssel in der Abfrage) |
| `src/Services/DarreichungService.php:310` | exists | – | bleibt: Kind eines geprüften Eltern-Datensatzes (Eltern-Schlüssel in der Abfrage) |
| `src/Services/DarreichungService.php:319` | updateOrCreate | FoodAlchemistRecipeDarreichungDelta | bleibt: Kind eines geprüften Eltern-Datensatzes (Eltern-Schlüssel in der Abfrage) |
| `src/Services/DarreichungService.php:351` | updateOrCreate | FoodAlchemistRecipeDarreichungZubehoer | bleibt: Kind eines geprüften Eltern-Datensatzes (Eltern-Schlüssel in der Abfrage) |
| `src/Services/DarreichungService.php:550` | exists | – | bleibt: Kind eines geprüften Eltern-Datensatzes (Eltern-Schlüssel in der Abfrage) |
| `src/Services/DarreichungService.php:699` | exists | – | bleibt: Kind eines geprüften Eltern-Datensatzes (Eltern-Schlüssel in der Abfrage) |
| `src/Services/DarreichungService.php:766` | exists | – | bleibt: Kind eines geprüften Eltern-Datensatzes (Eltern-Schlüssel in der Abfrage) |
| `src/Services/ConceptService.php:512` | exists | FoodAlchemistRecipeDarreichung | **Risiko Abo-Kind:** Kind einer Abo-Fassung trägt `team_id` = Master → nach B3 für den Mandanten unsichtbar, Prüfung schlägt fehl. Kind-Tabelle nicht scopen (B3-Vorschlag 1) |
| `src/Services/CoherenceService.php:64` | updateOrCreate | FoodAlchemistRecipeCulinaryCoherence | **Risiko Abo-Kind + Eindeutigkeit:** `updateOrCreate` über `recipe_id` findet die Master-Zeile der Fassung nicht → Insert → Unique-Absturz. Kind-Tabelle nicht scopen |
| `src/Services/CoherenceService.php:102` | updateOrCreate | FoodAlchemistRecipeCulinaryCoherence | **Risiko Abo-Kind + Eindeutigkeit:** `updateOrCreate` über `recipe_id` findet die Master-Zeile der Fassung nicht → Insert → Unique-Absturz. Kind-Tabelle nicht scopen |
| `src/Services/FoodbookService.php:561` | exists | FoodAlchemistFoodbookKapitel | bleibt: Kind eines geprüften Eltern-Datensatzes (Eltern-Schlüssel in der Abfrage) |
| `src/Services/FoodbookService.php:788` | exists | – | bleibt: Kind eines geprüften Eltern-Datensatzes (Eltern-Schlüssel in der Abfrage) |
| `src/Services/FoodbookService.php:797` | exists | FoodAlchemistConceptSlot | **Risiko Abo-Kind:** Kind einer Abo-Fassung trägt `team_id` = Master → nach B3 für den Mandanten unsichtbar, Prüfung schlägt fehl. Kind-Tabelle nicht scopen (B3-Vorschlag 1) |
| `src/Services/FoodbookService.php:893` | exists | – | bleibt: Kind eines geprüften Eltern-Datensatzes (Eltern-Schlüssel in der Abfrage) |
| `src/Services/FoodbookService.php:2339` | exists | FoodAlchemistFoodbookKapitel | bleibt: Kind eines geprüften Eltern-Datensatzes (Eltern-Schlüssel in der Abfrage) |
| `src/Services/Necta/NectaAbgleichService.php:141` | exists | – | bleibt: Konsole bzw. Job mit Auftrags-Kontext (Teil A), Wurzel-Modell |
| `src/Services/Pairing/AnkerWissenFreigabe.php:92` | exists | – | nicht betroffen: Query Builder |
| `src/Services/Knowledge/KnowledgeCanonService.php:241` | exists | – | nicht betroffen: Query Builder |
| `src/Services/Produktpass/ProduktpassService.php:280` | firstOrNew | FoodAlchemistItemAllergen | bleibt: 1:1-Nebentabelle erbt `team_id` vom Eltern-Artikel (gleiche Sicht wie der Eltern) |
| `src/Services/Produktpass/ProduktpassService.php:610` | firstOrNew | FoodAlchemistItemNutritional | bleibt: 1:1-Nebentabelle erbt `team_id` vom Eltern-Artikel (gleiche Sicht wie der Eltern) |
| `src/Services/Produktpass/ProduktpassService.php:619` | firstOrNew | FoodAlchemistItemDeclaration | bleibt: 1:1-Nebentabelle erbt `team_id` vom Eltern-Artikel (gleiche Sicht wie der Eltern) |
| `src/Console/RecomputeCommand.php:44` | exists | FoodAlchemistRecipe | bleibt: Konsole bzw. Job mit Auftrags-Kontext (Teil A), Wurzel-Modell |
| `src/Console/WareneingangAltbestandCommand.php:58` | exists | FoodAlchemistDeliveryNoteLine | bleibt: Kind eines geprüften Eltern-Datensatzes (Eltern-Schlüssel in der Abfrage) |

## 4 · Eigentum je Schreibweg (Service-Scan)

Öffentliche Service-Methoden, die mit `visibleToTeam` laden, schreiben und im Rumpf keinen erkannten Riegel haben (`isOwnedBy`, `canCurate`, `guard*`, `owned*`, `eigene*`, `assertOwner`, `team_id`-Vergleich, `FaRechte`). 51 Treffer auf `81bb128f`; davon in PR 0 erledigt: `SalesRecipeService` (Regeneration/Behälter/Kunden-Namen), `DarreichungService`, `ComponentEquivalentService`, `RecipeService` Bild.

| Methode | Urteil | Begründung / Fix |
|---|---|---|
| `BulkEnrichService::uebernehmen` | **Leck** | Vorschlag ohne Team-Prüfung geladen, Rezept nur sichtbar → KI-Wert landet auf Master-/Abo-Rezept. Zwilling `uebernehmenGp` prüft `isOwnedBy`. Fix: Vorschlag des eigenen Teams + `isOwnedBy` |
| `BulkEnrichService::verwerfen`, `verwerfenGp` | **Leck (klein)** | Vorschlag eines fremden Teams verwerfbar. Fix: `team_id` des Vorschlags = Team |
| `BulkEnrichService::verarbeiteRezept`, `verarbeiteGp` | ok | legt nur eigene Vorschläge an; Riegel sitzt in `uebernehmen*` |
| `PairingService::setGpAnker`, `removeGpAnker`, `clearGpAnker`, `setGpAnkerInference` (+ `SignalFixService::fixGpAnker`) | **Leck über Mandantengrenzen** | Mapping-Tabelle hat keinen Team-Schlüssel; jedes Team, das ein GP sieht, änderte dessen Anker für alle. Fix: `darfAnkerPflegen` = eigenes GP (inkl. Master an globaler Stamm-Zeile) ODER GP der eigenen Team-Kette. Die Kette bleibt bewusst offen (frühere Entscheidung „Anker-Pflege ist ein geteiltes kuratorisches Anliegen“, `McpGpAnchorsTest`). Gesperrt: globale GPs ohne Master-Recht, GPs anderer Mandanten. **Frage an Dominique:** Soll auch das Unter-Team die Oberteam-Anker nicht mehr ändern dürfen? |
| `PlanningCascadeService::enrichBestehendesRezept` + `RecipeOneShotService::anreichern` (über `EnrichRecipeJob`) | **Leck** | Anreicherung schreibt KI-Felder auf jedes sichtbare Rezept. Fix: `isOwnedBy` vor Lauf-Anlage und zentral in `anreichern` |
| `SpeisenKlassenService::acceptRollen` | **Leck** | schreibt Zutaten-Rollen eines nur sichtbaren Rezepts. Fix: `isOwnedBy` |
| `RecipeService::deleteGeneratorStub` | **Leck (schmal)** | löscht einen sichtbaren Generator-Stub fremder Teams. Fix: `isOwnedBy` |
| `RecipeService::createSubRecipeStub` | **Leck (schmal)** | setzt den Status eines sichtbaren fremden Eltern-Stubs auf `draft`. Fix: nur eigener Eltern |
| `FeedbackService::weiterentwickeln` | **Leck** | Feedback nur sichtbar (vertikal auch Kind-Teams) → schreibt `spawned_recipe_id` in fremdes Feedback; die Idempotenz gibt ein fremdes Rezept ungefiltert zurück. Fix: eigenes Feedback |
| `ConceptGeneratorService::uebernehmeAssemblierung` | **Leck (Reihenfolge)** | kopiert das Gerüst an ein fremdes Konzept, bevor `addSlot` den Eigentümer prüft. Fix: `isOwnedBy` direkt nach dem Laden |
| `ConceptService::duplicate`, `forkVonVorlage`, `alsVorlageSpeichern`, `PaketService::duplicate`, `RecipeService::duplicate`, `ConceptVariantService::varianteFuerSlot` | ok | Kopie ins eigene Team („anpassen = eigene Kopie“) |
| `RecipeService::create`, `SalesRecipeService::createFromBasis`, `VocabularyService::createRecipeCategory`, `SupplierItemService::create`, `SupplierService::addContact`, `StammLieferantService::setStamm`, `OrderService::addManualLine`, `generateDraftsFromSources`, `FeedbackService::erstelle` | ok | schreibt eigene Zeile, die eine sichtbare Zeile **referenziert** (Master-Kategorie, Stamm-Lieferant …) — gewollt |
| `SupplierService::create`, `VocabularyService::createEinheit` | ok | `exists()` über die Kette als Dubletten-Schutz; eigene Zeile |
| `PreislistenImportService::stage`, `MatchService::bulkFuerLieferant` | ok | nur Staging-/Vorschlags-Zeilen des eigenen Teams |
| `ProportionService::rescaleRecipe`, `setIngredientBakerPercent` | ok | `assertOwner` (Scanner-Lücke) |
| `TeamProfilService::setzeEquipment`, `TrendService::aendern` | ok | eigenes Profil mit Rechte-Prüfung bzw. `eigener()` |

## Anhang · alle 312 Stellen

| Stelle | Aufruf | Modell | Entscheidung |
|---|---|---|---|
| `src/Tools/OutletPostTool.php:49` | exists | FoodAlchemistOutlet | bleibt: nur eigenes Team (eigene Zeilen sind immer sichtbar) |
| `src/Tools/GpsPostTool.php:82` | exists | FoodAlchemistGp | bleibt: Abfrage trägt schon die Sicht |
| `src/Tools/PlatzhalterPutTool.php:50` | exists | FoodAlchemistGp | bleibt: nur eigenes Team (eigene Zeilen sind immer sichtbar) |
| `src/Tools/FoodbookBlocksPutTool.php:61` | exists | FoodAlchemistRecipe | bleibt: Abfrage trägt schon die Sicht |
| `src/Tools/RecipeFeedbackDeleteTool.php:44` | exists | FoodAlchemistRecipeFeedback | bleibt: nur eigenes Team (eigene Zeilen sind immer sichtbar) |
| `src/Tools/ComponentEquivalentsPostTool.php:64` | exists | FoodAlchemistGp | bleibt: Abfrage trägt schon die Sicht |
| `src/Tools/ComponentEquivalentsPostTool.php:65` | exists | FoodAlchemistGp | bleibt: Abfrage trägt schon die Sicht |
| `src/Tools/KapitelIdeenGetTool.php:63` | exists | FoodAlchemistFoodbookKapitel | bleibt: Abfrage trägt schon die Sicht |
| `src/Tools/KapitelIdeenGetTool.php:67` | exists | FoodAlchemistConcept | bleibt: Abfrage trägt schon die Sicht |
| `src/Tools/AngebotConceptRefPostTool.php:49` | exists | FoodAlchemistConcept | bleibt: Abfrage trägt schon die Sicht |
| `src/Tools/ConceptsTemplateForkTool.php:48` | exists | FoodAlchemistConcept | bleibt: Abfrage trägt schon die Sicht |
| `src/Tools/PlatzhalterDeleteTool.php:44` | exists | FoodAlchemistGp | bleibt: nur eigenes Team (eigene Zeilen sind immer sichtbar) |
| `src/Tools/PaketeDuplicateTool.php:37` | exists | FoodAlchemistPaket | bleibt: Abfrage trägt schon die Sicht |
| `src/Tools/FoodbookBlocksPostTool.php:107` | exists | FoodAlchemistConcept | bleibt: Abfrage trägt schon die Sicht |
| `src/Tools/FoodbookBlocksPostTool.php:115` | exists | FoodAlchemistRecipe | bleibt: Abfrage trägt schon die Sicht |
| `src/Tools/ConceptsDuplicateTool.php:41` | exists | FoodAlchemistConcept | bleibt: Abfrage trägt schon die Sicht |
| `src/Tools/ConceptSlotsPutTool.php:63` | exists | FoodAlchemistRecipe | bleibt: Abfrage trägt schon die Sicht |
| `src/Tools/ConceptSlotsPutTool.php:67` | exists | FoodAlchemistPaket | bleibt: Abfrage trägt schon die Sicht |
| `src/Tools/RecipeFeedbackDevelopTool.php:46` | exists | FoodAlchemistRecipeFeedback | bleibt: nur eigenes Team (eigene Zeilen sind immer sichtbar) |
| `src/Tools/FoodbooksPostTool.php:86` | exists | FoodAlchemistEventtyp | bleibt: Abfrage trägt schon die Sicht |
| `src/Tools/FoodbooksPostTool.php:90` | exists | FoodAlchemistServierform | bleibt: Abfrage trägt schon die Sicht |
| `src/Tools/UiOpenTool.php:78` | exists | – | nicht betroffen: Modell ohne Team-Trait (ToolResult) |
| `src/Tools/GpsPutTool.php:90` | exists | FoodAlchemistGp | bleibt: Abfrage trägt schon die Sicht |
| `src/Tools/FoodbookKapitelPutTool.php:111` | exists | FoodAlchemistServierform | bleibt: Abfrage trägt schon die Sicht |
| `src/Tools/FoodbookKapitelPutTool.php:115` | exists | FoodAlchemistEinsatzmoment | bleibt: Abfrage trägt schon die Sicht |
| `src/Tools/VerkaufsrezeptePostTool.php:57` | exists | FoodAlchemistRecipe | bleibt: Abfrage trägt schon die Sicht |
| `src/Tools/FoodAlchemistTool.php:300` | exists | – | nicht betroffen: Query Builder (B3 greift nur auf Eloquent) |
| `src/Tools/PlanungLeitplankenExtractTool.php:95` | exists | FoodAlchemistPlanningSession | bleibt: nur eigenes Team (eigene Zeilen sind immer sichtbar) |
| `src/Tools/UiNavigateTool.php:82` | exists | – | nicht betroffen: Modell ohne Team-Trait (model) |
| `src/Tools/TeamSettingsPutTool.php:18` | firstOrNew | – | nicht betroffen: Modell ohne Team-Trait (TeamSettingsService) |
| `src/Tools/TeamSettingsPutTool.php:184` | exists | FoodAlchemistProductionStation | bleibt: Abfrage trägt schon die Sicht |
| `src/Tools/TeamSettingsPutTool.php:193` | exists | FoodAlchemistMarkupClass | bleibt: Abfrage trägt schon die Sicht |
| `src/Tools/CanvasGetTool.php:76` | exists | – | nicht betroffen: Modell ohne Team-Trait (ownerModel) |
| `src/Tools/ComponentEquivalentsDeleteTool.php:44` | exists | – | nicht betroffen: Modell ohne Team-Trait (Equiv) |
| `src/Tools/ConceptSlotsPostTool.php:70` | exists | FoodAlchemistRecipe | bleibt: Abfrage trägt schon die Sicht |
| `src/Tools/ConceptSlotsPostTool.php:74` | exists | FoodAlchemistPaket | bleibt: Abfrage trägt schon die Sicht |
| `src/Tools/RecipesInstantiateTool.php:72` | exists | FoodAlchemistRecipe | bleibt: Abfrage trägt schon die Sicht |
| `src/Tools/SpeiseplanEintraegePostTool.php:71` | exists | – | nicht betroffen: Modell ohne Team-Trait (model) |
| `src/Tools/ArtikelPreisePutTool.php:56` | exists | FoodAlchemistPrice | bleibt (sicher): Fremd-Erkennung sieht nach B3 die fremde Zeile nicht mehr → „nicht gefunden“ statt „Zugriff verweigert“; Test-Erwartung ggf. anpassen |
| `src/Tools/ArtikelPreiseDeleteTool.php:51` | exists | FoodAlchemistPrice | bleibt (sicher): Fremd-Erkennung sieht nach B3 die fremde Zeile nicht mehr → „nicht gefunden“ statt „Zugriff verweigert“; Test-Erwartung ggf. anpassen |
| `src/Models/Concerns/HasUuidV7.php:21` | exists | – | nicht betroffen: Modell ohne Team-Trait (static) |
| `src/Policies/FoodAlchemistPolicy.php:24` | exists | – | nicht betroffen: Modell ohne Team-Trait (model) |
| `src/Livewire/Settings/ConcepterDimensionen.php:66` | exists | – | nicht betroffen: Query Builder (B3 greift nur auf Eloquent) |
| `src/Livewire/Settings/Einkauf.php:268` | exists | FoodAlchemistInventoryLocation | bleibt: nur eigenes Team (eigene Zeilen sind immer sichtbar) |
| `src/Livewire/Settings/Einkauf.php:329` | exists | – | bleibt: Kind eines geprüften Eltern-Datensatzes (Eltern-Schlüssel in der Abfrage) |
| `src/Livewire/Settings/DnaFelder.php:74` | exists | FoodAlchemistCanvasTemplate | bleibt: nur eigenes Team (eigene Zeilen sind immer sichtbar) |
| `src/Livewire/Settings/Schreibstile.php:84` | exists | – | nicht betroffen: Query Builder (B3 greift nur auf Eloquent) |
| `src/Livewire/Settings/Posten.php:79` | exists | FoodAlchemistProductionStation | bleibt: Abfrage trägt schon die Sicht |
| `src/Livewire/Settings/Posten.php:122` | exists | FoodAlchemistProductionStation | bleibt: nur eigenes Team (eigene Zeilen sind immer sichtbar) |
| `src/Livewire/Settings/Wissenssteuerung.php:312` | exists | – | nicht betroffen: Query Builder (B3 greift nur auf Eloquent) |
| `src/Livewire/Settings/Behaelter.php:85` | exists | – | nicht betroffen: Query Builder (B3 greift nur auf Eloquent) |
| `src/Livewire/Settings/Rollen.php:71` | exists | FoodAlchemistKitchenRole | bleibt: nur eigenes Team (eigene Zeilen sind immer sichtbar) |
| `src/Livewire/Settings/Wissenskategorien.php:112` | exists | – | nicht betroffen: Query Builder (B3 greift nur auf Eloquent) |
| `src/Livewire/Settings/Betriebe.php:71` | exists | FoodAlchemistOutlet | bleibt: nur eigenes Team (eigene Zeilen sind immer sichtbar) |
| `src/Livewire/Settings/Betriebe.php:167` | exists | FoodAlchemistOutlet | bleibt: nur eigenes Team (eigene Zeilen sind immer sichtbar) |
| `src/Livewire/Settings/Aufschlagsklassen.php:122` | exists | FoodAlchemistMarkupClass | bleibt: nur eigenes Team (eigene Zeilen sind immer sichtbar) |
| `src/Livewire/Produktion/Editor.php:358` | exists | FoodAlchemistConcept | bleibt: Abfrage trägt schon die Sicht |
| `src/Livewire/Produktion/Editor.php:359` | exists | FoodAlchemistConcept | bleibt: Abfrage trägt schon die Sicht |
| `src/Livewire/Produktion/Editor.php:360` | exists | FoodAlchemistConcept | bleibt: Abfrage trägt schon die Sicht |
| `src/Livewire/Produktion/Editor.php:376` | exists | FoodAlchemistRecipeDarreichung | **Risiko Abo-Kind:** Kind einer Abo-Fassung trägt `team_id` = Master → nach B3 für den Mandanten unsichtbar, Prüfung schlägt fehl. Kind-Tabelle nicht scopen (B3-Vorschlag 1) |
| `src/Livewire/Recipes/StepEditor.php:284` | exists | – | bleibt: Kind eines geprüften Eltern-Datensatzes (Eltern-Schlüssel in der Abfrage) |
| `src/Livewire/Standortprofil/Index.php:69` | exists | FoodAlchemistProjektStandort | bleibt: nur eigenes Team (eigene Zeilen sind immer sichtbar) |
| `src/Livewire/Planung/Index.php:1446` | exists | FoodAlchemistConcept | bleibt: nur eigenes Team (eigene Zeilen sind immer sichtbar) |
| `src/Livewire/Planung/Index.php:2922` | exists | FoodAlchemistConcept | bleibt: nur eigenes Team (eigene Zeilen sind immer sichtbar) |
| `src/Livewire/Planung/Index.php:2937` | exists | FoodAlchemistDishIdea | bleibt: Abfrage trägt schon die Sicht |
| `src/Livewire/Verkauf/VkModal.php:826` | exists | – | nicht betroffen: Query Builder (B3 greift nur auf Eloquent) |
| `src/Livewire/Concepter/Editor.php:1403` | exists | FoodAlchemistRecipeDarreichung | **Risiko Abo-Kind:** Kind einer Abo-Fassung trägt `team_id` = Master → nach B3 für den Mandanten unsichtbar, Prüfung schlägt fehl. Kind-Tabelle nicht scopen (B3-Vorschlag 1) |
| `src/Livewire/Foodbooks/Index.php:1588` | exists | FoodAlchemistEventtyp | bleibt: Abfrage trägt schon die Sicht |
| `src/Support/ProduktpassSchutz.php:54` | exists | – | nicht betroffen: Query Builder (B3 greift nur auf Eloquent) |
| `src/Support/TeamScope.php:205` | exists | – | nicht betroffen: Modell ohne Team-Trait (modelClass) |
| `src/Support/SensorikNachholen.php:45` | exists | – | nicht betroffen: Query Builder (B3 greift nur auf Eloquent) |
| `src/Support/SensorikNachholen.php:49` | exists | – | nicht betroffen: Query Builder (B3 greift nur auf Eloquent) |
| `src/Support/StammPaket.php:63` | exists | – | nicht betroffen: Query Builder (B3 greift nur auf Eloquent) |
| `src/Support/StammPaket.php:110` | firstOrCreate | – | nicht betroffen: Modell ohne Team-Trait (FoodAlchemistTeamStammOptin) |
| `src/Support/AboSicht.php:33` | exists | – | nicht betroffen: Query Builder (B3 greift nur auf Eloquent) |
| `src/Support/CrmKunden.php:83` | exists | – | nicht betroffen: Modell ohne Team-Trait (CrmCompany) |
| `src/Support/CrmKunden.php:86` | exists | – | nicht betroffen: Modell ohne Team-Trait (CrmContact) |
| `src/Jobs/ClassifyLaJob.php:101` | updateOrCreate | FoodAlchemistSupplierItemStructure | bleibt: 1:1-Nebentabelle erbt `team_id` vom Eltern-Artikel (gleiche Sicht wie der Eltern) |
| `src/Jobs/ConformanceCheckJob.php:115` | exists | FoodAlchemistRecipe | bleibt: Konsole bzw. Job mit Auftrags-Kontext (Teil A), Wurzel-Modell |
| `src/Observers/GpLagerObserver.php:27` | exists | FoodAlchemistStorageBin | bleibt: Kind eines geprüften Eltern-Datensatzes (Eltern-Schlüssel in der Abfrage) |
| `src/Services/SalesImportService.php:187` | exists | FoodAlchemistRecipe | bleibt: Abfrage trägt schon die Sicht |
| `src/Services/PriceService.php:290` | exists | FoodAlchemistSupplierItem | bleibt: Abfrage trägt schon die Sicht |
| `src/Services/PriceService.php:350` | exists | FoodAlchemistSupplierItem | bleibt: Abfrage trägt schon die Sicht |
| `src/Services/PlanningCascadeService.php:179` | exists | FoodAlchemistConcept | bleibt: nur eigenes Team (eigene Zeilen sind immer sichtbar) |
| `src/Services/PlanningCascadeService.php:3132` | firstOrCreate | – | nicht betroffen: Modell ohne Team-Trait (FoodAlchemistCascadeRecipeDependency) |
| `src/Services/OrderService.php:1834` | exists | FoodAlchemistOrder | bleibt: nur eigenes Team (eigene Zeilen sind immer sichtbar) |
| `src/Services/SalesRecipeService.php:491` | exists | – | bleibt: Kind eines geprüften Eltern-Datensatzes (Eltern-Schlüssel in der Abfrage) |
| `src/Services/SalesRecipeService.php:663` | exists | – | nicht betroffen: Query Builder (B3 greift nur auf Eloquent) |
| `src/Services/SalesRecipeService.php:687` | exists | – | nicht betroffen: Query Builder (B3 greift nur auf Eloquent) |
| `src/Services/AboService.php:63` | firstOrNew | – | nicht betroffen: Modell ohne Team-Trait (FoodAlchemistAbo) |
| `src/Services/EtikettService.php:129` | exists | FoodAlchemistOutlet | bleibt: nur eigenes Team (eigene Zeilen sind immer sichtbar) |
| `src/Services/PlanningFrameService.php:50` | firstOrCreate | FoodAlchemistPlanningFrame | bleibt: nur eigenes Team (eigene Zeilen sind immer sichtbar) |
| `src/Services/PlanningFrameService.php:205` | exists | – | bleibt: Kind eines geprüften Eltern-Datensatzes (Eltern-Schlüssel in der Abfrage) |
| `src/Services/PlanningFrameService.php:233` | doesntExist | FoodAlchemistSaison | bleibt: Abfrage trägt schon die Sicht |
| `src/Services/NonFoodService.php:98` | updateOrCreate | FoodAlchemistSupplierItemNonfood | bleibt: 1:1-Nebentabelle erbt `team_id` vom Eltern-Artikel (gleiche Sicht wie der Eltern) |
| `src/Services/NonFoodService.php:138` | updateOrCreate | FoodAlchemistSupplierItemNonfood | bleibt: 1:1-Nebentabelle erbt `team_id` vom Eltern-Artikel (gleiche Sicht wie der Eltern) |
| `src/Services/NonFoodService.php:152` | exists | FoodAlchemistSupplierItemNonfood | bleibt: 1:1-Nebentabelle erbt `team_id` vom Eltern-Artikel (gleiche Sicht wie der Eltern) |
| `src/Services/SpeisekarteService.php:296` | exists | FoodAlchemistSpeisekarteRubrik | bleibt: Kind eines geprüften Eltern-Datensatzes (Eltern-Schlüssel in der Abfrage) |
| `src/Services/SpeisekarteService.php:343` | exists | FoodAlchemistSpeisekarteRubrik | bleibt: Kind eines geprüften Eltern-Datensatzes (Eltern-Schlüssel in der Abfrage) |
| `src/Services/SpeisekarteService.php:491` | exists | FoodAlchemistRecipe | bleibt: Abfrage trägt schon die Sicht |
| `src/Services/SpeisekarteService.php:503` | exists | FoodAlchemistConcept | bleibt: Abfrage trägt schon die Sicht |
| `src/Services/AboKuendigungService.php:118` | exists | – | nicht betroffen: Query Builder (B3 greift nur auf Eloquent) |
| `src/Services/AboKuendigungService.php:786` | firstOrCreate | FoodAlchemistComponentEquivalent | bleibt: nur eigenes Team (eigene Zeilen sind immer sichtbar) |
| `src/Services/AboKuendigungService.php:876` | exists | FoodAlchemistRecipe | bleibt: nur eigenes Team (eigene Zeilen sind immer sichtbar) |
| `src/Services/RecipeStepService.php:312` | exists | FoodAlchemistRecipeStep | bleibt: Kind eines geprüften Eltern-Datensatzes (Eltern-Schlüssel in der Abfrage) |
| `src/Services/RecipeStepService.php:359` | exists | FoodAlchemistRecipeStep | bleibt: Kind eines geprüften Eltern-Datensatzes (Eltern-Schlüssel in der Abfrage) |
| `src/Services/TrendService.php:193` | exists | FoodAlchemistTrend | bleibt: Abfrage trägt schon die Sicht |
| `src/Services/TrendService.php:854` | exists | FoodAlchemistTrendBeleg | `ohneTeamSicht('verwendung')`: Verwendungs-Prüfung muss Zeilen ANDERER Teams sehen, sonst löscht B3, was ein anderes Team noch nutzt |
| `src/Services/KnowledgeService.php:364` | exists | – | nicht betroffen: Query Builder (B3 greift nur auf Eloquent) |
| `src/Services/KnowledgeService.php:545` | exists | – | nicht betroffen: Query Builder (B3 greift nur auf Eloquent) |
| `src/Services/KnowledgeService.php:588` | exists | – | nicht betroffen: Query Builder (B3 greift nur auf Eloquent) |
| `src/Services/KnowledgeService.php:763` | exists | – | nicht betroffen: Query Builder (B3 greift nur auf Eloquent) |
| `src/Services/WareneingangService.php:204` | exists | FoodAlchemistDeliveryNote | bleibt: nur eigenes Team (eigene Zeilen sind immer sichtbar) |
| `src/Services/WareneingangService.php:212` | exists | FoodAlchemistInventoryLocation | bleibt: nur eigenes Team (eigene Zeilen sind immer sichtbar) |
| `src/Services/WareneingangService.php:322` | exists | FoodAlchemistDeliveryNoteLine | bleibt: Kind eines geprüften Eltern-Datensatzes (Eltern-Schlüssel in der Abfrage) |
| `src/Services/WareneingangService.php:422` | exists | FoodAlchemistDeliveryNoteLine | bleibt: Kind eines geprüften Eltern-Datensatzes (Eltern-Schlüssel in der Abfrage) |
| `src/Services/PaketService.php:278` | exists | FoodAlchemistRecipeDarreichung | **Risiko Abo-Kind:** Kind einer Abo-Fassung trägt `team_id` = Master → nach B3 für den Mandanten unsichtbar, Prüfung schlägt fehl. Kind-Tabelle nicht scopen (B3-Vorschlag 1) |
| `src/Services/PaketService.php:314` | exists | – | nicht betroffen: Query Builder (B3 greift nur auf Eloquent) |
| `src/Services/LieferantenRechnungService.php:178` | exists | FoodAlchemistSupplierInvoice | bleibt: nur eigenes Team (eigene Zeilen sind immer sichtbar) |
| `src/Services/LieferantenRechnungService.php:503` | exists | FoodAlchemistSupplierInvoiceLine | bleibt: Kind eines geprüften Eltern-Datensatzes (Eltern-Schlüssel in der Abfrage) |
| `src/Services/ProjektUebernahmeService.php:59` | exists | – | nicht betroffen: Modell ohne Team-Trait (Team) |
| `src/Services/ProjektUebernahmeService.php:122` | exists | FoodAlchemistProjekt | `ohneTeamSicht('eindeutigkeit')`: global eindeutiger Wert (öffentlicher Link / Zuordnung über Teams) |
| `src/Services/ProduktionsVerbrauchService.php:35` | exists | FoodAlchemistInventoryMovement | bleibt: nur eigenes Team (eigene Zeilen sind immer sichtbar) |
| `src/Services/GpFormService.php:95` | firstOrNew | – | nicht betroffen: Modell ohne Team-Trait (FoodAlchemistGpForm) |
| `src/Services/InhaltsFreigabeService.php:109` | exists | – | nicht betroffen: Query Builder (B3 greift nur auf Eloquent) |
| `src/Services/InhaltsFreigabeService.php:158` | exists | – | nicht betroffen: Query Builder (B3 greift nur auf Eloquent) |
| `src/Services/InhaltsFreigabeService.php:190` | exists | – | nicht betroffen: Query Builder (B3 greift nur auf Eloquent) |
| `src/Services/InhaltsFreigabeService.php:448` | exists | – | nicht betroffen: Query Builder (B3 greift nur auf Eloquent) |
| `src/Services/BriefTemplateService.php:181` | exists | FoodAlchemistBriefTemplate | bleibt: nur eigenes Team (eigene Zeilen sind immer sichtbar) |
| `src/Services/RebateService.php:304` | firstOrNew | FoodAlchemistSupplierRebateConfig | bleibt: nur eigenes Team (eigene Zeilen sind immer sichtbar) |
| `src/Services/RebateService.php:332` | exists | FoodAlchemistSupplierRebateTier | bleibt: nur eigenes Team (eigene Zeilen sind immer sichtbar) |
| `src/Services/RebateService.php:407` | exists | FoodAlchemistSupplier | bleibt: Abfrage trägt schon die Sicht |
| `src/Services/PreislistenImportService.php:202` | exists | – | nicht betroffen: Modell ohne Team-Trait (Zeile) |
| `src/Services/TeamVkService.php:50` | exists | – | bleibt: Kind eines geprüften Eltern-Datensatzes (Eltern-Schlüssel in der Abfrage) |
| `src/Services/TeamVkService.php:60` | exists | – | nicht betroffen: Modell ohne Team-Trait (DarreichungService) |
| `src/Services/LeadLaService.php:301` | updateOrCreate | – | nicht betroffen: Modell ohne Team-Trait (FoodAlchemistGpLaPreference) |
| `src/Services/LeadLaService.php:392` | updateOrCreate | – | nicht betroffen: Modell ohne Team-Trait (FoodAlchemistGpLaPreference) |
| `src/Services/LeadLaService.php:411` | updateOrCreate | – | nicht betroffen: Modell ohne Team-Trait (FoodAlchemistGpLaPreference) |
| `src/Services/LeadLaService.php:660` | exists | – | nicht betroffen: Query Builder (B3 greift nur auf Eloquent) |
| `src/Services/ConceptVariantService.php:142` | exists | FoodAlchemistConceptSlot | `ohneTeamSicht('verwendung')`: Verwendungs-Prüfung muss Zeilen ANDERER Teams sehen, sonst löscht B3, was ein anderes Team noch nutzt |
| `src/Services/BulkEnrichService.php:151` | exists | – | nicht betroffen: Query Builder (B3 greift nur auf Eloquent) |
| `src/Services/BulkEnrichService.php:155` | exists | – | nicht betroffen: Query Builder (B3 greift nur auf Eloquent) |
| `src/Services/BulkEnrichService.php:157` | exists | – | nicht betroffen: Query Builder (B3 greift nur auf Eloquent) |
| `src/Services/BulkEnrichService.php:158` | exists | – | nicht betroffen: Query Builder (B3 greift nur auf Eloquent) |
| `src/Services/BulkEnrichService.php:823` | exists | FoodAlchemistRecipe | bleibt: Abfrage trägt schon die Sicht |
| `src/Services/BulkEnrichService.php:1062` | exists | – | nicht betroffen: Query Builder (B3 greift nur auf Eloquent) |
| `src/Services/BulkEnrichService.php:1108` | exists | FoodAlchemistGp | bleibt: Abfrage trägt schon die Sicht |
| `src/Services/ComponentEquivalentService.php:51` | updateOrCreate | – | nicht betroffen: Modell ohne Team-Trait (Equiv) |
| `src/Services/ComponentEquivalentService.php:192` | exists | FoodAlchemistRecipe | bleibt: Abfrage trägt schon die Sicht |
| `src/Services/ComponentEquivalentService.php:224` | exists | FoodAlchemistRecipe | bleibt: Abfrage trägt schon die Sicht |
| `src/Services/GpNamingService.php:326` | exists | – | bleibt: nur eigenes Team bzw. eigene Kette |
| `src/Services/GpNamingService.php:432` | exists | FoodAlchemistGp | bleibt mit Sicht: heute über ALLE Teams (Team-Übersprechen als Namens-Hinweis); B3 korrigiert das |
| `src/Services/SignalTrendService.php:214` | updateOrCreate | – | nicht betroffen: Modell ohne Team-Trait (FoodAlchemistSignalSnapshot) |
| `src/Services/InventoryService.php:108` | exists | – | bleibt: nur eigenes Team bzw. eigene Kette |
| `src/Services/PresentationService.php:154` | firstOrNew | FoodAlchemistPresentation | bleibt: nur eigenes Team bzw. eigene Kette |
| `src/Services/PresentationService.php:274` | exists | FoodAlchemistPresentation | `ohneTeamSicht('eindeutigkeit')`: global eindeutiger Wert (öffentlicher Link / Zuordnung über Teams) |
| `src/Services/PresentationService.php:292` | exists | FoodAlchemistPresentation | `ohneTeamSicht('eindeutigkeit')`: global eindeutiger Wert (öffentlicher Link / Zuordnung über Teams) |
| `src/Services/PresentationService.php:1275` | exists | – | nicht betroffen: Modell ohne Team-Trait (class) |
| `src/Services/PresentationService.php:1299` | exists | – | `ohneTeamSicht('eindeutigkeit')`: global eindeutiger Wert (öffentlicher Link / Zuordnung über Teams) |
| `src/Services/OfferCompositionService.php:61` | exists | FoodAlchemistOfferChapter | bleibt: Kind eines geprüften Eltern-Datensatzes (Eltern-Schlüssel in der Abfrage) |
| `src/Services/OfferCompositionService.php:91` | exists | FoodAlchemistOfferChapter | bleibt: Kind eines geprüften Eltern-Datensatzes (Eltern-Schlüssel in der Abfrage) |
| `src/Services/OfferCompositionService.php:1015` | exists | FoodAlchemistConcept | bleibt: Abfrage trägt schon die Sicht |
| `src/Services/OfferCompositionService.php:1026` | exists | FoodAlchemistRecipe | bleibt: Abfrage trägt schon die Sicht |
| `src/Services/GpService.php:114` | exists | FoodAlchemistGp | bleibt: nur eigenes Team (eigene Zeilen sind immer sichtbar) |
| `src/Services/SupplierService.php:94` | exists | FoodAlchemistSupplier | bleibt: Abfrage trägt schon die Sicht |
| `src/Services/SupplierService.php:222` | exists | FoodAlchemistSupplier | bleibt: Abfrage trägt schon die Sicht |
| `src/Services/SupplierAgreementService.php:113` | exists | FoodAlchemistSupplier | bleibt: Abfrage trägt schon die Sicht |
| `src/Services/RecipeDependencyWorkflowService.php:716` | firstOrCreate | FoodAlchemistCascadeRunStep | bleibt: nur eigenes Team (eigene Zeilen sind immer sichtbar) |
| `src/Services/RecipeDependencyWorkflowService.php:745` | firstOrCreate | FoodAlchemistCascadeRunStep | bleibt: nur eigenes Team (eigene Zeilen sind immer sichtbar) |
| `src/Services/RecipeDependencyWorkflowService.php:762` | firstOrCreate | – | nicht betroffen: Modell ohne Team-Trait (FoodAlchemistCascadeRecipeDependency) |
| `src/Services/RecipeDependencyWorkflowService.php:803` | firstOrCreate | – | nicht betroffen: Modell ohne Team-Trait (FoodAlchemistCascadeRecipeDependency) |
| `src/Services/RecipeDependencyWorkflowService.php:843` | firstOrCreate | FoodAlchemistCascadeRunStep | bleibt: nur eigenes Team (eigene Zeilen sind immer sichtbar) |
| `src/Services/RecipeOneShotService.php:620` | exists | – | nicht betroffen: Query Builder (B3 greift nur auf Eloquent) |
| `src/Services/LagerBewegungService.php:108` | exists | FoodAlchemistInventoryMovement | bleibt: nur eigenes Team (eigene Zeilen sind immer sichtbar) |
| `src/Services/LeitstelleService.php:528` | exists | – | bleibt: Kind eines geprüften Eltern-Datensatzes (Eltern-Schlüssel in der Abfrage) |
| `src/Services/MasterTeamService.php:73` | updateOrCreate | – | nicht betroffen: Modell ohne Team-Trait (FoodAlchemistPlattformEinstellung) |
| `src/Services/MandantExportService.php:76` | exists | – | nicht betroffen: Query Builder (B3 greift nur auf Eloquent) |
| `src/Services/TripleMatchService.php:60` | updateOrCreate | FoodAlchemistTeamSetting | bleibt: nur eigenes Team (eigene Zeilen sind immer sichtbar) |
| `src/Services/SpeiseplanService.php:327` | exists | FoodAlchemistSpeiseplan | bleibt: nur eigenes Team bzw. eigene Kette |
| `src/Services/SpeiseplanService.php:727` | exists | – | nicht betroffen: Modell ohne Team-Trait (model) |
| `src/Services/SpeiseplanService.php:732` | exists | FoodAlchemistRecipe | bleibt: Abfrage trägt schon die Sicht |
| `src/Services/SpeiseplanService.php:956` | exists | FoodAlchemistRecipeDarreichung | **Risiko Abo-Kind:** Kind einer Abo-Fassung trägt `team_id` = Master → nach B3 für den Mandanten unsichtbar, Prüfung schlägt fehl. Kind-Tabelle nicht scopen (B3-Vorschlag 1) |
| `src/Services/SpeiseplanService.php:986` | exists | – | bleibt: Kind eines geprüften Eltern-Datensatzes (Eltern-Schlüssel in der Abfrage) |
| `src/Services/TeamSettingsService.php:179` | firstOrNew | FoodAlchemistOutletSetting | bleibt: nur eigenes Team bzw. eigene Kette |
| `src/Services/TeamSettingsService.php:350` | firstOrNew | FoodAlchemistTeamSetting | bleibt: nur eigenes Team (eigene Zeilen sind immer sichtbar) |
| `src/Services/TeamSettingsService.php:377` | exists | FoodAlchemistProductionStation | bleibt: Abfrage trägt schon die Sicht |
| `src/Services/TeamSettingsService.php:389` | exists | FoodAlchemistMarkupClass | bleibt: Abfrage trägt schon die Sicht |
| `src/Services/TeamProfilService.php:134` | firstOrNew | – | nicht betroffen: Modell ohne Team-Trait (FoodAlchemistTeamProfile) |
| `src/Services/TeamProfilService.php:147` | exists | FoodAlchemistVocabKochequipment | bleibt: Abfrage trägt schon die Sicht |
| `src/Services/TeamProfilService.php:209` | exists | – | nicht betroffen: Modell ohne Team-Trait (FoodAlchemistTeamProfile) |
| `src/Services/TeamProfilService.php:210` | exists | – | nicht betroffen: Modell ohne Team-Trait (FoodAlchemistTeamProfile) |
| `src/Services/TeamProfilService.php:211` | exists | – | nicht betroffen: Modell ohne Team-Trait (FoodAlchemistTeamProfile) |
| `src/Services/TeamProfilService.php:247` | exists | FoodAlchemistProjektStandort | bleibt: nur eigenes Team (eigene Zeilen sind immer sichtbar) |
| `src/Services/InventurService.php:213` | updateOrCreate | FoodAlchemistInventoryMovement | bleibt: nur eigenes Team (eigene Zeilen sind immer sichtbar) |
| `src/Services/ConformanceService.php:63` | exists | FoodAlchemistGp | bleibt: Wurzel-Modell GP per id, sichtbar |
| `src/Services/DataQualityService.php:1909` | exists | – | bleibt: nur eigenes Team bzw. eigene Kette |
| `src/Services/PlanPreisService.php:121` | exists | – | nicht betroffen: Modell ohne Team-Trait (FoodAlchemistPlanPreis) |
| `src/Services/SignalPolicyService.php:90` | updateOrCreate | FoodAlchemistSignalPolicy | bleibt: nur eigenes Team (eigene Zeilen sind immer sichtbar) |
| `src/Services/PairingService.php:1134` | exists | – | nicht betroffen: Query Builder (B3 greift nur auf Eloquent) |
| `src/Services/PairingService.php:2123` | exists | FoodAlchemistRecipe | bleibt: Abfrage trägt schon die Sicht |
| `src/Services/PurchaseJournalService.php:59` | updateOrCreate | FoodAlchemistPurchaseTransaction | bleibt: nur eigenes Team bzw. eigene Kette |
| `src/Services/PurchaseJournalService.php:87` | exists | – | bleibt: nur eigenes Team bzw. eigene Kette |
| `src/Services/GebuehrenZaehler.php:65` | exists | – | nicht betroffen: Modell ohne Team-Trait (FoodAlchemistTeamStammOptin) |
| `src/Services/MandantenService.php:115` | firstOrNew | – | nicht betroffen: Modell ohne Team-Trait (FoodAlchemistMandant) |
| `src/Services/MandantenService.php:191` | exists | – | nicht betroffen: Modell ohne Team-Trait (CrmCompany) |
| `src/Services/RecipeService.php:667` | exists | – | bleibt: Kind eines geprüften Eltern-Datensatzes (Eltern-Schlüssel in der Abfrage) |
| `src/Services/RecipeService.php:670` | exists | – | bleibt: Kind eines geprüften Eltern-Datensatzes (Eltern-Schlüssel in der Abfrage) |
| `src/Services/RecipeService.php:697` | exists | – | bleibt: Kind eines geprüften Eltern-Datensatzes (Eltern-Schlüssel in der Abfrage) |
| `src/Services/RecipeService.php:700` | exists | – | `ohneTeamSicht('verwendung')`: Verwendungs-Prüfung muss Zeilen ANDERER Teams sehen, sonst löscht B3, was ein anderes Team noch nutzt |
| `src/Services/RecipeService.php:1378` | exists | FoodAlchemistRecipe | bleibt: nur eigenes Team (eigene Zeilen sind immer sichtbar) |
| `src/Services/ConceptPreisService.php:103` | exists | – | nicht betroffen: Query Builder (B3 greift nur auf Eloquent) |
| `src/Services/RecipeRollenService.php:30` | exists | FoodAlchemistRecipeRolle | bleibt: Kind eines geprüften Eltern-Datensatzes (Eltern-Schlüssel in der Abfrage) |
| `src/Services/PaketGrundeinstellungenService.php:134` | exists | – | nicht betroffen: Modell ohne Team-Trait (FoodAlchemistTeamPaket) |
| `src/Services/PaketGrundeinstellungenService.php:135` | updateOrCreate | – | nicht betroffen: Modell ohne Team-Trait (FoodAlchemistTeamPaket) |
| `src/Services/PaketGrundeinstellungenService.php:281` | firstOrNew | FoodAlchemistTeamSetting | bleibt: nur eigenes Team (eigene Zeilen sind immer sichtbar) |
| `src/Services/ProjektService.php:131` | exists | FoodAlchemistProjektStandort | bleibt: Kind eines geprüften Eltern-Datensatzes (Eltern-Schlüssel in der Abfrage) |
| `src/Services/ProjektService.php:134` | exists | FoodAlchemistProjektStandort | bleibt: Kind eines geprüften Eltern-Datensatzes (Eltern-Schlüssel in der Abfrage) |
| `src/Services/ProjektService.php:152` | exists | FoodAlchemistProjektStandort | bleibt: Kind eines geprüften Eltern-Datensatzes (Eltern-Schlüssel in der Abfrage) |
| `src/Services/RecipeGeneratorService.php:279` | exists | – | nicht betroffen: Modell ohne Team-Trait (RecipeService) |
| `src/Services/OrderTemplateService.php:103` | exists | FoodAlchemistConcept | bleibt: Abfrage trägt schon die Sicht |
| `src/Services/OrderTemplateService.php:104` | exists | FoodAlchemistConcept | bleibt: Abfrage trägt schon die Sicht |
| `src/Services/OrderTemplateService.php:105` | exists | FoodAlchemistConcept | bleibt: Abfrage trägt schon die Sicht |
| `src/Services/OrderTemplateService.php:106` | exists | FoodAlchemistConcept | bleibt: Abfrage trägt schon die Sicht |
| `src/Services/OrderTemplateService.php:107` | exists | FoodAlchemistConcept | bleibt: Abfrage trägt schon die Sicht |
| `src/Services/OrderTemplateService.php:113` | firstOrNew | FoodAlchemistOrderTemplateLine | bleibt: Kind eines geprüften Eltern-Datensatzes (Eltern-Schlüssel in der Abfrage) |
| `src/Services/FaRechte.php:137` | exists | – | nicht betroffen: Query Builder (B3 greift nur auf Eloquent) |
| `src/Services/FaRechte.php:149` | updateOrCreate | FoodAlchemistTeamMemberFlag | bleibt: nur eigenes Team (eigene Zeilen sind immer sichtbar) |
| `src/Services/FaRechte.php:192` | exists | FoodAlchemistTeamBereich | bleibt: nur eigenes Team bzw. eigene Kette |
| `src/Services/FaRechte.php:213` | exists | FoodAlchemistUserBereichSperre | bleibt: nur eigenes Team bzw. eigene Kette |
| `src/Services/FaRechte.php:242` | updateOrCreate | FoodAlchemistTeamBereich | bleibt: nur eigenes Team (eigene Zeilen sind immer sichtbar) |
| `src/Services/FaRechte.php:253` | exists | – | nicht betroffen: Query Builder (B3 greift nur auf Eloquent) |
| `src/Services/FaRechte.php:260` | updateOrCreate | FoodAlchemistUserBereichSperre | bleibt: nur eigenes Team (eigene Zeilen sind immer sichtbar) |
| `src/Services/FaRechte.php:296` | updateOrCreate | FoodAlchemistTeamKontingent | bleibt: nur eigenes Team bzw. eigene Kette |
| `src/Services/FaRechte.php:369` | exists | FoodAlchemistTeamMemberFlag | bleibt: nur eigenes Team bzw. eigene Kette |
| `src/Services/FaRechte.php:389` | exists | – | nicht betroffen: Query Builder (B3 greift nur auf Eloquent) |
| `src/Services/ActiveOutletContext.php:78` | updateOrCreate | – | nicht betroffen: Modell ohne Team-Trait (FoodAlchemistActiveOutlet) |
| `src/Services/FormatService.php:435` | exists | FoodAlchemistFormatImage | bleibt: Kind eines geprüften Eltern-Datensatzes (Eltern-Schlüssel in der Abfrage) |
| `src/Services/StammLieferantService.php:48` | exists | FoodAlchemistSupplier | bleibt: Abfrage trägt schon die Sicht |
| `src/Services/StammLieferantService.php:52` | firstOrCreate | FoodAlchemistStammLieferant | bleibt: nur eigenes Team (eigene Zeilen sind immer sichtbar) |
| `src/Services/StandortService.php:80` | exists | FoodAlchemistOutlet | bleibt: nur eigenes Team (eigene Zeilen sind immer sichtbar) |
| `src/Services/StandortService.php:83` | updateOrCreate | FoodAlchemistTeamBetrieb | `ohneTeamSicht('unterteam-verwaltung')`: Oberteam schreibt die Zeile seines Unter-Teams; die Sicht geht nur aufwärts → nach B3 Dublette/Unique-Absturz |
| `src/Services/SupplierItemService.php:142` | updateOrCreate | FoodAlchemistItemAllergen | bleibt: 1:1-Nebentabelle erbt `team_id` vom Eltern-Artikel (gleiche Sicht wie der Eltern) |
| `src/Services/SupplierItemService.php:197` | updateOrCreate | FoodAlchemistItemNutritional | bleibt: 1:1-Nebentabelle erbt `team_id` vom Eltern-Artikel (gleiche Sicht wie der Eltern) |
| `src/Services/SupplierItemService.php:240` | updateOrCreate | FoodAlchemistItemDeclaration | bleibt: 1:1-Nebentabelle erbt `team_id` vom Eltern-Artikel (gleiche Sicht wie der Eltern) |
| `src/Services/SupplierItemService.php:254` | exists | FoodAlchemistSupplier | bleibt: Abfrage trägt schon die Sicht |
| `src/Services/LagerartikelService.php:49` | exists | FoodAlchemistGpLagerartikel | bleibt: nur eigenes Team (eigene Zeilen sind immer sichtbar) |
| `src/Services/AngebotEinfrierService.php:359` | exists | – | bleibt: Kind eines geprüften Eltern-Datensatzes (Eltern-Schlüssel in der Abfrage) |
| `src/Services/AngebotService.php:190` | exists | FoodAlchemistOfferBlock | bleibt: Kind eines geprüften Eltern-Datensatzes (Eltern-Schlüssel in der Abfrage) |
| `src/Services/VocabularyService.php:63` | exists | FoodAlchemistVocabEinheit | bleibt: Abfrage trägt schon die Sicht |
| `src/Services/VocabularyService.php:158` | exists | FoodAlchemistLookupWarengruppe | bleibt: nur eigenes Team (eigene Zeilen sind immer sichtbar) |
| `src/Services/VocabularyService.php:278` | exists | FoodAlchemistWarengruppeSubkategorie | bleibt: nur eigenes Team (eigene Zeilen sind immer sichtbar) |
| `src/Services/VocabularyService.php:403` | exists | FoodAlchemistRecipeMainGroup | bleibt: nur eigenes Team (eigene Zeilen sind immer sichtbar) |
| `src/Services/VocabularyService.php:451` | exists | FoodAlchemistDishMainGroup | bleibt: nur eigenes Team (eigene Zeilen sind immer sichtbar) |
| `src/Services/VocabularyService.php:481` | exists | FoodAlchemistDishClass | bleibt: nur eigenes Team (eigene Zeilen sind immer sichtbar) |
| `src/Services/SpeiseplanVorgabenService.php:116` | exists | FoodAlchemistSpeiseplanChip | bleibt: nur eigenes Team (eigene Zeilen sind immer sichtbar) |
| `src/Services/SpeiseplanVorgabenService.php:156` | exists | FoodAlchemistDishMainGroup | bleibt: Abfrage trägt schon die Sicht |
| `src/Services/OutletSettingsService.php:19` | firstOrNew | FoodAlchemistOutletSetting | bleibt: nur eigenes Team bzw. eigene Kette |
| `src/Services/CanvasService.php:200` | firstOrCreate | FoodAlchemistCanvas | bleibt: nur eigenes Team (eigene Zeilen sind immer sichtbar) |
| `src/Services/DarreichungService.php:48` | exists | – | bleibt: Kind eines geprüften Eltern-Datensatzes (Eltern-Schlüssel in der Abfrage) |
| `src/Services/DarreichungService.php:104` | exists | – | bleibt: Kind eines geprüften Eltern-Datensatzes (Eltern-Schlüssel in der Abfrage) |
| `src/Services/DarreichungService.php:262` | exists | – | bleibt: Kind eines geprüften Eltern-Datensatzes (Eltern-Schlüssel in der Abfrage) |
| `src/Services/DarreichungService.php:310` | exists | – | bleibt: Kind eines geprüften Eltern-Datensatzes (Eltern-Schlüssel in der Abfrage) |
| `src/Services/DarreichungService.php:319` | updateOrCreate | FoodAlchemistRecipeDarreichungDelta | bleibt: Kind eines geprüften Eltern-Datensatzes (Eltern-Schlüssel in der Abfrage) |
| `src/Services/DarreichungService.php:351` | updateOrCreate | FoodAlchemistRecipeDarreichungZubehoer | bleibt: Kind eines geprüften Eltern-Datensatzes (Eltern-Schlüssel in der Abfrage) |
| `src/Services/DarreichungService.php:438` | exists | – | nicht betroffen: Query Builder (B3 greift nur auf Eloquent) |
| `src/Services/DarreichungService.php:550` | exists | – | bleibt: Kind eines geprüften Eltern-Datensatzes (Eltern-Schlüssel in der Abfrage) |
| `src/Services/DarreichungService.php:699` | exists | – | bleibt: Kind eines geprüften Eltern-Datensatzes (Eltern-Schlüssel in der Abfrage) |
| `src/Services/DarreichungService.php:766` | exists | – | bleibt: Kind eines geprüften Eltern-Datensatzes (Eltern-Schlüssel in der Abfrage) |
| `src/Services/ConceptService.php:512` | exists | FoodAlchemistRecipeDarreichung | **Risiko Abo-Kind:** Kind einer Abo-Fassung trägt `team_id` = Master → nach B3 für den Mandanten unsichtbar, Prüfung schlägt fehl. Kind-Tabelle nicht scopen (B3-Vorschlag 1) |
| `src/Services/ConceptService.php:1487` | exists | FoodAlchemistVocabKlasse | bleibt: nur eigenes Team (eigene Zeilen sind immer sichtbar) |
| `src/Services/CoherenceService.php:64` | updateOrCreate | FoodAlchemistRecipeCulinaryCoherence | **Risiko Abo-Kind + Eindeutigkeit:** `updateOrCreate` über `recipe_id` findet die Master-Zeile der Fassung nicht → Insert → Unique-Absturz. Kind-Tabelle nicht scopen |
| `src/Services/CoherenceService.php:102` | updateOrCreate | FoodAlchemistRecipeCulinaryCoherence | **Risiko Abo-Kind + Eindeutigkeit:** `updateOrCreate` über `recipe_id` findet die Master-Zeile der Fassung nicht → Insert → Unique-Absturz. Kind-Tabelle nicht scopen |
| `src/Services/LagerEinrichtungService.php:131` | updateOrCreate | – | nicht betroffen: Modell ohne Team-Trait (FoodAlchemistStorageBinItem) |
| `src/Services/AktionspreisService.php:122` | exists | FoodAlchemistSupplierItem | bleibt: Abfrage trägt schon die Sicht |
| `src/Services/FoodbookService.php:274` | exists | FoodAlchemistTargetGroup | bleibt: nur eigenes Team (eigene Zeilen sind immer sichtbar) |
| `src/Services/FoodbookService.php:561` | exists | FoodAlchemistFoodbookKapitel | bleibt: Kind eines geprüften Eltern-Datensatzes (Eltern-Schlüssel in der Abfrage) |
| `src/Services/FoodbookService.php:788` | exists | – | bleibt: Kind eines geprüften Eltern-Datensatzes (Eltern-Schlüssel in der Abfrage) |
| `src/Services/FoodbookService.php:797` | exists | FoodAlchemistConceptSlot | **Risiko Abo-Kind:** Kind einer Abo-Fassung trägt `team_id` = Master → nach B3 für den Mandanten unsichtbar, Prüfung schlägt fehl. Kind-Tabelle nicht scopen (B3-Vorschlag 1) |
| `src/Services/FoodbookService.php:893` | exists | – | bleibt: Kind eines geprüften Eltern-Datensatzes (Eltern-Schlüssel in der Abfrage) |
| `src/Services/FoodbookService.php:1513` | exists | FoodAlchemistRecipe | bleibt: Abfrage trägt schon die Sicht |
| `src/Services/FoodbookService.php:2339` | exists | FoodAlchemistFoodbookKapitel | bleibt: Kind eines geprüften Eltern-Datensatzes (Eltern-Schlüssel in der Abfrage) |
| `src/Services/Necta/NectaLieferstatusService.php:53` | exists | – | nicht betroffen: Query Builder (B3 greift nur auf Eloquent) |
| `src/Services/Necta/NectaLaufBasis.php:214` | exists | – | nicht betroffen: Query Builder (B3 greift nur auf Eloquent) |
| `src/Services/Necta/NectaAbgleichService.php:141` | exists | – | bleibt: Konsole bzw. Job mit Auftrags-Kontext (Teil A), Wurzel-Modell |
| `src/Services/Pairing/AnkerWissenImport.php:63` | exists | – | nicht betroffen: Query Builder (B3 greift nur auf Eloquent) |
| `src/Services/Pairing/AnkerWissenImport.php:175` | exists | – | nicht betroffen: Query Builder (B3 greift nur auf Eloquent) |
| `src/Services/Pairing/AnkerWissenFreigabe.php:92` | exists | – | nicht betroffen: Query Builder |
| `src/Services/Reife/RecipeReifeAdapter.php:138` | exists | – | nicht betroffen: Query Builder (B3 greift nur auf Eloquent) |
| `src/Services/Knowledge/KnowledgeCanonService.php:241` | exists | – | nicht betroffen: Query Builder |
| `src/Services/Produktpass/ProduktpassAbweichungService.php:85` | firstOrNew | – | nicht betroffen: Modell ohne Team-Trait (klasse) |
| `src/Services/Produktpass/ProduktpassService.php:280` | firstOrNew | FoodAlchemistItemAllergen | bleibt: 1:1-Nebentabelle erbt `team_id` vom Eltern-Artikel (gleiche Sicht wie der Eltern) |
| `src/Services/Produktpass/ProduktpassService.php:430` | exists | – | nicht betroffen: Modell ohne Team-Trait (FoodAlchemistProduktpassFeld) |
| `src/Services/Produktpass/ProduktpassService.php:610` | firstOrNew | FoodAlchemistItemNutritional | bleibt: 1:1-Nebentabelle erbt `team_id` vom Eltern-Artikel (gleiche Sicht wie der Eltern) |
| `src/Services/Produktpass/ProduktpassService.php:619` | firstOrNew | FoodAlchemistItemDeclaration | bleibt: 1:1-Nebentabelle erbt `team_id` vom Eltern-Artikel (gleiche Sicht wie der Eltern) |
| `src/Services/Produktpass/ProduktpassService.php:638` | updateOrCreate | – | nicht betroffen: Modell ohne Team-Trait (FoodAlchemistProduktpassFeld) |
| `src/Services/Concerns/PruefstOutletZuordnung.php:47` | exists | FoodAlchemistOutlet | bleibt: nur eigenes Team (eigene Zeilen sind immer sichtbar) |
| `src/Console/VocabUnitsDedupeCommand.php:96` | exists | – | nicht betroffen: Query Builder (B3 greift nur auf Eloquent) |
| `src/Console/ImportSliceCommand.php:55` | exists | – | nicht betroffen: Query Builder (B3 greift nur auf Eloquent) |
| `src/Console/ImportSliceCommand.php:525` | exists | – | nicht betroffen: Query Builder (B3 greift nur auf Eloquent) |
| `src/Console/ImportSliceCommand.php:571` | exists | – | nicht betroffen: Query Builder (B3 greift nur auf Eloquent) |
| `src/Console/ImportSliceCommand.php:601` | exists | – | nicht betroffen: Query Builder (B3 greift nur auf Eloquent) |
| `src/Console/ImportSliceCommand.php:673` | exists | – | nicht betroffen: Query Builder (B3 greift nur auf Eloquent) |
| `src/Console/ImportSliceCommand.php:904` | exists | – | nicht betroffen: Query Builder (B3 greift nur auf Eloquent) |
| `src/Console/ImportSliceCommand.php:1316` | exists | – | nicht betroffen: Query Builder (B3 greift nur auf Eloquent) |
| `src/Console/TrendsStartbestandCommand.php:50` | exists | FoodAlchemistTrend | bleibt: nur eigenes Team (eigene Zeilen sind immer sichtbar) |
| `src/Console/ImportMasterCommand.php:72` | exists | – | nicht betroffen: Query Builder (B3 greift nur auf Eloquent) |
| `src/Console/ImportMasterCommand.php:102` | exists | – | nicht betroffen: Query Builder (B3 greift nur auf Eloquent) |
| `src/Console/RegenerationHochziehenCommand.php:223` | exists | – | nicht betroffen: Query Builder (B3 greift nur auf Eloquent) |
| `src/Console/RegenerationHochziehenCommand.php:245` | exists | – | nicht betroffen: Query Builder (B3 greift nur auf Eloquent) |
| `src/Console/RecomputeCommand.php:44` | exists | FoodAlchemistRecipe | bleibt: Konsole bzw. Job mit Auftrags-Kontext (Teil A), Wurzel-Modell |
| `src/Console/WareneingangAltbestandCommand.php:58` | exists | FoodAlchemistDeliveryNoteLine | bleibt: Kind eines geprüften Eltern-Datensatzes (Eltern-Schlüssel in der Abfrage) |
| `src/Console/KnowledgeImportCommand.php:227` | exists | – | nicht betroffen: Query Builder (B3 greift nur auf Eloquent) |

## E-2 · Listen-Audit große Tabellen (Spec 84 §5a, 17.10.)

Gescannt: alle Lesepfade auf `supplier_items`, `prices`, `price_history`, `necta_stand`, `anchor_beziehungen`, `signals` (119 Methoden, davon 84 ohne limit/paginate/chunk; die meisten sind je Entität eingegrenzt).

**Umgebaut:**

| Stelle | PR | Änderung |
|---|---|---|
| `PriceService::detectAnomalies` | E-2 A (#475) | eine gestreamte Abfrage statt historyFor je Artikel; Ausreißer zwei Stream-Durchgänge; Grenze 200 + Gesamtzahl |
| `MatchService::exactBruecken` | E-2 A (#475) | nur EANs/Artikelnummern der geprüften Items statt aller gemappten Artikel |
| `SignalObjectService::signaleAmObjekt` | E-2 B | SQL-Vorfilter auf die drei möglichen Wege (Referenz, Metrik, Beispiele) statt aller offenen Signale |
| `SupplierService::volumenProxyRaw` | E-2 B | sichtbare Rezepte als Unterabfrage statt ID-Liste |
| `SignalDetektorService::veraltetePreise` | E-2 B | COUNT in der Datenbank statt alle GP-IDs laden |
| `PairingSignale::konfliktImGericht` / `wissenJeAnker` / `widerspruchWissenMessung`, `KontrastAbleitung::baue` | E-5 | in Stapeln bzw. auf die Anker eingegrenzt |

**Bewusst NICHT geändert (nicht erneut suchen):**

| Stelle | Grund |
|---|---|
| Necta-Dienste (`NectaAbgleichService`, `NectaNachholService`, `NectaNachfuellService`, `NectaPreishistorieService`) | je Lieferant bzw. in Blöcken, Batch-Jobs ohne UI-Pfad |
| `ReportExportService` (gpDaten, supplierDaten) | Export ist vollständig gewollt |
| Signal-Zähler (`Dashboard`, `ReviewQueue`, `SignalService::offeneNachTyp`, `SignalTrendService::offeneNachTypUndSeverity`), `StammPaketService::lieferanten`, `SupplierService::listWithCounts` | gruppierte COUNTs, liefern wenige Zeilen |
| `SignalService::schliesseGemessen` / `schliesseVerschwundene` | je Team + Typ (+ dedup), wenige Zeilen |
| Konsole `NectaLieferstatusCommand`, `RecipePackagingUnitsCommand` | Einmal-/Admin-Befehle |
| je Entität eingegrenzte Lesepfade (LeadLa, PreisVerlauf je Artikel, Produktpass, GpModal, ItemModal, IngredientEditor::gpArtikel …) | wenige Zeilen je Aufruf |
| MCP-List/Search-Tools (`->items()`) | paginiert (Scan-Fehlalarm) |
