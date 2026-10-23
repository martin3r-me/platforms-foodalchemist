<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Models\FoodAlchemistItemAllergen;
use Platform\FoodAlchemist\Models\FoodAlchemistPrice;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplier;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem;
use Platform\FoodAlchemist\Services\Ai\PoolEmbeddingService;
use Platform\FoodAlchemist\Services\GpAggregateService;
use Platform\FoodAlchemist\Services\KostenCacheService;
use Platform\FoodAlchemist\Support\MasterTeam;
use Platform\FoodAlchemist\Support\StammPaket;
use Platform\FoodAlchemist\Tests\Support\GpPaketBauer as B;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * foodalchemist:gp-paket-import (E2, Plan §8): GP-Paket → io. Synthetische Mini-Pakete, kein Zugriff auf echte Daten.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->master = Team::create(['name' => 'BHG.DIGITAL', 'user_id' => 1, 'personal_team' => false]);
    config(['foodalchemist.master_team_id' => $this->master->id]);
    MasterTeam::flush();
    $this->admin = $this->makeUser($this->master, 'Plattform Admin');
    config(['platform-shell.admins' => [strtolower($this->admin->email)]]);

    $this->dir = sys_get_temp_dir() . '/gpp_' . uniqid();
    $this->ber = sys_get_temp_dir() . '/gpp_ber_' . uniqid();
    File::ensureDirectoryExists($this->dir);
    File::ensureDirectoryExists($this->ber);

    $jetzt = now();
    foreach (['04' => 'Fleisch', '05' => 'Fisch', '11' => 'Öle & Fette', '13' => 'Convenience'] as $code => $name) {
        DB::table('foodalchemist_lookup_commodity_groups')->insert(['uuid' => (string) \Illuminate\Support\Str::uuid(), 'team_id' => null, 'code' => $code, 'name' => $name, 'sort_order' => (int) $code, 'created_at' => $jetzt, 'updated_at' => $jetzt]);
    }
    foreach (['stk' => 'Stück', 'zehe' => 'Zehe'] as $slug => $de) {
        DB::table('foodalchemist_vocab_units')->insert(['uuid' => (string) \Illuminate\Support\Str::uuid(), 'team_id' => null, 'slug' => $slug, 'display_de' => $de, 'sort_order' => 1, 'created_at' => $jetzt, 'updated_at' => $jetzt]);
    }
    DB::table('foodalchemist_vocab_textures')->insert(['slug' => 'fluessig', 'display_de' => 'flüssig', 'sort_order' => 1, 'created_at' => $jetzt, 'updated_at' => $jetzt]);
    foreach (['fresh_eel_fillet', 'lemon', 'dill', 'thyme'] as $slug) {
        DB::table('foodalchemist_vocab_pairing_anchors')->insert(['uuid' => (string) \Illuminate\Support\Str::uuid(), 'team_id' => null, 'slug' => $slug, 'display_de' => $slug, 'created_at' => $jetzt, 'updated_at' => $jetzt]);
    }
    $this->sup = FoodAlchemistSupplier::create(['team_id' => null, 'name' => 'Hanos', 'stamm_class' => 'stamm_paket']);

    /** Globaler Stamm-Artikel mit Necta-legacy_id; $milch = Allergen-Wert (null = keine Zeile); $preis = aktiver Listenpreis. */
    $this->item = function (int $legacy, string $bez = 'Artikel', ?string $milch = null, ?float $preis = null): FoodAlchemistSupplierItem {
        $i = FoodAlchemistSupplierItem::create(['team_id' => null, 'stamm_class' => 'stamm_paket', 'supplier_id' => $this->sup->id, 'designation' => $bez . ' ' . $legacy,
            'article_number' => 'A' . $legacy, 'legacy_id' => $legacy, 'qty' => 1, 'unit_code' => 'kg']);
        if ($milch !== null) {
            FoodAlchemistItemAllergen::create(['team_id' => null, 'supplier_item_id' => $i->id, 'allergen_milk' => $milch]);
        }
        if ($preis !== null) {
            FoodAlchemistPrice::create(['team_id' => null, 'supplier_item_id' => $i->id, 'is_blocked' => false, 'price' => $preis, 'status' => '0']);
        }

        return $i;
    };
    $this->lauf = fn (array $opt = [], int $exit = 0) => $this->artisan('foodalchemist:gp-paket-import', ['--paket' => $this->dir, '--admin' => $this->admin->email, '--bericht' => $this->ber] + $opt)->assertExitCode($exit);
    $this->backup = function (): string {
        $b = $this->ber . '/backup.sql';
        file_put_contents($b, str_repeat('x', 2048));

        return $b;
    };
    $this->apply = fn (array $opt = [], int $exit = 0) => ($this->lauf)(['--apply' => true, '--backup-nachweis' => ($this->backup)()] + $opt, $exit);
    $this->csv = function (string $name): array {
        $dateien = glob($this->ber . "/*_{$name}.csv") ?: [];
        sort($dateien);
        if ($dateien === []) {
            return [];
        }
        $zeilen = array_map(fn ($z) => str_getcsv($z, ';', '"', ''), file(end($dateien), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES));
        $kopf = array_shift($zeilen);
        $kopf[0] = ltrim($kopf[0], "\xEF\xBB\xBF");

        return array_map(fn ($z) => array_combine($kopf, $z), $zeilen);
    };
    $this->kennzahlen = function (): array {
        $dateien = glob($this->ber . '/*_kennzahlen.json') ?: [];
        sort($dateien);

        return json_decode(file_get_contents(end($dateien)), true)['gesamt'];
    };
    $this->protokoll = function (): string {
        $dateien = glob($this->ber . '/*_gp_paket_protokoll.json') ?: [];
        sort($dateien);

        return end($dateien);
    };
    $this->zeilen = fn () => collect(['foodalchemist_gps', 'foodalchemist_supplier_item_structures', 'foodalchemist_gp_forms', 'foodalchemist_gp_textures', 'foodalchemist_gp_count_unit_defaults',
        'foodalchemist_component_equivalents', 'foodalchemist_gp_anchor_mappings'])->mapWithKeys(fn ($t) => [$t => DB::table($t)->count()])->all();
    $this->gp = fn (string $key) => DB::table('foodalchemist_gps')->where('gp_key', $key)->whereNull('team_id')->first();
});

afterEach(function () {
    File::deleteDirectory($this->dir);
    File::deleteDirectory($this->ber);
});

