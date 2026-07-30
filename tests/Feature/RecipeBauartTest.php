<?php

use Illuminate\Support\Facades\DB;
use Platform\Core\Contracts\LLMProviderContract;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeFinding;
use Platform\FoodAlchemist\Services\DataQualityService;
use Platform\FoodAlchemist\Services\RecipeBauartService;
use Platform\FoodAlchemist\Services\RecipeFindingService;
use Platform\FoodAlchemist\Services\RecipeReviewService;
use Platform\FoodAlchemist\Tests\Support\CopilotStub;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 21 · S5b-2 (Tranche B) — „Gericht oder Komponente?" als eigener Pass.
 *
 * Vier Dinge tragen die Etappe und werden hier bewiesen:
 *  1. **Widerspruch erzeugt einen Befund, Zustimmung nicht.** Ein „passt schon" gehört
 *     in den Stempel, nicht in die Ablage.
 *  2. **Der Fingerprint ist stabil, obwohl der Befund kein Zeilen-Ziel hat.** Sonst
 *     hebelte jede neu formulierte Begründung eine Ablehnung aus.
 *  3. **Die zwei Pässe fassen sich nicht an** — weder beim Schließen nicht mehr
 *     gemeldeter Befunde noch beim Prüf-Stempel.
 *  4. **Zwei Signale, nicht eins.** Ein Bauart-Zweifel darf nicht als Rezeptur-Befund
 *     mitzählen.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam));

    $this->g = \Platform\FoodAlchemist\Models\FoodAlchemistVocabEinheit::create([
        'team_id' => $this->rootTeam->id, 'slug' => 'g', 'display_de' => 'Gramm', 'dimension' => 'mass', 'default_in_g' => 1,
    ]);

    // Ein VK-„Gericht", das nach Bauart eine Komponente ist — der Ankerfall der Etappe.
    $this->recipe = FoodAlchemistRecipe::create([
        'team_id' => $this->rootTeam->id, 'recipe_key' => 's5b2-sauce', 'name' => 'Pfefferrahm-Sauce',
        'status' => 'approved', 'is_sales_recipe' => true, 'preparation' => 'Reduzieren, montieren, abschmecken.',
    ]);

    $gp = $this->makeGp($this->rootTeam, 'Sahne: frisch');
    $this->zeileId = DB::table('foodalchemist_recipe_ingredients')->insertGetId([
        'uuid' => (string) \Symfony\Component\Uid\UuidV7::generate(), 'team_id' => $this->rootTeam->id,
        'recipe_id' => $this->recipe->id, 'gp_id' => $gp->id, 'raw_text' => 'Sahne', 'display_name' => 'Sahne',
        'quantity' => 500, 'unit_vocab_id' => $this->g->id, 'position' => 1,
        'created_at' => now(), 'updated_at' => now(),
    ]);
});

/** Provider-Stub für den Bauart-Pass: liefert genau diese Einstufung. */
function bindBauartStub(?string $einstufung, float $konfidenz = 0.9, string $begruendung = 'Sauce ohne Sättigungskomponente.'): void
{
    config(['foodalchemist.ai.provider' => 'core']);
    app()->bind(LLMProviderContract::class, fn () => new class($einstufung, $konfidenz, $begruendung) implements LLMProviderContract
    {
        public function __construct(private ?string $einstufung, private float $konfidenz, private string $begruendung) {}

        public function getName(): string
        {
            return 'test-stub';
        }

        public function chat(array $messages, array $options = []): array
        {
            return ['content' => json_encode(['werte' => [
                'einstufung' => $this->einstufung, 'konfidenz' => $this->konfidenz, 'begruendung' => $this->begruendung,
            ], 'confidence' => $this->konfidenz, 'reasoning' => 'stub']),
                'usage' => [], 'model' => 'stub', 'tool_calls' => null];
        }

        public function streamChat(array $messages, callable $onDelta, array $options = []): void
        {
            $onDelta($this->chat($messages, $options)['content']);
        }

        public function getAvailableModels(): array
        {
            return ['stub'];
        }

        public function getDefaultModel(): string
        {
            return 'stub';
        }

        public function isAvailable(): bool
        {
            return true;
        }
    });
}

it('S5b-2: Widerspruch wird ein Befund, Zustimmung nicht', function () {
    bindBauartStub('komponente');
    $ergebnis = app(RecipeBauartService::class)->pruefe($this->rootTeam, $this->recipe->id);

    expect($ergebnis['ist'])->toBe('gericht')
        ->and($ergebnis['urteil'])->toBe('komponente')
        ->and($ergebnis['befunde'])->toHaveCount(1)
        ->and($ergebnis['befunde'][0]['art'])->toBe('bauart')
        ->and($ergebnis['befunde'][0]['zutat_id'] ?? null)->toBeNull();

    bindBauartStub('gericht');
    expect(app(RecipeBauartService::class)->pruefe($this->rootTeam, $this->recipe->id)['befunde'])->toBe([]);
});

it('S5b-2: eine Antwort ausserhalb des Vokabulars erzeugt keinen Befund', function () {
    bindBauartStub('beilage');                                       // kein zulässiges Wort
    $ergebnis = app(RecipeBauartService::class)->pruefe($this->rootTeam, $this->recipe->id);

    expect($ergebnis['urteil'])->toBeNull()->and($ergebnis['befunde'])->toBe([]);
});

