<?php

use Illuminate\Support\Facades\DB;
use Platform\FoodAlchemist\Enums\BulkProposalStatus;
use Platform\FoodAlchemist\Models\FoodAlchemistBulkGpProposal;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeIngredient;
use Platform\FoodAlchemist\Models\FoodAlchemistVocabEinheit;
use Platform\FoodAlchemist\Services\Ai\AiGatewayService;
use Platform\FoodAlchemist\Services\Ai\AiProposal;
use Platform\FoodAlchemist\Services\Ai\FakeAiProvider;
use Platform\FoodAlchemist\Services\BulkEnrichService;
use Platform\FoodAlchemist\Services\DataQualityService;
use Platform\FoodAlchemist\Services\PairingService;
use Platform\FoodAlchemist\Services\RecipeOneShotService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;
use Symfony\Component\Uid\UuidV7;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 50 Paket B-7/B-3/B-4/B-8 — der Kern-Anker als GP-Anreicherungs-Schritt.
 *
 * Ein GP ohne Anker ist im Pairing-Graph unsichtbar. Zu beweisen: (B-7) der Schritt `anker`
 * gehört zum Standard-Set, die KI wählt aus einer begründeten Vorauswahl (lexikalisch Wortgrenze,
 * `neutral` immer), ein Slug außerhalb ist kein Wert, die Übernahme legt ein `ai_inferred`-Mapping
 * an und respektiert Override-First; (B-3) `ankerNachziehen` fasst nur GPs ohne lebendes Mapping
 * an; (B-4) das Pairing-Glied sagt ehrlich „ohne Anker" statt still zu überspringen; (B-8) das
 * Signal `gp_anker_fehlt` zählt soft-gelöschte Mappings nicht als Anker.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam));
    config(['foodalchemist.ai.provider' => 'fake', 'foodalchemist.ai.backoff' => []]);
    $this->svc = app(BulkEnrichService::class);

    $this->ankerId = [];
    foreach (['neutral', 'karotte', 'rote-bete', 'karamell', 'rauch'] as $slug) {
        DB::table('foodalchemist_vocab_pairing_anchors')->updateOrInsert(['slug' => $slug], [
            'uuid' => (string) UuidV7::generate(), 'display_de' => ucfirst(str_replace('-', ' ', $slug)),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->ankerId[$slug] = (int) DB::table('foodalchemist_vocab_pairing_anchors')->where('slug', $slug)->value('id');
    }

    $this->gp = $this->makeGp($this->rootTeam, 'Karotte: frisch, ganz');

    $this->mapping = function (int $gpId, string $slug, string $source = 'ai_inferred', ?string $deletedAt = null): int {
        return DB::table('foodalchemist_gp_anchor_mappings')->insertGetId([
            'uuid' => (string) UuidV7::generate(), 'team_id' => $this->rootTeam->id,
            'gp_id' => $gpId, 'anchor_id' => $this->ankerId[$slug], 'role' => 'kern', 'source' => $source,
            'ai_confidence' => 0.5, 'deleted_at' => $deletedAt, 'created_at' => now(), 'updated_at' => now(),
        ]);
    };

    $this->lebend = fn (int $gpId) => DB::table('foodalchemist_gp_anchor_mappings as m')
        ->join('foodalchemist_vocab_pairing_anchors as a', 'a.id', '=', 'm.anchor_id')
        ->where('m.gp_id', $gpId)->whereNull('m.deleted_at')->orderBy('m.id')
        ->get(['a.slug', 'm.source', 'm.role', 'm.ai_confidence', 'm.ai_reasoning']);

    // Fake: der GP-Anker-Prompt ist strukturell an `vokabular` + `commodity_group` erkennbar
    // (der Rollen-Schritt am Gericht trägt `vokabular`, aber keine `commodity_group`).
    $this->fake = function (string $slug) {
        app()->singleton(FakeAiProvider::class, fn () => new class($slug) extends FakeAiProvider
        {
            public function __construct(private string $slug)
            {
            }

            public function chat(array $messages, array $options = []): array
            {
                $user = collect($messages)->last()['content'];
                $werte = match (true) {
                    str_contains($user, '"vokabular"') && str_contains($user, '"commodity_group"') => ['anchor_slug' => $this->slug],
                    str_contains($user, '"tags"') => ['tags' => ['vegan' => true]],
                    default => ['condition' => 'frisch'],
                };

                return ['content' => json_encode(['werte' => $werte, 'confidence' => 0.85, 'reasoning' => 'Fixture-Anker']), 'model' => 'fake-anker', 'usage' => []];
            }
        });
    };
});

// ── B-7: Schritt + Kandidaten ─────────────────────────────────────────────────────────

