<?php

use Livewire\Livewire;
use Platform\Core\Contracts\ToolContext;
use Platform\FoodAlchemist\Livewire\Controlling\Panels\Erfolg;
use Platform\FoodAlchemist\Livewire\Controlling\Panels\Kennzahlen;
use Platform\FoodAlchemist\Livewire\Controlling\Panels\Wareneinsatz;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Models\FoodAlchemistSalesFact;
use Platform\FoodAlchemist\Services\SalesImportService;
use Platform\FoodAlchemist\Services\TeamSettingsService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;
use Platform\FoodAlchemist\Tools\MenuEngineeringGetTool;
use Platform\FoodAlchemist\Tools\SalesFactsGetTool;
use Platform\FoodAlchemist\Tools\SalesImportPostTool;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 32 — adversariale Mandantentrennung für die neuen Schreibaktionen.
 *
 * **Warum diese Datei existiert.** Beim Bauen von C3 lag der Verkaufs-Import in einem
 * geteilten Ablage-Ordner: jeder Betrieb konnte die Datei eines anderen überschreiben und
 * dessen Umsätze ins eigene Journal einlesen. Der Guard fehlte nicht, weil er vergessen
 * wurde — sondern weil die Ablage kein Eloquent-Objekt ist und damit durch das Raster fiel,
 * mit dem man Tenant-Sicherheit üblicherweise prüft.
 *
 * Die Lehre: „ein `visibleToTeam` steht im Code" ist kein Nachweis. Jede öffentliche
 * Schreibaktion braucht einen Test, der sie mit fremden Daten füttert und beobachtet, dass
 * nichts passiert.
 *
 * Geprüft werden hier die Aktionen aus C2–C4, für die es noch keinen gab. Aufbau der
 * Hierarchie: rootTeam → childA / childB (Geschwister), Nutzer sitzt in childA.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->user = $this->makeUser($this->childA);
    $this->actingAs($this->user);
});

// ── Batch-Umstellung ──────────────────────────────────────────────────────────

it('nimmt kein fremdes Grundprodukt in die Batch-Umstellung', function () {
    $fremdGp = $this->makeGp($this->childB, 'Fremde Butter');

    // Die Auswahl kommt aus dem Browser und ist damit manipulierbar — eine untergeschobene
    // id darf nicht dazu führen, dass am Geschwister-Team geschrieben wird.
    Livewire::test(Wareneinsatz::class)
        ->set('auswahl', [$fremdGp->id])
        ->call('vorschau')
        ->assertSet('vorschau', null)
        ->call('umstellen');

    expect($fremdGp->refresh()->lead_la_supplier_item_id)->toBeNull();
});

// ── Verkaufszeilen zuordnen ───────────────────────────────────────────────────

it('ordnet weder fremde Verkaufszeilen zu noch auf fremde Gerichte', function () {
    $eigenerFact = FoodAlchemistSalesFact::create([
        'team_id' => $this->childA->id, 'raw_label' => 'Eigen', 'revenue_net' => 10,
        'sold_at' => '2026-07-01', 'source' => 'csv_import', 'source_hash' => 'a',
    ]);
    $fremderFact = FoodAlchemistSalesFact::create([
        'team_id' => $this->childB->id, 'raw_label' => 'Fremd', 'revenue_net' => 10,
        'sold_at' => '2026-07-01', 'source' => 'csv_import', 'source_hash' => 'b',
    ]);
    $eigenesGericht = FoodAlchemistRecipe::create([
        'team_id' => $this->childA->id, 'recipe_key' => 'a', 'name' => 'Eigen',
        'status' => 'approved', 'is_sales_recipe' => true,
    ]);
    $fremdesGericht = FoodAlchemistRecipe::create([
        'team_id' => $this->childB->id, 'recipe_key' => 'b', 'name' => 'Fremd',
        'status' => 'approved', 'is_sales_recipe' => true,
    ]);

    // (a) fremde Zeile, eigenes Gericht → nichts
    Livewire::test(Erfolg::class)
        ->call('zuordnenOeffnen', $fremderFact->id)
        ->call('zuordnen', $eigenesGericht->id);
    expect($fremderFact->refresh()->recipe_id)->toBeNull();

    // (b) eigene Zeile, fremdes Gericht → nichts. Sonst hinge der eigene Umsatz an einer
    //     Kalkulation, die man nicht sehen darf.
    Livewire::test(Erfolg::class)
        ->call('zuordnenOeffnen', $eigenerFact->id)
        ->call('zuordnen', $fremdesGericht->id);
    expect($eigenerFact->refresh()->recipe_id)->toBeNull();

    // (c) eigene Zeile, eigenes Gericht → geht
    Livewire::test(Erfolg::class)
        ->call('zuordnenOeffnen', $eigenerFact->id)
        ->call('zuordnen', $eigenesGericht->id);
    expect((int) $eigenerFact->refresh()->recipe_id)->toBe((int) $eigenesGericht->id);
});

