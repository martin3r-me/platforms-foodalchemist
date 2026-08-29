<?php

/**
 * Food Alchemist Service Provider
 * 
 * Dieser Service Provider ist das Herzstück jedes Platform-Moduls.
 * 
 * WICHTIG FÜR LLMs:
 * - Dieser Service Provider folgt dem exakten Muster von HCM und Planner
 * - Alle wichtigen Schritte sind kommentiert
 * - Config wird in register() geladen (Laravel Best Practice)
 * - Modul-Registrierung erfolgt in boot()
 * 
 * ANPASSUNGEN FÜR NEUES MODUL:
 * 1. Ersetze "FoodAlchemist" durch deinen Modul-Namen (PascalCase)
 * 2. Ersetze "foodalchemist" durch deinen Modul-Namen (kebab-case)
 * 3. Passe Namespaces an
 * 4. Füge Commands/Tools hinzu falls nötig
 * 
 * @see Platform\Core\PlatformCore für Modul-Registrierung
 * @see Platform\Core\Routing\ModuleRouter für Route-Registrierung
 */

namespace Platform\FoodAlchemist;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Platform\Core\PlatformCore;
use Platform\Core\Routing\ModuleRouter;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

class FoodAlchemistServiceProvider extends ServiceProvider
{
    /**
     * Register Services
     * 
     * Wird VOR boot() aufgerufen.
     * Hier sollten nur leichte Registrierungen erfolgen.
     * 
     * LARAVEL BEST PRACTICE:
     * - Config sollte hier geladen werden (mergeConfigFrom)
     * - Commands können hier registriert werden
     */
    public function register(): void
    {
        /**
         * Config laden
         * 
         * mergeConfigFrom lädt die Config aus dem Package-Verzeichnis
         * und merged sie mit der Config aus config/ (falls vorhanden).
         * 
         * WICHTIG: Muss in register() sein, nicht in boot()!
         */
        $this->mergeConfigFrom(__DIR__.'/../config/foodalchemist.php', 'foodalchemist');
        
        /**
         * Commands registrieren
         */
        if ($this->app->runningInConsole()) {
            $this->commands([
                \Platform\FoodAlchemist\Console\ImportSliceCommand::class,
                \Platform\FoodAlchemist\Console\ImportMasterCommand::class,
                \Platform\FoodAlchemist\Console\KnowledgeImportCommand::class,
                \Platform\FoodAlchemist\Console\KnowledgeEmbedCommand::class,
                \Platform\FoodAlchemist\Console\EmbedCommand::class,
                \Platform\FoodAlchemist\Console\EmbeddingsMigrateStoreCommand::class,
                \Platform\FoodAlchemist\Console\EmbedEvalCommand::class,
                \Platform\FoodAlchemist\Console\MatcherEvalCommand::class,
                \Platform\FoodAlchemist\Console\GeneratorEvalCommand::class,
                \Platform\FoodAlchemist\Console\TerminologyImportCommand::class,
                \Platform\FoodAlchemist\Console\TeamOnboardingCommand::class,
                \Platform\FoodAlchemist\Console\SignaleDetektorCommand::class,
                \Platform\FoodAlchemist\Console\PairingProjectComputedCommand::class,
                \Platform\FoodAlchemist\Console\InspireImportCommand::class,
                \Platform\FoodAlchemist\Console\PairingWipeErprobtCommand::class,
                \Platform\FoodAlchemist\Console\PairingDropLegacyAnchorsCommand::class,
                \Platform\FoodAlchemist\Console\DataQualityCommand::class,
                \Platform\FoodAlchemist\Console\LeadLaRepickCommand::class,
                \Platform\FoodAlchemist\Console\RecomputeCommand::class,
                \Platform\FoodAlchemist\Console\GpAllergenBackfillCommand::class,
                \Platform\FoodAlchemist\Console\ProcessAnchorGroundCommand::class,
                \Platform\FoodAlchemist\Console\FavoriteGpsCommand::class,
                \Platform\FoodAlchemist\Console\BackfillKapitelZieleCommand::class,
                \Platform\FoodAlchemist\Console\RecipeFindingsCommand::class,
                \Platform\FoodAlchemist\Console\ImportArticlesCommand::class,
                \Platform\FoodAlchemist\Console\MoneyTruthReportCommand::class,
                \Platform\FoodAlchemist\Console\SeedRebateTiersCommand::class,
                \Platform\FoodAlchemist\Console\StepsBackfillCommand::class,
                \Platform\FoodAlchemist\Console\TrendClusterCommand::class,
                \Platform\FoodAlchemist\Console\TrendKonzepteCommand::class,
                \Platform\FoodAlchemist\Console\AnchorsTranslateCsvCommand::class,
                \Platform\FoodAlchemist\Console\PaketeToConceptsCommand::class,
                \Platform\FoodAlchemist\Console\FormatEditionsToSlotsCommand::class,
                \Platform\FoodAlchemist\Console\DynamicPricingMigrationCommand::class,
                \Platform\FoodAlchemist\Console\ConformanceBackfillCommand::class,
            ]);

            $this->planeLaeufe();
        }
    }

    /**
     * Den Qualitäts-Lauf täglich einplanen — **im Modul**, nicht im Host-Kernel.
     *
     * Der Command trug seit seiner Entstehung den Kommentar „Registrierung der Cron-Frequenz
     * ist Host-/Deploy-Sache (Console-Kernel der office.bhg-App)". Dort ist es nie passiert.
     * Die Folge war ein blinder Fleck, der wie ein fehlendes Feature aussah: die 20+ Signal-
     * Typen für Rezepte/Konzepte/Foodbooks und die komplette Zeitreihe existierten im Code,
     * aber nie in den Daten — eine Ampel, die nur leuchtet, wenn jemand eine Shell öffnet.
     *
     * Ein Modul, dessen Kernfunktion von einer Registrierung in einem fremden Repo abhängt,
     * hat diese Funktion nicht. Darum hängt der Plan jetzt hier: mitdeployt, mitversioniert,
     * ohne Zutun von außen. Der Host behält die Hoheit — er kann den Eintrag über
     * `foodalchemist.scheduler.enabled` abschalten.
     *
     * Bewusst NUR der Detektor. `recipe-findings` bleibt manuell: er ruft das Modell pro
     * Rezept, und ein nächtlicher Job, der ungefragt Provider-Geld ausgibt, ist keine
     * Bequemlichkeit, sondern eine unbemerkte Rechnung.
     *
     * 03:20 Uhr: nach dem DB-Snapshot (23:00) und weit vor dem Arbeitstag — der Lauf ist die
     * teuerste lesende Operation des Moduls und soll nicht neben der Nutzung liegen.
     */
    private function planeLaeufe(): void
    {
        if (! config('foodalchemist.scheduler.enabled', true)) {
            return;
        }

        // `booted`, weil der Scheduler beim Registrieren des Providers noch nicht steht.
        $this->app->booted(function () {
            $schedule = $this->app->make(\Illuminate\Console\Scheduling\Schedule::class);

            $schedule->command(\Platform\FoodAlchemist\Console\SignaleDetektorCommand::class)
                ->dailyAt(config('foodalchemist.scheduler.detektor_zeit', '03:20'))
                ->withoutOverlapping()   // ein zweiter Lauf würde in dieselbe Zeitreihe schreiben
                ->onOneServer()          // Hausschreibweise des Hosts (routes/console.php)
                ->runInBackground()
                ->description('FoodAlchemist: Qualitäts-Lauf (Signale, DQ-Kaskade, Zeitreihen-Snapshot, Drift)');

            // Trendradar-Automatisierung: NUR wenn explizit eingeschaltet (Default aus) —
            // der Lauf ruft das Modell pro Trend/Team und gibt sonst ungefragt Provider-Geld aus.
            if (config('foodalchemist.scheduler.trend_konzepte_enabled', false)) {
                $schedule->command(\Platform\FoodAlchemist\Console\TrendKonzepteCommand::class)
                    ->dailyAt(config('foodalchemist.scheduler.trend_konzepte_zeit', '08:00'))
                    ->withoutOverlapping()
                    ->onOneServer()
                    ->runInBackground()
                    ->description('FoodAlchemist: Trendradar → tägliche Konzeptvorschläge aus Top-Trends');
            }
        });
    }

