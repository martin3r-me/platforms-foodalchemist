<?php

use Platform\FoodAlchemist\Enums\ProductionOrderStatus;
use Platform\FoodAlchemist\Models\FoodAlchemistProductionOrderLine as Line;
use Platform\FoodAlchemist\Models\FoodAlchemistProductionStation as Posten;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Services\ProductionCapacityService;
use Platform\FoodAlchemist\Services\ProductionOrderService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 30 E3 — Posten, Zuteilung, Vorproduktion, Kapazität.
 *
 * Die zu schützenden Invarianten:
 *  · `plan_date` driftet NIE gegen `vorlauf_tage` — über JEDEN Schreibpfad
 *  · Vorproduktion ist ein Offset: verschiebt sich der Liefertag, wandert der Plan mit
 *  · ein Posten ohne Kapazität warnt NIE (opt-in)
 *  · Auslastung ist team-strikt — geerbte Posten sind Vorlagen, keine geteilte Ressource
 *  · unverplante Arbeit ist sichtbar, statt aus der Rechnung zu fallen
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->svc = app(ProductionOrderService::class);
    $this->kap = app(ProductionCapacityService::class);

    $this->rezept = FoodAlchemistRecipe::create([
        'team_id' => $this->rootTeam->id, 'recipe_key' => 'fond', 'name' => 'Brauner Fond',
        'status' => 'approved', 'is_sales_recipe' => false, 'yield_kg' => 2.0, 'work_time_min' => 120,
    ]);

    $this->posten = fn (string $name, ?int $kap = null, array $wtag = []) => Posten::create([
        'team_id' => $this->rootTeam->id, 'slug' => \Illuminate\Support\Str::slug($name), 'name' => $name,
        'kapazitaet_min_pro_tag' => $kap, 'kapazitaet_wochentag' => $wtag ?: null,
    ]);

    // Liefertag Do 2026-08-20; 3 kg Bedarf bei 2 kg Ansatz ⇒ 2 Ansätze × 120 min = 240 min
    $this->order = $this->svc->saveNew($this->rootTeam, '2026-08-20', 'Kapazitätstest', [
        ['source_ref' => 'r:fond', 'recipe_id' => $this->rezept->id, 'amount_kg' => 3.0],
    ]);
    $this->zeile = fn () => Line::where('production_order_id', $this->order->id)->first();
});

// ── plan_date: die Drift-Sicherung ─────────────────────────────────────────

it('plan_date wird beim Anlegen abgeleitet und ist gleich dem Liefertag', function () {
    expect(($this->zeile)()->plan_date->toDateString())->toBe('2026-08-20');
});

it('Vorlauf verschiebt plan_date rückwärts', function () {
    $this->svc->assignLine($this->rootTeam, ($this->zeile)()->id, ['vorlauf_tage' => 2]);

    expect(($this->zeile)()->plan_date->toDateString())->toBe('2026-08-18');
});

it('verschiebt sich der Liefertag, wandert der ganze Vorproduktions-Schwanz mit', function () {
    $this->svc->assignLine($this->rootTeam, ($this->zeile)()->id, ['vorlauf_tage' => 3]);
    expect(($this->zeile)()->plan_date->toDateString())->toBe('2026-08-17');

    // Event rutscht um einen Tag
    $this->svc->updateHeader($this->rootTeam, $this->order->id, ['production_date' => '2026-08-21']);

    expect(($this->zeile)()->plan_date->toDateString())->toBe('2026-08-18')
        ->and(($this->zeile)()->vorlauf_tage)->toBe(3);   // der Offset bleibt, das Datum folgt
});

it('plan_date bleibt über JEDEN Schreibpfad konsistent — die Spalte darf nie driften', function () {
    $pruefe = function (string $wo) {
        $order = $this->order->fresh();
        foreach (Line::where('production_order_id', $order->id)->get() as $l) {
            $soll = $order->production_date->copy()->subDays((int) $l->vorlauf_tage)->toDateString();
            expect($l->plan_date?->toDateString())->toBe($soll, "Drift nach: {$wo}");
        }
    };

    $this->svc->assignLine($this->rootTeam, ($this->zeile)()->id, ['vorlauf_tage' => 2]);
    $pruefe('assignLine');

    $this->svc->updateHeader($this->rootTeam, $this->order->id, ['production_date' => '2026-08-25']);
    $pruefe('updateHeader (Datum)');

    $this->svc->updateHeader($this->rootTeam, $this->order->id, ['buffer_pct' => 10]);
    $pruefe('updateHeader (Puffer ⇒ Recompute)');

    $this->svc->replaceTargets($this->rootTeam, $this->order->id, [
        ['source_ref' => 'r:fond', 'recipe_id' => $this->rezept->id, 'amount_kg' => 8.0],
    ]);
    $pruefe('replaceTargets');

    $this->svc->setStatus($this->rootTeam, $this->order->id, ProductionOrderStatus::InProgress);
    $pruefe('setStatus (letzter Recompute)');
});

