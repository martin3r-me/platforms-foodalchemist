<?php

use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Tools\ToolRegistry;
use Platform\FoodAlchemist\Livewire\Speiseplan\Editor as SpeiseplanEditor;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Services\SpeiseplanService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 57 · Welle B — Umbauen (Paket 5: verschieben, ersetzen, kopieren, Woche kopieren) und
 * Mengen (Paket 3: Matrix, Zellen setzen, Vorwoche, skalieren) über Service, Editor und MCP.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->svc = app(SpeiseplanService::class);
    $this->mo = '2026-10-05';   // Montag, KW 41
    $this->gericht = fn ($team, string $name, array $attrs = []) => FoodAlchemistRecipe::create(array_merge([
        'team_id' => $team->id, 'recipe_key' => 'sp57b-' . uniqid(), 'name' => $name, 'status' => 'approved',
        'is_sales_recipe' => true, 'sales_net' => 10.0, 'ek_total_eur' => 3.0,
    ], $attrs));
    $this->plan = $this->svc->create($this->rootTeam, ['name' => 'Umbau', 'start_date' => $this->mo, 'default_pax' => 100]);
    [$this->menue, $this->veg] = $this->plan->lines()->get()->all();
});

it('verschieben: Datum, Linie und Wochentag wandern mit; eine Linie eines anderen Plans wird zu „ohne Linie“', function () {
    $g = ($this->gericht)($this->rootTeam, 'Gulasch');
    $e = $this->svc->addEintrag($this->rootTeam, $this->plan->id, ['entry_date' => $this->mo, 'line_id' => $this->menue->id, 'sales_recipe_id' => $g->id]);
    $this->svc->setEintragPax($this->rootTeam, $e->id, 80);

    $neu = $this->svc->verschiebeEintrag($this->rootTeam, $e->id, '2026-10-07', $this->veg->id);
    expect($neu->entry_date->format('Y-m-d'))->toBe('2026-10-07')->and($neu->weekday)->toBe(3)
        ->and((int) $neu->line_id)->toBe($this->veg->id)->and($neu->pax)->toBe(80);

    $fremdePlanLinie = $this->svc->create($this->rootTeam, ['name' => 'Anderer'])->lines()->first();
    expect($this->svc->verschiebeEintrag($this->rootTeam, $e->id, '2026-10-07', $fremdePlanLinie->id)->line_id)->toBeNull();

    expect(fn () => $this->svc->verschiebeEintrag($this->childA, $e->id, '2026-10-08', null))->toThrow(Exception::class);
});

it('ersetzen: Inhalt getauscht, Zelle und Pax bleiben; fremder Inhalt wird abgelehnt', function () {
    $a = ($this->gericht)($this->rootTeam, 'Alt');
    $b = ($this->gericht)($this->rootTeam, 'Neu');
    $fremd = ($this->gericht)($this->childB, 'Fremd');
    $e = $this->svc->addEintrag($this->rootTeam, $this->plan->id, ['entry_date' => $this->mo, 'line_id' => $this->menue->id, 'sales_recipe_id' => $a->id]);
    $this->svc->setEintragPax($this->rootTeam, $e->id, 55);

    $neu = $this->svc->ersetzeEintrag($this->rootTeam, $e->id, ['sales_recipe_id' => $b->id]);
    expect($neu->sales_recipe_id)->toBe($b->id)->and($neu->pax)->toBe(55)->and((int) $neu->line_id)->toBe($this->menue->id);

    expect(fn () => $this->svc->ersetzeEintrag($this->rootTeam, $e->id, ['sales_recipe_id' => $fremd->id]))->toThrow(RuntimeException::class);
});

