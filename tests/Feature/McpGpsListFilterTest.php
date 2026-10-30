<?php

use Platform\Core\Contracts\ToolContext;
use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Models\FoodAlchemistItemAllergen;
use Platform\FoodAlchemist\Models\FoodAlchemistPrice;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeIngredient;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplier;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItemStructure;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;
use Platform\FoodAlchemist\Tools\GpsListTool;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * MCP gps.LIST für Bestandsarbeit per MCP (Kurator 29.10.): Filter nach Warengruppe, Unterkategorie, Status, Marke offen, Rezepturware,
 * ohne Lead — und felder="voll". Sicht und Paging wie bisher.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->user = $this->makeUser($this->rootTeam);
    $this->actingAs($this->user);
    $sup = FoodAlchemistSupplier::create(['team_id' => $this->rootTeam->id, 'name' => 'Chefs Culinar']);
    $la = FoodAlchemistSupplierItem::create(['team_id' => $this->rootTeam->id, 'supplier_id' => $sup->id, 'designation' => 'HT Kalbsjus 1 kg', 'qty' => 1, 'unit_code' => 'kg']);
    $gp = fn (string $name, array $extra = []) => FoodAlchemistGp::create(array_merge(['team_id' => $this->rootTeam->id, 'name' => $name,
        'gp_key' => 'gl-' . bin2hex(random_bytes(4)) . '||', 'status' => 'approved', 'commodity_group_code' => '13'], $extra));
    $this->jus = $gp('Kalbsjus / (Hi Taste)', ['sub_category' => '13.3 Saucen & Fonds', 'rezepturware' => true, 'zusatz' => 'Hi Taste', 'lead_la_supplier_item_id' => $la->id, 'condition' => 'konserviert']);
    $this->offen = $gp('Bratensauce', ['sub_category' => '13.3 Saucen & Fonds', 'rezepturware' => true, 'marke_offen' => true]);
    $this->apfel = $gp('Apfel: frisch', ['commodity_group_code' => '02', 'rezepturware' => false, 'status' => 'tentative']);
    $this->ungeklaert = $gp('Pesto', ['commodity_group_code' => '13']);
    $this->fremd = FoodAlchemistGp::create(['team_id' => $this->childB->id, 'name' => 'Fremd-GP', 'gp_key' => 'gl-fremd||', 'status' => 'approved', 'commodity_group_code' => '13']);
    $this->ctx = new ToolContext($this->user, $this->rootTeam);
    $this->ids = fn (array $a) => collect(app(GpsListTool::class)->execute($a, $this->ctx)->data['gps'])->pluck('id')->sort()->values()->all();
    $this->sortiert = fn (...$gps) => collect($gps)->pluck('id')->sort()->values()->all();
});

it('filtert je Feld: Warengruppe, Unterkategorie, Status, Marke offen, Rezepturware (ja/nein/offen), ohne Lead — kombinierbar', function () {
    expect(($this->ids)(['commodity_group_code' => '02']))->toBe(($this->sortiert)($this->apfel))
        ->and(($this->ids)(['sub_category' => '13.3 Saucen & Fonds']))->toBe(($this->sortiert)($this->jus, $this->offen))
        ->and(($this->ids)(['status' => 'tentative']))->toBe(($this->sortiert)($this->apfel))
        ->and(($this->ids)(['marke_offen' => true]))->toBe(($this->sortiert)($this->offen))
        ->and(($this->ids)(['marke_offen' => 'false', 'commodity_group_code' => '13']))->toBe(($this->sortiert)($this->jus, $this->ungeklaert))
        ->and(($this->ids)(['rezepturware' => 'ja']))->toBe(($this->sortiert)($this->jus, $this->offen))
        ->and(($this->ids)(['rezepturware' => 'nein']))->toBe(($this->sortiert)($this->apfel))
        ->and(($this->ids)(['rezepturware' => 'offen']))->toBe(($this->sortiert)($this->ungeklaert))
        ->and(($this->ids)(['ohne_lead' => false]))->toBe(($this->sortiert)($this->jus))
        ->and(($this->ids)(['ohne_lead' => true, 'commodity_group_code' => '13', 'rezepturware' => 'ja']))->toBe(($this->sortiert)($this->offen))
        ->and(($this->ids)([]))->not->toContain($this->fremd->id);   // Sicht unverändert
    expect(app(GpsListTool::class)->execute(['rezepturware' => 'vielleicht'], $this->ctx)->errorCode)->toBe('VALIDATION_ERROR');
});

