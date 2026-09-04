<?php

use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Platform\FoodAlchemist\Livewire\Recipes\IngredientEditor;
use Platform\FoodAlchemist\Livewire\Verkauf\VkModal;
use Platform\FoodAlchemist\Models\FoodAlchemistMarkupClass;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeDarreichung;
use Platform\FoodAlchemist\Models\FoodAlchemistServierform;
use Platform\FoodAlchemist\Services\SalesRecipeService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * M9-01: VK-Editor-Vollparität — neue Felder (Marketing/Eigenschaften/Plating/
 * Notizen) über die VK_FELDER-Whitelist inkl. Lineage-manual-Stempel; Rollen-
 * Spalte nur im VK-Kontext; Rollen-Sync über den Zutaten-Editor-Payload.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam));
    config(['foodalchemist.ai.provider' => 'fake']);

    $this->vk = FoodAlchemistRecipe::create([
        'team_id' => $this->rootTeam->id, 'recipe_key' => 'vk-m9', 'name' => 'FIN: Hot Dog',
        'status' => 'draft', 'is_sales_recipe' => true,
    ]);
    $this->g = \Platform\FoodAlchemist\Models\FoodAlchemistVocabEinheit::create(['team_id' => $this->rootTeam->id, 'slug' => 'g', 'display_de' => 'Gramm', 'dimension' => 'mass', 'default_in_g' => 1]);
    $gp = $this->makeGp($this->rootTeam, 'Wiener');
    DB::table('foodalchemist_recipe_ingredients')->insert([
        'uuid' => (string) \Symfony\Component\Uid\UuidV7::generate(), 'team_id' => $this->rootTeam->id,
        'recipe_id' => $this->vk->id, 'gp_id' => $gp->id, 'raw_text' => 'Wiener', 'display_name' => 'Wiener',
        'quantity' => 50, 'unit_vocab_id' => $this->g->id, 'position' => 1, 'created_at' => now(), 'updated_at' => now(),
    ]);
    $this->zeileId = (int) DB::getPdo()->lastInsertId();
});

it('M9-Felder speichern über die Whitelist; Plating/Marketing manuell ⇒ Lineage manual', function () {
    Livewire::test(VkModal::class)
        ->call('oeffnen', $this->vk->id)
        ->set('form.marketing_text', 'Knuspriger Klassiker.')
        ->set('form.plating_text', '## Aufbau\n1. Bun setzen.')
        ->set('form.work_time_min', 8)
        ->set('form.additional_costs_eur', '1.50')
        ->set('form.function', 'Fingerfood')
        ->set('form.production_depth', 'teilfertig')
        ->set('form.notes_manual', 'Catering-Notiz')
        ->call('speichern')
        ->assertSet('fehler', null);

    $r = $this->vk->fresh();
    expect($r->marketing_text)->toBe('Knuspriger Klassiker.')
        ->and($r->marketing_text_source)->toBe('manual')
        ->and($r->plating_source)->toBe('manual')
        ->and($r->work_time_min)->toBe(8)
        ->and((float) $r->additional_costs_eur)->toBe(1.5)                 // M-K8-Pflege zurück (#379)
        ->and($r->function)->toBe('Fingerfood')
        ->and($r->production_depth)->toBe('teilfertig')
        ->and($r->notes_manual)->toBe('Catering-Notiz');
});

it('Rollen-Spalte rendert NUR im VK-Kontext; Rollen-Wert geht durch den Sync (V-21)', function () {
    $html = Livewire::test(IngredientEditor::class, ['recipeId' => $this->vk->id, 'eingebettet' => true])->html();
    expect($html)->toContain('data-role-select');

    $basis = app(\Platform\FoodAlchemist\Services\RecipeService::class)->create($this->rootTeam, ['name' => 'Fond: Basis']);
    $htmlBasis = Livewire::test(IngredientEditor::class, ['recipeId' => $basis->id, 'eingebettet' => true])->html();
    expect($htmlBasis)->not->toContain('data-role-select');

    // Rolle über den Editor-Payload (rows enthalten rolle) — syncIngredients schreibt sie
    Livewire::test(IngredientEditor::class, ['recipeId' => $this->vk->id, 'eingebettet' => true])
        ->call('speichern', [[
            'id' => $this->zeileId, 'gp_id' => DB::table('foodalchemist_recipe_ingredients')->where('id', $this->zeileId)->value('gp_id'),
            'raw_text' => 'Wiener', 'quantity' => '50', 'unit_vocab_id' => $this->g->id, 'role' => 'komponente',
        ]])
        ->assertSet('fehler', null);
    expect(DB::table('foodalchemist_recipe_ingredients')->where('recipe_id', $this->vk->id)->whereNull('deleted_at')->value('role'))
        ->toBe('komponente');
});

