<?php

use Illuminate\Support\Facades\DB;
use Platform\FoodAlchemist\Services\Ai\KnowledgeSearchService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;
use Symfony\Component\Uid\UuidV7;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 53 Paket H Aufgabe A — Gruppen-Dedup im Discovery.
 *
 * Befund (PREVIEW-Messung `recipe.generator`/`recipe.steps`, Brief "Passionsfrucht-Gelee mit
 * Acerola, 40 Portionen"): `zutat.acerola_14--*` belegte alle 4 Aspekte (steckbrief/verwendung/
 * verhalten/cave), `zutat.passion_fruit--*` nur 2 von 4, das Technik-Dossier
 * `frucht-gelees-praxis-dosierungen-apfel-birne-ananas` fiel bei `recipe.steps` komplett raus
 * (dropped_chars 33.104 von insgesamt ~50k Kandidat-Zeichen). Eine Aspekt-Familie darf nicht
 * mehrere Plätze derselben Entität belegen.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();

    $this->mkDoc = function (string $slug, string $title, string $category = 'zutat') {
        DB::table('foodalchemist_knowledge_documents')->insert([
            'uuid' => (string) UuidV7::generate(), 'slug' => $slug, 'title' => $title,
            'category' => $category, 'content_md' => "Wissen zu {$title}", 'version' => 1,
            'content_hash' => hash('sha256', $slug), 'char_count' => 20,
            'active' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
    };
});

it('dedupliziert eine Aspekt-Familie auf einen Platz, ohne $preferredAspect gewinnt der Score', function () {
    ($this->mkDoc)('zutat.testfrucht--steckbrief', 'Testfrucht — Einkauf, Handelsformen & Ausbeute');
    ($this->mkDoc)('zutat.testfrucht--verwendung', 'Testfrucht — Rollen, Zubereitungen & Baukasten');
    ($this->mkDoc)('zutat.testfrucht--verhalten', 'Testfrucht — Verhalten unter Hitze');
    ($this->mkDoc)('zutat.testfrucht--cave', 'Testfrucht — Cave: Fallen, Lager, Service');
    ($this->mkDoc)('fremdes-technik-dossier', 'Technik-Dossier — Ausbeute-Berechnung ohne Testfrucht-Bezug');

    $base = DB::table('foodalchemist_knowledge_documents')->whereIn('slug', [
        'zutat.testfrucht--steckbrief', 'zutat.testfrucht--verwendung',
        'zutat.testfrucht--verhalten', 'zutat.testfrucht--cave', 'fremdes-technik-dossier',
    ]);
    $hits = app(KnowledgeSearchService::class)->search($base, 'Testfrucht Ausbeute', 2, semantic: false);

    expect($hits)->toHaveCount(2);
    $entitaeten = array_map(
        fn ($h) => str_contains($h['slug'], '--') ? strstr($h['slug'], '--', true) : $h['slug'],
        $hits,
    );
    expect(array_unique($entitaeten))->toHaveCount(2)
        ->and(array_column($hits, 'slug'))->toContain('fremdes-technik-dossier');
});

it('bevorzugter Aspekt gewinnt gegen den Score-Sieger derselben Entität', function () {
    // "Ausbeute" taucht nur im Steckbrief-Titel auf -> steckbrief scort lexikalisch hoeher als
    // verhalten, das nur "testfrucht" trifft. $preferredAspect muss trotzdem verhalten liefern.
    ($this->mkDoc)('zutat.testfrucht--steckbrief', 'Testfrucht — Einkauf, Handelsformen & Ausbeute');
    ($this->mkDoc)('zutat.testfrucht--verhalten', 'Testfrucht — Verhalten unter Hitze');

    $base = DB::table('foodalchemist_knowledge_documents')->whereIn('slug', [
        'zutat.testfrucht--steckbrief', 'zutat.testfrucht--verhalten',
    ]);
    $ohneAspekt = app(KnowledgeSearchService::class)->search($base, 'Testfrucht Ausbeute', 5, semantic: false);
    expect($ohneAspekt)->toHaveCount(1)
        ->and($ohneAspekt[0]['slug'])->toBe('zutat.testfrucht--steckbrief');

    $mitAspekt = app(KnowledgeSearchService::class)->search($base, 'Testfrucht Ausbeute', 5, semantic: false, preferredAspect: 'verhalten');
    expect($mitAspekt)->toHaveCount(1)
        ->and($mitAspekt[0]['slug'])->toBe('zutat.testfrucht--verhalten')
        ->and($mitAspekt[0]['aspekt_fallback'] ?? null)->toBeNull();
});

it('faellt bei fehlendem Aspekt in der Gruppe auf den Score-Sieger zurueck und markiert das ehrlich', function () {
    ($this->mkDoc)('zutat.testfrucht--steckbrief', 'Testfrucht — Einkauf, Handelsformen & Ausbeute');
    ($this->mkDoc)('zutat.testfrucht--verwendung', 'Testfrucht — Rollen, Zubereitungen & Baukasten');

    $base = DB::table('foodalchemist_knowledge_documents')->whereIn('slug', [
        'zutat.testfrucht--steckbrief', 'zutat.testfrucht--verwendung',
    ]);
    // "cave" existiert in dieser Gruppe nicht -> Rueckfall auf den Score-Sieger (steckbrief, s.o.).
    $hits = app(KnowledgeSearchService::class)->search($base, 'Testfrucht Ausbeute', 5, semantic: false, preferredAspect: 'cave');

    expect($hits)->toHaveCount(1)
        ->and($hits[0]['slug'])->toBe('zutat.testfrucht--steckbrief')
        ->and($hits[0]['aspekt_fallback'])->toBeTrue();
});

it('laesst Dossiers ohne "--" unangetastet, auch wenn mehrere hoch scoren', function () {
    ($this->mkDoc)('fonds-jus-consomme-kennwerte', 'Fonds, Jus & Consommé — Kennwerte', 'domain');
    ($this->mkDoc)('kerntemperaturen-rind-kalb-lamm-wild', 'Kerntemperaturen Rind, Kalb, Lamm, Wild', 'domain');

    $base = DB::table('foodalchemist_knowledge_documents')->where('category', 'domain');
    $hits = app(KnowledgeSearchService::class)->search($base, 'Kennwerte Kerntemperaturen', 5, semantic: false);

    expect(array_column($hits, 'slug'))
        ->toContain('fonds-jus-consomme-kennwerte')
        ->toContain('kerntemperaturen-rind-kalb-lamm-wild');
});