it('B-7: `anker` gehört zum Standard-Set der GP-Anreicherung; Allergene/Nährwerte bleiben explizit (kommen vom LA)', function () {
    expect(BulkEnrichService::SCHRITTE_GP)->toBe(['condition', 'tags', 'anker'])
        ->and(BulkEnrichService::SCHRITTE_GP_EXPLIZIT)->toBe(['allergene', 'naehrwerte'])
        ->and(array_intersect(BulkEnrichService::SCHRITTE_GP, BulkEnrichService::SCHRITTE_GP_EXPLIZIT))->toBe([]);
});

it('B-7: lexikalische Kandidaten treffen nur GANZE Wörter — Substring ist kein Treffer, längster Term zuerst', function () {
    $p = app(PairingService::class);

    expect($p->lexicalAnkerKandidaten('Karotte: frisch, ganz'))->toBe([$this->ankerId['karotte']])
        // „Rote Bete" hat den Zwei-Wort-Term, „rote" allein (kürzer) ist im Index gar nicht drin (< 4 Zeichen).
        ->and($p->lexicalAnkerKandidaten('Rote Bete: gekocht'))->toBe([$this->ankerId['rote-bete']])
        // „Karottenpüree" enthält „karotte" nur als Teilwort → kein Fund.
        ->and($p->lexicalAnkerKandidaten('Karottenpüree: TK'))->toBe([])
        ->and($p->lexicalAnkerKandidaten('Wasser: still'))->toBe([]);
});

it('B-7: der Vorschlag ist ein Anker-Slug aus dem Vokabular; `neutral` ist gültig', function () {
    ($this->fake)('karotte');
    $runId = $this->svc->starteGp($this->rootTeam, [$this->gp->id], ['anker']);
    $zeile = FoodAlchemistBulkGpProposal::where('run_id', $runId)->where('field', 'anker')->first();

    expect($zeile->status)->toBe(BulkProposalStatus::Offen)
        ->and($zeile->value)->toBe(['anchor_slug' => 'karotte']);

    ($this->fake)('neutral');
    $runId = $this->svc->starteGp($this->rootTeam, [$this->gp->id], ['anker']);
    $zeile = FoodAlchemistBulkGpProposal::where('run_id', $runId)->where('field', 'anker')->first();

    expect($zeile->status)->toBe(BulkProposalStatus::Offen)
        ->and($zeile->value)->toBe(['anchor_slug' => 'neutral']);
});

it('B-7: ein Slug außerhalb des Vokabulars (oder ein erfundener) wird zur LEEREN Zeile, nicht zum Wert', function () {
    // `rauch` existiert als Anker, steht aber nicht im Vokabular der Karotte (weder lexikalisch noch neutral).
    ($this->fake)('rauch');
    $runId = $this->svc->starteGp($this->rootTeam, [$this->gp->id], ['anker']);
    $zeile = FoodAlchemistBulkGpProposal::where('run_id', $runId)->where('field', 'anker')->first();
    expect($zeile->status)->toBe(BulkProposalStatus::Leer)->and($zeile->value)->toBeNull()->and($zeile->error)->toBeNull();

    ($this->fake)('holunder-erfunden');
    $runId = $this->svc->starteGp($this->rootTeam, [$this->gp->id], ['anker']);
    expect(FoodAlchemistBulkGpProposal::where('run_id', $runId)->where('field', 'anker')->value('status'))->toBe(BulkProposalStatus::Leer);
});

it('B-7: ohne lexikalischen Fund bekommt die KI das Vollvokabular (ohne Blindflug-Whitelist) — dann ist `rauch` gültig', function () {
    $gp = $this->makeGp($this->rootTeam, 'Räucherlachs: TK');   // kein Wort-Treffer, Fake-Provider ⇒ kein semantischer Recall
    ($this->fake)('rauch');
    $runId = $this->svc->starteGp($this->rootTeam, [$gp->id], ['anker']);

    expect(FoodAlchemistBulkGpProposal::where('run_id', $runId)->where('field', 'anker')->value('value'))->toBe(['anchor_slug' => 'rauch']);
});

// ── B-7: Übernahme ────────────────────────────────────────────────────────────────────

it('B-7: Übernahme legt EIN lebendes Kern-Mapping `ai_inferred` mit Konfidenz + Begründung an', function () {
    ($this->fake)('karotte');
    $runId = $this->svc->starteGp($this->rootTeam, [$this->gp->id], ['anker']);

    expect($this->svc->alleUebernehmenGp($this->rootTeam, $runId))->toBe(1);

    $m = ($this->lebend)($this->gp->id);
    expect($m)->toHaveCount(1)
        ->and($m[0]->slug)->toBe('karotte')
        ->and($m[0]->source)->toBe('ai_inferred')
        ->and($m[0]->role)->toBe('kern')
        ->and((float) $m[0]->ai_confidence)->toBe(0.85)
        ->and($m[0]->ai_reasoning)->toBe('Fixture-Anker')
        ->and(FoodAlchemistBulkGpProposal::where('run_id', $runId)->value('status'))->toBe(BulkProposalStatus::Uebernommen);
});