/** Standardpaket: Zander (2 Artikel, einer bepreist = Lead), Lachs (kein Artikel), Platzhalter, Derivat, 2 Warengruppen. */
function standardPaket($t): array
{
    ($t->item)(101, 'Zander', null, 8.5);
    ($t->item)(102, 'Zander', null, 9.0);
    $zander = B::gp('Zanderfilet: frisch');
    $lachs = B::gp('Lachsfilet: frisch', ['commodity_group_code' => 5]);
    $karkasse = B::gp('Zanderkarkasse: frisch', ['is_derivat' => 1, 'requires_la' => 0, 'derivat_von_gp_key' => $zander['gp_key']]);
    $wasser = B::gp('Wasser (neutral)', ['gp_key' => 'PLATZHALTER_WASSER', 'is_platzhalter' => 1, 'requires_la' => 0, 'condition' => null]);

    return [[$wasser, $karkasse, $lachs, $zander], [B::artikel('zanderfilet_frisch', 101, true), B::artikel('zanderfilet_frisch', 102)]];   // Derivat/Platzhalter vor der Mutter: Reihenfolge im Paket egal
}

// ── 1 Dry-Run schreibt nichts ──────────────────────────────────────────────────────────────────────
it('1 Dry-Run schreibt nichts (alle berührten Tabellen unverändert), liefert aber Kennzahlen und Berichte', function () {
    [$gps, $art] = standardPaket($this);
    B::schreibe($this->dir, $gps, $art, ['gp_forms.jsonl' => [['gp_key' => 'zanderfilet_frisch', 'form_slug' => 'stk', 'gramm' => 250.0, 'source' => 'ki']]]);
    $vorher = ($this->zeilen)();

    ($this->lauf)([])->expectsOutputToContain('Trockenlauf — nichts geändert');

    expect(($this->zeilen)())->toBe($vorher);
    $k = ($this->kennzahlen)();
    expect($k['gp_paket'])->toBe(4)->and($k['gp_neu'])->toBe(4)->and($k['gp_neu_approved'])->toBe(3)->and($k['gp_neu_tentative'])->toBe(1)   // Lachs: requires_la, kein Artikel
        ->and($k['v_neu'])->toBe(2)->and($k['struktur_neu'])->toBe(2)->and($k['derivate'])->toBe(1)->and($k['platzhalter'])->toBe(1)
        ->and($k['gp_ohne_artikel'])->toBe(3)->and($k['n_las_erwartet'])->toBe(2)->and($k['kind_neu'])->toBe(1)->and($k['lead_uebernommen'])->toBe(1);
    expect(($this->csv)('gp_key_zu_id'))->toHaveCount(4)->and(glob($this->ber . '/*_gp_paket_protokoll.json'))->toBe([]);
});

it('Dry-Run: --apply ohne Backup-Nachweis, Backup älter als 24 h und fehlende Betriebsart-Kombination werden abgelehnt', function () {
    [$gps, $art] = standardPaket($this);
    B::schreibe($this->dir, $gps, $art);

    ($this->lauf)(['--apply' => true], 1)->expectsOutputToContain('--backup-nachweis');
    $alt = $this->ber . '/alt.sql';
    file_put_contents($alt, str_repeat('x', 2048));
    touch($alt, time() - 3 * 86400);
    ($this->lauf)(['--apply' => true, '--backup-nachweis' => $alt], 1)->expectsOutputToContain('älter als 24 h');
    ($this->lauf)(['--apply' => true, '--verify' => true], 1);
    expect(DB::table('foodalchemist_gps')->count())->toBe(0);
});

// ── 2 Erstlauf legt an ─────────────────────────────────────────────────────────────────────────────
it('2 Erstlauf: GPs global (team_id NULL, stamm_paket), Status nach §13.4, Struktur + gp_id, Lead, n_las_total, Protokoll', function () {
    [$gps, $art] = standardPaket($this);
    B::schreibe($this->dir, $gps, $art, [
        'gp_forms.jsonl' => [['gp_key' => 'zanderfilet_frisch', 'form_slug' => 'stk', 'gramm' => 250.0, 'source' => 'ki']],
        'gp_textures.jsonl' => [['gp_key' => 'lachsfilet_frisch', 'texture_slug' => 'fluessig', 'intensity' => 0.5, 'source' => 'gemini']],
        'gp_einheiten.jsonl' => [['gp_key' => 'zanderfilet_frisch', 'unit_slug' => 'stk', 'default_g' => 300.0, 'is_primary' => 1, 'sort_order' => 0, 'source' => 'manual']],
        'gp_aequivalente.jsonl' => [['source_gp_key' => 'zanderfilet_frisch', 'alt_gp_key' => 'lachsfilet_frisch', 'umrechnungsfaktor' => 0.9, 'standard_seite' => 'source', 'match_confidence' => null, 'notes' => 'Test']],
    ]);

    ($this->apply)([]);

    $z = ($this->gp)('zanderfilet_frisch');
    expect($z->team_id)->toBeNull()->and($z->stamm_class)->toBe('stamm_paket')->and($z->status)->toBe('approved')->and($z->uuid)->toBe($gps[3]['uuid'])
        ->and((int) $z->n_las_total)->toBe(2)->and((int) $z->commodity_group_code)->toBe(4)->and(DB::table('foodalchemist_gps')->where('gp_key', 'zanderfilet_frisch')->value('commodity_group_code'))->toBe('04');
    $strukturen = DB::table('foodalchemist_supplier_item_structures')->where('gp_id', $z->id)->get();
    expect($strukturen)->toHaveCount(2)->and($strukturen->pluck('team_id')->filter()->all())->toBe([])   // global geblieben (verknuepfen() setzt Master — der Importer stellt NULL zurück)
        ->and((int) $z->lead_la_supplier_item_id)->toBe((int) FoodAlchemistSupplierItem::where('legacy_id', 101)->value('id'));
    expect(($this->gp)('lachsfilet_frisch')->status)->toBe('tentative')                                   // requires_la, kein Artikel → vorläufig
        ->and(($this->gp)('PLATZHALTER_WASSER')->status)->toBe('approved')
        ->and(($this->gp)('zanderkarkasse_frisch')->status)->toBe('approved')
        ->and((int) ($this->gp)('zanderkarkasse_frisch')->derivat_von_gp_id)->toBe((int) $z->id)
        ->and(DB::table('foodalchemist_gps')->whereNotNull('team_id')->count())->toBe(0);
    expect(DB::table('foodalchemist_gp_forms')->where('gp_id', $z->id)->value('gramm'))->toEqual(250)
        ->and(DB::table('foodalchemist_gp_textures')->count())->toBe(1)->and(DB::table('foodalchemist_gp_count_unit_defaults')->whereNull('team_id')->count())->toBe(1)
        ->and(DB::table('foodalchemist_component_equivalents')->whereNull('team_id')->where('source_id', $z->id)->count())->toBe(1);
    $p = json_decode(file_get_contents(($this->protokoll)()), true);
    expect($p['gps_neu'])->toHaveCount(4)->and($p['verknuepfungen'])->toHaveCount(2)->and($p['struktur_neu'])->toHaveCount(2)->and($p['gp_stand'])->toHaveCount(4);
});

