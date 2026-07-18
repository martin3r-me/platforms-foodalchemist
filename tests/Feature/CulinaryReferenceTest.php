<?php

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Tools\ToolRegistry;
use Platform\FoodAlchemist\Services\CulinaryReferenceService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

/**
 * #513 Tier 1 / Punkt 2 — Kerntemperatur-Referenz: QUALITÄTS-Zielwerte (weich),
 * Sicherheit als Zeit-Temperatur-Kontext, harte Böden nur bei Hackfleisch.
 */
uses(TestCase::class, SeedsTeamHierarchy::class);

beforeEach(function () {
    $this->svc = app(CulinaryReferenceService::class);
});

it('Qualitäts-Zielwerte: Rind rosa = 52 °C, Geflügel-Brust = 68 °C (weich, keine amtliche Zahl)', function () {
    $rind = collect($this->svc->kerntemperaturen('rind'))->firstWhere('doneness', 'medium_rare');
    expect($rind['target_c'])->toBe(52)->and($rind['is_hard_safety'])->toBeFalse();

    $gefl = collect($this->svc->kerntemperaturen('gefluegel'))->firstWhere('cut', 'brust');
    expect($gefl['target_c'])->toBe(68)->and($gefl['is_hard_safety'])->toBeFalse()
        ->and($gefl['classic_safe_c'])->toBe(72);   // klassische Sofort-Kerntemp als Kontext
});

it('harte Sicherheitsböden NUR bei durchmischter Masse (Hack/Brät); ganzer Muskel nie hart', function () {
    $alle = collect($this->svc->kerntemperaturen());
    $hart = $alle->filter(fn ($r) => $r['is_hard_safety'])->pluck('protein')->all();
    // Genau die durchmischten Massen sind hart — nichts anderes.
    expect($hart)->toEqualCanonicalizing(['hackfleisch', 'gefluegel_hack', 'braet']);
    // Gegenprobe: ganze Muskelstücke (rosa) sind NIE hart.
    expect($alle->firstWhere('doneness', 'medium_rare')['is_hard_safety'])->toBeFalse();
    expect($alle->firstWhere('protein', 'hackfleisch')['safety'])->toContain('durcherhitzt');
});

it('jede Zeile trägt Quelle + Weichheits-/HACCP-Hinweis (keine nackte Zahl)', function () {
    foreach ($this->svc->kerntemperaturen() as $r) {
        expect($r['source'])->not->toBe('')
            ->and($r['hinweis'])->toContain('Vorrang')
            ->and($r['evidence'])->toBe('med');
    }
});

it('protein-Filter grenzt ein; unbekannt → leer', function () {
    expect(collect($this->svc->kerntemperaturen('fisch'))->pluck('protein')->unique()->all())->toEqual(['fisch'])
        ->and($this->svc->kerntemperaturen('einhorn'))->toBe([]);
});

it('Hydrokolloid-Dosier: publizierte Ranges (% vom Ansatz) + Cofaktor + Hinweis', function () {
    $agar = collect($this->svc->hydrokolloidDosierungen('agar'))->firstWhere('application', 'festes Gel');
    expect($agar['dose_min'])->toBe(0.5)->and($agar['dose_max'])->toBe(2.0)
        ->and($agar['thermoreversible'])->toBeTrue()
        ->and($agar['hinweis'])->toContain('Herstellerangabe');
    // Alginat trägt den Sphärifikation-Cofaktor
    expect(collect($this->svc->hydrokolloidDosierungen('alginat'))->first()['needs'])->toContain('Calcium');
});

it('HLB: Skala-Logik (<6 W/O, >8 O/W) über type abgebildet', function () {
    $hlb = collect($this->svc->hlbWerte());
    expect($hlb->firstWhere('emulsifier', 'polysorbat80')['type'])->toBe('o_w')       // HLB 15
        ->and($hlb->firstWhere('emulsifier', 'span60')['type'])->toBe('w_o')          // HLB 4,7
        ->and($hlb->firstWhere('emulsifier', 'sojalecithin')['hlb'])->toBe(8.0);
});

it('MCP reference.GET: alle drei kinds, read-only, Disclaimer + Zeilen', function () {
    $this->seedTeamHierarchy();
    $user = $this->makeUser($this->rootTeam);
    $this->actingAs($user);
    $tool = app(ToolRegistry::class)->get('foodalchemist.reference.GET');
    $ctx = new ToolContext($user, $this->rootTeam);

    expect($tool)->not->toBeNull()->and($tool->getMetadata()['read_only'])->toBeTrue();

    $temp = $tool->execute(['kind' => 'core_temp', 'protein' => 'rind'], $ctx);
    expect($temp->success)->toBeTrue()
        ->and($temp->data['disclaimer'])->toContain('Zeit-Temperatur')
        ->and(collect($temp->data['rows'])->firstWhere('doneness', 'medium_rare')['target_c'])->toBe(52);

    $hydro = $tool->execute(['kind' => 'hydrocolloid', 'filter' => 'xanthan'], $ctx);
    expect($hydro->success)->toBeTrue()
        ->and(collect($hydro->data['rows'])->first()['agent'])->toBe('xanthan');

    $hlb = $tool->execute(['kind' => 'hlb'], $ctx);
    expect($hlb->success)->toBeTrue()->and($hlb->data['rows'])->not->toBeEmpty();

    // unbekannte kind → Fehler
    expect($tool->execute(['kind' => 'foo'], $ctx)->success)->toBeFalse();
});
