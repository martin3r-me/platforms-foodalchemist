<?php

use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Models\FoodAlchemistPrice;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplier;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItemStructure;
use Platform\FoodAlchemist\Services\LeadLaService;
use Platform\FoodAlchemist\Services\RecipeRecomputeService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Ebene 1 (Betriebs-/Kunden-Kalkulation): der Recompute-EK ist TEAM-bewusst.
 *
 * Ein Kunden-(Kind-)Team, das seinen EIGENEN Lieferantenpreis als Lead pinnt (bzw. den
 * geerbten globalen Lead sperrt), bekommt seinen eigenen Wareneinsatz; Parent + Geschwister-
 * Kind bleiben byte-identisch. Ohne Overlay = exakt der globale Lead (Baseline).
 *
 * Der Fix: {@see RecipeRecomputeService::effektiverLeadId} (Team-Pin > globale Spalte >
 * Ausweich-Kandidat) + `laMitPreis`-Filter auf `visibleToTeam`. Die Backward-Compat der
 * überlagerungsfreien Kaskade riegeln EkPreisBasis-/LeadPreisWahrheit-Golden ab; dieser
 * Test riegelt das NEUE Overlay-Verhalten + die Geschwister-Isolation.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->recompute = app(RecipeRecomputeService::class);
    $this->leadLa = app(LeadLaService::class);

    $this->supplier = FoodAlchemistSupplier::create([
        'team_id' => $this->rootTeam->id, 'name' => 'Master-Lieferant',
    ]);

    // LA am $owner-Team, an $gp verknüpft + mit €/kg bepreist. Gibt die LA-ID zurück.
    $this->mkLa = function (Team $owner, FoodAlchemistGp $gp, float $preisProKg, string $designation): int {
        $la = FoodAlchemistSupplierItem::create([
            'team_id' => $owner->id, 'supplier_id' => $this->supplier->id,
            'designation' => $designation, 'qty' => 1.0, 'unit_code' => 'kg',
        ]);
        FoodAlchemistSupplierItemStructure::create([
            'team_id' => $owner->id, 'supplier_item_id' => $la->id, 'gp_id' => $gp->id,
        ]);
        FoodAlchemistPrice::create([
            'team_id' => $owner->id, 'supplier_item_id' => $la->id,
            'price' => $preisProKg, 'status' => '0',
        ]);

        return (int) $la->id;
    };

    // Rezept im $team mit 100 g des GP, frisch gerechnet ⇒ ek_total = €/kg · 0,1.
    $this->ekFuer = function (Team $team, FoodAlchemistGp $gp, string $name): ?float {
        $r = $this->makeRecipe($team, $name);
        $this->makeIngredient($r, $gp->name, $gp, '100', 1);
        $this->recompute->recomputeAndPropagate($r->id);
        $fresh = $r->fresh();

        return $fresh->ek_total_eur !== null ? (float) $fresh->ek_total_eur : null;
    };
});

it('Team-Pin des Kind-Teams schlägt im EK durch; Parent + Geschwister bleiben unberührt', function () {
    // Master-GP am Root mit Root-Lead 10 €/kg — von den Kindern via Ancestry geerbt.
    $gp = $this->makeGp($this->rootTeam, 'Zander');
    $rootLa = ($this->mkLa)($this->rootTeam, $gp, 10.0, 'Zander Master');
    $gp->update(['status' => 'approved', 'lead_la_supplier_item_id' => $rootLa]);

    // Baseline OHNE Overlay: alle drei rechnen den geerbten Lead (10 €/kg ⇒ 1,00 €).
    expect(($this->ekFuer)($this->rootTeam, $gp, 'Root Baseline'))->toBe(1.0)
        ->and(($this->ekFuer)($this->childA, $gp, 'A Baseline'))->toBe(1.0)
        ->and(($this->ekFuer)($this->childB, $gp, 'B Baseline'))->toBe(1.0);

    // Kind A bringt seinen EIGENEN, günstigeren Lieferantenpreis (4 €/kg) und pinnt ihn.
    $laChildA = ($this->mkLa)($this->childA, $gp, 4.0, 'Zander Kunde-A');
    $this->leadLa->pinnen($this->childA, $gp, $laChildA);

    // A rechnet jetzt SEINEN Preis (0,40 €); Root + B unverändert (1,00 €).
    expect(($this->ekFuer)($this->childA, $gp, 'A nach Pin'))->toBe(0.4)
        ->and(($this->ekFuer)($this->rootTeam, $gp, 'Root nach A-Pin'))->toBe(1.0)
        ->and(($this->ekFuer)($this->childB, $gp, 'B nach A-Pin'))->toBe(1.0);
});

it('Sperre des geerbten Leads durch das Kind-Team weicht auf den nächsten Kandidaten aus (Parent unberührt)', function () {
    // GP mit zwei Root-LAs: Lead 10 €/kg + Ausweich 20 €/kg.
    $gp = $this->makeGp($this->rootTeam, 'Kabeljau');
    $leadLaId = ($this->mkLa)($this->rootTeam, $gp, 10.0, 'Kabeljau Lead');
    ($this->mkLa)($this->rootTeam, $gp, 20.0, 'Kabeljau Ausweich');
    $gp->update(['status' => 'approved', 'lead_la_supplier_item_id' => $leadLaId]);

    // Kind A sperrt den geerbten Lead ⇒ Ausweich-LA (20 €/kg ⇒ 2,00 €); Root bleibt bei 1,00 €.
    $this->leadLa->sperren($this->childA, $gp, $leadLaId);

    expect(($this->ekFuer)($this->childA, $gp, 'A gesperrt'))->toBe(2.0)
        ->and(($this->ekFuer)($this->rootTeam, $gp, 'Root trotz A-Sperre'))->toBe(1.0);
});