it('felder=voll liefert WG, Unterkategorie, Zustand, Zusatz, Marke offen, Rezepturware und den Lead samt Lieferant; kurz bleibt schmal', function () {
    $voll = collect(app(GpsListTool::class)->execute(['felder' => 'voll', 'sub_category' => '13.3 Saucen & Fonds'], $this->ctx)->data['gps'])->keyBy('id');
    $kurz = app(GpsListTool::class)->execute(['sub_category' => '13.3 Saucen & Fonds'], $this->ctx)->data['gps'][0];

    expect($voll[$this->jus->id])->toMatchArray(['commodity_group_code' => '13', 'sub_category' => '13.3 Saucen & Fonds', 'condition' => 'konserviert',
        'zusatz' => 'Hi Taste', 'marke_offen' => false, 'rezepturware' => true, 'lead_la_supplier_item_id' => $this->jus->lead_la_supplier_item_id,
        'lead_la' => ['designation' => 'HT Kalbsjus 1 kg', 'lieferant' => 'Chefs Culinar']])
        ->and($voll[$this->offen->id]['lead_la'])->toBeNull()
        ->and(array_keys($kurz))->toBe(['id', 'name', 'status', 'main_ingredient_slug']);
});

it('ohne_la: GPs ohne aktive Lieferantenartikel-Struktur (gelöschte zählt nicht); nur_la_pflicht lässt Derivate und GPs ohne LA-Pflicht weg', function () {
    $lieferant = FoodAlchemistSupplier::create(['team_id' => $this->rootTeam->id, 'name' => 'Hanos']);
    $struktur = fn (FoodAlchemistGp $gp, bool $geloescht = false) => \Illuminate\Support\Facades\DB::table('foodalchemist_supplier_item_structures')->insert([
        'uuid' => (string) \Illuminate\Support\Str::uuid(), 'team_id' => $this->rootTeam->id, 'gp_id' => $gp->id,
        'supplier_item_id' => FoodAlchemistSupplierItem::create(['team_id' => $this->rootTeam->id, 'supplier_id' => $lieferant->id, 'designation' => 'Artikel ' . $gp->name, 'qty' => 1, 'unit_code' => 'kg'])->id,
        'created_at' => now(), 'updated_at' => now(), 'deleted_at' => $geloescht ? now() : null]);
    $struktur($this->jus);
    $struktur($this->offen, geloescht: true);   // nur eine gelöschte Verknüpfung → gilt als ohne LA
    $this->apfel->update(['requires_la' => false]);
    $this->ungeklaert->update(['is_derivat' => true]);
    foreach ([$this->jus, $this->offen] as $g) {
        $g->update(['requires_la' => true]);
    }

    expect(($this->ids)(['ohne_la' => true]))->toBe(($this->sortiert)($this->offen, $this->apfel, $this->ungeklaert))
        ->and(($this->ids)(['ohne_la' => 'false']))->toBe(($this->sortiert)($this->jus))
        ->and(($this->ids)(['ohne_la' => true, 'nur_la_pflicht' => true]))->toBe(($this->sortiert)($this->offen))
        ->and(($this->ids)(['nur_la_pflicht' => false]))->toHaveCount(4);   // false schränkt nicht ein
});

