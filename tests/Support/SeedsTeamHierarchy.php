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

        $this->artisan('migrate', [
            '--realpath' => true,
            '--path' => [
                $core . '/0001_01_01_000005_create_teams_table.php',
                $core . '/2025_11_08_150000_add_parent_team_id_to_teams_table.php',
                $module,
            ],
        ])->run();

        $this->rootTeam = Team::create(['name' => 'Root (Katalog-Besitzer)', 'user_id' => 1, 'personal_team' => false]);
        $this->childA = Team::create(['name' => 'Kind A', 'user_id' => 1, 'personal_team' => false, 'parent_team_id' => $this->rootTeam->id]);
        $this->childB = Team::create(['name' => 'Kind B', 'user_id' => 1, 'personal_team' => false, 'parent_team_id' => $this->rootTeam->id]);

        // Stale Ketten aus früheren Tests desselben Prozesses verwerfen.
        // Weitere Models mit BelongsToTeamHierarchy hier ergänzen, sobald getestet.
        FoodAlchemistGp::flushTeamAncestryCache();
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
