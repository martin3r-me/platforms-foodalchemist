<?php

use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Platform\FoodAlchemist\Enums\ProductionLineStatus;
use Platform\FoodAlchemist\Enums\ProductionOrderStatus;
use Platform\FoodAlchemist\Livewire\Produktion\Editor;
use Platform\FoodAlchemist\Livewire\Produktion\Tagesplan;
use Platform\FoodAlchemist\Models\FoodAlchemistProductionOrderLine as Line;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Services\ProductionOrderService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 30 E6 — Küchen-Ausführung: abhaken, sonst nichts.
 *
 * Die Invarianten, die hier festgenagelt werden:
 *  · abhaken erst ab `in_progress` — im `planned` kann ein Recompute die Zeile ersetzen,
 *    ein überlebendes „erledigt" hinge dann an geänderten Ansätzen
 *  · der Auftragsstatus wird NIE automatisch weitergeschaltet
 *  · `fortschritt` ist abgeleitet, nie gespeichert; gestrichene Zeilen zählen nirgends
 *  · `is_struck` (Planungs-Entscheid) und `skipped` (Ausführungs-Ergebnis) bleiben getrennt
 *  · fremdes Team kann nicht abhaken (D1)
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->svc = app(ProductionOrderService::class);
    $this->actingAs($this->makeUser($this->rootTeam, 'Küchenchef'));

    $mk = fn (string $key, string $name) => FoodAlchemistRecipe::create([
        'team_id' => $this->rootTeam->id, 'recipe_key' => $key, 'name' => $name,
        'status' => 'approved', 'is_sales_recipe' => false, 'yield_kg' => 2.0, 'work_time_min' => 60,
    ]);
    $this->fond = $mk('fond', 'Brauner Fond');
    $this->jus = $mk('jus', 'Kalbsjus');

    $this->order = $this->svc->saveNew($this->rootTeam, '2026-08-20', 'Abarbeiten-Test', [
        ['source_ref' => 'r:fond', 'recipe_id' => $this->fond->id, 'amount_kg' => 6.0],
        ['source_ref' => 'r:jus', 'recipe_id' => $this->jus->id, 'amount_kg' => 4.0],
    ]);

    $this->zeile = fn (FoodAlchemistRecipe $r) => Line::where('production_order_id', $this->order->id)
        ->where('recipe_id', $r->id)->first();

    $this->starte = function () {
        $this->svc->setStatus($this->rootTeam, $this->order->id, ProductionOrderStatus::InProgress);

        return $this->order->refresh();
    };
});

// ── Schema ─────────────────────────────────────────────────────────────────

it('Schema: line_status/done_at/done_by stehen, Default ist open', function () {
    foreach (['line_status', 'done_at', 'done_by'] as $sp) {
        expect(Schema::hasColumn('foodalchemist_production_order_lines', $sp))->toBeTrue($sp);
    }

    expect(($this->zeile)($this->fond)->line_status)->toBe(ProductionLineStatus::Open);
});

/**
 * Die Invariante, die den Verzicht auf `line_status` in OVERLAY_FELDER trägt: bricht sie,
 * würden Häkchen per Overlay an inzwischen neu gerechneten Ansätzen kleben bleiben.
 */
it('Invariante: Recompute und Abhaken schließen sich strukturell aus', function () {
    expect(ProductionOrderService::OVERLAY_FELDER)
        ->not->toContain('line_status')
        ->not->toContain('done_at')
        ->not->toContain('done_by');

    // Recompute läuft nur im `planned` …
    $this->svc->updateHeader($this->rootTeam, $this->order->id, ['name' => 'Neu benannt']);
    expect($this->order->refresh()->status)->toBe(ProductionOrderStatus::Planned);

    // … abgehakt wird erst ab `in_progress`.
    ($this->starte)();
    $this->svc->setLineStatus($this->rootTeam, ($this->zeile)($this->fond)->id, ProductionLineStatus::Done);

    expect(fn () => $this->svc->updateHeader($this->rootTeam, $this->order->id, ['name' => 'X']))
        ->toThrow(\RuntimeException::class);
});

// ── Guard-Matrix ───────────────────────────────────────────────────────────

