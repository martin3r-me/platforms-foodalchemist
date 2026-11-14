<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Services\GpNamingService;
use Platform\FoodAlchemist\Support\MasterTeam;
use Platform\FoodAlchemist\Support\StammPaket;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * foodalchemist:gp-felder (GP-Lauf, Kurator 14.11.2026): Felder nach JSONL-Plan setzen, Namen genau wie updateGp neu bilden.
 * Trockenlauf schreibt nichts; --apply schreibt mit Protokoll; --zurueck stellt her; Konflikte werden nie geschrieben. Immer über den echten Kommando-Weg.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    config(['foodalchemist.master_team_id' => $this->rootTeam->id]);
    MasterTeam::flush();
    $this->team = $this->rootTeam->id;
    $this->dir = sys_get_temp_dir() . '/gpf_' . uniqid();
    File::ensureDirectoryExists($this->dir);
    $this->backup = $this->dir . '/b.sql';
    file_put_contents($this->backup, str_repeat('x', 2048));

    // GP, dessen Name dem Render seiner Felder entspricht (Hauptzutat Banane, Zuschnitt ganz, Zustand frisch → „Banane: ganz, frisch“)
    $this->gp = function (array $extra = [], ?int $team = -1): FoodAlchemistGp {
        $felder = array_merge(['main_ingredient_display' => 'Banane', 'form' => 'ganz', 'condition' => 'frisch', 'commodity_group_code' => '02'], $extra);
        $svc = app(GpNamingService::class);
        $name = $svc->renderGpName(['hauptzutat' => $felder['main_ingredient_display'], 'form' => $felder['form'], 'condition' => $felder['condition'],
            'processing' => $felder['processing'] ?? null, 'commodity_group_code' => $felder['commodity_group_code']]);
        $gp = FoodAlchemistGp::create(array_merge($felder, [
            'team_id' => $team === -1 ? $this->team : $team, 'name' => $name, 'gp_key' => $svc->slugify($name), 'status' => 'approved',
            'main_ingredient_slug' => $svc->slugify($felder['main_ingredient_display']),
        ]));

        return $gp->fresh();
    };
    $this->plan = function (array $zeilen): string {
        $pfad = $this->dir . '/plan_' . uniqid() . '.jsonl';
        file_put_contents($pfad, implode("\n", array_map(fn ($z) => json_encode($z, JSON_UNESCAPED_UNICODE), $zeilen)) . "\n");

        return $pfad;
    };
    $this->lauf = fn (array $opt) => $this->artisan('foodalchemist:gp-felder', ['--bericht' => $this->dir] + $opt);
    $this->datei = fn (string $endung) => collect(glob($this->dir . '/*' . $endung))->last();
    $this->apply = fn (array $opt) => ($this->lauf)($opt + ['--apply' => true, '--backup-nachweis' => $this->backup, '--ohne-recompute' => true]);
});

afterEach(fn () => File::deleteDirectory($this->dir));

it('Trockenlauf schreibt nichts, der Bericht zeigt den neu gerenderten Namen', function () {
    $gp = ($this->gp)();
    $plan = ($this->plan)([['gp_id' => $gp->id, 'name_alt' => $gp->name, 'felder' => ['condition' => 'TK'], 'notiz' => 'Test']]);

    ($this->lauf)(['--team' => $this->team, '--plan' => $plan])->assertSuccessful()->expectsOutputToContain('ausführbar 1');

    expect($gp->fresh()->name)->toBe('Banane: ganz, frisch')->and($gp->fresh()->condition)->toBe('frisch')
        ->and(glob($this->dir . '/*_protokoll.json'))->toBe([])
        ->and(file_get_contents(($this->datei)('_felder_bericht.csv')))->toContain('Banane: ganz, TK')->toContain('condition');
});

it('--apply braucht einen Backup-Nachweis', function () {
    $gp = ($this->gp)();
    $plan = ($this->plan)([['gp_id' => $gp->id, 'name_alt' => $gp->name, 'felder' => ['condition' => 'TK']]]);

    ($this->lauf)(['--team' => $this->team, '--plan' => $plan, '--apply' => true])->assertFailed()->expectsOutputToContain('--backup-nachweis');
    expect($gp->fresh()->condition)->toBe('frisch');
});

