<?php

use Platform\FoodAlchemist\Models\FoodAlchemistConcept;
use Platform\FoodAlchemist\Models\FoodAlchemistFormat;
use Platform\FoodAlchemist\Models\FoodAlchemistFormatSlot;
use Platform\FoodAlchemist\Models\FoodAlchemistOfferBlock;
use Platform\FoodAlchemist\Models\FoodAlchemistOfferChapter;
use Platform\FoodAlchemist\Services\AngebotService;
use Platform\FoodAlchemist\Services\OfferCompositionService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * #380 Composer — Angebot-Aufbau (eigene Tabellen offer_chapters/offer_blocks) nach
 * Foodbook-Vorbild: Kapitel/Block-CRUD, Format-Kapitel (additiv | alternativen),
 * interna-freie Komposition, idempotente Concept-Anbindung.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->user = $this->makeUser($this->rootTeam);
    $this->actingAs($this->user);
    $this->svc = app(AngebotService::class);
    $this->comp = app(OfferCompositionService::class);
    $this->angebot = $this->svc->create($this->rootTeam, ['name' => 'Gala 2027', 'personen' => 50]);
});

/** Zwei Katalog-Concepts mit gesetzten Preis-Caches (deterministische Format-Additiv-/Range-Mathematik). */
function macheConcept(int $teamId, string $name, float $vk, float $ek): FoodAlchemistConcept
{
    return FoodAlchemistConcept::create([
        'team_id' => $teamId, 'name' => $name, 'kind' => 'concept', 'status' => 'active',
        'price_per_person_cache' => $vk, 'ek_per_person_cache' => $ek,
    ]);
}

it('Kapitel + Block-CRUD: anlegen, Header, entfernen', function () {
    $kap = $this->comp->addKapitel($this->rootTeam, $this->angebot->id, ['title' => 'Vorspeisen']);
    expect($kap->offer_id)->toBe($this->angebot->id)->and($kap->title)->toBe('Vorspeisen');

    $header = $this->comp->addBlock($this->rootTeam, $kap->id, ['type' => 'header', 'label' => 'Kaltes']);
    expect($header->type)->toBe('header');

    $c = macheConcept($this->rootTeam->id, 'Suppe', 8.0, 2.0);
    $block = $this->comp->addBlock($this->rootTeam, $kap->id, ['type' => 'concept_ref', 'concept_id' => $c->id]);
    // Positionen sind 1-basiert (max+1, wie FoodbookService): Header=1, concept_ref=2.
    expect($block->type)->toBe('concept_ref')->and($block->position)->toBe(2);

    $this->comp->deleteBlock($this->rootTeam, $header->id);
    expect(FoodAlchemistOfferBlock::where('chapter_id', $kap->id)->count())->toBe(1);

    $this->comp->deleteKapitel($this->rootTeam, $kap->id);
    expect(FoodAlchemistOfferChapter::where('offer_id', $this->angebot->id)->count())->toBe(0);
});

it('Format-Kapitel ADDITIV: Editionen werden summiert', function () {
    $c1 = macheConcept($this->rootTeam->id, 'Frühstück', 10.0, 3.0);
    $c2 = macheConcept($this->rootTeam->id, 'Dinner', 15.0, 5.0);
    $format = FoodAlchemistFormat::create(['team_id' => $this->rootTeam->id, 'name' => 'Tages-VA', 'status' => 'aktiv']);
    FoodAlchemistFormatSlot::create(['team_id' => $this->rootTeam->id, 'format_id' => $format->id, 'type' => 'concept', 'concept_id' => $c1->id, 'position' => 0]);
    FoodAlchemistFormatSlot::create(['team_id' => $this->rootTeam->id, 'format_id' => $format->id, 'type' => 'concept', 'concept_id' => $c2->id, 'position' => 1]);

    $kap = $this->comp->insertFormatKapitel($this->rootTeam, $this->angebot->id, $format->id);
    expect($kap->istFormatKapitel())->toBeTrue()->and($kap->format_price_mode)->toBe('additiv');

    $agg = $this->comp->kapitelAggregat($this->rootTeam, $kap->fresh());
    expect($agg['vk_pro_person'])->toBe(25.0)->and($agg['ek_pro_person'])->toBe(8.0)->and($agg['preis_range'])->toBeNull();

    $einheiten = $this->comp->preisEinheiten($this->rootTeam, $this->angebot->fresh());
    expect($einheiten['concepts'])->toHaveCount(2)->and($einheiten['alternativen'])->toBeEmpty();
});

