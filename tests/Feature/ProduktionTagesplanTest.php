<?php

use Livewire\Livewire;
use Platform\FoodAlchemist\Enums\ProductionOrderStatus;
use Platform\FoodAlchemist\Livewire\Produktion\Tagesplan;
use Platform\FoodAlchemist\Models\FoodAlchemistProductionOrderLine as Line;
use Platform\FoodAlchemist\Models\FoodAlchemistProductionStation as Posten;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Services\ProductionOrderService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 30 E3 — Tagesplan. Die Sicht, die Vorproduktion überhaupt erst nützlich macht:
 * Zeilen aus MEHREREN Aufträgen an einem Tag, mit dem Auftrag als Kontext.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->svc = app(ProductionOrderService::class);
    $this->actingAs($this->makeUser($this->rootTeam, 'Küchenchef'));

    $this->fond = FoodAlchemistRecipe::create([
        'team_id' => $this->rootTeam->id, 'recipe_key' => 'fond', 'name' => 'Brauner Fond',
        'status' => 'approved', 'is_sales_recipe' => false, 'yield_kg' => 2.0, 'work_time_min' => 120,
    ]);
    $this->glace = FoodAlchemistRecipe::create([
        'team_id' => $this->rootTeam->id, 'recipe_key' => 'glace', 'name' => 'Glace de Viande',
        'status' => 'approved', 'is_sales_recipe' => false, 'yield_kg' => 1.0, 'work_time_min' => 90,
    ]);

    // Zwei Aufträge mit VERSCHIEDENEN Liefertagen, deren Vorproduktion auf denselben Tag fällt.
    $this->a1 = $this->svc->saveNew($this->rootTeam, '2026-08-20', 'Hochzeit Meyer', [
        ['source_ref' => 'r:fond', 'recipe_id' => $this->fond->id, 'amount_kg' => 2.0],
    ]);
    $this->a2 = $this->svc->saveNew($this->rootTeam, '2026-08-21', 'Tagung Schmitt', [
        ['source_ref' => 'r:glace', 'recipe_id' => $this->glace->id, 'amount_kg' => 1.0],
    ]);

    $this->plan = fn () => Livewire::test(Tagesplan::class, ['von' => '2026-08-15'])->set('modus', 'editor');
});

it('legt das Küchenleiter-Dashboard auf die Tagesplanung und verlinkt den Editor separat', function () {
    Livewire::test(Tagesplan::class, ['von' => '2026-08-15'])
        ->assertSeeHtml('data-tagesplanung-dashboard')
        ->assertSeeHtml('data-tagesplanung-sidebar')
        ->assertSeeHtml('activity_tagesplan')
        ->assertSee('Küchenleiter-Dashboard')
        ->assertSeeHtml('data-tagesplan-editor-link')
        ->assertSeeHtml('data-modal="tagesplan-editor"')
        ->assertSeeHtml('data-tagesplan-editor');
});

it('führt Zeilen mehrerer Aufträge an einem Tag zusammen — mit Auftrag und Liefertag als Kontext', function () {
    $l1 = Line::where('production_order_id', $this->a1->id)->firstOrFail();
    $l2 = Line::where('production_order_id', $this->a2->id)->firstOrFail();
    $this->svc->assignLine($this->rootTeam, $l1->id, ['vorlauf_tage' => 2]);   // 20.08. − 2 = 18.08.
    $this->svc->assignLine($this->rootTeam, $l2->id, ['vorlauf_tage' => 3]);   // 21.08. − 3 = 18.08.

    ($this->plan)()->set('tage', 14)
        ->assertSeeHtml('data-tagesplan-tag="2026-08-18"')
        ->assertSee('Brauner Fond')->assertSee('Glace de Viande')
        ->assertSee('Hochzeit Meyer')->assertSee('Tagung Schmitt')
        ->assertSee('für 20.08.')->assertSee('für 21.08.');
});

