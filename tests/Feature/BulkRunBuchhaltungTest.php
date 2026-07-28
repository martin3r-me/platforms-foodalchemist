<?php

use Illuminate\Support\Facades\DB;
use Platform\FoodAlchemist\Enums\BulkRunStatus;
use Platform\FoodAlchemist\Enums\BulkRunType;
use Platform\FoodAlchemist\Models\FoodAlchemistBulkRun;
use Platform\FoodAlchemist\Services\BulkEnrichService;
use Platform\FoodAlchemist\Services\FileArticleImportService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 22 · H3a — die Lauf-Buchhaltung bekommt Vokabular (V-032) und einen
 * Gegenstand (V-047).
 *
 * Vier Zusicherungen, jede gegen einen benannten Befund:
 *  1. **Jede Lauf-Art ist ein Enum-Fall** — `type` war ein freier `string(32)`, und
 *     zwei der fünf Werte im Bestand stehen in keinem Migrations-Kommentar. Ein
 *     Tippfehler soll ab hier eine neue Lauf-Art nicht mehr still erzeugen können.
 *  2. **Jeder Schreiber legt seinen Gegenstand ab** — die Zeile sagt bisher DASS ein
 *     Lauf lief, nie WORAN.
 *  3. **Die uuid entsteht am Model** (`HasUuidV7`), nicht in vier handgeschriebenen
 *     Insert-Blöcken.
 *  4. **`ingest.STATUS` benennt den Lauf** statt ihn nur zu quittieren — das war die
 *     Zusage aus Spec 13 S3, die `laeufe_hinweis` bis heute einräumen musste.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->user = $this->makeUser($this->rootTeam);
    $this->actingAs($this->user);
});

it('legt einen Lauf mit Enum-Typ, Enum-Status und uuid an', function () {
    $run = FoodAlchemistBulkRun::starte($this->rootTeam->id, BulkRunType::Review, 7, ['pass' => 'bauart'], $this->user->id);

    expect($run->type)->toBe(BulkRunType::Review)
        ->and($run->status)->toBe(BulkRunStatus::Running)
        ->and($run->status->istOffen())->toBeTrue()
        ->and($run->total)->toBe(7)
        ->and($run->uuid)->not->toBeEmpty()
        ->and($run->user_id)->toBe($this->user->id)
        ->and($run->context)->toBe(['pass' => 'bauart']);

    // Roundtrip über die DB: der Cast darf nicht nur im Speicher gelten.
    $frisch = FoodAlchemistBulkRun::findOrFail($run->id);
    expect($frisch->type)->toBe(BulkRunType::Review)
        ->and($frisch->context['pass'])->toBe('bauart');
});

it('legt leeren Kontext als NULL ab statt als leeres Objekt', function () {
    // „kein Kontext" und „leerer Kontext" sollen dieselbe Antwort geben — sonst muss
    // jeder Leser beide Formen kennen.
    $run = FoodAlchemistBulkRun::starte($this->rootTeam->id, BulkRunType::Enrich, 1);

    expect(DB::table('foodalchemist_bulk_runs')->where('id', $run->id)->value('context'))->toBeNull()
        ->and(FoodAlchemistBulkRun::findOrFail($run->id)->context)->toBeNull();
});

it('kennt genau die fünf Lauf-Arten, die im Bestand geschrieben werden', function () {
    // Registry-Riegel im Muster von 22·H1 (V-003): jede Art braucht ein Label, und
    // die Menge selbst ist die Dokumentation — nicht der Migrations-Kommentar (V-020).
    expect(array_map(fn (BulkRunType $t) => $t->value, BulkRunType::cases()))
        ->toBe(['enrich', 'enrich_vk', 'enrich_gp', 'ingest', 'review']);

    foreach (BulkRunType::cases() as $typ) {
        expect($typ->label())->not->toBeEmpty();
    }
    foreach (BulkRunStatus::cases() as $status) {
        expect($status->label())->not->toBeEmpty();
    }

    // Der Datei-Import ist der einzige Lauf ohne Provider-Call.
    expect(BulkRunType::Ingest->istKiLauf())->toBeFalse()
        ->and(BulkRunType::EnrichGp->istKiLauf())->toBeTrue();
});

it('schreibt beim Anreicherungs-Lauf die Schrittfolge als Gegenstand mit', function () {
    $runId = app(BulkEnrichService::class)->laufAnlegen($this->rootTeam, 3, BulkRunType::EnrichVk, ['schritte' => ['wording']]);

    $run = FoodAlchemistBulkRun::findOrFail($runId);
    expect($run->type)->toBe(BulkRunType::EnrichVk)
        ->and($run->context['schritte'])->toBe(['wording']);
});

it('schreibt beim Datei-Import Datei und Lieferant als Gegenstand mit', function () {
    $runId = app(FileArticleImportService::class)->starteRun($this->rootTeam->id, 42, null, [
        'datei' => 'hanos_q3.csv', 'supplier_id' => 5, 'lieferant' => 'Hanos', 'apply' => true, 'quelle' => 'konsole',
    ]);

    $run = FoodAlchemistBulkRun::findOrFail($runId);
    expect($run->type)->toBe(BulkRunType::Ingest)
        ->and($run->status)->toBe(BulkRunStatus::Running)
        ->and($run->context['datei'])->toBe('hanos_q3.csv')
        ->and($run->context['supplier_id'])->toBe(5);
});