it('kopieren: auf weitere Tage, ohne Dublette bei gleichem Inhalt', function () {
    $g = ($this->gericht)($this->rootTeam, 'Salat');
    $e = $this->svc->addEintrag($this->rootTeam, $this->plan->id, ['entry_date' => $this->mo, 'line_id' => $this->veg->id, 'sales_recipe_id' => $g->id]);
    $this->svc->addEintrag($this->rootTeam, $this->plan->id, ['entry_date' => '2026-10-07', 'line_id' => $this->veg->id, 'sales_recipe_id' => $g->id]);

    expect($this->svc->kopiereEintrag($this->rootTeam, $e->id, ['2026-10-06', '2026-10-07', $this->mo]))->toBe(1);   // Di neu, Mi schon da, Mo = Quelle
    expect($this->plan->entries()->count())->toBe(3);
});

it('Woche kopieren: ersetzt belegte Zielzellen, mit „zusammenführen“ bleiben sie; Pax optional', function () {
    $a = ($this->gericht)($this->rootTeam, 'Quelle');
    $alt = ($this->gericht)($this->rootTeam, 'Alt in Zielwoche');
    $e = $this->svc->addEintrag($this->rootTeam, $this->plan->id, ['entry_date' => $this->mo, 'line_id' => $this->menue->id, 'sales_recipe_id' => $a->id]);
    $this->svc->setEintragPax($this->rootTeam, $e->id, 90);
    $this->svc->addEintrag($this->rootTeam, $this->plan->id, ['entry_date' => '2026-10-12', 'line_id' => $this->menue->id, 'sales_recipe_id' => $alt->id]);

    $res = $this->svc->kopiereWoche($this->rootTeam, $this->plan->id, $this->mo, '2026-10-12');
    expect($res)->toBe(['kopiert' => 1, 'ersetzt' => 1]);
    $ziel = $this->plan->entries()->whereDate('entry_date', '2026-10-12')->get();
    expect($ziel)->toHaveCount(1)->and($ziel->first()->sales_recipe_id)->toBe($a->id)->and($ziel->first()->pax)->toBe(90);

    $this->svc->addEintrag($this->rootTeam, $this->plan->id, ['entry_date' => '2026-10-19', 'line_id' => $this->menue->id, 'sales_recipe_id' => $alt->id]);
    $res2 = $this->svc->kopiereWoche($this->rootTeam, $this->plan->id, $this->mo, '2026-10-19', true, false);
    expect($res2)->toBe(['kopiert' => 1, 'ersetzt' => 0]);
    $ziel2 = $this->plan->entries()->whereDate('entry_date', '2026-10-19')->get();
    expect($ziel2)->toHaveCount(2)->and($ziel2->firstWhere('sales_recipe_id', $a->id)->pax)->toBeNull();

    expect(fn () => $this->svc->kopiereWoche($this->rootTeam, $this->plan->id, $this->mo, '2026-10-07'))->toThrow(RuntimeException::class, 'gleich');
});

it('Mengen: Matrix, Zelle setzen, Vorwoche übernehmen, skalieren', function () {
    $g = ($this->gericht)($this->rootTeam, 'Eintopf', ['sales_net' => 5, 'ek_total_eur' => 1.5]);
    foreach (['2026-09-28', '2026-09-29', $this->mo, '2026-10-06'] as $d) {
        $this->svc->addEintrag($this->rootTeam, $this->plan->id, ['entry_date' => $d, 'line_id' => $this->menue->id, 'sales_recipe_id' => $g->id]);
    }
    $this->svc->setzeZellenPax($this->rootTeam, $this->plan->id, $this->menue->id, '2026-09-28', 'mittag', 120);   // Vorwoche Mo

    $m = $this->svc->mengenMatrix($this->rootTeam, $this->svc->detail($this->rootTeam, $this->plan->id), 'mittag', Carbon::parse($this->mo));
    $zeile = collect($m['zeilen'])->firstWhere('line_id', $this->menue->id);
    expect($m['tage'])->toHaveCount(5)
        ->and($zeile['zellen'][$this->mo])->toBe(100)->and($zeile['zellen']['2026-10-07'])->toBeNull()
        ->and($zeile['summe'])->toBe(200)->and($zeile['vorwoche'])->toBe(220)
        ->and($zeile['umsatz'])->toBe(1000.0)->and($zeile['wes'])->toBe(30.0);

    expect($this->svc->setzeZellenPax($this->rootTeam, $this->plan->id, $this->menue->id, '2026-10-06', 'mittag', 75))->toBe(1);
    expect($this->svc->uebernehmeVorwoche($this->rootTeam, $this->plan->id, 'mittag', Carbon::parse($this->mo)))->toBe(2);
    $mo = $this->plan->entries()->whereDate('entry_date', $this->mo)->first();
    expect($mo->pax)->toBe(120);                                                  // Vorwoche Mo
    expect($this->plan->entries()->whereDate('entry_date', '2026-10-06')->first()->pax)->toBe(100);   // Vorwoche Di = Standard 100

    expect($this->svc->skaliereWoche($this->rootTeam, $this->plan->id, 'mittag', Carbon::parse($this->mo), 1.1))->toBe(2);
    expect($mo->fresh()->pax)->toBe(132);
    expect(fn () => $this->svc->skaliereWoche($this->rootTeam, $this->plan->id, 'mittag', Carbon::parse($this->mo), 0))->toThrow(RuntimeException::class);
});

