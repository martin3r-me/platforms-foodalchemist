<?php

use Illuminate\Support\Collection;
use Platform\FoodAlchemist\Models\FoodAlchemistDishMainGroup;
use Platform\FoodAlchemist\Services\ConceptGeneratorService;
use Platform\FoodAlchemist\Services\MenuCandidatePoolService;
use Platform\FoodAlchemist\Services\PlanningFrameService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * 12·S3a — Golden-Riegel für die Slot-Semantik VOR der Lesart-Entscheidung.
 *
 * Warum dieser Test existiert: Etappe 12·S3 muss entscheiden, ob die Hauptgruppe
 * den Slot **hart** bindet (a), **lexikografisch** (b) oder nur das Ranking führt (c).
 * Jede dieser Lesarten greift in eine bestehende Auswahl-Regel ein — und die
 * Fehlerklasse solcher Umbauten ist die stille Verschiebung, nicht der Crash.
 * Darum wird der Ist-Zustand hier zuerst eingefroren:
 *
 *   1. die Semantik-Funktion selbst über eine Tabelle mehrdeutiger Fälle
 *      (Präfix-Pfad · Token-Gleichheits-Pfad · beide Nicht-Treffer-Ränder),
 *   2. die Zusicherung „eine Wahrheit": der delegierende Zugang am Generator
 *      liefert für JEDEN Tabellenfall dasselbe wie die Naht im Pool,
 *   3. die Wirkung im Ranking end-to-end über `slotKandidaten` — mit Treffer
 *      (Semantik ordnet) und ohne Treffer (Semantik neutral, Reihenfolge fällt
 *      auf die nächsten Kriterien durch).
 *
 * Die Tabelle friert bewusst auch zwei **Unschönheiten** ein, statt sie in einem
 * Naht-Umbau zu heilen: die Groß-/Kleinschreibungs-Asymmetrie (nur die Slot-Seite
 * wird normalisiert → V-065) und die rein literale 5-Zeichen-Präfix-Regel, die
 * „süßes"/„süßspeise" NICHT verbindet. Wer sie ändert, soll es an diesem Test
 * merken — nicht am Menü.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam));
    $this->svc = app(ConceptGeneratorService::class);
    $this->frames = app(PlanningFrameService::class);
});

/**
 * Eingefrorene Wahrheitstabelle: [Slot-Label, hg_label (kleingeschrieben!), erwartet].
 *
 * @return list<array{0: string, 1: string, 2: int}>
 */
function slotSemantikGolden(): array
{
    return [
        // Präfix-Pfad (≥5 gemeinsame Zeichen) — der Normalfall, für den die Regel gebaut ist
        ['Hauptgang', 'hauptgericht', 1],
        ['Vorspeise', 'vorspeise', 1],
        ['Salate & Vorspeisen', 'vorspeise', 1],
        ['Suppe', 'suppen', 1],
        ['Fisch', 'fisch', 1],
        ['Dessert', 'dessert', 1],
        // Token-Gleichheits-Pfad (3–4 Zeichen, Präfix-Regel greift dort nicht)
        ['Dip Station', 'dip', 1],
        // Nicht-Treffer: freies Label ⇒ neutral, nicht falsch-positiv
        ['Buffet-Station Süß', 'hauptgericht', 0],
        ['Gang 1', 'hauptgericht', 0],
        ['Tapas-Bar', 'fingerfood', 0],
        // Die 5 ist die scharfe Grenze, nicht „ungefähr vier": „brat" ist gemeinsam,
        // das fünfte Zeichen trennt — eine Braten-Station bindet keine Bratwurst.
        ['Bratenstation', 'bratwurst', 0],
        // Rand 1: leeres hg_label (Gericht ohne Hauptgruppe) ⇒ immer 0
        ['Hauptgang', '', 0],
        // Rand 2: Gleichheit unter 3 Zeichen zählt nicht (sonst würde „zu" alles binden)
        ['Zu Tisch', 'zu', 0],
        // Eingefrorene Unschönheit A (→ V-065): nur die SLOT-Seite wird kleingeschrieben.
        // Derselbe Fall wie Zeile 1, nur mit rohem Model-Label ⇒ still 0.
        ['Hauptgang', 'Hauptgericht', 0],
        // Eingefrorene Unschönheit B: die Präfix-Regel ist literal, nicht stamm-bewusst.
        ['Süßes', 'süßspeise', 0],
    ];
}

it('friert die Slot-Semantik über die Tabelle mehrdeutiger Fälle ein', function () {
    foreach (slotSemantikGolden() as [$slot, $hg, $erwartet]) {
        expect(MenuCandidatePoolService::slotSemantik($slot, $hg))
            ->toBe($erwartet, "Slot '{$slot}' x HG '{$hg}'");
    }
});

