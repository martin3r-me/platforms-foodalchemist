<?php

use Livewire\Livewire;
use Platform\FoodAlchemist\Livewire\Controlling\Panels\Preisvergleich;
use Platform\FoodAlchemist\Livewire\Controlling\Panels\LinkPreise;
use Platform\FoodAlchemist\Models\FoodAlchemistPrice;
use Platform\FoodAlchemist\Models\FoodAlchemistServierform;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplier;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItemStructure;
use Platform\FoodAlchemist\Services\SimulationService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 32 · C2 — die Hebel der Werkbank.
 *
 * Der Kern der Spec ist, dass Befund und Handlung am selben Ort liegen. Diese Datei prüft
 * die Handlungen: Bezugsquelle umstellen, Link-Preise prüfen (Spec 85 F5), Lieferant-Szenario.
 * Jede geht über einen bestehenden Service — geprüft wird also, dass die Fläche ihn richtig
 * ruft und dass sie nichts anfasst, was ihr nicht gehört.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->user = $this->makeUser($this->childA);
    $this->actingAs($this->user);

    $this->teuer = FoodAlchemistSupplier::create([
        'team_id' => $this->childA->id, 'name' => 'Teuer GmbH', 'status' => 'aktiv',
    ]);
    $this->guenstig = FoodAlchemistSupplier::create([
        'team_id' => $this->childA->id, 'name' => 'Günstig KG', 'status' => 'aktiv',
    ]);

    $this->gp = $this->makeGp($this->childA, 'Butter');

    $mkLa = function (FoodAlchemistSupplier $s, float $preis) {
        $la = FoodAlchemistSupplierItem::create([
            'team_id' => $this->childA->id, 'supplier_id' => $s->id, 'gp_id' => $this->gp->id,
            'designation' => 'Butter 250 g ' . $s->name, 'unit_code' => 'kg', 'qty' => 1,
        ]);
        FoodAlchemistPrice::create([
            'team_id' => $this->childA->id, 'supplier_item_id' => $la->id,
            'price' => $preis, 'status' => '0',
        ]);
        // Die LA↔GP-Verknüpfung, die LeadLaService prüft, hängt an der Struktur-Tabelle —
        // `supplier_items.gp_id` allein reicht nicht (GL-03 I2). Über das Model, nicht per
        // DB::insert: der UUID-Hook sitzt in `booted()`.
        FoodAlchemistSupplierItemStructure::create([
            'team_id' => $this->childA->id, 'supplier_item_id' => $la->id, 'gp_id' => $this->gp->id,
        ]);

        return $la;
    };

    $this->servierform = FoodAlchemistServierform::firstOrCreate(
        ['code' => 'unbestimmt', 'team_id' => $this->childA->id],
        ['label' => 'Unbestimmt'],
    );

    $this->laTeuer = $mkLa($this->teuer, 12.00);
    $this->laGuenstig = $mkLa($this->guenstig, 7.00);
});

it('stellt die Bezugsquelle auf den günstigsten Artikel um', function () {
    $this->gp->update(['lead_la_supplier_item_id' => $this->laTeuer->id]);

    Livewire::test(Preisvergleich::class)
        ->call('bezugsquelleSetzen', $this->gp->id, $this->laGuenstig->id)
        ->assertSet('fehler', null);

    expect((int) $this->gp->refresh()->lead_la_supplier_item_id)->toBe((int) $this->laGuenstig->id);
});

it('verweigert eine Bezugsquelle, die nicht zum Grundprodukt gehört', function () {
    // Fremder Artikel ohne Verknüpfung — LeadLaService wirft (GL-03 I2), die Fläche fängt das
    // ab und meldet, statt den Editor mit einer Exception zu zerlegen.
    $fremd = FoodAlchemistSupplierItem::create([
        'team_id' => $this->childA->id, 'supplier_id' => $this->guenstig->id,
        'designation' => 'Ganz was anderes', 'unit_code' => 'kg', 'qty' => 1,
    ]);

    Livewire::test(Preisvergleich::class)
        ->call('bezugsquelleSetzen', $this->gp->id, $fremd->id)
        ->assertSet('hinweis', null);

    expect($this->gp->refresh()->lead_la_supplier_item_id)->toBeNull();
});

