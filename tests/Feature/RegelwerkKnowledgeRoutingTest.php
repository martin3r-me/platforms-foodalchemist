<?php

use Illuminate\Support\Facades\DB;
use Platform\FoodAlchemist\Services\Ai\KnowledgeContextService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;
use Symfony\Component\Uid\UuidV7;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Etappe 1 (Planung »Mise en Place«): Wissens-Erdung des Rezept-Generators am Regelwerk
 * Basisrezepte. Routing `ai_generate_recipe:regelwerk:always` (Migration 2026_08_14_000010)
 * + dedizierter Konsum-Pfad KnowledgeContextService::regelwerkBlock (§2–§4-Extraktion).
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

    // Regelwerk-Doc mit realistischer §-Struktur: §1 vor der Kern-Region, §5 danach.
    $this->regelwerkMd = "# Regelwerk Basisrezepte\n\n## §1 Naming\nNaming-Regeln.\n\n"
        . "## §2 Verarbeitungs-Reduktion\nBrunoise → Roh-Form.\n\n"
        . "## §3 Pürees / Marks / Coulis\nFrucht = Boiron-Tentative.\n\n"
        . "## §4 Sub-Rezept-Hierarchie\nSauce/Jus/Püree = eigenes Sub-Rezept.\n\n"
        . "## §5 Default-GPs\nMilch, Olivenöl, Zucker.\n";

    // Das cross_cutting-Routing des Rezept-Generators wird per Import (seedRoutings), nicht per
    // Migration geseedet → im Test explizit setzen, wo VAULT-WISSEN als Rahmen gebraucht wird.
    $this->seedCrossCutting = function () {
        DB::table('foodalchemist_knowledge_routings')->insertOrIgnore([
            'feature' => 'ai_generate_recipe', 'category' => 'cross_cutting', 'mode' => 'always',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    };
});

it('seedet die regelwerk-Routing-Zeile für den Rezept-Generator', function () {
    $zeile = DB::table('foodalchemist_knowledge_routings')
        ->where('feature', 'ai_generate_recipe')->where('category', 'regelwerk')->first();

    expect($zeile)->not->toBeNull()
        ->and($zeile->mode)->toBe('always')
        ->and((int) $zeile->max_docs)->toBe(1)
        ->and((int) $zeile->max_chars_per_doc)->toBe(7000);
});

it('hängt das Regelwerk-Basisrezepte an den Rezept-Generator — mit §2–§4, vor dem Food-Wissen', function () {
    ($this->seedCrossCutting)();
    ($this->mkDoc)('regelwerk.regelwerk_basisrezepte', 'regelwerk', $this->regelwerkMd);
    foreach (KnowledgeContextService::ALWAYS_LOAD_CROSS_CUTTING as $slug) {
        ($this->mkDoc)($slug, 'cross_cutting', "Wissen zu {$slug}");
    }

    $ctx = $this->svc->contextFor('ai_generate_recipe', 'Steinpilz-Risotto mit Rinderjus');

    expect($ctx['block'])->toContain('# REGELWERK BASISREZEPTE')
        ->and($ctx['block'])->toContain('## §2 Verarbeitungs-Reduktion')
        ->and($ctx['block'])->toContain('## §4 Sub-Rezept-Hierarchie')
        ->and($ctx['files_used'])->toContain('regelwerk.regelwerk_basisrezepte@v1')
        // §1 (vor der Region) und §5 (nach der Region) sind bewusst NICHT im Ausschnitt
        ->and($ctx['block'])->not->toContain('## §1 Naming')
        ->and($ctx['block'])->not->toContain('## §5 Default-GPs')
        // Reihenfolge: Bau-Regel rahmt das Food-Wissen darunter
        ->and(strpos($ctx['block'], '# REGELWERK BASISREZEPTE'))->toBeLessThan(strpos($ctx['block'], '# VAULT-WISSEN'));
});

it('bleibt ohne Regelwerk-Doc ohne Block (Invariante 6)', function () {
    ($this->seedCrossCutting)();
    foreach (KnowledgeContextService::ALWAYS_LOAD_CROSS_CUTTING as $slug) {
        ($this->mkDoc)($slug, 'cross_cutting', "Wissen zu {$slug}");
    }

    $ctx = $this->svc->contextFor('ai_generate_recipe', 'Lachs mit brauner Butter');

    expect($ctx['block'])->not->toContain('# REGELWERK BASISREZEPTE')
        ->and($ctx['block'])->toContain('# VAULT-WISSEN');
});

it('wählt gezielt das Basisrezepte-Regelwerk, nicht andere regelwerk-Docs', function () {
    ($this->mkDoc)('regelwerk.regelwerk_grundprodukte', 'regelwerk', "## §2 Foo\nGP-Regel-Text.\n\n## §5 Bar\n");
    ($this->mkDoc)('regelwerk.regelwerk_basisrezepte', 'regelwerk', $this->regelwerkMd);

    $ctx = $this->svc->contextFor('ai_generate_recipe', 'Beliebiges Gericht');

    expect($ctx['block'])->toContain('Sauce/Jus/Püree = eigenes Sub-Rezept.')
        ->and($ctx['block'])->not->toContain('GP-Regel-Text.')
        ->and($ctx['files_used'])->toContain('regelwerk.regelwerk_basisrezepte@v1')
        ->and($ctx['files_used'])->not->toContain('regelwerk.regelwerk_grundprodukte@v1');
});

it('respektiert den Zeichen-Deckel und kürzt mit Marker', function () {
    $gross = "## §2 Verarbeitungs-Reduktion\n" . str_repeat('X', 9000) . 'ENDE';   // kein §5 → §2 bis Doc-Ende
    ($this->mkDoc)('regelwerk.regelwerk_basisrezepte', 'regelwerk', $gross);
    DB::table('foodalchemist_knowledge_routings')
        ->where('feature', 'ai_generate_recipe')->where('category', 'regelwerk')
        ->update(['max_chars_per_doc' => 500]);

    $ctx = $this->svc->contextFor('ai_generate_recipe', 'Brief');

    expect($ctx['block'])->toContain('[…gekürzt für KI-Kontext…]')
        ->and($ctx['block'])->not->toContain('ENDE');
});

it('ignoriert inaktive regelwerk-Docs', function () {
    ($this->mkDoc)('regelwerk.regelwerk_basisrezepte', 'regelwerk', $this->regelwerkMd);
    DB::table('foodalchemist_knowledge_documents')
        ->where('slug', 'regelwerk.regelwerk_basisrezepte')->update(['active' => 0]);

    $ctx = $this->svc->contextFor('ai_generate_recipe', 'Brief');

    expect($ctx['block'])->not->toContain('# REGELWERK BASISREZEPTE');
});
