<?php

use Illuminate\Support\Facades\DB;
use Platform\Core\Contracts\LLMProviderContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Tools\ToolRegistry;
use Platform\FoodAlchemist\Enums\SignalSeverity;
use Platform\FoodAlchemist\Enums\SignalTyp;
use Platform\FoodAlchemist\Models\FoodAlchemistSignal;
use Platform\FoodAlchemist\Services\Ai\FakeAiProvider;
use Platform\FoodAlchemist\Services\SignalService;
use Platform\FoodAlchemist\Support\SignalCockpit;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * #1002 · Paket Signale: `signale.FIX` mit Assist-Plan läuft per MCP gleichwertig — ein propose() je Signal-Typ (signal.* / price.plausi),
 * Entwurf ohne Schreiben und ohne Schließen. Deterministische Pläne und Absagen rufen keine KI und bleiben unverändert.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->user = $this->makeUser($this->rootTeam);
    $this->actingAs($this->user);
    config(['foodalchemist.ai.provider' => 'core', 'foodalchemist.ai.backoff' => [], 'foodalchemist.ai.mcp_extern' => true]);
    $this->registry = app(ToolRegistry::class);
    $this->mcp = new ToolContext($this->user, $this->rootTeam, ['team_source' => 'user_current']);
    $this->intern = new ToolContext($this->user, $this->rootTeam);
    $this->stub = new class extends FakeAiProvider
    {
        public int $calls = 0;

        public function chat(array $messages, array $options = []): array
        {
            $this->calls++;

            return ['content' => json_encode(['werte' => ['text' => 'Entwurf: bitte Konditionen prüfen.'], 'confidence' => 0.8, 'reasoning' => 'stub'], JSON_UNESCAPED_UNICODE),
                'usage' => [], 'model' => 'stub-1', 'tool_calls' => null];
        }
    };
    app()->instance(LLMProviderContract::class, $this->stub);
    $this->signal = fn (SignalTyp $t) => app(SignalService::class)->erzeuge($this->rootTeam, $t, SignalSeverity::Warnung, 'Test ' . $t->value, ['dedup_key' => 'x-' . $t->value]);
    $this->fix = fn ($sig, ToolContext $k, array $extra = []) => $this->registry->get('foodalchemist.signale.FIX')->execute(['signal_id' => $sig->id] + $extra, $k);
});

dataset('assist_signale', function () {
    return [
        'preis_sprung_marge_impact' => [SignalTyp::PreisSprungMargeImpact, 'signal.supplier_inquiry'],
        'marge_unter_ziel' => [SignalTyp::MargeUnterZiel, 'signal.margin_levers'],
        'wareneinsatz_ueber_ziel' => [SignalTyp::WareneinsatzUeberZiel, 'signal.margin_levers'],
        'preis_anomalie' => [SignalTyp::PreisAnomalie, 'price.plausi'],
        'vk_anpassung_empfohlen' => [SignalTyp::VkAnpassungEmpfohlen, 'signal.vk_release_advice'],
        'servierform_unbestimmt' => [SignalTyp::ServierformUnbestimmt, 'signal.serving_form_suggest'],
        'rezept_kategorie_problem' => [SignalTyp::RezeptKategorieProblem, 'signal.recipe_category_suggest'],
        'rezept_naming_regelwerk' => [SignalTyp::RezeptNamingRegelwerk, 'signal.recipe_naming_suggest'],
    ];
});

it('der Test deckt alle Assist-Signaltypen ab', function () {
    $assist = [];
    foreach (SignalTyp::cases() as $t) {
        if ((SignalCockpit::planFor(new FoodAlchemistSignal(['type' => $t->value]))['kind'] ?? null) === 'assist') {
            $assist[] = $t->value;
        }
    }
    $abgedeckt = array_map(fn ($z) => $z[0]->value, array_values((fn () => [
        [SignalTyp::PreisSprungMargeImpact], [SignalTyp::MargeUnterZiel], [SignalTyp::WareneinsatzUeberZiel], [SignalTyp::PreisAnomalie],
        [SignalTyp::VkAnpassungEmpfohlen], [SignalTyp::ServierformUnbestimmt], [SignalTyp::RezeptKategorieProblem], [SignalTyp::RezeptNamingRegelwerk],
    ])()));
    expect($abgedeckt)->toEqualCanonicalizing($assist);
});

