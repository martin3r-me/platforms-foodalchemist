<?php

use Illuminate\Support\Facades\DB;
use Platform\FoodAlchemist\Models\FoodAlchemistItemNutritional;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplier;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItemStructure;
use Platform\FoodAlchemist\Services\SensorikService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Erdung der messbaren Sensorik-Achsen (Salzig/Süß/Fettig) an den LA-Nährwerten.
 *
 * Anlass: „Achiote-Paste: konserviert" stand mit salzig 0.60 im GP-Vektor (Gemini-Schätzung
 * allein aus dem Namen), während die verknüpften LAs 1640/1200 mg Natrium melden = ~3,6 g
 * Salz/100 g — Salz ist dort Zutat #2. Messbares wird nicht mehr geschätzt.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam));
    $this->svc = app(SensorikService::class);
    $this->supplier = FoodAlchemistSupplier::create(['team_id' => $this->rootTeam->id, 'name' => 'BOS Food']);

    // GP + LA + Nährwertzeile in einem Rutsch
    $this->mkLa = function (int $gpId, array $naehrwerte, bool $discontinued = false) {
        $la = FoodAlchemistSupplierItem::create([
            'team_id' => $this->rootTeam->id, 'supplier_id' => $this->supplier->id,
            'designation' => 'LA ' . $gpId . '-' . uniqid(), 'is_discontinued' => $discontinued,
        ]);
        FoodAlchemistSupplierItemStructure::create([
            'team_id' => $this->rootTeam->id, 'supplier_item_id' => $la->id, 'gp_id' => $gpId,
        ]);
        FoodAlchemistItemNutritional::create(array_merge(
            ['team_id' => $this->rootTeam->id, 'supplier_item_id' => $la->id], $naehrwerte,
        ));

        return $la;
    };

    $this->setzeVektor = function (int $gpId, array $dims) {
        DB::table('foodalchemist_gp_taste_vectors')->insert(array_merge(
            array_fill_keys(SensorikService::DIMS, 0),
            ['gp_id' => $gpId, 'source' => 'gemini', 'created_at' => now(), 'updated_at' => now()],
            $dims,
        ));
    };
});

it('Salzig kommt aus dem Natrium der LAs, nicht aus der KI-Schätzung (Fall Achiote-Paste)', function () {
    $gp = $this->makeGp($this->rootTeam, 'Achiote-Paste konserviert');
    ($this->setzeVektor)($gp->id, ['salzig' => 0.6, 'umami' => 0.4, 'sauer' => 0.3]);
    ($this->mkLa)($gp->id, ['sodium' => 1640, 'energy_kcal' => 204, 'fat' => 2.3, 'sugar' => 3.2]);
    ($this->mkLa)($gp->id, ['sodium' => 1200, 'energy_kcal' => 550, 'fat' => 10, 'sugar' => 46]);

    $s = $this->svc->fuerGp($gp->id);

    // Ø 1420 mg Na = 3,55 g Salz/100 g → deutlich über der FSA-Schwelle „hoch" (1,5 g = 0.60)
    expect($s['geschmack']['salzig'])->toBeGreaterThan(0.6)
        ->and($s['erdung'])->toHaveKey('salzig')
        ->and($s['erdung']['salzig']['basis'])->toContain('Salz 3,6 g/100 g')->toContain('Ø 2 LA')
        ->and($s['erdung']['salzig']['angewendet'])->toBeTrue()
        ->and($s['erdung']['salzig']['konflikt'])->toBeNull()
        ->and($s['dominant'])->toContain('salzig');

    // nicht messbare Achsen bleiben bei der KI-Schätzung — und werden nicht als gemessen ausgewiesen
    expect($s['geschmack']['umami'])->toBe(0.4)
        ->and($s['geschmack']['sauer'])->toBe(0.3)
        ->and($s['erdung'])->not->toHaveKey('umami');
});

it('erdet auch nach unten, solange die Messung nicht abstürzt', function () {
    $gp = $this->makeGp($this->rootTeam, 'Kochschinken');
    ($this->setzeVektor)($gp->id, ['salzig' => 0.45]);
    ($this->mkLa)($gp->id, ['sodium' => 60, 'energy_kcal' => 110, 'fat' => 3]);    // 0,15 g Salz/100 g

    $s = $this->svc->fuerGp($gp->id);

    expect($s['geschmack']['salzig'])->toBeLessThan(0.2)
        ->and($s['erdung']['salzig']['angewendet'])->toBeTrue();
});

it('ein abstürzender Label-Wert wird NICHT übernommen, sondern als Widerspruch gemeldet', function () {
    $gp = $this->makeGp($this->rootTeam, 'Praline mit Yuzu');
    ($this->setzeVektor)($gp->id, ['suess' => 0.8]);
    ($this->mkLa)($gp->id, ['sugar' => 0.8, 'carbs_absorbable' => 55, 'fat' => 30, 'energy_kcal' => 520]);

    $s = $this->svc->fuerGp($gp->id);

    expect($s['geschmack']['suess'])->toBe(0.8)                       // Schätzung bleibt stehen
        ->and($s['erdung']['suess']['angewendet'])->toBeFalse()
        ->and($s['erdung']['suess']['konflikt'])->toContain('unplausibel')
        ->and($s['erdung']['fettig']['angewendet'])->toBeTrue();      // Fett am selben LA ist plausibel
});

