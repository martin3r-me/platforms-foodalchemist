<?php

use Platform\FoodAlchemist\Models\FoodAlchemistDishMainGroup;
use Platform\FoodAlchemist\Models\FoodAlchemistFoodbook;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeDarreichung;
use Platform\FoodAlchemist\Models\FoodAlchemistServierform;
use Platform\FoodAlchemist\Services\MenuAssemblyService;
use Platform\FoodAlchemist\Services\PlanningFrameService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * 12·S3b — die Slot-Rolle bindet den Slot (Lesart (b) lexikografisch).
 *
 * Der Anlass ist gemessen, nicht vermutet: am echten Gerüst besetzte der Solver den Slot
 * „Hauptgang" mit Kuchen und Fingerfood, weil er allein aufs DB sortierte — die Slot-Semantik,
 * die der greedy Generator daneben als ERSTES Kriterium anwendet, kannte er nicht (Bug #651).
 *
 * Der tragende Test ist deshalb der erste: die Fixture ist so geschnitten, dass die rollen-
 * treue Lösung **messbar schlechter** im DB ist (40 € statt 71 € p. P.). Wäre sie es nicht,
 * wäre ein grüner Test kein Beweis für die neue Ebene, sondern nur dafür, dass Rollen-Treue
 * und DB-Maximum in der Fixture zufällig zusammenfallen — genau die Falle aus V-019/V-020.
 *
 * Die zweite Zusicherung ist die Gegenrichtung: die Ebene **bindet**, aber sie **sperrt nicht**.
 * Kein `reject` im Filter — reicht die passende Hauptgruppe nicht für alle Plätze, wird der
 * Slot trotzdem voll und der Bruch wird ein benannter Befund.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->assembly = app(MenuAssemblyService::class);
    $this->frames = app(PlanningFrameService::class);

    $this->sf = FoodAlchemistServierform::firstOrCreate(
        ['code' => 'unbestimmt', 'team_id' => $this->rootTeam->id],
        ['label' => 'Unbestimmt']
    );

    $this->hgHaupt = FoodAlchemistDishMainGroup::create([
        'team_id' => $this->rootTeam->id, 'code' => 'HGR', 'label' => 'Hauptgericht',
    ]);
    $this->hgDessert = FoodAlchemistDishMainGroup::create([
        'team_id' => $this->rootTeam->id, 'code' => 'FIN', 'label' => 'Dessert',
    ]);

    $this->mk = function (string $key, string $name, float $vk, float $ek, $hg): FoodAlchemistRecipe {
        $r = FoodAlchemistRecipe::create([
            'team_id' => $this->rootTeam->id, 'recipe_key' => $key, 'name' => $name,
            'status' => 'approved', 'is_sales_recipe' => true,
            'dish_main_group_id' => $hg?->id,
        ]);
        FoodAlchemistRecipeDarreichung::create([
            'team_id' => $this->rootTeam->id, 'recipe_id' => $r->id, 'serving_form_id' => $this->sf->id,
            'is_standard' => true, 'sales_net' => $vk, 'ek_portion' => $ek,
        ]);

        return $r;
    };

    // Hand-Rechnung (2 Plätze im Slot „Hauptgang", Preisdeckel 100 € p. P. — bindet nicht,
    // erzwingt aber den B&B-Pfad statt `slot_unabhaengig`):
    //   rein nach DB : Bienenstich 38 + Brownie 33 = 71 € DB — und 2 Rollen-Brüche
    //   rollen-treu  : Rind        25 + Kalb    15 = 40 € DB — und 0 Rollen-Brüche
    // Die Rollen-Treue kostet also genau 31,00 € DB p. P. Das ist die Zahl, die die
    // Erklärung ausweisen muss — sonst ist sie unbelegt.
    $this->vier = function (): void {
        ($this->mk)('h1', 'Rind Roulade', 30.00, 5.00, $this->hgHaupt);      // DB 25
        ($this->mk)('h2', 'Kalb Geschnetzeltes', 20.00, 5.00, $this->hgHaupt); // DB 15
        ($this->mk)('d1', 'Bienenstich', 40.00, 2.00, $this->hgDessert);      // DB 38
        ($this->mk)('d2', 'Brownie Cubes', 35.00, 2.00, $this->hgDessert);    // DB 33
    };

    $fb = FoodAlchemistFoodbook::create(['team_id' => $this->rootTeam->id, 'label' => 'S3b-FB']);
    $this->frame = $this->frames->frameFor($this->rootTeam, 'foodbook', $fb->id);
});

