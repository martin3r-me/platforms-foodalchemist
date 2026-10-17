<?php

use Illuminate\Support\Facades\DB;
use Platform\FoodAlchemist\Models\FoodAlchemistPrice;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplier;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItemStructure;
use Platform\FoodAlchemist\Services\MatchService;
use Platform\FoodAlchemist\Services\PriceService;
use Platform\FoodAlchemist\Services\SupplierItemService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 84 E-2 PR A · PriceService::detectAnomalies ohne N+1 und ohne Vollladen; MatchService::exactBruecken nur für die
 * EANs/Artikelnummern der geprüften Items. Ergebnis bis zur Grenze identisch mit dem bisherigen Verfahren (Referenz unten
 * nachgebaut), Abfragezahl unabhängig von der Artikelzahl.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->preise = app(PriceService::class);
    $this->artikel = app(SupplierItemService::class);
    $this->lief = FoodAlchemistSupplier::create(['team_id' => $this->rootTeam->id, 'name' => 'Großhandel E2']);

    // n Artikel in zwei Warengruppen, je 1–3 Preisgenerationen mit Sprüngen, ein paar Ausreißer
    $this->baue = function (int $n): void {
        $gpA = $this->makeGp($this->rootTeam, 'E2 Tomate '.uniqid());
        $gpA->update(['commodity_group_code' => '01']);
        $gpB = $this->makeGp($this->rootTeam, 'E2 Öl '.uniqid());
        $gpB->update(['commodity_group_code' => '07']);
        for ($k = 0; $k < $n; $k++) {
            $einheit = $k % 3 === 0 ? 'l' : 'kg';
            $item = $this->artikel->create($this->rootTeam, $this->lief->id, ['designation' => "E2 Artikel {$k}", 'qty' => '1', 'unit_code' => $einheit]);
            FoodAlchemistSupplierItemStructure::create(['team_id' => $this->rootTeam->id, 'supplier_item_id' => $item->id, 'gp_id' => ($k % 2 ? $gpA : $gpB)->id]);
            $basis = 2.0 + ($k % 7) * 0.3 + ($k % 11 === 0 ? 40.0 : 0.0);   // jeder 11. ein Ausreißer
            foreach (array_slice([1.0, $k % 4 === 0 ? 1.9 : 1.1, $k % 5 === 0 ? 0.5 : 1.05], 0, 1 + $k % 3) as $faktor) {
                $this->preise->createFor($this->rootTeam, $item, round($basis * $faktor, 2));
            }
        }
    };

    // Referenz = das bisherige Verfahren (historyFor je Artikel, alle Kandidaten im RAM), sortiert wie neu
    $this->referenz = function ($team, float $sprungPct = 30.0, float $faktorGrenze = 4.0): array {
        $mehrfach = FoodAlchemistPrice::query()->whereIn('supplier_item_id', FoodAlchemistSupplierItem::visibleToTeam($team)->select('id'))
            ->selectRaw('supplier_item_id, COUNT(*) AS n')->groupBy('supplier_item_id')->having('n', '>', 1)->pluck('supplier_item_id');
        $spruenge = [];
        foreach ($mehrfach as $itemId) {
            $z = $this->preise->historyFor($itemId, $team)->filter(fn ($p) => $p->price !== null && (float) $p->price > 0)->values();
            for ($i = 0; $i < $z->count() - 1; $i++) {
                $neu = (float) $z[$i]->price;
                $alt = (float) $z[$i + 1]->price;
                $pct = abs($neu - $alt) / $alt * 100;
                if ($pct > $sprungPct) {
                    $spruenge[] = ['supplier_item_id' => (int) $itemId, 'von' => $alt, 'nach' => $neu, 'sprung_pct' => round($pct, 1)];
                }
            }
        }
        usort($spruenge, fn ($a, $b) => [$b['sprung_pct'], $a['supplier_item_id'], $b['nach']] <=> [$a['sprung_pct'], $b['supplier_item_id'], $a['nach']]);

        return $spruenge;
    };
    $this->alsArray = fn ($c) => collect($c)->map(fn ($o) => (array) $o)->values()->all();
});

