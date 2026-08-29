<?php

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Tools\ToolRegistry;
use Platform\FoodAlchemist\Models\FoodAlchemistVocabEinheit;
use Platform\FoodAlchemist\Services\VocabularyService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * MCP-Steuerbarkeit · D13: Vokabular/Taxonomie SAFE-additiv — vocab_*.POST/PUT/TOGGLE/REORDER,
 * KEIN Delete; globale/kanonische Zeilen read-only (VocabularyService-self-guard).
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->user = $this->makeUser($this->rootTeam);
    $this->actingAs($this->user);
    $this->registry = app(ToolRegistry::class);
    $this->kontext = new ToolContext($this->user, $this->rootTeam);
    $this->childKontext = new ToolContext($this->makeUser($this->childA), $this->childA);
    $this->run = fn (string $n, array $a, ?ToolContext $k = null) => $this->registry->get($n)->execute($a, $k ?? $this->kontext);
    $this->svc = app(VocabularyService::class);
});

it('Registry-Smoke: alle 15 D13-Vocab-Tools registriert mit type=object', function () {
    $namen = [
        'vocab_einheiten.POST', 'vocab_einheiten.PUT', 'vocab_einheiten.TOGGLE',
        'vocab_warengruppen.POST', 'vocab_warengruppen.PUT', 'vocab_warengruppen.REORDER',
        'vocab_subkategorien.POST', 'vocab_subkategorien.PUT', 'vocab_subkategorien.REORDER',
        'vocab_recipe_maingroups.POST', 'vocab_recipe_maingroups.PUT', 'vocab_recipe_maingroups.REORDER',
        'vocab_dish_maingroups.POST', 'vocab_dish_maingroups.PUT', 'vocab_dish_maingroups.REORDER',
    ];
    foreach ($namen as $n) {
        $tool = $this->registry->get("foodalchemist.{$n}");
        expect($tool)->not->toBeNull($n);
        expect($tool->getSchema()['type'] ?? null)->toBe('object', $n);
    }
});

it('vocab_einheiten: POST / PUT / TOGGLE (kein Delete)', function () {
    $post = ($this->run)('foodalchemist.vocab_einheiten.POST', ['slug' => 'el', 'display_de' => 'Esslöffel', 'dimension' => 'volume', 'default_in_ml' => 15]);
    expect($post->success)->toBeTrue('post: ' . ($post->error ?? ''));
    $id = $post->data['id'];

    $put = ($this->run)('foodalchemist.vocab_einheiten.PUT', ['id' => $id, 'felder' => ['display_de' => 'EL']]);
    expect($put->success)->toBeTrue('put: ' . ($put->error ?? ''));
    expect(FoodAlchemistVocabEinheit::find($id)->display_de)->toBe('EL');

    $tog = ($this->run)('foodalchemist.vocab_einheiten.TOGGLE', ['id' => $id, 'inactive' => true]);
    expect($tog->success)->toBeTrue('toggle: ' . ($tog->error ?? ''));

    // Kein Delete-Tool vorhanden (Safe-Variante)
    expect($this->registry->get('foodalchemist.vocab_einheiten.DELETE'))->toBeNull();
});

it('vocab_warengruppen + vocab_subkategorien: POST/PUT/REORDER', function () {
    $wg = ($this->run)('foodalchemist.vocab_warengruppen.POST', ['name' => 'Fermente', 'code' => 'FERM']);
    expect($wg->success)->toBeTrue('wg: ' . ($wg->error ?? ''));

    $put = ($this->run)('foodalchemist.vocab_warengruppen.PUT', ['id' => $wg->data['id'], 'name' => 'Fermente & Pickles']);
    expect($put->success)->toBeTrue('wg-put: ' . ($put->error ?? ''));

    $re = ($this->run)('foodalchemist.vocab_warengruppen.REORDER', ['ids' => [$wg->data['id']]]);
    expect($re->success)->toBeTrue('wg-reorder: ' . ($re->error ?? ''));

    $sub = ($this->run)('foodalchemist.vocab_subkategorien.POST', ['warengruppe_code' => 'FERM', 'name' => 'Kimchi']);
    expect($sub->success)->toBeTrue('sub: ' . ($sub->error ?? ''));

    $subPut = ($this->run)('foodalchemist.vocab_subkategorien.PUT', ['warengruppe_code' => 'FERM', 'alt' => 'Kimchi', 'neu' => 'Kimchi & Co']);
    expect($subPut->success)->toBeTrue('sub-put: ' . ($subPut->error ?? ''));

    $subRe = ($this->run)('foodalchemist.vocab_subkategorien.REORDER', ['warengruppe_code' => 'FERM', 'names' => ['Kimchi & Co']]);
    expect($subRe->success)->toBeTrue('sub-reorder: ' . ($subRe->error ?? ''));
});

it('vocab_recipe_maingroups + vocab_dish_maingroups: POST/PUT/REORDER', function () {
    $rmg = ($this->run)('foodalchemist.vocab_recipe_maingroups.POST', ['code' => 'FERM_R', 'label' => 'Fermente']);
    expect($rmg->success)->toBeTrue('rmg: ' . ($rmg->error ?? ''));
    expect(($this->run)('foodalchemist.vocab_recipe_maingroups.PUT', ['id' => $rmg->data['id'], 'felder' => ['label' => 'Fermente & Säuren']])->success)->toBeTrue();
    expect(($this->run)('foodalchemist.vocab_recipe_maingroups.REORDER', ['ids' => [$rmg->data['id']]])->success)->toBeTrue();

    $dmg = ($this->run)('foodalchemist.vocab_dish_maingroups.POST', ['code' => 'BOWL', 'label' => 'Bowls']);
    expect($dmg->success)->toBeTrue('dmg: ' . ($dmg->error ?? ''));
    expect(($this->run)('foodalchemist.vocab_dish_maingroups.PUT', ['id' => $dmg->data['id'], 'name' => 'Bowls & Salate'])->success)->toBeTrue();
    expect(($this->run)('foodalchemist.vocab_dish_maingroups.REORDER', ['ids' => [$dmg->data['id']]])->success)->toBeTrue();
});

it('Safe-Guard: fremde/geerbte Einheit ist read-only', function () {
    $post = ($this->run)('foodalchemist.vocab_einheiten.POST', ['slug' => 'tl', 'display_de' => 'Teelöffel', 'dimension' => 'volume', 'default_in_ml' => 5]);
    $id = $post->data['id'];

    // childA sieht die root-Einheit (Ancestry), darf sie aber nicht editieren (Geerbt → blockiert)
    $put = ($this->run)('foodalchemist.vocab_einheiten.PUT', ['id' => $id, 'felder' => ['display_de' => 'TL']], $this->childKontext);
    expect($put->success)->toBeFalse();
    expect(FoodAlchemistVocabEinheit::find($id)->display_de)->toBe('Teelöffel');
});
