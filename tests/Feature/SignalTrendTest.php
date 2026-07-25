<?php

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Tools\ToolRegistry;
use Platform\FoodAlchemist\Enums\SignalSeverity;
use Platform\FoodAlchemist\Enums\SignalTyp;
use Platform\FoodAlchemist\Models\FoodAlchemistSignalSnapshot;
use Platform\FoodAlchemist\Services\SignalService;
use Platform\FoodAlchemist\Services\SignalTrendService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 21 · S2a (Tranche E · E1) — Zeitreihe der Qualitäts-Zähler.
 *
 * Kern der Prüfung: die Reihe muss **dicht** sein (auch Nullen), sonst ist „behoben"
 * nicht von „damals nicht gemessen" zu unterscheiden — genau diese Unterscheidung
 * braucht das Drift-Signal (E3) später, damit ein neu gebauter Check nicht als
 * Verschlechterung durchschlägt.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->trend = app(SignalTrendService::class);
    $this->signals = app(SignalService::class);
});

it('E1: ein Lauf schreibt beide Quellen dicht — jede Lücken-Metrik und jeden Signal-Typ, auch mit 0', function () {
    $rows = $this->trend->schreibeSnapshot($this->rootTeam);

    $dq = FoodAlchemistSignalSnapshot::where('team_id', $this->rootTeam->id)
        ->where('source', FoodAlchemistSignalSnapshot::SOURCE_DQ)->get();
    $sig = FoodAlchemistSignalSnapshot::where('team_id', $this->rootTeam->id)
        ->where('source', FoodAlchemistSignalSnapshot::SOURCE_SIGNALS)->get();

    expect($rows)->toBe($dq->count() + $sig->count())
        // Jeder Signal-Typ bekommt eine Zeile — auch die, die nie gefeuert haben (Nullen).
        ->and($sig)->toHaveCount(count(SignalTyp::cases()))
        ->and($sig->every(fn ($r) => (int) $r->count === 0))->toBeTrue()
        // Die Lücken-Metriken der Ampel sind vollständig da, ein Bestands-Total nicht.
        ->and($dq->pluck('metric_key')->all())->toContain('rezept_mengen_luecke', 'br_ek_teil')
        ->and($dq->pluck('metric_key')->all())->not->toContain('gp_approved');

    // Ein Lauf = ein Zeitstempel (Basis für das Paaren „letzter Lauf" ↔ „Vorlauf").
    expect($dq->merge($sig)->pluck('measured_at')->map(fn ($v) => (string) $v)->unique())->toHaveCount(1);
});

it('E1: offene Signale landen mit Severity-Aufschlüsselung in der Signal-Zeile, erledigte nicht', function () {
    $this->signals->erzeuge($this->rootTeam, SignalTyp::VeraltetePreise, SignalSeverity::Warnung, 'A', ['dedup_key' => 'a']);
    $this->signals->erzeuge($this->rootTeam, SignalTyp::VeraltetePreise, SignalSeverity::Kritisch, 'B', ['dedup_key' => 'b']);
    $erledigt = $this->signals->erzeuge($this->rootTeam, SignalTyp::VeraltetePreise, SignalSeverity::Info, 'C', ['dedup_key' => 'c']);
    $this->signals->abschliessen($this->rootTeam, $erledigt->id);

    $this->trend->schreibeSnapshot($this->rootTeam);

    $row = FoodAlchemistSignalSnapshot::where('team_id', $this->rootTeam->id)
        ->where('source', FoodAlchemistSignalSnapshot::SOURCE_SIGNALS)
        ->where('metric_key', SignalTyp::VeraltetePreise->value)->firstOrFail();

    expect((int) $row->count)->toBe(2)
        ->and($row->severity_counts)->toEqualCanonicalizing(['warnung' => 1, 'kritisch' => 1])
        ->and($row->signal_type)->toBe(SignalTyp::VeraltetePreise->value);
});

