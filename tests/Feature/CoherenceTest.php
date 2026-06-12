<?php

use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Platform\FoodAlchemist\Livewire\Verkauf\DetailPanel;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeCulinaryCoherence;
use Platform\FoodAlchemist\Services\Ai\AiGatewayService;
use Platform\FoodAlchemist\Services\Ai\AiProposal;
use Platform\FoodAlchemist\Services\CoherenceService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * D-6 §5.x / GL-10: Kohärenz-Judge (zweite Achse) + Teller-Heber — Cache-
 * Lebenszyklus (judge → Zeile, Zutaten-Änderung → stale, Re-Judge ersetzt),
 * Validierung (score-Clamp, Heber-Typ-Whitelist), ehrlicher Nicht-Treffer
 * ohne Cache-Write (FakeProvider-Grenze).
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam));
    config(['foodalchemist.ai.provider' => 'fake']);

    $this->vk = FoodAlchemistRecipe::create([
        'team_id' => $this->rootTeam->id, 'recipe_key' => 'vk-koh', 'name' => 'HG: Käsekrainer | Brioche',
        'status' => 'draft', 'ist_verkaufsrezept' => true,
    ]);
    $g = \Platform\FoodAlchemist\Models\FoodAlchemistVocabEinheit::create(['team_id' => $this->rootTeam->id, 'slug' => 'g', 'display_de' => 'Gramm', 'dimension' => 'mass', 'default_in_g' => 1]);
    $gp = $this->makeGp($this->rootTeam, 'Käsekrainer');
    DB::table('foodalchemist_recipe_ingredients')->insert([
        'uuid' => (string) \Symfony\Component\Uid\UuidV7::generate(), 'team_id' => $this->rootTeam->id,
        'recipe_id' => $this->vk->id, 'gp_id' => $gp->id, 'raw_text' => 'Käsekrainer', 'display_name' => 'Käsekrainer',
        'menge' => 180, 'einheit_vocab_id' => $g->id, 'position' => 1, 'created_at' => now(), 'updated_at' => now(),
    ]);
    $this->zeileId = (int) DB::getPdo()->lastInsertId();

    $this->mockGateway = function (array $werte) {
        $this->mock(AiGatewayService::class, function ($mock) use ($werte) {
            $mock->shouldReceive('propose')->andReturn(new AiProposal($werte, 0.95, 'Mock', [], 'judge-mock-1'));
        });
    };
});

it('judge: schreibt Cache-Zeile (Score-Clamp, Label, Modell, Hash); Zutaten-Änderung macht stale; Re-Judge ersetzt', function () {
    ($this->mockGateway)(['score' => 195, 'label' => 'Klassischer Teller', 'begruendung' => 'Streetfood-Logik.', 'schwachstelle' => 'Senf']);
    $zeile = app(CoherenceService::class)->judge($this->rootTeam, $this->vk->id);

    expect($zeile->score)->toBe(100)                                  // 195 → Clamp
        ->and($zeile->label)->toBe('Klassischer Teller')
        ->and($zeile->schwachstelle)->toBe('Senf')
        ->and($zeile->judge_model)->toBe('judge-mock-1')
        ->and($zeile->judged_at)->not->toBeNull()
        ->and(FoodAlchemistRecipeCulinaryCoherence::count())->toBe(1);

    $status = app(CoherenceService::class)->status($this->rootTeam, $this->vk->id);
    expect($status['stale'])->toBeFalse();

    // Zutaten-Änderung ⇒ components_hash passt nicht mehr ⇒ stale (GL-10-Invalidierung)
    DB::table('foodalchemist_recipe_ingredients')->where('id', $this->zeileId)->update(['menge' => 250]);
    expect(app(CoherenceService::class)->status($this->rootTeam, $this->vk->id)['stale'])->toBeTrue();

    // Re-Judge ersetzt die EINE Zeile (kein Duplikat) und ist wieder frisch
    ($this->mockGateway)(['score' => 72, 'label' => 'Solide', 'begruendung' => 'Neu.', 'schwachstelle' => null]);
    app(CoherenceService::class)->judge($this->rootTeam, $this->vk->id);
    expect(FoodAlchemistRecipeCulinaryCoherence::count())->toBe(1)
        ->and(FoodAlchemistRecipeCulinaryCoherence::first()->score)->toBe(72)
        ->and(app(CoherenceService::class)->status($this->rootTeam, $this->vk->id)['stale'])->toBeFalse();
});

