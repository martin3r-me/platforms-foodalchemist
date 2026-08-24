<?php

use Livewire\Livewire;
use Platform\FoodAlchemist\Livewire\Formate\Browser;
use Platform\FoodAlchemist\Livewire\Formate\Editor;
use Platform\FoodAlchemist\Models\FoodAlchemistEinsatzmoment;
use Platform\FoodAlchemist\Models\FoodAlchemistEventtyp;
use Platform\FoodAlchemist\Models\FoodAlchemistSaison;
use Platform\FoodAlchemist\Models\FoodAlchemistServierform;
use Platform\FoodAlchemist\Services\FormatService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Format-Umbau F1: Format bekommt die Concept-Dimensionen (Facetten) — dieselben
 * in-den-Einstellungen-gepflegten Vokabulare wie das Concept (geteilte Tabellen).
 * Deckt Service (Spalten + Pivot-Sync), Browser-Filter und Editor (laden/toggle) ab.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam));
    $this->svc = app(FormatService::class);

    // Geteiltes Dimensions-Vokabular (wie aus den Einstellungen).
    $this->sf = FoodAlchemistServierform::create(['team_id' => $this->rootTeam->id, 'code' => 'buffet', 'label' => 'Buffet', 'is_inactive' => false, 'sort_order' => 1]);
    $this->et = FoodAlchemistEventtyp::create(['team_id' => $this->rootTeam->id, 'name' => 'Gala/Bankett', 'is_inactive' => false, 'sort_order' => 1]);
    $this->em = FoodAlchemistEinsatzmoment::create(['team_id' => $this->rootTeam->id, 'name' => 'Apéro/Empfang', 'is_inactive' => false, 'sort_order' => 1]);
    $this->sa = FoodAlchemistSaison::create(['team_id' => $this->rootTeam->id, 'name' => 'Sommer', 'is_inactive' => false, 'sort_order' => 1]);
});

it('create + update setzen serving_form_id/event_type_id und synchronisieren die Pivots', function () {
    $f = $this->svc->create($this->rootTeam, [
        'name' => 'CHEFS.CORNER',
        'serving_form_id' => $this->sf->id,
        'event_type_id' => $this->et->id,
        'einsatzmoment_ids' => [$this->em->id],
        'saison_ids' => [$this->sa->id],
    ]);

    $f->refresh();
    expect((int) $f->serving_form_id)->toBe($this->sf->id)
        ->and((int) $f->event_type_id)->toBe($this->et->id)
        ->and($f->serviceMoments->pluck('id')->all())->toBe([$this->em->id])
        ->and($f->seasons->pluck('id')->all())->toBe([$this->sa->id]);

    // Update leert die Servierform (leer → null) und den Einsatzmoment-Pivot.
    $this->svc->update($this->rootTeam, $f->id, ['serving_form_id' => '', 'einsatzmoment_ids' => []]);
    $f->refresh();
    expect($f->serving_form_id)->toBeNull()
        ->and($f->serviceMoments)->toHaveCount(0)
        ->and($f->seasons->pluck('id')->all())->toBe([$this->sa->id]); // Saison unangetastet (Key nicht im Input)
});

it('Pivot-Sync ignoriert nicht-sichtbares Vokabular (kein Cross-Team-Attach)', function () {
    $fremd = \Platform\Core\Models\Team::factory()->create();
    $fremdMoment = FoodAlchemistEinsatzmoment::create(['team_id' => $fremd->id, 'name' => 'Fremd', 'is_inactive' => false, 'sort_order' => 1]);

    $f = $this->svc->create($this->rootTeam, ['name' => 'X', 'einsatzmoment_ids' => [$this->em->id, $fremdMoment->id]]);
    expect($f->serviceMoments->pluck('id')->all())->toBe([$this->em->id]); // fremder Moment gefiltert
})->skip(fn () => ! class_exists(\Database\Factories\Platform\Core\Models\TeamFactory::class), 'Kein TeamFactory');

it('paginateBrowser filtert nach Facetten', function () {
    $treffer = $this->svc->create($this->rootTeam, ['name' => 'Gala-Format', 'serving_form_id' => $this->sf->id]);
    $this->svc->update($this->rootTeam, $treffer->id, ['einsatzmoment_ids' => [$this->em->id]]);
    $this->svc->create($this->rootTeam, ['name' => 'Anderes-Format']); // ohne Dimension

    expect($this->svc->paginateBrowser(['servierform' => (string) $this->sf->id], $this->rootTeam)->total())->toBe(1);
    expect($this->svc->paginateBrowser(['einsatzmoment' => (string) $this->em->id], $this->rootTeam)->total())->toBe(1);
    expect($this->svc->paginateBrowser(['servierform' => '999999'], $this->rootTeam)->total())->toBe(0);
    expect($this->svc->paginateBrowser([], $this->rootTeam)->total())->toBe(2); // ohne Filter beide
});

it('Editor lädt die Facetten ins Formular und toggleFacette persistiert', function () {
    $f = $this->svc->create($this->rootTeam, [
        'name' => 'CHEFS.CORNER', 'serving_form_id' => $this->sf->id, 'einsatzmoment_ids' => [$this->em->id],
    ]);

    $comp = Livewire::test(Editor::class)
        ->call('oeffnen', $f->id)
        ->assertSet('form.serving_form_id', $this->sf->id)
        ->assertSet('form.einsatzmoment_ids', [$this->em->id]);

    // Toggle den Einsatzmoment wieder aus → sofort persistiert.
    $comp->call('toggleFacette', 'einsatzmoment_ids', $this->em->id);
    expect($f->fresh()->serviceMoments)->toHaveCount(0);

    // Und die Saison an → persistiert.
    $comp->call('toggleFacette', 'saison_ids', $this->sa->id);
    expect($f->fresh()->seasons->pluck('id')->all())->toBe([$this->sa->id]);
});

it('Browser-Facetten-Filter grenzt die Formatliste ein', function () {
    $gala = $this->svc->create($this->rootTeam, ['name' => 'Gala-Format', 'serving_form_id' => $this->sf->id]);
    $this->svc->create($this->rootTeam, ['name' => 'Freies-Format']);

    Livewire::test(Browser::class)
        ->call('waehleFacette', 'servierformFilter', (string) $this->sf->id)
        ->assertSet('servierformFilter', (string) $this->sf->id)
        ->assertSee('Gala-Format')
        ->assertDontSee('Freies-Format');
});
