<?php

use Platform\FoodAlchemist\Enums\SignalTyp;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeDarreichung;
use Platform\FoodAlchemist\Models\FoodAlchemistServierform;
use Platform\FoodAlchemist\Models\FoodAlchemistSignal;
use Platform\FoodAlchemist\Services\SignalDetektorService;
use Platform\FoodAlchemist\Services\VkSnapshotService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * R2.5 — Trennung interne Live-Marge ↔ freigegebener VK-Snapshot. Kern-Beweis:
 * ein VK-Sprung OHNE Freigabe lässt den veröffentlichten Preis unverändert und
 * erzeugt ein „VK-Anpassung empfohlen"-Signal.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->snap = app(VkSnapshotService::class);
    $sf = FoodAlchemistServierform::firstOrCreate(['code' => 'unbestimmt', 'team_id' => $this->rootTeam->id], ['label' => 'Unbestimmt']);

    $this->gericht = FoodAlchemistRecipe::create([
        'team_id' => $this->rootTeam->id, 'recipe_key' => 'vk-snap', 'name' => 'Snapshot-Gericht',
        'status' => 'approved', 'is_sales_recipe' => true, 'sales_net' => 25.00,
    ]);
    $this->darr = FoodAlchemistRecipeDarreichung::create([
        'team_id' => $this->rootTeam->id, 'recipe_id' => $this->gericht->id, 'serving_form_id' => $sf->id,
        'is_standard' => true, 'sales_net' => 25.00, 'sales_gross' => 29.75,
    ]);
});

it('release friert den Live-VK ein; publishedFor liefert den Snapshot', function () {
    expect($this->snap->publishedFor($this->darr->id))->toBeNull();

    $n = $this->snap->release($this->rootTeam, [$this->darr->id]);
    expect($n)->toBe(1);

    $pub = $this->snap->publishedFor($this->darr->id);
    expect($pub)->not->toBeNull()
        ->and((float) $pub->sales_net)->toBe(25.0);
});

it('VK-Sprung OHNE Freigabe → Signal + veröffentlichter VK bleibt unverändert', function () {
    $this->snap->release($this->rootTeam, [$this->darr->id]);          // 25 € freigegeben

    // Interner Live-VK springt (z. B. EK-Sprung → Recompute) auf 30 €.
    $this->darr->update(['sales_net' => 30.00]);

    // pending: Live 30 vs. freigegeben 25 → +20 % > Default-Leitplanke 5 %.
    $pending = $this->snap->pending($this->rootTeam);
    expect($pending)->toHaveCount(1)
        ->and($pending[0]['published_net'])->toBe(25.0)
        ->and($pending[0]['live_net'])->toBe(30.0)
        ->and($pending[0]['delta_pct'])->toBe(20.0)
        ->and($pending[0]['richtung'])->toBe('erhoehen');

    // Detektor feuert genau ein Signal.
    $n = app(SignalDetektorService::class)->vkAnpassungEmpfohlen($this->rootTeam);
    expect($n)->toBe(1)
        ->and(FoodAlchemistSignal::where('team_id', $this->rootTeam->id)->where('type', SignalTyp::VkAnpassungEmpfohlen->value)->count())->toBe(1);

    // Kernbeweis: OHNE erneute Freigabe bleibt der veröffentlichte VK 25 €.
    expect((float) $this->snap->publishedFor($this->darr->id)->sales_net)->toBe(25.0);
});

it('release schreibt nur eigene Darreichungen (D1-Schreibrecht)', function () {
    $sf = FoodAlchemistServierform::where('code', 'unbestimmt')->firstOrFail();   // code global unique
    $fremd = FoodAlchemistRecipe::create([
        'team_id' => $this->childA->id, 'recipe_key' => 'vk-fremd', 'name' => 'Fremd-Gericht',
        'status' => 'approved', 'is_sales_recipe' => true, 'sales_net' => 10.00,
    ]);
    $fremdeDarr = FoodAlchemistRecipeDarreichung::create([
        'team_id' => $this->childA->id, 'recipe_id' => $fremd->id, 'serving_form_id' => $sf->id,
        'is_standard' => true, 'sales_net' => 10.00,
    ]);

    // rootTeam versucht, die Darreichung von childA freizugeben → übersprungen.
    $n = $this->snap->release($this->rootTeam, [$this->darr->id, $fremdeDarr->id]);
    expect($n)->toBe(1)
        ->and($this->snap->publishedFor($fremdeDarr->id))->toBeNull();
});
