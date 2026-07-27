<?php

use Platform\FoodAlchemist\Models\FoodAlchemistFoodbook;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeDarreichung;
use Platform\FoodAlchemist\Models\FoodAlchemistSaison;
use Platform\FoodAlchemist\Models\FoodAlchemistServierform;
use Platform\FoodAlchemist\Services\MenuAssemblyService;
use Platform\FoodAlchemist\Services\PlanningFrameService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * 12·S2a-3 (R2.4) — die Erklärung: welche Vorgaben binden, wie viel DB liegt hinter jeder.
 *
 * Der tragende Test ist der erste, und zwar mit **hand-gerechnetem** Lockerungs-Delta: die
 * Basis ist die Optimallösung unter dem Preisdeckel (31 €), ohne Deckel wäre es 42 € — also
 * genau 11 € p. P., die der Deckel kostet. Ein Test, der nur „delta > 0" prüft, wäre auch
 * mit einer falschen Zahl grün.
 *
 * Die zweite Zusicherung ist ebenso wichtig und leicht zu übersehen: `erklaere()` darf die
 * **Antwort nicht verändern**. Die Erklärung fährt den Motor mehrfach; wenn dabei die Basis
 * kippt, ist die Erklärung wertlos — deshalb der Byte-Vergleich gegen `assembliere()`.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->assembly = app(MenuAssemblyService::class);
    $this->frames = app(PlanningFrameService::class);

    $this->sf = FoodAlchemistServierform::firstOrCreate(
        ['code' => 'unbestimmt', 'team_id' => $this->rootTeam->id],
        ['label' => 'Unbestimmt']
    );

    $this->mk = function (string $key, string $name, ?float $vk, ?float $ek, array $attr = []): FoodAlchemistRecipe {
        $r = FoodAlchemistRecipe::create(array_merge([
            'team_id' => $this->rootTeam->id, 'recipe_key' => $key, 'name' => $name,
            'status' => 'approved', 'is_sales_recipe' => true,
        ], $attr));
        if ($vk !== null || $ek !== null) {
            FoodAlchemistRecipeDarreichung::create([
                'team_id' => $this->rootTeam->id, 'recipe_id' => $r->id, 'serving_form_id' => $this->sf->id,
                'is_standard' => true, 'sales_net' => $vk, 'ek_portion' => $ek,
            ]);
        }

        return $r;
    };

    $fb = FoodAlchemistFoodbook::create(['team_id' => $this->rootTeam->id, 'label' => 'Erklaerung-FB']);
    $this->frame = $this->frames->frameFor($this->rootTeam, 'foodbook', $fb->id);

    // Dieselbe Slot-Trennung wie im Motor-Test: zwei Slots, disjunkte Mengen über
    // Namens-No-Gos, damit die Preisbänder als Constraint frei bleiben.
    $this->zweiSlots = function (): array {
        $a = $this->frames->addSlot($this->rootTeam, $this->frame, ['label' => 'Vorspeisen', 'target_count' => 1]);
        $b = $this->frames->addSlot($this->rootTeam, $this->frame, ['label' => 'Hauptgänge', 'target_count' => 1]);
        $this->frames->addRule($this->rootTeam, $this->frame, ['rule_type' => 'nogo_ingredient', 'slot_id' => $a->id, 'value_text' => 'hauptgang']);
        $this->frames->addRule($this->rootTeam, $this->frame, ['rule_type' => 'nogo_ingredient', 'slot_id' => $b->id, 'value_text' => 'vorspeise']);

        return [$a, $b];
    };

    // Fixture des Motor-Tests: unter dem Deckel 36 € ist A2+B1 = 31 € optimal,
    // ohne Deckel A1+B1 = 42 €.
    $this->vierGerichte = function (): array {
        return [
            ($this->mk)('a1', 'Vorspeise: Tartar', 20.00, 2.00),   // DB 18
            ($this->mk)('a2', 'Vorspeise: Suppe', 10.00, 3.00),    // DB  7
            ($this->mk)('b1', 'Hauptgang: Rind', 25.00, 1.00),     // DB 24
            ($this->mk)('b2', 'Hauptgang: Fisch', 15.00, 6.00),    // DB  9
        ];
    };
});

