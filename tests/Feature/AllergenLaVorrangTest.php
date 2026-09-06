<?php

use Platform\FoodAlchemist\Enums\BulkProposalStatus;
use Platform\FoodAlchemist\Enums\BulkRunType;
use Platform\FoodAlchemist\Models\FoodAlchemistBulkGpProposal;
use Platform\FoodAlchemist\Models\FoodAlchemistBulkRun;
use Platform\FoodAlchemist\Models\FoodAlchemistItemAllergen;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplier;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItemStructure;
use Platform\FoodAlchemist\Services\BulkEnrichService;
use Platform\FoodAlchemist\Services\GpAggregateService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 50 · A8 — der LA-Vorrang bei Allergenen.
 *
 * Der Fall, den die Etappe-0-Messung auf demo gefunden hat: 9 GPs trugen einen kompletten
 * KI-Allergen-Override (alle 14 Felder), OBWOHL ihre Lieferantenartikel ein Profil liefern.
 * Zwei Schäden: die Schätzung steht als GL-01 §4.3 Prio 1 über der Messung, und
 * `allergens_source='ki'` schirmt den GP dauerhaft von `backfillAllergenKonfidenz` ab.
 *
 * Getestet wird beides — der Wächter UND der Gegenfall. Ein Wächter, der immer sperrt, sieht
 * wie „sicher" aus und nimmt der Fallback-Schicht ihre Berechtigung: GPs ganz ohne LA-Daten
 * DÜRFEN den KI-Wert bekommen (R10, dokumentierter Fallback).
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->agg = app(GpAggregateService::class);

    /** GP + LA; mit $allergene !== null bekommt der LA ein Allergenprofil. */
    $this->mkGpMitLa = function (string $name, ?array $allergene) {
        $gp = $this->makeGp($this->rootTeam, $name);
        $supplier = FoodAlchemistSupplier::create(['team_id' => $this->rootTeam->id, 'name' => 'Lieferant '.$name]);
        $la = FoodAlchemistSupplierItem::create([
            'team_id' => $this->rootTeam->id, 'supplier_id' => $supplier->id,
            'designation' => $name, 'unit_code' => 'kg',
        ]);
        FoodAlchemistSupplierItemStructure::create([
            'team_id' => $this->rootTeam->id, 'supplier_item_id' => $la->id, 'gp_id' => $gp->id,
        ]);
        if ($allergene !== null) {
            FoodAlchemistItemAllergen::create(array_merge(
                ['team_id' => $this->rootTeam->id, 'supplier_item_id' => $la->id],
                $allergene
            ));
        }

        return $gp->fresh();
    };

    /** Offener KI-Vorschlag „alle 14 auf nicht_enthalten" — genau die Form aus dem Ist-Bestand. */
    $this->mkVorschlag = function ($gp) {
        $run = FoodAlchemistBulkRun::starte($this->rootTeam->id, BulkRunType::EnrichGp, 1, [], null);
        $werte = [];
        foreach (\Platform\FoodAlchemist\Models\FoodAlchemistGp::ALLERGEN_FIELDS as $feld) {
            $werte[$feld] = 'nicht_enthalten';
        }

        return FoodAlchemistBulkGpProposal::create([
            'team_id' => $this->rootTeam->id, 'run_id' => $run->id, 'gp_id' => $gp->id,
            'field' => 'allergene', 'value' => ['allergene' => $werte],
            'confidence' => 0.9, 'status' => BulkProposalStatus::Offen,
        ]);
    };
});

it('erkennt ein LA-Allergenprofil — und sein Fehlen', function () {
    $mit = ($this->mkGpMitLa)('Schalotte', ['allergen_gluten' => 'nicht_enthalten']);
    $ohne = ($this->mkGpMitLa)('Zimt', null);

    expect($this->agg->hatLaAllergenProfil($mit))->toBeTrue()
        ->and($this->agg->hatLaAllergenProfil($ohne))->toBeFalse();
});

it('A8: der Bulk-Pfad schreibt KEINEN KI-Override über ein LA-Profil', function () {
    $gp = ($this->mkGpMitLa)('Schalotte', ['allergen_gluten' => 'nicht_enthalten', 'allergen_celery' => 'enthalten']);
    $prop = ($this->mkVorschlag)($gp);

    $ok = app(BulkEnrichService::class)->uebernehmenGp($this->rootTeam, $prop->id);

    expect($ok)->toBeFalse();
    $gp->refresh();
    // Kein Override, keine Quelle, keine Konfidenz — die LA-Kette bleibt zuständig.
    expect($gp->allergen_gluten)->toBeNull()
        ->and($gp->allergen_celery)->toBeNull()
        ->and($gp->allergens_source)->toBeNull();
    // Der Vorschlag bleibt offen (Haus-Vertrag: `false` = nicht übernommen, nicht verworfen).
    expect($prop->fresh()->status)->toBe(BulkProposalStatus::Offen);
});

it('A8-Gegenfall: ohne LA-Daten bleibt der KI-Fallback erlaubt', function () {
    $gp = ($this->mkGpMitLa)('Zimt', null);
    $prop = ($this->mkVorschlag)($gp);

    $ok = app(BulkEnrichService::class)->uebernehmenGp($this->rootTeam, $prop->id);

    expect($ok)->toBeTrue()
        ->and($gp->fresh()->allergen_gluten)->toBe('nicht_enthalten');
});

it('A8: ein GP mit LA-Profil bleibt für die Konfidenz-Kaskade erreichbar', function () {
    // Der eigentliche Dauerschaden: `allergens_source='ki'` liesse backfillAllergenKonfidenz
    // den GP künftig überspringen (:124). Ohne Quelle bleibt er in der Kaskade.
    $gp = ($this->mkGpMitLa)('Schalotte', ['allergen_gluten' => 'nicht_enthalten']);
    app(BulkEnrichService::class)->uebernehmenGp($this->rootTeam, ($this->mkVorschlag)($gp)->id);

    expect($gp->fresh()->allergens_source)->not->toBe('ki');
});
