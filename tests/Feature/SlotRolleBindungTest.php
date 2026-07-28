<?php

use Illuminate\Support\Collection;
use Platform\FoodAlchemist\Models\FoodAlchemistDishMainGroup;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeDarreichung;
use Platform\FoodAlchemist\Models\FoodAlchemistServierform;
use Platform\FoodAlchemist\Services\MenuAssemblyService;
use Platform\FoodAlchemist\Services\MenuCandidatePoolService;
use Platform\FoodAlchemist\Services\PlanningFrameService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * 12·S3c-1 — die Rolle eines Slots als Fremdschlüssel statt als String-Vergleich.
 *
 * **Warum diese Etappe überhaupt kommt** (gemessen in Lauf 60, → D-019): die Label-Näherung
 * aus S3a/S3b trägt für Kanon-Bezeichnungen, aber am Master lösen 5 von 6 distinkten
 * Slot-Labels auf **keine** Hauptgruppe auf, weil der Slot-Titel dort die Verkaufszeile ist
 * („Main – Hyper Local · Geschmack aus der Region, neu gedacht"). Gegen freie Prosa hilft
 * weder eine Alias-Liste noch ein LLM — nur eine hingeschriebene Entscheidung. Genau die
 * ist `foodalchemist_planning_frame_slots.dish_main_group_id`.
 *
 * Der tragende Test ist der zweite: er nimmt **das echte Master-Label** und zeigt beide
 * Zustände am gleichen Slot — ohne Bindung `unbekannt` (die Ebene schweigt), mit Bindung
 * `gebunden` (sie greift). Der erste Test riegelt die Rangfolge in der Gegenrichtung: eine
 * Bindung, die dem Label **widerspricht**, gewinnt. Wäre es umgekehrt, wäre die Spalte
 * dekorativ und die Fehlerklasse „Vorspeise vs Vorspeisen" nur verschoben, nicht weg.
 *
 * Bestandsverhalten: `NULL` ist der Normalfall jedes Alt-Slots (kein Backfill), und der
 * Label-Pfad bleibt byte-identisch — dafür steht `SlotSemantikGoldenTest` aus S3a, der
 * unverändert grün bleiben MUSS.
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
});

// ── Die Naht: zwei Quellen, eine Rangfolge ────────────────────────────────

it('lässt die Bindung am Slot gegen das Label gewinnen — nicht umgekehrt', function () {
    // Der Slot heißt „Hauptgang", ist aber an DESSERT gebunden. Die Label-Näherung würde
    // das Hauptgericht wählen; die Bindung sagt Dessert. Wer gewinnt, entscheidet, ob die
    // Spalte trägt oder Dekoration ist.
    $slot = (object) ['label' => 'Hauptgang', 'dish_main_group_id' => $this->hgDessert->id];
    $kandidaten = new Collection([
        1 => ['id' => 1, 'hg_label' => 'hauptgericht', 'hg_id' => $this->hgHaupt->id],
        2 => ['id' => 2, 'hg_label' => 'dessert', 'hg_id' => $this->hgDessert->id],
        3 => ['id' => 3, 'hg_label' => '', 'hg_id' => null],
    ]);

    expect(MenuCandidatePoolService::semantikJeKandidat($kandidaten, $slot))
        ->toBe([1 => 0, 2 => 1, 3 => 0]);

    // Gegenprobe ohne Bindung: derselbe Slot, dieselben Kandidaten — Label-Pfad, andere Antwort.
    $ohne = (object) ['label' => 'Hauptgang', 'dish_main_group_id' => null];
    expect(MenuCandidatePoolService::semantikJeKandidat($kandidaten, $ohne))
        ->toBe([1 => 1, 2 => 0, 3 => 0]);
});

