<?php

use Illuminate\Support\Facades\DB;
use Platform\FoodAlchemist\Exceptions\FaRechtFehltException;
use Platform\FoodAlchemist\Exceptions\NectaPruefallException;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplier;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem;
use Platform\FoodAlchemist\Services\Necta\NectaAbgleichService;
use Platform\FoodAlchemist\Services\Necta\NectaLieferstatusService;
use Platform\FoodAlchemist\Services\Necta\NectaPruefallService;
use Platform\FoodAlchemist\Services\Necta\NectaQuelle;
use Platform\FoodAlchemist\Support\StammPaket;
use Platform\FoodAlchemist\Tests\Support\NectaAttrappe;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;
use Symfony\Component\Uid\UuidV7;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Necta-Prüffälle entscheiden (PR 3, Kurator 18.10.): Necta übernehmen / FA behalten, „nicht wieder fragen“, Produktpass- und
 * Veraltet-Schutz, Sammel-Entscheidung mit Vorschau-Schlüssel und Admin-Pflicht bei Allergen-Lockerungen, Eingestellt-Widerspruch.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    config(['foodalchemist.master_team_id' => $this->rootTeam->id]);
    $this->admin = $this->makeUser($this->rootTeam, 'Admin M', 'owner');
    $this->kurator = $this->makeUser($this->rootTeam, 'Kurator M', 'member');
    $this->svc = app(NectaPruefallService::class);

    $this->lieferant = FoodAlchemistSupplier::create(['team_id' => null, 'legacy_id' => 19491, 'name' => 'Bäckerei', 'stamm_class' => StammPaket::PAKET]);
    $this->artikel = fn (int $legacy, string $name) => FoodAlchemistSupplierItem::create(['team_id' => null, 'legacy_id' => $legacy,
        'supplier_id' => $this->lieferant->id, 'designation' => $name, 'stamm_class' => StammPaket::PAKET]);
    $this->a = ($this->artikel)(36674788, 'Schoko Splitterbrötchen');
    $this->b = ($this->artikel)(36674790, 'Rosinenschnecke');
    $this->allergene = fn (FoodAlchemistSupplierItem $i, array $w) => DB::table('foodalchemist_item_allergens')->insert(['uuid' => (string) UuidV7::generate(),
        'team_id' => null, 'legacy_id' => $i->legacy_id, 'supplier_item_id' => $i->id, 'created_at' => now(), 'updated_at' => now()] + $w);
    $this->fall = fn (string $art, string $objekt, int $legacy, ?string $feld, ?string $fa, ?string $necta) => DB::table('foodalchemist_necta_pruefaelle')->insertGetId([
        'uuid' => (string) UuidV7::generate(), 'art' => $art, 'objekt' => $objekt, 'legacy_id' => $legacy, 'feld' => $feld,
        'fa_wert' => $fa, 'necta_wert' => $necta, 'status' => 'offen', 'created_at' => now(), 'updated_at' => now()]);
    $this->milch = fn (FoodAlchemistSupplierItem $i) => DB::table('foodalchemist_item_allergens')->where('supplier_item_id', $i->id)->value('allergen_milk');
});

it('Necta übernehmen: Wert eingetragen, Necta-Stand gesetzt, Fall übernommen mit Person und Zeit', function () {
    ($this->allergene)($this->a, ['allergen_milk' => 'enthalten']);
    $id = ($this->fall)('allergen_aenderung', 'item_allergen', 36674788, 'allergen_milk', 'enthalten', 'nicht_enthalten');

    $r = $this->svc->entscheiden($this->rootTeam, [$id], [], 'necta_uebernehmen', $this->kurator->id);

    $fall = DB::table('foodalchemist_necta_pruefaelle')->find($id);
    expect($r['erledigt'])->toBe(1)->and(($this->milch)($this->a))->toBe('nicht_enthalten')
        ->and($fall->status)->toBe('uebernommen')->and((int) $fall->erledigt_von)->toBe($this->kurator->id)->and($fall->erledigt_at)->not->toBeNull()
        ->and(json_decode(DB::table('foodalchemist_necta_stand')->where('objekt', 'item_allergen')->where('legacy_id', 36674788)->value('werte'), true)['allergen_milk'])->toBe('nicht_enthalten');
});

