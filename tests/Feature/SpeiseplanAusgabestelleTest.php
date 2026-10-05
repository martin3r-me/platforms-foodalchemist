<?php

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Tools\ToolRegistry;
use Platform\FoodAlchemist\Jobs\MaterializeSpeiseplanCellJob;
use Platform\FoodAlchemist\Livewire\Speiseplan\Editor as SpeiseplanEditor;
use Platform\FoodAlchemist\Models\FoodAlchemistPlanningSession;
use Platform\FoodAlchemist\Models\FoodAlchemistProductionOrder;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Services\PlanningCascadeService;
use Platform\FoodAlchemist\Services\SpeiseplanService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 57 · Welle A — Befunde (Paket 0), Zelle „auf einen Blick“ (1), Linie als Ausgabestelle (2),
 * Öffnungstage (9) und Kaskaden-Bestätigung (10.1).
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->svc = app(SpeiseplanService::class);
    $this->mo = '2026-10-05';   // Montag, KW 41
    $this->gericht = fn ($team, string $name, array $attrs = []) => FoodAlchemistRecipe::create(array_merge([
        'team_id' => $team->id, 'recipe_key' => 'sp57-' . uniqid(), 'name' => $name, 'status' => 'approved',
        'is_sales_recipe' => true, 'sales_net' => 10.0, 'ek_total_eur' => 3.0,
    ], $attrs));
});

it('0.1: addEintrag nimmt nur sichtbare Inhalte an — eigenes und geerbtes ja, fremdes Team und leer nein', function () {
    $plan = $this->svc->create($this->childA, ['name' => 'Plan A', 'start_date' => $this->mo]);
    $eigen = ($this->gericht)($this->childA, 'Eigenes Gericht');
    $geerbt = ($this->gericht)($this->rootTeam, 'Master-Gericht');
    $fremd = ($this->gericht)($this->childB, 'Fremdes Gericht');

    expect($this->svc->addEintrag($this->childA, $plan->id, ['entry_date' => $this->mo, 'sales_recipe_id' => $eigen->id])->sales_recipe_id)->toBe($eigen->id)
        ->and($this->svc->addEintrag($this->childA, $plan->id, ['entry_date' => $this->mo, 'sales_recipe_id' => $geerbt->id])->sales_recipe_id)->toBe($geerbt->id);

    expect(fn () => $this->svc->addEintrag($this->childA, $plan->id, ['entry_date' => $this->mo, 'sales_recipe_id' => $fremd->id]))
        ->toThrow(RuntimeException::class, 'nicht sichtbar');
    expect(fn () => $this->svc->addEintrag($this->childA, $plan->id, ['entry_date' => $this->mo]))
        ->toThrow(RuntimeException::class, 'Genau einen Inhalt');

    expect($plan->entries()->count())->toBe(2);
});

it('0.1: auch der Editor kann kein Gericht eines fremden Teams einhängen', function () {
    $this->actingAs($this->makeUser($this->childA));
    $plan = $this->svc->create($this->childA, ['name' => 'Plan A', 'start_date' => $this->mo]);
    $fremd = ($this->gericht)($this->childB, 'Fremdes Gericht');

    expect(fn () => Livewire::test(SpeiseplanEditor::class)
        ->call('oeffnenBearbeiten', $plan->id)
        ->call('zelleOeffnen', $this->mo, null)
        ->call('inhaltHinzu', 'gericht', $fremd->id))->toThrow(RuntimeException::class);

    expect($plan->entries()->count())->toBe(0);
});

