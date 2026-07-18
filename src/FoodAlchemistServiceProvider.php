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
                \Platform\FoodAlchemist\Console\EmbedEvalCommand::class,
                \Platform\FoodAlchemist\Console\TeamOnboardingCommand::class,
                \Platform\FoodAlchemist\Console\SignaleDetektorCommand::class,
                \Platform\FoodAlchemist\Console\PairingProjectComputedCommand::class,
                \Platform\FoodAlchemist\Console\DataQualityCommand::class,
                \Platform\FoodAlchemist\Console\LeadLaRepickCommand::class,
                \Platform\FoodAlchemist\Console\RecomputeCommand::class,
                \Platform\FoodAlchemist\Console\GpAllergenBackfillCommand::class,
                \Platform\FoodAlchemist\Console\ProcessAnchorGroundCommand::class,
                \Platform\FoodAlchemist\Console\ConvenienceHighlightsCommand::class,
            ]);
        }
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
                    \Platform\FoodAlchemist\Tools\RecipeKlassePostTool::class,
                    \Platform\FoodAlchemist\Tools\UiOpenTool::class,
                    // Phase 0: GP-Ground-Truth (Match + NEW-GP-Staging, LA-First-konform)
                    \Platform\FoodAlchemist\Tools\GpsMatchTool::class,
                    \Platform\FoodAlchemist\Tools\GpProposalsPostTool::class,
                    // 07·M3: LA-First-GP-Mint als MCP-Tool (löst den Ruby-Fall FA-nativ)
                    \Platform\FoodAlchemist\Tools\GpsMintFromLaTool::class,
                    // #513 Tier 1: Grammaturen-Rechner (Bäckerprozent/Extraprozent/Brining/Bloom)
                    \Platform\FoodAlchemist\Tools\ProportionCalcTool::class,
                    // #513: %→Gramm-Rückschreiben (Batch-Skalierung + Einzel-Zutat-Edit, write)
                    \Platform\FoodAlchemist\Tools\ProportionApplyTool::class,
                    // Phase K: Wissen + Pairing-Graph für externe LLM-Clients
                    \Platform\FoodAlchemist\Tools\KnowledgeSearchTool::class,
                    \Platform\FoodAlchemist\Tools\KnowledgeListTool::class,
                    \Platform\FoodAlchemist\Tools\KnowledgeGetTool::class,
                    \Platform\FoodAlchemist\Tools\KnowledgeCreateTool::class,
                    \Platform\FoodAlchemist\Tools\KnowledgeUpdateTool::class,
                    \Platform\FoodAlchemist\Tools\PairingsGetTool::class,
                    \Platform\FoodAlchemist\Tools\PairingsSuggestTool::class,
                    // 05·P5: Prozessanker deterministisch erden (MCP-Lockstep)
                    \Platform\FoodAlchemist\Tools\ProcessAnchorsGroundTool::class,
                    // 06·H2: Convenience-Highlights kuratieren (MCP-Lockstep)
                    \Platform\FoodAlchemist\Tools\ConvenienceHighlightsGetTool::class,
                    \Platform\FoodAlchemist\Tools\ConvenienceHighlightsPutTool::class,
                    // Phase A: Rezept-Schreibkaskade (Weg-A-Ausnahme, Draft-Quarantäne)
                    \Platform\FoodAlchemist\Tools\RecipesPostTool::class,
                    \Platform\FoodAlchemist\Tools\RecipesPutTool::class,
                    \Platform\FoodAlchemist\Tools\RecipeIngredientsPutTool::class,
                    // Phase B: Foodbook-Kaskade (nativ FA, Draft-only)
                    \Platform\FoodAlchemist\Tools\FoodbooksPostTool::class,
                    \Platform\FoodAlchemist\Tools\FoodbookKapitelPostTool::class,
                    \Platform\FoodAlchemist\Tools\FoodbookBlocksPostTool::class,
                    // Phase C: Concepter, Angebote, Kalkulation, Settings, Signale, Food DNA, Speiseplan
                    \Platform\FoodAlchemist\Tools\ConceptsSearchTool::class,
                    \Platform\FoodAlchemist\Tools\ConceptsListTool::class,
                    \Platform\FoodAlchemist\Tools\ConceptsGetTool::class,
                    \Platform\FoodAlchemist\Tools\ConceptsPostTool::class,
                    \Platform\FoodAlchemist\Tools\ConceptSlotsPostTool::class,
                    \Platform\FoodAlchemist\Tools\AngeboteSearchTool::class,
                    \Platform\FoodAlchemist\Tools\AngeboteListTool::class,
                    \Platform\FoodAlchemist\Tools\AngeboteGetTool::class,
                    \Platform\FoodAlchemist\Tools\AngebotePostTool::class,
                    \Platform\FoodAlchemist\Tools\KalkulationGetTool::class,
                    \Platform\FoodAlchemist\Tools\SimulationPostTool::class,
                    // R7.1: Operative Planungs-Blätter (read-only, rein rechnend)
                    \Platform\FoodAlchemist\Tools\ProduktionsblattGetTool::class,
                    \Platform\FoodAlchemist\Tools\BestellvorschlagGetTool::class,
                    \Platform\FoodAlchemist\Tools\EinkaufslisteGetTool::class,
                    // R2.6: Praxis-Feedback (Küche/Kunde/Event) je Gericht/Rezept
                    \Platform\FoodAlchemist\Tools\FeedbackSearchTool::class,
                    \Platform\FoodAlchemist\Tools\FeedbackPostTool::class,
                    // R2.7: Portfolio-Benchmark (BHG-intern, read-only)
                    \Platform\FoodAlchemist\Tools\BenchmarkGetTool::class,
                    \Platform\FoodAlchemist\Tools\SettingsGetTool::class,
                    \Platform\FoodAlchemist\Tools\SignaleSearchTool::class,
                    \Platform\FoodAlchemist\Tools\SignaleListTool::class,
                    \Platform\FoodAlchemist\Tools\SignalePutTool::class,
                    \Platform\FoodAlchemist\Tools\CanvasGetTool::class,
                    \Platform\FoodAlchemist\Tools\CanvasPutTool::class,
                    // R4.1–R4.3 Planungs-Gerüst + Coverage + Phase — MCP im Lockstep
                    \Platform\FoodAlchemist\Tools\PlanningGetTool::class,
                    \Platform\FoodAlchemist\Tools\PlanningPutTool::class,
                    \Platform\FoodAlchemist\Tools\CoverageGetTool::class,
                    \Platform\FoodAlchemist\Tools\PhasePutTool::class,
                    \Platform\FoodAlchemist\Tools\ConceptSlotVariantePostTool::class, // R4.4
                    \Platform\FoodAlchemist\Tools\ConceptsGenerateTool::class, // R6.1
                    \Platform\FoodAlchemist\Tools\SpeiseplaenePostTool::class,
                    \Platform\FoodAlchemist\Tools\SpeiseplanEintraegePostTool::class,
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
