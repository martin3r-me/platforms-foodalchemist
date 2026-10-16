<?php

use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Platform\Core\Contracts\ToolContext;
use Platform\FoodAlchemist\Livewire\Suppliers\ProduktpassPanel;
use Platform\FoodAlchemist\Models\FoodAlchemistProduktpass;
use Platform\FoodAlchemist\Models\FoodAlchemistProduktpassFeld;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplier;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem;
use Platform\FoodAlchemist\Services\Necta\NectaAbgleichService;
use Platform\FoodAlchemist\Services\Necta\NectaQuelle;
use Platform\FoodAlchemist\Services\Produktpass\ProduktpassAbweichungService;
use Platform\FoodAlchemist\Services\Produktpass\ProduktpassService;
use Platform\FoodAlchemist\Support\ProduktpassFelder;
use Platform\FoodAlchemist\Support\ProduktpassSchutz;
use Platform\FoodAlchemist\Support\StammPaket;
use Platform\FoodAlchemist\Tests\Support\NectaAttrappe;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;
use Platform\FoodAlchemist\Tools\ProduktpassAbweichungEntscheidenTool;
use Platform\FoodAlchemist\Tools\ProduktpassGetTool;
use Symfony\Component\Uid\UuidV7;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Produktpass-Vorrang (Entscheid Dominique 16.10.2026, Variante 1): an Feldern mit Pass-Marker in den Kerngruppen (Allergene samt Unterarten,
 * Zusatzstoffe, Nährwerte, Zutatenliste, Gebinde) bleibt der Pass-Wert — auch gegen ein STRENGERES Necta. Jede Abweichung wird Prüffall
 * `produktpass_abweichung` + EIN Signal je Artikel (Allergene kritisch, sonst Warnung). Alle übrigen Felder kommen aus Necta; ein leerer
 * Necta-Wert löscht den Pass-Wert nicht. Nur Marker aus dem Dokument (Quelle produktpass_ki) schützen, Websuche-Marker nicht.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    config(['foodalchemist.master_team_id' => $this->rootTeam->id]);
    ProduktpassSchutz::zuruecksetzen();
    $this->kurator = $this->makeUser($this->rootTeam, 'Kurator M', 'member');
    $this->admin = $this->makeUser($this->rootTeam, 'Admin M', 'owner');

    $lieferant = FoodAlchemistSupplier::create(['team_id' => null, 'legacy_id' => 19491, 'name' => 'Bäckerei', 'stamm_class' => StammPaket::PAKET]);
    $this->a = FoodAlchemistSupplierItem::create(['team_id' => null, 'legacy_id' => 36674788, 'supplier_id' => $lieferant->id,
        'designation' => 'Schoko Splitterbrötchen', 'unit_code' => 'Stk', 'stamm_class' => StammPaket::PAKET, 'brand' => 'Harry']);
    $detail = fn (string $tabelle, array $werte) => DB::table($tabelle)->insert(['uuid' => (string) UuidV7::generate(), 'team_id' => null,
        'legacy_id' => 36674788, 'supplier_item_id' => $this->a->id, 'source' => 'produktpass_ki', 'created_at' => now(), 'updated_at' => now()] + $werte);
    $detail('foodalchemist_item_allergens', ['allergen_milk' => 'nicht_enthalten']);
    $detail('foodalchemist_item_nutritionals', ['protein' => 5.0]);

    $this->pass = FoodAlchemistProduktpass::create(['team_id' => $this->rootTeam->id, 'supplier_item_id' => $this->a->id, 'version' => 1,
        'status' => 'gelesen', 'quelle' => ProduktpassFelder::QUELLE_PASS, 'extrakt' => []]);
    $this->marker = fn (string $objekt, string $feld, ?string $wert) => FoodAlchemistProduktpassFeld::updateOrCreate(
        ['supplier_item_id' => $this->a->id, 'objekt' => $objekt, 'feld' => $feld],
        ['produktpass_id' => $this->pass->id, 'wert_norm' => $wert, 'quelle' => 'produktpass_ki']);
    ($this->marker)('item_allergen', 'allergen_milk', 'nicht_enthalten');
    ($this->marker)('item_nutritional', 'protein', '5');
    ($this->marker)('supplier_item', 'brand', 'Harry');

    $this->necta = function (array $artikel = [], array $allergene = [], array $naehrwerte = []) {
        $this->app->instance(NectaQuelle::class, new NectaAttrappe([
            'suppliers' => [['id' => 19491, 'name' => 'Bäckerei']],
            'supplier-items' => [NectaAttrappe::artikel(36674788, 19491, 'Schoko Splitterbrötchen', $artikel + ['brand' => 'Harry'])],
            'supplier-item-prices' => [],
            'supplier-item-allergens' => $allergene === [] ? [] : [['supplierItemId' => 36674788] + $allergene],
            'supplier-item-nutritional-values' => $naehrwerte === [] ? [] : [['supplierItemId' => 36674788] + $naehrwerte],
            'supplier-item-declaration-substances' => [],
        ], [36674788 => 19491]));
        ProduktpassSchutz::zuruecksetzen();
        $this->travel(5)->minutes();
        app(NectaAbgleichService::class)->lauf(1, ['pause_ms' => 0]);
        $this->travel(5)->minutes();

        return app(NectaAbgleichService::class)->lauf(1, ['voll' => true, 'pause_ms' => 0]);
    };
    $this->milch = fn () => DB::table('foodalchemist_item_allergens')->where('supplier_item_id', $this->a->id)->value('allergen_milk');
    $this->faelle = fn (string $status = 'offen') => DB::table('foodalchemist_necta_pruefaelle')->where('art', 'produktpass_abweichung')->where('status', $status)->orderBy('id')->get();
    $this->signal = fn () => DB::table('foodalchemist_signals')->where('type', 'produktpass_abweichung')->where('dedup_key', 'produktpass_abweichung:'.$this->a->id)->first();
});

