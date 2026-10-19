<?php

use Illuminate\Support\Facades\Log;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistRule;
use Platform\FoodAlchemist\Services\CulinaryReferenceService;
use Platform\FoodAlchemist\Services\Regeln\Arten\Schwelle;
use Platform\FoodAlchemist\Services\Regeln\RegelBuch;
use Platform\FoodAlchemist\Services\Regeln\RegelPromptBlock;
use Platform\FoodAlchemist\Support\FaTeam;
use Platform\FoodAlchemist\Support\TeamAncestryRegistry;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 85 D-7 PR 1b · HACCP-Temperaturen als Regeln (Dominique 19.10.). Werte aus den Dossiers (Saat-Migration), Team darf strenger,
 * lockerer nur bis zur belegten Untergrenze (heute nur Garen zerkleinert: DIN 10506 ≥ 72 °C/2 min), Prompt nennt beim lockereren
 * Team-Wert das gesetzliche Minimum, die Garstufen-Referenz nennt die greifende Sicherheitsregel.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->mandant = Team::create(['name' => 'Mandant', 'user_id' => 1, 'personal_team' => false]);
    $this->nachbar = Team::create(['name' => 'Nachbar', 'user_id' => 1, 'personal_team' => false]);
    TeamAncestryRegistry::flushAll();
    RegelBuch::vergessen();
    $this->abweichung = function (string $schluessel, array $params, ?Team $team = null) {
        $g = FoodAlchemistRule::ohneTeamSicht('test')->whereNull('team_id')->where('schluessel', $schluessel)->firstOrFail();
        $r = $g->replicate(['uuid']);
        $r->forceFill(['team_id' => ($team ?? $this->mandant)->id, 'params' => array_merge((array) $g->params, $params), 'created_via' => 'test'])->save();
        RegelBuch::vergessen();

        return $r;
    };
    $this->text = fn (string $s, ?Team $t) => Schwelle::text((array) RegelBuch::per($s, $t)->params);
});

it('Saat = Dossier: acht Schlüssel aktiv, Werte und Quelle der einzigen Untergrenze wörtlich', function () {
    $soll = [
        'haccp.heisshalten' => '>= 65 °C', 'haccp.kuehlen' => '< 4 °C', 'haccp.schnellkuehlen' => '< 3 °C in 90 min',
        'haccp.kuehlkette' => '<= 7 °C', 'haccp.abkuehlen' => '< 7 °C in 120 min', 'haccp.standzeit_gefahrenzone' => '<= 120 min',
        'haccp.garen_zerkleinert' => '> 75 °C, 2 min', 'haccp.regenerieren' => '>= 72 °C, 2 min',
    ];
    foreach ($soll as $s => $text) {
        $r = RegelBuch::per($s, null);
        expect($r)->not->toBeNull($s)->and($r->aktiv)->toBeTrue()->and($r->art)->toBe('schwelle')->and($r->wirkung)->toBe('warnen')
            ->and(Schwelle::text((array) $r->params))->toBe($text, $s)
            ->and(in_array($s, RegelBuch::UEBERSTEUERBAR, true))->toBeTrue();
    }
    $mitGrenze = collect(array_keys($soll))->filter(fn ($s) => isset(RegelBuch::per($s, null)->params['untergrenze']))->values()->all();
    expect($mitGrenze)->toBe(['haccp.garen_zerkleinert'])
        ->and(RegelBuch::per('haccp.garen_zerkleinert', null)->params['untergrenze_quelle'])->toContain('DIN 10506')
        ->and(RegelBuch::per('haccp.garen_zerkleinert', null)->params['geltung'])->toContain('Hack');
});

it('Schwelle validiert Dauer und Untergrenze (Untergrenze ohne Quelle abgelehnt)', function () {
    $s = new Schwelle;
    expect($s->validiere(['vergleich' => '>', 'wert' => 75, 'dauer' => 0]))->not->toBe([])
        ->and($s->validiere(['vergleich' => '>', 'wert' => 75, 'dauer' => 2, 'dauer_art' => 'irgendwie']))->not->toBe([])
        ->and($s->validiere(['vergleich' => '>', 'wert' => 75, 'untergrenze' => 72]))->toContain('untergrenze braucht eine Quelle (untergrenze_quelle: Norm/Gesetz wörtlich)')
        ->and($s->validiere(['vergleich' => '>', 'wert' => 75, 'dauer' => 2, 'untergrenze' => 72, 'untergrenze_quelle' => 'DIN 10506']))->toBe([]);
});