it('Fett und Zucker kommen ebenfalls vom Label, die Kurve trifft die bekannten Anker', function () {
    $oel = $this->makeGp($this->rootTeam, 'Rapsoel');
    ($this->setzeVektor)($oel->id, ['fettig' => 0.3]);                              // absichtlich zu niedrig
    ($this->mkLa)($oel->id, ['fat' => 100, 'energy_kcal' => 900, 'sodium' => 0]);

    $zucker = $this->makeGp($this->rootTeam, 'Zucker');
    ($this->mkLa)($zucker->id, ['sugar' => 100, 'carbs_absorbable' => 100, 'energy_kcal' => 400]);

    expect($this->svc->fuerGp($oel->id)['geschmack']['fettig'])->toBe(1.0)
        ->and($this->svc->fuerGp($zucker->id)['geschmack']['suess'])->toBe(1.0);
});

it('Leer-Label (alles 0) und ausgelistete LAs ziehen keine Achse auf 0', function () {
    $gp = $this->makeGp($this->rootTeam, 'Miso hell');
    ($this->setzeVektor)($gp->id, ['salzig' => 0.7, 'umami' => 0.8]);
    ($this->mkLa)($gp->id, ['sodium' => 0, 'energy_kcal' => 0, 'fat' => 0, 'sugar' => 0, 'protein' => 0]);
    ($this->mkLa)($gp->id, ['sodium' => 9999, 'energy_kcal' => 200], discontinued: true);

    $s = $this->svc->fuerGp($gp->id);

    expect($s['erdung'])->toBe([])                       // keine verwertbare Zeile ⇒ keine Erdung
        ->and($s['geschmack']['salzig'])->toBe(0.7);     // KI-Schätzung bleibt stehen
});

it('eine exakte 0 im Label ist keine Messung (Fall Cola: 0-Zucker-Label darf Süße nicht löschen)', function () {
    $gp = $this->makeGp($this->rootTeam, 'Cola konserviert');
    ($this->setzeVektor)($gp->id, ['suess' => 0.9]);
    ($this->mkLa)($gp->id, ['sugar' => 0, 'carbs_absorbable' => 10.6, 'energy_kcal' => 42]);

    $s = $this->svc->fuerGp($gp->id);

    expect($s['geschmack']['suess'])->toBe(0.9)
        ->and($s['erdung'])->not->toHaveKey('suess');

    // eine zweite, deklarierte Zeile erdet dann — ohne dass die 0 den Ø verwässert
    ($this->mkLa)($gp->id, ['sugar' => 10.6, 'carbs_absorbable' => 10.6, 'energy_kcal' => 42]);
    expect($this->svc->fuerGp($gp->id)['erdung']['suess']['basis'])->toContain('Zucker 10,6 g/100 g');
});

it('Zucker > Kohlenhydrate ist LMIV-widersprüchlich und wird verworfen', function () {
    $gp = $this->makeGp($this->rootTeam, 'Margarine vegan');
    ($this->setzeVektor)($gp->id, ['fettig' => 0.9]);
    ($this->mkLa)($gp->id, ['sugar' => 74.7, 'carbs_absorbable' => 2.0, 'fat' => 80, 'energy_kcal' => 720]);

    $s = $this->svc->fuerGp($gp->id);

    expect($s['erdung'])->not->toHaveKey('suess')
        ->and($s['erdung'])->toHaveKey('fettig');
});

it('weicht die Messung stark von der Schätzung ab, wird der Widerspruch ausgewiesen', function () {
    $gp = $this->makeGp($this->rootTeam, 'Knollensellerie Stifte');
    ($this->setzeVektor)($gp->id, ['salzig' => 0.0]);
    ($this->mkLa)($gp->id, ['sodium' => 32000, 'energy_kcal' => 20]);       // 80 g Salz/100 g = LA-Fehler

    $s = $this->svc->fuerGp($gp->id);

    expect($s['geschmack']['salzig'])->toBeGreaterThan(0.9)              // Messung gewinnt nach oben …
        ->and($s['erdung']['salzig']['angewendet'])->toBeTrue()
        ->and($s['erdung']['salzig']['konflikt'])->toContain('LA-Zuordnung prüfen');   // … aber sichtbar
});

it('ohne Nährwerte bleibt alles wie bisher (reine KI-Schätzung)', function () {
    $gp = $this->makeGp($this->rootTeam, 'Kreuzkuemmel gemahlen');
    ($this->setzeVektor)($gp->id, ['bitter' => 0.4, 'scharf' => 0.3]);

    $s = $this->svc->fuerGp($gp->id);

    expect($s['erdung'])->toBe([])
        ->and($s['geschmack']['bitter'])->toBe(0.4)
        ->and($s['geschmack']['salzig'])->toBe(0.0);
});

it('im Rezept-Rohaggregat wird erst geerdet, dann gemaxt (GP ohne Label verliert nicht)', function () {
    $salzig = $this->makeGp($this->rootTeam, 'Sojasauce');
    ($this->setzeVektor)($salzig->id, ['salzig' => 0.9, 'umami' => 0.9]);           // kuratierter Regel-Wert
    $mager = $this->makeGp($this->rootTeam, 'Zucchini');
    ($this->setzeVektor)($mager->id, ['salzig' => 0.0]);
    ($this->mkLa)($mager->id, ['sodium' => 4, 'energy_kcal' => 19, 'fat' => 0.3]);  // messbar ≈ 0

    // Sojasauce hat KEIN Label → ihr 0.9 muss den Messwert der Zucchini überleben
    $vektoren = $this->svc->vektorenFuerGps([$salzig->id, $mager->id]);

    expect($vektoren[$salzig->id]['werte']['salzig'])->toBe(0.9)
        ->and($vektoren[$salzig->id]['messung'])->toBe([])
        ->and($vektoren[$mager->id]['werte']['salzig'])->toBeLessThan(0.1)
        ->and(max(array_column(array_column($vektoren, 'werte'), 'salzig')))->toBe(0.9);
});