// ── 3 Idempotenz + verify ──────────────────────────────────────────────────────────────────────────
it('3 Idempotenz: zweiter Lauf = 0 neu / 0 geändert / 0 Verknüpfungen, --verify grün', function () {
    [$gps, $art] = standardPaket($this);
    B::schreibe($this->dir, $gps, $art, ['gp_forms.jsonl' => [['gp_key' => 'zanderfilet_frisch', 'form_slug' => 'stk', 'gramm' => 250.0, 'source' => 'ki']]]);
    ($this->apply)([]);
    $nachErst = ($this->zeilen)();

    ($this->apply)([]);
    $k = ($this->kennzahlen)();
    expect($k['gp_neu'])->toBe(0)->and($k['gp_geaendert'])->toBe(0)->and($k['gp_konflikt'])->toBe(0)->and($k['gp_unveraendert'])->toBe(4)
        ->and($k['v_neu'])->toBe(0)->and($k['v_bestehend'])->toBe(2)->and($k['v_umgehaengt'])->toBe(0)->and($k['kind_neu'])->toBe(0)->and($k['lead_unveraendert'])->toBe(1);
    expect(($this->zeilen)())->toBe($nachErst)->and(DB::table('foodalchemist_gps')->count())->toBe(4);

    ($this->lauf)(['--verify' => true])->expectsOutputToContain('VERIFY grün');
});

it('3b --verify meldet eine fehlende Verknüpfung und ein von Hand gelöschtes GP als Abweichung', function () {
    [$gps, $art] = standardPaket($this);
    B::schreibe($this->dir, $gps, $art);
    ($this->apply)([]);
    DB::table('foodalchemist_supplier_item_structures')->where('supplier_item_id', FoodAlchemistSupplierItem::where('legacy_id', 102)->value('id'))->update(['gp_id' => null]);

    DB::table('foodalchemist_gps')->where('gp_key', 'lachsfilet_frisch')->update(['n_las_total' => 7]);
    ($this->lauf)(['--verify' => true], 1);
    $abw = ($this->csv)('verify_abweichungen');
    expect(collect($abw)->pluck('art')->all())->toContain('Verknüpfung fehlt')->toContain('n_las_total weicht ab');
});

it('3c bestehendes tentative GP wird nie befördert — nur gemeldet, wenn inzwischen ein Artikel hängt', function () {
    $paket = fn () => [[B::gp('Lachsfilet: frisch', ['commodity_group_code' => 5])]];
    B::schreibe($this->dir, $paket()[0], []);
    ($this->apply)([]);
    expect(($this->gp)('lachsfilet_frisch')->status)->toBe('tentative');

    ($this->item)(301, 'Lachs', null, 12.0);
    B::schreibe($this->dir, $paket()[0], [B::artikel('lachsfilet_frisch', 301, true)]);
    ($this->apply)([]);

    expect(($this->gp)('lachsfilet_frisch')->status)->toBe('tentative')->and((int) ($this->gp)('lachsfilet_frisch')->n_las_total)->toBe(1);
    expect(($this->kennzahlen)()['tentative_nun_mit_artikel'])->toBe(1)->and(($this->csv)('tentative_nun_mit_artikel'))->toHaveCount(1);
});

// ── Konflikt: Hand-Änderung nach dem letzten Lauf ──────────────────────────────────────────────────
it('Hand-Änderung nach dem Lauf wird nie überschrieben (Konfliktliste), unberührte GPs werden aktualisiert', function () {
    B::schreibe($this->dir, [B::gp('Zanderfilet: frisch', ['main_ingredient_display' => 'Zander']), B::gp('Lachsfilet: frisch', ['commodity_group_code' => 5, 'main_ingredient_display' => 'Lachs'])], []);
    ($this->apply)([]);
    DB::table('foodalchemist_gps')->where('gp_key', 'zanderfilet_frisch')->update(['main_ingredient_display' => 'Zander (von Hand)']);   // nach dem Lauf von Hand

    B::schreibe($this->dir, [B::gp('Zanderfilet: frisch', ['main_ingredient_display' => 'Zanderfilet']), B::gp('Lachsfilet: frisch', ['commodity_group_code' => 5, 'main_ingredient_display' => 'Lachsfilet'])], []);
    ($this->apply)([]);

    expect(($this->gp)('zanderfilet_frisch')->main_ingredient_display)->toBe('Zander (von Hand)')      // Konflikt: nicht überschrieben
        ->and(($this->gp)('lachsfilet_frisch')->main_ingredient_display)->toBe('Lachsfilet');            // frei: aktualisiert
    $k = ($this->kennzahlen)();
    expect($k['gp_konflikt'])->toBe(1)->and($k['gp_geaendert'])->toBe(1);
    expect(collect(($this->csv)('konflikte'))->pluck('feld')->all())->toBe(['main_ingredient_display']);
});

it('ohne Vorlauf-Protokoll wird nichts Bestehendes überschrieben (alles Konflikt)', function () {
    B::schreibe($this->dir, [B::gp('Zanderfilet: frisch', ['main_ingredient_display' => 'Zander'])], []);
    ($this->apply)([]);
    foreach (glob($this->ber . '/*_gp_paket_protokoll.json') as $f) {
        unlink($f);
    }
    B::schreibe($this->dir, [B::gp('Zanderfilet: frisch', ['main_ingredient_display' => 'Zanderfilet'])], []);
    ($this->apply)([]);

    expect(($this->gp)('zanderfilet_frisch')->main_ingredient_display)->toBe('Zander')->and(($this->kennzahlen)()['gp_konflikt'])->toBe(1);
});

// ── 4 Match über legacy_id ─────────────────────────────────────────────────────────────────────────
it('4 legacy_id-Match: Treffer, kein Treffer, soft-gelöscht, mehrdeutig im Paket — nie über Namen', function () {
    ($this->item)(101, 'Zander', null, 8.5);
    $weg = ($this->item)(104, 'Zander');
    $weg->delete();                                                                   // soft-gelöscht
    ($this->item)(105, 'Zander');
    B::schreibe($this->dir, [B::gp('Zanderfilet: frisch'), B::gp('Hechtfilet: frisch')], [
        B::artikel('zanderfilet_frisch', 101, true), B::artikel('zanderfilet_frisch', 103), B::artikel('zanderfilet_frisch', 104),
        B::artikel('zanderfilet_frisch', 105), B::artikel('hechtfilet_frisch', 105),                                  // 105 steht zweimal im Paket
    ]);
    ($this->apply)([]);

    $k = ($this->kennzahlen)();
    expect($k['v_neu'])->toBe(1)->and($k['v_ohne_treffer'])->toBe(2)->and($k['v_mehrdeutig'])->toBe(2);
    $liste = collect(($this->csv)('review_ohne_match'));
    expect($liste->pluck('legacy_id')->map(fn ($v) => (int) $v)->sort()->values()->all())->toBe([103, 104, 105, 105])
        ->and($liste->firstWhere('legacy_id', '103')['grund'])->toContain('gibt es auf io nicht')->and($liste->firstWhere('legacy_id', '104')['grund'])->toContain('soft-gelöscht');
    expect((int) ($this->gp)('zanderfilet_frisch')->n_las_total)->toBe(1)
        ->and(DB::table('foodalchemist_supplier_item_structures')->where('supplier_item_id', FoodAlchemistSupplierItem::where('legacy_id', 105)->value('id'))->exists())->toBeFalse();
});

