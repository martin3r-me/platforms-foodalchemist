<?php

use Illuminate\Support\Facades\DB;
use Platform\FoodAlchemist\Services\Ai\KnowledgeContextService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;
use Symfony\Component\Uid\UuidV7;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 53 Paket B, Rückfrage der Orchestrierung zu Aufgabe 2 (Query-Hygiene): aroma_kueche
 * (z. B. "thai") und diaet_hart (z. B. "vegan") tragen fachliche Information, die oft NICHT im
 * Brief-Text steht, und haben — anders als niveau/saison/occasion/… — keinen eigenen
 * deterministischen Selektor, der ihre Kategorie (weltkueche/ernaehrung) sonst erden würde.
 *
 * Erst-Messung (vor diesem Fix): beide Fixture-Dossiers verschwanden komplett aus der Discovery,
 * sobald die Info nur in der Leitplanke stand. Deshalb bleiben GENAU diese zwei Schlüssel als
 * Ausnahme in `discoveryQuery()` — dieser Test ist der Regressions-Wächter dafür.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();

    $this->mkDoc = function (string $slug, string $category, string $inhalt) {
        DB::table('foodalchemist_knowledge_documents')->insert([
            'uuid' => (string) UuidV7::generate(), 'slug' => $slug, 'title' => $slug,
            'category' => $category, 'content_md' => $inhalt, 'version' => 1,
            'content_hash' => hash('sha256', $slug . $inhalt), 'char_count' => mb_strlen($inhalt),
            'active' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
    };
    $this->mkRouting = function (string $feature, string $category, string $mode, ?int $maxDocs = null) {
        DB::table('foodalchemist_knowledge_routings')->insert([
            'feature' => $feature, 'category' => $category, 'mode' => $mode,
            'max_docs' => $maxDocs, 'max_chars_per_doc' => null,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    };
});

it('aroma_kueche=thai OHNE das Wort im Brief findet trotzdem das weltkueche-Dossier', function () {
    // Lexikalisches Ranking (KnowledgeSearchService) scort gegen slug+title, nicht content_md —
    // die Fixture braucht darum "thai" als EIGENES Token im Slug (wie reale Küchen-Slugs es tun),
    // nicht nur als Adjektiv-Präfix ("thailaendisch" teilt kein ≥5-Zeichen-Substring mit "thai").
    $feature = 'test.kueche_diaet_frage';
    ($this->mkDoc)('weltkueche-thai-kueche', 'weltkueche', 'Thailändische Küche: Zitronengras, Galgant, Fischsauce, Kokos, Chili.');
    ($this->mkDoc)('weltkueche-italienische-kueche', 'weltkueche', 'Italienische Küche: Olivenöl, Parmesan, Basilikum, Tomate.');
    ($this->mkRouting)($feature, 'weltkueche', 'discovery', 1);

    $brief = 'Hauptgang mit Huhn, Gemüse und Reis, cremig abgeschmeckt';

    $ctx = app(KnowledgeContextService::class)->contextFor(
        null, $feature, $brief, null, [], ['aroma_kueche' => 'thai']
    );

    $slugs = array_map(fn ($f) => explode('@', $f)[0], $ctx['files_used']);
    expect($slugs)->toContain('weltkueche-thai-kueche')
        ->and($slugs)->not->toContain('weltkueche-italienische-kueche');
});

it('diaet_hart=vegan OHNE das Wort im Brief findet trotzdem das ernaehrung-Dossier', function () {
    $feature = 'test.kueche_diaet_frage_2';
    ($this->mkDoc)('ernaehrung-vegan', 'ernaehrung', 'Vegane Küche: pflanzliche Proteinquellen, Bindemittel ohne Ei, Milchersatz.');
    ($this->mkDoc)('ernaehrung-fisch', 'ernaehrung', 'Fischgerichte: Grätenfreiheit, Garpunkt, Frische-Kriterien.');
    ($this->mkRouting)($feature, 'ernaehrung', 'discovery', 1);

    $brief = 'Vorspeise mit Linsen, Kichererbsen und geröstetem Gemüse';

    $ctx = app(KnowledgeContextService::class)->contextFor(
        null, $feature, $brief, null, [], ['diaet_hart' => 'vegan']
    );

    $slugs = array_map(fn ($f) => explode('@', $f)[0], $ctx['files_used']);
    expect($slugs)->toContain('ernaehrung-vegan')
        ->and($slugs)->not->toContain('ernaehrung-fisch');
});

it('die uebrigen entfernten Leitplanken bleiben draussen — keine stille Rueckkehr der alten Liste', function () {
    $feature = 'test.kueche_diaet_kontrolle';
    ($this->mkDoc)('convenience-stufen', 'convenience_kat', 'Convenience-Stufen: küchenfertig, garfertig, verzehrfertig.');
    ($this->mkRouting)($feature, 'convenience_kat', 'discovery', 1);

    $brief = 'Suppe mit Gemüse';

    $ctx = app(KnowledgeContextService::class)->contextFor(
        null, $feature, $brief, null, [], ['convenience' => 'kuechenfertig']
    );

    expect($ctx['files_used'])->not->toContain('convenience-stufen@v1');
});
