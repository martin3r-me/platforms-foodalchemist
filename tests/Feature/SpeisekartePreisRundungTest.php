<?php

use Livewire\Livewire;
use Platform\FoodAlchemist\Livewire\Speisekarte\Index as SpeisekarteIndex;
use Platform\FoodAlchemist\Models\FoodAlchemistSpeisekarte;
use Platform\FoodAlchemist\Services\SpeisekarteService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * #7 (Dominique 2026-08-27): Speisekarte-Editor bekommt einen netto/brutto-Umschalter + Brutto-Rundung.
 * Die Rundung wirkt NUR auf die Ausgabe (dokumentDaten), nie auf die gespeicherten Netto-Preise.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam));
    $this->karten = app(SpeisekarteService::class);
});

it('rundeBrutto: alle Modi runden korrekt', function () {
    $svc = $this->karten;
    expect($svc->rundeBrutto(5.077, 'keine'))->toBe(5.08)
        ->and($svc->rundeBrutto(5.07, 'auf_10'))->toBe(5.1)
        ->and($svc->rundeBrutto(5.04, 'auf_10'))->toBe(5.0)
        ->and($svc->rundeBrutto(5.30, 'auf_50'))->toBe(5.5)
        ->and($svc->rundeBrutto(5.07, 'auf_50'))->toBe(5.0)
        ->and($svc->rundeBrutto(5.00, 'auf_90'))->toBe(5.9)   // aufgerundet auf X,90
        ->and($svc->rundeBrutto(5.95, 'auf_90'))->toBe(6.9)   // über 5,90 → nächste X,90
        ->and($svc->rundeBrutto(null, 'auf_90'))->toBeNull();
});

it('dokumentDaten: Brutto-Rundung (auf_90) greift auf die Ausgabe-Preise, Netto bleibt roh', function () {
    $g = $this->makeRecipe($this->rootTeam, 'Filet', ['is_sales_recipe' => true, 'sales_net' => 5.00]);
    $karte = $this->karten->create($this->rootTeam, ['name' => 'K', 'preis_anzeige_brutto' => true]);
    $this->karten->update($this->rootTeam, $karte->id, ['preis_rundung' => 'auf_90']);
    $rubrik = $this->karten->addRubrik($this->rootTeam, $karte->id, ['title' => 'Hauptgänge']);
    $this->karten->addPosition($this->rootTeam, $rubrik->id, ['type' => 'gericht_ref', 'sales_recipe_id' => $g->id]);

    $daten = $this->karten->dokumentDaten($this->rootTeam, $karte->fresh());
    $pos = collect($daten['rubriken'])->flatMap(fn ($r) => $r['positionen'])->firstWhere('typ', 'gericht_ref');

    // 5,00 netto × 1,19 = 5,95 brutto → aufgerundet auf X,90 = 6,90. Netto bleibt 5,00.
    expect((float) $pos['vk_brutto'])->toBe(6.9)
        ->and((float) $pos['vk_netto'])->toBe(5.0)
        ->and($daten['brutto'])->toBeTrue();
});

it('Editor: netto/brutto-Umschalter + Rundung werden geladen und gespeichert', function () {
    $karte = $this->karten->create($this->rootTeam, ['name' => 'K']);

    Livewire::test(SpeisekarteIndex::class)
        ->call('waehle', $karte->id)
        ->assertSet('preisAnzeigeBrutto', true)     // DB-Default
        ->assertSet('preisRundung', 'keine')
        ->set('preisAnzeigeBrutto', false)
        ->set('preisRundung', 'auf_10')
        ->call('speichern')
        ->assertHasNoErrors();

    $frisch = FoodAlchemistSpeisekarte::find($karte->id);
    expect((bool) $frisch->preis_anzeige_brutto)->toBeFalse()
        ->and($frisch->preis_rundung)->toBe('auf_10');
});

it('Editor: Fremd-Rundungswert fällt auf keine zurück (Whitelist)', function () {
    $karte = $this->karten->create($this->rootTeam, ['name' => 'K']);
    $this->karten->update($this->rootTeam, $karte->id, ['preis_rundung' => 'quatsch']);
    expect(FoodAlchemistSpeisekarte::find($karte->id)->preis_rundung)->toBe('keine');
});
