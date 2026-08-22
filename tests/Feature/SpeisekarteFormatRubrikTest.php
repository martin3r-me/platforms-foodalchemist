<?php

use Livewire\Livewire;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Tools\ToolRegistry;
use Platform\FoodAlchemist\Livewire\Speisekarte\Index as SpeisekarteIndex;
use Platform\FoodAlchemist\Models\FoodAlchemistSpeisekarteRubrik;
use Platform\FoodAlchemist\Services\FormatService;
use Platform\FoodAlchemist\Services\SpeisekarteService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 42 (Folge) — Format als Live-Rubrik in der Speisekarte (gleiche Logik wie das Foodbook-
 * Format-Kapitel): insertFormatRubrik legt eine Rubrik mit format_id an; dokumentDaten rendert sie
 * als ist_format (Editionen live), nicht als eigene Positionen.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->user = $this->makeUser($this->rootTeam);
    $this->actingAs($this->user);
    $this->svc = app(SpeisekarteService::class);
    $this->karte = $this->svc->create($this->rootTeam, ['name' => 'Sommerkarte']);
    $this->format = app(FormatService::class)->create($this->rootTeam, ['name' => 'CHEFS.CORNER', 'consumer_name' => 'Chef’s Corner']);
});

it('insertFormatRubrik: legt eine Rubrik mit format_id an (Titel aus dem Format)', function () {
    $rubrik = $this->svc->insertFormatRubrik($this->rootTeam, (int) $this->karte->id, (int) $this->format->id);

    expect((int) $rubrik->format_id)->toBe((int) $this->format->id)
        ->and($rubrik->title)->toBe('CHEFS.CORNER')
        ->and($rubrik->menu_card_id)->toBe($this->karte->id);
    expect(FoodAlchemistSpeisekarteRubrik::where('menu_card_id', $this->karte->id)->whereNotNull('format_id')->count())->toBe(1);
});

it('dokumentDaten: die Format-Rubrik rendert als ist_format (Editionen-Zweig, nicht Positionen)', function () {
    $this->svc->insertFormatRubrik($this->rootTeam, (int) $this->karte->id, (int) $this->format->id);

    $daten = $this->svc->dokumentDaten($this->rootTeam, $this->karte->refresh());
    $fmtRubrik = collect($daten['rubriken'])->firstWhere('ist_format', true);

    expect($fmtRubrik)->not->toBeNull()
        ->and($fmtRubrik['title'])->toBe('Chef’s Corner')      // consumer_name gewinnt
        ->and($fmtRubrik)->toHaveKey('editionen')
        ->and($fmtRubrik['positionen'])->toBe([]);              // Format-Rubrik trägt keine eigenen Positionen
});

it('Livewire: „+ Format"-Picker öffnet + fügt eine Format-Rubrik ein', function () {
    Livewire::test(SpeisekarteIndex::class)
        ->call('waehle', $this->karte->id)
        ->call('formatPickerToggle')
        ->assertSet('formatPickerOffen', true)
        ->call('formatRubrikEinfuegen', $this->format->id);

    expect(FoodAlchemistSpeisekarteRubrik::where('menu_card_id', $this->karte->id)
        ->where('format_id', $this->format->id)->count())->toBe(1);
});

it('MCP speisekarte_format_rubriken.POST: fügt ein + weist Unbekanntes ab', function () {
    $registry = app(ToolRegistry::class);
    $kontext = new ToolContext($this->user, $this->rootTeam);

    $ok = $registry->get('foodalchemist.speisekarte_format_rubriken.POST')
        ->execute(['speisekarte_id' => $this->karte->id, 'format_id' => $this->format->id], $kontext);
    expect($ok->success)->toBeTrue()
        ->and((int) $ok->data['rubrik']['format_id'])->toBe((int) $this->format->id);

    $bad = $registry->get('foodalchemist.speisekarte_format_rubriken.POST')
        ->execute(['speisekarte_id' => $this->karte->id, 'format_id' => 999999], $kontext);
    expect($bad->success)->toBeFalse()->and($bad->errorCode)->toBe('NOT_FOUND');
});
