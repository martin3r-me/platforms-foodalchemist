<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Platform\FoodAlchemist\Jobs\GpAromaNachholenJob;
use Platform\FoodAlchemist\Livewire\Gps\DetailPanel;
use Platform\FoodAlchemist\Livewire\Gps\GpModal;
use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Services\Ai\AiGatewayService;
use Platform\FoodAlchemist\Services\Ai\AiProposal;
use Platform\FoodAlchemist\Services\BulkEnrichService;
use Platform\FoodAlchemist\Services\GpAromaService;
use Platform\FoodAlchemist\Services\SensorikService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * 27.10.2026 (Dominique/Kurator): neue GPs bekommen Kern-Anker und Geschmacksprofil. Geschwister derselben Hauptzutat zuerst
 * (kostenlos), dann KI — die KI schätzt die Wahrnehmung auf allen 7 Achsen. Nur Lücken, manuell bleibt, alle Anlagewege.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->user = $this->makeUser($this->rootTeam);
    config(['foodalchemist.bearbeitungssperre' => false]);
    $this->anker = [];
    foreach (['pineapple', 'lime', 'dark_chocolate'] as $slug) {
        $this->anker[$slug] = DB::table('foodalchemist_vocab_pairing_anchors')->insertGetId(['uuid' => (string) Str::uuid7(), 'slug' => $slug,
            'display_de' => ucfirst($slug), 'created_at' => now(), 'updated_at' => now()]);
    }
    $this->gp = fn (string $name, string $slug = 'ananas', array $extra = []) => FoodAlchemistGp::create(['team_id' => $this->rootTeam->id,
        'gp_key' => Str::slug($name).'-'.Str::random(4), 'name' => $name, 'main_ingredient_slug' => $slug, 'status' => 'approved'] + $extra);
    // Fixture setzt den Stand fest — auch über das, was der Geschwister-Weg bei der Anlage schon übernommen hat.
    $this->mapping = function (int $gpId, string $slug, string $role = 'kern') {
        DB::table('foodalchemist_gp_anchor_mappings')->where('gp_id', $gpId)->where('source', 'geschwister')->delete();
        DB::table('foodalchemist_gp_anchor_mappings')->updateOrInsert(['gp_id' => $gpId, 'anchor_id' => $this->anker[$slug]], [
            'uuid' => (string) Str::uuid7(), 'team_id' => $this->rootTeam->id, 'role' => $role, 'source' => 'manual', 'deleted_at' => null,
            'created_at' => now(), 'updated_at' => now()]);
    };
    $this->profil = fn (int $gpId, array $werte, string $source = 'ai') => DB::table('foodalchemist_gp_taste_vectors')->updateOrInsert(['gp_id' => $gpId],
        array_merge(array_fill_keys(SensorikService::DIMS, 0), $werte, ['source' => $source, 'created_at' => now(), 'updated_at' => now()]));
    $this->ankerVon = fn (int $gpId) => DB::table('foodalchemist_gp_anchor_mappings')->where('gp_id', $gpId)->whereNull('deleted_at')->orderBy('anchor_id')->get(['anchor_id', 'role', 'source']);
    /** KI-Mock: $antworten[key] = werte; zählt die Aufrufe je Key. */
    $this->ki = function (array $antworten) {
        $this->aufrufe = [];
        $this->mock(AiGatewayService::class, function ($mock) use ($antworten) {
            $mock->shouldReceive('propose')->andReturnUsing(function (string $key, array $ctx = []) use ($antworten) {
                $this->aufrufe[$key][] = $ctx;

                return new AiProposal($antworten[$key] ?? [], 0.7, "Mock {$key}", [], $key);
            });
            $mock->shouldReceive('stempleAccepted', 'stempleRejected')->andReturnNull();
        });
    };
    /** Nährwerte stubben: Konzentrationswert + Gehalt (g/100 g, Standard 10 = löst keine Grenze aus) ohne LA-Fixtures. */
    $this->gemessen = function (array $dims, float $gehalt = 10.0) {
        $s = Mockery::mock(SensorikService::class)->makePartial();
        $s->shouldReceive('erdungBulk')->andReturnUsing(fn (array $ids) => collect($ids)->mapWithKeys(fn ($id) => [(int) $id => collect($dims)
            ->map(fn ($w) => ['wert' => $w, 'gehalt' => $gehalt, 'anzahl' => 1, 'basis' => 'Test'])->all()])->all());
        app()->instance(SensorikService::class, $s);
    };
});

// ── Geschwister: Anker ──

it('neues GP übernimmt die Anker einiger Geschwister derselben Hauptzutat, ohne KI', function () {
    ($this->ki)([]);
    foreach (['Ananas: frisch, ganz', 'Ananas: TK, Würfel'] as $n) {
        $g = ($this->gp)($n);
        ($this->mapping)($g->id, 'pineapple');
        ($this->mapping)($g->id, 'lime', 'neben');
    }

    $neu = ($this->gp)('Ananas: TK, 35 g pro Stück / (Base)');

    expect(($this->ankerVon)($neu->id)->map(fn ($r) => [$r->anchor_id, $r->role, $r->source])->all())
        ->toBe([[$this->anker['pineapple'], 'kern', 'geschwister'], [$this->anker['lime'], 'neben', 'geschwister']])
        ->and($this->aufrufe)->toBe([]);
});

