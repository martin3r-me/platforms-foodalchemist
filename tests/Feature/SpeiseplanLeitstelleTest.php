<?php

use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Platform\FoodAlchemist\Jobs\MaterializeSpeiseplanCellJob;
use Platform\FoodAlchemist\Livewire\Speiseplan\Editor as SpeiseplanEditor;
use Platform\FoodAlchemist\Models\FoodAlchemistCascadeRun;
use Platform\FoodAlchemist\Models\FoodAlchemistPlanningSession;
use Platform\FoodAlchemist\Services\SpeiseplanService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;
use Platform\FoodAlchemist\Livewire\Planung\Index as PlanungIndex;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Etappe 5 P5 — Speiseplan als Leitstelle-Trigger: aus den Menü-Linien × dem Zyklus (cycle_weeks ×
 * Mo–Fr × Mittag) eine Voll-Kaskade starten — je LEERER Zelle EIN Gericht-Step + {@see MaterializeSpeiseplanCellJob}
 * — und in den Planung-Editor zur Sammel-Review leiten. Anders als Foodbook/Speisekarte (Slot → Concept)
 * hält eine Zelle EIN Gericht (kind='gericht', nicht 'concept'). Der Service-Pfad
 * ({@see PlanningCascadeService::starteSpeiseplanVollkaskade}) ist in PlanningCascadeTest gepinnt; hier
 * fehlte die Livewire-Trigger-Deckung (Session-Anlage, Redirect, Cap, Fehlerpfad) — 1:1 analog zu
 * FoodbookLeitstelleTest / SpeisekarteLeitstelleTest.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->plaene = app(SpeiseplanService::class);
    $this->actingAs($this->makeUser($this->rootTeam));
});

it('vollKaskadeStarten (Leitstelle P5): legt eine Review-Session an, startet die Voll-Kaskade (Gericht-Step je leerer Zelle) und leitet in den Planung-Editor', function () {
    Queue::fake();

    // 1 Zyklus-Woche × 3 Starter-Linien × 5 Werktage = 15 leere Zellen (< Cap 30).
    $plan = $this->plaene->create($this->rootTeam, ['name' => 'Leitstelle-Speiseplan', 'cycle_weeks' => 1]);
    $zellen = $plan->lines()->count() * 5;
    expect($zellen)->toBe(15);

    Livewire::test(SpeiseplanEditor::class)
        ->set('planId', $plan->id)
        ->call('vollKaskadeStarten')
        ->assertRedirect()
        ->assertSet('kaskadeMeldung', null);

    // Ausgabe-Modul = Quelle: die Review-Wurzel wird als Planungs-Session mit speiseplan-Herkunft angelegt.
    $session = FoodAlchemistPlanningSession::where('team_id', $this->rootTeam->id)
        ->where('created_via', 'speiseplan_vollkaskade')->latest('id')->first();
    expect($session)->not->toBeNull();

    // Genau ein Voll-Kaskaden-Lauf am Speiseplan + je leerer Zelle ein Gericht-Step; kein Deckel (15 < 30).
    $run = FoodAlchemistCascadeRun::where('source_owner_type', 'speiseplan')
        ->where('source_owner_id', $plan->id)->latest('id')->first();
    expect($run)->not->toBeNull()
        ->and($run->scope)->toBe('vollkaskade')
        ->and($run->status)->toBe('running')
        ->and($run->planning_session_id)->toBe($session->id)
        ->and($run->steps()->where('kind', 'gericht')->count())->toBe($zellen)
        ->and($run->deckel_hinweise)->toBeNull();   // 15 < 30: ein Nichts wird nicht als Hinweis verkauft

    Queue::assertPushed(MaterializeSpeiseplanCellJob::class, $zellen);
});

