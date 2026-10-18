<?php

use Livewire\Livewire;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Tools\ToolRegistry;
use Platform\FoodAlchemist\Livewire\Angebote\Editor;
use Platform\FoodAlchemist\Models\FoodAlchemistConcept;
use Platform\FoodAlchemist\Models\FoodAlchemistFormat;
use Platform\FoodAlchemist\Models\FoodAlchemistFormatSlot;
use Platform\FoodAlchemist\Services\AngebotService;
use Platform\FoodAlchemist\Services\OfferCompositionService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 85 D-2 (zurück in die Oberfläche): Preisart eines Format-Kapitels im Angebot —
 * „Summe aller Teile“ (additiv) oder „Alternativen (von–bis)“. UI im Kapitel-Kopf + MCP-Schreibweg
 * über offer_chapter.PUT, beide über OfferCompositionService::setFormatPriceMode.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->user = $this->makeUser($this->rootTeam);
    $this->actingAs($this->user);
    $this->comp = app(OfferCompositionService::class);
    $this->angebot = app(AngebotService::class)->create($this->rootTeam, ['name' => 'Hochzeit 2027', 'personen' => 80]);

    $concept = fn (string $name, float $vk, float $ek) => FoodAlchemistConcept::create([
        'team_id' => $this->rootTeam->id, 'name' => $name, 'kind' => 'concept', 'status' => 'active',
        'price_per_person_cache' => $vk, 'ek_per_person_cache' => $ek,
    ]);
    $format = FoodAlchemistFormat::create(['team_id' => $this->rootTeam->id, 'name' => 'Hochzeit', 'status' => 'aktiv']);
    foreach ([$concept('Menü A', 40.0, 12.0), $concept('Menü B', 60.0, 20.0)] as $i => $c) {
        FoodAlchemistFormatSlot::create(['team_id' => $this->rootTeam->id, 'format_id' => $format->id, 'type' => 'concept', 'concept_id' => $c->id, 'position' => $i]);
    }
    $this->formatKapitel = $this->comp->insertFormatKapitel($this->rootTeam, $this->angebot->id, $format->id);
    $this->textKapitel = $this->comp->addKapitel($this->rootTeam, $this->angebot->id, ['title' => 'Begrüßung']);

    $this->kontext = new ToolContext($this->user, $this->rootTeam);
    $this->put = fn (array $a, ?ToolContext $k = null) => app(ToolRegistry::class)
        ->get('foodalchemist.offer_chapter.PUT')->execute($a, $k ?? $this->kontext);
});

it('UI: Format-Kapitel zeigt die Auswahl; Alternativen → Spanne von–bis, zurück auf Summe → Summe', function () {
    $lw = Livewire::test(Editor::class)
        ->call('oeffnen', $this->angebot->id)
        ->call('kapitelWaehle', $this->formatKapitel->id)
        ->assertSeeHtml('data-angebot-formatpreis')
        ->assertSee('Summe aller Teile');

    $lw->call('formatPreisModus', $this->formatKapitel->id, 'alternativen')->assertOk();
    $agg = $this->comp->kapitelAggregat($this->rootTeam, $this->formatKapitel->fresh());
    expect($this->formatKapitel->fresh()->format_price_mode)->toBe('alternativen')
        ->and($agg['vk_pro_person'])->toBeNull()
        ->and($agg['preis_range']['min'])->toBe(40.0)
        ->and($agg['preis_range']['max'])->toBe(60.0);

    $lw->call('formatPreisModus', $this->formatKapitel->id, 'additiv')->assertOk();
    $agg = $this->comp->kapitelAggregat($this->rootTeam, $this->formatKapitel->fresh());
    expect($this->formatKapitel->fresh()->format_price_mode)->toBe('additiv')
        ->and($agg['vk_pro_person'])->toBe(100.0)
        ->and($agg['preis_range'])->toBeNull();
});

it('UI: Kapitel ohne Format zeigt keine Auswahl; ein Aufruf ändert nichts und meldet den Fehler', function () {
    Livewire::test(Editor::class)
        ->call('oeffnen', $this->angebot->id)
        ->call('kapitelWaehle', $this->textKapitel->id)
        ->assertDontSeeHtml('data-angebot-formatpreis')
        ->call('formatPreisModus', $this->textKapitel->id, 'alternativen')
        ->assertDispatched('fa-saved', type: 'error');

    expect($this->textKapitel->fresh()->format_price_mode)->toBe('additiv');
});

it('MCP: offer_chapter.PUT setzt format_price_mode, auch zusammen mit anderen Feldern', function () {
    $r = ($this->put)(['chapter_id' => $this->formatKapitel->id, 'felder' => [
        'format_price_mode' => 'alternativen', 'consumer_title' => 'Ihre Menüwahl',
    ]]);

    expect($r->success)->toBeTrue((string) ($r->error ?? ''))
        ->and($r->data['updated'])->toContain('format_price_mode')->toContain('consumer_title');
    $kap = $this->formatKapitel->fresh();
    expect($kap->format_price_mode)->toBe('alternativen')->and($kap->consumer_title)->toBe('Ihre Menüwahl');
});

it('MCP: Kapitel ohne Format → VALIDATION_ERROR, und auch die übrigen Felder bleiben ungeschrieben', function () {
    $r = ($this->put)(['chapter_id' => $this->textKapitel->id, 'felder' => [
        'format_price_mode' => 'alternativen', 'consumer_title' => 'Darf nicht landen',
    ]]);

    expect($r->success)->toBeFalse()->and($r->errorCode)->toBe('VALIDATION_ERROR');
    expect($this->textKapitel->fresh()->consumer_title)->not->toBe('Darf nicht landen');
});

it('MCP: unbekannter Modus → VALIDATION_ERROR; fremdes Team → ACCESS_DENIED (gleiche Eigentums-Prüfung)', function () {
    expect(($this->put)(['chapter_id' => $this->formatKapitel->id, 'felder' => ['format_price_mode' => 'summe']])->errorCode)
        ->toBe('VALIDATION_ERROR');

    $fremd = new ToolContext($this->makeUser($this->childA), $this->childA);
    expect(($this->put)(['chapter_id' => $this->formatKapitel->id, 'felder' => ['format_price_mode' => 'alternativen']], $fremd)->errorCode)
        ->toBe('ACCESS_DENIED');
    expect($this->formatKapitel->fresh()->format_price_mode)->toBe('additiv');
});