it('judge ohne score (FakeProvider-Echo) = ehrlicher Nicht-Treffer ohne Cache-Write', function () {
    expect(fn () => app(CoherenceService::class)->judge($this->rootTeam, $this->vk->id))
        ->toThrow(RuntimeException::class, 'kein verwertbares Urteil');
    expect(FoodAlchemistRecipeCulinaryCoherence::count())->toBe(0);
});

it('tellerHeber: validiert Typen + Confidence, lebt in derselben Zeile, lässt das Judge-Urteil unberührt', function () {
    ($this->mockGateway)(['score' => 95, 'label' => 'Klassischer Teller', 'begruendung' => 'x', 'schwachstelle' => null]);
    app(CoherenceService::class)->judge($this->rootTeam, $this->vk->id);

    ($this->mockGateway)([
        'einschaetzung' => 'Gewinnt durch hellere Säure.',
        'vorschlaege' => [
            ['typ' => 'kontrast', 'zutat' => 'Eingelegte rote Zwiebeln', 'kategorie' => 'Säure', 'begruendung' => 'Durchbricht die Fettlastigkeit.', 'confidence' => 0.92],
            ['typ' => 'quatsch', 'zutat' => 'Schnittlauch', 'kategorie' => null, 'begruendung' => null, 'confidence' => 7],
            ['typ' => 'veredelung', 'zutat' => '', 'kategorie' => null, 'begruendung' => null, 'confidence' => 0.5],  // ohne Zutat ⇒ raus
        ],
    ]);
    $zeile = app(CoherenceService::class)->tellerHeber($this->rootTeam, $this->vk->id);

    expect($zeile->heber_json['einschaetzung'])->toBe('Gewinnt durch hellere Säure.')
        ->and($zeile->heber_json['vorschlaege'])->toHaveCount(2)
        ->and($zeile->heber_json['vorschlaege'][0]['typ'])->toBe('kontrast')
        ->and($zeile->heber_json['vorschlaege'][1]['typ'])->toBe('ergaenzung')   // Whitelist-Fallback
        ->and((float) $zeile->heber_json['vorschlaege'][1]['confidence'])->toBe(1.0)  // Clamp (JSON-Roundtrip macht 1.0 → 1)
        ->and($zeile->heber_model)->toBe('judge-mock-1')
        ->and($zeile->score)->toBe(95)                                            // Judge-Urteil unberührt
        ->and(FoodAlchemistRecipeCulinaryCoherence::count())->toBe(1);
});

it('Panel: Sektionen togglen, pruefeKohaerenz zeigt Urteil, Fake-Fehler landet als kiFehler (kein Crash)', function () {
    ($this->mockGateway)(['score' => 95, 'label' => 'Klassischer Teller', 'begruendung' => 'Streetfood-Logik.', 'schwachstelle' => null]);

    Livewire::test(DetailPanel::class, ['recipeId' => $this->vk->id])
        ->call('toggleSektion', 'kohaerenz')
        ->call('pruefeKohaerenz')
        ->assertSet('kiFehler', null)
        ->assertSee('95 %')
        ->assertSee('Klassischer Teller')
        ->assertSee('judge-mock-1')
        ->call('toggleSektion', 'nachbarn')                           // deterministisch, leerer Graph ⇒ Leer-Hinweis
        ->assertSee('Aroma-Nachbarn');
});

it('Panel: FakeProvider-Echo (kein score) wird als kiFehler angezeigt, nichts gecacht', function () {
    Livewire::test(DetailPanel::class, ['recipeId' => $this->vk->id])
        ->call('toggleSektion', 'heber')
        ->call('schlageHeberVor')
        ->assertSet('kiFehler', fn ($f) => str_contains((string) $f, 'echter Provider'));
    expect(FoodAlchemistRecipeCulinaryCoherence::count())->toBe(0);
});
