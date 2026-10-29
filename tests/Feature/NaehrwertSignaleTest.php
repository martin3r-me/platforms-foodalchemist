<?php

use Livewire\Livewire;
use Platform\Core\Contracts\ToolContext;
use Platform\FoodAlchemist\Enums\SignalStatus;
use Platform\FoodAlchemist\Enums\SignalTyp;
use Platform\FoodAlchemist\Livewire\Suppliers\ItemModal;
use Platform\FoodAlchemist\Models\FoodAlchemistItemNutritional;
use Platform\FoodAlchemist\Models\FoodAlchemistSignal;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplier;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItemStructure;
use Platform\FoodAlchemist\Services\GpAggregateService;
use Platform\FoodAlchemist\Services\Naehrwerte\NaehrwertSignalService;
use Platform\FoodAlchemist\Support\MasterTeam;
use Platform\FoodAlchemist\Support\NaehrwertPruefModus;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;
use Platform\FoodAlchemist\Tools\NaehrwertPruefungEntscheidenTool;
use Platform\FoodAlchemist\Tools\NaehrwertPruefungPutTool;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Nährwert-Prüfung PR C: Modus per Einstellung (nur Master-Team), Signal je Artikel (gedrosselt, schließt selbst), freigeben/korrigieren per MCP,
 * Kennzeichen am Artikel.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    config(['foodalchemist.master_team_id' => $this->rootTeam->id]);
    MasterTeam::flush();
    $this->admin = $this->makeUser($this->rootTeam, 'BHG Admin', 'owner');
    $this->actingAs($this->admin);
    $this->ctx = fn ($user = null, $team = null) => new ToolContext($user ?? $this->admin, $team ?? $this->rootTeam);
    $this->lieferant = FoodAlchemistSupplier::create(['team_id' => $this->rootTeam->id, 'name' => 'Hanos']);
    $this->mkLa = function (int $gpId, array $werte, $team = null) {
        $team ??= $this->rootTeam;
        $la = FoodAlchemistSupplierItem::create(['team_id' => $team->id, 'supplier_id' => $this->lieferant->id, 'designation' => 'LA ' . uniqid()]);
        FoodAlchemistSupplierItemStructure::create(['team_id' => $team->id, 'supplier_item_id' => $la->id, 'gp_id' => $gpId]);
        FoodAlchemistItemNutritional::create(array_merge(['team_id' => $team->id, 'supplier_item_id' => $la->id], $werte));

        return $la;
    };
    // Öl mit einem kaputten Artikel (W1 + H4) und eine Garnele mit unmöglichem Wert (H1) — zwei harte Befunde
    $this->oel = tap($this->makeGp($this->rootTeam, 'Rapsöl: raffiniert'))->update(['sub_category' => '11.1 Öl']);
    ($this->mkLa)($this->oel->id, ['energy_kcal' => 828, 'fat' => 92]);
    $this->mazola = ($this->mkLa)($this->oel->id, ['energy_kcal' => 98, 'fat' => 2.5, 'protein' => 0, 'carbs_absorbable' => 0]);
    $this->garnele = $this->makeGp($this->rootTeam, 'Garnele: gekocht, TK');
    $this->kaputt = ($this->mkLa)($this->garnele->id, ['energy_kcal' => 300, 'carbs_absorbable' => 269, 'fat' => 1, 'protein' => 14]);
    $this->signale = fn () => FoodAlchemistSignal::where('type', SignalTyp::NaehrwertPruefen->value)->where('status', SignalStatus::Offen->value)->orderBy('id')->get();
});

// ── Modus per Einstellung ───────────────────────────────────────────────────────────────

it('Modus: FA-Admin im Master-Team schaltet per MCP; leer = zurück auf die env', function () {
    expect(NaehrwertPruefModus::aktuell())->toBe('aus');

    $r = (new NaehrwertPruefungPutTool)->execute(['modus' => 'scharf'], ($this->ctx)());
    expect($r->success)->toBeTrue()->and($r->data)->toMatchArray(['vorher' => 'aus', 'modus' => 'scharf', 'quelle' => 'einstellung'])
        ->and(app(GpAggregateService::class)->naehrwerte($this->oel)['fat']['n'])->toBe(1);                 // Mazola zählt nicht

    (new NaehrwertPruefungPutTool)->execute(['modus' => ''], ($this->ctx)());
    expect(NaehrwertPruefModus::aktuell())->toBe('aus')->and(NaehrwertPruefModus::quelle())->toBe('env');
});

