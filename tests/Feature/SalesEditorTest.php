<?php

use Illuminate\Support\Facades\DB;
use Platform\FoodAlchemist\Models\FoodAlchemistMarkupClass;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Models\FoodAlchemistVocabEinheit;
use Platform\FoodAlchemist\Services\SalesRecipeService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * M6-04: VK-Editor-Schreibpfade — createFromBasis (DoD), updateVk (V-12-Feld-
 * Gate, brutto-Konsistenz, Wording-Lineage), V-19-Regen-CRUD + Reorder,
 * Verwendungsnachweise (§7.7 team-scoped + Kaskade).
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->svc = app(SalesRecipeService::class);

    FoodAlchemistVocabEinheit::create(['team_id' => $this->rootTeam->id, 'slug' => 'g', 'display_de' => 'Gramm', 'dimension' => 'mass', 'default_in_g' => 1]);
    $this->basis = FoodAlchemistRecipe::create([
        'team_id' => $this->rootTeam->id, 'recipe_key' => 'basis', 'name' => 'Sauce: BBQ', 'status' => 'approved',
        'yield_kg' => 1.06, 'ek_total_eur' => 6.66, 'ek_per_kg_eur' => 6.28,
    ]);
});

it('DoD: createFromBasis legt VK mit ganzer Charge als Komponente an (Recompute läuft)', function () {
    $vk = $this->svc->createFromBasis($this->rootTeam, $this->basis->id, 'FIN: Dog | BBQ');

    expect($vk->is_sales_recipe)->toBeTrue()
        ->and($vk->ingredients()->count())->toBe(1)
        ->and($vk->ingredients()->first()->referenced_recipe_id)->toBe($this->basis->id)
        ->and((float) $vk->ingredients()->first()->quantity)->toBe(1060.0);  // yield_kg × 1000

    // VK-Rezepte sind kein createFromBasis-Quell-Kandidat (basis()-Scope wirft)
    expect(fn () => $this->svc->createFromBasis($this->rootTeam, $vk->id, 'X'))
        ->toThrow(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
});

it('updateVk: nur VK-Feldgruppen (V-12), brutto konsistent, Wording-Edit setzt Lineage manual', function () {
    $vk = $this->svc->createFromBasis($this->rootTeam, $this->basis->id, 'FIN: Dog | BBQ');
    DB::table('foodalchemist_recipes')->where('id', $vk->id)
        ->update(['sales_wording_source' => 'ai_suggested', 'sales_wording_ai_confidence' => 0.8]);

    $nach = $this->svc->updateVk($this->rootTeam, $vk->id, [
        'sales_net' => 9.90, 'vat_rate' => 19, 'sales_wording_standard' => 'Honey Dog',
        'status' => 'approved',                                       // NICHT in VK_FELDER → ignoriert
        'ek_total_eur' => 0.01,                                       // Aggregat → ignoriert (I9-Geist)
    ]);

    expect((float) $nach->sales_net)->toBe(9.90)
        ->and((float) $nach->sales_gross)->toBe(11.78)                  // 9,90 × 1,19
        ->and($nach->sales_wording_standard)->toBe('Honey Dog')
        ->and($nach->sales_wording_source)->toBe('manual')
        ->and($nach->sales_wording_ai_confidence)->toBeNull()
        ->and($nach->status->value)->toBe('draft')
        ->and((float) $nach->ek_total_eur)->toBe(6.66);

    // netto zurück auf null ⇒ brutto folgt
    expect($this->svc->updateVk($this->rootTeam, $vk->id, ['sales_net' => null])->sales_gross)->toBeNull();
});

it('V-19: Regen-Zeilen CRUD + Reorder; Liste bleibt sortiert', function () {
    $vk = $this->svc->createFromBasis($this->rootTeam, $this->basis->id, 'FIN: Dog | BBQ');

    $this->svc->upsertRegeneration($this->rootTeam, $vk->id, ['component_label' => 'Schmorgericht', 'temp_c' => 140]);
    $this->svc->upsertRegeneration($this->rootTeam, $vk->id, ['component_label' => 'Püree', 'temp_c' => 80]);
    $ids = DB::table('foodalchemist_recipe_regenerations')->where('recipe_id', $vk->id)->whereNull('deleted_at')
        ->orderBy('sort_order')->pluck('id')->all();

    $this->svc->reorderRegenerations($this->rootTeam, $vk->id, array_reverse($ids));
    $labels = DB::table('foodalchemist_recipe_regenerations')->where('recipe_id', $vk->id)->whereNull('deleted_at')
        ->orderBy('sort_order')->pluck('component_label')->all();
    expect($labels)->toBe(['Püree', 'Schmorgericht']);

    $this->svc->upsertRegeneration($this->rootTeam, $vk->id, ['component_label' => 'Püree', 'temp_c' => 85, 'core_temp_c' => 70], $ids[1]);
    $zeile = DB::table('foodalchemist_recipe_regenerations')->where('id', $ids[1])->first();
    expect((int) $zeile->temp_c)->toBe(85)->and((int) $zeile->core_temp_c)->toBe(70)->and($zeile->source)->toBe('manual');

    $this->svc->deleteRegeneration($this->rootTeam, $vk->id, $ids[0]);
    expect(DB::table('foodalchemist_recipe_regenerations')->where('recipe_id', $vk->id)->whereNull('deleted_at')->count())->toBe(1);
});

it('§7.7: Verwendungsnachweise — Upsert je Kunde, distinct team-scoped, FK-Kaskade', function () {
    $vk = $this->svc->createFromBasis($this->rootTeam, $this->basis->id, 'FIN: Dog | BBQ');

    $this->svc->addCustomerName($this->rootTeam, $vk->id, 'Hotel Adler', 'Honey Dog');
    $this->svc->addCustomerName($this->rootTeam, $vk->id, 'Hotel Adler', 'Honey Dog v2');  // Upsert, kein Dupe
    $this->svc->addCustomerName($this->rootTeam, $vk->id, 'Messe Catering', 'BBQ Dog');

    expect(DB::table('foodalchemist_recipe_customer_names')->where('recipe_id', $vk->id)->whereNull('deleted_at')->count())->toBe(2)
        ->and(DB::table('foodalchemist_recipe_customer_names')->where('recipe_id', $vk->id)->where('customer_name', 'Hotel Adler')->value('marketing_name'))->toBe('Honey Dog v2')
        ->and($this->svc->distinctCustomerNames($this->rootTeam))->toBe(['Hotel Adler', 'Messe Catering'])
        ->and($this->svc->distinctCustomerNames($this->childA))->toBe([]);  // team-scoped

    $vk->forceDelete();                                               // hartes Löschen → FK kaskadiert
    expect(DB::table('foodalchemist_recipe_customer_names')->where('recipe_id', $vk->id)->count())->toBe(0);
});

it('Cockpit nach Pflege: Vorschlag aus Klasse, g/Einheit aus Yield/Anzahl (E2E-Spiegel)', function () {
    $alc = FoodAlchemistMarkupClass::create(['code' => 'ALC', 'label' => 'A la Carte', 'raw_markup_pct' => 420, 'vat_rate' => 19, 'formula_type' => 'aufschlag']);
    $vk = $this->svc->createFromBasis($this->rootTeam, $this->basis->id, 'FIN: Dog | BBQ');
    $this->svc->updateVk($this->rootTeam, $vk->id, ['markup_class_id' => $alc->id, 'sales_unit_count' => 4]);

    $cockpit = $this->svc->cockpit($this->svc->detail($this->rootTeam, $vk->id));

    expect($cockpit['verkauft_als']['g_pro_einheit'])->toBe(265.0)    // 1,06 kg / 4
        ->and($cockpit['vk']['source'])->toBe('class')
        ->and($cockpit['vk']['sales_net'])->toBeGreaterThan(0);
});