it('zeigt in den offenen Zuordnungen keine fremden Verkaufszeilen', function () {
    FoodAlchemistSalesFact::create([
        'team_id' => $this->childB->id, 'raw_label' => 'Geheimer Fremdposten', 'revenue_net' => 999,
        'sold_at' => '2026-07-01', 'source' => 'csv_import', 'source_hash' => 'x',
    ]);

    Livewire::test(Erfolg::class)->assertDontSee('Geheimer Fremdposten');
});

// ── Zielwerte ─────────────────────────────────────────────────────────────────

it('schreibt Zielwerte nur ans eigene Team', function () {
    $svc = app(TeamSettingsService::class);
    $vorherB = $svc->zielWareneinsatzPct($this->childB);

    Livewire::test(Kennzahlen::class)
        ->set('zielWe', '22')->set('marge', '18')
        ->call('zieleSpeichern');

    expect($svc->zielWareneinsatzPct($this->childA))->toBe(22.0)
        ->and($svc->zielWareneinsatzPct($this->childB))->toBe($vorherB);
});

it('weist unsinnige Zielwerte ab, statt sie zu speichern', function () {
    $svc = app(TeamSettingsService::class);
    $vorher = $svc->zielWareneinsatzPct($this->childA);

    Livewire::test(Kennzahlen::class)
        ->set('zielWe', '0')->call('zieleSpeichern')
        ->assertHasErrors('zielWe');

    expect($svc->zielWareneinsatzPct($this->childA))->toBe($vorher);
});

// ── MCP ───────────────────────────────────────────────────────────────────────

it('liefert über MCP keine fremden Umsätze', function () {
    FoodAlchemistSalesFact::create([
        'team_id' => $this->childB->id, 'raw_label' => 'Fremd', 'revenue_net' => 500,
        'qty_sold' => 5, 'sold_at' => '2026-07-01', 'source' => 'csv_import', 'source_hash' => 'y',
    ]);
    FoodAlchemistSalesFact::create([
        'team_id' => $this->childA->id, 'raw_label' => 'Eigen', 'revenue_net' => 100,
        'qty_sold' => 2, 'sold_at' => '2026-07-01', 'source' => 'csv_import', 'source_hash' => 'z',
    ]);

    $ctx = new ToolContext($this->user, $this->childA);
    $r = app(SalesFactsGetTool::class)->execute([], $ctx);

    expect($r->success)->toBeTrue()
        ->and($r->data['umsatz_gesamt'])->toBe(100.0)
        ->and(collect($r->data['offen'])->pluck('raw_label'))->not->toContain('Fremd');
});

it('rechnet die Menu-Engineering-Matrix nur auf eigenen Zahlen', function () {
    $ctx = new ToolContext($this->user, $this->childA);
    $r = app(MenuEngineeringGetTool::class)->execute([], $ctx);

    // Ohne eigenes Verkaufs-Ist darf die Antwort nicht plötzlich auf fremden Zahlen stehen.
    expect($r->success)->toBeTrue()
        ->and($r->data['quelle'])->not->toBe('sales');
});

it('liest über MCP keine Datei aus der Ablage eines fremden Teams', function () {
    $fremdOrdner = storage_path('app/' . SalesImportService::ordnerFuer($this->childB));
    if (! is_dir($fremdOrdner)) {
        mkdir($fremdOrdner, 0775, true);
    }
    file_put_contents($fremdOrdner . '/fremd.csv', "Artikel;Umsatz;Datum\nGeheim;9999,00;01.07.2026\n");

    $ctx = new ToolContext($this->user, $this->childA);
    $r = app(SalesImportPostTool::class)->execute(['file' => 'fremd.csv', 'columns' => true], $ctx);

    expect($r->success)->toBeFalse();

    // Auch der Umweg über einen relativen Pfad darf nicht in den Nachbar-Ordner führen.
    $hoch = app(SalesImportPostTool::class)->execute(
        ['file' => '../' . $this->childB->id . '/fremd.csv', 'columns' => true], $ctx
    );
    expect($hoch->success)->toBeFalse();

    @unlink($fremdOrdner . '/fremd.csv');
});

it('verlangt für jedes neue Tool ein Team', function () {
    // Achtung beim Lesen: ein NULL-Team im Kontext ist NICHT „kein Team". Alle FA-Tools fallen
    // laut `FoodAlchemistTool::team()` bewusst auf `currentTeamRelation` des Users zurück —
    // gleiches Verhalten wie die UI. Der echte teamlose Fall ist ein User ohne aktuelles Team,
    // und nur der darf abgewiesen werden.
    $heimatlos = \Platform\Core\Models\User::forceCreate([
        'name' => 'Ohne Team', 'email' => 'ohne.team@test.local',
        'password' => bcrypt('secret'), 'current_team_id' => null,
    ]);
    $ohneTeam = new ToolContext($heimatlos, null);

    foreach ([SalesFactsGetTool::class, MenuEngineeringGetTool::class] as $tool) {
        expect(app($tool)->execute([], $ohneTeam)->success)->toBeFalse();
    }

    expect(app(SalesImportPostTool::class)->execute(['file' => 'x.csv'], $ohneTeam)->success)->toBeFalse();
});