it('★ FA behalten: der nächste Vollabgleich meldet denselben Necta-Wert nicht wieder, einen NEUEN schon', function () {
    ($this->allergene)($this->a, ['allergen_milk' => 'enthalten']);
    $daten = fn (int $milch) => new NectaAttrappe([
        'suppliers' => [['id' => 19491, 'name' => 'Bäckerei']],
        'supplier-items' => [NectaAttrappe::artikel(36674788, 19491, 'Schoko Splitterbrötchen'), NectaAttrappe::artikel(36674790, 19491, 'Rosinenschnecke')],
        'supplier-item-prices' => [], 'supplier-item-allergens' => [['supplierItemId' => 36674788, 'milk' => $milch]],
        'supplier-item-nutritional-values' => [], 'supplier-item-declaration-substances' => [],
    ], [36674788 => 19491, 36674790 => 19491]);
    $lauf = function (int $milch) use ($daten) {
        $this->app->instance(NectaQuelle::class, $daten($milch));
        $this->travel(5)->minutes();
        app(NectaAbgleichService::class)->lauf(1, ['pause_ms' => 0]);
        $this->travel(5)->minutes();

        return app(NectaAbgleichService::class)->lauf(1, ['voll' => true, 'pause_ms' => 0]);
    };
    $offen = fn () => DB::table('foodalchemist_necta_pruefaelle')->where('art', 'allergen_aenderung')->where('feld', 'allergen_milk')->where('status', 'offen');

    $lauf(1);                                                     // Necta: nicht_enthalten → lockerer → Prüffall
    $id = $offen()->value('id');
    $this->svc->entscheiden($this->rootTeam, [$id], [], 'fa_behalten', $this->kurator->id);
    $r = $lauf(1);
    expect($offen()->count())->toBe(0)->and(($this->milch)($this->a))->toBe('enthalten')
        ->and($r['zaehler']['pruefaelle_unterdrueckt'] ?? 0)->toBe(1);

    $lauf(2);                                                     // neuer Necta-Wert (spuren) → wieder fragen
    expect($offen()->value('necta_wert'))->toBe('spuren');
});

it('Produktpass-Feld wird nicht übernommen, veralteter Fall wird übersprungen — der Rest des Stapels läuft', function () {
    ($this->allergene)($this->a, ['allergen_milk' => 'enthalten']);
    ($this->allergene)($this->b, ['allergen_milk' => 'spuren']);   // FA inzwischen anders als im Prüffall (enthalten)
    $pass = \Platform\FoodAlchemist\Models\FoodAlchemistProduktpass::create(['team_id' => $this->rootTeam->id, 'supplier_item_id' => $this->a->id,
        'version' => 1, 'status' => 'gelesen', 'quelle' => \Platform\FoodAlchemist\Support\ProduktpassFelder::QUELLE_PASS, 'extrakt' => []]);
    \Platform\FoodAlchemist\Models\FoodAlchemistProduktpassFeld::create(['supplier_item_id' => $this->a->id, 'produktpass_id' => $pass->id,
        'objekt' => 'item_allergen', 'feld' => 'allergen_milk', 'wert_norm' => 'enthalten', 'quelle' => 'produktpass_ki']);
    \Platform\FoodAlchemist\Support\ProduktpassSchutz::zuruecksetzen();
    $f1 = ($this->fall)('allergen_aenderung', 'item_allergen', 36674788, 'allergen_milk', 'enthalten', 'nicht_enthalten');
    $f2 = ($this->fall)('allergen_aenderung', 'item_allergen', 36674790, 'allergen_milk', 'enthalten', 'nicht_enthalten');
    $f3 = ($this->fall)('manuell_gepflegt', 'supplier_item', 36674790, 'brand', null, 'Hausbäcker');
    $v = $this->svc->vorschau($this->rootTeam, [$f1, $f2, $f3], [], 'necta_uebernehmen');

    $r = $this->svc->entscheiden($this->rootTeam, [$f1, $f2, $f3], [], 'necta_uebernehmen', $this->admin->id, $v['schluessel']);

    expect($r['erledigt'])->toBe(1)->and(array_keys($r['uebersprungen']))->toBe([$f1, $f2])
        ->and($r['uebersprungen'][$f1])->toContain('Produktpass')->and($r['uebersprungen'][$f2])->toContain('Veraltet')
        ->and(($this->milch)($this->a))->toBe('enthalten')->and(($this->milch)($this->b))->toBe('spuren')
        ->and($this->b->fresh()->brand)->toBe('Hausbäcker')
        ->and(DB::table('foodalchemist_necta_pruefaelle')->whereIn('id', [$f1, $f2])->pluck('status')->unique()->all())->toBe(['offen']);
});