it('Necta STRENGER als der Pass-Allergenwert: Pass bleibt (keine Strenge-Regel), Prüffall + EIN kritisches Signal je Artikel auch mit Nährwert-Abweichung', function () {
    $r = ($this->necta)([], ['milk' => 3], ['protein' => 3.0]);

    $faelle = ($this->faelle)();
    $signal = ($this->signal)();
    expect(($this->milch)())->toBe('nicht_enthalten')
        ->and((float) DB::table('foodalchemist_item_nutritionals')->where('supplier_item_id', $this->a->id)->value('protein'))->toBe(5.0)
        ->and($faelle->pluck('feld')->sort()->values()->all())->toBe(['allergen_milk', 'protein'])
        ->and($faelle->firstWhere('feld', 'allergen_milk')->necta_wert)->toBe('enthalten')
        ->and(DB::table('foodalchemist_necta_pruefaelle')->where('art', 'allergen_aenderung')->exists())->toBeFalse()
        ->and($r['zaehler']['allergen_verschaerft'])->toBe(0)
        ->and(DB::table('foodalchemist_signals')->where('type', 'produktpass_abweichung')->count())->toBe(1)
        ->and($signal->severity)->toBe('kritisch')->and($signal->status)->toBe('offen')->and((int) $signal->team_id)->toBe($this->rootTeam->id)
        ->and(json_decode($signal->payload, true)['pruefaelle'])->toHaveCount(2);
});

it('Necta LOCKERER als der Pass: Pass bleibt, Prüffall produktpass_abweichung statt allergen_aenderung', function () {
    DB::table('foodalchemist_item_allergens')->where('supplier_item_id', $this->a->id)->update(['allergen_milk' => 'enthalten']);
    ($this->marker)('item_allergen', 'allergen_milk', 'enthalten');

    ($this->necta)([], ['milk' => 1]);

    expect(($this->milch)())->toBe('enthalten')
        ->and(($this->faelle)()->sole()->necta_wert)->toBe('nicht_enthalten')
        ->and(DB::table('foodalchemist_necta_pruefaelle')->where('art', 'allergen_aenderung')->exists())->toBeFalse();
});

it('Nur Nährwert weicht ab: Signal Warnung; Necta ohne Angabe (k. A.) ist keine Abweichung und löscht nichts', function () {
    ($this->necta)([], ['milk' => 0], ['protein' => 3.0]);

    expect(($this->faelle)()->pluck('feld')->all())->toBe(['protein'])
        ->and(($this->milch)())->toBe('nicht_enthalten')
        ->and(($this->signal)()->severity)->toBe('warnung');
});

it('Pass behalten: dieselbe Necta-Angabe kommt nicht wieder, Signal schließt; eine NEUE Necta-Angabe wird wieder gemeldet', function () {
    ($this->necta)([], ['milk' => 3]);
    app(ProduktpassAbweichungService::class)->behalten($this->rootTeam, (int) ($this->faelle)()->sole()->id, $this->kurator->id);

    expect(($this->signal)()->status)->toBe('erledigt')
        ->and(($this->faelle)('verworfen')->sole()->erledigt_von)->toBe($this->kurator->id);

    ($this->necta)([], ['milk' => 3]);
    expect(($this->faelle)())->toHaveCount(0)->and(($this->milch)())->toBe('nicht_enthalten');

    $r = ($this->necta)([], ['milk' => 2]);
    expect(($this->faelle)()->sole()->necta_wert)->toBe('spuren')
        ->and(($this->milch)())->toBe('nicht_enthalten')
        ->and($r['zaehler']['produktpass_abweichung_neu'])->toBe(1)
        ->and(DB::table('foodalchemist_signals')->where('type', 'produktpass_abweichung')->where('status', 'offen')->count())->toBe(1);
});

