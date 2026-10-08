<?php

use Livewire\Livewire;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Tools\ToolRegistry;
use Platform\FoodAlchemist\Livewire\Etiketten\Index as EtikettenIndex;
use Platform\FoodAlchemist\Livewire\Settings\Etiketten as EtikettenSettings;
use Platform\FoodAlchemist\Models\FoodAlchemistInventoryLocation;
use Platform\FoodAlchemist\Models\FoodAlchemistLabelTemplate;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Models\FoodAlchemistVocabEinheit;
use Platform\FoodAlchemist\Services\EtikettService;
use Platform\FoodAlchemist\Services\LagerEinrichtungService;
use Platform\FoodAlchemist\Services\RecipeRecomputeService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 70 · Etiketten: Inhalt aus Rezept (Allergene, Zutatenliste nach Gewicht mit hervorgehobenen
 * Allergenen, verbrauchen bis aus der Haltbarkeit), Vorlagen mit Pflichtfeldern und Datums-Modus,
 * Druckansicht, Seite, Einstellungen, MCP.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->user = $this->makeUser($this->rootTeam);
    $this->actingAs($this->user);
    $this->svc = app(EtikettService::class);
    $t = $this->rootTeam->id;
    $g = FoodAlchemistVocabEinheit::create(['team_id' => $t, 'slug' => 'g', 'display_de' => 'Gramm', 'dimension' => 'mass', 'default_in_g' => 1]);

    $this->kuerbis = $this->makeGp($this->rootTeam, 'Kürbis: frisch, Hokkaido');
    $this->sahne = $this->makeGp($this->rootTeam, 'Sahne: frisch, 30 % Fett');
    $this->sahne->forceFill(['allergen_milk' => 'enthalten'])->save();
    $this->sellerie = $this->makeGp($this->rootTeam, 'Sellerie: frisch');
    $this->sellerie->forceFill(['allergen_celery' => 'enthalten'])->save();
    $this->kuerbis->forceFill(['allergen_milk' => 'nicht_enthalten'])->save();

    $this->fond = FoodAlchemistRecipe::create(['team_id' => $t, 'recipe_key' => 'fond', 'name' => '[BR] Fond: Gemüsefond', 'status' => 'approved', 'is_sales_recipe' => false, 'yield_kg' => 1.0]);
    $this->fond->ingredients()->create(['team_id' => $t, 'position' => 0, 'gp_id' => $this->sellerie->id, 'raw_text' => 'Sellerie', 'quantity' => 200, 'unit_vocab_id' => $g->id]);
    $this->suppe = FoodAlchemistRecipe::create(['team_id' => $t, 'recipe_key' => 'suppe', 'name' => 'Suppe: Kürbissuppe', 'status' => 'approved', 'is_sales_recipe' => false, 'yield_kg' => 2.0,
        'shelf_life_chilled_days' => 3, 'shelf_life_frozen_days' => 90]);
    $this->suppe->ingredients()->create(['team_id' => $t, 'position' => 0, 'gp_id' => $this->sahne->id, 'raw_text' => 'Sahne', 'quantity' => 200, 'unit_vocab_id' => $g->id]);
    $this->suppe->ingredients()->create(['team_id' => $t, 'position' => 1, 'gp_id' => $this->kuerbis->id, 'raw_text' => 'Kürbis', 'quantity' => 1000, 'unit_vocab_id' => $g->id]);
    $this->suppe->ingredients()->create(['team_id' => $t, 'position' => 2, 'referenced_recipe_id' => $this->fond->id, 'raw_text' => 'Fond', 'quantity' => 500, 'unit_vocab_id' => $g->id]);
    $rc = app(RecipeRecomputeService::class);
    $rc->recomputePipeline($this->fond->id);
    $rc->recomputePipeline($this->suppe->id);
});

