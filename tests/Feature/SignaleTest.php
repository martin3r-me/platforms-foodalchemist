<?php

use Livewire\Livewire;
use Platform\FoodAlchemist\Enums\SignalSeverity;
use Platform\FoodAlchemist\Enums\SignalStatus;
use Platform\FoodAlchemist\Enums\SignalTyp;
use Platform\FoodAlchemist\Livewire\ReviewQueue;
use Platform\FoodAlchemist\Models\FoodAlchemistSignal;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplier;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem;
use Platform\FoodAlchemist\Services\SignalDetektorService;
use Platform\FoodAlchemist\Services\SignalService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * #378 — Signale (Aufmerksamkeits-Inbox): SignalService (Dedup + Lifecycle),
 * SignalDetektorService (idempotent, team-gescoped) und die ReviewQueue als
 * Full-Page-Inbox inkl. #393-Rest (match_proposals-Zähler = aktuelles Team).
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->signals = app(SignalService::class);
    $this->detektor = app(SignalDetektorService::class);
});

it('erzeuge ist idempotent über dedup_key: offenes Signal wird aktualisiert statt dupliziert', function () {
    $a = $this->signals->erzeuge($this->rootTeam, SignalTyp::DatenqualitaetGpLa, SignalSeverity::Warnung, '5 GPs ohne Lead-LA', [
        'dedup_key' => 'dq-test', 'payload' => ['anzahl' => 5],
    ]);
    $b = $this->signals->erzeuge($this->rootTeam, SignalTyp::DatenqualitaetGpLa, SignalSeverity::Kritisch, '120 GPs ohne Lead-LA', [
        'dedup_key' => 'dq-test', 'payload' => ['anzahl' => 120],
    ]);

    expect($b->id)->toBe($a->id)                                     // aktualisiert, kein Duplikat
        ->and($b->severity)->toBe(SignalSeverity::Kritisch)
        ->and($b->titel)->toBe('120 GPs ohne Lead-LA')
        ->and(FoodAlchemistSignal::count())->toBe(1);

    // erledigtes Signal blockt den dedup NICHT — neues offenes Signal entsteht (Historie bleibt)
    $this->signals->abschliessen($this->rootTeam, $a->id);
    $c = $this->signals->erzeuge($this->rootTeam, SignalTyp::DatenqualitaetGpLa, SignalSeverity::Info, 'wieder da', ['dedup_key' => 'dq-test']);
    expect($c->id)->not->toBe($a->id)->and(FoodAlchemistSignal::count())->toBe(2);
});

it('Lifecycle: offen → erledigt/ignoriert → wieder offen (Zeitstempel folgen)', function () {
    $s = $this->signals->erzeuge($this->rootTeam, SignalTyp::VeraltetePreise, SignalSeverity::Info, 'Preise alt');

    $this->signals->abschliessen($this->rootTeam, $s->id);
    expect($s->refresh()->status)->toBe(SignalStatus::Erledigt)
        ->and($s->erledigt_at)->not->toBeNull();

    $this->signals->wiederOeffnen($this->rootTeam, $s->id);
    expect($s->refresh()->status)->toBe(SignalStatus::Offen)
        ->and($s->erledigt_at)->toBeNull();

    $this->signals->ignorieren($this->rootTeam, $s->id);
    expect($s->refresh()->status)->toBe(SignalStatus::Ignoriert)
        ->and($s->ignoriert_at)->not->toBeNull();
});

it('paginate filtert nach Status + Typ; offeneCount/offeneNachTyp zählen nur offene', function () {
    $this->signals->erzeuge($this->rootTeam, SignalTyp::VeraltetePreise, SignalSeverity::Info, 'A');
    $this->signals->erzeuge($this->rootTeam, SignalTyp::DatenqualitaetGpLa, SignalSeverity::Warnung, 'B');
    $erledigt = $this->signals->erzeuge($this->rootTeam, SignalTyp::VeraltetePreise, SignalSeverity::Info, 'C', ['dedup_key' => 'c']);
    $this->signals->abschliessen($this->rootTeam, $erledigt->id);

    expect($this->signals->paginate(['status' => 'offen'], $this->rootTeam)->total())->toBe(2)
        ->and($this->signals->paginate(['status' => 'offen', 'typ' => 'veraltete_preise'], $this->rootTeam)->total())->toBe(1)
        ->and($this->signals->paginate(['status' => 'erledigt'], $this->rootTeam)->total())->toBe(1)
        ->and($this->signals->offeneCount($this->rootTeam))->toBe(2)
        ->and($this->signals->offeneNachTyp($this->rootTeam))->toBe(['datenqualitaet_gp_la' => 1, 'veraltete_preise' => 1]);
});

