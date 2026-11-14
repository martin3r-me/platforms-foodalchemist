<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Platform\FoodAlchemist\Models\FoodAlchemistRule;
use Platform\FoodAlchemist\Services\GpNamingService;
use Platform\FoodAlchemist\Services\Regeln\RegelBuch;
use Platform\FoodAlchemist\Services\Regeln\RegelMotor;
use Platform\FoodAlchemist\Support\RezeptTypVokabular;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * GP-/Basisrezept-Regelwerk v3.10.0 (13.11.2026): Daten-Migrationen der Untergruppen (316-heimat-tabelle-teil-2) und der Regeln
 * (gp.8.4.garnelen, gp.7.1.gebinde/7.1a, gp.8.12.kartoffel, gp.8.1.fett, basisrezept.1.2.typ). Beide idempotent.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->mig = fn (string $datei) => require dirname(__DIR__, 2) . '/database/migrations/' . $datei;
    $this->regel = fn (string $s) => FoodAlchemistRule::query()->whereNull('team_id')->where('schluessel', $s)->firstOrFail();
    $this->pruefe = fn (string $s, string $text, array $kontext) => app(RegelMotor::class)->pruefe(($this->regel)($s), $text, $kontext);
});

it('Untergruppen: 06.9 + 10.8 neu, 06.8 + 15.9 + Aperitifs aufgelöst, WG 05 neu gegliedert, 04.7 umbenannt — GP-Werte unberührt, 2× up gleich', function () {
    $t = 'foodalchemist_commodity_group_subcategories';
    $neu = fn (?int $team, string $wg, string $name) => DB::table($t)->insert(['uuid' => (string) Str::uuid(), 'team_id' => $team, 'commodity_group_code' => $wg,
        'name' => $name, 'position' => 0, 'created_at' => now(), 'updated_at' => now()]);
    $neu(null, '04', '04.7 Wurstwaren');
    $neu(null, '04', '04.1 Rind');
    $neu(null, '05', '05.2 Fisch Filet');
    $neu(null, '05', '05.6 Meeresfrüchte');
    $neu(null, '06', '06.8 Laktosefreie Produkte');
    $neu(null, '06', '06.5 Käse');
    $neu(null, '15', '15.9 Sirupe für Bar');
    $neu(null, '15', '15.4 Aperitifs');
    $neu(null, '15', '15.1 Wein');
    $neu($this->rootTeam->id, '06', 'Laktosefrei');
    DB::table('foodalchemist_gps')->insert(['uuid' => (string) Str::uuid(), 'team_id' => $this->rootTeam->id, 'gp_key' => 'milch||', 'name' => 'Milch: frisch, 3,5 % Fett',
        'main_ingredient_slug' => 'milch', 'commodity_group_code' => '06', 'sub_category' => '06.8 Laktosefreie Produkte', 'status' => 'approved',
        'created_at' => now(), 'updated_at' => now()]);

    $m = ($this->mig)('2026_12_28_200000_subkategorien_regelwerk_v310.php');
    $m->up();
    $stand = fn () => DB::table($t)->whereNull('deleted_at')->orderBy('commodity_group_code')->orderBy('name')->get(['commodity_group_code', 'name'])
        ->map(fn ($z) => $z->commodity_group_code . ' ' . $z->name)->all();
    $erst = $stand();
    $m->up();

    expect($stand())->toBe($erst)
        ->and($erst)->toBe([
            '04 04.1 Rind', '04 04.7 Wurst, Schinken, Pökelware, Aufschnitt',
            '05 05.1 Fisch', '05 05.2 Geräuchert und gebeizt', '05 05.3 Konserven und Kaviar', '05 05.4 Meeresfrüchte',
            '06 06.5 Käse', '06 06.9 Portionsware',
            '10 10.8 Portionsware',
            '11 11.4 Schmalz & feste Fette',   // spätere Migration 2026_12_29_100000 (Regel 830 Teil 2) läuft in der Test-DB mit
            '15 15.1 Wein',
        ])
        ->and(DB::table('foodalchemist_gps')->where('gp_key', 'milch||')->value('sub_category'))->toBe('06.8 Laktosefreie Produkte');   // der GP-Lauf hängt um
});

