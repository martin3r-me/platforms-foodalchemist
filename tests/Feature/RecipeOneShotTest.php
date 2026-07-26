<?php

use Illuminate\Support\Facades\DB;
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
    expect($lauf->type)->toBe('enrich')
        ->and($lauf->status)->toBe('done')
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