it('uneinige Geschwister übertragen keinen Anker', function () {
    $a = ($this->gp)('Ananas: frisch, ganz');
    ($this->mapping)($a->id, 'pineapple');
    $b = ($this->gp)('Ananas: Schokolade');
    ($this->mapping)($b->id, 'dark_chocolate');

    $neu = ($this->gp)('Ananas: TK');

    expect(($this->ankerVon)($neu->id))->toHaveCount(0)
        ->and(app(GpAromaService::class)->geschwisterAnker($neu)['status'])->toBe('uneinig');
});

// ── Geschwister: Profil ──

it('Profil nur von Geschwistern mit gleichem Zustand und gleicher Verarbeitung und nur aus verlässlicher Quelle', function () {
    $tk1 = ($this->gp)('Ananas: TK, Würfel', 'ananas', ['condition' => 'TK']);
    ($this->profil)($tk1->id, ['suess' => 0.7, 'sauer' => 0.40]);
    $tk2 = ($this->gp)('Ananas: TK, Stücke', 'ananas', ['condition' => 'TK']);
    ($this->profil)($tk2->id, ['suess' => 0.7, 'sauer' => 0.50]);
    $frisch = ($this->gp)('Ananas: frisch', 'ananas', ['condition' => 'frisch']);
    ($this->profil)($frisch->id, ['suess' => 0.6, 'sauer' => 0.9]);   // anderer Zustand → zählt nicht
    $alt = ($this->gp)('Ananas: TK, Ringe', 'ananas', ['condition' => 'TK']);
    ($this->profil)($alt->id, ['suess' => 0.1, 'sauer' => 0.0], 'gemini');   // alter Import → zählt nicht

    $neu = ($this->gp)('Ananas: TK, Base', 'ananas', ['condition' => 'TK']);

    $t = DB::table('foodalchemist_gp_taste_vectors')->where('gp_id', $neu->id)->first();
    expect($t->source)->toBe('geschwister')->and((float) $t->sauer)->toBe(0.45)->and((float) $t->suess)->toBe(0.7);

    $geraeuchert = ($this->gp)('Ananas: TK, geräuchert', 'ananas', ['condition' => 'TK', 'processing' => 'geräuchert']);
    expect(DB::table('foodalchemist_gp_taste_vectors')->where('gp_id', $geraeuchert->id)->exists())->toBeFalse();   // Verarbeitung ändert den Geschmack
});

it('Geschwister-Profil mit zu großer Spannweite gilt als uneinig', function () {
    foreach ([0.2, 0.6] as $i => $sauer) {
        ($this->profil)(($this->gp)("Ananas {$i}")->id, ['sauer' => $sauer]);
    }
    expect(app(GpAromaService::class)->geschwisterProfil(($this->gp)('Ananas neu'))['status'])->toBe('uneinig');
});

// ── KI-Schätzung: Wahrnehmung auf allen 7 Achsen, Nährwerte als Fakt (Dominique 29.10.2026) ──

it('KI schätzt alle 7 Achsen, die Nährwerte stehen als Fakt im Kontext und überschreiben nichts', function () {
    ($this->gemessen)(['suess' => 0.57]);                                       // Konzentration 0,57 bei 10 g Zucker
    ($this->ki)(['gp.sensorik' => ['geschmack' => ['suess' => 0.1, 'salzig' => 0, 'sauer' => 0.45, 'bitter' => 0.05, 'umami' => 0, 'fettig' => 0, 'scharf' => 0],
        'texturen' => [['slug' => 'saftig', 'intensitaet' => 0.8]]]]);
    DB::table('foodalchemist_vocab_textures')->insert(['slug' => 'saftig', 'display_de' => 'saftig', 'sort_order' => 1, 'created_at' => now(), 'updated_at' => now()]);
    $gp = ($this->gp)('Ananas: TK, Base', 'ananas_solo');

    $v = app(GpAromaService::class)->schaetze($gp);

    expect($this->aufrufe['gp.sensorik'][0]['zu_schaetzen'])->toBe(SensorikService::DIMS)
        ->and($this->aufrufe['gp.sensorik'][0]['naehrwerte'])->toBe(['zucker_g_je_100g' => 10.0])
        ->and($this->aufrufe['gp.sensorik'][0]['konzentration_ohne_maskierung'])->toBe(['suess' => 0.57])
        ->and($this->aufrufe['gp.sensorik'][0])->not->toHaveKey('gemessen')
        ->and($v['werte']['suess'])->toBe(0.1);                                  // der KI-Wert, nicht der Kurvenwert
    app(GpAromaService::class)->speichereProfil($gp->id, $v['werte'], 'ai', $v['confidence'], $v['reasoning'], $v['texturen']);
    $t = DB::table('foodalchemist_gp_taste_vectors')->where('gp_id', $gp->id)->first();
    expect((float) $t->suess)->toBe(0.1)->and((float) $t->sauer)->toBe(0.45)->and($t->source)->toBe('ai')
        ->and(DB::table('foodalchemist_gp_textures')->where('gp_id', $gp->id)->where('source', 'ai')->count())->toBe(1);
});

