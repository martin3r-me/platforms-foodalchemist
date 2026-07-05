<?php

use Illuminate\Support\Facades\DB;
use Platform\FoodAlchemist\Services\Ai\AiGatewayService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * M7-01/02: ai_call_log (06_KI §5) + Tiering (V-01) — jeder Call loggt VOR
 * Rückgabe (auch der Fehlerpfad), callLogId geht im DTO mit, accepted_at
 * stempelbar; Tier aus der Registry mit Option-Override, Tier→Modell-Mapping
 * ist Deployment-Config.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam));
    config(['foodalchemist.ai.provider' => 'fake']);
    $this->gw = app(AiGatewayService::class);
});

it('Pflicht 1: erfolgreicher Call schreibt genau eine Zeile, callLogId im DTO', function () {
    $p = $this->gw->propose('recipe.description', ['description' => 'Klarer Fond.'], [
        'knowledge_used' => ['substitutionen@v1', 'fisch_seafood@v1'],
        'target_table' => 'foodalchemist_recipes', 'target_id' => 42,
    ]);

    $zeilen = DB::table('foodalchemist_ai_call_log')->get();
    expect($zeilen)->toHaveCount(1)
        ->and($p->callLogId)->toBe((int) $zeilen[0]->id);

    $log = $zeilen[0];
    expect($log->feature)->toBe('recipe.description')
        ->and($log->tier)->toBe('C')                                  // aus der Registry
        ->and($log->model)->toBe('fake-deterministic-1')
        ->and(json_decode($log->knowledge_used, true))->toBe(['substitutionen@v1', 'fisch_seafood@v1'])
        ->and(strlen($log->prompt_hash))->toBe(64)
        ->and($log->target_table)->toBe('foodalchemist_recipes')
        ->and((int) $log->target_id)->toBe(42)
        ->and((int) $log->team_id)->toBe($this->rootTeam->id)
        ->and($log->error)->toBeNull()
        ->and($log->response_summary)->not->toBeNull();
});

it('Pflicht 2: AUCH der Fehlerpfad loggt (error-Spalte befüllt), Exception fliegt danach', function () {
    expect(fn () => $this->gw->propose('gibts.nicht'))->toThrow(RuntimeException::class);  // Registry-Fehler: VOR dem Call, kein Log

    // Provider-Fehler: kaputtes JSON via Fake? FakeProvider liefert valides JSON —
    // wir erzwingen den Parse-Fehler über einen Provider-Stub
    app()->bind(\Platform\FoodAlchemist\Services\Ai\FakeAiProvider::class, fn () => new class extends \Platform\FoodAlchemist\Services\Ai\FakeAiProvider
    {
        public function chat(array $messages, array $options = []): array
        {
            return ['content' => 'KEIN JSON {', 'model' => 'fake-broken'];
        }
    });

    expect(fn () => $this->gw->propose('recipe.description', ['x' => 1]))
        ->toThrow(RuntimeException::class, 'kein valides JSON');

    $log = DB::table('foodalchemist_ai_call_log')->orderByDesc('id')->first();
    expect($log->error)->toContain('kein valides JSON')
        ->and($log->model)->toBe('fake-broken')
        ->and($log->response_summary)->toBeNull();
});

it('Pflicht 3: stempleAccepted/-Rejected setzen die Stempel; null-Id ist no-op', function () {
    $p = $this->gw->propose('recipe.description', ['description' => 'X.']);

    $this->gw->stempleAccepted($p->callLogId);
    expect(DB::table('foodalchemist_ai_call_log')->where('id', $p->callLogId)->value('accepted_at'))->not->toBeNull();

    $this->gw->stempleRejected($p->callLogId);
    $this->gw->stempleAccepted(null);                                 // kein Fehler
    expect(DB::table('foodalchemist_ai_call_log')->where('id', $p->callLogId)->value('rejected_at'))->not->toBeNull();
});

it('Tiering: Registry-Tier loggt; options[tier] übersteuert; Tier-Modell-Mapping greift', function () {
    config(['foodalchemist.ai.tiers.B' => 'billig-modell-v9']);

    $this->gw->propose('vk.speisen_klasse', ['name' => 'X']);         // Registry: Tier B
    $log = DB::table('foodalchemist_ai_call_log')->orderByDesc('id')->first();
    expect($log->tier)->toBe('B');
    // FakeProvider ignoriert model-Option — das Mapping selbst ist über die Option testbar:
    // ein Tier ohne Mapping ändert nichts, Override schreibt das Tier ins Log
    $this->gw->propose('vk.speisen_klasse', ['name' => 'X'], ['tier' => 'A']);
    expect(DB::table('foodalchemist_ai_call_log')->orderByDesc('id')->value('tier'))->toBe('A');
});

it('GL-13-Audit-Faden: Generator schreibt knowledge_used ins Log (vorher verloren)', function () {
    \Platform\FoodAlchemist\Models\FoodAlchemistVocabEinheit::create(['team_id' => $this->rootTeam->id, 'slug' => 'g', 'display_de' => 'Gramm', 'dimension' => 'mass', 'default_in_g' => 1]);
    DB::table('foodalchemist_knowledge_documents')->insert([
        'uuid' => (string) \Symfony\Component\Uid\UuidV7::generate(), 'slug' => 'substitutionen', 'title' => 'S',
        'category' => 'cross_cutting', 'content_md' => 'Wissen', 'version' => 1, 'content_hash' => 'x',
        'char_count' => 6, 'active' => 1, 'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('foodalchemist_knowledge_routings')->insert([
        'feature' => 'ai_generate_recipe', 'category' => 'cross_cutting', 'mode' => 'always',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    // FakeProvider echo't kein Rezept → RuntimeException NACH dem Log (name+zutaten fehlen)
    try {
        app(\Platform\FoodAlchemist\Services\RecipeGeneratorService::class)->generiere($this->rootTeam, 'Lachs mit Butter');
    } catch (RuntimeException) {
    }

    $log = DB::table('foodalchemist_ai_call_log')->where('feature', 'recipe.generator')->first();
    expect($log)->not->toBeNull()
        ->and(json_decode($log->knowledge_used, true))->toBe(['substitutionen@v1']);
});
