<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplier;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItemStructure;
use Platform\FoodAlchemist\Services\GpNamingService;
use Platform\FoodAlchemist\Support\MasterTeam;
use Platform\FoodAlchemist\Support\StammPaket;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * foodalchemist:gp-umhaengen --global (GP-Lauf 14.11.2026): umhängen, neue GPs (global, stamm_paket; Name aus neu_felder über GpNamingService) und
 * gp_aufloesen auf GLOBALEN Stamm-GPs — nur als Master. Danach Zähler + Lead neu; --zurueck stellt her.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    config(['foodalchemist.master_team_id' => $this->rootTeam->id]);
    MasterTeam::flush();
    $this->dir = sys_get_temp_dir() . '/gpug_' . uniqid();
    File::ensureDirectoryExists($this->dir);
    $this->backup = $this->dir . '/b.sql';
    file_put_contents($this->backup, str_repeat('x', 2048));
    $this->sup = FoodAlchemistSupplier::create(['team_id' => null, 'name' => 'Kluth']);
    if (! DB::table('foodalchemist_lookup_commodity_groups')->where('code', '03')->exists()) {
        DB::table('foodalchemist_lookup_commodity_groups')->insert(['uuid' => (string) \Illuminate\Support\Str::uuid(), 'team_id' => null, 'code' => '03', 'name' => 'Kräuter', 'sort_order' => 3, 'created_at' => now(), 'updated_at' => now()]);
    }

    $this->gp = function (string $name, ?int $team = null): FoodAlchemistGp {
        $svc = app(GpNamingService::class);
        $gp = FoodAlchemistGp::create(['team_id' => $team, 'name' => $name, 'gp_key' => $svc->slugify($name), 'status' => 'approved', 'commodity_group_code' => '03',
            'condition' => 'frisch', 'main_ingredient_slug' => $svc->slugify(strtok($name, ':')), 'main_ingredient_display' => strtok($name, ':'), 'requires_la' => true]);
        if ($team === null) {
            DB::table('foodalchemist_gps')->where('id', $gp->id)->update(['stamm_class' => StammPaket::PAKET]);
        }

        return $gp->fresh();
    };
    $this->la = function (FoodAlchemistGp $gp, string $bez): int {
        $item = FoodAlchemistSupplierItem::create(['team_id' => null, 'supplier_id' => $this->sup->id, 'designation' => $bez, 'article_number' => uniqid(), 'qty' => 1, 'unit_code' => 'kg']);
        FoodAlchemistSupplierItemStructure::create(['supplier_item_id' => $item->id, 'gp_id' => $gp->id]);

        return (int) $item->id;
    };
    $this->plan = function (array $zeilen): string {
        $pfad = $this->dir . '/plan_' . uniqid() . '.csv';
        $h = fopen($pfad, 'wb');
        fputcsv($h, ['aktion', 'supplier_item_id', 'gp_id_quelle', 'ziel_gp_id', 'neu_name', 'neu_wg', 'neu_sub', 'neu_status', 'notiz', 'neu_felder'], ';', '"', '');
        foreach ($zeilen as $z) {
            fputcsv($h, array_pad($z, 10, ''), ';', '"', '');
        }
        fclose($h);

        return $pfad;
    };
    $this->lauf = fn (array $opt) => $this->artisan('foodalchemist:gp-umhaengen', ['--bericht' => $this->dir] + $opt);
    $this->apply = fn (array $opt) => ($this->lauf)($opt + ['--apply' => true, '--backup-nachweis' => $this->backup, '--ohne-recompute' => true]);
    $this->konflikte = fn () => file_get_contents(collect(glob($this->dir . '/*_umhaengen_konflikte.csv'))->last());
});

afterEach(fn () => File::deleteDirectory($this->dir));

it('ohne --global sind globale GPs Konflikt; mit --global als Master wird umgehängt, Lead + Zähler neu, --zurueck stellt her', function () {
    $quelle = ($this->gp)('Kresse: frisch');
    $ziel = ($this->gp)('Honigkresse: frisch');
    $la = ($this->la)($quelle, 'Honey Cress');
    DB::table('foodalchemist_gps')->where('id', $quelle->id)->update(['lead_la_supplier_item_id' => $la, 'n_las_total' => 1]);
    $plan = ($this->plan)([['umhaengen', $la, $quelle->id, $ziel->id]]);

    ($this->apply)(['--team' => $this->rootTeam->id, '--plan' => [$plan]])->expectsConfirmation('Es gibt Konflikte (sie werden NICHT ausgeführt). Trotzdem die klaren Zeilen ausführen?', 'no')->assertFailed();
    expect((int) DB::table('foodalchemist_supplier_item_structures')->where('supplier_item_id', $la)->value('gp_id'))->toBe($quelle->id)
        ->and(($this->konflikte)())->toContain('Bereich');

    ($this->apply)(['--global' => true, '--plan' => [$plan]])->assertSuccessful();
    $z = DB::table('foodalchemist_gps')->where('id', $ziel->id)->first();
    $q = DB::table('foodalchemist_gps')->where('id', $quelle->id)->first();
    expect((int) DB::table('foodalchemist_supplier_item_structures')->where('supplier_item_id', $la)->value('gp_id'))->toBe($ziel->id)
        ->and((int) $z->lead_la_supplier_item_id)->toBe($la)->and((int) $z->n_las_total)->toBe(1)
        ->and($q->lead_la_supplier_item_id)->toBeNull()->and((int) $q->n_las_total)->toBe(0);

    ($this->lauf)(['--zurueck' => collect(glob($this->dir . '/*_umhaengen_protokoll.json'))->last()])->assertSuccessful();
    expect((int) DB::table('foodalchemist_supplier_item_structures')->where('supplier_item_id', $la)->value('gp_id'))->toBe($quelle->id)
        ->and((int) DB::table('foodalchemist_gps')->where('id', $quelle->id)->value('lead_la_supplier_item_id'))->toBe($la);
});