it('Team strenger wirkt; lockerer ohne belegte Untergrenze wird ignoriert (Heißhalten) — globaler Wert gilt, Log', function () {
    Log::spy();
    ($this->abweichung)('haccp.heisshalten', ['wert' => 70]);
    expect(($this->text)('haccp.heisshalten', $this->mandant))->toBe('>= 70 °C');

    FoodAlchemistRule::ohneTeamSicht('test')->where('team_id', $this->mandant->id)->forceDelete();
    ($this->abweichung)('haccp.heisshalten', ['wert' => 60]);
    expect(($this->text)('haccp.heisshalten', $this->mandant))->toBe('>= 65 °C');
    Log::shouldHaveReceived('warning')->withArgs(fn ($m) => str_contains($m, 'haccp.heisshalten') && str_contains($m, 'Untergrenze'))->once();
});

it('Garen zerkleinert: lockerer bis zur DIN-Untergrenze erlaubt, darunter oder kürzer ignoriert; geänderte Richtung ignoriert', function () {
    ($this->abweichung)('haccp.garen_zerkleinert', ['wert' => 72]);
    expect(($this->text)('haccp.garen_zerkleinert', $this->mandant))->toBe('> 72 °C, 2 min')
        ->and(($this->text)('haccp.garen_zerkleinert', $this->nachbar))->toBe('> 75 °C, 2 min');

    foreach ([['wert' => 70], ['wert' => 75, 'dauer' => 1], ['vergleich' => '<', 'wert' => 80]] as $p) {
        FoodAlchemistRule::ohneTeamSicht('test')->where('team_id', $this->mandant->id)->forceDelete();
        ($this->abweichung)('haccp.garen_zerkleinert', $p);
        expect(($this->text)('haccp.garen_zerkleinert', $this->mandant))->toBe('> 75 °C, 2 min', json_encode($p));
    }
});

it('Zeitregeln: Abkühlen länger als der Hausstandard (lockerer, keine Untergrenze) ignoriert, kürzer (strenger) wirkt', function () {
    ($this->abweichung)('haccp.abkuehlen', ['dauer' => 180]);
    expect(($this->text)('haccp.abkuehlen', $this->mandant))->toBe('< 7 °C in 120 min');
    FoodAlchemistRule::ohneTeamSicht('test')->where('team_id', $this->mandant->id)->forceDelete();
    ($this->abweichung)('haccp.abkuehlen', ['dauer' => 90]);
    expect(($this->text)('haccp.abkuehlen', $this->mandant))->toBe('< 7 °C in 90 min');
});

it('Prompt: global mit Geltung; lockererer Team-Wert mit gesetzlichem Minimum und Quelle; strenger als Team-Hausstandard; Nachbar global', function () {
    $block = fn (Team $t, string $key) => FaTeam::imTeam($t, fn () => (string) app(RegelPromptBlock::class)->fuerPromptKey($key));

    expect($block($this->nachbar, 'recipe.generator'))->toContain('> 75 °C, 2 min — gilt für Hack')
        ->and($block($this->nachbar, 'vk.regeneration'))->toContain('Regenerieren')->toContain('>= 72 °C, 2 min');

    ($this->abweichung)('haccp.garen_zerkleinert', ['wert' => 72]);
    ($this->abweichung)('haccp.heisshalten', ['wert' => 70]);
    $m = $block($this->mandant, 'recipe.generator');
    expect($m)->toContain('Hausstandard des Teams: > 72 °C, 2 min (gesetzliches Minimum ≥ 72 °C, 2 min (DIN 10506')
        ->and($m)->toContain('>= 70 °C')->toContain('strenger als >= 65 °C')
        ->and($block($this->nachbar, 'recipe.generator'))->not->toContain('Hausstandard des Teams');
});

it('Garstufen-Referenz nennt bei Hack die Sicherheitsregel des Teams; Regel geht vor; ganzer Muskel ohne Regel', function () {
    $hack = fn (Team $t) => collect(FaTeam::imTeam($t, fn () => app(CulinaryReferenceService::class)->kerntemperaturen('hackfleisch')))->first();

    expect($hack($this->nachbar)['sicherheitsregel']['soll'])->toBe('> 75 °C, 2 min')
        ->and($hack($this->nachbar)['sicherheitsregel']['herkunft'])->toBe('global')
        ->and($hack($this->nachbar)['hinweis'])->toContain('die Regel geht vor');   // Referenz 72 °C liegt unter dem Hausstandard

    ($this->abweichung)('haccp.garen_zerkleinert', ['wert' => 72]);
    expect($hack($this->mandant)['sicherheitsregel']['soll'])->toBe('> 72 °C, 2 min')
        ->and($hack($this->mandant)['sicherheitsregel']['herkunft'])->toBe('team:' . $this->mandant->id);

    $rind = collect(app(CulinaryReferenceService::class)->kerntemperaturen('rind'))->first();
    expect($rind)->not->toHaveKey('sicherheitsregel');
});
