<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Platform\Core\Contracts\LLMProviderContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Tools\ToolRegistry;
use Platform\FoodAlchemist\Models\FoodAlchemistCascadeRunStep;
use Platform\FoodAlchemist\Services\Ai\FakeAiProvider;
use Platform\FoodAlchemist\Services\Ai\KiExternModus;
use Platform\FoodAlchemist\Services\PlanningCascadeService;
use Platform\FoodAlchemist\Services\PlanningSessionService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Lauf 62 (io, 13.11.2026): ein per MCP gestarteter Planungslauf wartete auf einen KI-Auftrag, Status und Cockpit zeigten aber
 * „eingereiht — wartet auf Worker“, und der Auftrag (recipe.generator, ~120.000 Zeichen) passte in kein MCP-Ergebnis. Abgesichert:
 *  1. der Kaskaden-Status nennt das Warten auf die KI-Antwort samt nächster Aktion,
 *  2. das Freiräumen verwaister Schritte lässt wartende Schritte in Ruhe,
 *  3. ki_auftraege.GET begrenzt das Paket, liefert übergroße Aufträge in Teilen und filtert auf einen Lauf.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->user = $this->makeUser($this->rootTeam);
    $this->actingAs($this->user);
    config(['foodalchemist.ai.provider' => 'core', 'foodalchemist.ai.backoff' => [], 'foodalchemist.ai.mcp_extern' => true]);
    $this->registry = app(ToolRegistry::class);
    $this->mcp = new ToolContext($this->user, $this->rootTeam, ['team_source' => 'user_current']);
    app()->instance(LLMProviderContract::class, new class extends FakeAiProvider
    {
        public function chat(array $messages, array $options = []): array
        {
            throw new \RuntimeException('Plattform-Provider darf im externen Weg nicht gerufen werden');
        }
    });
    $this->session = app(PlanningSessionService::class)->create($this->rootTeam, ['title' => 'Sommer', 'brief' => 'Sommerliches Hauptgericht, regional, gehoben']);
    $this->starte = fn () => app(KiExternModus::class)->imMcp(fn () => app(PlanningCascadeService::class)->starteKaskade(
        $this->rootTeam, 'gericht', $this->session, 'voll_kreativ', ['brief' => 'Sommerliches Hauptgericht, regional, gehoben', 'proposal_first' => true]));
    $this->auftrag = function (string $feature, array $messages): string {
        $uuid = (string) Str::uuid();
        DB::table('foodalchemist_ki_auftraege')->insert([
            'uuid' => $uuid, 'team_id' => $this->rootTeam->id, 'feature' => $feature, 'messages_hash' => hash('sha256', $uuid),
            'messages' => json_encode($messages, JSON_UNESCAPED_UNICODE), 'status' => 'offen', 'laeuft_ab_at' => now()->addDay(),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $uuid;
    };
    $this->get = fn (array $args = []) => $this->registry->get('foodalchemist.ki_auftraege.GET')->execute($args, $this->mcp);
});

