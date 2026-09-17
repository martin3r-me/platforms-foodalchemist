<?php

use Illuminate\Support\Facades\DB;
use Platform\FoodAlchemist\Services\Ai\KnowledgeContextBlock;
use Platform\FoodAlchemist\Services\Knowledge\KnowledgePreviewService;
use Platform\FoodAlchemist\Tests\Support\SeedsKanon;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;
use Symfony\Component\Uid\UuidV7;

uses(TestCase::class, SeedsTeamHierarchy::class, SeedsKanon::class);

/**
 * Spec 53 Paket B Aufgabe 8 — fachliches Zielbild Tomatensuppe als Golden-Test.
 *
 * Läuft über `KnowledgePreviewService::preview()` — denselben Pfad wie MCP `knowledge.PREVIEW`
 * (die Golden-Referenz „vorher" für diesen Brief kam aus genau diesem Aufruf gegen demo) — statt
 * nur `KnowledgeContextService::contextFor()` zu prüfen: das Zielbild nennt Kanon (§2/§6)
 * ausdrücklich, und Kanon ist ein eigener Kanal, den `contextFor()` allein nicht zurückgibt.
 *
 * Kriterium ist „unterschiedliche fachliche Fragen abgedeckt" (Zutat, Technik, Regel), NICHT
 * „mehr Quellen". Die drei Lärm-Kategorien (`convenience`, `niveau`, `allergen_patterns--ramen`)
 * sind der Regressions-Wächter für Aufgabe 2 (Query-Hygiene) und Aufgabe 3 (Kompositum): ohne die
 * beiden Fixes gewinnen sie die Discovery per Leitplanken-Verdünnung bzw. weil „tomatensuppe" als
 * Kompositum ihre generischen Slugs nicht schlechter trifft als die Fachdomäne.
 *
 * ★ Ursprünglich als `RecipeRetrievalQualityTest.php` angelegt — beim Rebase auf Pauls PR #94
 * (der unabhängig einen gleichnamigen Test für Paket A anlegte) umbenannt, um den Add/Add-Konflikt
 * sauber zu trennen: Pauls Datei bleibt unverändert (Grounding/Dosentomaten/Reifegrad/Semantik),
 * diese Datei trägt die Wissens-Golden-Fälle aus Paket B.
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

it('Tomatensuppen-Brief: Domain Tomate + Suppen/Fonds + Mengen-Defaults + Kanon §2/§6 — keine Lärm-Kategorien', function () {
    $feature = 'test.golden_tomatensuppe';
    // Array-Zugriff statt Punkt-Pfad: der Feature-Key enthält selbst einen Punkt, ein
    // `config()->set('foodalchemist.prompts.test.golden_tomatensuppe', ...)` würde ihn als
    // verschachtelten Pfad (['test']['golden_tomatensuppe']) statt als Schlüssel interpretieren.
    config(['foodalchemist.prompts' => array_merge(
        (array) config('foodalchemist.prompts', []),
        [$feature => ['tier' => 'B', 'task' => 'x']],
    )]);
    $brief = 'Basisrezept für eine Tomatensuppe im Ansatz sollen Steckwürfel sein, einen klassischen '
        . 'Ansatz mit Gemüse, Staudensellerie, ein bisschen Sahne drin, mit Olivenöl eingemixt und '
        . 'auch mit etwas Basilikum';

    // Zutat-Frage: Domain-Dossiers zu Tomate und Suppen/Fonds. Lexikalisches Ranking
    // (KnowledgeSearchService) scort gegen slug+title, nicht content_md — die diskriminierenden
    // Wörter müssen darum im Slug stehen, nicht nur in der Prosa.
    ($this->mkDoc)('fruchtgemuese-tomate-verwendung', 'domain', 'Tomate: Verwendung, Säure-Balance, Garzeiten im Ansatz.');
    ($this->mkDoc)('fonds-bruehen-ansatz-staudensellerie', 'domain', 'Fonds und Brühen: klassischer Ansatz, Auszug, Klärung, Staudensellerie als Röstgemüse.');
    ($this->mkRouting)($feature, 'domain', 'discovery', 4);

    // Mengen-Frage: Cross-Cutting Mengen-Defaults (Pflicht-Rahmenwissen).
    ($this->mkDoc)('mengen_defaults', 'cross_cutting', 'Hauptgang-Komponenten: Richtwerte pro Portion, Suppen-Ansatz-Faktor.');
    ($this->mkRouting)($feature, 'cross_cutting', 'always');

    // Regel-Frage: Kanon Basisrezepte §2 (Verarbeitungs-Reduktion) + §6 (Mengen/Yield).
    ($this->mkDoc)('regelwerk-basisrezepte-2-verarbeitungs-reduktion', 'regelwerk', 'Basisrezepte §2: Verarbeitungs-Reduktion — Brunoise/Steckwürfel zur Roh-Form.');
    ($this->mkDoc)('regelwerk-basisrezepte-6-mengen-einheiten-yield', 'regelwerk', 'Basisrezepte §6: Mengen, Einheiten, Yield-Berechnung im Ansatz.');
    $this->kanonZeile($this->rootTeam->id, $feature, 'regelwerk-basisrezepte-2-verarbeitungs-reduktion');
    $this->kanonZeile($this->rootTeam->id, $feature, 'regelwerk-basisrezepte-6-mengen-einheiten-yield');

    // Lärm-Kategorien: teilen keine Hauptzutat-/Technik-Tokens mit dem Brief, dürfen NICHT gewinnen.
    ($this->mkDoc)('convenience_und_recipe_engineering', 'convenience', 'Convenience-Stufen und Recipe Engineering für die Systemgastronomie.');
    ($this->mkRouting)($feature, 'convenience', 'discovery', 2);
    ($this->mkDoc)('niveau.niveau_premium_fine_dining', 'niveau', 'Küchenlinie Standard: Portionsgrößen und Serviceerwartung für die tägliche Betriebsgastronomie.');
    ($this->mkRouting)($feature, 'niveau', 'discovery', 1);
    ($this->mkDoc)('allergen_patterns--ramen', 'allergen_patterns', 'Ramen-spezifische Allergenmuster: Weizen, Soja, Ei in der Nudel.');
    ($this->mkRouting)($feature, 'allergen_patterns', 'discovery', 2);

    $ctx = app(KnowledgePreviewService::class)->preview($this->rootTeam, $feature, $brief);

    $retrievalSlugs = array_map(fn ($f) => explode('@', $f)[0], $ctx['retrieval']);
    $kanonSlugs = array_map(fn ($f) => explode('@', $f)[0], $ctx['kanon']);

    expect($retrievalSlugs)->toContain('fruchtgemuese-tomate-verwendung')
        ->and($retrievalSlugs)->toContain('fonds-bruehen-ansatz-staudensellerie')
        ->and($retrievalSlugs)->toContain('mengen_defaults')
        ->and($kanonSlugs)->toContain('regelwerk-basisrezepte-2-verarbeitungs-reduktion')
        ->and($kanonSlugs)->toContain('regelwerk-basisrezepte-6-mengen-einheiten-yield')
        // Lärm-Kategorien: dürfen in KEINEM der beiden Kanäle auftauchen.
        ->and([...$retrievalSlugs, ...$kanonSlugs])->not->toContain('convenience_und_recipe_engineering')
        ->and([...$retrievalSlugs, ...$kanonSlugs])->not->toContain('niveau.niveau_premium_fine_dining')
        ->and([...$retrievalSlugs, ...$kanonSlugs])->not->toContain('allergen_patterns--ramen');
});

/*
 * Aufgabe 4 (Budget nach Rang), mechanischer Beleg auf Ebene von KnowledgeContextBlock::assemble()
 * — bewusst OHNE Tokenizer/RRF: ein grosses Rang-1-Dossier einer Kategorie mit NIEDRIGEM Score
 * (z. B. ein Domain-Treffer mit schwacher Relevanz) verliert gegen ein kleines Dossier einer
 * ANDEREN Kategorie mit HÖHEREM Score, sobald das Budget knapp wird — unabhängig davon, welcher
 * Block zuerst gebaut wurde (`$blockGrossNiedrig` steht zuerst in der `$blocks`-Liste und wäre in
 * der alten Einfügereihenfolge gewonnen). Ein deterministisches Dossier (Score 1.0, s.
 * `KnowledgeContextService::DETERMINISTISCHER_SCORE`) wird von KEINER Fuzzy-Discovery verdrängt.
 */