it('zeigt Auslastung mit Balken nur dort, wo eine Kapazität hinterlegt ist', function () {
    $mit = Posten::create(['team_id' => $this->rootTeam->id, 'slug' => 'mit', 'name' => 'Warme Küche', 'kapazitaet_min_pro_tag' => 480]);
    $ohne = Posten::create(['team_id' => $this->rootTeam->id, 'slug' => 'ohne', 'name' => 'Kalte Küche']);

    $this->svc->assignLine($this->rootTeam, Line::where('production_order_id', $this->a1->id)->value('id'), ['station_id' => $mit->id]);
    $this->svc->assignLine($this->rootTeam, Line::where('production_order_id', $this->a2->id)->value('id'), ['station_id' => $ohne->id]);

    // Die View zeigt den Auslastungs-Block; die Kapazitäts-SEMANTIK wird unten am Service
    // geprüft statt am gerenderten Zahlen-String — sonst hinge der Test an Whitespace.
    ($this->plan)()->set('tage', 14)
        ->assertSee('Warme Küche')->assertSee('Kalte Küche')
        ->assertSeeHtml('data-tagesplan-auslastung');

    $auslastung = app(\Platform\FoodAlchemist\Services\ProductionCapacityService::class)
        ->auslastung($this->rootTeam, '2026-08-15', '2026-08-28');
    $buckets = collect($auslastung)->flatten(1);

    expect($buckets->firstWhere('station_id', $mit->id)['kapazitaet_min'])->toBe(480)
        ->and($buckets->firstWhere('station_id', $ohne->id)['kapazitaet_min'])->toBeNull()
        ->and($buckets->firstWhere('station_id', $ohne->id)['stufe'])->toBe('ohne_kapazitaet');
});

it('meldet Überlast am Tag, aber blockiert nichts', function () {
    $eng = Posten::create(['team_id' => $this->rootTeam->id, 'slug' => 'eng', 'name' => 'Patisserie', 'kapazitaet_min_pro_tag' => 60]);
    $this->svc->assignLine($this->rootTeam, Line::where('production_order_id', $this->a1->id)->value('id'), ['station_id' => $eng->id]);

    ($this->plan)()->set('tage', 14)->assertSee('Überlast')->assertSee('200 %');
});

it('verschiebt eine Zeile über den Vorlauf auf einen anderen Tag', function () {
    $l = Line::where('production_order_id', $this->a1->id)->firstOrFail();

    ($this->plan)()->set('tage', 14)->call('vorlaufSetzen', $l->id, '4')->assertSet('fehler', null);

    expect($l->fresh()->plan_date->toDateString())->toBe('2026-08-16');
});

it('filtert auf einen Posten und wieder zurück', function () {
    $p = Posten::create(['team_id' => $this->rootTeam->id, 'slug' => 'wk', 'name' => 'Warme Küche']);
    $this->svc->assignLine($this->rootTeam, Line::where('production_order_id', $this->a1->id)->value('id'), ['station_id' => $p->id]);

    ($this->plan)()->set('tage', 14)
        ->call('postenWaehlen', $p->id)
        ->assertSet('postenFilter', $p->id)
        ->assertSee('Brauner Fond')
        ->assertDontSee('Glace de Viande')          // hängt an keinem Posten
        ->call('postenWaehlen', $p->id)             // zweiter Klick hebt auf
        ->assertSet('postenFilter', null)
        ->assertSee('Glace de Viande');
});

it('blendet erledigte und stornierte Aufträge aus', function () {
    $this->svc->setStatus($this->rootTeam, $this->a1->id, ProductionOrderStatus::InProgress);
    // Spec 35 K4: Fertigmelden mit offenen Zeilen braucht eine Abschlussnotiz.
    $this->svc->setStatus($this->rootTeam, $this->a1->id, ProductionOrderStatus::Done, ['finish_note' => 'Testabschluss.']);

    ($this->plan)()->set('tage', 14)
        ->assertDontSee('Hochzeit Meyer')
        ->assertSee('Tagung Schmitt');
});