/*
 * KORREKTUR 2026-09-03. Dieser Test hiess »Rest steht ehrlich im Run« und prüfte genau das:
 * die Zahl in `params`. Zwei Zeilen darüber stand `assertSet('kaskadeMeldung', null)` — er
 * pinnte also die STILLE als erwartetes Verhalten und nannte sie im Kommentar »kein stiller
 * Deckel«. Beides war grün, und der Deckel war trotzdem unsichtbar: `params` wird in der
 * Status-DTO gegen ALLOWED_GENERATION_PARAMS gefiltert (der Beutel zeigt die LEITPLANKEN),
 * `resources/` hatte null Treffer auf den Schlüssel, und der Schreibweg überschrieb `params`
 * komplett — womit er zusätzlich die Leitplanken-Anzeige löschte.
 *
 * Bei einem Standard-Zyklus (4 Wochen × 3 Linien × 5 Werktage = 60 Zellen) fiel damit die
 * HÄLFTE des Auftrags weg, ohne dass es jemand erfuhr.
 *
 * `assertSet('kaskadeMeldung', null)` bleibt richtig und steht weiter drin: die Methode gibt
 * einen Redirect zurück, die Editor-Komponente stirbt damit, und `kaskadeMeldung` ist ihr
 * FEHLER-Kanal. Ein Deckel ist kein Fehler. Der Hinweis gehört dorthin, wo der Mensch landet —
 * das prüft der Test darunter.
 */
it('vollKaskadeStarten deckelt den Zell-Fan-out (SPEISEPLAN_MAX_ZELLEN=30) — und der Rest ist SICHTBAR', function () {
    Queue::fake();

    // 4 Zyklus-Wochen × 3 Linien × 5 Werktage = 60 leere Zellen → gedeckelt auf 30, 30 offen.
    $plan = $this->plaene->create($this->rootTeam, ['name' => 'Großer Zyklus', 'cycle_weeks' => 4]);
    expect($plan->lines()->count() * 4 * 5)->toBe(60);

    Livewire::test(SpeiseplanEditor::class)
        ->set('planId', $plan->id)
        ->call('vollKaskadeStarten')
        ->assertRedirect()
        ->assertSet('kaskadeMeldung', null);

    $run = FoodAlchemistCascadeRun::where('source_owner_type', 'speiseplan')
        ->where('source_owner_id', $plan->id)->latest('id')->first();
    expect($run)->not->toBeNull()
        ->and($run->steps()->where('kind', 'gericht')->count())->toBe(30)   // harter Cap
        // Der Vermerk liegt in `deckel_hinweise` (eigene Spalte, NICHT im gefilterten
        // Leitplanken-Beutel) und trägt den fertigen Satz mit — er muss auch dann verständlich
        // sein, wenn ihn jemand Wochen später im Lauf-Detail liest.
        ->and($run->deckel_hinweise)->toHaveCount(1)
        ->and($run->deckel_hinweise[0]['deckel'])->toBe('speiseplan_zellen')
        ->and($run->deckel_hinweise[0]['grenze'])->toBe(30)
        ->and($run->deckel_hinweise[0]['verlangt'])->toBe(60)
        ->and($run->deckel_hinweise[0]['offen'])->toBe(30)
        ->and($run->deckel_hinweise[0]['text'])->toContain('30 von 60 Zellen')
        // … und die Leitplanken sind NICHT mehr kollateral gelöscht (der alte params-Write
        // machte `params` non-empty und damit den Session-Fallback in der DTO wirkungslos).
        ->and($run->params)->toBeNull();

    Queue::assertPushed(MaterializeSpeiseplanCellJob::class, 30);
});

