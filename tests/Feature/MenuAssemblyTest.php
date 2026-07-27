<?php

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
 * 12·S2a-2 (R2.4) — der Solver-Motor.
 *
 * Der tragende Test ist der erste: ein Fall, in dem die **hand-gerechnete** Optimallösung
 * NICHT die ist, die man durch „je Slot das beste DB" bekommt — greedy verbaut sich am
 * ersten Slot das Preisbudget. Ohne diesen Zuschnitt wäre ein grüner Test kein Beweis für
 * den B&B-Pfad, sondern nur dafür, dass Greedy und Optimum in einer einfachen Fixture
 * zufällig zusammenfallen (dieselbe Falle wie V-019/V-020: die Fixture bestätigt die
 * Annahme, die sie enthält).
 *
 * Dazu die Zusicherungen, die den Motor gegenzeichenbar machen: Eindeutigkeit übers Menü,
 * leerer Slot mit Begründung statt Abbruch, unerfüllbare Vorgabe rot statt still, und die
 * Ampel im Ergebnis ist DIE Coverage-Ampel (keine zweite Messlatte).
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->assembly = app(MenuAssemblyService::class);
    $this->frames = app(PlanningFrameService::class);

    $this->sf = FoodAlchemistServierform::firstOrCreate(
        ['code' => 'unbestimmt', 'team_id' => $this->rootTeam->id],
        ['label' => 'Unbestimmt']
    );

    // Gericht mit Wirtschafts-Achse: VK/EK sitzen an der Standard-Darreichung (M2-Preis-
    // Wahrheit), DB = VK − EK. Die Legacy-Spalte bleibt bewusst leer.
    $this->mk = function (string $key, string $name, ?float $vk, ?float $ek, array $attr = []): FoodAlchemistRecipe {
        $r = FoodAlchemistRecipe::create(array_merge([
            'team_id' => $this->rootTeam->id, 'recipe_key' => $key, 'name' => $name,
            'status' => 'approved', 'is_sales_recipe' => true,
        ], $attr));
        if ($vk !== null || $ek !== null) {
            FoodAlchemistRecipeDarreichung::create([
                'team_id' => $this->rootTeam->id, 'recipe_id' => $r->id, 'serving_form_id' => $this->sf->id,
                'is_standard' => true, 'sales_net' => $vk, 'ek_portion' => $ek,
            ]);
        }

        return $r;
    };

    $fb = FoodAlchemistFoodbook::create(['team_id' => $this->rootTeam->id, 'label' => 'Solver-FB']);
    $this->frame = $this->frames->frameFor($this->rootTeam, 'foodbook', $fb->id);

    // Zwei getrennte Slots über Namens-No-Gos (jeder Slot schließt die Gerichte des anderen
    // aus) — so sind die Kandidaten-Mengen disjunkt, ohne die Preisbänder zu verbrauchen,
    // die der Test selbst als Constraint braucht.
    $this->zweiSlots = function (): array {
        $a = $this->frames->addSlot($this->rootTeam, $this->frame, ['label' => 'Vorspeisen', 'target_count' => 1]);
        $b = $this->frames->addSlot($this->rootTeam, $this->frame, ['label' => 'Hauptgänge', 'target_count' => 1]);
        $this->frames->addRule($this->rootTeam, $this->frame, ['rule_type' => 'nogo_ingredient', 'slot_id' => $a->id, 'value_text' => 'hauptgang']);
        $this->frames->addRule($this->rootTeam, $this->frame, ['rule_type' => 'nogo_ingredient', 'slot_id' => $b->id, 'value_text' => 'vorspeise']);

        return [$a, $b];
    };
});

it('B&B findet die hand-gerechnete Optimallösung, die Greedy verfehlt (Preisdeckel p. P.)', function () {
    // Hand-Rechnung: Deckel 36 € p. P.
    //   A1+B1 = 45 € → über dem Deckel (unzulässig)
    //   A1+B2 = 35 € → DB 18 + 9  = 27   ← das findet Greedy (nimmt A1 zuerst, dann passt nur B2)
    //   A2+B1 = 35 € → DB  7 + 24 = 31   ← OPTIMUM
    //   A2+B2 = 25 € → DB  7 + 9  = 16
    $a1 = ($this->mk)('a1', 'Vorspeise: Tartar', 20.00, 2.00);   // DB 18
    $a2 = ($this->mk)('a2', 'Vorspeise: Suppe', 10.00, 3.00);    // DB  7
    $b1 = ($this->mk)('b1', 'Hauptgang: Rind', 25.00, 1.00);     // DB 24
    $b2 = ($this->mk)('b2', 'Hauptgang: Fisch', 15.00, 6.00);    // DB  9

    ($this->zweiSlots)();
    $this->frames->setHead($this->rootTeam, $this->frame, ['price_max_pp' => 36.00]);

    $res = $this->assembly->assembliere($this->rootTeam, $this->frame->refresh());

    expect($res['verfahren'])->toBe('exakt')
        ->and($res['exakt'])->toBeTrue()
        ->and($res['deckel_erreicht'])->toBeFalse()
        ->and($res['zielfunktion']['db_gesamt'])->toBe(31.0)
        ->and($res['zielfunktion']['vk_pp'])->toBe(35.0)
        ->and($res['verletzungen'])->toBe(0);

    // Die Auswahl selbst — nicht nur die Summe: A2 + B1, NICHT das je Slot beste DB (A1+B1)
    expect(collect($res['gerichte'])->pluck('id')->all())->toBe([$a2->id, $b1->id]);
    expect(collect($res['gerichte'])->pluck('id')->all())->not->toContain($a1->id);
    expect(collect($res['gerichte'])->pluck('id')->all())->not->toContain($b2->id);
});