it('4b Artikel an anderem GP: ohne --umhaengen übersprungen und gemeldet, mit --umhaengen umgehängt', function () {
    $i = ($this->item)(101, 'Zander', null, 8.5);
    $alt = FoodAlchemistGp::create(['team_id' => null, 'stamm_class' => 'stamm_paket', 'gp_key' => 'alt_gp', 'name' => 'Alt: frisch', 'status' => 'approved', 'commodity_group_code' => '04', 'requires_la' => true]);
    DB::table('foodalchemist_supplier_item_structures')->insert(['uuid' => (string) \Illuminate\Support\Str::uuid(), 'team_id' => null, 'supplier_item_id' => $i->id, 'gp_id' => $alt->id, 'created_at' => now(), 'updated_at' => now()]);
    B::schreibe($this->dir, [B::gp('Zanderfilet: frisch')], [B::artikel('zanderfilet_frisch', 101, true)]);

    ($this->apply)([]);
    $k = ($this->kennzahlen)();
    expect($k['v_umhaengen_noetig'])->toBe(1)->and($k['v_uebersprungen'])->toBe(1)->and($k['v_umgehaengt'])->toBe(0)
        ->and((int) DB::table('foodalchemist_supplier_item_structures')->where('supplier_item_id', $i->id)->value('gp_id'))->toBe((int) $alt->id);

    ($this->apply)(['--umhaengen' => true]);
    expect((int) DB::table('foodalchemist_supplier_item_structures')->where('supplier_item_id', $i->id)->value('gp_id'))->toBe((int) ($this->gp)('zanderfilet_frisch')->id)
        ->and(($this->kennzahlen)()['v_umgehaengt'])->toBe(1);
});

// ── 5 Derivate ─────────────────────────────────────────────────────────────────────────────────────
it('5 Derivate: Mutter vor Kind; ohne Mutter / Derivat von Derivat / requires_la=1 werden abgelehnt; Allergene live von der Mutter, Override-Spalten NULL', function () {
    ($this->item)(101, 'Zander', 'enthalten', 8.5);
    $mutter = B::gp('Zanderfilet: frisch');
    $kind = B::gp('Zanderkarkasse: frisch', ['is_derivat' => 1, 'requires_la' => 0, 'derivat_von_gp_key' => 'zanderfilet_frisch']);
    $enkel = B::gp('Zanderfond: frisch', ['is_derivat' => 1, 'requires_la' => 0, 'derivat_von_gp_key' => 'zanderkarkasse_frisch']);
    $waise = B::gp('Hechtkarkasse: frisch', ['is_derivat' => 1, 'requires_la' => 0, 'derivat_von_gp_key' => 'hechtfilet_frisch']);
    $falsch = B::gp('Zanderhaut: frisch', ['is_derivat' => 1, 'requires_la' => 1, 'derivat_von_gp_key' => 'zanderfilet_frisch']);
    B::schreibe($this->dir, [$kind, $enkel, $waise, $falsch, $mutter], [B::artikel('zanderfilet_frisch', 101, true)]);

    ($this->apply)([]);

    $abg = collect(($this->csv)('gp_abgelehnt'))->keyBy('gp_key');
    expect($abg->keys()->sort()->values()->all())->toBe(['hechtkarkasse_frisch', 'zanderfond_frisch', 'zanderhaut_frisch'])
        ->and($abg['zanderfond_frisch']['code'])->toBe('derivat_von_derivat')->and($abg['hechtkarkasse_frisch']['code'])->toBe('mutter_fehlt')->and($abg['zanderhaut_frisch']['code'])->toBe('derivat_requires_la');
    $k = ($this->gp)('zanderkarkasse_frisch');
    $m = ($this->gp)('zanderfilet_frisch');
    expect((int) $k->derivat_von_gp_id)->toBe((int) $m->id)->and((bool) $k->is_derivat)->toBeTrue()->and($k->allergen_milk)->toBeNull()->and($m->allergen_milk)->toBeNull();
    $live = app(GpAggregateService::class)->allergene(FoodAlchemistGp::ohneTeamSicht('test')->find($k->id));
    expect($live['milk']['value']->value)->toBe('enthalten')->and($live['milk']['source'])->toBe('mutter');
});

// ── 6 Guard / Master-Kontext / Namen ───────────────────────────────────────────────────────────────
it('6 Allergen-Guard: abweichender Artikel wird NICHT verknüpft, kommt in guard_abgelehnt.csv (Vorschlag „neues GP“), nichts wird umgehängt oder angelegt; Dry-Run sagt dasselbe', function () {
    ($this->item)(101, 'Sahne', 'enthalten', 3.0);
    ($this->item)(102, 'Sahne laktosefrei', 'nicht_enthalten', 3.5);
    ($this->item)(103, 'Sahne Spuren', 'spuren', 3.2);                               // Spuren = nicht enthalten → widerspricht dem ersten (enthalten)
    B::schreibe($this->dir, [B::gp('Sahne: frisch', ['commodity_group_code' => 13])], [
        B::artikel('sahne_frisch', 101, true), B::artikel('sahne_frisch', 102), B::artikel('sahne_frisch', 103),
    ]);

    ($this->lauf)([]);
    $dry = ($this->kennzahlen)();
    expect($dry['v_neu'])->toBe(1)->and($dry['v_guard_abgelehnt'])->toBe(2);

    ($this->apply)([]);
    $k = ($this->kennzahlen)();
    expect($k['v_guard_abgelehnt'])->toBe(2)->and(DB::table('foodalchemist_gps')->count())->toBe(1)                // kein neues GP automatisch
        ->and((int) ($this->gp)('sahne_frisch')->n_las_total)->toBe(1);
    $g = ($this->csv)('guard_abgelehnt');
    expect($g)->toHaveCount(2)->and($g[0]['gp_key'])->toBe('sahne_frisch')->and($g[0]['abweichungen'])->toContain('Milch')->and($g[0]['vorschlag'])->toContain('neues GP')
        ->and(collect($g)->pluck('legacy_id')->map(fn ($v) => (int) $v)->sort()->values()->all())->toBe([102, 103]);
    expect(DB::table('foodalchemist_supplier_item_structures')->whereNotNull('gp_id')->count())->toBe(1);
});

it('6b Reihenfolge ist fest (Lead zuerst, dann legacy_id): der Lead legt das Profil fest, nicht die Paket-Zeilenfolge', function () {
    ($this->item)(101, 'Sahne', 'nicht_enthalten', 3.0);
    ($this->item)(102, 'Sahne mit Milch', 'enthalten', 3.5);
    B::schreibe($this->dir, [B::gp('Sahne: frisch', ['commodity_group_code' => 13])], [B::artikel('sahne_frisch', 102), B::artikel('sahne_frisch', 101, true)]);   // Lead (101) steht hinten

    ($this->apply)([]);

    expect(collect(($this->csv)('guard_abgelehnt'))->pluck('legacy_id')->all())->toBe(['102'])
        ->and((int) ($this->gp)('sahne_frisch')->lead_la_supplier_item_id)->toBe((int) FoodAlchemistSupplierItem::where('legacy_id', 101)->value('id'));
});

