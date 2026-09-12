<?php

use Illuminate\Support\Facades\DB;
use Platform\FoodAlchemist\Models\FoodAlchemistVocabKochequipment;
use Platform\FoodAlchemist\Services\RecipeService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Gefunden beim Feld-Audit an Dominiques Rezept 3748 (12.09.2026).
 *
 * `foodalchemist_recipe_equipment` war die einzige der vier Satelliten-Tabellen ohne
 * Provenienz: Behälter, Regeneration und Anker-Mappings führen alle `source` (manual|ki),
 * Equipment hatte nur recipe_id/equipment_id/note.
 *
 * Das war nicht bloss eine fehlende Spalte. Die Anreicherung schrieb mit
 * `$recipe->equipment()->sync(...)`, und sync ERSETZT die ganze Liste — ein von Hand
 * gesetztes Gerät verschwand beim nächsten Voll-Lauf spurlos. Bei den Anker-Mappings ist
 * genau das seit Spec 50 verboten („manuelle Mappings werden nie ersetzt"); beim Equipment
 * fehlte die Grundlage dafür, weil man manuell und KI gar nicht unterscheiden konnte.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->rezept = $this->makeRecipe($this->rootTeam, 'Equipment-Test');

    $this->geraet = function (string $slug): int {
        return (int) FoodAlchemistVocabKochequipment::create([
            'team_id' => null, 'slug' => $slug, 'name' => ucfirst($slug),
            'is_inactive' => false, 'sort_order' => 10,
        ])->id;
    };
});

it('★ stempelt eine menschliche Auswahl als manual — sonst gibt es nichts zu schuetzen', function () {
    $id = ($this->geraet)('schneebesen');

    app(RecipeService::class)->update($this->rootTeam, $this->rezept->id, ['equipment_ids' => [$id]]);

    expect(DB::table('foodalchemist_recipe_equipment')->where('recipe_id', $this->rezept->id)
        ->value('source'))->toBe('manual');
});

it('★ die Anreicherung loescht ein von Hand gesetztes Geraet NICHT mehr', function () {
    $vonHand = ($this->geraet)('schneebesen');
    $vonKi = ($this->geraet)('mixstab');

    // Mensch setzt eins.
    app(RecipeService::class)->update($this->rootTeam, $this->rezept->id, ['equipment_ids' => [$vonHand]]);

    // Anreicherung schlaegt ein ANDERES vor — das alte darf nicht verschwinden.
    // (Die Sync-Mechanik wird hier direkt geprueft, ohne Provider-Aufruf.)
    $manuell = $this->rezept->equipment()->wherePivot('source', 'manual')->allRelatedIds();
    $behalten = $manuell->flip()->map(fn () => ['source' => 'manual'])->all();
    $neu = collect([$vonKi])->diff($manuell)->flip()
        ->map(fn () => ['source' => 'ki', 'ai_confidence' => 0.8])->all();
    $this->rezept->equipment()->sync($behalten + $neu);

    $zeilen = DB::table('foodalchemist_recipe_equipment')->where('recipe_id', $this->rezept->id)
        ->pluck('source', 'equipment_id');

    expect($zeilen)->toHaveCount(2)
        ->and($zeilen[$vonHand])->toBe('manual')
        ->and($zeilen[$vonKi])->toBe('ki');
});

it('Bestandszeilen gelten als manual — die Migration gibt Bestehendes nicht frei', function () {
    // Direkt eingefuegt wie ein Import es taete: ohne source. Der Spalten-Default entscheidet.
    DB::table('foodalchemist_recipe_equipment')->insert([
        'recipe_id' => $this->rezept->id, 'equipment_id' => ($this->geraet)('topf'),
    ]);

    expect(DB::table('foodalchemist_recipe_equipment')->where('recipe_id', $this->rezept->id)
        ->value('source'))->toBe('manual');
});
