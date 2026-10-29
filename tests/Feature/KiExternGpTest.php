<?php

use Illuminate\Support\Facades\DB;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Tools\ToolRegistry;
use Platform\FoodAlchemist\Enums\BulkProposalStatus;
use Platform\FoodAlchemist\Models\FoodAlchemistBulkGpProposal;
use Platform\FoodAlchemist\Services\Ai\FakeAiProvider;
use Platform\FoodAlchemist\Services\Ai\KiAuftragService;
use Platform\FoodAlchemist\Services\Ai\KiExternModus;
use Platform\FoodAlchemist\Services\BulkEnrichService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;
use Symfony\Component\Uid\UuidV7;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * #1002 Phase 1 · gp.anker über den externen Weg: Fingerabdruck-Stabilität, Zähler (angelegt/verbraucht/verwaist/doppelt),
 * Vergleichstest Plattformweg = MCP-Weg (gleicher Prompt, gleiches Ergebnis, Lineage quelle=mcp) und das Extern-Flag am Lauf (gps.ENRICH).
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->user = $this->makeUser($this->rootTeam);
    $this->actingAs($this->user);
    config(['foodalchemist.ai.provider' => 'fake', 'foodalchemist.ai.backoff' => [], 'foodalchemist.ai.mcp_extern' => true]);
    $this->svc = app(BulkEnrichService::class);
    $this->ki = app(KiAuftragService::class);

    // Reihenfolge der Anlage ≠ Reihenfolge der Slugs (rote-bete hat die kleinere ID): fängt ein unsortiertes whereIn
    $this->ankerId = [];
    foreach (['neutral', 'rote-bete', 'karotte', 'rauch'] as $slug) {
        DB::table('foodalchemist_vocab_pairing_anchors')->updateOrInsert(['slug' => $slug], [
            'uuid' => (string) UuidV7::generate(), 'display_de' => ucfirst(str_replace('-', ' ', $slug)),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->ankerId[$slug] = (int) DB::table('foodalchemist_vocab_pairing_anchors')->where('slug', $slug)->value('id');
    }
    $this->gp = $this->makeGp($this->rootTeam, 'Karotte Rote Bete: frisch');

    $this->spy = new class extends FakeAiProvider
    {
        public int $ankerCalls = 0;

        public ?array $ankerMessages = null;

        public function chat(array $messages, array $options = []): array
        {
            $user = (string) collect($messages)->last()['content'];
            if (str_contains($user, '"vokabular"') && str_contains($user, '"commodity_group"')) {
                $this->ankerCalls++;
                $this->ankerMessages = $messages;

                return ['content' => json_encode(['werte' => ['anchor_slug' => 'karotte'], 'confidence' => 0.85, 'reasoning' => 'Fixture-Anker']), 'model' => 'fake-anker', 'usage' => []];
            }

            return parent::chat($messages, $options);
        }
    };
    app()->singleton(FakeAiProvider::class, fn () => $this->spy);

    $this->extern = fn (callable $f) => app(KiExternModus::class)->within($f);
    $this->nachziehen = fn () => $this->svc->nachziehenGp($this->rootTeam, [$this->gp->id], ['anker'], 'test');
    $this->antworte = function (string $uuid) {
        $this->ki->beantworte($this->rootTeam->id, $uuid, ['werte' => ['anchor_slug' => 'karotte'], 'confidence' => 0.85, 'reasoning' => 'Fixture-Anker'], 'anthropic', 'claude-opus-5-5');
    };
    $this->lebend = fn () => DB::table('foodalchemist_gp_anchor_mappings as m')
        ->join('foodalchemist_vocab_pairing_anchors as a', 'a.id', '=', 'm.anchor_id')
        ->where('m.gp_id', $this->gp->id)->whereNull('m.deleted_at')->orderBy('m.id')
        ->get(['a.slug', 'm.source', 'm.role', 'm.ai_confidence', 'm.ai_reasoning'])->map(fn ($r) => (array) $r)->all();
});

it('Fingerabdruck: das Anker-Vokabular im Auftrag ist sortiert (unabhängig von der Anlage-Reihenfolge) und der Lauf legt genau einen Auftrag an', function () {
    $r = ($this->extern)($this->nachziehen);
    expect($r['ausstehend'])->toBe(1);

    $auftrag = DB::table('foodalchemist_ki_auftraege')->first();
    $user = (string) collect(json_decode($auftrag->messages, true))->last()['content'];
    preg_match('/"vokabular":(\[[^\]]*\])/', $user, $m);
    $vokabular = json_decode($m[1] ?? '[]', true);

    expect($vokabular)->toBe(['karotte', 'rote-bete', 'neutral'], 'slug-sortiert, neutral zuletzt');
});

