<?php

use Platform\FoodAlchemist\Enums\SignalSeverity;
use Platform\FoodAlchemist\Enums\SignalStatus;
use Platform\FoodAlchemist\Enums\SignalTyp;
use Platform\FoodAlchemist\Models\FoodAlchemistSignal;
use Platform\FoodAlchemist\Services\DataQualityService;
use Platform\FoodAlchemist\Services\SignalService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * 22·H4a — die zwei gewollten Verschiebungen am Signal-Lifecycle, jede mit eigenem Namen:
 * V-011 (eine auf 0 gemessene Lücke schließt ihr Signal) und V-009 (Wiederkehr wird gezählt).
 *
 * Was NICHT verschoben werden darf, steht daneben in `SignalLifecycleGoldenTest`.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->dq = app(DataQualityService::class);
    $this->signals = app(SignalService::class);
});

// ---- V-011 · Schließ-Zweig ------------------------------------------------

it('V-011: eine Lücke, die verschwindet, schließt ihr Signal — auch ohne Fixer-Knopf', function () {
    $gp = $this->makeGp($this->rootTeam, 'Lachs');
    $gp->update(['status' => 'approved']);

    $erst = $this->dq->emittiereUndSchliesse($this->rootTeam);
    $offen = FoodAlchemistSignal::where('team_id', $this->rootTeam->id)
        ->where('status', SignalStatus::Offen->value)->count();

    expect($erst['emittiert'])->toBeGreaterThan(0)
        ->and($erst['geschlossen'])->toBe(0)
        ->and($offen)->toBe($erst['emittiert']);

    // Die Lücke wird auf ANDEREM Weg behoben als über den Fixer (hier: der GP ist weg).
    $gp->delete();

    $zweit = $this->dq->emittiereUndSchliesse($this->rootTeam);

    expect($zweit['geschlossen'])->toBe($erst['emittiert'])
        ->and(FoodAlchemistSignal::where('team_id', $this->rootTeam->id)
            ->where('status', SignalStatus::Offen->value)->count())->toBe(0);

    $zeile = FoodAlchemistSignal::where('team_id', $this->rootTeam->id)->firstOrFail();
    expect($zeile->status->value)->toBe(SignalStatus::Erledigt->value)
        ->and($zeile->erledigt_at)->not->toBeNull()
        ->and($zeile->payload['auto_geschlossen'])->toBe('Lücke gemessen 0 — automatisch geschlossen')
        ->and($zeile->payload)->toHaveKey('auto_geschlossen_am')
        // Der Befund selbst bleibt lesbar — der Grund steht daneben, nicht darüber.
        ->and($zeile->title)->toStartWith('1 — ')
        ->and($zeile->payload)->toHaveKey('metrik');
});

it('V-011: die Ampel schließt nur ihre eigenen Signale, nicht die des Detektors', function () {
    // Fremde Zeile: gleicher Typ, gleicher dedup_key — aber vom Detektor, nicht von der Ampel.
    $fremd = $this->signals->erzeuge($this->rootTeam, SignalTyp::DatenqualitaetGpLa, SignalSeverity::Warnung,
        '7 — Detektor-Befund', ['dedup_key' => 'dq-gp-allergen-konfidenz', 'source' => 'detektor']);

    // Leerer Bestand ⇒ die Ampel misst diese Metrik auf 0 und würde ohne Quellen-Filter
    // greifen. „Befund weg" ist auf der Detektor-Seite je Detektor definiert (V-011).
    $r = $this->dq->emittiereUndSchliesse($this->rootTeam);

    expect($r['geschlossen'])->toBe(0)
        ->and($fremd->refresh()->status->value)->toBe(SignalStatus::Offen->value);
});

it('V-011: ein bereits ignoriertes Signal wird nicht nachträglich auf erledigt gedreht', function () {
    $gp = $this->makeGp($this->rootTeam, 'Lachs');
    $gp->update(['status' => 'approved']);
    $this->dq->emittiereSignale($this->rootTeam);

    $s = FoodAlchemistSignal::where('team_id', $this->rootTeam->id)->firstOrFail();
    $this->signals->ignorieren($this->rootTeam, $s->id);

    $gp->delete();
    $this->dq->emittiereUndSchliesse($this->rootTeam);

    expect($s->refresh()->status->value)->toBe(SignalStatus::Ignoriert->value)
        ->and($s->erledigt_at)->toBeNull()
        ->and($s->payload)->not->toHaveKey('auto_geschlossen');
});

// ---- V-009 · Wiederkehr ---------------------------------------------------

it('V-009: die Anlage ist die erste Sichtung — seen_count startet bei 1', function () {
    $s = $this->signals->erzeuge($this->rootTeam, SignalTyp::PreisAnomalie, SignalSeverity::Warnung, 'Erster', [
        'dedup_key' => 'x:1',
    ]);

    expect($s->seen_count)->toBe(1)
        ->and($s->last_seen_at)->not->toBeNull();
});

it('V-009: jede Wieder-Emission zählt hoch und stempelt neu', function () {
    $a = $this->signals->erzeuge($this->rootTeam, SignalTyp::PreisAnomalie, SignalSeverity::Warnung, 'Erster', [
        'dedup_key' => 'x:1',
    ]);
    $erstesMal = $a->last_seen_at;

    $this->travel(90)->seconds();
    $b = $this->signals->erzeuge($this->rootTeam, SignalTyp::PreisAnomalie, SignalSeverity::Warnung, 'Erster', [
        'dedup_key' => 'x:1',
    ]);
    $this->travel(90)->seconds();
    $c = $this->signals->erzeuge($this->rootTeam, SignalTyp::PreisAnomalie, SignalSeverity::Warnung, 'Erster', [
        'dedup_key' => 'x:1',
    ]);

    expect($b->id)->toBe($a->id)
        ->and($c->id)->toBe($a->id)
        ->and($c->seen_count)->toBe(3)
        ->and($c->last_seen_at->greaterThan($erstesMal))->toBeTrue()
        // `created_at` bleibt „erstmals gesehen" — die zwei Zeitpunkte sind jetzt trennbar.
        ->and($c->created_at->toIso8601String())->toBe($a->created_at->toIso8601String());
});

it('V-009: eine neue Zeile nach dem Schließen beginnt wieder bei 1', function () {
    $a = $this->signals->erzeuge($this->rootTeam, SignalTyp::PreisAnomalie, SignalSeverity::Warnung, 'Erster', [
        'dedup_key' => 'x:1',
    ]);
    $this->signals->erzeuge($this->rootTeam, SignalTyp::PreisAnomalie, SignalSeverity::Warnung, 'Erster', [
        'dedup_key' => 'x:1',
    ]);
    $this->signals->abschliessen($this->rootTeam, $a->id);

    $neu = $this->signals->erzeuge($this->rootTeam, SignalTyp::PreisAnomalie, SignalSeverity::Warnung, 'Wieder da', [
        'dedup_key' => 'x:1',
    ]);

    // Die Kette über Status-Grenzen hinweg ist bewusst NICHT gebaut (V-009, zweiter
    // Halbsatz): der Dedup greift nur auf offene Zeilen, also ist dies eine neue Sichtung.
    expect($neu->id)->not->toBe($a->id)
        ->and($neu->seen_count)->toBe(1)
        ->and($a->refresh()->seen_count)->toBe(2);
});
