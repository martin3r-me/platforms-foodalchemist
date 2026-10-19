<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplier;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItemStructure;
use Platform\FoodAlchemist\Services\GpBereinigenService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * foodalchemist:gp-bereinigen (Kurator/Dominique 19.10.2026): Dubletten mergen + Namensfixes aus geprüften Sichtungs-CSV-Plänen.
 * Trockenlauf = Default und schreibt nichts; --apply braucht Backup-Nachweis, schreibt Protokoll, ist per --zurueck umkehrbar.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->team = $this->rootTeam->id;
    $this->dir = sys_get_temp_dir() . '/gpb_' . uniqid();
    File::ensureDirectoryExists($this->dir);
    $this->sup = FoodAlchemistSupplier::create(['team_id' => null, 'name' => 'Hanos']);

    $this->gp = function (string $name, string $wg = '02', array $extra = []): FoodAlchemistGp {
        $svc = app(\Platform\FoodAlchemist\Services\GpNamingService::class);

        return FoodAlchemistGp::create(array_merge([
            'team_id' => $this->team, 'name' => $name, 'gp_key' => $svc->slugify($name), 'status' => 'approved', 'commodity_group_code' => $wg,
            'condition' => 'frisch', 'main_ingredient_slug' => $svc->slugify(trim((string) strstr($name . ':', ':', true))),
            'main_ingredient_display' => trim((string) strstr($name . ':', ':', true)),
        ], $extra));
    };
    $this->la = function (FoodAlchemistGp $gp, string $bez): FoodAlchemistSupplierItemStructure {
        $item = FoodAlchemistSupplierItem::create(['team_id' => null, 'supplier_id' => $this->sup->id, 'designation' => $bez, 'article_number' => uniqid(), 'qty' => 1, 'unit_code' => 'kg']);

        return FoodAlchemistSupplierItemStructure::create(['supplier_item_id' => $item->id, 'gp_id' => $gp->id]);
    };
    $this->csv = function (string $datei, array $kopf, array $zeilen): string {
        $pfad = $this->dir . '/' . $datei;
        $h = fopen($pfad, 'wb');
        fputcsv($h, $kopf, ';', '"', '');
        foreach ($zeilen as $z) {
            fputcsv($h, $z, ';', '"', '');
        }
        fclose($h);

        return $pfad;
    };
    $this->dubCsv = fn (array $paare) => ($this->csv)('dubletten.csv', ['group_id', 'gp_id', 'name', 'keep_proposal', 'reason'],
        array_merge(...array_map(fn ($p, $i) => [
            ['N' . str_pad((string) ($i + 1), 4, '0', STR_PAD_LEFT), $p[0]->id, $p[0]->name, 'behalten', ''],
            ['N' . str_pad((string) ($i + 1), 4, '0', STR_PAD_LEFT), $p[1]->id, $p[1]->name, 'merge→' . $p[0]->id, ''],
        ], $paare, array_keys($paare))));
    $this->namenCsv = fn (array $zeilen) => ($this->csv)('benennung.csv', ['gp_id', 'name', 'rule', 'proposal'], $zeilen);
    $this->lauf = fn (array $opt) => $this->artisan('foodalchemist:gp-bereinigen', ['--team' => $this->team, '--bericht' => $this->dir] + $opt);
});

afterEach(fn () => File::deleteDirectory($this->dir));

it('Trockenlauf schreibt nichts (kein Merge, kein Protokoll) und meldet die Zahlen', function () {
    $sieger = ($this->gp)('Banane: frisch, ganz');
    $verlierer = ($this->gp)('Bananen: frisch, ganz');
    ($this->la)($verlierer, 'Bananen Chiquita');
    $csv = ($this->dubCsv)([[$sieger, $verlierer]]);

    ($this->lauf)(['--phase' => 'dubletten', '--plan-dubletten' => $csv])->assertSuccessful()->expectsOutputToContain('1 Merge-Gruppe(n) ausführbar');

    expect($verlierer->fresh()->status->value)->toBe('approved')
        ->and(DB::table('foodalchemist_supplier_item_structures')->where('gp_id', $verlierer->id)->count())->toBe(1)
        ->and(glob($this->dir . '/*_protokoll.json'))->toBe([]);
});