it('Necta übernehmen: Wert eingetragen, Marker an DIESEM Feld weg (andere bleiben), Prüffall übernommen, Signal zu; danach keine Abweichung mehr', function () {
    ($this->necta)([], ['milk' => 3]);
    app(ProduktpassAbweichungService::class)->uebernehmen($this->rootTeam, (int) ($this->faelle)()->sole()->id, $this->kurator->id);

    $marker = FoodAlchemistProduktpassFeld::where('supplier_item_id', $this->a->id)->pluck('feld')->sort()->values()->all();
    expect(($this->milch)())->toBe('enthalten')
        ->and($marker)->toBe(['brand', 'protein'])
        ->and(($this->faelle)('uebernommen')->sole()->erledigt_von)->toBe($this->kurator->id)
        ->and(($this->signal)()->status)->toBe('erledigt');

    ($this->necta)([], ['milk' => 3]);
    expect(($this->faelle)())->toHaveCount(0);
});

it('Nicht-Kern-Feld (Marke): Necta gewinnt trotz Marker, der Marker bleibt stehen; leere Necta-Marke löscht den Pass-Wert nicht', function () {
    ($this->necta)(['brand' => null]);
    expect($this->a->fresh()->brand)->toBe('Harry');

    $r = ($this->necta)(['brand' => 'Lieken']);
    expect($this->a->fresh()->brand)->toBe('Lieken')
        ->and($r['zaehler']['produktpass_necta_gewinnt'])->toBe(1)
        ->and(($this->faelle)())->toHaveCount(0)
        ->and(FoodAlchemistProduktpassFeld::where('supplier_item_id', $this->a->id)->where('feld', 'brand')->exists())->toBeTrue();
});

it('Unterarten: Bestätigung setzt den details-Marker; Pass „Weizen“, Necta zusätzlich „Hafer“ → Pass bleibt + kritisches Signal', function () {
    $pass = FoodAlchemistProduktpass::create(['team_id' => $this->rootTeam->id, 'supplier_item_id' => $this->a->id, 'version' => 2,
        'status' => 'gelesen', 'quelle' => ProduktpassFelder::QUELLE_PASS, 'allergene_status' => 'vorschlag', 'extrakt' => [
            'allergene' => ['gluten' => ['wert' => 'enthalten', 'konfidenz' => 0.95]],
            'allergene_details' => ['wheat' => ['wert' => 'enthalten', 'konfidenz' => 0.9]], 'felder' => []]]);
    app(ProduktpassService::class)->kennzeichnungBestaetigen($this->rootTeam, $pass, $this->kurator->id);

    expect(FoodAlchemistProduktpassFeld::where('supplier_item_id', $this->a->id)->where('feld', 'details')->value('wert_norm'))->toBe('{"wheat":"enthalten"}');

    ($this->necta)([], ['milk' => 1, 'gluten' => 3, 'allergeneWheat' => 3, 'allergeneOats' => 3]);

    $details = json_decode((string) DB::table('foodalchemist_item_allergens')->where('supplier_item_id', $this->a->id)->value('details'), true);
    $fall = ($this->faelle)()->sole();
    expect($details)->toBe(['wheat' => 'enthalten'])
        ->and($fall->feld)->toBe('details')
        ->and(json_decode($fall->necta_wert, true))->toBe(['oats' => 'enthalten', 'wheat' => 'enthalten'])
        ->and(($this->signal)()->severity)->toBe('kritisch');

    // Necta übernehmen schreibt die ganze Unterarten-Liste
    app(ProduktpassAbweichungService::class)->uebernehmen($this->rootTeam, (int) $fall->id, $this->kurator->id);
    expect(json_decode((string) DB::table('foodalchemist_item_allergens')->where('supplier_item_id', $this->a->id)->value('details'), true))
        ->toBe(['oats' => 'enthalten', 'wheat' => 'enthalten']);
});

