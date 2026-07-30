<?php

use Livewire\Livewire;
use Platform\FoodAlchemist\Models\FoodAlchemistDishClass;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeCategory;
use Platform\FoodAlchemist\Services\RecipeService;
use Platform\FoodAlchemist\Services\SalesRecipeService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * MVP-042/043/048 (Audit 23): Der Facetten-Vertrag. Eine sichtbare Zahl neben einer Facette
 * ist ein Versprechen — „klick hier, dann siehst du so viele". Gebrochen war das dreifach:
 * der Gesamtzähler summierte Facetten (verlor Rezepte ohne Kategorie: Tabelle 64, Baum 62),
 * die Kategorie-Zähler rechneten ohne die aktiven Filter (Zähler versprach 1, Ziel lieferte 0),
 * und im Gerichte-Browser kannten beide Achsen den Filtersatz der anderen nicht.
 *
 * Die Invariante, die hier festgenagelt wird — für JEDE Facette:
 *     count(Zielquery nach dem Klick) === angezeigte Facettenzahl
 * Deshalb prüfen die Tests nie eine Konstante, sondern immer Zähler GEGEN die Zielquery.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->team = $this->rootTeam;
});

// ── Basisrezepte ────────────────────────────────────────────────────────────

it('Gesamtzähler entspricht der Tabelle, auch mit Rezepten ohne Kategorie (MVP-042)', function () {
    $svc = app(RecipeService::class);
    $kat = $this->makeRecipeCategory($this->team, 'SAU-BBQ');

    $this->makeRecipe($this->team, 'BBQ Texas', ['category_id' => $kat->id]);
    $this->makeRecipe($this->team, 'Chimichurri', ['category_id' => $kat->id]);
    // Der Fall, der im Baum verschwand: sichtbar in der Tabelle, über keine Facette erreichbar.
    $this->makeRecipe($this->team, 'Heimatlose Sauce', ['category_id' => null]);

    $tabelle = $svc->paginateBrowser([], $this->team)->total();

    expect($tabelle)->toBe(3)
        // Der Gesamtzähler kommt aus derselben Query wie die Tabelle, nicht aus einer Summe.
        ->and($svc->gesamtCount($this->team, []))->toBe($tabelle)
        // Und das kategorielose Rezept ist als eigener Arbeitsvorrat erreichbar.
        ->and($svc->ohneKategorieCount($this->team, []))->toBe(1)
        ->and($svc->paginateBrowser(['ohne_kategorie' => true], $this->team)->total())->toBe(1);

    // Kern der Regression: Facettensumme + kategorielose == Tabelle. Keine verlorenen Zeilen.
    expect(array_sum($svc->hauptgruppenCounts($this->team, [])) + $svc->ohneKategorieCount($this->team, []))
        ->toBe($tabelle);
});

it('Kategorie-Zähler berücksichtigen die aktiven Filter (MVP-043)', function () {
    $svc = app(RecipeService::class);
    $katA = $this->makeRecipeCategory($this->team, 'SAU-BBQ');
    $katB = $this->makeRecipeCategory($this->team, 'SAU-JUS');
    $hgId = $katA->main_group_id;

    // Genau die Konstellation aus dem Audit: die Kategorie hat Rezepte, aber keines im Status.
    $this->makeRecipe($this->team, 'Glace de Viande', ['category_id' => $katA->id, 'status' => 'review']);
    $this->makeRecipe($this->team, 'Demi Glace', ['category_id' => $katA->id, 'status' => 'approved']);
    $this->makeRecipe($this->team, 'Jus Kalb', ['category_id' => $katB->id, 'status' => 'approved']);

    $filter = ['status' => 'review', 'hauptgruppe' => $hgId];
    $counts = $svc->kategorieCounts($this->team, $hgId, $filter);

    // Der Zähler von katA verspricht 1 — und der Klick liefert genau 1.
    expect($counts[$katA->id] ?? 0)->toBe(1)
        ->and($svc->paginateBrowser([...$filter, 'category' => $katA->id], $this->team)->total())->toBe(1)
        // katB hat unter diesem Filter nichts und darf keine Zahl versprechen.
        ->and($counts[$katB->id] ?? 0)->toBe(0)
        ->and($svc->paginateBrowser([...$filter, 'category' => $katB->id], $this->team)->total())->toBe(0);
});

