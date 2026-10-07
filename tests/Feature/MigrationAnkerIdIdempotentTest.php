<?php

use Illuminate\Support\Facades\Schema;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

beforeEach(fn () => $this->seedTeamHierarchy());

/**
 * Spec 60 · 100001 muss auf DBs laufen, die die Spalte schon haben — die fa-pass-Variante
 * legte sie als 2026_10_06_000002_add_anchor_id_to_knowledge_documents an.
 */
it('add_anchor_id_to_knowledge_documents ist idempotent (Spalte und Index schon vorhanden)', function () {
    expect(Schema::hasColumn('foodalchemist_knowledge_documents', 'anchor_id'))->toBeTrue();

    $m = require __DIR__.'/../../database/migrations/2026_10_06_100001_add_anchor_id_to_knowledge_documents.php';
    $m->up();   // zweiter Lauf: vorher „Duplicate column name 'anchor_id'"

    expect(Schema::hasColumn('foodalchemist_knowledge_documents', 'anchor_id'))->toBeTrue()
        ->and(Schema::hasIndex('foodalchemist_knowledge_documents', 'fa_knowledge_anchor_idx'))->toBeTrue();
});
