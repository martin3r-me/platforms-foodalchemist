<?php

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Tools\ToolRegistry;
use Platform\FoodAlchemist\Models\FoodAlchemistLabNote;
use Platform\FoodAlchemist\Services\LabNoteService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * R6.11 · S3 (Lab-Notes-Senke): team-eigene Lab-Notiz via Service + MCP, Evidenz-Stufe Pflicht.
 * Der S2-Detektor (Pairing-Dokument ⇄ Anker-Graph) ist mit Spec 60 · P8 abgelöst
 * → PairingSignaleTest.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->user = $this->makeUser($this->rootTeam);
    $this->actingAs($this->user);
});

it('S3: Lab-Notiz wird team-eigen angelegt, Evidenz-Stufe Default T3', function () {
    $note = app(LabNoteService::class)->create($this->rootTeam, [
        'title' => 'Hypothese: Basilikum × Erdbeere', 'body' => 'geteilte Terpene', 'source_ref' => 'widerspruch:doc:1',
    ], $this->user->id);

    expect($note->evidence_tier)->toBe('T3')
        ->and($note->isOwnedBy($this->rootTeam))->toBeTrue()
        ->and(app(LabNoteService::class)->forTeam($this->rootTeam))->toHaveCount(1);

    // Titel Pflicht
    expect(fn () => app(LabNoteService::class)->create($this->rootTeam, ['title' => '']))
        ->toThrow(RuntimeException::class, 'Titel');
});

it('S3 MCP: lab_notes.POST legt Notiz an (write), fehlender Titel → Fehler', function () {
    $registry = app(ToolRegistry::class);
    $kontext = new ToolContext($this->user, $this->rootTeam);
    $tool = $registry->get('foodalchemist.lab_notes.POST');
    expect($tool)->not->toBeNull()
        ->and($tool->getMetadata()['read_only'])->toBeFalse();

    $res = $tool->execute(['title' => 'Idee: Rauchpaprika × Kakao', 'evidence_tier' => 'T3', 'source_ref' => 'hypothesis:anchor:2'], $kontext);
    expect($res->success)->toBeTrue()
        ->and($res->data['evidence_tier'])->toBe('T3');
    expect(FoodAlchemistLabNote::where('team_id', $this->rootTeam->id)->where('id', $res->data['id'])->exists())->toBeTrue();

    expect($tool->execute([], $kontext)->success)->toBeFalse();   // title Pflicht
});
