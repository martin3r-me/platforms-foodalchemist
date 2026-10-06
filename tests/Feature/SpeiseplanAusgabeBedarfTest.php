<?php

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Livewire\Livewire;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Tools\ToolRegistry;
use Platform\FoodAlchemist\Livewire\Speiseplan\Editor as SpeiseplanEditor;
use Platform\FoodAlchemist\Models\FoodAlchemistPrice;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Models\FoodAlchemistSpeiseplan;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplier;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItemStructure;
use Platform\FoodAlchemist\Models\FoodAlchemistVocabEinheit;
use Platform\FoodAlchemist\Services\RecipeRecomputeService;
use Platform\FoodAlchemist\Services\SpeiseplanService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 57 · Welle C — Bedarf (Paket 4) und Ausgabe-Formate (Paket 6: Tischaufsteller,
 * Linienschild, Allergen-/Komponentenliste, CSV, „laufende Woche“).
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->svc = app(SpeiseplanService::class);
    $this->user = $this->makeUser($this->rootTeam);
    $this->actingAs($this->user);
    $this->mo = '2026-10-05';

    // VK-Gericht „Linsensuppe“ (10 Portionen je Ansatz) = 1000 g Linsen (GP mit Lead-LA 1 kg zu 2 €).
    $g = FoodAlchemistVocabEinheit::create(['team_id' => $this->rootTeam->id, 'slug' => 'g', 'display_de' => 'Gramm', 'dimension' => 'mass', 'default_in_g' => 1]);
    $lieferant = FoodAlchemistSupplier::create(['team_id' => $this->rootTeam->id, 'name' => 'Chefs']);
    $this->linsen = $this->makeGp($this->rootTeam, 'Linsen');
    $la = FoodAlchemistSupplierItem::create([
        'team_id' => $this->rootTeam->id, 'supplier_id' => $lieferant->id,
        'designation' => 'Linsen 1kg', 'article_number' => 'ART-LIN', 'qty' => 1.0, 'unit_code' => 'kg',
    ]);
    FoodAlchemistSupplierItemStructure::create(['team_id' => $this->rootTeam->id, 'supplier_item_id' => $la->id, 'gp_id' => $this->linsen->id]);
    FoodAlchemistPrice::create(['team_id' => $this->rootTeam->id, 'supplier_item_id' => $la->id, 'price' => 2.00, 'status' => '0']);
    $this->linsen->update(['lead_la_supplier_item_id' => $la->id]);

    $this->suppe = FoodAlchemistRecipe::create([
        'team_id' => $this->rootTeam->id, 'recipe_key' => 'sp57c-suppe', 'name' => 'Linsensuppe',
        'status' => 'approved', 'is_sales_recipe' => true, 'sales_net' => 4.00, 'sales_unit_count' => 10,
        'sales_wording_standard' => 'Linsensuppe | Kreuzkümmel | Zitrone', 'spec_is_vegan' => true, 'spec_is_vegetarian' => true,
        'preparation' => 'Linsen weich kochen.',
    ]);
    $this->suppe->ingredients()->create(['team_id' => $this->rootTeam->id, 'position' => 0, 'gp_id' => $this->linsen->id, 'raw_text' => 'Linsen', 'quantity' => 1000, 'unit_vocab_id' => $g->id]);
    app(RecipeRecomputeService::class)->recomputePipeline($this->suppe->id);

    $this->plan = $this->svc->create($this->rootTeam, ['name' => 'Kantine Nord', 'start_date' => $this->mo, 'default_pax' => 100]);
    $this->linie = $this->plan->lines()->first();
    $this->svc->updateLinie($this->rootTeam, $this->linie->id, ['plu' => '2102']);
    $this->svc->addEintrag($this->rootTeam, $this->plan->id, ['entry_date' => $this->mo, 'line_id' => $this->linie->id, 'sales_recipe_id' => $this->suppe->id]);
});

it('Paket 4: Bedarf = Plan × Essen durch die Einkaufsliste (100 Essen → 10 Ansätze → 10 kg Linsen)', function () {
    $plan = $this->svc->detail($this->rootTeam, $this->plan->id);
    $b = $this->svc->wochenBedarf($this->rootTeam, $plan, 'mittag', Carbon::parse($this->mo));

    expect($b['ziele'])->toBe(1)->and($b['liste'])->not->toBeNull();
    $chefs = collect($b['liste']['lieferanten'])->firstWhere('lieferant', 'Chefs');
    $pos = collect($chefs['positionen'])->firstWhere('gp', 'Linsen');
    expect($pos['menge_kg'])->toBe(10.0)->and($chefs['ek_summe'])->toBe(20.0);

    // Ein Tag ohne Planung: nichts zu bestellen.
    expect($this->svc->wochenBedarf($this->rootTeam, $plan, 'mittag', Carbon::parse($this->mo), '2026-10-06')['liste'])->toBeNull();
});

