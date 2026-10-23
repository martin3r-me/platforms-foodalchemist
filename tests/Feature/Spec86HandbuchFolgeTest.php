<?php

use Livewire\Livewire;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Tools\ToolRegistry;
use Platform\FoodAlchemist\Livewire\Handbuch\Panel;
use Platform\FoodAlchemist\Models\FoodAlchemistHandbuch;
use Platform\FoodAlchemist\Services\HandbuchService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 86 Folgepaket — freie Kapitel (Hygiene, Ausgabe-Regeln …), Panel-Pflege und MCP-Parameter.
 * Bewusst ohne Preisfelder am Rezept (Spec 85: VK lebt an der Darreichung).
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->user = $this->makeUser($this->rootTeam);
    $this->actingAs($this->user);
    $this->hb = app(HandbuchService::class);

    $team = $this->rootTeam;
    $concept = $this->makeConcept($team, 'Menü A', ['kind' => 'concept', 'consumer_name' => 'Genuss']);
    $dish = $this->makeRecipe($team, 'Zanderfilet', ['is_sales_recipe' => true]);
    $this->makeConceptSlot($concept, ['sales_recipe_id' => $dish->id, 'wording' => 'Zanderfilet auf Linsen', 'position' => 1]);
    $this->fb = $this->makeFoodbook($team, 'Sommerfest', ['personen' => 80]);
    $kap = $this->makeChapter($this->fb, ['title' => 'Hauptgänge', 'consumer_title' => 'Hauptgänge', 'position' => 1]);
    $this->makeFoodbookBlock($kap, ['type' => 'concept_ref', 'concept_id' => $concept->id, 'position' => 1]);
    $this->bis = now()->addDays(14)->toDateString();
});

it('freie Kapitel stehen im Handbuch (escaped, ohne HTML) und bleiben beim erneuten Veröffentlichen erhalten', function () {
    $res = $this->hb->publish($this->rootTeam, 'foodbook', $this->fb->id, [
        'expires_at' => $this->bis,
        'kapitel' => [['title' => 'Hygiene', 'text' => "Hände waschen.\n<script>alert(1)</script>Kühlkette halten."], ['title' => '', 'text' => '']],
    ]);

    $snap = FoodAlchemistHandbuch::where('token', $res['token'])->firstOrFail()->snapshot_json;
    expect($snap['freie_kapitel'])->toHaveCount(1)
        ->and($snap['freie_kapitel'][0]['title'])->toBe('Hygiene')
        ->and($snap['freie_kapitel'][0]['text'])->not->toContain('<script>');

    $this->get('/p/handbuch/' . $res['token'])->assertOk()->assertSee('Hygiene')->assertSee('Kühlkette halten.')->assertDontSee('<script>alert', false);

    // Ohne kapitel-Schlüssel bleiben die zuletzt veröffentlichten stehen; mit leerer Liste werden sie entfernt.
    $this->hb->publish($this->rootTeam, 'foodbook', $this->fb->id, ['expires_at' => $this->bis]);
    expect($this->hb->status($this->rootTeam, 'foodbook', $this->fb->id)['kapitel'])->toHaveCount(1);
    $this->hb->publish($this->rootTeam, 'foodbook', $this->fb->id, ['expires_at' => $this->bis, 'kapitel' => []]);
    expect($this->hb->status($this->rootTeam, 'foodbook', $this->fb->id)['kapitel'])->toBe([]);
});

it('Panel: Kapitel anlegen, veröffentlichen, entfernen', function () {
    $c = Livewire::test(Panel::class, ['type' => 'foodbook', 'quelleId' => $this->fb->id])
        ->call('kapitelNeu')
        ->set('kapitel.0.title', 'Ausgabe-Regeln')
        ->set('kapitel.0.text', 'Heiß über 65 Grad ausgeben.')
        ->set('gueltigBis', $this->bis)
        ->call('veroeffentlichen');

    expect($this->hb->status($this->rootTeam, 'foodbook', $this->fb->id)['kapitel'][0]['title'])->toBe('Ausgabe-Regeln');

    $c->call('kapitelEntfernen', 0)->assertSet('kapitel', []);
});

it('MCP PUBLISH nimmt freie Kapitel entgegen', function () {
    $registry = app(ToolRegistry::class);
    $kontext = new ToolContext($this->user, $this->rootTeam);

    $pub = $registry->get('foodalchemist.foodbook_handbuch.PUBLISH')->execute([
        'foodbook_id' => $this->fb->id, 'expires_at' => $this->bis,
        'kapitel' => [['title' => 'Hygiene', 'text' => 'Hände waschen.']],
    ], $kontext);

    expect($pub->success)->toBeTrue();
    $this->get('/p/handbuch/' . $pub->data['token'])->assertOk()->assertSee('Hände waschen.');
});
