<?php

use Livewire\Livewire;
use Platform\FoodAlchemist\Livewire\Speisekarte\Index as SpeisekarteIndex;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Models\FoodAlchemistSpeisekarte;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Speisekarte-Editor (Stufe A) — Livewire-Smoke: anlegen, Rubrik, Gericht-Position
 * über den Picker, Voll-Page-Render gegen platform::layouts.app.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->user = $this->makeUser($this->rootTeam);
    $this->actingAs($this->user);

    $this->gericht = FoodAlchemistRecipe::create([
        'team_id' => $this->rootTeam->id, 'recipe_key' => 'skui1', 'name' => 'Wiener Schnitzel', 'status' => 'approved',
        'is_sales_recipe' => true, 'sales_net' => 18.90, 'ek_total_eur' => 6.00,
    ]);
});

it('Speisekarte-Editor: anlegen, Rubrik, Gericht-Position über Picker', function () {
    Livewire::test(SpeisekarteIndex::class)->assertOk()->call('neu');
    $karte = FoodAlchemistSpeisekarte::first();
    expect($karte)->not->toBeNull();

    $comp = Livewire::test(SpeisekarteIndex::class)
        ->call('waehle', $karte->id)
        ->set('name', 'Abendkarte')
        ->set('kartenTyp', 'alacarte')
        ->call('speichern')
        ->set('neueRubrik', 'Hauptgänge')
        ->call('rubrikNeu');

    $rubrik = $karte->sections()->first();
    expect($rubrik)->not->toBeNull()->and($rubrik->title)->toBe('Hauptgänge');

    $comp->call('pickerOeffnen', $rubrik->id)
        ->set('pickerSuche', 'Schnitzel')
        ->call('positionAusGericht', $rubrik->id, $this->gericht->id)
        ->assertOk()
        ->assertSee('Wiener Schnitzel')
        ->assertSee('18,90')
        // Rechtes Detail-Panel (read-only Info): Blöcke + Eckdaten der Auswahl
        ->assertSee('Eckdaten')
        ->assertSee('Kartentyp')
        ->assertSee('Erstellt');

    expect($rubrik->items()->count())->toBe(1);
    expect($karte->refresh()->name)->toBe('Abendkarte');
});

// ── Werkstrang M Phase A (Spec 40 §6): Kontext-Leitplanken ────────────────────

it('Phase A: Kontext-Leitplanken werden gesetzt + persistiert (waehle hydriert, speichern schreibt)', function () {
    $ws = \Platform\FoodAlchemist\Models\FoodAlchemistWritingStyle::create([
        'team_id' => $this->rootTeam->id, 'slug' => 'nuechtern', 'name' => 'Nüchtern', 'sprach_duktus' => 'sachlich',
    ]);
    Livewire::test(SpeisekarteIndex::class)->call('neu');
    $karte = FoodAlchemistSpeisekarte::first();

    Livewire::test(SpeisekarteIndex::class)
        ->call('waehle', $karte->id)
        ->set('kundentyp', 'Business-Lunch')
        ->set('niveau', 'gehoben')
        ->set('convenience', 'teil_convenience')
        ->set('writingStyleId', $ws->id)
        ->call('speichern')
        ->assertOk();

    $karte->refresh();
    expect($karte->kundentyp)->toBe('Business-Lunch')
        ->and($karte->default_niveau)->toBe('gehoben')
        ->and($karte->default_convenience)->toBe('teil_convenience')
        ->and((int) $karte->writing_style_id)->toBe((int) $ws->id);

    // Rück-Hydration: waehle lädt die Leitplanken wieder in die Properties.
    Livewire::test(SpeisekarteIndex::class)
        ->call('waehle', $karte->id)
        ->assertSet('kundentyp', 'Business-Lunch')
        ->assertSet('niveau', 'gehoben')
        ->assertSet('writingStyleId', $ws->id);
});

// ── Werkstrang M Phase B (Spec 40 §6): reicher Gericht-Picker (Facetten + offen bleiben) ──────