it('0.2: → Produktion ist wiederholbar — zweiter Aufruf aktualisiert, ein laufender Auftrag bleibt unberührt', function () {
    $g = ($this->gericht)($this->rootTeam, 'Tagessuppe');
    $sp = $this->svc->create($this->rootTeam, ['name' => 'Prod', 'start_date' => $this->mo]);
    $e = $this->svc->addEintrag($this->rootTeam, $sp->id, ['entry_date' => $this->mo, 'mahlzeit' => 'mittag', 'sales_recipe_id' => $g->id]);
    $mo = Carbon::parse($this->mo);

    $r1 = $this->svc->wocheAnProduktion($this->rootTeam, $sp->refresh(), 'mittag', $mo);
    expect($r1['auftraege'])->toBe(1)->and($r1['aktualisiert'])->toBe(0);

    $this->svc->setEintragPax($this->rootTeam, $e->id, 42);
    $r2 = $this->svc->wocheAnProduktion($this->rootTeam, $sp->refresh(), 'mittag', $mo);
    expect($r2['auftraege'])->toBe(0)->and($r2['aktualisiert'])->toBe(1);

    $auftraege = FoodAlchemistProductionOrder::where('team_id', $this->rootTeam->id)->where('reference', 'Speiseplan #' . $sp->id)->get();
    expect($auftraege)->toHaveCount(1)
        ->and((int) collect($auftraege->first()->targets)->first()['portions'])->toBe(42);

    $auftraege->first()->update(['status' => 'in_progress']);
    $r3 = $this->svc->wocheAnProduktion($this->rootTeam, $sp->refresh(), 'mittag', $mo);
    expect($r3['gesperrt'])->toBe(1)->and($r3['auftraege'])->toBe(0)
        ->and(FoodAlchemistProductionOrder::where('reference', 'Speiseplan #' . $sp->id)->count())->toBe(1);
});

it('0.3: Ausrollen nimmt Pax mit und lässt belegte Zellen stehen — außer mit „ersetzen“', function () {
    $vorlage = ($this->gericht)($this->rootTeam, 'Vorlage-Gericht');
    $hand = ($this->gericht)($this->rootTeam, 'Handgesetzt');
    $sp = $this->svc->create($this->rootTeam, ['name' => 'Zyklus', 'start_date' => $this->mo, 'cycle_weeks' => 1]);
    $linie = $sp->lines()->first();
    $e = $this->svc->addEintrag($this->rootTeam, $sp->id, ['entry_date' => $this->mo, 'line_id' => $linie->id, 'sales_recipe_id' => $vorlage->id]);
    $this->svc->setEintragPax($this->rootTeam, $e->id, 70);
    $this->svc->addEintrag($this->rootTeam, $sp->id, ['entry_date' => '2026-10-12', 'line_id' => $linie->id, 'sales_recipe_id' => $hand->id]);

    expect($this->svc->vorlageAusrollen($this->rootTeam, $sp->id, '2026-10-19'))->toBe(1);   // nur der 19.10.
    $am12 = $sp->entries()->whereDate('entry_date', '2026-10-12')->get();
    expect($am12)->toHaveCount(1)->and($am12->first()->sales_recipe_id)->toBe($hand->id)
        ->and($sp->entries()->whereDate('entry_date', '2026-10-19')->first()->pax)->toBe(70);

    $this->svc->vorlageAusrollen($this->rootTeam, $sp->id, '2026-10-19', true);
    $am12 = $sp->entries()->whereDate('entry_date', '2026-10-12')->get();
    expect($am12)->toHaveCount(1)->and($am12->first()->sales_recipe_id)->toBe($vorlage->id)
        ->and($sp->entries()->whereDate('entry_date', '2026-10-19')->count())->toBe(1);
});

