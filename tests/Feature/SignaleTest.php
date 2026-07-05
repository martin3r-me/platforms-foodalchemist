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
        ->and($b->title)->toBe('120 GPs ohne Lead-LA')
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
        ->and($this->signals->paginate(['status' => 'offen', 'type' => 'veraltete_preise'], $this->rootTeam)->total())->toBe(1)
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

it('R2.1 Detektor preisSprungMargeImpact: Lead-LA +30% → Signal mit transitiver Betroffenheit (Gericht via Basisrezept)', function () {
    // Einheit g
    $g = \Platform\FoodAlchemist\Models\FoodAlchemistVocabEinheit::create([
        'team_id' => $this->rootTeam->id, 'slug' => 'g', 'display_de' => 'Gramm', 'dimension' => 'mass', 'default_in_g' => 1,
    ]);
    // Lieferant + LA (kg) + zwei Preis-Generationen: alt 10 (geschlossen, frisch), neu 13 (aktiv) = +30%
    $sup = \Platform\FoodAlchemist\Models\FoodAlchemistSupplier::create(['team_id' => $this->rootTeam->id, 'name' => 'Necta']);
    $la = \Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem::create([
        'team_id' => $this->rootTeam->id, 'supplier_id' => $sup->id, 'designation' => 'Butter 1 kg', 'qty' => 1.0, 'unit_code' => 'kg',
    ]);
    \Platform\FoodAlchemist\Models\FoodAlchemistPrice::create(['team_id' => $this->rootTeam->id, 'supplier_item_id' => $la->id, 'price' => 10.0, 'status' => '0', 'valid_to' => now()]);
    \Platform\FoodAlchemist\Models\FoodAlchemistPrice::create(['team_id' => $this->rootTeam->id, 'supplier_item_id' => $la->id, 'price' => 13.0, 'status' => '0', 'valid_to' => null]);
    // GP mit Lead-LA
    $gp = $this->makeGp($this->rootTeam, 'Butter');
    $gp->update(['lead_la_supplier_item_id' => $la->id]);
    // Basisrezept nutzt GP direkt; Gericht referenziert das Basisrezept (transitive Kette)
    $basis = \Platform\FoodAlchemist\Models\FoodAlchemistRecipe::create([
        'team_id' => $this->rootTeam->id, 'recipe_key' => 'basis-butter', 'name' => 'Buttersauce', 'status' => 'approved', 'is_sales_recipe' => false,
    ]);
    \Platform\FoodAlchemist\Models\FoodAlchemistRecipeIngredient::create([
        'team_id' => $this->rootTeam->id, 'recipe_id' => $basis->id, 'gp_id' => $gp->id, 'raw_text' => 'Butter', 'quantity' => '200', 'unit_vocab_id' => $g->id, 'position' => 1,
    ]);
    $gericht = \Platform\FoodAlchemist\Models\FoodAlchemistRecipe::create([
        'team_id' => $this->rootTeam->id, 'recipe_key' => 'gericht-x', 'name' => 'Gericht mit Buttersauce', 'status' => 'approved', 'is_sales_recipe' => true, 'sales_net' => 20.0,
    ]);
    \Platform\FoodAlchemist\Models\FoodAlchemistRecipeIngredient::create([
        'team_id' => $this->rootTeam->id, 'recipe_id' => $gericht->id, 'referenced_recipe_id' => $basis->id, 'raw_text' => 'Buttersauce', 'quantity' => '100', 'unit_vocab_id' => $g->id, 'position' => 1,
    ]);

    $n = $this->detektor->preisSprungMargeImpact($this->rootTeam, 10.0);

    expect($n)->toBe(1);
    $sig = FoodAlchemistSignal::where('type', 'preis_sprung_marge_impact')->where('ref_id', $gp->id)->firstOrFail();
    expect($sig->payload['n_gerichte'])->toBe(1)                        // transitive Betroffenheit: das Gericht via Basisrezept
        ->and($sig->payload['n_recipes'])->toBe(2)                      // Basisrezept + Gericht
        ->and(round($sig->payload['delta_pct']))->toBe(30.0)
        ->and($sig->payload['marge_delta_eur'])->toBeLessThanOrEqual(0.0); // teurer → Marge sinkt (nie besser)

    // Idempotenz: zweiter Lauf aktualisiert statt dupliziert (Dedup je neuem Preis)
    $this->detektor->preisSprungMargeImpact($this->rootTeam, 10.0);
    expect(FoodAlchemistSignal::where('type', 'preis_sprung_marge_impact')->where('ref_id', $gp->id)->count())->toBe(1);
});

it('Detektor naehrwertPlausi: flaggt Zucker>KH bzw. gesFett>Fett, Toleranz schützt Rundungs-Rauschen', function () {
    $mk = fn (string $key, array $nutri) => \Platform\FoodAlchemist\Models\FoodAlchemistRecipe::create([
        'team_id' => $this->rootTeam->id, 'recipe_key' => $key, 'name' => 'R-' . $key, 'status' => 'approved',
        'nutri_kcal_per_100g' => 100,
    ] + $nutri);
    $mk('zucker-hoch', ['nutri_carbs_g_per_100g' => 3.4, 'nutri_sugar_g_per_100g' => 3.9, 'nutri_fat_g_per_100g' => 4.0, 'nutri_saturated_fat_g_per_100g' => 1.0]);
    $mk('gesfett-hoch', ['nutri_carbs_g_per_100g' => 10, 'nutri_sugar_g_per_100g' => 2, 'nutri_fat_g_per_100g' => 2.0, 'nutri_saturated_fat_g_per_100g' => 2.5]);
    $mk('plausibel', ['nutri_carbs_g_per_100g' => 10, 'nutri_sugar_g_per_100g' => 4, 'nutri_fat_g_per_100g' => 5.0, 'nutri_saturated_fat_g_per_100g' => 2.0]);
    $mk('rundung', ['nutri_carbs_g_per_100g' => 3.40, 'nutri_sugar_g_per_100g' => 3.45, 'nutri_fat_g_per_100g' => 5.0, 'nutri_saturated_fat_g_per_100g' => 2.0]); // < Toleranz 0,1

    expect($this->detektor->naehrwertPlausi($this->rootTeam))->toBe(1);

    $signal = FoodAlchemistSignal::where('type', 'naehrwert_plausi')->firstOrFail();
    expect($signal->payload['anzahl'])->toBe(2)
        ->and(collect($signal->payload['beispiele'])->pluck('name')->sort()->values()->all())
        ->toBe(['R-gesfett-hoch', 'R-zucker-hoch']);

    // Idempotenz: zweiter Lauf aktualisiert statt dupliziert
    $this->detektor->naehrwertPlausi($this->rootTeam);
    expect(FoodAlchemistSignal::where('type', 'naehrwert_plausi')->count())->toBe(1);
});