it('Inhalt aus dem Rezept: Allergene mit Kürzel, Zutaten nach Gewicht mit Hervorhebung, verbrauchen bis aus der Haltbarkeit', function () {
    $d = $this->svc->daten($this->rootTeam, 'recipe', $this->suppe->id);
    expect($d['bezeichnung'])->toBe('Kürbissuppe')
        ->and(array_column($d['allergene'], 'label'))->toContain('Milch')
        ->and(array_column($d['zutaten'], 'name'))->toBe(['Kürbis', 'Gemüsefond', 'Sahne'])      // 1000 > 500 > 200 g
        ->and($d['zutaten'][2]['allergen'])->toBeTrue()->and($d['zutaten'][0]['allergen'])->toBeFalse()
        ->and($d['zutaten'][1]['teile'])->toBe([['name' => 'Sellerie', 'allergen' => true]])
        ->and($d['datum']['verbrauchen_bis']->toDateString())->toBe(now()->addDays(3)->toDateString());

    // Tiefgekühlt: ab Einfrierdatum + 90 Tage
    $tk = $this->svc->daten($this->rootTeam, 'recipe', $this->suppe->id, ['lagerung' => 'tiefgekuehlt', 'eingefroren_am' => '2026-10-01']);
    expect($tk['datum']['verbrauchen_bis']->toDateString())->toBe('2026-12-30')
        ->and($tk['datum']['eingefroren_am']->toDateString())->toBe('2026-10-01');
    // Hand-Datum schlägt Automatik
    expect($this->svc->daten($this->rootTeam, 'recipe', $this->suppe->id, ['verbrauchen_bis' => '2026-11-11'])['datum']['verbrauchen_bis']->toDateString())->toBe('2026-11-11');
});

