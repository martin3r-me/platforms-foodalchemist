<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Platform\FoodAlchemist\Jobs\GenerateConceptJob;
use Platform\FoodAlchemist\Models\FoodAlchemistConcept;
use Platform\FoodAlchemist\Models\FoodAlchemistPlanningSession;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Services\IdeenService;
use Platform\FoodAlchemist\Services\PlanningSessionService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Planungs-/Kreativ-Ebene (Doppel-Diamant, Spec 08): Session-Container + Trend-Carry-in +
 * 3-Wege-Owner der Skizzen + Lineage. Invariante: die Session erdet NICHTS (nur „Go", human-only).
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam));
    $this->svc = app(PlanningSessionService::class);
    $this->ideen = app(IdeenService::class);
});

/** Legt ein globales Trend-Wissens-Dokument an (wie der Import es täte). */
function makeTrendDoc(string $title = 'Fermentation & Gut Health'): int
{
    $md = "---\nrelevanz: hoch\nquellen:\n  - Quelle A\n  - Quelle B\n---\n# {$title}\n\n## Zusammenfassung\n\nFermentation ist ein starker Food-Trend 2026.";

    return DB::table('foodalchemist_knowledge_documents')->insertGetId([
        'uuid' => (string) Str::uuid(),
        'team_id' => null,                 // global sichtbar
        'slug' => 'trend.' . Str::slug($title),
        'title' => $title,
        'category' => 'trend',
        'content_md' => $md,
        'char_count' => mb_strlen($md),
        'content_hash' => hash('sha256', $md),
        'version' => 1,
        'active' => 1,
        'created_via' => 'import',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

it('legt eine freie Planungs-Session an (Status divergenz, erdet nichts)', function () {
    $s = $this->svc->create($this->rootTeam, ['title' => 'Sommer-Buffet', 'brief' => 'leichte Küche']);

    expect($s->status)->toBe('divergenz')
        ->and($s->title)->toBe('Sommer-Buffet')
        ->and($s->creative_mode)->toBe('voll_kreativ')
        ->and($s->source_knowledge_document_id)->toBeNull();

    expect(FoodAlchemistRecipe::count())->toBe(0)
        ->and(FoodAlchemistConcept::count())->toBe(0);
});

it('lehnt leeren Titel ab', function () {
    expect(fn () => $this->svc->create($this->rootTeam, ['title' => '  ']))
        ->toThrow(RuntimeException::class, 'Titel ist Pflicht');
});

it('eröffnet eine Session aus einem Trend — Kontext wandert mit', function () {
    $docId = makeTrendDoc('Postbiotic Drinks');

    $s = $this->svc->ausTrend($this->rootTeam, $docId);

    expect($s->source_knowledge_document_id)->toBe($docId)
        ->and($s->created_via)->toBe('trend')
        ->and($s->title)->toBe('Postbiotic Drinks')
        ->and($s->analysis)->toContain('Zusammenfassung')
        ->and($s->analysis)->toContain('Quelle A');       // quellen aus Frontmatter übernommen
});

it('hängt Skizzen als dritten Owner an die Session (3-Wege-XOR)', function () {
    $s = $this->svc->create($this->rootTeam, ['title' => 'Board-Test']);

    $einzel = $this->ideen->add($this->rootTeam, ['planning_session_id' => $s->id, 'title' => 'Skizze A']);
    $gruppe = $this->ideen->addGruppe($this->rootTeam, ['planning_session_id' => $s->id, 'name' => 'Paket X']);
    $imPaket = $this->ideen->add($this->rootTeam, ['planning_session_id' => $s->id, 'title' => 'Skizze B', 'group_id' => $gruppe->id]);

    expect($einzel->planning_session_id)->toBe($s->id)
        ->and($einzel->chapter_id)->toBeNull()
        ->and($imPaket->target_form)->toBe('paket');

    $liste = $this->ideen->liste($this->rootTeam, null, null, false, $s->id);
    expect($liste['einzel']->pluck('id')->all())->toBe([$einzel->id])
        ->and($liste['gruppen'])->toHaveCount(1)
        ->and($liste['gruppen'][0]['ideen']->pluck('id')->all())->toBe([$imPaket->id]);
});

it('erzwingt 3-Wege-Owner-XOR (kein Owner = Fehler)', function () {
    expect(fn () => $this->ideen->add($this->rootTeam, ['title' => 'Waise']))
        ->toThrow(RuntimeException::class, 'GENAU einen Owner');
});

it('schreibt Lineage beim „Go" (Trend-FK + created_via=plan_go, Session→konvergenz)', function () {
    $docId = makeTrendDoc();
    $s = $this->svc->ausTrend($this->rootTeam, $docId);

    $recipe = FoodAlchemistRecipe::create([
        'team_id' => $this->rootTeam->id, 'recipe_key' => 'plan1', 'name' => 'Entwurf', 'status' => 'draft',
    ]);

    $this->svc->verknuepfeArtefakt($s, 'recipe', $recipe->id);

    expect($recipe->refresh()->source_knowledge_document_id)->toBe($docId)
        ->and($recipe->created_via)->toBe('plan_go')
        ->and($s->refresh()->status)->toBe('konvergenz');
});

it('Go → alle drei Stufen laufen in-place über den Kaskaden-Motor (kein Handoff-Redirect mehr)', function () {
    // Seit P1a laufen Basisrezept/Gericht/Concept in-place über goKaskade (Details in PlanningCascadeTest);
    // der alte Handoff-Redirect ist weg (Editor = Kommandozentrum, kein Wegspringen).
    Queue::fake();
    $s = $this->svc->create($this->rootTeam, ['title' => 'Go-Test', 'brief' => 'leichte Küche']);

    Livewire::test(\Platform\FoodAlchemist\Livewire\Planung\Index::class)
        ->call('oeffne', $s->id)
        ->call('goKaskade', 'concept')
        ->assertSet('laeuft', true)
        ->assertNoRedirect();

    Queue::assertPushed(GenerateConceptJob::class);
    expect(session('fa_plan_handoff'))->toBeNull();     // kein Parallel-Pfad, kein Flash mehr
});

it('kappt Cross-Team-Zugriff auf fremde Sessions (Tenancy)', function () {
    $s = $this->svc->create($this->rootTeam, ['title' => 'Root-Planung']);

    $this->actingAs($this->makeUser($this->childA));

    expect(fn () => $this->svc->update($this->childA, $s->id, ['title' => 'geklaut']))
        ->toThrow(RuntimeException::class, 'nur durchs Besitzer-Team');

    // Kind darf auch keine Skizze an die fremde Session hängen.
    expect(fn () => $this->ideen->add($this->childA, ['planning_session_id' => $s->id, 'title' => 'Kind-Skizze']))
        ->toThrow(RuntimeException::class);
});
