<?php

use Livewire\Livewire;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Livewire\Produktion\Editor;
use Platform\FoodAlchemist\Models\FoodAlchemistProductionOrderLine as Line;
use Platform\FoodAlchemist\Models\FoodAlchemistProductionStation;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Services\BehaelterBedarfService;
use Platform\FoodAlchemist\Services\ProductionOrderService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Produktions-Editor (Tagesplanung › Zeilen):
 *  1) Der Standard-Posten am Rezept wird beim Erzeugen der Zeile übernommen — nur wenn die Zeile noch keinen hat und der Posten zum
 *     Team des Auftrags gehört; ein Fremd-Posten wird nicht still übernommen (Warnung am Auftrag).
 *  2) Die Spalte „Behälter" zeigt nur die Summe; die volle Aufstellung je Komponente steht im Popover.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->svc = app(ProductionOrderService::class);
    $this->actingAs($this->makeUser($this->rootTeam, 'Küchenchef'));

    $this->saucier = FoodAlchemistProductionStation::create(['team_id' => $this->rootTeam->id, 'slug' => 'saucier', 'name' => 'Saucier']);
    $this->kalt = FoodAlchemistProductionStation::create(['team_id' => $this->rootTeam->id, 'slug' => 'kalt', 'name' => 'Kalte Küche']);
    $fremd = Team::create(['name' => 'Fremd', 'user_id' => 1, 'personal_team' => false]);
    $this->fremderPosten = FoodAlchemistProductionStation::create(['team_id' => $fremd->id, 'slug' => 'fremd', 'name' => 'Fremd-Posten']);

    $this->rezept = fn (string $key, string $name, ?int $posten) => FoodAlchemistRecipe::create([
        'team_id' => $this->rootTeam->id, 'recipe_key' => $key, 'name' => $name, 'status' => 'approved',
        'is_sales_recipe' => false, 'yield_kg' => 2.0, 'work_time_min' => 30, 'default_station_id' => $posten,
    ]);
    $this->auftrag = fn (array $rezepte) => $this->svc->saveNew($this->rootTeam, '2026-08-20', 'Posten-Test', array_map(
        fn ($r) => ['source_ref' => 'r:'.$r->recipe_key, 'recipe_id' => $r->id, 'amount_kg' => 6.0], $rezepte));
    $this->zeile = fn ($order, $recipe) => Line::where('production_order_id', $order->id)->where('recipe_id', $recipe->id)->first();
});

it('★ der Standard-Posten am Rezept wird beim Erzeugen der Zeile übernommen', function () {
    $sauce = ($this->rezept)('demi', 'Demi-Glace', $this->saucier->id);
    $ohne = ($this->rezept)('salat', 'Salat', null);

    $order = ($this->auftrag)([$sauce, $ohne]);

    expect((int) ($this->zeile)($order, $sauce)->station_id)->toBe($this->saucier->id)
        ->and(($this->zeile)($order, $ohne)->station_id)->toBeNull();
});

it('★ eine manuelle Zuteilung wird nie überschrieben — auch nicht beim erneuten Berechnen', function () {
    $sauce = ($this->rezept)('demi', 'Demi-Glace', $this->saucier->id);
    $order = ($this->auftrag)([$sauce]);

    $this->svc->assignLine($this->rootTeam, ($this->zeile)($order, $sauce)->id, ['station_id' => $this->kalt->id]);
    $this->svc->recomputeOrder($this->rootTeam, $order->refresh());

    expect((int) ($this->zeile)($order, $sauce)->station_id)->toBe($this->kalt->id);   // Overlay gewinnt über den Standard-Posten
});

it('★ ein Fremd-Posten wird nicht still übernommen: Zeile bleibt ohne Posten, der Auftrag warnt', function () {
    $fremdRezept = ($this->rezept)('abo', 'Abo-Fond', $this->fremderPosten->id);

    $order = ($this->auftrag)([$fremdRezept]);

    expect(($this->zeile)($order, $fremdRezept)->station_id)->toBeNull()
        ->and(collect($order->refresh()->warnungen)->implode(' '))->toContain('Abo-Fond')->toContain('nicht zu diesem Team');
});

it('beim erneuten Berechnen (Auffrischen) greift der Standard-Posten, wenn er nachträglich am Rezept gepflegt wurde', function () {
    $sauce = ($this->rezept)('demi', 'Demi-Glace', null);
    $order = ($this->auftrag)([$sauce]);
    expect(($this->zeile)($order, $sauce)->station_id)->toBeNull();

    $sauce->update(['default_station_id' => $this->saucier->id]);
    $this->svc->recomputeOrder($this->rootTeam, $order->refresh());

    expect((int) ($this->zeile)($order, $sauce)->station_id)->toBe($this->saucier->id);
});