it('die Rollen-Ebene schlägt das DB: der Hauptgang bekommt Fleisch, nicht den margenstärkeren Kuchen', function () {
    ($this->vier)();
    $this->frames->addSlot($this->rootTeam, $this->frame, ['label' => 'Hauptgang', 'target_count' => 2]);
    $this->frames->setHead($this->rootTeam, $this->frame, ['price_max_pp' => 100.00]);

    $res = $this->assembly->assembliere($this->rootTeam, $this->frame->refresh());

    expect(collect($res['gerichte'])->pluck('name')->sort()->values()->all())
        ->toBe(['Kalb Geschnetzeltes', 'Rind Roulade'])
        // 40 € statt 71 € — die Ebene liegt VOR dem DB, nicht daneben
        ->and($res['zielfunktion']['db_pp'])->toBe(40.0)
        ->and($res['slot_semantik']['fremdlinge'])->toBe(0)
        ->and($res['slot_semantik']['brueche'])->toBe([])
        ->and($res['slots'][0]['rolle_aufloesbar'])->toBeTrue()
        ->and($res['slots'][0]['hg_fremdlinge'])->toBe(0)
        ->and($res['slots'][0]['status'])->toBe('befuellt');

    foreach ($res['slots'][0]['gerichte'] as $g) {
        expect($g['passt_zum_slot'])->toBeTrue()->and($g['hg_label'])->toBe('hauptgericht');
    }
});

it('auch der Pfad ohne Suche respektiert die Rolle — dort trägt allein die Sortierung', function () {
    // Ohne Menü-weite Vorgabe und ohne Slot-Quote löst der Motor `slot_unabhaengig`: er nimmt
    // je Slot schlicht die ersten n. Dieser Pfad hat kein Blatt und keine Schranke — was die
    // Rolle hier durchsetzt, ist ALLEIN die Sortierung in `aufgabenFuer`. Ohne diesen Test
    // wäre die Sortierung von den B&B-Schranken mit-abgedeckt und damit unbelegt.
    ($this->vier)();
    $this->frames->addSlot($this->rootTeam, $this->frame, ['label' => 'Hauptgang', 'target_count' => 2]);

    $res = $this->assembly->assembliere($this->rootTeam, $this->frame->refresh());

    expect($res['verfahren'])->toBe('slot_unabhaengig')
        ->and(collect($res['gerichte'])->pluck('name')->sort()->values()->all())
        ->toBe(['Kalb Geschnetzeltes', 'Rind Roulade'])
        ->and($res['slot_semantik']['fremdlinge'])->toBe(0);
});

it('kein reject im Filter: reicht die passende Hauptgruppe nicht, wird der Slot trotzdem voll — und der Bruch benannt', function () {
    ($this->vier)();
    // 3 Plätze, aber nur 2 Hauptgerichte im Bestand → Platz 3 MUSS ein Fremdling werden.
    // Lesart (a) „hart" hätte hier einen Platz leer gelassen; (b) füllt und meldet.
    $this->frames->addSlot($this->rootTeam, $this->frame, ['label' => 'Hauptgang', 'target_count' => 3]);
    $this->frames->setHead($this->rootTeam, $this->frame, ['price_max_pp' => 200.00]);

    $res = $this->assembly->assembliere($this->rootTeam, $this->frame->refresh());
    $namen = collect($res['gerichte'])->pluck('name')->all();

    expect($res['slots'][0]['status'])->toBe('befuellt')
        ->and($namen)->toContain('Rind Roulade')->toContain('Kalb Geschnetzeltes')
        // der dritte Platz ist der DB-stärkste Fremdling, nicht irgendeiner
        ->and($namen)->toContain('Bienenstich')
        ->and($res['slot_semantik']['fremdlinge'])->toBe(1)
        ->and($res['slots'][0]['hg_fremdlinge'])->toBe(1);

    $bruch = $res['slot_semantik']['brueche'][0];
    expect($bruch['name'])->toBe('Bienenstich')
        ->and($bruch['hg_label'])->toBe('dessert')
        ->and($bruch['slot_label'])->toBe('Hauptgang');
});

it('ein Slot-Label ohne auflösbare Hauptgruppe sagt „nicht geprüft" statt „alles Fremdlinge"', function () {
    ($this->vier)();
    // „Station Süß" trifft weder über das 5-Zeichen-Präfix noch über Token-Gleichheit auf
    // „Hauptgericht" oder „Dessert" — die Rolle ist unbekannt, nicht verletzt.
    $this->frames->addSlot($this->rootTeam, $this->frame, ['label' => 'Station Süß', 'target_count' => 2]);
    $this->frames->setHead($this->rootTeam, $this->frame, ['price_max_pp' => 200.00]);

    $res = $this->assembly->assembliere($this->rootTeam, $this->frame->refresh());

    expect($res['slots'][0]['rolle_aufloesbar'])->toBeFalse()
        ->and($res['slots'][0]['hg_fremdlinge'])->toBeNull()
        ->and($res['slot_semantik']['fremdlinge'])->toBe(0)
        ->and($res['slot_semantik']['nicht_aufloesbar'])->toBe(['Station Süß'])
        ->and($res['slot_semantik']['hinweis'])->toContain('nicht geprüft, nicht erfüllt')
        // ohne wirksame Rollen-Ebene entscheidet wieder das DB — Bienenstich + Brownie
        ->and($res['zielfunktion']['db_pp'])->toBe(71.0)
        // und je Gericht steht NULL, nicht false: „nicht prüfbar" ≠ „passt nicht"
        ->and(collect($res['slots'][0]['gerichte'])->pluck('passt_zum_slot')->all())->toBe([null, null]);
});