it('lead_lieferant_inaktiv: Lead beim inaktiven oder gesperrten Lieferanten (#632); false = bestellbarer Lead, GPs ohne Lead in keiner der beiden Mengen', function () {
    $neu = fn (string $name, array $sup) => FoodAlchemistGp::create(['team_id' => $this->rootTeam->id, 'name' => $name, 'gp_key' => 'gl-' . bin2hex(random_bytes(4)) . '||',
        'status' => 'approved', 'commodity_group_code' => '07', 'lead_la_supplier_item_id' => FoodAlchemistSupplierItem::create(['team_id' => $this->rootTeam->id,
            'supplier_id' => FoodAlchemistSupplier::create(['team_id' => $this->rootTeam->id, 'name' => 'Lieferant ' . $name] + $sup)->id,
            'designation' => 'Artikel ' . $name, 'qty' => 1, 'unit_code' => 'kg'])->id]);
    $inaktiv = $neu('Mehl', ['is_inactive' => true]);
    $gesperrt = $neu('Zucker', ['status' => 'gesperrt']);
    $aktiv = $neu('Salz', []);

    expect(($this->ids)(['lead_lieferant_inaktiv' => true]))->toBe(($this->sortiert)($inaktiv, $gesperrt))
        ->and(($this->ids)(['lead_lieferant_inaktiv' => 'false', 'commodity_group_code' => '07']))->toBe(($this->sortiert)($aktiv))
        ->and(($this->ids)(['lead_lieferant_inaktiv' => false]))->not->toContain($this->offen->id);   // ohne Lead: weder noch
});

it('unbekannter Filter wird gemeldet statt still verworfen (Spec 91)', function () {
    $r = app(GpsListTool::class)->execute(['warengruppe' => '13'], $this->ctx);

    expect($r->success)->toBeTrue()->and($r->data['warnungen'][0])->toContain("unbekannter Parameter 'warengruppe' ignoriert")->toContain('commodity_group_code')
        ->and(app(GpsListTool::class)->execute(['commodity_group_code' => '13'], $this->ctx)->data)->not->toHaveKey('warnungen');
});

it('Spec 91 C15 · q sucht wie der GP-Browser; ohne status fehlen merged-GPs, status=merged zeigt sie', function () {
    $tombstone = FoodAlchemistGp::create(['team_id' => $this->rootTeam->id, 'name' => 'Kalbsjus alt', 'gp_key' => 'gl-merged||', 'status' => 'merged', 'commodity_group_code' => '13']);

    expect(($this->ids)(['q' => 'kalbsjus']))->toBe(($this->sortiert)($this->jus))
        ->and(($this->ids)(['q' => 'jus taste']))->toBe(($this->sortiert)($this->jus))
        ->and(($this->ids)([]))->not->toContain($tombstone->id)
        ->and(($this->ids)(['status' => 'merged']))->toBe(($this->sortiert)($tombstone))
        ->and(app(GpsListTool::class)->execute(['status' => 'aktiv'], $this->ctx)->errorCode)->toBe('VALIDATION_ERROR');
});

it('Spec 91 D17 · felder=voll liefert Lead-Preis, Vergleichspreis, Anzahl Lieferantenartikel und Rezepte, Allergene wie der Browser', function () {
    $t = $this->rootTeam->id;
    FoodAlchemistPrice::create(['team_id' => $t, 'supplier_item_id' => $this->jus->lead_la_supplier_item_id, 'price' => 8.5, 'status' => '0']);
    FoodAlchemistSupplierItemStructure::create(['team_id' => $t, 'supplier_item_id' => $this->jus->lead_la_supplier_item_id, 'gp_id' => $this->jus->id]);
    FoodAlchemistItemAllergen::create(['team_id' => $t, 'supplier_item_id' => $this->jus->lead_la_supplier_item_id, 'allergen_celery' => 'enthalten']);
    $rezept = $this->makeRecipe($this->rootTeam, 'Rinderschmorbraten');
    FoodAlchemistRecipeIngredient::create(['team_id' => $t, 'recipe_id' => $rezept->id, 'gp_id' => $this->jus->id, 'raw_text' => 'Kalbsjus', 'position' => 1]);

    $voll = collect(app(GpsListTool::class)->execute(['felder' => 'voll', 'sub_category' => '13.3 Saucen & Fonds'], $this->ctx)->data['gps'])->keyBy('id');

    expect($voll[$this->jus->id])->toMatchArray(['lead_preis' => 8.5, 'lead_letzter_preis' => null, 'la_count' => 1, 'rezepte_count' => 1,
        'allergen_badges' => ['celery'], 'allergen_status' => 'vorhanden'])
        ->and($voll[$this->jus->id]['lead_vergleichspreis'])->toBe(['value' => 8.5, 'unit' => '€/kg'])
        ->and($voll[$this->offen->id])->toMatchArray(['lead_preis' => null, 'la_count' => 0, 'rezepte_count' => 0, 'allergen_status' => 'keine_daten']);
});
