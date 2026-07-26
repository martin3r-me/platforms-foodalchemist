<?php

use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Platform\FoodAlchemist\Livewire\Concepter\Editor;
use Platform\FoodAlchemist\Models\FoodAlchemistDishMainGroup;
use Platform\FoodAlchemist\Models\FoodAlchemistPlanningFrame;
use Platform\FoodAlchemist\Services\ConceptGeneratorService;
use Platform\FoodAlchemist\Services\PlanningFrameService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;
use Symfony\Component\Uid\UuidV7;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 03 · L4 — KI-Slot-Vorschlag im Concepter (deterministisch, ohne LLM).
 *
 * Geprüft wird beides: die Rangliste MIT Begründung im Service
 * ({@see ConceptGeneratorService::slotKandidaten}) und die Fläche im Editor.
 * Kern-Aussagen: die Reihenfolge folgt derselben Assembler-Logik wie der
 * Generator (Rolle-Semantik → Aroma-Kanten → Anker-Dichte → Preis-Nähe), die
 * bereits gesetzten Gerichte des Konzepts sind Kohäsions-BASIS statt Kandidaten,
 * und wo nichts zulässig ist, sagt der Vorschlag es statt zu schweigen.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam));
    $this->svc = app(ConceptGeneratorService::class);
    $this->frames = app(PlanningFrameService::class);

    // Zwei Speisen-Hauptgruppen mit SPRECHENDEN Labels: slotSemantik vergleicht Token-
    // Präfixe (≥5) des Slot-Labels gegen das HG-Label — „Hauptgang" ↔ „Hauptgericht".
    $this->hgHaupt = FoodAlchemistDishMainGroup::create(['team_id' => $this->rootTeam->id, 'code' => 'HG', 'label' => 'Hauptgericht']);
    $this->hgVor = FoodAlchemistDishMainGroup::create(['team_id' => $this->rootTeam->id, 'code' => 'VS', 'label' => 'Vorspeise']);

    /** VK-Gericht (im Kandidaten-Pool: approved, is_sales_recipe, keine Slot-Variante). */
    $this->gericht = fn (string $name, FoodAlchemistDishMainGroup $hg, float $vk, $team = null) => $this->makeRecipe($team ?? $this->rootTeam, $name, [
        'is_sales_recipe' => true, 'sales_net' => $vk, 'dish_main_group_id' => $hg->id,
    ]);

    /** Aroma-Anker (global, Slug unique) → GP mit Kern-Anker; so füllt anchorsForRecipe die Anker des Gerichts. */
    $this->gpMitAnker = function (string $slug) {
        $ankerId = DB::table('foodalchemist_vocab_pairing_anchors')->where('slug', $slug)->value('id')
            ?? DB::table('foodalchemist_vocab_pairing_anchors')->insertGetId([
                'uuid' => (string) UuidV7::generate(), 'team_id' => null, 'slug' => $slug,
                'display_de' => ucfirst($slug), 'created_at' => now(), 'updated_at' => now(),
            ]);
        $gp = $this->makeGp($this->rootTeam, ucfirst($slug));
        DB::table('foodalchemist_gp_anchor_mappings')->insert([
            'uuid' => (string) UuidV7::generate(), 'team_id' => $this->rootTeam->id,
            'gp_id' => $gp->id, 'anchor_id' => (int) $ankerId, 'role' => 'kern',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $gp;
    };

    $this->konzept = $this->makeConcept($this->rootTeam, 'Sommer-Menü');
    $this->frame = $this->frames->frameFor($this->rootTeam, 'concept', $this->konzept->id);
    $this->slot = $this->frames->addSlot($this->rootTeam, $this->frame, ['label' => 'Hauptgang', 'slot_type' => 'gang', 'target_count' => 1]);
});

it('rankt den Rollen-Treffer zuerst und macht die Ranking-Faktoren sichtbar', function () {
    $haupt = ($this->gericht)('Rinderbraten', $this->hgHaupt, 22.00);
    ($this->gericht)('Süppchen', $this->hgVor, 6.00);

    $res = $this->svc->slotKandidaten($this->rootTeam, $this->frame, $this->slot);

    expect($res['kandidaten'][0]['id'])->toBe($haupt->id)
        ->and($res['kandidaten'][0]['faktoren']['semantik'])->toBe(1)
        ->and($res['kandidaten'][0]['begruendung'])->toContain('Hauptgruppe passt zur Rolle')
        // Ehrlich statt stumm: 2 Gerichte im Bestand, gefragt waren 3.
        ->and($res['kandidaten'])->toHaveCount(2)
        ->and($res['hinweis'])->toContain('Nur 2 zulässige Treffer');
});

