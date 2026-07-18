<?php

use Illuminate\Support\Facades\DB;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Services\ProcessAnchorService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;
use Symfony\Component\Uid\UuidV7;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * 05·P5 — Prozessanker-Parser: deterministische Erdung aus dem Zubereitungstext,
 * hoch-präzise (Über-Tagging-Guard), idempotent, fremd-Quellen unangetastet.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();

    // die vier Prozessanker im shared Vokabular anlegen (global)
    $this->anchorIds = [];
    foreach (ProcessAnchorService::ANCHOR_SLUGS as $slug) {
        $this->anchorIds[$slug] = DB::table('foodalchemist_vocab_pairing_anchors')->insertGetId([
            'uuid' => (string) UuidV7::generate(),
            'team_id' => null,
            'slug' => $slug,
            'display_de' => ucfirst($slug),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    $this->makeRecipe = function (string $prep): FoodAlchemistRecipe {
        return FoodAlchemistRecipe::create([
            'team_id' => $this->rootTeam->id,
            'recipe_key' => 'r-' . bin2hex(random_bytes(4)),
            'name' => 'Test',
            'status' => 'approved',
            'is_sales_recipe' => false,
            'preparation' => $prep,
        ]);
    };

    $this->svc = app(ProcessAnchorService::class);
});

it('parst Röst-/Karamell-/Rauch-/Ferment-Marker', function () {
    expect(array_keys($this->svc->parse('Zwiebeln scharf anbraten und rösten')))->toContain('roestaromen');
    expect(array_keys($this->svc->parse('Zucker karamellisieren')))->toContain('karamell');
    expect(array_keys($this->svc->parse('mit geräuchertem Speck verfeinern')))->toContain('rauch');
    expect(array_keys($this->svc->parse('Miso und Sojasauce einrühren')))->toContain('ferment');
});

it('mappt grillen auf roestaromen UND rauch (Design-Entscheid)', function () {
    $slugs = array_keys($this->svc->parse('Das Fleisch grillen bis es Röstaromen bekommt'));
    expect($slugs)->toContain('roestaromen')->toContain('rauch');
});

it('setzt KEINE Anker bei kaltem/Assembly-Text (Über-Tagging-Guard)', function () {
    expect($this->svc->parse('Blattsalat waschen, mit Vinaigrette anmachen und anrichten'))->toBe([]);
    expect($this->svc->parse('Frischkäse mit Kräutern verrühren, als Dip servieren'))->toBe([]);
});

it('verwechselt „brauchen" nicht mit „rauch"', function () {
    expect($this->svc->parse('Man braucht dafür frische Kräuter'))->toBe([]);
});

it('dry-run schreibt nichts', function () {
    $r = ($this->makeRecipe)('Zwiebeln anbraten');
    $this->svc->groundRecipe($r, false);
    expect(DB::table('foodalchemist_recipe_process_anchors')->where('recipe_id', $r->id)->count())->toBe(0);
});

it('--apply schreibt parser-Anker idempotent', function () {
    $r = ($this->makeRecipe)('Zwiebeln anbraten und Zucker karamellisieren');
    $this->svc->groundRecipe($r, true);
    $rows = DB::table('foodalchemist_recipe_process_anchors')->where('recipe_id', $r->id)->whereNull('deleted_at')->get();
    expect($rows)->toHaveCount(2);
    expect($rows->pluck('source')->unique()->all())->toBe(['parser']);

    // zweiter Lauf → keine Dubletten (unique recipe_id+anchor_id + idempotent)
    $this->svc->groundRecipe($r->refresh(), true);
    expect(DB::table('foodalchemist_recipe_process_anchors')->where('recipe_id', $r->id)->whereNull('deleted_at')->count())->toBe(2);
});

it('entfernt eigene parser-Anker, wenn der Marker weg ist', function () {
    $r = ($this->makeRecipe)('Zwiebeln anbraten');
    $this->svc->groundRecipe($r, true);
    expect(DB::table('foodalchemist_recipe_process_anchors')->where('recipe_id', $r->id)->whereNull('deleted_at')->count())->toBe(1);

    DB::table('foodalchemist_recipes')->where('id', $r->id)->update(['preparation' => 'Zwiebeln roh reiben']);
    $this->svc->groundRecipe($r->refresh(), true);
    expect(DB::table('foodalchemist_recipe_process_anchors')->where('recipe_id', $r->id)->whereNull('deleted_at')->count())->toBe(0);
});

it('lässt fremde (manual/ki) Anker unangetastet', function () {
    $r = ($this->makeRecipe)('Zwiebeln roh reiben'); // kein Marker
    // manueller Röst-Anker von Hand gesetzt
    DB::table('foodalchemist_recipe_process_anchors')->insert([
        'uuid' => (string) UuidV7::generate(), 'team_id' => $this->rootTeam->id, 'recipe_id' => $r->id,
        'anchor_id' => $this->anchorIds['roestaromen'], 'source' => 'manual',
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $this->svc->groundRecipe($r, true);
    $row = DB::table('foodalchemist_recipe_process_anchors')->where('recipe_id', $r->id)->whereNull('deleted_at')->first();
    expect($row->source)->toBe('manual'); // NICHT gelöscht
});

it('dedupliziert gegen vorhandenen fremden Anker (kein Doppel-Insert)', function () {
    $r = ($this->makeRecipe)('Zwiebeln anbraten'); // parser will roestaromen
    DB::table('foodalchemist_recipe_process_anchors')->insert([
        'uuid' => (string) UuidV7::generate(), 'team_id' => $this->rootTeam->id, 'recipe_id' => $r->id,
        'anchor_id' => $this->anchorIds['roestaromen'], 'source' => 'ki',
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $result = $this->svc->groundRecipe($r, true);
    expect($result['kept_foreign'])->toContain('roestaromen');
    expect(DB::table('foodalchemist_recipe_process_anchors')->where('recipe_id', $r->id)->whereNull('deleted_at')->count())->toBe(1);
});

it('Command dry-run vs --apply', function () {
    ($this->makeRecipe)('Fleisch scharf anbraten');
    $this->artisan('foodalchemist:process-anchor-ground', ['--team' => $this->rootTeam->id])->assertSuccessful();
    expect(DB::table('foodalchemist_recipe_process_anchors')->count())->toBe(0);

    $this->artisan('foodalchemist:process-anchor-ground', ['--team' => $this->rootTeam->id, '--apply' => true])->assertSuccessful();
    expect(DB::table('foodalchemist_recipe_process_anchors')->where('source', 'parser')->count())->toBeGreaterThan(0);
});