it('Amerikaner: Salz 1,2 g und Zucker 21 g, die KI sagt salzig 0,1 → gespeichert 0,1, die Grundlage zeigt 1,2 g', function () {
    ($this->gemessen)(['salzig' => 0.54], 1.2);
    ($this->ki)(['gp.sensorik' => ['geschmack' => ['suess' => 0.7, 'salzig' => 0.1, 'sauer' => 0, 'bitter' => 0, 'umami' => 0, 'fettig' => 0.3, 'scharf' => 0], 'texturen' => []]]);
    $gp = ($this->gp)('Amerikaner mit Zuckerguss: TK', 'amerikaner');

    $v = app(GpAromaService::class)->schaetze($gp);
    app(GpAromaService::class)->speichereProfil($gp->id, $v['werte'], 'ai', 0.7, $v['reasoning']);

    expect((float) DB::table('foodalchemist_gp_taste_vectors')->where('gp_id', $gp->id)->value('salzig'))->toBe(0.1)   // 1,2 g liegt unter der Schwelle 1,5
        ->and($v['eingriffe'])->toBe([])
        ->and($this->aufrufe['gp.sensorik'][0]['naehrwerte'])->toBe(['salz_g_je_100g' => 1.2]);
    // Lesen: das Profil gewinnt, die Nährwerte bleiben Grundlage — die alte Überlagerung hätte 0,54 gezeigt
    $s = app(SensorikService::class)->vektorenFuerGps([$gp->id])[$gp->id];
    expect($s['werte']['salzig'])->toBe(0.1)->and($s['messung']['salzig']['gehalt'])->toBe(1.2)->and($s['messung']['salzig']['vorlaeufig'])->toBeFalse();
});

it('Grenzen nach der KI-Antwort: Untergrenze bei „hoch", Obergrenze bei „arm", Süße ohne Obergrenze', function () {
    $s = app(SensorikService::class);
    $roh = array_fill_keys(SensorikService::DIMS, 0.0);

    // Untergrenze: Salz 2,4 g und KI 0,05 → 0,30 mit Hinweis
    $r = $s->begrenze(['salzig' => 0.05] + $roh, ['salzig' => 2.4]);
    expect($r['werte']['salzig'])->toBe(0.3)->and($r['eingriffe'][0])->toContain('[Grenze: salzig 0,05 → 0,30')->toContain('Salz 2,4 g/100 g');
    // Gegenprobe: ohne Gehalt greift keine Grenze
    expect($s->begrenze(['salzig' => 0.05] + $roh, [])['werte']['salzig'])->toBe(0.05);
    // Fett und Zucker ab FSA „hoch"
    expect($s->begrenze(['fettig' => 0.1] + $roh, ['fettig' => 17.5])['werte']['fettig'])->toBe(0.3)
        ->and($s->begrenze(['suess' => 0.1] + $roh, ['suess' => 22.5])['werte']['suess'])->toBe(0.3);
    // Obergrenze: Salz 0,1 g und KI 0,6 → 0,20; Fett < 3 g ebenso
    expect($s->begrenze(['salzig' => 0.6] + $roh, ['salzig' => 0.1])['werte']['salzig'])->toBe(0.2)
        ->and($s->begrenze(['fettig' => 0.6] + $roh, ['fettig' => 2.0])['werte']['fettig'])->toBe(0.2);
    // Süße hat keine Obergrenze: Zucker 0 g und KI 0,8 bleibt 0,8 (Süßstoffe)
    expect($s->begrenze(['suess' => 0.8] + $roh, ['suess' => 0.0])['werte']['suess'])->toBe(0.8);
    // Zwischen den Schwellen darf die KI frei einstufen
    expect($s->begrenze(['salzig' => 0.0] + $roh, ['salzig' => 1.2])['eingriffe'])->toBe([]);
});

it('speichereProfil wendet die Grenze mit den eigenen Nährwerten an, auch beim Geschwister-Weg, und schreibt den Eingriff in die Begründung', function () {
    ($this->gemessen)(['salzig' => 0.7], 2.4);
    $gp = ($this->gp)('Brezel: TK', 'brezel');

    app(GpAromaService::class)->speichereProfil($gp->id, ['salzig' => 0.05, 'umami' => 0.2], 'geschwister', null, 'von Geschwistern');

    $t = DB::table('foodalchemist_gp_taste_vectors')->where('gp_id', $gp->id)->first();
    expect((float) $t->salzig)->toBe(0.3)->and($t->ai_reasoning)->toContain('[Grenze: salzig');
});

