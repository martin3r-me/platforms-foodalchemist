<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplier;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem;
use Platform\FoodAlchemist\Services\Ai\PoolEmbeddingService;
use Platform\FoodAlchemist\Services\GpStamm\GpStammPurgeService;
use Platform\FoodAlchemist\Support\MasterTeam;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * foodalchemist:gp-stamm-purge — globalen GP-Stamm (team_id NULL) hart löschen (Plan_Neuaufbau Schritt 4).
 * Synthetische Daten. Kind-Zeilen werden generisch gefüllt (Pflichtspalten per Schema), FK-Prüfung für die Testdaten aus.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->master = Team::create(['name' => 'BHG.DIGITAL', 'user_id' => 1, 'personal_team' => false]);
    config(['foodalchemist.master_team_id' => $this->master->id]);
    MasterTeam::flush();
    $this->admin = $this->makeUser($this->master, 'Plattform Admin');
    config(['platform-shell.admins' => [strtolower($this->admin->email)]]);
    $this->ber = sys_get_temp_dir().'/purge_ber_'.uniqid();
    File::ensureDirectoryExists($this->ber);
    $this->vek = [];
    $this->app->instance(PoolEmbeddingService::class, new class extends PoolEmbeddingService
    {
        public array $geloescht = [];

        public function __construct() {}

        public function deleteGp(int $realTeamId, int $id, int|string|null $rawTeamId = null): void
        {
            $this->geloescht[] = [$realTeamId, $id];
        }

        public function globalTeamId(): int
        {
            return 99;
        }
    });

    $this->gp = fn (string $key, ?int $team = null, bool $soft = false): FoodAlchemistGp => tap(FoodAlchemistGp::create([
        'team_id' => $team, 'gp_key' => $key, 'name' => ucfirst($key), 'main_ingredient_slug' => $key, 'status' => 'approved', 'condition' => 'frisch',
    ]), fn ($g) => $soft ? $g->delete() : null);

    /** Eine Kind-Zeile mit gp_id; Pflichtspalten generisch nach Schema. */
    $this->kind = function (string $tabelle, int $gpId, string $spalte = 'gp_id', array $extra = []) {
        Schema::disableForeignKeyConstraints();
        $zeile = [$spalte => $gpId] + $extra;
        foreach (Schema::getColumns($tabelle) as $c) {
            $n = $c['name'];
            if (isset($zeile[$n]) || $c['auto_increment'] || $c['nullable'] || $c['default'] !== null) {
                continue;
            }
            $t = strtolower($c['type_name'] ?? $c['type']);
            $zeile[$n] = match (true) {
                $n === 'uuid' => (string) \Illuminate\Support\Str::uuid(),
                str_contains($t, 'int') || str_contains($t, 'bool') => 1,
                str_contains($t, 'decimal') || str_contains($t, 'float') || str_contains($t, 'double') => 1,
                str_contains($t, 'date') || str_contains($t, 'time') => now(),
                str_contains($t, 'json') => '{}',
                default => 'x',
            };
        }
        DB::table($tabelle)->insert($zeile);
        Schema::enableForeignKeyConstraints();
    };
    $this->svc = fn (): GpStammPurgeService => app(GpStammPurgeService::class);
    $this->backup = function (): string {
        $f = sys_get_temp_dir().'/purge_dump_'.uniqid().'.sql';
        file_put_contents($f, str_repeat('-- dump ', 300));

        return $f;
    };
});

it('Trockenlauf zählt global/aktiv/soft/Team und Kind-Tabellen exakt und ändert nichts', function () {
    $a = ($this->gp)('apfel');
    ($this->gp)('birne', null, true);                       // soft-gelöscht, global
    $team = ($this->gp)('teamgp', $this->master->id);
    ($this->kind)('foodalchemist_gp_forms', $a->id, 'gp_id', ['form_slug' => 'f1']);
    ($this->kind)('foodalchemist_gp_forms', $a->id, 'gp_id', ['form_slug' => 'f2']);
    ($this->kind)('foodalchemist_gp_textures', $a->id);
    ($this->kind)('foodalchemist_gp_anchor_mappings', $a->id);
    ($this->kind)('foodalchemist_gp_forms', $team->id);

    $z = ($this->svc)()->zaehlen();

    expect($z['gps_global'])->toBe(2)->and($z['gps_global_aktiv'])->toBe(1)->and($z['gps_global_soft_geloescht'])->toBe(1)->and($z['gps_team_eigen'])->toBe(1)
        ->and($z['ohne_fk']['foodalchemist_gp_forms'])->toBe(2)->and($z['ohne_fk']['foodalchemist_gp_textures'])->toBe(1)
        ->and($z['cascade']['foodalchemist_gp_anchor_mappings'])->toBe(1)->and($z['riegel_treffer'])->toBe(0);

    $this->artisan('foodalchemist:gp-stamm-purge', ['--bericht' => $this->ber, '--erwartet-gps' => 2])->assertSuccessful()->expectsOutputToContain('Soll 2 · Ist 2 · Differenz +0');
    expect(DB::table('foodalchemist_gps')->count())->toBe(3);   // nichts gelöscht
});

it('Apply braucht Backup-Nachweis und Plattform-Admin', function () {
    ($this->gp)('apfel');
    $this->artisan('foodalchemist:gp-stamm-purge', ['--apply' => true, '--bericht' => $this->ber])->assertFailed();
    $this->artisan('foodalchemist:gp-stamm-purge', ['--apply' => true, '--bericht' => $this->ber, '--backup-nachweis' => ($this->backup)()])->assertFailed();   // ohne --admin
    $normal = $this->makeUser($this->master, 'Normal', 'member');
    $this->artisan('foodalchemist:gp-stamm-purge', ['--apply' => true, '--bericht' => $this->ber, '--backup-nachweis' => ($this->backup)(), '--admin' => $normal->email])->assertFailed();
    expect(FoodAlchemistGp::withTrashed()->whereNull('team_id')->count())->toBe(1);
});