it('hält die Zusicherung „eine Semantik-Wahrheit": Generator-Zugang == Pool-Naht', function () {
    foreach (slotSemantikGolden() as [$slot, $hg, $erwartet]) {
        // Der Generator-Zugang bleibt bestehen (Aufrufer + Bestands-Tests), delegiert aber.
        expect(ConceptGeneratorService::slotSemantik($slot, $hg))
            ->toBe(MenuCandidatePoolService::slotSemantik($slot, $hg), "Drift bei '{$slot}' x '{$hg}'")
            ->toBe($erwartet);
    }
});

it('bildet die Semantik je Kandidat auf die Rezept-ID ab (die Sicht, die Konsumenten brauchen)', function () {
    $slot = (object) ['label' => 'Hauptgang'];
    $kandidaten = new Collection([
        7 => ['id' => 7, 'hg_label' => 'hauptgericht'],
        9 => ['id' => 9, 'hg_label' => 'vorspeise'],
        // hg_label fehlt (Gericht ohne Hauptgruppe) ⇒ neutral statt Fehler
        11 => ['id' => 11],
    ]);

    expect(MenuCandidatePoolService::semantikJeKandidat($kandidaten, $slot))
        ->toBe([7 => 1, 9 => 0, 11 => 0]);
});

it('ordnet im Ranking den Hauptgruppen-Treffer nach vorn (Semantik greift)', function () {
    $hgHaupt = FoodAlchemistDishMainGroup::create(['team_id' => $this->rootTeam->id, 'code' => 'HG', 'label' => 'Hauptgericht']);
    $hgVor = FoodAlchemistDishMainGroup::create(['team_id' => $this->rootTeam->id, 'code' => 'VS', 'label' => 'Vorspeise']);

    $gericht = fn (string $name, $hg, float $vk) => $this->makeRecipe($this->rootTeam, $name, [
        'is_sales_recipe' => true, 'sales_net' => $vk, 'dish_main_group_id' => $hg->id,
    ]);

    // Name bewusst so, dass der Alphabet-Tiebreak den HG-Fremdling zuerst nähme:
    // ohne Semantik gewinnt „Apfelstrudel", mit Semantik „Zwiebelrostbraten".
    $fremd = $gericht('Apfelstrudel', $hgVor, 8.00);
    $treffer = $gericht('Zwiebelrostbraten', $hgHaupt, 24.00);

    $konzept = $this->makeConcept($this->rootTeam, 'Semantik-Menü');
    $frame = $this->frames->frameFor($this->rootTeam, 'concept', $konzept->id);
    $frameSlot = $this->frames->addSlot($this->rootTeam, $frame, ['label' => 'Hauptgang', 'slot_type' => 'gang', 'target_count' => 1]);

    $res = $this->svc->slotKandidaten($this->rootTeam, $frame, $frameSlot);
    $ids = array_column($res['kandidaten'], 'id');

    expect($ids)->toBe([$treffer->id, $fremd->id])
        ->and($res['kandidaten'][0]['faktoren']['semantik'])->toBe(1)
        ->and($res['kandidaten'][1]['faktoren']['semantik'])->toBe(0);
});

it('bleibt bei einem freien Slot-Label neutral und lässt die nächsten Kriterien entscheiden', function () {
    $hgHaupt = FoodAlchemistDishMainGroup::create(['team_id' => $this->rootTeam->id, 'code' => 'HG', 'label' => 'Hauptgericht']);
    $hgVor = FoodAlchemistDishMainGroup::create(['team_id' => $this->rootTeam->id, 'code' => 'VS', 'label' => 'Vorspeise']);

    $gericht = fn (string $name, $hg, float $vk) => $this->makeRecipe($this->rootTeam, $name, [
        'is_sales_recipe' => true, 'sales_net' => $vk, 'dish_main_group_id' => $hg->id,
    ]);

    $gericht('Zwiebelrostbraten', $hgHaupt, 24.00);
    $gericht('Apfelstrudel', $hgVor, 8.00);

    $konzept = $this->makeConcept($this->rootTeam, 'Stations-Menü');
    $frame = $this->frames->frameFor($this->rootTeam, 'concept', $konzept->id);
    // „Buffet-Station Süß" trifft KEINE der beiden Hauptgruppen ⇒ Semantik überall 0.
    $frameSlot = $this->frames->addSlot($this->rootTeam, $frame, ['label' => 'Buffet-Station Süß', 'slot_type' => 'station', 'target_count' => 2]);

    $res = $this->svc->slotKandidaten($this->rootTeam, $frame, $frameSlot);

    foreach ($res['kandidaten'] as $k) {
        expect($k['faktoren']['semantik'])->toBe(0, "Semantik bei '{$k['name']}'");
    }
    // Alle Semantik-Werte gleich ⇒ es entscheidet der stabile Namens-Tiebreak, nicht die Rolle.
    expect(array_column($res['kandidaten'], 'name'))->toBe(['Apfelstrudel', 'Zwiebelrostbraten']);
});