    /**
     * Boot Services
     * 
     * Wird NACH register() aufgerufen.
     * Hier erfolgt die eigentliche Modul-Registrierung.
     * 
     * REIHENFOLGE IST WICHTIG:
     * 1. Config prüfen (bereits in register() geladen)
     * 2. Modul bei PlatformCore registrieren
     * 3. Routes laden (nur wenn Modul registriert)
     * 4. Migrationen, Views, Livewire registrieren
     */
    public function boot(): void
    {
        // M7-10 / D8: STT-Fassade — Binding-Tausch genügt für einen späteren Core-Contract
        $this->app->bind(\Platform\FoodAlchemist\Services\Stt\SttServiceContract::class, fn () => match (config('foodalchemist.stt.provider', 'fake')) {
            'assemblyai' => new \Platform\FoodAlchemist\Services\Stt\AssemblyAiSttService(),
            default => new \Platform\FoodAlchemist\Services\Stt\FakeSttService(),
        });

        // E1 (#507): Embedding-Observer — halten die GP-/Rezept-Recall-Vektoren bei
        // interaktiven Einzeledits synchron (Bulk = foodalchemist:embed). Unbedingt
        // registriert (nicht table-guarded — der Guard liefe zur Boot-Zeit, bevor
        // Migrationen durch sind). Ungefährlich: die Observer feuern nur auf Model-
        // Events (nie während Migrationen), und queueGp/deleteGp no-oppen ohne Provider.
        \Platform\FoodAlchemist\Models\FoodAlchemistGp::observe(\Platform\FoodAlchemist\Observers\GpEmbeddingObserver::class);
        \Platform\FoodAlchemist\Models\FoodAlchemistRecipe::observe(\Platform\FoodAlchemist\Observers\RecipeEmbeddingObserver::class);

        // Spec 15 §5a/§5b: die kleinen Geschwister-Pools (Lieferant/Konzept/Foodbook/Lab-Note).
        \Platform\FoodAlchemist\Models\FoodAlchemistSupplier::observe(\Platform\FoodAlchemist\Observers\SupplierEmbeddingObserver::class);
        \Platform\FoodAlchemist\Models\FoodAlchemistConcept::observe(\Platform\FoodAlchemist\Observers\ConceptEmbeddingObserver::class);
        \Platform\FoodAlchemist\Models\FoodAlchemistFoodbook::observe(\Platform\FoodAlchemist\Observers\FoodbookEmbeddingObserver::class);
        \Platform\FoodAlchemist\Models\FoodAlchemistLabNote::observe(\Platform\FoodAlchemist\Observers\LabNoteEmbeddingObserver::class);

        // RAG-Autoindex A3: Lieferartikel-Einzeledits synchron halten. Der Katalog-Bulk-Import
        // umgeht Eloquent → bleibt der explizite Backfill (foodalchemist:embed --pool=la).
        \Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem::observe(\Platform\FoodAlchemist\Observers\SupplierItemEmbeddingObserver::class);

        // Ausbau (b): Ausgabe-/Container-Pools (Speisekarte/Angebot/Paket/Format) — Einzeledits
        // synchron; Bulk je Pool via foodalchemist:embed --pool=speisekarten|angebote|pakete|formate.
        \Platform\FoodAlchemist\Models\FoodAlchemistSpeisekarte::observe(\Platform\FoodAlchemist\Observers\SpeisekarteEmbeddingObserver::class);
        \Platform\FoodAlchemist\Models\FoodAlchemistAngebot::observe(\Platform\FoodAlchemist\Observers\AngebotEmbeddingObserver::class);
        \Platform\FoodAlchemist\Models\FoodAlchemistPaket::observe(\Platform\FoodAlchemist\Observers\PaketEmbeddingObserver::class);
        \Platform\FoodAlchemist\Models\FoodAlchemistFormat::observe(\Platform\FoodAlchemist\Observers\FormatEmbeddingObserver::class);

        // Embedding-Store-Routing (Runbook 34_Qdrant): FA deklariert selbst, in welchen
        // Store seine Pools gehen — Cores EmbeddingStoreRegistry bleibt entity-agnostisch
        // (der bevorzugte, lose gekoppelte Weg statt zentraler config('embeddings.routing')).
        // INVARIANTE: alle neun foodalchemist_*-Pools teilen sich EINEN Store. candidates()
        // in SemanticRetrievalService übergibt gemischte entity_type-Arrays (z. B. [GP, RECIPE]);
        // search() routet am ersten Typ → nur bei gemeinsamem Store liefern Mixed-Type-Suchen
        // vollständig. Store-Wechsel deshalb immer für ALLE neun gleichzeitig.
        // Kill-Switch/Rollback: FOODALCHEMIST_EMBEDDING_STORE — Default 'mysql' = No-op
        // (Verhalten wie bisher), Flip auf 'qdrant' beim Cutover per ENV (kein Deploy nötig).
        // Geschützt wie die Tool-Registrierung: das Registry-Binding kann auf älteren
        // Core-Versionen / während der Initial-Migration fehlen → nie den Boot brechen.
        if (
            class_exists(\Platform\Core\Services\EmbeddingStoreRegistry::class)
            && $this->app->bound(\Platform\Core\Services\EmbeddingStoreRegistry::class)
        ) {
            try {
                $embeddingStore = (string) config('foodalchemist.embedding_store', 'mysql');
                $embeddingRegistry = $this->app->make(\Platform\Core\Services\EmbeddingStoreRegistry::class);
                foreach ([
                    \Platform\FoodAlchemist\Services\Ai\PoolEmbeddingService::ENTITY_TYPE_GP,
                    \Platform\FoodAlchemist\Services\Ai\PoolEmbeddingService::ENTITY_TYPE_RECIPE,
                    \Platform\FoodAlchemist\Services\Ai\PoolEmbeddingService::ENTITY_TYPE_SUPPLIER,
                    \Platform\FoodAlchemist\Services\Ai\PoolEmbeddingService::ENTITY_TYPE_CONCEPT,
                    \Platform\FoodAlchemist\Services\Ai\PoolEmbeddingService::ENTITY_TYPE_FOODBOOK,
                    \Platform\FoodAlchemist\Services\Ai\PoolEmbeddingService::ENTITY_TYPE_LAB_NOTE,
                    \Platform\FoodAlchemist\Services\Ai\PoolEmbeddingService::ENTITY_TYPE_SUPPLIER_ITEM,
                    \Platform\FoodAlchemist\Services\Ai\KnowledgeEmbeddingService::ENTITY_TYPE,
                    \Platform\FoodAlchemist\Services\Ai\KnowledgeEmbeddingService::ENTITY_TYPE_ANKER,
                ] as $embeddingEntityType) {
                    $embeddingRegistry->route($embeddingEntityType, $embeddingStore);
                }
            } catch (\Throwable $e) {
                // Ohne Registrierung greift Cores Default-Store (config('embeddings.store')).
            }
        }

        // Roadmap Planung-Leitstelle · Et.8 »Worker-Präsenz«: jeder lebende Queue-Worker stempelt einen
        // Herzschlag ({@see \Platform\FoodAlchemist\Services\WorkerHealthService}). `Looping` feuert bei
        // JEDER Worker-Schleife — auch im Leerlauf — und ist damit die ehrliche „ein Worker ist da"-Quelle;
        // `JobProcessing` hält busy-Phasen frisch. Der Stempel ist gedrosselt (10 s) und fail-soft, die
        // Signale sind plattformweit (FA-Jobs teilen die Default-Queue → jeder Worker, der sie leert, zählt).
        \Illuminate\Support\Facades\Event::listen(
            \Illuminate\Queue\Events\Looping::class,
            fn () => $this->app->make(\Platform\FoodAlchemist\Services\WorkerHealthService::class)->heartbeat(),
        );
        \Illuminate\Support\Facades\Event::listen(
            \Illuminate\Queue\Events\JobProcessing::class,
            fn () => $this->app->make(\Platform\FoodAlchemist\Services\WorkerHealthService::class)->heartbeat(),
        );

        /**
         * SCHRITT 1: Modul-Registrierung prüfen
         * 
         * Prüft ob:
         * - Config vorhanden ist
         * - modules-Tabelle existiert (für Datenbank-Registrierung)
         * 
         * Nur wenn beide Bedingungen erfüllt, wird das Modul registriert.
         */
        if (
            config()->has('foodalchemist.routing') &&
            config()->has('foodalchemist.navigation') &&
            Schema::hasTable('modules')
        ) {
            /**
             * Modul bei PlatformCore registrieren
             * 
             * Dies registriert das Modul in:
             * - Der Modul-Registry (für Navigation, Sidebar)
             * - Der Datenbank (modules-Tabelle)
             * 
             * Die Config wird automatisch aus config/foodalchemist.php geladen.
             */
            PlatformCore::registerModule([
                'key'        => 'foodalchemist', // Eindeutiger Schlüssel
                'title'      => 'Food Alchemist', // Anzeige-Name
                'routing'    => config('foodalchemist.routing'),
                'guard'      => config('foodalchemist.guard'),
                'navigation' => config('foodalchemist.navigation'),
                'sidebar'    => config('foodalchemist.sidebar'),
            ]);
        }

        /**
         * SCHRITT 2: Routes laden
         * 
         * Routes werden nur geladen, wenn das Modul erfolgreich registriert wurde.
         * 
         * ModuleRouter::group() erstellt automatisch:
         * - Route-Prefix (aus Config)
         * - Middleware (web, auth, etc.)
         * - Domain-Handling (für Subdomain-Modus)
         */
        if (PlatformCore::getModule('foodalchemist')) {
            /**
             * Web-Routes (authentifiziert)
             * 
             * Standard: requireAuth = true
             * Für öffentliche Routes: requireAuth = false
             */
            ModuleRouter::group('foodalchemist', function () {
                $this->loadRoutesFrom(__DIR__.'/../routes/web.php');
            });
            
            /**
             * API-Routes (optional)
             * 
             * Falls dein Modul API-Endpoints hat:
             * 
             * ModuleRouter::apiGroup('foodalchemist', function () {
             *     $this->loadRoutesFrom(__DIR__.'/../routes/api.php');
             * });
             */
        }

        /**
         * SCHRITT 2b: Öffentliche Routes (kein Auth) — Pairing-Netz-Frontend-Bundle
         *
         * Analog platform-core's _platform/assets/{file}: statische JS-Bundles
         * ohne Team-/Auth-Kontext, daher NICHT über ModuleRouter::group (das
         * hängt 'auth' + Tenancy-Middleware an), sondern direkt registriert.
         */
        \Illuminate\Support\Facades\Route::domain(parse_url(config('app.url'), PHP_URL_HOST))
            ->middleware(['web'])
            ->group(__DIR__.'/../routes/public.php');

        // Pairing-Netz-Bundle-Hash fürs Cache-Busting (analog CoreServiceProvider Tiptap/Workshop/Echo).
        $faManifestPath = __DIR__.'/../resources/dist/manifest.json';
        if (file_exists($faManifestPath)) {
            $faManifest = json_decode(file_get_contents($faManifestPath), true) ?? [];
            config(['platform.fa_pairing_netz_hash' => $faManifest['foodalchemist-pairing-netz.iife.js'] ?? '0']);
        }

        /**
         * SCHRITT 3: Migrationen laden
         * 
         * Lädt alle Migrationen aus database/migrations/
         * Wird automatisch bei `php artisan migrate` ausgeführt.
         */
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');

        /**
         * SCHRITT 4: Config veröffentlichen
         * 
         * Ermöglicht es, die Config in config/foodalchemist.php zu überschreiben.
         * 
         * Publizieren mit:
         * php artisan vendor:publish --tag=config --provider="Platform\FoodAlchemist\FoodAlchemistServiceProvider"
         * 
         * WICHTIG: mergeConfigFrom funktioniert auch OHNE Publizierung!
         */
        $this->publishes([
            __DIR__.'/../config/foodalchemist.php' => config_path('foodalchemist.php'),
        ], 'config');

        /**
         * SCHRITT 5: Views laden
         * 
         * Registriert Views unter dem Namespace 'foodalchemist'
         * 
         * Verwendung in Views:
         * @return view('foodalchemist::livewire.dashboard')
         */
        $this->loadViewsFrom(__DIR__ . '/../resources/views', 'foodalchemist');
        
        /**
         * SCHRITT 6: Livewire Components registrieren
         * 
         * Registriert alle Livewire-Komponenten automatisch.
         * 
         * Pattern:
         * - Datei: src/Livewire/Dashboard.php
         * - Alias: foodalchemist.dashboard
         * 
         * Verwendung:
         * <livewire:foodalchemist.dashboard />
         */
        $this->registerLivewireComponents();

        // M8-02: generische Modul-Policy für die Kern-Models (view = Team-Kette,
        // update/delete = Curate-Gate M1-08 — dieselben Regel-Stellen wie die Services)
        foreach ([
            \Platform\FoodAlchemist\Models\FoodAlchemistGp::class,
            \Platform\FoodAlchemist\Models\FoodAlchemistRecipe::class,
            \Platform\FoodAlchemist\Models\FoodAlchemistSupplier::class,
            \Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem::class,
            \Platform\FoodAlchemist\Models\FoodAlchemistMarkupClass::class,
            \Platform\FoodAlchemist\Models\FoodAlchemistDishClass::class,
            \Platform\FoodAlchemist\Models\FoodAlchemistRecipeCustomerName::class,
            \Platform\FoodAlchemist\Models\FoodAlchemistRecipeRegeneration::class,
            \Platform\FoodAlchemist\Models\FoodAlchemistFoodbook::class,                 // M11-10
            \Platform\FoodAlchemist\Models\FoodAlchemistFoodbookKapitel::class,
            \Platform\FoodAlchemist\Models\FoodAlchemistFoodbookBlock::class,
        ] as $modelClass) {
            \Illuminate\Support\Facades\Gate::policy($modelClass, \Platform\FoodAlchemist\Policies\FoodAlchemistPolicy::class);
        }

        // M8-01: Modul-Tools (ToolContract) — idempotent in die Core-Registry;
        // afterResolving, damit die Registrierung auch greift, wenn der MCP-
        // Server die Registry erst später aufbaut (Auto-Discovery-Pfad variiert)
        if (class_exists(\Platform\Core\Tools\ToolRegistry::class)) {
            $toolHook = function ($registry) {
                foreach ([
                    \Platform\FoodAlchemist\Tools\GpsSearchTool::class,
                    \Platform\FoodAlchemist\Tools\GpsListTool::class,
                    \Platform\FoodAlchemist\Tools\GpsGetTool::class,
                    \Platform\FoodAlchemist\Tools\RecipesSearchTool::class,
                    \Platform\FoodAlchemist\Tools\RecipesListTool::class,
                    \Platform\FoodAlchemist\Tools\RecipesGetTool::class,
                    \Platform\FoodAlchemist\Tools\VerkaufsrezepteSearchTool::class,
                    \Platform\FoodAlchemist\Tools\VerkaufsrezepteListTool::class,
                    \Platform\FoodAlchemist\Tools\FoodbooksGetTool::class,
                    \Platform\FoodAlchemist\Tools\ArtikelSearchTool::class,
                    \Platform\FoodAlchemist\Tools\ArtikelListTool::class,
                    // Spec 13 · S3: Katalog-/Preis-Ingest — Lese-Fläche (Läufe, Lücken, Preis-Deltas)
                    \Platform\FoodAlchemist\Tools\IngestStatusTool::class,
                    // Spec 13 · S3b: die Auslösung (Dateiname aus dem festen Ablage-Ordner, Trockenlauf-Default, scharf als Job)
                    \Platform\FoodAlchemist\Tools\IngestImportTool::class,
                    // Spec 22 · H3c: die allgemeine Lauf-Quittung über ALLE Lauf-Arten (V-055)
                    \Platform\FoodAlchemist\Tools\RunsGetTool::class,
                    // Die beiden Qualitäts-Läufe als Auslöser (2026-07-28). Vorher war der
                    // Detektor NUR über artisan erreichbar und in keinem Scheduler — auf demo
                    // ist er darum nie gelaufen, und 20+ Signal-Typen plus die ganze Zeitreihe
                    // existierten im Code, aber nie in den Daten. Getrennt, weil die Ampel
                    // gratis ist und der Befunde-Batch pro Rezept Provider-Geld kostet.
                    \Platform\FoodAlchemist\Tools\QualityRunPostTool::class,
                    \Platform\FoodAlchemist\Tools\RecipeFindingsRunPostTool::class,
                    \Platform\FoodAlchemist\Tools\SuppliersSearchTool::class,
                    \Platform\FoodAlchemist\Tools\FoodbooksSearchTool::class,
                    \Platform\FoodAlchemist\Tools\LabNotesSearchTool::class,
                    \Platform\FoodAlchemist\Tools\TerminologyListTool::class,
                    \Platform\FoodAlchemist\Tools\TerminologyPostTool::class,
                    \Platform\FoodAlchemist\Tools\RecipeKlassePostTool::class,
                    \Platform\FoodAlchemist\Tools\UiOpenTool::class,
                    // Phase N: Navigation — Seiten-Katalog + Seiten-Navigation (ui.OPEN erweitert auf 13 Typen)
                    \Platform\FoodAlchemist\Tools\UiRoutesTool::class,
                    \Platform\FoodAlchemist\Tools\UiNavigateTool::class,
                    // Phase 0: GP-Ground-Truth (Match + NEW-GP-Staging, LA-First-konform)
                    \Platform\FoodAlchemist\Tools\GpsMatchTool::class,
                    \Platform\FoodAlchemist\Tools\GpProposalsPostTool::class,
                    // 07·M3: LA-First-GP-Mint als MCP-Tool (löst den Ruby-Fall FA-nativ)
                    \Platform\FoodAlchemist\Tools\GpsMintFromLaTool::class,
                    // MCP-Steuerbarkeit D1: GP-Kern-CRUD (team-eigen; §6-Naming im Service,
                    // isOwnedBy-Guard = Web-canCurate; DELETE destruktiv/confirm).
                    \Platform\FoodAlchemist\Tools\GpsPostTool::class,
                    \Platform\FoodAlchemist\Tools\GpsPutTool::class,
                    \Platform\FoodAlchemist\Tools\GpsStatusTool::class,
                    \Platform\FoodAlchemist\Tools\GpsDeleteTool::class,
                    // D1b: Naturaleinheit-Formen (Katalog-Gate) + KI-Anreicherung (Vorschlag→RESOLVE, GL-07)
                    \Platform\FoodAlchemist\Tools\GpFormsPutTool::class,
                    \Platform\FoodAlchemist\Tools\GpFormsDeleteTool::class,
                    \Platform\FoodAlchemist\Tools\GpFormsEstimateTool::class,
                    \Platform\FoodAlchemist\Tools\GpsEnrichTool::class,
                    \Platform\FoodAlchemist\Tools\GpEnrichResolveTool::class,
                    // D1c: LA↔GP-Mapping (link/unlink owner, lock/pin team-overlay), Ersatz-Äquivalenzen,
                    // Platzhalter-GPs, GP-Replace (destruktiv/confirm, team-übergreifender Recompute).
                    \Platform\FoodAlchemist\Tools\GpLaPutTool::class,
                    \Platform\FoodAlchemist\Tools\ComponentEquivalentsPostTool::class,
                    \Platform\FoodAlchemist\Tools\ComponentEquivalentsDeleteTool::class,
                    \Platform\FoodAlchemist\Tools\PlatzhalterPostTool::class,
                    \Platform\FoodAlchemist\Tools\PlatzhalterPutTool::class,
                    \Platform\FoodAlchemist\Tools\PlatzhalterDeleteTool::class,
                    \Platform\FoodAlchemist\Tools\GpsReplaceTool::class,
                    // D2a: Basisrezept-Lifecycle (base-scoped is_sales_recipe=false; delete confirm;
                    // status single/bulk; duplicate visible→owned Kopie; template; recompute).
                    \Platform\FoodAlchemist\Tools\RecipesDeleteTool::class,
                    \Platform\FoodAlchemist\Tools\RecipesStatusTool::class,
                    \Platform\FoodAlchemist\Tools\RecipesDuplicateTool::class,
                    \Platform\FoodAlchemist\Tools\RecipesTemplateToggleTool::class,
                    \Platform\FoodAlchemist\Tools\RecipesRecomputeTool::class,
                    // D2b: Rezept-Assoziationen (Eignung owner; Anker/Pairing team-scoped auf sichtbares
                    // Rezept), Sensorik (KI, owner), Feedback löschen/weiterentwickeln.
                    \Platform\FoodAlchemist\Tools\RecipeEignungPutTool::class,
                    \Platform\FoodAlchemist\Tools\RecipeAnchorsPutTool::class,
                    \Platform\FoodAlchemist\Tools\RecipePairingsPutTool::class,
                    \Platform\FoodAlchemist\Tools\RecipeSensorikPostTool::class,
                    \Platform\FoodAlchemist\Tools\RecipeFeedbackDeleteTool::class,
                    \Platform\FoodAlchemist\Tools\RecipeFeedbackDevelopTool::class,
                    // D2c: Basisrezept aus Vorlage instanziieren (Platzhalter-Slot-Bindung).
                    \Platform\FoodAlchemist\Tools\RecipesInstantiateTool::class,
                    // D2c: grounded Freitext-Revision (recipe.ueberarbeiten; Draft-Quarantäne; Workstream W).
                    \Platform\FoodAlchemist\Tools\RecipesReviseTool::class,
                    // D3a: Verkaufsrezepte (Gerichte) — Read-Detail + CRUD (VK-scoped, Owner-Guard,
                    // Service filtert VK-Whitelist + re-autorisiert FKs; Delete confirm).
                    \Platform\FoodAlchemist\Tools\VerkaufsrezepteGetTool::class,
                    \Platform\FoodAlchemist\Tools\VerkaufsrezeptePostTool::class,
                    \Platform\FoodAlchemist\Tools\VerkaufsrezeptePutTool::class,
                    \Platform\FoodAlchemist\Tools\VerkaufsrezepteDeleteTool::class,
                    \Platform\FoodAlchemist\Tools\VerkaufsrezepteStatusTool::class,
                    // D3b: Darreichungen (Servierformen) je Gericht — anlegen/bearbeiten/löschen/standard + Mengen-Delta.
                    \Platform\FoodAlchemist\Tools\RecipeDarreichungPostTool::class,
                    \Platform\FoodAlchemist\Tools\RecipeDarreichungPutTool::class,
                    \Platform\FoodAlchemist\Tools\RecipeDarreichungDeleteTool::class,
                    \Platform\FoodAlchemist\Tools\RecipeDarreichungStandardTool::class,
                    \Platform\FoodAlchemist\Tools\RecipeDarreichungDeltaPutTool::class,
                    // D3c: Regenerations-Programme + kundenspezifische Marketing-Namen je Gericht.
                    \Platform\FoodAlchemist\Tools\RecipeRegenerationPutTool::class,
                    \Platform\FoodAlchemist\Tools\RecipeRegenerationDeleteTool::class,
                    \Platform\FoodAlchemist\Tools\RecipeRegenerationReorderTool::class,
                    \Platform\FoodAlchemist\Tools\RecipeCustomerNamesPostTool::class,
                    \Platform\FoodAlchemist\Tools\RecipeCustomerNamesDeleteTool::class,
                    // D3d: KI-Rollenverteilung (Vorschlag/accept) + kulinarische Kohärenz/Teller-Heber.
                    \Platform\FoodAlchemist\Tools\RecipeRollenPostTool::class,
                    \Platform\FoodAlchemist\Tools\RecipeCoherencePostTool::class,
                    // D3d: grounded VK-Freitext-Revision (vk.ueberarbeiten; Draft-Quarantäne; Workstream W).
                    \Platform\FoodAlchemist\Tools\VerkaufsrezepteReviseTool::class,
                    // D4a: Lieferanten-Stammdaten CRUD (Service self-gatet; generischer guardOwned).
                    \Platform\FoodAlchemist\Tools\SuppliersPostTool::class,
                    \Platform\FoodAlchemist\Tools\SuppliersPutTool::class,
                    \Platform\FoodAlchemist\Tools\SuppliersStatusTool::class,
                    \Platform\FoodAlchemist\Tools\SuppliersDeactivateTool::class,
                    \Platform\FoodAlchemist\Tools\SupplierConditionsPutTool::class,
                    \Platform\FoodAlchemist\Tools\SupplierContactsPostTool::class,
                    \Platform\FoodAlchemist\Tools\SupplierDocumentsPostTool::class,
                    // D4b: Lieferantenartikel-CRUD + Allergene/Deklarationen/Nährwerte + Preise (Bestand-Services).
                    \Platform\FoodAlchemist\Tools\ArtikelPostTool::class,
                    \Platform\FoodAlchemist\Tools\ArtikelDeleteTool::class,
                    \Platform\FoodAlchemist\Tools\ArtikelDiscontinueTool::class,
                    \Platform\FoodAlchemist\Tools\ArtikelAllergenePutTool::class,
                    \Platform\FoodAlchemist\Tools\ArtikelDeklarationenPutTool::class,
                    \Platform\FoodAlchemist\Tools\ArtikelNaehrwertePutTool::class,
                    \Platform\FoodAlchemist\Tools\ArtikelPreisePostTool::class,
                    \Platform\FoodAlchemist\Tools\ArtikelPreiseDeleteTool::class,
                    // D4b-2: Artikel-/Preis-Edit (neue Service-Methoden SupplierItemService::update / PriceService::updatePrice).
                    \Platform\FoodAlchemist\Tools\ArtikelPutTool::class,
                    \Platform\FoodAlchemist\Tools\ArtikelPreisePutTool::class,
                    // D4c: LA→GP-Matching auslösen + Vorschläge übernehmen/verwerfen.
                    \Platform\FoodAlchemist\Tools\MatchRunTool::class,
                    \Platform\FoodAlchemist\Tools\MatchProposalsPutTool::class,
                    // D4d: Geschirr (Lieferanten + Artikel) — bisher komplett ohne MCP-Abdeckung.
                    \Platform\FoodAlchemist\Tools\GeschirrSuppliersListTool::class,
                    \Platform\FoodAlchemist\Tools\GeschirrSuppliersPostTool::class,
                    \Platform\FoodAlchemist\Tools\GeschirrSuppliersPutTool::class,
                    \Platform\FoodAlchemist\Tools\GeschirrSuppliersDeactivateTool::class,
                    \Platform\FoodAlchemist\Tools\GeschirrItemsListTool::class,
                    \Platform\FoodAlchemist\Tools\GeschirrItemsPostTool::class,
                    \Platform\FoodAlchemist\Tools\GeschirrItemsPutTool::class,
                    \Platform\FoodAlchemist\Tools\GeschirrItemsDeactivateTool::class,
                    // D5a: Concepts-Lifecycle (Update/Status/Duplicate/Recompute/Zielpreis/Sektor/Vorlage).
                    \Platform\FoodAlchemist\Tools\ConceptsPutTool::class,
                    \Platform\FoodAlchemist\Tools\ConceptsStatusTool::class,
                    \Platform\FoodAlchemist\Tools\ConceptsDuplicateTool::class,
                    \Platform\FoodAlchemist\Tools\ConceptsRecomputeTool::class,
                    \Platform\FoodAlchemist\Tools\ConceptsPriceTargetTool::class,
                    \Platform\FoodAlchemist\Tools\ConceptsSektorTool::class,
                    \Platform\FoodAlchemist\Tools\ConceptsTemplateSaveTool::class,
                    \Platform\FoodAlchemist\Tools\ConceptsTemplateForkTool::class,
                    // #513 Tier 1: Grammaturen-Rechner (Bäckerprozent/Extraprozent/Brining/Bloom)
                    \Platform\FoodAlchemist\Tools\ProportionCalcTool::class,
                    // #513: %→Gramm-Rückschreiben (Batch-Skalierung + Einzel-Zutat-Edit, write)
                    \Platform\FoodAlchemist\Tools\ProportionApplyTool::class,
                    // #513 Tier 1 Punkt 2: Kerntemperatur-Referenz (Qualitäts-Zielwerte, weich)
                    \Platform\FoodAlchemist\Tools\ReferenceGetTool::class,
                    // Phase K: Wissen + Pairing-Graph für externe LLM-Clients
                    \Platform\FoodAlchemist\Tools\KnowledgeSearchTool::class,
                    \Platform\FoodAlchemist\Tools\KnowledgeListTool::class,
                    \Platform\FoodAlchemist\Tools\KnowledgeGetTool::class,
                    \Platform\FoodAlchemist\Tools\KnowledgeCreateTool::class,
                    \Platform\FoodAlchemist\Tools\KnowledgeUpdateTool::class,
                    // D12: Wissen-Löschen/Alias (3 neue Service-Methoden) + Canvas-Einträge + Controlling
                    // + Trendradar + Präsentations-Designs. match_proposals.RESOLVE = bereits match_proposals.PUT.
                    \Platform\FoodAlchemist\Tools\KnowledgeDeleteTool::class,
                    \Platform\FoodAlchemist\Tools\KnowledgeAliasTool::class,
                    \Platform\FoodAlchemist\Tools\CanvasEntryAddTool::class,
                    \Platform\FoodAlchemist\Tools\CanvasEntryRemoveTool::class,
                    \Platform\FoodAlchemist\Tools\SalesFactsMapTool::class,
                    \Platform\FoodAlchemist\Tools\TrendradarImportTool::class,
                    \Platform\FoodAlchemist\Tools\PresentationDesignsDuplicateTool::class,
                    \Platform\FoodAlchemist\Tools\PresentationDesignsGenerateCssTool::class,
                    \Platform\FoodAlchemist\Tools\KnowledgeBindTool::class,
                    \Platform\FoodAlchemist\Tools\KnowledgeUnbindTool::class,
                    \Platform\FoodAlchemist\Tools\PairingsGetTool::class,
                    \Platform\FoodAlchemist\Tools\PairingsSuggestTool::class,
                    \Platform\FoodAlchemist\Tools\SubstitutionSuggestTool::class,
                    \Platform\FoodAlchemist\Tools\DishReverseTool::class,
                    \Platform\FoodAlchemist\Tools\SurplusSuggestTool::class,
                    \Platform\FoodAlchemist\Tools\KnowledgeHypothesizeTool::class,
                    \Platform\FoodAlchemist\Tools\LabNotesPostTool::class,
                    \Platform\FoodAlchemist\Tools\VkSnapshotsGetTool::class,
                    \Platform\FoodAlchemist\Tools\VkSnapshotsReleaseTool::class,
                    // Spec 43: Präsentation (digitales Kundenbuch) + Design-Struktur-Builder
                    \Platform\FoodAlchemist\Tools\FoodbookPresentationPublishTool::class,
                    \Platform\FoodAlchemist\Tools\FoodbookPresentationWithdrawTool::class,
                    \Platform\FoodAlchemist\Tools\FoodbookPresentationGetTool::class,
                    \Platform\FoodAlchemist\Tools\PresentationDesignsPostTool::class,
                    \Platform\FoodAlchemist\Tools\PresentationDesignsPutTool::class,
                    \Platform\FoodAlchemist\Tools\PresentationDesignsGetTool::class,
                    \Platform\FoodAlchemist\Tools\PresentationDesignsSearchTool::class,
                    \Platform\FoodAlchemist\Tools\PresentationDesignsDeleteTool::class,
                    \Platform\FoodAlchemist\Tools\SpeisekartePresentationPublishTool::class,
                    \Platform\FoodAlchemist\Tools\SpeisekartePresentationWithdrawTool::class,
                    \Platform\FoodAlchemist\Tools\SpeisekartePresentationGetTool::class,
                    \Platform\FoodAlchemist\Tools\SpeiseplanPresentationPublishTool::class,
                    \Platform\FoodAlchemist\Tools\SpeiseplanPresentationWithdrawTool::class,
                    \Platform\FoodAlchemist\Tools\SpeiseplanPresentationGetTool::class,
                    \Platform\FoodAlchemist\Tools\SuppliersGetTool::class,
                    \Platform\FoodAlchemist\Tools\SuppliersPutTool::class,
                    \Platform\FoodAlchemist\Tools\SupplierAgreementsPostTool::class,
                    \Platform\FoodAlchemist\Tools\SuppliersVolumeTool::class,
                    // Einkauf E1: Rückvergütungs-Staffeln (strukturiert, team-scoped)
                    \Platform\FoodAlchemist\Tools\SupplierRebateGetTool::class,
                    \Platform\FoodAlchemist\Tools\SupplierRebatePutTool::class,
                    // Einkauf E5: Preisvergleich · Optimierung · Spend · Ausreißer (read, Lockstep)
                    \Platform\FoodAlchemist\Tools\EinkaufPreisvergleichGetTool::class,
                    \Platform\FoodAlchemist\Tools\EinkaufOptimierungGetTool::class,
                    \Platform\FoodAlchemist\Tools\EinkaufSpendGetTool::class,
                    \Platform\FoodAlchemist\Tools\EinkaufAnomalienGetTool::class,
                    // Spec 32 C3: die Erlösseite — Verkaufsjournal lesen, CSV einlesen
                    // (Trockenlauf per Default), Menu-Engineering-Matrix.
                    \Platform\FoodAlchemist\Tools\SalesFactsGetTool::class,
                    \Platform\FoodAlchemist\Tools\SalesImportPostTool::class,
                    \Platform\FoodAlchemist\Tools\MenuEngineeringGetTool::class,
                    // Spec 33 P8: Portfolio-Steuerung — wer fährt gerade was (inkl. Konflikte,
                    // Lücken, Nicht-Zugeordnete) und was bringt eine laufende Ausgabe.
                    \Platform\FoodAlchemist\Tools\PortfolioGetTool::class,
                    \Platform\FoodAlchemist\Tools\PortfolioPromotionGetTool::class,
                    \Platform\FoodAlchemist\Tools\GpLeadGetTool::class,
                    \Platform\FoodAlchemist\Tools\GpLeadPutTool::class,
                    // 05·P5: Prozessanker deterministisch erden (MCP-Lockstep)
                    \Platform\FoodAlchemist\Tools\ProcessAnchorsGroundTool::class,
                    // 06·H2: Convenience-Highlights kuratieren (MCP-Lockstep)
                    \Platform\FoodAlchemist\Tools\FavoritesGetTool::class,
                    \Platform\FoodAlchemist\Tools\FavoritesPutTool::class,
                    // Phase A: Rezept-Schreibkaskade (Weg-A-Ausnahme, Draft-Quarantäne)
                    \Platform\FoodAlchemist\Tools\RecipesPostTool::class,
                    \Platform\FoodAlchemist\Tools\RecipesGenerateTool::class, // 03·L5 (Lockstep-Schuld aus #505)
                    \Platform\FoodAlchemist\Tools\RecipesExtractTool::class,  // Rezept-Import (Rohtext/Foto-Umweg → geerdeter Draft)
                    \Platform\FoodAlchemist\Tools\RecipesReviewTool::class,   // 03·L6 Copilot-Pruefpass (read-only)
                    // 21·S5a: die Ablage der Copilot-Befunde (Batch) — lesen ohne Egress + entscheiden
                    \Platform\FoodAlchemist\Tools\RecipeFindingsSearchTool::class,
                    \Platform\FoodAlchemist\Tools\RecipeFindingsPutTool::class,
                    \Platform\FoodAlchemist\Tools\RecipesPutTool::class,
                    \Platform\FoodAlchemist\Tools\RecipeIngredientsPutTool::class,
                    \Platform\FoodAlchemist\Tools\RecipeStepsGetTool::class,      // Spec 27
                    \Platform\FoodAlchemist\Tools\RecipeStepsPutTool::class,      // Spec 27
                    // Phase B: Foodbook-Kaskade (nativ FA, Draft-only)
                    \Platform\FoodAlchemist\Tools\FoodbooksPostTool::class,
                    \Platform\FoodAlchemist\Tools\FoodbookKapitelPostTool::class,
                    \Platform\FoodAlchemist\Tools\FoodbookKapitelPutTool::class,
                    \Platform\FoodAlchemist\Tools\FoodbookBlocksPostTool::class,
                    \Platform\FoodAlchemist\Tools\FoodbookBlocksDeleteTool::class,
                    // D7: Foodbook-Vervollständigung (List/Put/Status/Branding/Customer-Link +
                    // Kapitel-Bausteine + Block-Edits + Kundentext-Vorschläge, W-geerdet). Kein Buch-Delete.
                    \Platform\FoodAlchemist\Tools\FoodbooksListTool::class,
                    \Platform\FoodAlchemist\Tools\FoodbooksPutTool::class,
                    \Platform\FoodAlchemist\Tools\FoodbooksStatusTool::class,
                    \Platform\FoodAlchemist\Tools\FoodbooksBrandingTool::class,
                    \Platform\FoodAlchemist\Tools\FoodbooksCustomerLinkTool::class,
                    \Platform\FoodAlchemist\Tools\FoodbookKapitelDeleteTool::class,
                    \Platform\FoodAlchemist\Tools\FoodbookKapitelReorderTool::class,
                    \Platform\FoodAlchemist\Tools\FoodbookKapitelMoveTool::class,
                    \Platform\FoodAlchemist\Tools\FoodbookKapitelWordingTool::class,
                    \Platform\FoodAlchemist\Tools\FoodbookBlocksPutTool::class,
                    \Platform\FoodAlchemist\Tools\FoodbookBlocksReorderTool::class,
                    \Platform\FoodAlchemist\Tools\FoodbookBlocksVariantGroupTool::class,
                    \Platform\FoodAlchemist\Tools\FoodbookKundentextTool::class,
                    \Platform\FoodAlchemist\Tools\FoodbookKapitelKundentextTool::class,
                    // Format-Umbau F5: Format als Kapitel buchen (live concept_ref-Blöcke, kein Sonderweg)
                    \Platform\FoodAlchemist\Tools\FoodbookInsertFormatTool::class,
                    // Spec 19 E3.5: Zielgruppen-Vokabular (Leitstelle-Leseflaeche + Anlage)
                    \Platform\FoodAlchemist\Tools\ZielgruppenGetTool::class,
                    \Platform\FoodAlchemist\Tools\ZielgruppenPostTool::class,
                    // Spec 19 E5.4: Leitstelle-Leseflaeche (Checkliste + Kapitel-Matrix + Kapitel-Stand)
                    \Platform\FoodAlchemist\Tools\LeitstelleGetTool::class,
                    // Spec 19 E6.5: Kreativ-Skizzen (Entwuerfe) — GET gruppiert, POST/PUT Skizze+Paket
                    \Platform\FoodAlchemist\Tools\KapitelIdeenGetTool::class,
                    \Platform\FoodAlchemist\Tools\KapitelIdeenPostTool::class,
                    \Platform\FoodAlchemist\Tools\KapitelIdeenPutTool::class,
                    // Planungs-/Kreativ-Ebene (Doppel-Diamant): Session-CRUD. „Go" bleibt human-only (kein MCP-Trigger).
                    \Platform\FoodAlchemist\Tools\PlanungSessionGetTool::class,
                    \Platform\FoodAlchemist\Tools\PlanungSessionPostTool::class,
                    \Platform\FoodAlchemist\Tools\PlanungSessionPutTool::class,
                    // Etappe 9 (Planung-Leitstelle): Kaskaden-Status headless lesen — READ-ONLY.
                    \Platform\FoodAlchemist\Tools\PlanungKaskadeStatusGetTool::class,
                    // Etappe 9 · Slice 2: Kaskaden-START (Go) + FREIGABE (Gate 2) via MCP — WRITE. Der
                    // Kaskaden-Trigger via MCP ist bewusst freigegeben (Entscheidung 2026-08-17); Schutz =
                    // Tenancy (Start isOwnedBy Session, Freigabe ownedStep). Nicht mehr human-only.
                    \Platform\FoodAlchemist\Tools\PlanungKaskadeStartPostTool::class,
                    \Platform\FoodAlchemist\Tools\FoodbookPlanFromBriefTool::class,  // Spec 42 F5: Foodbook aus Brief (vollkaskade owner=foodbook)
                    \Platform\FoodAlchemist\Tools\PlanungKaskadeFreigabePostTool::class,
                    // Spec 19 E7.6: Kapitel-Go „Anlegen" — READ-ONLY (Stempel-Vorschau + Trockenlauf + Anlage-Stand; Go selbst human-only, kein MCP-Trigger)
                    \Platform\FoodAlchemist\Tools\KapitelFreigabeGetTool::class,
                    // Spec 19 E9: Pairing-Inspiration der Kreativ-Phase — READ-ONLY (Aroma-Nachbarn je Modus, abstrakt/geerdet)
                    \Platform\FoodAlchemist\Tools\PairingInspirationGetTool::class,
                    // Phase C: Concepter, Angebote, Kalkulation, Settings, Signale, Food DNA, Speiseplan
                    \Platform\FoodAlchemist\Tools\ConceptsSearchTool::class,
                    \Platform\FoodAlchemist\Tools\ConceptsListTool::class,
                    \Platform\FoodAlchemist\Tools\ConceptsGetTool::class,
                    \Platform\FoodAlchemist\Tools\ConceptsPostTool::class,
                    \Platform\FoodAlchemist\Tools\ConceptsDeleteTool::class,
                    \Platform\FoodAlchemist\Tools\ConceptSlotsPostTool::class,
                    // D5b: Concept-Slots/Blocks/Varianten/Paket (Editor-Parität)
                    \Platform\FoodAlchemist\Tools\ConceptSlotsPutTool::class,
                    \Platform\FoodAlchemist\Tools\ConceptSlotsDeleteTool::class,
                    \Platform\FoodAlchemist\Tools\ConceptSlotsReorderTool::class,
                    \Platform\FoodAlchemist\Tools\ConceptSlotsGeschirrTool::class,
                    \Platform\FoodAlchemist\Tools\ConceptSlotsDarreichungTool::class,
                    \Platform\FoodAlchemist\Tools\ConceptBlocksPostTool::class,
                    \Platform\FoodAlchemist\Tools\ConceptBlocksPutTool::class,
                    \Platform\FoodAlchemist\Tools\ConceptSlotVarianteSwapTool::class,
                    \Platform\FoodAlchemist\Tools\ConceptSlotVarianteResetTool::class,
                    \Platform\FoodAlchemist\Tools\ConceptPaketBuildTool::class,
                    // D5c: Konzept-Kategorien + Wording (W-Grounding) + Kohäsion-Read
                    \Platform\FoodAlchemist\Tools\ConceptCategoriesPostTool::class,
                    \Platform\FoodAlchemist\Tools\ConceptCategoriesPutTool::class,
                    \Platform\FoodAlchemist\Tools\ConceptCategoriesDeleteTool::class,
                    \Platform\FoodAlchemist\Tools\ConceptWordingGenerateTool::class,
                    \Platform\FoodAlchemist\Tools\ConceptsCohesionTool::class,
                    // D5d: Pakete-Ressource (physische Pakete, spiegelt Livewire\Pakete\Index) + Positionen
                    \Platform\FoodAlchemist\Tools\PaketeGetTool::class,
                    \Platform\FoodAlchemist\Tools\PaketeListTool::class,
                    \Platform\FoodAlchemist\Tools\PaketeSearchTool::class,
                    \Platform\FoodAlchemist\Tools\PaketePostTool::class,
                    \Platform\FoodAlchemist\Tools\PaketePutTool::class,
                    \Platform\FoodAlchemist\Tools\PaketeDeleteTool::class,
                    \Platform\FoodAlchemist\Tools\PaketeDuplicateTool::class,
                    \Platform\FoodAlchemist\Tools\PaketeRecomputeTool::class,
                    \Platform\FoodAlchemist\Tools\PaketGerichteSetTool::class,
                    \Platform\FoodAlchemist\Tools\PaketGerichteMengeTool::class,
                    \Platform\FoodAlchemist\Tools\PaketGerichteGeschirrTool::class,
                    \Platform\FoodAlchemist\Tools\PaketGerichteReorderTool::class,
                    // Format-Modul: Marken-/Themen-Container über den Konzepten (Editionen), Draft-on-create
                    \Platform\FoodAlchemist\Tools\FormatsListTool::class,
                    \Platform\FoodAlchemist\Tools\FormatsSearchTool::class,
                    \Platform\FoodAlchemist\Tools\FormatsGetTool::class,
                    \Platform\FoodAlchemist\Tools\FormatsPostTool::class,
                    \Platform\FoodAlchemist\Tools\FormatsPutTool::class,
                    \Platform\FoodAlchemist\Tools\FormatsDeleteTool::class,
                    \Platform\FoodAlchemist\Tools\FormatEditionsPostTool::class,
                    \Platform\FoodAlchemist\Tools\FormatEditionsDeleteTool::class,
                    // D6: Format-Status + Aufbau-Slots/Blöcke + Bildwelt (Binär-Upload deferred)
                    \Platform\FoodAlchemist\Tools\FormatsStatusTool::class,
                    \Platform\FoodAlchemist\Tools\FormatSlotsReorderTool::class,
                    \Platform\FoodAlchemist\Tools\FormatSlotsMoveTool::class,
                    \Platform\FoodAlchemist\Tools\FormatSlotsWordingTool::class,
                    \Platform\FoodAlchemist\Tools\FormatBlocksPostTool::class,
                    \Platform\FoodAlchemist\Tools\FormatBlocksPutTool::class,
                    \Platform\FoodAlchemist\Tools\FormatImagesHeroTool::class,
                    \Platform\FoodAlchemist\Tools\FormatImagesCaptionTool::class,
                    \Platform\FoodAlchemist\Tools\FormatImagesReorderTool::class,
                    \Platform\FoodAlchemist\Tools\FormatImagesClearTool::class,
                    \Platform\FoodAlchemist\Tools\AngeboteSearchTool::class,
                    \Platform\FoodAlchemist\Tools\AngeboteListTool::class,
                    \Platform\FoodAlchemist\Tools\AngeboteGetTool::class,
                    \Platform\FoodAlchemist\Tools\AngebotePostTool::class,
                    // D10: Angebote-Vervollständigung (Put/Delete(confirm)/Status/Customer-Link/Recompute
                    // + Menü-CRUD + Concept-Referenzen). angebote.DELETE bleibt (frühe Entwürfe).
                    \Platform\FoodAlchemist\Tools\AngebotePutTool::class,
                    \Platform\FoodAlchemist\Tools\AngeboteDeleteTool::class,
                    \Platform\FoodAlchemist\Tools\AngeboteStatusTool::class,
                    \Platform\FoodAlchemist\Tools\AngeboteCustomerLinkTool::class,
                    \Platform\FoodAlchemist\Tools\AngeboteRecomputeTool::class,
                    \Platform\FoodAlchemist\Tools\AngebotMenuePostTool::class,
                    \Platform\FoodAlchemist\Tools\AngebotMenuePromoteTool::class,
                    \Platform\FoodAlchemist\Tools\AngebotMenueDeleteTool::class,
                    \Platform\FoodAlchemist\Tools\AngebotConceptRefPostTool::class,
                    \Platform\FoodAlchemist\Tools\AngebotConceptRefDeleteTool::class,
                    \Platform\FoodAlchemist\Tools\KalkulationGetTool::class,
                    \Platform\FoodAlchemist\Tools\SimulationPostTool::class,
                    // R7.1: Operative Planungs-Blätter (read-only, rein rechnend)
                    \Platform\FoodAlchemist\Tools\ProduktionsblattGetTool::class,
                    \Platform\FoodAlchemist\Tools\BestellvorschlagGetTool::class,
                    \Platform\FoodAlchemist\Tools\EinkaufslisteGetTool::class,
                    // Spec 17/S2: Bestellschienen (mini-WaWi, N-Track) — MCP im Lockstep
                    \Platform\FoodAlchemist\Tools\OrdersGetTool::class,
                    \Platform\FoodAlchemist\Tools\OrdersAddNeedTool::class,
                    \Platform\FoodAlchemist\Tools\OrdersSetStatusTool::class,
                    \Platform\FoodAlchemist\Tools\OrdersUpdateLineTool::class,
                    \Platform\FoodAlchemist\Tools\OrdersUpdateTool::class,
                    // Spec 20 · E2: Direktbestellung (manueller Artikel + neue Schiene)
                    \Platform\FoodAlchemist\Tools\OrdersCreateTool::class,
                    \Platform\FoodAlchemist\Tools\OrdersAddLineTool::class,
                    // Spec 20 · E3: „Neu quellen" (Preisstrategie-Switch je Schiene)
                    \Platform\FoodAlchemist\Tools\OrdersResourceTool::class,
                    // D11: Bestell-Belegfacetten (Rechnung/Zahlung/Freigabe/Lieferantenbest./Wareneingang/
                    // Reklamation) + Zeilen-Ops + Versand (outward) + Produktionsplaner + Anproduktion.
                    \Platform\FoodAlchemist\Tools\OrdersUpdateInvoiceTool::class,
                    \Platform\FoodAlchemist\Tools\OrdersUpdatePaymentTool::class,
                    \Platform\FoodAlchemist\Tools\OrdersUpdateApprovalTool::class,
                    \Platform\FoodAlchemist\Tools\OrdersConfirmSupplierTool::class,
                    \Platform\FoodAlchemist\Tools\OrdersReceiptTool::class,
                    \Platform\FoodAlchemist\Tools\OrdersClaimTool::class,
                    \Platform\FoodAlchemist\Tools\OrdersRemoveLineTool::class,
                    \Platform\FoodAlchemist\Tools\OrdersSwitchArticleTool::class,
                    \Platform\FoodAlchemist\Tools\OrdersDispatchTool::class,
                    \Platform\FoodAlchemist\Tools\ProductionPlanSuggestTool::class,
                    \Platform\FoodAlchemist\Tools\ProductionPlanApplyTool::class,
                    \Platform\FoodAlchemist\Tools\SpeiseplanAnproduktionTool::class,
                    \Platform\FoodAlchemist\Tools\AngeboteAnproduktionTool::class,
                    // Produktionsaufträge geben Bedarf frei; Bestellungen entstehen im Bestellwesen.
                    \Platform\FoodAlchemist\Tools\ProductionOrdersGetTool::class,
                    \Platform\FoodAlchemist\Tools\ProductionOrdersAddTargetTool::class,
                    \Platform\FoodAlchemist\Tools\ProductionOrdersRemoveTargetTool::class,
                    \Platform\FoodAlchemist\Tools\ProductionOrdersUpdateTool::class,
                    \Platform\FoodAlchemist\Tools\ProductionOrdersSetStatusTool::class,
                    \Platform\FoodAlchemist\Tools\ProductionOrdersUpdateLineTool::class,
                    \Platform\FoodAlchemist\Tools\ProductionOrdersReleaseDemandTool::class,
                    // Spec 30: Zeilen-Eingriff, Zuteilung, Abarbeiten, Löschen — MCP im Lockstep
                    \Platform\FoodAlchemist\Tools\ProductionOrdersLineOverrideTool::class,
                    \Platform\FoodAlchemist\Tools\ProductionOrdersLineAssignTool::class,
                    \Platform\FoodAlchemist\Tools\ProductionOrdersLineStatusTool::class,
                    \Platform\FoodAlchemist\Tools\ProductionOrdersLineBlockTool::class,
                    \Platform\FoodAlchemist\Tools\ProductionOrdersLineUnblockTool::class,
                    \Platform\FoodAlchemist\Tools\ProductionOrdersStartTool::class,
                    \Platform\FoodAlchemist\Tools\ProductionOrdersFinishTool::class,
                    \Platform\FoodAlchemist\Tools\ProductionOrdersDeleteTool::class,
                    // R2.6: Praxis-Feedback (Küche/Kunde/Event) je Gericht/Rezept
                    \Platform\FoodAlchemist\Tools\FeedbackSearchTool::class,
                    \Platform\FoodAlchemist\Tools\FeedbackPostTool::class,
                    // R2.7: Portfolio-Benchmark (BHG-intern, read-only)
                    \Platform\FoodAlchemist\Tools\BenchmarkGetTool::class,
                    \Platform\FoodAlchemist\Tools\SettingsGetTool::class,
                    // MCP-Steuerbarkeit Phase 0: Team-Skalar-Config schreiben (sichere Config;
                    // Allow-List, nur eigene Team-Zeile). Vokabular/Taxonomie bleiben eigene Tools/UI.
                    \Platform\FoodAlchemist\Tools\TeamSettingsPutTool::class,
                    // D13: Vokabular/Taxonomie SAFE-additiv (POST/PUT/TOGGLE/REORDER, KEIN Delete;
                    // VocabularyService self-guardt globale/kanonische Zeilen read-only).
                    \Platform\FoodAlchemist\Tools\VocabEinheitenPostTool::class,
                    \Platform\FoodAlchemist\Tools\VocabEinheitenPutTool::class,
                    \Platform\FoodAlchemist\Tools\VocabEinheitenToggleTool::class,
                    \Platform\FoodAlchemist\Tools\VocabWarengruppenPostTool::class,
                    \Platform\FoodAlchemist\Tools\VocabWarengruppenPutTool::class,
                    \Platform\FoodAlchemist\Tools\VocabWarengruppenReorderTool::class,
                    \Platform\FoodAlchemist\Tools\VocabSubkategorienPostTool::class,
                    \Platform\FoodAlchemist\Tools\VocabSubkategorienPutTool::class,
                    \Platform\FoodAlchemist\Tools\VocabSubkategorienReorderTool::class,
                    \Platform\FoodAlchemist\Tools\VocabRecipeMaingroupsPostTool::class,
                    \Platform\FoodAlchemist\Tools\VocabRecipeMaingroupsPutTool::class,
                    \Platform\FoodAlchemist\Tools\VocabRecipeMaingroupsReorderTool::class,
                    \Platform\FoodAlchemist\Tools\VocabDishMaingroupsPostTool::class,
                    \Platform\FoodAlchemist\Tools\VocabDishMaingroupsPutTool::class,
                    \Platform\FoodAlchemist\Tools\VocabDishMaingroupsReorderTool::class,
                    \Platform\FoodAlchemist\Tools\SignaleSearchTool::class,
                    \Platform\FoodAlchemist\Tools\SignaleListTool::class,
                    \Platform\FoodAlchemist\Tools\SignalePutTool::class,
                    // „KI erledigen lassen" (Auto-Fix + Assistenz) — Lockstep zum Cockpit-Knopf
                    \Platform\FoodAlchemist\Tools\SignaleFixTool::class,
                    // Spec 21 E1: Trend statt Momentaufnahme („wird es besser oder schlechter?")
                    \Platform\FoodAlchemist\Tools\SignalTrendGetTool::class,
                    // Spec 21 E2: Rausch-Guard — Zustands-Zeilen lesen + Policy setzen (menschlich getriggert)
                    \Platform\FoodAlchemist\Tools\SignalPoliciesGetTool::class,
                    \Platform\FoodAlchemist\Tools\SignalPolicyPutTool::class,
                    // Spec 21 Punkt 5: Ursachen-Kette („warum ist DIESES Objekt betroffen?")
                    \Platform\FoodAlchemist\Tools\SignalCausesGetTool::class,
                    \Platform\FoodAlchemist\Tools\CanvasGetTool::class,
                    \Platform\FoodAlchemist\Tools\CanvasPutTool::class,
                    // R4.1–R4.3 Planungs-Gerüst + Coverage + Phase — MCP im Lockstep
                    \Platform\FoodAlchemist\Tools\PlanningGetTool::class,
                    \Platform\FoodAlchemist\Tools\PlanningPutTool::class,
                    \Platform\FoodAlchemist\Tools\CoverageGetTool::class,
                    \Platform\FoodAlchemist\Tools\PhasePutTool::class,
                    \Platform\FoodAlchemist\Tools\ConceptSlotVariantePostTool::class, // R4.4
                    \Platform\FoodAlchemist\Tools\ConceptsGenerateTool::class, // R6.1
                    // 12·S2b (R2.4): Vorschau (read-only) + explizite Übernahme als draft
                    \Platform\FoodAlchemist\Tools\AssemblierungPostTool::class,
                    \Platform\FoodAlchemist\Tools\AssemblierungApplyTool::class,
                    \Platform\FoodAlchemist\Tools\SpeiseplaenePostTool::class,
                    \Platform\FoodAlchemist\Tools\SpeiseplanEintraegePostTool::class,
                    // D9: Speiseplan-Vervollständigung (Read-Lücke geschlossen + Stamm/Status/Branding/CRM
                    // + Linien-CRUD + Eintrag-Bausteine + Ausrollen). Kein Plan-Delete (Status archiviert).
                    \Platform\FoodAlchemist\Tools\SpeiseplaeneGetTool::class,
                    \Platform\FoodAlchemist\Tools\SpeiseplaeneListTool::class,
                    \Platform\FoodAlchemist\Tools\SpeiseplaenePutTool::class,
                    \Platform\FoodAlchemist\Tools\SpeiseplaeneStatusTool::class,
                    \Platform\FoodAlchemist\Tools\SpeiseplanBrandingTool::class,
                    \Platform\FoodAlchemist\Tools\SpeiseplanCustomerLinkTool::class,
                    \Platform\FoodAlchemist\Tools\SpeiseplanLinienPostTool::class,
                    \Platform\FoodAlchemist\Tools\SpeiseplanLinienPutTool::class,
                    \Platform\FoodAlchemist\Tools\SpeiseplanLinienDeleteTool::class,
                    \Platform\FoodAlchemist\Tools\SpeiseplanLinienMoveTool::class,
                    \Platform\FoodAlchemist\Tools\SpeiseplanEintraegeDeleteTool::class,
                    \Platform\FoodAlchemist\Tools\SpeiseplanEintraegePaxTool::class,
                    \Platform\FoodAlchemist\Tools\SpeiseplanAusrollenTool::class,
                    // Speisekarte (Gastro-à-la-carte) — MCP-Lockstep
                    \Platform\FoodAlchemist\Tools\SpeisekartenPostTool::class,
                    \Platform\FoodAlchemist\Tools\SpeisekartenPutTool::class,
                    \Platform\FoodAlchemist\Tools\SpeisekarteRubrikPostTool::class,
                    \Platform\FoodAlchemist\Tools\SpeisekarteRubrikPutTool::class,
                    \Platform\FoodAlchemist\Tools\SpeisekartePositionenPostTool::class,
                    \Platform\FoodAlchemist\Tools\SpeisekarteInsertFormatTool::class,  // Format-Umbau F5: Format als Rubrik (live menue_ref)
                    \Platform\FoodAlchemist\Tools\SpeisekartePositionenDeleteTool::class,
                    \Platform\FoodAlchemist\Tools\SpeisekartePositionenMoveTool::class,
                    \Platform\FoodAlchemist\Tools\SpeisekartePositionenReorderTool::class,
                    \Platform\FoodAlchemist\Tools\SpeisekarteRubrikReorderTool::class,
                    \Platform\FoodAlchemist\Tools\SpeisekartenDuplicateTool::class,
                    \Platform\FoodAlchemist\Tools\SpeisekartenSearchTool::class,
                    \Platform\FoodAlchemist\Tools\SpeisekartenGetTool::class,
                    // D8: Speisekarte-Vervollständigung (List/Status/Branding/Customer-Link + Rubrik-Bausteine
                    // + Position-Edit + Wording). Kein Karten-Delete (Status archiviert). GET auf Plural vereinheitlicht.
                    \Platform\FoodAlchemist\Tools\SpeisekartenListTool::class,
                    \Platform\FoodAlchemist\Tools\SpeisekartenStatusTool::class,
                    \Platform\FoodAlchemist\Tools\SpeisekarteBrandingTool::class,
                    \Platform\FoodAlchemist\Tools\SpeisekarteCustomerLinkTool::class,
                    \Platform\FoodAlchemist\Tools\SpeisekarteRubrikDeleteTool::class,
                    \Platform\FoodAlchemist\Tools\SpeisekarteRubrikMoveTool::class,
                    \Platform\FoodAlchemist\Tools\SpeisekartePositionenPutTool::class,
                    \Platform\FoodAlchemist\Tools\SpeisekarteWordingTool::class,
                    \Platform\FoodAlchemist\Tools\SpeisekarteLeitstelleGetTool::class,
                    \Platform\FoodAlchemist\Tools\KnowledgeRoutingsGetTool::class,
                    \Platform\FoodAlchemist\Tools\KnowledgeRoutingsPutTool::class,
                    \Platform\FoodAlchemist\Tools\KnowledgeCategoriesGetTool::class,
                    \Platform\FoodAlchemist\Tools\KnowledgeCategoriesPostTool::class,
                    \Platform\FoodAlchemist\Tools\KnowledgeSetActiveTool::class,
                    // Schnellstart-Vorlagen (Brief-Templates) — kunden-anlegbare Planung-Startpunkte (CRUD)
                    \Platform\FoodAlchemist\Tools\BriefTemplatesListTool::class,
                    \Platform\FoodAlchemist\Tools\BriefTemplatesPostTool::class,
                    \Platform\FoodAlchemist\Tools\BriefTemplatesPutTool::class,
                    \Platform\FoodAlchemist\Tools\BriefTemplatesDeleteTool::class,
                ] as $toolClass) {
                    try {
                        $tool = new $toolClass();
                        if (! $registry->has($tool->getName())) {
                            $registry->register($tool);
                        }
                    } catch (\Throwable) {
                        // Tool-Registrierung darf den Boot nie reißen
                    }
                }
            };
            // Singleton ggf. schon resolved (Core-Boot) → sofort; sonst beim ersten make
            if ($this->app->resolved(\Platform\Core\Tools\ToolRegistry::class)) {
                $toolHook($this->app->make(\Platform\Core\Tools\ToolRegistry::class));
            } else {
                $this->app->afterResolving(\Platform\Core\Tools\ToolRegistry::class, $toolHook);
            }
        }
        
        /**
         * SCHRITT 7: Tools registrieren (optional)
         * 
         * Falls dein Modul AI/Chat-Tools hat:
         * 
         * $this->registerTools();
         */
    }

