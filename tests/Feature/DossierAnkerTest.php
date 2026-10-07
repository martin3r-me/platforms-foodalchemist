<?php

use Illuminate\Support\Facades\DB;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Tools\ToolRegistry;
use Platform\FoodAlchemist\Services\KnowledgeService;
use Platform\FoodAlchemist\Support\DossierAnker;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;
use Symfony\Component\Uid\UuidV7;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 60 · Schritt 1: Dossier ↔ Aroma-Anker. Der Anker im Dossier-Kopf war bisher nur Text;
 * jetzt wird er auf allen Schreibwegen verknüpft — aber nur, wenn ID UND Slug passen.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    DB::table('foodalchemist_vocab_pairing_anchors')->insert([
        'uuid' => (string) UuidV7::generate(), 'slug' => 'acai_berry', 'display_de' => 'Açai-Beere',
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $this->anker = (int) DB::getPdo()->lastInsertId();
    $this->kopf = fn (int $id, string $slug) => "---\ntyp: zutat\nthema: Açai-Beere\nanker_id: {$id}\nanker_slug: {$slug}\n---\n\n# Açai-Beere\n\nText.";
});

it('liest anker_id und anker_slug nur aus dem Frontmatter', function () {
    expect(DossierAnker::kopf(($this->kopf)(1011, 'acai_berry')))->toBe([1011, 'acai_berry'])
        ->and(DossierAnker::kopf("# Titel\n\nanker_id: 5\nanker_slug: x"))->toBe([null, null]);
});

it('verknüpft beim Anlegen, wenn ID und Slug zum Vokabular passen', function () {
    $doc = app(KnowledgeService::class)->create($this->rootTeam, [
        'title' => 'Açai-Beere — Kombinationslogik', 'slug' => 'zutat.acai_berry--verwendung-kombination',
        'category' => 'cross_cutting', 'content_md' => ($this->kopf)($this->anker, 'acai_berry'),
    ]);

    expect((int) DB::table('foodalchemist_knowledge_documents')->where('id', $doc->id)->value('anchor_id'))->toBe($this->anker);
});

it('verknüpft nicht, wenn der Slug nicht zur ID passt (lieber leer als falsch)', function () {
    $doc = app(KnowledgeService::class)->create($this->rootTeam, [
        'title' => 'Falscher Kopf', 'slug' => 'zutat.falsch--kombination',
        'category' => 'cross_cutting', 'content_md' => ($this->kopf)($this->anker, 'avocado'),
    ]);

    expect(DB::table('foodalchemist_knowledge_documents')->where('id', $doc->id)->value('anchor_id'))->toBeNull();
});

it('zieht die Verknüpfung bei einer Inhalts-Änderung nach und liefert sie über knowledge.GET', function () {
    $svc = app(KnowledgeService::class);
    $doc = $svc->create($this->rootTeam, [
        'title' => 'Ohne Kopf', 'slug' => 'zutat.acai_berry--steckbrief', 'category' => 'cross_cutting', 'content_md' => 'Noch ohne Kopf.',
    ]);
    expect(DB::table('foodalchemist_knowledge_documents')->where('id', $doc->id)->value('anchor_id'))->toBeNull();

    $svc->update($this->rootTeam, $doc->slug, ['content_md' => ($this->kopf)($this->anker, 'acai_berry')]);

    $user = $this->makeUser($this->rootTeam);
    $this->actingAs($user);
    $res = app(ToolRegistry::class)->get('foodalchemist.knowledge.GET')
        ->execute(['slug' => $doc->slug], new ToolContext($user, $this->rootTeam));
    expect($res->success)->toBeTrue()->and($res->data['anchor_id'])->toBe($this->anker);
});

it('verknüpft auch über den Massen-Import', function () {
    app(KnowledgeService::class)->import($this->rootTeam, [[
        'title' => 'Açai-Beere — Steckbrief', 'slug' => 'zutat.acai_berry--steckbrief-basis',
        'category' => 'cross_cutting', 'content_md' => ($this->kopf)($this->anker, 'acai_berry'),
    ]]);

    expect((int) DB::table('foodalchemist_knowledge_documents')->where('slug', 'zutat.acai_berry--steckbrief-basis')->value('anchor_id'))->toBe($this->anker);
});