it('--apply braucht einen Backup-Nachweis', function () {
    $a = ($this->gp)('Banane: frisch, ganz');
    $b = ($this->gp)('Bananen: frisch, ganz');
    $csv = ($this->dubCsv)([[$a, $b]]);

    ($this->lauf)(['--phase' => 'dubletten', '--plan-dubletten' => $csv, '--apply' => true])->assertFailed()->expectsOutputToContain('--backup-nachweis');
    expect($b->fresh()->status->value)->toBe('approved');
});

it('Merge hängt Artikel-Links, Derivate und Zähler um, Verlierer wird merged — und --zurueck stellt alles her', function () {
    $sieger = ($this->gp)('Banane: frisch, ganz');
    $verlierer = ($this->gp)('Bananen: frisch, ganz');
    ($this->la)($sieger, 'Banane A');
    ($this->la)($verlierer, 'Banane B');
    ($this->la)($verlierer, 'Banane C');
    $kind = ($this->gp)('Banane: frisch, Schale', '02', ['is_derivat' => true, 'derivat_von_gp_id' => $verlierer->id, 'requires_la' => false]);
    $csv = ($this->dubCsv)([[$sieger, $verlierer]]);
    $backup = $this->dir . '/backup.sql';
    file_put_contents($backup, str_repeat('x', 2048));

    ($this->lauf)(['--phase' => 'dubletten', '--plan-dubletten' => $csv, '--apply' => true, '--backup-nachweis' => $backup, '--ohne-recompute' => true])->assertSuccessful();

    $v = DB::table('foodalchemist_gps')->where('id', $verlierer->id)->first();
    expect($v->status)->toBe('merged')->and((int) $v->merged_into_id)->toBe($sieger->id)
        ->and(DB::table('foodalchemist_supplier_item_structures')->where('gp_id', $sieger->id)->count())->toBe(3)
        ->and(DB::table('foodalchemist_supplier_item_structures')->where('gp_id', $verlierer->id)->count())->toBe(0)
        ->and((int) DB::table('foodalchemist_gps')->where('id', $sieger->id)->value('n_las_total'))->toBe(3)
        ->and((int) DB::table('foodalchemist_gps')->where('id', $kind->id)->value('derivat_von_gp_id'))->toBe($sieger->id);

    $protokoll = glob($this->dir . '/*_protokoll.json')[0];
    ($this->lauf)(['--zurueck' => $protokoll])->assertSuccessful();

    expect(DB::table('foodalchemist_gps')->where('id', $verlierer->id)->value('status'))->toBe('approved')
        ->and(DB::table('foodalchemist_supplier_item_structures')->where('gp_id', $verlierer->id)->count())->toBe(2)
        ->and(DB::table('foodalchemist_supplier_item_structures')->where('gp_id', $sieger->id)->count())->toBe(1)
        ->and((int) DB::table('foodalchemist_gps')->where('id', $kind->id)->value('derivat_von_gp_id'))->toBe($verlierer->id);
});

it('Merge: Warengruppen verschieden, Namensdrift und Link-Review „kein Merge" werden NICHT gemerged, sondern gelistet', function () {
    $s1 = ($this->gp)('Banane: frisch', '02');
    $v1 = ($this->gp)('Bananen: frisch', '09');                 // andere WG
    $s2 = ($this->gp)('Apfel: frisch', '02');
    $v2 = ($this->gp)('Aepfel: frisch', '02');
    $s3 = ($this->gp)('Birne: frisch', '02');
    $v3 = ($this->gp)('Birnen: frisch', '02');
    $csv = ($this->dubCsv)([[$s1, $v1], [$s2, $v2], [$s3, $v3]]);
    $v2->update(['name' => 'Aepfel: frisch, ganz']);            // Name seit Sichtung geändert
    ($this->csv)('WG02_artikel.csv', ['gp_id', 'merge_hinweis'], [[$v3->id, 'Kein Merge: verschiedene Produkte']]);

    ($this->lauf)(['--phase' => 'dubletten', '--plan-dubletten' => $csv, '--review-dir' => $this->dir])
        ->assertSuccessful()->expectsOutputToContain('0 Merge-Gruppe(n) ausführbar');
    $liste = file_get_contents(glob($this->dir . '/*_review_liste.csv')[0]);
    expect($liste)->toContain('andere Warengruppe')->toContain('Name seit der Sichtung geändert')->toContain('Link-Review');
});