it('Modus: nur im Master-Team und nur FA-Admin — Kunden-Admin und Betrachter bekommen FORBIDDEN', function () {
    $kunde = $this->makeUser($this->childA, 'Kunden Admin', 'owner');
    $gast = $this->makeUser($this->rootTeam, 'Gast', 'viewer');

    expect((new NaehrwertPruefungPutTool)->execute(['modus' => 'scharf'], ($this->ctx)($kunde, $this->childA))->errorCode)->toBe('FORBIDDEN')
        ->and((new NaehrwertPruefungPutTool)->execute(['modus' => 'scharf'], ($this->ctx)($gast))->errorCode)->toBe('FORBIDDEN')
        ->and(NaehrwertPruefModus::aktuell())->toBe('aus');
});

it('Modus: der Notaus in der env schlägt die Einstellung', function () {
    (new NaehrwertPruefungPutTool)->execute(['modus' => 'scharf'], ($this->ctx)());
    config(['foodalchemist.naehrwert_pruefung.notaus' => true]);

    expect(NaehrwertPruefModus::aktuell())->toBe('aus')->and(NaehrwertPruefModus::quelle())->toBe('notaus')
        ->and(app(GpAggregateService::class)->naehrwerte($this->oel)['fat']['n'])->toBe(2);
});

// ── Signale ────────────────────────────────────────────────────────────────────────────

it('Signale nur im Modus scharf, je Artikel eins, mit Bezug und Befund', function () {
    expect(app(NaehrwertSignalService::class)->laufen($this->rootTeam))->toBe(0);

    config(['foodalchemist.naehrwert_pruefung.modus' => 'scharf']);
    app(NaehrwertSignalService::class)->laufen($this->rootTeam);
    $s = ($this->signale)();

    expect($s->pluck('dedup_key')->all())->toEqualCanonicalizing(['nw-la-' . $this->mazola->id, 'nw-la-' . $this->kaputt->id])
        ->and($s->firstWhere('ref_id', $this->kaputt->id)->ref_type)->toBe('supplier_item')
        ->and(collect($s->firstWhere('ref_id', $this->kaputt->id)->payload['befunde'])->pluck('regel')->all())->toContain('H1');
});

it('gedrosselt: höchstens signale_je_lauf neue je Lauf, zuerst das Unmögliche; der Rest rückt nach', function () {
    config(['foodalchemist.naehrwert_pruefung.modus' => 'scharf', 'foodalchemist.naehrwert_pruefung.signale_je_lauf' => 1]);

    app(NaehrwertSignalService::class)->laufen($this->rootTeam);
    expect(($this->signale)()->pluck('ref_id')->all())->toBe([$this->kaputt->id]);                       // H1 vor W1

    app(NaehrwertSignalService::class)->laufen($this->rootTeam);
    expect(($this->signale)()->pluck('ref_id')->all())->toBe([$this->kaputt->id, $this->mazola->id])
        ->and(FoodAlchemistSignal::where('dedup_key', 'nw-la-' . $this->kaputt->id)->value('seen_count'))->toBe(2);
});

it('Signale nur im Besitzer-Team des Artikels', function () {
    config(['foodalchemist.naehrwert_pruefung.modus' => 'scharf']);
    $gp = $this->makeGp($this->childA, 'Kunden-Öl');
    ($this->mkLa)($gp->id, ['energy_kcal' => 300, 'carbs_absorbable' => 269, 'fat' => 1, 'protein' => 14], $this->childA);

    app(NaehrwertSignalService::class)->laufen($this->rootTeam);
    expect(($this->signale)()->pluck('team_id')->unique()->all())->toBe([$this->rootTeam->id])->and(($this->signale)())->toHaveCount(2);
});

// ── freigeben / korrigieren ───────────────────────────────────────────────────────────