it('abhaken im geplanten Auftrag ist verboten', function () {
    $id = ($this->zeile)($this->fond)->id;

    expect(fn () => $this->svc->setLineStatus($this->rootTeam, $id, ProductionLineStatus::Done))
        ->toThrow(\RuntimeException::class);
});

it('abhaken im laufenden Auftrag setzt Status, Zeitstempel und Person', function () {
    ($this->starte)();
    $zeile = $this->svc->setLineStatus($this->rootTeam, ($this->zeile)($this->fond)->id, ProductionLineStatus::Done);

    expect($zeile->line_status)->toBe(ProductionLineStatus::Done)
        ->and($zeile->done_at)->not->toBeNull()
        ->and($zeile->done_by)->toBe(auth()->id());
});

it('Haken zurücknehmen räumt Zeitstempel und Person wieder ab', function () {
    ($this->starte)();
    $id = ($this->zeile)($this->fond)->id;
    $this->svc->setLineStatus($this->rootTeam, $id, ProductionLineStatus::Done);
    $zeile = $this->svc->setLineStatus($this->rootTeam, $id, ProductionLineStatus::Open);

    expect($zeile->line_status)->toBe(ProductionLineStatus::Open)
        ->and($zeile->done_at)->toBeNull()
        ->and($zeile->done_by)->toBeNull();
});

it('abhaken im fertigen Auftrag ist verboten', function () {
    ($this->starte)();
    $id = ($this->zeile)($this->fond)->id;
    $this->svc->setStatus($this->rootTeam, $this->order->id, ProductionOrderStatus::Done, ['finish_note' => 'Testabschluss.']);

    expect(fn () => $this->svc->setLineStatus($this->rootTeam, $id, ProductionLineStatus::Open))
        ->toThrow(\RuntimeException::class);
});

it('D1: fremdes Team kann nicht abhaken', function () {
    ($this->starte)();
    $id = ($this->zeile)($this->fond)->id;

    // childA SIEHT den Auftrag des Root-Teams (Kette nach oben), darf ihn aber nicht schreiben.
    expect(fn () => $this->svc->setLineStatus($this->childA, $id, ProductionLineStatus::Done))
        ->toThrow(\RuntimeException::class);
});

// ── Fortschritt ────────────────────────────────────────────────────────────

it('Fortschritt zählt erledigt und übersprungen, nicht offen', function () {
    ($this->starte)();
    $this->svc->setLineStatus($this->rootTeam, ($this->zeile)($this->fond)->id, ProductionLineStatus::Done);

    $fs = $this->svc->detail($this->rootTeam, $this->order->id)['fortschritt'];

    expect($fs['gesamt'])->toBe(2)
        ->and($fs['erledigt'])->toBe(1)
        ->and($fs['offen'])->toBe(1)
        ->and($fs['prozent'])->toBe(50)
        ->and($fs['alle_erledigt'])->toBeFalse();

    // `skipped` = „hätten wir sollen, haben wir nicht" — zählt als abgearbeitet, aber getrennt.
    $this->svc->setLineStatus($this->rootTeam, ($this->zeile)($this->jus)->id, ProductionLineStatus::Skipped, 'Nicht mehr benötigt');
    $fs = $this->svc->detail($this->rootTeam, $this->order->id)['fortschritt'];

    expect($fs['uebersprungen'])->toBe(1)
        ->and($fs['erledigt'])->toBe(1)
        ->and($fs['prozent'])->toBe(100)
        ->and($fs['alle_erledigt'])->toBeTrue();
});

it('gestrichene Zeilen zählen im Fortschritt weder im Zähler noch im Nenner', function () {
    // Streichen ist ein PLANUNGS-Entscheid und passiert deshalb vor dem Start.
    $this->svc->setLineStruck($this->rootTeam, ($this->zeile)($this->jus)->id, true, 'entfällt');
    ($this->starte)();
    $this->svc->setLineStatus($this->rootTeam, ($this->zeile)($this->fond)->id, ProductionLineStatus::Done);

    $fs = $this->svc->detail($this->rootTeam, $this->order->id)['fortschritt'];

    expect($fs['gesamt'])->toBe(1)
        ->and($fs['prozent'])->toBe(100)
        ->and($fs['alle_erledigt'])->toBeTrue();
});