it('Menü-weite Diät-Quote schlägt das höhere DB — und die Coverage-Ampel bestätigt sie', function () {
    // min 1× vegan: A1 (DB 18, ohne Diätform) ist besser als A2 (DB 7, vegan), aber nur
    // A2+B1 erfüllt die Quote UND holt das höchste zulässige DB (7 + 24 = 31).
    $a1 = ($this->mk)('a1', 'Vorspeise: Tartar', 20.00, 2.00);
    $a2 = ($this->mk)('a2', 'Vorspeise: Suppe', 10.00, 3.00, ['spec_is_vegan' => true]);
    $b1 = ($this->mk)('b1', 'Hauptgang: Rind', 25.00, 1.00);
    ($this->mk)('b2', 'Hauptgang: Fisch', 15.00, 6.00);

    ($this->zweiSlots)();
    $this->frames->addRule($this->rootTeam, $this->frame, [
        'rule_type' => 'diet_quota', 'ref_key' => 'vegan', 'operator' => 'min', 'value_num' => 1, 'unit' => 'count',
    ]);

    $res = $this->assembly->assembliere($this->rootTeam, $this->frame->refresh());

    expect(collect($res['gerichte'])->pluck('id')->all())->toBe([$a2->id, $b1->id])
        ->and($res['zielfunktion']['db_gesamt'])->toBe(31.0)
        ->and(collect($res['gerichte'])->pluck('id')->all())->not->toContain($a1->id);

    // Suchordnung und Ampel sehen dieselbe Vorgabe als erfüllt an (kein zweiter Maßstab)
    $quote = collect($res['befunde'])->firstWhere('dimension', 'diaet');
    expect($quote['ampel'])->toBe('erfuellt')
        ->and($res['verletzungen'])->toBe(0);
});

it('Prozent-Quote wird erst am Blatt entschieden — Greedy landet daneben, B&B nicht', function () {
    // Absicht: eine `max`-Quote in PROZENT ist am inneren Knoten nicht beurteilbar (der
    // Nenner wächst noch), also greift dort keine Schranke — nur die Blatt-Bewertung
    // entscheidet. Greedy nimmt zweimal vegan (18 + 24 = 42, aber 100 %) und verletzt die
    // Vorgabe; zulässig sind nur die gemischten Paare, das beste ist A2 + B1 = 7 + 24 = 31.
    ($this->mk)('a1', 'Vorspeise: Avocado', 20.00, 2.00, ['spec_is_vegan' => true]);   // DB 18 vegan
    $a2 = ($this->mk)('a2', 'Vorspeise: Tartar', 10.00, 3.00);                          // DB  7
    $b1 = ($this->mk)('b1', 'Hauptgang: Gemüse', 25.00, 1.00, ['spec_is_vegan' => true]); // DB 24 vegan
    ($this->mk)('b2', 'Hauptgang: Rind', 15.00, 6.00);                                  // DB  9

    ($this->zweiSlots)();
    $this->frames->addRule($this->rootTeam, $this->frame, [
        'rule_type' => 'diet_quota', 'ref_key' => 'vegan', 'operator' => 'max', 'value_num' => 50, 'unit' => 'percent',
    ]);

    $res = $this->assembly->assembliere($this->rootTeam, $this->frame->refresh());

    expect($res['zielfunktion']['db_gesamt'])->toBe(31.0)
        ->and($res['verfahren'])->toBe('exakt')
        ->and(collect($res['gerichte'])->pluck('id')->all())->toBe([$a2->id, $b1->id])
        ->and($res['verletzungen'])->toBe(0);

    $quote = collect($res['befunde'])->firstWhere('dimension', 'diaet');
    expect($quote['ist'])->toBe('50 % (1/2)')
        ->and($quote['ampel'])->toBe('erfuellt');
});