it('★ Sammel-Entscheidung: Vorschau zeigt Anzahl und Allergen-Liste; falscher Schlüssel bricht ab; Allergen-Lockerung nur Admin', function () {
    ($this->allergene)($this->a, ['allergen_milk' => 'enthalten']);
    ($this->allergene)($this->b, ['allergen_milk' => 'enthalten']);
    ($this->fall)('allergen_aenderung', 'item_allergen', 36674788, 'allergen_milk', 'enthalten', 'nicht_enthalten');
    ($this->fall)('allergen_aenderung', 'item_allergen', 36674790, 'allergen_milk', 'enthalten', 'nicht_enthalten');
    $filter = ['lieferant' => $this->lieferant->id, 'art' => 'allergen_aenderung'];

    $v = $this->svc->vorschau($this->rootTeam, null, $filter, 'necta_uebernehmen');
    expect($v['anzahl'])->toBe(2)->and($v['admin_noetig'])->toBeTrue()
        ->and($v['allergene'])->toHaveCount(1)->and($v['allergene'][0])->toMatchArray(['feld' => 'allergen_milk', 'von' => 'enthalten', 'nach' => 'nicht_enthalten', 'n' => 2]);

    expect(fn () => $this->svc->entscheiden($this->rootTeam, null, $filter, 'necta_uebernehmen', $this->admin->id, 'falsch'))
        ->toThrow(NectaPruefallException::class);
    expect(fn () => $this->svc->entscheiden($this->rootTeam, null, $filter, 'necta_uebernehmen', $this->kurator->id, $v['schluessel']))
        ->toThrow(FaRechtFehltException::class);
    expect(($this->milch)($this->a))->toBe('enthalten');

    // FA behalten im Sammel darf auch der Kurator (verschärft nichts)
    $vb = $this->svc->vorschau($this->rootTeam, null, $filter, 'fa_behalten');
    expect($vb['admin_noetig'])->toBeFalse();

    $r = $this->svc->entscheiden($this->rootTeam, null, $filter, 'necta_uebernehmen', $this->admin->id, $v['schluessel']);
    expect($r['erledigt'])->toBe(2)->and(($this->milch)($this->a))->toBe('nicht_enthalten')->and(($this->milch)($this->b))->toBe('nicht_enthalten');
});

it('Nur das Master-Team: Mandant sieht und entscheidet nichts', function () {
    $id = ($this->fall)('manuell_gepflegt', 'supplier_item', 36674788, 'brand', null, 'X');
    expect(fn () => $this->svc->liste($this->childA))->toThrow(NectaPruefallException::class);
    expect(fn () => $this->svc->entscheiden($this->childA, [$id], [], 'fa_behalten', $this->admin->id))->toThrow(NectaPruefallException::class);
});

it('Liste: FA damals/jetzt/Necta nebeneinander, Filter je Art/Lieferant, Zähler', function () {
    ($this->allergene)($this->a, ['allergen_milk' => 'spuren']);
    ($this->fall)('allergen_aenderung', 'item_allergen', 36674788, 'allergen_milk', 'enthalten', 'nicht_enthalten');
    ($this->fall)('manuell_gepflegt', 'supplier_item', 36674790, 'brand', null, 'X');

    $l = $this->svc->liste($this->rootTeam, ['art' => 'allergen_aenderung']);
    $z = $this->svc->zaehler($this->rootTeam);
    expect($l['gesamt'])->toBe(1)->and($l['faelle'][0])->toMatchArray(['artikel' => 'Schoko Splitterbrötchen', 'lieferant' => 'Bäckerei',
        'fa_damals' => 'enthalten', 'fa_jetzt' => 'spuren', 'necta' => 'nicht_enthalten', 'veraltet' => true, 'kritisch' => true])
        ->and($z['je_art'])->toBe(['allergen_aenderung' => 1, 'manuell_gepflegt' => 1])
        ->and($z['je_lieferant'][0])->toMatchArray(['name' => 'Bäckerei', 'n' => 2]);
});

// ── Eingestellt-Widerspruch (F2: Quelle pruefung, neuer Normalpreis → neuer Prüffall) ──

