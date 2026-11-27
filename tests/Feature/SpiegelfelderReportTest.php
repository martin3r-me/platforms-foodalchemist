<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Models\FoodAlchemistVocabEinheit;
use Platform\FoodAlchemist\Services\Rezept\SpiegelfelderReport;
use Platform\FoodAlchemist\Support\Datenmodell\SpiegelfelderRueckbau;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Plan 98 · 3.5 Schritt 6c · Abweichungsreport der Spiegelfelder (nur lesend). Spiegelwerte werden per DB gesetzt, damit die
 * Spiegel-Logik der Dienste sie nicht sofort wieder angleicht.
 */
beforeEach(function () {
    // Schema zuerst (wie FremdschluesselKernketteTest): allein oder als erster im Prozess gäbe es sonst keine Tabelle.
    $this->seedTeamHierarchy();
    // 98 · 3.5 6c: im Test-Schema sind die Spalten gedroppt — für den Report kurz zurückholen, danach wieder entfernen
    SpiegelfelderRueckbau::wiederherstellen();
    $this->actingAs($this->makeUser($this->rootTeam));
    $this->portion = FoodAlchemistVocabEinheit::create(['team_id' => $this->rootTeam->id, 'slug' => 'portion', 'display_de' => 'Portion', 'dimension' => 'count', 'default_in_g' => null]);
    $this->stueck = FoodAlchemistVocabEinheit::create(['team_id' => $this->rootTeam->id, 'slug' => 'stueck', 'display_de' => 'Stück', 'dimension' => 'count', 'default_in_g' => null]);
    $setze = fn (FoodAlchemistRecipe $r, array $werte) => DB::table('foodalchemist_recipes')->where('id', $r->id)->update($werte);

    $this->stimmig = $this->makeRecipe($this->rootTeam, 'Stimmig', ['is_sales_recipe' => true]);
    $this->makeStandardDarreichung($this->stimmig, null, ['unit_vocab_id' => $this->portion->id]);
    $setze($this->stimmig, ['sales_unit_vocab_id' => $this->portion->id]);

    $this->abweichend = $this->makeRecipe($this->rootTeam, 'Abweichend', ['is_sales_recipe' => true]);
    $this->makeStandardDarreichung($this->abweichend, null, ['unit_vocab_id' => $this->portion->id]);
    $setze($this->abweichend, ['sales_unit_vocab_id' => $this->stueck->id]);

    $this->nurRezept = $this->makeRecipe($this->rootTeam, 'Nur am Rezept', ['is_sales_recipe' => true]);
    $this->makeStandardDarreichung($this->nurRezept, null, ['unit_vocab_id' => null]);
    $setze($this->nurRezept, ['sales_unit_vocab_id' => $this->stueck->id]);

    $this->vier = $this->makeRecipe($this->rootTeam, 'Vier Portionen', ['is_sales_recipe' => true]);
    $this->makeStandardDarreichung($this->vier);
    $setze($this->vier, ['sales_unit_count' => 4]);

    $this->ohne = $this->makeRecipe($this->rootTeam, 'Ohne Darreichung', ['is_sales_recipe' => true]);
    $setze($this->ohne, ['sales_unit_vocab_id' => $this->portion->id]);
});

afterEach(fn () => SpiegelfelderRueckbau::entfernen());

it('nach dem Drop: Report meldet „entfernt“ statt zu prüfen', function () {
    SpiegelfelderRueckbau::entfernen();
    $b = app(SpiegelfelderReport::class)->bericht();

    expect($b['entfernt'])->toBeTrue()->and($b['rezepte'])->toBe(0);
});

it('zählt abweichend und nur am Rezept je Feld, mit Beispielen', function () {
    $b = app(SpiegelfelderReport::class)->bericht();
    $einheit = $b['felder']['sales_unit_vocab_id'];

    expect($einheit['abweichend'])->toBe(1)->and($einheit['nur_rezept'])->toBe(1)
        ->and(collect($einheit['beispiele'])->pluck('id')->all())->toEqualCanonicalizing([$this->abweichend->id, $this->nurRezept->id])
        ->and(collect($einheit['beispiele'])->firstWhere('id', $this->nurRezept->id))->toMatchArray(['rezept' => $this->stueck->id, 'darreichung' => null, 'art' => 'nur_rezept']);
});

it('zeigt sales_unit_count > 1 extra und Spiegelwerte ohne Standard-Darreichung', function () {
    $b = app(SpiegelfelderReport::class)->bericht();

    expect($b['portionen_gt_1']['anzahl'])->toBe(1)
        ->and($b['portionen_gt_1']['beispiele'][0])->toMatchArray(['id' => $this->vier->id, 'portionen' => 4])
        ->and($b['ohne_standard']['anzahl'])->toBe(1)
        ->and($b['ohne_standard']['beispiele'][0])->toMatchArray(['id' => $this->ohne->id, 'felder' => ['sales_unit_vocab_id']]);
    $g = $b['portionen_gt_1']['beispiele'][0];
    if ($g['gramm_heute'] !== null) {
        expect($g['gramm_teiler_1'])->toEqual(round($g['gramm_heute'] * 4, 1));
    }
});

it('Befehl liefert JSON und schreibt nichts', function () {
    $vorher = DB::table('foodalchemist_recipes')->orderBy('id')->get()->toArray();
    $code = Artisan::call('foodalchemist:diagnose:spiegelfelder', ['--json' => true]);
    $json = json_decode(Artisan::output(), true);

    expect($code)->toBe(0)->and($json['rezepte'])->toBeGreaterThanOrEqual(5)
        ->and(array_keys($json['felder']))->toBe(['sales_unit_vocab_id', 'container_warm_vocab_id', 'container_cold_vocab_id', 'serving_vehicle_vocab_id', 'sales_quantity_per_unit_g'])
        ->and(DB::table('foodalchemist_recipes')->orderBy('id')->get()->toArray())->toEqual($vorher);
});