it('Paket 4: Bedarf-Tab rechnet erst auf Knopfdruck; MCP speiseplan_bedarf.GET liefert dasselbe', function () {
    Livewire::test(SpeiseplanEditor::class)
        ->call('oeffnenBearbeiten', $this->plan->id)
        ->assertDontSee('ART-LIN')
        ->call('bedarfBerechnen')
        ->assertSee('Linsen')
        ->assertSee('ART-LIN');

    $res = app(ToolRegistry::class)->get('foodalchemist.speiseplan_bedarf.GET')
        ->execute(['plan_id' => $this->plan->id, 'montag' => $this->mo], new ToolContext($this->user, $this->rootTeam));
    expect($res->success)->toBeTrue()->and($res->data['ziele'])->toBe(1);
});

it('Paket 6: Tischaufsteller, Linienschild und Liste — Wording, Kennzeichnung, Preis nur auf Wunsch', function () {
    $plan = $this->svc->detail($this->rootTeam, $this->plan->id);

    $tag = $this->svc->ausgabeFormat($this->rootTeam, $plan, 'tag', 'mittag', $this->mo, $this->mo);
    $e = $tag['bloecke'][0]['zeilen'][0]['eintraege'][0];
    expect($tag['bloecke'])->toHaveCount(1)->and($e['titel'])->toBe('Linsensuppe')
        ->and($e['untertitel'])->toBe('Kreuzkümmel · Zitrone')->and($e['vk'])->toBeNull();

    $schild = $this->svc->ausgabeFormat($this->rootTeam, $plan, 'schild', 'mittag', $this->mo, $this->mo, $this->linie->id, null, true);
    expect($schild['bloecke'][0]['zeilen'])->toHaveCount(1)
        ->and($schild['bloecke'][0]['zeilen'][0]['plu'])->toBe('2102')
        ->and($schild['bloecke'][0]['zeilen'][0]['eintraege'][0]['vk'])->toBe(4.0)
        ->and($schild['bloecke'][0]['zeilen'][0]['eintraege'][0]['diaet'])->toBe(['vegan']);

    $liste = $this->svc->ausgabeFormat($this->rootTeam, $plan, 'liste', 'mittag', $this->mo);
    expect($liste['bloecke'])->toHaveCount(5)   // ganze Woche (Mo–Fr)
        ->and(collect($liste['bloecke'][0]['zeilen'][0]['eintraege'][0]['komponenten'])->pluck('name')->all())->toContain('Linsen');

    $this->get(route('foodalchemist.speiseplan.dokument', ['id' => $this->plan->id, 'format' => 'schild', 'tag' => $this->mo, 'preise' => 1]))
        ->assertOk()->assertSee('Linsensuppe')->assertSee('Kasse 2102')->assertSee('4,00 €');
    $this->get(route('foodalchemist.speiseplan.dokument', ['id' => $this->plan->id, 'format' => 'liste', 'montag' => $this->mo]))
        ->assertOk()->assertSee('Allergen- und Komponentenliste');
});

it('Paket 6: CSV der Woche mit Kopfzeile, Kassen-Nr. und Wareneinsatz', function () {
    $zeilen = $this->svc->csvZeilen($this->rootTeam, $this->svc->detail($this->rootTeam, $this->plan->id), 'mittag', Carbon::parse($this->mo));
    expect($zeilen[0][0])->toBe('Datum')->and($zeilen)->toHaveCount(2)
        ->and($zeilen[1][0])->toBe('05.10.2026')->and($zeilen[1][4])->toBe('2102')
        ->and($zeilen[1][5])->toBe('Linsensuppe | Kreuzkümmel | Zitrone')->and($zeilen[1][7])->toBe(100);

    $res = $this->get(route('foodalchemist.speiseplan.dokument', ['id' => $this->plan->id, 'format' => 'csv', 'montag' => $this->mo]));
    $res->assertOk();
    expect($res->streamedContent())->toContain('Linsensuppe')->toContain(';2102;');
});

