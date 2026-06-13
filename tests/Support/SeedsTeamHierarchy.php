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
}
