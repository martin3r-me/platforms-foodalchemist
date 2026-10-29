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
 * Wahrnehmung statt Messung (Dominique 29.10.2026, Plan „Geschmacksprofil = Wahrnehmung").
 *
 * Anlass: „Amerikaner mit Zuckerguss" (Salz 1,2 g/100 g) wurde über die Kurve auf salzig 0,54 gezogen, obwohl der Zucker das Salz
 * überdeckt. Das gespeicherte Profil beschreibt jetzt, wie das Produkt SCHMECKT; die Nährwerte bleiben Grundlage (Fakt) und
 * ersetzen beim Lesen nichts. Nur OHNE Profil steht die Konzentration vorläufig als Wert.
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

it('das gespeicherte Profil gewinnt, die Nährwerte bleiben als Grundlage (Fall Amerikaner: Salz 1,2 g, Zucker überdeckt)', function () {
    $gp = $this->makeGp($this->rootTeam, 'Amerikaner mit Zuckerguss');
    ($this->setzeVektor)($gp->id, ['suess' => 0.7, 'salzig' => 0.1, 'fettig' => 0.3], 'ai');
    ($this->mkLa)($gp->id, ['sodium' => 480, 'sugar' => 21, 'carbs_absorbable' => 55, 'fat' => 8, 'energy_kcal' => 380]);    // 1,2 g Salz

    $s = $this->svc->fuerGp($gp->id);

    // Gegenprobe zur alten Überlagerung: die Kurve hätte 1,2 g auf 0,54 gezogen
    expect($s['geschmack']['salzig'])->toBe(0.1)
        ->and($s['erdung']['salzig']['basis'])->toContain('Salz 1,2 g/100 g')
        ->and($s['erdung']['salzig']['gehalt'])->toBe(1.2)
        ->and($s['erdung']['salzig']['vorlaeufig'])->toBeFalse()
        ->and($s['erdung']['salzig']['konflikt'])->toContain('deutlich von der Konzentration')   // Überdeckung wird sichtbar, nicht überschrieben
        ->and($s['erdung']['suess']['basis'])->toContain('Zucker 21,0 g/100 g');
});

it('Achiote-Paste: ein Profil mit salzig 0,6 bleibt, die Grundlage zeigt 3,6 g Salz; nicht gemessene Achsen tauchen nicht als Grundlage auf', function () {
    $gp = $this->makeGp($this->rootTeam, 'Achiote-Paste konserviert');
    ($this->setzeVektor)($gp->id, ['salzig' => 0.6, 'umami' => 0.4, 'sauer' => 0.3], 'ai');
    ($this->mkLa)($gp->id, ['sodium' => 1640, 'energy_kcal' => 204, 'fat' => 2.3, 'sugar' => 3.2]);
    ($this->mkLa)($gp->id, ['sodium' => 1200, 'energy_kcal' => 550, 'fat' => 10, 'sugar' => 46]);

    $s = $this->svc->fuerGp($gp->id);

    expect($s['geschmack']['salzig'])->toBe(0.6)
        ->and($s['erdung']['salzig']['basis'])->toContain('Salz 3,6 g/100 g')->toContain('Median 2 LA')
        ->and($s['erdung']['salzig']['konflikt'])->toBeNull()
        ->and($s['geschmack']['umami'])->toBe(0.4)->and($s['geschmack']['sauer'])->toBe(0.3)
        ->and($s['erdung'])->not->toHaveKey('umami');
});

it('ohne Profil steht die Konzentration vorläufig als Wert, gekennzeichnet (kein Absturz auf 0 im Roh-Fallback)', function () {
    $gp = $this->makeGp($this->rootTeam, 'Salzstangen');
    ($this->mkLa)($gp->id, ['sodium' => 1400, 'energy_kcal' => 380, 'fat' => 8]);    // 3,5 g Salz

    $s = $this->svc->fuerGp($gp->id);

    expect($s['geschmack']['salzig'])->toBeGreaterThan(0.6)
        ->and($s['erdung']['salzig']['vorlaeufig'])->toBeTrue()
        ->and($s['gp_profil']['fehlt'])->toBeTrue();

    // ein Import-Artefakt aus lauter Nullen gilt ebenfalls als „kein Profil"
    ($this->setzeVektor)($gp->id, [], 'gemini');
    expect($this->svc->fuerGp($gp->id)['erdung']['salzig']['vorlaeufig'])->toBeTrue();
});

it('Fett und Zucker ohne Profil: die Kurve trifft die bekannten Anker (nur vorläufig)', function () {
    $oel = $this->makeGp($this->rootTeam, 'Rapsoel');
    ($this->mkLa)($oel->id, ['fat' => 100, 'energy_kcal' => 900, 'sodium' => 0]);
    $zucker = $this->makeGp($this->rootTeam, 'Zucker');
    ($this->mkLa)($zucker->id, ['sugar' => 100, 'carbs_absorbable' => 100, 'energy_kcal' => 400]);

    expect($this->svc->fuerGp($oel->id)['geschmack']['fettig'])->toBe(1.0)
        ->and($this->svc->fuerGp($zucker->id)['geschmack']['suess'])->toBe(1.0);

    // mit Profil gewinnt das Profil, auch wenn es niedriger ist (Gegenprobe zur alten Überlagerung)
    ($this->setzeVektor)($oel->id, ['fettig' => 0.3], 'ai');
    expect($this->svc->fuerGp($oel->id)['geschmack']['fettig'])->toBe(0.3);
});

it('Leer-Label (alles 0) und ausgelistete LAs liefern keine Grundlage, das Profil bleibt', function () {
    $gp = $this->makeGp($this->rootTeam, 'Miso hell');
    ($this->setzeVektor)($gp->id, ['salzig' => 0.7, 'umami' => 0.8]);
    ($this->mkLa)($gp->id, ['sodium' => 0, 'energy_kcal' => 0, 'fat' => 0, 'sugar' => 0, 'protein' => 0]);
    ($this->mkLa)($gp->id, ['sodium' => 9999, 'energy_kcal' => 200], discontinued: true);

    $s = $this->svc->fuerGp($gp->id);

    expect($s['erdung'])->toBe([])
        ->and($s['geschmack']['salzig'])->toBe(0.7);
});

it('eine exakte 0 im Label ist ein Messwert (Dominique 29.10.: Nullen sind oft echt); das Profil gewinnt trotzdem', function () {
    // Bis 10/2026 galt jede 0 als „nicht deklariert“ (Fall Cola mit 0-Zucker-Label). Seit das Profil die Wahrnehmung trägt, ändert die
    // Grundlage das Profil nicht mehr; ein falsch gepflegter Artikel ist Sache des Geschwister-Vergleichs, nicht des Wegwerfens jeder 0.
    $gp = $this->makeGp($this->rootTeam, 'Cola konserviert');
    ($this->setzeVektor)($gp->id, ['suess' => 0.9]);
    ($this->mkLa)($gp->id, ['sugar' => 0, 'carbs_absorbable' => 10.6, 'energy_kcal' => 42]);

    $s = $this->svc->fuerGp($gp->id);

    expect($s['geschmack']['suess'])->toBe(0.9)
        ->and($s['erdung']['suess']['gehalt'])->toBe(0.0)->and($s['erdung']['suess']['basis'])->toBe('Zucker 0,0 g/100 g');

    ($this->mkLa)($gp->id, ['sugar' => 10.6, 'carbs_absorbable' => 10.6, 'energy_kcal' => 42]);
    expect($this->svc->fuerGp($gp->id)['erdung']['suess']['basis'])->toContain('Zucker 5,3 g/100 g · Median 2 LA');
});

it('Zucker > Kohlenhydrate ist LMIV-widersprüchlich und wird verworfen', function () {
    $gp = $this->makeGp($this->rootTeam, 'Margarine vegan');
    ($this->setzeVektor)($gp->id, ['fettig' => 0.9]);
    ($this->mkLa)($gp->id, ['sugar' => 74.7, 'carbs_absorbable' => 2.0, 'fat' => 80, 'energy_kcal' => 720]);

    $s = $this->svc->fuerGp($gp->id);

    expect($s['erdung'])->not->toHaveKey('suess')
        ->and($s['erdung'])->toHaveKey('fettig');
});

it('ohne Nährwerte bleibt alles wie bisher (nur das gespeicherte Profil)', function () {
    $gp = $this->makeGp($this->rootTeam, 'Kreuzkuemmel gemahlen');
    ($this->setzeVektor)($gp->id, ['bitter' => 0.4, 'scharf' => 0.3]);

    $s = $this->svc->fuerGp($gp->id);

    expect($s['erdung'])->toBe([])
        ->and($s['geschmack']['bitter'])->toBe(0.4)
        ->and($s['geschmack']['salzig'])->toBe(0.0);
});

it('im Rezept-Rohfallback gilt das mengengewichtete Mittel, nicht MAX: eine Prise Salz dominiert nicht mehr', function () {
    $fleisch = $this->makeGp($this->rootTeam, 'Rinderlende');
    ($this->setzeVektor)($fleisch->id, ['umami' => 0.8, 'salzig' => 0.1], 'ai');
    $salz = $this->makeGp($this->rootTeam, 'Salz');
    ($this->setzeVektor)($salz->id, ['salzig' => 1.0], 'ai');
    $r = $this->makeRecipe($this->rootTeam, 'Fleisch: Roastbeef', ['is_sales_recipe' => false]);
    $this->makeIngredient($r, 'Rinderlende', $fleisch, '1600', 1);
    $this->makeIngredient($r, 'Salz', $salz, '16', 2);                      // 1 %

    $s = $this->svc->fuerRezept($r->id);

    // (1600 × 0,1 + 16 × 1,0) / 1616 ≈ 0,11 — MAX hätte 1,0 geliefert
    expect($s['source'])->toBe('roh')
        ->and($s['geschmack']['salzig'])->toBeLessThan(0.2)
        ->and($s['geschmack']['umami'])->toBeGreaterThan(0.7)
        ->and($s['dominant'])->toBe(['umami'])
        ->and($s['hinweis'])->toBeNull();
});

it('ohne Mengen bleibt der Rohfallback bei MAX und ist gekennzeichnet', function () {
    $a = $this->makeGp($this->rootTeam, 'Rinderlende');
    ($this->setzeVektor)($a->id, ['umami' => 0.8], 'ai');
    $b = $this->makeGp($this->rootTeam, 'Salz');
    ($this->setzeVektor)($b->id, ['salzig' => 1.0], 'ai');
    $r = $this->makeRecipe($this->rootTeam, 'Fleisch: ohne Mengen', ['is_sales_recipe' => false]);
    $this->makeIngredient($r, 'Rinderlende', $a, '0', 1);
    $this->makeIngredient($r, 'Salz', $b, '0', 2);

    $s = $this->svc->fuerRezept($r->id);

    expect($s['geschmack']['salzig'])->toBe(1.0)->and($s['geschmack']['umami'])->toBe(0.8)
        ->and($s['hinweis'])->toBe('grob, ohne Mengen');
});

it('Rezept-Nährwerte je 100 g aus Menge × Gehalt der Zutaten, mit Abdeckung; die Grenzen greifen nur bei genug bekannter Masse', function () {
    $fleisch = $this->makeGp($this->rootTeam, 'Rinderlende');
    ($this->mkLa)($fleisch->id, ['sodium' => 60, 'fat' => 5, 'energy_kcal' => 120]);               // 0,15 g Salz, 5 g Fett
    $salz = $this->makeGp($this->rootTeam, 'Salz');
    ($this->mkLa)($salz->id, ['sodium' => 39300, 'energy_kcal' => 0]);                            // 98,25 g Salz
    $r = $this->makeRecipe($this->rootTeam, 'Fleisch: Roastbeef', ['is_sales_recipe' => false]);
    $this->makeIngredient($r, 'Rinderlende', $fleisch, '1600', 1);
    $this->makeIngredient($r, 'Salz', $salz, '16', 2);
    $this->makeIngredient($r, 'Pfeffer', null, '5', 3);                                          // ungemappt: Masse ohne Gehalt

    $n = $this->svc->rezeptNaehrwerte($r->id);

    // Salz: (1600 × 0,15 + 16 × 98,25) / 1616 ≈ 1,09 g/100 g; bekannt für 1616 von 1621 g
    expect($n['werte']['salzig'])->toBeGreaterThan(1.0)->toBeLessThan(1.2)
        ->and($n['abdeckung']['salzig'])->toBeGreaterThan(0.99)
        ->and($n['werte']['suess'])->toBeNull();
});


it('Lücken nur beim Gericht: Basisrezept mit süß 0 zeigt keine „Lücke: süß", die Dominanten bleiben; Gegenprobe Gericht', function () {
    $zeile = ['suess' => 0, 'salzig' => 0.5, 'sauer' => 0, 'bitter' => 0, 'umami' => 0.8, 'fettig' => 0.5, 'scharf' => 0.15, 'source' => 'ai',
        'created_at' => now(), 'updated_at' => now()];
    $basis = $this->makeRecipe($this->rootTeam, 'Fleisch: Roastbeef', ['is_sales_recipe' => false]);
    $gericht = $this->makeRecipe($this->rootTeam, 'Roastbeef mit Remoulade', ['is_sales_recipe' => true]);
    DB::table('foodalchemist_recipe_taste_vectors')->insert([['recipe_id' => $basis->id] + $zeile, ['recipe_id' => $gericht->id] + $zeile]);

    $b = $this->svc->fuerRezept($basis->id);
    $g = $this->svc->fuerRezept($gericht->id);

    expect($b['luecken'])->toBe([])->and($b['dominant'])->toBe(['umami'])
        ->and($g['luecken'])->toContain('suess', 'sauer', 'bitter', 'scharf')->and($g['dominant'])->toBe(['umami']);
    $html = fn (array $s) => view('foodalchemist::livewire.concepter.partials.sensorik', ['sensorik' => $s])->render();
    expect($html($b))->not->toContain('Lücke:')->toContain('dominant: umami')
        ->and($html($g))->toContain('Lücke: süß');
});