it('fasst kein Grundprodukt an, das dem Team nicht sichtbar ist', function () {
    // childB ist ein Geschwister — sein GP darf über diese Fläche nicht erreichbar sein.
    $fremdGp = $this->makeGp($this->childB, 'Fremde Butter');

    Livewire::test(Preisvergleich::class)
        ->call('bezugsquelleSetzen', $fremdGp->id, $this->laGuenstig->id)
        ->assertSet('fehler', 'Grundprodukt nicht gefunden.');

    expect($fremdGp->refresh()->lead_la_supplier_item_id)->toBeNull();
});

it('simuliert einen Preisaufschlag über das ganze Sortiment eines Lieferanten', function () {
    $this->gp->update(['lead_la_supplier_item_id' => $this->laTeuer->id]);

    // Scope „lieferant" trifft die GPs, deren LEAD bei diesem Lieferanten liegt …
    $treffer = app(SimulationService::class)->simuliere($this->childA, 'lieferant', (string) $this->teuer->id, 10.0);
    expect($treffer['n_gps'])->toBe(1);

    // … und nicht die, die woanders beziehen. Sonst wäre das Szenario wertlos.
    $daneben = app(SimulationService::class)->simuliere($this->childA, 'lieferant', (string) $this->guenstig->id, 10.0);
    expect($daneben['n_gps'])->toBe(0);
});

// Spec 85 F5 (Dominique 19.10.): keine VK-Freigabe mehr — das Panel zeigt die Preise veröffentlichter Links, die von der
// Kalkulation abweichen (offene Signale vk_anpassung_empfohlen samt Payload), und prüft auf Knopfdruck neu.
function spec85_link_drift_signal(\Platform\Core\Models\Team $team, string $docType, int $docId, float $maxDelta, ?int $outletId = null): void
{
    app(\Platform\FoodAlchemist\Services\SignalService::class)->erzeuge($team, \Platform\FoodAlchemist\Enums\SignalTyp::VkAnpassungEmpfohlen,
        \Platform\FoodAlchemist\Enums\SignalSeverity::Warnung, "Link {$docType} {$docId}: Preise weichen ab", [
            'dedup_key' => "test-drift-{$docType}-{$docId}", 'outlet_id' => $outletId, 'ref_type' => $docType, 'ref_id' => $docId,
            'payload' => ['doc_type' => $docType, 'doc_id' => $docId, 'outlet_id' => $outletId, 'anzahl' => 1, 'max_delta_pct' => $maxDelta,
                'zeilen' => [['label' => 'Rührei', 'frozen' => 9.0, 'live' => round(9.0 * (1 + $maxDelta / 100), 2), 'delta_pct' => $maxDelta, 'richtung' => 'erhoehen']]],
        ]);
}

it('F5: zeigt je veröffentlichtem Link die abweichenden Preise aus dem Signal, größte Abweichung zuerst, mit Weg in den Editor', function () {
    spec85_link_drift_signal($this->childA, 'foodbook', 11, 8.0);
    spec85_link_drift_signal($this->childA, 'angebot', 22, 20.0);
    spec85_link_drift_signal($this->childB, 'speisekarte', 33, 50.0);   // fremdes Team: nie sichtbar

    Livewire::test(LinkPreise::class)
        ->assertViewHas('links', fn ($l) => count($l) === 2
            && $l[0]['art'] === 'Angebot' && $l[0]['max_delta_pct'] === 20.0
            && str_contains((string) $l[0]['url'], 'sel=22')
            && $l[1]['art'] === 'Foodbook' && str_contains((string) $l[1]['url'], 'fb=11')
            && (float) $l[0]['zeilen'][0]['frozen'] === 9.0)   // aus dem JSON-Payload: 9 statt 9.0
        ->assertSeeHtml('data-ctrl-link-preise-editor')
        ->assertDontSeeHtml('freigeben');
});

it('F5: ohne Abweichung ein ruhiger Leerzustand; „Jetzt prüfen“ ruft nur die Ausgabe-Drift des Detektors', function () {
    Livewire::test(LinkPreise::class)->assertViewHas('links', [])->assertSee('Alle veröffentlichten Links tragen aktuelle Preise.');

    $this->mock(\Platform\FoodAlchemist\Services\SignalDetektorService::class, fn ($m) => $m->shouldReceive('ausgabeDriftPruefen')->once()->andReturn(2));
    Livewire::test(LinkPreise::class)->call('jetztPruefen')->assertSet('hinweis', 'Geprüft: 2 Links zeigen Preise, die von der Kalkulation abweichen.');
});
