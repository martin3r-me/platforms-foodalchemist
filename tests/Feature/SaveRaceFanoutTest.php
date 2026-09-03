<?php

use Livewire\Livewire;
use Platform\FoodAlchemist\Livewire\Planung\Index as PlanungIndex;
use Platform\FoodAlchemist\Livewire\Recipes\IngredientEditor;
use Platform\FoodAlchemist\Models\FoodAlchemistCascadeRun;
use Platform\FoodAlchemist\Models\FoodAlchemistCascadeRunStep;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeIngredient;
use Platform\FoodAlchemist\Services\PlanningSessionService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Etappe 8 (verify): Save-Race Multi-Editor-Fan-out.
 *
 * Der Kaskaden-Fall (`planung/partials/step-zeile.blade.php`) mountet je offenem Draft EINEN
 * eingebetteten {@see IngredientEditor} — mehrere gleichzeitig, wenn der Nutzer mehrere Stufen
 * zum Prüfen aufklappt (`$zutatenOffen` = Liste). Zu prüfen war, ob der MVP-046-Fix auch dann
 * hält, wenn die Editoren PARALLEL montiert sind — sonst überschreiben sich Draft-Speicherungen.
 *
 * MVP-046 ({@see ZutatenSaveVertragTest}) pinnte die Server-Grenze an EINZELN getesteten
 * Instanzen. Dieser Test schließt die Fan-out-Lücke: (1) zwei GLEICHZEITIG lebende Instanzen
 * bleiben in ihrem Ziel-Rezept isoliert, (2) ein an ein fremdes Rezept adressierter Save wird
 * auch bei parallel montierten Editoren abgewiesen, (3) das Cockpit mountet je offenem Draft
 * eine eigene Editor-Instanz, (4) Blade-Vertrag: der Editor-Key ist EINDEUTIG je Step
 * (enthält die Step-ID, nicht nur die Rezept-ID) — sonst kollabierte Livewires Morph zwei
 * Stufen, die dasselbe Sub-Rezept teilen, zu einer Instanz.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam, 'Root User'));

    $this->rezeptA = $this->makeRecipe($this->rootTeam, 'Draft A', ['status' => 'draft']);
    $this->rezeptB = $this->makeRecipe($this->rootTeam, 'Draft B', ['status' => 'draft']);
    $this->makeIngredient($this->rezeptA, 'Zutat von A', null, '100', 1);
    $this->makeIngredient($this->rezeptB, 'Zutat von B', null, '200', 1);

    $this->zutatenVon = fn (int $recipeId) => FoodAlchemistRecipeIngredient::where('recipe_id', $recipeId)
        ->whereNull('deleted_at')->orderBy('position')->pluck('raw_text')->all();

    $einheitId = $this->unitG($this->rootTeam)->id;
    $this->zeile = fn (string $text, string $menge) => [
        'raw_text' => $text, 'quantity' => $menge, 'unit_vocab_id' => $einheitId, 'position' => 1,
    ];
});

it('zwei gleichzeitig montierte Editoren bleiben in ihrem eigenen Draft isoliert (Fan-out)', function () {
    // Beide Editoren LEBEN parallel — genau die Cockpit-Konstellation (zwei offene Stufen).
    $editorA = Livewire::test(IngredientEditor::class, ['recipeId' => $this->rezeptA->id, 'eingebettet' => true]);
    $editorB = Livewire::test(IngredientEditor::class, ['recipeId' => $this->rezeptB->id, 'eingebettet' => true]);

    // Jeder speichert seinen eigenen Stand (adressiert ans eigene Rezept).
    $editorA->call('speichern', [($this->zeile)('Neue Zutat A', '111')], $this->rezeptA->id);
    $editorB->call('speichern', [($this->zeile)('Neue Zutat B', '222')], $this->rezeptB->id);

    // Kein Bleed: A trägt nur A's Stand, B nur B's — keine geteilte/statische Instanz-Grenze.
    expect(($this->zutatenVon)($this->rezeptA->id))->toBe(['Neue Zutat A'])
        ->and(($this->zutatenVon)($this->rezeptB->id))->toBe(['Neue Zutat B']);
    $editorA->assertSet('fehler', null);
    $editorB->assertSet('fehler', null);
});

it('ein an das falsche Rezept adressierter Save wird auch bei parallelen Editoren abgewiesen', function () {
    $editorA = Livewire::test(IngredientEditor::class, ['recipeId' => $this->rezeptA->id, 'eingebettet' => true]);
    $editorB = Livewire::test(IngredientEditor::class, ['recipeId' => $this->rezeptB->id, 'eingebettet' => true]);

    // Das Rennen: Instanz B bekommt den Klick, der Auftrag ist an A adressiert.
    $editorB->call('speichern', [($this->zeile)('Fremder Stand aus A', '999')], $this->rezeptA->id);

    // Weder A (falsche Adresse) noch B (eigenes Ziel, aber nicht sein Auftrag) werden angetastet.
    expect(($this->zutatenVon)($this->rezeptA->id))->toBe(['Zutat von A'])
        ->and(($this->zutatenVon)($this->rezeptB->id))->toBe(['Zutat von B']);
    $editorB->assertSet('fehler', fn ($f) => is_string($f) && $f !== '');
});