it('E1: Serie kommt älteste zuerst, Delta vergleicht letzten Lauf mit dem Vorlauf', function () {
    $key = SignalTyp::VeraltetePreise->value;

    // Lauf 1: 0 offene
    $this->trend->schreibeSnapshot($this->rootTeam, now()->subDays(2));
    // Lauf 2: 2 offene
    $this->signals->erzeuge($this->rootTeam, SignalTyp::VeraltetePreise, SignalSeverity::Warnung, 'A', ['dedup_key' => 'a']);
    $this->signals->erzeuge($this->rootTeam, SignalTyp::VeraltetePreise, SignalSeverity::Warnung, 'B', ['dedup_key' => 'b']);
    $this->trend->schreibeSnapshot($this->rootTeam, now()->subDay());
    // Lauf 3: eines erledigt → 1 offen
    $offen = \Platform\FoodAlchemist\Models\FoodAlchemistSignal::where('dedup_key', 'a')->firstOrFail();
    $this->signals->abschliessen($this->rootTeam, $offen->id);
    $this->trend->schreibeSnapshot($this->rootTeam, now());

    $serie = $this->trend->serie($this->rootTeam, $key);
    expect(array_column($serie, 'count'))->toBe([0, 2, 1]);

    $delta = $this->trend->delta($this->rootTeam, $key);
    expect($delta['count'])->toBe(1)
        ->and($delta['previous'])->toBe(2)
        ->and($delta['delta'])->toBe(-1)
        ->and($delta['pct'])->toBe(-50.0);
});

it('E1: von 0 auf n ist ein Neuauftreten, keine Prozent-Angabe — und ein nie gemessener Key liefert null', function () {
    $key = SignalTyp::VeraltetePreise->value;
    $this->trend->schreibeSnapshot($this->rootTeam, now()->subDay());
    $this->signals->erzeuge($this->rootTeam, SignalTyp::VeraltetePreise, SignalSeverity::Warnung, 'A', ['dedup_key' => 'a']);
    $this->trend->schreibeSnapshot($this->rootTeam, now());

    $delta = $this->trend->delta($this->rootTeam, $key);
    expect($delta['delta'])->toBe(1)
        // pct wäre „+100 %" von einer Nullbasis = Scheingenauigkeit; E3 entscheidet über delta.
        ->and($delta['pct'])->toBeNull()
        ->and($this->trend->delta($this->rootTeam, 'gibt_es_nicht'))->toBeNull()
        ->and($this->trend->serie($this->rootTeam, 'gibt_es_nicht'))->toBe([]);
});

it('E1: doppelter Lauf in derselben Sekunde überschreibt statt zu doppeln', function () {
    $at = now();
    $this->trend->schreibeSnapshot($this->rootTeam, $at);
    $vorher = FoodAlchemistSignalSnapshot::where('team_id', $this->rootTeam->id)->count();
    $this->signals->erzeuge($this->rootTeam, SignalTyp::VeraltetePreise, SignalSeverity::Warnung, 'A', ['dedup_key' => 'a']);
    $this->trend->schreibeSnapshot($this->rootTeam, $at);

    expect(FoodAlchemistSignalSnapshot::where('team_id', $this->rootTeam->id)->count())->toBe($vorher)
        ->and($this->trend->serie($this->rootTeam, SignalTyp::VeraltetePreise->value))->toBe([[
            'measured_at' => (string) $at->copy()->startOfSecond(),
            'count' => 1,
            'source' => FoodAlchemistSignalSnapshot::SOURCE_SIGNALS,
        ]]);
});

it('E1: Zeitreihe ist team-eigen — ein fremdes Team sieht die eigene Serie nicht', function () {
    $this->trend->schreibeSnapshot($this->rootTeam);

    expect($this->trend->serie($this->childB, SignalTyp::VeraltetePreise->value))->toBe([])
        ->and($this->trend->uebersicht($this->childB)['measured_at'])->toBeNull()
        ->and($this->trend->uebersicht($this->rootTeam)['measured_at'])->not->toBeNull();
});

it('E1: Übersicht paart letzten Lauf mit Vorlauf; ein erst später gebauter Check gilt nicht als Verschlechterung', function () {
    $vor = now()->subDay();
    $this->trend->schreibeSnapshot($this->rootTeam, $vor);
    // Metrik, die es im Vorlauf noch nicht gab (Check neu gebaut) — von Hand nachgestellt.
    $jetzt = now();
    $this->trend->schreibeSnapshot($this->rootTeam, $jetzt);
    FoodAlchemistSignalSnapshot::create([
        'team_id' => $this->rootTeam->id, 'source' => FoodAlchemistSignalSnapshot::SOURCE_DQ,
        'metric_key' => 'neuer_check', 'count' => 7, 'measured_at' => $jetzt->copy()->startOfSecond(),
    ]);

    $u = $this->trend->uebersicht($this->rootTeam);
    $neu = collect($u['metriken'])->firstWhere('metric_key', 'neuer_check');

    expect($u['previous_at'])->toBe((string) $vor->copy()->startOfSecond())
        ->and($neu['count'])->toBe(7)
        ->and($neu['previous'])->toBeNull()
        ->and($neu['delta'])->toBeNull();
});

