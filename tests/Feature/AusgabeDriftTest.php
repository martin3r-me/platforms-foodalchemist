<?php

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Tools\ToolRegistry;
use Platform\FoodAlchemist\Enums\SignalTyp;
use Platform\FoodAlchemist\Models\FoodAlchemistOutlet;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeDarreichung;
use Platform\FoodAlchemist\Models\FoodAlchemistServierform;
use Platform\FoodAlchemist\Models\FoodAlchemistSignal;
use Platform\FoodAlchemist\Models\FoodAlchemistSpeisekarte;
use Platform\FoodAlchemist\Services\AusgabeDriftService;
use Platform\FoodAlchemist\Services\OutletSettingsService;
use Platform\FoodAlchemist\Services\PresentationService;
use Platform\FoodAlchemist\Services\SignalDetektorService;
use Platform\FoodAlchemist\Services\TeamSettingsService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Ebene 2 · Ausgabe-Drift — der Kern: die VERÖFFENTLICHTE Kundensicht friert ihre Preise ein;
 * ändert sich danach die Kalkulation, driftet der eingefrorene Preis weg (der Auto-Aufschlag
 * maskiert das intern). Der Detektor hält die eingefrorene Ausgabe gegen den Live-VK — auf ZWEI
 * Ebenen: Team-Core (inline Doc-Snapshot, NULL-Lane) und Betrieb (Präsentation, Betriebs-Lane).
 * Republish schließt das Signal. Preislose Ausgaben lösen nichts aus.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->user = $this->makeUser($this->childA);
    $this->actingAs($this->user);
    $this->registry = app(ToolRegistry::class);
    $this->kontext = new ToolContext($this->user, $this->childA);
    $this->pres = app(PresentationService::class);
    $this->det = app(SignalDetektorService::class);

    app(TeamSettingsService::class)->update($this->childA, ['target_food_cost_pct' => 25]);
    $this->betrieb = FoodAlchemistOutlet::create(['team_id' => $this->childA->id, 'name' => 'Betrieb Nord']);
    app(OutletSettingsService::class)->update($this->childA, $this->betrieb, ['target_food_cost_pct' => 20]);   // Basissatz 5,0

    // Speisekarte mit EINEM bepreisten Gericht: Baseline (Team-Core) = sales_net 99;
    // Betrieb = ek_portion 10 × 5,0 = 50.
    $this->gericht = $this->makeRecipe($this->childA, 'HG Zander', ['is_sales_recipe' => true, 'sales_net' => 99.0]);
    $sf = FoodAlchemistServierform::create(['team_id' => $this->childA->id, 'code' => 'teller', 'label' => 'Teller']);
    $this->darr = FoodAlchemistRecipeDarreichung::create([
        'team_id' => $this->childA->id, 'recipe_id' => $this->gericht->id, 'serving_form_id' => $sf->id,
        'is_standard' => true, 'ek_portion' => 10, 'sales_net' => 99.0,
    ]);
    $karteId = $this->registry->get('foodalchemist.speisekarten.POST')->execute(['name' => 'Abendkarte'], $this->kontext)->data['speisekarte']['id'];
    $rubrikId = $this->registry->get('foodalchemist.speisekarte_rubrik.POST')->execute([
        'speisekarte_id' => $karteId, 'title' => 'Fisch', 'art' => 'speisen',
    ], $this->kontext)->data['rubrik']['id'];
    $this->registry->get('foodalchemist.speisekarte_positionen.POST')->execute([
        'rubrik_id' => $rubrikId, 'type' => 'gericht_ref', 'sales_recipe_id' => $this->gericht->id,
    ], $this->kontext);
    $this->karte = FoodAlchemistSpeisekarte::find($karteId);
    $this->karteId = $karteId;
});

