<?php

use Platform\FoodAlchemist\Models\FoodAlchemistPrice;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplier;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem;
use Platform\FoodAlchemist\Services\DataQualityService;
use Platform\FoodAlchemist\Services\PriceService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Eigener Metrik-Zugriff statt des `metrik()`-Helfers aus `DataQualityTest`: eine
 * dateiübergreifend definierte Funktion existiert nur, solange die andere Datei
 * mitgeladen wird — mit `--filter` auf diese Datei wäre der Test sonst rot aus dem
 * falschen Grund.
 */
function leadPreisMetrik(array $ebenen): int
{
    foreach ($ebenen as $ebene) {
        foreach ($ebene['metriken'] as $m) {
            if ($m['key'] === 'gp_lead_ohne_preis') {
                return (int) $m['wert'];
            }
        }
    }
    throw new RuntimeException('Metrik gp_lead_ohne_preis nicht gefunden');
}

/**
 * Spec 22 · H2b — V-053: die Ampel liest die Preis-Wahrheit des Money-Paths.
 *
 * Golden-Riegel VOR dem Umbau (Bau-Rahmen: „kein Verhaltenswechsel ohne Golden-Test"),
 * denn `gp_lead_ohne_preis` ist eine **Auswahl-Regel im Money-Path-Umfeld** — die
 * Fehlerklasse ist die stille Verschiebung, nicht der Crash. Die Tabelle unten hält
 * jede Preis-Lage einzeln fest, damit nach dem Tausch auf `PriceService::scopeAktiv`
 * belegbar ist, **welche** Lagen sich bewegen und welche byte-identisch bleiben.
 *
 * Was hier ausdrücklich mitgeriegelt wird:
 *  1. Die zwei Fassungen (Zähl-Query + `betroffene()`) bleiben deckungsgleich — sie
 *     benutzen dieselbe Closure, und genau das ist der Sinn der einen Regel-Stelle.
 *  2. Die `valid_to`-Entscheidung ist eine **Zusicherung**, kein Zufall: eine abgelaufene
 *     Zeile bleibt ein Preis, weil `PriceService::activeFor` (der Money-Path) sie
 *     ebenfalls auflöst. Filterte die Ampel `valid_to`, wäre sie eine *dritte* Wahrheit.
 *  3. `price = 0` bleibt eine Lücke, obwohl `scopeAktiv` `>= 0` zulässt — bewusste,
 *     dokumentierte Abweichung (GL-11 / Spec 13 S1b: 0,00 € ist kein Preis).
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->dq = app(DataQualityService::class);
    $this->prices = app(PriceService::class);

    $this->supplier = FoodAlchemistSupplier::create([
        'team_id' => $this->rootTeam->id, 'name' => 'Preis-Lieferant',
    ]);

    // ID-Versatz zwischen Artikel und GP, damit die EXISTS-Korrelation überhaupt
    // prüfbar ist: in einer frischen Test-DB starten beide Auto-Increments bei 1,
    // eine auf `gps.id` verbogene Korrelation träfe dieselben Zeilen und der Riegel
    // wäre blind (Mutations-Gegenprobe M4 belegte genau das). Diese Artikel bleiben
    // ohne GP und ohne Preis, kosten also keine Metrik.
    for ($i = 0; $i < 7; $i++) {
        FoodAlchemistSupplierItem::create([
            'team_id' => $this->rootTeam->id, 'supplier_id' => $this->supplier->id,
            'designation' => 'ID-Versatz '.$i, 'unit_code' => 'kg',
        ]);
    }

    /**
     * approved GP mit Lead-LA und genau EINER Preiszeile in der übergebenen Lage.
     * Ein GP je Fall, damit „Lücke oder nicht" pro Lage einzeln ablesbar ist.
     */
    $this->mkFall = function (string $name, array $preis, bool $mitPreiszeile = true) {
        $gp = $this->makeGp($this->rootTeam, $name);
        $la = FoodAlchemistSupplierItem::create([
            'team_id' => $this->rootTeam->id, 'supplier_id' => $this->supplier->id,
            'designation' => $name, 'unit_code' => 'kg',
        ]);
        if ($mitPreiszeile) {
            $weichGeloescht = (bool) ($preis['__soft_deleted'] ?? false);
            unset($preis['__soft_deleted']);
            $p = FoodAlchemistPrice::create(array_merge([
                'team_id' => $this->rootTeam->id, 'supplier_item_id' => $la->id,
                'is_blocked' => false, 'status' => '0', 'price' => 9.5,
            ], $preis));
            if ($weichGeloescht) {
                $p->delete();
            }
        }
        $gp->update(['status' => 'approved', 'requires_la' => true, 'lead_la_supplier_item_id' => $la->id]);

        return $gp->fresh();
    };

    /** Die Namen der GPs, die die Metrik heute als „Lead-LA ohne gültigen Preis" listet. */
    $this->luecken = function (): array {
        $namen = collect($this->dq->betroffene($this->rootTeam, 'gp_lead_ohne_preis', 100))
            ->pluck('name')->sort()->values()->all();

        return $namen;
    };
});