it('ohne Profil steht der Konzentrationswert vorläufig, gekennzeichnet', function () {
    ($this->gemessen)(['salzig' => 0.74], 3.5);
    $gp = ($this->gp)('Salzstangen', 'salzstange');

    $v = app(SensorikService::class)->vektorenFuerGps([$gp->id])[$gp->id];

    expect($v['werte']['salzig'])->toBe(0.74)->and($v['messung']['salzig']['vorlaeufig'])->toBeTrue();
});

it('speichereProfil überschreibt nie ein manuelles und ohne Freigabe kein vorhandenes Profil', function () {
    $hand = ($this->gp)('Zitrone: frisch', 'zitrone');
    ($this->profil)($hand->id, ['sauer' => 0.9], 'manual');
    $alt = ($this->gp)('Limette: frisch', 'limette');
    ($this->profil)($alt->id, ['sauer' => 0.8], 'gemini');
    $svc = app(GpAromaService::class);

    expect($svc->speichereProfil($hand->id, ['sauer' => 0.1], 'ai', 0.5, null, [], true))->toBeFalse()
        ->and($svc->speichereProfil($alt->id, ['sauer' => 0.1], 'ai', 0.5, null))->toBeFalse()
        ->and($svc->speichereProfil($alt->id, ['sauer' => 0.2], 'ai', 0.5, null, [], true))->toBeTrue()
        ->and((float) DB::table('foodalchemist_gp_taste_vectors')->where('gp_id', $hand->id)->value('sauer'))->toBe(0.9);
});

// ── „Alles anreichern" ──

it('Alles anreichern setzt an einem GP ohne Profil und Anker beides', function () {
    $this->actingAs($this->user);
    ($this->ki)(['gp.anker' => ['anchor_slug' => 'pineapple'], 'gp.sensorik' => ['geschmack' => array_fill_keys(SensorikService::DIMS, 0.3)]]);
    $gp = ($this->gp)('Ananas: TK, Base', 'ananas_solo');

    app(BulkEnrichService::class)->nachziehenGp($this->rootTeam, [$gp->id], ['anker', 'sensorik'], 'test');

    expect(($this->ankerVon)($gp->id)->pluck('anchor_id')->all())->toBe([$this->anker['pineapple']])
        ->and(DB::table('foodalchemist_gp_taste_vectors')->where('gp_id', $gp->id)->value('source'))->toBe('ai');
});

it('Alles anreichern bezahlt kein Profil, das schon da ist', function () {
    $this->actingAs($this->user);
    ($this->ki)(['gp.sensorik' => ['geschmack' => array_fill_keys(SensorikService::DIMS, 0.3)]]);
    $gp = ($this->gp)('Ananas: TK, Base', 'ananas_solo');
    ($this->profil)($gp->id, ['sauer' => 0.4], 'gemini');

    app(BulkEnrichService::class)->nachziehenGp($this->rootTeam, [$gp->id], ['sensorik'], 'test');

    expect($this->aufrufe['gp.sensorik'] ?? [])->toBe([])
        ->and(BulkEnrichService::SCHRITTE_GP)->toContain('sensorik');
});

it('Schritt anker nimmt einige Geschwister vor der KI', function () {
    $this->actingAs($this->user);
    ($this->ki)(['gp.anker' => ['anchor_slug' => 'lime']]);
    $gp = ($this->gp)('Ananas: TK, Base');                         // noch ohne Geschwister → kein Anker bei der Anlage
    ($this->mapping)(($this->gp)('Ananas: frisch')->id, 'pineapple');

    app(BulkEnrichService::class)->nachziehenGp($this->rootTeam, [$gp->id], ['anker'], 'test');

    expect(($this->ankerVon)($gp->id)->pluck('source')->all())->toBe(['geschwister'])
        ->and($this->aufrufe['gp.anker'] ?? [])->toBe([]);
});

// ── Anlagewege ──

it('Anlage mit Nutzer und Worker stößt den KI-Job an, ohne Nutzer nicht', function () {
    Queue::fake();
    ($this->gp)('Ohne Nutzer', 'x1');
    Queue::assertNotPushed(GpAromaNachholenJob::class);

    $this->actingAs($this->user);
    $gp = ($this->gp)('Mit Nutzer', 'x2');
    Queue::assertPushed(GpAromaNachholenJob::class, fn ($j) => $j->gpId === $gp->id && $j->delay !== null);

    ($this->gp)('Platzhalter (neutral)', 'x3', ['is_platzhalter' => true]);
    Queue::assertPushed(GpAromaNachholenJob::class, 1);
});

it('auf einer sync-Queue läuft keine KI im Speicherpfad', function () {
    $this->actingAs($this->user);
    ($this->ki)([]);
    ($this->gp)('Ananas: TK', 'ananas_solo');

    expect($this->aufrufe)->toBe([]);
});

// ── Anzeige + Knöpfe ──