it('is_struck und skipped bleiben getrennte Sachverhalte', function () {
    $this->svc->setLineStruck($this->rootTeam, ($this->zeile)($this->jus)->id, true, 'entfällt');
    ($this->starte)();

    $jus = ($this->zeile)($this->jus);

    expect($jus->is_struck)->toBeTrue()
        ->and($jus->line_status)->toBe(ProductionLineStatus::Open);
});

it('der Auftragsstatus wird durch das letzte Häkchen NICHT weitergeschaltet', function () {
    ($this->starte)();
    foreach ([$this->fond, $this->jus] as $r) {
        $this->svc->setLineStatus($this->rootTeam, ($this->zeile)($r)->id, ProductionLineStatus::Done);
    }

    $detail = $this->svc->detail($this->rootTeam, $this->order->id);

    expect($detail['fortschritt']['alle_erledigt'])->toBeTrue()
        ->and($detail['status'])->toBe('in_progress');
});

it('fertig melden mit offenen Zeilen braucht Notiz und hakt nichts automatisch ab', function () {
    ($this->starte)();
    expect(fn () => $this->svc->setStatus($this->rootTeam, $this->order->id, ProductionOrderStatus::Done))
        ->toThrow(RuntimeException::class, 'Abschlussnotiz');
    $this->svc->setStatus($this->rootTeam, $this->order->id, ProductionOrderStatus::Done, ['finish_note' => 'Rest entfällt nach Rücksprache.']);

    $detail = $this->svc->detail($this->rootTeam, $this->order->id);

    expect($detail['status'])->toBe('done')
        ->and($detail['fortschritt']['offen'])->toBe(2)
        ->and($detail['fortschritt']['uebersprungen'])->toBe(0);
});

// ── Oberfläche ─────────────────────────────────────────────────────────────

it('Tagesplan zeigt Häkchen nur im laufenden Auftrag', function () {
    Livewire::test(Tagesplan::class, ['von' => '2026-08-18'])
        ->assertDontSeeHtml('data-tagesplan-abhaken');

    ($this->starte)();

    Livewire::test(Tagesplan::class, ['von' => '2026-08-18'])
        ->assertSeeHtml('data-tagesplan-abhaken');
});

it('Tagesplan hakt ab und wieder zurück', function () {
    ($this->starte)();
    $id = ($this->zeile)($this->fond)->id;

    $tp = Livewire::test(Tagesplan::class, ['von' => '2026-08-18'])->call('abhaken', $id);
    expect(Line::find($id)->line_status)->toBe(ProductionLineStatus::Done);

    $tp->call('abhaken', $id);
    expect(Line::find($id)->line_status)->toBe(ProductionLineStatus::Open);
});

it('Tagesplan meldet den Guard als Fehler statt zu krachen', function () {
    Livewire::test(Tagesplan::class, ['von' => '2026-08-18'])
        ->call('abhaken', ($this->zeile)($this->fond)->id)
        ->assertSet('fehler', fn ($f) => $f !== null);
});

it('Editor zeigt Fortschritt und Abhaken-Knopf erst im laufenden Auftrag', function () {
    Livewire::test(Editor::class)->call('oeffnenBearbeiten', $this->order->id)
        ->assertDontSeeHtml('data-zeile-abhaken')
        ->assertDontSeeHtml('data-editor-fortschritt');

    ($this->starte)();

    Livewire::test(Editor::class)->call('oeffnenBearbeiten', $this->order->id)
        ->assertSeeHtml('data-zeile-abhaken')
        ->assertSeeHtml('data-editor-fortschritt');
});

it('Editor hakt ab und zeigt den Status an der Zeile', function () {
    ($this->starte)();
    $id = ($this->zeile)($this->fond)->id;

    Livewire::test(Editor::class)->call('oeffnenBearbeiten', $this->order->id)
        ->call('zeileAbhaken', $id)
        ->assertSet('fehler', null)
        ->assertSeeHtml('data-zeile-status="done"');

    expect(Line::find($id)->line_status)->toBe(ProductionLineStatus::Done);
});