it('0.4: Veröffentlichen aus dem Editor friert die sichtbare Woche ein, nicht die Startwoche', function () {
    $this->actingAs($this->makeUser($this->rootTeam));
    $g = ($this->gericht)($this->rootTeam, 'Gulasch');
    $sp = $this->svc->create($this->rootTeam, ['name' => 'Aushang', 'start_date' => $this->mo]);
    $this->svc->addEintrag($this->rootTeam, $sp->id, ['entry_date' => '2026-10-12', 'line_id' => $sp->lines()->first()->id, 'sales_recipe_id' => $g->id]);

    Livewire::test(SpeiseplanEditor::class)
        ->call('oeffnenBearbeiten', $sp->id)
        ->call('wocheVerschieben', 1)
        ->set('presentationGueltigBis', now()->addDays(30)->format('Y-m-d'))
        ->call('veroeffentlichen')
        ->assertSet('presentationFehler', null);

    $snap = $sp->fresh()->presentation_snapshot_json;
    $snap = is_string($snap) ? json_decode($snap, true) : $snap;
    expect((string) ($snap['subtitle'] ?? data_get($snap, 'content.subtitle', '')))->toContain('KW 42');
});

it('0.5: der Aushang zeigt das Wording, nicht den internen Namen', function () {
    $g = ($this->gericht)($this->rootTeam, '[HG] Rinderroulade intern', ['sales_wording_standard' => 'Rinderroulade | Rotkohl']);
    $sp = $this->svc->create($this->rootTeam, ['name' => 'Wording', 'start_date' => $this->mo]);
    $this->svc->addEintrag($this->rootTeam, $sp->id, ['entry_date' => $this->mo, 'line_id' => $sp->lines()->first()->id, 'sales_recipe_id' => $g->id]);

    $dok = $this->svc->dokumentDaten($this->rootTeam, $this->svc->detail($this->rootTeam, $sp->id), 'mittag', $this->mo);
    $namen = collect($dok['zeilen'])->flatMap(fn ($z) => collect($z['zellen'])->flatten(1))->pluck('name')->all();
    expect($namen)->toContain('Rinderroulade | Rotkohl')->not->toContain('[HG] Rinderroulade intern');
});

it('Paket 1+2: Wareneinsatz gegen das Zielband der Linie, Linienpreis, Gäste nur aus Hauptgängen, Budget je Gast', function () {
    $sp = $this->svc->create($this->rootTeam, ['name' => 'Kennzahlen', 'start_date' => $this->mo, 'default_pax' => 100]);
    $this->svc->update($this->rootTeam, $sp->id, ['budget_wareneinsatz' => '3,00']);
    [$menue, , $dessert] = $sp->lines()->get()->all();   // Starter: Menü 1 (Hauptgang), Vegetarisch (Hauptgang), Dessert
    $this->svc->updateLinie($this->rootTeam, $menue->id, ['target_wes_min_pct' => 25, 'target_wes_max_pct' => 35]);
    $this->svc->updateLinie($this->rootTeam, $dessert->id, ['price_mode' => 'manuell', 'price_value' => '2,00', 'default_pax' => 40]);

    $roulade = ($this->gericht)($this->rootTeam, 'Rinderroulade | Rotkohl | Klöße', ['sales_net' => 10, 'ek_total_eur' => 3, 'spec_contains_beef' => true]);
    $lachs = ($this->gericht)($this->rootTeam, 'Lachs', ['sales_net' => 10, 'ek_total_eur' => 6]);
    $creme = ($this->gericht)($this->rootTeam, 'Vanillecreme', ['sales_net' => 5, 'ek_total_eur' => 0.8, 'spec_is_vegetarian' => true]);

    $e1 = $this->svc->addEintrag($this->rootTeam, $sp->id, ['entry_date' => $this->mo, 'line_id' => $menue->id, 'sales_recipe_id' => $roulade->id]);
    $e2 = $this->svc->addEintrag($this->rootTeam, $sp->id, ['entry_date' => '2026-10-06', 'line_id' => $menue->id, 'sales_recipe_id' => $lachs->id]);
    $e3 = $this->svc->addEintrag($this->rootTeam, $sp->id, ['entry_date' => $this->mo, 'line_id' => $dessert->id, 'sales_recipe_id' => $creme->id]);

    $plan = $this->svc->detail($this->rootTeam, $sp->id);
    $zk = $this->svc->zellenKennzahlen($this->rootTeam, $plan, 'mittag', Carbon::parse($this->mo));

    $k1 = $zk['eintraege'][$e1->id];
    expect($k1['wes'])->toBe(30.0)->and($k1['status'])->toBe('ok')->and($k1['band']['quelle'])->toBe('linie')
        ->and($k1['titel'])->toBe('Rinderroulade')->and($k1['untertitel'])->toBe('Rotkohl · Klöße')
        ->and($k1['diaet'])->toBe(['rind'])->and($k1['pax'])->toBe(100);

    expect($zk['eintraege'][$e2->id]['wes'])->toBe(60.0)
        ->and($zk['eintraege'][$e2->id]['status'])->toBe('weit_ueber');       // > 1,5 × 35 %

    $k3 = $zk['eintraege'][$e3->id];
    expect($k3['vk'])->toBe(2.0)->and($k3['linienpreis'])->toBeTrue()      // fester Linienpreis schlägt den Gericht-VK
        ->and($k3['wes'])->toBe(40.0)->and($k3['band']['quelle'])->toBe('team')
        ->and($k3['pax'])->toBe(40)->and($k3['diaet'])->toBe(['vegetarisch']);

    $montag = $zk['tage'][$this->mo];
    expect($montag['gaeste'])->toBe(100)                                    // nur die Hauptgang-Linie zählt Gäste
        ->and($montag['portionen'])->toBe(140)
        ->and($montag['umsatz'])->toBe(1080.0)                              // 10 × 100 + 2 × 40
        ->and($montag['ek_je_gast'])->toBe(3.32);                           // (300 + 32) / 100

    $budget = $this->svc->budgetAmpel($plan, $zk, $this->svc->wochenKosten($plan, 'mittag', Carbon::parse($this->mo)));
    expect($budget['basis'])->toBe('je_gast')->and($budget['avg'])->toBe(4.66)->and($budget['ampel'])->toBe('danger');
});

