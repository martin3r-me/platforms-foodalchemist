<?php

use Platform\FoodAlchemist\Models\FoodAlchemistPlanningSession;
use Platform\FoodAlchemist\Services\Ai\FakeAiProvider;
use Platform\FoodAlchemist\Services\BriefingLeitplankenService;
use Platform\FoodAlchemist\Services\PlanningSessionService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam));
    config(['foodalchemist.ai.provider' => 'fake']);
});

/** Provider-Stub, der einen festen Leitplanken-Satz liefert. */
function briefStub(array $werte): void
{
    app()->bind(FakeAiProvider::class, fn () => new class($werte) extends FakeAiProvider
    {
        public function __construct(private array $werte)
        {
        }

        public function chat(array $messages, array $options = []): array
        {
            return [
                'content' => json_encode(['werte' => $this->werte, 'confidence' => 0.8, 'reasoning' => 'stub'], JSON_UNESCAPED_UNICODE),
                'usage' => ['input_tokens' => 0, 'output_tokens' => 0],
                'model' => 'fake-brief',
                'tool_calls' => null,
            ];
        }
    });
}

/*
 * DIE WERT-PRÜFUNG ist die eigentliche Leitplanke gegen Halluzination — nicht der Prompt.
 * Ein erfundener Wert (»Gala« statt `dinner`) lief bis hierher stumm durch UND ins Leere:
 * das Achsen-Mapping löst `occasion`/`sektor` deterministisch auf und findet für
 * Unbekanntes nichts. Weder Fehler noch Playbook.
 */
it('verwirft erfundene Werte und meldet sie, statt sie still zu uebernehmen', function () {
    $svc = app(PlanningSessionService::class);

    $ok = $svc->filterGenerationParams([
        'occasion' => 'Gala',              // erfunden
        'serviceform' => 'buffet',         // gültig
        'level' => 'sterne',               // erfunden
    ], $verworfen);

    expect($ok)->toBe(['serviceform' => 'buffet'])
        ->and($verworfen)->toContain('occasion=Gala')
        ->and($verworfen)->toContain('level=sterne');
});

it('prueft Mehrfachauswahl eintragsweise und behaelt die gueltigen', function () {
    $ok = app(PlanningSessionService::class)->filterGenerationParams([
        'diaet_hart' => ['vegan', 'pescetarisch', 'glutenfrei'],
    ], $verworfen);

    expect($ok['diaet_hart'])->toBe(['vegan', 'glutenfrei'])
        ->and($verworfen)->toBe(['diaet_hart=pescetarisch']);
});

it('laesst Regler OHNE deklariertes Vokabular unangetastet durch', function () {
    // Zahlen, Freitext und die bewusst ungeprüften Achsen (`frische` hat zwei Wertesätze
    // im Code — unter-prüfen ist richtiger als Legitimes zu verwerfen).
    $ok = app(PlanningSessionService::class)->filterGenerationParams([
        'pax' => 80,
        'ziel_vk_eur' => 45.5,
        'aroma' => 'rauchig-mediterran',
        'frische' => 'irgendwas',
    ], $verworfen);

    expect($ok)->toBe(['pax' => 80, 'ziel_vk_eur' => 45.5, 'aroma' => 'rauchig-mediterran', 'frische' => 'irgendwas'])
        ->and($verworfen)->toBe([]);
});

it('meldet Keys, die gar keine Leitplanke sind', function () {
    $ok = app(PlanningSessionService::class)->filterGenerationParams([
        'occasion' => 'dinner',
        'geheimzutat' => 'Trüffel',
    ], $verworfen);

    expect($ok)->toBe(['occasion' => 'dinner'])
        ->and($verworfen)->toBe(['geheimzutat (kein Leitplanken-Regler)']);
});

it('destilliert Leitplanken aus einem Briefing und schreibt sie in die Sitzung', function () {
    briefStub([
        'leitplanken' => [
            'occasion' => 'dinner', 'sektor' => 'catering', 'level' => 'gehoben',
            'serviceform' => 'tellerservice', 'pax' => 80, 'ziel_vk_eur' => 45,
            'diaet_hart' => ['vegetarisch'],
        ],
        'unklar' => ['Gibt es Allergien?', ''],
        'begruendung' => 'Gala-Dinner impliziert Tellerservice.',
    ]);

    $session = FoodAlchemistPlanningSession::create([
        'team_id' => $this->rootTeam->id, 'title' => 'Sommerfest', 'status' => 'divergenz',
    ]);

    $r = app(BriefingLeitplankenService::class)->ausBriefing(
        $this->rootTeam,
        'Gala-Dinner für 80 Personen, gehoben, 45 Euro netto pro Person, vegetarisch.',
        $session->id,
    );

    expect($r['leitplanken']['occasion'])->toBe('dinner')
        ->and($r['leitplanken']['pax'])->toBe(80)
        ->and($r['verworfen'])->toBe([])
        // Leerwerte in `unklar` werden ausgesiebt, echte Rückfragen bleiben.
        ->and($r['unklar'])->toBe(['Gibt es Allergien?'])
        ->and($r['begruendung'])->toBe('Gala-Dinner impliziert Tellerservice.')
        ->and($r['gespeichert'])->toBeTrue()
        // Und die Sitzung trägt sie wirklich.
        ->and($session->refresh()->generation_params['occasion'])->toBe('dinner')
        ->and($session->generation_params['serviceform'])->toBe('tellerservice');
});

