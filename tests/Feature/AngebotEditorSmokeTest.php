<?php

use Livewire\Livewire;
use Platform\FoodAlchemist\Livewire\Angebote\Editor;
use Platform\FoodAlchemist\Models\FoodAlchemistConcept;
use Platform\FoodAlchemist\Services\AngebotService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * #380 Composer — Fork-Integrations-Smoke: der geforkte Foodbook-Editor (Angebote\Editor)
 * mountet + rendert ohne Fehler und die Kern-Aktionen (Kapitel/Concept/Kalkulation) laufen.
 * Fängt Naht-Fehler (undefined Property/Methode/render-Key) zwischen den parallel geforkten
 * Bausteinen (Komponente ↔ Blade ↔ Service).
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam));
    $this->svc = app(AngebotService::class);
});

it('Editor mountet + rendert ohne Fehler (leeres Angebot)', function () {
    $angebot = $this->svc->create($this->rootTeam, ['name' => 'Smoke-Angebot', 'personen' => 20]);
    Livewire::test(Editor::class)->call('oeffnen', $angebot->id)->assertOk();
});

it('Editor: Kapitel anlegen + Concept einbuchen + Kalkulation rendert', function () {
    $angebot = $this->svc->create($this->rootTeam, ['name' => 'Smoke-Voll', 'personen' => 30]);
    $c = FoodAlchemistConcept::create([
        'team_id' => $this->rootTeam->id, 'name' => 'VorspeiseSmoke', 'kind' => 'concept', 'status' => 'active',
        'price_per_person_cache' => 9.0, 'ek_per_person_cache' => 3.0,
    ]);

    $t = Livewire::test(Editor::class)->call('oeffnen', $angebot->id)->assertOk();
    $t->call('kapitelNeu')->assertOk();

    // erstes Kapitel wählen + ein Concept als Block einbuchen
    $kapId = \Platform\FoodAlchemist\Models\FoodAlchemistOfferChapter::where('offer_id', $angebot->id)->orderBy('position')->value('id');
    expect($kapId)->not->toBeNull();
    $t->call('kapitelWaehle', $kapId)->assertOk()
        ->call('conceptHinzu', $c->id)->assertOk();

    expect(\Platform\FoodAlchemist\Models\FoodAlchemistOfferBlock::where('chapter_id', $kapId)->where('type', 'concept_ref')->count())->toBeGreaterThan(0);
});

it('Editor: Präsentation veröffentlichen erzeugt einen Kunden-Link', function () {
    $angebot = $this->svc->create($this->rootTeam, ['name' => 'Smoke-Präsi', 'personen' => 25]);
    $c = FoodAlchemistConcept::create([
        'team_id' => $this->rootTeam->id, 'name' => 'MenüSmoke', 'kind' => 'concept', 'status' => 'active',
        'price_per_person_cache' => 15.0, 'ek_per_person_cache' => 5.0,
    ]);
    $comp = app(\Platform\FoodAlchemist\Services\OfferCompositionService::class);
    $kap = $comp->addKapitel($this->rootTeam, $angebot->id, ['title' => 'Menü']);
    $comp->addBlock($this->rootTeam, $kap->id, ['type' => 'concept_ref', 'concept_id' => $c->id]);

    $t = \Livewire\Livewire::test(Editor::class)->call('oeffnen', $angebot->id)
        ->set('presentationGueltigBis', now()->addDays(30)->toDateString())
        ->call('veroeffentlichen')->assertOk();

    expect($t->get('presentationFehler'))->toBeNull();
    $angebot->refresh();
    expect($angebot->presentation_enabled)->toBeTrue()
        ->and($angebot->isPresentationLive())->toBeTrue()
        ->and($angebot->presentationPublicRef())->not->toBeNull();

    // öffentlicher Link rendert (200) + interna-frei.
    $this->get('/p/angebot/' . $angebot->presentationPublicRef())->assertOk()->assertSee('Smoke-Präsi');
});
