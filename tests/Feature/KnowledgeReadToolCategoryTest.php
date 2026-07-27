<?php

use Illuminate\Support\Facades\DB;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Tools\ToolRegistry;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;
use Symfony\Component\Uid\UuidV7;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * 22·H1 / V-044 — der Kategorie-Filter der LESE-Tools folgt der Tabelle, nicht einer Handkopie.
 *
 * Zwei Wahrheiten über dieselbe Menge waren das Problem: der Schreibweg (`knowledge.POST`)
 * validierte dynamisch gegen `foodalchemist_knowledge_categories`, die beiden Lese-Tools
 * trugen die Slugs als JSON-Schema-`enum` im Code. Eine über die Settings-UI angelegte
 * Kategorie war damit sofort beschreibbar, aber für einen MCP-Client **nicht filterbar** —
 * und das Vergessen des Nachzugs bemerkt niemand: der Client sieht eine Kategorie, die es
 * „nicht gibt", und weicht auf die ungefilterte Suche aus (mehr Tokens, schlechteres Ranking).
 * Dreimal in Folge musste von Hand nachgezogen werden (skill→workflow→concept).
 *
 * `getSchema()` darf keine DB anfassen (seiteneffektfrei, wird bei jeder LLM-Anfrage
 * gerufen) — die Auflösung ist deshalb: kein Enum, Validierung in `execute()` über
 * `KnowledgeService::assertKategorie` (dieselbe Methode wie der Schreibweg).
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->user = $this->makeUser($this->rootTeam);
    $this->actingAs($this->user);
    $this->registry = app(ToolRegistry::class);
    $this->kontext = new ToolContext($this->user, $this->rootTeam);
});

it('führt in keinem der beiden Lese-Tools ein Kategorie-Enum im Schema', function () {
    foreach (['foodalchemist.knowledge.LIST', 'foodalchemist.knowledge.SEARCH'] as $name) {
        $schema = $this->registry->get($name)->getSchema();

        expect($schema['properties']['category'])->not->toHaveKey(
            'enum',
            "{$name} trägt die Kategorien wieder als Handkopie im Schema (V-044)"
        );
    }
});

it('weist eine unbekannte Kategorie mit derselben Vokabular-Liste ab wie der Schreibweg', function () {
    $list = $this->registry->get('foodalchemist.knowledge.LIST')
        ->execute(['category' => 'gibtsnicht'], $this->kontext);
    $search = $this->registry->get('foodalchemist.knowledge.SEARCH')
        ->execute(['q' => 'egal', 'category' => 'gibtsnicht'], $this->kontext);

    foreach ([$list, $search] as $res) {
        expect($res->success)->toBeFalse()
            ->and($res->errorCode)->toBe('VALIDATION_ERROR')
            ->and($res->error)->toContain('Verfügbar:');
    }
});

it('macht eine zur Laufzeit angelegte Kategorie sofort filterbar', function () {
    // Genau der Griff aus der Settings-UI (Wissenskategorien) — ohne Code-Änderung.
    DB::table('foodalchemist_knowledge_categories')->insert([
        'uuid' => (string) new UuidV7,
        'team_id' => $this->rootTeam->id,
        'slug' => 'hausintern',
        'label' => 'Hausintern',
        'sort_order' => 99,
        'active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // Schreibweg konnte das immer …
    $post = $this->registry->get('foodalchemist.knowledge.POST')->execute([
        'title' => 'Hausregel: Buffet-Nachschub',
        'category' => 'hausintern',
        'content_md' => '# Nachschub',
    ], $this->kontext);
    expect($post->success)->toBeTrue();

    // MCP-Docs liegen in Quarantäne (inaktiv) — ein Mensch gibt sie frei. Hier ist genau
    // das der zweite Handgriff, sonst prüfte der Test die Quarantäne statt den Filter.
    DB::table('foodalchemist_knowledge_documents')
        ->where('slug', $post->data['document']['slug'])->update(['active' => true]);

    // … und der Lese-Filter jetzt auch (vorher: „Kategorie existiert nicht").
    $list = $this->registry->get('foodalchemist.knowledge.LIST')
        ->execute(['category' => 'hausintern'], $this->kontext);

    expect($list->success)->toBeTrue()
        ->and(collect($list->data['documents'])->pluck('slug'))->toContain($post->data['document']['slug']);
});

it('lässt eine bekannte Kategorie und den leeren Filter unberührt durch', function () {
    $mit = $this->registry->get('foodalchemist.knowledge.LIST')
        ->execute(['category' => 'trend'], $this->kontext);
    $ohne = $this->registry->get('foodalchemist.knowledge.LIST')->execute([], $this->kontext);
    // Leerstring ist „kein Filter", nicht „unbekannte Kategorie" — sonst wäre ein
    // durchgereichtes leeres Formularfeld ein Fehler statt einer Vollauflistung.
    $leer = $this->registry->get('foodalchemist.knowledge.LIST')
        ->execute(['category' => '  '], $this->kontext);

    expect($mit->success)->toBeTrue()
        ->and($ohne->success)->toBeTrue()
        ->and($leer->success)->toBeTrue();
});