it('Fingerabdruck: derselbe Lauf wiederholt, nach irrelevanten Änderungen, ergibt keinen zweiten Auftrag', function () {
    ($this->extern)($this->nachziehen);
    expect(DB::table('foodalchemist_ki_auftraege')->count())->toBe(1);

    // irrelevant: GP angefasst, fremdes GP angelegt, fremder Anker NACH dem ersten Lauf angelegt
    DB::table('foodalchemist_gps')->where('id', $this->gp->id)->update(['updated_at' => now()->addMinute()]);
    $this->makeGp($this->rootTeam, 'Zwiebel: frisch');
    DB::table('foodalchemist_vocab_pairing_anchors')->insert(['slug' => 'zwiebel', 'uuid' => (string) UuidV7::generate(), 'display_de' => 'Zwiebel', 'created_at' => now(), 'updated_at' => now()]);

    ($this->extern)($this->nachziehen);
    ($this->extern)($this->nachziehen);

    expect(DB::table('foodalchemist_ki_auftraege')->count())->toBe(1);
    expect($this->ki->zaehler($this->rootTeam->id)['doppelt'])->toBe(0);
});

it('Ein Auftrag im Wartezustand erzeugt keinen Leer-Vorschlag mit Fehlertext im Lauf', function () {
    $r = ($this->extern)($this->nachziehen);

    expect($r['ausstehend'])->toBe(1)
        ->and(FoodAlchemistBulkGpProposal::where('run_id', $r['run_id'])->count())->toBe(0)
        ->and($r['uebernommen'])->toBe(0);
});

it('Zähler: angelegt/beantwortet/verbraucht über den ganzen Weg, nichts doppelt, nichts verwaist', function () {
    $seit = now()->subSecond();
    ($this->extern)($this->nachziehen);
    expect($this->ki->zaehler($this->rootTeam->id, $seit))->toMatchArray(['angelegt' => 1, 'beantwortet' => 0, 'verbraucht' => 0, 'offen' => 1, 'verwaist' => 0, 'doppelt' => 0]);

    ($this->antworte)(DB::table('foodalchemist_ki_auftraege')->value('uuid'));
    expect($this->ki->zaehler($this->rootTeam->id, $seit))->toMatchArray(['beantwortet' => 1, 'verbraucht' => 0, 'liegt_beantwortet' => 1, 'offen' => 0]);

    $r = ($this->extern)($this->nachziehen);
    expect($r['uebernommen'])->toBe(1)
        ->and($this->ki->zaehler($this->rootTeam->id, $seit))->toMatchArray(['angelegt' => 1, 'beantwortet' => 1, 'verbraucht' => 1, 'liegt_beantwortet' => 0, 'verwaist' => 0, 'doppelt' => 0]);
});

it('Zähler: doppelte Aufträge je Ziel und verwaiste (abgelaufen / lange unverbraucht) werden sichtbar', function () {
    ($this->extern)($this->nachziehen);
    $a = (array) DB::table('foodalchemist_ki_auftraege')->first();
    unset($a['id']);
    DB::table('foodalchemist_ki_auftraege')->insert([...$a, 'uuid' => (string) UuidV7::generate(), 'messages_hash' => 'anderer-hash']);   // gleiches Ziel, anderer Fingerabdruck
    DB::table('foodalchemist_ki_auftraege')->insert([...$a, 'uuid' => (string) UuidV7::generate(), 'messages_hash' => 'x2', 'target_id' => 987654, 'status' => 'beantwortet', 'beantwortet_at' => now()->subHours(3)]);
    DB::table('foodalchemist_ki_auftraege')->insert([...$a, 'uuid' => (string) UuidV7::generate(), 'messages_hash' => 'x3', 'target_id' => 987655, 'status' => 'abgelaufen']);

    $z = $this->ki->zaehler($this->rootTeam->id);
    expect($z['doppelt'])->toBe(1)->and($z['verwaist'])->toBe(2)->and($z['abgelaufen'])->toBe(1);
});

