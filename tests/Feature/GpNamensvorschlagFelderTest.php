<?php

use Livewire\Livewire;
use Platform\FoodAlchemist\Livewire\Gps\GpModal;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplier;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem;
use Platform\FoodAlchemist\Services\Ai\AiGatewayService;
use Platform\FoodAlchemist\Services\Ai\AiProposal;
use Platform\FoodAlchemist\Services\GpNamingService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Bugfix 27.10. (Kurator, Dominique freigegeben): „Name aus Lieferantenartikel ableiten“ übernimmt Name UND Felder. Vorher landeten die
 * KI-Felder nur in einem lokalen Builder, „Übernehmen“ setzte nur den Namen → Hinweis „Name passt nicht zu den Feldern“ (I4).
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam, 'Root User'));
    $lieferant = FoodAlchemistSupplier::create(['team_id' => $this->rootTeam->id, 'name' => 'Fischhandel Test']);
    $la = FoodAlchemistSupplierItem::create(['team_id' => $this->rootTeam->id, 'supplier_id' => $lieferant->id,
        'designation' => 'Lachs Seite geräuchert geschnitten', 'article_number' => 'L1']);
    $this->gp = $this->makeGp($this->rootTeam, 'Lachs: Filet, frisch');
    $this->gp->update(['lead_la_supplier_item_id' => $la->id]);
    $this->mock(AiGatewayService::class)->shouldReceive('propose')->with('gp.suggest', Mockery::any())
        ->andReturn(new AiProposal(['hauptzutat' => 'Lachs', 'condition' => 'geräuchert', 'processing' => 'geschnitten', 'form' => 'Seite'], 0.9));
    $this->modal = fn () => Livewire::test(GpModal::class)->call('oeffnen', $this->gp->id)
        ->set('builder.hauptzutat', 'Lachs')->set('builder.condition', 'frisch')->set('builder.form', 'Filet')->set('builder.flavor', 'Dill')
        ->set('builder.processing', '')->set('manuellerName', 'Lachs: Dill, Filet, frisch');
});

it('Vorschlag zeigt nur die Felder, die sich ändern; Übernehmen schreibt Name UND Felder — gefüllte werden überschrieben, nicht gelieferte bleiben', function () {
    $m = ($this->modal)()->call('nameAusLeadLa')
        ->assertSet('fehler', null)
        ->assertSeeHtml('data-name-vorschlag-aenderung="Zustand"')
        ->assertSeeHtml('data-name-vorschlag-aenderung="Form"')
        ->assertSeeHtml('data-name-vorschlag-aenderung="Verarbeitung"')
        ->assertDontSeeHtml('data-name-vorschlag-aenderung="Hauptzutat"');   // gleich geblieben → nicht gelistet

    $m->call('nameVorschlagUebernehmen')
        ->assertSet('builder.condition', 'geräuchert')                        // vorher „frisch“ → überschrieben
        ->assertSet('builder.form', 'Seite')                                  // vorher „Filet“ → überschrieben
        ->assertSet('builder.processing', 'geschnitten')
        ->assertSet('builder.flavor', 'Dill')                                 // von der KI nicht geliefert → bleibt
        ->assertSet('nameVorschlag', null)
        ->assertSet('nameVorschlagFelder', []);

    $builder = $m->get('builder');
    $naming = app(GpNamingService::class);
    expect($m->get('manuellerName'))->toBe($naming->renderGpName($builder))
        ->and(implode(' ', $naming->validateGpName($m->get('manuellerName'), $builder)['warnings']))->not->toContain(GpNamingService::HINWEIS_NAME_FELDER);
    $m->assertDontSeeHtml(GpNamingService::HINWEIS_NAME_FELDER);
});

it('Verwerfen lässt Name und Felder unverändert', function () {
    ($this->modal)()->call('nameAusLeadLa')
        ->call('nameVorschlagVerwerfen')
        ->assertSet('nameVorschlag', null)
        ->assertSet('nameVorschlagFelder', [])
        ->assertSet('builder.condition', 'frisch')
        ->assertSet('builder.form', 'Filet')
        ->assertSet('builder.processing', '')
        ->assertSet('manuellerName', 'Lachs: Dill, Filet, frisch');
});

it('Hinweis „Name passt nicht zu den Feldern“ nennt den konkreten Unterschied in Klartext (statt „Drift … (I4)“)', function () {
    $n = app(GpNamingService::class);
    $w = implode(' ', $n->validateGpName('Lachs: Filet, frisch', ['hauptzutat' => 'Lachs', 'condition' => 'geräuchert', 'form' => 'Filet'])['warnings']);

    expect($w)->toContain(GpNamingService::HINWEIS_NAME_FELDER)
        ->and($w)->toContain('„geräuchert“ aus dem Feld Zustand fehlt im Namen')
        ->and($w)->toContain('„frisch“ steht im Namen, aber in keinem Feld')
        ->and($w)->not->toContain('Drift')->and($w)->not->toContain('I4');
});