it('Kaskaden-Status: ein auf die KI-Antwort wartender Schritt heißt so — nicht „wartet auf Worker“ — und nennt die nächste Aktion', function () {
    $run = ($this->starte)();
    $step = FoodAlchemistCascadeRunStep::where('cascade_run_id', $run->id)->first();
    $uuid = DB::table('foodalchemist_ki_auftraege')->where('status', 'offen')->value('uuid');
    expect($uuid)->not->toBeNull()->and($step->fresh()->status)->toBe('running');

    $status = app(PlanningCascadeService::class)->laufStatus($this->rootTeam, (int) $run->id);

    expect($status['schritte'][0]['phase'])->toBe('wartet auf KI-Antwort (1 Auftrag offen)')
        ->and($status['schritte'][0]['wartet_auf_ki']['auftrag_ids'])->toBe([$uuid])
        ->and($status['ki_auftraege_offen']['anzahl'])->toBe(1)
        ->and($status['ki_auftraege_offen']['naechster_schritt'])->toContain("ki_auftraege.GET(run_id={$run->id})")
        ->and($status['hinweis'])->toContain('wartet auf 1 KI-Antwort')->not->toContain('Der Worker rechnet noch');

    // ki_auftraege.GET mit run_id liefert genau diesen Auftrag, mit dem Lauf-Schlüssel
    $offen = ($this->get)(['run_id' => $run->id]);
    expect($offen->success)->toBeTrue($offen->error ?? '')
        ->and(array_column($offen->data['auftraege'], 'auftrag_id'))->toBe([$uuid])
        ->and($offen->data['auftraege'][0]['lauf'])->toContain('dish-proposal:');

    // beantwortet → der Lauf wartet nicht mehr
    $ok = $this->registry->get('foodalchemist.ki_auftraege.ANTWORT')->execute(['anbieter' => 'anthropic', 'modell' => 'claude-opus-5-5', 'antworten' => [[
        'auftrag_id' => $uuid, 'werte' => ['werte' => ['ideen' => [['titel' => 'Geschmorte Schulter vom Rind', 'beschreibung' => 'Mit Wurzelgemüse und Jus.',
            'komponenten' => [['name' => 'Rinderschulter', 'funktion' => 'Hauptkomponente', 'herstellung' => 'schmoren']]]]], 'confidence' => 0.8, 'reasoning' => 'stub'],
    ]]], $this->mcp);
    expect($ok->success)->toBeTrue($ok->error ?? '');

    $danach = app(PlanningCascadeService::class)->laufStatus($this->rootTeam, (int) $run->id);
    expect($danach['ki_auftraege_offen'] ?? null)->toBeNull()
        ->and($danach['schritte'][0]['wartet_auf_ki'] ?? null)->toBeNull()
        ->and($step->fresh()->status)->toBe('geplant');
});

it('ein Lauf ohne offenen Auftrag behält den bisherigen Text (kein falsches „wartet auf KI“)', function () {
    $run = ($this->starte)();
    DB::table('foodalchemist_ki_auftraege')->delete();

    $status = app(PlanningCascadeService::class)->laufStatus($this->rootTeam, (int) $run->id);

    expect($status['schritte'][0]['phase'])->toBe('eingereiht — wartet auf Worker')
        ->and($status['ki_auftraege_offen'] ?? null)->toBeNull()
        ->and($status['hinweis'])->toContain('Der Worker rechnet noch');
});

it('Freiräumen: ein Schritt, der auf eine KI-Antwort wartet, ist nicht verwaist — ohne Auftrag wird er es', function () {
    $run = ($this->starte)();
    $step = FoodAlchemistCascadeRunStep::where('cascade_run_id', $run->id)->first();
    DB::table('foodalchemist_cascade_run_steps')->where('id', $step->id)->update(['updated_at' => now()->subHours(2)]);
    $cascade = app(PlanningCascadeService::class);

    expect($cascade->reapeVerwaisteSteps($this->rootTeam, (int) $run->id))->toBe(0)
        ->and($step->fresh()->status)->toBe('running');

    DB::table('foodalchemist_ki_auftraege')->delete();
    DB::table('foodalchemist_cascade_run_steps')->where('id', $step->id)->update(['updated_at' => now()->subHours(2)]);

    expect($cascade->reapeVerwaisteSteps($this->rootTeam, (int) $run->id))->toBe(1)
        ->and($step->fresh()->status)->toBe('failed');
});

it('ki_auftraege.GET begrenzt das Paket auf max_zeichen und nennt den Rest', function () {
    $ids = [];
    foreach (range(1, 3) as $i) {
        $ids[] = ($this->auftrag)('gp.anker', [['role' => 'user', 'content' => str_repeat('a', 3000)]]);
    }

    $r = ($this->get)(['max_zeichen' => 5000]);

    expect($r->success)->toBeTrue($r->error ?? '')
        ->and(array_column($r->data['auftraege'], 'auftrag_id'))->toBe([$ids[0]])
        ->and($r->data['auftraege'][0]['messages'][0]['content'])->toBe(str_repeat('a', 3000))
        ->and($r->data['offen_gesamt'])->toBe(3)->and($r->data['weitere'])->toBe(2)
        ->and($r->data['note'])->toContain('2 weitere offen');

    // ohne enge Grenze passen alle drei hinein — wie bisher
    $alle = ($this->get)();
    expect(array_column($alle->data['auftraege'], 'auftrag_id'))->toBe($ids)->and($alle->data['weitere'])->toBe(0);
});