it('Phase B: Gericht-Picker filtert per Facette + bleibt nach dem Einfügen offen', function () {
    $hg = \Platform\FoodAlchemist\Models\FoodAlchemistDishMainGroup::create(['team_id' => $this->rootTeam->id, 'code' => 'HG', 'label' => 'Hauptgericht']);
    $klFleisch = \Platform\FoodAlchemist\Models\FoodAlchemistDishClass::create(['team_id' => $this->rootTeam->id, 'dish_main_group_id' => $hg->id, 'code' => 'HG_F', 'label' => 'Fleisch', 'diet_form' => 'fleisch']);
    $klVeg = \Platform\FoodAlchemist\Models\FoodAlchemistDishClass::create(['team_id' => $this->rootTeam->id, 'dish_main_group_id' => $hg->id, 'code' => 'HG_V', 'label' => 'Vegetarisch', 'diet_form' => 'vegetarisch']);
    $rind = FoodAlchemistRecipe::create(['team_id' => $this->rootTeam->id, 'recipe_key' => 'pb1', 'name' => 'Rinderbraten', 'status' => 'approved', 'is_sales_recipe' => true, 'sales_net' => 22.00, 'dish_main_group_id' => $hg->id, 'dish_class_id' => $klFleisch->id]);
    FoodAlchemistRecipe::create(['team_id' => $this->rootTeam->id, 'recipe_key' => 'pb2', 'name' => 'Gemüsestrudel', 'status' => 'approved', 'is_sales_recipe' => true, 'sales_net' => 16.00, 'dish_main_group_id' => $hg->id, 'dish_class_id' => $klVeg->id]);

    Livewire::test(SpeisekarteIndex::class)->call('neu');
    $karte = FoodAlchemistSpeisekarte::first();
    $comp = Livewire::test(SpeisekarteIndex::class)
        ->call('waehle', $karte->id)
        ->set('neueRubrik', 'Hauptgänge')->call('rubrikNeu');
    $rubrik = $karte->sections()->first();

    $comp->call('pickerOeffnen', $rubrik->id, 'gericht')
        ->assertSee('Rinderbraten')->assertSee('Gemüsestrudel')        // ohne Facette: beide
        ->call('pickerWaehleHg', $hg->id)
        ->call('pickerWaehleKlasse', $klFleisch->id)
        ->assertSet('pickerDishClass', $klFleisch->id)
        ->assertSee('Rinderbraten')->assertDontSee('Gemüsestrudel');   // Fleisch-Facette filtert

    // „+ bleibt offen": nach dem Einfügen ist der Picker + die Facette noch aktiv.
    $comp->call('positionAusGericht', $rubrik->id, $rind->id)
        ->assertSet('pickerRubrikId', $rubrik->id)
        ->assertSet('pickerDishClass', $klFleisch->id);
    expect($rubrik->items()->count())->toBe(1);
});

// ── Werkstrang M Phase C (Spec 40 §6): Umsortieren + Verschieben ──────────────

it('Phase C: Position hoch/runter, in andere Rubrik verschieben, Rubrik hoch/runter', function () {
    $svc = app(\Platform\FoodAlchemist\Services\SpeisekarteService::class);
    $karte = $svc->create($this->rootTeam, ['name' => 'Karte C']);
    $rA = $svc->addRubrik($this->rootTeam, $karte->id, ['title' => 'Rubrik A']);
    $rB = $svc->addRubrik($this->rootTeam, $karte->id, ['title' => 'Rubrik B']);
    $p1 = $svc->addPosition($this->rootTeam, $rA->id, ['type' => 'header', 'label' => 'P1']);
    $p2 = $svc->addPosition($this->rootTeam, $rA->id, ['type' => 'header', 'label' => 'P2']);

    $comp = Livewire::test(SpeisekarteIndex::class)->call('waehle', $karte->id);

    // Position hoch: P2 nach oben → Reihenfolge P2, P1
    $comp->call('positionHochRunter', $p2->id, 'hoch');
    $order = \Platform\FoodAlchemist\Models\FoodAlchemistSpeisekartePosition::where('section_id', $rA->id)
        ->orderBy('position')->pluck('id')->all();
    expect($order)->toBe([$p2->id, $p1->id]);

    // Position verschieben: P1 → Rubrik B (ans Ende)
    $comp->call('positionInRubrik', $p1->id, $rB->id);
    expect($p1->refresh()->section_id)->toBe($rB->id);

    // Rubrik hoch: B nach oben → B, A
    $comp->call('rubrikHochRunter', $rB->id, 'hoch');
    $rubOrder = \Platform\FoodAlchemist\Models\FoodAlchemistSpeisekarteRubrik::where('menu_card_id', $karte->id)
        ->whereNull('parent_id')->orderBy('position')->pluck('id')->all();
    expect($rubOrder)->toBe([$rB->id, $rA->id]);
});