it('ein Tippfehler beim Vorlauf wird gekappt statt Arbeit einen Monat nach vorn zu werfen', function () {
    $this->svc->assignLine($this->rootTeam, ($this->zeile)()->id, ['vorlauf_tage' => 300]);

    expect(($this->zeile)()->vorlauf_tage)->toBe(ProductionOrderService::MAX_VORLAUF_TAGE);
});

// ── Zuteilung ──────────────────────────────────────────────────────────────

it('Zuteilung überlebt den Recompute', function () {
    $p = ($this->posten)('Warme Küche', 480);
    $this->svc->assignLine($this->rootTeam, ($this->zeile)()->id, [
        'station_id' => $p->id, 'assignee' => 'Marco', 'vorlauf_tage' => 1,
    ]);

    $this->svc->replaceTargets($this->rootTeam, $this->order->id, [
        ['source_ref' => 'r:fond', 'recipe_id' => $this->rezept->id, 'amount_kg' => 9.0],
    ]);

    $z = ($this->zeile)();
    expect($z->station_id)->toBe($p->id)
        ->and($z->assignee)->toBe('Marco')
        ->and($z->vorlauf_tage)->toBe(1)
        ->and($z->plan_date->toDateString())->toBe('2026-08-19');
});

it('Teil-Updates lassen die anderen Zuteilungs-Felder in Ruhe', function () {
    $p = ($this->posten)('Patisserie');
    $this->svc->assignLine($this->rootTeam, ($this->zeile)()->id, ['station_id' => $p->id, 'assignee' => 'Lena']);
    $this->svc->assignLine($this->rootTeam, ($this->zeile)()->id, ['vorlauf_tage' => 1]);

    expect(($this->zeile)()->assignee)->toBe('Lena')->and(($this->zeile)()->station_id)->toBe($p->id);
});

it('umdisponieren geht auch noch im laufenden Auftrag — die Realität besetzt mitten im Service um', function () {
    $p = ($this->posten)('Warme Küche');
    $this->svc->setStatus($this->rootTeam, $this->order->id, ProductionOrderStatus::InProgress);

    $this->svc->assignLine($this->rootTeam, ($this->zeile)()->id, ['station_id' => $p->id, 'assignee' => 'Marco']);

    expect(($this->zeile)()->assignee)->toBe('Marco');
});

it('ein fremdes Team kann nicht zuteilen (D1)', function () {
    expect(fn () => $this->svc->assignLine($this->childA, ($this->zeile)()->id, ['assignee' => 'X']))
        ->toThrow(RuntimeException::class, 'D1');
});

it('ein Posten aus einem fremden Team ist nicht zuweisbar', function () {
    $fremd = Posten::create(['team_id' => $this->childB->id, 'slug' => 'fremd', 'name' => 'Fremd-Posten']);

    expect(fn () => $this->svc->assignLine($this->rootTeam, ($this->zeile)()->id, ['station_id' => $fremd->id]))
        ->toThrow(RuntimeException::class);
});

// ── Kapazität ──────────────────────────────────────────────────────────────

it('rechnet Auslastung je Tag und Posten', function () {
    $p = ($this->posten)('Warme Küche', 480);
    $this->svc->assignLine($this->rootTeam, ($this->zeile)()->id, ['station_id' => $p->id]);

    $a = $this->kap->auslastung($this->rootTeam, '2026-08-01', '2026-08-31');
    $bucket = collect($a['2026-08-20'])->firstWhere('station_id', $p->id);

    expect($bucket['geplant_min'])->toBe(240)
        ->and($bucket['kapazitaet_min'])->toBe(480)
        ->and($bucket['prozent'])->toBe(50)
        ->and($bucket['stufe'])->toBe('ok');
});

it('ein Posten OHNE Kapazität warnt nie — das Feature ist opt-in', function () {
    $p = ($this->posten)('Kalte Küche');   // keine Kapazität hinterlegt
    $this->svc->assignLine($this->rootTeam, ($this->zeile)()->id, ['station_id' => $p->id]);

    $bucket = collect($this->kap->auslastung($this->rootTeam, '2026-08-01', '2026-08-31')['2026-08-20'])
        ->firstWhere('station_id', $p->id);

    expect($bucket['stufe'])->toBe('ohne_kapazitaet')
        ->and($bucket['prozent'])->toBeNull()
        ->and($bucket['geplant_min'])->toBe(240)                       // Minuten trotzdem sichtbar
        ->and($this->kap->warnungenFuer($this->rootTeam, $this->order->id))->toBe([]);
});

it('meldet Überlast nur oberhalb der Kapazität — und nennt Posten, Tag und Zahlen', function () {
    $p = ($this->posten)('Patisserie', 120);   // 240 min geplant ⇒ 200 %
    $this->svc->assignLine($this->rootTeam, ($this->zeile)()->id, ['station_id' => $p->id]);

    $w = $this->kap->warnungenFuer($this->rootTeam, $this->order->id);
    expect($w)->toHaveCount(1)
        ->and($w[0])->toContain('Patisserie')->toContain('20.08.')->toContain('200 %');
});

