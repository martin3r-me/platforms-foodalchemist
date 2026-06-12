<?php

namespace Platform\FoodAlchemist\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Services\RecipeService;

/**
 * M8-05 / D1+D2: Team-Onboarding — Kind-Team anlegen, Modul freischalten
 * (modulables, Mechanik aus der Core-Wissensbasis), optional ein Rezept-
 * Startpaket als TEAM-EIGENE Snapshots (D2: Kopie, keine Referenz — das
 * Kind editiert frei, der Eltern-Katalog bleibt unberührt sichtbar).
 */
class TeamOnboardingCommand extends Command
{
    protected $signature = 'foodalchemist:team-onboarding
        {--parent= : Eltern-Team-ID (Katalog-Besitzer)}
        {--name= : Name des neuen Kind-Teams}
        {--user= : User-ID des Team-Owners (Default: Owner des Eltern-Teams)}
        {--startpaket= : Kommagetrennte Basisrezept-IDs aus dem Eltern-Katalog als Snapshot kopieren}
        {--dry-run : Nur berichten, nichts schreiben}';

    protected $description = 'Kind-Team anlegen + foodalchemist freischalten + optionales Rezept-Startpaket (D2-Snapshots)';

    public function handle(): int
    {
        $parent = Team::find((int) $this->option('parent'));
        $name = trim((string) $this->option('name'));
        if ($parent === null || $name === '') {
            $this->error('--parent=<id> und --name=<…> sind Pflicht.');

            return self::FAILURE;
        }
        $userId = (int) ($this->option('user') ?: $parent->user_id);
        $dryRun = (bool) $this->option('dry-run');

        $modul = DB::table('modules')->where('key', 'foodalchemist')->first();
        if ($modul === null) {
            $this->error('Modul foodalchemist ist nicht registriert (modules-Tabelle) — App einmal booten.');

            return self::FAILURE;
        }

        $startIds = array_filter(array_map('intval', explode(',', (string) $this->option('startpaket'))));
        $this->info("Kind-Team »{$name}« unter »{$parent->name}« (#{$parent->id}), Owner-User #{$userId}"
            . ($startIds !== [] ? ', Startpaket: ' . count($startIds) . ' Rezepte' : ', ohne Startpaket')
            . ($dryRun ? ' [DRY-RUN]' : ''));
        if ($dryRun) {
            return self::SUCCESS;
        }

        return DB::transaction(function () use ($parent, $name, $userId, $modul, $startIds) {
            // 1. Kind-Team in der Hierarchie (D1: Kette aufwärts = Katalog sofort sichtbar)
            $team = Team::create([
                'name' => $name, 'user_id' => $userId, 'personal_team' => false,
                'parent_team_id' => $parent->id,
            ]);
            DB::table('team_user')->insertOrIgnore([
                'team_id' => $team->id, 'user_id' => $userId, 'role' => 'admin',
                'created_at' => now(), 'updated_at' => now(),
            ]);

            // 2. Modul-Freischaltung (modulables: Team-morph, enabled — Core-Wissensbasis)
            DB::table('modulables')->insertOrIgnore([
                'module_id' => $modul->id,
                'modulable_type' => get_class($team), 'modulable_id' => $team->id,
                'enabled' => 1, 'team_id' => $team->id,
                'created_at' => now(), 'updated_at' => now(),
            ]);

            // 3. Optionales Startpaket: D2-SNAPSHOTS (team-eigene Kopien via duplicate)
            $kopien = 0;
            if ($startIds !== []) {
                $recipes = app(RecipeService::class);
                foreach (FoodAlchemistRecipe::where('team_id', $parent->id)->whereIn('id', $startIds)->get() as $original) {
                    $kopie = $recipes->duplicate($parent, $original->id, $original->name);
                    // Snapshot dem KIND übereignen (duplicate legt im Eltern-Team an)
                    FoodAlchemistRecipe::whereKey($kopie->id)->update(['team_id' => $team->id]);
                    DB::table('foodalchemist_recipe_ingredients')->where('recipe_id', $kopie->id)->update(['team_id' => $team->id]);
                    $kopien++;
                }
            }

            $this->info("✅ Team #{$team->id} angelegt, Modul freigeschaltet, {$kopien} Startpaket-Snapshots übereignet.");
            $this->line('Katalog-Sichtbarkeit: Eltern-GPs/-Rezepte sind über die Team-Kette sofort sichtbar (D1).');

            return self::SUCCESS;
        });
    }
}