it('Phase C: movePosition über Karten-Grenze wirft (kein Cross-Card-Move)', function () {
    $svc = app(\Platform\FoodAlchemist\Services\SpeisekarteService::class);
    $k1 = $svc->create($this->rootTeam, ['name' => 'K1']);
    $k2 = $svc->create($this->rootTeam, ['name' => 'K2']);
    $r1 = $svc->addRubrik($this->rootTeam, $k1->id, ['title' => 'R1']);
    $r2 = $svc->addRubrik($this->rootTeam, $k2->id, ['title' => 'R2']);
    $p = $svc->addPosition($this->rootTeam, $r1->id, ['type' => 'header', 'label' => 'P']);

    expect(fn () => $svc->movePosition($this->rootTeam, $p->id, $r2->id))
        ->toThrow(RuntimeException::class, 'anderen Karte');
    expect($p->refresh()->section_id)->toBe($r1->id);
});

// ── Werkstrang M Phase D (Spec 40 §6): Layout-Blöcke + Wahl-Gruppen ───────────

it('Phase D: Layout-Block einfügen + Wahl-Gruppe setzen + dokumentDaten trägt variant_group_id', function () {
    $svc = app(\Platform\FoodAlchemist\Services\SpeisekarteService::class);
    $karte = $svc->create($this->rootTeam, ['name' => 'Karte D']);
    $r = $svc->addRubrik($this->rootTeam, $karte->id, ['title' => 'Rubrik']);
    $comp = Livewire::test(SpeisekarteIndex::class)->call('waehle', $karte->id);

    // Layout-Blöcke einfügen (header + spacer)
    $comp->call('layoutBlockNeu', $r->id, 'header')
        ->call('layoutBlockNeu', $r->id, 'spacer');
    $typen = \Platform\FoodAlchemist\Models\FoodAlchemistSpeisekartePosition::where('section_id', $r->id)
        ->orderBy('position')->pluck('type')->all();
    expect($typen)->toBe(['header', 'spacer']);

    // Wahl-Gruppe auf ein Gericht setzen
    $p = $svc->addPosition($this->rootTeam, $r->id, ['type' => 'gericht_ref', 'sales_recipe_id' => $this->gericht->id]);
    $comp->call('positionBearbeiten', $p->id)
        ->set('editVariantGroupId', 3)
        ->call('positionSpeichern');
    expect((int) $p->refresh()->variant_group_id)->toBe(3);

    // dokumentDaten trägt variant_group_id (daten-fertig für den Renderer)
    $doc = $svc->dokumentDaten($this->rootTeam, $karte->refresh());
    $posDaten = collect($doc['rubriken'])->firstWhere('id', $r->id)['positionen'] ?? [];
    $gerichtPos = collect($posDaten)->firstWhere('typ', 'gericht_ref');
    expect($gerichtPos['variant_group_id'] ?? null)->toBe(3);
});

// ── Werkstrang M Phase E (Spec 40 §6): Planungshilfe „was fehlt" ──────────────

it('Phase E: Leitstelle zeigt Checkliste immer, Coverage nur bei Planungs-Gerüst', function () {
    $svc = app(\Platform\FoodAlchemist\Services\SpeisekarteService::class);
    $karte = $svc->create($this->rootTeam, ['name' => 'Karte E']);

    // Ohne Gerüst: Checkliste ja, Coverage-Panel nein (kein Frame-Zwang).
    Livewire::test(\Platform\FoodAlchemist\Livewire\Speisekarte\LeitstelleRail::class, ['karteId' => $karte->id])
        ->assertOk()
        ->assertSee('Was fehlt der Karte noch?')
        ->assertDontSee('Soll/Ist-Coverage');

    // Mit Gerüst (Frame + Slot): Coverage-Panel erscheint.
    $frames = app(\Platform\FoodAlchemist\Services\PlanningFrameService::class);
    $frame = $frames->frameFor($this->rootTeam, 'speisekarte', $karte->id);
    $frames->addSlot($this->rootTeam, $frame, ['label' => 'Vorspeisen']);

    Livewire::test(\Platform\FoodAlchemist\Livewire\Speisekarte\LeitstelleRail::class, ['karteId' => $karte->id])
        ->assertOk()
        ->assertSee('Soll/Ist-Coverage');
});

// ── Werkstrang M UX-Ausbau: Drag & Drop (positionAblegen / rubrikAblegen) ─────