it('Namen: Plural→Singular zieht Name, Slug und Key mit; Review-Fälle bleiben unberührt', function () {
    $gp = ($this->gp)('Kartoffeln: festkochend, frisch', '01');
    $unklar = ($this->gp)('Aromat (neutral)', '13');
    $wg15 = ($this->gp)('Pils: fluessig, 4,9 vol-%', '15');
    $csv = ($this->namenCsv)([
        [$gp->id, $gp->name, '§6.1 Plural statt Singular', 'Kartoffel: festkochend, frisch'],
        [$unklar->id, $unklar->name, '§6 Schema: kein Doppelpunkt', 'unklar'],
        [$wg15->id, $wg15->name, '§9 Zustand fehlt im Namen', 'Pils: 4,9 vol-%'],
    ]);
    $backup = $this->dir . '/b.sql';
    file_put_contents($backup, str_repeat('x', 2048));

    ($this->lauf)(['--phase' => 'namen', '--plan-namen' => $csv, '--apply' => true, '--backup-nachweis' => $backup])->assertSuccessful();

    $g = DB::table('foodalchemist_gps')->where('id', $gp->id)->first();
    expect($g->name)->toBe('Kartoffel: festkochend, frisch')
        ->and($g->gp_key)->toBe('kartoffel_festkochend_frisch')
        ->and($g->main_ingredient_slug)->toBe('kartoffel')
        ->and($g->main_ingredient_display)->toBe('Kartoffel')
        ->and(DB::table('foodalchemist_gps')->where('id', $unklar->id)->value('name'))->toBe('Aromat (neutral)')
        ->and(DB::table('foodalchemist_gps')->where('id', $wg15->id)->value('name'))->toBe('Pils: fluessig, 4,9 vol-%');   // WG 15: eigene Phase
    expect(file_get_contents(glob($this->dir . '/*_review_liste.csv')[0]))->toContain('WG 15');
});

it('Namen: Bio-Fix ändert nur den Namen, der Key bleibt (Politik: nur bei Plural→Singular), --key-folgt-name zieht ihn mit', function () {
    $gp = ($this->gp)('Basmatireis: trocken, Bio', '07');
    $csv = ($this->namenCsv)([[$gp->id, $gp->name, '§6/§9 Bio als Eigenschaft statt Zusatz / (Bio)', 'Basmatireis: trocken / (Bio)']]);
    $backup = $this->dir . '/b.sql';
    file_put_contents($backup, str_repeat('x', 2048));

    ($this->lauf)(['--phase' => 'namen', '--plan-namen' => $csv, '--apply' => true, '--backup-nachweis' => $backup])->assertSuccessful();
    $g = DB::table('foodalchemist_gps')->where('id', $gp->id)->first();
    expect($g->name)->toBe('Basmatireis: trocken / (Bio)')->and($g->gp_key)->toBe('basmatireis_trocken_bio');

    $gp2 = ($this->gp)('Langkornreis: trocken, Bio', '07');
    $csv2 = ($this->namenCsv)([[$gp2->id, $gp2->name, '§6/§9 Bio als Eigenschaft statt Zusatz / (Bio)', 'Langkornreis: trocken / (Bio)']]);
    ($this->lauf)(['--phase' => 'namen', '--plan-namen' => $csv2, '--key-folgt-name' => true, '--apply' => true, '--backup-nachweis' => $backup])->assertSuccessful();
    expect(DB::table('foodalchemist_gps')->where('id', $gp2->id)->value('gp_key'))->toBe('langkornreis_trocken_bio');
});

it('Namen: Key-Kollision (auch mit merged-GP), mehrere Vorschläge und Namensdrift gehen in die Review-Liste', function () {
    $alt = ($this->gp)('Aubergine: frisch, Scheiben 20 mm', '01', ['status' => 'merged']);
    $gp = ($this->gp)('Auberginen: frisch, Scheiben 20 mm', '01');
    $zwei = ($this->gp)('Kartoffeln: gegart, Wuerfel 15x15 mm', '01');
    $drift = ($this->gp)('Zucchini: frisch', '01');
    $csv = ($this->namenCsv)([
        [$gp->id, $gp->name, '§6.1 Plural statt Singular', 'Aubergine: frisch, Scheiben 20 mm'],
        [$zwei->id, $zwei->name, '§9 Zustand fehlt im Namen', 'Kartoffeln: frisch, gegart, Wuerfel 15x15 mm'],
        [$zwei->id, $zwei->name, '§9 Maßangabe nicht kanonisch', 'Kartoffeln: gegart, Wuerfel 15 mm'],
        [$drift->id, 'Zucchini: frisch, ganz', '§6.1 Plural statt Singular', 'Zucchino: frisch, ganz'],
    ]);

    ($this->lauf)(['--phase' => 'namen', '--plan-namen' => $csv])->assertSuccessful()->expectsOutputToContain('0 Umbenennung(en) ausführbar');
    $liste = file_get_contents(glob($this->dir . '/*_review_liste.csv')[0]);
    expect($liste)->toContain('Key-Kollision')->toContain('Mehrere verschiedene Vorschläge')->toContain('Name seit der Sichtung geändert');
});