it('Neuer Pass bestätigt den Necta-Wert: offene Abweichung schließt sich selbst, Signal zu', function () {
    ($this->necta)([], ['milk' => 3]);
    $pass = FoodAlchemistProduktpass::create(['team_id' => $this->rootTeam->id, 'supplier_item_id' => $this->a->id, 'version' => 2,
        'status' => 'gelesen', 'quelle' => ProduktpassFelder::QUELLE_PASS, 'allergene_status' => 'vorschlag', 'extrakt' => [
            'allergene' => ['milk' => ['wert' => 'enthalten', 'konfidenz' => 0.95]], 'allergene_details' => [], 'felder' => []]]);

    app(ProduktpassService::class)->kennzeichnungBestaetigen($this->rootTeam, $pass, $this->kurator->id);

    expect(($this->faelle)())->toHaveCount(0)
        ->and(($this->faelle)('uebernommen')->sole()->erledigt_von)->toBeNull()
        ->and(($this->signal)()->status)->toBe('erledigt');
});

it('Oberfläche + MCP: Panel zeigt „Necta-Abweichungen“ mit beiden Knöpfen; GET liefert sie, ENTSCHEIDEN (nur Admin) übernimmt', function () {
    ($this->necta)([], ['milk' => 3], ['protein' => 3.0]);
    $this->actingAs($this->kurator);

    Livewire::test(ProduktpassPanel::class, ['itemId' => $this->a->id])
        ->assertSeeHtml('data-produktpass-abweichungen')->assertSee('Allergen Milch')->assertSee('Nährwert Eiweiß')
        ->assertSeeHtml('data-produktpass-pass-behalten')->assertSeeHtml('data-produktpass-necta-uebernehmen')
        ->call('passBehalten', (int) ($this->faelle)()->firstWhere('feld', 'protein')->id)
        ->assertSet('fehler', null)->assertDontSee('Nährwert Eiweiß');

    $get = app(ProduktpassGetTool::class)->execute(['supplier_item_id' => $this->a->id], new ToolContext($this->kurator, $this->rootTeam));
    $id = $get->data['necta_abweichungen'][0]['id'];
    $alsKurator = app(ProduktpassAbweichungEntscheidenTool::class)->execute(['id' => $id, 'aktion' => 'necta'], new ToolContext($this->kurator, $this->rootTeam));
    $alsAdmin = app(ProduktpassAbweichungEntscheidenTool::class)->execute(['id' => $id, 'aktion' => 'necta'], new ToolContext($this->admin, $this->rootTeam));

    expect($get->data['necta_abweichungen'])->toHaveCount(1)->and($get->data['necta_abweichungen'][0]['feld'])->toBe('allergen_milk')
        ->and($alsKurator->success)->toBeFalse()
        ->and($alsAdmin->success)->toBeTrue()->and(($this->milch)())->toBe('enthalten');
});

it('Gebinde gehört zum Kern: Pass-Menge bleibt gegen Necta, Prüffall + Signal Warnung; leere Necta-Menge ist keine Abweichung', function () {
    $this->a->update(['qty' => 0.66]);
    ($this->marker)('supplier_item', 'qty', '0.66');

    ($this->necta)(['quantity' => null]);
    expect((float) $this->a->fresh()->qty)->toBe(0.66)->and(($this->faelle)())->toHaveCount(0);

    ($this->necta)(['quantity' => 1]);
    $fall = ($this->faelle)()->sole();
    expect((float) $this->a->fresh()->qty)->toBe(0.66)
        ->and($fall->feld)->toBe('qty')->and((float) $fall->necta_wert)->toBe(1.0)
        ->and(($this->signal)()->severity)->toBe('warnung');

    app(ProduktpassAbweichungService::class)->uebernehmen($this->rootTeam, (int) $fall->id, $this->kurator->id);
    expect((float) $this->a->fresh()->qty)->toBe(1.0);
});

it('Websuche-Marker schützt nicht: Allergen aus der Websuche + Necta strenger → Strenge-Regel greift, kein Pass-Prüffall; Marker bleibt stehen', function () {
    FoodAlchemistProduktpassFeld::where('supplier_item_id', $this->a->id)->where('feld', 'allergen_milk')->update(['quelle' => ProduktpassFelder::QUELLE_WEB]);

    $r = ($this->necta)([], ['milk' => 3]);

    expect(($this->milch)())->toBe('enthalten')
        ->and($r['zaehler']['allergen_verschaerft'])->toBe(1)
        ->and(($this->faelle)())->toHaveCount(0)
        ->and(($this->signal)())->toBeNull()
        ->and(FoodAlchemistProduktpassFeld::where('supplier_item_id', $this->a->id)->where('feld', 'allergen_milk')->exists())->toBeTrue();
});