it('★ als eingestellt bestätigen: Status eingestellt mit Quelle pruefung, Preise begrenzt; neuer Normalpreis danach → neuer Prüffall', function () {
    $preis = fn (int $legacy, ?float $p, string $status, string $ab) => DB::table('foodalchemist_prices')->insertGetId([
        'uuid' => (string) UuidV7::generate(), 'team_id' => $this->rootTeam->id, 'legacy_id' => $legacy, 'supplier_item_id' => $this->a->id,
        'status' => $status, 'price' => $p, 'price_partial' => $p, 'status_valid_from' => $ab, 'is_blocked' => false, 'created_at' => now(), 'updated_at' => now()]);
    $alt = $preis(900, 1.00, '0', '2026-04-01 00:00:00');
    $preis(901, null, '2', '2026-10-10 00:00:00');
    $id = ($this->fall)('eingestellt_widerspruch', 'price', 901, 'status', 'Normalpreis in der Necta-Historie nach dem Eingestellt-Beginn', 'eingestellt seit 2026-10-10 00:00:00');

    $this->svc->entscheiden($this->rootTeam, [$id], [], 'eingestellt_bestaetigen', $this->kurator->id);

    $a = $this->a->fresh();
    expect($a->lieferstatus)->toBe('eingestellt')->and($a->lieferstatus_quelle)->toBe('pruefung')
        ->and(DB::table('foodalchemist_prices')->where('id', $alt)->value('valid_to'))->toBe('2026-10-10 00:00:00')
        ->and(DB::table('foodalchemist_necta_pruefaelle')->find($id)->status)->toBe('uebernommen');

    // Der nächste Lauf ändert den bestätigten Status nicht still — meldet aber einen NEUEN Normalpreis nach dem Datum
    $svc = app(NectaLieferstatusService::class);
    $svc->aktualisieren($this->a->id, $this->rootTeam->id);
    expect(DB::table('foodalchemist_necta_pruefaelle')->where('status', 'offen')->count())->toBe(0);
    $preis(902, 1.20, '0', '2026-10-20 00:00:00');
    $svc->aktualisieren($this->a->id, $this->rootTeam->id);
    $neu = DB::table('foodalchemist_necta_pruefaelle')->where('status', 'offen')->sole();
    expect($neu->art)->toBe('eingestellt_widerspruch')->and($neu->necta_wert)->toBe('Normalpreis ab 2026-10-20 00:00:00')
        ->and($this->a->fresh()->lieferstatus)->toBe('eingestellt');   // Status bleibt, bis jemand entscheidet

    // „aktiv lassen“ auf diesen Fall hebt den Status auf (Quelle bleibt pruefung)
    $this->svc->entscheiden($this->rootTeam, [$neu->id], [], 'aktiv_lassen', $this->kurator->id);
    expect($this->a->fresh()->lieferstatus)->toBeNull()->and($this->a->fresh()->lieferstatus_quelle)->toBe('pruefung');
});

it('aktiv lassen: kein Lieferstatus, dieselbe Eingestellt-Angabe fragt nicht wieder; Filter „Normalpreis jünger als N Tage“', function () {
    $this->travelTo(now()->setDate(2026, 10, 18));
    DB::table('foodalchemist_prices')->insert(['uuid' => (string) UuidV7::generate(), 'team_id' => $this->rootTeam->id, 'legacy_id' => 901,
        'supplier_item_id' => $this->a->id, 'status' => '2', 'price' => null, 'status_valid_from' => '2026-10-10 00:00:00', 'is_blocked' => false,
        'created_at' => now(), 'updated_at' => now()]);
    DB::table('foodalchemist_price_history')->insert(['uuid' => (string) UuidV7::generate(), 'necta_historie_id' => 1, 'supplier_item_id' => $this->a->id,
        'team_id' => $this->rootTeam->id, 'status' => '0', 'price' => 1.1, 'status_valid_from' => '2026-10-12 00:00:00', 'is_blocked' => false,
        'quelle' => 'necta_historie', 'created_at' => now(), 'updated_at' => now()]);
    $id = ($this->fall)('eingestellt_widerspruch', 'price', 901, 'status', 'Normalpreis in der Necta-Historie nach dem Eingestellt-Beginn', 'eingestellt seit 2026-10-10 00:00:00');

    $l = $this->svc->liste($this->rootTeam, ['art' => 'eingestellt_widerspruch', 'normalpreis_tage' => 90]);
    expect($l['gesamt'])->toBe(1)->and($l['faelle'][0]['letzter_normalpreis'])->toBe('2026-10-12 00:00:00')
        ->and($this->svc->liste($this->rootTeam, ['art' => 'eingestellt_widerspruch', 'normalpreis_tage' => 3])['gesamt'])->toBe(0);

    $this->svc->entscheiden($this->rootTeam, [$id], [], 'aktiv_lassen', $this->kurator->id);
    expect($this->a->fresh()->lieferstatus)->toBeNull()->and($this->a->fresh()->lieferstatus_quelle)->toBe('pruefung');

    app(NectaLieferstatusService::class)->aktualisieren($this->a->id, $this->rootTeam->id);
    expect(DB::table('foodalchemist_necta_pruefaelle')->where('status', 'offen')->count())->toBe(0);   // gleiche Angabe: nicht wieder
});