it('Editor: Drag & Drop verschiebt, fremde Eintrags-IDs bleiben wirkungslos; Detail → Ersetzen → Picker tauscht', function () {
    $this->actingAs($this->makeUser($this->rootTeam));
    $montag = now()->startOfWeek()->format('Y-m-d');
    $dienstag = now()->startOfWeek()->addDay()->format('Y-m-d');
    $a = ($this->gericht)($this->rootTeam, 'Erstgericht');
    $b = ($this->gericht)($this->rootTeam, 'Zweitgericht');
    $plan = $this->svc->create($this->rootTeam, ['name' => 'DnD', 'start_date' => $montag]);
    $linie = $plan->lines()->first();
    $e = $this->svc->addEintrag($this->rootTeam, $plan->id, ['entry_date' => $montag, 'line_id' => $linie->id, 'sales_recipe_id' => $a->id]);
    $fremd = $this->svc->addEintrag($this->rootTeam, $this->plan->id, ['entry_date' => $this->mo, 'sales_recipe_id' => $a->id]);   // anderer Plan

    Livewire::test(SpeiseplanEditor::class)
        ->call('oeffnenBearbeiten', $plan->id)
        ->call('eintragVerschieben', $e->id, $dienstag, $linie->id)
        ->call('eintragVerschieben', $fremd->id, $dienstag, $linie->id)
        ->call('eintragOeffnen', $e->id)
        ->assertSeeHtml('data-sp-eintrag-detail="' . $e->id . '"')
        ->assertSeeHtml('data-sp-inhalt-link')                                  // Detail führt zum Gericht
        ->assertSeeHtml('vk-modal.oeffnen')                                     // 2026-10-07: im Vordergrund (Modal), kein neuer Tab
        ->assertSeeHtml('id: ' . $a->id . ' })')
        ->call('eintragErsetzenStarten', $e->id)
        ->assertSet('pickerErsetzenId', $e->id)
        ->call('inhaltHinzu', 'gericht', $b->id)
        ->assertSet('pickerErsetzenId', null);

    expect($e->fresh()->entry_date->format('Y-m-d'))->toBe($dienstag)
        ->and($e->fresh()->sales_recipe_id)->toBe($b->id)
        ->and($plan->entries()->count())->toBe(1)                               // ersetzt, nicht dazugelegt
        ->and($fremd->fresh()->entry_date->format('Y-m-d'))->toBe($this->mo);   // fremder Plan unberührt
});

