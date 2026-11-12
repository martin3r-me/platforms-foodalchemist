<?php

use Illuminate\Support\Facades\DB;
use Platform\FoodAlchemist\Models\FoodAlchemistWarengruppeSubkategorie;
use Platform\FoodAlchemist\Services\VocabularyService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Dominique 12.11.2026: Das Unterkategorie-Auswahlfeld im GP-Editor zeigte jede Unterkategorie doppelt („Brot & Brötchen“ aus den
 * Einstellungen + „09.1 Brot & Brötchen“ aus den GPs). Editor nur noch aus den Einstellungen; die Einstellungs-Liste bekommt die Nummer.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    config(['foodalchemist.master_team_id' => $this->rootTeam->id]);
    $this->vocab = app(VocabularyService::class);
    $this->sub = fn (string $wg, string $name, int $pos = 1) => FoodAlchemistWarengruppeSubkategorie::create(['team_id' => null, 'commodity_group_code' => $wg, 'name' => $name, 'position' => $pos]);
    $this->gp = function (string $name, string $wg, string $sub) {
        $gp = $this->makeGp($this->rootTeam, $name);
        $gp->update(['commodity_group_code' => $wg, 'sub_category' => $sub]);

        return $gp;
    };
    $this->migration = require dirname(__DIR__, 2).'/database/migrations/2026_12_24_100000_subkategorie_nummern.php';
});

it('nurVerwaltet: Editor-Liste enthält nur die Einstellungs-Einträge, die Übersicht weiter auch GP-Freitextwerte', function () {
    ($this->sub)('09', '09.1 Brot & Brötchen');
    ($this->gp)('Brötchen', '09', '09.1 Brot & Brötchen');
    ($this->gp)('Exot', '09', 'Freitext ohne Verwaltung');

    expect($this->vocab->listSubCategories($this->rootTeam, '09', nurVerwaltet: true)->pluck('sub_category')->all())->toBe(['09.1 Brot & Brötchen'])
        ->and($this->vocab->listSubCategories($this->rootTeam, '09')->pluck('sub_category')->all())->toBe(['09.1 Brot & Brötchen', 'Freitext ohne Verwaltung']);
});

it('Migration: Eintrag ohne Nummer bekommt den nummerierten GP-Wert — Nummer aus den GPs, nicht aus der Position', function () {
    $brot = ($this->sub)('09', 'Brot & Brötchen', 1);
    $wasser = ($this->sub)('15', 'Wasser', 4);
    ($this->gp)('Brötchen', '09', '09.1 Brot & Brötchen');
    ($this->gp)('Mineralwasser', '15', '15.5 Wasser');

    $this->migration->up();

    expect($brot->fresh()->name)->toBe('09.1 Brot & Brötchen')
        ->and($wasser->fresh()->name)->toBe('15.5 Wasser')
        ->and($this->vocab->listSubCategories($this->rootTeam, '09')->pluck('sub_category')->all())->toBe(['09.1 Brot & Brötchen']);
});

it('Migration: ohne eindeutigen GP-Wert bleibt der Eintrag; gibt es den nummerierten schon, wird der alte gelöscht; zweiter Lauf ändert nichts', function () {
    $ohne = ($this->sub)('09', 'Knabbereien', 6);
    $alt = ($this->sub)('01', 'Pilze', 6);
    $neu = ($this->sub)('01', '01.6 Pilze', 6);
    ($this->gp)('Champignon', '01', '01.6 Pilze');

    $this->migration->up();
    $this->migration->up();

    expect($ohne->fresh()->name)->toBe('Knabbereien')
        ->and(FoodAlchemistWarengruppeSubkategorie::withTrashed()->find($alt->id)->trashed())->toBeTrue()
        ->and($neu->fresh()->name)->toBe('01.6 Pilze')
        ->and(DB::table('foodalchemist_commodity_group_subcategories')->whereNull('deleted_at')->where('commodity_group_code', '01')->count())->toBe(1);
});
