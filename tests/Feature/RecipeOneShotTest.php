<?php

use Illuminate\Support\Facades\DB;
use Platform\FoodAlchemist\Enums\BulkRunStatus;
use Platform\FoodAlchemist\Enums\BulkRunType;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeCategory;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeMainGroup;
use Platform\FoodAlchemist\Services\Ai\FakeAiProvider;
use Platform\FoodAlchemist\Services\BulkEnrichService;
use Platform\FoodAlchemist\Services\RecipeOneShotService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 03 L7a — der Kaskaden-Motor der One-Shot-Vollerstellung.
 *
 * Zu beweisen ist die Grenze, an der L7 sich von „✨ Alles anreichern" unterscheidet:
 * die Kaskade übernimmt SELBST, darf dafür aber nur LEERE Ziel-Felder anfassen
 * (GL-07 — Auto-Persistenz ist gegen menschliche Pflege verboten, nicht gegen
 * Leerstellen), fährt dabei die bestehende BulkEnrichService-Strecke inklusive
 * Vorschlags-Speicher, und bringt bei Provider-Ausfall nie das Rezept mit runter.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam));
    config(['foodalchemist.ai.provider' => 'fake', 'foodalchemist.ai.backoff' => []]);
    $this->svc = app(RecipeOneShotService::class);

    $hg = FoodAlchemistRecipeMainGroup::create(['team_id' => $this->rootTeam->id, 'code' => 'FND', 'label' => 'Fonds & Saucen']);
    $this->kategorie = FoodAlchemistRecipeCategory::create([
        'team_id' => $this->rootTeam->id, 'main_group_id' => $hg->id, 'code' => 'RED', 'label' => 'Reduktionen',
    ]);

    // Ein Stub, der die Ober-Menge aller Feld-Keys liefert; jeder Schritt zieht
    // sich mit seinem eigenen `$extract` heraus, was er braucht.
    $katId = $this->kategorie->id;
    $this->stub = function () use ($katId) {
        app()->singleton(FakeAiProvider::class, fn () => new class($katId) extends FakeAiProvider
        {
            public function __construct(private int $katId)
            {
            }

            public function chat(array $messages, array $options = []): array
            {
                return ['content' => json_encode(['werte' => [
                    'description' => 'Dunkle, sirupartige Saucenbasis.',
                    'category_id' => $this->katId,
                    'taste_direction' => 'herzhaft',
                ], 'confidence' => 0.82]), 'model' => 'fake-oneshot', 'usage' => []];
            }
        });
    };

    $this->basis = fn (array $attr = []) => FoodAlchemistRecipe::create([
        'team_id' => $this->rootTeam->id, 'recipe_key' => 'oneshot-' . bin2hex(random_bytes(4)),
        'name' => 'Reduktion: Rotwein-Schalotte', 'status' => 'draft', ...$attr,
    ]);
});

it('L7a: die Schrittfolge wird auf LÜCKEN geschnitten — was der Generator schon schrieb, wird nicht erneut bezahlt', function () {
    // So sieht ein frisch generiertes Basisrezept aus: description steht (Lineage ki),
    // Kategorie und Geschmacksrichtung sind offen.
    $r = ($this->basis)(['description' => 'Vom Generator.', 'description_source' => 'ki']);

    $offen = app(BulkEnrichService::class)->luecken($r, BulkEnrichService::SCHRITTE);

    expect($offen)->toBe(['category', 'geschmack'])
        ->and(BulkEnrichService::ZIELFELDER['category']['feld'])->toBe('category_id');
});

it('L7a: die Kaskade füllt die Lücken selbst — mit Lineage, ohne die Bestandsfelder anzufassen', function () {
    ($this->stub)();
    $r = ($this->basis)(['description' => 'Vom Generator.', 'description_source' => 'ki']);

    $erg = $this->svc->anreichern($this->rootTeam, $r);

    expect($erg['schritte'])->toBe(['category', 'geschmack'])
        ->and($erg['uebersprungen'])->toBe(['description'])
        ->and($erg['uebernommen'])->toBe(2)
        ->and($erg['offen'])->toBe(0)
        ->and($erg['fehler'])->toBeNull();

    $frisch = $r->fresh();
    expect((int) $frisch->category_id)->toBe((int) $this->kategorie->id)
        ->and($frisch->category_source)->toBe('ki')
        ->and($frisch->taste_direction)->toBe('herzhaft')
        ->and($frisch->description)->toBe('Vom Generator.')            // unangetastet
        ->and($frisch->status->value)->toBe('draft');                     // Vollerstellung ≠ Freigabe

    // Die Strecke ist die bestehende: Lauf-Zeile + Vorschlags-Speicher (Audit),
    // nur ohne den zweiten Job.
    $lauf = app(BulkEnrichService::class)->status($this->rootTeam, $erg['run_id']);
    expect($lauf->type)->toBe(BulkRunType::Enrich)
        ->and($lauf->status)->toBe(BulkRunStatus::Done)
        ->and((int) $lauf->failed)->toBe(0)
        ->and(DB::table('foodalchemist_bulk_proposals')->where('run_id', $erg['run_id'])
            ->where('status', 'uebernommen')->count())->toBe(2);
});