it('unerfüllbare Vorgabe liefert trotzdem eine Antwort — rot, nicht still und nicht leer', function () {
    // Kein veganes Gericht im Bestand → die Quote ist nicht erfüllbar. Der Motor liefert
    // die DB-beste Zusammenstellung und die Ampel sagt, was fehlt.
    ($this->mk)('a1', 'Vorspeise: Tartar', 20.00, 2.00);
    ($this->mk)('b1', 'Hauptgang: Rind', 25.00, 1.00);

    ($this->zweiSlots)();
    $this->frames->addRule($this->rootTeam, $this->frame, [
        'rule_type' => 'diet_quota', 'ref_key' => 'vegan', 'operator' => 'min', 'value_num' => 2, 'unit' => 'count',
    ]);

    $res = $this->assembly->assembliere($this->rootTeam, $this->frame->refresh());

    expect($res['gerichte'])->toHaveCount(2)
        ->and($res['zielfunktion']['db_gesamt'])->toBe(42.0)   // 18 + 24, das Maximum
        ->and($res['verletzungen'])->toBe(1)
        ->and($res['ampel_gesamt'])->toBe('verletzt');

    $quote = collect($res['befunde'])->firstWhere('dimension', 'diaet');
    expect($quote['ampel'])->toBe('verletzt');
});

it('ohne Menü-weite Vorgaben und mit disjunkten Kandidaten läuft der slot-unabhängige Pfad', function () {
    $a1 = ($this->mk)('a1', 'Vorspeise: Tartar', 20.00, 2.00);   // DB 18
    ($this->mk)('a2', 'Vorspeise: Suppe', 10.00, 3.00);          // DB  7
    $b1 = ($this->mk)('b1', 'Hauptgang: Rind', 25.00, 1.00);     // DB 24
    ($this->mk)('b2', 'Hauptgang: Fisch', 15.00, 6.00);          // DB  9

    ($this->zweiSlots)();

    $res = $this->assembly->assembliere($this->rootTeam, $this->frame->refresh());

    expect($res['verfahren'])->toBe('slot_unabhaengig')
        ->and($res['exakt'])->toBeTrue()
        ->and($res['knoten'])->toBe(0)
        ->and(collect($res['gerichte'])->pluck('id')->all())->toBe([$a1->id, $b1->id])
        ->and($res['zielfunktion']['db_gesamt'])->toBe(42.0);
});

it('kein Gericht zweimal — auch wenn beide Slots aus derselben Menge wählen', function () {
    $hoch = ($this->mk)('hoch', 'Gericht: Hoch', 30.00, 2.00);   // DB 28
    $mittel = ($this->mk)('mittel', 'Gericht: Mittel', 20.00, 5.00); // DB 15

    $this->frames->addSlot($this->rootTeam, $this->frame, ['label' => 'Erster', 'target_count' => 1]);
    $this->frames->addSlot($this->rootTeam, $this->frame, ['label' => 'Zweiter', 'target_count' => 1]);
    // Menü-weite Vorgabe erzwingt den Such-Pfad (der slot-unabhängige greift bei
    // überlappenden Mengen ohnehin nicht)
    $this->frames->setHead($this->rootTeam, $this->frame, ['price_max_pp' => 100.00]);

    $res = $this->assembly->assembliere($this->rootTeam, $this->frame->refresh());

    $ids = collect($res['gerichte'])->pluck('id')->all();
    expect($ids)->toHaveCount(2)
        ->and(array_unique($ids))->toHaveCount(2)
        ->and(sort($ids) ? $ids : $ids)->toContain($hoch->id, $mittel->id);
});

it('Slot ohne zulässigen Treffer bleibt leer und sagt warum — statt Abbruch', function () {
    ($this->mk)('a1', 'Vorspeise: Tartar', 20.00, 2.00);
    // kein Hauptgang im Bestand → der zweite Slot ist unbefüllbar
    ($this->zweiSlots)();

    $res = $this->assembly->assembliere($this->rootTeam, $this->frame->refresh());

    $leer = collect($res['slots'])->firstWhere('label', 'Hauptgänge');
    expect($leer['status'])->toBe('leer')
        ->and($leer['gerichte'])->toBe([])
        ->and($leer['kandidaten_zulaessig'])->toBe(0)
        ->and($leer['begruendung'])->toContain('No-Go: vorspeise');

    // Der leere Slot ist in der Messung ein Mengen-Befund („0 Gerichte"), NICHT
    // „kein Ist-Bezug" — die Position existiert, sie ist nur unbefüllbar.
    $menge = collect($res['befunde'])->where('dimension', 'menge')
        ->firstWhere('label', 'Slot „Hauptgänge“');
    expect($menge['ist'])->toBe('0 Gerichte')
        ->and($menge['ampel'])->toBe('verletzt');
});

