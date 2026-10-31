<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Platform\Core\Contracts\ToolContext;
use Platform\FoodAlchemist\Models\FoodAlchemistItemNutritional;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplier;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItemStructure;
use Platform\FoodAlchemist\Services\GpAggregateService;
use Platform\FoodAlchemist\Services\Naehrwerte\NaehrwertBefund;
use Platform\FoodAlchemist\Services\Naehrwerte\NaehrwertPruefService;
use Platform\FoodAlchemist\Services\SensorikService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;
use Platform\FoodAlchemist\Tools\NaehrwertPruefungGetTool;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Nährwert-Prüfung je Lieferantenartikel (Plan 29.10.2026). Dominique: Einheiten-Fehler automatisch je Artikel umrechnen, aber nur bei
 * eindeutiger Energie-Probe; Rohwert bleibt; nicht eindeutig = keine Umrechnung. Harte Befunde zählen (modus scharf) nicht, Hinweise rechnen mit.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam));
    $this->elbfrost = FoodAlchemistSupplier::create(['team_id' => $this->rootTeam->id, 'name' => 'Elbfrost Marl']);
    $this->hanos = FoodAlchemistSupplier::create(['team_id' => $this->rootTeam->id, 'name' => 'Hanos']);
    $this->mkLa = function (int $gpId, array $naehrwerte, ?FoodAlchemistSupplier $lieferant = null) {
        $la = FoodAlchemistSupplierItem::create(['team_id' => $this->rootTeam->id, 'supplier_id' => ($lieferant ?? $this->hanos)->id,
            'designation' => 'LA ' . $gpId . '-' . uniqid()]);
        FoodAlchemistSupplierItemStructure::create(['team_id' => $this->rootTeam->id, 'supplier_item_id' => $la->id, 'gp_id' => $gpId]);
        FoodAlchemistItemNutritional::create(array_merge(['team_id' => $this->rootTeam->id, 'supplier_item_id' => $la->id], $naehrwerte));

        return $la;
    };
    $this->gpSub = fn (string $name, string $sub) => tap($this->makeGp($this->rootTeam, $name))->update(['sub_category' => $sub]);
    $this->svc = app(NaehrwertPruefService::class);
    $this->agg = app(GpAggregateService::class);
    $this->omelett = ['energy_kcal' => 164, 'fat' => 0.0109, 'protein' => 0.0113, 'carbs_absorbable' => 0.0051, 'sugar' => 0.002, 'sodium' => 0.516];   // Elbfrost-Muster
    $this->scharf = fn () => config(['foodalchemist.naehrwert_pruefung.modus' => 'scharf']);
});

// ── Einheiten-Probe (H6) ───────────────────────────────────────────────────────────────

it('H6: eindeutige Probe → Umrechnung × 1000 mit Kennzeichen, Rohwert bleibt in der Tabelle', function () {
    $befunde = $this->svc->pruefe($this->omelett);
    $u = collect($befunde)->firstWhere('regel', 'H6');

    expect($u->schwere)->toBe(NaehrwertBefund::UMRECHNUNG)->and($u->vorschlag['faktor'])->toBe(1000)
        ->and($u->vorschlag['werte']['fat'])->toBe(10.9)->and($u->vorschlag['werte'])->not->toHaveKey('sodium')   // Natrium (mg) wird nie mit umgerechnet — sonst 20 g Salz je 100 g Pastinake
        ->and(NaehrwertPruefService::istHart($befunde))->toBeFalse();                                      // H4 prüft die umgerechneten Werte

    $gp = $this->makeGp($this->rootTeam, 'Eieromelett: TK, 80 g pro Stück');
    $la = ($this->mkLa)($gp->id, $this->omelett, $this->elbfrost);
    ($this->scharf)();
    expect($this->agg->naehrwerte($gp)['fat']['avg'])->toBe(10.9)
        ->and(app(SensorikService::class)->erdungBulk([$gp->id])[$gp->id]['fettig']['gehalt'])->toBe(10.9)
        ->and((float) FoodAlchemistItemNutritional::where('supplier_item_id', $la->id)->value('fat'))->toBe(0.0109);
});

it('H6: ein korrekter Artikel bleibt unberührt', function () {
    expect($this->svc->pruefe(['energy_kcal' => 740, 'fat' => 82, 'protein' => 0.7, 'carbs_absorbable' => 0.6, 'sugar' => 0.6, 'sodium' => 8]))->toBe([]);
});