it('Paket 6 · E9: „laufende Woche“ friert die aktuelle Woche ein; der Montags-Befehl erneuert veraltete Aushänge', function () {
    Livewire::test(SpeiseplanEditor::class)
        ->call('oeffnenBearbeiten', $this->plan->id)
        ->set('presentationGueltigBis', now()->addDays(60)->format('Y-m-d'))
        ->set('presentationLaufendeWoche', true)
        ->call('veroeffentlichen')
        ->assertSet('presentationFehler', null);

    $plan = FoodAlchemistSpeiseplan::find($this->plan->id);
    $aktuell = now()->startOfWeek()->format('Y-m-d');
    expect($plan->presentationSettings()['laufende_woche'])->toBeTrue()
        ->and($plan->presentationSettings()['montag'])->toBe($aktuell);

    // Einstellung auf eine alte Woche zurückdrehen → der Befehl bringt sie auf die laufende.
    $s = $plan->presentation_settings_json;
    $s['montag'] = '2020-01-06';
    $plan->forceFill(['presentation_settings_json' => $s])->save();
    Artisan::call('foodalchemist:speiseplan-aushang-rollieren');
    expect(FoodAlchemistSpeiseplan::find($this->plan->id)->presentationSettings()['montag'])->toBe($aktuell);

    // Zweiter Lauf: nichts mehr zu tun.
    Artisan::call('foodalchemist:speiseplan-aushang-rollieren');
    expect(Artisan::output())->toContain('0 Aushang/Aushänge erneuert');
});

/** Rezept mit fest gesetzter Kennzeichnung (alle 14 Allergene bewertet, außer $offen). */
function druckRezept(int $teamId, string $key, string $name, array $enthalten = [], array $extra = [], array $offen = []): FoodAlchemistRecipe
{
    $r = FoodAlchemistRecipe::create(['team_id' => $teamId, 'recipe_key' => $key, 'name' => $name, 'status' => 'approved'] + $extra);
    $werte = [];
    foreach (array_keys(\Platform\FoodAlchemist\Models\FoodAlchemistItemAllergen::ALLERGENE) as $slug) {
        $werte["allergen_{$slug}"] = in_array($slug, $offen, true) ? 'unbekannt' : (in_array($slug, $enthalten, true) ? 'enthalten' : 'nicht_enthalten');
    }
    \Illuminate\Support\Facades\DB::table($r->getTable())->where('id', $r->id)->update($werte);

    return $r->fresh();
}

it('Druck: Einträge ohne Linie stehen auf Tischaufsteller und Allergenliste; Legende nur für den gezeigten Tag', function () {
    $brot = druckRezept($this->rootTeam->id, 'druck-brot', 'Brotkorb', ['gluten'], ['is_sales_recipe' => true, 'sales_wording_standard' => 'Brotkorb']);
    $fisch = druckRezept($this->rootTeam->id, 'druck-fisch', 'Fischfilet', ['fish'], ['is_sales_recipe' => true, 'sales_wording_standard' => 'Fischfilet']);
    $this->svc->addEintrag($this->rootTeam, $this->plan->id, ['entry_date' => $this->mo, 'sales_recipe_id' => $brot->id]);   // ohne Linie
    $this->svc->addEintrag($this->rootTeam, $this->plan->id, ['entry_date' => '2026-10-06', 'line_id' => $this->linie->id, 'sales_recipe_id' => $fisch->id]);
    $plan = $this->svc->detail($this->rootTeam, $this->plan->id);

    $tag = $this->svc->ausgabeFormat($this->rootTeam, $plan, 'tag', 'mittag', $this->mo, $this->mo);
    $zeilen = collect($tag['bloecke'][0]['zeilen']);
    expect($zeilen->pluck('linie')->all())->toContain('Weitere Gerichte')
        ->and($zeilen->firstWhere('linie', 'Weitere Gerichte')['eintraege'][0]['titel'])->toBe('Brotkorb');
    // Gluten (Montag) ja, Fisch (Dienstag) nicht in der Legende des Montags.
    $labels = collect($tag['legende']['allergene'])->pluck('label');
    expect($labels->all())->toContain('Glutenhaltiges Getreide')->not->toContain('Fisch');

    $liste = $this->svc->ausgabeFormat($this->rootTeam, $plan, 'liste', 'mittag', $this->mo, $this->mo);
    expect(collect($liste['bloecke'][0]['zeilen'])->pluck('linie')->all())->toContain('Weitere Gerichte');

    // Linienschild: „Weitere Gerichte“ nur wenn belegt, nie als leeres Schild.
    $schild = $this->svc->ausgabeFormat($this->rootTeam, $plan, 'schild', 'mittag', $this->mo, '2026-10-06');
    expect(collect($schild['bloecke'][0]['zeilen'])->pluck('linie')->all())->not->toContain('Weitere Gerichte');

    $this->get(route('foodalchemist.speiseplan.dokument', ['id' => $this->plan->id, 'format' => 'tag', 'tag' => $this->mo]))
        ->assertOk()->assertSee('class="zelt', false)->assertSee('Brotkorb')->assertSee('Weitere Gerichte')->assertDontSee('Kantine Nord ·');
});

