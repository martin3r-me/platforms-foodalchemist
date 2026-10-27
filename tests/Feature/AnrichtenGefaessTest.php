<?php

use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Platform\FoodAlchemist\Livewire\Concepter\Editor;
use Platform\FoodAlchemist\Livewire\Verkauf\VkModal;
use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Models\FoodAlchemistPrice;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeDarreichung;
use Platform\FoodAlchemist\Models\FoodAlchemistServierform;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplier;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItemStructure;
use Platform\FoodAlchemist\Services\ConceptService;
use Platform\FoodAlchemist\Services\DarreichungService;
use Platform\FoodAlchemist\Services\GpMasseService;
use Platform\FoodAlchemist\Services\SupplierGroupService;
use Platform\FoodAlchemist\Support\NonFoodRegeln;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;
use Symfony\Component\Uid\UuidV7;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * PR C: das Gefäß an der Darreichung. Das Gericht ist neutral, die Darreichung definiert Menge und Gefäß; der Servier-Typ steht EINMAL am Gefäß-GP
 * (`vehikelId` = ausdrückliche Übersteuerung, sonst Typ des Gefäßes); ein gleicher oder leerer Bestandswert wird beim Setzen des Gefäßes geleert (sonst
 * übersteuerte er still für immer). Gefäß kostet wie Zubehör 1 Stück je Einheit, Miete (Gruppe VERLEIH) nur als Hinweis; Füllmenge > Volumen warnt.
 */
function gefaessGp(\Platform\Core\Models\Team $team, string $name, ?float $gebindePreis, float $stk = 12.0, ?string $sub = null): FoodAlchemistGp
{
    $gp = FoodAlchemistGp::create(['team_id' => $team->id, 'gp_key' => 'gef|'.mb_strtolower($name), 'name' => $name, 'status' => 'approved',
        'commodity_group_code' => '16', 'sub_category' => $sub ?? NonFoodRegeln::SUB_GESCHIRR]);
    $sup = FoodAlchemistSupplier::firstOrCreate(['team_id' => $team->id, 'name' => 'Verpackungs-Großhandel']);
    $la = FoodAlchemistSupplierItem::create(['team_id' => $team->id, 'supplier_id' => $sup->id, 'designation' => $name, 'qty' => $stk, 'unit_code' => 'Stk']);
    FoodAlchemistSupplierItemStructure::create(['team_id' => $team->id, 'supplier_item_id' => $la->id, 'gp_id' => $gp->id]);
    if ($gebindePreis !== null) {
        FoodAlchemistPrice::create(['team_id' => $team->id, 'supplier_item_id' => $la->id, 'price' => $gebindePreis, 'status' => '0']);
    }
    $gp->update(['lead_la_supplier_item_id' => $la->id]);

    return $gp->refresh();
}

beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->user = $this->makeUser($this->rootTeam, 'Einkauf');
    $this->actingAs($this->user);
    $this->svc = app(DarreichungService::class);
    FoodAlchemistServierform::firstOrCreate(['code' => 'unbestimmt', 'team_id' => $this->rootTeam->id], ['label' => 'Unbestimmt']);

    $this->fahrzeug = function (string $name) {
        return DB::table('foodalchemist_vocab_serving_vehicles')->insertGetId(['uuid' => (string) UuidV7::generate(), 'team_id' => $this->rootTeam->id,
            'slug' => mb_strtolower($name), 'name' => $name, 'sort_order' => 1, 'is_inactive' => false, 'created_at' => now(), 'updated_at' => now()]);
    };
    $this->glasTyp = ($this->fahrzeug)('Glas');
    $this->tellerTyp = ($this->fahrzeug)('Teller');

    // Gericht: 10 €/kg, 200 g je Einheit ⇒ Lebensmittel-EK 2,00 €
    $this->gericht = function (string $key = 'salat') {
        $g = FoodAlchemistRecipe::create(['team_id' => $this->rootTeam->id, 'recipe_key' => $key, 'name' => 'Salat '.$key, 'status' => 'approved', 'is_sales_recipe' => true, 'ek_per_kg_eur' => 10.0, 'yield_kg' => 1.0]);
        $d = $this->svc->ensureStandard($this->rootTeam, $g->id);
        $d->update(['quantity_per_unit_g' => 200, 'unit_count' => 1]);
        $this->svc->recomputePreise($d->fresh());

        return $d->fresh();
    };
    $this->dar = ($this->gericht)();
    $this->ek = fn ($d = null) => (float) ($d ?? $this->dar)->fresh()->ek_portion;
    $this->glas = gefaessGp($this->rootTeam, 'Schraubglas: Glas, 212 ml', 6.00);   // 0,50 €/Stk
    app(GpMasseService::class)->setze($this->glas, ['volume_ml' => '212']);
    app(GpMasseService::class)->setzeServierTyp($this->glas->refresh(), $this->glasTyp);
});

