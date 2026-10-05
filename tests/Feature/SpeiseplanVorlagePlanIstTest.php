<?php

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Tools\ToolRegistry;
use Platform\FoodAlchemist\Jobs\MaterializeSpeiseplanCellJob;
use Platform\FoodAlchemist\Livewire\Speiseplan\Editor as SpeiseplanEditor;
use Platform\FoodAlchemist\Models\FoodAlchemistOutlet;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Models\FoodAlchemistSalesFact;
use Platform\FoodAlchemist\Models\FoodAlchemistSpeiseplan;
use Platform\FoodAlchemist\Services\SpeiseplanService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 57 · Welle D — Vorlage für Betriebe (Paket 7), Plan/Ist (Paket 8), planweite
 * Leitplanken der Voll-Kaskade (Paket 10.2).
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->svc = app(SpeiseplanService::class);
    $this->user = $this->makeUser($this->rootTeam);
    $this->actingAs($this->user);
    $this->heute = now()->startOfWeek()->addWeek();   // Montag der nächsten Woche (Abgleich zählt ab heute)
    $this->gericht = fn ($team, string $name, array $attrs = []) => FoodAlchemistRecipe::create(array_merge([
        'team_id' => $team->id, 'recipe_key' => 'sp57d-' . uniqid(), 'name' => $name, 'status' => 'approved',
        'is_sales_recipe' => true, 'sales_net' => 6.0, 'ek_total_eur' => 2.0,
    ], $attrs));
    $this->nord = FoodAlchemistOutlet::create(['team_id' => $this->rootTeam->id, 'name' => 'Werk Nord']);
    $this->vorlage = $this->svc->create($this->rootTeam, ['name' => 'Zentrale Vorlage', 'start_date' => $this->heute->format('Y-m-d')]);
    $this->linie = $this->vorlage->lines()->first();
    $this->a = ($this->gericht)($this->rootTeam, 'Gulasch');
    $this->svc->addEintrag($this->rootTeam, $this->vorlage->id, ['entry_date' => $this->heute->format('Y-m-d'), 'line_id' => $this->linie->id, 'sales_recipe_id' => $this->a->id]);
});

it('Paket 7: Kopie je Betrieb nur aus einer Vorlage, nur für eigene Betriebe, je Betrieb einmal', function () {
    expect(fn () => $this->svc->betriebsKopieAnlegen($this->rootTeam, $this->vorlage->id, $this->nord->id))->toThrow(RuntimeException::class, 'nicht als Vorlage');

    $this->svc->setzeVorlage($this->rootTeam, $this->vorlage->id, true);
    $kopie = $this->svc->betriebsKopieAnlegen($this->rootTeam, $this->vorlage->id, $this->nord->id);
    expect($kopie->source_plan_id)->toBe($this->vorlage->id)->and($kopie->outlet_id)->toBe($this->nord->id)
        ->and($kopie->name)->toBe('Zentrale Vorlage · Werk Nord')->and($kopie->is_template)->toBeFalse()
        ->and($kopie->lines()->whereNotNull('source_line_id')->count())->toBe(3)
        ->and($kopie->entries()->count())->toBe(1);

    expect(fn () => $this->svc->betriebsKopieAnlegen($this->rootTeam, $this->vorlage->id, $this->nord->id))->toThrow(RuntimeException::class, 'schon eine Kopie');
    $fremderBetrieb = FoodAlchemistOutlet::create(['team_id' => $this->childB->id, 'name' => 'Fremd']);
    expect(fn () => $this->svc->betriebsKopieAnlegen($this->rootTeam, $this->vorlage->id, $fremderBetrieb->id))->toThrow(RuntimeException::class, 'nicht im eigenen Team');
    expect(fn () => $this->svc->setzeVorlage($this->rootTeam, $kopie->id, true))->toThrow(RuntimeException::class, 'Betriebs-Kopie');
    expect(fn () => $this->svc->setzeVorlage($this->childA, $this->vorlage->id, false))->toThrow(RuntimeException::class);
});

