<?php

use Illuminate\Support\Facades\DB;
use Platform\FoodAlchemist\Services\RecipeGenerationContextService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;
use Symfony\Component\Uid\UuidV7;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 53 Paket H Aufgabe B4 — leichte Vor-Sondierung: `RecipeGenerationContextService::build()`
 * muss `hauptzutatSlugs` (rohe Leit-Tokens des Briefs, {@see TokenEngine::leitTokens()}) an
 * `contextFor()` durchreichen, DAMIT `zutatGroundingBlock()` (Aufgabe B2) überhaupt etwas zu
 * tun bekommt — vorher lief `[]` fest verdrahtet rein.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();

    DB::table('foodalchemist_vocab_pairing_anchors')->insert([
        'uuid' => (string) UuidV7::generate(), 'slug' => 'passionsfrucht', 'display_de' => 'Passionsfrucht',
        'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('foodalchemist_knowledge_documents')->insert([
        'uuid' => (string) UuidV7::generate(), 'slug' => 'zutat.passionsfrucht--verwendung',
        'title' => 'Passionsfrucht Verwendung', 'category' => 'zutat', 'content_md' => 'Verwendung der Passionsfrucht',
        'version' => 1, 'content_hash' => hash('sha256', 'zutat.passionsfrucht--verwendung'), 'char_count' => 30,
        'active' => 1, 'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('foodalchemist_knowledge_routings')->insert([
        'feature' => 'recipe.generator', 'category' => 'zutat', 'mode' => 'grounding',
        'max_docs' => 8, 'max_chars_per_doc' => null, 'created_at' => now(), 'updated_at' => now(),
    ]);
});

it('reicht Leit-Tokens des Briefs als hauptzutatSlugs an contextFor() durch, das Zutat-Grounding greift', function () {
    $result = app(RecipeGenerationContextService::class)->build(
        $this->rootTeam, 'Passionsfrucht-Gelee mit Acerola, 40 Portionen', [], false,
    );

    expect($result['knowledge_used'])->toContain('zutat.passionsfrucht--verwendung@v1');
});