it('H6: korrigiert der Lieferant die Quelle, greift kein Faktor mehr (kein Doppel-Faktor)', function () {
    $gp = $this->makeGp($this->rootTeam, 'Eieromelett: TK, 80 g pro Stück');
    $la = ($this->mkLa)($gp->id, $this->omelett, $this->elbfrost);
    ($this->scharf)();
    expect($this->agg->naehrwerte($gp)['fat']['avg'])->toBe(10.9);

    FoodAlchemistItemNutritional::where('supplier_item_id', $la->id)->update(['fat' => 10.9, 'protein' => 11.3, 'carbs_absorbable' => 5.1, 'sugar' => 2, 'sodium' => 516]);

    expect($this->agg->naehrwerte($gp)['fat']['avg'])->toBe(10.9)                                           // nicht 10.900
        ->and(collect($this->svc->pruefe(FoodAlchemistItemNutritional::where('supplier_item_id', $la->id)->first()->toArray()))->pluck('regel')->all())->not->toContain('H6');
});

it('H6: nicht eindeutig → keine Umrechnung (keine kcal, Rohwerte passen, oder kein Faktor passt)', function () {
    expect($this->svc->einheit(['fat' => 0.0109, 'protein' => 0.0113, 'carbs_absorbable' => 0.0051]))->toBeNull()          // keine kcal
        ->and($this->svc->einheit(['energy_kcal' => 30, 'fat' => 0.01, 'protein' => 0.01, 'carbs_absorbable' => 0.01]))->toBeNull()   // unter der kcal-Schwelle
        ->and($this->svc->einheit(['energy_kcal' => 164, 'fat' => 10.9, 'protein' => 11.3, 'carbs_absorbable' => 5.1]))->toBeNull()    // passt roh
        ->and($this->svc->einheit(['energy_kcal' => 500, 'fat' => 0.5, 'protein' => 0.5, 'carbs_absorbable' => 0.5]))->toBeNull()      // × 100 = 850, × 1000 = 8.500: keiner passt
        ->and($this->svc->einheit(['energy_kcal' => 164, 'fat' => 0.109, 'protein' => 0.113, 'carbs_absorbable' => 0.051])->vorschlag['faktor'])->toBe(100);
});

// ── harte Befunde, Hinweise, Freigabe ──────────────────────────────────────────────────

it('hart zählt im Modus scharf nicht (Mazola-Öl), im Modus aus schon', function () {
    $gp = ($this->gpSub)('Rapsöl: raffiniert', '11.1 Öl');
    ($this->mkLa)($gp->id, ['energy_kcal' => 828, 'fat' => 92]);
    ($this->mkLa)($gp->id, ['energy_kcal' => 98, 'fat' => 2.5]);

    expect($this->agg->naehrwerte($gp)['fat']['avg'])->toBe(47.25)->and($this->agg->naehrwerte($gp)['fat']['n'])->toBe(2);   // modus aus
    ($this->scharf)();
    expect($this->agg->naehrwerte($gp)['fat']['avg'])->toBe(92.0)->and($this->agg->naehrwerte($gp)['fat']['n'])->toBe(1);
});

it('ein Hinweis rechnet mit (Gewürz-Zucker > 40 g, Dominique 29.10.)', function () {
    $gp = ($this->gpSub)('Piment: gemahlen, trocken', '10.1 Gewürze');
    ($this->mkLa)($gp->id, ['energy_kcal' => 263, 'fat' => 8.7, 'protein' => 6.1, 'carbs_absorbable' => 50.5, 'sugar' => 48]);
    ($this->scharf)();

    $b = $this->svc->pruefe(['energy_kcal' => 263, 'fat' => 8.7, 'protein' => 6.1, 'carbs_absorbable' => 50.5, 'sugar' => 48], [], ['sub' => '10.1 Gewürze']);
    expect(collect($b)->pluck('regel')->all())->toBe(['W3'])->and($b[0]->schwere)->toBe(NaehrwertBefund::HINWEIS)
        ->and($this->agg->naehrwerte($gp)['sugar']['avg'])->toBe(48.0);
});

it('Freigabe: ein freigegebener Artikel zählt wieder — aber nur, solange seine Werte gleich bleiben', function () {
    $gp = ($this->gpSub)('Rapsöl: raffiniert', '11.1 Öl');
    ($this->mkLa)($gp->id, ['energy_kcal' => 828, 'fat' => 92]);
    $mazola = ($this->mkLa)($gp->id, ['energy_kcal' => 98, 'fat' => 2.5]);
    ($this->scharf)();
    $roh = FoodAlchemistItemNutritional::where('supplier_item_id', $mazola->id)->first()->toArray();
    DB::table('foodalchemist_item_nutrition_freigaben')->insert(['uuid' => (string) Str::uuid7(), 'supplier_item_id' => $mazola->id,
        'werte_hash' => NaehrwertPruefService::werteHash($roh), 'created_at' => now(), 'updated_at' => now()]);

    expect($this->agg->naehrwerte($gp)['fat']['n'])->toBe(2);

    FoodAlchemistItemNutritional::where('supplier_item_id', $mazola->id)->update(['fat' => 3.0]);
    expect($this->agg->naehrwerte($gp)['fat']['n'])->toBe(1);
});