it('nimmt gesetzte Gerichte aus der Rangliste und nutzt ihre Anker als Kohäsions-Basis', function () {
    $lachs = ($this->gpMitAnker)('lachs');
    $rind = ($this->gpMitAnker)('rind');

    // Schon gesetzt (andere Position des Konzepts) — trägt „lachs" in die Kohäsions-Basis.
    $gesetzt = ($this->gericht)('Lachs-Tartar', $this->hgVor, 14.00);
    $this->makeIngredient($gesetzt, 'Lachs', $lachs);

    // Beide Kandidaten in derselben Hauptgruppe ⇒ die Semantik entscheidet NICHT, die Aroma-Kante schon.
    // Namen bewusst so, dass der Alphabet-Tiebreak den anderen zuerst nähme.
    $angus = ($this->gericht)('Angus-Braten', $this->hgHaupt, 24.00);
    $this->makeIngredient($angus, 'Rind', $rind);
    $wild = ($this->gericht)('Wildlachs-Filet', $this->hgHaupt, 26.00);
    $this->makeIngredient($wild, 'Lachs', $lachs);

    $res = $this->svc->slotKandidaten($this->rootTeam, $this->frame, $this->slot, [$gesetzt->id]);
    $ids = array_column($res['kandidaten'], 'id');

    expect($ids)->not->toContain($gesetzt->id)
        ->and($ids[0])->toBe($wild->id)
        ->and($res['kandidaten'][0]['faktoren']['kohaesion'])->toBe(1.0)
        ->and($res['kandidaten'][0]['faktoren']['ankerdichte'])->toBe(1)
        ->and($res['kandidaten'][0]['begruendung'])->toContain('Aroma-Nähe zur gesetzten Folge 1,00')
        // Ohne Kante zur Basis wird das ehrlich benannt, nicht weggelassen.
        ->and($res['kandidaten'][1]['begruendung'])->toContain('keine Aroma-Kante zur gesetzten Folge');
});

it('ohne gesetzte Gerichte spricht die Begründung nicht von einer Menüfolge', function () {
    ($this->gericht)('Rinderbraten', $this->hgHaupt, 22.00);

    $res = $this->svc->slotKandidaten($this->rootTeam, $this->frame, $this->slot);

    expect($res['kandidaten'][0]['begruendung'])->not->toContain('gesetzten Folge')
        ->and($res['kandidaten'][0]['begruendung'])->toContain('Aroma-Anker');
});

it('sagt ehrlich, dass der Preisrahmen des Slots nichts zulässt', function () {
    ($this->gericht)('Rinderbraten', $this->hgHaupt, 22.00);
    $this->frames->updateSlot($this->rootTeam, $this->slot->id, ['price_min' => 80, 'price_max' => 120]);

    $res = $this->svc->slotKandidaten($this->rootTeam, $this->frame->refresh(), $this->slot->refresh());

    expect($res['kandidaten'])->toBe([])
        ->and($res['hinweis'])->toContain('Preisrahmen 80.00–120.00 €');
});

it('respektiert No-Go-Regeln des Gerüsts (harte Filter wie im Generator)', function () {
    $lachs = ($this->gpMitAnker)('lachs');
    $mit = ($this->gericht)('Lachs-Steak', $this->hgHaupt, 22.00);
    $this->makeIngredient($mit, 'Lachs', $lachs);
    $ohne = ($this->gericht)('Rinderbraten', $this->hgHaupt, 22.00);
    $this->frames->addRule($this->rootTeam, $this->frame, ['rule_type' => 'nogo_ingredient', 'value_text' => 'Lachs', 'severity' => 'hart']);

    $ids = array_column($this->svc->slotKandidaten($this->rootTeam, $this->frame->refresh(), $this->slot)['kandidaten'], 'id');

    expect($ids)->toBe([$ohne->id])
        ->and($ids)->not->toContain($mit->id);
});

it('schlägt keine Gerichte fremder Teams vor (Tenancy)', function () {
    $eigen = ($this->gericht)('Rinderbraten', $this->hgHaupt, 22.00);
    $fremdHg = FoodAlchemistDishMainGroup::create(['team_id' => $this->childB->id, 'code' => 'HG', 'label' => 'Hauptgericht']);
    $fremd = ($this->gericht)('Fremd-Braten', $fremdHg, 22.00, $this->childB);

    $ids = array_column($this->svc->slotKandidaten($this->childA, $this->frame, $this->slot)['kandidaten'], 'id');

    expect($ids)->not->toContain($fremd->id)
        // Kind A erbt den Root-Katalog (Team-Hierarchie), Kind B ist Geschwister.
        ->and($ids)->toContain($eigen->id);
});

