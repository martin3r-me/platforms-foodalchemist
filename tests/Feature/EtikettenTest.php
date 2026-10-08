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

it('Seite: Rezept wählen, Haltbarkeit am Rezept speichern, Druck-Link; Einstellungen: Vorlage speichern', function () {
    Livewire::test(EtikettenIndex::class)
        ->set('suchArt', 'recipe')->set('suche', 'Kürbis')->assertSee('Suppe: Kürbissuppe')
        ->call('waehlen', 'recipe', $this->suppe->id)
        ->assertSet('haltbarGekuehlt', '3')
        ->assertSee('Etikett-Vorschau')
        ->set('haltbarGekuehlt', '4')->call('haltbarkeitSpeichern')->assertSet('fehler', null);
    expect($this->suppe->refresh()->shelf_life_chilled_days)->toBe(4);

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