it('die Erklärung beziffert die Rollen-Treue: bindend mit hand-gerechnetem Delta von 31,00 €', function () {
    ($this->vier)();
    $this->frames->addSlot($this->rootTeam, $this->frame, ['label' => 'Hauptgang', 'target_count' => 2]);
    $this->frames->setHead($this->rootTeam, $this->frame, ['price_max_pp' => 100.00]);

    $res = $this->assembly->erklaere($this->rootTeam, $this->frame->refresh(), 120);
    $zeile = collect($res['erklaerung']['constraints'])->firstWhere('schluessel', 'slot_rollen');

    expect($zeile)->not->toBeNull()
        ->and($zeile['typ'])->toBe('slot_rollen')
        ->and($zeile['ebene'])->toBe('menue')
        ->and($zeile['bindend'])->toBeTrue()
        // 71 − 40 = 31 € p. P.; bei 120 Gästen 3.720 €. Das ist der Preis der Rollen-Treue —
        // eine Aussage, die ein Mensch gegenzeichnen kann, statt „der Solver will es so".
        ->and($zeile['delta_db_pp'])->toBe(31.0)
        ->and($zeile['delta_db_gaeste'])->toBe(3720.0)
        // und die Kehrseite: die Lockerung KAUFT das DB mit zwei Rollen-Brüchen
        ->and($zeile['delta_fremdlinge'])->toBe(2)
        ->and($res['erklaerung']['bindend'])->toContain('slot_rollen')
        // die Basis-Antwort selbst bleibt die rollen-treue (erklaere() verändert nichts)
        ->and($res['zielfunktion']['db_pp'])->toBe(40.0)
        ->and($res['slot_semantik']['ebene_aktiv'])->toBeTrue();
});

it('ohne auflösbare Rolle wird die Ebene nicht als geprüfte Lockerung gezählt, sondern mit Grund benannt', function () {
    ($this->vier)();
    $this->frames->addSlot($this->rootTeam, $this->frame, ['label' => 'Station Süß', 'target_count' => 2]);

    $res = $this->assembly->erklaere($this->rootTeam, $this->frame->refresh());

    expect(collect($res['erklaerung']['constraints'])->pluck('schluessel')->all())
        ->not->toContain('slot_rollen')
        // 12·S3c: die Begründung nennt jetzt BEIDE Wege, die eine Rolle liefern könnten —
        // die Bindung am Slot und die Label-Näherung. Vorher stand dort nur das Label,
        // weil es der einzige Weg war.
        ->and(implode(' | ', $res['erklaerung']['nicht_gelockert']))
        ->toContain('kein Slot ist an eine Speisen-Hauptgruppe gebunden')
        ->and(implode(' | ', $res['erklaerung']['nicht_gelockert']))
        ->toContain('dish_main_group_id');
});

it('ohne Hauptgruppen am Rezept ist die Ebene inert — der Bestandspfad rechnet unverändert', function () {
    // Dieselbe Fixture, aber ohne dish_main_group_id: der Solver muss wieder rein nach DB
    // wählen. Das ist der Riegel dagegen, dass die neue Ebene sich in Gerüste einmischt,
    // deren Gerichte die Hauptgruppe (noch) nicht gepflegt haben.
    ($this->mk)('n1', 'Rind Roulade', 30.00, 5.00, null);
    ($this->mk)('n2', 'Kalb Geschnetzeltes', 20.00, 5.00, null);
    ($this->mk)('n3', 'Bienenstich', 40.00, 2.00, null);
    ($this->mk)('n4', 'Brownie Cubes', 35.00, 2.00, null);
    $this->frames->addSlot($this->rootTeam, $this->frame, ['label' => 'Hauptgang', 'target_count' => 2]);
    $this->frames->setHead($this->rootTeam, $this->frame, ['price_max_pp' => 100.00]);

    $res = $this->assembly->assembliere($this->rootTeam, $this->frame->refresh());

    expect($res['zielfunktion']['db_pp'])->toBe(71.0)
        ->and($res['slots'][0]['rolle_aufloesbar'])->toBeFalse()
        ->and($res['slot_semantik']['fremdlinge'])->toBe(0);
});
