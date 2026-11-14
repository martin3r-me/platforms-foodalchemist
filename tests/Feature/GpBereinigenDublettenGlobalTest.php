<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Models\FoodAlchemistItemAllergen;
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
 * foodalchemist:gp-bereinigen --phase=dubletten --global (GP-Lauf 14.11.2026): Merge auch für GLOBALE Stamm-GPs (nur Master) + Allergen-Guard wie
 * gps.REPLACE (Artikel- und GP-Allergene: enthalten gegen nicht enthalten → Konflikt, kein Merge). Sieger übernimmt Artikel, Rezeptzeilen, Derivate;
 * Lead wird neu gewählt, Zähler neu; --zurueck stellt her.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    config(['foodalchemist.master_team_id' => $this->rootTeam->id]);
    MasterTeam::flush();
    $this->dir = sys_get_temp_dir() . '/gpdg_' . uniqid();
    File::ensureDirectoryExists($this->dir);
    $this->backup = $this->dir . '/b.sql';
    file_put_contents($this->backup, str_repeat('x', 2048));
    $this->sup = FoodAlchemistSupplier::create(['team_id' => null, 'name' => 'Hanos']);

    $this->gp = function (string $name, ?int $team = null, array $extra = []): FoodAlchemistGp {
        $svc = app(GpNamingService::class);
        $gp = FoodAlchemistGp::create(array_merge(['team_id' => $team, 'name' => $name, 'gp_key' => $svc->slugify($name), 'status' => 'approved',
            'commodity_group_code' => '02', 'condition' => 'frisch', 'main_ingredient_slug' => $svc->slugify(strtok($name, ':')),
            'main_ingredient_display' => strtok($name, ':'), 'requires_la' => true], $extra));
        if ($team === null) {
            DB::table('foodalchemist_gps')->where('id', $gp->id)->update(['stamm_class' => StammPaket::PAKET]);
        }

        return $gp->fresh();
    };
    $this->la = function (FoodAlchemistGp $gp, string $bez, array $allergene = []): int {
        $item = FoodAlchemistSupplierItem::create(['team_id' => null, 'supplier_id' => $this->sup->id, 'designation' => $bez, 'article_number' => uniqid(), 'qty' => 1, 'unit_code' => 'kg']);
        FoodAlchemistSupplierItemStructure::create(['supplier_item_id' => $item->id, 'gp_id' => $gp->id]);
        if ($allergene !== []) {
            FoodAlchemistItemAllergen::create(['team_id' => $this->rootTeam->id, 'supplier_item_id' => $item->id] + $allergene);
        }

        return (int) $item->id;
    };
    $this->dubCsv = function (FoodAlchemistGp $sieger, FoodAlchemistGp $verlierer): string {
        $pfad = $this->dir . '/dub_' . uniqid() . '.csv';
        file_put_contents($pfad, "group_id;gp_id;name;keep_proposal;reason\nN0001;{$sieger->id};{$sieger->name};behalten;\nN0001;{$verlierer->id};{$verlierer->name};merge→{$sieger->id};\n");

        return $pfad;
    };
    $this->lauf = fn (array $opt) => $this->artisan('foodalchemist:gp-bereinigen', ['--bericht' => $this->dir, '--phase' => 'dubletten'] + $opt);
    $this->review = fn () => file_get_contents(collect(glob($this->dir . '/*_review_liste.csv'))->last());
});

afterEach(fn () => File::deleteDirectory($this->dir));

it('--global: Trockenlauf schreibt nichts; --apply merged globale GPs (Artikel, Rezeptzeile, Derivat), wählt den Lead neu, --zurueck stellt her', function () {
    $sieger = ($this->gp)('Banane: ganz, frisch');
    $verlierer = ($this->gp)('Bananen: ganz, frisch');
    $la = ($this->la)($verlierer, 'Banane Chiquita');
    DB::table('foodalchemist_gps')->where('id', $verlierer->id)->update(['lead_la_supplier_item_id' => $la, 'n_las_total' => 1]);
    $kind = ($this->gp)('Banane: Schale, frisch', null, ['is_derivat' => true, 'derivat_von_gp_id' => $verlierer->id, 'requires_la' => false]);
    $rezept = $this->makeRecipe($this->rootTeam, 'Bananenbrot');
    $zeile = \Platform\FoodAlchemist\Models\FoodAlchemistRecipeIngredient::create(['team_id' => $this->rootTeam->id, 'recipe_id' => $rezept->id, 'gp_id' => $verlierer->id,
        'raw_text' => '2 Bananen', 'quantity' => '2', 'position' => 1, 'unit_vocab_id' => $this->unitG($this->rootTeam)->id])->id;
    $csv = ($this->dubCsv)($sieger, $verlierer);

    ($this->lauf)(['--global' => true, '--plan-dubletten' => $csv])->assertSuccessful()->expectsOutputToContain('1 Merge-Gruppe(n) ausführbar');
    expect($verlierer->fresh()->status->value)->toBe('approved');

    ($this->lauf)(['--global' => true, '--plan-dubletten' => $csv, '--apply' => true, '--backup-nachweis' => $this->backup, '--ohne-recompute' => true])->assertSuccessful();

    $s = DB::table('foodalchemist_gps')->where('id', $sieger->id)->first();
    expect(DB::table('foodalchemist_gps')->where('id', $verlierer->id)->value('status'))->toBe('merged')
        ->and(DB::table('foodalchemist_supplier_item_structures')->where('supplier_item_id', $la)->value('gp_id'))->toBe($sieger->id)
        ->and((int) DB::table('foodalchemist_recipe_ingredients')->where('id', $zeile)->value('gp_id'))->toBe($sieger->id)
        ->and((int) DB::table('foodalchemist_gps')->where('id', $kind->id)->value('derivat_von_gp_id'))->toBe($sieger->id)
        ->and((int) $s->lead_la_supplier_item_id)->toBe($la)->and((int) $s->n_las_total)->toBe(1)->and($s->team_id)->toBeNull();

    ($this->lauf)(['--zurueck' => collect(glob($this->dir . '/*_protokoll.json'))->last()])->assertSuccessful();
    expect(DB::table('foodalchemist_gps')->where('id', $verlierer->id)->value('status'))->toBe('approved')
        ->and((int) DB::table('foodalchemist_supplier_item_structures')->where('supplier_item_id', $la)->value('gp_id'))->toBe($verlierer->id)
        ->and((int) DB::table('foodalchemist_recipe_ingredients')->where('id', $zeile)->value('gp_id'))->toBe($verlierer->id)
        ->and(DB::table('foodalchemist_gps')->where('id', $sieger->id)->value('lead_la_supplier_item_id'))->toBeNull();
});

