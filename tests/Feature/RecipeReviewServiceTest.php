<?php

use Illuminate\Support\Facades\DB;
use Platform\Core\Contracts\LLMProviderContract;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Services\RecipeReviewService;
use Platform\FoodAlchemist\Tests\Support\CopilotStub;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 03 · L6 — Rezept-Copilot, Service-Hälfte.
 *
 * Zwei Dinge sind zu beweisen:
 *  1. **Der Pass ratet nicht.** Ein `fehlt`-Befund ohne GP-/Sub-Treffer ist
 *     NICHT anwendbar (Hard-Stop), ein Befund ohne Zielzeile ebenso wenig.
 *  2. **Eine Übernahme trifft genau eine Zeile.** `syncIngredients` hat
 *     Voll-Ersatz-Semantik — der Rest des Rezepts muss den Apply unverändert
 *     überleben (Pflege-Felder inklusive, V-027).
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam));

    $this->g = \Platform\FoodAlchemist\Models\FoodAlchemistVocabEinheit::create([
        'team_id' => $this->rootTeam->id, 'slug' => 'g', 'display_de' => 'Gramm', 'dimension' => 'mass', 'default_in_g' => 1,
    ]);
    $this->kg = \Platform\FoodAlchemist\Models\FoodAlchemistVocabEinheit::create([
        'team_id' => $this->rootTeam->id, 'slug' => 'kg', 'display_de' => 'Kilogramm', 'dimension' => 'mass', 'default_in_g' => 1000,
    ]);

    $this->recipe = FoodAlchemistRecipe::create([
        'team_id' => $this->rootTeam->id, 'recipe_key' => 'l6-basis', 'name' => 'Kartoffelpüree',
        'status' => 'draft', 'preparation' => 'Kartoffeln kochen, stampfen, Butter einrühren.',
    ]);

    $this->gpKartoffel = $this->makeGp($this->rootTeam, 'Kartoffel: frisch');
    $this->gpButter = $this->makeGp($this->rootTeam, 'Butter');

    $this->zeile = fn (int $gpId, string $text, float $menge, array $extra = []) => DB::table('foodalchemist_recipe_ingredients')->insertGetId([
        ...['uuid' => (string) \Symfony\Component\Uid\UuidV7::generate(), 'team_id' => $this->rootTeam->id,
            'recipe_id' => $this->recipe->id, 'gp_id' => $gpId, 'raw_text' => $text, 'display_name' => $text,
            'quantity' => $menge, 'unit_vocab_id' => $this->g->id, 'position' => 1,
            'created_at' => now(), 'updated_at' => now()],
        ...$extra,
    ]);

    $this->idKartoffel = ($this->zeile)($this->gpKartoffel->id, 'Kartoffel', 1000, ['cooking_loss_pct' => 12, 'cooking_loss_source' => 'manual', 'note' => 'mehligkochend']);
    $this->idButter = ($this->zeile)($this->gpButter->id, 'Butter', 80, ['position' => 2, 'is_value_relevant' => true]);
});

