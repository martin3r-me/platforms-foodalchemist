<?php

use Illuminate\Support\Facades\DB;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Services\MargeService;
use Platform\FoodAlchemist\Services\PaketGrundeinstellungenService;
use Platform\FoodAlchemist\Support\MasterTeam;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;
use Symfony\Component\Uid\UuidV7;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Plan B (Kurator 18.10.2026): das Paket kopiert das Vokabular des Masters einmalig in den Mandanten — Aufschlagsklassen ohne BHG-Faktoren,
 * alle Verweise auf die Kopien (nie auf Master-Zeilen), „bewusst gelöscht“ über die Zuordnung, Vorschau mit Zahlen, Rücknahme nur unberührter Kopien.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->master = Team::create(['name' => 'BHG.DIGITAL', 'user_id' => 1, 'personal_team' => false]);
    config(['foodalchemist.master_team_id' => $this->master->id]);
    MasterTeam::flush();
    $this->admin = $this->makeUser($this->rootTeam, 'Plattform Admin');
    config(['platform-shell.admins' => [strtolower($this->admin->email)]]);
    $this->actingAs($this->admin);
    $this->svc = app(PaketGrundeinstellungenService::class);
    $this->mandant = $this->rootTeam;

    $this->ins = fn (string $t, array $z, ?int $team = -1) => DB::table('foodalchemist_'.$t)->insertGetId($z + ['uuid' => (string) UuidV7::generate(),
        'team_id' => $team === -1 ? $this->master->id : $team, 'created_at' => now()->subDay(), 'updated_at' => now()->subDay()]);
    $ins = $this->ins;
    $this->hg = $ins('recipe_main_groups', ['code' => 'SAU', 'label' => 'Saucen']);
    $this->kat = $ins('recipe_categories', ['main_group_id' => $this->hg, 'code' => 'SAU-JUS', 'label' => 'Jus']);
    $this->klasseA = $ins('markup_classes', ['code' => 'A', 'label' => 'Klasse A', 'class_factor_pct' => 140, 'raw_markup_pct' => 250, 'service_pct' => 5, 'profit_pct' => 8, 'legacy_id' => 900001]);
    $this->klasseGlobal = $ins('markup_classes', ['code' => 'G', 'label' => 'Global'], null);
    $this->spHg = $ins('dish_main_groups', ['code' => 'HG', 'label' => 'Hauptgang', 'default_markup_class_id' => $this->klasseA]);
    $this->spKl = $ins('dish_classes', ['code' => 'HG-FL', 'label' => 'Fleisch', 'dish_main_group_id' => $this->spHg, 'default_markup_class_id' => $this->klasseA]);
    $ins('serving_forms', ['code' => 'teller', 'label' => 'Teller', 'legacy_id' => 4711]);
    $ins('vocab_containers', ['slug' => 'gn11', 'name' => 'GN 1/1']);
    $ins('vocab_kitchen_equipment', ['slug' => 'thermomix', 'name' => 'Thermomix']);
    $ins('vocab_regeneration_devices', ['slug' => 'kombi', 'name' => 'Kombidämpfer']);
    $ins('event_types', ['name' => 'Hochzeit']);
    $ins('service_moments', ['name' => 'Mittag']);
    $ins('seasons', ['name' => 'Herbst']);
    DB::table('foodalchemist_team_settings')->insert(['uuid' => (string) UuidV7::generate(), 'team_id' => $this->master->id, 'default_markup_class_id' => $this->klasseA, 'created_at' => now(), 'updated_at' => now()]);

    $this->kopie = fn (string $t, int $quellId) => DB::table('foodalchemist_paket_kopien')->where('team_id', $this->mandant->id)->where('tabelle', $t)
        ->where('quell_id', $quellId)->value('kopie_id');
});

