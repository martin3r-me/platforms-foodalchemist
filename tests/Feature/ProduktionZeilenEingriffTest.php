<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Platform\FoodAlchemist\Enums\ProductionOrderStatus;
use Platform\FoodAlchemist\Models\FoodAlchemistPrice;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplier;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItemStructure;
use Platform\FoodAlchemist\Models\FoodAlchemistVocabEinheit;
use Platform\FoodAlchemist\Models\FoodAlchemistProductionOrderLine as Line;
use Platform\FoodAlchemist\Services\ProductionOrderService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 30 E1 — Zeilen-Eingriff. Der Auftrag wird vom Rechenergebnis zum Arbeitsdokument,
 * OHNE Spec-18-P2 („volle Neu-Explosion") aufzuweichen.
 *
 * Die zu schützenden Invarianten:
 *  · die Explosion erzeugt höchstens EINE Zeile je Rezept (darauf beruht der Overlay-Restore)
 *  · jedes Overlay-Feld überlebt jeden Recompute
 *  · freie Positionen überleben auch, wenn ALLE Ziele weg sind
 *  · verwaistes Overlay → Warnung statt stillem Verlust
 *  · gestrichene Zeilen: raus aus Summen und Druck, drin im Panel
 *  · der Override propagiert NICHT in Einkauf/GP-Bedarf
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->svc = app(ProductionOrderService::class);

    // Fixture wie ProductionOrderServiceTest: Kuchen (10 Port./Ansatz) = 1000 g Mehl +
    // 150 g Vanillesauce; Vanillesauce (Basis 1 kg) = 500 g Zucker + 500 g Butter.
    $this->g = FoodAlchemistVocabEinheit::create([
        'team_id' => $this->rootTeam->id, 'slug' => 'g', 'display_de' => 'Gramm',
        'dimension' => 'mass', 'default_in_g' => 1,
    ]);

    $mkGp = function (string $name, float $preis, string $lieferant) {
        $supplier = FoodAlchemistSupplier::firstOrCreate(['team_id' => $this->rootTeam->id, 'name' => $lieferant]);
        $gp = $this->makeGp($this->rootTeam, $name);
        $la = FoodAlchemistSupplierItem::create([
            'team_id' => $this->rootTeam->id, 'supplier_id' => $supplier->id,
            'designation' => $name . ' 1kg', 'article_number' => 'ART-' . strtoupper(substr($name, 0, 3)),
            'qty' => 1.0, 'unit_code' => 'kg',
        ]);
        FoodAlchemistSupplierItemStructure::create(['team_id' => $this->rootTeam->id, 'supplier_item_id' => $la->id, 'gp_id' => $gp->id]);
        FoodAlchemistPrice::create(['team_id' => $this->rootTeam->id, 'supplier_item_id' => $la->id, 'price' => $preis, 'status' => '0']);
        $gp->update(['lead_la_supplier_item_id' => $la->id]);

        return $gp->refresh();
    };
    $mehl = $mkGp('Mehl', 2.00, 'Chefs');
    $zucker = $mkGp('Zucker', 1.00, 'Chefs');
    $butter = $mkGp('Butter', 12.00, 'Hanos');

    $this->sauce = FoodAlchemistRecipe::create([
        'team_id' => $this->rootTeam->id, 'recipe_key' => 'vanillesauce', 'name' => 'Vanillesauce',
        'status' => 'approved', 'is_sales_recipe' => false, 'yield_kg' => 1.0,
    ]);
    $this->sauce->ingredients()->create(['team_id' => $this->rootTeam->id, 'position' => 0, 'gp_id' => $zucker->id, 'raw_text' => 'Zucker', 'quantity' => 500, 'unit_vocab_id' => $this->g->id]);
    $this->sauce->ingredients()->create(['team_id' => $this->rootTeam->id, 'position' => 1, 'gp_id' => $butter->id, 'raw_text' => 'Butter', 'quantity' => 500, 'unit_vocab_id' => $this->g->id]);

    $this->kuchen = FoodAlchemistRecipe::create([
        'team_id' => $this->rootTeam->id, 'recipe_key' => 'kuchen', 'name' => 'DES: Kuchen',
        'status' => 'approved', 'is_sales_recipe' => true, 'sales_net' => 3.50, 'sales_unit_count' => 10,
    ]);
    $this->kuchen->ingredients()->create(['team_id' => $this->rootTeam->id, 'position' => 0, 'gp_id' => $mehl->id, 'raw_text' => 'Mehl', 'quantity' => 1000, 'unit_vocab_id' => $this->g->id]);
    $this->kuchen->ingredients()->create(['team_id' => $this->rootTeam->id, 'position' => 1, 'referenced_recipe_id' => $this->sauce->id, 'raw_text' => 'Vanillesauce', 'quantity' => 150, 'unit_vocab_id' => $this->g->id]);

    $rc = app(\Platform\FoodAlchemist\Services\RecipeRecomputeService::class);
    $rc->recomputePipeline($this->sauce->id);
    $rc->recomputePipeline($this->kuchen->id);

    $this->auftrag = fn (float $portionen = 20) => $this->svc->saveNew(
        $this->rootTeam, '2026-08-10', 'Testauftrag',
        [['source_ref' => 'r:kuchen', 'recipe_id' => $this->kuchen->id, 'portions' => $portionen]],
    );
    $this->zeile = fn ($order, $recipeId) => Line::where('production_order_id', $order->id)
        ->where('recipe_id', $recipeId)->first();
});

