<?php

use Illuminate\Support\Facades\DB;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Tools\ToolRegistry;
use Platform\FoodAlchemist\Enums\SignalTyp;
use Platform\FoodAlchemist\Models\FoodAlchemistLabNote;
use Platform\FoodAlchemist\Models\FoodAlchemistSignal;
use Platform\FoodAlchemist\Services\LabNoteService;
use Platform\FoodAlchemist\Services\SignalDetektorService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;
use Symfony\Component\Uid\UuidV7;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * R6.11 · S2 (Widerspruchs-Detektor) + S3 (Lab-Notes-Senke).
 * S2: pairing-Doc ⇄ Anker-Graph Präsenz/Absenz — belegte Paarung ohne Kante wird
 * als R&D-Signal gemeldet, vorhandene Kante NICHT. S3: team-eigene Lab-Notiz via
 * Service + MCP, Evidenz-Stufe Pflicht.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->user = $this->makeUser($this->rootTeam);
    $this->actingAs($this->user);

    $mkAnker = function (string $slug) {
        DB::table('foodalchemist_vocab_pairing_anchors')->insert([
            'uuid' => (string) UuidV7::generate(), 'slug' => $slug, 'display_de' => ucfirst($slug),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return (int) DB::getPdo()->lastInsertId();
    };
    $this->basilikum = $mkAnker('basilikum');
    $this->tomate = $mkAnker('tomate');        // hat Kante → kein Widerspruch
    $this->erdbeere = $mkAnker('erdbeere');    // KEINE Kante → Widerspruch

    // Kante basilikum–tomate (symmetrisch)
    foreach ([[$this->basilikum, $this->tomate], [$this->tomate, $this->basilikum]] as [$x, $y]) {
        DB::table('foodalchemist_pairing_anchor_edges')->insert([
            'uuid' => (string) UuidV7::generate(), 'anchor_a_id' => $x, 'anchor_b_id' => $y,
            'type' => 'erprobt', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    // Pairing-Wissensdokument (global) — behauptet Tomate UND Erdbeere als Partner.
    $md = "## Pairings\n### Klassisch\n- [[Tomate]]\n- [[Erdbeere]]\n";
    DB::table('foodalchemist_knowledge_documents')->insert([
        'uuid' => (string) UuidV7::generate(), 'team_id' => null,
        'slug' => 'basilikum', 'title' => 'Basilikum', 'category' => 'pairing',
        'content_md' => $md, 'version' => 1, 'content_hash' => 'test', 'char_count' => mb_strlen($md),
        'active' => 1, 'created_via' => 'test', 'created_at' => now(), 'updated_at' => now(),
    ]);
});

it('meldet belegte Paarung ohne Graph-Kante als Signal, vorhandene Kante nicht', function () {
    $n = app(SignalDetektorService::class)->widerspruchWissenGraph($this->rootTeam);
    expect($n)->toBe(1);

    $signal = FoodAlchemistSignal::where('team_id', $this->rootTeam->id)
        ->where('type', SignalTyp::WiderspruchWissenGraph->value)->first();
    expect($signal)->not->toBeNull()
        ->and($signal->title)->toContain('Erdbeere')
        ->and($signal->title)->not->toContain('Tomate')          // Tomate hat Kante → kein Widerspruch
        ->and($signal->ref_type)->toBe('knowledge_document');

    $fehlend = collect($signal->payload['fehlende_kanten'])->pluck('anchor_id');
    expect($fehlend)->toContain($this->erdbeere)
        ->and($fehlend)->not->toContain($this->tomate)
        ->and($signal->payload['doc_tier'])->toBe('T0');
});

it('ist idempotent — zweiter Lauf dupliziert das Signal nicht (dedup)', function () {
    $svc = app(SignalDetektorService::class);
    $svc->widerspruchWissenGraph($this->rootTeam);
    $svc->widerspruchWissenGraph($this->rootTeam);

    expect(FoodAlchemistSignal::where('team_id', $this->rootTeam->id)
        ->where('type', SignalTyp::WiderspruchWissenGraph->value)->count())->toBe(1);
});

it('feuert kein Signal, wenn alle belegten Paarungen eine Kante haben', function () {
    // Kante basilikum–erdbeere ergänzen → kein Widerspruch mehr.
    foreach ([[$this->basilikum, $this->erdbeere], [$this->erdbeere, $this->basilikum]] as [$x, $y]) {
        DB::table('foodalchemist_pairing_anchor_edges')->insert([
            'uuid' => (string) UuidV7::generate(), 'anchor_a_id' => $x, 'anchor_b_id' => $y,
            'type' => 'aroma', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
    expect(app(SignalDetektorService::class)->widerspruchWissenGraph($this->rootTeam))->toBe(0);
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
