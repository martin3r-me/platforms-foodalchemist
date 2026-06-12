<?php

use Livewire\Livewire;
use Platform\FoodAlchemist\Livewire\Gps\DetailPanel;
use Platform\FoodAlchemist\Services\Ai\AiGatewayService;
use Platform\FoodAlchemist\Services\Ai\AiProposal;
use Platform\FoodAlchemist\Services\GpAggregateService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * R10 (Ist-Feature): ✨ Allergene/Nährwerte per KI schätzen, wenn keine
 * LA-Daten — GL-07 (Vorschlag → Übernehmen schreibt Override bzw. Fallback-
 * Schicht); der Fallback gilt NUR der Panel-Anzeige, nie der GL-08-Rezept-
 * Aggregation.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam));
    config(['foodalchemist.ai.provider' => 'fake']);
    $this->gp = $this->makeGp($this->rootTeam, 'Berliner gebacken');
});

it('kiNaehrwerte: Vorschlag validiert → Übernehmen schreibt Fallback-Schicht; Aggregat liefert sie NUR mit Flag', function () {
    $this->mock(AiGatewayService::class, function ($mock) {
        $mock->shouldReceive('propose')->andReturn(new AiProposal(
            ['kcal' => 389, 'protein_g' => 6.5, 'fat_g' => 17.2, 'carbs_g' => 51.0, 'salt_g' => 0.6, 'quatsch' => -5], 0.82,
        ));
    });

    Livewire::test(DetailPanel::class, ['gpId' => $this->gp->id])
        ->call('kiNaehrwerte')
        ->assertSet('fehler', null)
        ->assertSet('kiVorschlag.typ', 'naehrwerte')
        ->call('kiUebernehmen');

    $gp = $this->gp->fresh();
    expect((float) $gp->nutri_kcal_per_100g)->toBe(389.0)
        ->and($gp->nutri_quelle)->toBe('ki')
        ->and((float) $gp->nutri_ai_confidence)->toBe(0.82);

    $agg = app(GpAggregateService::class);
    expect($agg->naehrwerte($gp)['energy_kcal']['avg'])->toBeNull()           // Rezept-Pfad: KEIN Fallback
        ->and($agg->naehrwerte($gp, mitKiFallback: true)['energy_kcal']['avg'])->toBe(389.0)
        ->and($agg->naehrwerte($gp, mitKiFallback: true)['quelle'])->toBe('ki');
});

it('kiAllergene: nur gültige Werte werden Vorschlag, unbekannt fliegt; Übernehmen schreibt den Override (GL-01 Prio 1)', function () {
    $this->mock(AiGatewayService::class, function ($mock) {
        $mock->shouldReceive('propose')->andReturn(new AiProposal(
            ['allergene' => ['glutenhaltiges_getreide' => 'enthalten', 'milch' => 'spuren', 'fisch' => 'unbekannt', 'eier' => 'kaese']], 0.9,
        ));
    });

    Livewire::test(DetailPanel::class, ['gpId' => $this->gp->id])
        ->call('kiAllergene')
        ->assertSet('fehler', null)
        ->assertSet('kiVorschlag.werte', ['glutenhaltiges_getreide' => 'enthalten', 'milch' => 'spuren'])
        ->call('kiUebernehmen');

    $gp = $this->gp->fresh();
    expect($gp->allergen_glutenhaltiges_getreide)->toBe('enthalten')
        ->and($gp->allergen_milch)->toBe('spuren')
        ->and($gp->allergen_fisch)->toBeNull()                                 // unbekannt ⇒ KEIN Override (F7.1)
        ->and((float) $gp->allergene_ki_confidence)->toBe(0.9);

    // Aggregat zeigt Override-Quelle
    $allergene = app(GpAggregateService::class)->allergene($gp);
    expect($allergene['glutenhaltiges_getreide']['quelle'])->toBe('override');
});

it('FakeProvider-Echo ⇒ ehrlicher Fehler, nichts geschrieben; Kind-Team blockt am Curate-Gate', function () {
    Livewire::test(DetailPanel::class, ['gpId' => $this->gp->id])
        ->call('kiNaehrwerte')
        ->assertSet('fehler', fn ($f) => str_contains((string) $f, 'echter Provider'));
    expect($this->gp->fresh()->nutri_quelle)->toBeNull();

    $this->actingAs($this->makeUser($this->childA, 'Kind User'));
    Livewire::test(DetailPanel::class, ['gpId' => $this->gp->id])
        ->call('kiAllergene')
        ->assertSet('fehler', fn ($f) => str_contains((string) $f, 'Kurations-Team'));
});