// ── Schema-Test: die riskante ->change()-Migration nachmessen ───────────────

it('Schema: recipe_id ist nullable, Cascade und beide Indizes stehen', function () {
    $order = ($this->auftrag)();

    // NULL-recipe_id muss einfügbar sein (freie Position)
    $frei = Line::create([
        'team_id' => $this->rootTeam->id, 'production_order_id' => $order->id,
        'origin' => 'manual', 'recipe_id' => null, 'titel' => 'Brot holen',
        'ansaetze' => 1, 'benoetigt_ansaetze' => 1, 'position' => 10001,
    ]);
    expect($frei->recipe_id)->toBeNull();

    // Zweite freie Position: der Unique-Index darf an NULL nicht hängenbleiben
    Line::create([
        'team_id' => $this->rootTeam->id, 'production_order_id' => $order->id,
        'origin' => 'manual', 'recipe_id' => null, 'titel' => 'Eis holen',
        'ansaetze' => 1, 'benoetigt_ansaetze' => 1, 'position' => 10002,
    ]);
    expect(Line::where('production_order_id', $order->id)->whereNull('recipe_id')->count())->toBe(2);

    // Spalten da
    foreach (['origin', 'titel', 'manual_ansaetze', 'is_manual_ansaetze', 'is_struck', 'struck_reason'] as $sp) {
        expect(Schema::hasColumn('foodalchemist_production_order_lines', $sp))->toBeTrue($sp);
    }

    // Cascade: Auftrag hart löschen entfernt die Zeilen
    $orderId = $order->id;
    DB::table('foodalchemist_production_orders')->where('id', $orderId)->delete();
    expect(Line::where('production_order_id', $orderId)->count())->toBe(0);
});

it('Invariante: die Explosion erzeugt höchstens EINE Zeile je Rezept — auch wenn ein Rezept Top UND Sub ist', function () {
    // Die Sauce ist Sub des Kuchens UND gleichzeitig eigenes Ziel.
    $order = $this->svc->saveNew($this->rootTeam, '2026-08-11', 'Doppelrolle', [
        ['source_ref' => 'r:kuchen', 'recipe_id' => $this->kuchen->id, 'portions' => 20],
        ['source_ref' => 'r:sauce', 'recipe_id' => $this->sauce->id, 'amount_kg' => 2.0],
    ]);

    expect(Line::where('production_order_id', $order->id)->where('recipe_id', $this->sauce->id)->count())->toBe(1);
});

