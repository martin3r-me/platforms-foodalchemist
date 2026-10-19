<?php

use Illuminate\Support\Facades\Log;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistRule;
use Platform\FoodAlchemist\Services\Matching\MatchHeuristics;
use Platform\FoodAlchemist\Services\Regeln\RegelBuch;
use Platform\FoodAlchemist\Services\Regeln\RegelPromptBlock;
use Platform\FoodAlchemist\Support\FaTeam;
use Platform\FoodAlchemist\Support\TeamAncestryRegistry;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 85 D-7 · Team-Regeln im RegelBuch (Lesepfad). Auflösung je Schlüssel: eigenes Team > Oberteam > global, Kette gekappt an der
 * Kundengrenze, nur Schlüssel aus RegelBuch::UEBERSTEUERBAR. Team = Kontext (Seite/Tool/Job) oder ausdrücklich; ohne Team nur global.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->master = Team::create(['name' => 'Master', 'user_id' => 1, 'personal_team' => false]);
    $this->mandant = Team::create(['name' => 'Mandant', 'user_id' => 1, 'personal_team' => false]);
    $this->bereich = Team::create(['name' => 'Bereich', 'user_id' => 1, 'personal_team' => false, 'parent_team_id' => $this->mandant->id]);
    $this->nachbar = Team::create(['name' => 'Nachbar', 'user_id' => 1, 'personal_team' => false]);
    $this->unterMaster = Team::create(['name' => 'Mandant unter Master', 'user_id' => 1, 'personal_team' => false, 'parent_team_id' => $this->master->id]);
    config(['foodalchemist.master_team_id' => $this->master->id]);
    TeamAncestryRegistry::flushAll();
    RegelBuch::vergessen();

    // Default-GP-Regel (Zuordnung): „Öl“ → Ziel je Ebene
    $this->regel = function (?int $team, string $ziel, string $schluessel = 'basisrezept.5.default_gp', bool $aktiv = true) {
        return FoodAlchemistRule::ohneTeamSicht('test')->create(['team_id' => $team, 'schluessel' => $schluessel, 'regelwerk' => 'basisrezept',
            'paragraph' => '§5', 'titel' => 'Default-GP', 'art' => 'zuordnung', 'ziel' => 'rezeptzeile', 'wirkung' => 'korrigieren', 'aktiv' => $aktiv,
            'params' => ['vergleich' => 'tokens', 'eintraege' => [['begriff' => 'Öl', 'ziel_typ' => 'gp', 'ziel_name' => $ziel]]], 'created_via' => 'test']);
    };
    FoodAlchemistRule::ohneTeamSicht('test')->whereIn('schluessel', ['basisrezept.5.default_gp', 'gp.10.generik'])->forceDelete();   // Seeds der Migration
    ($this->regel)(null, 'Rapsöl: raffiniert');
    $this->ziel = fn (?Team $t) => RegelBuch::per('basisrezept.5.default_gp', $t)?->params['eintraege'][0]['ziel_name'];
});

it('Auflösung: eigenes Team > Oberteam > global; Geschwister und Nachbar sehen die Abweichung nie', function () {
    ($this->regel)($this->mandant->id, 'Sonnenblumenöl: raffiniert');

    expect(($this->ziel)($this->mandant))->toBe('Sonnenblumenöl: raffiniert')
        ->and(($this->ziel)($this->bereich))->toBe('Sonnenblumenöl: raffiniert')   // erbt vom Oberteam
        ->and(($this->ziel)($this->nachbar))->toBe('Rapsöl: raffiniert')
        ->and(($this->ziel)(null))->toBe('Rapsöl: raffiniert');

    ($this->regel)($this->bereich->id, 'Olivenöl: nativ extra');
    RegelBuch::vergessen();
    expect(($this->ziel)($this->bereich))->toBe('Olivenöl: nativ extra')
        ->and(($this->ziel)($this->mandant))->toBe('Sonnenblumenöl: raffiniert')
        ->and(RegelBuch::herkunft(RegelBuch::per('basisrezept.5.default_gp', $this->bereich)))->toBe('team:' . $this->bereich->id);
});

it('Kappung: eine Team-Regel des Masters gilt nie beim Mandanten darunter — der Master selbst nutzt sie', function () {
    ($this->regel)($this->master->id, 'Master-Öl');

    expect(($this->ziel)($this->unterMaster))->toBe('Rapsöl: raffiniert')
        ->and(($this->ziel)($this->master))->toBe('Master-Öl');
});

