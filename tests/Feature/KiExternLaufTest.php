<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Tools\ToolRegistry;
use Platform\FoodAlchemist\Exceptions\KiExternSchrankeException;
use Platform\FoodAlchemist\Services\Ai\KiAuftragService;
use Platform\FoodAlchemist\Services\Ai\KiExternLauf;
use Platform\FoodAlchemist\Tests\Support\KiExternFakeJob;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * #1002 (P2a): externer Job-Lauf — Sammel-Transaktion mit Rollback bis zur Antwort, Auto-Wiederholung durch ki_auftraege.ANTWORT,
 * Wiederholungs-Schranke, ein laufender Lauf je Ziel. Der Plattformweg bleibt bytegleich.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->user = $this->makeUser($this->rootTeam);
    $this->actingAs($this->user);
    config(['foodalchemist.ai.provider' => 'fake', 'foodalchemist.ai.backoff' => [], 'foodalchemist.ai.mcp_extern' => true]);
    Schema::create('ki_fake_job', function ($t) {
        $t->id();
        $t->string('x');
    });
    KiExternFakeJob::$nachCommit = [];
    KiExternFakeJob::$niveaus = [];
    $this->ki = app(KiAuftragService::class);
    $this->kontext = new ToolContext($this->user, $this->rootTeam, ['team_source' => 'user_current']);
    $this->zeilen = fn () => DB::table('ki_fake_job')->orderBy('id')->pluck('x')->all();
    $this->start = fn (array $a = []) => (new KiExternFakeJob($this->rootTeam->id, ...($a + ['extern' => true])))->handle(app(KiExternLauf::class));
    $this->antwort = fn (string $uuid) => app(ToolRegistry::class)->get('foodalchemist.ki_auftraege.ANTWORT')->execute([
        'antworten' => [['auftrag_id' => $uuid, 'werte' => ['werte' => ['description' => 'Fertig.'], 'confidence' => 0.9]]],
        'anbieter' => 'anthropic', 'modell' => 'claude-opus-5-5',
    ], $this->kontext);
    $this->offen = fn () => DB::table('foodalchemist_ki_auftraege')->where('status', 'offen')->orderBy('id')->value('uuid');
});

it('Plattformweg bleibt bytegleich: ohne Extern-Flag keine Transaktion, kein Auftrag, Rumpf läuft durch', function () {
    $niveau = DB::transactionLevel();

    $ergebnis = ($this->start)(['extern' => false]);

    expect($ergebnis)->toBe(KiExternLauf::FERTIG)
        ->and(($this->zeilen)())->toBe(['A', 'B', 'C'])
        ->and(KiExternFakeJob::$niveaus)->toBe([$niveau], 'Rumpf läuft im selben Transaktions-Niveau wie der Aufrufer')
        ->and(DB::table('foodalchemist_ki_auftraege')->count())->toBe(0);
});

it('extern: wartet der Rumpf, Rollback — kein Halbzustand, kein Nach-Commit-Effekt, Auftrag trägt Nutzlast und Lauf-Schlüssel', function () {
    $ergebnis = ($this->start)();

    expect($ergebnis)->toBe(KiExternLauf::WARTET)
        ->and(($this->zeilen)())->toBe([], 'Halbzustand')
        ->and(KiExternFakeJob::$nachCommit)->toBe([])
        ->and(DB::table('foodalchemist_ki_auftraege')->count())->toBe(1);

    $a = DB::table('foodalchemist_ki_auftraege')->first();
    $p = json_decode($a->wiederholung, true);
    expect($a->lauf_key)->toBe('fake-job:ziel-1')
        ->and($p['klasse'])->toBe(KiExternFakeJob::class)
        ->and($p['versuch'])->toBe(0)
        ->and($p['nutzlast']['team_id'])->toBe($this->rootTeam->id);
});

it('Auto-Wiederholung: ANTWORT startet den Lauf neu, bis er vollständig durchläuft — Antworten erst dann verbraucht, nichts doppelt', function () {
    ($this->start)();
    $a1 = ($this->offen)();

    $r1 = ($this->antwort)($a1);
    expect($r1->data['laeufe_neu_gestartet'])->toHaveCount(1)
        ->and($r1->data['laeufe_neu_gestartet'][0]['versuch'])->toBe(1)
        ->and(($this->zeilen)())->toBe([], 'zweites Glied wartet noch: weiter Rollback')
        ->and(DB::table('foodalchemist_ki_auftraege')->where('status', 'offen')->count())->toBe(1)
        ->and(DB::table('foodalchemist_ki_auftraege')->where('uuid', $a1)->value('status'))->toBe('beantwortet', 'Verbrauch mit zurückgerollt');

    $a2 = ($this->offen)();
    $r2 = ($this->antwort)($a2);

    expect($r2->data['laeufe_neu_gestartet'][0]['versuch'])->toBe(2)
        ->and(($this->zeilen)())->toBe(['A', 'B', 'C'], 'einmal, committet')
        ->and(KiExternFakeJob::$nachCommit)->toBe(['ziel-1'], 'Nach-Commit-Effekt genau einmal')
        ->and(DB::table('foodalchemist_ki_auftraege')->pluck('status')->unique()->all())->toBe(['verbraucht'])
        ->and($this->ki->zaehler($this->rootTeam->id))->toMatchArray(['angelegt' => 2, 'verbraucht' => 2, 'doppelt' => 0, 'offen' => 0, 'verwaist' => 0]);
});