it('Gefäß: nur ein sichtbares Non-Food-GP; Lebensmittel und fremde GPs werden abgewiesen; leer löst', function () {
    $butter = FoodAlchemistGp::create(['team_id' => $this->rootTeam->id, 'gp_key' => 'gef|butter', 'name' => 'Butter: frisch', 'status' => 'approved', 'commodity_group_code' => '06']);

    expect(fn () => $this->svc->aktualisieren($this->rootTeam, $this->dar->id, ['vessel_gp_id' => $butter->id]))->toThrow(\RuntimeException::class, 'Non-Food')
        ->and(fn () => $this->svc->aktualisieren($this->rootTeam, $this->dar->id, ['vessel_gp_id' => 999999]))->toThrow(\RuntimeException::class, 'nicht sichtbar');

    $this->svc->aktualisieren($this->rootTeam, $this->dar->id, ['vessel_gp_id' => $this->glas->id]);
    expect($this->dar->fresh()->vessel_gp_id)->toBe($this->glas->id);
    $this->svc->aktualisieren($this->rootTeam, $this->dar->id, ['vessel_gp_id' => '']);
    expect($this->dar->fresh()->vessel_gp_id)->toBeNull();
});

it('Servier-Typ: kommt aus dem Gefäß (eine Lese-Stelle), ändert sich am GP, ziehen alle Darreichungen mit; Servier-Typ-Setzen nur WG 16', function () {
    $d2 = ($this->gericht)('salat2');
    $this->svc->aktualisieren($this->rootTeam, $this->dar->id, ['vessel_gp_id' => $this->glas->id]);
    $this->svc->aktualisieren($this->rootTeam, $d2->id, ['vessel_gp_id' => $this->glas->id]);

    expect($this->svc->vehikelId($this->dar->fresh()))->toBe($this->glasTyp)->and($this->svc->vehikelId($d2->fresh()))->toBe($this->glasTyp)
        ->and($this->dar->fresh()->serving_vehicle_vocab_id)->toBeNull();                       // nichts kopiert

    app(GpMasseService::class)->setzeServierTyp($this->glas->refresh(), 'Teller');             // am GP geändert (Name statt Id)
    expect($this->svc->vehikelId($this->dar->fresh()))->toBe($this->tellerTyp)->and($this->svc->vehikelId($d2->fresh()))->toBe($this->tellerTyp);

    $butter = FoodAlchemistGp::create(['team_id' => $this->rootTeam->id, 'gp_key' => 'gef|butter', 'name' => 'Butter: frisch', 'status' => 'approved', 'commodity_group_code' => '06']);
    expect(fn () => app(GpMasseService::class)->setzeServierTyp($butter, $this->glasTyp))->toThrow(\RuntimeException::class, 'Non-Food')
        ->and(fn () => app(GpMasseService::class)->setzeServierTyp($this->glas->refresh(), 'Schiff'))->toThrow(\RuntimeException::class, 'Vokabular');
});

it('Bestandswert: gleich dem Typ des Gefäßes → nach der Zuweisung leer; abweichend → bleibt und gilt als Übersteuerung; leer bleibt leer', function () {
    $gleich = ($this->gericht)('gleich');
    $gleich->update(['serving_vehicle_vocab_id' => $this->glasTyp]);                          // Bestand: normaler Typ, identisch zum Typ des Gefäßes
    $abweichend = ($this->gericht)('abweichend');
    $abweichend->update(['serving_vehicle_vocab_id' => $this->tellerTyp]);                    // Bestand weicht ab
    $leer = ($this->gericht)('leer');

    foreach ([$gleich, $abweichend, $leer] as $d) {
        $this->svc->aktualisieren($this->rootTeam, $d->id, ['vessel_gp_id' => $this->glas->id]);
    }

    expect($gleich->fresh()->serving_vehicle_vocab_id)->toBeNull()->and($this->svc->uebersteuert($gleich->fresh()))->toBeFalse()->and($this->svc->vehikelId($gleich->fresh()))->toBe($this->glasTyp)
        ->and($abweichend->fresh()->serving_vehicle_vocab_id)->toBe($this->tellerTyp)->and($this->svc->uebersteuert($abweichend->fresh()))->toBeTrue()
        ->and($this->svc->vehikelId($abweichend->fresh()))->toBe($this->tellerTyp)
        ->and($leer->fresh()->serving_vehicle_vocab_id)->toBeNull()->and($this->svc->vehikelId($leer->fresh()))->toBe($this->glasTyp);

    $info = $this->svc->gefaessInfo($abweichend->fresh());
    expect($info['uebersteuert'])->toBeTrue()->and($info['typ_name'])->toBe('Teller')->and($info['gefaess_typ_name'])->toBe('Glas');

    // später ausdrücklich auf den Typ des Gefäßes gesetzt = keine Übersteuerung mehr
    $this->svc->aktualisieren($this->rootTeam, $abweichend->id, ['serving_vehicle_vocab_id' => $this->glasTyp]);
    expect($abweichend->fresh()->serving_vehicle_vocab_id)->toBeNull()->and($this->svc->uebersteuert($abweichend->fresh()))->toBeFalse();

    // Gegenprobe: ohne Gefäß bleibt ein Bestandswert unverändert wirksam
    $ohne = ($this->gericht)('ohne');
    $ohne->update(['serving_vehicle_vocab_id' => $this->tellerTyp]);
    expect($this->svc->vehikelId($ohne->fresh()))->toBe($this->tellerTyp)->and($this->svc->uebersteuert($ohne->fresh()))->toBeFalse();
});