it('Regeln v3.10.0: Garnelen-Kaliber nackt, Kochtyp nur rohe ganze Kartoffel, Fett exakt mit „Fett“', function () {
    $g = fn (string $text) => ($this->pruefe)('gp.8.4.garnelen', $text, ['hauptzutat' => trim((string) strstr($text, ':', true))]);
    expect($g('Garnele Black Tiger: roh, geschält, TK, 16/20'))->toBe([])
        ->and($g('Garnele: gekocht, TK, 16/20 / (MSC)'))->toBe([])
        ->and($g('Garnele: gekocht, geschält, TK, Kaliber 13/15'))->not->toBe([])
        ->and($g('Garnele: roh, TK, 16/20 pro lb'))->not->toBe([]);

    $k = fn (string $text, string $zustand, string $verarbeitet) => ($this->pruefe)('gp.8.12.kartoffel', $text,
        ['hauptzutat' => 'Kartoffel', 'zustand' => $zustand, 'verarbeitet' => $verarbeitet]);
    expect($k('Kartoffel: frisch', 'frisch', 'nein'))->not->toBe([])
        ->and($k('Kartoffel: Würfel 5 mm, frisch', 'frisch', 'ja'))->toBe([])
        ->and($k('Kartoffel: Wedges, TK', 'TK', 'ja'))->toBe([])
        ->and($k('Kartoffel: frisch, vorwiegend festkochend', 'frisch', 'nein'))->toBe([]);

    $f = fn (string $text) => ($this->pruefe)('gp.8.1.fett', $text, ['hauptzutat' => trim((string) strstr($text, ':', true))]);
    expect($f('Sahne: frisch, 33 % Fett'))->toBe([])
        ->and($f('Frischkäse: frisch, 60 % Fett i. Tr.'))->toBe([])
        ->and($f('Sahne: frisch, 33 %'))->not->toBe([])
        ->and($f('Butter: frisch, Süßrahm'))->toBe([]);

    // Kontext „verarbeitet“ liefert die GP-Prüfung selbst
    $regel = ($this->regel)('gp.8.12.kartoffel');
    $regel->update(['aktiv' => true]);
    RegelBuch::vergessen();
    $n = app(GpNamingService::class);
    expect(implode(' ', $n->validateGpName('Kartoffel: frisch', ['hauptzutat' => 'Kartoffel', 'condition' => 'frisch'])['warnings']))->toContain('Kochtyp')
        ->and(implode(' ', $n->validateGpName('Kartoffel: gegart, geschält, frisch', ['hauptzutat' => 'Kartoffel', 'processing' => 'gegart, geschält', 'condition' => 'frisch'])['warnings']))->not->toContain('Kochtyp');
});

it('Regeln v3.10.0: Beutel und Kanister sind Getränke-Einzelgebinde, Kanister sonst Verpackungswort', function () {
    $n = app(GpNamingService::class);
    $fehler = fn (string $name, string $wg) => $n->validateGpName($name, ['warengruppe' => $wg])['errors'];
    expect($fehler('Capri Orange: Beutel 0,2 l', '15'))->toBe([])
        ->and($fehler('Apfelsaft: Kanister 5 l', '15'))->toBe([])
        ->and($fehler('Pils: Kasten 20 x 0,5 l', '15'))->not->toBe([])
        ->and($fehler('Rapsöl: Kanister 10 l', '11'))->not->toBe([]);
});

it('Basisrezept-Typen: 11 neue Typen, Sous-Vide/Garmethode keine Typen, eigene Hauptgruppe je neuem Typ', function () {
    RezeptTypVokabular::vergessen();
    foreach (['Fleisch', 'Fisch', 'Geflügel', 'Gemüse', 'Füllung', 'Gewürzmischung', 'Buttermischung', 'Kuchen', 'Gebäck', 'Pasta', 'Topping'] as $t) {
        expect(RezeptTypVokabular::finde($t))->toBe($t, $t);
    }
    expect(RezeptTypVokabular::finde('Sous-Vide'))->toBeNull()
        ->and(RezeptTypVokabular::finde('Garmethode'))->toBeNull()
        ->and(RezeptTypVokabular::finde('Confit'))->toBe('Confit')
        ->and(array_intersect(RezeptTypVokabular::gruppenVon('Fleisch'), RezeptTypVokabular::gruppenVon('Kuchen')))->toBe([]);
});

it('Regel-Migration ist idempotent: zweiter Lauf schreibt keine neue Version', function () {
    $versionen = fn () => FoodAlchemistRule::query()->whereNull('team_id')
        ->whereIn('schluessel', ['gp.8.4.garnelen', 'gp.7.1.gebinde', 'gp.7.1a.sammelgebinde', 'gp.8.12.kartoffel', 'gp.8.1.fett', 'basisrezept.1.2.typ'])
        ->orderBy('schluessel')->pluck('version', 'schluessel')->all();
    $vorher = $versionen();
    ($this->mig)('2026_12_28_300000_regeln_regelwerk_v310.php')->up();
    expect($versionen())->toBe($vorher)
        ->and(($this->regel)('gp.8.4.garnelen')->aktiv)->toBeFalse()   // Aktiv-Status bleibt (Aktivieren = Kuration)
        ->and(($this->regel)('gp.7.1.gebinde')->aktiv)->toBeTrue()
        ->and(in_array('Beutel', (array) ($this->regel)('gp.7.1a.sammelgebinde')->params['tokens'], true))->toBeFalse();
});
