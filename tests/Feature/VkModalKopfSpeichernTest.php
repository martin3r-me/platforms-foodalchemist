<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Platform\FoodAlchemist\Livewire\Verkauf\VkModal;
use Platform\FoodAlchemist\Models\FoodAlchemistMarkupClass;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeDarreichung;
use Platform\FoodAlchemist\Models\FoodAlchemistVocabEinheit;
use Platform\FoodAlchemist\Services\Rezept\DarreichungService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Gericht-Modal: „Speichern" im Kopf überschreibt nicht, was in der Zeile der Standard-Darreichung steht
 * (Dominique 13.11.2026: Preisklasse in der Zeile gewählt, nach dem Speichern oben „nicht gesetzt", in der
 * Datenbank weg, die Zeile zeigte weiter die alte Auswahl).
 *
 * Ursache: der Kopf lud die gespiegelten Felder einmal beim Öffnen und schrieb sie beim Speichern komplett in die
 * Standard-Darreichung zurück. Jetzt: die Preisklasse steht nur noch an der Darreichung, und ein gespiegeltes
 * Kopf-Feld geht nur mit, wenn es im Kopf geändert wurde. Nach dem Speichern werden die Zeilen neu geladen.
 * 98 · 3.5 6b: der Kopf trägt gar keine Darreichungs-Spiegel mehr (Grammatur, Einheit, Portionszahl, Behälter-Typen) —
 * gepflegt wird nur in der Zeile.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam));
    $this->g = $this->unitG($this->rootTeam);
    $this->stk = FoodAlchemistVocabEinheit::create(['team_id' => $this->rootTeam->id, 'slug' => 'stk', 'display_de' => 'Stück', 'dimension' => 'count']);
    $this->portion = FoodAlchemistVocabEinheit::create(['team_id' => $this->rootTeam->id, 'slug' => 'portion', 'display_de' => 'Portion', 'dimension' => 'count']);
    $behaelter = fn (string $slug) => DB::table('foodalchemist_vocab_containers')->insertGetId([
        'uuid' => (string) Str::uuid7(), 'team_id' => $this->rootTeam->id, 'slug' => $slug, 'name' => $slug, 'sort_order' => 1, 'familie' => 'GN',
        'laenge_mm' => 530, 'breite_mm' => 325, 'tiefe_mm' => 65, 'volumen_l' => 8.8, 'nutzfaktor' => 0.85,
        'eignung' => json_encode(['abfuellen', 'ausgabe']), 'created_at' => now(), 'updated_at' => now(),
    ]);
    $vehikel = fn (string $slug) => DB::table('foodalchemist_vocab_serving_vehicles')->insertGetId([
        'uuid' => (string) Str::uuid7(), 'team_id' => $this->rootTeam->id, 'slug' => $slug, 'name' => $slug, 'sort_order' => 1, 'created_at' => now(), 'updated_at' => now(),
    ]);
    $this->warmA = $behaelter('kopf_warm_a');
    $this->warmB = $behaelter('kopf_warm_b');
    $this->kaltB = $behaelter('kopf_kalt_b');
    $this->vehikelB = $vehikel('kopf_vehikel_b');
    $this->alc = FoodAlchemistMarkupClass::create(['team_id' => $this->rootTeam->id, 'code' => 'ALC', 'label' => 'A la carte',
        'class_factor_pct' => 100, 'bereich' => 'speisen', 'is_inactive' => false]);

    // Gericht mit Ertrag: die Lage, in der der Sync die Grammatur aus dem Ertrag neu rechnen würde (Kopf-Spiegel seit 98 · 3.5 6c entfernt).
    $this->gericht = $this->makeRecipe($this->rootTeam, '[VOR] Graved Karotte', ['is_sales_recipe' => true, 'status' => 'draft',
        'yield_kg' => 1.0]);
    $this->standard = app(DarreichungService::class)->ensureStandard($this->rootTeam, $this->gericht->id, 'generator');
    $this->zeile = fn () => FoodAlchemistRecipeDarreichung::findOrFail($this->standard->id);
    $this->modal = fn () => Livewire::test(VkModal::class)->call('oeffnen', $this->gericht->id)->call('bearbeitenStarten');
});