it('Detail-Ansicht: ohne Profil „Profil fehlt" und beide KI-Knöpfe; Schätzen schreibt erst mit Übernehmen', function () {
    $this->actingAs($this->user);
    ($this->ki)(['gp.sensorik' => ['geschmack' => array_fill_keys(SensorikService::DIMS, 0.4)]]);
    $gp = ($this->gp)('Ananas: TK, Base', 'ananas_solo');

    $c = Livewire::test(DetailPanel::class, ['gpId' => $gp->id])
        ->assertSeeHtml('data-gp-profil-fehlt')
        ->assertSeeHtml('data-ki-action="sensorikKiSchaetzen"')
        ->assertSeeHtml('data-ki-action="ankerKiVorschlagen"')
        ->assertDontSee('Lücke:')->assertDontSee('schwach:')          // unbewertet ist keine Schwäche
        ->call('sensorikKiSchaetzen')
        ->assertSeeHtml('data-gp-sensorik-vorschau');
    expect(DB::table('foodalchemist_gp_taste_vectors')->where('gp_id', $gp->id)->exists())->toBeFalse();

    $c->call('sensorikUebernehmen')->assertDontSeeHtml('data-gp-profil-fehlt');
    expect(DB::table('foodalchemist_gp_taste_vectors')->where('gp_id', $gp->id)->value('source'))->toBe('ai');
});

it('mit Profil heißt ein Wert unter 0,3 „schwach", nicht „Lücke" — im GP-Detail und im GP-Dialog', function () {
    $this->actingAs($this->user);
    $gp = ($this->gp)('Zitrone: frisch', 'zitrone');
    ($this->profil)($gp->id, ['sauer' => 0.9]);

    Livewire::test(DetailPanel::class, ['gpId' => $gp->id])
        ->assertSee('schwach: süß')->assertDontSee('Lücke:')->assertSeeHtml('data-gp-profil-quelle="ai"');
    Livewire::test(GpModal::class)->call('oeffnen', $gp->id)
        ->assertSee('schwach: süß')->assertDontSee('Lücke:')->assertSeeHtml('data-gp-profil-quelle="ai"');
});

