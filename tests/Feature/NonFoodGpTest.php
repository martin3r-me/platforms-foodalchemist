<?php

use Platform\FoodAlchemist\Exceptions\NonFoodAlsZutatException;
use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Services\ConformanceService;
use Platform\FoodAlchemist\Services\DataQualityService;
use Platform\FoodAlchemist\Services\GpAggregateService;
use Platform\FoodAlchemist\Services\GpNamingService;
use Platform\FoodAlchemist\Services\GpService;
use Platform\FoodAlchemist\Services\IngredientMatchService;
use Platform\FoodAlchemist\Services\RecipeService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Non-Food PR 2a: Grundprodukte der Warengruppe 16 haben keine Allergen-/Zustand-Pflicht und sind als Lebensmittel-Zutat gesperrt
 * (Zutaten-Speichern, Zutaten-Suche, Matching, Ersetzen). Gegenprobe jeweils mit einem Lebensmittel-GP.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam));
    $this->gp = function (string $name, string $wg, array $mehr = []) {
        $gp = $this->makeGp($this->rootTeam, $name);
        $gp->update(['status' => 'approved', 'commodity_group_code' => $wg] + $mehr);

        return $gp->fresh();
    };
});

it('istNonFood und Scopes: nur Warengruppe 16; GP ohne Warengruppe zählt als Lebensmittel', function () {
    $nf = ($this->gp)('Serviette: Papier, 33 x 33 cm', '16');
    $food = ($this->gp)('Butter: frisch', '06');
    $ohne = $this->makeGp($this->rootTeam, 'Ohne WG');

    expect($nf->istNonFood())->toBeTrue()->and($food->istNonFood())->toBeFalse()->and($ohne->istNonFood())->toBeFalse()
        ->and(FoodAlchemistGp::lebensmittel()->pluck('id')->all())->toContain($food->id, $ohne->id)->not->toContain($nf->id)
        ->and(FoodAlchemistGp::nonFood()->pluck('id')->all())->toBe([$nf->id]);
});

it('Zutaten speichern: ein Non-Food-GP als Zutat wird abgelehnt (Typ, Name), nichts wird gespeichert; Lebensmittel geht', function () {
    $g = $this->unitG($this->rootTeam)->id;
    $nf = ($this->gp)('Serviette: Papier, 33 x 33 cm', '16');
    $food = ($this->gp)('Butter: frisch', '06');
    $r = $this->makeRecipe($this->rootTeam, 'Dip: Test', ['status' => 'draft']);
    $svc = app(RecipeService::class);

    $svc->syncIngredients($this->rootTeam, $r->id, [['gp_id' => $food->id, 'raw_text' => '100 g Butter', 'quantity' => '100', 'unit_vocab_id' => $g]]);
    expect($r->ingredients()->count())->toBe(1);

    try {
        $svc->syncIngredients($this->rootTeam, $r->id, [
            ['gp_id' => $food->id, 'raw_text' => '100 g Butter', 'quantity' => '100', 'unit_vocab_id' => $g],
            ['gp_id' => $nf->id, 'raw_text' => '1 Serviette', 'quantity' => '1', 'unit_vocab_id' => $g],
        ]);
        $this->fail('Non-Food als Zutat hätte abgelehnt werden müssen.');
    } catch (NonFoodAlsZutatException $e) {
        expect($e->namen)->toBe(['Serviette: Papier, 33 x 33 cm'])->and($e->getMessage())->toContain('Zubehör & Verpackung');
    }
    expect($r->ingredients()->count())->toBe(1);   // die Transaktion lief gar nicht erst an
});

it('Zutaten-Suche und Matching liefern kein Non-Food, Lebensmittel mit demselben Wort schon', function () {
    ($this->gp)('Serviette: Papier, 33 x 33 cm', '16');
    $food = ($this->gp)('Servietten-Knoedel: frisch', '09');

    $such = app(RecipeService::class)->sucheZutatenZiel($this->rootTeam, 'Serviette', 0);
    expect(array_column(array_filter($such, fn ($z) => $z['type'] === 'gp'), 'name'))->toBe(['Servietten-Knoedel: frisch']);

    $m = app(IngredientMatchService::class)->matchIngredient($this->rootTeam, 'Serviette: Papier');
    expect($m['gp_id'] ?? null)->not->toBe(FoodAlchemistGp::nonFood()->value('id'));
});

