<?php

use Illuminate\Support\Facades\DB;
use Platform\FoodAlchemist\Exceptions\KiAuftragAusstehendException;
use Platform\FoodAlchemist\Services\Ai\AiGatewayService;
use Platform\FoodAlchemist\Services\Ai\KiAuftragService;
use Platform\FoodAlchemist\Services\Ai\KiExternModus;
use Platform\FoodAlchemist\Tests\Support\SeedsKanon;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class, SeedsKanon::class);

/**
 * #1002 P1: externer KI-Weg (Variante „Wiederholung") am Provider-Punkt des Gateways.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam));
    config(['foodalchemist.ai.provider' => 'fake']);
    $this->gw = app(AiGatewayService::class);
    $this->extern = fn (callable $f) => app(KiExternModus::class)->within($f);
});

it('extern: legt einen Auftrag an statt den Provider zu rufen, wirft „ausstehend"', function () {
    expect(fn () => ($this->extern)(fn () => $this->gw->propose('recipe.description', ['description' => 'Klarer Fond.'])))
        ->toThrow(KiAuftragAusstehendException::class);

    $a = DB::table('foodalchemist_ki_auftraege')->get();
    expect($a)->toHaveCount(1)
        ->and($a[0]->status)->toBe('offen')
        ->and($a[0]->feature)->toBe('recipe.description')
        ->and((int) $a[0]->team_id)->toBe($this->rootTeam->id)
        ->and(json_decode($a[0]->messages, true))->toBeArray()->not->toBeEmpty();
    // der Fehlerpfad bleibt im Call-Log sichtbar (06_KI §5 Pflicht 2)
    expect(DB::table('foodalchemist_ai_call_log')->whereNotNull('error')->count())->toBe(1);
});

it('extern: derselbe Aufruf wiederholt = kein zweiter Auftrag', function () {
    $lauf = fn () => ($this->extern)(fn () => $this->gw->propose('recipe.description', ['description' => 'Klarer Fond.']));
    expect($lauf)->toThrow(KiAuftragAusstehendException::class)
        ->and($lauf)->toThrow(KiAuftragAusstehendException::class);

    expect(DB::table('foodalchemist_ki_auftraege')->count())->toBe(1);
});

it('Roundtrip: Antwort speichern, Wiederholung nimmt sie durch dieselbe Prüfung, source=mcp im Log', function () {
    $lauf = fn () => ($this->extern)(fn () => $this->gw->propose('recipe.description', ['description' => 'Klarer Fond.']));
    expect($lauf)->toThrow(KiAuftragAusstehendException::class);
    $id = DB::table('foodalchemist_ki_auftraege')->value('uuid');

    app(KiAuftragService::class)->beantworte($this->rootTeam->id, $id, ['werte' => ['description' => 'Fertig.'], 'confidence' => 0.9], 'anthropic', 'claude-opus-5-5');
    $p = $lauf();

    expect($p->werte)->toBe(['description' => 'Fertig.'])
        ->and($p->confidence)->toBe(0.9)
        ->and($p->model)->toBe('anthropic/claude-opus-5-5');
    $log = DB::table('foodalchemist_ai_call_log')->whereNull('error')->first();
    expect(json_decode($log->prompt_parts, true)['quelle'])->toBe('mcp')
        ->and(DB::table('foodalchemist_ki_auftraege')->value('status'))->toBe('verbraucht');
    // Antwort ist einmalig: ein dritter Lauf legt wieder einen neuen Auftrag an
    expect($lauf)->toThrow(KiAuftragAusstehendException::class);
});

it('Ablehnung: unbekannte ID, fremdes Team, Nicht-JSON, doppelt, abgelaufen', function () {
    $lauf = fn () => ($this->extern)(fn () => $this->gw->propose('recipe.description', ['description' => 'x']));
    expect($lauf)->toThrow(KiAuftragAusstehendException::class);
    $svc = app(KiAuftragService::class);
    $id = DB::table('foodalchemist_ki_auftraege')->value('uuid');

    expect(fn () => $svc->beantworte($this->rootTeam->id, 'gibt-es-nicht', ['a' => 1], 'x', 'y'))->toThrow(RuntimeException::class)
        ->and(fn () => $svc->beantworte($this->rootTeam->id + 999, $id, ['a' => 1], 'x', 'y'))->toThrow(RuntimeException::class)
        ->and(fn () => $svc->beantworte($this->rootTeam->id, $id, 'kein json', 'x', 'y'))->toThrow(RuntimeException::class);
    expect(DB::table('foodalchemist_ki_auftraege')->value('status'))->toBe('offen');   // nichts geschrieben

    $svc->beantworte($this->rootTeam->id, $id, ['werte' => []], 'x', 'y');
    expect(fn () => $svc->beantworte($this->rootTeam->id, $id, ['werte' => []], 'x', 'y'))->toThrow(RuntimeException::class);   // schon beantwortet

    DB::table('foodalchemist_ki_auftraege')->update(['status' => 'offen', 'laeuft_ab_at' => now()->subMinute()]);
    expect(fn () => $svc->beantworte($this->rootTeam->id, $id, ['werte' => []], 'x', 'y'))->toThrow(RuntimeException::class)
        ->and(DB::table('foodalchemist_ki_auftraege')->value('status'))->toBe('abgelaufen');
});

it('Plattformweg bleibt unberührt: ohne Extern-Modus kein Auftrag', function () {
    $this->gw->propose('recipe.description', ['description' => 'Klarer Fond.']);

    expect(DB::table('foodalchemist_ki_auftraege')->count())->toBe(0);
});

it('Antwort ohne Umschlag (Fachfelder oben) wird übernommen statt eine Schleife identischer Aufträge zu starten (Bro-Scetta, io 08.11.2026)', function () {
    $lauf = fn () => ($this->extern)(fn () => $this->gw->propose('recipe.description', ['description' => 'Klarer Fond.'], [
        'structural_retry' => fn (array $p) => ! empty($p['werte']['description']),
    ]));
    expect($lauf)->toThrow(KiAuftragAusstehendException::class);
    $id = DB::table('foodalchemist_ki_auftraege')->value('uuid');

    app(KiAuftragService::class)->beantworte($this->rootTeam->id, $id, ['description' => 'Fertig.', 'confidence' => 0.8], 'anthropic', 'claude-opus-5-5');
    $p = $lauf();

    expect($p->werte)->toBe(['description' => 'Fertig.'])->and($p->confidence)->toBe(0.8)
        ->and(DB::table('foodalchemist_ki_auftraege')->count())->toBe(1, 'kein zweiter, identischer Auftrag');
});

it('wirklich unbrauchbare externe Antwort: kein stiller Re-Roll (kein identischer Folgeauftrag), sondern ein klarer Fehler', function () {
    $lauf = fn () => ($this->extern)(fn () => $this->gw->propose('recipe.description', ['description' => 'Klarer Fond.'], [
        'structural_retry' => fn (array $p) => ! empty($p['werte']['description']),
    ]));
    expect($lauf)->toThrow(KiAuftragAusstehendException::class);
    $id = DB::table('foodalchemist_ki_auftraege')->value('uuid');
    app(KiAuftragService::class)->beantworte($this->rootTeam->id, $id, ['werte' => ['anderes' => 'x']], 'anthropic', 'claude-opus-5-5');

    expect($lauf)->toThrow(RuntimeException::class, 'nicht verwertbar')
        ->and(DB::table('foodalchemist_ki_auftraege')->count())->toBe(1, 'kein identischer Folgeauftrag');
});

it('mitUmschlag: lässt korrekte Umschläge, Listen und Nicht-JSON unverändert, hebt confidence/reasoning/sources nach oben', function () {
    expect(KiAuftragService::mitUmschlag('{"werte":{"a":1},"confidence":0.5}'))->toBe('{"werte":{"a":1},"confidence":0.5}')
        ->and(KiAuftragService::mitUmschlag('[1,2]'))->toBe('[1,2]')
        ->and(KiAuftragService::mitUmschlag('kein json'))->toBe('kein json')
        ->and(json_decode(KiAuftragService::mitUmschlag('{"name":"X","reasoning":"r","sources":[]}'), true))
        ->toBe(['werte' => ['name' => 'X'], 'reasoning' => 'r', 'sources' => []]);
});

it('mehrere Antworten auf dieselbe Frage: die neueste gilt, damit eine Korrektur ankommt (Crostini, io 09.11.2026)', function () {
    $lauf = fn () => ($this->extern)(fn () => $this->gw->propose('recipe.description', ['description' => 'Klarer Fond.']));
    expect($lauf)->toThrow(KiAuftragAusstehendException::class);
    $erste = DB::table('foodalchemist_ki_auftraege')->first();
    app(KiAuftragService::class)->beantworte($this->rootTeam->id, $erste->uuid, ['werte' => ['description' => 'Alt.'], 'confidence' => 0.9], 'x', 'y');

    // zweite beantwortete Zeile zur selben Frage (wie nach einem zurückgerollten MCP-Lauf mit korrigierter Antwort)
    $neu = (array) $erste;
    unset($neu['id']);
    $neu['uuid'] = (string) \Illuminate\Support\Str::uuid7();
    $neu['antwort'] = json_encode(['werte' => ['description' => 'Korrigiert.'], 'confidence' => 0.9]);
    $neu['status'] = 'beantwortet';
    DB::table('foodalchemist_ki_auftraege')->insert($neu);

    expect($lauf()->werte)->toBe(['description' => 'Korrigiert.']);
});