it('VK-Editor rendert die neuen Sektionen (Deklaration, Nährwerte, Spezifikation, Plating, KPI-Leiste)', function () {
    $html = Livewire::test(VkModal::class)->call('oeffnen', $this->vk->id)->html();
    foreach (['data-deklaration', 'data-vk-naehrwerte-leer', 'data-vk-spezifikation', 'data-vk-plating-text', 'data-vk-editor-kpis', 'data-md-toolbar', 'data-ki-wording', 'data-ki-behaelter', 'data-ki-regeneration'] as $marker) {
        expect($html)->toContain($marker);
    }
    expect($html)->toContain('Rohertragsquote')
        ->and($html)->toContain('Rohertragsquote = (VK netto − MEK) ÷ VK netto')
        ->and($html)->toContain('Verkaufs-Block (Live-Rohertrag)');
});

it('speichert den Wechsel einer Darreichung auf auto unmittelbar', function () {
    $form = \Platform\FoodAlchemist\Models\FoodAlchemistServierform::create([
        'team_id' => $this->rootTeam->id, 'code' => 'portion', 'label' => 'Portion',
    ]);
    $darreichung = \Platform\FoodAlchemist\Models\FoodAlchemistRecipeDarreichung::create([
        'team_id' => $this->rootTeam->id,
        'recipe_id' => $this->vk->id,
        'serving_form_id' => $form->id,
        'is_standard' => true,
        'price_mode' => 'fixed',
        'sales_net' => 1.50,
        'price_override_reason' => 'Testpreis',
    ]);

    $component = Livewire::test(VkModal::class)
        ->call('oeffnen', $this->vk->id)
        ->call('darreichungPreisModusGeaendert', $darreichung->id, 'auto')
        ->assertSet("darForm.{$darreichung->id}.price_mode", 'auto')
        ->assertSet('recipeId', $this->vk->id)
        ->assertNotDispatched('modal.close');

    $darreichung = $darreichung->fresh();
    expect($darreichung->price_mode)->toBe('auto')
        ->and($darreichung->price_override_reason)->toBeNull();

    // Der allgemeine Rezept-Speicherweg darf den angezeigten Legacy-VK nicht wieder
    // als manuellen Preis in die Standard-Darreichung zurückspiegeln.
    $component->call('speichern')->assertSet('fehler', null);
    expect($darreichung->fresh()->price_mode)->toBe('auto');
});