it('--apply schreibt Felder + Namen + gp_key (Key war aus dem Namen ableitbar), nicht genannte Felder bleiben; --zurueck stellt alles her', function () {
    $gp = ($this->gp)(['processing' => 'geschält', 'zusatz' => 'Chiquita']);
    $nameAlt = $gp->name;
    $keyAlt = $gp->gp_key;
    $plan = ($this->plan)([['gp_id' => $gp->id, 'name_alt' => $nameAlt, 'felder' => ['condition' => 'TK', 'piece_default_g' => 120]]]);

    ($this->apply)(['--team' => $this->team, '--plan' => $plan])->assertSuccessful();

    $g = $gp->fresh();
    $erwartet = app(GpNamingService::class)->renderGpName(['hauptzutat' => 'Banane', 'processing' => 'geschält', 'form' => 'ganz', 'condition' => 'TK', 'zusatz' => 'Chiquita', 'commodity_group_code' => '02']);
    expect($g->name)->toBe($erwartet)->and($g->name)->toContain('/ (Chiquita)')
        ->and($g->condition)->toBe('TK')->and($g->condition_source)->toBe('manual')
        ->and($g->processing)->toBe('geschält')->and($g->zusatz)->toBe('Chiquita')
        ->and((float) $g->piece_default_g)->toBe(120.0)
        ->and($g->gp_key)->toBe(app(GpNamingService::class)->slugify($erwartet));

    ($this->lauf)(['--zurueck' => ($this->datei)('_felder_protokoll.json'), '--ohne-recompute' => true])->assertSuccessful();
    $z = $gp->fresh();
    expect($z->name)->toBe($nameAlt)->and($z->gp_key)->toBe($keyAlt)->and($z->condition)->toBe('frisch')->and($z->piece_default_g)->toBeNull();
});

it('Konflikt: Name seit Plan geändert — wird auch mit --apply nicht geschrieben', function () {
    $gp = ($this->gp)();
    $plan = ($this->plan)([['gp_id' => $gp->id, 'name_alt' => 'Banane: alt', 'felder' => ['condition' => 'TK']]]);

    ($this->apply)(['--team' => $this->team, '--plan' => $plan])->assertSuccessful();

    expect($gp->fresh()->condition)->toBe('frisch')
        ->and(file_get_contents(($this->datei)('_felder_konflikte.csv')))->toContain('Name seit Plan geändert');
});

it('Konflikt: Namens-Kollision mit anderem aktiven GP (Merge-Kandidat) und im selben Lauf', function () {
    $gp = ($this->gp)();
    ($this->gp)(['condition' => 'TK']);   // trägt schon „Banane: ganz, TK“
    $a = ($this->gp)(['main_ingredient_display' => 'Apfel']);
    $b = ($this->gp)(['main_ingredient_display' => 'Apfel', 'form' => 'Scheiben']);
    $plan = ($this->plan)([
        ['gp_id' => $gp->id, 'name_alt' => $gp->name, 'felder' => ['condition' => 'TK']],
        ['gp_id' => $a->id, 'name_alt' => $a->name, 'felder' => ['form' => 'Würfel']],
        ['gp_id' => $b->id, 'name_alt' => $b->name, 'felder' => ['form' => 'Würfel']],
    ]);

    ($this->apply)(['--team' => $this->team, '--plan' => $plan])->assertSuccessful();

    $konflikte = file_get_contents(($this->datei)('_felder_konflikte.csv'));
    expect($gp->fresh()->condition)->toBe('frisch')
        ->and($konflikte)->toContain('Merge-Kandidat')->toContain('im selben Lauf')
        ->and($a->fresh()->form)->toBe('Würfel')->and($b->fresh()->form)->toBe('Scheiben');
});

it('Konflikt: Regelwerk-Fehler im neuen Namen, Wert außerhalb des Vokabulars, unerlaubtes Feld, Untergruppe nicht verwaltet', function () {
    $g1 = ($this->gp)();
    $g2 = ($this->gp)(['main_ingredient_display' => 'Kiwi']);
    $g3 = ($this->gp)(['main_ingredient_display' => 'Mango']);
    $g4 = ($this->gp)(['main_ingredient_display' => 'Papaya']);
    $plan = ($this->plan)([
        ['gp_id' => $g1->id, 'name_alt' => $g1->name, 'felder' => ['flavor' => 'frisch']],          // §6.6: Geschmack = Zustandswort → harter Fehler
        ['gp_id' => $g2->id, 'name_alt' => $g2->name, 'felder' => ['condition' => 'gekocht']],
        ['gp_id' => $g3->id, 'name_alt' => $g3->name, 'felder' => ['gp_key' => 'x']],
        ['gp_id' => $g4->id, 'name_alt' => $g4->name, 'felder' => ['sub_category' => 'Gibt es nicht']],
    ]);

    ($this->apply)(['--team' => $this->team, '--plan' => $plan])->assertSuccessful()->expectsOutputToContain('Konflikte 4');

    $k = file_get_contents(($this->datei)('_felder_konflikte.csv'));
    expect($k)->toContain('Regelwerk')->toContain('§9-Vokabular')->toContain('nicht erlaubt')->toContain('verwalteten Liste')
        ->and($g1->fresh()->flavor)->toBeNull()->and($g2->fresh()->condition)->toBe('frisch')->and($g4->fresh()->sub_category)->toBeNull();
});