it('jede Basisrezept-Facette hält ihr Versprechen über alle Filterkombinationen', function () {
    $svc = app(RecipeService::class);
    $katA = $this->makeRecipeCategory($this->team, 'SAU-BBQ');
    $katB = $this->makeRecipeCategory($this->team, 'SAU-JUS');

    $this->makeRecipe($this->team, 'BBQ Texas', ['category_id' => $katA->id, 'status' => 'review', 'taste_direction' => 'herzhaft']);
    $this->makeRecipe($this->team, 'BBQ Honig', ['category_id' => $katA->id, 'status' => 'approved', 'taste_direction' => 'suess']);
    $this->makeRecipe($this->team, 'Jus Kalb', ['category_id' => $katB->id, 'status' => 'review', 'taste_direction' => 'herzhaft']);
    $this->makeRecipe($this->team, 'Namenlos', ['category_id' => null, 'status' => 'review', 'taste_direction' => 'herzhaft']);

    $hgId = $katA->main_group_id;
    $kombinationen = [
        [],
        ['status' => 'review'],
        ['geschmack' => 'herzhaft'],
        ['search' => 'bbq'],
        ['status' => 'review', 'geschmack' => 'herzhaft'],
        ['search' => 'bbq', 'status' => 'approved'],
    ];

    foreach ($kombinationen as $basis) {
        $als = json_encode($basis);

        // (a) Gesamtzähler == Tabelle
        expect($svc->gesamtCount($this->team, $basis))
            ->toBe($svc->paginateBrowser($basis, $this->team)->total(), "Gesamtzähler bei {$als}");

        // (b) Hauptgruppen-Facette == Zielmenge nach dem Klick
        foreach ($svc->hauptgruppenCounts($this->team, $basis) as $id => $n) {
            expect((int) $n)->toBe(
                $svc->paginateBrowser([...$basis, 'hauptgruppe' => (int) $id], $this->team)->total(),
                "HG-Facette {$id} bei {$als}"
            );
        }

        // (c) „Ohne Kategorie" == Zielmenge nach dem Klick
        expect($svc->ohneKategorieCount($this->team, $basis))->toBe(
            $svc->paginateBrowser([...$basis, 'ohne_kategorie' => true], $this->team)->total(),
            "Ohne-Kategorie-Facette bei {$als}"
        );

        // (d) Kategorie-Facette innerhalb der offenen Hauptgruppe == Zielmenge nach dem Klick
        $mitHg = [...$basis, 'hauptgruppe' => $hgId];
        foreach ($svc->kategorieCounts($this->team, $hgId, $mitHg) as $id => $n) {
            expect((int) $n)->toBe(
                $svc->paginateBrowser([...$mitHg, 'category' => (int) $id], $this->team)->total(),
                "Kategorie-Facette {$id} bei {$als}"
            );
        }
    }
});

// ── Gerichte (Modell A: Hauptgruppe und Diät-Klasse sind unabhängige Achsen) ──

it('jede Gerichte-Facette hält ihr Versprechen, auch kombiniert (MVP-048)', function () {
    $svc = app(SalesRecipeService::class);

    $hgKaese = $this->makeMainGroup($this->team, 'KAE');
    $hgAmuse = $this->makeMainGroup($this->team, 'AMU');
    // Modell A: die vier Diätformen hängen an KEINER Hauptgruppe (`dish_main_group_id = null`) —
    // genau daran erkennt der Browser sie als eigene Achse.
    $klasse = fn (string $code, string $label, string $diet) => FoodAlchemistDishClass::create([
        'team_id' => $this->team->id, 'code' => $code, 'label' => $label,
        'diet_form' => $diet, 'dish_main_group_id' => null,
    ]);
    $vegan = $klasse('VEG', 'Vegan', 'vegan');
    $fleisch = $klasse('FLE', 'Fleisch', 'fleisch');

    $vk = fn (string $name, $hg, $klasse, string $status = 'approved') => $this->makeRecipe($this->team, $name, [
        'is_sales_recipe' => true, 'dish_main_group_id' => $hg->id, 'dish_class_id' => $klasse->id, 'status' => $status,
    ]);

    $vk('Käseplatte', $hgKaese, $fleisch);
    $vk('Vegane Käseplatte', $hgKaese, $vegan, 'review');
    $vk('Amuse Rote Bete', $hgAmuse, $vegan);

    $kombinationen = [
        [],
        ['status' => 'review'],
        ['search' => 'käse'],
        ['hauptgruppe' => $hgKaese->id],
        ['class' => $vegan->id],
        // Der Audit-Fall: sichtbare HG neben aktiver Klasse anklicken → lieferte 0 Treffer.
        ['class' => $vegan->id, 'hauptgruppe' => $hgKaese->id],
    ];

    foreach ($kombinationen as $basis) {
        $als = json_encode($basis);

        foreach ($svc->hauptgruppenCounts($this->team, $basis) as $id => $n) {
            expect((int) $n)->toBe(
                $svc->paginateBrowser([...$basis, 'hauptgruppe' => (int) $id], $this->team)->total(),
                "VK-HG-Facette {$id} bei {$als}"
            );
        }

        foreach ($svc->klassenCounts($this->team, $basis) as $id => $n) {
            expect((int) $n)->toBe(
                $svc->paginateBrowser([...$basis, 'class' => (int) $id], $this->team)->total(),
                "VK-Klassen-Facette {$id} bei {$als}"
            );
        }
    }
});