// ── Der Freeze: die Lagen, die sich NICHT bewegen dürfen ─────────────────────

it('friert die unstrittigen Preis-Lagen ein (Standard, Aktion, gesperrt, gelöscht, ohne Zeile)', function () {
    ($this->mkFall)('Standard-Preis', ['status' => '0', 'price' => 9.5]);
    ($this->mkFall)('Aktions-Preis', ['status' => '2', 'price' => 8.0]);
    ($this->mkFall)('Gesperrt', ['is_blocked' => true]);
    ($this->mkFall)('Weich geloescht', ['__soft_deleted' => true]);
    ($this->mkFall)('Gar keine Preiszeile', [], false);

    // Standard + Aktion sind versorgt; die drei übrigen sind Lücken — vor UND nach dem Umbau.
    expect(($this->luecken)())->toBe(['Gar keine Preiszeile', 'Gesperrt', 'Weich geloescht']);
});

it('hält Zähl-Query und betroffene() deckungsgleich (eine Regel-Stelle, kein Drift)', function () {
    ($this->mkFall)('Versorgt', ['status' => '0']);
    ($this->mkFall)('Gesperrt', ['is_blocked' => true]);
    ($this->mkFall)('Ohne Zeile', [], false);

    $wert = leadPreisMetrik($this->dq->messeAlleEbenen($this->rootTeam));

    expect($wert)->toBe(2)
        ->and(count(($this->luecken)()))->toBe($wert);
});

// ── Die Zusicherungen, die der Umbau NEU gibt ───────────────────────────────

it('zählt statusfremde Preiszeilen als Lücke — GL-11 T1: nur 0 (Standard) und 2 (Aktion) sind aktiv', function () {
    ($this->mkFall)('Status 0 Standard', ['status' => '0']);
    ($this->mkFall)('Status 1 fremd', ['status' => '1']);
    ($this->mkFall)('Status 2 Aktion', ['status' => '2']);
    ($this->mkFall)('Status 3 fremd', ['status' => '3']);
    ($this->mkFall)('Status leer', ['status' => null]);

    // Genau die Verschiebung, um die es V-053 geht: der Money-Path (scopeAktiv) findet
    // für die drei statusfremden Zeilen keinen Preis — die Ampel sagt es jetzt auch.
    expect(($this->luecken)())->toBe(['Status 1 fremd', 'Status 3 fremd', 'Status leer']);
});

it('behandelt price NULL und price 0 als Lücke, obwohl scopeAktiv >= 0 zulaesst (GL-11 / S1b)', function () {
    ($this->mkFall)('Preis NULL', ['price' => null]);
    ($this->mkFall)('Preis exakt null', ['price' => 0]);
    ($this->mkFall)('Preis positiv', ['price' => 0.01]);

    expect(($this->luecken)())->toBe(['Preis NULL', 'Preis exakt null']);
});

it('laesst eine abgelaufene Preiszeile gelten — dieselbe Lesart wie PriceService::activeFor', function () {
    $gp = ($this->mkFall)('Nur abgelaufen', ['valid_to' => now()->subYear(), 'price' => 7.25]);

    // 1. Die Ampel meldet KEINE Lücke …
    expect(($this->luecken)())->toBe([]);

    // 2. … und das ist keine Schlamperei, sondern Deckung mit dem Money-Path:
    //    activeFor löst dieselbe abgelaufene Zeile als aktiven Preis auf.
    $aufgeloest = $this->prices->activeFor((int) $gp->lead_la_supplier_item_id);
    expect($aufgeloest)->not->toBeNull()
        ->and((float) $aufgeloest->price)->toBe(7.25);
});