it('UX D&D: positionAblegen sortiert in der Rubrik + verschiebt zwischen Rubriken', function () {
    $svc = app(\Platform\FoodAlchemist\Services\SpeisekarteService::class);
    $karte = $svc->create($this->rootTeam, ['name' => 'DnD']);
    $rA = $svc->addRubrik($this->rootTeam, $karte->id, ['title' => 'A']);
    $rB = $svc->addRubrik($this->rootTeam, $karte->id, ['title' => 'B']);
    $p1 = $svc->addPosition($this->rootTeam, $rA->id, ['type' => 'header', 'label' => 'P1']);
    $p2 = $svc->addPosition($this->rootTeam, $rA->id, ['type' => 'header', 'label' => 'P2']);
    $p3 = $svc->addPosition($this->rootTeam, $rA->id, ['type' => 'header', 'label' => 'P3']);
    $comp = Livewire::test(SpeisekarteIndex::class)->call('waehle', $karte->id);

    // p3 VOR p1 ablegen → [p3, p1, p2]
    $comp->call('positionAblegen', $p3->id, $p1->id);
    expect(\Platform\FoodAlchemist\Models\FoodAlchemistSpeisekartePosition::where('section_id', $rA->id)
        ->orderBy('position')->pluck('id')->all())->toBe([$p3->id, $p1->id, $p2->id]);

    // p1 aus rA auf eine Position in rB ablegen → p1 wandert nach rB, VOR pB
    $pB = $svc->addPosition($this->rootTeam, $rB->id, ['type' => 'header', 'label' => 'PB']);
    $comp->call('positionAblegen', $p1->id, $pB->id);
    expect($p1->refresh()->section_id)->toBe($rB->id);
    expect(\Platform\FoodAlchemist\Models\FoodAlchemistSpeisekartePosition::where('section_id', $rB->id)
        ->orderBy('position')->pluck('id')->all())->toBe([$p1->id, $pB->id]);
});

it('UX D&D: rubrikAblegen sortiert Rubriken derselben Ebene', function () {
    $svc = app(\Platform\FoodAlchemist\Services\SpeisekarteService::class);
    $karte = $svc->create($this->rootTeam, ['name' => 'DnDR']);
    $rA = $svc->addRubrik($this->rootTeam, $karte->id, ['title' => 'A']);
    $rB = $svc->addRubrik($this->rootTeam, $karte->id, ['title' => 'B']);
    $rC = $svc->addRubrik($this->rootTeam, $karte->id, ['title' => 'C']);

    Livewire::test(SpeisekarteIndex::class)->call('waehle', $karte->id)
        ->call('rubrikAblegen', $rC->id, $rA->id);   // rC VOR rA → [rC, rA, rB]

    expect(\Platform\FoodAlchemist\Models\FoodAlchemistSpeisekarteRubrik::where('menu_card_id', $karte->id)
        ->whereNull('parent_id')->orderBy('position')->pluck('id')->all())->toBe([$rC->id, $rA->id, $rB->id]);
});

// Picker-Umbau + Konzept/Paket-Split (Dominique 2026-08-27): permanenter Katalog rechts mit VIER Modi
// (gericht|konzept|paket|format) — „Menü ist eigentlich Concept, Paket fehlt noch". Konzept + Paket
// buchen beide als menue_ref, werden aber getrennt gebrowst; Format wird WIE EIN CONCEPT gebucht.
it('Speisekarte-Editor: permanenter Katalog mit 4 Modi (Gericht·Konzept·Paket·Format) + Ziel-Rubrik', function () {
    Livewire::test(SpeisekarteIndex::class)->call('neu');
    $karte = FoodAlchemistSpeisekarte::first();
    $comp = Livewire::test(SpeisekarteIndex::class)
        ->call('waehle', $karte->id)
        ->set('neueRubrik', 'Vorspeisen')->call('rubrikNeu');
    $rubrik = $karte->sections()->first();

    // Katalog + 4 Modi rendern; Default-Modus Gericht.
    $comp->assertOk()
        ->assertSeeHtml('data-sk-katalog')
        ->assertSeeHtml('data-sk-kat="gericht"')
        ->assertSeeHtml('data-sk-kat="konzept"')
        ->assertSeeHtml('data-sk-kat="paket"')
        ->assertSeeHtml('data-sk-kat="format"')
        ->assertSet('pickerModus', 'gericht');

    // Modus-Umschalter (Livewire); konzept/paket/format sind gültig, Unfug fällt auf gericht.
    $comp->call('katalogModus', 'format')->assertSet('pickerModus', 'format')
        ->call('katalogModus', 'quatsch')->assertSet('pickerModus', 'gericht')
        ->call('katalogModus', 'konzept')->assertSet('pickerModus', 'konzept')
        ->call('katalogModus', 'paket')->assertSet('pickerModus', 'paket');

    // „+ Gericht" an der Rubrik setzt die Ziel-Rubrik → Katalog nennt sie.
    $comp->call('pickerOeffnen', $rubrik->id, 'gericht')
        ->assertSet('pickerRubrikId', $rubrik->id)
        ->assertSee('Ziel-Rubrik: Vorspeisen');
});
