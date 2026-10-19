<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplier;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItemStructure;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * foodalchemist:gp-umhaengen (Kurator/Dominique 19.10.2026): genehmigten Plan ausführen — umhängen, neue GPs über den GP-Dienst, lösen, WG setzen.
 * Trockenlauf = Default; --apply braucht Backup-Nachweis, schreibt Protokoll, ist per --zurueck umkehrbar; nur klare Ziele laufen.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->team = $this->rootTeam->id;
    $this->dir = sys_get_temp_dir() . '/gpu_' . uniqid();
    File::ensureDirectoryExists($this->dir);
    $this->sup = FoodAlchemistSupplier::create(['team_id' => null, 'name' => 'Kluth']);
    $this->wgAnlegen = function (string $code, string $name) {
        if (! DB::table('foodalchemist_lookup_commodity_groups')->where('code', $code)->exists()) {
            DB::table('foodalchemist_lookup_commodity_groups')->insert(['uuid' => (string) \Illuminate\Support\Str::uuid(), 'team_id' => null, 'code' => $code, 'name' => $name, 'sort_order' => (int) $code, 'created_at' => now(), 'updated_at' => now()]);
        }
    };
    ($this->wgAnlegen)('03', 'Kräuter');
    ($this->wgAnlegen)('06', 'Molkerei');

    $this->gp = fn (string $key, string $name, string $wg = '03', array $extra = []): FoodAlchemistGp => FoodAlchemistGp::create(array_merge([
        'team_id' => $this->team, 'gp_key' => $key, 'name' => $name, 'status' => 'approved', 'commodity_group_code' => $wg, 'condition' => 'frisch',
        'main_ingredient_slug' => strtolower(strtok($name, ':')),
    ], $extra));
    $this->la = function (?FoodAlchemistGp $gp, string $bez): FoodAlchemistSupplierItemStructure {
        $item = FoodAlchemistSupplierItem::create(['team_id' => null, 'supplier_id' => $this->sup->id, 'designation' => $bez, 'article_number' => uniqid(), 'qty' => 1, 'unit_code' => 'kg']);

        return FoodAlchemistSupplierItemStructure::create(['supplier_item_id' => $item->id, 'gp_id' => $gp?->id]);
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
    $this->plan = fn (array $zeilen) => ($this->csv)('plan.csv', ['aktion', 'supplier_item_id', 'gp_id_quelle', 'ziel_gp_id', 'neu_name', 'neu_wg', 'neu_sub', 'neu_status', 'notiz'], $zeilen);
    $this->lauf = fn (array $opt) => $this->artisan('foodalchemist:gp-umhaengen', ['--team' => $this->team, '--bericht' => $this->dir] + $opt);
    $this->backup = function () {
        $b = $this->dir . '/backup.sql';
        file_put_contents($b, str_repeat('x', 2048));

        return $b;
    };
});

afterEach(fn () => File::deleteDirectory($this->dir));