it('Allergen-Guard: Verlierer-Artikel „Milch enthalten“ gegen Sieger-Artikel „nicht enthalten“ → Konflikt, kein Merge', function () {
    $sieger = ($this->gp)('Joghurt: frisch');
    $verlierer = ($this->gp)('Joghurts: frisch');
    ($this->la)($sieger, 'Joghurt vegan', ['allergen_milk' => 'nicht_enthalten']);
    $la = ($this->la)($verlierer, 'Joghurt Kuh', ['allergen_milk' => 'enthalten']);

    ($this->lauf)(['--global' => true, '--plan-dubletten' => ($this->dubCsv)($sieger, $verlierer), '--apply' => true, '--backup-nachweis' => $this->backup, '--ohne-recompute' => true])
        ->assertSuccessful()->expectsOutputToContain('0 Merge-Gruppe(n) ausführbar');

    expect($verlierer->fresh()->status->value)->toBe('approved')
        ->and((int) DB::table('foodalchemist_supplier_item_structures')->where('supplier_item_id', $la)->value('gp_id'))->toBe($verlierer->id)
        ->and(($this->review)())->toContain('Allergen-Konflikt')->toContain('Milch');
});

it('Allergen-Guard: GP-Allergen-Override enthalten gegen nicht enthalten → Konflikt; Spuren gegen nicht enthalten ist kein Widerspruch', function () {
    $s1 = ($this->gp)('Senf: mittelscharf, konserviert', null, ['allergen_mustard' => 'enthalten']);
    $v1 = ($this->gp)('Senfe: mittelscharf, konserviert', null, ['allergen_mustard' => 'nicht_enthalten']);
    $s2 = ($this->gp)('Nuss: gehackt, trocken');
    $v2 = ($this->gp)('Nuesse: gehackt, trocken');
    ($this->la)($s2, 'Nuss A', ['allergen_peanuts' => 'spuren']);
    ($this->la)($v2, 'Nuss B', ['allergen_peanuts' => 'nicht_enthalten']);

    ($this->lauf)(['--global' => true, '--plan-dubletten' => ($this->dubCsv)($s1, $v1), '--apply' => true, '--backup-nachweis' => $this->backup, '--ohne-recompute' => true])->assertSuccessful();
    expect($v1->fresh()->status->value)->toBe('approved')->and(($this->review)())->toContain('GP-Allergen');

    ($this->lauf)(['--global' => true, '--plan-dubletten' => ($this->dubCsv)($s2, $v2), '--apply' => true, '--backup-nachweis' => $this->backup, '--ohne-recompute' => true])->assertSuccessful();
    expect($v2->fresh()->status->value)->toBe('merged');
});

it('--global fasst team-eigene GPs nicht an; ohne --global bleiben globale GPs außer Reichweite; Nicht-Master bricht ab', function () {
    $s = ($this->gp)('Kiwi: ganz, frisch', $this->rootTeam->id);
    $v = ($this->gp)('Kiwis: ganz, frisch', $this->rootTeam->id);
    ($this->lauf)(['--global' => true, '--plan-dubletten' => ($this->dubCsv)($s, $v), '--apply' => true, '--backup-nachweis' => $this->backup])->assertSuccessful();
    expect($v->fresh()->status->value)->toBe('approved')->and(($this->review)())->toContain('kein globales Stamm-GP');

    $gs = ($this->gp)('Mango: ganz, frisch');
    $gv = ($this->gp)('Mangos: ganz, frisch');
    ($this->lauf)(['--team' => $this->rootTeam->id, '--plan-dubletten' => ($this->dubCsv)($gs, $gv), '--apply' => true, '--backup-nachweis' => $this->backup])->assertSuccessful();
    expect($gv->fresh()->status->value)->toBe('approved');

    ($this->lauf)(['--global' => true, '--team' => $this->rootTeam->id + 999, '--plan-dubletten' => ($this->dubCsv)($gs, $gv)])->assertFailed()->expectsOutputToContain('nur als Master-Team');
});