it('sagt im Leerzustand, was der Tagesplan überhaupt zeigt', function () {
    Livewire::test(Tagesplan::class, ['von' => '2027-01-01'])
        ->set('modus', 'editor')
        ->assertSeeHtml('data-tagesplan-leer')
        ->assertSee('In diesem Zeitraum steht nichts an');
});

it('das Zeitfenster steht in der URL — Kontext überlebt einen Reload', function () {
    // Spec 30 E8: orderId (Detail-Panel) + display (Wandmodus) sind ebenfalls URL-Zustand,
    // damit ein Reload / geteilter Link die gewählte Ausgabe wiederherstellt.
    expect(collect((new ReflectionClass(Tagesplan::class))->getProperties())
        ->filter(fn ($p) => $p->getAttributes(\Livewire\Attributes\Url::class) !== [])
        ->map(fn ($p) => $p->getName())->values()->all())
        ->toBe(['von', 'bis', 'tage', 'postenFilter', 'orderId', 'selectedDay', 'display', 'ansicht']);
});

// ── Spec 30 E8: Tages-Ausgabe (3-Panel, Wandmodus, Posten-Blatt) ──────────────

it('wählt einen Auftrag ins Detail-Panel und wieder ab', function () {
    ($this->plan)()->set('tage', 14)
        ->call('waehleAuftrag', $this->a1->id)->assertSet('orderId', $this->a1->id)
        ->call('waehleAuftrag', $this->a1->id)->assertSet('orderId', null);   // zweiter Klick schließt
});

it('wählt einen Tag ins rechte Detail-Panel und zeigt dessen Speisen', function () {
    $l = Line::where('production_order_id', $this->a1->id)->firstOrFail();
    $this->svc->assignLine($this->rootTeam, $l->id, ['vorlauf_tage' => 2]);   // 20.08. − 2 = 18.08.

    ($this->plan)()->set('tage', 14)
        ->call('waehleTag', '2026-08-18')
        ->assertSet('selectedDay', '2026-08-18')
        ->assertSeeHtml('data-tagesplanung-tagdetail')
        ->assertSee('Brauner Fond')
        ->call('waehleTag', '2026-08-18')
        ->assertSet('selectedDay', null);
});

it('rendert den Wandmodus ohne zu krachen', function () {
    // Spec 35: Der Wandmonitor ist ein eigenes Küchenmonitor-Layout (nicht mehr die Editor-Ansicht).
    Livewire::test(Tagesplan::class, ['von' => '2026-08-20', 'display' => 'wall'])
        ->set('modus', 'editor')
        ->assertOk()
        ->assertSee('Brauner Fond')
        ->assertSeeHtml('data-tagesplan-wall')
        ->assertSeeHtml('data-tagesplan-wall-kiosk')
        ->assertSeeHtml('data-tagesplan-wall-zurueck')
        ->assertSee('Zurück')
        ->assertSeeHtml('data-tagesplan-wall-fullscreen')
        ->assertSeeHtml('wire:poll.30s')
        ->assertSeeHtml('data-tagesplan-lanes')
        ->assertSeeHtml('data-tagesplan-wall-karte')
        ->assertSeeHtml('data-tagesplan-abhaken')
        ->assertSee('Startet beim Abhaken')
        ->assertSeeHtml('data-tagesplan-wall-mise')     // Mise-en-Place-Umschalter da (Wunsch #3)
        ->assertDontSeeHtml('data-tagesplanung-sidebar');
});

it('startet im Wandmodus geplante Aufträge beim Abhaken und setzt die Zeile erledigt', function () {
    $line = Line::where('production_order_id', $this->a1->id)->firstOrFail();

    Livewire::test(Tagesplan::class, ['von' => '2026-08-20', 'display' => 'wall'])
        ->set('modus', 'editor')
        ->call('abhaken', $line->id)
        ->assertSet('fehler', null);

    $neu = Line::where('production_order_id', $this->a1->id)->firstOrFail();

    expect($this->a1->fresh()->status)->toBe(ProductionOrderStatus::InProgress)
        ->and($neu->line_status)->toBe(\Platform\FoodAlchemist\Enums\ProductionLineStatus::Done);
});

