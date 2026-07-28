<?php

use Platform\FoodAlchemist\Enums\SignalTyp;
use Platform\FoodAlchemist\Models\FoodAlchemistSignal;
use Platform\FoodAlchemist\Services\DataQualityService;
use Platform\FoodAlchemist\Support\SignalCockpit;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 22 · H4b (V-033) — die §9-Registry: **kein Signal ohne Weg oder Begründung.**
 *
 * Spec 21 §9 verlangt „kein Signal ohne Fixer-Weg"; bis H4b war das eine Absicht, die
 * niemand nachhielt — 29 der 37 Typen fielen im Panel auf `null` und rendern gar nichts,
 * womit drei verschiedene Lagen identisch aussahen: echte Urteilssache, „der Weg führt
 * woanders hin" und „die Ursache liegt ausserhalb". Dieser Test macht daraus eine
 * Zusicherung: **jeder** Typ ist genau einer Antwort zugeordnet, und jede einzelne
 * Metrik-Lage der metrik-feinen Typen löst ebenfalls auf.
 *
 * Zwei Ebenen, weil die Registry zwei Ebenen hat:
 *  1. typ-grob über `SignalTyp::cases()` (pur, ohne DB) — Fixer/Assist/navigate/ohne-Weg
 *     bzw. die ausdrückliche Verweisung „entscheidet sich an der Metrik".
 *  2. metrik-fein über die **echten** Deskriptoren aus `DataQualityService::messeAlleEbenen()`
 *     — nicht über eine im Test nachgebaute Liste, die vom Original wegdriften würde.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
});

/** Signal-Attrappe ohne DB (die Plan-Auflösung ist pur). */
function wegSignal(string $type, ?string $metrik = null): FoodAlchemistSignal
{
    $s = new FoodAlchemistSignal();
    $s->type = $type;
    $s->payload = $metrik !== null ? ['metrik' => $metrik, 'anzahl' => 3] : ['anzahl' => 3];

    return $s;
}

it('ordnet jeden Signal-Typ genau einer Antwort zu (Assist | navigate | ohne Weg | metrik-fein)', function () {
    $reg = SignalCockpit::wegRegistry();

    foreach (SignalTyp::cases() as $typ) {
        $treffer = array_keys(array_filter(
            $reg,
            fn (array $keys) => in_array($typ->value, $keys, true)
        ));

        expect(count($treffer))->toBe(1, sprintf(
            'Signal-Typ %s ist %s zugeordnet — erwartet genau eine Kategorie aus: %s',
            $typ->value,
            $treffer === [] ? 'KEINER Kategorie' : 'mehreren (' . implode(', ', $treffer) . ')',
            implode(' | ', array_keys($reg))
        ));
    }
});

it('kennt keine Registry-Einträge für Typen, die es gar nicht gibt', function () {
    // Gegenrichtung zum Test oben: ein Tippfehler im Map-Key (oder ein gelöschter Typ)
    // bliebe sonst als toter Satz liegen und die Registry wäre stillschweigend falsch.
    $bekannt = array_map(fn (SignalTyp $t) => $t->value, SignalTyp::cases());

    foreach (SignalCockpit::wegRegistry() as $kategorie => $keys) {
        foreach ($keys as $key) {
            expect(in_array($key, $bekannt, true))
                ->toBeTrue("Registry-Kategorie {$kategorie} nennt den unbekannten Signal-Typ {$key}.");
        }
    }
});

it('gibt jedem Typ ohne Knopf entweder einen Weg-Satz oder eine ausdrückliche Begründung', function () {
    $reg = SignalCockpit::wegRegistry();

    foreach (SignalTyp::cases() as $typ) {
        if (in_array($typ->value, $reg['metrik_fein'], true)) {
            continue;   // wird unten je Lage geprüft
        }
        $sig = wegSignal($typ->value, $typ->value);
        $plan = SignalCockpit::planFor($sig);
        $grund = SignalCockpit::ohneWegGrund($sig);

        expect($plan !== null || $grund !== null)
            ->toBeTrue("Signal-Typ {$typ->value} rendert weder Plan noch Begründung.");
        expect(trim((string) ($plan['plan'] ?? $grund)))->not->toBe('');
    }
});

it('löst jede einzelne Metrik-Lage auf — gemessen an den echten Deskriptoren, nicht an einer Kopie', function () {
    $ebenen = app(DataQualityService::class)->messeAlleEbenen($this->rootTeam);

    $geprueft = 0;
    foreach ($ebenen as $ebene) {
        foreach ($ebene['metriken'] as $m) {
            if (($m['signal'] ?? null) === null) {
                continue;   // Kennzahl ohne Signal-Deskriptor — nichts, was im Panel landet
            }
            $sig = wegSignal($m['signal']['typ']->value, $m['key']);
            $plan = SignalCockpit::planFor($sig);
            $grund = SignalCockpit::ohneWegGrund($sig);

            expect($plan !== null || $grund !== null)->toBeTrue(
                "Metrik {$m['key']} ({$m['signal']['typ']->value}) rendert weder Plan noch Begründung."
            );
            $geprueft++;
        }
    }

    // Schutz gegen eine Schleife, die nie zuschlägt (die Fixture misst alles auf 0 —
    // die Deskriptoren kommen trotzdem, weil `gap()` sie wertunabhängig baut).
    expect($geprueft)->toBeGreaterThan(20);
});