it('ein laufender Lauf je Ziel: ein zweiter Start (manueller Neuaufruf) legt weder Auftrag noch Zeilen an', function () {
    ($this->start)();

    expect(($this->start)())->toBe(KiExternLauf::LAEUFT_BEREITS)
        ->and(DB::table('foodalchemist_ki_auftraege')->count())->toBe(1)
        ->and(($this->zeilen)())->toBe([]);

    // auch nach der Antwort (Auftrag beantwortet, Neustart noch nicht verbraucht) startet kein zweiter
    $this->ki->beantworte($this->rootTeam->id, ($this->offen)(), ['werte' => ['description' => 'x']], 'a', 'b');
    expect(($this->start)())->toBe(KiExternLauf::LAEUFT_BEREITS);

    // anderes Ziel: unabhängig
    expect(($this->start)(['ziel' => 'ziel-2']))->toBe(KiExternLauf::WARTET);
});

it('derselbe Versuch wird nur einmal angestoßen (Queue: angestoßen, aber noch nicht gelaufen)', function () {
    ($this->start)();
    $a1 = ($this->offen)();
    $this->ki->beantworte($this->rootTeam->id, $a1, ['werte' => ['description' => 'x']], 'a', 'b');
    \Illuminate\Support\Facades\Bus::fake();                 // dispatch läuft NICHT inline: es gibt (noch) keinen neuen offenen Auftrag

    $erst = $this->ki->stoesseWiederholungAn($this->rootTeam->id, [$a1]);
    $nochmal = $this->ki->stoesseWiederholungAn($this->rootTeam->id, [$a1]);

    expect($erst)->toHaveCount(1)->and($nochmal)->toBe([]);
    \Illuminate\Support\Facades\Bus::assertDispatchedTimes(KiExternFakeJob::class, 1);
    \Illuminate\Support\Facades\Bus::assertDispatched(KiExternFakeJob::class, fn ($j) => $j->versuch === 1 && $j->extern === true && $j->ziel === 'ziel-1');
});

it('Schranke: instabiler Prompt ⇒ Lauf bricht mit klarem Fehler ab, „doppelt“ macht es sichtbar', function () {
    config(['foodalchemist.ai.extern_max_wiederholungen' => 2]);
    ($this->start)(['instabil' => true]);                                  // Versuch 0 → Auftrag 1
    $this->ki->beantworte($this->rootTeam->id, ($this->offen)(), ['werte' => ['description' => 'x']], 'a', 'b');
    $this->ki->stoesseWiederholungAn($this->rootTeam->id, DB::table('foodalchemist_ki_auftraege')->where('status', 'beantwortet')->pluck('uuid')->all());
    // Versuch 1: Prompt hat sich geändert → neuer Auftrag, die Antwort von Auftrag 1 passt nicht
    expect(DB::table('foodalchemist_ki_auftraege')->count())->toBe(2);
    $this->ki->beantworte($this->rootTeam->id, ($this->offen)(), ['werte' => ['description' => 'x']], 'a', 'b');

    // Versuch 2 = Schranke: der Lauf wartet wieder → Abbruch statt weiterer Aufträge
    expect(fn () => $this->ki->stoesseWiederholungAn($this->rootTeam->id, DB::table('foodalchemist_ki_auftraege')->where('status', 'beantwortet')->pluck('uuid')->all()))
        ->toThrow(KiExternSchrankeException::class, 'Wiederholungs-Schranke');
    expect(DB::table('foodalchemist_ki_auftraege')->count())->toBe(2, 'kein dritter Auftrag')
        ->and($this->ki->zaehler($this->rootTeam->id)['doppelt'])->toBeGreaterThan(0)
        ->and(($this->zeilen)())->toBe([]);
});

it('fremde Klasse in der Nutzlast wird nie instanziiert', function () {
    ($this->start)();
    $uuid = ($this->offen)();
    $this->ki->beantworte($this->rootTeam->id, $uuid, ['werte' => ['description' => 'x']], 'a', 'b');
    $p = json_decode((string) DB::table('foodalchemist_ki_auftraege')->where('uuid', $uuid)->value('wiederholung'), true);
    DB::table('foodalchemist_ki_auftraege')->where('uuid', $uuid)->update(['wiederholung' => json_encode(['klasse' => \stdClass::class] + $p)]);

    expect($this->ki->stoesseWiederholungAn($this->rootTeam->id, [$uuid]))->toBe([]);
});

it('Aufträge ohne Nutzlast (Backfill, Tool-Hülle) lösen keine Wiederholung aus', function () {
    $tool = app(ToolRegistry::class)->get('foodalchemist.gps.ENRICH');   // nur um den Registry-Zugriff zu belegen
    expect($tool)->not->toBeNull();
    DB::table('foodalchemist_ki_auftraege')->insert([
        'uuid' => 'ohne-nutzlast', 'team_id' => $this->rootTeam->id, 'feature' => 'recipe.description', 'messages_hash' => 'h',
        'messages' => '[]', 'status' => 'beantwortet', 'laeuft_ab_at' => now()->addHour(), 'created_at' => now(), 'updated_at' => now(),
    ]);

    expect($this->ki->stoesseWiederholungAn($this->rootTeam->id, ['ohne-nutzlast']))->toBe([]);
});