it('trennt im Wandmonitor zusammengesetzte Gerichts- und Rezeptnamen untereinander', function () {
    $this->fond->update(['name' => '[FIN] Curry-Hummus | Quinoa-Minz-Salat']);

    Livewire::test(Tagesplan::class, ['von' => '2026-08-20', 'display' => 'wall'])
        ->set('modus', 'editor')
        ->assertSeeHtml('data-tagesplan-wall-gericht')
        ->assertSeeHtml('data-tagesplan-wall-rezept')
        ->assertSee('Gericht')
        ->assertSee('Basisrezept')
        ->assertSee('[FIN] Curry-Hummus')
        ->assertSee('Quinoa-Minz-Salat');
});

it('gruppiert im Wandmonitor Gericht und Basisrezepte als Küchen-Arbeitsblock', function () {
    $haupt = FoodAlchemistRecipe::create([
        'team_id' => $this->rootTeam->id, 'recipe_key' => 'tomate-burrata', 'name' => '[VOR] Tomate-Burrata | Anrichten',
        'status' => 'approved', 'is_sales_recipe' => true, 'yield_kg' => 1.0, 'work_time_min' => 30,
    ]);
    $basis = FoodAlchemistRecipe::create([
        'team_id' => $this->rootTeam->id, 'recipe_key' => 'basilikum-schaum', 'name' => '[VOR] Tomate-Burrata | Basilikum-Schaum',
        'status' => 'approved', 'is_sales_recipe' => false, 'yield_kg' => 1.0, 'work_time_min' => 45,
    ]);

    $auftrag = $this->svc->saveNew($this->rootTeam, '2026-08-20', 'Probe Küche', [
        ['source_ref' => 'r:tomate-burrata', 'recipe_id' => $haupt->id, 'amount_kg' => 1.0],
        ['source_ref' => 'r:basilikum-schaum', 'recipe_id' => $basis->id, 'amount_kg' => 1.0],
    ]);
    Line::where('production_order_id', $auftrag->id)
        ->where('recipe_id', $basis->id)
        ->update(['is_basisrezept' => true]);

    Livewire::test(Tagesplan::class, ['von' => '2026-08-20', 'display' => 'wall'])
        ->set('modus', 'editor')
        ->assertSeeHtml('data-tagesplan-wall-gericht-gruppe')
        ->assertSeeHtml('data-tagesplan-wall-rezepte')
        ->assertSeeHtml('data-tagesplan-wall-rezept')
        ->assertSeeHtml('data-tagesplan-abhaken')
        ->assertSeeHtml('data-tagesplan-wall-karte')
        ->assertSee('Probe Küche')
        ->assertSeeInOrder([
            'Gericht',
            '[VOR] Tomate-Burrata',
            'Anrichten',
            'Basisrezept',
            'Basilikum-Schaum',
        ]);
});

it('zeigt im Wandmonitor Allergen-, Diät- und Datenqualitätswarnungen', function () {
    $this->fond->forceFill([
        'allergens_confidence' => 'low',
        'allergen_milk' => 'enthalten',
        'allergen_sesame' => 'spuren',
        'spec_is_vegetarian' => true,
        'spec_contains_pork' => true,
    ])->save();
    $lineId = Line::where('production_order_id', $this->a1->id)->value('id');

    Livewire::test(Tagesplan::class, ['von' => '2026-08-20', 'display' => 'wall'])
        ->set('modus', 'editor')
        ->assertSeeHtml('data-tagesplan-wall-sicherheit')
        ->assertSeeHtml('data-tagesplan-wall-allergen')
        ->assertSeeHtml('data-tagesplan-wall-warnung')
        ->assertSeeHtml('data-tagesplan-wall-diaet')
        ->assertSee('Milch')
        ->assertSee('Sesam')
        ->assertSee('Allergene unsicher')
        ->assertSee('Schwein')
        ->assertSee('vegetarisch')
        ->call('oeffneAnleitung', $lineId)
        ->assertSeeHtml('data-tagesplan-wall-sicherheitsblock')
        ->assertSee('Sicherheit');
});