it('Vergleichstest signale.FIX assist: Plattformweg und MCP-Weg liefern denselben Entwurf, schreiben nichts und schließen nicht', function (SignalTyp $typ, string $prompt) {
    $sig = ($this->signal)($typ);
    expect(SignalCockpit::planFor($sig)['prompt'] ?? null)->toBe($prompt);

    $rp = ($this->fix)($sig, $this->intern);
    expect($rp->success)->toBeTrue($rp->error ?? '')->and($rp->data['kind'])->toBe('assist')->and($this->stub->calls)->toBe(1)
        ->and(DB::table('foodalchemist_ki_auftraege')->count())->toBe(0);

    $r1 = ($this->fix)($sig, $this->mcp);
    expect($r1->success)->toBeTrue($r1->error ?? '')->and($r1->data['status'])->toBe('ki_auftrag_offen')
        ->and($r1->data['auftraege'][0]['feature'])->toBe($prompt)
        ->and($this->stub->calls)->toBe(1);                                           // der Provider wurde per MCP nicht gerufen

    $this->registry->get('foodalchemist.ki_auftraege.ANTWORT')->execute(['anbieter' => 'anthropic', 'modell' => 'claude-opus-5-5', 'antworten' => [
        ['auftrag_id' => $r1->data['auftraege'][0]['auftrag_id'], 'werte' => ['werte' => ['text' => 'Entwurf: bitte Konditionen prüfen.'], 'confidence' => 0.8, 'reasoning' => 'stub']],
    ]], $this->mcp);

    $rm = ($this->fix)($sig, $this->mcp);
    expect($rm->success)->toBeTrue($rm->error ?? '')
        ->and($rm->data['kind'])->toBe($rp->data['kind'])
        ->and($rm->data['draft'])->toBe($rp->data['draft'])
        ->and($rm->data['confidence'])->toBe($rp->data['confidence'])
        ->and($rm->data['signal_closed'])->toBeFalse();
    expect($sig->fresh()->status->value)->toBe('offen')                               // kein Schließen, kein Schreiben am Signal
        ->and($this->stub->calls)->toBe(1);
    $log = DB::table('foodalchemist_ai_call_log')->whereNull('error')->orderByDesc('id')->first();
    expect(json_decode($log->prompt_parts, true)['quelle'])->toBe('mcp');
})->with('assist_signale');

it('Weg-Absage und Signal ohne Plan rufen keine KI und bleiben unverändert (kein Auftrag)', function () {
    foreach ([SignalTyp::KonformitaetLa, SignalTyp::KiAuftraegeOffen] as $typ) {
        $sig = ($this->signal)($typ);
        $r = ($this->fix)($sig, $this->mcp);
        expect($r->success)->toBeFalse()->and($r->errorCode)->toBe('ACTION_NOT_AVAILABLE');
    }
    expect(DB::table('foodalchemist_ki_auftraege')->count())->toBe(0)->and($this->stub->calls)->toBe(0);
});

it('Schalter aus: signale.FIX assist läuft über den Plattform-Provider, kein Auftrag', function () {
    config(['foodalchemist.ai.mcp_extern' => false]);
    $r = ($this->fix)(($this->signal)(SignalTyp::MargeUnterZiel), $this->mcp);

    expect($r->success)->toBeTrue($r->error ?? '')->and($r->data['kind'])->toBe('assist')->and($this->stub->calls)->toBe(1)
        ->and(DB::table('foodalchemist_ki_auftraege')->count())->toBe(0);
});