it('schreibt NICHTS ohne Sitzungs-Id — reiner Vorschlag', function () {
    briefStub(['leitplanken' => ['occasion' => 'lunch']]);

    $r = app(BriefingLeitplankenService::class)->ausBriefing($this->rootTeam, 'Business-Lunch für 20.');

    expect($r['leitplanken'])->toBe(['occasion' => 'lunch'])
        ->and($r['gespeichert'])->toBeFalse();
});

it('uebernimmt halluzinierte Werte auch aus dem Briefing-Pfad nicht', function () {
    briefStub(['leitplanken' => ['occasion' => 'Sommerfest', 'serviceform' => 'buffet']]);

    $session = FoodAlchemistPlanningSession::create([
        'team_id' => $this->rootTeam->id, 'title' => 'X', 'status' => 'divergenz',
    ]);

    $r = app(BriefingLeitplankenService::class)->ausBriefing($this->rootTeam, 'Sommerfest.', $session->id);

    expect($r['leitplanken'])->toBe(['serviceform' => 'buffet'])
        ->and($r['verworfen'])->toBe(['occasion=Sommerfest'])
        // Der falsche Regler landet NICHT in der Sitzung …
        ->and($session->refresh()->generation_params)->toBe(['serviceform' => 'buffet']);
});

it('weist ein leeres Briefing zurueck', function () {
    expect(fn () => app(BriefingLeitplankenService::class)->ausBriefing($this->rootTeam, "  \n "))
        ->toThrow(InvalidArgumentException::class, 'Briefing ist leer');
});

/*
 * Der Zweck der ganzen Übung: die extrahierten Leitplanken müssen im Achsen-Mapping
 * ankommen. Sonst ist die Brücke gebaut, aber nicht befahrbar.
 */
it('liefert Werte, die das Achsen-Mapping wirklich aufloest', function () {
    $map = config('foodalchemist.ai.knowledge_axis_map', []);

    foreach (FoodAlchemistPlanningSession::ALLOWED_GENERATION_VALUES['occasion'] as $wert) {
        expect($map['occasion'] ?? [])->toHaveKey($wert);
    }
    // `sektor` ist absichtlich NICHT im geprüften Vokabular (zwei konkurrierende Wertesätze
     // im Code) — geprüft wird hier stattdessen, dass das Mapping die vier Sektoren kennt,
     // für die ein Segment-Dossier existiert. `restaurant` ist die bekannte Lücke.
    foreach (['betriebsgastronomie', 'catering', 'care', 'schule_kita'] as $wert) {
        expect($map['sektor'] ?? [])->toHaveKey($wert);
    }
    expect($map['sektor'] ?? [])->not->toHaveKey('restaurant')
        ->and(FoodAlchemistPlanningSession::ALLOWED_GENERATION_VALUES)->not->toHaveKey('sektor');
});

/*
 * MCP-Lockstep: die Extraktion muss von aussen erreichbar sein — das ist der Einstieg
 * für ein gesprochenes oder getipptes Briefing.
 */
it('ist als MCP-Tool erreichbar und schreibt nur team-eigene Sitzungen', function () {
    briefStub(['leitplanken' => ['occasion' => 'empfang', 'serviceform' => 'flying']]);

    $tool = app(\Platform\FoodAlchemist\Tools\PlanungLeitplankenExtractTool::class);
    $ctx = new \Platform\Core\Contracts\ToolContext(auth()->user(), $this->rootTeam);

    // Leeres Briefing → Validierungsfehler VOR jedem Provider-Call.
    expect($tool->execute(['briefing' => '   '], $ctx)->success)->toBeFalse();

    // Fremde Sitzung → NOT_FOUND, ebenfalls ohne Provider-Call.
    $fremd = FoodAlchemistPlanningSession::create([
        'team_id' => 999999, 'title' => 'Fremd', 'status' => 'divergenz',
    ]);
    $r = $tool->execute(['briefing' => 'Empfang mit Fingerfood.', 'session_id' => $fremd->id], $ctx);
    expect($r->success)->toBeFalse()
        ->and($r->error)->toContain('nicht team-eigen');

    // Eigene Sitzung → Regler landen.
    $eigen = FoodAlchemistPlanningSession::create([
        'team_id' => $this->rootTeam->id, 'title' => 'Eigen', 'status' => 'divergenz',
    ]);
    $ok = $tool->execute(['briefing' => 'Empfang mit Fingerfood.', 'session_id' => $eigen->id], $ctx);

    expect($ok->success)->toBeTrue()
        ->and($ok->data['leitplanken']['serviceform'])->toBe('flying')
        ->and($ok->data['gespeichert'])->toBeTrue()
        ->and($eigen->refresh()->generation_params['occasion'])->toBe('empfang');
});

it('deklariert sich als schreibendes Tool — der Voice-Loop darf es nur als Proposal fuehren', function () {
    $meta = app(\Platform\FoodAlchemist\Tools\PlanungLeitplankenExtractTool::class)->getMetadata();

    // read_only=false ist entscheidend: die policy-gebundene Voice-Whitelist lässt nur
    // lesende Tools automatisch zu — dieses hier braucht eine bewusste Freigabe.
    expect($meta['read_only'])->toBeFalse()
        ->and($meta['requires_team'])->toBeTrue()
        ->and($meta['cost_class'])->toBe('llm_call');
});
