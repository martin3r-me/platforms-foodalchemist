<?php

use Livewire\Livewire;
use Platform\Core\Contracts\ToolContext;
use Platform\FoodAlchemist\Livewire\Favorites\Index as FavoritesIndex;
use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Services\FavoriteGpService;
use Platform\FoodAlchemist\Tools\FavoritesGetTool;
use Platform\FoodAlchemist\Tools\FavoritesPutTool;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * 06·H2/H4b — Favoriten (Lieblings-GPs): Auto-Score + Pin/Exclude (Service, Command,
 * MCP-Tools, Kuratierungs-Screen). JEDER approved GP ist pinbar (kein §4-Convenience-
 * Zwang mehr); Convenience bleibt ein GP-Tag (Generator kann darauf verengen).
 */
beforeEach(function () {
    $this->seedTeamHierarchy();

    $this->conv = $this->makeGp($this->rootTeam, 'TK-Kartoffelgratin');
    $this->conv->update(['status' => 'approved', 'tag_is_convenience' => true]);

    $this->nichtConv = $this->makeGp($this->rootTeam, 'Frische Kartoffel');
    $this->nichtConv->update(['status' => 'approved', 'tag_is_convenience' => false]);

    $this->svc = app(FavoriteGpService::class);
});

it('scored alle approved GPs (nicht nur Convenience)', function () {
    $namen = $this->svc->suggest($this->rootTeam)->pluck('name')->all();
    expect($namen)->toContain('TK-Kartoffelgratin')->toContain('Frische Kartoffel');
});

it('markiert Convenience-getaggte GPs (is_convenience-Flag pro Zeile)', function () {
    $conv = $this->svc->suggest($this->rootTeam)->firstWhere('name', 'TK-Kartoffelgratin');
    $roh = $this->svc->suggest($this->rootTeam)->firstWhere('name', 'Frische Kartoffel');
    expect($conv['is_convenience'])->toBeTrue()->and($roh['is_convenience'])->toBeFalse();
});

it('pinnt JEDEN approved GP — auch nicht-Convenience (kein §4-Zwang)', function () {
    $this->svc->pin($this->conv, 1);
    expect($this->conv->refresh()->is_favorite)->toBeTrue();
    expect($this->conv->favorite_rank)->toBe(1);

    $this->svc->pin($this->nichtConv);                 // früher: RuntimeException — jetzt erlaubt
    expect($this->nichtConv->refresh()->is_favorite)->toBeTrue();
});

it('exclude entfernt den Favorit', function () {
    $this->svc->pin($this->conv, 2);
    $this->svc->exclude($this->conv);
    expect($this->conv->refresh()->is_favorite)->toBeFalse();
    expect($this->conv->favorite_rank)->toBeNull();
});

it('current listet gepinnte nach Rang', function () {
    $b = $this->makeGp($this->rootTeam, 'TK-Rösti');
    $b->update(['status' => 'approved']);
    $this->svc->pin($this->conv, 2);
    $this->svc->pin($b, 1);
    expect($this->svc->current($this->rootTeam)->pluck('name')->all())->toBe(['TK-Rösti', 'TK-Kartoffelgratin']);
});

it('gepinnte Favoriten bleiben trotz Score-Cap in der Liste — und der Cap greift wirklich', function () {
    $this->svc->pin($this->nichtConv);                 // Score 0 (keine Nutzung/Lead), gepinnt
    $items = $this->svc->suggest($this->rootTeam, 1);  // Cap = 1
    // gepinnter Low-Score-GP bleibt drin …
    expect($items->pluck('name'))->toContain('Frische Kartoffel')
        // … aber der Cap kappt echt: rest = limit − pinned = 0 → nur der gepinnte, sonst nichts.
        ->and($items)->toHaveCount(1)
        ->and($items->pluck('name'))->not->toContain('TK-Kartoffelgratin');
});

