<?php

use Illuminate\Support\Facades\DB;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 84 (Kurator 17.10.) · `foodalchemist:teller-rollen-klassifizieren --team=X` meldet sich als erster Nutzer von X an. Dessen UI-Team kann
 * ein anderes sein — die KI-Abrechnung (ai_call_log.team_id) muss trotzdem auf X laufen (Abrechnung je Mandant), unabhängig von der Stufe.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    config(['foodalchemist.ai.provider' => 'fake', 'foodalchemist.ai.backoff' => [], 'foodalchemist.team_sicht.stufe' => 0]);
    $this->gericht = $this->makeRecipe($this->childA, '[HG] Rinderfilet', ['status' => 'draft', 'is_sales_recipe' => true]);
    $this->zeile = $this->makeIngredient($this->gericht, 'Rinderfilet', null, '150', 1);
    DB::table('foodalchemist_recipe_ingredients')->where('id', $this->zeile->id)->update(['role' => null]);

    $nutzer = $this->makeUser($this->childA, 'Koch A');
    $nutzer->forceFill(['current_team_id' => $this->childB->id])->save();   // UI-Team ist ein anderes Team
    DB::table('team_user')->where('team_id', $this->childA->id)->where('user_id', '!=', $nutzer->id)->delete();

    $zeileId = $this->zeile->id;
    app()->bind(\Platform\FoodAlchemist\Services\Ai\FakeAiProvider::class, fn () => new class($zeileId) extends \Platform\FoodAlchemist\Services\Ai\FakeAiProvider
    {
        public function __construct(private int $zeile) {}

        public function chat(array $messages, array $options = []): array
        {
            return ['content' => json_encode(['werte' => ['rollen' => [$this->zeile => 'traeger']], 'confidence' => 0.8, 'reasoning' => 'stub']),
                'usage' => ['input_tokens' => 10, 'output_tokens' => 5], 'model' => 'fake', 'tool_calls' => null];
        }
    });
});

it('KI-Aufruf des Kommandos wird dem --team gebucht, nicht dem UI-Team des angemeldeten Nutzers', function () {
    $this->artisan('foodalchemist:teller-rollen-klassifizieren', ['--team' => $this->childA->id, '--apply' => true])->assertSuccessful();

    $log = DB::table('foodalchemist_ai_call_log')->where('feature', 'vk.rollen')->latest('id')->first();
    expect($log)->not->toBeNull()
        ->and((int) $log->team_id)->toBe((int) $this->childA->id);
    expect($this->zeile->fresh()->role)->toBe('traeger');
});
