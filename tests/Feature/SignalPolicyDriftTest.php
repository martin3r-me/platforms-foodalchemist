<?php

use Livewire\Livewire;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Tools\ToolRegistry;
use Platform\FoodAlchemist\Enums\SignalSeverity;
use Platform\FoodAlchemist\Enums\SignalStatus;
use Platform\FoodAlchemist\Enums\SignalTyp;
use Platform\FoodAlchemist\Models\FoodAlchemistSignal;
use Platform\FoodAlchemist\Models\FoodAlchemistSignalSnapshot;
use Platform\FoodAlchemist\Services\SignalDetektorService;
use Platform\FoodAlchemist\Services\SignalPolicyService;
use Platform\FoodAlchemist\Services\SignalService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 21 · S2b (Tranche E · E2+E3) — Rausch-Guard + Drift-Meta-Signal.
 *
 * Die zwei Aussagen, die hier hängen müssen:
 *  - E2 dämpft die **Darstellung** des Bestands, nie den Befund: das Signal bleibt in
 *    der Tabelle und ist über den Typ-Filter aufklappbar.
 *  - E3 alarmiert bei **Veränderung**: Schwelle und Akzeptanz-Frist dürfen einen
 *    Zuwachs nicht wegkonfigurieren — sonst könnte man eine wachsende Lücke
 *    stummstellen. Nur `muted` schaltet beides ab.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->policies = app(SignalPolicyService::class);
    $this->signals = app(SignalService::class);
    $this->detektor = app(SignalDetektorService::class);
});

/** n offene Signale eines Typs erzeugen (jedes mit eigenem dedup_key, sonst dedupt der Service). */
function offeneSignale($team, SignalTyp $typ, int $n): void
{
    $svc = app(SignalService::class);
    for ($i = 0; $i < $n; $i++) {
        $svc->erzeuge($team, $typ, SignalSeverity::Warnung, "Fall {$i}", ['dedup_key' => $typ->value . "-{$i}"]);
    }
}

/** Snapshot-Zeile von Hand — erlaubt es, eine Historie zu stellen, ohne echte Messung. */
function snapshotZeile($team, string $source, string $key, string $at, int $count, ?string $signalType = null): void
{
    FoodAlchemistSignalSnapshot::create([
        'team_id' => $team->id, 'source' => $source, 'metric_key' => $key,
        'signal_type' => $signalType, 'count' => $count, 'measured_at' => $at,
    ]);
}

// ── E2 · Rausch-Guard ──────────────────────────────────────────────────────

it('E2: über der Schwelle fällt der Typ in eine Zustands-Zeile — die Einzel-Signale bleiben aber erhalten', function () {
    offeneSignale($this->rootTeam, SignalTyp::VeraltetePreise, 6);
    $this->policies->setzen($this->rootTeam, SignalTyp::VeraltetePreise, ['threshold' => 3, 'note' => 'Altbestand, bekannt']);

    $zeile = collect($this->policies->zustand($this->rootTeam))->firstWhere('type', SignalTyp::VeraltetePreise->value);
    expect($zeile['count'])->toBe(6)
        ->and($zeile['aggregiert'])->toBeTrue()
        ->and($zeile['state'])->toBe(SignalPolicyService::STATE_ALARM)
        ->and($zeile['note'])->toBe('Altbestand, bekannt');

    // Der Guard versteckt nur: ungefiltert weg, mit explizitem Typ-Filter wieder da.
    $ohneFilter = $this->signals->paginate(['exclude_types' => $this->policies->aggregierteTypen($this->rootTeam)], $this->rootTeam);
    $mitFilter = $this->signals->paginate(['type' => SignalTyp::VeraltetePreise->value, 'exclude_types' => [SignalTyp::VeraltetePreise->value]], $this->rootTeam);

    expect($ohneFilter->total())->toBe(0)
        ->and($mitFilter->total())->toBe(6)
        ->and(FoodAlchemistSignal::where('status', SignalStatus::Offen->value)->count())->toBe(6);
});

it('E2: unter der Schwelle bleibt alles Einzel-Alarm', function () {
    offeneSignale($this->rootTeam, SignalTyp::VeraltetePreise, 2);
    $this->policies->setzen($this->rootTeam, SignalTyp::VeraltetePreise, ['threshold' => 5]);

    $zeile = collect($this->policies->zustand($this->rootTeam))->firstWhere('type', SignalTyp::VeraltetePreise->value);
    expect($zeile['aggregiert'])->toBeFalse()
        ->and($this->policies->aggregierteTypen($this->rootTeam))->toBe([]);
});