// ── Oberfläche + MCP ──

it('Livewire: Master sieht die Liste, entscheidet einzeln und gesammelt (mit Allergen-Liste); Lieferanten-Seite zeigt den Hinweis', function () {
    config(['foodalchemist.bearbeitungssperre' => false]);
    ($this->allergene)($this->a, ['allergen_milk' => 'enthalten']);
    ($this->allergene)($this->b, ['allergen_milk' => 'enthalten']);
    $f1 = ($this->fall)('allergen_aenderung', 'item_allergen', 36674788, 'allergen_milk', 'enthalten', 'nicht_enthalten');
    ($this->fall)('allergen_aenderung', 'item_allergen', 36674790, 'allergen_milk', 'enthalten', 'nicht_enthalten');
    $this->actingAs($this->admin);

    \Livewire\Livewire::test(\Platform\FoodAlchemist\Livewire\Suppliers\Index::class)->assertSeeHtml('data-necta-pruefaelle-hinweis');

    $c = \Livewire\Livewire::test(\Platform\FoodAlchemist\Livewire\Suppliers\NectaPruefaelle::class)
        ->assertSee('Schoko Splitterbrötchen')->assertSee('Rosinenschnecke')->assertSeeHtml('data-pruefall="'.$f1.'"')
        ->call('entscheide', $f1, 'fa_behalten')->assertSet('fehler', null);
    expect(DB::table('foodalchemist_necta_pruefaelle')->find($f1)->status)->toBe('verworfen');

    ($this->fall)('allergen_aenderung', 'item_allergen', 36674788, 'allergen_milk', 'enthalten', 'spuren');
    $c->set('art', 'allergen_aenderung')->set('lieferant', $this->lieferant->id)->assertSeeHtml('data-sammel-leiste')
        ->call('sammelPruefen', 'necta_uebernehmen')->assertSeeHtml('data-sammel-allergene')->assertSee('Milch')
        ->call('sammelBestaetigen')->assertSet('fehler', null);
    expect(($this->milch)($this->a))->toBe('spuren')->and(($this->milch)($this->b))->toBe('nicht_enthalten')
        ->and(DB::table('foodalchemist_necta_pruefaelle')->where('status', 'offen')->count())->toBe(0);
});

it('Livewire: Mandant kommt nicht auf die Seite und sieht keinen Hinweis', function () {
    ($this->fall)('manuell_gepflegt', 'supplier_item', 36674788, 'brand', null, 'X');
    $this->actingAs($this->makeUser($this->childA, 'Mandant'));

    \Livewire\Livewire::test(\Platform\FoodAlchemist\Livewire\Suppliers\NectaPruefaelle::class)->assertStatus(403);
    \Livewire\Livewire::test(\Platform\FoodAlchemist\Livewire\Suppliers\Index::class)->assertDontSeeHtml('data-necta-pruefaelle-hinweis');
});

