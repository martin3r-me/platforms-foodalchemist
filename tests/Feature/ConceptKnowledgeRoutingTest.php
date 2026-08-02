<?php

use Illuminate\Support\Facades\DB;
use Platform\FoodAlchemist\Services\Ai\KnowledgeContextService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;
use Symfony\Component\Uid\UuidV7;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 08 P6 (Wissens-Ebene): Kategorie `concept` + Planungs-Routings
 * `foodbook.plan`/`concept.plan` — und der Konsum-Pfad im KnowledgeContextService.
 * Vorher lief `IdeenService::kiDivergenz` gegen ein Routing, das es in der DB
 * nicht gab (leerer Wissens-Block).
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->svc = app(KnowledgeContextService::class);

    $this->mkDoc = function (string $slug, string $kategorie, string $inhalt) {
        DB::table('foodalchemist_knowledge_documents')->insert([
            'uuid' => (string) UuidV7::generate(), 'slug' => $slug, 'title' => $slug,
            'category' => $kategorie, 'content_md' => $inhalt, 'version' => 1,
            'content_hash' => hash('sha256', $inhalt), 'char_count' => mb_strlen($inhalt),
            'active' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
    };
});

it('legt die Kategorie concept an und führt keine aktive konzept-Dublette', function () {
    $aktive = DB::table('foodalchemist_knowledge_categories')
        ->whereNull('team_id')->where('active', 1)->pluck('slug')->all();

    expect($aktive)->toContain('concept')
        ->and($aktive)->not->toContain('konzept');
});

it('seedet die Planungs-Routings inklusive der concept-Zeile', function () {
    $zeilen = DB::table('foodalchemist_knowledge_routings')
        ->whereIn('feature', ['foodbook.plan', 'concept.plan'])
        ->get()->map(fn ($r) => "{$r->feature}:{$r->category}:{$r->mode}")->all();

    expect($zeilen)->toHaveCount(7)   // + Trendradar: foodbook.plan:trend:discovery
        ->and($zeilen)->toContain('foodbook.plan:concept:always')
        ->and($zeilen)->toContain('concept.plan:concept:always')
        ->and($zeilen)->toContain('foodbook.plan:cross_cutting:always')
        ->and($zeilen)->toContain('concept.plan:domain:discovery');

    $deckel = DB::table('foodalchemist_knowledge_routings')
        ->where('feature', 'concept.plan')->where('category', 'concept')->first();
    expect((int) $deckel->max_docs)->toBe(4)
        ->and((int) $deckel->max_chars_per_doc)->toBe(4000);
});

it('hängt das Concepting-Wissen an Planungs-Calls — vor dem Food-Wissen', function () {
    ($this->mkDoc)('concept.menue_dramaturgie', 'concept', 'Spannungsbogen über die Gänge.');
    foreach (KnowledgeContextService::ALWAYS_LOAD_CROSS_CUTTING as $slug) {
        ($this->mkDoc)($slug, 'cross_cutting', "Wissen zu {$slug}");
    }

    $ctx = $this->svc->contextFor('concept.plan', 'Sommerliches Flying Buffet');

    expect($ctx['block'])->toContain('# CONCEPTING-WISSEN')
        ->and($ctx['block'])->toContain('## CONCEPT: concept.menue_dramaturgie')
        ->and($ctx['block'])->toContain('Spannungsbogen über die Gänge.')
        ->and($ctx['files_used'])->toContain('concept.menue_dramaturgie@v1')
        // Reihenfolge: Konzept-Handwerk rahmt das Food-Wissen darunter
        ->and(strpos($ctx['block'], '# CONCEPTING-WISSEN'))->toBeLessThan(strpos($ctx['block'], '# VAULT-WISSEN'));
});

it('bleibt bei leerer Kategorie ohne Block (Invariante 6)', function () {
    foreach (KnowledgeContextService::ALWAYS_LOAD_CROSS_CUTTING as $slug) {
        ($this->mkDoc)($slug, 'cross_cutting', "Wissen zu {$slug}");
    }

    $ctx = $this->svc->contextFor('foodbook.plan', 'Sommerliches Flying Buffet');

    expect($ctx['block'])->not->toContain('CONCEPTING-WISSEN')
        ->and($ctx['block'])->toContain('# VAULT-WISSEN');
});

it('respektiert den Routing-Deckel und kürzt mit Marker', function () {
    foreach (['a', 'b', 'c', 'd', 'e'] as $i => $s) {
        ($this->mkDoc)("concept.{$s}", 'concept', str_repeat('X', 5000) . 'ENDE');
    }

    $ctx = $this->svc->contextFor('concept.plan', 'Brief');

    expect(substr_count($ctx['block'], '## CONCEPT: '))->toBe(4)   // max_docs = 4
        ->and($ctx['block'])->toContain('[…gekürzt für KI-Kontext…]')
        ->and($ctx['block'])->not->toContain('ENDE')
        ->and($ctx['block'])->not->toContain('## CONCEPT: concept.e');  // Slug-stabile Reihenfolge
});

it('zieht keine Fremd-Kategorien in den Concept-Block', function () {
    ($this->mkDoc)('concept.balance', 'concept', 'Balance-Regeln.');
    ($this->mkDoc)('rindfleisch', 'domain', 'Domain-Wissen zu Rind.');
    ($this->mkDoc)('inaktiv', 'concept', 'Alt-Stand.');
    DB::table('foodalchemist_knowledge_documents')->where('slug', 'inaktiv')->update(['active' => 0]);

    $ctx = $this->svc->contextFor('concept.plan', 'Brief');
    $conceptTeil = substr($ctx['block'], 0, strpos($ctx['block'] . '# VAULT-WISSEN', '# VAULT-WISSEN'));

    expect($conceptTeil)->toContain('Balance-Regeln.')
        ->and($conceptTeil)->not->toContain('Domain-Wissen zu Rind.')
        ->and($ctx['block'])->not->toContain('Alt-Stand.');
});

it('lässt Rezept-Features unberührt (kein concept-Routing)', function () {
    ($this->mkDoc)('concept.balance', 'concept', 'Balance-Regeln.');
    foreach (KnowledgeContextService::ALWAYS_LOAD_CROSS_CUTTING as $slug) {
        ($this->mkDoc)($slug, 'cross_cutting', "Wissen zu {$slug}");
    }
    DB::table('foodalchemist_knowledge_routings')->insert([
        'feature' => 'ai_generate_recipe', 'category' => 'cross_cutting', 'mode' => 'always',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $ctx = $this->svc->contextFor('ai_generate_recipe', 'Lachs mit brauner Butter');

    expect($ctx['block'])->not->toContain('CONCEPTING-WISSEN')
        ->and($ctx['block'])->toContain('# VAULT-WISSEN');
});
