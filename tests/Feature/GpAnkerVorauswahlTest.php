<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Services\Ai\KnowledgeEmbeddingService;
use Platform\FoodAlchemist\Support\GpAnkerVorauswahl;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * io-Lauf 01.11.2026: Die Anker-Vorauswahl für gp.anker war Rauschen (Zustandswort „frisch" → Lachs-Anker in fast jeder Liste), der echte Anker
 * fehlte oft, neutral hieß dann „nicht in der Vorauswahl". Neu: Geschwister-Anker, Wort-Treffer im Produktkopf inkl. Komposita, Semantik nur
 * mit dem Kopf, lexikalisch + semantisch nur aus den Anker-Kategorien der Warengruppe, Rückfall auf die häufigsten Anker der Warengruppe.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    config(['foodalchemist.gp_anker_vorauswahl.kategorien_je_wg' => ['04' => ['Protein/Fleisch'], '05' => ['Protein/Fisch']]]);
    $this->anker = [];
    foreach ([
        ['bratwurst', 'Bratwurst', 'Protein/Fleisch'], ['pan_fried_bratwurst', 'Bratwurst, in der Pfanne gebraten', 'Protein/Fleisch'],
        ['smoked_pork', 'Schweinefleisch, geräuchert', 'Protein/Fleisch'], ['fresh_atlantic_salmon', 'Atlantischer Lachs, frisch', 'Protein/Fisch'],
        ['fresh_trout', 'Forelle, frisch', 'Protein/Fisch'], ['lake_trout', 'Seeforelle', 'Protein/Fisch'], ['neutral', 'Neutral', null],
    ] as [$slug, $de, $sub]) {
        $this->anker[$slug] = DB::table('foodalchemist_vocab_pairing_anchors')->insertGetId(['uuid' => (string) Str::uuid7(), 'slug' => $slug, 'display_de' => $de,
            'category' => $sub === null ? null : explode('/', $sub)[0], 'subcategory' => $sub, 'created_at' => now(), 'updated_at' => now()]);
    }
    $this->gp = fn (string $name, string $haupt, ?string $wg) => FoodAlchemistGp::create(['team_id' => $this->rootTeam->id, 'gp_key' => Str::slug($name).'-'.Str::random(4),
        'name' => $name, 'main_ingredient_slug' => $haupt, 'commodity_group_code' => $wg, 'status' => 'approved']);
    $this->kern = fn (int $gpId, string $slug, string $source = 'inspire_katalog') => DB::table('foodalchemist_gp_anchor_mappings')->insert(['uuid' => (string) Str::uuid7(),
        'team_id' => $this->rootTeam->id, 'gp_id' => $gpId, 'anchor_id' => $this->anker[$slug], 'role' => 'kern', 'source' => $source, 'created_at' => now(), 'updated_at' => now()]);
    $this->svc = app(GpAnkerVorauswahl::class);
});

it('Produktkopf: Name vor dem Doppelpunkt, ohne Marke und Klammern', function () {
    expect(GpAnkerVorauswahl::kopf('Ravioli Rustico mit Trüffel: TK / (Bon Pastaio)'))->toBe('Ravioli Rustico mit Trüffel')
        ->and(GpAnkerVorauswahl::kopf('Weißkohl (Spitz) / Weißkraut: Streifen'))->toBe('Weißkohl / Weißkraut')
        ->and(GpAnkerVorauswahl::kopf('Traubenkernöl'))->toBe('Traubenkernöl');
});

it('Wort-Treffer im Kopf auch im Kompositum, Geschwister-Anker dabei, kein Lachs aus dem Zustandswort „frisch" — die alte Vorauswahl hatte ihn', function () {
    $gp = ($this->gp)('Rostbratwurst: frisch, 100 g pro Stück', 'rostbratwurst', '04');
    $geschwister = ($this->gp)('Rostbratwurst: TK', 'rostbratwurst', '04');
    ($this->kern)($geschwister->id, 'smoked_pork');

    $neu = $this->svc->fuer($gp, $this->rootTeam);

    expect($neu)->toBe(['smoked_pork', 'bratwurst', 'pan_fried_bratwurst', 'neutral'])
        ->and($this->svc->alt($gp))->toContain('fresh_atlantic_salmon')->not->toContain('bratwurst');
});

it('Semantik fragt nur den Kopf und liefert nur Anker der passenden Kategorie (Fisch-Anker nicht bei Fleisch), nach Slug sortiert', function () {
    $gefragt = [];
    $this->mock(KnowledgeEmbeddingService::class, function ($m) use (&$gefragt) {
        $m->shouldReceive('searchAnkerSlugs')->andReturnUsing(function (string $q) use (&$gefragt) {
            $gefragt[] = $q;

            return ['smoked_pork', 'fresh_atlantic_salmon', 'bratwurst'];
        });
    });
    $gp = ($this->gp)('Mettenden: geräuchert, frisch', 'mettenden', '04');

    $q = app(GpAnkerVorauswahl::class)->quellen($gp, $this->rootTeam);

    expect($gefragt)->toBe(['Mettenden'])
        ->and($q['semantisch'])->toBe(['bratwurst', 'smoked_pork'])
        ->and($q['lexikalisch'])->toBe([]);
});

it('ohne jeden Treffer: die häufigsten Kern-Anker der Warengruppe statt der alphabetisch ersten des ganzen Vokabulars', function () {
    foreach (['Forelle: frisch', 'Forelle: TK', 'Seeforelle: frisch'] as $i => $n) {
        ($this->kern)(($this->gp)($n, 'f'.$i, '05')->id, $i < 2 ? 'fresh_trout' : 'lake_trout');
    }
    $gp = ($this->gp)('Saibling: frisch', 'saibling', '05');

    expect($this->svc->fuer($gp, $this->rootTeam))->toBe(['fresh_trout', 'lake_trout', 'neutral']);
});

it('Messbefehl: zählt, ob der bekannte Anker in alter und neuer Vorauswahl steht (read-only)', function () {
    $gp = ($this->gp)('Rostbratwurst: frisch', 'rostbratwurst', '04');
    ($this->kern)($gp->id, 'bratwurst');
    $vorher = DB::table('foodalchemist_gp_anchor_mappings')->count();

    $this->artisan('foodalchemist:gp-anker-vorauswahl-messen', ['--stichprobe' => 5])
        ->expectsOutputToContain('Treffer alt 0/1, neu 1/1')
        ->assertSuccessful();
    expect(DB::table('foodalchemist_gp_anchor_mappings')->count())->toBe($vorher);
});
