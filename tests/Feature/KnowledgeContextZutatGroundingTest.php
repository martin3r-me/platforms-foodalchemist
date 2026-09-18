<?php

use Illuminate\Support\Facades\DB;
use Platform\FoodAlchemist\Services\Ai\KnowledgeContextService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;
use Symfony\Component\Uid\UuidV7;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 53 Paket H Aufgabe B — Zutaten-Grounding je Aspekt.
 *
 * Befund (PREVIEW-Messung "Passionsfrucht-Gelee mit Acerola, 40 Portionen"): die generische
 * Fuzzy-Discovery liess `zutat.acerola_14--*` alle 4 Aspekte belegen, `zutat.passion_fruit--*` nur
 * 2 von 4, ein Gelée-Technik-Dossier fiel komplett raus. Grounding lädt stattdessen deterministisch
 * GENAU EIN Aspekt-Dossier je Hauptzutat, Aspekt aus dem Prompt-Key (`zutatAspektFuer()`).
 */
beforeEach(function () {
    $this->seedTeamHierarchy();

    $this->mkAnker = function (string $slug, string $displayDe) {
        DB::table('foodalchemist_vocab_pairing_anchors')->insert([
            'uuid' => (string) UuidV7::generate(), 'slug' => $slug, 'display_de' => $displayDe,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    };
    $this->mkZutatDoc = function (string $slug, string $inhalt) {
        DB::table('foodalchemist_knowledge_documents')->insert([
            'uuid' => (string) UuidV7::generate(), 'slug' => $slug, 'title' => $slug,
            'category' => 'zutat', 'content_md' => $inhalt, 'version' => 1,
            'content_hash' => hash('sha256', $slug), 'char_count' => mb_strlen($inhalt),
            'active' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
    };
    $this->mkGroundingRouting = function (string $feature, int $maxDocs = 8) {
        DB::table('foodalchemist_knowledge_routings')->insert([
            'feature' => $feature, 'category' => 'zutat', 'mode' => 'grounding',
            'max_docs' => $maxDocs, 'max_chars_per_doc' => null,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    };
});

it('laedt je Hauptzutat genau ein Aspekt-Dossier statt aller Aspekte gegeneinander konkurrieren zu lassen', function () {
    $feature = 'test.zutat_grounding_generator';
    config(['foodalchemist.prompts' => array_merge(
        (array) config('foodalchemist.prompts', []),
        [$feature => ['tier' => 'B', 'task' => 'x']],
    )]);
    ($this->mkAnker)('passionsfrucht', 'Passionsfrucht');
    ($this->mkAnker)('acerola', 'Acerola');
    foreach (['steckbrief', 'verwendung', 'verhalten', 'cave'] as $aspekt) {
        ($this->mkZutatDoc)("zutat.passionsfrucht--{$aspekt}", "Passionsfrucht {$aspekt}");
        ($this->mkZutatDoc)("zutat.acerola--{$aspekt}", "Acerola {$aspekt}");
    }
    ($this->mkGroundingRouting)($feature);

    $ctx = app(KnowledgeContextService::class)->contextFor(
        $this->rootTeam, $feature, 'Passionsfrucht-Gelee mit Acerola', null,
        ['passionsfrucht', 'acerola'],
    );

    expect($ctx['files_used'])
        ->toContain('zutat.passionsfrucht--verwendung@v1')
        ->toContain('zutat.acerola--verwendung@v1')
        ->not->toContain('zutat.passionsfrucht--steckbrief@v1')
        ->not->toContain('zutat.passionsfrucht--verhalten@v1')
        ->not->toContain('zutat.passionsfrucht--cave@v1')
        ->not->toContain('zutat.acerola--steckbrief@v1')
        ->not->toContain('zutat.acerola--verhalten@v1')
        ->not->toContain('zutat.acerola--cave@v1');
    // genau 2 Zutaten-Dossiers, keine Aspekt-Häufung einer einzelnen Entität
    expect(array_filter($ctx['files_used'], fn ($f) => str_starts_with($f, 'zutat.')))->toHaveCount(2);
});

it('waehlt den Aspekt nach Prompt-Key: recipe.steps will "verhalten", gp.suggest will "steckbrief"', function () {
    ($this->mkAnker)('passionsfrucht', 'Passionsfrucht');
    foreach (['steckbrief', 'verwendung', 'verhalten', 'cave'] as $aspekt) {
        ($this->mkZutatDoc)("zutat.passionsfrucht--{$aspekt}", "Passionsfrucht {$aspekt}");
    }
    ($this->mkGroundingRouting)('recipe.steps');
    ($this->mkGroundingRouting)('gp.suggest');

    $steps = app(KnowledgeContextService::class)->contextFor($this->rootTeam, 'recipe.steps', 'Passionsfrucht-Gelee', null, ['passionsfrucht']);
    expect($steps['files_used'])->toContain('zutat.passionsfrucht--verhalten@v1');

    $gp = app(KnowledgeContextService::class)->contextFor($this->rootTeam, 'gp.suggest', 'Passionsfrucht', null, ['passionsfrucht']);
    expect($gp['files_used'])->toContain('zutat.passionsfrucht--steckbrief@v1');
});

it('findet nummerierte Anker-Varianten per Praefix (zutat.acerola_14--… bei Anker "acerola")', function () {
    ($this->mkAnker)('acerola', 'Acerola');
    ($this->mkZutatDoc)('zutat.acerola_14--verwendung', 'Acerola verwendung');
    ($this->mkGroundingRouting)('recipe.generator');

    $ctx = app(KnowledgeContextService::class)->contextFor($this->rootTeam, 'recipe.generator', 'Acerola-Sirup', null, ['acerola']);

    expect($ctx['files_used'])->toContain('zutat.acerola_14--verwendung@v1');
});

it('LIKE-Praefix trifft KEIN unverwandtes Dossier mit gemeinsamem Wortstamm (Apfel vs. Apfelsine)', function () {
    // Regression: `LIKE 'zutat.apfel_%--verwendung'` OHNE Regex-Nachpruefung traf frueher auch
    // "zutat.apfelsine--verwendung", weil '_' in LIKE ein BELIEBIGES Zeichen matcht (Orchestrierung,
    // 2026-09-18). Nur ein `_<Zahl>`-Suffix darf durchgehen.
    ($this->mkAnker)('apfel', 'Apfel');
    ($this->mkZutatDoc)('zutat.apfelsine--verwendung', 'Apfelsine verwendung');
    ($this->mkGroundingRouting)('recipe.generator');

    $ctx = app(KnowledgeContextService::class)->contextFor($this->rootTeam, 'recipe.generator', 'Apfel-Kompott', null, ['apfel']);

    expect($ctx['files_used'])->not->toContain('zutat.apfelsine--verwendung@v1');
});

it('laedt BEIDE Teil-Dossiers eines Aspekts (Regelwerk Zutaten-Dossier §2: Teilung innerhalb der Frage)', function () {
    ($this->mkAnker)('acerola', 'Acerola');
    ($this->mkZutatDoc)('zutat.acerola--verhalten-hitze', 'Acerola Verhalten unter Hitze');
    ($this->mkZutatDoc)('zutat.acerola--verhalten-saeure', 'Acerola Verhalten unter Saeure');
    ($this->mkZutatDoc)('zutat.acerola--verwendung', 'Acerola Verwendung');   // darf NICHT mitgezogen werden
    ($this->mkGroundingRouting)('recipe.steps');   // Aspekt "verhalten"

    $ctx = app(KnowledgeContextService::class)->contextFor($this->rootTeam, 'recipe.steps', 'Acerola-Sirup', null, ['acerola']);

    expect($ctx['files_used'])
        ->toContain('zutat.acerola--verhalten-hitze@v1')
        ->toContain('zutat.acerola--verhalten-saeure@v1')
        ->not->toContain('zutat.acerola--verwendung@v1');
});

it('laedt genau ein Dossier, wenn der Aspekt NICHT geteilt ist', function () {
    ($this->mkAnker)('acerola', 'Acerola');
    ($this->mkZutatDoc)('zutat.acerola--verhalten', 'Acerola Verhalten');
    ($this->mkGroundingRouting)('recipe.steps');

    $ctx = app(KnowledgeContextService::class)->contextFor($this->rootTeam, 'recipe.steps', 'Acerola-Sirup', null, ['acerola']);

    $zutatFiles = array_values(array_filter($ctx['files_used'], fn ($f) => str_starts_with($f, 'zutat.')));
    expect($zutatFiles)->toBe(['zutat.acerola--verhalten@v1']);
});

it('markiert eine Zutat ohne Anker-Treffer ehrlich statt sie zu ignorieren oder zu raten', function () {
    ($this->mkGroundingRouting)('recipe.generator');

    $ctx = app(KnowledgeContextService::class)->contextFor($this->rootTeam, 'recipe.generator', 'Erfundenzutat-Extrakt', null, ['erfundenzutat-extrakt-ohne-anker']);

    expect(array_filter($ctx['files_used'], fn ($f) => str_starts_with($f, 'zutat.')))->toBeEmpty();
});
