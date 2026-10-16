<?php

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Platform\FoodAlchemist\Livewire\Preise\Verlauf;
use Platform\FoodAlchemist\Livewire\Suppliers\Index;
use Platform\FoodAlchemist\Livewire\Suppliers\ItemModal;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplier;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem;
use Platform\FoodAlchemist\Services\PreisVerlaufService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;
use Symfony\Component\Uid\UuidV7;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Lieferstatus aus Necta sichtbar machen: „Eingestellt seit 14.10.2026“ / „Läuft aus seit …“ in Liste, Artikel-Modal und Preisverlauf
 * (die Linie endet am Tag der Einstellung, mit Markierung). `lieferstatus_seit` liegt in UTC, angezeigt wird Europe/Berlin.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    Carbon::setTestNow('2026-10-20 12:00:00');
    PreisVerlaufService::flush();
    $this->actingAs($this->makeUser($this->rootTeam, 'Einkauf'));
    $this->sup = FoodAlchemistSupplier::create(['team_id' => $this->rootTeam->id, 'name' => 'Kluth']);
    $this->la = fn (string $d, ?string $status = null, ?string $seit = null) => FoodAlchemistSupplierItem::create([
        'team_id' => $this->rootTeam->id, 'supplier_id' => $this->sup->id, 'designation' => $d, 'qty' => 1, 'unit_code' => 'kg',
        'lieferstatus' => $status, 'lieferstatus_seit' => $seit, 'lieferstatus_quelle' => $status === null ? null : 'necta']);
    $this->preis = fn (FoodAlchemistSupplierItem $la, float $p, string $ab, ?string $bis = null) => DB::table('foodalchemist_prices')->insert([
        'uuid' => (string) UuidV7::generate(), 'team_id' => $la->team_id, 'supplier_item_id' => $la->id, 'status' => '0', 'price' => $p,
        'status_valid_from' => $ab, 'valid_to' => $bis, 'is_blocked' => 0, 'created_at' => now(), 'updated_at' => now()]);
});

afterEach(fn () => Carbon::setTestNow());

it('lieferstatusInfo: Datum in Europe/Berlin (UTC-Mitternacht vom Vortag), gelistet = null', function () {
    $e = ($this->la)('Kluth Eingestellt', 'eingestellt', '2026-10-13 22:00:00')->lieferstatusInfo();   // 14.10. 00:00 Berlin
    $a = ($this->la)('Kluth Läuft aus', 'auslaufend', '2026-10-01 10:00:00')->lieferstatusInfo();

    expect($e['text'])->toBe('Eingestellt seit 14.10.2026')->and($e['kurz'])->toBe('Eingestellt seit 14.10.')->and($e['status'])->toBe('eingestellt')
        ->and($a['text'])->toBe('Läuft aus seit 01.10.2026')
        ->and(($this->la)('Gelistet')->lieferstatusInfo())->toBeNull()
        ->and(($this->la)('Ohne Datum', 'eingestellt')->lieferstatusInfo()['text'])->toBe('Eingestellt');
});

it('Liste: das Badge nennt das Datum, Tooltip erklärt; Gegenprobe gelistet zeigt Lieferbar', function () {
    ($this->la)('Hecht', 'eingestellt', '2026-10-13 22:00:00');
    ($this->la)('Zander', 'auslaufend', '2026-10-01 10:00:00');
    ($this->la)('Lachs');

    $html = Livewire::withQueryParams(['lieferant' => $this->sup->id, 'zeilen' => 100])->test(Index::class, [])->set('onlyActive', false)->html();

    expect($html)->toContain('Eingestellt seit 14.10.')->toContain('data-lieferstatus="eingestellt"')
        ->toContain('Läuft aus seit 01.10.')->toContain('data-lieferstatus="auslaufend"')->toContain('Lieferbar');
});

it('Artikel-Modal: Hinweis mit Datum bei eingestellt und läuft aus, keiner bei gelisteten Artikeln', function () {
    $e = ($this->la)('Hecht', 'eingestellt', '2026-10-13 22:00:00');
    $a = ($this->la)('Zander', 'auslaufend', '2026-10-01 10:00:00');
    $g = ($this->la)('Lachs');

    $h = fn ($la) => Livewire::test(ItemModal::class)->call('oeffnen', $la->id)->html();

    expect($h($e))->toContain('data-lieferstatus-hinweis="eingestellt"')->toContain('Eingestellt seit 14.10.2026')->toContain('nicht bestellbar')
        ->and($h($a))->toContain('data-lieferstatus-hinweis="auslaufend"')->toContain('Läuft aus seit 01.10.2026')
        ->and($h($g))->not->toContain('data-lieferstatus-hinweis');
});

it('Preisverlauf: bei „eingestellt“ endet die Linie am Einstellungstag (auch bei offenem Stand), Markierung mit Datum; läuft aus markiert, kappt nicht', function () {
    $e = ($this->la)('Hecht', 'eingestellt', '2026-05-31 22:00:00');   // 01.06. Berlin
    $a = ($this->la)('Zander', 'auslaufend', '2026-05-31 22:00:00');
    $g = ($this->la)('Lachs');
    foreach ([$e, $a, $g] as $la) {
        ($this->preis)($la, 5.0, '2026-01-01', '2026-03-01');
        ($this->preis)($la, 6.0, '2026-03-01');                          // offen
    }
    $svc = app(PreisVerlaufService::class);
    [$von, $bis] = $svc->fenster('alles');

    $r = $svc->verlauf($this->rootTeam, [$e->id, $a->id, $g->id], $von, $bis);
    $linie = fn ($id) => collect($r['linien'])->firstWhere('la_id', $id);

    expect(end($linie($e->id)['segmente'])['bis'])->toBeLessThanOrEqual('2026-06-01')                // eingestellt: Ende am 01.06.
        ->and(end($linie($a->id)['segmente'])['bis'])->toBe('2026-10-20')                             // läuft aus: Linie läuft weiter
        ->and(end($linie($g->id)['segmente'])['bis'])->toBe('2026-10-20')
        ->and($linie($e->id)['lieferstatus']['kurz'])->toBe('Eingestellt seit 01.06.')->and($linie($g->id)['lieferstatus'])->toBeNull();

    $html = Livewire::test(Verlauf::class, ['modus' => 'la', 'objektId' => $e->id])->html();
    expect($html)->toContain('data-preisverlauf-marker="eingestellt"')->toContain('data-preisverlauf-marker-label')->toContain('Eingestellt seit 01.06.')
        ->toContain('data-legende-lieferstatus');
    expect(Livewire::test(Verlauf::class, ['modus' => 'la', 'objektId' => $a->id])->html())->toContain('data-preisverlauf-marker="auslaufend"')->toContain('Läuft aus seit 01.06.');
    expect(Livewire::test(Verlauf::class, ['modus' => 'la', 'objektId' => $g->id])->html())->not->toContain('data-preisverlauf-marker="')->not->toContain('data-preisverlauf-marker-label>')->not->toContain('data-legende-lieferstatus');
});