it('Paket 7: Abgleich trennt Vorlage-Änderungen von lokalen Abweichungen; Übernehmen nimmt nur die Vorlage-Änderungen', function () {
    $this->svc->setzeVorlage($this->rootTeam, $this->vorlage->id, true);
    $kopie = $this->svc->betriebsKopieAnlegen($this->rootTeam, $this->vorlage->id, $this->nord->id);
    $b = ($this->gericht)($this->rootTeam, 'Rinderroulade');
    $c = ($this->gericht)($this->rootTeam, 'Käsespätzle');
    $di = $this->heute->copy()->addDay()->format('Y-m-d');
    $mi = $this->heute->copy()->addDays(2)->format('Y-m-d');

    $this->travel(2)->minutes();
    // Vorlage ändert Dienstag; der Betrieb setzt am Mittwoch lokal etwas anderes.
    $this->svc->addEintrag($this->rootTeam, $this->vorlage->id, ['entry_date' => $di, 'line_id' => $this->linie->id, 'sales_recipe_id' => $b->id]);
    $kLinie = $kopie->lines()->where('source_line_id', $this->linie->id)->first();
    $this->svc->addEintrag($this->rootTeam, $kopie->id, ['entry_date' => $mi, 'line_id' => $kLinie->id, 'sales_recipe_id' => $c->id]);
    $this->svc->addLinie($this->rootTeam, $this->vorlage->id, ['name' => 'Wok']);

    $abgleich = $this->svc->vorlagenAbgleich($this->rootTeam, $kopie->id);
    $arten = collect($abgleich['zellen'])->pluck('art', 'datum');
    expect($arten[$di])->toBe('vorlage_geaendert')->and($arten[$mi])->toBe('lokal_abweichend')
        ->and(collect($abgleich['neue_linien'])->pluck('name')->all())->toBe(['Wok']);
    expect(collect($this->svc->betriebsKopien($this->rootTeam, $this->vorlage->id))->first()['aus_vorlage'])->toBe(2);   // Zelle + neue Linie

    expect($this->svc->ausVorlageUebernehmen($this->rootTeam, $kopie->id))->toBe(1);
    expect($kopie->entries()->whereDate('entry_date', $di)->first()->sales_recipe_id)->toBe($b->id)
        ->and($kopie->entries()->whereDate('entry_date', $mi)->first()->sales_recipe_id)->toBe($c->id)   // lokal bleibt
        ->and($kopie->lines()->where('name', 'Wok')->exists())->toBeTrue();

    $danach = $this->svc->vorlagenAbgleich($this->rootTeam, $kopie->id);
    expect(collect($danach['zellen'])->pluck('art')->all())->toBe(['lokal_abweichend'])->and($danach['neue_linien'])->toBe([]);
});

it('Paket 8: Plan/Ist je Gericht aus dem Verkaufsjournal des eigenen Teams; fremde Umsätze zählen nicht', function () {
    $mo = $this->heute->format('Y-m-d');
    $this->svc->update($this->rootTeam, $this->vorlage->id, ['default_pax' => 100]);
    FoodAlchemistSalesFact::create(['team_id' => $this->rootTeam->id, 'recipe_id' => $this->a->id, 'raw_label' => 'Gulasch', 'qty_sold' => 80, 'revenue_net' => 480, 'sold_at' => $mo]);
    FoodAlchemistSalesFact::create(['team_id' => $this->childB->id, 'recipe_id' => $this->a->id, 'raw_label' => 'Gulasch', 'qty_sold' => 999, 'revenue_net' => 9999, 'sold_at' => $mo]);

    $pi = $this->svc->planIst($this->rootTeam, $this->svc->detail($this->rootTeam, $this->vorlage->id), 'mittag', Carbon::parse($mo));
    expect($pi['hat_ist'])->toBeTrue()->and($pi['zeilen'])->toHaveCount(1)
        ->and($pi['zeilen'][0]['plan'])->toBe(100)->and($pi['zeilen'][0]['ist'])->toBe(80.0)
        ->and($pi['zeilen'][0]['abweichung_pct'])->toBe(-20.0)
        ->and($pi['zeilen'][0]['plan_umsatz'])->toBe(600.0)->and($pi['zeilen'][0]['ist_umsatz'])->toBe(480.0);

    $leer = $this->svc->planIst($this->rootTeam, $this->svc->detail($this->rootTeam, $this->vorlage->id), 'mittag', Carbon::parse($mo)->addWeeks(3));
    expect($leer['hat_ist'])->toBeFalse()->and($leer['zeilen'])->toBe([]);
});