it('fokussiert im Wandmonitor einen Posten und bietet den Reset auf alle Stationen an', function () {
    $p = Posten::create(['team_id' => $this->rootTeam->id, 'slug' => 'wk', 'name' => 'Warme Küche']);
    $this->svc->assignLine($this->rootTeam, Line::where('production_order_id', $this->a1->id)->value('id'), ['station_id' => $p->id]);

    Livewire::test(Tagesplan::class, ['von' => '2026-08-20', 'display' => 'wall', 'postenFilter' => $p->id])
        ->set('modus', 'editor')
        ->assertSeeHtml('data-tagesplan-wall-station-filter')
        ->assertSeeHtml('data-tagesplan-wall-station-reset')
        ->assertSeeHtml('data-tagesplan-wall-single-lane')
        ->assertSee('Warme Küche')
        ->assertSee('Brauner Fond')
        ->assertDontSee('Glace de Viande');
});

it('zeigt im leeren Wandmodus trotzdem den gewählten Tag und den Zurück-Button', function () {
    Livewire::test(Tagesplan::class, ['von' => '2027-01-01', 'display' => 'wall'])
        ->set('modus', 'editor')
        ->assertSee('Freitag, 1. Januar')
        ->assertSeeHtml('data-tagesplan-wall-zurueck')
        ->assertDontSee('Kein Tag geplant');
});

it('öffnet im Wandmodus die touchfreundliche Anleitung mit Medienbereich', function () {
    $lineId = Line::where('production_order_id', $this->a1->id)->value('id');
    Line::whereKey($lineId)->update(['zubereitung' => "## Mise en Place\n1. Fonds erhitzen.\n2. Abschmecken und bereitstellen."]);

    Livewire::test(Tagesplan::class, ['von' => '2026-08-20', 'display' => 'wall'])
        ->set('modus', 'editor')
        ->call('oeffneAnleitung', $lineId)
        ->assertSet('anleitungLineId', $lineId)
        ->assertSeeHtml('data-tagesplan-wall-anleitung')
        ->assertSeeHtml('data-tagesplan-wall-anleitung-zurueck')
        ->assertSeeHtml('anleitungSchliessen(); close()')
        ->assertSeeHtml('data-tagesplan-wall-media')
        ->assertSeeHtml('data-tagesplan-wall-fallback-schritte')
        ->assertSee('Schritte')
        ->assertSee('Medien');
});

it('zeigt Zutaten in der Wandmonitor-Anleitung immer untereinander', function () {
    $lineId = Line::where('production_order_id', $this->a1->id)->value('id');
    Line::whereKey($lineId)->update(['zutaten' => [
        ['name' => 'Hummus Mango', 'menge' => '1.2', 'einheit' => 'kg'],
        ['name' => 'Salat: Quinoa Mango Peppadew', 'menge' => '2.8', 'einheit' => 'kg'],
        ['name' => 'Deko: Sesam', 'menge' => '0', 'einheit' => 'kg'],
    ]]);

    Livewire::test(Tagesplan::class, ['von' => '2026-08-20', 'display' => 'wall'])
        ->set('modus', 'editor')
        ->call('oeffneAnleitung', $lineId)
        ->assertSeeHtml('data-tagesplan-wall-zutatenliste')
        ->assertSeeHtml('data-tagesplan-wall-zutat')
        ->assertDontSeeHtml('mt-3 grid grid-cols-1 gap-2 md:grid-cols-2 xl:grid-cols-3')
        ->assertSee('Hummus Mango')
        ->assertSee('Salat: Quinoa Mango Peppadew')
        ->assertSee('Deko: Sesam');
});