/*
 * DER TEST, AUF DEN ES ANKOMMT. Der Deckel-Vermerk in der DB war schon vorher da — er hat
 * niemanden erreicht. Diese Zusicherung prüft den WEG bis zur Fläche, nicht den Schreibvorgang.
 *
 * Der Weg ist verschlungen und genau deshalb prüfbedürftig: `vollKaskadeStarten` gibt einen
 * Redirect auf die Planung-Leitstelle zurück (`?session=…&open=1`), die Editor-Komponente stirbt
 * damit, und erst der `open=1`-Zweig in `Planung\Index::mount()` lädt den Lauf und damit den
 * Hinweis. Fehlt dort das Laden, bleibt die Landung stumm, obwohl der Vermerk in der DB steht —
 * genau der Zustand von vorher, nur mit einer anderen Spalte.
 *
 * EHRLICHE GRENZE: `Livewire::test` ist layout-blind. Dieser Test belegt, dass der Hinweis in
 * der Property steht und im HTML der Komponente auftaucht. Er belegt NICHT, dass er in der
 * richtigen Modal-Zone landet — der Span muss innerhalb von `x-slot:actions` stehen, dahinter
 * beginnt die Tab-Leiste. Das ist eine Browser-Abnahme, kein Testfall.
 */
it('der Deckel-Hinweis erreicht die Leitstelle, wo der Mensch nach dem Go landet', function () {
    Queue::fake();

    $plan = $this->plaene->create($this->rootTeam, ['name' => 'Großer Zyklus', 'cycle_weeks' => 4]);

    Livewire::test(SpeiseplanEditor::class)
        ->set('planId', $plan->id)
        ->call('vollKaskadeStarten')
        ->assertRedirect();

    $session = FoodAlchemistPlanningSession::where('team_id', $this->rootTeam->id)
        ->where('created_via', 'speiseplan_vollkaskade')->latest('id')->first();

    // Genau die Landung nachstellen, auf die der Editor umleitet.
    $leitstelle = Livewire::withQueryParams(['session' => $session->id, 'open' => 1])
        ->test(PlanungIndex::class);

    $leitstelle->assertSet('deckelHinweis', fn ($t) => is_string($t) && str_contains($t, '30 von 60 Zellen'))
        ->assertSeeHtml('data-deckel-hinweis')
        ->assertSee('30 bleiben leer');
});

it('ohne Deckel bleibt die Leitstelle still — ein Nichts ist keine Warnung', function () {
    Queue::fake();

    // 1 Zyklus-Woche × 3 Linien × 5 Werktage = 15 Zellen, also unter der Grenze von 30.
    // `cycle_weeks` MUSS gesetzt werden — ohne den Schlüssel greift der Service-Default (4),
    // und dann sind es 60 Zellen und der Deckel feuert. Genau darauf bin ich hier reingelaufen.
    $plan = $this->plaene->create($this->rootTeam, ['name' => 'Kleiner Zyklus', 'cycle_weeks' => 1]);
    expect($plan->lines()->count() * 5)->toBe(15);

    Livewire::test(SpeiseplanEditor::class)
        ->set('planId', $plan->id)
        ->call('vollKaskadeStarten')
        ->assertRedirect();

    $session = FoodAlchemistPlanningSession::where('team_id', $this->rootTeam->id)
        ->where('created_via', 'speiseplan_vollkaskade')->latest('id')->first();

    Livewire::withQueryParams(['session' => $session->id, 'open' => 1])
        ->test(PlanungIndex::class)
        ->assertSet('deckelHinweis', null)
        ->assertDontSeeHtml('data-deckel-hinweis');
});

it('vollKaskadeStarten ohne Menü-Linien meldet ehrlich (kaskadeMeldung) — kein Lauf, kein Redirect, kein Job', function () {
    Queue::fake();

    $plan = $this->plaene->create($this->rootTeam, ['name' => 'Plan ohne Linien']);
    foreach ($plan->lines()->pluck('id') as $linieId) {
        $this->plaene->removeLinie($this->rootTeam, (int) $linieId);
    }
    expect($plan->lines()->count())->toBe(0);

    Livewire::test(SpeiseplanEditor::class)
        ->set('planId', $plan->id)
        ->call('vollKaskadeStarten')
        ->assertNoRedirect()
        ->assertSet('kaskadeMeldung', fn ($v) => is_string($v) && $v !== '');

    expect(FoodAlchemistCascadeRun::where('source_owner_type', 'speiseplan')->where('source_owner_id', $plan->id)->count())->toBe(0);
    Queue::assertNotPushed(MaterializeSpeiseplanCellJob::class);
});
