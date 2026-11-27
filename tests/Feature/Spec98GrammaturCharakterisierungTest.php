<?php

use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Services\Rezept\DarreichungService;
use Platform\FoodAlchemist\Services\Planung\PlanungsblattService;
use Platform\FoodAlchemist\Services\Rezept\RecipeRecomputeService;
use Platform\FoodAlchemist\Tests\TestCase;
use Platform\FoodAlchemist\Tests\Support\GrammaturWelt;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\Core\Models\Team;

uses(TestCase::class, SeedsTeamHierarchy::class, GrammaturWelt::class);

/**
 * 98 · Grammatur „immer automatisch“ (Entscheid Dominique 22.11., Option 2: Varianten über Deltas je Komponente), Schritt 1:
 * Charakterisierung VOR jeder Änderung. Hält je Darreichung fest: Grammatur, EK je Portion, Auto-VK, Produktionsanteil und
 * Planungsblatt-Bedarf — für alle Arten, die die Umstellung berührt:
 *  A  Ansatz mit mehreren Portionen (Bratkartoffeln 1,2 kg, Ertrag = Komponentensumme): Standard frei 300 g, Kinder frei 150 g,
 *     „ohne Speck“ (Delta weggelassen), „Doppel“ frei 150 g × 2 Einheiten, „wie Standard“ (menge_vom_standard)
 *  B  Gericht als Komponentenliste (Roulade, Ansatz = 1 Portion): Standard menge_vom_standard (Σ Komponenten), Variante mit
 *     Gramm-Override-Delta
 *  C  fester Ertrag ≠ Komponentensumme (Schmorbraten, yield_kg_manual 0,8 kg bei 1 kg netto): Variante frei 200 g —
 *     der Fall, den die verlustfreie Umstellung NICHT schafft (EK über Ertrag vs. über Bestandteile)
 *  D  unbepreiste Komponente (Eintopf): Variante frei
 * Golden: tests/Fixtures/golden/grammatur_charakter.json. Nach der Umstellung müssen A, B und D unverändert bleiben;
 * C landet auf der Liste „nicht verlustfrei“ (Entscheid je Fall durch Dominique).
 */
beforeEach(function () {
    $this->grammaturWeltAnlegen();
});

/** Normalisierter Ist-Zustand eines Rezepts samt aller Darreichungen (Werte, die die Umstellung nicht ändern darf). */
function grammaturZustand(FoodAlchemistRecipe $r, Team $team): array
{
    $r = $r->fresh();
    $dar = app(DarreichungService::class);
    $zahl = fn ($v, int $d = 4) => $v === null ? null : round((float) $v, $d);
    $zeilen = app(RecipeRecomputeService::class)->zeilenKostenUndMassen($r, $team);
    $namen = $r->ingredients()->pluck('raw_text', 'id');
    $blatt = app(PlanungsblattService::class);

    return [
        'rezept' => ['yield_kg' => $zahl($r->yield_kg), 'yield_kg_manual' => $zahl($r->yield_kg_manual), 'ek_per_kg' => $zahl($r->ek_per_kg_eur),
            // 98 · 3.5 6b: Rezept-Kopf (sales_unit_count, sales_quantity_per_unit_g) wird nicht mehr gespiegelt, entfällt mit 6c — die Grammatur steht an der Darreichung
            'komponenten_netto_g' => $zahl(array_sum(array_column($zeilen, 'masse_g')), 1)],
        'darreichungen' => $r->presentations()->with('servingForm')->orderBy('id')->get()->map(function ($d) use ($r, $team, $dar, $zahl, $namen, $blatt) {
            $anteil = $dar->produktionsAnteil($r, $d, 10, $team);
            $b = $blatt->produktionsblattFuerZiele($team, [['recipe_id' => $r->id, 'portions' => 40, 'presentation_id' => $d->id]]);

            return [
                'form' => $d->servingForm?->code, 'standard' => (bool) $d->is_standard, 'menge_vom_standard' => (bool) $d->menge_vom_standard,
                'unit_count' => $zahl($d->unit_count), 'deltas' => $d->deltas()->count(),
                'gramm' => $zahl($dar->grammJeEinheit($d, $r), 1), 'quantity_per_unit_g' => $zahl($d->quantity_per_unit_g, 1),
                'ek_portion' => $zahl($d->ek_portion), 'calculated_sales_net' => $zahl($d->calculated_sales_net, 2), 'sales_net' => $zahl($d->sales_net, 2),
                'produktion_10' => ['ansaetze' => $zahl($anteil['ansaetze']), 'gramm_je_portion' => $zahl($anteil['gramm_je_portion'], 1),
                    'je_zutat' => $anteil['je_zutat'] === null ? null : collect($anteil['je_zutat'])->mapWithKeys(fn ($v, $id) => [$namen[$id] ?? $id => $zahl($v)])->sortKeys()->all()],
                'planungsblatt_40_kg' => collect($b['gp_bedarf'])->mapWithKeys(fn ($g) => [$g['name'] => $zahl($g['menge_kg'], 3)])->sortKeys()->all(),
            ];
        })->all(),
    ];
}

function grammaturGolden(string $schluessel, array $ist): void
{
    $g = \Platform\FoodAlchemist\Tests\Support\Golden::vergleich('grammatur_charakter', $schluessel, $ist);
    expect($g['ist'])->toBe($g['soll'], "Golden {$schluessel}");
}

it('A · Ansatz mit mehreren Portionen: Standard frei, Kinder frei, ohne Speck (Delta), Doppel (×2), wie Standard', function () {
    $z = grammaturZustand($this->a, $this->rootTeam);
    $kinder = collect($z['darreichungen'])->firstWhere('form', 'kinder');
    expect(collect($z['darreichungen'])->firstWhere('form', 'doppel')['unit_count'])->toBe(2.0)
        ->and($kinder['gramm'])->toBe(150.0)
        ->and($kinder['planungsblatt_40_kg'])->toBe(['Kartoffeln' => 5.0, 'Speck' => 1.0]);   // wie ProduktionDarreichungTest
    grammaturGolden('a_bratkartoffeln', $z);
});

it('B · Gericht als Komponentenliste: Standard = Σ Komponenten, Variante mit Gramm-Override', function () {
    $z = grammaturZustand($this->b, $this->rootTeam);
    expect(collect($z['darreichungen'])->firstWhere('form', 'teller')['gramm'])->toBe(260.0)
        ->and(collect($z['darreichungen'])->firstWhere('form', 'klein')['gramm'])->toBe(200.0);
    grammaturGolden('b_roulade', $z);
});

it('C · fester Ertrag ≠ Komponentensumme: Variante frei (verlustfreie Umstellung nicht möglich)', function () {
    $z = grammaturZustand($this->c, $this->rootTeam);
    expect($z['rezept']['komponenten_netto_g'])->not->toBe(800.0);   // genau der Unterschied, der die Umstellung verhindert
    grammaturGolden('c_schmorbraten', $z);
});

it('D · unbepreiste Komponente: Variante frei', function () {
    grammaturGolden('d_eintopf', grammaturZustand($this->d, $this->rootTeam));
});
