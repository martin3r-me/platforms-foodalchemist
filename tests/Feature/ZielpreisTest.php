<?php

use Platform\FoodAlchemist\Services\ConceptService;
use Platform\FoodAlchemist\Services\PaketService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * M13: Zielpreis-Konfigurator — Solver tauscht Pakete derselben Rolle, um dem
 * Ziel-€/Person am nächsten zu kommen; feste Gerichte = Fixkosten.
 */
beforeEach(function () {
    $this->concepts = app(ConceptService::class);
    $this->seedTeamHierarchy();

    // Kaskade: Pakete = kind=paket-Concepts (manueller Preis), Einbau via embedded_concept_id.
    $mk = function (string $name, float $preis) {
        $p = $this->concepts->createPaket($this->rootTeam, ['name' => $name, 'price_mode' => 'manuell']);
        $this->concepts->update($this->rootTeam, $p->id, ['price_per_person_manual' => $preis]);

        return $p;
    };
    $this->v4 = $mk('Vorspeise günstig', 4.00);
    $this->v6 = $mk('Vorspeise mittel', 6.00);
    $this->v10 = $mk('Vorspeise premium', 10.00);
    $this->h20 = $mk('Hauptgang günstig', 20.00);
    $this->h30 = $mk('Hauptgang premium', 30.00);

    $this->concept = $this->concepts->create($this->rootTeam, ['name' => 'Grill-Buffet']);
    $this->sVor = $this->concepts->addSlot($this->rootTeam, $this->concept->id, ['role' => 'Vorspeise']);
    $this->sHg = $this->concepts->addSlot($this->rootTeam, $this->concept->id, ['role' => 'Hauptgang']);
    $this->concepts->fillSlot($this->rootTeam, $this->sVor->id, ['embedded_concept_id' => $this->v4->id]);
    $this->concepts->fillSlot($this->rootTeam, $this->sHg->id, ['embedded_concept_id' => $this->h20->id]);
});

it('M13: Zielpreis-Solver trifft die nächstbeste Paket-Kombination (greift nur Paket-Slots)', function () {
    // aktuell 24 € (4 + 20). Ziel 36 → optimal 6 + 30 = 36
    $v = $this->concepts->zielpreisVorschlag($this->rootTeam, $this->concept->id, 36.00);

    expect($v['aktuell'])->toBe(24.00)
        ->and($v['price'])->toBe(36.00)                              // exakt erreichbar
        // Spanne rollen-agnostisch (2026-08-24): jeder Slot darf jedes Paket → min 4+4, max 30+30
        ->and($v['min'])->toBe(8.00)->and($v['max'])->toBe(60.00)
        ->and($v['aenderungen'])->toBe(2)
        ->and($v['vorschlag'][$this->sVor->id])->toBe($this->v6->id)
        ->and($v['vorschlag'][$this->sHg->id])->toBe($this->h30->id);

    // Anwenden → Concept-Preis = 36
    $this->concepts->zielpreisAnwenden($this->rootTeam, $this->concept->id, $v['vorschlag']);
    expect($this->concepts->preisCockpit($this->concept->refresh())['price_per_person'])->toBe(36.00);
});

it('M13: festes Gericht bleibt Fixkosten, nur Pakete werden getauscht', function () {
    // Dessert-Slot mit festem Gericht (5 €) → Fixkosten; Pakete tauschen für Ziel
    $dessert = \Platform\FoodAlchemist\Models\FoodAlchemistRecipe::create([
        'team_id' => $this->rootTeam->id, 'recipe_key' => 'd1', 'name' => 'Dessert', 'status' => 'approved',
        'is_sales_recipe' => true, 'sales_net' => 5.00,
    ]);
    $sDess = $this->concepts->addSlot($this->rootTeam, $this->concept->id, ['role' => 'Dessert']);
    $this->concepts->fillSlot($this->rootTeam, $sDess->id, ['sales_recipe_id' => $dessert->id]);

    // Ziel 41 = 5 fix + 36 Pakete (6 + 30)
    $v = $this->concepts->zielpreisVorschlag($this->rootTeam, $this->concept->id, 41.00);
    expect($v['fix'])->toBe(5.00)
        ->and($v['price'])->toBe(41.00)
        ->and($v['vorschlag'])->not->toHaveKey($sDess->id);          // festes Gericht nicht im Vorschlag
});
