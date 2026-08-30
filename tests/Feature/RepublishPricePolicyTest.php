<?php

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Tools\ToolRegistry;
use Platform\FoodAlchemist\Models\FoodAlchemistOutlet;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeDarreichung;
use Platform\FoodAlchemist\Models\FoodAlchemistServierform;
use Platform\FoodAlchemist\Models\FoodAlchemistSpeisekarte;
use Platform\FoodAlchemist\Services\OutletSettingsService;
use Platform\FoodAlchemist\Services\PresentationService;
use Platform\FoodAlchemist\Services\TeamSettingsService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Republish-Preis-Schutz: Re-Publish behält per Default (`price_mode` fehlt = preserve) die
 * EINGEFRORENEN Preise — nur mit „Preise aktualisieren" (`price_mode=auto`) zieht der Publish die
 * aktuellen Live-VK. Neu hinzugefügte Speisen kommen immer live rein; Erstpublish ist immer live.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->user = $this->makeUser($this->childA);
    $this->actingAs($this->user);
    $this->registry = app(ToolRegistry::class);
    $this->kontext = new ToolContext($this->user, $this->childA);
    $this->pres = app(PresentationService::class);
    $this->exp = now()->addDays(30)->toDateString();

    app(TeamSettingsService::class)->update($this->childA, ['target_food_cost_pct' => 25]);
    $this->betrieb = FoodAlchemistOutlet::create(['team_id' => $this->childA->id, 'name' => 'Betrieb Nord']);
    app(OutletSettingsService::class)->update($this->childA, $this->betrieb, ['target_food_cost_pct' => 20]);

    $this->gericht = $this->makeRecipe($this->childA, 'HG Zander', ['is_sales_recipe' => true, 'sales_net' => 99.0, 'ek_total_eur' => 10.0]);
    $sf = FoodAlchemistServierform::create(['team_id' => $this->childA->id, 'code' => 'teller', 'label' => 'Teller']);
    $this->darr = FoodAlchemistRecipeDarreichung::create([
        'team_id' => $this->childA->id, 'recipe_id' => $this->gericht->id, 'serving_form_id' => $sf->id,
        'is_standard' => true, 'ek_portion' => 10, 'sales_net' => 99.0,
    ]);
    $this->karteId = $this->registry->get('foodalchemist.speisekarten.POST')->execute(['name' => 'Abendkarte'], $this->kontext)->data['speisekarte']['id'];
    $this->rubrikId = $this->registry->get('foodalchemist.speisekarte_rubrik.POST')->execute([
        'speisekarte_id' => $this->karteId, 'title' => 'Fisch', 'art' => 'speisen',
    ], $this->kontext)->data['rubrik']['id'];
    $this->registry->get('foodalchemist.speisekarte_positionen.POST')->execute([
        'rubrik_id' => $this->rubrikId, 'type' => 'gericht_ref', 'sales_recipe_id' => $this->gericht->id,
    ], $this->kontext);

    // Preise des aktuell gespeicherten (Standard-Link-)Snapshots als Liste der Netto-Werte.
    $this->preise = fn () => collect($this->pres->preisPfade(
        FoodAlchemistSpeisekarte::find($this->karteId)->presentation_snapshot_json ?? []
    ))->pluck('net')->values();
    $this->publishStd = fn (?string $mode = null) => $this->pres->publish($this->childA, 'speisekarte', $this->karteId,
        ['price_display' => true, 'expires_at' => $this->exp] + ($mode !== null ? ['price_mode' => $mode] : []));
});

it('Re-Publish behält per Default die eingefrorenen Preise; „auto" zieht die neuen', function () {
    ($this->publishStd)();                                    // Erstpublish → live A
    $a = ($this->preise)()->first();
    expect($a)->toBeGreaterThan(0.0);

    $this->darr->update(['sales_net' => 140.0]);              // Live-VK springt A→B

    ($this->publishStd)();                                    // Re-Publish ohne price_mode ⇒ preserve
    expect(($this->preise)()->first())->toBe($a);            // Preis bleibt A

    ($this->publishStd)('auto');                              // Re-Publish „Preise aktualisieren"
    $b = ($this->preise)()->first();
    expect($b)->not->toBe($a)->and($b)->toBeGreaterThan($a); // jetzt B
});

it('neue Speise kommt beim preserve-Republish LIVE rein, bestehende bleibt eingefroren', function () {
    ($this->publishStd)();                                    // A = 1 Preis (~99)
    $a = ($this->preise)()->first();

    $this->darr->update(['sales_net' => 140.0]);              // bestehendes Gericht: Live steigt auf 140

    // Zweite, günstigere Speise ergänzen.
    $g2 = $this->makeRecipe($this->childA, 'VS Süppchen', ['is_sales_recipe' => true, 'sales_net' => 50.0, 'ek_total_eur' => 5.0]);
    $sf = FoodAlchemistServierform::where('team_id', $this->childA->id)->first();
    FoodAlchemistRecipeDarreichung::create([
        'team_id' => $this->childA->id, 'recipe_id' => $g2->id, 'serving_form_id' => $sf->id,
        'is_standard' => true, 'ek_portion' => 5, 'sales_net' => 50.0,
    ]);
    $this->registry->get('foodalchemist.speisekarte_positionen.POST')->execute([
        'rubrik_id' => $this->rubrikId, 'type' => 'gericht_ref', 'sales_recipe_id' => $g2->id,
    ], $this->kontext);

    ($this->publishStd)();                                    // preserve
    $preise = ($this->preise)();
    expect($preise)->toHaveCount(2)
        // Bestehendes Gericht bewahrt → höchster Preis bleibt A (~99), NICHT das live-140.
        ->and($preise->max())->toBe($a)
        // Neue Speise ist live drin (>0, ≠ das bewahrte A).
        ->and($preise->contains(fn ($v) => $v > 0 && $v !== $a))->toBeTrue();
});

it('preserve gilt je Betrieb (publishForOutlet)', function () {
    $pub = fn (?string $mode = null) => $this->pres->publishForOutlet($this->childA, 'speisekarte', $this->karteId, $this->betrieb->id,
        ['price_display' => true, 'expires_at' => $this->exp] + ($mode !== null ? ['price_mode' => $mode] : []));
    $preiseBetrieb = fn () => collect($this->pres->preisPfade(
        \Platform\FoodAlchemist\Models\FoodAlchemistPresentation::where('outlet_id', $this->betrieb->id)->first()->snapshot_json ?? []
    ))->pluck('net')->values();

    $pub();                                                   // Betriebs-Erstpublish → live
    $a = $preiseBetrieb()->first();
    expect($a)->toBeGreaterThan(0.0);

    $this->darr->update(['ek_portion' => 40]);               // Betriebs-VK springt (ek×Basissatz)

    $pub();                                                   // preserve
    expect($preiseBetrieb()->first())->toBe($a);            // bewahrt
    $pub('auto');                                            // aktualisieren
    expect($preiseBetrieb()->first())->not->toBe($a);
});