it('freigeben: Artikel zählt wieder, Signal schließt; ändern sich die Werte, kommt es zurück', function () {
    config(['foodalchemist.naehrwert_pruefung.modus' => 'scharf']);
    app(NaehrwertSignalService::class)->laufen($this->rootTeam);

    $r = (new NaehrwertPruefungEntscheidenTool)->execute(['la_id' => $this->mazola->id, 'aktion' => 'freigeben', 'grund' => 'Etikett geprüft'], ($this->ctx)());
    expect($r->success)->toBeTrue()
        ->and(app(GpAggregateService::class)->naehrwerte($this->oel)['fat']['n'])->toBe(2)
        ->and(($this->signale)()->pluck('ref_id')->all())->toBe([$this->kaputt->id]);

    FoodAlchemistItemNutritional::where('supplier_item_id', $this->mazola->id)->update(['fat' => 3.0]);
    app(NaehrwertSignalService::class)->laufen($this->rootTeam);
    expect(($this->signale)()->pluck('ref_id')->all())->toContain($this->mazola->id);
});

it('korrigieren: Werte über die Handpflege, neu geprüft, Signal schließt', function () {
    config(['foodalchemist.naehrwert_pruefung.modus' => 'scharf']);
    app(NaehrwertSignalService::class)->laufen($this->rootTeam);

    $r = (new NaehrwertPruefungEntscheidenTool)->execute(['la_id' => $this->kaputt->id, 'aktion' => 'korrigieren', 'werte' => ['carbs_absorbable' => 2.9, 'energy_kcal' => 77]], ($this->ctx)());

    expect($r->success)->toBeTrue()->and($r->data['zaehlt'])->toBeTrue()->and($r->data['befunde_nachher'])->toBe([])
        ->and((float) FoodAlchemistItemNutritional::where('supplier_item_id', $this->kaputt->id)->value('carbs_absorbable'))->toBe(2.9)
        ->and((float) FoodAlchemistItemNutritional::where('supplier_item_id', $this->kaputt->id)->value('protein'))->toBe(14.0)          // bleibt stehen
        ->and(($this->signale)()->pluck('ref_id')->all())->toBe([$this->mazola->id]);
});

it('ENTSCHEIDEN: fremder Artikel und unbekanntes Feld werden abgelehnt', function () {
    $gp = $this->makeGp($this->childA, 'Kunden-Öl');
    $fremd = ($this->mkLa)($gp->id, ['energy_kcal' => 98, 'fat' => 2.5], $this->childA);

    expect((new NaehrwertPruefungEntscheidenTool)->execute(['la_id' => $fremd->id, 'aktion' => 'freigeben'], ($this->ctx)())->errorCode)->toBe('VALIDATION_ERROR')
        ->and((new NaehrwertPruefungEntscheidenTool)->execute(['la_id' => $this->kaputt->id, 'aktion' => 'korrigieren', 'werte' => ['zucker' => 1]], ($this->ctx)())->errorCode)->toBe('VALIDATION_ERROR')
        ->and((new NaehrwertPruefungEntscheidenTool)->execute(['la_id' => $this->kaputt->id, 'aktion' => 'freigeben'], ($this->ctx)($this->makeUser($this->rootTeam, 'Gast', 'viewer')))->errorCode)->toBe('FORBIDDEN');
});

// ── Kennzeichen am Artikel ───────────────────────────────────────────────────────────

it('Artikel-Ansicht zeigt den Befund mit Wirkung: würde nicht zählen (aus) bzw. zählt nicht (scharf); Einheit umgerechnet', function () {
    Livewire::test(ItemModal::class)->call('oeffnen', $this->mazola->id)
        ->assertSeeHtml('data-naehrwert-befund="W1"')->assertSee('würde nicht zählen');

    config(['foodalchemist.naehrwert_pruefung.modus' => 'scharf']);
    Livewire::test(ItemModal::class)->call('oeffnen', $this->mazola->id)->assertSee('zählt nicht')->assertDontSee('würde nicht zählen');

    $ei = $this->makeGp($this->rootTeam, 'Eieromelett: TK');
    $elbfrost = ($this->mkLa)($ei->id, ['energy_kcal' => 164, 'fat' => 0.0109, 'protein' => 0.0113, 'carbs_absorbable' => 0.0051]);
    Livewire::test(ItemModal::class)->call('oeffnen', $elbfrost->id)->assertSeeHtml('data-naehrwert-befund="H6"')->assertSee('umgerechnet × 1000');
});