it('L6: normalisiert die Befunde und entscheidet je Befund, ob er anwendbar IST', function () {
    $this->makeGp($this->rootTeam, 'Muskatnuss');                     // fehlt-Befund findet ein Ziel

    CopilotStub::bind([
        ['art' => 'menge', 'zutat_id' => $this->idButter, 'quantity' => 120, 'begruendung' => 'Zu wenig Fett für 1 kg Kartoffeln.', 'konfidenz' => 0.8],
        ['art' => 'einheit', 'zutat_id' => $this->idKartoffel, 'einheit_slug' => 'kg', 'quantity' => 1, 'begruendung' => 'kg ist die übliche Ansatz-Einheit.', 'konfidenz' => 'hoch'],
        ['art' => 'fehlt', 'zutat_text' => 'Muskatnuss', 'quantity' => 1, 'einheit_slug' => 'g', 'begruendung' => 'Klassische Würzung fehlt.', 'konfidenz' => 90],
        ['art' => 'fehlt', 'zutat_text' => 'Trüffel-Espuma vom Périgord', 'quantity' => 50, 'begruendung' => 'Würde das Püree heben.', 'konfidenz' => 0.4],
        ['art' => 'menge', 'zutat_id' => 99999, 'quantity' => 5, 'begruendung' => 'Zeile gibt es nicht.'],
        ['art' => 'reihenfolge', 'begruendung' => 'Butter erst nach dem Stampfen.', 'konfidenz' => 0.7],
        ['art' => 'hinweis', 'begruendung' => ''],                    // leer = Rauschen
    ]);

    $ergebnis = app(RecipeReviewService::class)->pruefe($this->rootTeam, $this->recipe->id);

    expect($ergebnis['gesamturteil'])->toBe('Solide Basis, kleine Lücken.')
        ->and($ergebnis['befunde'])->toHaveCount(6);                  // der leere Befund fällt raus

    $b = $ergebnis['befunde'];
    expect($b[0]['auto_applicable'])->toBeTrue()                      // menge auf echter Zeile
        ->and($b[1]['auto_applicable'])->toBeTrue()                   // einheit im Vokabular
        ->and($b[1]['konfidenz'])->toBe(0.9)                          // 'hoch' → 0.9
        ->and($b[2]['auto_applicable'])->toBeTrue()                   // fehlt MIT GP-Treffer
        ->and($b[2]['kind'])->toBe('gp')
        ->and($b[2]['konfidenz'])->toBe(0.9)                          // 90 → 0.9 (Prozent-Lesart)
        ->and($b[3]['auto_applicable'])->toBeFalse()                  // fehlt OHNE Treffer = Hard-Stop
        ->and($b[3]['status'])->toBe('kein_treffer')
        ->and($b[3]['primaer'])->not->toBeNull()
        ->and($b[4]['auto_applicable'])->toBeFalse()                  // erfundene zutat_id
        ->and($b[4]['status'])->toBe('kein_ziel')
        ->and($b[5]['art'])->toBe('hinweis')                          // unbekannte Art wird entschärft
        ->and($b[5]['status'])->toBe('nur_hinweis');
});

it('L6: findet die Zielzeile auch über den Namen, wenn das Modell keine id mitschickt', function () {
    CopilotStub::bind([['art' => 'menge', 'zutat_text' => 'Butter', 'quantity' => 150, 'begruendung' => 'Mehr Fett.']]);

    $b = app(RecipeReviewService::class)->pruefe($this->rootTeam, $this->recipe->id)['befunde'][0];

    expect($b['zutat_id'])->toBe($this->idButter)->and($b['auto_applicable'])->toBeTrue();
});

it('L6: eine Mengen-Übernahme ändert genau ihre Zeile — der Rest überlebt unverändert', function () {
    $service = app(RecipeReviewService::class);
    $service->uebernehmen($this->rootTeam, $this->recipe->id, [
        'art' => 'menge', 'zutat_id' => $this->idButter, 'quantity' => 120, 'auto_applicable' => true,
    ]);

    $butter = DB::table('foodalchemist_recipe_ingredients')->find($this->idButter);
    $kartoffel = DB::table('foodalchemist_recipe_ingredients')->find($this->idKartoffel);

    expect((float) $butter->quantity)->toBe(120.0)
        ->and((bool) $butter->is_value_relevant)->toBeTrue()          // Pflege-Feld der Zielzeile bleibt (V-027)
        ->and((float) $kartoffel->quantity)->toBe(1000.0)             // fremde Zeile unangetastet
        ->and((float) $kartoffel->cooking_loss_pct)->toBe(12.0)
        ->and($kartoffel->cooking_loss_source)->toBe('manual')
        ->and($kartoffel->note)->toBe('mehligkochend')
        ->and($kartoffel->gp_id)->toBe($this->gpKartoffel->id);
});