it('6c Master-Kontext: ohne Master-Team, ohne --admin, mit Nicht-Admin und ohne Backup bricht der Befehl hart ab, nichts wird geschrieben', function () {
    [$gps, $art] = standardPaket($this);
    B::schreibe($this->dir, $gps, $art);

    $this->artisan('foodalchemist:gp-paket-import', ['--paket' => $this->dir, '--bericht' => $this->ber])->assertExitCode(1)->expectsOutputToContain('--admin');
    $normal = $this->makeUser($this->rootTeam, 'Normaler Nutzer');
    $this->artisan('foodalchemist:gp-paket-import', ['--paket' => $this->dir, '--admin' => $normal->email, '--bericht' => $this->ber])->assertExitCode(1);
    config(['foodalchemist.master_team_id' => null]);
    MasterTeam::flush();
    $this->artisan('foodalchemist:gp-paket-import', ['--paket' => $this->dir, '--admin' => $this->admin->email, '--bericht' => $this->ber])->assertExitCode(1)->expectsOutputToContain('Master-Team');
    expect(DB::table('foodalchemist_gps')->count())->toBe(0);
});

it('6d stamm_paket gibt es nur global (Guard im Modell) und ungültige Namen/Zustand/Warengruppe/Sub/Einheit lehnen nur die Zeile ab', function () {
    expect(fn () => FoodAlchemistGp::create(['team_id' => $this->rootTeam->id, 'stamm_class' => 'stamm_paket', 'gp_key' => 'x', 'name' => 'X']))->toThrow(InvalidArgumentException::class);

    B::schreibe($this->dir, [
        B::gp('Zanderfilet: frisch'),
        B::gp('Zander (1 kg) Gebinde', ['gp_key' => 'zander_1_kg_gebinde']),                        // §7.1 Verpackungswort
        B::gp('Lachsfilet: frisch', ['condition' => 'gefroren', 'commodity_group_code' => 5]),    // Zustand nicht im §9-Vokabular
        B::gp('Hechtfilet: frisch', ['commodity_group_code' => 99]),                              // Warengruppe unbekannt
        B::gp('Barschfilet: frisch', ['sub_category' => '04.9 Gibt es nicht']),                   // Sub unbekannt
        B::gp('Forellenfilet: frisch', ['preferred_count_unit_slug' => 'nix']),                   // Einheit unbekannt
        B::gp('Karpfenfilet: frisch', ['gp_key' => 'falscher_schluessel']),                       // Key nicht re-slugify-stabil
        B::gp('Zanderbacke: frisch', ['status' => 'review']),                                     // Status nicht importierbar
    ], []);

    ($this->apply)([]);

    $abg = collect(($this->csv)('gp_abgelehnt'))->pluck('code', 'gp_key')->all();
    expect($abg)->toBe(['zander_1_kg_gebinde' => 'name_ungueltig', 'lachsfilet_frisch' => 'zustand', 'hechtfilet_frisch' => 'wg_unbekannt', 'barschfilet_frisch' => 'sub_unbekannt',
        'forellenfilet_frisch' => 'einheit_unbekannt', 'falscher_schluessel' => 'key_instabil', 'zanderbacke_frisch' => 'status'])
        ->and(DB::table('foodalchemist_gps')->pluck('gp_key')->all())->toBe(['zanderfilet_frisch']);
});

it('Sub-Kategorie löst als verwalteter Tabellenname und als gespeicherter Bestandswert „NN.N Name“ auf', function () {
    DB::table('foodalchemist_commodity_group_subcategories')->insert(['uuid' => (string) \Illuminate\Support\Str::uuid(), 'team_id' => null, 'commodity_group_code' => '04', 'name' => 'Rind & Kalb', 'position' => 1, 'created_at' => now(), 'updated_at' => now()]);
    B::schreibe($this->dir, [B::gp('Rinderfilet: frisch', ['sub_category' => '04.1 Rind & Kalb']), B::gp('Kalbsfilet: frisch', ['sub_category' => 'Rind & Kalb']), B::gp('Schweinefilet: frisch', ['sub_category' => '04.2 Schwein'])], []);

    ($this->apply)([]);

    expect(DB::table('foodalchemist_gps')->pluck('gp_key')->sort()->values()->all())->toBe(['kalbsfilet_frisch', 'rinderfilet_frisch'])
        ->and(collect(($this->csv)('gp_abgelehnt'))->pluck('gp_key')->all())->toBe(['schweinefilet_frisch']);
});

it('Dubletten im Paket (Name umlaut-normalisiert) und Namensdublette zu einem GP auf io werden abgelehnt', function () {
    FoodAlchemistGp::create(['team_id' => null, 'stamm_class' => 'stamm_paket', 'gp_key' => 'schon_da', 'name' => 'Brühe: trocken', 'status' => 'approved', 'commodity_group_code' => '04']);
    B::schreibe($this->dir, [B::gp('Müsli: trocken', ['gp_key' => 'muesli_trocken']), B::gp('Muesli: trocken', ['gp_key' => 'muesli|trocken|']), B::gp('Bruehe: trocken', ['gp_key' => 'bruehe_trocken'])], []);

    ($this->apply)([]);

    expect(collect(($this->csv)('gp_abgelehnt'))->pluck('code', 'gp_key')->sortKeys()->all())->toBe(['bruehe_trocken' => 'name_vergeben', 'muesli_trocken' => 'name_doppelt', 'muesli|trocken|' => 'name_doppelt']);
});

// ── 7 Sichtbarkeit (Leck-Test) ─────────────────────────────────────────────────────────────────────
it('7 Sichtbarkeit: Mandant ohne Opt-in sieht 0 GPs, mit Opt-in die GPs (Artikel nur freigegebener Lieferanten), der Master sieht alles', function () {
    [$gps, $art] = standardPaket($this);
    B::schreibe($this->dir, $gps, $art);
    ($this->apply)([]);

    expect(FoodAlchemistGp::visibleToTeam($this->rootTeam)->count())->toBe(0)
        ->and(FoodAlchemistGp::visibleToTeam($this->childA)->count())->toBe(0)
        ->and(FoodAlchemistGp::visibleToTeam($this->master)->count())->toBe(4);

    StammPaket::freischalten($this->rootTeam);
    expect(FoodAlchemistGp::visibleToTeam($this->rootTeam)->count())->toBe(4)
        ->and(FoodAlchemistGp::visibleToTeam($this->childA)->count())->toBe(4)                 // Unter-Teams erben das Paket
        ->and(FoodAlchemistSupplierItem::visibleToTeam($this->rootTeam)->count())->toBe(0);    // Lieferant nicht freigegeben → keine Artikel
    $this->alleLieferantenFrei($this->rootTeam);
    expect(FoodAlchemistSupplierItem::visibleToTeam($this->rootTeam)->count())->toBe(2)
        ->and(FoodAlchemistSupplierItem::visibleToTeam($this->master)->count())->toBe(2);
});