it('Verweis-Erkennung: findet gp_id-Tabellen der EIGENEN Datenbank (nie schema-qualifizierte Fremdtabellen)', function () {
    $spalten = app(GpBereinigenService::class)->verweisSpalten();

    expect($spalten)->toContain(['foodalchemist_recipe_ingredients', 'gp_id'])
        ->and($spalten)->toContain(['foodalchemist_supplier_item_structures', 'gp_id']);
    foreach ($spalten as [$tabelle]) {
        expect($tabelle)->not->toContain('.')->and($tabelle)->not->toBe('foodalchemist_gps');
    }
});

it('Mehrere --plan-namen: beide CSVs werden angewandt, derselbe Vorschlag aus zwei Dateien zählt einmal', function () {
    $a = ($this->gp)('Kartoffeln: festkochend, frisch', '01');
    $b = ($this->gp)('Ananaspueree: konserviert, Boiron 1 kg', '02');
    $csv1 = ($this->csv)('regeln.csv', ['gp_id', 'name', 'rule', 'proposal'], [[$a->id, $a->name, '§6.1 Plural statt Singular', 'Kartoffel: festkochend, frisch']]);
    $csv2 = ($this->csv)('marke.csv', ['gp_id', 'name', 'rule', 'proposal'], [
        [$b->id, $b->name, 'Marke/Gebinde im GP-Namen', 'Ananaspueree: konserviert'],
        [$a->id, $a->name, '§6.1 Plural statt Singular', 'Kartoffel: festkochend, frisch'],   // dasselbe nochmal
    ]);
    $backup = $this->dir . '/b.sql';
    file_put_contents($backup, str_repeat('x', 2048));

    ($this->lauf)(['--phase' => 'namen', '--plan-namen' => [$csv1, $csv2], '--apply' => true, '--backup-nachweis' => $backup])
        ->assertSuccessful()->expectsOutputToContain('2 Umbenennung(en) ausführbar');

    expect(DB::table('foodalchemist_gps')->where('id', $a->id)->value('name'))->toBe('Kartoffel: festkochend, frisch')
        ->and(DB::table('foodalchemist_gps')->where('id', $b->id)->value('name'))->toBe('Ananaspueree: konserviert');
});

it('Namens-Kollision über zwei CSVs: zwei GPs würden denselben Namen bekommen → nur das erste wird umbenannt, das zweite ist ein Konflikt', function () {
    $a = ($this->gp)('Brombeerpuerree: konserviert', '02');
    $b = ($this->gp)('Brombeerpueree: konserviert, Boiron 1 kg', '02');
    $csv1 = ($this->csv)('regeln.csv', ['gp_id', 'name', 'rule', 'proposal'], [[$a->id, $a->name, '§9 Tippfehler', 'Brombeerpueree: konserviert']]);
    $csv2 = ($this->csv)('marke.csv', ['gp_id', 'name', 'rule', 'proposal'], [[$b->id, $b->name, 'Marke/Gebinde im GP-Namen', 'Brombeerpueree: konserviert']]);
    $backup = $this->dir . '/b.sql';
    file_put_contents($backup, str_repeat('x', 2048));

    ($this->lauf)(['--phase' => 'namen', '--plan-namen' => [$csv1, $csv2], '--apply' => true, '--backup-nachweis' => $backup])
        ->assertSuccessful()->expectsOutputToContain('1 Umbenennung(en) ausführbar');

    $namen = DB::table('foodalchemist_gps')->whereIn('id', [$a->id, $b->id])->pluck('name', 'id');
    expect($namen[$a->id])->toBe('Brombeerpueree: konserviert')                       // erster Vorschlag gewinnt
        ->and($namen[$b->id])->toBe('Brombeerpueree: konserviert, Boiron 1 kg');      // zweiter unverändert, Merge-Kandidat
    expect(file_get_contents(glob($this->dir . '/*_review_liste.csv')[0]))->toContain('Namens-Kollision im selben Lauf');
});