it('MCP: GET listet für den Master, ENTSCHEIDEN zweistufig (Vorschau → Schlüssel → ausführen); Mandant ACCESS_DENIED', function () {
    ($this->allergene)($this->a, ['allergen_milk' => 'enthalten']);
    ($this->allergene)($this->b, ['allergen_milk' => 'enthalten']);
    ($this->fall)('allergen_aenderung', 'item_allergen', 36674788, 'allergen_milk', 'enthalten', 'nicht_enthalten');
    ($this->fall)('allergen_aenderung', 'item_allergen', 36674790, 'allergen_milk', 'enthalten', 'nicht_enthalten');
    $reg = app(\Platform\Core\Tools\ToolRegistry::class);
    $ctx = new \Platform\Core\Contracts\ToolContext($this->admin, $this->rootTeam);
    $ctxKurator = new \Platform\Core\Contracts\ToolContext($this->kurator, $this->rootTeam);

    $get = $reg->get('foodalchemist.necta_pruefaelle.GET')->execute(['art' => 'allergen_aenderung'], $ctx);
    expect($get->success)->toBeTrue()->and($get->data['gesamt'])->toBe(2)->and($get->data['zaehler']['je_art']['allergen_aenderung'])->toBe(2);

    $tool = $reg->get('foodalchemist.necta_pruefaelle.ENTSCHEIDEN');
    $args = ['aktion' => 'necta_uebernehmen', 'filter' => ['art' => 'allergen_aenderung', 'lieferant' => $this->lieferant->id]];
    $v = $tool->execute($args, $ctx);
    expect($v->success)->toBeTrue()->and($v->data['vorschau'])->toBeTrue()->and($v->data['anzahl'])->toBe(2)
        ->and(($this->milch)($this->a))->toBe('enthalten');                                                      // Vorschau schreibt nichts

    expect($tool->execute($args + ['bestaetigen' => true, 'schluessel' => 'falsch'], $ctx)->errorCode)->toBe('LISTE_GEAENDERT')
        ->and($tool->execute($args + ['bestaetigen' => true, 'schluessel' => $v->data['schluessel']], $ctxKurator)->errorCode)->toBe('FORBIDDEN');

    $ok = $tool->execute($args + ['bestaetigen' => true, 'schluessel' => $v->data['schluessel']], $ctx);
    expect($ok->success)->toBeTrue()->and($ok->data['erledigt'])->toBe(2)->and(($this->milch)($this->a))->toBe('nicht_enthalten');

    $mandant = new \Platform\Core\Contracts\ToolContext($this->makeUser($this->childA, 'Mandant'), $this->childA);
    expect($reg->get('foodalchemist.necta_pruefaelle.GET')->execute([], $mandant)->errorCode)->toBe('ACCESS_DENIED');
});

it('MySQL only_full_group_by (Hotfix nach #496): keine gruppierte Abfrage im Dienst wählt „*“ oder nicht gruppierte Spalten', function () {
    ($this->allergene)($this->a, ['allergen_milk' => 'enthalten']);
    ($this->fall)('allergen_aenderung', 'item_allergen', 36674788, 'allergen_milk', 'enthalten', 'nicht_enthalten');
    $sql = [];
    DB::listen(function ($q) use (&$sql) {
        if (stripos($q->sql, 'group by') !== false) {
            $sql[] = $q->sql;
        }
    });

    $this->svc->zaehler($this->rootTeam);
    $this->svc->zaehler($this->rootTeam, ['art' => 'allergen_aenderung', 'lieferant' => $this->lieferant->id]);
    $this->svc->liste($this->rootTeam, ['art' => 'eingestellt_widerspruch', 'normalpreis_tage' => 90]);
    $this->svc->liste($this->rootTeam);

    expect($sql)->not->toBeEmpty();
    foreach ($sql as $s) {
        $auswahl = substr($s, 0, stripos($s, ' from '));
        // „pf.*“ / "pf".* bzw. nicht gruppierte COALESCE-Spalten: SQLite duldet es, MySQL wirft 1055 (COUNT(*) ist erlaubt)
        expect(preg_match('/\.\s*\*/', $auswahl))->toBe(0, $s)->and($auswahl)->not->toContain('COALESCE');
    }
});

// ── Kurator 19.10.: Werte in Worten, Sammel über den aktuellen Filter (Art genügt) ─────────────────────────────────────────