it('trennt den ausführbaren Plan vom Weg-Satz — `navigate` ist kein KI-Knopf', function () {
    // Der Kern der H4b-Verschiebung: `planFor() !== null` heißt ab hier „es gibt etwas zu
    // sagen", `kiPlan() !== null` heißt „es gibt einen Knopf". Wer beides verwechselt,
    // rendert einen Knopf, der beim Klick eine RuntimeException zeigt.
    $weg = wegSignal('rezept_plausi_ki', 'rezept_plausi_ki');
    expect(SignalCockpit::planFor($weg)['kind'])->toBe('navigate')
        ->and(SignalCockpit::kiPlan($weg))->toBeNull()
        ->and(SignalCockpit::ohneWegGrund($weg))->toBeNull();

    $fix = wegSignal('datenqualitaet_gp_la', 'gp_kein_lead');
    expect(SignalCockpit::kiPlan($fix)['kind'])->toBe('deterministic');

    $assist = wegSignal('marge_unter_ziel');
    expect(SignalCockpit::kiPlan($assist)['kind'])->toBe('assist');

    $stumm = wegSignal('rezept_verwaist', 'rezept_verwaist');
    expect(SignalCockpit::planFor($stumm))->toBeNull()
        ->and(SignalCockpit::kiPlan($stumm))->toBeNull()
        ->and(SignalCockpit::ohneWegGrund($stumm))->not->toBeNull();
});

it('MCP signale.FIX: gibt den Weg statt einer nackten Absage weiter (Lockstep)', function () {
    // Ohne diesen Satz liest ein Agent dreimal dieselbe „nicht verfügbar"-Antwort und hat
    // nach dem dritten Mal so wenig gelernt wie nach dem ersten. Mit ihm kann er dem
    // Menschen sagen, wo entschieden wird — mehr darf er hier ohnehin nicht.
    $user = $this->makeUser($this->rootTeam);
    $this->actingAs($user);
    $tool = app(\Platform\Core\Tools\ToolRegistry::class)->get('foodalchemist.signale.FIX');
    $ctx = new \Platform\Core\Contracts\ToolContext($user, $this->rootTeam);

    $weg = FoodAlchemistSignal::create([
        'team_id' => $this->rootTeam->id,
        'type' => SignalTyp::KonzeptSlotLuecke,
        'severity' => \Platform\FoodAlchemist\Enums\SignalSeverity::Warnung,
        'title' => 'Konzept mit unbesetztem Pflicht-Slot',
        'dedup_key' => 'dq-konzept-slot-luecke',
        'payload' => ['metrik' => 'konzept_slot_luecke'],
    ]);
    $res = $tool->execute(['signal_id' => (int) $weg->id], $ctx);
    expect($res->success)->toBeFalse()
        ->and($res->errorCode)->toBe('ACTION_NOT_AVAILABLE')
        ->and($res->error)->toContain('aber ein Weg')
        ->and($res->error)->toContain('Concepter');

    $stumm = FoodAlchemistSignal::create([
        'team_id' => $this->rootTeam->id,
        'type' => SignalTyp::VertragsfristFaellig,
        'severity' => \Platform\FoodAlchemist\Enums\SignalSeverity::Warnung,
        'title' => 'Vertragsfrist läuft ab',
        'dedup_key' => 'vertragsfrist-1',
        'payload' => [],
    ]);
    $res2 = $tool->execute(['signal_id' => (int) $stumm->id], $ctx);
    expect($res2->success)->toBeFalse()
        ->and($res2->errorCode)->toBe('ACTION_NOT_AVAILABLE')
        ->and($res2->error)->toContain('keinen Weg im System');
});

it('lehnt einen navigate-Plan im Executor genauso ab wie gar keinen', function () {
    // `navigate` darf niemals in den Fix-/Assist-Pfad geraten — der Satz ist eine
    // Wegbeschreibung, kein Auftrag.
    $svc = app(\Platform\FoodAlchemist\Services\SignalFixService::class);
    $sig = FoodAlchemistSignal::create([
        'team_id' => $this->rootTeam->id,
        'type' => SignalTyp::FoodbookKapitelOhneText,
        'severity' => \Platform\FoodAlchemist\Enums\SignalSeverity::Info,
        'title' => 'Kapitel ohne Hinführung',
        'dedup_key' => 'dq-foodbook-kapitel-ohne-text',
        'payload' => ['metrik' => 'foodbook_kapitel_ohne_text'],
    ]);

    expect(SignalCockpit::planFor($sig)['kind'])->toBe('navigate');
    expect(fn () => $svc->execute($this->rootTeam, $sig))->toThrow(RuntimeException::class);
    expect(fn () => $svc->assist($this->rootTeam, $sig))->toThrow(RuntimeException::class);
    expect(fn () => $svc->vorschau($this->rootTeam, $sig))->toThrow(RuntimeException::class);
});
