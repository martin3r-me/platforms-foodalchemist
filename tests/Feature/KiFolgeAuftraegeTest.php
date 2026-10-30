<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Platform\Core\Contracts\LLMProviderContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Tools\ToolRegistry;
use Platform\FoodAlchemist\Enums\SignalTyp;
use Platform\FoodAlchemist\Jobs\GpAromaNachholenJob;
use Platform\FoodAlchemist\Models\FoodAlchemistSignal;
use Platform\FoodAlchemist\Services\Ai\FakeAiProvider;
use Platform\FoodAlchemist\Services\SignalDetektorService;
use Platform\FoodAlchemist\Support\GpAromaNachholen;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * #1002 · Folge-Aufträge dürfen nicht unbemerkt liegen bleiben: (a) das Tool-Ergebnis meldet angestoßene Folge-Jobs, (b) offene/abgelaufene
 * KI-Aufträge stehen als gebündeltes Signal im Cockpit, (c) abgelaufene werden nicht still verworfen — der Nachlauf erkennt die Lücke wieder.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->user = $this->makeUser($this->rootTeam);
    $this->actingAs($this->user);
    config(['foodalchemist.ai.provider' => 'core', 'foodalchemist.ai.backoff' => [], 'foodalchemist.ai.mcp_extern' => true]);
    $this->registry = app(ToolRegistry::class);
    $this->mcp = new ToolContext($this->user, $this->rootTeam, ['team_source' => 'user_current']);
    $this->intern = new ToolContext($this->user, $this->rootTeam);
    app()->instance(LLMProviderContract::class, new class extends FakeAiProvider
    {
        public function chat(array $messages, array $options = []): array
        {
            throw new \RuntimeException('Plattform-Provider darf nicht gerufen werden');
        }
    });
    $this->auftrag = function (string $feature, string $status, ?\DateTimeInterface $angelegt = null, ?\DateTimeInterface $ab = null) {
        $t = $angelegt ?? now();
        DB::table('foodalchemist_ki_auftraege')->insert(['uuid' => (string) \Illuminate\Support\Str::uuid(), 'team_id' => $this->rootTeam->id, 'feature' => $feature,
            'messages' => '[]', 'messages_hash' => bin2hex(random_bytes(16)), 'status' => $status, 'laeuft_ab_at' => $ab ?? now()->addDay(), 'created_at' => $t, 'updated_at' => $t]);
    };
    $this->signale = fn () => FoodAlchemistSignal::where('team_id', $this->rootTeam->id)->where('type', SignalTyp::KiAuftraegeOffen->value)->orderBy('dedup_key')->get();
});

it('(a) das Tool-Ergebnis meldet angestoßene Folge-Jobs — nur beim externen MCP-Aufruf', function () {
    Queue::fake();
    $r = $this->registry->get('foodalchemist.gps.POST')->execute(['hauptzutat' => 'Ananas'], $this->mcp);
    expect($r->success)->toBeTrue($r->error ?? '')->and($r->data['folgeauftraege']['jobs_angestossen'])->toBeGreaterThan(0)
        ->and($r->data['folgeauftraege']['hinweis'])->toContain('ki_auftraege.GET');

    $i = $this->registry->get('foodalchemist.gps.POST')->execute(['hauptzutat' => 'Birne'], $this->intern);
    expect($i->success)->toBeTrue()->and($i->data)->not->toHaveKey('folgeauftraege');

    config(['foodalchemist.ai.mcp_extern' => false]);
    $s = $this->registry->get('foodalchemist.gps.POST')->execute(['hauptzutat' => 'Mango'], $this->mcp);
    expect($s->data)->not->toHaveKey('folgeauftraege');
});

it('(b) Signal je Team und Feature gebündelt, nicht je Auftrag; frische Aufträge lösen nichts aus', function () {
    ($this->auftrag)('gp.anker', 'offen', now()->subHour());                      // zu frisch (< 2 h)
    expect(app(SignalDetektorService::class)->kiAuftraegeOffen($this->rootTeam))->toBe(0)->and(($this->signale)())->toHaveCount(0);

    foreach (range(1, 3) as $_) {
        ($this->auftrag)('gp.anker', 'offen', now()->subHours(5));
    }
    ($this->auftrag)('gp.sensorik', 'abgelaufen', now()->subDays(2), now()->subDay());
    expect(app(SignalDetektorService::class)->kiAuftraegeOffen($this->rootTeam))->toBe(2);

    $s = ($this->signale)();
    expect($s)->toHaveCount(2)
        ->and($s[0]->dedup_key)->toBe('ki-auftraege-gp.anker')->and($s[0]->payload['offen'])->toBe(3)->and($s[0]->severity->value)->toBe('info')
        ->and($s[1]->dedup_key)->toBe('ki-auftraege-gp.sensorik')->and($s[1]->payload['abgelaufen'])->toBe(1)->and($s[1]->severity->value)->toBe('warnung');

    app(SignalDetektorService::class)->kiAuftraegeOffen($this->rootTeam);          // zweiter Lauf: kein zweites Signal
    expect(($this->signale)())->toHaveCount(2);
});

it('(b) das Signal schließt sich, wenn nichts mehr unbeantwortet liegt', function () {
    ($this->auftrag)('gp.anker', 'offen', now()->subHours(5));
    app(SignalDetektorService::class)->kiAuftraegeOffen($this->rootTeam);
    expect(($this->signale)()->first()->status->value)->toBe('offen');

    DB::table('foodalchemist_ki_auftraege')->update(['status' => 'verbraucht']);
    app(SignalDetektorService::class)->kiAuftraegeOffen($this->rootTeam);
    expect(($this->signale)()->first()->status->value)->toBe('erledigt');
});

it('(c) abgelaufene Aufträge werden nicht verworfen — der Nachlauf erkennt die Lücke und legt einen neuen Auftrag an', function () {
    Queue::fake();
    $gpId = $this->registry->get('foodalchemist.gps.POST')->execute(['hauptzutat' => 'Ananas'], $this->mcp)->data['id'];
    $job = fn () => (new GpAromaNachholenJob($this->rootTeam->id, (int) $this->user->id, $gpId, true))
        ->handle(app(\Platform\FoodAlchemist\Services\BulkEnrichService::class), app(\Platform\FoodAlchemist\Services\GpAromaService::class));

    $job();
    $erste = DB::table('foodalchemist_ki_auftraege')->where('status', 'offen')->pluck('uuid')->all();
    expect($erste)->not->toBe([]);

    DB::table('foodalchemist_ki_auftraege')->update(['laeuft_ab_at' => now()->subMinute()]);                  // 24 h ohne Antwort
    app(SignalDetektorService::class)->kiAuftraegeOffen($this->rootTeam);                                      // markiert „abgelaufen“
    expect(DB::table('foodalchemist_ki_auftraege')->whereIn('uuid', $erste)->pluck('status')->unique()->all())->toBe(['abgelaufen'])
        ->and(GpAromaNachholen::braucht($gpId))->toBeTrue('die Lücke bleibt in den Daten sichtbar');

    $job();                                                                                                     // Nachlauf: neue Aufträge, alte bleiben als Spur
    expect(DB::table('foodalchemist_ki_auftraege')->where('status', 'offen')->count())->toBe(count($erste))
        ->and(DB::table('foodalchemist_ki_auftraege')->whereIn('uuid', $erste)->count())->toBe(count($erste));
});
