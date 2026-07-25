<?php

namespace Platform\FoodAlchemist\Tests\Support;

use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistGp;

/**
 * M0-06 Leak-Test-Harness (D1-Risiko, 08_ENTSCHEIDUNGEN):
 * Root-Team + 2 Geschwister-Kinder in der leeren Test-DB (:memory:).
 *
 * Migriert gezielt NUR die benötigten Tabellen — Cores teams-Migrationen
 * (der Gesamtsatz scheitert auf SQLite an MySQL-only-SQL, 09_TESTKATALOG §0
 * Lücke 2) plus alle foodalchemist_*-Migrationen.
 *
 * Annahme: LogsActivity ist in der Test-Host-App ein No-op (Sandbox-Stub).
 * Läuft die Suite je in einer Host-App mit echtem Activity-Log, braucht
 * der Helper zusätzlich dessen Tabellen-Migration.
 */
trait SeedsTeamHierarchy
{
    protected Team $rootTeam;

    protected Team $childA;

    protected Team $childB;

    protected function seedTeamHierarchy(): void
    {
        $core = base_path('vendor/martin3r/platform-core/database/migrations');
        $module = \dirname(__DIR__, 2) . '/database/migrations';

        // Stub: die AI-User-Migration hängt einen FK auf core_ai_models an users —
        // SQLite validiert ALLE Tabellen-FKs beim Insert, also muss die Tabelle existieren.
        if (! \Illuminate\Support\Facades\Schema::hasTable('core_ai_models')) {
            \Illuminate\Support\Facades\Schema::create('core_ai_models', function ($table) {
                $table->id();
                $table->timestamps();
            });
        }

        // Stub: Cores Navbar (über x-ui-page-navbar) rendert die Zeiterfassung aus
        // platform-organization — `checkins` muss existieren, Inhalt egal (M3-02).
        if (! \Illuminate\Support\Facades\Schema::hasTable('checkins')) {
            \Illuminate\Support\Facades\Schema::create('checkins', function ($table) {
                $table->id();
                $table->unsignedBigInteger('user_id')->nullable();
                $table->unsignedBigInteger('team_id')->nullable();
                $table->date('date')->nullable();
                $table->dateTime('check_in')->nullable();
                $table->dateTime('check_out')->nullable();
                $table->timestamps();
            });
        }

        $this->artisan('migrate', [
            '--realpath' => true,
            '--path' => [
                $core . '/0001_01_01_000000_create_users_table.php', // Cores Version (current_team_id)
                $core . '/2026_01_11_160000_add_ai_user_fields_to_users_table.php', // type/team_id (User-Model-Hooks erwarten sie)
                $core . '/0001_01_01_000005_create_teams_table.php',
                $core . '/2025_11_08_150000_add_parent_team_id_to_teams_table.php',
                // x-ui-page-navbar fragt `modules` + team_user ab — nötig für Full-Page-Komponenten via Livewire::test (M3-02)
                $core . '/0001_01_01_000006_create_team_user_table.php',
                $core . '/0001_01_01_000011_create_modules_table.php',
                $core . '/2025_11_08_150001_add_scope_type_to_modules_table.php',
                $core . '/2025_12_20_000001_create_team_user_last_modules_table.php',
                $core . '/2026_04_12_000004_create_module_usage_counts_table.php',
                $module,
            ],
        ])->run();

        $this->rootTeam = Team::create(['name' => 'Root (Katalog-Besitzer)', 'user_id' => 1, 'personal_team' => false]);
        $this->childA = Team::create(['name' => 'Kind A', 'user_id' => 1, 'personal_team' => false, 'parent_team_id' => $this->rootTeam->id]);
        $this->childB = Team::create(['name' => 'Kind B', 'user_id' => 1, 'personal_team' => false, 'parent_team_id' => $this->rootTeam->id]);

        // Stale Ketten aus früheren Tests desselben Prozesses verwerfen.
        // Weitere Models mit BelongsToTeamHierarchy hier ergänzen, sobald getestet.
        FoodAlchemistGp::flushTeamAncestryCache();
        \Platform\FoodAlchemist\Models\FoodAlchemistPaket::flushTeamAncestryCache();
        \Platform\FoodAlchemist\Models\FoodAlchemistConcept::flushTeamAncestryCache();
        \Platform\FoodAlchemist\Models\FoodAlchemistConceptCategory::flushTeamAncestryCache();
        \Platform\FoodAlchemist\Models\FoodAlchemistVocabKlasse::flushTeamAncestryCache();
        \Platform\FoodAlchemist\Models\FoodAlchemistFoodbook::flushTeamAncestryCache();
        \Platform\FoodAlchemist\Models\FoodAlchemistFoodbookKapitel::flushTeamAncestryCache();
        \Platform\FoodAlchemist\Models\FoodAlchemistFoodbookBlock::flushTeamAncestryCache();
        \Platform\FoodAlchemist\Models\FoodAlchemistSpeiseplan::flushTeamAncestryCache();
        \Platform\FoodAlchemist\Models\FoodAlchemistSpeiseplanEintrag::flushTeamAncestryCache();
        \Platform\FoodAlchemist\Models\FoodAlchemistComponentEquivalent::flushTeamAncestryCache();
        \Platform\FoodAlchemist\Models\FoodAlchemistProductionOrder::flushTeamAncestryCache();
        \Platform\FoodAlchemist\Models\FoodAlchemistDishIdea::flushTeamAncestryCache();
        \Platform\FoodAlchemist\Models\FoodAlchemistDishIdeaGroup::flushTeamAncestryCache();

        // Modul-Routen für Full-Page-Komponenten: der ServiceProvider registriert sie nur
        // hinter PlatformCore/ModuleRouter (modules-Tabelle beim Boot noch leer) — ohne sie
        // crasht jede View mit route('foodalchemist.*') (Breadcrumbs/Sidebar) im Test-Env.
        if (! \Illuminate\Support\Facades\Route::has('foodalchemist.dashboard')) {
            \Illuminate\Support\Facades\Route::middleware('web')->prefix('foodalchemist')
                ->group(\dirname(__DIR__, 2) . '/routes/web.php');
        }
    }