it('Cockpit-Fan-out: zwei offene Drafts mounten je eine eigene Editor-Instanz', function () {
    $session = app(PlanningSessionService::class)->create($this->rootTeam, ['title' => 'X', 'brief' => 'y']);
    $run = FoodAlchemistCascadeRun::create(['team_id' => $this->rootTeam->id, 'planning_session_id' => $session->id, 'scope' => 'gericht', 'status' => 'review']);
    $stepA = FoodAlchemistCascadeRunStep::create([
        'team_id' => $this->rootTeam->id, 'cascade_run_id' => $run->id, 'kind' => 'rezept', 'status' => 'done',
        'label' => 'Draft A', 'ref_id' => $this->rezeptA->id, 'sort' => 1,
    ]);
    $stepB = FoodAlchemistCascadeRunStep::create([
        'team_id' => $this->rootTeam->id, 'cascade_run_id' => $run->id, 'kind' => 'rezept', 'status' => 'done',
        'label' => 'Draft B', 'ref_id' => $this->rezeptB->id, 'sort' => 2,
    ]);
    // 2026-09-03: Stufe C ist NEU und trägt den Fall, den der Docblock oben (Punkt 4) beschreibt,
    // erstmals ZUR LAUFZEIT — sie wiederverwendet DASSELBE Sub-Rezept wie Stufe A. Nur so kann ein
    // rein ref_id-basierter wire:key zwei Instanzen per Morph kollabieren lassen; mit zwei
    // verschiedenen Rezepten (A/B) ist der Fall unsichtbar. Bisher prüfte nur ein statischer
    // Blade-Grep weiter unten, dass die Step-ID im Key steht.
    $stepC = FoodAlchemistCascadeRunStep::create([
        'team_id' => $this->rootTeam->id, 'cascade_run_id' => $run->id, 'kind' => 'rezept', 'status' => 'done',
        'label' => 'Draft A (reused)', 'ref_id' => $this->rezeptA->id, 'sort' => 3,
    ]);

    $cockpit = Livewire::test(PlanungIndex::class)
        ->set('sessionId', $session->id)
        ->set('laufId', $run->id)
        ->call('toggleZutaten', $stepA->id)
        ->call('toggleZutaten', $stepB->id)
        ->call('toggleZutaten', $stepC->id)
        ->assertSet('zutatenOffen', [$stepA->id, $stepB->id, $stepC->id]);

    $html = $cockpit->html();

    // Editor-Wurzeln einsammeln. `wire:name` trägt JEDE montierte Instanz — auch die, die Livewire
    // als nackten Platzhalter ausgibt, weil das Eltern-Memo sie schon kennt.
    preg_match_all(
        '/<[a-zA-Z0-9-]+[^>]*wire:name="foodalchemist\.recipes\.ingredient-editor"[^>]*>/',
        $html,
        $editorWurzeln
    );
    $editorIds = [];
    foreach ($editorWurzeln[0] as $wurzel) {
        if (preg_match('/wire:id="([^"]+)"/', $wurzel, $mid)) {
            $editorIds[] = $mid[1];
        }
    }
    preg_match_all('/worker-zutaten-\d+-\d+/', $html, $editorKeys);

    // Alle drei Stufen sind aufgeklappt (Toggle-Label je offenem Draft).
    expect(substr_count($html, 'Zutaten schließen'))->toBe(3)
        // … und JEDER Draft mountet einen EIGENEN, eindeutig gekeyten Editor (Step-ID + Rezept-ID).
        ->and($html)->toContain("worker-zutaten-{$stepA->id}-{$this->rezeptA->id}")
        ->and($html)->toContain("worker-zutaten-{$stepB->id}-{$this->rezeptB->id}")
        // Stufe C teilt das Rezept von A — der Key muss sie TROTZDEM trennen (Step-ID im Key).
        ->and($html)->toContain("worker-zutaten-{$stepC->id}-{$this->rezeptA->id}")
        ->and(array_unique($editorKeys[0]))->toHaveCount(3)
        // KORREKTUR 2026-09-03: hier stand `substr_count($html, 'wire:snapshot')->toBe(2)`.
        // Diese Zusicherung war UNERFÜLLBAR: einen `wire:snapshot` trägt nur ein Kind, das in
        // GENAU DIESEM Request erstmals montiert wird; ein dem Eltern-Memo bereits bekanntes Kind
        // gibt Livewire absichtlich als nackten Platzhalter aus (nur `wire:id` + `wire:name`), und
        // `update()` legt den Eltern-HTML ohnehin nur in `effects['html']`. Bei GETRENNTEN
        // toggleZutaten-Requests steht deshalb nie mehr als EIN Snapshot im HTML — der Test
        // konnte nur rot sein.
        //
        // Der Ersatz misst, was gemeint war: drei VERSCHIEDENE Instanz-IDs. Wäre eine Instanz
        // geteilt (Morph-Kollaps), stünde dieselbe `wire:id` zweimal im Render, weil der
        // Platzhalter die ID des bekannten Kindes übernimmt. Das ist die Invariante
        // „getrennte Instanzen, kein Save-Bleed" — unabhängig davon, welcher Request montiert.
        ->and(array_unique($editorIds))->toHaveCount(3);
});

it('Blade-Vertrag: der Fan-out-Editor-Key ist eindeutig je Step (Step-ID, nicht nur Rezept-ID)', function () {
    // Zwei Stufen, die dasselbe Sub-Rezept teilen (Reuse), tragen dieselbe ref_id. Wäre der
    // wire:key nur ref_id-basiert, kollabierte Livewires Morph beide zu EINER Instanz und der
    // Stand der einen bliebe auf der anderen kleben. Die Step-ID im Key verhindert das.
    $modulRoot = dirname((new ReflectionClass(\Platform\FoodAlchemist\FoodAlchemistServiceProvider::class))->getFileName(), 2);
    $blade = file_get_contents($modulRoot . '/resources/views/livewire/planung/partials/step-zeile.blade.php');

    expect($blade)->toContain('wire:key="worker-zutaten-{{ $st->id }}-{{ (int) $st->ref_id }}"')
        ->and($blade)->toContain(':eingebettet="true"');
});
