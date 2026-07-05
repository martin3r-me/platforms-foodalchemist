<?php

use Illuminate\Support\Facades\DB;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Services\Ai\FakeAiProvider;
use Platform\FoodAlchemist\Services\BulkEnrichService;
use Platform\FoodAlchemist\Services\TeamSettingsService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * M7-06: Bulk-Autopilot — DoD: 50er-Bulk läuft (Queue sync), Fortschritt
 * sichtbar (run done/total); Vorschläge statt Auto-Persistenz (GL-07),
 * Übernahme Override-First, Kill-Switch zählt Fehler statt Crash.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam));
    config(['foodalchemist.ai.provider' => 'fake', 'foodalchemist.ai.backoff' => []]);
    $this->svc = app(BulkEnrichService::class);

    // Provider liefert je Feld brauchbare Werte (Echo reicht nicht für leere Felder)
    app()->singleton(FakeAiProvider::class, fn () => new class extends FakeAiProvider
    {
        public function chat(array $messages, array $options = []): array
        {
            $user = collect($messages)->last()['content'];
            $werte = str_contains($user, 'Geschmacksrichtung') ? ['taste_direction' => 'herzhaft']
                : (str_contains($user, 'Kategorie') ? ['category_id' => null] : ['description' => 'Bulk-Beschreibung.']);

            return ['content' => json_encode(['werte' => $werte, 'confidence' => 0.8]), 'model' => 'fake-bulk', 'usage' => []];
        }
    });

    $this->rezepte = collect(range(1, 50))->map(fn ($i) => FoodAlchemistRecipe::create([
        'team_id' => $this->rootTeam->id, 'recipe_key' => "bulk_{$i}", 'name' => "Fond: Bulk {$i}", 'status' => 'draft',
    ]));
});

it('DoD: 50er-Bulk läuft durch — Fortschritt done/total, Vorschläge offen, KEINE Auto-Persistenz', function () {
    $runId = $this->svc->starte($this->rootTeam, $this->rezepte->pluck('id')->all());

    $run = $this->svc->status($this->rootTeam, $runId);
    expect($run->status)->toBe('done')                                // Queue sync ⇒ sofort fertig
        ->and((int) $run->total)->toBe(50)
        ->and((int) $run->done)->toBe(50)
        ->and((int) $run->fehler)->toBe(0);

    // 50 × beschreibung/geschmack offen; kategorie (null vom Provider) als leer markiert
    expect(DB::table('foodalchemist_bulk_proposals')->where('run_id', $runId)->where('status', 'offen')->count())->toBe(100)
        ->and(DB::table('foodalchemist_bulk_proposals')->where('run_id', $runId)->where('field', 'category')->where('status', 'leer')->count())->toBe(50)
        ->and(FoodAlchemistRecipe::whereNotNull('description')->count())->toBe(0);  // nie Auto-Persistenz
});

it('Alle übernehmen: schreibt mit Lineage ki + Stempel; Override-First (manual bleibt)', function () {
    $manuell = $this->rezepte[0];
    $manuell->update(['description' => 'Handarbeit.', 'description_source' => 'manual']);

    $runId = $this->svc->starte($this->rootTeam, $this->rezepte->take(3)->pluck('id')->all());
    $n = $this->svc->alleUebernehmen($this->rootTeam, $runId);

    expect($n)->toBe(5)                                               // 3×geschmack + 2×beschreibung (1 manual geblockt)
        ->and($manuell->fresh()->description)->toBe('Handarbeit.')
        ->and($this->rezepte[1]->fresh()->description)->toBe('Bulk-Beschreibung.')
        ->and($this->rezepte[1]->fresh()->description_source)->toBe('ki')
        ->and($this->rezepte[1]->fresh()->taste_direction)->toBe('herzhaft')
        ->and(DB::table('foodalchemist_ai_call_log')->whereNotNull('accepted_at')->count())->toBe(5);

    // geblockter manual-Vorschlag bleibt offen (Review kann später entscheiden)
    expect(DB::table('foodalchemist_bulk_proposals')->where('run_id', $runId)->where('recipe_id', $manuell->id)
        ->where('field', 'description')->value('status'))->toBe('offen');
});

it('Kill-Switch mitten im Bulk: Items zählen als Fehler, Run wird trotzdem done', function () {
    app(TeamSettingsService::class)->update($this->rootTeam, ['ai_active' => false]);

    $runId = $this->svc->starte($this->rootTeam, $this->rezepte->take(5)->pluck('id')->all());
    $run = $this->svc->status($this->rootTeam, $runId);

    expect($run->status)->toBe('done')
        ->and((int) $run->fehler)->toBe(5)
        ->and(DB::table('foodalchemist_bulk_proposals')->where('run_id', $runId)->whereNotNull('fehler')->count())->toBe(15);
});