it('Druck: Buffetschilder lösen Pakete auf, ergänzen Unterrezepte und schreiben Allergene aus', function () {
    $jus = druckRezept($this->rootTeam->id, 'druck-jus', 'Rotweinjus (Basis)', ['celery', 'sulphites']);
    $braten = druckRezept($this->rootTeam->id, 'druck-braten', 'Rinderbraten', [], ['is_sales_recipe' => true, 'sales_wording_standard' => 'Rinderbraten | Rotweinjus', 'spec_contains_beef' => true]);
    $g = FoodAlchemistVocabEinheit::where('slug', 'g')->first();
    $braten->ingredients()->create(['team_id' => $this->rootTeam->id, 'position' => 0, 'referenced_recipe_id' => $jus->id, 'raw_text' => 'Jus', 'quantity' => 100, 'unit_vocab_id' => $g->id]);
    $gratin = druckRezept($this->rootTeam->id, 'druck-gratin', 'Kartoffelgratin', ['milk'], ['is_sales_recipe' => true, 'sales_wording_standard' => 'Kartoffelgratin', 'spec_is_vegetarian' => true], ['eggs']);
    $paket = \Platform\FoodAlchemist\Models\FoodAlchemistPaket::create(['team_id' => $this->rootTeam->id, 'name' => 'Buffet Klassik']);
    foreach ([$braten, $gratin] as $i => $g) {
        $paket->dishes()->create(['team_id' => $this->rootTeam->id, 'sales_recipe_id' => $g->id, 'position' => $i]);
    }
    $this->svc->addEintrag($this->rootTeam, $this->plan->id, ['entry_date' => '2026-10-06', 'line_id' => $this->linie->id, 'package_id' => $paket->id]);
    $plan = $this->svc->detail($this->rootTeam, $this->plan->id);

    $d = $this->svc->buffetKarten($this->rootTeam, $plan, 'mittag', $this->mo, '2026-10-06');
    $karten = collect($d['karten']);
    expect($karten->pluck('titel')->all())->toBe(['Rinderbraten', 'Rotweinjus', 'Kartoffelgratin']);   // „(Basis)“ gekappt

    $j = $karten->firstWhere('titel', 'Rotweinjus');
    expect($j['zu'])->toBe('Rinderbraten')
        ->and(collect($j['allergene'])->pluck('label')->all())->toBe(['Sellerie', 'Schwefeldioxid & Sulfite'])
        ->and($j['diaet'])->toBe([]);                                   // kein geratenes „mit Fleisch“
    expect($karten->firstWhere('titel', 'Rinderbraten')['diaet'])->toBe(['rind'])
        ->and($karten->firstWhere('titel', 'Rinderbraten')['wording'])->toBe('Rotweinjus');
    $g = $karten->firstWhere('titel', 'Kartoffelgratin');
    expect($g['diaet'])->toBe(['vegetarisch'])->and($g['unvollstaendig'])->toBeTrue();

    // Ohne Unterrezepte nur die Gerichte.
    expect($this->svc->buffetKarten($this->rootTeam, $plan, 'mittag', $this->mo, '2026-10-06', null, false)['karten'])->toHaveCount(2);

    $this->get(route('foodalchemist.speiseplan.dokument', ['id' => $this->plan->id, 'format' => 'buffet', 'tag' => '2026-10-06']))
        ->assertOk()->assertSee('Komponente zu Rinderbraten')->assertSee('Schwefeldioxid &amp; Sulfite', false)
        ->assertSee('Allergene nicht vollständig bewertet');
});

it('Druck: CSV sortiert nach Linien-Reihenfolge des Plans, nicht alphabetisch', function () {
    $zweite = $this->svc->addLinie($this->rootTeam, $this->plan->id, ['name' => 'Aktionstheke']);
    $this->svc->addEintrag($this->rootTeam, $this->plan->id, ['entry_date' => $this->mo, 'line_id' => $zweite->id, 'sales_recipe_id' => $this->suppe->id]);
    $zeilen = $this->svc->csvZeilen($this->rootTeam, $this->svc->detail($this->rootTeam, $this->plan->id), 'mittag', Carbon::parse($this->mo));

    expect($zeilen[1][3])->toBe($this->linie->name)->and($zeilen[2][3])->toBe('Aktionstheke');
});