it('E2: akzeptiert bis heute gilt noch, ab gestern ist die Frist abgelaufen', function () {
    offeneSignale($this->rootTeam, SignalTyp::MargeUnterZiel, 1);

    $this->policies->setzen($this->rootTeam, SignalTyp::MargeUnterZiel, ['accepted_until' => now()->toDateString()]);
    expect(collect($this->policies->zustand($this->rootTeam))->firstWhere('type', SignalTyp::MargeUnterZiel->value)['state'])
        ->toBe(SignalPolicyService::STATE_AKZEPTIERT);

    // Abgelaufen ⇒ wieder Alarm, ohne dass jemand die Policy anfassen muss.
    $this->policies->setzen($this->rootTeam, SignalTyp::MargeUnterZiel, ['accepted_until' => now()->subDay()->toDateString()]);
    expect(collect($this->policies->zustand($this->rootTeam))->firstWhere('type', SignalTyp::MargeUnterZiel->value)['state'])
        ->toBe(SignalPolicyService::STATE_FRIST_ABGELAUFEN);
});

it('E2: muted schaltet stumm und aggregiert immer, egal wie klein der Bestand ist', function () {
    offeneSignale($this->rootTeam, SignalTyp::RezeptVerwaist, 1);
    $this->policies->setzen($this->rootTeam, SignalTyp::RezeptVerwaist, ['muted' => true]);

    $zeile = collect($this->policies->zustand($this->rootTeam))->firstWhere('type', SignalTyp::RezeptVerwaist->value);
    expect($zeile['state'])->toBe(SignalPolicyService::STATE_STUMM)->and($zeile['aggregiert'])->toBeTrue();
});

it('E2: Typen ohne Bestand und ohne Policy bekommen keine Zeile', function () {
    expect($this->policies->zustand($this->rootTeam))->toBe([]);

    // Eine Policy allein genügt für die Zeile — sonst verlöre man die getroffene Entscheidung aus dem Blick.
    $this->policies->setzen($this->rootTeam, SignalTyp::PreisAnomalie, ['threshold' => 10]);
    expect(collect($this->policies->zustand($this->rootTeam))->pluck('type')->all())->toBe([SignalTyp::PreisAnomalie->value]);
});

it('E2: Kind-Team erbt die Eltern-Policy und kann sie mit einer eigenen überstimmen', function () {
    $this->policies->setzen($this->rootTeam, SignalTyp::VeraltetePreise, ['threshold' => 3]);
    expect($this->policies->fuer($this->childA, SignalTyp::VeraltetePreise)?->threshold)->toBe(3);

    $this->policies->setzen($this->childA, SignalTyp::VeraltetePreise, ['threshold' => 99]);
    $eigene = $this->policies->fuer($this->childA, SignalTyp::VeraltetePreise);
    expect($eigene?->threshold)->toBe(99)
        ->and($eigene?->isOwnedBy($this->childA))->toBeTrue()
        // Die Eltern-Zeile bleibt unangetastet — überstimmen ≠ überschreiben.
        ->and($this->policies->fuer($this->rootTeam, SignalTyp::VeraltetePreise)?->threshold)->toBe(3);
});

// ── E3 · Drift-Meta-Signal ────────────────────────────────────────────────

it('E3: ohne Vorlauf gibt es keinen Drift (erster Lauf ist keine Verschlechterung)', function () {
    snapshotZeile($this->rootTeam, FoodAlchemistSignalSnapshot::SOURCE_DQ, 'br_ek_teil', '2026-07-20 08:00:00', 50);

    expect($this->detektor->qualitaetsDrift($this->rootTeam))->toBe(0);
});

it('E3: ein erst jetzt gebauter Check alarmiert nicht — previous=null ist keine Verschlechterung', function () {
    snapshotZeile($this->rootTeam, FoodAlchemistSignalSnapshot::SOURCE_DQ, 'br_ek_teil', '2026-07-20 08:00:00', 10);
    snapshotZeile($this->rootTeam, FoodAlchemistSignalSnapshot::SOURCE_DQ, 'br_ek_teil', '2026-07-21 08:00:00', 10);
    // Neuer Check: existiert nur im jüngsten Lauf, mit sofort 300 Treffern.
    snapshotZeile($this->rootTeam, FoodAlchemistSignalSnapshot::SOURCE_DQ, 'rezept_verwaist', '2026-07-21 08:00:00', 300);

    expect($this->detektor->qualitaetsDrift($this->rootTeam))->toBe(0);
});

