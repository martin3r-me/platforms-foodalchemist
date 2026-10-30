<?php

use Platform\FoodAlchemist\Enums\SignalSeverity;
use Platform\FoodAlchemist\Enums\SignalStatus;
use Platform\FoodAlchemist\Enums\SignalTyp;
use Platform\FoodAlchemist\Models\FoodAlchemistSignal;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplier;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierDocument;
use Platform\FoodAlchemist\Services\SignalDetektorService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Zertifikats-Signal (Link-Stufe 2d): ein Signal je Team und Lieferant, Kritisch bei abgelaufen, Warnung ab 60 Tagen davor, schließt sich selbst.
 * Alle Läufe über SignalDetektorService::laufen() (der Weg des Schedulers), nicht über die Einzelmethode — sonst bleibt eine fehlende Verdrahtung in
 * detektoren() unentdeckt.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->team = $this->rootTeam;
    $this->hanos = FoodAlchemistSupplier::create(['team_id' => $this->team->id, 'name' => 'Hanos']);
    $this->chefs = FoodAlchemistSupplier::create(['team_id' => $this->team->id, 'name' => 'Chefs Culinar']);
    $this->zert = fn (FoodAlchemistSupplier $s, string $typ, int $tage, string $kind = 'zertifikat', ?int $teamId = null) => FoodAlchemistSupplierDocument::create([
        'team_id' => $teamId ?? $this->team->id, 'supplier_id' => $s->id, 'kind' => $kind, 'title' => $typ, 'cert_type' => $typ, 'term_end' => now()->addDays($tage)->toDateString()]);
    $this->signale = fn (?int $teamId = null) => FoodAlchemistSignal::where('team_id', $teamId ?? $this->team->id)->where('type', SignalTyp::ZertifikatLaeuftAb->value)->orderBy('dedup_key')->get();
    $this->lauf = fn () => app(SignalDetektorService::class)->laufen($this->team);
});

it('laufen() erzeugt je Lieferant EIN Signal: Warnung vor Ablauf, Kritisch bei abgelaufen, mehrere Zertifikate gebündelt', function () {
    ($this->zert)($this->hanos, 'IFS', 30);
    ($this->zert)($this->hanos, 'Bio', 50);
    ($this->zert)($this->chefs, 'BRC', -5);
    ($this->zert)($this->chefs, 'MSC', 20);

    ($this->lauf)();

    $s = ($this->signale)();
    expect($s)->toHaveCount(2)->and($s->pluck('dedup_key')->all())->toBe(['zertifikat-lieferant-' . $this->hanos->id, 'zertifikat-lieferant-' . $this->chefs->id])
        ->and($s[0]->severity)->toBe(SignalSeverity::Warnung)->and(count($s[0]->payload['zertifikate']))->toBe(2)
        ->and($s[1]->severity)->toBe(SignalSeverity::Kritisch)->and($s[1]->ref_type)->toBe('supplier')->and($s[1]->ref_id)->toBe($this->chefs->id)
        ->and($s[1]->title)->toContain('Chefs Culinar')->toContain('abgelaufen');
});

it('kein Signal: weit in der Zukunft, andere Dokument-Art, inaktiver Lieferant, gelöschtes Dokument, ohne Ablaufdatum', function () {
    ($this->zert)($this->hanos, 'IFS', 200);
    ($this->zert)($this->hanos, 'Vertrag', 5, 'vertrag');
    ($this->zert)($this->chefs, 'BRC', 5)->delete();
    $inaktiv = FoodAlchemistSupplier::create(['team_id' => $this->team->id, 'name' => 'Alt', 'is_inactive' => true]);
    ($this->zert)($inaktiv, 'IFS', -10);
    FoodAlchemistSupplierDocument::create(['team_id' => $this->team->id, 'supplier_id' => $this->hanos->id, 'kind' => 'zertifikat', 'title' => 'ohne Datum']);

    ($this->lauf)();

    expect(($this->signale)())->toHaveCount(0);
});

it('idempotent und selbstschließend: zweiter Lauf = kein zweites Signal, neues Ablaufdatum schließt es mit Grund', function () {
    $d = ($this->zert)($this->hanos, 'IFS', 10);
    ($this->lauf)();
    ($this->lauf)();
    expect(($this->signale)())->toHaveCount(1)->and(($this->signale)()[0]->seen_count)->toBe(2);

    $d->update(['term_end' => now()->addDays(400)->toDateString()]);
    ($this->lauf)();

    $s = ($this->signale)()[0];
    expect($s->status)->toBe(SignalStatus::Erledigt)->and($s->payload['auto_geschlossen'])->toContain('automatisch geschlossen');

    // kommt es wieder, entsteht ein neues offenes Signal
    $d->update(['term_end' => now()->addDays(5)->toDateString()]);
    ($this->lauf)();
    expect(($this->signale)()->where('status', SignalStatus::Offen)->count())->toBe(1);
});

it('je Team getrennt: das Zertifikat eines anderen Teams erzeugt hier nichts, dort ein eigenes Signal', function () {
    $fremdLieferant = FoodAlchemistSupplier::create(['team_id' => $this->childA->id, 'name' => 'Eigener']);
    ($this->zert)($fremdLieferant, 'IFS', 3, 'zertifikat', $this->childA->id);

    ($this->lauf)();
    expect(($this->signale)())->toHaveCount(0);

    app(SignalDetektorService::class)->laufen($this->childA);
    expect(($this->signale)($this->childA->id))->toHaveCount(1);
});