it('Paket 2: Linien-Felder werden gesäubert, Dauerangebote zählen nicht als Wiederholung, Duplizieren nimmt alles mit', function () {
    $sp = $this->svc->create($this->rootTeam, ['name' => 'Linien', 'start_date' => $this->mo, 'min_abstand_tage' => 14]);

    $x = $this->svc->addLinie($this->rootTeam, $sp->id, [
        'name' => 'Falsch gepflegt', 'role' => 'quatsch', 'meal' => 'nachts', 'price_mode' => 'teuer',
        'target_wes_min_pct' => '45', 'target_wes_max_pct' => '30,5', 'default_pax' => 0,
    ]);
    expect($x->role)->toBeNull()->and($x->meal)->toBeNull()->and($x->price_mode)->toBe('auto')
        ->and($x->target_wes_min_pct)->toBe(30.5)->and($x->target_wes_max_pct)->toBe(45.0)   // vertauschte Grenzen geradegerückt
        ->and($x->default_pax)->toBeNull();

    $salat = $this->svc->addLinie($this->rootTeam, $sp->id, ['name' => 'Salatbar', 'role' => 'salat', 'is_standing' => true, 'plu' => '2105', 'target_wes_max_pct' => 40]);
    $g = ($this->gericht)($this->rootTeam, 'Salatbar klein');
    $this->svc->addEintrag($this->rootTeam, $sp->id, ['entry_date' => $this->mo, 'line_id' => $salat->id, 'sales_recipe_id' => $g->id]);
    $this->svc->addEintrag($this->rootTeam, $sp->id, ['entry_date' => '2026-10-06', 'line_id' => $salat->id, 'sales_recipe_id' => $g->id]);
    expect(collect($this->svc->wiederholungen($sp->fresh()))->where('konflikt', true))->toBeEmpty();

    $kopie = $this->svc->dupliziere($this->rootTeam, $sp->id);
    $kSalat = $kopie->lines()->where('name', 'Salatbar')->first();
    expect($kSalat->is_standing)->toBeTrue()->and($kSalat->plu)->toBe('2105')
        ->and($kSalat->role)->toBe('salat')->and($kSalat->target_wes_max_pct)->toBe(40.0);
});