it('E3: kleiner Zuwachs ist kein Drift, ein deutlicher schon — und er wird nur einmal gemeldet', function () {
    // 12 → 14: +16,7 % und nur +2 absolut ⇒ unter beiden Schwellen.
    snapshotZeile($this->rootTeam, FoodAlchemistSignalSnapshot::SOURCE_DQ, 'br_ek_teil', '2026-07-20 08:00:00', 12);
    snapshotZeile($this->rootTeam, FoodAlchemistSignalSnapshot::SOURCE_DQ, 'br_ek_teil', '2026-07-21 08:00:00', 14);
    expect($this->detektor->qualitaetsDrift($this->rootTeam))->toBe(0);

    // 14 → 30: +114 % und +16 absolut ⇒ Drift.
    snapshotZeile($this->rootTeam, FoodAlchemistSignalSnapshot::SOURCE_DQ, 'br_ek_teil', '2026-07-22 08:00:00', 30);
    expect($this->detektor->qualitaetsDrift($this->rootTeam))->toBe(1);

    $sig = FoodAlchemistSignal::where('type', SignalTyp::QualitaetDrift->value)->firstOrFail();
    expect($sig->title)->toContain('14 → 30 (+16)')
        ->and($sig->payload['drift_metric'])->toBe('br_ek_teil')
        ->and($sig->payload['neuauftreten'])->toBeFalse()
        // Kein Fix-Knopf an der Trend-Zeile: der Fixer hängt am zugrundeliegenden Befund.
        ->and($sig->payload)->not->toHaveKey('metrik');

    // Zweiter Lauf ohne neue Messung: derselbe dedup_key ⇒ Update statt zweitem Signal.
    $this->detektor->qualitaetsDrift($this->rootTeam);
    expect(FoodAlchemistSignal::where('type', SignalTyp::QualitaetDrift->value)->count())->toBe(1);
});

it('E3: ein behobener Befund, der zurückkommt, alarmiert auch unter der Mengen-Schwelle', function () {
    snapshotZeile($this->rootTeam, FoodAlchemistSignalSnapshot::SOURCE_DQ, 'br_ek_teil', '2026-07-20 08:00:00', 0);
    snapshotZeile($this->rootTeam, FoodAlchemistSignalSnapshot::SOURCE_DQ, 'br_ek_teil', '2026-07-21 08:00:00', 1);

    expect($this->detektor->qualitaetsDrift($this->rootTeam))->toBe(1)
        ->and(FoodAlchemistSignal::where('type', SignalTyp::QualitaetDrift->value)->firstOrFail()->payload['neuauftreten'])->toBeTrue();
});

it('E3: dieselbe Lage auf beiden Quellen meldet nur einmal — die Ampel-Seite gewinnt', function () {
    foreach (['2026-07-20 08:00:00' => 10, '2026-07-21 08:00:00' => 40] as $at => $c) {
        snapshotZeile($this->rootTeam, FoodAlchemistSignalSnapshot::SOURCE_DQ, 'br_ek_teil', $at, $c, SignalTyp::EkKetteUnvollstaendig->value);
        snapshotZeile($this->rootTeam, FoodAlchemistSignalSnapshot::SOURCE_SIGNALS, SignalTyp::EkKetteUnvollstaendig->value, $at, $c, SignalTyp::EkKetteUnvollstaendig->value);
    }

    expect($this->detektor->qualitaetsDrift($this->rootTeam))->toBe(1)
        ->and(FoodAlchemistSignal::where('type', SignalTyp::QualitaetDrift->value)->firstOrFail()->payload['drift_source'])
        ->toBe(FoodAlchemistSignalSnapshot::SOURCE_DQ);
});

it('E3: Schwelle und Akzeptanz dämpfen nur den Bestand — der Zuwachs meldet sich weiter; nur muted schweigt', function () {
    $typ = SignalTyp::VeraltetePreise;
    snapshotZeile($this->rootTeam, FoodAlchemistSignalSnapshot::SOURCE_SIGNALS, $typ->value, '2026-07-20 08:00:00', 10, $typ->value);
    snapshotZeile($this->rootTeam, FoodAlchemistSignalSnapshot::SOURCE_SIGNALS, $typ->value, '2026-07-21 08:00:00', 40, $typ->value);

    // Bekannte, akzeptierte und aggregierte Lage — und trotzdem meldet der Zuwachs.
    $this->policies->setzen($this->rootTeam, $typ, ['threshold' => 5, 'accepted_until' => now()->addMonth()->toDateString()]);
    expect($this->detektor->qualitaetsDrift($this->rootTeam))->toBe(1);

    FoodAlchemistSignal::where('type', SignalTyp::QualitaetDrift->value)->forceDelete();
    $this->policies->setzen($this->rootTeam, $typ, ['muted' => true]);
    expect($this->detektor->qualitaetsDrift($this->rootTeam))->toBe(0);
});