it('der gemeldete Fall: Preisklasse in der Zeile wählen, im Kopf speichern — die Klasse bleibt', function () {
    $c = ($this->modal)();
    expect(array_key_exists('markup_class_id', $c->get('form')))->toBeFalse();   // kein zweites Preisklassen-Feld im Kopf

    $c->set("darForm.{$this->standard->id}.markup_class_id", $this->alc->id)->call('darreichungSpeichern', $this->standard->id)->assertSet('fehler', null);
    expect((int) ($this->zeile)()->markup_class_id)->toBe((int) $this->alc->id);

    $c->call('speichern')->assertSet('fehler', null);

    expect((int) ($this->zeile)()->markup_class_id)->toBe((int) $this->alc->id)               // vorher: NULL
        ->and((int) $c->get("darForm.{$this->standard->id}.markup_class_id"))->toBe((int) $this->alc->id);
});

it('alle gespiegelten Felder: was in der Standard-Darreichung steht, übersteht ein Speichern im Kopf ohne Kopf-Änderung', function () {
    // Stand der Zeile weicht vom Kopf (Gericht) ab — so, als hätte jemand die Zeile gepflegt.
    ($this->zeile)()->forceFill([
        'quantity_per_unit_g' => 96, 'unit_count' => 1, 'unit_vocab_id' => $this->stk->id, 'markup_class_id' => $this->alc->id,
        'container_warm_vocab_id' => $this->warmB, 'container_cold_vocab_id' => $this->kaltB, 'serving_vehicle_vocab_id' => $this->vehikelB,
    ])->save();
    $vorher = ($this->zeile)()->only(['quantity_per_unit_g', 'unit_count', 'unit_vocab_id', 'markup_class_id', 'container_warm_vocab_id', 'container_cold_vocab_id', 'serving_vehicle_vocab_id']);

    ($this->modal)()->call('speichern')->assertSet('fehler', null);

    expect(($this->zeile)()->only(array_keys($vorher)))->toEqual($vorher);
});

it('Grammatur in der Zeile ist nur Anzeige (Plan 98 B): ein geänderter Formularwert wird nicht gespeichert, auch nicht über den Kopf', function () {
    // Früher: 96 g in der Zeile gespeichert und gegen Ertrag ÷ Anzahl verteidigt. Jetzt ist die Grammatur gesperrt; die Zeile
    // schickt sie nicht mit, der Standard behält seine Menge (98 · 3.5 6b: automatisch, ohne Teiler aus der Portionszahl).
    $menge = ($this->zeile)()->quantity_per_unit_g;
    $c = ($this->modal)();
    $c->set("darForm.{$this->standard->id}.quantity_per_unit_g", '96')->call('darreichungSpeichern', $this->standard->id)->assertSet('fehler', null);
    expect(($this->zeile)()->quantity_per_unit_g)->toEqual($menge);

    $c->call('speichern')->assertSet('fehler', null);

    expect(($this->zeile)()->quantity_per_unit_g)->toEqual($menge);
});

it('der Kopf trägt keine Darreichungs-Spiegel mehr (98 · 3.5 6b): Grammatur, Einheit, Portionszahl und Behälter-Typen fehlen im Formular', function () {
    $form = ($this->modal)()->get('form');

    foreach (['sales_quantity_per_unit_g', 'sales_unit_vocab_id', 'sales_unit_count', 'container_warm_vocab_id', 'container_cold_vocab_id', 'serving_vehicle_vocab_id'] as $feld) {
        expect(array_key_exists($feld, $form))->toBeFalse($feld);
    }
    expect(array_key_exists('container_warm_count', $form))->toBeTrue();   // die Anzahlen bleiben am Gericht
});

it('eine in der Zeile gesetzte Einheit übersteht ein Speichern im Kopf', function () {
    $c = ($this->modal)();
    $c->set("darForm.{$this->standard->id}.unit_vocab_id", $this->stk->id)->call('darreichungSpeichern', $this->standard->id)->assertSet('fehler', null);
    expect((int) ($this->zeile)()->unit_vocab_id)->toBe((int) $this->stk->id);

    $c->call('speichern')->assertSet('fehler', null);

    expect((int) ($this->zeile)()->unit_vocab_id)->toBe((int) $this->stk->id)
        ->and((int) $c->get("darForm.{$this->standard->id}.unit_vocab_id"))->toBe((int) $this->stk->id);
});

it('Maske: eine Portion je Darreichung, keine zweite Mengenpflege im Kopf', function () {
    $html = Livewire::test(VkModal::class)->call('oeffnen', $this->gericht->id)->html();

    expect($html)->toContain('data-vk-darreichungen', 'data-vk-nebenkosten')
        ->not->toContain('data-vk-unit-block', 'data-vk-unit-select', 'data-vk-anzahl', 'data-vk-g-unit')
        ->not->toContain('Preis und Rohertrag')->not->toContain('vk-preisklasse')->not->toContain('data-vk-netto-manuell')->not->toContain('data-vk-mwst');
});
