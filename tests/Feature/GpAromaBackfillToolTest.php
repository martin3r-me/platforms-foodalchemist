<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Models\Team;
use Platform\Core\Tools\ToolRegistry;
use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Services\GpAromaBackfillService;
use Platform\FoodAlchemist\Services\SensorikService;
use Platform\FoodAlchemist\Support\MasterTeam;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * #1002: MCP-Tool foodalchemist.gp_aroma_backfill.RUN — dieselbe Logik wie `foodalchemist:gp-aroma-backfill` (gemeinsamer Service). Per MCP läuft die
 * KI extern (Aufträge), jedes Team bearbeitet nur seine pflegbaren GPs, das Master-Team zusätzlich die globalen Stamm-GPs.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->master = Team::create(['name' => 'BHG.DIGITAL', 'user_id' => 1, 'personal_team' => false]);
    config(['foodalchemist.master_team_id' => $this->master->id, 'foodalchemist.ai.provider' => 'fake', 'foodalchemist.ai.backoff' => [], 'foodalchemist.ai.mcp_extern' => true,
        'foodalchemist.bearbeitungssperre' => false]);
    MasterTeam::flush();
    $this->registry = app(ToolRegistry::class);
    $this->mandantUser = $this->makeUser($this->rootTeam, 'Mandant Admin');
    $this->masterUser = $this->makeUser($this->master, 'Master Admin');
    $this->leserUser = $this->makeUser($this->rootTeam, 'Leser', 'viewer');
    $this->mcp = fn ($user, Team $team) => new ToolContext($user, $team, ['team_source' => 'user_current']);
    $this->run = fn ($user, Team $team, array $args = []) => $this->registry->get('foodalchemist.gp_aroma_backfill.RUN')->execute($args, ($this->mcp)($user, $team));

    $this->anker = [];
    foreach (['pineapple', 'lime'] as $slug) {
        $this->anker[$slug] = DB::table('foodalchemist_vocab_pairing_anchors')->insertGetId(['uuid' => (string) Str::uuid7(), 'slug' => $slug,
            'display_de' => ucfirst($slug), 'created_at' => now(), 'updated_at' => now()]);
    }
    $this->gp = fn (?int $teamId, string $name, string $slug, array $extra = []) => FoodAlchemistGp::create(['team_id' => $teamId,
        'gp_key' => Str::slug($name).'-'.Str::random(4), 'name' => $name, 'main_ingredient_slug' => $slug, 'status' => 'approved'] + $extra);
    // Mandant: zwei GPs ohne Geschwister (Lücke bei Anker und Profil), eines mit manuellem Profil
    $this->quitte = ($this->gp)($this->rootTeam->id, 'Quitte: frisch', 'quitte');
    $this->kuerbis = ($this->gp)($this->rootTeam->id, 'Kürbis: frisch', 'kuerbis');
    $this->manuell = ($this->gp)($this->rootTeam->id, 'Kardamom: gemahlen', 'kardamom');
    DB::table('foodalchemist_gp_taste_vectors')->updateOrInsert(['gp_id' => $this->manuell->id], array_merge(array_fill_keys(SensorikService::DIMS, 0), ['suess' => 0.4, 'source' => 'manual',
        'created_at' => now(), 'updated_at' => now()]));
    // manuelles Profil mit lauter Nullen: ein bewusst gesetztes „neutral“ — gilt NIE als Lücke
    $this->manuellNull = ($this->gp)($this->rootTeam->id, 'Safran: Fäden', 'safran');
    DB::table('foodalchemist_gp_taste_vectors')->updateOrInsert(['gp_id' => $this->manuellNull->id], array_merge(array_fill_keys(SensorikService::DIMS, 0), ['source' => 'manual',
        'created_at' => now(), 'updated_at' => now()]));
    // Fremdes Team (Standort) und globales Stamm-GP
    $this->fremd = ($this->gp)($this->childA->id, 'Fremd: frisch', 'fremd');
    $this->global = ($this->gp)(null, 'Zitrone: frisch', 'zitrone', ['stamm_class' => 'stamm_paket']);
    $this->ankerZeilen = fn (int $gpId) => DB::table('foodalchemist_gp_anchor_mappings')->where('gp_id', $gpId)->whereNull('deleted_at')->count();
    $this->antworten = function (ToolContext $k, string $slug = 'pineapple') {
        $offen = DB::table('foodalchemist_ki_auftraege')->where('status', 'offen')->get();
        $liste = $offen->map(fn ($a) => ['auftrag_id' => $a->uuid, 'werte' => ['werte' => ['anchor_slug' => $slug], 'confidence' => 0.8, 'reasoning' => 'Test']])->all();

        return $this->registry->get('foodalchemist.ki_auftraege.ANTWORT')->execute(['antworten' => $liste, 'anbieter' => 'anthropic', 'modell' => 'claude-opus-5-5'], $k);
    };
});