it('Namens-Kollision mit einem bestehenden aktiven GP wird gemeldet statt umbenannt (Merge-Kandidat)', function () {
    $ziel = ($this->gp)('Granatapfel: frisch, ganz', '02');
    $gp = ($this->gp)('Granataepfel: frisch, ganz', '02');
    $csv = ($this->namenCsv)([[$gp->id, $gp->name, '§6.1 Plural statt Singular', 'Granatapfel: frisch, ganz']]);

    ($this->lauf)(['--phase' => 'namen', '--plan-namen' => [$csv]])->assertSuccessful()->expectsOutputToContain('0 Umbenennung(en) ausführbar');

    expect(file_get_contents(glob($this->dir . '/*_review_liste.csv')[0]))->toContain('Namens-Kollision')->toContain('#' . $ziel->id);
});

it('Zwei verschiedene Vorschläge für dasselbe GP aus zwei CSVs → Konflikt, keine Umbenennung', function () {
    $gp = ($this->gp)('Kartoffeln: gegart, Wuerfel 15x15 mm', '01');
    $csv1 = ($this->csv)('a.csv', ['gp_id', 'name', 'rule', 'proposal'], [[$gp->id, $gp->name, '§9 Zustand fehlt im Namen', 'Kartoffeln: frisch, gegart, Wuerfel 15x15 mm']]);
    $csv2 = ($this->csv)('b.csv', ['gp_id', 'name', 'rule', 'proposal'], [[$gp->id, $gp->name, '§9 Maßangabe nicht kanonisch', 'Kartoffeln: gegart, Wuerfel 15 mm']]);

    ($this->lauf)(['--phase' => 'namen', '--plan-namen' => [$csv1, $csv2]])->assertSuccessful()->expectsOutputToContain('0 Umbenennung(en) ausführbar');

    expect(file_get_contents(glob($this->dir . '/*_review_liste.csv')[0]))->toContain('Mehrere verschiedene Vorschläge');
});

it('Namen: wechselt das Zustandswort im neuen Namen (trocken→frisch), zieht `condition` mit — und --zurueck stellt es her', function () {
    $gp = ($this->gp)('Kartoffeln: trocken, mehligkochend', '02');
    $gp->update(['condition' => 'trocken']);
    $csv = ($this->namenCsv)([[$gp->id, $gp->name, '§9 Zustand', 'Kartoffeln: frisch, mehligkochend']]);
    $backup = $this->dir . '/b.sql';
    file_put_contents($backup, str_repeat('x', 2048));

    ($this->lauf)(['--phase' => 'namen', '--plan-namen' => $csv, '--apply' => true, '--backup-nachweis' => $backup])->assertSuccessful();

    $g = DB::table('foodalchemist_gps')->where('id', $gp->id)->first();
    expect($g->name)->toBe('Kartoffeln: frisch, mehligkochend')->and($g->condition)->toBe('frisch');

    ($this->lauf)(['--zurueck' => glob($this->dir . '/*_protokoll.json')[0]])->assertSuccessful();

    expect(DB::table('foodalchemist_gps')->where('id', $gp->id)->value('condition'))->toBe('trocken');
});

it('Namen: Form „Pueree“ am Roh-GP wandert in die Hauptzutat (§8.17) — das Formfeld wird geleert, --zurueck stellt es her', function () {
    $gp = ($this->gp)('Butternusskuerbis: konserviert, Pueree', '02', ['condition' => 'konserviert', 'form' => 'Pueree']);
    $csv = ($this->namenCsv)([[$gp->id, $gp->name, '§8.17 Pueree', 'Butternusskuerbispueree: konserviert']]);
    $backup = $this->dir . '/b.sql';
    file_put_contents($backup, str_repeat('x', 2048));

    ($this->lauf)(['--phase' => 'namen', '--plan-namen' => $csv, '--apply' => true, '--backup-nachweis' => $backup])->assertSuccessful();

    $g = DB::table('foodalchemist_gps')->where('id', $gp->id)->first();
    expect($g->name)->toBe('Butternusskuerbispueree: konserviert')->and($g->form)->toBeNull();

    ($this->lauf)(['--zurueck' => glob($this->dir . '/*_protokoll.json')[0]])->assertSuccessful();

    expect(DB::table('foodalchemist_gps')->where('id', $gp->id)->value('form'))->toBe('Pueree');
});