it('E3: der Drift zählt sich nicht selbst', function () {
    $typ = SignalTyp::QualitaetDrift;
    snapshotZeile($this->rootTeam, FoodAlchemistSignalSnapshot::SOURCE_SIGNALS, $typ->value, '2026-07-20 08:00:00', 1, $typ->value);
    snapshotZeile($this->rootTeam, FoodAlchemistSignalSnapshot::SOURCE_SIGNALS, $typ->value, '2026-07-21 08:00:00', 20, $typ->value);

    expect($this->detektor->qualitaetsDrift($this->rootTeam))->toBe(0);
});

// ── UI ─────────────────────────────────────────────────────────────────────

it('E2 · Signale-Seite: die Zustands-Zeile ersetzt die Einzel-Alarme, der Typ-Filter holt sie zurück', function () {
    $this->actingAs($this->makeUser($this->rootTeam));
    offeneSignale($this->rootTeam, SignalTyp::VeraltetePreise, 4);
    $this->policies->setzen($this->rootTeam, SignalTyp::VeraltetePreise, ['threshold' => 2, 'note' => 'Altbestand aus dem Import']);

    $lw = Livewire::test(\Platform\FoodAlchemist\Livewire\ReviewQueue::class)->set('tab', 'signale');
    $lw->assertSee('Altbestand aus dem Import')   // Zustands-Zeile mit Begründung ist da …
        ->assertDontSee('Fall 0');                 // … die vier Einzel-Zeilen nicht.
    expect($lw->viewData('signale')->total())->toBe(0);

    // Aufklappen über die Zeile („4 anzeigen") = Typ-Filter setzen.
    $lw->call('setSignalTyp', SignalTyp::VeraltetePreise->value)->assertSee('Fall 0');
    expect($lw->viewData('signale')->total())->toBe(4);
});

// ── MCP-Lockstep ───────────────────────────────────────────────────────────

it('E2 · MCP-Lockstep: signal_policies.GET liest die Zustände, signal_policy.PUT setzt und nimmt zurück', function () {
    $user = $this->makeUser($this->rootTeam);
    $this->actingAs($user);
    $kontext = new ToolContext($user, $this->rootTeam);
    $get = app(ToolRegistry::class)->get('foodalchemist.signal_policies.GET');
    $put = app(ToolRegistry::class)->get('foodalchemist.signal_policy.PUT');

    offeneSignale($this->rootTeam, SignalTyp::VeraltetePreise, 4);

    $res = $put->execute(['type' => SignalTyp::VeraltetePreise->value, 'threshold' => 2, 'accepted_until' => '2099-01-31', 'note' => 'bekannt'], $kontext);
    expect($res->success)->toBeTrue()->and($res->data['threshold'])->toBe(2);

    $zustand = $get->execute([], $kontext);
    $zeile = collect($zustand->data['zustaende'])->firstWhere('type', SignalTyp::VeraltetePreise->value);
    expect($zustand->success)->toBeTrue()
        ->and($zeile['state'])->toBe(SignalPolicyService::STATE_AKZEPTIERT)
        ->and($zeile['aggregiert'])->toBeTrue()
        ->and($get->execute(['nur_alarm' => true], $kontext)->data['anzahl'])->toBe(0);

    // Ungültiger Typ + leerer Aufruf werden abgewiesen, nicht stillschweigend geschluckt.
    expect($put->execute(['type' => 'gibt_es_nicht'], $kontext)->errorCode)->toBe('VALIDATION_ERROR')
        ->and($put->execute(['type' => SignalTyp::VeraltetePreise->value], $kontext)->errorCode)->toBe('VALIDATION_ERROR');

    expect($put->execute(['type' => SignalTyp::VeraltetePreise->value, 'zuruecksetzen' => true], $kontext)->data['entfernt'])->toBeTrue()
        ->and($this->policies->fuer($this->rootTeam, SignalTyp::VeraltetePreise))->toBeNull();
});

it('E2 · MCP-Lockstep: beide Policy-Tools ohne auflösbares Team → NO_TEAM (Tenancy)', function () {
    $ohneTeam = \Platform\Core\Models\User::forceCreate([
        'name' => 'Teamlos', 'email' => 'teamlos-policy@test.local',
        'password' => bcrypt('secret'), 'current_team_id' => null,
    ]);
    $kontext = new ToolContext($ohneTeam, null);

    foreach (['foodalchemist.signal_policies.GET', 'foodalchemist.signal_policy.PUT'] as $name) {
        $res = app(ToolRegistry::class)->get($name)->execute(['type' => SignalTyp::VeraltetePreise->value], $kontext);
        expect($res->success)->toBeFalse()->and($res->errorCode)->toBe('NO_TEAM');
    }
});