// ── 8 Lead ─────────────────────────────────────────────────────────────────────────────────────────
it('8 Lead: Paket-Lead mit Preis übernommen, sonst neu gewählt, Lead aus Artikeln ohne Preis wird nie gesetzt (Park)', function () {
    ($this->item)(101, 'Zander', null, 8.5);              // Paket-Lead, bepreist → übernommen
    ($this->item)(201, 'Lachs', null, null);               // Paket-Lead OHNE Preis …
    ($this->item)(202, 'Lachs', null, 7.0);                // … anderer Artikel bepreist → neu gewählt
    ($this->item)(301, 'Hecht', null, null);               // nichts bepreist → Park
    B::schreibe($this->dir, [B::gp('Zanderfilet: frisch'), B::gp('Lachsfilet: frisch', ['commodity_group_code' => 5]), B::gp('Hechtfilet: frisch')], [
        B::artikel('zanderfilet_frisch', 101, true), B::artikel('lachsfilet_frisch', 201, true), B::artikel('lachsfilet_frisch', 202), B::artikel('hechtfilet_frisch', 301, true),
    ]);

    ($this->apply)([]);

    $id = fn (int $legacy) => (int) FoodAlchemistSupplierItem::where('legacy_id', $legacy)->value('id');
    expect((int) ($this->gp)('zanderfilet_frisch')->lead_la_supplier_item_id)->toBe($id(101))
        ->and((int) ($this->gp)('lachsfilet_frisch')->lead_la_supplier_item_id)->toBe($id(202))
        ->and(($this->gp)('hechtfilet_frisch')->lead_la_supplier_item_id)->toBeNull();
    $k = ($this->kennzahlen)();
    expect($k['lead_uebernommen'])->toBe(1)->and($k['lead_neu'])->toBe(1)->and($k['lead_park'])->toBe(1);
    expect(collect(($this->csv)('lead_park'))->pluck('gp_key')->all())->toBe(['hechtfilet_frisch']);
    // Hecht hat einen verknüpften Artikel → bleibt approved (§13.4: Artikel-Treffer), nur der Lead parkt
    expect(($this->gp)('hechtfilet_frisch')->status)->toBe('approved');
});

// ── 9 Rückweg ──────────────────────────────────────────────────────────────────────────────────────
it('9 Rückweg: --zurueck löst Verknüpfungen, entfernt Struktur-Zeilen und GPs des Laufs (SoftDelete), stellt umgehängte Artikel zurück', function () {
    [$gps, $art] = standardPaket($this);                                              // legt die Artikel 101/102 an
    $i = FoodAlchemistSupplierItem::where('legacy_id', 101)->first();
    $alt = FoodAlchemistGp::create(['team_id' => null, 'stamm_class' => 'stamm_paket', 'gp_key' => 'alt_gp', 'name' => 'Alt: frisch', 'status' => 'approved', 'commodity_group_code' => '04', 'requires_la' => true, 'n_las_total' => 1, 'lead_la_supplier_item_id' => $i->id]);
    DB::table('foodalchemist_supplier_item_structures')->insert(['uuid' => (string) \Illuminate\Support\Str::uuid(), 'team_id' => null, 'supplier_item_id' => $i->id, 'gp_id' => $alt->id, 'created_at' => now(), 'updated_at' => now()]);
    B::schreibe($this->dir, $gps, $art, ['gp_forms.jsonl' => [['gp_key' => 'zanderfilet_frisch', 'form_slug' => 'stk', 'gramm' => 250.0, 'source' => 'ki']]]);
    $vorher = ($this->zeilen)();
    ($this->apply)(['--umhaengen' => true]);
    expect((int) DB::table('foodalchemist_supplier_item_structures')->where('supplier_item_id', $i->id)->value('gp_id'))->not->toBe((int) $alt->id);

    $this->artisan('foodalchemist:gp-paket-import', ['--zurueck' => ($this->protokoll)(), '--admin' => $this->admin->email, '--bericht' => $this->ber])->assertExitCode(0)->expectsOutputToContain('Zurückgenommen');

    expect((int) DB::table('foodalchemist_supplier_item_structures')->where('supplier_item_id', $i->id)->value('gp_id'))->toBe((int) $alt->id)
        ->and(DB::table('foodalchemist_supplier_item_structures')->count())->toBe(1)                          // Struktur-Zeilen des Laufs weg
        ->and(DB::table('foodalchemist_gps')->whereNull('deleted_at')->pluck('gp_key')->all())->toBe(['alt_gp'])   // GPs des Laufs weich gelöscht
        ->and(DB::table('foodalchemist_gps')->whereNotNull('deleted_at')->count())->toBe(4)
        ->and((int) DB::table('foodalchemist_gps')->where('id', $alt->id)->value('n_las_total'))->toBe(1)
        ->and((int) DB::table('foodalchemist_gps')->where('id', $alt->id)->value('lead_la_supplier_item_id'))->toBe((int) $i->id)
        ->and(DB::table('foodalchemist_gp_forms')->count())->toBe($vorher['foodalchemist_gp_forms']);
});

it('9b Rückweg bricht ab (und ändert nichts), sobald an einem neuen GP etwas Neues hängt', function () {
    [$gps, $art] = standardPaket($this);
    B::schreibe($this->dir, $gps, $art);
    ($this->apply)([]);
    $z = ($this->gp)('zanderfilet_frisch');
    DB::table('foodalchemist_gp_la_preferences')->insert(['uuid' => (string) \Illuminate\Support\Str::uuid(), 'team_id' => $this->rootTeam->id, 'gp_id' => $z->id,
        'supplier_item_id' => FoodAlchemistSupplierItem::where('legacy_id', 101)->value('id'), 'created_at' => now(), 'updated_at' => now()]);
    $nachLauf = ($this->zeilen)();

    $this->artisan('foodalchemist:gp-paket-import', ['--zurueck' => ($this->protokoll)(), '--admin' => $this->admin->email, '--bericht' => $this->ber])
        ->assertExitCode(1)->expectsOutputToContain('Rückweg abgebrochen');

    expect(($this->zeilen)())->toBe($nachLauf)->and(DB::table('foodalchemist_gps')->whereNull('deleted_at')->count())->toBe(4);
});