it('kennt Wochentag-Abweichungen (Samstag kurz, Sonntag zu)', function () {
    // 2026-08-22 ist ein Samstag
    $p = ($this->posten)('Warme Küche', 480, ['6' => 240, '7' => 0]);
    $this->svc->updateHeader($this->rootTeam, $this->order->id, ['production_date' => '2026-08-22']);
    $this->svc->assignLine($this->rootTeam, ($this->zeile)()->id, ['station_id' => $p->id]);

    $bucket = collect($this->kap->auslastung($this->rootTeam, '2026-08-01', '2026-08-31')['2026-08-22'])
        ->firstWhere('station_id', $p->id);

    // 240 geplant bei 240 Kapazität = genau 100 % ⇒ „eng", noch keine Überlast (die beginnt > 100 %).
    expect($bucket['kapazitaet_min'])->toBe(240)
        ->and($bucket['prozent'])->toBe(100)
        ->and($bucket['stufe'])->toBe('eng');
});

it('unverplante Arbeit ist sichtbar statt zu verschwinden', function () {
    $bucket = collect($this->kap->auslastung($this->rootTeam, '2026-08-01', '2026-08-31')['2026-08-20'])
        ->firstWhere('station_id', null);

    expect($bucket['station'])->toBe('Nicht zugeteilt')
        ->and($bucket['geplant_min'])->toBe(240)
        ->and($bucket['stufe'])->toBe('ohne_kapazitaet');   // zählt gegen keine Kapazität
});

it('macht die Datenlücke sichtbar: Zeilen ohne Arbeitszeit werden mitgezählt', function () {
    $this->svc->addManualLine($this->rootTeam, $this->order->id, ['titel' => 'Brot holen']);   // ohne Zeit

    $bucket = collect($this->kap->auslastung($this->rootTeam, '2026-08-01', '2026-08-31')['2026-08-20'])
        ->firstWhere('station_id', null);

    expect($bucket['zeilen'])->toBe(2)->and($bucket['ohne_zeit'])->toBe(1);
});

it('gestrichene Zeilen belegen keine Kapazität', function () {
    $p = ($this->posten)('Warme Küche', 480);
    $this->svc->assignLine($this->rootTeam, ($this->zeile)()->id, ['station_id' => $p->id]);
    $this->svc->setLineStruck($this->rootTeam, ($this->zeile)()->id, true);

    expect($this->kap->auslastung($this->rootTeam, '2026-08-01', '2026-08-31'))->toBe([]);
});

it('erledigte und stornierte Aufträge belegen nichts mehr', function () {
    $p = ($this->posten)('Warme Küche', 480);
    $this->svc->assignLine($this->rootTeam, ($this->zeile)()->id, ['station_id' => $p->id]);
    $this->svc->setStatus($this->rootTeam, $this->order->id, ProductionOrderStatus::InProgress);
    expect($this->kap->auslastung($this->rootTeam, '2026-08-01', '2026-08-31'))->not->toBe([]);

    $this->svc->setStatus($this->rootTeam, $this->order->id, ProductionOrderStatus::Done, ['finish_note' => 'Kapazitätstest abgeschlossen.']);
    expect($this->kap->auslastung($this->rootTeam, '2026-08-01', '2026-08-31'))->toBe([]);
});

it('Auslastung ist team-strikt — der Eltern-Betrieb blockiert die Posten des Kind-Betriebs nicht', function () {
    $p = ($this->posten)('Warme Küche', 480);
    $this->svc->assignLine($this->rootTeam, ($this->zeile)()->id, ['station_id' => $p->id]);

    // Kind-Team sieht das Rezept (Kette aufwärts), aber NICHT die Belegung des Eltern-Betriebs
    expect($this->kap->auslastung($this->childA, '2026-08-01', '2026-08-31'))->toBe([]);
});

it('Posten-Summen je Auftrag mit Bucket für Unverplantes', function () {
    $p = ($this->posten)('Warme Küche', 480);
    $this->svc->addManualLine($this->rootTeam, $this->order->id, ['titel' => 'Brot holen', 'arbeitszeit_min' => 30]);
    $this->svc->assignLine($this->rootTeam, ($this->zeile)()->id, ['station_id' => $p->id]);

    $summen = collect($this->svc->postenSummen($this->rootTeam, $this->order->id));

    expect($summen->firstWhere('station_id', $p->id)['arbeitszeit_min'])->toBe(240)
        ->and($summen->firstWhere('station_id', null)['station'])->toBe('Nicht zugeteilt')
        ->and($summen->firstWhere('station_id', null)['arbeitszeit_min'])->toBe(30)
        ->and($summen->last()['station_id'])->toBeNull();   // Unverplantes steht hinten
});