it('Kosten: Einweg-Gefäß zählt 1 Stück je Einheit × Anzahl auf den EK; ohne Preis ein Hinweis, kein Preis vorgetäuscht', function () {
    expect(($this->ek)())->toBe(2.0);
    $this->svc->aktualisieren($this->rootTeam, $this->dar->id, ['vessel_gp_id' => $this->glas->id]);
    expect(($this->ek)())->toBe(2.5);

    $this->dar->update(['unit_count' => 2]);                                                   // Lebensmittel 4,00 + 2 × 0,50
    $this->svc->recomputePreise($this->dar->fresh());
    expect(($this->ek)())->toBe(5.0);

    $ohnePreis = gefaessGp($this->rootTeam, 'Pappschale', null);
    $d = ($this->gericht)('ohnepreis');
    $this->svc->aktualisieren($this->rootTeam, $d->id, ['vessel_gp_id' => $ohnePreis->id]);
    expect(($this->ek)($d))->toBe(2.0)->and($this->svc->gefaessInfo($d->fresh())['preis_fehlt'])->toBeTrue();   // zählt nicht mit, wird aber ausgewiesen
});

it('Miete: ein Gefäß vom Lieferanten der Gruppe VERLEIH zählt NICHT je Portion, nur als Hinweis; dasselbe gilt für Zubehör', function () {
    app(SupplierGroupService::class)->einrichten(true);
    $verleih = \Platform\FoodAlchemist\Models\FoodAlchemistSupplierGroup::whereNull('team_id')->where('code', 'VERLEIH')->firstOrFail();
    $miet = gefaessGp($this->rootTeam, 'Speiseteller: Porzellan, Ø 27 cm', 0.55, 1.0);
    $verleiher = FoodAlchemistSupplier::create(['team_id' => $this->rootTeam->id, 'name' => 'Eventausstatter', 'group_id' => $verleih->id]);   // eigener Lieferant, damit das Glas weiter „normal“ bleibt
    FoodAlchemistSupplierItem::whereKey($miet->lead_la_supplier_item_id)->update(['supplier_id' => $verleiher->id]);

    $this->svc->aktualisieren($this->rootTeam, $this->dar->id, ['vessel_gp_id' => $miet->id]);
    $info = $this->svc->gefaessInfo($this->dar->fresh());
    expect(($this->ek)())->toBe(2.0)->and($info['miete'])->toBeTrue()->and($info['kosten'])->toBeNull()->and($info['preis_fehlt'])->toBeFalse();

    // Zubehör vom Verleih: ebenfalls nur Hinweis, nie je Portion, und es macht die Kalkulation nicht „unvollständig“
    $this->svc->aktualisieren($this->rootTeam, $this->dar->id, ['vessel_gp_id' => '']);
    $this->svc->setzeZubehoer($this->rootTeam, $this->dar->id, $miet->id, 1);
    $k = $this->svc->zubehoerKosten($this->dar->fresh(), $this->rootTeam);
    expect(($this->ek)())->toBe(2.0)->and($k['zeilen'][0]['miete'])->toBeTrue()->and($k['vollstaendig'])->toBeTrue()->and($k['summe'])->toBe(0.0);

    // Gegenprobe: dasselbe Zubehör von einem normalen Lieferanten kostet je Portion
    $this->svc->setzeZubehoer($this->rootTeam, $this->dar->id, $this->glas->id, 1);
    expect(($this->ek)())->toBe(2.5);
});