it('L7a: ein von Hand gepflegtes Feld erzeugt gar keinen Vorschlag — es kostet nicht einmal einen Call', function () {
    ($this->stub)();
    $r = ($this->basis)([
        'description' => 'Handarbeit.', 'description_source' => 'manual',
        'category_id' => $this->kategorie->id, 'category_source' => 'manual',
        'taste_direction' => 'suess',
    ]);

    $erg = $this->svc->anreichern($this->rootTeam, $r);

    expect($erg['schritte'])->toBe([])
        ->and($erg['run_id'])->toBeNull()                               // kein Lauf ⇒ kein Provider-Call
        ->and($erg['uebernommen'])->toBe(0)
        ->and(DB::table('foodalchemist_bulk_runs')->count())->toBe(0)
        ->and(DB::table('foodalchemist_ai_call_log')->count())->toBe(0);

    expect($r->fresh()->description)->toBe('Handarbeit.')
        ->and($r->fresh()->taste_direction)->toBe('suess');
});

it('L7a: die Ebene entscheidet das is_sales_recipe-Flag — ein Gericht bekommt die VK-Schrittfolge', function () {
    $vk = ($this->basis)(['is_sales_recipe' => true, 'name' => 'TEL: Rinderrücken | Jus']);

    $offen = app(BulkEnrichService::class)->luecken($vk, BulkEnrichService::SCHRITTE_VK);

    expect($offen)->toBe(['description', 'wording', 'plating', 'speisen_klasse'])
        ->and($offen)->not->toContain('category');                      // 186er-Kategorie ist Basisrezept-Ebene
});

it('L7a: Provider-Ausfall mitten in der Kaskade lässt das Rezept vollständig zurück — nie ein halbes Wrack', function () {
    app()->singleton(FakeAiProvider::class, fn () => new class extends FakeAiProvider
    {
        public function chat(array $messages, array $options = []): array
        {
            throw new \RuntimeException('Provider weg.');
        }
    });
    $r = ($this->basis)(['description' => 'Vom Generator.', 'description_source' => 'ki']);

    $erg = $this->svc->anreichern($this->rootTeam, $r);

    expect($erg['fehler'])->toBeNull()                                  // der Pass wirft nicht nach außen
        ->and($erg['uebernommen'])->toBe(0)
        ->and((int) app(BulkEnrichService::class)->status($this->rootTeam, $erg['run_id'])->failed)->toBe(1)
        ->and(DB::table('foodalchemist_bulk_proposals')->where('run_id', $erg['run_id'])
            ->whereNotNull('error')->count())->toBe(2);

    $frisch = $r->fresh();
    expect($frisch)->not->toBeNull()
        ->and($frisch->description)->toBe('Vom Generator.')
        ->and($frisch->status->value)->toBe('draft');
});

// ── L7b-2: das Kohärenz-Glied am Ende der Kaskade ────────────────────────────
//
// Spec-Kaskade: „… → Auto-Enrichment → Kohärenz-Check (VK) → fertig". Die
// Fixtures füllen die vier VK-Ziel-Felder absichtlich vor, damit der
// Anreicherungs-Pass leer läuft (`schritte === []`) — dann steht in den Tests
// wirklich nur das Urteil und nicht der halbe Bulk-Pass.

/** VK-Gericht mit vollständigen Text-Feldern (⇒ keine Anreicherungs-Lücke) + n Komponenten. */
$vkFertig = function (object $t, int $komponenten = 2): \Platform\FoodAlchemist\Models\FoodAlchemistRecipe {
    $bau = \Closure::bind(function (int $komponenten) {
        $hg = \Platform\FoodAlchemist\Models\FoodAlchemistDishMainGroup::firstOrCreate(['code' => 'TEL'], ['label' => 'Tellergericht']);
        $klasse = \Platform\FoodAlchemist\Models\FoodAlchemistDishClass::firstOrCreate(
            ['dish_main_group_id' => $hg->id, 'code' => 'TEL-OMN'],
            ['label' => 'Teller omnivor', 'diet_form' => 'omnivor'],
        );

        $vk = $this->makeRecipe($this->rootTeam, 'TEL: Rinderrücken | Jus', [
            'is_sales_recipe' => true, 'status' => 'draft',
            'description' => 'Steht.', 'sales_wording_standard' => 'Steht.',
            'plating_text' => 'Steht.', 'dish_class_id' => $klasse->id,
            'taste_direction' => 'herzhaft',
        ]);
        for ($i = 1; $i <= $komponenten; $i++) {
            $this->makeIngredient($vk, 'Komponente ' . $i, $this->makeGp($this->rootTeam, 'Komponente ' . $i . '-' . $vk->id), '100', $i);
        }

        return $vk->refresh();
    }, $t, $t::class);

    return $bau($komponenten);
};

