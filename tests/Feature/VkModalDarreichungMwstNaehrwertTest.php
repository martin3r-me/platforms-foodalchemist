<?php

use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Platform\FoodAlchemist\Livewire\Verkauf\VkModal;
use Platform\FoodAlchemist\Models\FoodAlchemistMarkupClass;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeDarreichung;
use Platform\FoodAlchemist\Services\Rezept\DarreichungService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Gericht-Modal, Befunde Dominique 18.11.2026:
 *  1. MwSt der Darreichung sprang nach dem Speichern auf „ermäßigt“ — der Preis-Recompute schrieb den AUFGELÖSTEN Schlüssel
 *     in `vat_profile_key` zurück, die Wahl „aus Preisklasse“ (leer) ging so verloren.
 *  2. Nährwerte „je Einheit“ blieben leer, wenn das Gericht keine Verkaufseinheit trägt — jetzt Grammatur der Standard-Darreichung.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam));
    $this->g = $this->unitG($this->rootTeam);
    $this->gericht = $this->makeRecipe($this->rootTeam, '[VOR] Perlcouscous-Salat', ['is_sales_recipe' => true, 'status' => 'draft']);
    $this->standard = app(DarreichungService::class)->ensureStandard($this->rootTeam, $this->gericht->id, 'generator');
    // #853: die Standard-Darreichung folgt sonst der Komponentensumme — hier eine eigene Portionsmenge (120 g)
    $this->standard->forceFill(['quantity_per_unit_g' => 120, 'unit_count' => 1, 'vat_profile_key' => null, 'menge_vom_standard' => false])->save();
    $this->zeile = fn () => FoodAlchemistRecipeDarreichung::findOrFail($this->standard->id);
    $this->modal = fn () => Livewire::test(VkModal::class)->call('oeffnen', $this->gericht->id)->call('bearbeitenStarten');
});

it('„aus Preisklasse“ bleibt nach Speichern und Neuladen leer (vorher: sprang auf ermäßigt)', function () {
    $c = ($this->modal)();
    $c->set("darForm.{$this->standard->id}.vat_profile_key", '')->call('darreichungSpeichern', $this->standard->id)->assertSet('fehler', null);

    expect(($this->zeile)()->vat_profile_key)->toBeNull()
        ->and($c->get("darForm.{$this->standard->id}.vat_profile_key"))->toBeNull();
    // auch ein späterer Recompute (anderes Feld gespeichert) legt nichts fest
    app(DarreichungService::class)->aktualisieren($this->rootTeam, $this->standard->id, ['unit_count' => '1']);
    expect(($this->zeile)()->vat_profile_key)->toBeNull();
    ($this->modal)()->assertSet("darForm.{$this->standard->id}.vat_profile_key", null)->assertSee('aus Preisklasse (ermäßigt)');
});

it('eine ausdrückliche Wahl bleibt — und „aus Preisklasse“ folgt der Preisklasse samt Brutto', function () {
    $c = ($this->modal)();
    $c->set("darForm.{$this->standard->id}.vat_profile_key", 'regulaer')->call('darreichungSpeichern', $this->standard->id)->assertSet('fehler', null);
    expect(($this->zeile)()->vat_profile_key)->toBe('regulaer');
    ($this->modal)()->assertSet("darForm.{$this->standard->id}.vat_profile_key", 'regulaer');

    $reg = FoodAlchemistMarkupClass::create(['team_id' => $this->rootTeam->id, 'code' => 'REG', 'label' => 'Regulär',
        'class_factor_pct' => 100, 'bereich' => 'speisen', 'is_inactive' => false, 'vat_profile_key' => 'regulaer']);
    $c = ($this->modal)();
    $c->set("darForm.{$this->standard->id}.vat_profile_key", '')->set("darForm.{$this->standard->id}.markup_class_id", $reg->id)
        ->call('darreichungSpeichern', $this->standard->id)->assertSet('fehler', null)->assertSee('aus Preisklasse (regulär)');
    $z = ($this->zeile)();
    expect($z->vat_profile_key)->toBeNull();
    if ($z->sales_net !== null) {
        expect((float) $z->sales_gross)->toBe(round((float) $z->sales_net * 1.19, 2));
    }
});

it('Befehl darreichung-mwst-leeren: leert nur festgeschriebene Schlüssel, Trockenlauf ändert nichts, --zurueck stellt her', function () {
    $zweite = app(DarreichungService::class)->ensureStandard($this->rootTeam, $this->makeRecipe($this->rootTeam, '[VOR] Zwei', ['is_sales_recipe' => true])->id, 'generator');
    DB::table('foodalchemist_recipe_presentations')->where('id', $this->standard->id)->update(['vat_profile_key' => 'ermaessigt']);   // = aus Preisklasse
    DB::table('foodalchemist_recipe_presentations')->where('id', $zweite->id)->update(['vat_profile_key' => 'regulaer']);           // ausdrückliche Wahl
    $dir = sys_get_temp_dir() . '/fa-mwst-' . uniqid();

    $this->artisan('foodalchemist:darreichung-mwst-leeren', ['--bericht' => $dir])->assertSuccessful();
    expect(($this->zeile)()->vat_profile_key)->toBe('ermaessigt');

    $this->artisan('foodalchemist:darreichung-mwst-leeren', ['--bericht' => $dir, '--apply' => true])->assertFailed();   // ohne Backup-Nachweis
    $backup = $dir . '/dump.sql';
    @mkdir($dir, 0777, true);
    file_put_contents($backup, str_repeat('x', 2048));
    $this->artisan('foodalchemist:darreichung-mwst-leeren', ['--bericht' => $dir, '--apply' => true, '--backup-nachweis' => $backup])->assertSuccessful();
    expect(($this->zeile)()->vat_profile_key)->toBeNull()
        ->and(FoodAlchemistRecipeDarreichung::find($zweite->id)->vat_profile_key)->toBe('regulaer');

    $protokoll = collect(glob($dir . '/*_darreichung_mwst_protokoll.json'))->first();
    $this->artisan('foodalchemist:darreichung-mwst-leeren', ['--zurueck' => $protokoll])->assertSuccessful();
    expect(($this->zeile)()->vat_profile_key)->toBe('ermaessigt');
});

it('Nährwerte je Einheit: ohne Verkaufseinheit am Gericht nach der Grammatur der Standard-Darreichung', function () {
    $this->gericht->forceFill(['nutri_kcal_per_100g' => 150, 'nutri_salt_g_per_100g' => 1.0, 'yield_kg' => null])->save();

    ($this->modal)()
        ->assertSee('je Einheit (≈ 120 g)')
        ->assertSee('180 kcal')          // 150 × 120 / 100
        ->assertSee('1,20 g')
        ->assertSee('Je 100 g Ausbeute (nach Gar- und Putzverlust), Kochmedium nur anteilig.')
        ->assertDontSee('Rohwerte ohne Gar- und Putzverlust');
});
