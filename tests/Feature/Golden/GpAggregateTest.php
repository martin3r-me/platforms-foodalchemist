<?php

use Platform\FoodAlchemist\Enums\AllergenValue;
use Platform\FoodAlchemist\Models\FoodAlchemistItemAllergen;
use Platform\FoodAlchemist\Models\FoodAlchemistItemDeclaration;
use Platform\FoodAlchemist\Models\FoodAlchemistItemNutritional;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplier;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItemStructure;
use Platform\FoodAlchemist\Services\GpAggregateService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * M3-04: GP-Ebene der Aggregations-GLs — Golden-Cases (GP-auflösbares Teilset
 * aus GL-01 §5, GL-08 §5, GL-09 §5; Rezept-Ebene folgt in M4 mit den GT-IDs aus 09).
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->svc = app(GpAggregateService::class);

    $this->supplier = FoodAlchemistSupplier::create(['team_id' => $this->rootTeam->id, 'name' => 'Necta Test']);
    $this->gp = $this->makeGp($this->rootTeam, 'Cornflakes');

    // LA mit optionalen Allergen-/Deklarations-/Nährwert-Zeilen anlegen + an GP hängen
    $this->mkLa = function (array $allergene = [], array $deklarationen = [], array $naehrwerte = [], array $itemExtra = [], $gp = null) {
        $item = FoodAlchemistSupplierItem::create([
            'team_id' => $this->rootTeam->id, 'supplier_id' => $this->supplier->id,
            'designation' => 'LA ' . uniqid(), ...$itemExtra,
        ]);
        FoodAlchemistSupplierItemStructure::create([
            'team_id' => $this->rootTeam->id, 'supplier_item_id' => $item->id, 'gp_id' => ($gp ?? $this->gp)->id,
        ]);
        if ($allergene !== []) {
            FoodAlchemistItemAllergen::create(['team_id' => $this->rootTeam->id, 'supplier_item_id' => $item->id, ...$allergene]);
        }
        if ($deklarationen !== []) {
            FoodAlchemistItemDeclaration::create(['team_id' => $this->rootTeam->id, 'supplier_item_id' => $item->id, ...$deklarationen]);
        }
        if ($naehrwerte !== []) {
            FoodAlchemistItemNutritional::create(['team_id' => $this->rootTeam->id, 'supplier_item_id' => $item->id, ...$naehrwerte]);
        }

        return $item;
    };
});

// ── GL-01 — Allergene ────────────────────────────────────────────────────

it('GL-01 GT-04: LA→GP-Merge — MAX-Rang gewinnt, konkreter Wert schlägt unbekannt, leer = unbekannt', function () {
    ($this->mkLa)(['allergen_gluten' => 'enthalten', 'allergen_soy' => 'nicht_enthalten']);
    ($this->mkLa)(['allergen_gluten' => 'enthalten']);
    ($this->mkLa)(['allergen_gluten' => 'spuren', 'allergen_milk' => 'nicht_enthalten']);

    $werte = $this->svc->allergene($this->gp->fresh());

    expect($werte['gluten']['value'])->toBe(AllergenValue::Enthalten)   // MAX(3,3,2)=3
        ->and($werte['gluten']['source'])->toBe('la')
        ->and($werte['soy']['value'])->toBe(AllergenValue::NichtEnthalten)               // {1, NULL, NULL} ⇒ konkreter gewinnt
        ->and($werte['milk']['value'])->toBe(AllergenValue::NichtEnthalten)
        ->and($werte['fish']['value'])->toBe(AllergenValue::Unbekannt)                   // {NULL,NULL,NULL} ⇒ unbekannt
        ->and($werte['fish']['source'])->toBe('keine');
});

it('GL-01 GT-07: GP-Override ist absolut — ersetzt die LA-Aggregation, wird NICHT gemax-t', function () {
    ($this->mkLa)(['allergen_milk' => 'enthalten']);
    $this->gp->update(['allergen_milk' => 'nicht_enthalten', 'allergens_source' => 'manual']);

    $werte = $this->svc->allergene($this->gp->fresh());

    expect($werte['milk']['value'])->toBe(AllergenValue::NichtEnthalten)
        ->and($werte['milk']['source'])->toBe('override');
});

it('GL-01 GT-06 (SOLL ⚠A2): Derivat erbt LIVE vom Mutter-GP — kein Snapshot', function () {
    $mutter = $this->makeGp($this->rootTeam, 'Haehnchen ganz');
    $mutterLa = ($this->mkLa)(['allergen_soy' => 'spuren'], gp: $mutter);

    $derivat = $this->makeGp($this->rootTeam, 'Huehnerfett frisch');
    $derivat->update(['is_derivat' => true, 'derivat_von_gp_id' => $mutter->id]);

    $werte = $this->svc->allergene($derivat->fresh());
    expect($werte['soy']['value'])->toBe(AllergenValue::Spuren)
        ->and($werte['soy']['source'])->toBe('mutter');

    // LIVE-Beweis: Mutter ändert sich ⇒ nächste Auflösung folgt sofort
    $mutterLa->allergens->first()->update(['allergen_soy' => 'enthalten']);
    expect($this->svc->allergene($derivat->fresh())['soy']['value'])->toBe(AllergenValue::Enthalten);

    // Override auf dem Derivat schlägt die Mutter (Prio 1 vor Prio 2)
    $derivat->update(['allergen_soy' => 'nicht_enthalten']);
    expect($this->svc->allergene($derivat->fresh())['soy']['source'])->toBe('override');
});