it('Detektor datenqualitaetGpLa: Summen-Signal mit Anzahl, idempotent, Team-Kette statt ALLER Teams', function () {
    // Root: 2 GPs ohne Lead-LA (requires_la default true) · Kind A: 1 eigener
    $this->makeGp($this->rootTeam, 'Zander ohne LA');
    $this->makeGp($this->rootTeam, 'Lachs ohne LA');
    $this->makeGp($this->childA, 'Nur-A-GP ohne LA');

    expect($this->detektor->datenqualitaetGpLa($this->rootTeam))->toBe(1);
    $signal = FoodAlchemistSignal::where('team_id', $this->rootTeam->id)->firstOrFail();
    expect($signal->payload['anzahl'])->toBe(2);                     // Kind-A-GP zählt für Root NICHT

    // Kind A sieht Kette aufwärts: eigene + geerbte Root-GPs
    $this->detektor->datenqualitaetGpLa($this->childA);
    $kindSignal = FoodAlchemistSignal::where('team_id', $this->childA->id)->firstOrFail();
    expect($kindSignal->payload['anzahl'])->toBe(3);

    // Idempotenz: zweiter Lauf aktualisiert das Summen-Signal, statt zu duplizieren
    $this->makeGp($this->rootTeam, 'Dorade ohne LA');
    $this->detektor->datenqualitaetGpLa($this->rootTeam);
    expect(FoodAlchemistSignal::where('team_id', $this->rootTeam->id)->count())->toBe(1)
        ->and($signal->refresh()->payload['anzahl'])->toBe(3);
});

it('ReviewQueue: Signale in der Inbox, Quick-Actions erledigt/ignoriert/wieder öffnen', function () {
    $this->actingAs($this->makeUser($this->rootTeam, 'Root User'));
    $s = $this->signals->erzeuge($this->rootTeam, SignalTyp::VeraltetePreise, SignalSeverity::Info, 'Preise älter als 180 Tage');

    $lw = Livewire::test(ReviewQueue::class)
        ->assertViewHas('signalOffen', 1)
        ->assertSee('Preise älter als 180 Tage');

    $lw->call('signalErledigt', $s->id)->assertViewHas('signalOffen', 0);
    expect($s->refresh()->status)->toBe(SignalStatus::Erledigt);

    $lw->call('signalWiederOeffnen', $s->id)->assertViewHas('signalOffen', 1);
    $lw->call('signalIgnorieren', $s->id)->assertViewHas('signalOffen', 0);
    expect($s->refresh()->status)->toBe(SignalStatus::Ignoriert);
});

it('#393-Rest: ReviewQueue-Match-Zähler ist team-scoped (aktuelles Team), nicht teamübergreifend', function () {
    $supplier = FoodAlchemistSupplier::create(['team_id' => $this->rootTeam->id, 'name' => 'Necta']);
    $la = FoodAlchemistSupplierItem::create([
        'team_id' => $this->rootTeam->id, 'supplier_id' => $supplier->id,
        'designation' => 'Zanderfilet 2 kg', 'qty' => 2.0, 'unit_code' => 'kg',
    ]);
    $gp = $this->makeGp($this->rootTeam, 'Zanderfilet');
    $mk = fn (int $teamId) => \Platform\FoodAlchemist\Models\FoodAlchemistMatchProposal::create([
        'team_id' => $teamId, 'supplier_item_id' => $la->id, 'gp_id' => $gp->id,
        'score' => 0.9, 'band' => 'exact', 'methode' => 'exact_ean', 'status' => 'offen',
    ]);
    $mk($this->rootTeam->id);   // eigenes Team → zählt
    $mk($this->childA->id);     // fremdes Team → darf NICHT mitzählen
    $mk($this->childA->id);

    $this->actingAs($this->makeUser($this->rootTeam, 'Root User'));
    Livewire::test(ReviewQueue::class)->assertViewHas('matchZahl', 1);
});
