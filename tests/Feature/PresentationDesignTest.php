<?php

use Livewire\Livewire;
use Platform\FoodAlchemist\Livewire\Settings\PraesentationsDesigns;
use Platform\FoodAlchemist\Models\FoodAlchemistPresentationDesign;
use Platform\FoodAlchemist\Services\PresentationDesignService;
use Platform\FoodAlchemist\Services\PresentationService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 43 — Struktur-Builder (Livewire) + Design-Tenancy + „eingefrorenes Design".
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->designs = app(PresentationDesignService::class);
    $this->pres = app(PresentationService::class);

    $this->baueFb = function ($team, array $attrs = []) {
        $fb = $this->makeFoodbook($team, 'Katalog', array_merge(['personen' => 8], $attrs));
        $kap = $this->makeChapter($fb, ['title' => 'Vorspeisen', 'consumer_title' => 'Vorspeisen', 'position' => 1]);
        $dish = $this->makeRecipe($team, 'Suppe', ['is_sales_recipe' => true, 'sales_net' => 5.0]);
        $this->makeFoodbookBlock($kap, ['type' => 'recipe_ref', 'sales_recipe_id' => $dish->id, 'position' => 1]);

        return $fb;
    };
});

it('Block-Palette ist datengebunden — kein Interna-Block existiert', function () {
    foreach (['ek', 'wareneinsatz', 'marge', 'lieferant', 'deckungsbeitrag'] as $verboten) {
        expect(PresentationDesignService::BLOCK_TYPES)->not->toContain($verboten);
    }
    expect(PresentationDesignService::BLOCK_TYPES)->toContain('cover')->toContain('chapter_loop');
});

it('Builder legt ein Design an (Block hinzufügen → speichern → layout_json persistiert)', function () {
    ($this->baueFb)($this->rootTeam);
    $this->actingAs($this->makeUser($this->rootTeam, 'Designer'));

    $c = Livewire::test(PraesentationsDesigns::class)
        ->assertSet('baseSlug', 'editorial')
        ->call('blockHinzufuegen', 'cta')
        ->set('name', 'Mein Design')
        ->call('speichern');

    expect($c->get('selectedId'))->not->toBeNull();

    $design = FoodAlchemistPresentationDesign::where('team_id', $this->rootTeam->id)->where('name', 'Mein Design')->first();
    expect($design)->not->toBeNull();
    $types = collect($design->layout_json)->pluck('block_type');
    expect($types)->toContain('cta');
});

it('Builder-Reorder ändert die Blockreihenfolge', function () {
    $this->actingAs($this->makeUser($this->rootTeam, 'Designer'));

    $c = Livewire::test(PraesentationsDesigns::class);
    // frisch aus editorial: cover, chapter_loop, price_summary, legend
    $first = $c->get('layout')[0]['block_type'];
    expect($first)->toBe('cover');

    $c->call('blockVerschieben', 0, 1); // cover eins runter
    expect($c->get('layout')[0]['block_type'])->not->toBe('cover')
        ->and($c->get('layout')[1]['block_type'])->toBe('cover');
});

it('Design-CRUD ist team-gescopt (geerbtes Design ist read-only)', function () {
    // Design im Eltern-Team (rootTeam) — für childA sichtbar (Ancestry), aber nicht besitzbar.
    $design = $this->designs->create($this->rootTeam, ['name' => 'Haus-Design', 'base_slug' => 'menu']);

    // childA sieht es in der Liste …
    expect($this->designs->list($this->childA)->pluck('id'))->toContain($design->id);
    // … darf es aber nicht ändern.
    expect(fn () => $this->designs->update($this->childA, $design->id, ['name' => 'Klau']))
        ->toThrow(RuntimeException::class);
});

it('resolveTokens: builtin = Basis, Design übersteuert; Branding-Farbe übersteuert das Design NICHT', function () {
    $team = $this->rootTeam;

    // Nur Slug → Builtin-Palette.
    expect($this->designs->resolveTokens('editorial', $team, [], [])['palette']['primary'])->toBe('#6d28d9');

    // Design übersteuert den Builtin.
    $design = $this->designs->create($team, ['name' => 'D', 'base_slug' => 'editorial', 'tokens_json' => ['palette' => ['primary' => '#111111']]]);
    expect($this->designs->resolveTokens('design:' . $design->id, $team, [], [])['palette']['primary'])->toBe('#111111');

    // Auch mit gesetztem Branding gewinnt das Design (die Palette gehört dem Design).
    expect($this->designs->resolveTokens('design:' . $design->id, $team, ['color' => '#abcdef'], [])['palette']['primary'])->toBe('#111111');
});

it('der Snapshot friert das AUFGELÖSTE Design ein — Design-Edit nach Freigabe ändert den Link nicht', function () {
    $team = $this->rootTeam;
    $this->actingAs($this->makeUser($team, 'Publisher'));

    $design = $this->designs->create($team, [
        'name' => 'Fix', 'base_slug' => 'editorial',
        'layout_json' => [['block_type' => 'cover', 'style' => []], ['block_type' => 'chapter_loop', 'style' => []]],
        'tokens_json' => ['palette' => ['primary' => '#111111']],
    ]);
    // Foodbook OHNE Branding → das Design-Token gewinnt.
    $fb = ($this->baueFb)($team);
    $fb->update(['presentation_design' => 'design:' . $design->id]);

    $res = $this->pres->publish($team, 'foodbook', $fb->id, [
        'design' => 'design:' . $design->id, 'expires_at' => now()->addDays(30)->toDateString(),
    ]);
    $snap1 = $this->pres->resolveByToken('foodbook', $res['token']);
    expect($snap1['resolved_design']['tokens']['palette']['primary'])->toBe('#111111');

    // Design weiterbauen …
    $this->designs->update($team, $design->id, ['tokens_json' => ['palette' => ['primary' => '#999999']]]);

    // Public-Link unverändert (eingefroren) …
    $snap2 = $this->pres->resolveByToken('foodbook', $res['token']);
    expect($snap2['resolved_design']['tokens']['palette']['primary'])->toBe('#111111');

    // … aber die LIVE-Vorschau zeigt den neuen Stand.
    $prev = $this->pres->previewData($team, 'foodbook', $fb->id);
    expect($prev['resolved_design']['tokens']['palette']['primary'])->toBe('#999999');
});