it('GP in Rezepten ersetzen: ein Non-Food-GP ist kein gültiges Ziel', function () {
    $von = ($this->gp)('Butter: frisch', '06');
    $nf = ($this->gp)('Serviette: Papier, 33 x 33 cm', '16');

    expect(fn () => app(GpService::class)->ersetzeInRezepten($von, $nf))->toThrow(NonFoodAlsZutatException::class);
});

it('Allergen-Aggregation: Non-Food nimmt nicht teil (nicht anwendbar), Lebensmittel schon', function () {
    $nf = ($this->gp)('Serviette: Papier, 33 x 33 cm', '16');
    $food = ($this->gp)('Butter: frisch', '06');
    $agg = app(GpAggregateService::class);

    $r = $agg->backfillAllergenKonfidenz($nf, true);
    expect($r['skipped'])->toBeTrue()->and($r['written'])->toBeFalse()->and($r['source'])->toBe('nicht_anwendbar')->and($nf->fresh()->allergens_confidence)->toBeNull();

    $f = $agg->backfillAllergenKonfidenz($food, true);
    expect($f['skipped'])->toBeFalse()->and($f['written'])->toBeTrue()->and($food->fresh()->allergens_confidence)->not->toBeNull();
});

it('Datenqualität: fehlende Allergen-Konfidenz zählt nur bei Lebensmitteln', function () {
    ($this->gp)('Serviette: Papier, 33 x 33 cm', '16');
    ($this->gp)('Butter: frisch', '06');
    $metrik = collect(app(DataQualityService::class)->messeAlleEbenen($this->rootTeam)['gp']['metriken'])->firstWhere('key', 'gp_allergen_konfidenz');

    expect($metrik['wert'])->toBe(1);   // nur die Butter; die Serviette hat keine Allergene
});

it('Konformität: das GP-Regelwerk wird für Non-Food nicht angewendet (kein KI-Call), Lebensmittel-GP geht den normalen Weg', function () {
    $nf = ($this->gp)('Serviette: Papier, 33 x 33 cm', '16');
    $svc = app(ConformanceService::class);

    expect($svc->pruefe($this->rootTeam, 'gp', $nf->id)['befunde'])->toBe([])
        ->and($svc->pruefeUndHeile($this->rootTeam, 'gp', $nf->id)['befunde'])->toBe([]);
});

it('Benennung Non-Food: Produkt: Eigenschaft, Größe — ohne Zustand und Diät-Suffix; Zustandswort im Namen ist ein Hinweis, kein Fehler', function () {
    $n = app(GpNamingService::class);

    $name = $n->renderGpName(['warengruppe' => '16', 'hauptzutat' => 'Handschuh', 'processing' => 'Nitril, puderfrei', 'pflichtangabe' => 'Größe M', 'condition' => 'frisch', 'bio' => true]);
    expect($name)->toBe('Handschuh: Nitril, puderfrei, Größe M');

    $ok = $n->validateGpName($name, ['warengruppe' => '16', 'hauptzutat' => 'Handschuh', 'processing' => 'Nitril, puderfrei', 'pflichtangabe' => 'Größe M']);
    expect($ok['errors'])->toBe([])->and($ok['warnings'])->toBe([]);

    $warn = $n->validateGpName('Serviette: frisch', ['warengruppe' => '16']);
    expect($warn['errors'])->toBe([])->and($warn['warnings'][0])->toContain('Non-Food braucht keinen Zustand');

    // Gegenprobe: Lebensmittel behalten das §6-Schema mit Zustand
    expect($n->renderGpName(['warengruppe' => '06', 'hauptzutat' => 'Butter', 'condition' => 'frisch']))->toBe('Butter: frisch');
});
