<?php

use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Platform\FoodAlchemist\Jobs\GenerateConceptJob;
use Platform\FoodAlchemist\Livewire\Speisekarte\Index as SpeisekarteIndex;
use Platform\FoodAlchemist\Models\FoodAlchemistCascadeRun;
use Platform\FoodAlchemist\Models\FoodAlchemistPlanningSession;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Services\SpeisekarteLeitstelleService;
use Platform\FoodAlchemist\Services\SpeisekarteService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/** Speisekarte Stufe E — abgeleitete Leitstelle-Checkliste (Rubriken/Positionen/Preise/Allergene/Branding). */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->karten = app(SpeisekarteService::class);
    $this->ls = app(SpeisekarteLeitstelleService::class);
});

function skStatus(array $stand, string $key): string
{
    return collect($stand['punkte'])->firstWhere('key', $key)['status'];
}

it('Stufe E: leere Karte — alles offen, nicht bereit', function () {
    $karte = $this->karten->create($this->rootTeam, ['name' => 'K']);
    $stand = $this->ls->checkliste($this->rootTeam, $karte->id);
    expect(skStatus($stand, 'rubriken'))->toBe('offen')
        ->and(skStatus($stand, 'positionen'))->toBe('offen')
        ->and($stand['bereit'])->toBeFalse();
});

it('Stufe E: vollständige Karte (Preis + Allergene) → harte Punkte erledigt, bereit', function () {
    $g = FoodAlchemistRecipe::create([
        'team_id' => $this->rootTeam->id, 'recipe_key' => 'le1', 'name' => 'Filet', 'status' => 'approved',
        'is_sales_recipe' => true, 'sales_net' => 24.00, 'ek_total_eur' => 8.00,
    ]);
    $g->forceFill(['allergens_confidence' => 'high'])->save();

    $karte = $this->karten->create($this->rootTeam, ['name' => 'K']);
    $rubrik = $this->karten->addRubrik($this->rootTeam, $karte->id, ['title' => 'Hauptgänge']);
    $this->karten->addPosition($this->rootTeam, $rubrik->id, ['type' => 'gericht_ref', 'sales_recipe_id' => $g->id]);

    $stand = $this->ls->checkliste($this->rootTeam, $karte->id);
    expect(skStatus($stand, 'rubriken'))->toBe('erledigt')
        ->and(skStatus($stand, 'positionen'))->toBe('erledigt')
        ->and(skStatus($stand, 'preise'))->toBe('erledigt')
        ->and(skStatus($stand, 'allergene'))->toBe('erledigt')
        ->and($stand['bereit'])->toBeTrue();
});

it('Stufe E: unbekannte Allergen-Konfidenz → allergene offen, nicht bereit', function () {
    $g = FoodAlchemistRecipe::create([
        'team_id' => $this->rootTeam->id, 'recipe_key' => 'le2', 'name' => 'Suppe', 'status' => 'approved',
        'is_sales_recipe' => true, 'sales_net' => 6.00,
    ]);
    $g->forceFill(['allergens_confidence' => 'low'])->save();   // schwache Konfidenz → „unbekannt"
    $karte = $this->karten->create($this->rootTeam, ['name' => 'K']);
    $rubrik = $this->karten->addRubrik($this->rootTeam, $karte->id);
    $this->karten->addPosition($this->rootTeam, $rubrik->id, ['type' => 'gericht_ref', 'sales_recipe_id' => $g->id]);

    $stand = $this->ls->checkliste($this->rootTeam, $karte->id);
    expect(skStatus($stand, 'allergene'))->toBe('offen')
        ->and($stand['bereit'])->toBeFalse();
});

/**
 * Etappe 5 P4 — Speisekarte als Leitstelle-Trigger: aus den Rubriken der Karte eine Voll-Kaskade
 * starten (Ausgabe-Modul = Quelle → je Rubrik 1 Concept + Gericht-Fan-out) und in den Planung-Editor
 * zur Sammel-Review leiten. Der Service-Pfad (Frame → Concept-Step je Slot + GenerateConceptJob-Attach
 * an die Rubrik) ist in PlanningCascadeTest gepinnt; hier fehlte die Livewire-Trigger-Deckung
 * (Session-Anlage, Redirect, Fehlerpfad) — 1:1 analog zu FoodbookLeitstelleTest.
 */
it('vollKaskadeStarten (Leitstelle P4): legt eine Review-Session an, startet die Voll-Kaskade (Concept-Step je Rubrik) und leitet in den Planung-Editor', function () {
    Queue::fake();
    $this->actingAs($this->makeUser($this->rootTeam));

    $karte = $this->karten->create($this->rootTeam, ['name' => 'Leitstelle-Karte']);
    $this->karten->addRubrik($this->rootTeam, $karte->id, ['title' => 'Hauptgänge']);

    Livewire::test(SpeisekarteIndex::class)
        ->call('waehle', $karte->id)
        ->call('vollKaskadeStarten')
        ->assertRedirect()
        ->assertSet('kaskadeMeldung', null);

    // Ausgabe-Modul = Quelle: die Review-Wurzel wird als Planungs-Session mit speisekarte-Herkunft angelegt.
    $session = FoodAlchemistPlanningSession::where('team_id', $this->rootTeam->id)
        ->where('created_via', 'speisekarte_vollkaskade')->latest('id')->first();
    expect($session)->not->toBeNull();

    // Genau ein Voll-Kaskaden-Lauf an der Karte + ein Concept-Step (die eine Rubrik) + Job an die Rubrik.
    $run = FoodAlchemistCascadeRun::where('source_owner_type', 'speisekarte')
        ->where('source_owner_id', $karte->id)->latest('id')->first();
    expect($run)->not->toBeNull()
        ->and($run->scope)->toBe('vollkaskade')
        ->and($run->status)->toBe('running')
        ->and($run->planning_session_id)->toBe($session->id)
        ->and($run->steps()->where('kind', 'concept')->count())->toBe(1);
    Queue::assertPushed(GenerateConceptJob::class, fn ($job) => $job->attachOwnerType === 'speisekarte' && (int) $job->attachContainerId > 0);
});

it('vollKaskadeStarten ohne Rubriken meldet ehrlich (kaskadeMeldung) — kein Lauf, kein Redirect', function () {
    Queue::fake();
    $this->actingAs($this->makeUser($this->rootTeam));

    $leer = $this->karten->create($this->rootTeam, ['name' => 'Karte ohne Rubriken']);

    Livewire::test(SpeisekarteIndex::class)
        ->call('waehle', $leer->id)
        ->call('vollKaskadeStarten')
        ->assertNoRedirect()
        ->assertSet('kaskadeMeldung', fn ($v) => is_string($v) && $v !== '');

    expect(FoodAlchemistCascadeRun::where('source_owner_type', 'speisekarte')->where('source_owner_id', $leer->id)->count())->toBe(0);
    Queue::assertNotPushed(GenerateConceptJob::class);
});