it('synchronisiert die Preisklasse aus Kalkulation sofort mit der Standard-Darreichung', function () {
    $klasseAlt = FoodAlchemistMarkupClass::create([
        'team_id' => $this->rootTeam->id,
        'code' => 'ALT',
        'label' => 'Alt',
        'class_factor_pct' => 100,
        'raw_markup_pct' => 0,
        'vat_rate' => 7,
        'formula_type' => 'aufschlag',
    ]);
    $klasseNeu = FoodAlchemistMarkupClass::create([
        'team_id' => $this->rootTeam->id,
        'code' => 'NEU',
        'label' => 'Neu',
        'class_factor_pct' => 150,
        'raw_markup_pct' => 0,
        'vat_rate' => 7,
        'formula_type' => 'aufschlag',
    ]);
    $servierform = FoodAlchemistServierform::create([
        'team_id' => $this->rootTeam->id,
        'code' => 'buffet',
        'label' => 'Buffet',
    ]);
    $this->vk->update([
        'markup_class_id' => $klasseAlt->id,
        'yield_kg' => 0.05,
        'ek_per_kg_eur' => 10,
    ]);
    $darreichung = FoodAlchemistRecipeDarreichung::create([
        'team_id' => $this->rootTeam->id,
        'recipe_id' => $this->vk->id,
        'serving_form_id' => $servierform->id,
        'is_standard' => true,
        'quantity_per_unit_g' => 50,
        'unit_count' => 1,
        'markup_class_id' => $klasseAlt->id,
        'price_mode' => 'auto',
    ]);
    app(\Platform\FoodAlchemist\Services\DarreichungService::class)->recomputePreise($darreichung);
    $alterPreis = (float) $darreichung->fresh()->sales_net;

    Livewire::test(VkModal::class)
        ->call('oeffnen', $this->vk->id)
        ->call('preisklasseGeaendert', (string) $klasseNeu->id)
        ->assertSet('form.markup_class_id', $klasseNeu->id)
        ->assertSet("darForm.{$darreichung->id}.markup_class_id", $klasseNeu->id)
        ->assertSet('fehler', null)
        ->assertNotDispatched('modal.close');

    expect($this->vk->fresh()->markup_class_id)->toBe($klasseNeu->id)
        ->and($darreichung->fresh()->markup_class_id)->toBe($klasseNeu->id)
        ->and((float) $darreichung->fresh()->sales_net)->toBeGreaterThan($alterPreis);
});

it('zeigt im Kalkulations-Tab die Preisklasse der Standard-Darreichung als Wahrheit', function () {
    $rezeptKlasse = FoodAlchemistMarkupClass::create([
        'team_id' => $this->rootTeam->id,
        'code' => 'REZ',
        'label' => 'Rezept-Cache',
        'class_factor_pct' => 100,
        'raw_markup_pct' => 0,
        'vat_rate' => 7,
        'formula_type' => 'aufschlag',
    ]);
    $darreichungsKlasse = FoodAlchemistMarkupClass::create([
        'team_id' => $this->rootTeam->id,
        'code' => 'DAR',
        'label' => 'Darreichung',
        'class_factor_pct' => 120,
        'raw_markup_pct' => 0,
        'vat_rate' => 7,
        'formula_type' => 'aufschlag',
    ]);
    $servierform = FoodAlchemistServierform::create([
        'team_id' => $this->rootTeam->id,
        'code' => 'portion',
        'label' => 'Portion',
    ]);
    $this->vk->update(['markup_class_id' => $rezeptKlasse->id]);
    FoodAlchemistRecipeDarreichung::create([
        'team_id' => $this->rootTeam->id,
        'recipe_id' => $this->vk->id,
        'serving_form_id' => $servierform->id,
        'is_standard' => true,
        'markup_class_id' => $darreichungsKlasse->id,
        'price_mode' => 'auto',
    ]);

    Livewire::test(VkModal::class)
        ->call('oeffnen', $this->vk->id)
        ->assertSet('form.markup_class_id', $darreichungsKlasse->id);
});

it('✨-Fake-Pfade sind ehrlich (kein gültiger Wert ⇒ kiFehler, Form unverändert)', function () {
    Livewire::test(VkModal::class)
        ->call('oeffnen', $this->vk->id)
        ->call('ki', 'vehikel')
        ->assertSet('fehler', fn ($f) => str_contains((string) $f, 'echter Provider'))
        ->assertSet('form.serving_vehicle_vocab_id', null);
});