it('Editor: Woche kopieren und Mengen-Tab', function () {
    $this->actingAs($this->makeUser($this->rootTeam));
    $montag = now()->startOfWeek();
    $g = ($this->gericht)($this->rootTeam, 'Wochengericht');
    $plan = $this->svc->create($this->rootTeam, ['name' => 'Kopie', 'start_date' => $montag->format('Y-m-d')]);
    $linie = $plan->lines()->first();
    $this->svc->addEintrag($this->rootTeam, $plan->id, ['entry_date' => $montag->format('Y-m-d'), 'line_id' => $linie->id, 'sales_recipe_id' => $g->id]);

    Livewire::test(SpeiseplanEditor::class)
        ->call('oeffnenBearbeiten', $plan->id)
        ->assertSeeHtml('data-sp-mengen-matrix')
        ->call('mengenSetzen', $linie->id, $montag->format('Y-m-d'), 150)
        ->call('wocheKopierenOeffnen')
        ->assertSet('wocheKopierenZiel', $montag->copy()->addWeek()->format('Y-m-d'))
        ->call('wocheKopieren')
        ->assertSet('wocheKopierenOffen', false);

    $kopie = $plan->entries()->whereDate('entry_date', $montag->copy()->addWeek()->format('Y-m-d'))->first();
    expect($kopie)->not->toBeNull()->and($kopie->pax)->toBe(150);
});

it('MCP: Einträge lesen und an Entwürfen umbauen, Woche kopieren mit confirm, Mengen lesen/setzen', function () {
    $user = $this->makeUser($this->rootTeam);
    $this->actingAs($user);
    $registry = app(ToolRegistry::class);
    $ctx = new ToolContext($user, $this->rootTeam);
    $g = ($this->gericht)($this->rootTeam, 'MCP-Gericht');
    $e = $this->svc->addEintrag($this->rootTeam, $this->plan->id, ['entry_date' => $this->mo, 'line_id' => $this->menue->id, 'sales_recipe_id' => $g->id]);

    $get = $registry->get('foodalchemist.speiseplan_eintraege.GET')->execute(['plan_id' => $this->plan->id, 'von' => $this->mo, 'bis' => '2026-10-11'], $ctx);
    expect($get->success)->toBeTrue()->and($get->data['anzahl'])->toBe(1)
        ->and($get->data['eintraege'][0]['id'])->toBe($e->id)->and($get->data['eintraege'][0]['pax_effektiv'])->toBe(100);

    $put = $registry->get('foodalchemist.speiseplan_eintraege.PUT')->execute(['eintrag_id' => $e->id, 'entry_date' => '2026-10-06', 'line_id' => $this->veg->id, 'pax' => 60], $ctx);
    expect($put->success)->toBeTrue('put: ' . ($put->error ?? ''))
        ->and($put->data['eintrag']['entry_date'])->toBe('2026-10-06')->and($put->data['eintrag']['pax'])->toBe(60);

    $ohne = $registry->get('foodalchemist.speiseplan.WOCHE_KOPIEREN')->execute(['plan_id' => $this->plan->id, 'von_montag' => $this->mo, 'nach_montag' => '2026-10-12'], $ctx);
    expect($ohne->success)->toBeFalse();
    $mit = $registry->get('foodalchemist.speiseplan.WOCHE_KOPIEREN')->execute(['plan_id' => $this->plan->id, 'von_montag' => $this->mo, 'nach_montag' => '2026-10-12', 'confirm' => true], $ctx);
    expect($mit->success)->toBeTrue()->and($mit->data['kopiert'])->toBe(1);

    $mengenPut = $registry->get('foodalchemist.speiseplan_mengen.PUT')->execute(['plan_id' => $this->plan->id, 'zellen' => [['line_id' => $this->veg->id, 'datum' => '2026-10-06', 'pax' => 44]]], $ctx);
    expect($mengenPut->success)->toBeTrue()->and($mengenPut->data['geaenderte_eintraege'])->toBe(1);
    $mengen = $registry->get('foodalchemist.speiseplan_mengen.GET')->execute(['plan_id' => $this->plan->id, 'montag' => $this->mo], $ctx);
    expect(collect($mengen->data['zeilen'])->firstWhere('line_id', $this->veg->id)['zellen']['2026-10-06'])->toBe(44);

    // E6: an einem aktiven Plan ändert MCP keine Einträge.
    $this->svc->update($this->rootTeam, $this->plan->id, ['status' => 'aktiv']);
    $gesperrt = $registry->get('foodalchemist.speiseplan_eintraege.PUT')->execute(['eintrag_id' => $e->id, 'pax' => 10], $ctx);
    expect($gesperrt->success)->toBeFalse();
});
