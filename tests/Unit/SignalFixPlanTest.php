<?php

use Platform\FoodAlchemist\Models\FoodAlchemistSignal;
use Platform\FoodAlchemist\Support\SignalCockpit;

/**
 * „KI erledigen lassen" — Plan-Mapping (metrik-fein, pur/kein DB). Sperrt die
 * Dispatch-Tabelle: welcher Signaltyp/Metrik ist deterministic|assist|none.
 */
function mkSignal(string $type, ?string $metrik = null, ?string $dedup = null): FoodAlchemistSignal
{
    $s = new FoodAlchemistSignal();
    $s->type = $type;
    $s->payload = $metrik !== null ? ['metrik' => $metrik, 'anzahl' => 5] : ['anzahl' => 5];
    $s->dedup_key = $dedup;

    return $s;
}

it('mappt Metriken auf deterministische Fixer', function () {
    expect(SignalCockpit::planFor(mkSignal('datenqualitaet_gp_la', 'gp_allergen_konfidenz'))['fixer'])->toBe('allergen')
        ->and(SignalCockpit::planFor(mkSignal('datenqualitaet_gp_la', 'gp_ohne_lead'))['fixer'])->toBe('lead_la')
        ->and(SignalCockpit::planFor(mkSignal('datenqualitaet_gp_la', 'gp_lead_ohne_preis'))['fixer'])->toBe('lead_la')
        ->and(SignalCockpit::planFor(mkSignal('anker_fehlt', 'br_anker_fehlt'))['fixer'])->toBe('recipe_anker')
        ->and(SignalCockpit::planFor(mkSignal('anker_fehlt', 'vk_anker_fehlt'))['fixer'])->toBe('recipe_anker')
        ->and(SignalCockpit::planFor(mkSignal('anker_fehlt', 'gp_anker_fehlt'))['fixer'])->toBe('gp_anker')
        ->and(SignalCockpit::planFor(mkSignal('ek_kette_unvollstaendig', 'vk_ek_null'))['fixer'])->toBe('recompute');
});

it('mappt Assist-Typen auf einen Prompt-Key', function () {
    expect(SignalCockpit::planFor(mkSignal('servierform_unbestimmt', 'vk_servierform_unbestimmt'))['kind'])->toBe('assist')
        ->and(SignalCockpit::planFor(mkSignal('preis_sprung_marge_impact'))['prompt'])->toBe('signal.supplier_inquiry')
        ->and(SignalCockpit::planFor(mkSignal('marge_unter_ziel'))['prompt'])->toBe('signal.margin_levers')
        ->and(SignalCockpit::planFor(mkSignal('preis_anomalie'))['prompt'])->toBe('price.plausi');
});

it('gibt kein Plan (null) für reine Urteilssachen', function () {
    expect(SignalCockpit::planFor(mkSignal('datenqualitaet_gp_la', 'gp_tentative_genutzt')))->toBeNull()
        ->and(SignalCockpit::planFor(mkSignal('naehrwert_plausi')))->toBeNull()
        ->and(SignalCockpit::planFor(mkSignal('veraltete_preise')))->toBeNull()
        ->and(SignalCockpit::planFor(mkSignal('vertragsfrist_faellig')))->toBeNull()
        ->and(SignalCockpit::planFor(mkSignal('widerspruch_wissen_graph')))->toBeNull();
});

it('leitet den Detektor-gp-ohne-la-Fix aus dem dedup_key ab (kein payload.metrik)', function () {
    $plan = SignalCockpit::planFor(mkSignal('datenqualitaet_gp_la', null, 'datenqualitaet-gp-ohne-la'));
    expect($plan)->not->toBeNull()
        ->and($plan['kind'])->toBe('deterministic')
        ->and($plan['fixer'])->toBe('lead_la');
});