it('B-7: ein früheres `ai_inferred`-Mapping weicht (soft-delete), ein manuelles blockt die Übernahme komplett', function () {
    ($this->mapping)($this->gp->id, 'karamell');                          // alte KI-Inferenz
    ($this->fake)('karotte');
    $runId = $this->svc->starteGp($this->rootTeam, [$this->gp->id], ['anker']);
    expect($this->svc->alleUebernehmenGp($this->rootTeam, $runId))->toBe(1);

    $m = ($this->lebend)($this->gp->id);
    expect($m->pluck('slug')->all())->toBe(['karotte'])
        ->and(DB::table('foodalchemist_gp_anchor_mappings')->where('gp_id', $this->gp->id)->whereNotNull('deleted_at')->count())->toBe(1);

    // Override-First: manueller Anker am GP → der Vorschlag bleibt offen, nichts wird angefasst.
    $gp2 = $this->makeGp($this->rootTeam, 'Karotte: TK, Würfel');
    ($this->mapping)($gp2->id, 'karamell', 'manual');
    $runId = $this->svc->starteGp($this->rootTeam, [$gp2->id], ['anker']);
    expect($this->svc->alleUebernehmenGp($this->rootTeam, $runId))->toBe(0)
        ->and(($this->lebend)($gp2->id)->pluck('slug')->all())->toBe(['karamell'])
        ->and(FoodAlchemistBulkGpProposal::where('run_id', $runId)->value('status'))->toBe(BulkProposalStatus::Offen);
});

// ── B-3: Nachziehen nach Mint ─────────────────────────────────────────────────────────

it('B-3: `ankerNachziehen` fasst nur GPs ohne lebendes Mapping an und übernimmt sofort', function () {
    $mitAnker = $this->makeGp($this->rootTeam, 'Karotte: frisch, Julienne');
    ($this->mapping)($mitAnker->id, 'karamell');                            // hat schon einen → kein Call
    $geloescht = $this->makeGp($this->rootTeam, 'Karotte: TK, Scheibe');
    ($this->mapping)($geloescht->id, 'karamell', 'ai_inferred', now()->toDateTimeString());   // nur soft-gelöschtes → zählt als „ohne"

    ($this->fake)('karotte');
    $erg = $this->svc->ankerNachziehen($this->rootTeam, [$this->gp->id, $mitAnker->id, $geloescht->id]);

    expect($erg['geprueft'])->toBe(2)
        ->and($erg['uebernommen'])->toBe(2)
        ->and($erg['offen'])->toBe(0)
        ->and($erg['run_id'])->toBeInt()
        ->and(($this->lebend)($this->gp->id)->pluck('slug')->all())->toBe(['karotte'])
        ->and(($this->lebend)($geloescht->id)->pluck('slug')->all())->toBe(['karotte'])
        ->and(($this->lebend)($mitAnker->id)->pluck('slug')->all())->toBe(['karamell'])
        ->and(DB::table('foodalchemist_bulk_runs')->where('id', $erg['run_id'])->value('total'))->toBe(2);
});

it('B-3: nichts nachzuziehen → kein Lauf, kein Provider-Call', function () {
    ($this->mapping)($this->gp->id, 'karamell');
    $this->mock(AiGatewayService::class, fn ($m) => $m->shouldReceive('propose')->never());

    expect(app(BulkEnrichService::class)->ankerNachziehen($this->rootTeam, [$this->gp->id]))
        ->toBe(['run_id' => null, 'geprueft' => 0, 'uebernommen' => 0, 'offen' => 0]);
});

// ── B-4: ehrliches Pairing-Glied ──────────────────────────────────────────────────────