it('★ buchen kopiert das Vokabular: Aufschlagsklasse neutral ohne BHG-Werte, Verweise auf die eigenen Kopien, globale Zeilen nicht', function () {
    $r = $this->svc->buchen($this->mandant, $this->admin);

    $klasse = DB::table('foodalchemist_markup_classes')->find(($this->kopie)('markup_classes', $this->klasseA));
    $spKl = DB::table('foodalchemist_dish_classes')->find(($this->kopie)('dish_classes', $this->spKl));
    $spHg = DB::table('foodalchemist_dish_main_groups')->find(($this->kopie)('dish_main_groups', $this->spHg));
    $kat = DB::table('foodalchemist_recipe_categories')->find(($this->kopie)('recipe_categories', $this->kat));
    expect((int) $klasse->team_id)->toBe($this->mandant->id)->and($klasse->code)->toBe('A')
        ->and((float) $klasse->class_factor_pct)->toBe(100.0)->and($klasse->raw_markup_pct)->toBeNull()
        ->and($klasse->service_pct)->toBeNull()->and($klasse->profit_pct)->toBeNull()->and($klasse->legacy_id)->toBeNull()
        ->and((int) $spKl->default_markup_class_id)->toBe((int) $klasse->id)
        ->and((int) $spKl->dish_main_group_id)->toBe((int) $spHg->id)
        ->and((int) $spHg->default_markup_class_id)->toBe((int) $klasse->id)
        ->and((int) $kat->main_group_id)->toBe((int) ($this->kopie)('recipe_main_groups', $this->hg))
        ->and(DB::table('foodalchemist_serving_forms')->where('team_id', $this->mandant->id)->where('code', 'teller')->value('legacy_id'))->toBeNull()
        ->and(DB::table('foodalchemist_markup_classes')->where('team_id', $this->mandant->id)->where('code', 'G')->exists())->toBeFalse()
        ->and(DB::table('foodalchemist_team_settings')->where('team_id', $this->mandant->id)->value('default_markup_class_id'))->toBe((int) $klasse->id);
    foreach (['vocab_containers' => 'gn11', 'vocab_kitchen_equipment' => 'thermomix', 'vocab_regeneration_devices' => 'kombi'] as $t => $slug) {
        expect(DB::table('foodalchemist_'.$t)->where('team_id', $this->mandant->id)->where('slug', $slug)->exists())->toBeTrue($t);
    }
    foreach (['event_types' => 'Hochzeit', 'service_moments' => 'Mittag', 'seasons' => 'Herbst'] as $t => $name) {
        expect(DB::table('foodalchemist_'.$t)->where('team_id', $this->mandant->id)->where('name', $name)->exists())->toBeTrue($t);
    }
    // keine Kopie zeigt auf eine Master-Zeile
    $masterIds = DB::table('foodalchemist_markup_classes')->where('team_id', $this->master->id)->pluck('id')->all();
    expect(DB::table('foodalchemist_dish_classes')->where('team_id', $this->mandant->id)->whereIn('default_markup_class_id', $masterIds)->exists())->toBeFalse();
});

it('★ einmalige Befüllung: Umbenanntes, Deaktiviertes, Gelöschtes kommt beim erneuten Buchen nicht wieder — nur Neues aus dem Master', function () {
    $this->svc->buchen($this->mandant, $this->admin);
    $kat = ($this->kopie)('recipe_categories', $this->kat);
    $klasse = ($this->kopie)('markup_classes', $this->klasseA);
    $form = DB::table('foodalchemist_serving_forms')->where('team_id', $this->mandant->id)->where('code', 'teller')->value('id');
    DB::table('foodalchemist_recipe_categories')->where('id', $kat)->update(['label' => 'Unser Jus']);
    DB::table('foodalchemist_markup_classes')->where('id', $klasse)->update(['is_inactive' => true, 'class_factor_pct' => 120]);
    DB::table('foodalchemist_serving_forms')->where('id', $form)->update(['deleted_at' => now()]);          // soft gelöscht
    DB::table('foodalchemist_event_types')->where('team_id', $this->mandant->id)->delete();               // hart gelöscht
    ($this->ins)('seasons', ['name' => 'Winter']);                                                       // neu im Master

    $r = $this->svc->buchen($this->mandant, $this->admin);

    expect(DB::table('foodalchemist_recipe_categories')->find($kat)->label)->toBe('Unser Jus')
        ->and((bool) DB::table('foodalchemist_markup_classes')->find($klasse)->is_inactive)->toBeTrue()
        ->and((float) DB::table('foodalchemist_markup_classes')->find($klasse)->class_factor_pct)->toBe(120.0)
        ->and(DB::table('foodalchemist_serving_forms')->where('team_id', $this->mandant->id)->where('code', 'teller')->whereNull('deleted_at')->exists())->toBeFalse()
        ->and(DB::table('foodalchemist_event_types')->where('team_id', $this->mandant->id)->exists())->toBeFalse()
        ->and(DB::table('foodalchemist_seasons')->where('team_id', $this->mandant->id)->pluck('name')->sort()->values()->all())->toBe(['Herbst', 'Winter'])
        ->and(collect($r['ergebnis'])->firstWhere('tabelle', 'seasons'))->toMatchArray(['neu' => 1, 'bereits' => 1]);

    $v = collect($this->svc->vorschau($this->mandant)['tabellen']);
    expect($v->firstWhere('tabelle', 'serving_forms')['beim_mandanten_geloescht'])->toBe(1)
        ->and($v->firstWhere('tabelle', 'event_types')['beim_mandanten_geloescht'])->toBe(1)
        ->and($v->firstWhere('tabelle', 'seasons'))->toMatchArray(['quelle' => 2, 'bereits' => 2, 'neu' => 0]);
});