it('Anzeige: Deklarations-Codes und Allergenwerte in Worten — Seite und MCP (der Code bleibt daneben)', function () {
    ($this->fall)('deklaration_aenderung', 'item_declaration', 36674788, 'glutamate', '3', '1');
    ($this->fall)('allergen_aenderung', 'item_allergen', 36674790, 'allergen_milk', 'spuren', 'nicht_enthalten');
    $faelle = collect($this->svc->liste($this->rootTeam, [], 1, 50)['faelle'])->keyBy('objekt');

    expect($faelle['item_declaration']['fa_damals_text'])->toBe('ja (enthalten)')
        ->and($faelle['item_declaration']['necta_text'])->toBe('nein')
        ->and($faelle['item_declaration']['fa_jetzt_text'])->toBe('k. A.')                    // keine Deklarationszeile am Artikel
        ->and($faelle['item_declaration']['necta'])->toBe('1')
        ->and($faelle['item_allergen']['fa_damals_text'])->toBe('Spuren')
        ->and($faelle['item_allergen']['necta_text'])->toBe('nicht enthalten')
        ->and(NectaPruefallService::wertText('item_declaration', '0'))->toBe('k. A.')
        ->and(NectaPruefallService::wertText('price', '12.50'))->toBe('12.50');

    $get = app(\Platform\Core\Tools\ToolRegistry::class)->get('foodalchemist.necta_pruefaelle.GET')
        ->execute(['art' => 'deklaration_aenderung'], new \Platform\Core\Contracts\ToolContext($this->admin, $this->rootTeam));
    expect($get->data['faelle'][0]['fa_damals_text'])->toBe('ja (enthalten)')->and($get->data['faelle'][0]['fa_damals'])->toBe('3');

    $this->actingAs($this->kurator);
    \Livewire\Livewire::test(\Platform\FoodAlchemist\Livewire\Suppliers\NectaPruefaelle::class)
        ->assertSee('ja (enthalten)')->assertSee('nicht enthalten');
});

it('Sammel nur nach Art über alle Lieferanten: Bestätigung nennt Anzahl und Lieferanten, andere Art bleibt unberührt, Lauf am Fall', function () {
    $zweiter = FoodAlchemistSupplier::create(['team_id' => null, 'legacy_id' => 19492, 'name' => 'Metzgerei', 'stamm_class' => StammPaket::PAKET]);
    FoodAlchemistSupplierItem::create(['team_id' => null, 'legacy_id' => 36674791, 'supplier_id' => $zweiter->id, 'designation' => 'Leberkäse', 'stamm_class' => StammPaket::PAKET]);
    $d1 = ($this->fall)('deklaration_aenderung', 'item_declaration', 36674788, 'glutamate', '3', '1');
    $d2 = ($this->fall)('deklaration_aenderung', 'item_declaration', 36674791, 'phosphate', '3', '0');
    $andere = ($this->fall)('allergen_aenderung', 'item_allergen', 36674790, 'allergen_milk', 'enthalten', 'nicht_enthalten');
    $this->actingAs($this->kurator);

    $lw = \Livewire\Livewire::test(\Platform\FoodAlchemist\Livewire\Suppliers\NectaPruefaelle::class)
        ->set('art', 'deklaration_aenderung')->assertSeeHtml('data-sammel-leiste')
        ->call('sammelPruefen', 'fa_behalten')
        ->assertSet('sammelVorschau.anzahl', 2)->assertSee('2 Prüffälle bei 2 Lieferanten')
        ->call('sammelBestaetigen')->assertSet('fehler', null);

    $zeile = fn (int $id) => DB::table('foodalchemist_necta_pruefaelle')->find($id);
    expect($zeile($d1)->status)->toBe('verworfen')->and($zeile($d2)->status)->toBe('verworfen')
        ->and($zeile($d1)->sammel_lauf)->not->toBeNull()->and($zeile($d1)->sammel_lauf)->toBe($zeile($d2)->sammel_lauf)
        ->and((int) $zeile($d1)->erledigt_von)->toBe((int) $this->kurator->id)
        ->and($zeile($andere)->status)->toBe('offen')->and($zeile($andere)->sammel_lauf)->toBeNull();
});

it('Sammel: ohne Kuratier-Recht abgewiesen; MCP-Sammel über art ohne Lieferant', function () {
    ($this->fall)('deklaration_aenderung', 'item_declaration', 36674788, 'glutamate', '3', '1');
    ($this->fall)('deklaration_aenderung', 'item_declaration', 36674790, 'phosphate', '3', '1');
    $leser = $this->makeUser($this->rootTeam, 'Leser M', 'viewer');
    $v = $this->svc->vorschau($this->rootTeam, null, ['art' => 'deklaration_aenderung'], 'fa_behalten');

    expect(fn () => $this->svc->entscheiden($this->rootTeam, null, ['art' => 'deklaration_aenderung'], 'fa_behalten', $leser->id, $v['schluessel']))
        ->toThrow(FaRechtFehltException::class);

    $tool = app(\Platform\Core\Tools\ToolRegistry::class)->get('foodalchemist.necta_pruefaelle.ENTSCHEIDEN');
    $ctx = new \Platform\Core\Contracts\ToolContext($this->kurator, $this->rootTeam);
    $vor = $tool->execute(['aktion' => 'fa_behalten', 'filter' => ['art' => 'deklaration_aenderung']], $ctx);
    expect($vor->data['anzahl'])->toBe(2)
        ->and($tool->execute(['aktion' => 'fa_behalten', 'filter' => ['art' => 'deklaration_aenderung'], 'bestaetigen' => true, 'schluessel' => $vor->data['schluessel']], $ctx)->data['erledigt'])->toBe(2);
});