it('Vorlagen: Pflichtfelder bleiben an, Typ verkauf erzwingt LMIV-Felder, Datums-Modus, Standard wechselt', function () {
    $std = $this->svc->vorlagen($this->rootTeam)->first();
    expect($std->name)->toBe('Standard (intern)')->and($std->is_default)->toBeTrue();

    $v = $this->svc->speichern($this->rootTeam, null, ['name' => 'To-Go', 'typ' => 'verkauf', 'format' => 'dymo_54', 'is_default' => true, 'felder' => [
        ['key' => 'bezeichnung', 'an' => false], ['key' => 'verbrauchen_bis', 'an' => true, 'modus' => 'leer'], ['key' => 'kuerzel', 'an' => true],
    ]]);
    $felder = collect($v->felder)->keyBy('key');
    expect($felder['bezeichnung']['an'])->toBeTrue()                       // Pflicht, Abschalten ignoriert
        ->and($felder['zutaten']['an'])->toBeTrue()->and($felder['hersteller']['an'])->toBeTrue()   // LMIV
        ->and($felder['verbrauchen_bis']['modus'])->toBe('leer')
        ->and(collect($v->felder)->pluck('key')->take(3)->all())->toBe(['bezeichnung', 'verbrauchen_bis', 'kuerzel'])
        ->and(FoodAlchemistLabelTemplate::find($std->id)->is_default)->toBeFalse();

    expect(fn () => $this->svc->speichern($this->rootTeam, null, ['name' => 'X', 'format' => 'a3']))->toThrow(\RuntimeException::class, 'Format')
        ->and(fn () => $this->svc->vorlage($this->childA, $v->id))->toThrow(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
});

it('Druckansicht: A4-Bogen mit Startplatz, Handschreib-Linie, PDF; Stellplatz-Etikett mit Inhalt', function () {
    $v = $this->svc->speichern($this->rootTeam, null, ['name' => 'Bogen', 'format' => 'a4_24', 'felder' => [
        ['key' => 'bezeichnung', 'an' => true], ['key' => 'hergestellt_am', 'an' => true, 'modus' => 'leer'], ['key' => 'verbrauchen_bis', 'an' => true], ['key' => 'allergene', 'an' => true], ['key' => 'zutaten', 'an' => true],
    ]]);
    $url = $this->svc->druckUrl('recipe', $this->suppe->id, $v->id, [], 5, 4);
    $html = $this->get($url)->assertOk()->getContent();
    expect(substr_count($html, 'class="etikett"'))->toBe(5)
        ->and($html)->toContain('Kürbissuppe')->toContain('class="linie"')->toContain('<b>Sahne</b>')->toContain('Milch (G)');
    $this->get($url . '&pdf=1')->assertOk()->assertHeader('content-type', 'application/pdf');

    $ort = FoodAlchemistInventoryLocation::create(['team_id' => $this->rootTeam->id, 'name' => 'Hauptlager', 'type' => 'warehouse', 'is_default' => true, 'is_active' => true]);
    $ein = app(LagerEinrichtungService::class);
    $bin = $ein->stellplatzAnlegen($this->rootTeam, $ort->id, 'Kühlhaus Regal B', 'kuehl');
    $ein->zuordnen($this->rootTeam, $ort->id, [$this->sahne->id], $bin->id);
    $this->get($this->svc->druckUrl('stellplatz', $bin->id))->assertOk()->assertSee('Kühlhaus Regal B')->assertSee('Sahne');

    $this->actingAs($this->makeUser($this->childA));
    $this->get($this->svc->druckUrl('stellplatz', $bin->id))->assertNotFound();
});

it('Seite: Rezept wählen, Lagerart aus dem Rezept, Menge als Zahl + Einheit; Einstellungen: Vorlage speichern', function () {
    // Spec 76: Haltbarkeit wird am Rezept gepflegt, das Etikett wählt nur noch aus den Lagerarten des Rezepts
    $this->suppe->update(['storage_types' => ['gekuehlt', 'tiefgekuehlt'], 'shelf_life_frozen_days' => 90]);
    $lw = Livewire::test(EtikettenIndex::class)
        ->set('suchArt', 'recipe')->set('suche', 'Kürbis')->assertSee('Suppe: Kürbissuppe')
        ->call('waehlen', 'recipe', $this->suppe->id)
        ->assertSee('Etikett-Vorschau')->assertSee('Zum Rezept')->assertDontSee('Lagerung & Haltbarkeit am Rezept')
        ->assertSet('mengeEinheit', 'kg')
        ->set('mengeZahl', '2,5')->assertSet('e.menge', '2,5 kg')
        ->set('mengeEinheit', 'l')->assertSet('e.menge', '2,5 l')
        ->call('$set', 'e.lagerung', 'tiefgekuehlt');
    expect($lw->html())->toContain('data-etikett-lagerart="tiefgekuehlt"')->not->toContain('data-etikett-lagerart="trocken"');

    $lw = Livewire::test(EtikettenSettings::class)
        ->set('form.name', 'Kühlhaus')->set('form.format', 'a4_40')->call('speichern')->assertSet('fehler', null);
    expect(FoodAlchemistLabelTemplate::where('name', 'Kühlhaus')->value('format'))->toBe('a4_40');
    $lw->call('neu')->assertSee('Neue Vorlage');
});

it('MCP: labels.POST liefert Links + Inhalt, label_templates.GET/POST', function () {
    $reg = app(ToolRegistry::class);
    $ctx = new ToolContext($this->user, $this->rootTeam);
    $r = $reg->get('foodalchemist.labels.POST')->execute(['quelle' => 'recipe', 'id' => $this->suppe->id, 'anzahl' => 6, 'eingabe' => ['lagerung' => 'tiefgekuehlt', 'eingefroren_am' => '2026-10-01']], $ctx);
    expect($r->success)->toBeTrue()
        ->and($r->data['druck_url'])->toContain('anzahl=6')
        ->and($r->data['pdf_url'])->toContain('pdf=1')
        ->and($r->data['inhalt']['allergene'])->toContain('Milch')
        ->and($r->data['inhalt']['verbrauchen_bis'])->toBe('2026-12-30');
    expect($reg->get('foodalchemist.labels.POST')->execute(['quelle' => 'recipe', 'id' => 999999], $ctx)->success)->toBeFalse();

    $p = $reg->get('foodalchemist.label_templates.POST')->execute(['name' => 'TK Dymo', 'format' => 'dymo_54', 'felder' => [['key' => 'verbrauchen_bis', 'an' => true, 'modus' => 'leer']]], $ctx);
    expect($p->success)->toBeTrue();
    $g = $reg->get('foodalchemist.label_templates.GET')->execute([], $ctx);
    expect(collect($g->data['vorlagen'])->pluck('name')->all())->toContain('TK Dymo')
        ->and($g->data['formate'])->toHaveKey('rolle_62');
});

it('Übliche Lagerart am Rezept: Speichern über RecipeService/MCP, Etikett übernimmt sie (TK → Einfrierdatum + TK-Haltbarkeit)', function () {
    app(\Platform\FoodAlchemist\Services\RecipeService::class)->update($this->rootTeam, $this->suppe->id, ['storage_type' => 'tiefgekuehlt', 'shelf_life_frozen_days' => 60]);
    $r = $this->suppe->refresh();
    expect($r->storage_type)->toBe('tiefgekuehlt')->and($r->shelf_life_frozen_days)->toBe(60);

    $d = $this->svc->daten($this->rootTeam, 'recipe', $this->suppe->id);
    expect($d['lagerung'])->toBe('tiefgekuehlt')
        ->and($d['datum']['eingefroren_am']->toDateString())->toBe(now()->toDateString())
        ->and($d['datum']['verbrauchen_bis']->toDateString())->toBe(now()->addDays(60)->toDateString());
    // Im Einzelfall gekühlt statt TK
    expect($this->svc->daten($this->rootTeam, 'recipe', $this->suppe->id, ['lagerung' => 'gekuehlt'])['datum']['verbrauchen_bis']->toDateString())
        ->toBe(now()->addDays(3)->toDateString());

    $reg = app(ToolRegistry::class);
    $this->suppe->forceFill(['status' => 'draft'])->save();   // freigegebene Rezepte sind für die KI gesperrt (kiEditGesperrt)
    $put = $reg->get('foodalchemist.recipes.PUT')->execute(['recipe_id' => $this->suppe->id, 'storage_type' => 'trocken'], new ToolContext($this->user, $this->rootTeam));
    expect($put->success)->toBeTrue()->and($this->suppe->refresh()->storage_type)->toBe('trocken');
});

it('Spec 76: Lagerarten am Rezept — Mehrfachauswahl, Standard, Haltbarkeit je Lagerart, Etikett + Einlagern', function () {
    $r = $this->suppe;
    // Ableitung ohne explizite Auswahl: Standard + Lagerarten mit Haltbarkeit
    $r->update(['storage_types' => null, 'storage_type' => null, 'shelf_life_chilled_days' => 3, 'shelf_life_frozen_days' => null]);
    expect($r->refresh()->lagerarten())->toBe(['gekuehlt'])->and($r->standardLagerart())->toBe('gekuehlt');

    $r->update(['storage_types' => ['tiefgekuehlt', 'trocken'], 'storage_type' => 'tiefgekuehlt', 'shelf_life_frozen_days' => 60, 'shelf_life_dry_days' => 10]);
    $r->refresh();
    expect($r->lagerarten())->toBe(['tiefgekuehlt', 'trocken'])->and($r->standardLagerart())->toBe('tiefgekuehlt')->and($r->haltbarTage('trocken'))->toBe(10);

    $d = $this->svc->daten($this->rootTeam, 'recipe', $r->id, ['hergestellt_am' => '2026-10-01', 'eingefroren_am' => '2026-10-02']);
    expect($d['lagerung'])->toBe('tiefgekuehlt')->and($d['lagerarten'])->toBe(['tiefgekuehlt', 'trocken'])
        ->and($d['datum']['verbrauchen_bis']->toDateString())->toBe('2026-12-01');                 // 02.10. + 60 Tage
    $t = $this->svc->daten($this->rootTeam, 'recipe', $r->id, ['lagerung' => 'trocken', 'hergestellt_am' => '2026-10-01']);
    expect($t['datum']['verbrauchen_bis']->toDateString())->toBe('2026-10-11');                    // trocken: 01.10. + 10

    $b = app(\Platform\FoodAlchemist\Services\EigenproduktionService::class)->einlagern($this->rootTeam, ['recipe_id' => $r->id, 'menge' => 1, 'produziert_am' => '2026-10-01', 'lagerart' => 'trocken']);
    expect($b->best_before->toDateString())->toBe('2026-10-11');
});

it('Spec 76: Rezept-Editor speichert Lagerarten (Mehrfachauswahl) und Standard; MCP recipes.PUT', function () {
    $r = $this->suppe;
    $r->update(['status' => 'draft']);
    Livewire::test(\Platform\FoodAlchemist\Livewire\Recipes\RecipeModal::class)
        ->call('oeffnen', $r->id)
        ->set('form.storage_types', ['gekuehlt', 'tiefgekuehlt'])->set('form.storage_type', 'trocken')   // Standard nicht erlaubt → erste
        ->set('form.shelf_life_frozen_days', 90)
        ->call('speichern');
    $r->refresh();
    expect($r->storage_types)->toBe(['gekuehlt', 'tiefgekuehlt'])->and($r->storage_type)->toBe('gekuehlt')->and((int) $r->shelf_life_frozen_days)->toBe(90);

    $res = app(ToolRegistry::class)->get('foodalchemist.recipes.PUT')->execute(['recipe_id' => $r->id, 'storage_types' => ['trocken'], 'shelf_life_dry_days' => 30, 'storage_type' => 'trocken'], new ToolContext($this->user, $this->rootTeam));
    expect($res->success)->toBeTrue()->and($r->refresh()->lagerarten())->toBe(['trocken'])->and((int) $r->shelf_life_dry_days)->toBe(30);
});

it('Spec 76: Druckprotokoll — Einzeldruck wird festgehalten (Vorschau nicht, Neuladen nicht doppelt), nochmal drucken', function () {
    $url = $this->svc->druckUrl('recipe', $this->suppe->id, null, ['menge' => '2 kg'], 3);
    $this->get($url . '&vorschau=1')->assertOk();
    expect(\Platform\FoodAlchemist\Models\FoodAlchemistLabelPrint::count())->toBe(0);
    $this->get($url)->assertOk();
    $this->get($url)->assertOk();                                               // Neuladen
    $p = \Platform\FoodAlchemist\Models\FoodAlchemistLabelPrint::sole();
    expect($p->bezeichnung)->toBe('Kürbissuppe')->and($p->anzahl)->toBe(3)->and($p->eingabe)->toBe(['menge' => '2 kg']);
    $v = $this->svc->verlauf($this->rootTeam);
    expect($v[0]['titel'])->toBe('Kürbissuppe')->and($v[0]['url'])->toContain('anzahl=3');
    Livewire::test(EtikettenIndex::class)->assertSee('Zuletzt gedruckt')->assertSee('Nochmal drucken');
});

it('Spec 76: Sammeldruck aus der Produktion — Basisrezepte je Ansatz, Auswahl, Protokoll als Gruppe, nochmal drucken', function () {
    $prod = app(\Platform\FoodAlchemist\Services\ProductionOrderService::class);
    $order = $prod->saveNew($this->rootTeam, '2026-10-20', 'Bankett', [['recipe_id' => $this->suppe->id, 'amount_kg' => 4, 'source_ref' => 'r:s']]);
    $pos = $this->svc->produktionPositionen($this->rootTeam, $order->id);
    $suppe = collect($pos)->firstWhere('id', $this->suppe->id);
    $zeile = $order->lines()->where('recipe_id', $this->suppe->id)->first();
    $mengeJe = rtrim(rtrim(number_format((float) $zeile->basis_yield_kg, 3, ',', ''), '0'), ',') . ' kg';
    expect($suppe['anzahl'])->toBe((int) ceil((float) $zeile->ansaetze_effektiv - 1e-9))         // je Ansatz ein Etikett
        ->and($suppe['eingabe'])->toMatchArray(['hergestellt_am' => '2026-10-20', 'menge' => $mengeJe]);

    $html = $this->get(route('foodalchemist.etiketten.produktion', ['order' => $order->id]))->assertOk()->getContent();
    expect(substr_count($html, 'Kürbissuppe'))->toBeGreaterThanOrEqual(2);
    $gruppe = \Platform\FoodAlchemist\Models\FoodAlchemistLabelPrint::whereNotNull('gruppe')->value('gruppe');
    expect($gruppe)->not->toBeNull()->and(\Platform\FoodAlchemist\Models\FoodAlchemistLabelPrint::where('gruppe', $gruppe)->value('production_order_id'))->toBe($order->id);

    // Auswahl: Suppe abgewählt → nur der Rest
    $lineId = $suppe['line_id'];
    // wie das Formular: Häkchen nur bei ausgewählten Zeilen, Anzahl/Menge immer
    $zeilen = collect($pos)->mapWithKeys(fn ($p) => [$p['line_id'] => ['anzahl' => 1, 'menge' => ''] + ($p['line_id'] === $lineId ? [] : ['an' => 1])])->all();
    $ohne = $this->get(route('foodalchemist.etiketten.produktion', ['order' => $order->id, 'zeilen' => $zeilen]));
    count($pos) > 1 ? $ohne->assertOk()->assertDontSee('Kürbissuppe') : $ohne->assertStatus(422);

    expect($this->svc->verlauf($this->rootTeam)[0]['titel'])->toContain('Sammeldruck');
    $this->get(route('foodalchemist.etiketten.gruppe', ['gruppe' => $gruppe]))->assertOk()->assertSee('Kürbissuppe');

    \Livewire\Livewire::test(\Platform\FoodAlchemist\Livewire\Produktion\Editor::class)->call('oeffnenBearbeiten', $order->id)
        ->assertSee('Etiketten für diesen Auftrag')->assertSee('data-produktion-etikett', false);
});

it('Hotfix: Produktionsauftrag mit Altbestand-Ziel ohne source_ref öffnet (stabile Ref), Menü hat „Etiketten drucken"', function () {
    $order = \Platform\FoodAlchemist\Models\FoodAlchemistProductionOrder::create(['team_id' => $this->rootTeam->id, 'name' => 'Altbestand', 'production_date' => '2026-09-05', 'status' => 'planned',
        'targets' => [['label' => 'Suppe (4 kg)', 'amount_kg' => 4, 'recipe_id' => $this->suppe->id]]]);
    $d1 = app(\Platform\FoodAlchemist\Services\ProductionOrderService::class)->detail($this->rootTeam, $order->id);
    $d2 = app(\Platform\FoodAlchemist\Services\ProductionOrderService::class)->detail($this->rootTeam, $order->id);
    expect($d1['targets'][0]['source_ref'])->toStartWith('ziel:')->toBe($d2['targets'][0]['source_ref']);
    app(\Platform\FoodAlchemist\Services\ProductionOrderService::class)->recomputeOrder($this->rootTeam, $order->refresh());
    \Livewire\Livewire::test(\Platform\FoodAlchemist\Livewire\Produktion\Editor::class)->call('oeffnenBearbeiten', $order->id)
        ->assertSee('Suppe (4 kg)')->assertSee('Etiketten drucken');
});

it('Spec 76b: Vorlage „Standard für den Wandmonitor" — eigene Küchen-Vorlage, sonst Standard', function () {
    expect($this->svc->kuechenVorlage($this->rootTeam)->id)->toBe($this->svc->vorlage($this->rootTeam, null)->id);
    $k = $this->svc->speichern($this->rootTeam, null, ['name' => 'Küche Rolle', 'format' => 'a4_24', 'is_kitchen_default' => true]);
    expect($this->svc->kuechenVorlage($this->rootTeam)->id)->toBe($k->id);
    $k2 = $this->svc->speichern($this->rootTeam, null, ['name' => 'Küche 2', 'is_kitchen_default' => true]);
    expect($k->refresh()->is_kitchen_default)->toBeFalse()->and($this->svc->kuechenVorlage($this->rootTeam)->id)->toBe($k2->id);
    Livewire::test(EtikettenSettings::class)->call('waehlen', $k2->id)->assertSet('form.is_kitchen_default', true)->assertSee('Standard für den Wandmonitor');
});