it('GP-Detail „Geschmack" (Dominique 28.10.): Radar oben, Tags darunter; Erklärtext, Grundlage-Box und Crunch-Hinweis weg — der GP-Dialog bleibt', function () {
    $this->actingAs($this->user);
    $gp = ($this->gp)('Zitrone: frisch', 'zitrone');
    ($this->profil)($gp->id, ['sauer' => 0.9, 'suess' => 0.1]);
    ($this->gemessen)(['suess' => 0.1]);                                        // Grundlage-Box hätte Inhalt
    foreach (['cremig' => 'Cremig', 'fluessig' => 'Flüssig'] as $slug => $label) {   // zwei weiche Texturen → Crunch-Hinweis
        $v = DB::table('foodalchemist_vocab_textures')->insertGetId(['slug' => $slug, 'display_de' => $label, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('foodalchemist_gp_textures')->insert(['gp_id' => $gp->id, 'texture_vocab_id' => $v, 'intensity' => 1, 'created_at' => now(), 'updated_at' => now()]);
    }

    $detail = Livewire::test(DetailPanel::class, ['gpId' => $gp->id])->html();
    $dialog = Livewire::test(GpModal::class)->call('oeffnen', $gp->id)->html();
    $geschmack = Str::between($detail, 'data-gp-panel-geschmack', 'data-gp-panel-anker');

    expect($geschmack)->toContain('data-sensorik-nur-radar')->toContain('data-geschmack-radar')->toContain('max-w-lg')->toContain('Grundprodukt, Rohprofil')
        ->toContain('data-ki-action="sensorikKiSchaetzen"');
    $tags = Str::between($geschmack, 'data-sensorik-tags', '</div>');
    foreach (['dominant: sauer', 'schwach: süß', 'Cremig', 'Flüssig'] as $tag) {
        expect($tags)->toContain($tag);
    }
    expect(strpos($geschmack, 'data-sensorik-tags'))->toBeGreaterThan(strpos($geschmack, 'data-geschmack-radar'));   // unter dem Radar
    foreach (['Die Fläche zeigt', 'Grundlage: Nährwerte der Lieferantenartikel', 'Crunch-Element', '>Textur<'] as $teil) {
        expect($geschmack)->not->toContain($teil);
        expect($dialog)->toContain($teil);                                      // GP-Dialog unverändert
    }
    expect($dialog)->not->toContain('data-sensorik-nur-radar')->not->toContain('data-sensorik-tags')->toContain('max-w-[360px]');
});

it('Detail-Ansicht: „Anker vorschlagen" nimmt Geschwister ohne KI', function () {
    $this->actingAs($this->user);
    ($this->ki)([]);
    $gp = ($this->gp)('Ananas: TK, Base');
    ($this->mapping)(($this->gp)('Ananas: frisch')->id, 'pineapple');

    Livewire::test(DetailPanel::class, ['gpId' => $gp->id])->call('ankerKiVorschlagen')
        ->assertSet('hinweis', fn ($h) => str_contains((string) $h, 'Geschwister'));

    expect(($this->ankerVon)($gp->id)->pluck('source')->all())->toBe(['geschwister'])->and($this->aufrufe)->toBe([]);
});

// ── Backfill ──

it('Backfill: Trockenlauf schreibt nichts, --apply --nur-geschwister füllt ohne KI', function () {
    ($this->ki)([]);
    $a = ($this->gp)('Ananas: frisch');
    $b = ($this->gp)('Ananas: TK');                                 // beide ohne Anker angelegt
    ($this->mapping)($a->id, 'pineapple');

    $this->artisan('foodalchemist:gp-aroma-backfill', ['--nur' => 'anker'])->assertExitCode(0)->expectsOutputToContain('Trockenlauf');
    expect(($this->ankerVon)($b->id))->toHaveCount(0);

    $this->artisan('foodalchemist:gp-aroma-backfill', ['--nur' => 'anker', '--apply' => true, '--nur-geschwister' => true])->assertExitCode(0);
    expect(($this->ankerVon)($b->id)->pluck('source')->all())->toBe(['geschwister'])->and($this->aufrufe)->toBe([]);
});

it('Backfill: KI-Teil mit --limit füllt nur Lücken und schreibt ein Protokoll', function () {
    ($this->ki)(['gp.sensorik' => ['geschmack' => array_fill_keys(SensorikService::DIMS, 0.2)]]);
    $mit = ($this->gp)('Zitrone', 'zitrone');
    ($this->profil)($mit->id, ['sauer' => 0.9], 'gemini');
    $ohne = ($this->gp)('Limette', 'limette');
    $ber = sys_get_temp_dir().'/gparoma_'.uniqid();

    $this->artisan('foodalchemist:gp-aroma-backfill', ['--nur' => 'profil', '--apply' => true, '--admin' => $this->user->email, '--pause' => 0, '--limit' => 5, '--bericht' => $ber])
        ->assertExitCode(0);

    expect(DB::table('foodalchemist_gp_taste_vectors')->where('gp_id', $ohne->id)->value('source'))->toBe('ai')
        ->and(DB::table('foodalchemist_gp_taste_vectors')->where('gp_id', $mit->id)->value('source'))->toBe('gemini')
        ->and(count($this->aufrufe['gp.sensorik']))->toBe(1)
        ->and(glob($ber.'/*_aroma_protokoll.jsonl'))->toHaveCount(1);
});

it('Backfill-Trockenlauf schätzt die Kosten aus dem Mittel der letzten Calls', function () {
    ($this->gp)('Limette', 'limette');
    foreach ([0.002, 0.004] as $usd) {
        DB::table('foodalchemist_ai_call_log')->insert(['uuid' => (string) Str::uuid7(), 'feature' => 'gp.sensorik', 'model' => 'test',
            'tokens_in' => 1000, 'tokens_out' => 200, 'kosten_usd' => $usd, 'usd_eur' => 1.0, 'created_at' => now(), 'updated_at' => now()]);
    }

    $this->artisan('foodalchemist:gp-aroma-backfill', ['--nur' => 'profil'])->assertExitCode(0)
        ->expectsOutputToContain('gp.sensorik: Ø 0,0030 € je Call');
});

// ── Fund io 27.10.2026: globaler Basis-Stamm ist für alle nur lesbar ──

it('globales Basis-Stamm-GP gilt als nicht pflegbar: keine Geschwister-Übernahme, im Backfill gesondert gezählt', function () {
    $master = \Platform\Core\Models\Team::create(['name' => 'BHG.DIGITAL', 'user_id' => 1, 'personal_team' => false]);
    config(['foodalchemist.master_team_id' => $master->id]);
    \Platform\FoodAlchemist\Support\MasterTeam::flush();
    $global = fn (string $name, string $klasse) => FoodAlchemistGp::create(['team_id' => null, 'gp_key' => Str::slug($name), 'name' => $name,
        'main_ingredient_slug' => 'sonnenblumenhack', 'status' => 'approved', 'stamm_class' => $klasse]);
    $vorlage = $global('Sonnenblumen-Hack: TK', 'stamm_paket');
    DB::table('foodalchemist_gp_anchor_mappings')->insert(['uuid' => (string) Str::uuid7(), 'team_id' => $master->id, 'gp_id' => $vorlage->id,
        'anchor_id' => $this->anker['pineapple'], 'role' => 'kern', 'source' => 'manual', 'created_at' => now(), 'updated_at' => now()]);

    $basis = $global('Sonnenblumen-Hack: TK / (Bio) / (Vegan)', 'basis');
    $paket = $global('Sonnenblumen-Hack: TK / (Bio)', 'stamm_paket');
    $svc = app(GpAromaService::class);

    expect($svc->pflegbar($basis))->toBeFalse()->and($svc->pflegbar($paket))->toBeTrue()
        ->and(($this->ankerVon)($basis->id))->toHaveCount(0)                       // keine Exception, kein Versuch
        ->and(($this->ankerVon)($paket->id)->pluck('source')->all())->toBe(['geschwister']);
    expect($svc->ankerVonGeschwistern($basis))->toBe(0)                              // direkt (Knopf/Job): kein Versuch, keine Exception
        ->and($svc->profilVonGeschwistern($basis))->toBeFalse();

    $this->artisan('foodalchemist:gp-aroma-backfill', ['--nur' => 'anker'])->assertExitCode(0)
        ->expectsOutputToContain('nicht pflegbar (Basis-Stamm)');
    $this->artisan('foodalchemist:gp-aroma-backfill', ['--nur' => 'anker', '--apply' => true, '--nur-geschwister' => true])->assertExitCode(0)
        ->doesntExpectOutputToContain('Geerbt');                                     // io: 43 × „GP-Anker kann nur das Besitzer-Team ändern“
});

it('Backfill --limit streut den KI-Teil reihum über die Warengruppen', function () {
    ($this->ki)(['gp.sensorik' => ['geschmack' => array_fill_keys(SensorikService::DIMS, 0.2)]]);
    foreach (['11', '11', '11', '11', '02', '05'] as $i => $wg) {
        ($this->gp)("GP {$i}", "slug{$i}", ['commodity_group_code' => $wg]);
    }

    $this->artisan('foodalchemist:gp-aroma-backfill', ['--nur' => 'profil', '--apply' => true, '--admin' => $this->user->email, '--pause' => 0, '--limit' => 3])
        ->assertExitCode(0);

    $wgs = DB::table('foodalchemist_gp_taste_vectors AS t')->join('foodalchemist_gps AS g', 'g.id', '=', 't.gp_id')->pluck('g.commodity_group_code')->sort()->values()->all();
    expect($wgs)->toBe(['02', '05', '11']);
});

// ── Backfill --neu-schaetzen ──

it('Backfill --neu-schaetzen schätzt vorhandene Profile der genannten Quellen neu, ein manuelles nie', function () {
    ($this->ki)(['gp.sensorik' => ['geschmack' => array_fill_keys(SensorikService::DIMS, 0.2)]]);
    $alt = ($this->gp)('Zitrone', 'zitrone');
    ($this->profil)($alt->id, ['sauer' => 0.9, 'salzig' => 0.54], 'ai');
    $hand = ($this->gp)('Limette', 'limette');
    ($this->profil)($hand->id, ['sauer' => 0.8], 'manual');
    $andere = ($this->gp)('Ananas', 'ananas');
    ($this->profil)($andere->id, ['suess' => 0.7], 'gemini');                  // Quelle nicht genannt → bleibt
    $ber = sys_get_temp_dir().'/gparoma_'.uniqid();

    $this->artisan('foodalchemist:gp-aroma-backfill', ['--nur' => 'profil', '--apply' => true, '--admin' => $this->user->email, '--pause' => 0,
        '--neu-schaetzen' => 'ai,manual_nie_genannt', '--bericht' => $ber])->assertExitCode(1);       // unbekannte Quelle → Fehler, nichts geschrieben
    $this->artisan('foodalchemist:gp-aroma-backfill', ['--nur' => 'profil', '--apply' => true, '--admin' => $this->user->email, '--pause' => 0,
        '--neu-schaetzen' => 'ai', '--bericht' => $ber])->assertExitCode(0);

    $t = fn ($g) => DB::table('foodalchemist_gp_taste_vectors')->where('gp_id', $g->id)->first();
    expect((float) $t($alt)->salzig)->toBe(0.2)->and((float) $t($alt)->sauer)->toBe(0.2)         // neu geschätzt, alter Messwert weg
        ->and((float) $t($hand)->sauer)->toBe(0.8)->and($t($hand)->source)->toBe('manual')       // manuell nie
        ->and($t($andere)->source)->toBe('gemini')
        ->and(count($this->aufrufe['gp.sensorik']))->toBe(1);
});

// ── Rezept-Sensorik: Nährwerte als Fakt, Sub-Rezept-Profil im Kontext, Grenzen nach der Antwort ──

it('Rezept-Sensorik: Kontext mit Nährwert-Fakt, Summenzeile und Sub-Rezept-Profil, kein „[gemessen]"; die Grenze greift nach der Antwort', function () {
    ($this->gemessen)(['salzig' => 0.9], 98.0);                                 // jedes GP: Salz 98 g/100 g
    ($this->ki)(['recipe.sensorik' => ['geschmack' => array_fill_keys(SensorikService::DIMS, 0.0), 'texturen' => []]]);
    $gp = ($this->gp)('Salz', 'salz');
    $sub = $this->makeRecipe($this->rootTeam, 'Fond', ['is_sales_recipe' => false]);
    DB::table('foodalchemist_recipe_taste_vectors')->insert(array_merge(array_fill_keys(SensorikService::DIMS, 0), ['umami' => 0.7,
        'recipe_id' => $sub->id, 'source' => 'ai', 'created_at' => now(), 'updated_at' => now()]));
    $r = $this->makeRecipe($this->rootTeam, 'Fleisch: Roastbeef', ['is_sales_recipe' => false]);
    $this->makeIngredient($r, 'Salz', $gp, '16', 1);
    \Platform\FoodAlchemist\Models\FoodAlchemistRecipeIngredient::create(['team_id' => $r->team_id, 'recipe_id' => $r->id, 'referenced_recipe_id' => $sub->id,
        'raw_text' => 'Fond', 'quantity' => '400', 'unit_vocab_id' => $this->unitG($this->rootTeam)->id, 'position' => 2]);

    $res = app(SensorikService::class)->bewerteRezept($r->id);

    $kontext = $this->aufrufe['recipe.sensorik'][0]['zutaten_geerdet'];
    expect($res['status'])->toBe('bewertet')
        ->and($kontext)->toContain('[Nährwert: Salz 98,0 g/100 g]')->toContain('Summe Rezept je 100 g')
        ->and($kontext)->toContain('Sub-Rezept, gegart: Umami 0.7')->not->toContain('[gemessen')
        // Der Gehalt ist nur für 16 von 416 g bekannt (< 80 %): keine Grenze, die KI-Einstufung 0 bleibt
        ->and((float) DB::table('foodalchemist_recipe_taste_vectors')->where('recipe_id', $r->id)->value('salzig'))->toBe(0.0);
});

it('Rezept-Grenze: ist der Gehalt für die ganze Masse bekannt, gilt die Untergrenze und der Eingriff steht in der Begründung', function () {
    ($this->gemessen)(['salzig' => 0.9], 2.4);                                  // alle Zutaten 2,4 g Salz/100 g → Rezept 2,4 g
    ($this->ki)(['recipe.sensorik' => ['geschmack' => array_fill_keys(SensorikService::DIMS, 0.0), 'texturen' => []]]);
    $r = $this->makeRecipe($this->rootTeam, 'Gebäck: Brezel', ['is_sales_recipe' => false]);
    $this->makeIngredient($r, 'Teig', ($this->gp)('Teig', 'teig'), '500', 1);

    $res = app(SensorikService::class)->bewerteRezept($r->id);

    $t = DB::table('foodalchemist_recipe_taste_vectors')->where('recipe_id', $r->id)->first();
    expect((float) $t->salzig)->toBe(0.3)->and($t->ai_reasoning)->toContain('[Grenze: salzig 0,00 → 0,30')
        ->and($res['eingriffe'])->toHaveCount(1);
});

it('Referenz-Befehl (nur lesend): Amerikaner im Rahmen → Erfolg; Gegenprobe außerhalb der Spanne → Fehlschlag; nichts gespeichert', function () {
    $gp = ($this->gp)('Amerikaner mit Zuckerguss: TK', 'amerikaner');
    ($this->ki)(['gp.sensorik' => ['geschmack' => ['suess' => 0.7, 'salzig' => 0.1, 'sauer' => 0, 'bitter' => 0, 'umami' => 0, 'fettig' => 0.3, 'scharf' => 0], 'texturen' => []]]);
    $this->artisan('foodalchemist:gp-sensorik-referenz', ['--admin' => $this->user->email, '--gp' => ["amerikaner:{$gp->id}"]])->assertExitCode(0);

    ($this->ki)(['gp.sensorik' => ['geschmack' => ['suess' => 0.7, 'salzig' => 0.54, 'sauer' => 0, 'bitter' => 0, 'umami' => 0, 'fettig' => 0.3, 'scharf' => 0], 'texturen' => []]]);
    $this->artisan('foodalchemist:gp-sensorik-referenz', ['--admin' => $this->user->email, '--gp' => ["amerikaner:{$gp->id}"]])->assertExitCode(1);

    expect(DB::table('foodalchemist_gp_taste_vectors')->where('gp_id', $gp->id)->where('source', 'ai')->exists())->toBeFalse();
});

it('GP-Dialog: „Anker vorschlagen" setzt einen Paten gekennzeichnet (Quelle ai_pate), der Dialog zeigt „Pate"; neutral zeigt „Geprüft: kein Aroma-Träger"', function () {
    $this->actingAs($this->user);
    ($this->ki)(['gp.anker' => ['anchor_slug' => 'lime', 'art' => 'pate', 'pate_begruendung' => 'Zitrusfrucht, gleiche Säure und Funktion']]);
    $gp = ($this->gp)('Kalamansi: frisch', 'kalamansi');

    Livewire::test(GpModal::class)->call('oeffnen', $gp->id)->call('ankerKiVorschlagen')
        ->assertSeeHtml('data-gp-anker-pate');
    $m = DB::table('foodalchemist_gp_anchor_mappings')->where('gp_id', $gp->id)->whereNull('deleted_at')->first();
    expect($m->source)->toBe('ai_pate')->and($m->ai_reasoning)->toStartWith('[Pate] Zitrusfrucht');

    ($this->ki)(['gp.anker' => ['anchor_slug' => 'neutral', 'art' => 'neutral']]);
    $neutral = ($this->gp)('Speisestärke: trocken', 'speisestaerke');
    Livewire::test(GpModal::class)->call('oeffnen', $neutral->id)->call('ankerKiVorschlagen')
        ->assertSeeHtml('data-gp-anker-neutral');
    expect(app(GpAromaService::class)->istAnkerNeutral($neutral->id))->toBeTrue();
});
