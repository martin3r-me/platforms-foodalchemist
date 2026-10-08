<?php

use Livewire\Livewire;
use Platform\Core\Contracts\ToolContext;
use Platform\FoodAlchemist\Livewire\Concepter\Editor as ConcepterEditor;
use Platform\FoodAlchemist\Models\FoodAlchemistGeschirrItem;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeDarreichung;
use Platform\FoodAlchemist\Models\FoodAlchemistServierform;
use Platform\FoodAlchemist\Models\FoodAlchemistVocabEinheit;
use Platform\FoodAlchemist\Services\PaketService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;
use Platform\FoodAlchemist\Tools\PaketGerichteSetTool;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * 2026-10-08: Paket-Gerichte behalten beim Hinzufügen/Entfernen ihre Menge, Einheit,
 * Darreichung und ihr Geschirr (vorher: alle Zeilen gelöscht und neu angelegt), und die
 * Darreichung ist im Paket-Editor je Posten wählbar.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->user = $this->makeUser($this->rootTeam);
    $this->actingAs($this->user);
    $this->svc = app(PaketService::class);
    $this->portion = FoodAlchemistVocabEinheit::create(['team_id' => $this->rootTeam->id, 'slug' => 'portion', 'display_de' => 'Portion', 'dimension' => 'count', 'default_in_g' => null]);

    $this->gericht = FoodAlchemistRecipe::create(['team_id' => $this->rootTeam->id, 'recipe_key' => 'bratkartoffeln', 'name' => 'Bratkartoffeln', 'status' => 'approved', 'is_sales_recipe' => true, 'sales_net' => 4.00, 'ek_total_eur' => 2.00]);
    $this->zweites = FoodAlchemistRecipe::create(['team_id' => $this->rootTeam->id, 'recipe_key' => 'rahmspinat', 'name' => 'Rahmspinat', 'status' => 'approved', 'is_sales_recipe' => true, 'sales_net' => 3.00, 'ek_total_eur' => 1.00]);
    $teller = FoodAlchemistServierform::create(['team_id' => $this->rootTeam->id, 'code' => 'teller', 'label' => 'Teller']);
    $kind = FoodAlchemistServierform::create(['team_id' => $this->rootTeam->id, 'code' => 'kinder', 'label' => 'Kinder']);
    $this->standard = FoodAlchemistRecipeDarreichung::create(['team_id' => $this->rootTeam->id, 'recipe_id' => $this->gericht->id, 'serving_form_id' => $teller->id, 'is_standard' => true, 'quantity_per_unit_g' => 300, 'unit_count' => 1, 'ek_portion' => 2.00, 'sales_net' => 4.00, 'price_mode' => 'auto']);
    $this->kinder = FoodAlchemistRecipeDarreichung::create(['team_id' => $this->rootTeam->id, 'recipe_id' => $this->gericht->id, 'serving_form_id' => $kind->id, 'is_standard' => false, 'quantity_per_unit_g' => 150, 'unit_count' => 1, 'ek_portion' => 1.00, 'sales_net' => 2.50, 'price_mode' => 'auto']);
    $this->geschirr = FoodAlchemistGeschirrItem::create(['team_id' => $this->rootTeam->id, 'label' => 'Coupteller 28 cm', 'category' => 'Teller']);

    $this->paket = $this->svc->create($this->rootTeam, ['name' => 'Beilagen-Duo']);
    $this->svc->syncGerichte($this->rootTeam, $this->paket->id, [['sales_recipe_id' => $this->gericht->id, 'quantity' => 1, 'unit_vocab_id' => $this->portion->id]]);
    $this->zeile = $this->paket->dishes()->first();
});

it('Hinzufügen/Entfernen eines Gerichts erhält Menge, Einheit, Darreichung und Geschirr der übrigen', function () {
    $this->svc->setGerichtDarreichung($this->rootTeam, $this->zeile->id, $this->kinder->id);
    $this->svc->setGerichtGeschirr($this->rootTeam, $this->zeile->id, 'haupt', $this->geschirr->id);

    // Paket-Übersicht schickt nur die Gericht-IDs (vorher: Menge/Einheit/Form/Geschirr weg).
    $this->svc->syncGerichte($this->rootTeam, $this->paket->id, [['sales_recipe_id' => $this->gericht->id], ['sales_recipe_id' => $this->zweites->id]]);
    $a = $this->paket->dishes()->where('sales_recipe_id', $this->gericht->id)->first();
    expect($a->id)->toBe($this->zeile->id)
        ->and((float) $a->quantity)->toBe(1.0)
        ->and((int) $a->unit_vocab_id)->toBe($this->portion->id)
        ->and((int) $a->presentation_id)->toBe($this->kinder->id)
        ->and((int) $a->tableware_item_id)->toBe($this->geschirr->id)
        ->and($this->paket->dishes()->count())->toBe(2);

    $this->svc->syncGerichte($this->rootTeam, $this->paket->id, [['sales_recipe_id' => $this->gericht->id]]);
    expect($this->paket->dishes()->count())->toBe(1)
        ->and((int) $this->paket->dishes()->first()->presentation_id)->toBe($this->kinder->id);
});

