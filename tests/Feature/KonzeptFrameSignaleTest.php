<?php

use Platform\FoodAlchemist\Enums\SignalTyp;
use Platform\FoodAlchemist\Models\FoodAlchemistDishClass;
use Platform\FoodAlchemist\Models\FoodAlchemistDishMainGroup;
use Platform\FoodAlchemist\Services\DataQualityService;
use Platform\FoodAlchemist\Services\PlanningFrameService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 21 · S4b — die frame-gestützte Hälfte von Tranche C.
 *
 * Anders als S4a misst hier nichts absolut: geprüft wird gegen ein SOLL, das jemand
 * im Planungs-Gerüst gesetzt hat. Daraus folgt die Kern-Abgrenzung dieser Etappe —
 * **ohne Gerüst kein Befund**, und gemeldet wird nur die ROTE Lage (`ampel=verletzt`),
 * also genau das, was das Concepter-Cockpit rot zeigt. Jeder Check hat deshalb einen
 * Negativfall auf der gelben Seite (`teilerfuellt`): das ist ein Arbeitsstand, kein Fehler.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->dq = app(DataQualityService::class);
    $this->frames = app(PlanningFrameService::class);

    $hg = FoodAlchemistDishMainGroup::create(['team_id' => $this->rootTeam->id, 'code' => 'HG', 'label' => 'Hauptgericht']);
    $this->klasseFleisch = FoodAlchemistDishClass::create([
        'team_id' => $this->rootTeam->id, 'dish_main_group_id' => $hg->id,
        'code' => 'HG_F', 'label' => 'Fleisch', 'diet_form' => 'fleisch',
    ]);

    // Zwei bepreiste VK-Gerichte ⇒ Ist-Preis p. P. = 30,00 €.
    $this->konzept = $this->makeConcept($this->rootTeam, 'Test-Buffet');
    foreach ([['Bowl', 12.00, 'nicht_enthalten'], ['Kalbsleber', 18.00, 'enthalten']] as $i => [$n, $vk, $erdnuss]) {
        $g = $this->makeRecipe($this->rootTeam, 'HG: ' . $n, [
            'is_sales_recipe' => true, 'sales_wording_standard' => $n, 'sales_net' => $vk,
            'dish_class_id' => $this->klasseFleisch->id, 'allergen_peanuts' => $erdnuss,
        ]);
        $this->makeConceptSlot($this->konzept, ['position' => $i + 1, 'sales_recipe_id' => $g->id]);
    }
});

/** Metrik über alle Ebenen hinweg per key finden. */
function kfMetrik(array $ebenen, string $key): array
{
    foreach ($ebenen as $ebene) {
        foreach ($ebene['metriken'] as $m) {
            if ($m['key'] === $key) {
                return $m;
            }
        }
    }
    throw new RuntimeException("Metrik {$key} nicht gefunden");
}

it('kennt beide S4b-Typen und führt sie in der Konzept-Ebene', function () {
    $e = $this->dq->messeAlleEbenen($this->rootTeam);

    expect(array_column($e['konzept']['metriken'], 'key'))->toBe([
        'konzept_slot_luecke', 'konzept_ohne_wording', 'konzept_preisband_verletzt', 'konzept_regel_verletzt',
    ])->and(SignalTyp::KonzeptPreisbandVerletzt->istKonzeptQualitaet())->toBeTrue()
        ->and(SignalTyp::KonzeptRegelVerletzt->istKonzeptQualitaet())->toBeTrue();
});

it('meldet ohne Planungs-Gerüst nichts — kein Soll, kein Befund', function () {
    // Das Konzept ist in Gebrauch und kostet 30 € p. P. — ohne gesetzte Erwartung ist das
    // weder richtig noch falsch. Die Arbeitsmenge ist damit doppelt geschnitten.
    expect($this->dq->countFor($this->rootTeam, 'konzept_preisband_verletzt'))->toBe(0)
        ->and($this->dq->countFor($this->rootTeam, 'konzept_regel_verletzt'))->toBe(0);
});

it('flaggt einen gerissenen Preis-Deckel, nicht aber eine Zielpreis-Abweichung in der Spanne', function () {
    $frame = $this->frames->frameFor($this->rootTeam, 'concept', $this->konzept->id);

    // Über der Spanne (30 > 28) ⇒ rot.
    $this->frames->setHead($this->rootTeam, $frame, ['target_price_pp' => 25, 'price_max_pp' => 28]);
    $items = $this->dq->betroffene($this->rootTeam, 'konzept_preisband_verletzt');

    expect(array_column($items, 'name'))->toBe(['Test-Buffet'])
        ->and($items[0]['kind'])->toBe('concept')
        ->and($this->dq->countFor($this->rootTeam, 'konzept_regel_verletzt'))->toBe(0);

    // In der Spanne, aber >10 % über dem Zielpreis (30 vs. 25) ⇒ gelb, also KEIN Signal.
    $this->frames->setHead($this->rootTeam, $frame, ['target_price_pp' => 25, 'price_max_pp' => 40]);

    expect($this->dq->countFor($this->rootTeam, 'konzept_preisband_verletzt'))->toBe(0);
});