it('Freigabeliste im Lesepfad: Team-Zeile auf nicht übersteuerbarem Schlüssel oder ohne globale Regel wird ignoriert und einmal geloggt', function () {
    Log::spy();
    $fremd = ($this->regel)($this->mandant->id, 'egal', 'gp.10.generik');          // gibt es nicht global + nicht freigegeben
    FoodAlchemistRule::ohneTeamSicht('test')->create(['team_id' => null, 'schluessel' => 'gp.10.generik', 'regelwerk' => 'gp', 'titel' => 'Generik',
        'art' => 'verbot', 'ziel' => 'gp.name', 'wirkung' => 'blockieren', 'aktiv' => true, 'params' => ['tokens' => ['diverse']], 'created_via' => 'test']);
    RegelBuch::vergessen();

    expect((int) RegelBuch::per('gp.10.generik', $this->mandant)->team_id)->toBe(0)   // global gewinnt — Team-Zeile nicht freigegeben
        ->and(RegelBuch::per('gp.10.generik', $this->mandant)->team_id)->toBeNull();
    RegelBuch::alle($this->mandant);
    Log::shouldHaveReceived('warning')->withArgs(fn ($m) => str_contains($m, 'gp.10.generik') && str_contains($m, 'ignoriert'))->once();
    expect(in_array('gp.10.generik', RegelBuch::UEBERSTEUERBAR, true))->toBeFalse()
        ->and(RegelBuch::UEBERSTEUERBAR)->toBe(['basisrezept.5.default_gp', 'gericht.kleinstmengen', 'basisrezept.bestand.haus_zuordnung',
            // D-7 PR 1b (Dominique 19.10.): HACCP-Temperaturen, lockerer nur bis zur belegten Untergrenze
            'haccp.heisshalten', 'haccp.kuehlen', 'haccp.schnellkuehlen', 'haccp.kuehlkette', 'haccp.abkuehlen',
            'haccp.standzeit_gefahrenzone', 'haccp.garen_zerkleinert', 'haccp.regenerieren']);
});

it('inaktive Team-Zeile wirkt nicht (kein Abschalten der globalen Regel über das Team)', function () {
    ($this->regel)($this->mandant->id, 'Sonnenblumenöl: raffiniert', aktiv: false);

    expect(($this->ziel)($this->mandant))->toBe('Rapsöl: raffiniert');
});

it('Kontext: ohne Team nur global, im Team-Kontext (Seite/Job) die Team-Werte; ausdrückliches Team schlägt den Kontext', function () {
    ($this->regel)($this->mandant->id, 'Sonnenblumenöl: raffiniert');
    $ohne = RegelBuch::per('basisrezept.5.default_gp')?->params['eintraege'][0]['ziel_name'];
    $imTeam = FaTeam::imTeam($this->mandant, fn () => RegelBuch::per('basisrezept.5.default_gp')?->params['eintraege'][0]['ziel_name']);
    $ausdruecklich = FaTeam::imTeam($this->mandant, fn () => ($this->ziel)($this->nachbar));

    expect($ohne)->toBe('Rapsöl: raffiniert')->and($imTeam)->toBe('Sonnenblumenöl: raffiniert')->and($ausdruecklich)->toBe('Rapsöl: raffiniert');
});

it('Memo je Team: zwei Teams im selben Prozess bekommen je ihre Werte; vergessen() leert alle', function () {
    ($this->regel)($this->mandant->id, 'Sonnenblumenöl: raffiniert');
    expect(($this->ziel)($this->mandant))->toBe('Sonnenblumenöl: raffiniert')->and(($this->ziel)($this->nachbar))->toBe('Rapsöl: raffiniert');

    ($this->regel)($this->nachbar->id, 'Erdnussöl');
    expect(($this->ziel)($this->nachbar))->toBe('Rapsöl: raffiniert');   // Memo hält 10 min
    RegelBuch::vergessen();
    expect(($this->ziel)($this->nachbar))->toBe('Erdnussöl')->and(($this->ziel)($this->mandant))->toBe('Sonnenblumenöl: raffiniert');
});

it('Konsument Ende-zu-Ende: Default-GP-Alias im Matching folgt der Team-Abweichung, der Nachbar bleibt global', function () {
    ($this->regel)($this->mandant->id, 'Sonnenblumenöl: raffiniert');
    $alias = fn (Team $t) => FaTeam::imTeam($t, fn () => app(MatchHeuristics::class)->defaultGpAlias(['oel'], false));

    expect($alias($this->mandant))->toBe('Sonnenblumenöl: raffiniert')
        ->and($alias($this->nachbar))->toBe('Rapsöl: raffiniert');
});

it('Stufe 0 und 4 liefern dieselbe Auflösung (eigene Kette, ohne Team-Sicht)', function () {
    ($this->regel)($this->mandant->id, 'Sonnenblumenöl: raffiniert');
    $je = [];
    foreach ([0, 4] as $stufe) {
        config(['foodalchemist.team_sicht.stufe' => $stufe]);
        RegelBuch::vergessen();
        $je[$stufe] = FaTeam::imTeam($this->bereich, fn () => [($this->ziel)(null), ($this->ziel)($this->bereich), ($this->ziel)($this->unterMaster)]);
    }

    expect($je[4])->toBe($je[0])->and($je[0][1])->toBe('Sonnenblumenöl: raffiniert');
});