it('neues GP global aus neu_felder: Name über GpNamingService, team_id NULL + stamm_paket, Artikel hängt dran; --zurueck entfernt es', function () {
    $quelle = ($this->gp)('Kresse: frisch');
    $la = ($this->la)($quelle, 'Shiso Kresse rot');
    $felder = json_encode(['main_ingredient_display' => 'Shisokresse', 'flavor' => 'rot', 'condition' => 'frisch', 'piece_default_g' => 30], JSON_UNESCAPED_UNICODE);
    $plan = ($this->plan)([['neues_gp', $la, $quelle->id, '', '', '03', '', 'approved', 'Test', $felder]]);

    ($this->apply)(['--global' => true, '--plan' => [$plan]])->assertSuccessful();

    $erwartet = app(GpNamingService::class)->renderGpName(['hauptzutat' => 'Shisokresse', 'flavor' => 'rot', 'condition' => 'frisch', 'commodity_group_code' => '03']);
    $neu = DB::table('foodalchemist_gps')->where('name', $erwartet)->first();
    expect($neu)->not->toBeNull()->and($neu->team_id)->toBeNull()->and($neu->stamm_class)->toBe(StammPaket::PAKET)
        ->and($neu->status)->toBe('approved')->and($neu->flavor)->toBe('rot')->and((float) $neu->piece_default_g)->toBe(30.0)
        ->and((int) DB::table('foodalchemist_supplier_item_structures')->where('supplier_item_id', $la)->value('gp_id'))->toBe((int) $neu->id)
        ->and((int) $neu->lead_la_supplier_item_id)->toBe($la);

    ($this->lauf)(['--zurueck' => collect(glob($this->dir . '/*_umhaengen_protokoll.json'))->last()])->assertSuccessful();
    expect(DB::table('foodalchemist_gps')->where('id', $neu->id)->exists())->toBeFalse()
        ->and((int) DB::table('foodalchemist_supplier_item_structures')->where('supplier_item_id', $la)->value('gp_id'))->toBe($quelle->id);
});

it('neu_felder: neu_name muss dem gerenderten Namen entsprechen, unzulässige Felder sind Konflikt', function () {
    $quelle = ($this->gp)('Kresse: frisch');
    $l1 = ($this->la)($quelle, 'A');
    $l2 = ($this->la)($quelle, 'B');
    $plan = ($this->plan)([
        ['neues_gp', $l1, $quelle->id, '', 'Shisokresse: grün, frisch', '03', '', '', '', json_encode(['main_ingredient_display' => 'Shisokresse', 'condition' => 'frisch'])],
        ['neues_gp', $l2, $quelle->id, '', '', '03', '', '', '', json_encode(['main_ingredient_display' => 'Shisokresse', 'gp_key' => 'x'])],
    ]);

    ($this->lauf)(['--global' => true, '--plan' => [$plan]])->assertSuccessful();

    expect(($this->konflikte)())->toContain('aus neu_felder gerendert')->toContain('nicht erlaubt')
        ->and(DB::table('foodalchemist_supplier_item_structures')->where('gp_id', $quelle->id)->count())->toBe(2);
});

it('gp_aufloesen global: Rest geht ans Ziel, Quelle merged; team-eigenes Ziel ist im globalen Lauf Konflikt', function () {
    $quelle = ($this->gp)('Kresse: frisch');
    $ziel = ($this->gp)('Gartenkresse: frisch');
    $team = ($this->gp)('Teamkresse: frisch', $this->rootTeam->id);
    $la = ($this->la)($quelle, 'Kresse Schale');

    ($this->lauf)(['--global' => true, '--plan' => [($this->plan)([['gp_aufloesen', '', $quelle->id, $team->id]])]])->assertSuccessful();
    expect($quelle->fresh()->status->value)->toBe('approved')->and(($this->konflikte)())->toContain('Ziel-GP');

    ($this->apply)(['--global' => true, '--plan' => [($this->plan)([['gp_aufloesen', '', $quelle->id, $ziel->id]])]])->assertSuccessful();
    expect($quelle->fresh()->status->value)->toBe('merged')
        ->and((int) DB::table('foodalchemist_supplier_item_structures')->where('supplier_item_id', $la)->value('gp_id'))->toBe($ziel->id)
        ->and((int) DB::table('foodalchemist_gps')->where('id', $ziel->id)->value('lead_la_supplier_item_id'))->toBe($la);
});
