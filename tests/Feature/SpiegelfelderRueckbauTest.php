<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Services\Fassung\FassungService;
use Platform\FoodAlchemist\Support\Datenmodell\SpiegelfelderRueckbau;
use Platform\FoodAlchemist\Support\TeamAncestryRegistry;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Plan 98 · 3.5 Schritt 6c · Rückbau der Spiegelfelder: Backup, Drop, Rehash (R1) und Rückweg. Im Test-Schema hat die Migration
 * 2027_01_16_100000 die Spalten schon entfernt; der Zyklus startet deshalb mit dem Rückweg und endet wieder im gedroppten Zustand.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->master = Team::create(['name' => 'BHG.DIGITAL (Master)', 'user_id' => 1, 'personal_team' => false]);
    config(['foodalchemist.master_team_id' => $this->master->id]);
    TeamAncestryRegistry::flushAll();
    $this->kurator = $this->makeUser($this->master, 'Kurator', 'owner');
    $this->actingAs($this->kurator);
});

it('nach der Migration: Spiegelspalten weg, Backup-Tabellen da', function () {
    expect(SpiegelfelderRueckbau::vorhanden())->toBe([])
        ->and(Schema::hasTable(SpiegelfelderRueckbau::BACKUP))->toBeTrue()
        ->and(Schema::hasTable(SpiegelfelderRueckbau::BACKUP_HASH))->toBeTrue();
});

it('Zyklus: Rückweg legt Spalten wieder an, up sichert, dropt und hasht unveränderte Fassungen neu (keine Fassung „weicht ab“)', function () {
    SpiegelfelderRueckbau::wiederherstellen();
    expect(SpiegelfelderRueckbau::vorhanden())->toHaveCount(9);

    $butter = FoodAlchemistGp::create(['team_id' => null, 'gp_key' => 'rueckbau|butter', 'name' => 'Butter']);
    $unver = $this->makeRecipe($this->master, 'Unverändert', ['is_sales_recipe' => true]);
    $this->makeIngredient($unver, 'Butter', $butter, '100');
    $geaendert = $this->makeRecipe($this->master, 'Geändert', ['is_sales_recipe' => true]);
    $this->makeIngredient($geaendert, 'Butter', $butter, '100');
    DB::table('foodalchemist_recipes')->whereIn('id', [$unver->id, $geaendert->id])->update(['sales_unit_count' => 4, 'sales_quantity_per_unit_g' => 324.5]);
    $svc = app(FassungService::class);
    $svc->veroeffentlichen($this->master, $unver->id, 'Erst', $this->kurator);
    $svc->veroeffentlichen($this->master, $geaendert->id, 'Erst', $this->kurator);
    $geaendert->forceFill(['name' => 'Geändert neu'])->save();   // nach der Fassung geändert: darf NICHT umgehasht werden

    $plan = SpiegelfelderRueckbau::plan();
    expect($plan['spalten'])->toHaveCount(9)->and($plan['fassungen_rehash'])->toBeGreaterThanOrEqual(1);

    // up() der Migration
    SpiegelfelderRueckbau::sichern();
    $unveraendert = SpiegelfelderRueckbau::unveraenderte();
    SpiegelfelderRueckbau::entfernen();
    SpiegelfelderRueckbau::rehash($unveraendert);

    $ids = array_column($unveraendert, 'recipe_id');
    expect($ids)->toContain($unver->id)->not->toContain($geaendert->id)
        ->and(SpiegelfelderRueckbau::vorhanden())->toBe([])
        ->and($svc->status(FoodAlchemistRecipe::ohneTeamSicht('test')->findOrFail($unver->id))['weicht_ab'])->toBeFalse()
        ->and($svc->status(FoodAlchemistRecipe::ohneTeamSicht('test')->findOrFail($geaendert->id))['weicht_ab'])->toBeTrue()
        ->and((float) DB::table(SpiegelfelderRueckbau::BACKUP)->where('recipe_id', $unver->id)->value('sales_quantity_per_unit_g'))->toBe(324.5)
        ->and((float) DB::table(SpiegelfelderRueckbau::BACKUP)->where('recipe_id', $unver->id)->value('sales_unit_count'))->toBe(4.0);

    // Rückweg stellt Werte und den alten Hash wieder her
    $fassungId = array_key_first(array_filter($unveraendert, fn ($u) => $u['recipe_id'] === $unver->id));
    $hashAlt = $unveraendert[$fassungId]['hash_alt'];
    SpiegelfelderRueckbau::wiederherstellen();
    expect((float) DB::table('foodalchemist_recipes')->where('id', $unver->id)->value('sales_unit_count'))->toBe(4.0)
        ->and(DB::table('foodalchemist_recipes')->where('id', $fassungId)->value('inhalt_hash'))->toBe($hashAlt);

    // zurück in den Zustand nach der Migration (nachfolgende Tests erwarten ihn)
    SpiegelfelderRueckbau::sichern();
    $u2 = SpiegelfelderRueckbau::unveraenderte();
    SpiegelfelderRueckbau::entfernen();
    SpiegelfelderRueckbau::rehash($u2);
    expect(SpiegelfelderRueckbau::vorhanden())->toBe([]);
});