it('Trockenlauf schreibt nichts: Zählung nur der pflegbaren GPs des Teams (nicht Fremde, nicht Globale), manuelles Profil nicht in der Lücke', function () {
    $r = ($this->run)($this->mandantUser, $this->rootTeam, ['nur' => 'beide']);

    expect($r->success)->toBeTrue($r->error ?? '')
        ->and($r->data['apply'])->toBeFalse()
        ->and($r->data['luecken'])->toBe(['anker' => 4, 'profil' => 2])   // Anker: Quitte, Kürbis, Kardamom, Safran · Profil: Quitte, Kürbis (Kardamom und Safran haben ein manuelles Profil, auch das mit lauter Nullen)
        ->and($r->data['ki_teil'])->toBeNull()
        ->and($r->data['hinweis'])->toContain('Trockenlauf')
        ->and(DB::table('foodalchemist_ki_auftraege')->count())->toBe(0)
        ->and(($this->ankerZeilen)($this->quitte->id))->toBe(0);
});

it('apply nur_geschwister: füllt aus einigen Geschwistern, ohne KI und ohne Aufträge', function () {
    $neu = ($this->gp)($this->rootTeam->id, 'Apfel: TK', 'apfel');                      // zuerst ohne Geschwister …
    foreach (['Apfel: frisch', 'Apfel: Würfel'] as $n) {                                  // … dann zwei Geschwister mit gleichem Anker
        $g = ($this->gp)($this->rootTeam->id, $n, 'apfel');
        DB::table('foodalchemist_gp_anchor_mappings')->where('gp_id', $g->id)->delete();   // die Anlage hat schon vom ersten Geschwister übernommen — Fixture setzt den Stand fest
        DB::table('foodalchemist_gp_anchor_mappings')->insert(['uuid' => (string) Str::uuid7(), 'team_id' => $this->rootTeam->id, 'gp_id' => $g->id,
            'anchor_id' => $this->anker['pineapple'], 'role' => 'kern', 'source' => 'manual', 'created_at' => now(), 'updated_at' => now()]);
    }

    $r = ($this->run)($this->mandantUser, $this->rootTeam, ['nur' => 'anker', 'apply' => true, 'nur_geschwister' => true]);

    expect($r->success)->toBeTrue($r->error ?? '')
        ->and($r->data['geschwister_uebernommen']['anker'])->toBe(1)
        ->and(($this->ankerZeilen)($neu->id))->toBe(1)
        ->and(DB::table('foodalchemist_gp_anchor_mappings')->where('gp_id', $neu->id)->value('source'))->toBe('geschwister')
        ->and(DB::table('foodalchemist_ki_auftraege')->count())->toBe(0);
});

it('apply (MCP): legt KI-Aufträge nur für eigene GPs an, nach ANTWORT übernimmt der zweite Aufruf — globale und fremde GPs bleiben unberührt', function () {
    $k = ($this->mcp)($this->mandantUser, $this->rootTeam);
    $r1 = ($this->run)($this->mandantUser, $this->rootTeam, ['nur' => 'anker', 'apply' => true, 'limit' => 10]);

    expect($r1->success)->toBeTrue($r1->error ?? '')->and($r1->data['extern'])->toBeTrue()
        ->and($r1->data['ki_teil']['schritte_wartend'])->toBe(4)             // Quitte, Kürbis, Kardamom, Safran (Anker) — manuelles Profil egal, der Anker fehlt
        ->and($r1->data['hinweis'])->toContain('ki_auftraege.GET');
    $ziele = DB::table('foodalchemist_ki_auftraege')->where('status', 'offen')->pluck('target_id')->map(fn ($v) => (int) $v)->sort()->values()->all();
    expect($ziele)->toBe(collect([$this->quitte->id, $this->kuerbis->id, $this->manuell->id, $this->manuellNull->id])->sort()->values()->all());

    ($this->antworten)($k);
    $r2 = ($this->run)($this->mandantUser, $this->rootTeam, ['nur' => 'anker', 'apply' => true, 'limit' => 10]);

    expect($r2->data['ki_teil']['uebernommen'])->toBe(4)
        ->and(($this->ankerZeilen)($this->quitte->id))->toBe(1)->and(($this->ankerZeilen)($this->kuerbis->id))->toBe(1)
        ->and(($this->ankerZeilen)($this->global->id))->toBe(0, 'das globale Stamm-GP bleibt unberührt')
        ->and(($this->ankerZeilen)($this->fremd->id))->toBe(0, 'das GP des anderen Teams bleibt unberührt')
        ->and($r2->data['ki_teil']['zaehler']['doppelt'])->toBe(0);
});