it('Darreichung je Posten: Preis/EK des Pakets folgen der Form, fremde Form wird abgelehnt', function () {
    $vorher = (float) $this->paket->fresh()->ek_per_person;
    $this->svc->setGerichtDarreichung($this->rootTeam, $this->zeile->id, $this->kinder->id);
    expect((float) $this->paket->fresh()->ek_per_person)->toBe(1.0)->and($vorher)->toBe(2.0);

    $fremd = FoodAlchemistRecipeDarreichung::create(['team_id' => $this->rootTeam->id, 'recipe_id' => $this->zweites->id, 'serving_form_id' => $this->kinder->serving_form_id, 'is_standard' => true, 'quantity_per_unit_g' => 100, 'unit_count' => 1, 'price_mode' => 'auto']);
    expect(fn () => $this->svc->setGerichtDarreichung($this->rootTeam, $this->zeile->id, $fremd->id))
        ->toThrow(\RuntimeException::class, 'gehört nicht');

    $this->svc->setGerichtDarreichung($this->rootTeam, $this->zeile->id, null);
    expect($this->zeile->fresh()->presentation_id)->toBeNull()->and((float) $this->paket->fresh()->ek_per_person)->toBe(2.0);
});

it('Duplizieren übernimmt Darreichung und Geschirr je Posten', function () {
    $this->svc->setGerichtDarreichung($this->rootTeam, $this->zeile->id, $this->kinder->id);
    $this->svc->setGerichtGeschirr($this->rootTeam, $this->zeile->id, 'haupt', $this->geschirr->id);
    $kopie = $this->svc->duplicate($this->rootTeam, $this->paket->id);
    $z = $kopie->dishes()->first();
    expect((int) $z->presentation_id)->toBe($this->kinder->id)->and((int) $z->tableware_item_id)->toBe($this->geschirr->id);
});

it('Paket-Editor: Form-Auswahl je Posten, Preis der gewählten Form in der Zeile', function () {
    $lw = Livewire::test(ConcepterEditor::class)->call('oeffnen', 'pakete', $this->paket->id)
        ->assertSeeHtml('data-paket-form-picker')
        ->assertSee('Kinder · 150 g')
        ->call('paketGerichtDarreichungSetzen', $this->zeile->id, (string) $this->kinder->id);
    expect((int) $this->zeile->fresh()->presentation_id)->toBe($this->kinder->id);
    $lw->assertSee('2,50');   // VK der Kinder-Form statt 4,00 (Standard)

    $lw->call('paketGerichtDarreichungSetzen', $this->zeile->id, '');
    expect($this->zeile->fresh()->presentation_id)->toBeNull();
});

it('MCP paket_gerichte.SET: presentation_id setzen, weglassen erhält, Payload liefert die Form', function () {
    $ctx = new ToolContext($this->user, $this->rootTeam);
    $tool = app(PaketGerichteSetTool::class);

    $r = $tool->execute(['paket_id' => $this->paket->id, 'items' => [['sales_recipe_id' => $this->gericht->id, 'presentation_id' => $this->kinder->id]]], $ctx);
    expect($r->success)->toBeTrue('set: ' . ($r->error ?? ''))
        ->and($r->data['paket']['dishes'][0]['presentation_id'])->toBe($this->kinder->id)
        ->and($r->data['paket']['dishes'][0]['darreichung'])->toBe('Kinder')
        ->and($r->data['paket']['dishes'][0]['quantity'])->toBe(1.0);          // Menge blieb erhalten

    $r = $tool->execute(['paket_id' => $this->paket->id, 'items' => [['sales_recipe_id' => $this->gericht->id], ['sales_recipe_id' => $this->zweites->id]]], $ctx);
    expect($r->data['paket']['dishes'][0]['presentation_id'])->toBe($this->kinder->id);

    $r = $tool->execute(['paket_id' => $this->paket->id, 'items' => [['sales_recipe_id' => $this->gericht->id, 'presentation_id' => 0]]], $ctx);
    expect($r->data['paket']['dishes'][0]['presentation_id'])->toBeNull();
});