// ── Overlay überlebt den Recompute ─────────────────────────────────────────

it('jedes Overlay-Feld überlebt einen Recompute', function () {
    $order = ($this->auftrag)();
    $zeile = ($this->zeile)($order, $this->sauce->id);

    // Alle Overlay-Felder gleichzeitig setzen …
    $zeile->update([
        'note' => 'Küchen-Notiz',
        'manual_ansaetze' => 7.5, 'is_manual_ansaetze' => true,
        'is_struck' => true, 'struck_reason' => 'ist noch von gestern da',
    ]);

    // … Ziel ändern ⇒ voller Recompute
    $this->svc->replaceTargets($this->rootTeam, $order->id, [
        ['source_ref' => 'r:kuchen', 'recipe_id' => $this->kuchen->id, 'portions' => 40],
    ]);

    $neu = ($this->zeile)($order->fresh(), $this->sauce->id);
    expect($neu)->not->toBeNull()
        ->and($neu->note)->toBe('Küchen-Notiz')
        ->and((float) $neu->manual_ansaetze)->toBe(7.5)
        ->and($neu->is_manual_ansaetze)->toBeTrue()
        ->and($neu->is_struck)->toBeTrue()
        ->and($neu->struck_reason)->toBe('ist noch von gestern da');

    // …und die Rechen-Wahrheit wurde trotzdem neu gerechnet
    expect((float) $neu->ansaetze)->not->toBe(7.5);
});

it('die Overlay-Konstante ist vollständig — jedes gelistete Feld wird wirklich restauriert', function () {
    $order = ($this->auftrag)();
    $zeile = ($this->zeile)($order, $this->sauce->id);

    // Tabellengetrieben: neue Overlay-Felder fallen hier auf, wenn der Restore sie vergisst.
    $werte = ['note' => 'x', 'manual_ansaetze' => 3.0, 'is_manual_ansaetze' => true,
        'is_struck' => true, 'struck_reason' => 'y',
        'station_id' => null, 'assignee' => 'Marco', 'vorlauf_tage' => 2];
    expect(array_keys($werte))->toBe(ProductionOrderService::OVERLAY_FELDER);

    $zeile->update($werte);
    $this->svc->replaceTargets($this->rootTeam, $order->id, [
        ['source_ref' => 'r:kuchen', 'recipe_id' => $this->kuchen->id, 'portions' => 30],
    ]);

    $neu = ($this->zeile)($order->fresh(), $this->sauce->id);
    foreach ($werte as $feld => $erwartet) {
        // lose vergleichen: decimal:3-Casts liefern '3.000', Booleans 1/0
        expect($neu->{$feld} == $erwartet)->toBeTrue($feld . ' ging beim Recompute verloren');
    }
});

it('eine freie Position überlebt den Recompute — auch wenn ALLE Ziele entfernt werden', function () {
    $order = ($this->auftrag)();
    $frei = $this->svc->addManualLine($this->rootTeam, $order->id, [
        'titel' => 'Brot beim Bäcker abholen', 'arbeitszeit_min' => 30,
    ]);

    $this->svc->replaceTargets($this->rootTeam, $order->id, []);

    expect(Line::whereKey($frei->id)->exists())->toBeTrue()
        ->and(Line::where('production_order_id', $order->id)->where('origin', 'computed')->count())->toBe(0)
        ->and($frei->fresh()->position)->toBeGreaterThanOrEqual(ProductionOrderService::MANUELL_POSITION_BASIS);
});

it('verwaistes Overlay wird gemeldet statt still verworfen', function () {
    $order = ($this->auftrag)();
    ($this->zeile)($order, $this->sauce->id)->update(['note' => 'wichtig']);

    // Ziel weg ⇒ die Sauce kommt nicht mehr vor
    $this->svc->replaceTargets($this->rootTeam, $order->id, []);

    expect(collect($order->fresh()->warnungen)->implode(' '))->toContain('Vanillesauce')
        ->and(collect($order->fresh()->warnungen)->implode(' '))->toContain('verworfen');
});