it('Apply löscht vollständig inkl. soft-gelöschter; Team-GPs und ihre Kinder bleiben; Lieferantenartikel bleiben', function () {
    $a = ($this->gp)('apfel');
    $b = ($this->gp)('birne', null, true);
    $team = ($this->gp)('teamgp', $this->master->id);
    $uuid = $b->uuid;
    $lief = FoodAlchemistSupplier::create(['team_id' => null, 'name' => 'Hanos', 'stamm_class' => 'stamm_paket']);
    $item = FoodAlchemistSupplierItem::create(['team_id' => null, 'stamm_class' => 'stamm_paket', 'supplier_id' => $lief->id, 'designation' => 'Apfel', 'article_number' => 'A1', 'legacy_id' => 1, 'qty' => 1, 'unit_code' => 'kg']);
    DB::table('foodalchemist_supplier_item_structures')->insert(['uuid' => (string) \Illuminate\Support\Str::uuid(), 'supplier_item_id' => $item->id, 'gp_id' => $a->id, 'team_id' => null]);
    $team->update(['merged_into_id' => $a->id]);                         // Team-GP zeigt auf einen globalen
    foreach (['foodalchemist_gp_forms', 'foodalchemist_gp_textures', 'foodalchemist_gp_taste_vectors', 'foodalchemist_gp_anchor_mappings', 'foodalchemist_gp_la_preferences'] as $t) {
        ($this->kind)($t, $a->id);
        ($this->kind)($t, $team->id);
    }

    $p = ($this->svc)()->ausfuehren($this->admin);

    expect(FoodAlchemistGp::withTrashed()->whereNull('team_id')->count())->toBe(0)
        ->and(FoodAlchemistGp::find($team->id))->not->toBeNull()
        ->and(FoodAlchemistGp::find($team->id)->merged_into_id)->toBeNull()
        ->and(DB::table('foodalchemist_gps')->where('uuid', $uuid)->count())->toBe(0)
        ->and(DB::table('foodalchemist_supplier_item_structures')->count())->toBe(0)
        ->and(DB::table('foodalchemist_supplier_items')->count())->toBe(1)               // Artikel bleiben vollständig
        ->and(FoodAlchemistSupplierItem::find($item->id)->designation)->toBe('Apfel')
        ->and($p['geloescht']['foodalchemist_gps'])->toBe(2)->and($p['geloescht']['foodalchemist_supplier_item_structures'])->toBe(1);
    foreach (['foodalchemist_gp_forms', 'foodalchemist_gp_textures', 'foodalchemist_gp_taste_vectors', 'foodalchemist_gp_anchor_mappings', 'foodalchemist_gp_la_preferences'] as $t) {
        expect(DB::table($t)->count())->toBe(1, $t);                                    // nur die Team-Zeile bleibt
    }
    // Re-Insert derselben uuid funktioniert (uuid-UNIQUE-Falle)
    FoodAlchemistGp::create(['team_id' => null, 'uuid' => $uuid, 'gp_key' => 'birne', 'name' => 'Birne', 'main_ingredient_slug' => 'birne', 'status' => 'approved']);
    expect(FoodAlchemistGp::where('uuid', $uuid)->count())->toBe(1);
});

it('Vektoren der globalen GPs werden über den Embedding-Dienst gelöscht (Partition global)', function () {
    $a = ($this->gp)('apfel');
    $b = ($this->gp)('birne', null, true);
    ($this->gp)('teamgp', $this->master->id);
    $dienst = app(PoolEmbeddingService::class);
    $dienst->geloescht = [];                                   // Aufräum-Aufrufe des Soft-Delete-Observers beim Anlegen ausblenden
    ($this->svc)()->ausfuehren($this->admin);
    $ids = collect($dienst->geloescht)->pluck(1)->sort()->values()->all();
    expect($ids)->toBe(collect([$a->id, $b->id])->sort()->values()->all())->and(collect($dienst->geloescht)->pluck(0)->unique()->all())->toBe([99]);
});

dataset('riegel', fn () => collect(GpStammPurgeService::RIEGEL)->mapWithKeys(fn ($r) => [$r[0].'.'.$r[1] => [$r[0], $r[1]]])->all());

it('Riegel greift je Tabelle: nichts wird gelöscht', function (string $tabelle, string $spalte) {
    $a = ($this->gp)('apfel');
    ($this->gp)('birne');
    ($this->kind)($tabelle, $a->id, $spalte);
    $z = ($this->svc)()->zaehlen();
    expect($z['riegel_treffer'])->toBe(1);

    expect(fn () => ($this->svc)()->ausfuehren($this->admin))->toThrow(RuntimeException::class, 'Riegel');
    expect(FoodAlchemistGp::withTrashed()->whereNull('team_id')->count())->toBe(2);
    $this->artisan('foodalchemist:gp-stamm-purge', ['--apply' => true, '--bericht' => $this->ber, '--backup-nachweis' => ($this->backup)(), '--admin' => $this->admin->email])->assertFailed();
    expect(FoodAlchemistGp::withTrashed()->whereNull('team_id')->count())->toBe(2);
})->with('riegel');

it('Riegel zählt Verweise von Team-GPs NICHT (nur globale)', function () {
    ($this->gp)('apfel');
    $team = ($this->gp)('teamgp', $this->master->id);
    ($this->kind)('foodalchemist_recipe_ingredients', $team->id);
    expect(($this->svc)()->zaehlen()['riegel_treffer'])->toBe(0);
});