it('Gericht ohne belastbare Wirtschafts-Achse wird benannt, nicht weggelassen', function () {
    $ohne = ($this->mk)('ohne', 'Vorspeise: Ohne Preis', null, null);   // keine Darreichung
    ($this->zweiSlots)();

    $res = $this->assembly->assembliere($this->rootTeam, $this->frame->refresh());

    expect(collect($res['gerichte'])->pluck('id')->all())->toContain($ohne->id)
        ->and(collect($res['gerichte'])->firstWhere('id', $ohne->id)['db_eur'])->toBeNull()
        ->and(collect($res['unvollstaendig'])->pluck('id')->all())->toBe([$ohne->id])
        ->and($res['zielfunktion']['db_gesamt'])->toBe(0.0);
});

it('Gästezahl skaliert nur die Ausgabe, nicht die Auswahl', function () {
    ($this->mk)('a1', 'Vorspeise: Tartar', 20.00, 2.00);
    ($this->mk)('b1', 'Hauptgang: Rind', 25.00, 1.00);
    ($this->zweiSlots)();

    $ohne = $this->assembly->assembliere($this->rootTeam, $this->frame->refresh());
    $mit = $this->assembly->assembliere($this->rootTeam, $this->frame->refresh(), 50);

    expect($mit['gerichte'])->toBe($ohne['gerichte'])
        ->and($ohne['db_gesamt_gaeste'])->toBeNull()
        ->and($mit['gaeste'])->toBe(50)
        ->and($mit['db_gesamt_gaeste'])->toBe(2100.0);   // 42 € × 50
});

it('zu großer Suchraum fällt ehrlich auf die Heuristik zurück — exakt=false, ohne Knoten zu verbrennen', function () {
    // 10 von 21 Kandidaten = C(21,10) = 352.716 Kombinationen, also über EXAKT_RAUM_MAX.
    // Erwartung: der Motor sucht GAR NICHT (knoten=0) statt den Deckel vollzulaufen.
    for ($i = 1; $i <= 21; $i++) {
        ($this->mk)('h' . $i, 'Gericht: Nr ' . str_pad((string) $i, 2, '0', STR_PAD_LEFT), 10.00 + $i, 4.00);
    }
    $this->frames->addSlot($this->rootTeam, $this->frame, ['label' => 'Buffet', 'target_count' => 10]);
    $this->frames->setHead($this->rootTeam, $this->frame, ['price_max_pp' => 1000.00]);

    $res = $this->assembly->assembliere($this->rootTeam, $this->frame->refresh());

    expect($res['verfahren'])->toBe('heuristik')
        ->and($res['exakt'])->toBeFalse()
        ->and($res['knoten'])->toBe(0)
        ->and($res['deckel_erreicht'])->toBeFalse()
        ->and($res['suchraum'])->toBeNull()   // über der Grenze gekappt
        ->and($res['gerichte'])->toHaveCount(10)
        // Die 10 besten von 21 (DB 27 … 18) = 225 € — hier fällt Greedy mit dem Optimum
        // zusammen, weil kein Constraint bindet; die Zusicherung ist die Pfad-Wahl.
        ->and($res['zielfunktion']['db_gesamt'])->toBe(225.0);
});

it('Suchraum-Schätzung entscheidet den Pfad — dieselbe Kandidatenzahl, ein Platz weniger', function () {
    // Gegenstück zum Test darüber: C(21,3) = 1.330 liegt unter der Grenze → exakter Pfad.
    // Damit ist belegt, dass die Grenze am RAUM hängt und nicht an der Kandidatenzahl.
    for ($i = 1; $i <= 21; $i++) {
        ($this->mk)('h' . $i, 'Gericht: Nr ' . str_pad((string) $i, 2, '0', STR_PAD_LEFT), 10.00 + $i, 4.00);
    }
    $this->frames->addSlot($this->rootTeam, $this->frame, ['label' => 'Buffet', 'target_count' => 3]);
    $this->frames->setHead($this->rootTeam, $this->frame, ['price_max_pp' => 1000.00]);

    $res = $this->assembly->assembliere($this->rootTeam, $this->frame->refresh());

    expect($res['verfahren'])->toBe('exakt')
        ->and($res['suchraum'])->toBe(1330)
        ->and($res['knoten'])->toBeGreaterThan(0)
        ->and($res['zielfunktion']['db_gesamt'])->toBe(78.0);   // 27 + 26 + 25
});

it('Gerüst ohne Slots ist ein Fehler, keine leere Antwort', function () {
    expect(fn () => $this->assembly->assembliere($this->rootTeam, $this->frame->refresh()))
        ->toThrow(RuntimeException::class, 'ohne Slots');
});