it('eine Zeile ohne Overlay verschwindet still — keine Warnung', function () {
    $order = ($this->auftrag)();
    $this->svc->replaceTargets($this->rootTeam, $order->id, []);

    expect($order->fresh()->warnungen)->toBe([]);
});

// ── Override ───────────────────────────────────────────────────────────────

it('Override lässt den berechneten Wert stehen und ist zurücknehmbar', function () {
    $order = ($this->auftrag)();
    $zeile = ($this->zeile)($order, $this->sauce->id);
    $berechnet = (float) $zeile->ansaetze;

    $this->svc->setLineAnsaetze($this->rootTeam, $zeile->id, 9.0);
    $zeile->refresh();
    expect($zeile->ansaetze_effektiv)->toBe(9.0)
        ->and((float) $zeile->ansaetze)->toBe($berechnet)      // Referenz bleibt
        ->and($zeile->override_stale)->toBeTrue();

    $this->svc->setLineAnsaetze($this->rootTeam, $zeile->id, null);
    $zeile->refresh();
    expect($zeile->is_manual_ansaetze)->toBeFalse()
        ->and($zeile->ansaetze_effektiv)->toBe($berechnet);
});

it('der Puffer skaliert den Override NICHT — ein Override ist absolut, kein Faktor', function () {
    $order = ($this->auftrag)();
    $zeile = ($this->zeile)($order, $this->sauce->id);
    $this->svc->setLineAnsaetze($this->rootTeam, $zeile->id, 5.0);

    $this->svc->updateHeader($this->rootTeam, $order->id, ['buffer_pct' => 20]);

    expect((float) ($this->zeile)($order->fresh(), $this->sauce->id)->manual_ansaetze)->toBe(5.0);
});

it('der Override propagiert NICHT in den Einkauf — er ist Küchen-Korrektur, kein Bedarfs-Eingriff', function () {
    $order = ($this->auftrag)();
    $vorher = $this->svc->dokument($this->rootTeam, $order->id, true)['einkauf'];

    $this->svc->setLineAnsaetze($this->rootTeam, ($this->zeile)($order, $this->sauce->id)->id, 99.0);

    $nachher = $this->svc->dokument($this->rootTeam, $order->id, true)['einkauf'];
    expect($nachher['ek_gesamt'])->toBe($vorher['ek_gesamt']);
});

// ── Streichen ──────────────────────────────────────────────────────────────

it('gestrichene Zeilen bleiben im Panel, fallen aber aus Summen und Druck', function () {
    $order = ($this->auftrag)();
    $zeile = ($this->zeile)($order, $this->sauce->id);
    $this->svc->setLineStruck($this->rootTeam, $zeile->id, true, 'noch von gestern');

    $detail = $this->svc->detail($this->rootTeam, $order->id);
    $gestrichen = collect($detail['zeilen'])->firstWhere('id', $zeile->id);

    expect($gestrichen)->not->toBeNull()                       // sichtbar
        ->and($gestrichen['ist_gestrichen'])->toBeTrue()
        ->and($gestrichen['struck_reason'])->toBe('noch von gestern');

    // …aber nicht gezählt und nicht gedruckt
    $summeOhne = collect($detail['zeilen'])->reject(fn ($z) => $z['ist_gestrichen'])->sum('ansaetze');
    expect($detail['ansaetze_gesamt'])->toBe((float) $summeOhne)
        ->and(collect($this->svc->dokument($this->rootTeam, $order->id, false)['zeilen'])->pluck('name'))
        ->not->toContain('Vanillesauce');
});