it('macht ein Prosa-Slot-Label auflösbar, das die Näherung nicht greifen kann (der D-019-Fall)', function () {
    // Wörtlich das Label aus dem Master-Bestand. Es enthält kein Token, das mit einer
    // Hauptgruppe fünf Zeichen teilt — die Näherung ist dort strukturell blind.
    $prosa = 'Main – Hyper Local · Geschmack aus der Region, neu gedacht';
    $kandidaten = new Collection([
        1 => ['id' => 1, 'hg_label' => 'hauptgericht', 'hg_id' => $this->hgHaupt->id],
        2 => ['id' => 2, 'hg_label' => 'dessert', 'hg_id' => $this->hgDessert->id],
    ]);

    $ohne = MenuCandidatePoolService::rolleFuerSlot($kandidaten, (object) ['label' => $prosa, 'dish_main_group_id' => null]);
    expect($ohne['quelle'])->toBe('unbekannt')
        ->and($ohne['aufloesbar'])->toBeFalse()
        ->and(MenuCandidatePoolService::semantikJeKandidat($kandidaten, (object) ['label' => $prosa, 'dish_main_group_id' => null]))
        ->toBe([1 => 0, 2 => 0]);

    $mit = MenuCandidatePoolService::rolleFuerSlot($kandidaten, (object) ['label' => $prosa, 'dish_main_group_id' => $this->hgHaupt->id]);
    expect($mit['quelle'])->toBe('gebunden')
        ->and($mit['aufloesbar'])->toBeTrue()
        ->and($mit['main_group_id'])->toBe($this->hgHaupt->id)
        ->and(MenuCandidatePoolService::semantikJeKandidat($kandidaten, (object) ['label' => $prosa, 'dish_main_group_id' => $this->hgHaupt->id]))
        ->toBe([1 => 1, 2 => 0]);
});

it('unterscheidet die drei Rollen-Zustände — und „gebunden" gilt auch gegen einen leeren Pool', function () {
    $leer = new Collection();
    $mitTreffer = new Collection([1 => ['id' => 1, 'hg_label' => 'hauptgericht', 'hg_id' => $this->hgHaupt->id]]);

    // gebunden: die Entscheidung steht am Slot, unabhängig davon, was im Bestand liegt.
    // Genau hier lag der Unterschied zur Vor-S3c-Lesart (`in_array(1, $semantik)`), die
    // „keine Kandidaten" und „kein Label-Treffer" zu einer Aussage verschmolz.
    expect(MenuCandidatePoolService::rolleFuerSlot($leer, (object) ['label' => 'Egal', 'dish_main_group_id' => $this->hgHaupt->id]))
        ->toBe(['quelle' => 'gebunden', 'aufloesbar' => true, 'main_group_id' => $this->hgHaupt->id]);

    expect(MenuCandidatePoolService::rolleFuerSlot($mitTreffer, (object) ['label' => 'Hauptgang', 'dish_main_group_id' => null]))
        ->toBe(['quelle' => 'label', 'aufloesbar' => true, 'main_group_id' => null]);

    expect(MenuCandidatePoolService::rolleFuerSlot($mitTreffer, (object) ['label' => 'Station Süß', 'dish_main_group_id' => null]))
        ->toBe(['quelle' => 'unbekannt', 'aufloesbar' => false, 'main_group_id' => null]);
});

// ── Der Solver: die Bindung verschiebt die Auswahl, sichtbar ──────────────

it('wählt rollen-treu nach der Bindung, obwohl das DB das Gegenteil verlangt', function () {
    // DB: Dessert 38/33, Hauptgericht 25/15. Ohne Rollen-Ebene gewinnt Dessert.
    ($this->mk)('h1', 'Rind Roulade', 30.00, 5.00, $this->hgHaupt);        // DB 25
    ($this->mk)('h2', 'Kalb Geschnetzeltes', 20.00, 5.00, $this->hgHaupt); // DB 15
    ($this->mk)('d1', 'Bienenstich', 40.00, 2.00, $this->hgDessert);       // DB 38
    ($this->mk)('d2', 'Brownie-Cubes', 35.00, 2.00, $this->hgDessert);     // DB 33

    $konzept = $this->makeConcept($this->rootTeam, 'Bindungs-Menü');
    $frame = $this->frames->frameFor($this->rootTeam, 'concept', $konzept->id);
    // Das Label ist PROSA — ohne Bindung wäre die Ebene hier blind und der Solver nähme
    // die beiden Desserts. Die Bindung ist also die einzige Ursache der Verschiebung.
    $this->frames->addSlot($this->rootTeam, $frame, [
        'label' => 'Zweiter Akt · was aus der Region kommt', 'slot_type' => 'gang',
        'target_count' => 2, 'dish_main_group_id' => $this->hgHaupt->id,
    ]);

    $res = $this->assembly->assembliere($this->rootTeam, $frame->refresh());

    expect(array_column($res['gerichte'], 'name'))->toEqualCanonicalizing(['Rind Roulade', 'Kalb Geschnetzeltes'])
        ->and($res['zielfunktion']['db_pp'])->toBe(40.0)   // rollen-treu, nicht 71 €
        ->and($res['slot_semantik']['fremdlinge'])->toBe(0)
        ->and($res['slot_semantik']['quellen'])->toBe(['gebunden' => 1, 'label' => 0, 'unbekannt' => 0])
        ->and($res['slots'][0]['rolle_quelle'])->toBe('gebunden')
        ->and($res['slots'][0]['dish_main_group_id'])->toBe($this->hgHaupt->id)
        ->and($res['slots'][0]['hg_fremdlinge'])->toBe(0);
});