    /** User mit current_team_id im gegebenen Team (für UI-/Curate-Gating-Tests, M1-08). */
    protected function makeUser(Team $team, string $name = 'Tester'): \Platform\Core\Models\User
    {
        return \Platform\Core\Models\User::forceCreate([
            'name' => $name,
            'email' => strtolower(str_replace(' ', '.', $name)) . '+' . $team->id . '@test.local',
            'password' => bcrypt('secret'),
            'current_team_id' => $team->id,
        ]);
    }

    protected function makeGp(Team $owner, string $name): FoodAlchemistGp
    {
        return FoodAlchemistGp::create([
            'team_id' => $owner->id,
            'gp_key' => 'leaktest-' . mb_strtolower(str_replace(' ', '-', $name)) . '|test|test',
            'name' => $name,
        ]);
    }

    // ---- Rezeptur-Fixtures (Spec 21 S0) ----------------------------------
    //
    // Die Qualitäts-Checks brauchen Rezepte mit realistischen Feld-Kombinationen
    // (Zubereitung, Mengen, Ausbeute, Kategorie). Vorher baute jeder Test seine
    // Rezepte inline — mit je eigener Default-Wahl, was die Checks schwer
    // vergleichbar macht. Diese Helfer setzen einen bewusst „sauberen" Default:
    // ein so erzeugtes Rezept darf KEINEN Tranche-A-Check auslösen, damit Tests
    // gezielt genau ein Feld verschlechtern und den Negativfall gratis bekommen.

    protected static int $recipeSeq = 0;

    /** Ausbeute des Fixture-Defaults — nur solange sie unverändert ist, führt makeIngredient sie mit. */
    protected const FIXTURE_YIELD_KG = 1.0;

    /**
     * Qualitativ unauffälliges Rezept. $attrs überschreibt gezielt einzelne Felder.
     *
     * Zwei bewusste Grenzen des „sauber"-Versprechens (Spec 21 S1b):
     *  · **VK-Gerichte** müssen selbst regelkonform benannt werden (`[HG] A | B`), sonst
     *    schlägt `rezept_naming_regelwerk` an — der Name kommt vom Test, nicht vom Fixture.
     *  · **Ausbeute** bleibt nur kohärent, wenn Zutaten über makeIngredient dazukommen
     *    (das führt sie mit); rohe Inserts in `recipe_ingredients` muss der Test selbst passend
     *    zur Ausbeute wählen.
     */
    protected function makeRecipe(Team $owner, string $name, array $attrs = []): \Platform\FoodAlchemist\Models\FoodAlchemistRecipe
    {
        self::$recipeSeq++;

        // Allergene stehen nach dem Insert per DB-Default auf `unbekannt`/`unknown` — ein
        // kundenexponiertes Fixture-Rezept würde damit sofort `rezept_allergen_unbelastbar`
        // auslösen. Der saubere Default ist „aggregiert und belastbar".
        $allergene = ['allergens_confidence' => 'high'];
        foreach (['gluten', 'crustaceans', 'eggs', 'fish', 'peanuts', 'soy', 'milk',
            'tree_nuts', 'celery', 'mustard', 'sesame', 'sulphites', 'lupin', 'molluscs'] as $a) {
            $allergene['allergen_' . $a] = 'nicht_enthalten';
        }

        return \Platform\FoodAlchemist\Models\FoodAlchemistRecipe::create(array_merge($allergene, [
            'team_id' => $owner->id,
            'recipe_key' => 'fixture-' . self::$recipeSeq . '-' . mb_strtolower(str_replace(' ', '-', $name)),
            'name' => $name,
            'status' => 'approved',
            'is_sales_recipe' => false,
            'preparation' => 'Alle Zutaten abwiegen, zusammenführen und auf Temperatur bringen.',
            'yield_kg' => self::FIXTURE_YIELD_KG,
            'n_ingredients_total' => 2,
            'n_ingredients_unmapped' => 0,
            // Beide Taxonomie-Wege gesetzt, damit der Default egal ob Basisrezept oder VK-Gericht
            // sauber ist: VK-Gerichte hängen an der Hauptgruppe (Modell A), Basisrezepte an der
            // Produktions-Kategorie. Sonst würde jedes Fixture-Rezept `rezept_kategorie_problem` auslösen.
            'category_id' => $this->makeRecipeCategory($owner)->id,
            'dish_main_group_id' => $this->makeMainGroup($owner)->id,
        ], $attrs));
    }