// ── GL-01 §4.5 — GP-Konfidenz (SOLL ⚠A1) ───────────────────────────────

it('GL-01 §4.5: identische LA-Profile ⇒ HIGH; nur unbekannt-vs-konkret ⇒ HIGH', function () {
    ($this->mkLa)(['allergen_milk' => 'enthalten']);
    ($this->mkLa)(['allergen_milk' => 'enthalten']);
    ($this->mkLa)([]); // LA ohne Allergen-Zeile — kein Profil-Beitrag

    expect($this->svc->allergenKonfidenz($this->gp))
        ->toBe(['confidence' => 'high', 'needs_review' => false, 'konflikt_felder' => [], 'n_las_mit_daten' => 2]);

    ($this->mkLa)(['allergen_soy' => 'nicht_enthalten']); // milch=NULL hier: unbekannt vs. konkret ⇒ bleibt HIGH
    expect($this->svc->allergenKonfidenz($this->gp)['confidence'])->toBe('high');
});

it('GL-01 §4.5: Unterschiede auf gleicher Stufe ⇒ MED; enthalten↔nicht_enthalten ohne spuren ⇒ LOW + Review', function () {
    ($this->mkLa)(['allergen_mustard' => 'spuren']);
    ($this->mkLa)(['allergen_mustard' => 'nicht_enthalten']);
    expect($this->svc->allergenKonfidenz($this->gp)['confidence'])->toBe('medium');

    ($this->mkLa)(['allergen_celery' => 'enthalten']);
    ($this->mkLa)(['allergen_celery' => 'nicht_enthalten']);
    $k = $this->svc->allergenKonfidenz($this->gp);
    expect($k['confidence'])->toBe('low')
        ->and($k['needs_review'])->toBeTrue()
        ->and($k['konflikt_felder'])->toBe(['celery']);

    // spuren-Mittelweg entschärft den Konflikt zurück auf MED
    ($this->mkLa)(['allergen_celery' => 'spuren']);
    expect($this->svc->allergenKonfidenz($this->gp)['confidence'])->toBe('medium');
});

it('GL-01 §4.5: GP ohne LA mit gepflegten Allergenen ⇒ NONE', function () {
    ($this->mkLa)([]); // LA ohne Allergen-Zeile

    expect($this->svc->allergenKonfidenz($this->gp)['confidence'])->toBe('none');
});

// ── GL-09 — Zusatzstoffe (GP-Teil) ──────────────────────────────────────

it('GL-09: MAX über Roh-Domäne {0,1,3} — 3=ja gewinnt, {0,1}⇒1, keine Daten ⇒ NULL', function () {
    ($this->mkLa)(deklarationen: ['with_dye' => 0, 'sulphurated' => 0, 'caffeinated' => 1]);
    ($this->mkLa)(deklarationen: ['with_dye' => 1, 'sulphurated' => 0]);
    ($this->mkLa)(deklarationen: ['with_dye' => 3]);

    $z = $this->svc->zusatzstoffe($this->gp);

    expect($z['with_dye'])->toBe(3)          // MAX(0,1,3)
        ->and($z['caffeinated'])->toBe(1)    // MAX(1, NULL, NULL) — konkret „nein"
        ->and($z['sulphurated'])->toBe(0)    // MAX(0,0) — k.A. bleibt k.A.
        ->and($z['waxed'])->toBeNull();      // nirgends gepflegt ⇒ NULL (kein Beitrag)
});

it('GL-09: GP ohne declarations-Zeilen ⇒ alle 18 NULL', function () {
    ($this->mkLa)([]);

    expect(collect($this->svc->zusatzstoffe($this->gp))->filter(fn ($v) => $v !== null))->toBeEmpty();
});

// ── GL-08 — Nährwert-Ø (GP-Pfad) ────────────────────────────────────────

it('GL-08: je Nährstoff AVG über aktive LAs — NULL fällt aus dem AVG, Salz = sodium × 0.0025', function () {
    ($this->mkLa)(naehrwerte: ['energy_kcal' => 200.0, 'protein' => 10.0]);                  // sodium NULL
    ($this->mkLa)(naehrwerte: ['energy_kcal' => 100.0, 'protein' => 20.0, 'sodium' => 400.0]);
    ($this->mkLa)(naehrwerte: ['energy_kcal' => 999.0], itemExtra: ['is_discontinued' => true]); // GL-08: discontinued raus

    $n = $this->svc->naehrwerte($this->gp);

    expect($n['energy_kcal']['avg'])->toBe(150.0)->and($n['energy_kcal']['n'])->toBe(2)
        ->and($n['protein']['avg'])->toBe(15.0)
        ->and($n['sodium']['n'])->toBe(1)                       // NULL-Wert fällt aus AVG UND Count
        ->and($n['salt_g']['avg'])->toBe(1.0)                   // 400 mg × 0.0025
        ->and($n['fat']['avg'])->toBeNull()->and($n['fat']['n'])->toBe(0);
});

it('GL-08: GP ohne Nährwert-Daten ⇒ alle NULL, n=0', function () {
    ($this->mkLa)([]);

    $n = $this->svc->naehrwerte($this->gp);
    expect($n['energy_kcal']['avg'])->toBeNull()->and($n['salt_g']['n'])->toBe(0);
});