it('meldet einen gebundenen Slot ohne passendes Gericht als Portfolio-Lücke, nicht als „nicht prüfbar"', function () {
    // Der Bestand hält NUR Desserts, der Slot ist an Hauptgericht gebunden. Vor S3c hätte
    // derselbe Fall `rolle_aufloesbar=false` geliefert (kein Treffer ⇒ „Label unbekannt")
    // und die Fremdlinge verschwiegen. Die Rolle steht aber fest — das Portfolio hält sie nicht.
    ($this->mk)('d1', 'Bienenstich', 40.00, 2.00, $this->hgDessert);
    ($this->mk)('d2', 'Brownie-Cubes', 35.00, 2.00, $this->hgDessert);

    $konzept = $this->makeConcept($this->rootTeam, 'Lücken-Menü');
    $frame = $this->frames->frameFor($this->rootTeam, 'concept', $konzept->id);
    $this->frames->addSlot($this->rootTeam, $frame, [
        'label' => 'Hauptgang', 'slot_type' => 'gang', 'target_count' => 2,
        'dish_main_group_id' => $this->hgHaupt->id,
    ]);

    $res = $this->assembly->assembliere($this->rootTeam, $frame->refresh());

    expect($res['slots'][0]['rolle_aufloesbar'])->toBeTrue()
        ->and($res['slots'][0]['rolle_quelle'])->toBe('gebunden')
        ->and($res['slots'][0]['hg_fremdlinge'])->toBe(2)     // beide Plätze rollen-fremd
        ->and($res['slot_semantik']['fremdlinge'])->toBe(2)
        ->and($res['slot_semantik']['nicht_aufloesbar'])->toBe([])
        ->and(array_column($res['slot_semantik']['brueche'], 'name'))
        ->toEqualCanonicalizing(['Bienenstich', 'Brownie-Cubes'])
        // Der Hinweis darf hier NICHT von einer Näherung reden — es gibt keine.
        ->and($res['slot_semantik']['hinweis'])->toBeNull();
});

it('benennt Slots, die nur über die Label-Näherung tragen, im Hinweis', function () {
    ($this->mk)('h1', 'Rind Roulade', 30.00, 5.00, $this->hgHaupt);

    $konzept = $this->makeConcept($this->rootTeam, 'Näherungs-Menü');
    $frame = $this->frames->frameFor($this->rootTeam, 'concept', $konzept->id);
    $this->frames->addSlot($this->rootTeam, $frame, ['label' => 'Hauptgang', 'slot_type' => 'gang', 'target_count' => 1]);

    $res = $this->assembly->assembliere($this->rootTeam, $frame->refresh());

    expect($res['slots'][0]['rolle_quelle'])->toBe('label')
        ->and($res['slots'][0]['dish_main_group_id'])->toBeNull()
        ->and($res['slot_semantik']['quellen'])->toBe(['gebunden' => 0, 'label' => 1, 'unbekannt' => 0])
        ->and($res['slot_semantik']['hinweis'])->toContain('Label-Näherung')
        ->and($res['slot_semantik']['hinweis'])->toContain('dish_main_group_id');
});

// ── Der Schreibweg: prüfen, erhalten, mitnehmen ───────────────────────────