it('Format-Kapitel ALTERNATIVEN: Preis-Range statt Summe, nicht additiv', function () {
    $c1 = macheConcept($this->rootTeam->id, 'Menü A', 40.0, 12.0);
    $c2 = macheConcept($this->rootTeam->id, 'Menü B', 60.0, 20.0);
    $format = FoodAlchemistFormat::create(['team_id' => $this->rootTeam->id, 'name' => 'Hochzeit', 'status' => 'aktiv']);
    FoodAlchemistFormatSlot::create(['team_id' => $this->rootTeam->id, 'format_id' => $format->id, 'type' => 'concept', 'concept_id' => $c1->id, 'position' => 0]);
    FoodAlchemistFormatSlot::create(['team_id' => $this->rootTeam->id, 'format_id' => $format->id, 'type' => 'concept', 'concept_id' => $c2->id, 'position' => 1]);

    $kap = $this->comp->insertFormatKapitel($this->rootTeam, $this->angebot->id, $format->id);
    $this->comp->setFormatPriceMode($this->rootTeam, $kap->id, 'alternativen');

    $agg = $this->comp->kapitelAggregat($this->rootTeam, $kap->fresh());
    expect($agg['vk_pro_person'])->toBeNull(); // Showcase — kein additiver Summand
    expect($agg['preis_range']['min'])->toBe(40.0)->and($agg['preis_range']['max'])->toBe(60.0);

    $einheiten = $this->comp->preisEinheiten($this->rootTeam, $this->angebot->fresh());
    expect($einheiten['concepts'])->toBeEmpty()->and($einheiten['alternativen'])->toHaveCount(1);
    expect($einheiten['alternativen'][0]['min'])->toBe(40.0)->and($einheiten['alternativen'][0]['max'])->toBe(60.0);
});

it('komposition ist interna-frei ohne intern-Flag', function () {
    $c = macheConcept($this->rootTeam->id, 'Suppe', 8.0, 2.0);
    $kap = $this->comp->addKapitel($this->rootTeam, $this->angebot->id, ['title' => 'Menü']);
    $this->comp->addBlock($this->rootTeam, $kap->id, ['type' => 'concept_ref', 'concept_id' => $c->id]);

    $kunde = $this->comp->komposition($this->rootTeam, $this->angebot->fresh(), null, false);
    expect($kunde['kapitel'][0])->not->toHaveKey('ek_pro_person');
    $intern = $this->comp->komposition($this->rootTeam, $this->angebot->fresh(), null, true);
    expect($intern['kapitel'][0])->toHaveKey('ek_pro_person');
});

it('referenziereConcept schreibt idempotent EINEN concept_ref-Block', function () {
    $c = macheConcept($this->rootTeam->id, 'Katalog-Menü', 20.0, 6.0);
    $this->svc->referenziereConcept($this->rootTeam, $this->angebot->id, $c->id);
    $this->svc->referenziereConcept($this->rootTeam, $this->angebot->id, $c->id); // doppelt

    $bloecke = FoodAlchemistOfferBlock::where('concept_id', $c->id)
        ->whereHas('chapter', fn ($q) => $q->where('offer_id', $this->angebot->id))->count();
    expect($bloecke)->toBe(1);

    $this->svc->entferneReferenz($this->rootTeam, $this->angebot->id, $c->id);
    expect(FoodAlchemistOfferBlock::where('concept_id', $c->id)->count())->toBe(0);
});

it('Tenancy: fremdes Team kann den Aufbau nicht ändern', function () {
    $kap = $this->comp->addKapitel($this->rootTeam, $this->angebot->id, ['title' => 'Menü']);
    $this->actingAs($this->makeUser($this->childA));
    $verweigert = false;
    try {
        $this->comp->addBlock($this->childA, $kap->id, ['type' => 'header', 'label' => 'X']);
    } catch (\Throwable) {
        $verweigert = true;
    }
    expect($verweigert)->toBeTrue();
});