it('L7b-2: das Kohärenz-Urteil läuft auch dann, wenn es NICHTS anzureichern gibt — es hängt an den Komponenten, nicht am Pass', function () use ($vkFertig) {
    $this->mock(\Platform\FoodAlchemist\Services\Ai\AiGatewayService::class, function ($mock) {
        $mock->shouldReceive('propose')->once()->with('vk.kohaerenz', \Mockery::any())
            ->andReturn(new \Platform\FoodAlchemist\Services\Ai\AiProposal(
                ['score' => 78, 'label' => 'Klassisch geschlossen', 'reasoning' => 'Jus bindet.', 'schwachstelle' => 'Säure fehlt'],
                0.9, 'Mock', [], 'judge-mock',
            ));
    });
    $vk = $vkFertig($this);

    $erg = app(RecipeOneShotService::class)->anreichern($this->rootTeam, $vk);

    // Kein Anreicherungs-Lauf (alle Ziel-Felder belegt) — und trotzdem ein Urteil.
    expect($erg['schritte'])->toBe([])
        ->and($erg['run_id'])->toBeNull()
        ->and($erg['kohaerenz_urteil'])->toBe([
            'score' => 78, 'label' => 'Klassisch geschlossen', 'schwachstelle' => 'Säure fehlt', 'fehler' => null,
        ]);

    // Das Urteil ist gecacht (GL-10-Cache, keine zweite Wahrheit) und frisch.
    expect(\Platform\FoodAlchemist\Models\FoodAlchemistRecipeCulinaryCoherence::where('recipe_id', $vk->id)->count())->toBe(1)
        ->and(app(\Platform\FoodAlchemist\Services\CoherenceService::class)->status($this->rootTeam, $vk->id)['stale'])->toBeFalse();
});

it('L7b-2: ein Basisrezept bekommt kein Teller-Urteil — und bezahlt dafür auch keinen Call', function () {
    $this->mock(\Platform\FoodAlchemist\Services\Ai\AiGatewayService::class, function ($mock) {
        $mock->shouldReceive('propose')->never();
    });
    $basis = $this->makeRecipe($this->rootTeam, 'Reduktion: Rotwein-Schalotte', [
        'description' => 'Steht.', 'category_id' => $this->kategorie->id, 'taste_direction' => 'herzhaft',
    ]);
    $this->makeIngredient($basis, 'Schalotte', $this->makeGp($this->rootTeam, 'Schalotte-basis'), '200', 1);
    $this->makeIngredient($basis, 'Rotwein', $this->makeGp($this->rootTeam, 'Rotwein-basis'), '500', 2);

    $erg = app(RecipeOneShotService::class)->anreichern($this->rootTeam, $basis->refresh());

    expect($erg['kohaerenz_urteil'])->toBeNull()
        ->and(\Platform\FoodAlchemist\Models\FoodAlchemistRecipeCulinaryCoherence::count())->toBe(0);
});

it('L7b-2: ein Gericht mit EINER Komponente hat kein Zusammenspiel — kein Urteil, kein Call', function () use ($vkFertig) {
    $this->mock(\Platform\FoodAlchemist\Services\Ai\AiGatewayService::class, function ($mock) {
        $mock->shouldReceive('propose')->never();
    });
    $vk = $vkFertig($this, 1);

    $erg = app(RecipeOneShotService::class)->anreichern($this->rootTeam, $vk);

    expect($erg['kohaerenz_urteil'])->toBeNull()
        ->and(\Platform\FoodAlchemist\Models\FoodAlchemistRecipeCulinaryCoherence::count())->toBe(0);
});

it('L7b-2: ein Judge ohne verwertbaren Score wird zur ehrlichen Lücke — nicht zum Generierungs-Fehler', function () use ($vkFertig) {
    // FakeProvider-Grenze: `judge()` wirft absichtlich und cacht nichts.
    $this->mock(\Platform\FoodAlchemist\Services\Ai\AiGatewayService::class, function ($mock) {
        $mock->shouldReceive('propose')->andReturn(
            new \Platform\FoodAlchemist\Services\Ai\AiProposal(['label' => 'ohne Score'], 0.4, 'Mock', [], 'judge-mock'),
        );
    });
    $vk = $vkFertig($this);

    $erg = app(RecipeOneShotService::class)->anreichern($this->rootTeam, $vk);

    expect($erg['fehler'])->toBeNull()                                  // die Kaskade selbst ist nicht gescheitert
        ->and($erg['kohaerenz_urteil']['score'])->toBeNull()
        ->and($erg['kohaerenz_urteil']['fehler'])->toContain('score')
        ->and(\Platform\FoodAlchemist\Models\FoodAlchemistRecipeCulinaryCoherence::count())->toBe(0);

    expect($vk->fresh()->status->value)->toBe('draft');                 // Rezept unversehrt
});