it('Doppelzählung: dasselbe GP als Gefäß und als Zubehör — das Gefäß zählt, die Zubehör-Zeile trägt den Hinweis statt doppelter Kosten', function () {
    $this->svc->aktualisieren($this->rootTeam, $this->dar->id, ['vessel_gp_id' => $this->glas->id]);
    $this->svc->setzeZubehoer($this->rootTeam, $this->dar->id, $this->glas->id, 1);

    $k = $this->svc->zubehoerKosten($this->dar->fresh(), $this->rootTeam);
    expect(($this->ek)())->toBe(2.5)                                                            // einmal 0,50, nicht zweimal
        ->and($k['zeilen'][0]['doppelt'])->toBeTrue()->and($k['zeilen'][0]['kosten'])->toBeNull()->and($k['vollstaendig'])->toBeTrue();

    // anderes Zubehör zählt zusätzlich
    $deckel = gefaessGp($this->rootTeam, 'Deckel', 2.40);
    $this->svc->setzeZubehoer($this->rootTeam, $this->dar->id, $deckel->id, 1);              // 0,20 €/Stk
    expect(($this->ek)())->toBe(2.7);
});

it('Füllmenge: Grammatur > Volumen des Gefäßes warnt (nur Hinweis), kleiner/gleich oder ohne Volumen keine Warnung', function () {
    $this->svc->aktualisieren($this->rootTeam, $this->dar->id, ['vessel_gp_id' => $this->glas->id]);          // 200 g in 212 ml
    expect($this->svc->gefaessInfo($this->dar->fresh())['warnung'])->toBeNull();

    $this->svc->aktualisieren($this->rootTeam, $this->dar->id, ['quantity_per_unit_g' => '250']);
    $w = $this->svc->gefaessInfo($this->dar->fresh())['warnung'];
    expect($w)->toContain('250 g')->toContain('212 ml')->and(($this->ek)())->toBeGreaterThan(0);              // kein Block, der EK rechnet weiter

    $teller = gefaessGp($this->rootTeam, 'Teller flach', 0.5);                                           // nur Ø, kein Volumen: keine Prüfung
    app(GpMasseService::class)->setze($teller, ['diameter_cm' => '27']);
    $this->svc->aktualisieren($this->rootTeam, $this->dar->id, ['vessel_gp_id' => $teller->id]);
    expect($this->svc->gefaessInfo($this->dar->fresh())['warnung'])->toBeNull();
});

it('Preis-Anstoß: ändert sich der Preis des Gefäß-GP, rechnet die Darreichung neu; ein anderes Gericht bleibt unberührt', function () {
    $this->svc->aktualisieren($this->rootTeam, $this->dar->id, ['vessel_gp_id' => $this->glas->id]);
    $anderes = ($this->gericht)('anderes');
    $anderes->update(['ek_portion' => 99.0]);

    app(\Platform\FoodAlchemist\Services\PriceService::class)->createFor($this->rootTeam, FoodAlchemistSupplierItem::findOrFail($this->glas->lead_la_supplier_item_id), 12.00);   // 1,00 €/Stk

    expect(($this->ek)())->toBe(3.0)->and((float) $anderes->fresh()->ek_portion)->toBe(99.0);
});

it('VK-Modal: Spalte Gefäß, wirksamer Typ aus dem Gefäß, Übersteuerung sichtbar, Hinweise; speichern setzt das Gefäß und leert den gleichen Bestandswert', function () {
    $this->dar->update(['serving_vehicle_vocab_id' => $this->glasTyp]);                       // Bestand identisch zum Typ des Gefäßes
    $c = Livewire::test(VkModal::class)->call('oeffnen', $this->dar->recipe_id);
    $c->assertSeeHtml('data-dar-gefaess')->assertSeeHtml('data-dar-typ');

    $c->set('darForm.'.$this->dar->id.'.vessel_gp_id', (string) $this->glas->id)->call('darreichungSpeichern', $this->dar->id);
    expect($this->dar->fresh()->vessel_gp_id)->toBe($this->glas->id)->and($this->dar->fresh()->serving_vehicle_vocab_id)->toBeNull();
    $html = Livewire::test(VkModal::class)->call('oeffnen', $this->dar->recipe_id)->html();
    expect($html)->toContain('data-dar-typ-aus-gefaess')->toContain('Glas')->not->toContain('data-dar-uebersteuert')->toContain('data-dar-gefaess-kosten');

    $this->dar->update(['serving_vehicle_vocab_id' => $this->tellerTyp, 'quantity_per_unit_g' => 300]);
    $html = Livewire::test(VkModal::class)->call('oeffnen', $this->dar->recipe_id)->html();
    expect($html)->toContain('data-dar-uebersteuert')->toContain('Gefäß sagt Glas')->toContain('data-dar-fuellmenge-warnung');
});

