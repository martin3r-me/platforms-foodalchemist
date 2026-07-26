<?php

use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Platform\FoodAlchemist\Livewire\Verkauf\VkModal;
use Platform\FoodAlchemist\Models\FoodAlchemistDishClass;
use Platform\FoodAlchemist\Models\FoodAlchemistDishMainGroup;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Services\Ai\FakeAiProvider;
use Platform\FoodAlchemist\Services\BulkEnrichService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 03 L1b: „✨ Alles anreichern" am Gericht. Zu beweisen ist, dass der Knopf
 * die VK-EBENE anreichert (Beschreibung · VK-Wording · Plating · Speisen-Klasse)
 * und nicht die Basisrezept-Schrittfolge (dort wäre `category` die 186er-Rezept-
 * Kategorie), dass nichts auto-persistiert wird (GL-07) und dass Override-First
 * je Feld gilt — inklusive der Klasse, deren Accept durch den
 * SpeisenKlassenService läuft (Besitzer-Regel + Taxonomie-Validierung).
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam));
    config(['foodalchemist.ai.provider' => 'fake', 'foodalchemist.ai.backoff' => []]);
    $this->svc = app(BulkEnrichService::class);

    $hg = FoodAlchemistDishMainGroup::create(['code' => 'TEL', 'label' => 'Tellergericht']);
    $this->klasse = FoodAlchemistDishClass::create([
        'dish_main_group_id' => $hg->id, 'code' => 'TEL-OMN', 'label' => 'Teller omnivor', 'diet_form' => 'omnivor',
    ]);

    // Provider antwortet je Prompt-Aufgabe (Echo reicht nicht — die Felder sind leer).
    $klasseId = $this->klasse->id;
    app()->singleton(FakeAiProvider::class, fn () => new class($klasseId) extends FakeAiProvider
    {
        public function __construct(private int $klasseId)
        {
        }

        public function chat(array $messages, array $options = []): array
        {
            $user = collect($messages)->last()['content'];
            $werte = match (true) {
                str_contains($user, 'Marketing-Namen') => ['sales_wording_standard' => 'Zarter Rinderrücken'],
                str_contains($user, 'Plating-Anweisung') => ['preparation' => 'Fleisch mittig, Jus angießen.'],
                str_contains($user, 'Klassifiziere') => ['dish_class_id' => $this->klasseId, 'klasse_name' => 'Teller omnivor'],
                default => ['description' => 'Kurzgebratener Rücken mit Jus.'],
            };

            return ['content' => json_encode(['werte' => $werte, 'confidence' => 0.8]), 'model' => 'fake-vk-bulk', 'usage' => []];
        }
    });

    $this->vk = FoodAlchemistRecipe::create([
        'team_id' => $this->rootTeam->id, 'recipe_key' => 'vk-l1b', 'name' => 'TEL: Rinderrücken | Jus',
        'status' => 'draft', 'is_sales_recipe' => true, 'sales_quantity_per_unit_g' => 180,
    ]);
});

it('L1b: der Lauf am Gericht erzeugt die VIER VK-Vorschläge — und schreibt nichts (GL-07)', function () {
    $runId = $this->svc->starteVk($this->rootTeam, [$this->vk->id]);

    $run = $this->svc->status($this->rootTeam, $runId);
    expect($run->status)->toBe('done')
        ->and($run->type)->toBe('enrich_vk')                          // eigener Lauf-Typ, vom Basisrezept-Lauf unterscheidbar
        ->and((int) $run->failed)->toBe(0);

    $felder = DB::table('foodalchemist_bulk_proposals')->where('run_id', $runId)->where('status', 'offen')
        ->orderBy('field')->pluck('field')->all();
    expect($felder)->toBe(['description', 'plating', 'speisen_klasse', 'wording'])
        ->and($felder)->not->toContain('category');                   // die Basisrezept-Ebene bleibt draußen

    $r = $this->vk->fresh();
    expect($r->sales_wording_standard)->toBeNull()
        ->and($r->plating_text)->toBeNull()
        ->and($r->dish_class_id)->toBeNull();
});

