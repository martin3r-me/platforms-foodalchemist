<?php

use Illuminate\Support\Facades\DB;
use Platform\FoodAlchemist\Services\KnowledgeRoutingService;
use Platform\FoodAlchemist\Services\KnowledgeService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 52 Runde D — die einzige Reihenfolge-Falle des Umbaus.
 *
 * Uneingeordnete Dossiers bedient das Kategorie-Routing, eingeordnete das Arten-Routing.
 * Ein Dossier wechselt die Spur, sobald es eine Art bekommt. Fehlt fuer diese Art eine
 * Arten-Zeile, faellt es ins Leere — OHNE Fehlermeldung, weil beide Mechanismen fuer sich
 * korrekt arbeiten. Statt diese Regel jemandem zum Merken zu geben, sagt sie das System.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam));
    $this->dossier = fn (string $slug, ?string $art) => app(KnowledgeService::class)->create($this->rootTeam, [
        'title' => 'D '.$slug, 'slug' => $slug, 'category' => 'cross_cutting',
        'content_md' => 'Inhalt.', 'art' => $art,
    ]);
});

it('meldet nichts, solange nichts eingeordnet ist', function () {
    $this->artisan('foodalchemist:wissen-spuren')->assertExitCode(0);
});

it('★ meldet eine Art, die Dossiers traegt, aber von keinem Schritt geroutet wird', function () {
    ($this->dossier)('f1', 'fachwissen');

    $this->artisan('foodalchemist:wissen-spuren')
        ->expectsOutputToContain('KEINE SPUR')
        ->assertExitCode(1);
});

it('schweigt, sobald die Spur existiert', function () {
    ($this->dossier)('f2', 'fachwissen');
    app(KnowledgeRoutingService::class)->setArt('recipe.generator', 'fachwissen', 'discovery', 5);

    $this->artisan('foodalchemist:wissen-spuren')
        ->expectsOutputToContain('recipe.generator')
        ->assertExitCode(0);
});

it('★ eine Spur auf `none` zaehlt NICHT als Spur — das ist ausdrueckliches Abschalten', function () {
    ($this->dossier)('f3', 'referenz');
    app(KnowledgeRoutingService::class)->setArt('recipe.generator', 'referenz', 'none');

    $this->artisan('foodalchemist:wissen-spuren')->assertExitCode(1);
});

it('verlangt fuer regel und ablauf KEINE Spur — die laufen anders', function () {
    ($this->dossier)('r1', 'regel');       // ueber den Kanon
    ($this->dossier)('a1', 'ablauf');      // ueber ablauf.GET, nie im Prompt

    $this->artisan('foodalchemist:wissen-spuren')
        ->expectsOutputToContain('Kanon')
        ->assertExitCode(0);
});

it('zaehlt nur AKTIVE Dossiers — ein stillgelegtes erreicht ohnehin nichts', function () {
    ($this->dossier)('f4', 'fachwissen');
    DB::table('foodalchemist_knowledge_documents')->where('slug', 'f4')->update(['active' => 0]);

    $this->artisan('foodalchemist:wissen-spuren')->assertExitCode(0);
});
