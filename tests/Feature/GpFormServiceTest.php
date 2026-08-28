<?php

use Livewire\Livewire;
use Platform\FoodAlchemist\Livewire\Gps\GpModal;
use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Models\FoodAlchemistGpForm;
use Platform\FoodAlchemist\Services\Ai\AiGatewayService;
use Platform\FoodAlchemist\Services\Ai\AiProposal;
use Platform\FoodAlchemist\Services\GpFormService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * #9 (Dominique 2026-08-28): GP-Formen-Modell — Gramm je Form (Stück/Scheibe/Würfel …). Basis für
 * KI-Formen-Schätzung + gefilterten Rezept-Einheiten-Dropdown. „stk" spiegelt piece_default_g.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam));
    $this->svc = app(GpFormService::class);
    $this->gp = $this->makeGp($this->rootTeam, 'Zwiebel');
});

it('setForm: upsert + stk spiegelt piece_default_g', function () {
    $this->svc->setForm($this->rootTeam, $this->gp->id, 'stk', 150);
    $this->svc->setForm($this->rootTeam, $this->gp->id, 'wuerfel', 5);

    expect($this->svc->list($this->gp->id)->pluck('gramm', 'form_slug')->map(fn ($g) => (float) $g)->all())
        ->toMatchArray(['stk' => 150.0, 'wuerfel' => 5.0])
        ->and((float) FoodAlchemistGp::find($this->gp->id)->piece_default_g)->toBe(150.0);

    // upsert: gleiche Form → Update, keine zweite Zeile; piece_default_g zieht nach.
    $this->svc->setForm($this->rootTeam, $this->gp->id, 'stk', 160);
    expect(FoodAlchemistGpForm::where('gp_id', $this->gp->id)->where('form_slug', 'stk')->count())->toBe(1)
        ->and((float) FoodAlchemistGp::find($this->gp->id)->piece_default_g)->toBe(160.0);
});

it('setForm weist unbekannte Form + Gewicht<=0 ab', function () {
    expect(fn () => $this->svc->setForm($this->rootTeam, $this->gp->id, 'quatsch', 10))->toThrow(RuntimeException::class);
    expect(fn () => $this->svc->setForm($this->rootTeam, $this->gp->id, 'stk', 0))->toThrow(RuntimeException::class);
});

it('removeForm stk leert auch piece_default_g', function () {
    $this->svc->setForm($this->rootTeam, $this->gp->id, 'stk', 150);
    $this->svc->removeForm($this->rootTeam, $this->gp->id, 'stk');
    expect(FoodAlchemistGpForm::where('gp_id', $this->gp->id)->count())->toBe(0)
        ->and(FoodAlchemistGp::find($this->gp->id)->piece_default_g)->toBeNull();
});

it('setForm reaktiviert eine zuvor entfernte Form (SoftDeletes + unique-Slot)', function () {
    $this->svc->setForm($this->rootTeam, $this->gp->id, 'wuerfel', 5);
    $this->svc->removeForm($this->rootTeam, $this->gp->id, 'wuerfel');
    // Wieder anlegen darf NICHT am unique(gp_id,form_slug) der trashed-Zeile scheitern.
    $this->svc->setForm($this->rootTeam, $this->gp->id, 'wuerfel', 7);

    expect(FoodAlchemistGpForm::where('gp_id', $this->gp->id)->where('form_slug', 'wuerfel')->count())->toBe(1)
        ->and((float) FoodAlchemistGpForm::where('gp_id', $this->gp->id)->where('form_slug', 'wuerfel')->value('gramm'))->toBe(7.0);
});

it('GpModal (Livewire): Form hinzufügen + entfernen', function () {
    Livewire::test(GpModal::class)
        ->call('oeffnen', $this->gp->id)
        ->set('formNeuSlug', 'wuerfel')
        ->set('formNeuGramm', '5')
        ->call('formSetzen')
        ->assertHasNoErrors();
    expect(FoodAlchemistGpForm::where('gp_id', $this->gp->id)->where('form_slug', 'wuerfel')->exists())->toBeTrue();

    Livewire::test(GpModal::class)
        ->call('oeffnen', $this->gp->id)
        ->call('formEntfernen', 'wuerfel')
        ->assertHasNoErrors();
    expect(FoodAlchemistGpForm::where('gp_id', $this->gp->id)->where('form_slug', 'wuerfel')->exists())->toBeFalse();
});

it('estimateKi: nur gültige Formen als ki, manuelle bleiben (Override-First)', function () {
    $this->svc->setForm($this->rootTeam, $this->gp->id, 'stk', 200, 'manual');

    $this->mock(AiGatewayService::class, fn ($m) => $m->shouldReceive('propose')
        ->andReturn(new AiProposal(['einheiten' => [
            ['unit' => 'stk', 'gewicht_g' => 150],        // manuell → NICHT überschreiben
            ['unit' => 'wuerfel', 'gewicht_g' => 5],       // gültig → ki
            ['unit' => 'quatsch', 'gewicht_g' => 9],       // ungültig → skip
            ['unit' => 'scheibe', 'gewicht_g' => 0],       // <=0 → skip
        ]], 0.9, 'm', [], 'x')));

    $n = app(GpFormService::class)->estimateKi($this->rootTeam, $this->gp->id);

    expect($n)->toBe(1)
        ->and((float) FoodAlchemistGpForm::where('gp_id', $this->gp->id)->where('form_slug', 'stk')->value('gramm'))->toBe(200.0)
        ->and(FoodAlchemistGpForm::where('gp_id', $this->gp->id)->where('form_slug', 'wuerfel')->value('source'))->toBe('ki');
});