it('lehnt eine unbekannte oder fremde Hauptgruppe ab, statt sie zu setzen', function () {
    $konzept = $this->makeConcept($this->rootTeam, 'Guard-Menü');
    $frame = $this->frames->frameFor($this->rootTeam, 'concept', $konzept->id);

    expect(fn () => $this->frames->addSlot($this->rootTeam, $frame, [
        'label' => 'Hauptgang', 'dish_main_group_id' => 999999,
    ]))->toThrow(RuntimeException::class, 'nicht sichtbar');

    // D1: eine Hauptgruppe des Geschwister-Teams ist NICHT sichtbar — eine Fremd-ID darf
    // über die Slot-Rolle keine Existenz verraten.
    $fremd = FoodAlchemistDishMainGroup::create([
        'team_id' => $this->childB->id, 'code' => 'FRM', 'label' => 'Fremd',
    ]);
    $konzeptA = $this->makeConcept($this->childA, 'Kind-A-Menü');
    $frameA = $this->frames->frameFor($this->childA, 'concept', $konzeptA->id);
    expect(fn () => $this->frames->addSlot($this->childA, $frameA, [
        'label' => 'Hauptgang', 'dish_main_group_id' => $fremd->id,
    ]))->toThrow(RuntimeException::class);

    // Die Hauptgruppe des Root-Teams ist für das Kind sichtbar (Ancestry) ⇒ erlaubt.
    $ok = $this->frames->addSlot($this->childA, $frameA, [
        'label' => 'Hauptgang', 'dish_main_group_id' => $this->hgHaupt->id,
    ]);
    expect($ok->dish_main_group_id)->toBe($this->hgHaupt->id);

    // Und die Bindung lösen bleibt erlaubt — sie ist eine Entscheidung, keine Einbahnstraße.
    expect($this->frames->updateSlot($this->childA, $ok->id, ['dish_main_group_id' => null])->dish_main_group_id)
        ->toBeNull();
});

it('erhält die Slot-Rolle über einen Gerüst-Rerun per Label-Match — der Payload gewinnt', function () {
    $konzept = $this->makeConcept($this->rootTeam, 'Rerun-Menü');
    $frame = $this->frames->frameFor($this->rootTeam, 'concept', $konzept->id);
    $this->frames->addSlot($this->rootTeam, $frame, ['label' => 'Hauptgang', 'dish_main_group_id' => $this->hgHaupt->id]);
    $this->frames->addSlot($this->rootTeam, $frame, ['label' => 'Süßes', 'dish_main_group_id' => $this->hgDessert->id]);

    // Ein Generator-Payload, der die Spalte nicht kennt (Slot 1) und einer, der sie
    // überschreibt (Slot 2) — plus ein neuer Slot ohne Vorgeschichte.
    $this->frames->replaceStructure($this->rootTeam, $frame->refresh(), [
        ['label' => 'hauptgang'],                                            // Label-Match case-insensitiv
        ['label' => 'Süßes', 'dish_main_group_id' => $this->hgHaupt->id],    // Payload gewinnt
        ['label' => 'Zwischengang'],                                         // neu, keine Rolle
    ]);

    $slots = $frame->refresh()->slots->sortBy('position')->values();
    expect($slots[0]->dish_main_group_id)->toBe($this->hgHaupt->id)
        ->and($slots[1]->dish_main_group_id)->toBe($this->hgHaupt->id)
        ->and($slots[2]->dish_main_group_id)->toBeNull();
});

it('nimmt die Slot-Rolle in die Gerüst-Kopie mit (anders als den Kapitel-Bezug) und weist sie in summary aus', function () {
    $foodbook = $this->makeFoodbook($this->rootTeam, 'Quell-Foodbook');
    $quelle = $this->frames->frameFor($this->rootTeam, 'foodbook', $foodbook->id);
    $kapitel = $this->makeChapter($foodbook, ['title' => 'Hauptgang']);
    $this->frames->addSlot($this->rootTeam, $quelle, [
        'label' => 'Hauptgang', 'dish_main_group_id' => $this->hgHaupt->id, 'chapter_id' => $kapitel->id,
    ]);

    $konzept = $this->makeConcept($this->rootTeam, 'Ziel-Konzept');
    $ziel = $this->frames->kopiereZu($this->rootTeam, $quelle->refresh(), 'concept', $konzept->id);

    $kopie = $ziel->refresh()->slots->first();
    expect($kopie->dish_main_group_id)->toBe($this->hgHaupt->id)
        ->and($kopie->chapter_id)->toBeNull();   // Ist-Bezug bleibt beim Quell-Owner

    $summary = $this->frames->summary($ziel->refresh());
    expect($summary['slots'][0]['dish_main_group_id'])->toBe($this->hgHaupt->id)
        ->and($summary['slots'][0]['dish_main_group_label'])->toBe('Hauptgericht');
});