it('S5b-2: der Fingerprint hängt an der Art, nicht an der Begründung — eine Ablehnung hält', function () {
    $svc = app(RecipeFindingService::class);

    bindBauartStub('komponente', 0.9, 'Erste Begründung.');
    $svc->pruefeUndAblegen($this->rootTeam, $this->recipe->id, null, RecipeFindingService::PASS_BAUART);

    $zeile = FoodAlchemistRecipeFinding::where('kind', 'bauart')->firstOrFail();
    expect($zeile->auto_applicable)->toBeFalse()
        ->and($zeile->applicability)->toBe('strukturentscheidung');
    $svc->entscheide($this->rootTeam, $zeile->id, 'verworfen');

    // Folgelauf: derselbe Sachverhalt, komplett andere Formulierung.
    bindBauartStub('komponente', 0.95, 'Ganz anders formulierte zweite Begründung.');
    $z = $svc->pruefeUndAblegen($this->rootTeam, $this->recipe->id, null, RecipeFindingService::PASS_BAUART);

    expect(FoodAlchemistRecipeFinding::where('kind', 'bauart')->count())->toBe(1)
        ->and($z['neu'])->toBe(0)->and($z['offen'])->toBe(0)
        ->and($zeile->refresh()->status)->toBe('verworfen')
        ->and($zeile->seen_count)->toBe(2);
});

it('S5b-2: die zwei Pässe räumen sich nicht gegenseitig ab', function () {
    $svc = app(RecipeFindingService::class);

    // Copilot-Pass legt einen Mengen-Befund ab …
    CopilotStub::bind([['art' => 'menge', 'zutat_id' => $this->zeileId, 'quantity' => 600,
        'begruendung' => 'Zu wenig.', 'konfidenz' => 0.9]]);
    $svc->pruefeUndAblegen($this->rootTeam, $this->recipe->id);

    // … der Bauart-Pass darf ihn nicht als „nicht mehr gemeldet" schließen.
    bindBauartStub('komponente');
    $z = $svc->pruefeUndAblegen($this->rootTeam, $this->recipe->id, null, RecipeFindingService::PASS_BAUART);

    expect($z['verschwunden'])->toBe(0)
        ->and(FoodAlchemistRecipeFinding::where('kind', 'menge')->value('status'))->toBe('offen')
        ->and(FoodAlchemistRecipeFinding::where('kind', 'bauart')->value('status'))->toBe('offen');

    // Und umgekehrt: der Bauart-Stempel nimmt dem Copilot nicht die Fälligkeit.
    $r = DB::table('foodalchemist_recipes')->where('id', $this->recipe->id)->first();
    expect($r->structure_reviewed_at)->not->toBeNull()->and($r->ai_reviewed_at)->not->toBeNull();

    $this->travel(2)->minutes();
    FoodAlchemistRecipe::whereKey($this->recipe->id)->update(['updated_at' => now()]);

    expect($svc->arbeitsmenge($this->rootTeam)->pluck('id')->all())->toBe([$this->recipe->id])
        ->and($svc->arbeitsmenge($this->rootTeam, false, RecipeFindingService::PASS_BAUART)->pluck('id')->all())
        ->toBe([$this->recipe->id]);
});

it('S5b-2: der Bauart-Befund zählt in sein eigenes Signal, nicht in rezept_plausi_ki', function () {
    $svc = app(RecipeFindingService::class);

    bindBauartStub('komponente');
    $svc->pruefeUndAblegen($this->rootTeam, $this->recipe->id, null, RecipeFindingService::PASS_BAUART);

    expect($svc->offeneUeberSchwelle($this->rootTeam, null, RecipeReviewService::ARTEN_STRUKTUR)->count())->toBe(1)
        ->and($svc->offeneUeberSchwelle($this->rootTeam, null, RecipeReviewService::ARTEN_COPILOT)->count())->toBe(0);

    $dq = app(DataQualityService::class);
    expect($dq->countFor($this->rootTeam, 'rezept_gericht_vs_komponente'))->toBe(1)
        ->and($dq->countFor($this->rootTeam, 'rezept_plausi_ki'))->toBe(0)
        // Das Panel muss das Rezept unter dem neuen Signal auch wiederfinden.
        ->and($dq->trifftObjekt($this->rootTeam, 'rezept_gericht_vs_komponente', 'recipe', $this->recipe->id))->toBeTrue();
});

it('S5b-2: der Batch-Command fährt den Bauart-Pass und weist einen falschen Pass ab', function () {
    bindBauartStub('komponente');

    $this->artisan('foodalchemist:recipe-findings', ['--team' => $this->rootTeam->id, '--pass' => 'bauart', '--limit' => 5])
        ->assertExitCode(0);

    expect(FoodAlchemistRecipeFinding::where('kind', 'bauart')->count())->toBe(1);

    $this->artisan('foodalchemist:recipe-findings', ['--team' => $this->rootTeam->id, '--pass' => 'quatsch'])
        ->assertExitCode(1);
});