it('VK-Modal: Gefäß-Auswahl findet Geschirr-GPs auch unter dem alten Unterkategorie-Namen (Übergang bis zum Datenlauf, 27.10.)', function () {
    $alt = gefaessGp($this->rootTeam, 'Weckglas: Glas, 140 ml', 5.00, 12.0, 'Geschirr & Ausstattung');
    $deko = gefaessGp($this->rootTeam, 'Windlicht: Glas', 3.00, 12.0, NonFoodRegeln::SUB_DEKO);
    $ids = Livewire::test(VkModal::class)->call('oeffnen', $this->dar->recipe_id)->viewData('gefaessGps')->pluck('id')->all();

    expect($ids)->toContain($this->glas->id)->toContain($alt->id)->not->toContain($deko->id);
});

it('Concepter-Reiter „Anrichten“: nur lesend — Darreichung, Gefäß, Typ, Zubehör mit Kosten; kein Haupt-/Alternativ-Geschirr mehr', function () {
    $this->svc->aktualisieren($this->rootTeam, $this->dar->id, ['vessel_gp_id' => $this->glas->id]);
    $deckel = gefaessGp($this->rootTeam, 'Deckel', 2.40);
    $this->svc->setzeZubehoer($this->rootTeam, $this->dar->id, $deckel->id, 1);
    $concepts = app(ConceptService::class);
    $konzept = $concepts->create($this->rootTeam, ['name' => 'Salat-Buffet']);
    $slot = $concepts->addSlot($this->rootTeam, $konzept->id, ['role' => 'Vorspeise']);
    $concepts->fillSlot($this->rootTeam, $slot->id, ['sales_recipe_id' => $this->dar->recipe_id]);

    $html = Livewire::test(Editor::class)->call('oeffnen', 'concepts', $konzept->id, 'anrichten')->html();

    expect($html)->toContain('data-anrichten')->toContain('data-anrichten-gefaess')->toContain('Schraubglas: Glas, 212 ml')->toContain('data-anrichten-typ')
        ->toContain('aus dem Gefäß')->toContain('data-anrichten-zubehoer')->toContain('Deckel')->toContain('Anrichten')
        ->not->toContain('Haupt-Geschirr')->not->toContain('Geschirr wählen');
});

it('Kommando geschirr-typ-uebernehmen: Dry-Run zählt, --apply setzt den Typ nur wo kein Typ und kein Gefäß da ist, Bestand bleibt', function () {
    $sup = FoodAlchemistSupplier::create(['team_id' => $this->rootTeam->id, 'name' => 'Eventausstatter']);
    $mk = fn (string $label, ?int $typ) => DB::table('foodalchemist_tableware_items')->insertGetId(['uuid' => (string) UuidV7::generate(), 'team_id' => $this->rootTeam->id,
        'tableware_supplier_id' => null, 'label' => $label, 'unit' => 'Stk', 'vehicle_vocab_id' => $typ, 'is_inactive' => 0, 'created_at' => now(), 'updated_at' => now()]);
    $altMitTyp = $mk('Suppenteller', $this->tellerTyp);

    $frei = $this->dar;                                                                        // alter Geschirr-Artikel mit Typ, Darreichung ohne Typ/Gefäß → übernehmen
    $frei->update(['tableware_item_id' => $altMitTyp]);
    $schonTyp = ($this->gericht)('schontyp');                                                  // hat schon einen Typ → bleibt
    $schonTyp->update(['tableware_item_id' => $altMitTyp, 'serving_vehicle_vocab_id' => $this->glasTyp]);
    $mitGefaess = ($this->gericht)('mitgefaess');                                              // hat ein Gefäß → bleibt
    $mitGefaess->update(['tableware_item_id' => $altMitTyp, 'vessel_gp_id' => $this->glas->id]);

    $this->artisan('foodalchemist:geschirr-typ-uebernehmen')->assertSuccessful();
    expect($frei->fresh()->serving_vehicle_vocab_id)->toBeNull();                              // Dry-Run schreibt nichts

    $this->artisan('foodalchemist:geschirr-typ-uebernehmen', ['--apply' => true])->assertSuccessful();
    expect($frei->fresh()->serving_vehicle_vocab_id)->toBe($this->tellerTyp)
        ->and($schonTyp->fresh()->serving_vehicle_vocab_id)->toBe($this->glasTyp)
        ->and($mitGefaess->fresh()->serving_vehicle_vocab_id)->toBeNull();
});