it('bindender Preisdeckel: das Delta ist hand-gerechnet, nicht nur positiv', function () {
    ($this->vierGerichte)();
    ($this->zweiSlots)();
    $this->frames->setHead($this->rootTeam, $this->frame, ['price_max_pp' => 36.00]);

    $res = $this->assembly->erklaere($this->rootTeam, $this->frame->refresh(), 100);
    $e = $res['erklaerung'];

    // Die Basis bleibt die Optimallösung unter dem Deckel
    expect($e['basis']['db_pp'])->toBe(31.0)
        ->and($e['basis']['exakt'])->toBeTrue()
        ->and($e['hinweis'])->toBeNull();

    $deckel = collect($e['constraints'])->firstWhere('schluessel', 'preisband_max');
    expect($deckel['bindend'])->toBeTrue()
        // 42 € ohne Deckel − 31 € mit = genau 11 € p. P.
        ->and($deckel['delta_db_pp'])->toBe(11.0)
        ->and($deckel['db_pp_gelockert'])->toBe(42.0)
        ->and($deckel['delta_db_gaeste'])->toBe(1100.0)
        ->and($deckel['delta_ist_untergrenze'])->toBeFalse()
        // Das Preisband p. P. ist KEIN Kandidaten-Filter: die zulässige Menge je Slot
        // bleibt gleich, nur die Kombination wird frei. Diese Unterscheidung (Menü-weit
        // vs. harter Slot-Filter) ist der halbe Erklärwert.
        ->and($deckel['kandidaten_delta'])->toBe(0)
        // Ehrlich beziffert, nicht schöngerechnet: die 11 € kosten die Preis-Vorgabe.
        ->and($deckel['delta_verletzungen'])->toBe(1)
        ->and($e['bindend'])->toContain('preisband_max');

    // Der größte Hebel steht oben
    expect($e['constraints'][0]['schluessel'])->toBe('preisband_max');
});

it('erklaere() verändert die Antwort nicht — Byte-Vergleich gegen assembliere()', function () {
    ($this->vierGerichte)();
    ($this->zweiSlots)();
    $this->frames->setHead($this->rootTeam, $this->frame, ['price_max_pp' => 36.00]);

    $roh = $this->assembly->assembliere($this->rootTeam, $this->frame->refresh(), 100);
    $mit = $this->assembly->erklaere($this->rootTeam, $this->frame->refresh(), 100);

    expect(array_key_exists('erklaerung', $mit))->toBeTrue();
    unset($mit['erklaerung']);
    expect($mit)->toEqual($roh);
});

it('nicht bindende Vorgabe wird als solche ausgewiesen — 0 € und keine Kandidaten mehr', function () {
    ($this->vierGerichte)();
    ($this->zweiSlots)();
    // No-Go auf einen Begriff, den kein Gericht trägt → schließt nichts aus
    $regel = $this->frames->addRule($this->rootTeam, $this->frame, ['rule_type' => 'nogo_ingredient', 'value_text' => 'kaviar']);

    $res = $this->assembly->erklaere($this->rootTeam, $this->frame->refresh());
    $zeile = collect($res['erklaerung']['constraints'])->firstWhere('schluessel', 'regel_' . $regel->id);

    expect($zeile['bindend'])->toBeFalse()
        ->and($zeile['delta_db_pp'])->toBe(0.0)
        ->and($zeile['kandidaten_delta'])->toBe(0)
        ->and($zeile['delta_verletzungen'])->toBe(0)
        ->and($res['erklaerung']['bindend'])->not->toContain('regel_' . $regel->id);
});

it('harter Slot-Preisrahmen: das Delta kommt aus mehr Kandidaten, nicht aus mehr Freiheit', function () {
    // Der Slot-Rahmen schließt das teure Tartar (20 €) aus; ohne ihn ist es zulässig
    // und hebt das DB von 7 + 24 = 31 auf 18 + 24 = 42.
    ($this->vierGerichte)();
    [$a] = ($this->zweiSlots)();
    $this->frames->updateSlot($this->rootTeam, $a->id, ['price_max' => 12.00]);

    $res = $this->assembly->erklaere($this->rootTeam, $this->frame->refresh());
    $zeile = collect($res['erklaerung']['constraints'])->firstWhere('schluessel', 'slot_preis_' . $a->id);

    expect($res['erklaerung']['basis']['db_pp'])->toBe(31.0)
        ->and($zeile['bindend'])->toBeTrue()
        ->and($zeile['delta_db_pp'])->toBe(11.0)
        // Hier ist es umgekehrt zum Preisband p. P.: der harte Filter lässt einen
        // Kandidaten mehr zu, und genau daher kommt das Delta.
        ->and($zeile['kandidaten_delta'])->toBe(1)
        ->and($zeile['ebene'])->toBe('slot')
        ->and($zeile['slot_id'])->toBe($a->id);
});

