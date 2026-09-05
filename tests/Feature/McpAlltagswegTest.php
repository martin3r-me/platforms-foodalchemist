<?php

use Illuminate\Support\Facades\Queue;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Models\Team;
use Platform\Core\Tools\ToolRegistry;
use Platform\FoodAlchemist\Jobs\EnrichRecipeJob;
use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 50 · Etappe 4 — die zwei Lücken, die den Alltags-Weg (Weg B) lahmgelegt haben.
 *
 * E-1: Ein per MCP angelegtes Rezept konnte GAR NICHT angereichert werden —
 * `anreichern()` lief nur aus `recipes.GENERATE`, und `enrichBestehendesRezept()` hatte als
 * einzigen Aufrufer eine Livewire-Klasse. Messbare Folge auf demo: 939 von 950 Gerichten
 * ohne VK-Wording, obwohl `wording` in der Schrittfolge steht.
 *
 * E-2: `gps.MATCH` reichte `bio`/`frische`/`mode`/`prefer_raw` nicht durch, obwohl der
 * Service sie seit jeher kennt. Deshalb musste ein Agent am 2026-09-03 „Bio oder nicht?"
 * als Rückfrage stellen und ein bereits angelegtes Rezept nachkorrigieren.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->user = $this->makeUser($this->rootTeam);
    $this->actingAs($this->user);
    $this->kontext = new ToolContext($this->user, $this->rootTeam);
    $this->run = fn (string $n, array $a) => app(ToolRegistry::class)->get($n)->execute($a, $this->kontext);
});

it('E-2: bio kippt den Treffer bei Namens-Gleichstand', function () {
    // Zwei GPs, die sich NUR durch Bio unterscheiden — genau der Fall, in dem die
    // Präferenz entscheiden DARF (sie ist ein Tiebreak, kein Filter).
    $konv = $this->makeGp($this->rootTeam, 'Akazienhonig: trocken');
    $bio = $this->makeGp($this->rootTeam, 'Akazienhonig: trocken, Bio');
    $bio->update(['bio' => 'bio']);

    $neutral = ($this->run)('foodalchemist.gps.MATCH', ['zutat' => 'Akazienhonig']);
    $mitBio = ($this->run)('foodalchemist.gps.MATCH', ['zutat' => 'Akazienhonig', 'bio' => 'bio']);

    expect($neutral->success)->toBeTrue()->and($mitBio->success)->toBeTrue()
        ->and($mitBio->data['best_match']['gp_id'] ?? null)->toBe($bio->id)
        ->and($neutral->data['best_match']['gp_id'] ?? null)->toBe($konv->id);
});

it('E-2: unbekannte Präferenz-Werte fallen neutral zurück, statt zu werfen', function () {
    $this->makeGp($this->rootTeam, 'Akazienhonig: trocken');

    $res = ($this->run)('foodalchemist.gps.MATCH', ['zutat' => 'Akazienhonig', 'bio' => 'quatsch', 'frische' => 'unfug']);

    expect($res->success)->toBeTrue();
});

it('E-1: recipes.ENRICH stösst den Anreicherungs-Lauf an und meldet den Ausgangszustand', function () {
    Queue::fake();                                        // der Pass selbst gehört dem Worker
    $r = $this->makeRecipe($this->rootTeam, 'Fond: Anzureichern');

    $res = ($this->run)('foodalchemist.recipes.ENRICH', ['recipe_id' => $r->id]);

    expect($res->success)->toBeTrue()
        ->and($res->data['run_id'])->toBeGreaterThan(0)
        ->and($res->data['step_id'])->toBeGreaterThan(0)
        // Der Ausgangszustand kommt mit, damit nach dem Lauf vergleichbar ist, was er bewirkt hat.
        ->and($res->data)->toHaveKey('reife_vorher');

    Queue::assertPushed(EnrichRecipeJob::class);
});

it('E-1: ki_bilder ist standardmässig AUS — Fotos auf Bedarf', function () {
    Queue::fake();
    $r = $this->makeRecipe($this->rootTeam, 'Fond: Ohne Bilder');

    ($this->run)('foodalchemist.recipes.ENRICH', ['recipe_id' => $r->id]);

    Queue::assertPushed(EnrichRecipeJob::class, fn (EnrichRecipeJob $j) => $j->kiBilder === false);
});

it('E-1: ein fremdes Rezept wird nicht angereichert', function () {
    $fremdesTeam = Team::create(['name' => 'Fremd', 'user_id' => 1, 'personal_team' => false]);
    $fremd = $this->makeRecipe($fremdesTeam, 'Fond: Fremd');

    $res = ($this->run)('foodalchemist.recipes.ENRICH', ['recipe_id' => $fremd->id]);

    expect($res->success)->toBeFalse();
});