it('Entwurf aus Review: liest id-Formen, NEUES GP (mit WG-Hinweis) und Lösen; nicht Lesbares kommt in die Liste — und ändert nichts', function () {
    $quelle = ($this->gp)('brennnessel|', 'Brennnessel: frisch');
    $ziel = ($this->gp)('dill|', 'Dill: frisch');
    $s1 = ($this->la)($quelle, 'Gruentee Brennnessel');
    $s2 = ($this->la)($quelle, 'Dill Tray');
    $s3 = ($this->la)($quelle, 'Zester');
    $s4 = ($this->la)($quelle, 'Unklar');
    $review = ($this->csv)('review.csv', ['gp_id', 'gp_key', 'gp_name', 'supplier_item_id', 'necta_legacy_id', 'lieferant', 'artikelname', 'urteil', 'ziel_gp', 'begruendung', 'sicherheit', 'lead_problem', 'merge_hinweis'], [
        [$quelle->id, 'k', 'n', $s1->supplier_item_id, '', 'K', 'a', 'falsch', 'NEUES GP NOETIG: Brennnessel-Tee: trocken (Teebeutel, WG 15)', 'Tee', 'hoch', '', ''],
        [$quelle->id, 'k', 'n', $s2->supplier_item_id, '', 'K', 'a', 'andere_variante', "dill_frisch (id {$ziel->id}: Dill: frisch)", 'Dill', 'hoch', '', ''],
        [$quelle->id, 'k', 'n', $s3->supplier_item_id, '', 'K', 'a', 'falsch', 'NEUES GP NOETIG: kein Food-GP, Kuechenwerkzeug Zester (Verknuepfung loesen)', 'Geraet', 'hoch', '', ''],
        [$quelle->id, 'k', 'n', $s4->supplier_item_id, '', 'K', 'a', 'andere_variante', 'irgendein Text ohne Ziel', 'x', 'niedrig', '', ''],
        [$quelle->id, 'k', 'n', $s4->supplier_item_id + 1000, '', 'K', 'a', 'passt', '', 'ok', 'hoch', '', ''],
    ]);

    ($this->lauf)(['--entwurf-aus-review' => [$review]])->assertSuccessful()->expectsOutputToContain('Entwurf: 3 Zeilen (umhängen 1 · neues GP 1 · lösen 1), 1 nicht eindeutig lesbar');

    $entwurf = file_get_contents(glob($this->dir . '/*_umhaengen_entwurf.csv')[0]);
    expect($entwurf)->toContain('Brennnessel-Tee: trocken')->and($entwurf)->not->toContain('(Teebeutel')->and($entwurf)->toContain(';15;')   // WG-Hinweis aus „(… WG 15)“
        ->and(DB::table('foodalchemist_supplier_item_structures')->where('gp_id', $quelle->id)->count())->toBe(4);
});

it('Trockenlauf schreibt nichts und meldet Zahlen', function () {
    $a = ($this->gp)('a|', 'Kresse: frisch');
    $b = ($this->gp)('b|', 'Honigkresse: frisch');
    $s = ($this->la)($a, 'Honey Cress');
    $plan = ($this->plan)([['umhaengen', $s->supplier_item_id, $a->id, $b->id, '', '', '', '', '']]);

    ($this->lauf)(['--plan' => [$plan]])->assertSuccessful()->expectsOutputToContain('Trockenlauf');

    expect(DB::table('foodalchemist_supplier_item_structures')->where('id', $s->id)->value('gp_id'))->toBe($a->id)
        ->and(glob($this->dir . '/*_umhaengen_protokoll.json'))->toBe([]);
});

it('--apply braucht einen Backup-Nachweis', function () {
    $a = ($this->gp)('a|', 'Kresse: frisch');
    $b = ($this->gp)('b|', 'Honigkresse: frisch');
    $s = ($this->la)($a, 'Honey Cress');
    $plan = ($this->plan)([['umhaengen', $s->supplier_item_id, $a->id, $b->id, '', '', '', '', '']]);

    ($this->lauf)(['--plan' => [$plan], '--apply' => true])->assertFailed()->expectsOutputToContain('--backup-nachweis');
    expect(DB::table('foodalchemist_supplier_item_structures')->where('id', $s->id)->value('gp_id'))->toBe($a->id);
});

it('Umhängen: Verknüpfung, n_las_total und ungültiger Lead werden angepasst — und --zurueck stellt alles her', function () {
    $a = ($this->gp)('a|', 'Kresse: frisch');
    $b = ($this->gp)('b|', 'Honigkresse: frisch');
    $s1 = ($this->la)($a, 'Garten Kresse');
    $s2 = ($this->la)($a, 'Honey Cress');
    $a->update(['lead_la_supplier_item_id' => $s2->supplier_item_id, 'n_las_total' => 2]);
    $plan = ($this->plan)([['umhaengen', $s2->supplier_item_id, $a->id, $b->id, '', '', '', '', '']]);

    ($this->lauf)(['--plan' => [$plan], '--apply' => true, '--backup-nachweis' => ($this->backup)(), '--ohne-recompute' => true])->assertSuccessful();

    expect(DB::table('foodalchemist_supplier_item_structures')->where('id', $s2->id)->value('gp_id'))->toBe($b->id)
        ->and((int) DB::table('foodalchemist_gps')->where('id', $a->id)->value('n_las_total'))->toBe(1)
        ->and((int) DB::table('foodalchemist_gps')->where('id', $b->id)->value('n_las_total'))->toBe(1)
        ->and(DB::table('foodalchemist_gps')->where('id', $a->id)->value('lead_la_supplier_item_id'))->not->toBe($s2->supplier_item_id);   // Lead zeigte auf den umgehängten Artikel

    ($this->lauf)(['--zurueck' => glob($this->dir . '/*_umhaengen_protokoll.json')[0]])->assertSuccessful();

    expect(DB::table('foodalchemist_supplier_item_structures')->where('id', $s2->id)->value('gp_id'))->toBe($a->id)
        ->and((int) DB::table('foodalchemist_gps')->where('id', $a->id)->value('n_las_total'))->toBe(2)
        ->and((int) DB::table('foodalchemist_gps')->where('id', $a->id)->value('lead_la_supplier_item_id'))->toBe($s2->supplier_item_id);
});