// ── UI: der Baum muss die geprüften Zahlen auch zeigen und bedienbar machen ──

it('Basisrezept-Baum zeigt den Gesamtzähler der Tabelle und macht „Ohne Kategorie" klickbar', function () {
    $kat = $this->makeRecipeCategory($this->team, 'SAU-BBQ');
    $this->makeRecipe($this->team, 'BBQ Texas', ['category_id' => $kat->id]);
    $this->makeRecipe($this->team, 'Heimatlose Sauce', ['category_id' => null]);
    $this->actingAs($this->makeUser($this->team, 'Root User'));

    $c = Livewire::test(\Platform\FoodAlchemist\Livewire\Recipes\Browser::class)
        // 2 statt 1: der kategorielose Datensatz zählt mit, obwohl er in keiner HG-Facette steckt.
        ->assertViewHas('gesamtCount', 2)
        ->assertViewHas('ohneKategorieCount', 1)
        ->assertSee('Ohne Kategorie')
        ->assertSee('Heimatlose Sauce');

    // Klick auf die Facette liefert genau den versprochenen Arbeitsvorrat …
    $c->call('waehleOhneKategorie')
        ->assertSet('ohneKategorie', true)
        ->assertSee('Heimatlose Sauce')
        ->assertDontSee('BBQ Texas');

    // … und schließt die Hauptgruppen-Achse aus, statt sich mit ihr zu vermischen.
    $c->call('waehleHauptgruppe', $kat->main_group_id)
        ->assertSet('ohneKategorie', false)
        ->assertSee('BBQ Texas')
        ->assertDontSee('Heimatlose Sauce');
});

it('Gerichte-Baum zeigt den Gesamtzähler aus der Tabellenquery', function () {
    $hg = $this->makeMainGroup($this->team, 'KAE');
    $this->makeRecipe($this->team, 'Käseplatte', ['is_sales_recipe' => true, 'dish_main_group_id' => $hg->id]);
    // Gericht ohne Hauptgruppe: fehlt in $hgCounts, muss in der Gesamtzahl trotzdem auftauchen.
    $this->makeRecipe($this->team, 'Gericht ohne HG', ['is_sales_recipe' => true, 'dish_main_group_id' => null]);
    $this->actingAs($this->makeUser($this->team, 'Root User'));

    Livewire::test(\Platform\FoodAlchemist\Livewire\Verkauf\Browser::class)
        ->assertViewHas('gesamtCount', 2)
        ->assertViewHas('hgCounts', fn ($c) => array_sum($c) === 1);
});

it('Facetten-Zähler bleiben teamgescopt — Geschwisterdaten zählen nicht mit', function () {
    $svc = app(RecipeService::class);
    $katA = $this->makeRecipeCategory($this->childA, 'A-SAU');

    $this->makeRecipe($this->childA, 'Kind-A-Sauce', ['category_id' => $katA->id]);
    $this->makeRecipe($this->childB, 'Kind-B-Sauce', ['category_id' => $this->makeRecipeCategory($this->childB, 'B-SAU')->id]);

    expect($svc->gesamtCount($this->childA, []))->toBe(1)
        ->and($svc->hauptgruppenCounts($this->childA, []))->toBe([$katA->main_group_id => 1])
        ->and(array_sum($svc->hauptgruppenCounts($this->childB, [])))->toBe(1);
});