it('L1b: »Alle übernehmen« schreibt VK-Wording, Plating, Beschreibung und Klasse mit Lineage ki', function () {
    $runId = $this->svc->starteVk($this->rootTeam, [$this->vk->id]);
    $n = $this->svc->alleUebernehmen($this->rootTeam, $runId);

    $r = $this->vk->fresh();
    expect($n)->toBe(4)
        ->and($r->sales_wording_standard)->toBe('Zarter Rinderrücken')
        ->and($r->sales_wording_source)->toBe('ki')
        ->and($r->plating_text)->toBe('Fleisch mittig, Jus angießen.')
        ->and($r->plating_source)->toBe('ki')
        ->and($r->description)->toBe('Kurzgebratener Rücken mit Jus.')
        ->and((int) $r->dish_class_id)->toBe((int) $this->klasse->id)
        ->and($r->dish_class_source)->toBe('ki')
        ->and(DB::table('foodalchemist_ai_call_log')->whereNotNull('accepted_at')->count())->toBe(4);
});

it('L1b: Override-First je Feld — manuell gepflegtes Wording und manuelle Klasse bleiben', function () {
    $andere = FoodAlchemistDishClass::create([
        'dish_main_group_id' => $this->klasse->dish_main_group_id, 'code' => 'TEL-VEG', 'label' => 'Teller vegan', 'diet_form' => 'vegan',
    ]);
    $this->vk->update([
        'sales_wording_standard' => 'Handarbeit', 'sales_wording_source' => 'manual',
        'dish_class_id' => $andere->id, 'dish_class_source' => 'manual',
    ]);

    $runId = $this->svc->starteVk($this->rootTeam, [$this->vk->id]);
    $n = $this->svc->alleUebernehmen($this->rootTeam, $runId);

    $r = $this->vk->fresh();
    expect($n)->toBe(2)                                               // nur description + plating
        ->and($r->sales_wording_standard)->toBe('Handarbeit')
        ->and((int) $r->dish_class_id)->toBe((int) $andere->id);

    // Die geblockten Vorschläge bleiben offen (Review entscheidet später)
    expect(DB::table('foodalchemist_bulk_proposals')->where('run_id', $runId)
        ->whereIn('field', ['wording', 'speisen_klasse'])->where('status', 'offen')->count())->toBe(2);
});

it('L1b: ein Basisrezept fällt aus dem VK-Lauf (falsche Ebene wird nicht angereichert)', function () {
    $basis = FoodAlchemistRecipe::create([
        'team_id' => $this->rootTeam->id, 'recipe_key' => 'basis-l1b', 'name' => 'Fond: Kalb', 'status' => 'draft',
    ]);

    $runId = $this->svc->starteVk($this->rootTeam, [$basis->id, $this->vk->id]);

    expect((int) $this->svc->status($this->rootTeam, $runId)->total)->toBe(1)
        ->and(DB::table('foodalchemist_bulk_proposals')->where('run_id', $runId)->where('recipe_id', $basis->id)->count())->toBe(0);
});

it('L1b: VK-Schritte auf einem Basisrezept erzeugen einen ehrlichen Fehler statt eines Vorschlags', function () {
    $basis = FoodAlchemistRecipe::create([
        'team_id' => $this->rootTeam->id, 'recipe_key' => 'basis-l1b-2', 'name' => 'Fond: Rind', 'status' => 'draft',
    ]);

    // Der direkte Weg (ohne den ->verkauf()-Schnitt von starteVk) muss trotzdem sauber scheitern.
    $runId = $this->svc->starte($this->rootTeam, [$basis->id], BulkEnrichService::SCHRITTE_VK);

    expect((int) $this->svc->status($this->rootTeam, $runId)->failed)->toBe(1)
        ->and(DB::table('foodalchemist_bulk_proposals')->where('run_id', $runId)->whereNotNull('error')->count())->toBe(3)
        ->and($basis->fresh()->plating_text)->toBeNull();
});

it('L1b: das VkModal fährt den Lauf und rendert die Status-Zeile mit »Alle übernehmen«', function () {
    $c = Livewire::test(VkModal::class)
        ->call('oeffnen', $this->vk->id)
        ->call('allesAnreichern')
        ->assertSet('fehler', null);

    expect($c->get('bulkRunId'))->not->toBeNull();
    expect($c->html())->toContain('data-vk-anreichern-status')
        ->and($c->html())->toContain('data-vk-anreichern-uebernehmen');

    $c->call('bulkAlleUebernehmen')->assertSet('bulkRunId', null);

    expect($this->vk->fresh()->sales_wording_standard)->toBe('Zarter Rinderrücken');
});

it('L1b: der Knopf steht im Gericht-Editor', function () {
    $html = Livewire::test(VkModal::class)->call('oeffnen', $this->vk->id)->html();

    expect($html)->toContain('data-vk-alles-anreichern');
});