it('optionaler Verweis ohne Gegenstück wird NULL (gezählt), nie die Master-ID; eine vorhandene Mandanten-Zeile gleichen Codes wird zugeordnet, nicht überschrieben', function () {
    $eigene = ($this->ins)('markup_classes', ['code' => 'A', 'label' => 'Unsere A', 'class_factor_pct' => 115], $this->mandant->id);
    $geloescht = ($this->ins)('markup_classes', ['code' => 'X', 'label' => 'Weg', 'deleted_at' => now()]);
    $hg2 = ($this->ins)('dish_main_groups', ['code' => 'VS', 'label' => 'Vorspeise', 'default_markup_class_id' => $geloescht]);

    $r = $this->svc->buchen($this->mandant, $this->admin);

    expect(($this->kopie)('markup_classes', $this->klasseA))->toBe($eigene)
        ->and((float) DB::table('foodalchemist_markup_classes')->find($eigene)->class_factor_pct)->toBe(115.0)       // nie überschrieben
        ->and(DB::table('foodalchemist_dish_main_groups')->find(($this->kopie)('dish_main_groups', $hg2))->default_markup_class_id)->toBeNull()
        ->and(collect($r['ergebnis'])->firstWhere('tabelle', 'dish_main_groups')['verweis_genullt'])->toBe(1);
});

it('Rücknahme: Vorschau ändert nichts; entfernt nur unveränderte und unbenutzte Kopien, Geändertes und Verwendetes bleibt mit Grund', function () {
    $this->svc->buchen($this->mandant, $this->admin);
    $kat = ($this->kopie)('recipe_categories', $this->kat);
    $saison = DB::table('foodalchemist_seasons')->where('team_id', $this->mandant->id)->value('id');
    $this->makeRecipe($this->mandant, 'Eigener Jus', ['category_id' => $kat]);                       // verwendet
    DB::table('foodalchemist_seasons')->where('id', $saison)->update(['name' => 'Unser Herbst', 'updated_at' => now()->addMinute()]);   // geändert

    $vorschau = $this->svc->zuruecknehmen($this->mandant, $this->admin, false);
    expect(DB::table('foodalchemist_event_types')->where('team_id', $this->mandant->id)->exists())->toBeTrue()
        ->and($vorschau['entfernt']['event_types'] ?? 0)->toBe(1);

    $r = $this->svc->zuruecknehmen($this->mandant, $this->admin, true);
    $bleibt = collect($r['bleibt'])->keyBy(fn ($b) => $b['tabelle'].':'.$b['id']);
    expect(DB::table('foodalchemist_event_types')->where('team_id', $this->mandant->id)->exists())->toBeFalse()
        ->and(DB::table('foodalchemist_recipe_categories')->where('id', $kat)->exists())->toBeTrue()
        ->and($bleibt["recipe_categories:{$kat}"]['grund'])->toContain('verwendet')
        ->and($bleibt["seasons:{$saison}"]['grund'])->toBe('vom Mandanten geändert')
        ->and(DB::table('foodalchemist_recipe_main_groups')->where('team_id', $this->mandant->id)->exists())->toBeTrue();   // Eltern der verwendeten Kategorie
});

it('F5: Aufschlagsklasse ohne Aufschlag (Paket-Kopie) liefert keinen Preis statt VK = EK', function () {
    $marge = app(MargeService::class);
    expect($marge->vkVorschlag(5.20, 250.0, (object) ['code' => 'A', 'raw_markup_pct' => null, 'formula_type' => 'aufschlag']))->toBeNull()
        ->and($marge->vkVorschlag(5.20, 250.0, (object) ['code' => 'A', 'raw_markup_pct' => 0, 'formula_type' => 'aufschlag'])['sales_net'])->toBe(1.3);
});

it('Spec 84 Stufe 4: Buchen aus dem Master-Kontext findet die vorhandene Einstellungszeile und den Pass des Mandanten — keine zweite Zeile', function () {
    config(['foodalchemist.team_sicht.stufe' => 4]);
    $passId = ($this->ins)('production_stations', ['slug' => 'pass', 'name' => 'Pass', 'group_name' => 'Ausgabe', 'sort_order' => 900], $this->mandant->id);
    ($this->ins)('team_settings', ['ai_active' => false], $this->mandant->id);

    \Platform\FoodAlchemist\Support\FaTeam::imTeam($this->master, fn () => $this->svc->buchen($this->mandant, $this->admin));

    $zeilen = DB::table('foodalchemist_team_settings')->where('team_id', $this->mandant->id)->get();
    expect($zeilen)->toHaveCount(1)
        ->and((bool) $zeilen[0]->ai_active)->toBeFalse()                              // vorhandene Zeile ergänzt, nicht neu angelegt
        ->and((int) $zeilen[0]->gerichte_station_id)->toBe((int) $passId)              // der vorhandene Pass, kein zweiter
        ->and((int) $zeilen[0]->default_markup_class_id)->toBe((int) ($this->kopie)('markup_classes', $this->klasseA))
        ->and(DB::table('foodalchemist_production_stations')->where('team_id', $this->mandant->id)->where('slug', 'pass')->count())->toBe(1);
});