it('E1: Detektor-Lauf schreibt die Zeitreihe mit (Scheduler-Pfad, kein Zweit-Job)', function () {
    $this->artisan('foodalchemist:signale-detektor', ['--team' => $this->rootTeam->id])->assertSuccessful();

    expect(FoodAlchemistSignalSnapshot::where('team_id', $this->rootTeam->id)->count())->toBeGreaterThan(0);
});

it('E1: data-quality --snapshot schreibt die Zeitreihe, ohne Flag nicht', function () {
    $this->artisan('foodalchemist:data-quality', ['--team' => $this->rootTeam->id])->assertSuccessful();
    expect(FoodAlchemistSignalSnapshot::count())->toBe(0);

    $this->artisan('foodalchemist:data-quality', ['--team' => $this->rootTeam->id, '--snapshot' => true])->assertSuccessful();
    expect(FoodAlchemistSignalSnapshot::where('team_id', $this->rootTeam->id)->count())->toBeGreaterThan(0);
});

it('E1 · MCP-Lockstep: signal_trend.GET liefert Übersicht, Serie und den Leer-Hinweis', function () {
    $user = $this->makeUser($this->rootTeam);
    $this->actingAs($user);
    $tool = app(ToolRegistry::class)->get('foodalchemist.signal_trend.GET');
    $kontext = new ToolContext($user, $this->rootTeam);

    // Noch kein Snapshot → Erfolg mit Hinweis (kein Fehler: „nichts gemessen" ist kein Defekt).
    $leer = $tool->execute([], $kontext);
    expect($leer->success)->toBeTrue()->and($leer->data['measured_at'])->toBeNull();

    $this->trend->schreibeSnapshot($this->rootTeam, now()->subDay());
    $this->signals->erzeuge($this->rootTeam, SignalTyp::VeraltetePreise, SignalSeverity::Warnung, 'A', ['dedup_key' => 'a']);
    $this->trend->schreibeSnapshot($this->rootTeam, now());

    $u = $tool->execute(['only_worse' => true], $kontext);
    expect($u->success)->toBeTrue()
        ->and(collect($u->data['metriken'])->pluck('metric_key')->all())->toBe([SignalTyp::VeraltetePreise->value])
        ->and(collect($u->data['metriken'])->first()['label'])->toBe(SignalTyp::VeraltetePreise->label());

    $s = $tool->execute(['metric_key' => SignalTyp::VeraltetePreise->value], $kontext);
    expect($s->success)->toBeTrue()
        ->and(array_column($s->data['serie'], 'count'))->toBe([0, 1])
        ->and($s->data['delta']['delta'])->toBe(1);

    $nix = $tool->execute(['metric_key' => 'gibt_es_nicht'], $kontext);
    expect($nix->success)->toBeFalse()->and($nix->errorCode)->toBe('NOT_FOUND');
});

it('E1 · MCP-Lockstep: signal_trend.GET ohne auflösbares Team → NO_TEAM (Tenancy)', function () {
    // Kein Team im Kontext UND keins am User: die Basisklasse fällt bewusst auf
    // currentTeamRelation zurück (gleiches Verhalten wie die UI) — erst wenn auch das
    // leer ist, darf das Tool antworten, und dann mit NO_TEAM statt mit fremden Daten.
    $ohneTeam = \Platform\Core\Models\User::forceCreate([
        'name' => 'Teamlos', 'email' => 'teamlos@test.local',
        'password' => bcrypt('secret'), 'current_team_id' => null,
    ]);
    $res = app(ToolRegistry::class)->get('foodalchemist.signal_trend.GET')
        ->execute([], new ToolContext($ohneTeam, null));

    expect($res->success)->toBeFalse()->and($res->errorCode)->toBe('NO_TEAM');
});
