<?php

use Illuminate\Support\Facades\DB;
use Platform\FoodAlchemist\Services\Ai\KnowledgeContextService;
use Platform\FoodAlchemist\Support\TeamScope;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;
use Symfony\Component\Uid\UuidV7;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * STOLPERDRAHT für einen Produktentscheid, nicht für einen Bug.
 *
 * Ein Voll-Audit hat am 2026-09-02 dreiundzwanzig ungescopete Doc-Queries im Retrieval
 * gefunden und als Mandanten-Lecks eingeordnet. Der Filter war gebaut — und wurde nach
 * Dominiques Einwand („das Wissen ist für alle Teams gedacht, sonst würde der Generator
 * nicht laufen") und einer Messung auf demo wieder verworfen:
 *
 *   598 aktive Docs · team_id NULL: 6 (1,0 %) · team_id 6 (Kurator): 592 (99,0 %)
 *
 * Ein `TeamScope::applyVisible`-Filter ist für den Kurator ein No-op (598 → 598) und lässt
 * für jedes ANDERE Team 6 von 598 übrig. Der Korpus fällt auf 1 %, die Generierung verliert
 * ihr Fundament — und KEIN Test wird rot, weil die Suite ihre Docs mit team_id NULL seedet.
 * Genau diese Blindheit schliesst diese Datei.
 *
 * DAS MODELL, das hier gepinnt wird: alle lesen, nur der Eigentümer schreibt.
 *
 * OFFENE GRENZE (bewusst benannt, nicht gelöst): der Wissens-BROWSER ist gescopet
 * (WissenTenantTest, Audit 23 P0), das RETRIEVAL nicht. Zwei Regeln auf einer Tabelle.
 * Solange BHG der einzige Kurator ist, stimmig. Pflegt ein Kundenteam eigenes Wissen,
 * landet es in den Prompts aller Teams und ist in deren Browser nicht sichtbar —
 * unsichtbar-aber-wirksam. Diese Entscheidung fällt VOR dem zweiten Kurator. Der Weg dahin
 * ist zuerst eine DATEN-Frage (kuratierten Bestand auf team_id NULL heben, oder
 * `teams.parent_team_id` setzen — heute bei allen 8 Teams NULL), erst danach Code.
 */
function korpusDoc(?int $teamId, string $slug, string $title, string $kategorie = 'domain'): int
{
    return DB::table('foodalchemist_knowledge_documents')->insertGetId([
        'uuid' => (string) UuidV7::generate(), 'team_id' => $teamId, 'slug' => $slug,
        'title' => $title, 'category' => $kategorie, 'content_md' => '# ' . $title . "\n\nInhalt.",
        'version' => 1, 'content_hash' => hash('sha256', $slug), 'char_count' => 30,
        'active' => 1, 'source_path' => null, 'created_via' => 'ui',
        'created_at' => now(), 'updated_at' => now(),
    ]);
}

beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->svc = app(KnowledgeContextService::class);
});

it('GEWOLLT: das Retrieval findet Wissen eines FREMDEN Teams — der Korpus ist gemeinsam', function () {
    korpusDoc($this->childB->id, 'kurator-dossier-zander', 'Zander-Dossier des Kurators');

    $treffer = collect($this->svc->searchDocuments('zander'))->pluck('slug');

    // Wird das hier rot, hat jemand einen team_id-Filter eingebaut. BITTE ZUERST den
    // Klassen-Docblock von KnowledgeContextService lesen: der Filter kappt 99 % des
    // Korpus, und das merkt man erst im Betrieb, nicht in der Suite.
    expect($treffer)->toContain('kurator-dossier-zander');
});

it('GEWOLLT: auch die Katalog-Enumeration ist gemeinsam', function () {
    korpusDoc($this->childB->id, 'kurator-katalog-doc', 'Katalog-Doc des Kurators');

    $slugs = collect($this->svc->listDocuments('domain', 0, 50)['documents'] ?? [])->pluck('slug');

    expect($slugs)->toContain('kurator-katalog-doc');
});

it('GEWOLLT: der Volltext ist per Slug für jedes Team lesbar', function () {
    korpusDoc($this->childB->id, 'kurator-volltext', 'Volltext des Kurators');

    expect($this->svc->getDocument('kurator-volltext'))->not->toBeNull();
});

/*
 * Die andere Hälfte des Modells — und DIESE Grenze ist echt: geschrieben wird nur im
 * Eigentum. `TeamScope::owns` ist der kanonische Wächter; global (NULL) und Fremd-Teams
 * sind read-only. Ohne diesen Test wäre „alle lesen" nicht von „alle dürfen alles"
 * unterscheidbar.
 */
it('GRENZE: Schreibrecht nur im Eigentum — global und fremd sind read-only', function () {
    expect(TeamScope::owns($this->childA->id, $this->childA))->toBeTrue()
        ->and(TeamScope::owns($this->childB->id, $this->childA))->toBeFalse()
        ->and(TeamScope::owns(null, $this->childA))->toBeFalse()
        ->and(TeamScope::owns($this->childA->id, null))->toBeFalse();
});

/*
 * Und der Beleg für die Zahl, die den Filter widerlegt hat — als Rechnung, nicht als
 * Behauptung: unter einem applyVisible-Filter sähe ein fremdes Team NUR den globalen Seed.
 */
it('BELEG: ein applyVisible-Filter würde den Korpus für ein fremdes Team auf den globalen Seed kappen', function () {
    korpusDoc(null, 'global-seed-doc', 'Globaler Seed');
    korpusDoc($this->childB->id, 'kurator-1', 'Kurator 1');
    korpusDoc($this->childB->id, 'kurator-2', 'Kurator 2');

    // Slug-MENGEN statt absoluter Zahlen: die TestCase seedet selbst Wissens-Docs,
    // eine Zählung würde von fremdem Seed verunreinigt (und hätte hier 5 statt 3 gemeldet).
    $ungefiltert = DB::table('foodalchemist_knowledge_documents')->where('active', 1)->pluck('slug');
    $gefiltert = TeamScope::applyVisible(
        DB::table('foodalchemist_knowledge_documents')->where('active', 1), 'team_id', $this->childA,
    )->pluck('slug');

    // Ungefiltert (= heutiges Retrieval) ist der Kurator-Bestand da …
    expect($ungefiltert)->toContain('kurator-1')->toContain('kurator-2')->toContain('global-seed-doc');
    // … unter dem Filter bliebe für childA nur der globale Seed übrig. DAS ist der Grund,
    // warum der Filter verworfen wurde: auf demo sind 99 % des Korpus Kurator-Bestand.
    expect($gefiltert)->toContain('global-seed-doc')
        ->not->toContain('kurator-1')
        ->not->toContain('kurator-2');
});