    /**
     * Registriert alle Livewire-Komponenten automatisch
     * 
     * Scant das src/Livewire/ Verzeichnis rekursiv und registriert
     * alle PHP-Dateien als Livewire-Komponenten.
     * 
     * NAMING CONVENTION:
     * - Datei: src/Livewire/Dashboard.php
     * - Namespace: Platform\FoodAlchemist\Livewire\Dashboard
     * - Alias: foodalchemist.dashboard
     * 
     * - Datei: src/Livewire/Entity/Index.php
     * - Namespace: Platform\FoodAlchemist\Livewire\Entity\Index
     * - Alias: foodalchemist.entity.index
     * 
     * @return void
     */
    protected function registerLivewireComponents(): void
    {
        $basePath = __DIR__ . '/Livewire';
        $baseNamespace = 'Platform\\FoodAlchemist\\Livewire';
        $prefix = 'foodalchemist';

        // Kritische eingebettete Komponenten explizit registrieren: diese Tags werden in
        // Blade direkt gerendert und dürfen nicht von Auto-Discovery/Cache-Zustand abhängen.
        Livewire::component('foodalchemist.orders.editor', \Platform\FoodAlchemist\Livewire\Orders\Editor::class);

        // Prüfe ob Verzeichnis existiert
        if (!is_dir($basePath)) {
            return;
        }

        // Rekursiv alle PHP-Dateien durchsuchen
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($basePath)
        );

        foreach ($iterator as $file) {
            // Nur PHP-Dateien verarbeiten
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            // Relativen Pfad extrahieren
            $relativePath = str_replace($basePath . DIRECTORY_SEPARATOR, '', $file->getPathname());
            
            // Klassenpfad generieren (z.B. Entity\Index -> Entity\Index)
            $classPath = str_replace(['/', '.php'], ['\\', ''], $relativePath);
            $class = $baseNamespace . '\\' . $classPath;

            // Prüfe ob Klasse existiert
            if (!class_exists($class)) {
                continue;
            }

            // Alias generieren (z.B. Settings/Einheiten -> settings.einheiten).
            // WICHTIG: jedes Pfad-Segment einzeln kebab-en — Str::kebab über den ganzen
            // Pfad macht aus "Settings/Einheiten" sonst "settings/-einheiten" (M1-01-Fund).
            $aliasPath = collect(explode(DIRECTORY_SEPARATOR, str_replace('.php', '', $relativePath)))
                ->map(fn (string $segment) => Str::kebab($segment))
                ->implode('.');
            $alias = $prefix . '.' . $aliasPath;

            // Livewire-Komponente registrieren
            Livewire::component($alias, $class);
        }
    }
}