it('Team-Core: veröffentlichte Ausgabe driftet gegen den Live-VK → NULL-Lane-Signal, Republish schließt es', function () {
    $this->pres->publish($this->childA, 'speisekarte', $this->karteId, ['price_display' => true, 'expires_at' => now()->addDays(30)->toDateString()]);

    // Vor der Kostenänderung: keine Drift.
    expect(app(AusgabeDriftService::class)->abgedriftet($this->childA, null))->toHaveCount(0);

    // Baseline-VK springt (99 → 140) — die eingefrorene Ausgabe zeigt weiter 99.
    $this->darr->update(['sales_net' => 140.0]);
    $drift = app(AusgabeDriftService::class)->abgedriftet($this->childA, null);
    expect($drift)->toHaveCount(1)
        ->and($drift[0]['zeilen'])->not->toBeEmpty();

    // Detektor: genau ein vk_anpassung_empfohlen in der Team-Core-Lane (outlet_id NULL).
    expect($this->det->vkAnpassungEmpfohlen($this->childA))->toBe(1);
    $sig = FoodAlchemistSignal::where('team_id', $this->childA->id)->where('type', SignalTyp::VkAnpassungEmpfohlen->value)->get();
    expect($sig)->toHaveCount(1)
        ->and($sig->first()->outlet_id)->toBeNull();

    // Republish MIT „Preise aktualisieren" (price_mode=auto) friert 140 ein → deckungsgleich →
    // Detektor schließt das Signal automatisch. (Ohne auto würde der Republish die alten Preise
    // bewahren — Republish-Preis-Schutz —, die Drift bliebe bewusst offen.)
    $this->pres->publish($this->childA, 'speisekarte', $this->karteId, ['price_display' => true, 'price_mode' => 'auto', 'expires_at' => now()->addDays(30)->toDateString()]);
    expect(app(AusgabeDriftService::class)->abgedriftet($this->childA, null))->toHaveCount(0)
        ->and($this->det->vkAnpassungEmpfohlen($this->childA))->toBe(0)
        ->and(FoodAlchemistSignal::where('team_id', $this->childA->id)->where('type', SignalTyp::VkAnpassungEmpfohlen->value)->where('status', 'offen')->count())->toBe(0);
});

it('Betrieb: die betriebs-scopte Präsentation driftet → Signal in der Betriebs-Lane', function () {
    $this->pres->publishForOutlet($this->childA, 'speisekarte', $this->karteId, $this->betrieb->id, ['price_display' => true, 'expires_at' => now()->addDays(30)->toDateString()]);

    expect(app(AusgabeDriftService::class)->abgedriftet($this->childA, $this->betrieb))->toHaveCount(0);

    // Betriebs-VK = ek_portion × 5,0; EK springt 10 → 40 ⇒ live 200 vs. eingefroren 50.
    $this->darr->update(['ek_portion' => 40]);
    $drift = app(AusgabeDriftService::class)->abgedriftet($this->childA, $this->betrieb);
    expect($drift)->toHaveCount(1)
        ->and($drift[0]['outlet_id'])->toBe($this->betrieb->id);

    expect($this->det->vkAnpassungEmpfohlen($this->childA, $this->betrieb))->toBe(1);
    $sig = FoodAlchemistSignal::where('team_id', $this->childA->id)->where('type', SignalTyp::VkAnpassungEmpfohlen->value)->get();
    expect($sig)->toHaveCount(1)
        ->and($sig->first()->outlet_id)->toBe($this->betrieb->id);
});

it('preisPfade parst deutschen Format-Preis (Speiseplan-Grid) und liest float-Preise', function () {
    $snapshot = ['content' => [
        'sections' => [['blocks' => [['items' => [
            ['label' => 'Zander', 'price' => 24.5],
            ['label' => 'ohne Preis', 'price' => null],
        ]]]]],
        'grid' => ['lines' => [['name' => 'Menü 1', 'cells' => ['2026-09-01' => [
            ['label' => 'Suppe', 'price' => '1.234,56 €'],
        ]]]]],
        'total' => ['vk_pro_person' => 12.0, 'pauschal' => 0],
    ]];

    $pfade = $this->pres->preisPfade($snapshot);
    expect(collect($pfade)->pluck('net')->sort()->values()->all())->toBe([12.0, 24.5, 1234.56]);
});

it('preisloser Speiseplan (price_display aus) löst keine Drift aus', function () {
    // Speiseplan-Snapshot ohne Preise → preisPfade leer → keine Ausgabe-Drift.
    expect($this->pres->preisPfade(['content' => ['grid' => ['lines' => [
        ['name' => 'Menü', 'cells' => ['2026-09-01' => [['label' => 'Eintopf']]]],
    ]]]]))->toBe([]);
});