it('Paket 9: Öffnungstage steuern Wochentage, Aushang und Kostformen; leer fällt auf Mo–Fr zurück', function () {
    $sp = $this->svc->create($this->rootTeam, ['name' => 'Sechs Tage', 'start_date' => $this->mo, 'opening_days' => [6, 1, 2, 3, 4, 5, 9]]);
    $g = ($this->gericht)($this->rootTeam, 'Samstagsgericht');
    $this->svc->addEintrag($this->rootTeam, $sp->id, ['entry_date' => '2026-10-10', 'line_id' => $sp->lines()->first()->id, 'sales_recipe_id' => $g->id]);

    $plan = $this->svc->detail($this->rootTeam, $sp->id);
    expect($plan->oeffnungstage())->toBe([1, 2, 3, 4, 5, 6]);

    $tage = $this->svc->wochenTage($plan, Carbon::parse($this->mo));
    expect($tage)->toHaveCount(6)->and($tage[5]->format('Y-m-d'))->toBe('2026-10-10');

    $dok = $this->svc->dokumentDaten($this->rootTeam, $plan, 'mittag', $this->mo);
    expect(collect($dok['tage'])->pluck('ymd')->all())->toContain('2026-10-10')
        ->and($this->svc->kostformAbdeckung($plan, 'mittag', Carbon::parse($this->mo))[0]['tage'])->toBe(6);

    $this->svc->update($this->rootTeam, $sp->id, ['opening_days' => []]);
    expect($sp->fresh()->oeffnungstage())->toBe([1, 2, 3, 4, 5]);
});

it('Paket 9/10.1: Kaskaden-Vorschau zählt Öffnungstage × Linien, erst „Starten“ legt los; die Frühstückslinie plant Frühstück', function () {
    Queue::fake();
    $this->actingAs($this->makeUser($this->rootTeam));
    $sp = $this->svc->create($this->rootTeam, ['name' => 'Kaskade', 'start_date' => $this->mo, 'cycle_weeks' => 1, 'opening_days' => [1, 2, 3, 4, 5, 6]]);
    $this->svc->addLinie($this->rootTeam, $sp->id, ['name' => 'Frühstücksbuffet', 'meal' => 'fruehstueck', 'role' => 'hauptgang']);

    $v = app(PlanningCascadeService::class)->speiseplanVorschau($this->rootTeam, $sp->id);
    expect($v['linien'])->toBe(4)->and($v['leer'])->toBe(24)->and($v['dieser_lauf'])->toBe(24)->and($v['gedeckelt'])->toBeFalse();

    Livewire::test(SpeiseplanEditor::class)
        ->set('planId', $sp->id)
        ->call('vollKaskadePruefen')
        ->assertSet('kaskadeVorschau.leer', 24)
        ->assertNoRedirect();
    Queue::assertNotPushed(MaterializeSpeiseplanCellJob::class);    // Prüfen startet nichts

    Livewire::test(SpeiseplanEditor::class)
        ->set('planId', $sp->id)
        ->call('vollKaskadeStarten')
        ->assertRedirect();

    Queue::assertPushed(MaterializeSpeiseplanCellJob::class, 24);
    Queue::assertPushed(MaterializeSpeiseplanCellJob::class, fn ($job) => $job->meal === 'fruehstueck' && $job->entryDate === '2026-10-10');
    Queue::assertPushed(MaterializeSpeiseplanCellJob::class, fn ($job) => str_contains($job->brief, 'Mittagsgericht (Dessert)'));
});