it('Sammel über viele Fälle läuft als Job in Teilmengen: Stand fertig, alle entschieden, Stand per MCP abfragbar', function () {
    $n = NectaPruefallService::SAMMEL_SOFORT_MAX + 1;
    $zeilen = [];
    for ($i = 0; $i < $n; $i++) {
        $zeilen[] = ['uuid' => (string) UuidV7::generate(), 'art' => 'deklaration_aenderung', 'objekt' => 'item_declaration', 'legacy_id' => 36674788,
            'feld' => 'stoff_'.$i, 'fa_wert' => '3', 'necta_wert' => '1', 'status' => 'offen', 'created_at' => now(), 'updated_at' => now()];
    }
    DB::table('foodalchemist_necta_pruefaelle')->insert($zeilen);
    $v = $this->svc->vorschau($this->rootTeam, null, ['art' => 'deklaration_aenderung'], 'fa_behalten');

    $r = $this->svc->entscheiden($this->rootTeam, null, ['art' => 'deklaration_aenderung'], 'fa_behalten', $this->kurator->id, $v['schluessel']);

    expect($r['im_hintergrund'])->toBeTrue()->and($r['gesamt'])->toBe($n)                     // Sync-Queue: der Job lief schon inline
        ->and(NectaPruefallService::sammelStand($r['lauf']))->toMatchArray(['gesamt' => $n, 'erledigt' => $n, 'fertig' => true])
        ->and(DB::table('foodalchemist_necta_pruefaelle')->where('status', 'offen')->count())->toBe(0);

    $stand = app(\Platform\Core\Tools\ToolRegistry::class)->get('foodalchemist.necta_pruefaelle.ENTSCHEIDEN')
        ->execute(['lauf' => $r['lauf']], new \Platform\Core\Contracts\ToolContext($this->kurator, $this->rootTeam));
    expect($stand->data['erledigt'])->toBe($n)->and($stand->data['fertig'])->toBeTrue();
});

it('Dominique 23.10.: Artikelname öffnet das Artikel-Modal auf der Seite; nach dem Speichern bleibt die Liste gefiltert', function () {
    config(['foodalchemist.bearbeitungssperre' => false]);
    ($this->allergene)($this->a, ['allergen_milk' => 'enthalten']);
    $f1 = ($this->fall)('allergen_aenderung', 'item_allergen', 36674788, 'allergen_milk', 'enthalten', 'nicht_enthalten');
    ($this->fall)('manuell_gepflegt', 'supplier_item', 36674790, 'brand', null, 'X');
    $this->actingAs($this->admin);

    $c = \Livewire\Livewire::test(\Platform\FoodAlchemist\Livewire\Suppliers\NectaPruefaelle::class)
        ->set('art', 'allergen_aenderung')->set('lieferant', $this->lieferant->id)
        ->assertSeeHtml('data-pruefall-artikel="' . $this->a->id . '"')
        ->assertSeeHtml("\$dispatch('item-modal.oeffnen', { id: " . $this->a->id . ' })')
        ->assertDontSee('Rosinenschnecke');

    // Modal auf der Seite eingebunden (eigener key, sonst springt es zu) und öffnet genau diesen Artikel
    expect(file_get_contents(__DIR__ . '/../../resources/views/livewire/suppliers/necta-pruefaelle.blade.php'))
        ->toContain('<livewire:foodalchemist.suppliers.item-modal key="necta-pruefaelle--suppliers.item-modal" />');
    \Livewire\Livewire::test(\Platform\FoodAlchemist\Livewire\Suppliers\ItemModal::class)->call('oeffnen', $this->a->id)
        ->assertSet('itemId', $this->a->id)->assertDispatched('modal.open');

    // Speichern im Modal meldet „item-gespeichert“ → die Liste zeichnet neu, Filter und Fall bleiben
    $c->dispatch('item-gespeichert')->assertSet('art', 'allergen_aenderung')->assertSet('lieferant', $this->lieferant->id)
        ->assertSeeHtml('data-pruefall="' . $f1 . '"')->assertDontSee('Rosinenschnecke');
});