it('B-4: ohne Kern-Anker meldet das Pairing-Glied `uebersprungen_ohne_anker` mit Grund — und ruft die KI nicht', function () {
    $this->mock(AiGatewayService::class, function ($mock) {
        $mock->shouldReceive('propose')->with('recipe.pairing', \Mockery::any(), \Mockery::any())->never();
        $mock->shouldReceive('propose')->andReturnUsing(fn (string $key) => match ($key) {
            'recipe.anker' => new AiProposal(['anker_slugs' => []], 0.5, 'Mock', [], 'anker-op'),
            'recipe.steps' => new AiProposal(['steps' => [['phase' => 'Garen', 'text' => 'Kochen.']]], 0.9, 'Mock', [], 'steps-op'),
            default => new AiProposal([], 0.5, 'Mock', [], 'op'),
        });
    });

    $hg = \Platform\FoodAlchemist\Models\FoodAlchemistRecipeMainGroup::create(['team_id' => $this->rootTeam->id, 'code' => 'SUP', 'label' => 'Suppen']);
    $kat = \Platform\FoodAlchemist\Models\FoodAlchemistRecipeCategory::create(['team_id' => $this->rootTeam->id, 'main_group_id' => $hg->id, 'code' => 'GEM', 'label' => 'Gemüsesuppen']);
    $r = $this->makeRecipe($this->rootTeam, 'Karottensuppe', [
        'status' => 'draft', 'description' => 'Steht.', 'description_source' => 'ki',
        'category_id' => $kat->id, 'category_source' => 'ki', 'taste_direction' => 'herzhaft',
    ]);
    $this->makeIngredient($r, 'Karotte', $this->gp, '1000', 1);

    $erg = app(RecipeOneShotService::class)->anreichern($this->rootTeam, $r->refresh(), completeCoverage: true);

    expect($erg['coverage']['aromaanker']['status'])->toBe('leer')
        ->and($erg['coverage']['pairings']['status'])->toBe('uebersprungen_ohne_anker')
        ->and($erg['coverage']['pairings']['n_pairings'])->toBe(0)
        ->and($erg['coverage']['pairings']['grund'])->toContain('gps.ENRICH');
});

// ── B-8: Signal-Baseline ──────────────────────────────────────────────────────────────

it('B-8: `gp_anker_fehlt` zählt ein GP mit NUR soft-gelöschtem Mapping als ohne Anker, ein lebendes nicht', function () {
    $this->gp->update(['status' => 'approved']);
    $basis = FoodAlchemistRecipe::create([
        'team_id' => $this->rootTeam->id, 'recipe_key' => 'basis-anker', 'name' => 'Basis Anker',
        'status' => 'approved', 'is_sales_recipe' => false,
    ]);
    $g = FoodAlchemistVocabEinheit::create([
        'team_id' => $this->rootTeam->id, 'slug' => 'g', 'display_de' => 'Gramm', 'dimension' => 'mass', 'default_in_g' => 1,
    ]);
    FoodAlchemistRecipeIngredient::create([
        'team_id' => $this->rootTeam->id, 'recipe_id' => $basis->id, 'gp_id' => $this->gp->id,
        'raw_text' => 'Karotte', 'quantity' => '100', 'unit_vocab_id' => $g->id, 'position' => 1,
    ]);
    $wert = function (): int {
        foreach (app(DataQualityService::class)->messeAlleEbenen($this->rootTeam) as $ebene) {
            foreach ($ebene['metriken'] as $m) {
                if ($m['key'] === 'gp_anker_fehlt') {
                    return (int) $m['wert'];
                }
            }
        }
        throw new RuntimeException('Metrik fehlt');
    };

    ($this->mapping)($this->gp->id, 'karamell', 'ai_inferred', now()->toDateTimeString());
    expect($wert())->toBe(1);

    ($this->mapping)($this->gp->id, 'karotte');
    expect($wert())->toBe(0);
});

// ── Nebenbefund 2026-09-06: SignalFix `gp_anker` etikettiert ehrlich ────────────────────

it('SignalFix gp_anker: der lexikalische Fund wird als `ai_inferred` gespeichert, nicht als `manual`', function () {
    $svc = app(\Platform\FoodAlchemist\Services\SignalFixService::class);
    $m = new ReflectionMethod($svc, 'fixGpAnker');
    $m->setAccessible(true);

    expect($m->invoke($svc, $this->rootTeam, (int) $this->gp->id))->toBeTrue();

    $lebend = ($this->lebend)((int) $this->gp->id);
    expect($lebend)->toHaveCount(1)
        ->and($lebend[0]->slug)->toBe('karotte')
        ->and($lebend[0]->source)->toBe('ai_inferred')            // Etikett lügt nicht: Namens-Match ≠ Handarbeit
        ->and((float) $lebend[0]->ai_confidence)->toBe(0.7)
        ->and($lebend[0]->ai_reasoning)->toContain('lexikalisch');
});

it('SignalFix gp_anker: ein vorhandenes manuelles Mapping bleibt stehen (Inv. 3)', function () {
    ($this->mapping)((int) $this->gp->id, 'rote-bete', 'manual');
    $svc = app(\Platform\FoodAlchemist\Services\SignalFixService::class);
    $m = new ReflectionMethod($svc, 'fixGpAnker');
    $m->setAccessible(true);
    $m->invoke($svc, $this->rootTeam, (int) $this->gp->id);

    $lebend = ($this->lebend)((int) $this->gp->id);
    expect($lebend->pluck('slug')->sort()->values()->all())->toBe(['karotte', 'rote-bete'])
        ->and($lebend->firstWhere('slug', 'rote-bete')->source)->toBe('manual');
});