it('Backlog #54: ohne Linien entsteht keine verwaiste Planungs-Session', function () {
    Queue::fake();
    $this->actingAs($this->makeUser($this->rootTeam));
    $sp = $this->svc->create($this->rootTeam, ['name' => 'Leer']);
    foreach ($sp->lines()->pluck('id') as $id) {
        $this->svc->removeLinie($this->rootTeam, (int) $id);
    }

    Livewire::test(SpeiseplanEditor::class)->set('planId', $sp->id)
        ->call('vollKaskadePruefen')
        ->assertSet('kaskadeVorschau', null)
        ->assertSet('kaskadeMeldung', fn ($m) => is_string($m) && str_contains($m, 'keine Menü-Linien'))
        ->call('vollKaskadeStarten')
        ->assertNoRedirect();

    expect(FoodAlchemistPlanningSession::where('created_via', 'speiseplan_vollkaskade')->count())->toBe(0);
});

it('Editor: Matrix zeigt Zell-Kennzahlen, Tagesfuß und Linien-Ampel; Öffnungstag umschalten speichert', function () {
    $this->actingAs($this->makeUser($this->rootTeam));
    $sp = $this->svc->create($this->rootTeam, ['name' => 'UI', 'start_date' => now()->startOfWeek()->format('Y-m-d')]);
    $g = ($this->gericht)($this->rootTeam, 'Kürbissuppe | Ingwer', ['sales_net' => 4, 'ek_total_eur' => 1, 'spec_is_vegan' => true, 'spec_is_vegetarian' => true]);
    $this->svc->addEintrag($this->rootTeam, $sp->id, ['entry_date' => now()->startOfWeek()->format('Y-m-d'), 'line_id' => $sp->lines()->first()->id, 'sales_recipe_id' => $g->id]);

    Livewire::test(SpeiseplanEditor::class)
        ->call('oeffnenBearbeiten', $sp->id)
        ->assertSee('Kürbissuppe')
        ->assertSee('Ingwer')
        ->assertSeeHtml('data-sp-tagesfuss')
        ->assertSeeHtml('data-sp-linien-ampel')
        ->call('dichteSetzen', 'detail')
        ->assertSeeHtml('data-sp-komponenten')
        ->call('oeffnungstagUmschalten', 6)
        ->assertSet('form.opening_days', [1, 2, 3, 4, 5, 6]);

    expect($sp->fresh()->oeffnungstage())->toBe([1, 2, 3, 4, 5, 6]);
});

it('MCP: speiseplan_linien.PUT setzt Rolle und Zielband, speiseplaene.GET liefert sie samt Öffnungstagen', function () {
    $user = $this->makeUser($this->rootTeam);
    $this->actingAs($user);
    $registry = app(ToolRegistry::class);
    $ctx = new ToolContext($user, $this->rootTeam);
    $sp = $this->svc->create($this->rootTeam, ['name' => 'MCP', 'start_date' => $this->mo]);
    $linie = $sp->lines()->first();

    $put = $registry->get('foodalchemist.speiseplan_linien.PUT')->execute(['linie_id' => $linie->id, 'felder' => [
        'role' => 'suppe', 'plu' => '2101', 'target_wes_min_pct' => 25, 'target_wes_max_pct' => 45, 'is_standing' => true,
    ]], $ctx);
    expect($put->success)->toBeTrue('put: ' . ($put->error ?? ''));

    $planPut = $registry->get('foodalchemist.speiseplaene.PUT')->execute(['id' => $sp->id, 'felder' => ['opening_days' => [1, 2, 3, 4, 5, 6]]], $ctx);
    expect($planPut->success)->toBeTrue('plan put: ' . ($planPut->error ?? ''));

    $get = $registry->get('foodalchemist.speiseplaene.GET')->execute(['id' => $sp->id], $ctx);
    $l = collect($get->data['speiseplan']['linien'])->firstWhere('id', $linie->id);
    expect($get->data['speiseplan']['opening_days'])->toBe([1, 2, 3, 4, 5, 6])
        ->and($l['role'])->toBe('suppe')->and($l['plu'])->toBe('2101')
        ->and($l['target_wes_max_pct'])->toBe(45.0)->and($l['is_standing'])->toBeTrue();
});