it('Streichen klebt am Rezept — der nächste Recompute holt es nicht zurück', function () {
    $order = ($this->auftrag)();
    $this->svc->setLineStruck($this->rootTeam, ($this->zeile)($order, $this->sauce->id)->id, true);

    $this->svc->replaceTargets($this->rootTeam, $order->id, [
        ['source_ref' => 'r:kuchen', 'recipe_id' => $this->kuchen->id, 'portions' => 50],
    ]);

    expect(($this->zeile)($order->fresh(), $this->sauce->id)->is_struck)->toBeTrue();
});

// ── Freie Positionen ───────────────────────────────────────────────────────

it('freie Position braucht einen Titel und hat nie ein Rezept', function () {
    $order = ($this->auftrag)();

    expect(fn () => $this->svc->addManualLine($this->rootTeam, $order->id, ['titel' => '  ']))
        ->toThrow(RuntimeException::class, 'Titel');

    $frei = $this->svc->addManualLine($this->rootTeam, $order->id, ['titel' => 'Brot holen', 'arbeitszeit_min' => 20]);
    expect($frei->recipe_id)->toBeNull()
        ->and($frei->origin)->toBe('manual')
        ->and($frei->anzeigeName())->toBe('Brot holen')
        ->and((int) $frei->arbeitszeit_min)->toBe(20);
});

it('berechnete Zeilen werden gestrichen, freie gelöscht — nicht umgekehrt', function () {
    $order = ($this->auftrag)();
    $berechnet = ($this->zeile)($order, $this->sauce->id);
    $frei = $this->svc->addManualLine($this->rootTeam, $order->id, ['titel' => 'Brot holen']);

    expect(fn () => $this->svc->removeManualLine($this->rootTeam, $berechnet->id))
        ->toThrow(RuntimeException::class, 'gestrichen');
    expect(fn () => $this->svc->setLineStruck($this->rootTeam, $frei->id, true))
        ->toThrow(RuntimeException::class, 'gelöscht');

    $this->svc->removeManualLine($this->rootTeam, $frei->id);
    expect(Line::whereKey($frei->id)->exists())->toBeFalse();
});

// ── Guards ─────────────────────────────────────────────────────────────────

it('ein laufender Auftrag ist gegen Zeilen-Eingriffe dicht', function () {
    $order = ($this->auftrag)();
    $this->svc->setStatus($this->rootTeam, $order->id, ProductionOrderStatus::InProgress);
    // Zeile NACH dem Start holen: setStatus() rechnet ein letztes Mal durch und ersetzt sie.
    $zeile = ($this->zeile)($order->fresh(), $this->sauce->id);

    expect(fn () => $this->svc->setLineAnsaetze($this->rootTeam, $zeile->id, 3.0))
        ->toThrow(RuntimeException::class, 'geplanter Auftrag');
    expect(fn () => $this->svc->addManualLine($this->rootTeam, $order->id, ['titel' => 'zu spät']))
        ->toThrow(RuntimeException::class);
});

it('der letzte Recompute beim Start bewahrt das Overlay', function () {
    $order = ($this->auftrag)();
    $zeile = ($this->zeile)($order, $this->sauce->id);
    $this->svc->setLineAnsaetze($this->rootTeam, $zeile->id, 4.0);
    $this->svc->updateLine($this->rootTeam, $zeile->id, ['note' => 'vor dem Start']);

    // setStatus() rechnet ein letztes Mal durch, bevor es einfriert
    $this->svc->setStatus($this->rootTeam, $order->id, ProductionOrderStatus::InProgress);

    $neu = ($this->zeile)($order->fresh(), $this->sauce->id);
    expect((float) $neu->manual_ansaetze)->toBe(4.0)
        ->and($neu->note)->toBe('vor dem Start');
});

it('ein fremdes Team kann keine Zeile anfassen (D1)', function () {
    $order = ($this->auftrag)();
    $zeile = ($this->zeile)($order, $this->sauce->id);

    expect(fn () => $this->svc->setLineAnsaetze($this->childA, $zeile->id, 2.0))
        ->toThrow(RuntimeException::class, 'D1');
});