it('Spec 84 E-2 · Sprünge: identisch mit dem bisherigen Verfahren (Referenz)', function () {
    ($this->baue)(40);
    $neu = $this->preise->detectAnomalies($this->rootTeam);

    expect(($this->alsArray)($neu['spruenge']))->toBe(($this->referenz)($this->rootTeam))
        ->and($neu['spruenge_gesamt'])->toBe(count(($this->referenz)($this->rootTeam)))
        ->and($neu['spruenge_gesamt'])->toBeGreaterThan(5);
});

it('Spec 84 E-2 · Grenze: die größten N, Gesamtzahl kommt mit', function () {
    ($this->baue)(40);
    $alle = $this->preise->detectAnomalies($this->rootTeam);
    $drei = $this->preise->detectAnomalies($this->rootTeam, grenze: 3);

    expect(($this->alsArray)($drei['spruenge']))->toBe(array_slice(($this->alsArray)($alle['spruenge']), 0, 3))
        ->and($drei['spruenge_gesamt'])->toBe($alle['spruenge_gesamt'])
        ->and($drei['ausreisser'])->toHaveCount(min(3, $alle['ausreisser_gesamt']))
        ->and($drei['ausreisser_gesamt'])->toBe($alle['ausreisser_gesamt'])
        ->and($alle['ausreisser_gesamt'])->toBeGreaterThan(0);
});

it('Spec 84 E-2 · Abfragezahl: konstant statt eine je Artikel (40 vs. 120 Artikel)', function () {
    $messe = function (int $n): int {
        ($this->baue)($n);
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->preise->detectAnomalies($this->rootTeam);
        $anzahl = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $anzahl;
    };
    $klein = $messe(40);
    $gross = $messe(80);   // insgesamt 120 Artikel

    expect($gross)->toBe($klein)->and($klein)->toBeLessThan(15);
});

it('Spec 84 E-2 · Fensterfreie Sprung-Abfrage läuft auf dieser Datenbank wirklich (Smoke)', function () {
    ($this->baue)(5);
    expect(fn () => $this->preise->detectAnomalies($this->rootTeam))->not->toThrow(\Throwable::class);
});

it('Spec 84 E-2 · exactBruecken: dieselben Brücken wie aus allen Artikeln, Mehrdeutigkeit bleibt', function () {
    $gp1 = $this->makeGp($this->rootTeam, 'Brücke Eins');
    $gp2 = $this->makeGp($this->rootTeam, 'Brücke Zwei');
    $mk = function (array $a, ?int $gp) {
        $i = FoodAlchemistSupplierItem::create($a + ['team_id' => $this->rootTeam->id, 'supplier_id' => $this->lief->id, 'designation' => 'X '.uniqid()]);
        if ($gp !== null) {
            FoodAlchemistSupplierItemStructure::create(['team_id' => $this->rootTeam->id, 'supplier_item_id' => $i->id, 'gp_id' => $gp]);
        }

        return $i;
    };
    $mk(['ean_packaging' => '400001'], $gp1->id);                       // eindeutig
    $mk(['ean_packaging' => '400002'], $gp1->id);                       // mehrdeutig …
    $mk(['ean_ordering' => '400002'], $gp2->id);                        // … über die zweite Spalte
    $mk(['article_number' => 'A-77'], $gp2->id);                        // Artikelnummer
    for ($k = 0; $k < 30; $k++) {
        $mk(['ean_packaging' => '9'.str_pad((string) $k, 5, '0', STR_PAD_LEFT)], $gp1->id);   // Rauschen
    }
    $offen = collect([$mk(['ean_packaging' => '400001'], null), $mk(['ean_ordering' => '400002'], null), $mk(['article_number' => 'A-77'], null)]);

    $svc = app(MatchService::class);
    $m = new ReflectionMethod($svc, 'exactBruecken');
    [$ean, $artno] = $m->invoke($svc, $this->rootTeam, $this->lief->id, $offen);

    expect($ean)->toBe(['400001' => $gp1->id])                         // 400002 mehrdeutig → keine Brücke
        ->and($artno)->toBe(['A-77' => $gp2->id])
        ->and(count($ean))->toBeLessThan(5);                            // das Rauschen wird nicht geladen
});