it('Vergleichstest gp.anker: Plattformweg und MCP-Weg — gleicher Prompt, gleicher Anker, Lineage quelle=mcp', function () {
    // Plattformweg
    $r1 = ($this->nachziehen)();
    $plattform = ($this->lebend)();
    $promptPlattform = json_encode($this->spy->ankerMessages);
    expect($r1['uebernommen'])->toBe(1)->and($plattform)->toHaveCount(1)->and($plattform[0]['slug'])->toBe('karotte');
    $logPlattform = DB::table('foodalchemist_ai_call_log')->whereNull('error')->where('feature', 'gp.anker')->first();
    expect(json_decode($logPlattform->prompt_parts, true)['quelle'] ?? null)->toBeNull();

    // Zurück auf „ohne Anker"
    DB::table('foodalchemist_gp_anchor_mappings')->where('gp_id', $this->gp->id)->delete();
    FoodAlchemistBulkGpProposal::query()->delete();
    $callsVorher = $this->spy->ankerCalls;

    // MCP-Weg: Auftrag → Antwort → Wiederholung
    $w = ($this->extern)($this->nachziehen);
    expect($w['ausstehend'])->toBe(1)->and($this->spy->ankerCalls)->toBe($callsVorher, 'Provider darf im Extern-Weg nicht gerufen werden');
    $auftrag = DB::table('foodalchemist_ki_auftraege')->first();
    expect(json_encode(json_decode($auftrag->messages, true)))->toBe($promptPlattform, 'identischer Prompt');

    ($this->antworte)($auftrag->uuid);
    $w2 = ($this->extern)($this->nachziehen);
    $mcp = ($this->lebend)();

    expect($w2['uebernommen'])->toBe(1)
        ->and($mcp)->toBe($plattform)
        ->and($this->spy->ankerCalls)->toBe($callsVorher);
    $logMcp = DB::table('foodalchemist_ai_call_log')->whereNull('error')->where('feature', 'gp.anker')->orderByDesc('id')->first();
    expect(json_decode($logMcp->prompt_parts, true)['quelle'])->toBe('mcp')
        ->and($logMcp->model)->toBe('anthropic/claude-opus-5-5');
});

it('Extern-Flag: gps.ENRICH per MCP legt im Worker KI-Aufträge an, nach ANTWORT übernimmt der wiederholte Aufruf', function () {
    $registry = app(ToolRegistry::class);
    $mcp = new ToolContext($this->user, $this->rootTeam, ['team_source' => 'user_current']);
    $aufruf = fn () => $registry->get('foodalchemist.gps.ENRICH')->execute(['gp_id' => $this->gp->id, 'felder' => ['anker']], $mcp);

    $r = $aufruf();
    expect($r->success)->toBeTrue($r->error ?? '')->and($r->data['extern'] ?? false)->toBeTrue();
    expect($this->spy->ankerCalls)->toBe(0)
        ->and(DB::table('foodalchemist_ki_auftraege')->count())->toBe(1)
        ->and(DB::table('foodalchemist_bulk_runs')->where('id', $r->data['run_id'])->value('context'))->toContain('"extern":true');
    expect(FoodAlchemistBulkGpProposal::where('run_id', $r->data['run_id'])->count())->toBe(0, 'kein Leer-Vorschlag');

    ($this->antworte)(DB::table('foodalchemist_ki_auftraege')->value('uuid'));
    $r2 = $aufruf();

    $p = FoodAlchemistBulkGpProposal::where('run_id', $r2->data['run_id'])->where('field', 'anker')->first();
    expect($p->status)->toBe(BulkProposalStatus::Offen)->and($p->value)->toBe(['anchor_slug' => 'karotte'])
        ->and($this->spy->ankerCalls)->toBe(0)
        ->and(DB::table('foodalchemist_ki_auftraege')->value('status'))->toBe('verbraucht');
});

it('Extern-Flag: gps.ENRICH ohne MCP-Herkunft (interner Weg) bleibt beim Plattform-Provider', function () {
    $intern = new ToolContext($this->user, $this->rootTeam);
    $r = app(ToolRegistry::class)->get('foodalchemist.gps.ENRICH')->execute(['gp_id' => $this->gp->id, 'felder' => ['anker']], $intern);

    expect($r->success)->toBeTrue()->and($r->data['extern'] ?? false)->toBeFalse()
        ->and($this->spy->ankerCalls)->toBeGreaterThan(0)
        ->and(DB::table('foodalchemist_ki_auftraege')->count())->toBe(0);
});