it('Budget nach Rang: großes Rang-1-Dossier mit niedrigem Score verliert gegen kleines mit hohem Score', function () {
    $deterministisch = new KnowledgeContextBlock('', [
        ['file' => 'niveau-doc', 'text' => str_repeat('N', 50), 'score' => 1.0],
    ]);
    $blockGrossNiedrig = new KnowledgeContextBlock('', [
        ['file' => 'domain-gross-schwach', 'text' => str_repeat('D', 2000), 'score' => 0.02],
    ]);
    $blockKleinHoch = new KnowledgeContextBlock('', [
        ['file' => 'kueche-klein-stark', 'text' => str_repeat('K', 100), 'score' => 0.03],
    ]);

    // Budget reicht für Deterministisch (50) + klein/hoch (100) = 150, aber NICHT zusätzlich für
    // gross/niedrig (+2000 = 2150).
    $result = KnowledgeContextBlock::assemble(
        [$blockGrossNiedrig, $blockKleinHoch, $deterministisch], [], 200, 'test.budget_nach_rang'
    );

    expect($result['files_used'])
        ->toContain('niveau-doc')          // deterministisch: nie verdrängt
        ->toContain('kueche-klein-stark')  // höherer Score gewinnt den Restplatz
        ->not->toContain('domain-gross-schwach'); // Rang-1-in-der-eigenen-Kategorie schützt nicht vor dem Budget-Schnitt
});