it('Concepter-Editor: ✨ Vorschlag füllt die Position — und legt dabei KEIN Gerüst an', function () {
    $haupt = ($this->gericht)('Rinderbraten', $this->hgHaupt, 22.00);
    // Konzept OHNE Gerüst: der Vorschlag muss trotzdem tragen (Rolle + Anker ranken auch ohne Vorgaben).
    $ohneFrame = $this->makeConcept($this->rootTeam, 'Freies Menü');
    $slot = $this->makeConceptSlot($ohneFrame, ['role' => 'Hauptgang', 'sales_recipe_id' => null]);

    $c = Livewire::test(Editor::class)
        ->call('oeffnen', 'concepts', $ohneFrame->id)
        ->call('vorschlagFuerSlot', $slot->id);

    $vorschlaege = $c->get('slotVorschlaege')[$slot->id];
    expect(array_column($vorschlaege['kandidaten'], 'id'))->toContain($haupt->id)
        ->and(FoodAlchemistPlanningFrame::where('owner_type', 'concept')->where('owner_id', $ohneFrame->id)->count())->toBe(0);

    $c->call('vorschlagUebernehmen', $slot->id, $haupt->id);

    expect((int) $slot->refresh()->sales_recipe_id)->toBe($haupt->id)
        ->and($c->get('slotVorschlaege'))->not->toHaveKey($slot->id);
});

it('Concepter-Editor: zweiter Klick klappt die Rangliste wieder zu, Verwerfen nimmt eine Zeile', function () {
    $haupt = ($this->gericht)('Rinderbraten', $this->hgHaupt, 22.00);
    $slot = $this->makeConceptSlot($this->konzept, ['role' => 'Hauptgang', 'sales_recipe_id' => null]);

    $c = Livewire::test(Editor::class)
        ->call('oeffnen', 'concepts', $this->konzept->id)
        ->call('vorschlagFuerSlot', $slot->id);
    expect($c->get('slotVorschlaege'))->toHaveKey($slot->id);

    $c->call('vorschlagVerwerfen', $slot->id, $haupt->id);
    expect(array_column($c->get('slotVorschlaege')[$slot->id]['kandidaten'], 'id'))->not->toContain($haupt->id);

    $c->call('vorschlagFuerSlot', $slot->id);
    expect($c->get('slotVorschlaege'))->not->toHaveKey($slot->id);
});

it('Gerichte gebuchter Pakete zählen zur Kohäsions-Basis', function () {
    $lachs = ($this->gpMitAnker)('lachs');
    $rind = ($this->gpMitAnker)('rind');

    // Das gesetzte Gericht steckt NICHT direkt an einer Position, sondern in einem Paket.
    $imPaket = ($this->gericht)('Lachs-Tartar', $this->hgVor, 14.00);
    $this->makeIngredient($imPaket, 'Lachs', $lachs);
    $paket = app(\Platform\FoodAlchemist\Services\PaketService::class)->create($this->rootTeam, ['name' => 'Fisch-Station', 'role' => 'Vorspeisen']);
    app(\Platform\FoodAlchemist\Services\PaketService::class)->syncGerichte($this->rootTeam, $paket->id, [['sales_recipe_id' => $imPaket->id]]);
    $this->makeConceptSlot($this->konzept, ['role' => 'Vorspeisen', 'type' => 'paket', 'package_id' => $paket->id, 'position' => 1]);

    $angus = ($this->gericht)('Angus-Braten', $this->hgHaupt, 24.00);
    $this->makeIngredient($angus, 'Rind', $rind);
    $wild = ($this->gericht)('Wildlachs-Filet', $this->hgHaupt, 26.00);
    $this->makeIngredient($wild, 'Lachs', $lachs);

    $offen = $this->makeConceptSlot($this->konzept, ['role' => 'Hauptgang', 'sales_recipe_id' => null, 'position' => 2]);

    $c = Livewire::test(Editor::class)
        ->call('oeffnen', 'concepts', $this->konzept->id)
        ->call('vorschlagFuerSlot', $offen->id);
    $kandidaten = $c->get('slotVorschlaege')[$offen->id]['kandidaten'];

    // Ohne Paket-Auffaltung wäre die Basis leer → Alphabet-Tiebreak (Angus zuerst) und keine Aroma-Zeile.
    expect($kandidaten[0]['id'])->toBe($wild->id)
        ->and($kandidaten[0]['begruendung'])->toContain('Aroma-Nähe zur gesetzten Folge')
        ->and(array_column($kandidaten, 'id'))->not->toContain($imPaket->id);
});

it('Struktur-Blöcke bekommen keinen Vorschlag (sie tragen kein Gericht)', function () {
    ($this->gericht)('Rinderbraten', $this->hgHaupt, 22.00);
    $header = $this->makeConceptSlot($this->konzept, ['role' => 'Hauptgang', 'type' => 'header', 'sales_recipe_id' => null]);

    $c = Livewire::test(Editor::class)
        ->call('oeffnen', 'concepts', $this->konzept->id)
        ->call('vorschlagFuerSlot', $header->id);

    expect($c->get('slotVorschlaege'))->not->toHaveKey($header->id);
});
