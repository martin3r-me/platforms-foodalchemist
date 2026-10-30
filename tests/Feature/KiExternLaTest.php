<?php

use Illuminate\Support\Facades\DB;
use Platform\Core\Contracts\LLMProviderContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Tools\ToolRegistry;
use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplier;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItemStructure;
use Platform\FoodAlchemist\Services\Ai\FakeAiProvider;
use Platform\FoodAlchemist\Services\Ai\KiAuftragService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * #1002 (LA-Wege): la.GP_ABGLEICH — Vergleichstest Plattformweg = MCP-Weg. Der Abgleich fragt bis zu zweimal nacheinander
 * (gp.suggest für die Felder, gp.la_abgleich für die Entscheidung); der zweite Prompt hängt von der ersten Antwort ab.
 * Per MCP: je Runde ein Auftrag, nach ANTWORT den Aufruf wiederholen.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->user = $this->makeUser($this->rootTeam);
    $this->actingAs($this->user);
    config(['foodalchemist.ai.provider' => 'core', 'foodalchemist.ai.backoff' => [], 'foodalchemist.ai.mcp_extern' => true,
        'foodalchemist.master_team_id' => $this->rootTeam->id]);
    \Platform\FoodAlchemist\Support\MasterTeam::flush();
    $this->registry = app(ToolRegistry::class);
    $this->mcp = new ToolContext($this->user, $this->rootTeam, ['team_source' => 'user_current']);
    $this->intern = new ToolContext($this->user, $this->rootTeam);

    $this->sup = FoodAlchemistSupplier::create(['team_id' => $this->rootTeam->id, 'name' => 'Chefs']);
    $this->gp = FoodAlchemistGp::create(['team_id' => $this->rootTeam->id, 'name' => 'Zwiebel: frisch, ganz', 'gp_key' => 'k_zw', 'status' => 'approved',
        'requires_la' => true, 'commodity_group_code' => '01', 'condition' => 'frisch', 'main_ingredient_slug' => 'zwiebel']);
    $this->nr = 0;
    $this->la = fn (string $bez) => FoodAlchemistSupplierItem::create(['team_id' => $this->rootTeam->id, 'supplier_id' => $this->sup->id, 'designation' => $bez,
        'article_number' => 'Z' . (++$this->nr), 'qty' => 1, 'unit_code' => 'kg']);

    $this->stub = new class extends FakeAiProvider
    {
        public int $calls = 0;

        public int $gpId = 0;

        public function chat(array $messages, array $options = []): array
        {
            $this->calls++;
            $abgleich = str_contains(json_encode($messages, JSON_UNESCAPED_UNICODE), 'deterministischer_rang');
            $werte = $abgleich
                ? ['aktion' => 'verknuepfen', 'gp_id' => $this->gpId, 'begruendung' => 'Gleiche Ware, gleicher Zustand.', 'sicherheit' => 0.95]
                : ['hauptzutat' => 'Zwiebel', 'condition' => 'frisch', 'commodity_group_code' => '01', 'processing' => 'ganz'];

            return ['content' => json_encode(['werte' => $werte, 'confidence' => 0.9, 'reasoning' => 'stub'], JSON_UNESCAPED_UNICODE), 'usage' => [], 'model' => 'stub-1', 'tool_calls' => null];
        }
    };
    $this->stub->gpId = $this->gp->id;
    app()->instance(LLMProviderContract::class, $this->stub);
    $this->abgleich = fn ($la, ToolContext $k, array $extra = []) => $this->registry->get('foodalchemist.la.GP_ABGLEICH')
        ->execute(['artikel_id' => $la->id] + $extra, $k);
    $this->verknuepft = fn ($la) => FoodAlchemistSupplierItemStructure::where('supplier_item_id', $la->id)->whereNull('deleted_at')->value('gp_id');
});

it('Vergleichstest la.GP_ABGLEICH apply: Plattformweg und MCP-Weg (zwei Runden) entscheiden und verknüpfen gleich', function () {
    $a = ($this->la)('Zwiebeln gelb 1 kg Netz');
    $b = ($this->la)('Zwiebeln gelb 5 kg Sack');

    $rp = ($this->abgleich)($a, $this->intern, ['apply' => true, 'confirm' => true]);
    expect($rp->success)->toBeTrue($rp->error ?? '')->and($rp->data['aktion'])->toBe('verknuepfen')->and($rp->data['ausgefuehrt']['gp_id'])->toBe($this->gp->id);
    $callsPlattform = $this->stub->calls;
    expect($callsPlattform)->toBe(2)->and(DB::table('foodalchemist_ki_auftraege')->count())->toBe(0);

    $antwort = $this->registry->get('foodalchemist.ki_auftraege.ANTWORT');
    $runden = 0;
    $r = ($this->abgleich)($b, $this->mcp, ['apply' => true, 'confirm' => true]);
    while (($r->data['status'] ?? null) === 'ki_auftrag_offen' && $runden++ < 5) {
        $offen = DB::table('foodalchemist_ki_auftraege')->where('status', 'offen')->get();
        expect($offen)->toHaveCount(1)->and(($this->verknuepft)($b))->toBeNull('bis zur Antwort nichts geschrieben');
        $vorher = $this->stub->calls;
        $antworten = $offen->map(fn ($o) => ['auftrag_id' => $o->uuid, 'werte' => json_decode($this->stub->chat(json_decode($o->messages, true), [])['content'], true)['werte']])->all();
        $this->stub->calls = $vorher;
        $antwort->execute(['antworten' => $antworten, 'anbieter' => 'anthropic', 'modell' => 'claude-opus-5-5'], $this->mcp);
        $r = ($this->abgleich)($b, $this->mcp, ['apply' => true, 'confirm' => true]);
    }

    expect($runden)->toBe(2, 'gp.suggest, dann gp.la_abgleich')
        ->and($r->success)->toBeTrue($r->error ?? '')->and($r->data['aktion'])->toBe('verknuepfen')->and($r->data['ausgefuehrt']['gp_id'])->toBe($this->gp->id)
        ->and(($this->verknuepft)($b))->toBe($this->gp->id)
        ->and($this->stub->calls)->toBe($callsPlattform, 'im MCP-Weg ruft der Abgleich den Plattform-Provider nie')
        ->and(DB::table('foodalchemist_ki_auftraege')->whereIn('feature', ['gp.suggest', 'gp.la_abgleich'])->where('status', 'beantwortet')->count())->toBe(0)
        ->and(app(KiAuftragService::class)->zaehler($this->rootTeam->id)['verwaist'])->toBe(0)
        ->and(app(KiAuftragService::class)->zaehler($this->rootTeam->id)['doppelt'])->toBe(0);
});

it('Sparsamkeit: ki=false legt per MCP nie einen Auftrag an', function () {
    $la = ($this->la)('Zwiebeln gelb 1 kg Netz');
    $r = ($this->abgleich)($la, $this->mcp, ['ki' => false]);

    expect($r->success)->toBeTrue($r->error ?? '')->and($r->data['status'] ?? null)->not->toBe('ki_auftrag_offen')
        ->and(DB::table('foodalchemist_ki_auftraege')->count())->toBe(0)->and($this->stub->calls)->toBe(0);
});
