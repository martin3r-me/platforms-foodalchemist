<?php

namespace Platform\FoodAlchemist\Tests\Support;

use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Support\TeamAncestryRegistry;

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
        // Der INHALT ist hier bewusst irrelevant: kein Test und kein gerenderter
        // Core-Component liest die Tabelle, sie erfüllt nur den FK. Cores echte Migration
        // zöge `core_ai_providers` als Abhängigkeit nach — dafür ist hier kein Bedarf.
        // (Sobald etwas die Tabelle WIRKLICH liest, gehört sie in die --path-Liste unten.)
        if (! \Illuminate\Support\Facades\Schema::hasTable('core_ai_models')) {
            \Illuminate\Support\Facades\Schema::create('core_ai_models', function ($table) {
                $table->id();
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
                // `checkins` gehört CORE (nicht platform-organization — der frühere Kommentar
                // hier war falsch und hat die Suche nach einer Organization-Abhängigkeit
                // ausgelöst, die es nicht gibt). Gebraucht, weil x-ui-page-navbar per
                // @livewire('core.navbar-checkin') bei JEDEM Full-Page-Render darauf zugreift
                // (liest mood_score/daily_goal, ruft Checkin::currentStreak) — M3-02.
                // Bewusst Cores echte Migrationen statt eines handgebauten Stubs: der Stub
                // erfand team_id/check_in/check_out und ließ mood_score/daily_goal weg; er
                // trug nur, solange die Tabelle leer blieb. Reihenfolge: nach users (FK).
                $core . '/2025_01_09_000001_create_checkins_table.php',
                $core . '/2025_01_21_000001_add_kpi_fields_to_checkins_table.php',
                // `context_files` gehört CORE. FA-Branding (Logo/Cover an Foodbook &
                // Speisekarte) speichert Bilder seit 07899da (2026-08-11) über Cores
                // ContextFileService — die Module-Migration
                // 2026_08_11_000002_attach_core_context_files_to_foodalchemist_images
                // hängt FKs auf `context_files` an, und delete() liest
                // `context_file_variants`. Ohne diese Tabellen scheitern die
                // Branding-Upload-Tests mit "no such table: context_files". Die
                // user_id-nullable-ALTER ist plain ->change() (Prod: user_id nullable,
                // z.B. Upload ohne Acting-User) und SQLite-safe. Bewusst NICHT dabei:
                // die ALTERs auf context_file_references (…_000004 / make_context_file_id_nullable)
                // — die nutzen SHOW INDEX / DROP FOREIGN KEY / information_schema (MySQL-only)
                // und brechen auf SQLite. Reihenfolge egal: Migrator sortiert nach Namen.
                $core . '/2025_01_01_000001_create_context_files_table.php',
                $core . '/2025_01_01_000002_create_context_file_variants_table.php',
                $core . '/2025_01_01_000003_create_context_file_references_table.php',
                $core . '/2026_02_15_000001_make_user_id_nullable_on_context_files_table.php',
                $module,
            ],
        ])->run();

        $this->rootTeam = Team::create(['name' => 'Root (Katalog-Besitzer)', 'user_id' => 1, 'personal_team' => false]);
        $this->childA = Team::create(['name' => 'Kind A', 'user_id' => 1, 'personal_team' => false, 'parent_team_id' => $this->rootTeam->id]);
        $this->childB = Team::create(['name' => 'Kind B', 'user_id' => 1, 'personal_team' => false, 'parent_team_id' => $this->rootTeam->id]);

        // Stale Ketten aus früheren Tests desselben Prozesses verwerfen (V-048).
        // Früher stand hier eine Handliste über 14 von 77 Models; sie wuchs nur, wenn jemand
        // darüber stolperte, und eine fehlende Zeile machte D1-Tests grün aus dem falschen
        // Grund. Jetzt trägt sich jede Klasse selbst ein, sobald sie eine Kette cacht —
        // hier (und in TestCase::setUp) wird die registrierte Menge geleert, nicht aufgezählt.
        TeamAncestryRegistry::flushAll();

        // Modul-Routen für Full-Page-Komponenten: der ServiceProvider registriert sie nur
        // hinter PlatformCore/ModuleRouter (modules-Tabelle beim Boot noch leer) — ohne sie
        // crasht jede View mit route('foodalchemist.*') (Breadcrumbs/Sidebar) im Test-Env.
        if (! \Illuminate\Support\Facades\Route::has('foodalchemist.dashboard')) {
            \Illuminate\Support\Facades\Route::middleware('web')->prefix('foodalchemist')
                ->group(\dirname(__DIR__, 2) . '/routes/web.php');

            // Der Namens-Index der RouteCollection ist zu diesem Zeitpunkt schon gebaut —
            // ohne Refresh sind die Routen VORHANDEN, aber `Route::has()`/`route()` finden
            // sie nicht (und Dokument-Tests skippen sich fälschlich weg).
            \Illuminate\Support\Facades\Route::getRoutes()->refreshNameLookups();
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

    /**
     * Konzept, das die Tranche-C-Checks (Spec 21 · S4a) ÜBERHAUPT erreichen: Status
     * `active` = „in Gebrauch". Bewusst nicht `draft` — die Checks messen nur benutzte
     * Konzepte, ein Entwurf wäre für jeden Positivfall unsichtbar. Wer den Negativfall
     * „Entwurf zählt nicht" prüfen will, setzt `status` explizit auf `draft`.
     *
     * Unauffällig ist das Konzept damit noch NICHT — es hat keine Slots und löst
     * deshalb `konzept_slot_luecke` aus (kein belegter Inhalts-Slot). Ein sauberes
     * Konzept braucht mindestens einen Slot über {@see makeConceptSlot} mit Wording.
     */
    protected function makeConcept(Team $owner, string $name, array $attrs = []): \Platform\FoodAlchemist\Models\FoodAlchemistConcept
    {
        return \Platform\FoodAlchemist\Models\FoodAlchemistConcept::create(array_merge([
            'team_id' => $owner->id,
            'name' => $name,
            'status' => 'active',
            'is_template' => false,
        ], $attrs));
    }

    /**
     * Gericht-Slot eines Konzepts. Default = Pflicht-Slot mit Kunden-Wording, also
     * die saubere Variante: so löst er weder `konzept_slot_luecke` noch
     * `konzept_ohne_wording` aus. Ein Test verschlechtert genau ein Feld
     * (`sales_recipe_id` => null, `wording` => null …).
     */
    protected function makeConceptSlot(
        \Platform\FoodAlchemist\Models\FoodAlchemistConcept $concept,
        array $attrs = []
    ): \Platform\FoodAlchemist\Models\FoodAlchemistConceptSlot {
        return \Platform\FoodAlchemist\Models\FoodAlchemistConceptSlot::create(array_merge([
            'team_id' => $concept->team_id,
            'concept_id' => $concept->id,
            'type' => 'gericht',
            'is_pflicht' => true,
            'position' => 1,
            'wording' => 'Kundenfähige Bezeichnung',
        ], $attrs));
    }

    /**
     * Foodbook, das die Tranche-D-Checks (Spec 21 · S4c) ÜBERHAUPT erreichen: Status
     * `aktiv` = „in Gebrauch". Bewusst nicht `draft` — `foodbook_kapitel_leer` misst nur
     * benutzte Bücher, ein Entwurf wäre für jeden Positivfall unsichtbar. Wer den
     * Negativfall „Entwurf zählt nicht" prüfen will, setzt `status` explizit auf `draft`.
     *
     * Unauffällig ist das Buch damit noch NICHT: ohne befülltes Kapitel löst es
     * `foodbook_kapitel_leer` über den zweiten Zweig aus (kein Kapitel mit Inhalt).
     */
    protected function makeFoodbook(Team $owner, string $label, array $attrs = []): \Platform\FoodAlchemist\Models\FoodAlchemistFoodbook
    {
        return \Platform\FoodAlchemist\Models\FoodAlchemistFoodbook::create(array_merge([
            'team_id' => $owner->id,
            'label' => $label,
            'status' => 'aktiv',
        ], $attrs));
    }

    /** Kapitel eines Foodbooks. Ohne Inhalts-Block ist es leer (Tranche D). */
    protected function makeChapter(
        \Platform\FoodAlchemist\Models\FoodAlchemistFoodbook $foodbook,
        array $attrs = []
    ): \Platform\FoodAlchemist\Models\FoodAlchemistFoodbookKapitel {
        return \Platform\FoodAlchemist\Models\FoodAlchemistFoodbookKapitel::create(array_merge([
            'team_id' => $foodbook->team_id,
            'foodbook_id' => $foodbook->id,
            'title' => 'Kapitel',
            'position' => 1,
        ], $attrs));
    }

    /**
     * Inhalts-Block eines Kapitels. Default `recipe_ref` + sichtbar = die Variante, die
     * das Kapitel befüllt; ein Test verschlechtert genau ein Feld (`type => 'header'`,
     * `visible => false` …).
     */
    protected function makeFoodbookBlock(
        \Platform\FoodAlchemist\Models\FoodAlchemistFoodbookKapitel $chapter,
        array $attrs = []
    ): \Platform\FoodAlchemist\Models\FoodAlchemistFoodbookBlock {
        return \Platform\FoodAlchemist\Models\FoodAlchemistFoodbookBlock::create(array_merge([
            'team_id' => $chapter->team_id,
            'chapter_id' => $chapter->id,
            'type' => 'recipe_ref',
            'position' => 1,
            'visible' => true,
        ], $attrs));
    }

    /**
     * Kreativ-Skizze am Kapitel. Default = der Zustand, den der Kapitel-Go bei einer
     * Freitext-Idee hinterlässt (`generation_status='queued'`, nichts materialisiert) —
     * also der Positivfall von `foodbook_skizze_ungeerdet`, sobald das Kapitel ein
     * `released_at` jenseits der Karenzzeit trägt.
     */
    protected function makeDishIdea(
        \Platform\FoodAlchemist\Models\FoodAlchemistFoodbookKapitel $chapter,
        array $attrs = []
    ): \Platform\FoodAlchemist\Models\FoodAlchemistDishIdea {
        return \Platform\FoodAlchemist\Models\FoodAlchemistDishIdea::create(array_merge([
            'team_id' => $chapter->team_id,
            'chapter_id' => $chapter->id,
            'title' => 'Freie Idee',
            'status' => 'entwurf',
            'target_form' => 'einzel',
            'generation_status' => 'queued',
            'position' => 1,
        ], $attrs));
    }
}