it('Master-Team erreicht zusätzlich die globalen Stamm-GPs', function () {
    $r = ($this->run)($this->masterUser, $this->master, ['nur' => 'anker', 'apply' => true, 'limit' => 10]);

    expect($r->success)->toBeTrue($r->error ?? '')
        ->and($r->data['luecken']['anker'])->toBe(1, 'nur das globale Stamm-GP — die GPs des Mandanten gehören nicht zum Master-Team')
        ->and(DB::table('foodalchemist_ki_auftraege')->where('status', 'offen')->pluck('target_id')->map(fn ($v) => (int) $v)->all())->toBe([$this->global->id]);
});

it('Mandant ohne eigene pflegbare GPs: klare Antwort, kein Fehler', function () {
    $leer = Team::create(['name' => 'Leerer Mandant', 'user_id' => 1, 'personal_team' => false]);
    $u = $this->makeUser($leer, 'Leer Admin');

    $r = ($this->run)($u, $leer, ['apply' => true]);

    expect($r->success)->toBeTrue($r->error ?? '')->and($r->data['hinweis'])->toContain('Keine pflegbaren GPs')
        ->and($r->data['pflegbare_gps_mit_luecke'])->toBe(0)
        ->and(DB::table('foodalchemist_ki_auftraege')->count())->toBe(0);
});

it('Rolle: wer keine GPs bearbeiten darf (Lesen), wird abgelehnt', function () {
    $r = ($this->run)($this->leserUser, $this->rootTeam, ['apply' => true]);

    expect($r->success)->toBeFalse()->and($r->errorCode)->toBe('FORBIDDEN')
        ->and(DB::table('foodalchemist_ki_auftraege')->count())->toBe(0);
});

it('Eingaben: limit außerhalb 1..300 und neu_schaetzen=manual werden abgelehnt', function () {
    expect(($this->run)($this->mandantUser, $this->rootTeam, ['limit' => 301])->errorCode)->toBe('VALIDATION_ERROR')
        ->and(($this->run)($this->mandantUser, $this->rootTeam, ['limit' => 0])->errorCode)->toBe('VALIDATION_ERROR')
        ->and(($this->run)($this->mandantUser, $this->rootTeam, ['neu_schaetzen' => ['manual']])->errorCode)->toBe('VALIDATION_ERROR')
        ->and(($this->run)($this->mandantUser, $this->rootTeam, ['nur' => 'quatsch'])->errorCode)->toBe('VALIDATION_ERROR');
});

it('manuelles Profil wird nie angefasst: auch nicht mit neu_schaetzen über alle erlaubten Quellen', function () {
    $r = ($this->run)($this->mandantUser, $this->rootTeam, ['nur' => 'profil', 'neu_schaetzen' => ['ai', 'geschwister', 'gemini', 'rule'], 'apply' => true, 'limit' => 10]);

    expect($r->success)->toBeTrue($r->error ?? '');
    $vorher = DB::table('foodalchemist_gp_taste_vectors')->where('gp_id', $this->manuell->id)->first();
    expect($vorher->source)->toBe('manual')->and((float) $vorher->suess)->toBe(0.4)
        ->and(DB::table('foodalchemist_gp_taste_vectors')->where('gp_id', $this->manuellNull->id)->value('source'))->toBe('manual')
        ->and(DB::table('foodalchemist_ki_auftraege')->whereIn('target_id', [$this->manuell->id, $this->manuellNull->id])->where('feature', 'gp.sensorik')->count())->toBe(0, 'für manuelle Profile entsteht nie ein Profil-Auftrag');
});

it('Befehl und Tool teilen den Service: die Zählung des Trockenlaufs stimmt überein', function () {
    $service = app(GpAromaBackfillService::class)->lauf($this->rootTeam, null, ['fuer_team' => true, 'nur' => 'beide']);
    $tool = ($this->run)($this->mandantUser, $this->rootTeam, ['nur' => 'beide']);

    expect($tool->data['luecken'])->toBe($service['luecken'])->and($tool->data['geschwister'])->toBe($service['geschwister']);
});

it('interner Aufruf ohne MCP-Herkunft läuft über den Plattform-Provider (kein Auftrag, extern=false)', function () {
    $r = $this->registry->get('foodalchemist.gp_aroma_backfill.RUN')->execute(['nur' => 'anker', 'apply' => true, 'limit' => 1], new ToolContext($this->mandantUser, $this->rootTeam));

    expect($r->data['extern'] ?? null)->toBeFalse()
        ->and(DB::table('foodalchemist_ki_auftraege')->count())->toBe(0);
});