it('flaggt eine harte No-Go-Regel, aber nicht dieselbe Regel als weich', function () {
    $frame = $this->frames->frameFor($this->rootTeam, 'concept', $this->konzept->id);
    $regel = $this->frames->addRule($this->rootTeam, $frame, ['rule_type' => 'nogo_allergen', 'ref_key' => 'peanuts']);

    $m = kfMetrik($this->dq->messeAlleEbenen($this->rootTeam), 'konzept_regel_verletzt');

    expect($m['wert'])->toBe(1)
        ->and($m['signal']['typ'])->toBe(SignalTyp::KonzeptRegelVerletzt)
        // Der Preis-Check darf davon nichts abbekommen (getrennte Dimensionen, ein Pass).
        ->and(kfMetrik($this->dq->messeAlleEbenen($this->rootTeam), 'konzept_preisband_verletzt')['wert'])->toBe(0);

    // `severity=weich` macht denselben Befund gelb — eine bewusst tolerierte Lage.
    $regel->update(['severity' => 'weich']);

    expect($this->dq->countFor($this->rootTeam, 'konzept_regel_verletzt'))->toBe(0);
});

it('zählt Mengen- und Dramaturgie-Lücken des Gerüsts NICHT als Regel-Verletzung', function () {
    $frame = $this->frames->frameFor($this->rootTeam, 'concept', $this->konzept->id);
    // Pflicht-Slot ohne Ist-Bezug ⇒ rote Dramaturgie-Ampel; Mengen-Soll 5 bei 2 Gerichten.
    $this->frames->addSlot($this->rootTeam, $frame, ['label' => 'Dessert', 'slot_type' => 'gang', 'is_pflicht' => true]);
    $this->frames->addSlot($this->rootTeam, $frame, ['label' => 'Hauptgang', 'slot_type' => 'gang', 'target_count' => 5]);

    // Beides ist eine Struktur-Aussage, keine Kunden-Zusage — sonst vermischte ein Signal
    // zwei Sachverhalte (die Struktur-Seite deckt `konzept_slot_luecke` ab).
    expect($this->dq->countFor($this->rootTeam, 'konzept_regel_verletzt'))->toBe(0)
        ->and($this->dq->countFor($this->rootTeam, 'konzept_preisband_verletzt'))->toBe(0);
});

it('hält die Arbeitsmengen-Grenze auch mit Gerüst: ein Entwurf zählt nicht', function () {
    $frame = $this->frames->frameFor($this->rootTeam, 'concept', $this->konzept->id);
    $this->frames->setHead($this->rootTeam, $frame, ['price_max_pp' => 10]);

    expect($this->dq->countFor($this->rootTeam, 'konzept_preisband_verletzt'))->toBe(1);

    $this->konzept->update(['status' => 'draft']);

    expect($this->dq->countFor($this->rootTeam, 'konzept_preisband_verletzt'))->toBe(0);
});

it('löst das Konzept in beide Richtungen auf und emittiert ein Signal', function () {
    $this->frames->setHead($this->rootTeam, $this->frames->frameFor($this->rootTeam, 'concept', $this->konzept->id), ['price_max_pp' => 10]);

    expect($this->dq->trifftObjekt($this->rootTeam, 'konzept_preisband_verletzt', 'concept', $this->konzept->id))->toBeTrue()
        // Der kind muss passen — dieselbe ID als Rezept gelesen darf nie treffen.
        ->and($this->dq->trifftObjekt($this->rootTeam, 'konzept_preisband_verletzt', 'recipe', $this->konzept->id))->toBeFalse();

    $this->dq->emittiereSignale($this->rootTeam);

    $sig = \Platform\FoodAlchemist\Models\FoodAlchemistSignal::visibleToTeam($this->rootTeam)
        ->where('type', SignalTyp::KonzeptPreisbandVerletzt->value)->firstOrFail();

    expect($sig->payload['ebene'])->toBe('Konzepte')
        ->and($sig->payload['anzahl'])->toBe(1)
        ->and($sig->source)->toBe('data-quality');
});

it('scopet die frame-gestützten Checks auf das Team', function () {
    $fremd = $this->makeConcept($this->childA, 'Kind-A-Buffet');
    $this->makeConceptSlot($fremd, ['sales_recipe_id' => $this->makeRecipe($this->childA, 'HG: Kind-Gericht', [
        'is_sales_recipe' => true, 'sales_wording_standard' => 'Kind-Gericht', 'sales_net' => 40.00,
    ])->id]);
    $this->frames->setHead($this->childA, $this->frames->frameFor($this->childA, 'concept', $fremd->id), ['price_max_pp' => 10]);

    expect($this->dq->countFor($this->childA, 'konzept_preisband_verletzt'))->toBe(1)
        ->and($this->dq->countFor($this->childB, 'konzept_preisband_verletzt'))->toBe(0);
});