it('suggest kappt den ganzen approved-Pool auf $limit (Rangliste, nicht ALLE) — Regressions-Guard 2026-07-20', function () {
    // 4 weitere approved GPs → Pool = 6; nichts gepinnt.
    foreach (['A', 'B', 'C', 'D'] as $s) {
        $this->makeGp($this->rootTeam, "Pool-GP {$s}")->update(['status' => 'approved']);
    }
    $items = $this->svc->suggest($this->rootTeam, 3, null, false);
    expect($items)->toHaveCount(3); // vor dem Fix kamen ALLE (früher toter Cap-Code hinter return)
});

it('suggest sortiert absteigend nach Score (höchster zuerst)', function () {
    // Lead-LA auf einen GP → +W_LEAD; bleibt über den score-0-GPs.
    $supplier = \Platform\FoodAlchemist\Models\FoodAlchemistSupplier::create(['team_id' => $this->rootTeam->id, 'name' => 'Chefs']);
    $item = \Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem::create([
        'team_id' => $this->rootTeam->id, 'supplier_id' => $supplier->id, 'designation' => 'Gratin-Mix',
    ]);
    $hi = $this->makeGp($this->rootTeam, 'Lead-GP');
    $hi->update(['status' => 'approved', 'lead_la_supplier_item_id' => $item->id]);

    $items = $this->svc->suggest($this->rootTeam);
    $scores = $items->pluck('score')->all();
    expect($scores)->toBe(collect($scores)->sortDesc()->values()->all()) // monoton fallend
        ->and($items->first()['name'])->toBe('Lead-GP');                 // Top-Score oben
});

it('Command --suggest + --pin', function () {
    $this->artisan('foodalchemist:favorites', ['--team' => $this->rootTeam->id, '--suggest' => true])->assertSuccessful();
    $this->artisan('foodalchemist:favorites', ['--pin' => $this->conv->id, '--rank' => 3])->assertSuccessful();
    expect($this->conv->refresh()->is_favorite)->toBeTrue();
});

it('MCP GET/PUT: jeder eigene GP pinbar + Ownership-Guard (D1)', function () {
    $ctx = new ToolContext($this->makeUser($this->rootTeam), $this->rootTeam);

    $put = (new FavoritesPutTool())->execute(['gp_id' => $this->conv->id, 'action' => 'pin', 'rank' => 1], $ctx);
    expect($put->success)->toBeTrue();
    expect($this->conv->refresh()->is_favorite)->toBeTrue();

    // nicht-Convenience, aber eigenes Team → jetzt erlaubt (kein §4)
    $roh = (new FavoritesPutTool())->execute(['gp_id' => $this->nichtConv->id, 'action' => 'pin'], $ctx);
    expect($roh->success)->toBeTrue();
    expect($this->nichtConv->refresh()->is_favorite)->toBeTrue();

    // Ownership-Guard: Kind-Team-User darf ein root-eigenes (geerbtes) GP NICHT pinnen
    $childCtx = new ToolContext($this->makeUser($this->childA), $this->childA);
    $verweigert = (new FavoritesPutTool())->execute(['gp_id' => $this->conv->id, 'action' => 'exclude'], $childCtx);
    expect($verweigert->success)->toBeFalse();

    $get = (new FavoritesGetTool())->execute(['mode' => 'current'], $ctx);
    expect($get->success)->toBeTrue();
    expect(collect($get->data['items'])->pluck('gp_id'))->toContain($this->conv->id);
});

it('Kuratierungs-Screen rendert + pinnt', function () {
    $user = $this->makeUser($this->rootTeam);
    $this->actingAs($user);

    Livewire::test(FavoritesIndex::class)
        ->assertOk()
        ->assertSee('TK-Kartoffelgratin')
        ->call('pin', $this->conv->id)
        ->assertOk();

    expect($this->conv->refresh()->is_favorite)->toBeTrue();
});
