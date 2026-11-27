<?php

use Platform\FoodAlchemist\Models\FoodAlchemistRecipeDarreichung;
use Platform\FoodAlchemist\Services\Rezept\DarreichungService;
use Platform\FoodAlchemist\Services\Rezept\SalesRecipeService;
use Platform\FoodAlchemist\Tests\Support\GrammaturWelt;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class, GrammaturWelt::class);

/**
 * Plan 98 B, Schritt 4d — Sperre (Entscheid Dominique 22.11.2026): keine freie Grammatur mehr über Service, Oberfläche oder
 * MCP. Standard = Ansatz ÷ Portionen, Variante = ihre Komponenten (Anteil am Standard oder Mengen je Komponente).
 */
beforeEach(function () {
    $this->grammaturWeltAnlegen();
    $this->svc = app(DarreichungService::class);
    $this->dar = fn ($r, string $form) => FoodAlchemistRecipeDarreichung::where('recipe_id', $r->id)
        ->whereHas('servingForm', fn ($q) => $q->where('code', $form))->firstOrFail();
});

it('anlegen und aktualisieren lehnen quantity_per_unit_g ab — auch mit Komma oder neben menge_vom_standard', function () {
    $kinder = ($this->dar)($this->a, 'kinder');
    $vorher = $kinder->fresh()->only(['quantity_per_unit_g', 'menge_vom_standard', 'ek_portion']);

    expect(fn () => $this->svc->aktualisieren($this->rootTeam, $kinder->id, ['quantity_per_unit_g' => '120,5']))
        ->toThrow(RuntimeException::class, 'nicht direkt setzbar')
        ->and(fn () => $this->svc->aktualisieren($this->rootTeam, $kinder->id, ['quantity_per_unit_g' => 120, 'menge_vom_standard' => true]))
        ->toThrow(RuntimeException::class)
        ->and(fn () => $this->svc->anlegen($this->rootTeam, $this->a->id, ($this->form)('klein')->id, ['quantity_per_unit_g' => 80]))
        ->toThrow(RuntimeException::class, 'nicht direkt setzbar');
    expect($kinder->fresh()->only(array_keys($vorher)))->toBe($vorher)
        ->and(FoodAlchemistRecipeDarreichung::where('recipe_id', $this->a->id)->count())->toBe(5);
});

it('eine neue Variante folgt dem Standard; „eigene Menge“ hält die Komposition als Mengen je Komponente fest', function () {
    $neu = $this->svc->anlegen($this->rootTeam, $this->a->id, ($this->form)('klein')->id);
    expect((bool) $neu->menge_vom_standard)->toBeTrue()
        ->and($this->svc->grammJeEinheit($neu->fresh(), $this->a->fresh()))->toBe(300.0);

    // Vorher blieb ohne vorhandene Deltas eine freie Grammatur übrig; jetzt immer Deltas (wie der Schalter in der Oberfläche)
    $this->svc->aktualisieren($this->rootTeam, $neu->id, ['menge_vom_standard' => false]);
    $n = $neu->fresh();
    expect((bool) $n->menge_vom_standard)->toBeFalse()
        ->and($n->deltas()->count())->toBe(2)
        ->and((float) $n->quantity_per_unit_g)->toBe(300.0)
        ->and((float) $n->ek_portion)->toBe(0.75);
});

it('verkaufsrezepte-Weg: abweichender Kopf und eine Portionszahl ≠ 1 werfen, unveränderte Werte gehen durch', function () {
    $sales = app(SalesRecipeService::class);
    expect(fn () => $sales->updateVk($this->rootTeam, $this->a->id, ['sales_quantity_per_unit_g' => 250]))
        ->toThrow(RuntimeException::class, 'nicht direkt setzbar');

    $sales->updateVk($this->rootTeam, $this->a->id, ['sales_quantity_per_unit_g' => '300,0', 'name' => 'Bratkartoffeln neu']);
    expect($this->a->fresh()->name)->toBe('Bratkartoffeln neu');

    // Modell 23.11.: Portionszahl am Gericht fest 1 (leer = 1). Ein anderer Wert wirft und ändert nichts — vorher schrieb
    // der Sync Ertrag ÷ Portionen als freie Grammatur in den Standard.
    $vorher = ($this->dar)($this->a, 'teller')->only(['quantity_per_unit_g', 'menge_vom_standard', 'ek_portion']);
    expect(fn () => $sales->updateVk($this->rootTeam, $this->a->id, ['sales_unit_count' => 4]))
        ->toThrow(RuntimeException::class, 'fest 1');
    expect($this->a->fresh()->sales_unit_count)->toBeNull()
        ->and(($this->dar)($this->a, 'teller')->only(array_keys($vorher)))->toBe($vorher);

    $sales->updateVk($this->rootTeam, $this->a->id, ['sales_unit_count' => '1', 'name' => 'Bratkartoffeln 1']);
    $sales->updateVk($this->rootTeam, $this->a->id, ['sales_unit_count' => null, 'name' => 'Bratkartoffeln 2']);
    expect($this->a->fresh()->name)->toBe('Bratkartoffeln 2')->and($this->a->fresh()->sales_unit_count)->toBeNull();
});

it('Selbstheilung: ensureStandard legt einen fehlenden Standard automatisch an — ein Rezept-Kopf zählt nicht mehr (98 · 3.5 6b)', function () {
    $r = $this->makeRecipe($this->rootTeam, '[HG] Ohne Darreichung', ['is_sales_recipe' => true, 'yield_kg' => 1.0, 'sales_quantity_per_unit_g' => 250]);
    $std = $this->svc->ensureStandard($this->rootTeam, $r->id, 'test');

    expect((bool) $std->menge_vom_standard)->toBeTrue()
        ->and($this->svc->grammJeEinheit($std, $r->fresh()))->toBe(1000.0);   // Ertrag 1 kg ohne Bestandteile, nicht der Kopf-Altwert 250 g
});