it('A: ein Artikel weit weg von den Geschwistern ist hart; zwei Artikel reichen nicht', function () {
    $z = fn (float $fett) => ['energy_kcal' => 100 + 9 * $fett, 'fat' => $fett, 'protein' => 25, 'carbs_absorbable' => 0];
    $gruppe = $this->svc->pruefeGruppe([$z(10), $z(11), $z(60)]);

    expect(collect($gruppe[2])->pluck('regel')->unique()->values()->all())->toBe(['A'])->and(collect($gruppe[2])->pluck('feld')->all())->toContain('fat')->and($gruppe[0])->toBe([])->and($gruppe[1])->toBe([])
        ->and($this->svc->pruefeGruppe([$z(10), $z(60)]))->toBe([0 => [], 1 => []]);
});

it('H1–H4: unmöglich, Summe, Teilmenge, Energie', function () {
    $regeln = fn (array $z) => collect($this->svc->pruefe($z))->pluck('regel')->unique()->values()->all();

    expect($regeln(['energy_kcal' => 300, 'carbs_absorbable' => 269, 'fat' => 1, 'protein' => 14]))->toContain('H1', 'H2')
        ->and($regeln(['energy_kcal' => 30, 'carbs_absorbable' => 0.3, 'sugar' => 3, 'fat' => 0.2, 'protein' => 0.9]))->toBe(['H3'])
        ->and($regeln(['energy_kcal' => 300, 'fat' => 0.2, 'protein' => 1, 'carbs_absorbable' => 4]))->toBe(['H4'])
        ->and($regeln(['energy_kcal' => 231, 'alcohol' => 33]))->toBe([]);                                   // Spirituose ohne Makros: keine Probe
});

// ── Trockenlauf per MCP ────────────────────────────────────────────────────────────────

it('naehrwert_pruefung.GET zählt je Regel und Umrechnungen je Lieferant, schreibt nichts', function () {
    $ei = $this->makeGp($this->rootTeam, 'Eieromelett: TK, 80 g pro Stück');
    ($this->mkLa)($ei->id, $this->omelett, $this->elbfrost);
    $oel = ($this->gpSub)('Rapsöl: raffiniert', '11.1 Öl');
    ($this->mkLa)($oel->id, ['energy_kcal' => 828, 'fat' => 92]);
    ($this->mkLa)($oel->id, ['energy_kcal' => 98, 'fat' => 2.5]);
    $vorher = FoodAlchemistItemNutritional::query()->orderBy('id')->get()->toArray();

    $r = (new NaehrwertPruefungGetTool)->execute(['limit' => 2], new ToolContext(auth()->user(), $this->rootTeam));

    expect($r->success)->toBeTrue()
        ->and($r->data['modus'])->toBe('aus')
        ->and($r->data['artikel_geprueft'])->toBe(3)
        ->and($r->data['artikel_markiert'])->toBe(1)
        ->and($r->data['artikel_umgerechnet'])->toBe(1)
        ->and($r->data['umrechnung_je_lieferant'])->toBe(['Elbfrost Marl' => ['1000' => 1]])
        ->and($r->data['je_regel']['W1']['artikel'])->toBe(1)
        ->and($r->data['markiert_je_lieferant'])->toBe(['Hanos' => 1])
        ->and(FoodAlchemistItemNutritional::query()->orderBy('id')->get()->toArray())->toBe($vorher);
});

it('naehrwert_pruefung.GET: nur Kuratieren; der Betrachter bekommt FORBIDDEN', function () {
    $viewer = $this->makeUser($this->rootTeam, 'Gast', 'viewer');
    expect((new NaehrwertPruefungGetTool)->execute([], new ToolContext($viewer, $this->rootTeam))->errorCode)->toBe('FORBIDDEN');
});

it('Befehl foodalchemist:naehrwerte-pruefen schreibt die Meldeliste als CSV', function () {
    $ei = $this->makeGp($this->rootTeam, 'Eieromelett: TK, 80 g pro Stück');
    ($this->mkLa)($ei->id, $this->omelett, $this->elbfrost);
    $datei = sys_get_temp_dir() . '/nw_' . uniqid() . '.csv';

    $this->artisan('foodalchemist:naehrwerte-pruefen', ['--team' => $this->rootTeam->id, '--csv' => $datei])->assertSuccessful();

    $zeilen = array_map(fn ($l) => str_getcsv($l, ';'), array_filter(explode("\n", file_get_contents($datei))));
    @unlink($datei);
    expect($zeilen[1][0])->toBe('H6')->and($zeilen[1][5])->toBe('Elbfrost Marl')->and($zeilen[1][11])->toBe('1000');
});