it('inaktive Posten werden nicht übernommen', function () {
    $this->saucier->update(['is_inactive' => true]);
    $sauce = ($this->rezept)('demi', 'Demi-Glace', $this->saucier->id);

    expect(($this->zeile)(($this->auftrag)([$sauce]), $sauce)->station_id)->toBeNull();
});

// ── Spalte „Behälter" ──────────────────────────────────────────────────────

function bedarfBlock(): array
{
    $v = fn (string $name, int $anzahl, string $konfidenz = 'hoch') => ['behaelter' => $name, 'anzahl' => $anzahl, 'konfidenz' => $konfidenz];

    return [
        'abfuellen' => null,
        'je_komponente' => [
            ['zweck' => 'ausgabe', 'label' => 'Zwiebelring', 'berechenbar' => true, 'varianten' => [$v('GN 1/1 65mm', 1, 'mittel'), $v('GN 1/2 65mm', 2), $v('GN 1/3 65mm', 3)]],
            ['zweck' => 'regenerieren', 'label' => 'Sauce', 'berechenbar' => true, 'varianten' => [$v('GN 1/1 65mm', 2)]],
            ['zweck' => 'ausgabe', 'label' => 'Bereits gezählt', 'berechenbar' => true, 'bereits_gezaehlt' => true, 'varianten' => [$v('Eimer', 9)]],
            ['zweck' => 'ausgabe', 'label' => 'Ohne Angabe', 'berechenbar' => false, 'grund' => 'yield_kg fehlt'],
        ],
    ];
}

it('★ kompakt: eine kurze Summe statt der langen Kette, die volle Aufstellung bleibt in den Zeilen', function () {
    $k = BehaelterBedarfService::kompakt(bedarfBlock());

    expect($k['kurz'])->toBe('3× GN 1/1 65mm')                      // Basis-Varianten summiert, Alternativen und „bereits gezählt" nicht
        ->and($k['geschaetzt'])->toBeTrue()
        ->and($k['alternativen'])->toBe(2)
        ->and($k['luecken'])->toBe(1)
        ->and($k['zeilen'])->toHaveCount(3)                          // je Komponente eine Zeile (die lange Kette)
        ->and(BehaelterBedarfService::kurz(bedarfBlock()))->toContain('Ausgabe Zwiebelring')->toContain('Alternativen');
});

it('kompakt: verschiedene Behälter → „n Behälter"; ohne berechenbaren Bedarf → „Angaben fehlen"; kein Block → null', function () {
    $zwei = ['abfuellen' => null, 'je_komponente' => [
        ['zweck' => 'ausgabe', 'label' => 'A', 'berechenbar' => true, 'varianten' => [['behaelter' => 'GN 1/1', 'anzahl' => 2, 'konfidenz' => 'hoch']]],
        ['zweck' => 'ausgabe', 'label' => 'B', 'berechenbar' => true, 'varianten' => [['behaelter' => 'Eimer', 'anzahl' => 3, 'konfidenz' => 'hoch']]],
    ]];
    $leer = ['abfuellen' => null, 'je_komponente' => [['zweck' => 'ausgabe', 'label' => 'A', 'berechenbar' => false, 'grund' => 'yield_kg fehlt']]];

    expect(BehaelterBedarfService::kompakt($zwei)['kurz'])->toBe('5 Behälter')
        ->and(BehaelterBedarfService::kompakt($leer)['kurz'])->toBe('Angaben fehlen')
        ->and(BehaelterBedarfService::kompakt(null))->toBeNull();
});

it('★ der Editor zeigt in der Zelle nur die Summe; die Aufstellung steckt im Popover', function () {
    $rezept = ($this->rezept)('gulasch', 'Gulasch', null);
    $order = ($this->auftrag)([$rezept]);
    $zeile = ($this->zeile)($order, $rezept);
    $zeile->update(['darreichung' => ['behaelter_bedarf' => bedarfBlock()]]);

    Livewire::test(Editor::class)->call('oeffnenBearbeiten', $order->id)
        ->assertSeeHtml('data-zeile-behaelter-kurz')
        ->assertSeeHtml('data-zeile-behaelter-popover')
        ->assertSeeHtml('data-zeile-behaelter-detail')
        ->assertSee('3× GN 1/1 65mm')
        ->assertSee('Behälter je Komponente')
        ->assertSee('Ausgabe Zwiebelring');   // die volle Aufstellung ist da — im Popover, nicht in der Summe
});
