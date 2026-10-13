<?php

use Illuminate\Support\Facades\DB;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistComponentEquivalent;
use Platform\FoodAlchemist\Models\FoodAlchemistDishClass;
use Platform\FoodAlchemist\Models\FoodAlchemistServierform;
use Platform\FoodAlchemist\Models\FoodAlchemistVocabEinheit;
use Platform\FoodAlchemist\Services\ComponentEquivalentService;
use Platform\FoodAlchemist\Services\VocabularyService;
use Platform\FoodAlchemist\Support\BasisKatalog;
use Platform\FoodAlchemist\Support\MasterTeam;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 82 §13 I (Grundsatz (a)) — Basis-Kataloge: global lesen alle, schreiben nur der Master, das Team ergänzt eigene Zeilen.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    config(['foodalchemist.master_team_id' => null]);
    MasterTeam::flush();
    $this->master = Team::create(['name' => 'BHG.DIGITAL', 'user_id' => 1, 'personal_team' => false]);
    $this->einheit = FoodAlchemistVocabEinheit::create(['team_id' => null, 'slug' => 'kg-basis', 'display_de' => 'kg']);
    $this->mitMaster = function () {
        config(['foodalchemist.master_team_id' => $this->master->id]);
        MasterTeam::flush();
    };
});

it('★ global lesen alle, schreiben nur der Master (Einheit)', function () {
    ($this->mitMaster)();
    $svc = app(VocabularyService::class);

    // Mandant (nicht Master) sieht die Basis-Einheit, ändert sie aber nicht
    expect(FoodAlchemistVocabEinheit::visibleToTeam($this->childA)->where('slug', 'kg-basis')->exists())->toBeTrue();
    expect(fn () => $svc->updateEinheit($this->childA, $this->einheit->id, ['display_de' => 'Fremd']))->toThrow(RuntimeException::class);
    expect(fn () => $svc->deleteEinheit($this->childA, $this->einheit->id))->toThrow(RuntimeException::class);

    // Master ändert sie
    $svc->updateEinheit($this->master, $this->einheit->id, ['display_de' => 'Kilogramm']);
    expect($this->einheit->refresh()->display_de)->toBe('Kilogramm');
});

it('Ohne Master-Konfiguration schreibt niemand global (fail closed); eigene Zeilen bleiben schreibbar', function () {
    $svc = app(VocabularyService::class);

    expect(fn () => $svc->updateEinheit($this->rootTeam, $this->einheit->id, ['display_de' => 'X']))->toThrow(RuntimeException::class);

    $eigene = $svc->createEinheit($this->childA, ['slug' => 'eigen-a', 'display_de' => 'eigen']);
    expect($svc->updateEinheit($this->childA, $eigene->id, ['display_de' => 'eigen2'])->display_de)->toBe('eigen2');
});

it('Team ergänzt eigene Zeilen, fremde Teams schreiben sie nicht', function () {
    ($this->mitMaster)();
    $svc = app(VocabularyService::class);
    $eigene = $svc->createEinheit($this->childA, ['slug' => 'eigen-a', 'display_de' => 'eigen']);

    expect(fn () => $svc->updateEinheit($this->childB, $eigene->id, ['display_de' => 'x']))->toThrow(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
});

it('BasisKatalog::mayWrite: eigene Zeile oder global als Master, sonst nie', function () {
    ($this->mitMaster)();

    expect(BasisKatalog::mayWrite($this->childA->id, $this->childA))->toBeTrue()
        ->and(BasisKatalog::mayWrite(null, $this->master))->toBeTrue()
        ->and(BasisKatalog::mayWrite(null, $this->childA))->toBeFalse()
        ->and(BasisKatalog::mayWrite($this->childB->id, $this->childA))->toBeFalse();
});

it('★ Migration: Servierformen und Komponenten-Äquivalenz können global liegen (team_id NULL)', function () {
    $form = FoodAlchemistServierform::create(['team_id' => null, 'code' => 'basis-teller', 'label' => 'Teller']);
    $aeq = FoodAlchemistComponentEquivalent::create([
        'team_id' => null, 'source_kind' => 'gp', 'source_id' => 1, 'alt_kind' => 'gp', 'alt_id' => 2,
    ]);

    expect(FoodAlchemistServierform::visibleToTeam($this->childA)->where('code', 'basis-teller')->exists())->toBeTrue()
        ->and(FoodAlchemistComponentEquivalent::visibleToTeam($this->childA)->whereKey($aeq->id)->exists())->toBeTrue();
    expect($form->team_id)->toBeNull();
});

it('Komponenten-Äquivalenz: global lösen nur der Master', function () {
    ($this->mitMaster)();
    $aeq = FoodAlchemistComponentEquivalent::create(['team_id' => null, 'source_kind' => 'gp', 'source_id' => 1, 'alt_kind' => 'gp', 'alt_id' => 2]);
    $svc = app(ComponentEquivalentService::class);

    $svc->loese($this->childA, $aeq->id);
    expect(FoodAlchemistComponentEquivalent::whereKey($aeq->id)->exists())->toBeTrue();   // Mandant: nichts passiert
    $svc->loese($this->master, $aeq->id);
    expect(FoodAlchemistComponentEquivalent::whereKey($aeq->id)->exists())->toBeFalse();
});
