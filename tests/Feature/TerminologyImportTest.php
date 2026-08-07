<?php

use Illuminate\Support\Facades\DB;
use Symfony\Component\Uid\UuidV7;
use Platform\FoodAlchemist\Console\TerminologyImportCommand;
use Platform\FoodAlchemist\Models\FoodAlchemistTerminologyAntiMarker;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

/**
 * P1 (2026-08-07): Brücke Wissens-Doc-Gold-Tabelle → deterministische Anti-Marker-Negativliste.
 * Parser ist rein/DB-los; der Apply-Pfad schreibt provenienz-getaggt + idempotent.
 */
uses(TestCase::class, SeedsTeamHierarchy::class);

// seedTeamHierarchy() triggert die :memory:-Migration (diese Suite bindet kein RefreshDatabase).
beforeEach(fn () => $this->seedTeamHierarchy());

$TABELLE = <<<'MD'
# Anti-Marker

## Klassische Top-10-Fallen (Gold)

| # | String | Falsch-Match-Risiko | Korrekte Domain | Wie unterscheiden |
|---|--------|---------------------|-----------------|-------------------|
| 1 | **Brie** | Bries (Kalbsthymus) | Kaese (Weichkaese) | Brie = Molkerei; Bries = Innerei (mit 's') |
| 2 | **Triple Sec** | Triple Chocolate Cookie / Schoko-Praline | Spirituosen | Volumen vs. Stueck |

## Per Domain konsolidiert
- freitext, wird bewusst NICHT geparst
MD;

it('parseAntiMarkers liest die Gold-Tabelle deterministisch (trigger ↛ letztes Token je /-Term)', function () use ($TABELLE) {
    $cmd = new TerminologyImportCommand();
    $regeln = $cmd->parseAntiMarkers($TABELLE);

    // Brie ↛ bries (Klammer-Zusatz entfernt); Triple Sec ↛ cookie UND ↛ praline (/-Split, letztes Token)
    $paare = array_map(fn ($r) => $r['trigger'] . '↛' . $r['forbid'], $regeln);
    expect($paare)->toContain('brie↛bries')
        ->and($paare)->toContain('triple sec↛cookie')
        ->and($paare)->toContain('triple sec↛praline')
        // Prosa-Liste + Header/Trenn-Zeile erzeugen KEINE Regeln
        ->and(count($regeln))->toBe(3);
});

it('--apply schreibt provenienz-getaggt in die Negativliste und ist idempotent', function () use ($TABELLE) {
    DB::table('foodalchemist_knowledge_documents')->insert([
        'uuid' => (string) UuidV7::generate(), 'slug' => 'anti_marker', 'title' => 'Anti-Marker',
        'category' => 'cross_cutting', 'content_md' => $TABELLE, 'version' => 1,
        'content_hash' => hash('sha256', $TABELLE), 'char_count' => mb_strlen($TABELLE),
        'active' => 1, 'created_at' => now(), 'updated_at' => now(),
    ]);

    $this->artisan('foodalchemist:terminology-import', ['--apply' => true])->assertSuccessful();

    $rows = FoodAlchemistTerminologyAntiMarker::query()->where('created_via', 'knowledge_import')->get();
    expect($rows)->toHaveCount(3)
        ->and($rows->firstWhere('forbid_token', 'bries')?->trigger_token)->toBe('brie');

    // Zweiter Lauf legt NICHTS doppelt an (Dedup auf trigger+forbid).
    $this->artisan('foodalchemist:terminology-import', ['--apply' => true])->assertSuccessful();
    expect(FoodAlchemistTerminologyAntiMarker::query()->where('created_via', 'knowledge_import')->count())->toBe(3);
});

it('Default ist dry-run — ohne --apply wird NICHTS geschrieben', function () use ($TABELLE) {
    DB::table('foodalchemist_knowledge_documents')->insert([
        'uuid' => (string) UuidV7::generate(), 'slug' => 'anti_marker', 'title' => 'Anti-Marker',
        'category' => 'cross_cutting', 'content_md' => $TABELLE, 'version' => 1,
        'content_hash' => hash('sha256', $TABELLE), 'char_count' => mb_strlen($TABELLE),
        'active' => 1, 'created_at' => now(), 'updated_at' => now(),
    ]);

    $this->artisan('foodalchemist:terminology-import')->assertSuccessful();

    expect(FoodAlchemistTerminologyAntiMarker::query()->where('created_via', 'knowledge_import')->count())->toBe(0);
});

// ── Move 2 (2026-08-07): Synonym-Tabelle → deterministische Alias-Gruppen (additiv) ──

$SYN = <<<'MD'
# Synonyme & Schreibvarianten

## Cross-Sprachliche Synonyme
### Fisch & Seafood
| Begriff | gleichbedeutend mit | Domain |
|---------|---------------------|--------|
| Wolfsbarsch | Loup de Mer / Branzino / Sea Bass | Fisch_Seafood |
| Forelle | Trout | Fisch_Seafood |

## Freitext — wird NICHT geparst
- irgendwas
MD;

it('parseAliases liest Synonym-Tabellen deterministisch (Begriff + /,-getrennte Synonyme, ≥2 Glieder)', function () use ($SYN) {
    $gruppen = (new \Platform\FoodAlchemist\Console\TerminologyImportCommand())->parseAliases($SYN);

    expect($gruppen)->toHaveCount(2);
    $wolf = collect($gruppen)->first(fn ($g) => in_array('wolfsbarsch', $g, true));
    expect($wolf)->toContain('branzino')->toContain('loup de mer')->toContain('sea bass')
        ->and(collect($gruppen)->first(fn ($g) => in_array('forelle', $g, true)))->toContain('trout');
});

it('--kind=alias --apply schreibt Alias-Gruppen provenienz-getaggt und ist idempotent', function () use ($SYN) {
    DB::table('foodalchemist_knowledge_documents')->insert([
        'uuid' => (string) UuidV7::generate(), 'slug' => 'synonyme', 'title' => 'Synonyme',
        'category' => 'cross_cutting', 'content_md' => $SYN, 'version' => 1,
        'content_hash' => hash('sha256', $SYN), 'char_count' => mb_strlen($SYN),
        'active' => 1, 'created_at' => now(), 'updated_at' => now(),
    ]);

    $this->artisan('foodalchemist:terminology-import', ['--kind' => 'alias', '--apply' => true])->assertSuccessful();
    expect(\Platform\FoodAlchemist\Models\FoodAlchemistTerminologyAlias::query()->where('created_via', 'knowledge_import')->count())->toBe(2);

    $this->artisan('foodalchemist:terminology-import', ['--kind' => 'alias', '--apply' => true])->assertSuccessful();
    expect(\Platform\FoodAlchemist\Models\FoodAlchemistTerminologyAlias::query()->where('created_via', 'knowledge_import')->count())->toBe(2);
});