it('Neues GP entsteht über den GP-Dienst (Name, WG, Status), der Artikel wird verknüpft; --zurueck entfernt es wieder', function () {
    $quelle = ($this->gp)('zm|', 'Zitronenmelisse: frisch');
    $s = ($this->la)($quelle, 'KRAEUTERTOPF ZITRONENMELISSE 13CM');
    $plan = ($this->plan)([['neues_gp', $s->supplier_item_id, $quelle->id, '', 'Zitronenmelisse: frisch, im Topf', '03', '', '', 'Topf']]);

    ($this->lauf)(['--plan' => [$plan], '--apply' => true, '--backup-nachweis' => ($this->backup)(), '--ohne-recompute' => true])->assertSuccessful();

    $neu = DB::table('foodalchemist_gps')->where('name', 'Zitronenmelisse: frisch, im Topf')->first();
    expect($neu)->not->toBeNull()->and($neu->status)->toBe('approved')->and($neu->commodity_group_code)->toBe('03')->and((int) $neu->team_id)->toBe($this->team)
        ->and(DB::table('foodalchemist_supplier_item_structures')->where('id', $s->id)->value('gp_id'))->toBe((int) $neu->id)
        ->and((int) $neu->n_las_total)->toBe(1);

    ($this->lauf)(['--zurueck' => glob($this->dir . '/*_umhaengen_protokoll.json')[0]])->assertSuccessful();

    expect(DB::table('foodalchemist_gps')->where('name', 'Zitronenmelisse: frisch, im Topf')->exists())->toBeFalse()
        ->and(DB::table('foodalchemist_supplier_item_structures')->where('id', $s->id)->value('gp_id'))->toBe($quelle->id);
});

it('Lösen setzt gp_id auf NULL; Warengruppe setzen ändert WG und Sub-Kategorie', function () {
    $a = ($this->gp)('a|', 'Zester: frisch');
    $s = ($this->la)($a, 'ZITRONEN ZESTER');
    $plan = ($this->plan)([['loesen', $s->supplier_item_id, $a->id, '', '', '', '', '', ''], ['wg_setzen', '', $a->id, '', '', '06', '', '', '']]);

    ($this->lauf)(['--plan' => [$plan], '--apply' => true, '--backup-nachweis' => ($this->backup)(), '--ohne-recompute' => true])->assertSuccessful();

    expect(DB::table('foodalchemist_supplier_item_structures')->where('id', $s->id)->value('gp_id'))->toBeNull()
        ->and(DB::table('foodalchemist_gps')->where('id', $a->id)->value('commodity_group_code'))->toBe('06');
});