it('bindendes No-Go: die Lockerung läuft durch DENSELBEN Filter, nicht durch einen Nachbau', function () {
    // Das No-Go schließt das DB-stärkste Gericht aus (Tartar, DB 18) → Basis 7 + 24 = 31.
    // Ohne das No-Go sind 18 + 24 = 42 möglich. Dieser Fall ist der Beweis, dass die
    // Regel-Lockerung im Pool-Filter wirklich greift — die Quoten-Lockerung würde ihn
    // nicht erbringen (sie sitzt im Solver, nicht im Filter).
    ($this->vierGerichte)();
    ($this->zweiSlots)();
    $regel = $this->frames->addRule($this->rootTeam, $this->frame, ['rule_type' => 'nogo_ingredient', 'value_text' => 'tartar']);

    $res = $this->assembly->erklaere($this->rootTeam, $this->frame->refresh());
    $zeile = collect($res['erklaerung']['constraints'])->firstWhere('schluessel', 'regel_' . $regel->id);

    expect($res['erklaerung']['basis']['db_pp'])->toBe(31.0)
        ->and($zeile['bindend'])->toBeTrue()
        ->and($zeile['delta_db_pp'])->toBe(11.0)
        ->and($zeile['kandidaten_delta'])->toBe(1)
        // Die Coverage-Ampel zählt das gebrochene No-Go mit — das Geld ist nicht gratis.
        ->and($zeile['delta_verletzungen'])->toBe(1);
});

it('bindende Diät-Quote: die Erklärung und die Coverage-Ampel sehen dieselbe Vorgabe', function () {
    ($this->mk)('a1', 'Vorspeise: Tartar', 20.00, 2.00);
    ($this->mk)('a2', 'Vorspeise: Suppe', 10.00, 3.00, ['spec_is_vegan' => true]);
    ($this->mk)('b1', 'Hauptgang: Rind', 25.00, 1.00);
    ($this->mk)('b2', 'Hauptgang: Fisch', 15.00, 6.00);
    ($this->zweiSlots)();
    $quote = $this->frames->addRule($this->rootTeam, $this->frame, [
        'rule_type' => 'diet_quota', 'ref_key' => 'vegan', 'operator' => 'min', 'value_num' => 1, 'unit' => 'count',
    ]);

    $res = $this->assembly->erklaere($this->rootTeam, $this->frame->refresh());
    $zeile = collect($res['erklaerung']['constraints'])->firstWhere('schluessel', 'regel_' . $quote->id);

    // Basis erfüllt die Quote (31 €); ohne Quote wären 42 € möglich → die Quote kostet 11 €
    expect($res['erklaerung']['basis']['db_pp'])->toBe(31.0)
        ->and($zeile['bindend'])->toBeTrue()
        ->and($zeile['delta_db_pp'])->toBe(11.0)
        // Erklärung und Ampel bleiben verzahnt: die Basis erfüllt sie, die Lockerung
        // bricht sie — gemessen von derselben Coverage-Ampel, keine zweite Elle.
        ->and($zeile['delta_verletzungen'])->toBe(1)
        ->and(collect($res['befunde'])->firstWhere('dimension', 'diaet')['ampel'])->toBe('erfuellt');
});

it('nicht lockerbare Vorgaben werden mit Grund benannt, nicht weggelassen', function () {
    ($this->vierGerichte)();
    ($this->zweiSlots)();
    $saison = FoodAlchemistSaison::create(['team_id' => $this->rootTeam->id, 'name' => 'Frühling']);
    $this->frames->addRule($this->rootTeam, $this->frame, ['rule_type' => 'season_coverage', 'ref_id' => $saison->id]);
    $this->frames->addRule($this->rootTeam, $this->frame, ['rule_type' => 'allergen_line', 'value_text' => 'nussfreie Linie']);

    $res = $this->assembly->erklaere($this->rootTeam, $this->frame->refresh());
    $nicht = implode(' | ', $res['erklaerung']['nicht_gelockert']);

    expect($nicht)->toContain('Saison-Abdeckung')
        ->and($nicht)->toContain('Allergen-Linie')
        ->and($nicht)->toContain('Platzzahl ist das Gerüst')
        // und keine dieser Vorgaben ist als geprüfte Lockerung gezählt
        ->and(collect($res['erklaerung']['constraints'])->pluck('typ')->all())
        ->not->toContain('season_coverage');
});