it('✨ Behälter übernimmt validierte Vokabular-IDs in die Form (Mock-Gateway)', function () {
    $warmId = (int) DB::table('foodalchemist_vocab_containers')->insertGetId([
        'uuid' => (string) \Illuminate\Support\Str::uuid7(), 'team_id' => $this->rootTeam->id,
        'slug' => 'gn_11_65', 'name' => 'GN 1/1 65mm', 'sort_order' => 1, 'created_at' => now(), 'updated_at' => now(),
    ]);
    $this->mock(\Platform\FoodAlchemist\Services\Ai\AiGatewayService::class, function ($mock) use ($warmId) {
        $mock->shouldReceive('propose')->andReturn(new \Platform\FoodAlchemist\Services\Ai\AiProposal(
            ['behaelter_warm_id' => $warmId, 'behaelter_warm_anzahl' => 2, 'behaelter_kalt_id' => 999999], 0.9,
        ));
    });

    Livewire::test(VkModal::class)
        ->call('oeffnen', $this->vk->id)
        ->call('ki', 'behaelter')
        ->assertSet('fehler', null)
        ->assertSet('form.container_warm_vocab_id', $warmId)
        ->assertSet('form.container_warm_count', 2)
        ->assertSet('form.container_cold_vocab_id', null);            // ungültige ID fliegt
});

/**
 * 2026-09-04: Der Editor ist nach den drei Anleitungs-Ebenen geschnitten (Regelwerk
 * Verkaufsgerichte §3) — der Sammel-Tab „Service" ist in Regeneration und Anrichten
 * aufgeteilt. Dieser Render-Test ist die eigentliche Absicherung des Blade-Umbaus:
 * ein Kompilat-Lint ist in der Sandbox ohne MySQL nicht zu haben, ein ParseError oder
 * ein verlorener Block fällt hier als roter Test auf.
 */
it('rendert die drei Ebenen als eigene Tabs und keinen Service-Tab mehr', function () {
    $html = Livewire::test(VkModal::class)->call('oeffnen', $this->vk->id)->html();

    foreach (["tab === 'regeneration'", "tab === 'preparation'", "tab === 'plating'"] as $tab) {
        expect($html)->toContain($tab);
    }
    expect($html)->not->toContain("tab === 'service'");

    // Die Ebenen-Beschriftung ist Teil des Vertrags — sie sagt dem Koch, was hingehört.
    expect($html)->toContain('Regeneration')
        ->and($html)->toContain('Finalisieren')
        ->and($html)->toContain('Anrichten');
});

it('rendert die abgeleiteten Marken: Finalisierungszeit, Servierform-Select, Posten-Hinweis', function () {
    // Standard-Darreichung anlegen, damit die Darreichungs-Tabelle Zeilen hat.
    app(\Platform\FoodAlchemist\Services\DarreichungService::class)
        ->ensureStandard($this->rootTeam, $this->vk->id, 'fa_ui');

    $html = Livewire::test(VkModal::class)->call('oeffnen', $this->vk->id)->html();

    expect($html)->toContain('data-vk-finalisierungszeit')      // Zeitfeld neu beschriftet
        ->and($html)->toContain('data-dar-form')                // Servierform je Zeile wählbar (Review-Ausgang)
        ->and($html)->toContain('Finalisierungs-Posten');       // Posten = Zusammensetzen, nicht das ganze Gericht
});

it('bietet als Verkaufseinheit nur die vier zulaessigen Einheiten an', function () {
    foreach ([['portion', 'Portion'], ['stk', 'Stück'], ['kg', 'Kilogramm'], ['l', 'Liter']] as [$slug, $label]) {
        \Platform\FoodAlchemist\Models\FoodAlchemistVocabEinheit::firstOrCreate(
            ['team_id' => $this->rootTeam->id, 'slug' => $slug],
            ['display_de' => $label, 'dimension' => 'count']
        );
    }
    // Zutaten-Einheit, die im VK-Select NICHT auftauchen darf.
    \Platform\FoodAlchemist\Models\FoodAlchemistVocabEinheit::firstOrCreate(
        ['team_id' => $this->rootTeam->id, 'slug' => 'prise'],
        ['display_de' => 'Prise', 'dimension' => 'mass', 'is_approximate' => true]
    );

    $html = Livewire::test(VkModal::class)->call('oeffnen', $this->vk->id)->html();

    // Das VK-Select steht im Kalkulations-Tab; der Zutaten-Editor ist eine eigene Komponente,
    // deshalb darf „Prise" im HTML dieses Modals gar nicht vorkommen.
    expect($html)->toContain('data-vk-unit-select')
        ->and($html)->toContain('Portion')
        ->and($html)->not->toContain('Prise');
});