it('L6: entfernen löscht genau eine Zeile, fehlt legt eine geerdete Zeile an', function () {
    $gpMuskat = $this->makeGp($this->rootTeam, 'Muskatnuss');
    $service = app(RecipeReviewService::class);

    $service->uebernehmen($this->rootTeam, $this->recipe->id, [
        'art' => 'fehlt', 'zutat_text' => 'Muskatnuss', 'quantity' => 2, 'einheit_slug' => 'g', 'auto_applicable' => true,
    ]);
    $service->uebernehmen($this->rootTeam, $this->recipe->id, [
        'art' => 'entfernen', 'zutat_id' => $this->idButter, 'auto_applicable' => true,
    ]);

    $zeilen = DB::table('foodalchemist_recipe_ingredients')
        ->where('recipe_id', $this->recipe->id)->whereNull('deleted_at')->get();

    expect($zeilen)->toHaveCount(2)
        ->and($zeilen->pluck('display_name')->all())->not->toContain('Butter');

    $neu = $zeilen->firstWhere('display_name', 'Muskatnuss');
    expect($neu)->not->toBeNull()
        ->and($neu->gp_id)->toBe($gpMuskat->id)                       // im Sync gegroundet (#508-Pfad)
        ->and((float) $neu->quantity)->toBe(2.0);
});

it('L6: ein nicht anwendbarer Befund wird nicht geschrieben', function () {
    expect(fn () => app(RecipeReviewService::class)->uebernehmen($this->rootTeam, $this->recipe->id, [
        'art' => 'fehlt', 'zutat_text' => 'Trüffel-Espuma', 'auto_applicable' => false,
    ]))->toThrow(RuntimeException::class);

    expect(DB::table('foodalchemist_recipe_ingredients')->where('recipe_id', $this->recipe->id)->whereNull('deleted_at')->count())->toBe(2);
});

it('L6: das Gericht läuft über den VK-Prompt und bekommt die Verkaufs-Facetten als Massstab', function () {
    $klasse = \Platform\FoodAlchemist\Models\FoodAlchemistDishClass::create([
        'dish_main_group_id' => \Platform\FoodAlchemist\Models\FoodAlchemistDishMainGroup::create(
            ['code' => 'FIN', 'label' => 'Fingerfood'])->id,
        'code' => 'FIN-VEG', 'label' => 'Fingerfood vegan', 'diet_form' => 'vegan',
    ]);
    $vk = FoodAlchemistRecipe::create([
        'team_id' => $this->rootTeam->id, 'recipe_key' => 'l6-vk', 'name' => 'FIN: Slider',
        'status' => 'draft', 'is_sales_recipe' => true, 'dish_class_id' => $klasse->id,
        'sales_unit_count' => 4, 'sales_quantity_per_unit_g' => 60,
    ]);
    DB::table('foodalchemist_recipe_ingredients')->insert([
        'uuid' => (string) \Symfony\Component\Uid\UuidV7::generate(), 'team_id' => $this->rootTeam->id,
        'recipe_id' => $vk->id, 'gp_id' => $this->gpButter->id, 'raw_text' => 'Butter', 'display_name' => 'Butter',
        'quantity' => 20, 'unit_vocab_id' => $this->g->id, 'position' => 1, 'created_at' => now(), 'updated_at' => now(),
    ]);

    CopilotStub::bind([['art' => 'hinweis', 'begruendung' => '60 g pro Stück ist für Fingerfood grenzwertig.']]);
    $ergebnis = app(RecipeReviewService::class)->pruefe($this->rootTeam, $vk->id);

    expect($ergebnis['befunde'])->toHaveCount(1)
        ->and($GLOBALS['l6_user_prompt'])->toContain('Fingerfood vegan')
        ->and($GLOBALS['l6_user_prompt'])->toContain('speisen_klasse');
});

it('L6: ohne Provider kein halber Zustand — der Pass scheitert sichtbar, das Rezept bleibt unberührt', function () {
    config(['foodalchemist.ai.provider' => 'core']);
    app()->bind(LLMProviderContract::class, fn () => throw new RuntimeException('kein Provider gebunden'));

    expect(fn () => app(RecipeReviewService::class)->pruefe($this->rootTeam, $this->recipe->id))
        ->toThrow(RuntimeException::class);

    expect(DB::table('foodalchemist_recipe_ingredients')->where('recipe_id', $this->recipe->id)->whereNull('deleted_at')->count())->toBe(2);
});