it('ki_auftraege.GET liefert einen übergroßen Auftrag in Teilen, zeichengenau zusammensetzbar', function () {
    $system = 'Regelwerk ' . str_repeat('ä', 6990);                     // 7000 Zeichen, Umlaute: Teilen zählt Zeichen, nicht Bytes
    $user = 'Kontext ' . implode(' ', range(1, 1500));
    $klein = ($this->auftrag)('gp.anker', [['role' => 'user', 'content' => 'kurz']]);
    $gross = ($this->auftrag)('recipe.generator', [['role' => 'system', 'content' => $system], ['role' => 'user', 'content' => $user]]);

    // Übersicht: beide Aufträge, ohne Prompt, mit Größe
    $kopf = ($this->get)(['nur_kopf' => true, 'max_zeichen' => 5000]);
    expect(array_column($kopf->data['auftraege'], 'auftrag_id'))->toBe([$klein, $gross])
        ->and($kopf->data['auftraege'][1])->not->toHaveKey('messages')
        ->and($kopf->data['auftraege'][1]['zeichen'])->toBe(mb_strlen($system) + mb_strlen($user))
        ->and($kopf->data['auftraege'][1]['teile'])->toBeGreaterThan(1);

    // gemischtes Paket: der kleine kommt ganz, der große erst im nächsten Aufruf
    $paket = ($this->get)(['max_zeichen' => 5000]);
    expect(array_column($paket->data['auftraege'], 'auftrag_id'))->toBe([$klein])->and($paket->data['weitere'])->toBe(1);

    // Einzelabruf in Teilen
    $eins = ($this->get)(['auftrag_id' => $gross, 'max_zeichen' => 5000]);
    expect($eins->success)->toBeTrue($eins->error ?? '')->and($eins->data['auftraege'][0]['teil'])->toBe(1);
    $teile = $eins->data['auftraege'][0]['teile'];
    $zusammen = [];
    for ($t = 1; $t <= $teile; $t++) {
        $r = ($this->get)(['auftrag_id' => $gross, 'teil' => $t, 'max_zeichen' => 5000]);
        $zeichenImTeil = 0;
        foreach ($r->data['auftraege'][0]['messages'] as $stueck) {
            $zusammen[$stueck['nr']] = ($zusammen[$stueck['nr']] ?? '') . $stueck['content'];
            $zeichenImTeil += mb_strlen($stueck['content']);
        }
        expect($zeichenImTeil)->toBeLessThanOrEqual(5000);
    }
    expect($zusammen)->toBe([1 => $system, 2 => $user]);

    $zuViel = ($this->get)(['auftrag_id' => $gross, 'teil' => $teile + 1, 'max_zeichen' => 5000]);
    expect($zuViel->success)->toBeFalse();
});

it('ki_auftraege.GET: unbekannter Lauf und nicht mehr offener Auftrag werden klar abgewiesen', function () {
    expect(($this->get)(['run_id' => 999999])->success)->toBeFalse()
        ->and(($this->get)(['auftrag_id' => (string) Str::uuid()])->success)->toBeFalse();
});