    /**
     * VK-Speisen-Hauptgruppe (Modell A, hängt direkt am Gericht) — die Tabelle mit
     * `is_inactive`; `$code` variieren, um eine stillgelegte Gruppe zu bauen.
     */
    protected function makeMainGroup(Team $owner, string $code = 'FIX', bool $inactive = false): \Platform\FoodAlchemist\Models\FoodAlchemistDishMainGroup
    {
        return \Platform\FoodAlchemist\Models\FoodAlchemistDishMainGroup::firstOrCreate(
            ['team_id' => $owner->id, 'code' => $code],
            ['label' => 'Hauptgruppe ' . $code, 'is_inactive' => $inactive]
        );
    }

    /**
     * Produktions-Kategorie des Basisrezepts. Achtung: hängt an `recipe_main_groups`
     * (Produktions-Taxonomie), NICHT an den VK-Speisen-Hauptgruppen — zwei getrennte Bäume.
     */
    protected function makeRecipeCategory(Team $owner, string $code = 'FIX-KAT'): \Platform\FoodAlchemist\Models\FoodAlchemistRecipeCategory
    {
        $hg = \Platform\FoodAlchemist\Models\FoodAlchemistRecipeMainGroup::firstOrCreate(
            ['team_id' => $owner->id, 'code' => 'FIX-PHG'],
            ['label' => 'Produktions-Hauptgruppe']
        );

        return \Platform\FoodAlchemist\Models\FoodAlchemistRecipeCategory::firstOrCreate(
            ['team_id' => $owner->id, 'code' => $code],
            ['label' => 'Kategorie ' . $code, 'main_group_id' => $hg->id]
        );
    }

    /** Einheit „g" (idempotent je Team) — Zutaten brauchen eine Mengen-Einheit. */
    protected function unitG(Team $owner): \Platform\FoodAlchemist\Models\FoodAlchemistVocabEinheit
    {
        return \Platform\FoodAlchemist\Models\FoodAlchemistVocabEinheit::firstOrCreate(
            ['team_id' => $owner->id, 'slug' => 'g'],
            ['display_de' => 'Gramm', 'dimension' => 'mass', 'default_in_g' => 1]
        );
    }

    /** Rezept-Zutat mit GP-Mapping (gp_id = null ⇒ ungemappt). */
    protected function makeIngredient(
        \Platform\FoodAlchemist\Models\FoodAlchemistRecipe $recipe,
        string $rawText,
        ?FoodAlchemistGp $gp = null,
        string $quantity = '100',
        int $position = 1
    ): \Platform\FoodAlchemist\Models\FoodAlchemistRecipeIngredient {
        $team = Team::findOrFail($recipe->team_id);

        $zutat = \Platform\FoodAlchemist\Models\FoodAlchemistRecipeIngredient::create([
            'team_id' => $recipe->team_id,
            'recipe_id' => $recipe->id,
            'gp_id' => $gp?->id,
            'raw_text' => $rawText,
            'quantity' => $quantity,
            'unit_vocab_id' => $this->unitG($team)->id,
            'position' => $position,
        ]);

        $this->haltAusbeuteKohaerent($recipe);

        return $zutat;
    }

    /**
     * Führt die Ausbeute mit der eingewogenen Masse mit — sonst würde jedes Fixture-Rezept
     * mit Zutaten `rezept_yield_implausibel` auslösen (1,0 kg Ausbeute aus 200 g Zutaten ist
     * physikalisch unmöglich). Greift nur, solange die Ausbeute noch der Fixture-Default ist:
     * setzt ein Test sie bewusst (auch auf null), bleibt sein Wert stehen. Schreibt per
     * Query-Builder, damit `updated_at` unberührt bleibt (der Verwaist-Check liest es).
     */
    private function haltAusbeuteKohaerent(\Platform\FoodAlchemist\Models\FoodAlchemistRecipe $recipe): void
    {
        if ($recipe->yield_kg === null || abs((float) $recipe->yield_kg - self::FIXTURE_YIELD_KG) > 0.0001) {
            return;
        }

        $summeG = (float) \Illuminate\Support\Facades\DB::table('foodalchemist_recipe_ingredients')
            ->where('recipe_id', $recipe->id)->whereNull('deleted_at')->sum('quantity');
        if ($summeG <= 0.0) {
            return;
        }

        \Illuminate\Support\Facades\DB::table('foodalchemist_recipes')
            ->where('id', $recipe->id)->update(['yield_kg' => round($summeG / 1000, 3)]);
    }
}