it('zeigt im Wandmodus einen klaren Leerzustand statt leerer Anleitung', function () {
    $lineId = Line::where('production_order_id', $this->a1->id)->value('id');

    Livewire::test(Tagesplan::class, ['von' => '2026-08-20', 'display' => 'wall'])
        ->set('modus', 'editor')
        ->call('oeffneAnleitung', $lineId)
        ->assertSet('anleitungLineId', $lineId)
        ->assertSeeHtml('data-tagesplan-wall-anleitung-leer')
        ->assertSee('Keine Anleitung hinterlegt.');
});

it('fasst gleiche Komponenten über Gerichte zusammen (Mise en Place)', function () {
    // Zwei Aufträge, dieselbe Komponente „Brauner Fond" → EINE Mise-en-Place-Karte, 2×.
    $this->svc->saveNew($this->rootTeam, '2026-08-20', 'Zweite Hochzeit', [
        ['source_ref' => 'r:fond', 'recipe_id' => $this->fond->id, 'amount_kg' => 2.0],
    ]);

    Livewire::test(Tagesplan::class, ['von' => '2026-08-20', 'display' => 'wall'])
        ->set('modus', 'editor')
        ->call('wallAnsichtSetzen', 'mise')
        ->assertSet('wallAnsicht', 'mise')
        ->assertSeeHtml('data-tagesplan-mise')
        ->assertSee('Brauner Fond')
        ->assertSee('2×');
});

it('hakt im Wandmodus eine Mise-en-Place-Gruppe gesammelt ab und nimmt sie wieder zurück', function () {
    $this->svc->saveNew($this->rootTeam, '2026-08-20', 'Zweite Hochzeit', [
        ['source_ref' => 'r:fond', 'recipe_id' => $this->fond->id, 'amount_kg' => 2.0],
    ]);
    $line = Line::where('recipe_id', $this->fond->id)->firstOrFail();

    Livewire::test(Tagesplan::class, ['von' => '2026-08-20', 'display' => 'wall'])
        ->set('modus', 'editor')
        ->call('wallAnsichtSetzen', 'mise')
        ->assertSeeHtml('data-tagesplan-mise-abhaken')
        ->call('abhakenMise', $line->id)
        ->assertSet('fehler', null);

    expect(Line::where('recipe_id', $this->fond->id)->pluck('line_status')->all())
        ->each->toBe(\Platform\FoodAlchemist\Enums\ProductionLineStatus::Done);

    Livewire::test(Tagesplan::class, ['von' => '2026-08-20', 'display' => 'wall'])
        ->set('modus', 'editor')
        ->call('wallAnsichtSetzen', 'mise')
        ->call('abhakenMise', Line::where('recipe_id', $this->fond->id)->value('id'))
        ->assertSet('fehler', null);

    expect(Line::where('recipe_id', $this->fond->id)->pluck('line_status')->all())
        ->each->toBe(\Platform\FoodAlchemist\Enums\ProductionLineStatus::Open);
});

it('druckt ein Posten-Blatt über alle Aufträge des Fensters', function () {
    $this->get(route('foodalchemist.produktion.tagesplan.blatt', ['von' => '2026-08-18', 'tage' => 14]))
        ->assertOk()
        ->assertSeeHtml('data-tagesplan-blatt')
        ->assertSee('Brauner Fond')->assertSee('Glace de Viande')
        ->assertSee('Hochzeit Meyer');
});

it('respektiert den Posten-Filter im Posten-Blatt', function () {
    $p = Posten::create(['team_id' => $this->rootTeam->id, 'slug' => 'wk', 'name' => 'Warme Küche']);
    $this->svc->assignLine($this->rootTeam, Line::where('production_order_id', $this->a1->id)->value('id'), ['station_id' => $p->id]);

    $this->get(route('foodalchemist.produktion.tagesplan.blatt', ['von' => '2026-08-18', 'tage' => 14, 'posten' => $p->id]))
        ->assertOk()
        ->assertSee('Brauner Fond')
        ->assertDontSee('Glace de Viande');       // hängt an keinem Posten
});