it('Paket 10.2: Zell-Brief trägt Leitplanken aus dem Plan — nicht wiederholen, vegan auf der vegetarischen Linie, Linienpreis', function () {
    Queue::fake();
    $plan = $this->svc->create($this->rootTeam, ['name' => 'Kaskade', 'start_date' => $this->heute->format('Y-m-d'), 'cycle_weeks' => 1]);
    [$menue, $veg, $dessert] = $plan->lines()->get()->all();
    $this->svc->updateLinie($this->rootTeam, $menue->id, ['price_mode' => 'manuell', 'price_value' => 6.9, 'target_wes_min_pct' => 28, 'target_wes_max_pct' => 36]);
    $this->svc->addEintrag($this->rootTeam, $plan->id, ['entry_date' => $this->heute->format('Y-m-d'), 'line_id' => $dessert->id, 'sales_recipe_id' => $this->a->id]);

    Livewire::test(SpeiseplanEditor::class)->set('planId', $plan->id)->call('vollKaskadeStarten')->assertRedirect();

    $mo = $this->heute->format('Y-m-d');
    Queue::assertPushed(MaterializeSpeiseplanCellJob::class, fn ($j) => $j->entryDate === $mo && $j->lineId === $menue->id
        && str_contains($j->brief, 'nicht wiederholen') && str_contains($j->brief, 'Gulasch')
        && str_contains($j->brief, 'Verkaufspreis netto 6,90 €') && str_contains($j->brief, 'EK höchstens 2,48 €')
        && ! str_contains($j->brief, 'bitte vegan'));
    Queue::assertPushed(MaterializeSpeiseplanCellJob::class, fn ($j) => $j->entryDate === $mo && $j->lineId === $veg->id && str_contains($j->brief, 'bitte vegan'));
});

it('Editor + MCP: Vorlage freigeben, Kopie anlegen, Abgleich lesen; Plan/Ist-Tab und -Tool', function () {
    Livewire::test(SpeiseplanEditor::class)
        ->call('oeffnenBearbeiten', $this->vorlage->id)
        ->call('vorlageUmschalten')
        ->assertSeeHtml('data-sp-betriebskopien')
        ->set('kopieOutletId', (string) $this->nord->id)
        ->call('betriebsKopieAnlegen')
        ->assertSet('vorlageHinweis', fn ($h) => str_contains((string) $h, 'angelegt'))
        ->assertSeeHtml('data-sp-tab-planist');

    $kopie = FoodAlchemistSpeiseplan::where('source_plan_id', $this->vorlage->id)->first();
    expect($kopie)->not->toBeNull();

    $registry = app(ToolRegistry::class);
    $ctx = new ToolContext($this->user, $this->rootTeam);
    $liste = $registry->get('foodalchemist.speiseplan_vorlage.PUT')->execute(['plan_id' => $this->vorlage->id, 'aktion' => 'liste'], $ctx);
    expect($liste->success)->toBeTrue()->and($liste->data['kopien'][0]['id'])->toBe($kopie->id);

    $anzeigen = $registry->get('foodalchemist.speiseplan_vorlage.ABGLEICH')->execute(['kopie_id' => $kopie->id], $ctx);
    expect($anzeigen->success)->toBeTrue()->and($anzeigen->data['vorlage']['id'])->toBe($this->vorlage->id);
    $ohneConfirm = $registry->get('foodalchemist.speiseplan_vorlage.ABGLEICH')->execute(['kopie_id' => $kopie->id, 'aktion' => 'uebernehmen'], $ctx);
    expect($ohneConfirm->success)->toBeFalse();

    $pi = $registry->get('foodalchemist.speiseplan_planist.GET')->execute(['plan_id' => $this->vorlage->id, 'montag' => $this->heute->format('Y-m-d')], $ctx);
    expect($pi->success)->toBeTrue()->and($pi->data['zeilen'][0]['name'])->toBe('Gulasch');
});