it('Allergen-No-Go wird beziffert, aber mit Warnung — Geld rechtfertigt keine Kennzeichnung', function () {
    ($this->vierGerichte)();
    ($this->zweiSlots)();
    $regel = $this->frames->addRule($this->rootTeam, $this->frame, ['rule_type' => 'nogo_allergen', 'ref_key' => 'gluten']);

    $res = $this->assembly->erklaere($this->rootTeam, $this->frame->refresh());
    $zeile = collect($res['erklaerung']['constraints'])->firstWhere('schluessel', 'regel_' . $regel->id);

    expect($zeile['typ'])->toBe('nogo_allergen')
        ->and($zeile['warnung'])->toContain('Gast- und Kennzeichnungsfrage');
});

it('Zielpreis wird ausgewiesen, weil er NICHT in der Zielfunktion steht (V-061)', function () {
    ($this->vierGerichte)();
    ($this->zweiSlots)();
    $this->frames->setHead($this->rootTeam, $this->frame, ['target_price_pp' => 20.00, 'price_max_pp' => 36.00]);

    $res = $this->assembly->erklaere($this->rootTeam, $this->frame->refresh());
    $zp = $res['erklaerung']['zielpreis'];

    // Der Motor fährt an die Obergrenze (35 €), nicht an den Zielpreis (20 €) — das ist
    // gewollt und wird deshalb benannt statt kaschiert.
    expect($zp['ziel_pp'])->toBe(20.0)
        ->and($zp['ist_pp'])->toBe(35.0)
        ->and($zp['abweichung_pp'])->toBe(15.0)
        ->and($zp['in_zielfunktion'])->toBeFalse()
        ->and($zp['hinweis'])->toContain('zwei Aufträge');
});

it('heuristische Basis macht jedes Delta zur Untergrenze (V-062) — statt es als Abstand zu verkaufen', function () {
    for ($i = 1; $i <= 21; $i++) {
        ($this->mk)('h' . $i, 'Gericht: Nr ' . str_pad((string) $i, 2, '0', STR_PAD_LEFT), 10.00 + $i, 4.00);
    }
    $this->frames->addSlot($this->rootTeam, $this->frame, ['label' => 'Buffet', 'target_count' => 10]);
    $this->frames->setHead($this->rootTeam, $this->frame, ['price_max_pp' => 1000.00]);

    $res = $this->assembly->erklaere($this->rootTeam, $this->frame->refresh());
    $e = $res['erklaerung'];

    expect($e['basis']['exakt'])->toBeFalse()
        ->and($e['hinweis'])->toContain('Untergrenze');
    foreach ($e['constraints'] as $z) {
        expect($z['delta_ist_untergrenze'])->toBeTrue();
    }
});

it('viele Vorgaben werden gekappt — und die Kappung sagt es (kein stilles Abschneiden)', function () {
    ($this->vierGerichte)();
    ($this->zweiSlots)();
    // 14 No-Gos, die nichts ausschließen → über LOCKERUNGEN_MAX (12)
    for ($i = 1; $i <= 14; $i++) {
        $this->frames->addRule($this->rootTeam, $this->frame, ['rule_type' => 'nogo_ingredient', 'value_text' => 'unbekannt' . $i]);
    }

    $res = $this->assembly->erklaere($this->rootTeam, $this->frame->refresh());
    $e = $res['erklaerung'];

    expect($e['abgeschnitten'])->toBeTrue()
        ->and($e['geprueft'])->toBe(MenuAssemblyService::LOCKERUNGEN_MAX)
        ->and($e['lockerbar_gesamt'])->toBeGreaterThan(MenuAssemblyService::LOCKERUNGEN_MAX);
});

it('Gerüst ohne Slots ist auch für die Erklärung ein Fehler, keine leere Antwort', function () {
    expect(fn () => $this->assembly->erklaere($this->rootTeam, $this->frame->refresh()))
        ->toThrow(RuntimeException::class, 'ohne Slots');
});