it('Konflikte werden gelistet und NICHT ausgeführt: Drift, fehlendes/gemergtes Ziel, doppeltes neues GP, unbekannte WG', function () {
    $a = ($this->gp)('a|', 'Kresse: frisch');
    $merged = ($this->gp)('m|', 'Kresse alt: frisch', '03', ['status' => 'merged']);
    $b = ($this->gp)('b|', 'Honigkresse: frisch');
    $s1 = ($this->la)($a, 'x1');
    $s2 = ($this->la)($a, 'x2');
    $s3 = ($this->la)($a, 'x3');
    $s4 = ($this->la)($a, 'x4');
    $plan = ($this->plan)([
        ['umhaengen', $s1->supplier_item_id, $b->id, $merged->id, '', '', '', '', ''],            // Drift: hängt nicht an b
        ['umhaengen', $s2->supplier_item_id, $a->id, $merged->id, '', '', '', '', ''],            // Ziel gemergt
        ['umhaengen', $s3->supplier_item_id, $a->id, 999999, '', '', '', '', ''],                 // Ziel fehlt
        ['wg_setzen', '', $a->id, '', '', '99', '', '', ''],                                      // WG gibt es nicht
        ['neues_gp', $s4->supplier_item_id, $a->id, '', 'Kresse: frisch', '03', '', '', ''],      // GP mit demselben Schlüssel gibt es schon
    ]);

    ($this->lauf)(['--plan' => [$plan]])->assertSuccessful()->expectsOutputToContain('Konflikte: 5');

    $liste = file_get_contents(glob($this->dir . '/*_umhaengen_konflikte.csv')[0]);
    expect($liste)->toContain('inzwischen an GP')->toContain('Status merged')->toContain('existiert nicht')->toContain('Warengruppe »99«')->toContain('GP gibt es schon');
});

it('GP auflösen: Einzel-Umhängungen laufen zuerst, der Rest geht auf das Ziel-GP, die Quelle wird merged — und --zurueck stellt alles her', function () {
    $quelle = ($this->gp)('q|', 'Kresse: frisch');
    $ziel = ($this->gp)('z|', 'Gartenkresse: frisch');
    $anders = ($this->gp)('d|', 'Honigkresse: frisch');
    $s1 = ($this->la)($quelle, 'Kresse A');
    $s2 = ($this->la)($quelle, 'Kresse B');
    $quelle->update(['n_las_total' => 2]);
    $plan = ($this->plan)([
        ['umhaengen', $s1->supplier_item_id, $quelle->id, $anders->id, '', '', '', '', ''],
        ['gp_aufloesen', '', $quelle->id, $ziel->id, '', '', '', '', 'Rest'],
    ]);

    ($this->lauf)(['--plan' => [$plan], '--apply' => true, '--backup-nachweis' => ($this->backup)(), '--ohne-recompute' => true])->assertSuccessful();

    expect(DB::table('foodalchemist_supplier_item_structures')->where('id', $s1->id)->value('gp_id'))->toBe($anders->id)
        ->and(DB::table('foodalchemist_supplier_item_structures')->where('id', $s2->id)->value('gp_id'))->toBe($ziel->id)
        ->and(DB::table('foodalchemist_gps')->where('id', $quelle->id)->value('status'))->toBe('merged')
        ->and((int) DB::table('foodalchemist_gps')->where('id', $ziel->id)->value('n_las_total'))->toBe(1);

    ($this->lauf)(['--zurueck' => glob($this->dir . '/*_umhaengen_protokoll.json')[0]])->assertSuccessful();

    expect(DB::table('foodalchemist_supplier_item_structures')->where('id', $s2->id)->value('gp_id'))->toBe($quelle->id)
        ->and(DB::table('foodalchemist_supplier_item_structures')->where('id', $s1->id)->value('gp_id'))->toBe($quelle->id)
        ->and(DB::table('foodalchemist_gps')->where('id', $quelle->id)->value('status'))->toBe('approved');
});

it('GP auflösen: Ziel gleich Quelle oder inaktiv ist ein Konflikt', function () {
    $quelle = ($this->gp)('q|', 'Kresse: frisch');
    $tot = ($this->gp)('t|', 'Alte Kresse: frisch', '03', ['status' => 'merged']);
    $plan = ($this->plan)([['gp_aufloesen', '', $quelle->id, $quelle->id, '', '', '', '', ''], ['gp_aufloesen', '', $quelle->id, $tot->id, '', '', '', '', '']]);

    ($this->lauf)(['--plan' => [$plan]])->assertSuccessful()->expectsOutputToContain('Konflikte: 2');
});
