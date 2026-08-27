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
 * Spec 42 (Speisekarte-Parität zu Foodbook-F2, Dominique 2026-08-27): Die Planung (Brief → Gerüst →
 * Kaskade) zieht in die Leitstelle; die Speisekarte ist reine Ausgabe. Der „In der Leitstelle planen"-
 * Knopf baut KEIN Gerüst mehr im Modul, sondern springt in die Leitstelle im Owner-Kontext der Karte
 * (`sk_owner`) — 1:1 analog zum Foodbook. Kein Session-/Lauf-/Job-Nebeneffekt im Modul.
 */
it('vollKaskadeStarten (Spec 42): öffnet die Leitstelle im Owner-Kontext (sk_owner), plant NICHT mehr im Modul', function () {
    Queue::fake();
    $this->actingAs($this->makeUser($this->rootTeam));

    $karte = $this->karten->create($this->rootTeam, ['name' => 'Leitstelle-Karte']);
    $this->karten->addRubrik($this->rootTeam, $karte->id, ['title' => 'Hauptgänge']);

    Livewire::test(SpeisekarteIndex::class)
        ->call('waehle', $karte->id)
        ->call('vollKaskadeStarten')
        ->assertRedirect(route('foodalchemist.planung.index', ['sk_owner' => $karte->id]))
        ->assertSet('kaskadeMeldung', null);

    // Spec 42: das Modul legt weder Session noch Lauf an und dispatcht keinen Job — Rahmen/Inhalte
    // entstehen erst in der Leitstelle (speisekarteAusBrief).
    expect(FoodAlchemistPlanningSession::where('team_id', $this->rootTeam->id)
        ->where('created_via', 'speisekarte_vollkaskade')->count())->toBe(0);
    expect(FoodAlchemistCascadeRun::where('source_owner_type', 'speisekarte')
        ->where('source_owner_id', $karte->id)->count())->toBe(0);
    Queue::assertNotPushed(GenerateConceptJob::class);
});

it('vollKaskadeStarten ohne gewählte Karte tut nichts (kein Redirect)', function () {
    $this->actingAs($this->makeUser($this->rootTeam));

    Livewire::test(SpeisekarteIndex::class)
        ->call('vollKaskadeStarten')
        ->assertNoRedirect()
        ->assertSet('kaskadeMeldung', null);
});