// ── 10 Whitelist / Manifest ────────────────────────────────────────────────────────────────────────
it('10 Whitelist: verbotene (allergen_*, nutri_*, ai_*) und unbekannte Spalten lehnen das GANZE Paket ab; nichts geplant, nichts geschrieben', function () {
    $vorher = ($this->zeilen)();
    B::schreibe($this->dir, [B::gp('Zanderfilet: frisch', ['allergen_milk' => 'enthalten', 'nutri_kcal_per_100g' => 100, 'ai_reasoning' => 'x', 'foo' => 1])], []);

    ($this->lauf)([], 1)->expectsOutputToContain('PAKET ABGELEHNT')->expectsOutputToContain('VERBOTENE Spalte „allergen_milk“')->expectsOutputToContain('unbekannte Spalte „foo“');
    ($this->apply)([], 1);

    expect(($this->zeilen)())->toBe($vorher);
    $g = collect(($this->csv)('paket_abgelehnt'))->pluck('grund')->implode(' | ');
    expect($g)->toContain('allergen_milk')->toContain('nutri_kcal_per_100g')->toContain('ai_reasoning');
});

it('10b Manifest: verfälschte Datei (SHA256), doppelter gp_key und unbekannte Struktur-Spalte lehnen das Paket ab', function () {
    B::schreibe($this->dir, [B::gp('Zanderfilet: frisch')], []);
    B::verfaelsche($this->dir, 'gps.jsonl');
    ($this->lauf)([], 1)->expectsOutputToContain('SHA256');

    B::schreibe($this->dir, [B::gp('Zanderfilet: frisch'), B::gp('Zanderfilet: frisch')], []);
    ($this->lauf)([], 1)->expectsOutputToContain('gp_key doppelt im Paket');

    B::schreibe($this->dir, [B::gp('Zanderfilet: frisch')], [B::artikel('zanderfilet_frisch', 1, true, ['allergen_milk' => 'enthalten'])]);
    ($this->lauf)([], 1)->expectsOutputToContain('allergen_milk');
    expect(DB::table('foodalchemist_gps')->count())->toBe(0);
});

// ── 11 Kein Recompute-/Embedding-Sturm ─────────────────────────────────────────────────────────────
it('11 Massenlauf löst keine Embedding-Jobs/Observer aus, keinen Recompute je GP — und genau EINEN gesammelten Lauf für geänderte Leads bestehender GPs', function () {
    Queue::fake();
    $embed = $this->mock(PoolEmbeddingService::class)->shouldIgnoreMissing();   // Artikel-Observer (Fixture) darf; GP-Pfade nicht
    $embed->shouldNotReceive('queueGp');
    $embed->shouldNotReceive('deleteGp');
    [$gps, $art] = standardPaket($this);
    ($this->item)(401, 'Hecht', null, null);
    $gps[] = B::gp('Hechtfilet: frisch');
    $art[] = B::artikel('hechtfilet_frisch', 401, true);
    B::schreibe($this->dir, $gps, $art);

    $kosten = $this->mock(KostenCacheService::class);
    $kosten->shouldNotReceive('markiereVeraltet');
    ($this->apply)([]);
    Queue::assertNothingPushed();
    expect(($this->gp)('hechtfilet_frisch')->lead_la_supplier_item_id)->toBeNull();       // Park, nichts gerechnet

    // Preis ist da → Lauf 2 wählt für den BESTEHENDEN GP einen Lead: genau ein gesammelter Kosten-Cache-Lauf
    FoodAlchemistPrice::create(['team_id' => null, 'supplier_item_id' => FoodAlchemistSupplierItem::where('legacy_id', 401)->value('id'), 'is_blocked' => false, 'price' => 5.0, 'status' => '0']);
    $kosten2 = $this->mock(KostenCacheService::class);
    $kosten2->shouldReceive('rezepteMitGps')->once()->andReturn([]);
    $kosten2->shouldReceive('markiereVeraltet')->once()->andReturn(0);
    ($this->apply)([]);
    expect((int) ($this->gp)('hechtfilet_frisch')->lead_la_supplier_item_id)->toBe((int) FoodAlchemistSupplierItem::where('legacy_id', 401)->value('id'));
    Queue::assertNothingPushed();
});

// ── 12 Teillauf ────────────────────────────────────────────────────────────────────────────────────
it('12 --nur-wg berührt nur diese Warengruppe (GPs, Artikel, Kind-Zeilen, Anker)', function () {
    [$gps, $art] = standardPaket($this);
    ($this->item)(201, 'Lachs', null, 7.0);
    $art[] = B::artikel('lachsfilet_frisch', 201, true);
    B::schreibe($this->dir, $gps, $art, [], [
        ['gp_key' => 'zanderfilet_frisch', 'anchor_slug' => 'lemon', 'role' => 'kern', 'source' => 'inspire_katalog', 'ai_confidence' => 0.8, 'ai_reasoning' => 'x'],
        ['gp_key' => 'lachsfilet_frisch', 'anchor_slug' => 'dill', 'role' => 'kern', 'source' => 'inspire_katalog', 'ai_confidence' => 0.8, 'ai_reasoning' => 'x'],
    ]);

    ($this->apply)(['--nur-wg' => '05']);

    expect(DB::table('foodalchemist_gps')->pluck('gp_key')->all())->toBe(['lachsfilet_frisch'])
        ->and(DB::table('foodalchemist_supplier_item_structures')->count())->toBe(1)
        ->and(DB::table('foodalchemist_gp_anchor_mappings')->count())->toBe(1)
        ->and((int) ($this->gp)('lachsfilet_frisch')->n_las_total)->toBe(1);
    expect(($this->kennzahlen)()['gp_paket'])->toBe(1);

    ($this->apply)(['--nur-wg' => '4']);                                                          // „4“ = „04“; Derivat/Platzhalter haben WG 4 bzw. 5 → Mutter da
    expect(DB::table('foodalchemist_gps')->whereNull('deleted_at')->count())->toBe(4)
        ->and(DB::table('foodalchemist_gps')->where('gp_key', 'lachsfilet_frisch')->count())->toBe(1);
});

