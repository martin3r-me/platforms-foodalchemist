<?php

use Illuminate\Support\Facades\DB;
use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Services\Matching\MatchHeuristics;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;
use Symfony\Component\Uid\UuidV7;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * LEITUNGSWASSER. `MatchHeuristics::defaultGpAlias()` leitet bares »Wasser« seit dem
 * 2026-08-18 auf »Wasser: Leitung« und ist im eigenen Kommentar als „inert, solange das GP
 * nicht existiert" markiert — es existierte nicht. 64 Rezepte auf demo rechneten
 * Leitungswasser darum als «Wasser: still, 0,5 l, Bio», also als zugekauftes
 * Bio-Flaschenwasser mit Einkaufspreis.
 *
 * Der Befehl setzt beides um, in dieser Reihenfolge: Regelwerk §11.2a zuerst (CLAUDE.md —
 * das Regelwerk steht über dem Code), dann das GP.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();

    // Das §11.2-Dossier, wie es im Modul liegt (die zwei Kopplungs-Zeilen im Original-Wortlaut).
    DB::table('foodalchemist_knowledge_documents')->insert([
        'uuid' => (string) UuidV7::generate(), 'team_id' => null,
        'slug' => 'regelwerk-gp-112-derivate-nebenprodukt-derivate',
        'title' => '§11.2 Nebenprodukt-Derivate', 'category' => 'regelwerk',
        'content_md' => "## §11 Derivate\n\n```sql\nALTER TABLE wawi_gp_v2 ADD COLUMN requires_la INTEGER DEFAULT 1;  -- Derivate: 0\n```\n\n"
            . "| **LA-Match** | `requires_la = 0` → übersprungen. Kein Lieferanten-Match nötig (Derivate werden nicht zugekauft). |\n\n"
            . "#### Querverweis\n\nSiehe Regelwerk Basisrezepte, §11.\n",
        'version' => 1, 'content_hash' => hash('sha256', 'x'), 'char_count' => 300,
        'active' => 1, 'source_path' => null, 'created_via' => 'ui',
        'created_at' => now(), 'updated_at' => now(),
    ]);
});

it('Trockenlauf schreibt nichts', function () {
    $this->artisan('foodalchemist:wasser-leitung', ['--team' => $this->rootTeam->id])
        ->expectsOutputToContain('Trockenlauf')
        ->assertSuccessful();

    expect(FoodAlchemistGp::where('name', 'Wasser: Leitung')->exists())->toBeFalse();
    expect(DB::table('foodalchemist_knowledge_documents')
        ->where('slug', 'regelwerk-gp-112-derivate-nebenprodukt-derivate')->value('version'))->toBe(1);
});

it('legt das GP mit den Feldern aus §11.2a an — requires_la 0, is_platzhalter 0', function () {
    $this->artisan('foodalchemist:wasser-leitung', ['--team' => $this->rootTeam->id, '--apply' => true])
        ->assertSuccessful();

    $gp = FoodAlchemistGp::where('name', 'Wasser: Leitung')->first();
    expect($gp)->not->toBeNull()
        ->and((bool) $gp->requires_la)->toBeFalse()
        // is_platzhalter MUSS 0 sein: der §5-Alias (resolveGpByName) verlangt es, und ein
        // Platzhalter markiert Ungemapptes — Leitungswasser ist aufgelöst.
        ->and((bool) $gp->is_platzhalter)->toBeFalse()
        ->and((bool) $gp->is_derivat)->toBeFalse()
        ->and($gp->commodity_group_code)->toBe('15')
        ->and($gp->main_ingredient_slug)->toBe('wasser')
        // status ist ein Enum (GpStatus), kein String — deshalb ->value.
        ->and($gp->status->value)->toBe('approved');
});

it('ergänzt §11.2a im Dossier und zieht die beiden Kopplungs-Zeilen mit', function () {
    $this->artisan('foodalchemist:wasser-leitung', ['--team' => $this->rootTeam->id, '--apply' => true])
        ->assertSuccessful();

    $d = DB::table('foodalchemist_knowledge_documents')
        ->where('slug', 'regelwerk-gp-112-derivate-nebenprodukt-derivate')
        ->first(['content_md', 'version', 'char_count']);

    expect($d->content_md)->toContain('§11.2a')
        ->and($d->content_md)->toContain('selbst-gestellte Ware: 0 (§11.2a)')      // SQL-Kommentar mitgezogen
        ->and($d->content_md)->toContain('Dieselbe 0 gilt für selbst-gestellte')   // LA-Match-Zeile mitgezogen
        ->and($d->version)->toBe(2);

    // Der Zusatz gehört VOR den Querverweis, nicht dahinter.
    expect(mb_strpos($d->content_md, '§11.2a'))->toBeLessThan(mb_strpos($d->content_md, '#### Querverweis'));
});

it('ist idempotent — der zweite Lauf ändert nichts', function () {
    $this->artisan('foodalchemist:wasser-leitung', ['--team' => $this->rootTeam->id, '--apply' => true])->assertSuccessful();
    $v1 = DB::table('foodalchemist_knowledge_documents')->where('slug', 'regelwerk-gp-112-derivate-nebenprodukt-derivate')->value('version');

    $this->artisan('foodalchemist:wasser-leitung', ['--team' => $this->rootTeam->id, '--apply' => true])
        ->expectsOutputToContain('steht schon im Dossier')
        ->expectsOutputToContain('existiert schon')
        ->assertSuccessful();

    expect(DB::table('foodalchemist_knowledge_documents')->where('slug', 'regelwerk-gp-112-derivate-nebenprodukt-derivate')->value('version'))->toBe($v1)
        ->and(FoodAlchemistGp::where('name', 'Wasser: Leitung')->count())->toBe(1);
});

it('der §5-Alias zeigt weiterhin genau auf diesen Namen', function () {
    // Wenn jemand den Namen im Command oder in MatchHeuristics ändert, ohne den anderen
    // mitzuziehen, wird der Alias wieder stumm inert — genau der Zustand, der 64 Rezepte
    // falsch gerechnet hat.
    $rc = new ReflectionClass(MatchHeuristics::class);
    $m = $rc->getMethod('defaultGpAlias');
    $src = implode('', array_slice(file($m->getFileName()), $m->getStartLine() - 1, $m->getEndLine() - $m->getStartLine() + 1));

    expect($src)->toContain("'Wasser: Leitung'");
});