it('Untergruppe aus der verwalteten Liste der WG wird gesetzt', function () {
    DB::table('foodalchemist_commodity_group_subcategories')->insert(['uuid' => (string) \Illuminate\Support\Str::uuid(), 'team_id' => null,
        'commodity_group_code' => '02', 'name' => '02.1 Kernobst', 'position' => 1, 'created_at' => now(), 'updated_at' => now()]);
    $gp = ($this->gp)();
    $plan = ($this->plan)([['gp_id' => $gp->id, 'name_alt' => $gp->name, 'felder' => ['sub_category' => '02.1 Kernobst']]]);

    ($this->apply)(['--team' => $this->team, '--plan' => $plan])->assertSuccessful();

    expect($gp->fresh()->sub_category)->toBe('02.1 Kernobst')->and($gp->fresh()->name)->toBe('Banane: ganz, frisch');
});

it('global: ohne --global unberührt, mit --global als Master geschrieben (bleibt global), Nicht-Master bricht ab', function () {
    $gp = ($this->gp)([], null);
    DB::table('foodalchemist_gps')->where('id', $gp->id)->update(['stamm_class' => StammPaket::PAKET]);
    $plan = ($this->plan)([['gp_id' => $gp->id, 'name_alt' => $gp->name, 'felder' => ['condition' => 'TK']]]);

    ($this->apply)(['--team' => $this->team, '--plan' => $plan])->assertSuccessful();
    expect($gp->fresh()->condition)->toBe('frisch')
        ->and(file_get_contents(($this->datei)('_felder_konflikte.csv')))->toContain('--global');

    ($this->apply)(['--team' => $this->team + 999, '--global' => true, '--plan' => $plan])->assertFailed()->expectsOutputToContain('nur als Master-Team');
    expect($gp->fresh()->condition)->toBe('frisch');

    ($this->apply)(['--global' => true, '--plan' => $plan])->assertSuccessful();
    $g = $gp->fresh();
    expect($g->condition)->toBe('TK')->and($g->name)->toBe('Banane: ganz, TK')->and($g->team_id)->toBeNull()
        ->and(DB::table('foodalchemist_gps')->where('id', $gp->id)->value('stamm_class'))->toBe(StammPaket::PAKET);
});

it('Gegenprobe Namensbildung: Trockenlauf-Name = geschriebener Name = updateGp-Render (Marke/Land/Siegel-Klammern in fester Reihenfolge)', function () {
    $gp = ($this->gp)(['main_ingredient_display' => 'Lachsfilet', 'form' => null, 'condition' => 'TK', 'commodity_group_code' => '05', 'zusatz' => 'Royal']);
    $plan = ($this->plan)([['gp_id' => $gp->id, 'name_alt' => $gp->name, 'felder' => ['herkunft_land' => 'Norwegen', 'namens_msc' => true, 'bio' => true]]]);

    ($this->lauf)(['--team' => $this->team, '--plan' => $plan])->assertSuccessful();
    $bericht = file_get_contents(($this->datei)('_felder_bericht.csv'));
    ($this->apply)(['--team' => $this->team, '--plan' => $plan])->assertSuccessful();

    $g = $gp->fresh();
    expect($bericht)->toContain($g->name)
        ->and($g->name)->toBe('Lachsfilet: TK / (Royal) / (Norwegen) / (Bio) / (MSC)')
        ->and((bool) $g->namens_msc)->toBeTrue()->and($g->bio)->toBe('bio')->and($g->herkunft_land)->toBe('Norwegen');
});

it('condition „geräuchert, TK“ (§9.2 R3) wird zu Behandlung + TK in processing und rendert wie der Namensdienst', function () {
    $gp = ($this->gp)(['main_ingredient_display' => 'Lachsfilet', 'form' => null, 'processing' => 'geschnitten', 'condition' => 'frisch', 'commodity_group_code' => '05']);
    $plan = ($this->plan)([['gp_id' => $gp->id, 'name_alt' => $gp->name, 'felder' => ['condition' => 'geräuchert, TK']]]);

    ($this->apply)(['--team' => $this->team, '--plan' => $plan])->assertSuccessful();

    $g = $gp->fresh();
    expect($g->name)->toBe('Lachsfilet: geschnitten, geräuchert, TK')->and($g->condition)->toBe('geräuchert')->and($g->processing)->toBe('TK, geschnitten')
        ->and($g->name)->toBe(app(GpNamingService::class)->renderGpName(['hauptzutat' => 'Lachsfilet', 'processing' => 'TK, geschnitten', 'condition' => 'geräuchert', 'commodity_group_code' => '05']));
});

it('marke_offen ist ein erlaubtes Feld (0/1) und wird nach updateGp gesetzt', function () {
    $gp = ($this->gp)();
    $plan = ($this->plan)([['gp_id' => $gp->id, 'name_alt' => $gp->name, 'felder' => ['marke_offen' => true]]]);

    ($this->apply)(['--team' => $this->team, '--plan' => $plan])->assertSuccessful();

    expect((bool) $gp->fresh()->marke_offen)->toBeTrue()->and($gp->fresh()->name)->toBe('Banane: ganz, frisch');
});