// ── Anker ──────────────────────────────────────────────────────────────────────────────────────────
it('Anker-Phase: setzt über den PairingService (CAP 3, ersetze_alle), lehnt unbekannte Slugs/zu lange source ab, ist idempotent; --ohne-anker überspringt; gp_key_zu_id.csv', function () {
    ($this->item)(101, 'Zander', null, 8.5);
    $anker = [
        ['gp_key' => 'zanderfilet_frisch', 'anchor_slug' => 'lemon', 'role' => 'kern', 'source' => 'inspire_katalog', 'ai_confidence' => 0.9, 'ai_reasoning' => 'Zitrone'],
        ['gp_key' => 'zanderfilet_frisch', 'anchor_slug' => 'dill', 'role' => 'neben', 'source' => 'inspire_behelf', 'ai_confidence' => 0.6, 'ai_reasoning' => null],
        ['gp_key' => 'zanderfilet_frisch', 'anchor_slug' => 'thyme', 'role' => 'neben', 'source' => 'inspire_behelf', 'ai_confidence' => 0.5, 'ai_reasoning' => null],
        ['gp_key' => 'zanderfilet_frisch', 'anchor_slug' => 'fresh_eel_fillet', 'role' => 'neben', 'source' => 'inspire_behelf', 'ai_confidence' => 0.5, 'ai_reasoning' => null],   // 4. → CAP_GP 3
        ['gp_key' => 'zanderfilet_frisch', 'anchor_slug' => 'gibt_es_nicht', 'role' => 'kern', 'source' => 'x', 'ai_confidence' => 0.5, 'ai_reasoning' => null],
        ['gp_key' => 'lachsfilet_frisch', 'anchor_slug' => 'lemon', 'role' => 'kern', 'source' => str_repeat('s', 40), 'ai_confidence' => 0.5, 'ai_reasoning' => null],
        ['gp_key' => 'gibt_es_nicht_frisch', 'anchor_slug' => 'lemon', 'role' => 'kern', 'source' => 'x', 'ai_confidence' => 0.5, 'ai_reasoning' => null],
    ];
    B::schreibe($this->dir, [B::gp('Zanderfilet: frisch'), B::gp('Lachsfilet: frisch', ['commodity_group_code' => 5])], [B::artikel('zanderfilet_frisch', 101, true)], [], $anker);

    ($this->lauf)(['--ohne-anker' => true]);
    expect(($this->kennzahlen)()['anker_gps'])->toBe(0);

    ($this->apply)([]);
    $zId = (int) ($this->gp)('zanderfilet_frisch')->id;
    expect(DB::table('foodalchemist_gp_anchor_mappings')->where('gp_id', $zId)->whereNull('deleted_at')->count())->toBe(3);
    $k = ($this->kennzahlen)();
    expect($k['anker_gps'])->toBe(1)->and($k['anker_neu'])->toBe(3)->and($k['anker_abgelehnt'])->toBe(4);
    expect(collect(($this->csv)('anker_abgelehnt'))->pluck('grund')->implode(' | '))->toContain('Slug unbekannt')->toContain('source zu lang')->toContain('GP nicht im Paket')->toContain('Limit erreicht');
    $zuId = collect(($this->csv)('gp_key_zu_id'))->pluck('gp_id', 'gp_key');
    expect((int) $zuId['zanderfilet_frisch'])->toBe($zId)->and($zuId)->toHaveCount(2);
    $reasoning = DB::table('foodalchemist_gp_anchor_mappings')->where('gp_id', $zId)->where('role', 'kern')->value('ai_reasoning');
    expect($reasoning)->toBe('Zitrone');

    ($this->apply)([]);                                                                              // idempotent
    $k = ($this->kennzahlen)();
    expect($k['anker_neu'])->toBe(0)->and($k['anker_unveraendert'])->toBe(3)->and(DB::table('foodalchemist_gp_anchor_mappings')->count())->toBe(3);

    // Rückweg stellt den Vorzustand der Anker her (neue GPs sind weg, ihre Mappings auch)
    $this->artisan('foodalchemist:gp-paket-import', ['--zurueck' => ($this->protokoll)(), '--admin' => $this->admin->email, '--bericht' => $this->ber])->assertExitCode(0);
});

it('Anker: manipulierte gp_anker.jsonl (SHA256 ≠ gp_anker_manifest.json) lehnt das Paket ab', function () {
    B::schreibe($this->dir, [B::gp('Zanderfilet: frisch')], [], [], [['gp_key' => 'zanderfilet_frisch', 'anchor_slug' => 'lemon', 'role' => 'kern', 'source' => 'x', 'ai_confidence' => 0.5, 'ai_reasoning' => null]]);
    file_put_contents($this->dir . '/gp_anker.jsonl', json_encode(['gp_key' => 'zanderfilet_frisch', 'anchor_slug' => 'dill', 'role' => 'kern', 'source' => 'x']) . "\n", FILE_APPEND);

    ($this->lauf)([], 1)->expectsOutputToContain('gp_anker.jsonl');
    ($this->lauf)(['--ohne-anker' => true]);                                                         // ohne Anker-Phase wird die Datei nicht angefasst
});

it('Feld-Abgleich: Pflege-Felder, Einheit/Fahrzeug per Slug, Favoriten werden nie geschrieben, Tags/Maße kommen mit', function () {
    DB::table('foodalchemist_vocab_serving_vehicles')->insert(['uuid' => (string) \Illuminate\Support\Str::uuid(), 'team_id' => null, 'slug' => 'teller_flach', 'name' => 'Teller flach', 'created_at' => now(), 'updated_at' => now()]);
    B::schreibe($this->dir, [B::gp('Knoblauch: frisch, ganz', ['preferred_count_unit_slug' => 'zehe', 'piece_default_g' => 5.0, 'piece_default_g_source' => 'manual', 'tag_is_vegan' => 1, 'tag_contains_pork' => 0,
        'serving_vehicle_slug' => 'teller_flach', 'diameter_mm' => 270.0, 'is_favorite' => 1, 'favorite_rank' => 3, 'bio' => 'bio'])], []);

    ($this->apply)([]);

    $g = ($this->gp)('knoblauch_frisch_ganz');
    expect((int) $g->preferred_count_unit_id)->toBe((int) DB::table('foodalchemist_vocab_units')->where('slug', 'zehe')->value('id'))
        ->and((float) $g->piece_default_g)->toBe(5.0)->and((int) $g->tag_is_vegan)->toBe(1)->and((int) $g->tag_contains_pork)->toBe(0)->and($g->tag_is_halal)->toBeNull()
        ->and((int) $g->serving_vehicle_vocab_id)->toBe((int) DB::table('foodalchemist_vocab_serving_vehicles')->where('slug', 'teller_flach')->value('id'))
        ->and((float) $g->diameter_mm)->toBe(270.0)->and($g->bio)->toBe('bio')
        ->and($g->allergen_milk)->toBeNull()->and($g->nutri_kcal_per_100g)->toBeNull()->and($g->ai_reasoning)->toBeNull();
    expect(array_key_exists('is_favorite', (array) $g))->toBeFalse();                                // Spalte gibt es nicht mehr (Team-Overlay)
});

it('Zustand NULL ist bei Öle/Fette (WG 11) gültig; bei Fleisch (WG 4) angenommen, aber als Hinweis gezählt; gesetzter Zustand muss ins §9-Vokabular', function () {
    B::schreibe($this->dir, [B::gp('Olivenöl', ['commodity_group_code' => 11, 'condition' => null]), B::gp('Rindfleisch', ['condition' => null]), B::gp('Kalbfleisch: frisch', ['condition' => 'lau'])], []);

    ($this->apply)([])->expectsOutputToContain('ohne Zustand (NULL)');

    expect(DB::table('foodalchemist_gps')->pluck('gp_key')->sort()->values()->all())->toBe(['olivenoel', 'rindfleisch'])
        ->and(collect(($this->csv)('gp_abgelehnt'))->pluck('code', 'gp_key')->all())->toBe(['kalbfleisch_frisch' => 'zustand']);
});
