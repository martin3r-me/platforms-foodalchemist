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
        ->and(SignalCockpit::planFor(mkSignal('datenqualitaet_gp_la', 'gp_kein_lead'))['fixer'])->toBe('lead_la')
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

it('gibt keinen KI-Knopf für Lagen, in denen ein Fixer nichts bewegen könnte', function () {
    // 22·H4b/V-033 — die Aussage dieses Tests hat sich VERSCHOBEN, nicht abgeschwächt:
    // geprüft wird weiterhin „hier gibt es keinen Knopf", aber über `kiPlan()` statt über
    // `planFor() === null`. Seit H4b tragen dieselben Lagen einen `navigate`-Weg-Satz —
    // ein Erklärtext ohne Executor. Die Garantie „kein Knopf" bleibt damit wortgleich,
    // die Garantie „gar nichts" war nie gewollt (sie WAR der Befund V-033).
    foreach (['gp_tentative_genutzt', 'gp_kein_la', 'gp_kein_preis'] as $metrik) {
        expect(SignalCockpit::kiPlan(mkSignal('datenqualitaet_gp_la', $metrik)))->toBeNull()
            ->and(SignalCockpit::planFor(mkSignal('datenqualitaet_gp_la', $metrik))['kind'])->toBe('navigate');
    }
    expect(SignalCockpit::kiPlan(mkSignal('naehrwert_plausi')))->toBeNull()
        ->and(SignalCockpit::kiPlan(mkSignal('veraltete_preise')))->toBeNull()
        ->and(SignalCockpit::kiPlan(mkSignal('vertragsfrist_faellig')))->toBeNull()
        ->and(SignalCockpit::kiPlan(mkSignal('widerspruch_wissen_graph')))->toBeNull();

    // Und die eine Lage, die auch keinen Weg hat: die Begründung steht trotzdem bereit.
    expect(SignalCockpit::planFor(mkSignal('vertragsfrist_faellig')))->toBeNull()
        ->and(SignalCockpit::ohneWegGrund(mkSignal('vertragsfrist_faellig')))->not->toBeNull();
});

it('gibt der Rezept-Kategorie einen Assist-Vorschlag und lässt den Rest von Tranche A knopflos', function () {
    $plan = SignalCockpit::planFor(mkSignal('rezept_kategorie_problem', 'rezept_kategorie_problem'));

    expect($plan['kind'])->toBe('assist')
        ->and($plan['prompt'])->toBe('signal.recipe_category_suggest')
        ->and($plan['plan'])->not->toBeEmpty();
        // (Dass der Prompt-Key auch in der Registry steht, prüft RezeptQualitaetSignaleTest —
        //  dieser Unit-Test läuft ohne App-Container.)

    // Bewusst ohne Knopf: Küchen-Wissen am Einzelfall bzw. reine Entscheidung — ein Sammel-propose
    // über 15 Beispiele würde hier Scheinsicherheit erzeugen. Seit 22·H4b haben vier der fünf
    // dafür einen Weg-Satz (`navigate`), `rezept_verwaist` eine Begründung — knopflos bleiben alle.
    foreach (['rezept_ohne_zubereitung', 'rezept_mengen_luecke', 'rezept_ein_zutat', 'rezept_verwaist',
        'rezept_zutaten_ungemappt'] as $typ) {
        expect(SignalCockpit::kiPlan(mkSignal($typ, $typ)))->toBeNull();
    }
    expect(SignalCockpit::planFor(mkSignal('rezept_verwaist', 'rezept_verwaist')))->toBeNull()
        ->and(SignalCockpit::planFor(mkSignal('rezept_mengen_luecke', 'rezept_mengen_luecke'))['kind'])->toBe('navigate');
});

it('leitet den Detektor-gp-ohne-la-Fix aus dem dedup_key ab (kein payload.metrik)', function () {
    $plan = SignalCockpit::planFor(mkSignal('datenqualitaet_gp_la', null, 'datenqualitaet-gp-ohne-la'));
    expect($plan)->not->toBeNull()
        ->and($plan['kind'])->toBe('deterministic')
        ->and($plan['fixer'])->toBe('lead_la');
});