it('planung_kaskade.WEITER führt den Lauf: Frage → Antwort → Tor → Freigabe, mit einem Werkzeug', function () {
    $run = ($this->starte)();
    $step = FoodAlchemistCascadeRunStep::where('cascade_run_id', $run->id)->first();
    $weiter = fn (array $args = []) => $this->registry->get('foodalchemist.planung_kaskade.WEITER')->execute(['run_id' => $run->id] + $args, $this->mcp);

    // 1) die Plattform hat eine Frage
    $frage = $weiter();
    expect($frage->success)->toBeTrue($frage->error ?? '')
        ->and($frage->data['zustand'])->toBe('frage')
        ->and($frage->data['auftraege'])->toHaveCount(1)
        ->and($frage->data['auftraege'][0]['messages'])->not->toBeEmpty()
        ->and($frage->data['naechster_schritt'])->toContain('planung_kaskade.WEITER');

    // 2) Antwort mit demselben Werkzeug zurück → der Job läuft weiter, der Plan steht am Tor
    $tor = $weiter(['anbieter' => 'anthropic', 'modell' => 'claude-opus-5-5', 'antworten' => [[
        'auftrag_id' => $frage->data['auftraege'][0]['auftrag_id'],
        'werte' => ['werte' => ['ideen' => [['titel' => 'Geschmorte Schulter vom Rind', 'beschreibung' => 'Mit Wurzelgemüse und Jus.',
            'komponenten' => [['name' => 'Rinderschulter', 'funktion' => 'Hauptkomponente', 'herstellung' => 'schmoren']]]]], 'confidence' => 0.8, 'reasoning' => 'stub'],
    ]]]);
    expect($tor->success)->toBeTrue($tor->error ?? '')
        ->and($tor->data['antworten']['angenommen'])->toBe(1)
        ->and($tor->data['zustand'])->toBe('tor')
        ->and($tor->data['tor'])->toBe('plan_annehmen')
        ->and(array_column($tor->data['schritte'], 'step_id'))->toBe([(int) $step->id])
        ->and($tor->data['naechster_schritt'])->toContain('planung_kaskade.FREIGABE');

    // 3) WEITER gibt nicht selbst frei: ohne FREIGABE bleibt das Tor stehen
    expect($weiter()->data['zustand'])->toBe('tor')->and($step->fresh()->status)->toBe('geplant');
});

it('planung_kaskade.WEITER: Antworten ohne anbieter/modell werden abgewiesen, ein fremder Lauf ist nicht sichtbar', function () {
    $run = ($this->starte)();
    $tool = $this->registry->get('foodalchemist.planung_kaskade.WEITER');
    $uuid = DB::table('foodalchemist_ki_auftraege')->where('status', 'offen')->value('uuid');

    expect($tool->execute(['run_id' => $run->id, 'antworten' => [['auftrag_id' => $uuid, 'werte' => ['werte' => []]]]], $this->mcp)->success)->toBeFalse()
        ->and(DB::table('foodalchemist_ki_auftraege')->where('uuid', $uuid)->value('status'))->toBe('offen')
        ->and($tool->execute(['run_id' => 999999], $this->mcp)->success)->toBeFalse();
});

it('planung_kaskade.WEITER meldet „arbeitet“, solange ein Schritt läuft und keine Frage offen ist', function () {
    $run = ($this->starte)();
    DB::table('foodalchemist_ki_auftraege')->delete();

    $r = $this->registry->get('foodalchemist.planung_kaskade.WEITER')->execute(['run_id' => $run->id], $this->mcp);

    expect($r->data['zustand'])->toBe('arbeitet')->and($r->data['warte_sekunden'])->toBeGreaterThan(0);
});

it('Lauf 65: ein Gericht-Schritt aus einer Idee wartet sichtbar auf seinen KI-Auftrag (Schlüssel materialize-idea je Idee)', function () {
    $run = ($this->starte)();
    DB::table('foodalchemist_ki_auftraege')->delete();
    $step = $run->steps()->orderBy('sort')->first();
    $step->update(['context_snapshot' => array_merge(is_array($step->context_snapshot) ? $step->context_snapshot : [], ['dish_idea_id' => 4711])]);
    $uuid = ($this->auftrag)('vk.generator', [['role' => 'user', 'content' => 'Gericht bauen']]);
    DB::table('foodalchemist_ki_auftraege')->where('uuid', $uuid)->update(['lauf_key' => 'materialize-idea:' . $this->rootTeam->id . ':4711']);

    $status = app(PlanningCascadeService::class)->laufStatus($this->rootTeam, (int) $run->id);

    expect($status['schritte'][0]['phase'])->toBe('wartet auf KI-Antwort (1 Auftrag offen)')
        ->and($status['ki_auftraege_offen']['auftrag_ids'])->toBe([$uuid]);
    $offen = ($this->get)(['run_id' => $run->id]);
    expect(array_column($offen->data['auftraege'], 'auftrag_id'))->toBe([$uuid]);
});
