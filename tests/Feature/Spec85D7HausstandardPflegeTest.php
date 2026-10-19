<?php

use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Exceptions\FaRechtFehltException;
use Platform\FoodAlchemist\Livewire\Settings\Hausstandards;
use Platform\FoodAlchemist\Models\FoodAlchemistRule;
use Platform\FoodAlchemist\Models\FoodAlchemistRuleVersion;
use Platform\FoodAlchemist\Services\Regeln\Arten\Schwelle;
use Platform\FoodAlchemist\Services\Regeln\RegelBuch;
use Platform\FoodAlchemist\Services\Regeln\RegelService;
use Platform\FoodAlchemist\Services\Regeln\RegelUngueltig;
use Platform\FoodAlchemist\Support\TeamAncestryRegistry;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;
use Platform\FoodAlchemist\Tools\RulesDeleteTool;
use Platform\FoodAlchemist\Tools\RulesGetTool;
use Platform\FoodAlchemist\Tools\RulesPutTool;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 85 D-7 PR 2 · Hausstandards pflegen (UI + MCP). Ein Schreibweg (RegelService::speichereTeam/entferneTeam): FA-Admin des
 * Teams, Mensch; nur übersteuerbare Schlüssel; nur params+notiz vom Team; Schwellen innerhalb der belegten Untergrenze; nur die
 * eigene Team-Zeile. Unter-Teams eigenständig (eigenes > Oberteam > global).
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->mandant = Team::create(['name' => 'Mandant', 'user_id' => 1, 'personal_team' => false]);
    $this->bereich = Team::create(['name' => 'Bereich', 'user_id' => 1, 'personal_team' => false, 'parent_team_id' => $this->mandant->id]);
    $this->nachbar = Team::create(['name' => 'Nachbar', 'user_id' => 1, 'personal_team' => false]);
    TeamAncestryRegistry::flushAll();
    RegelBuch::vergessen();
    $this->admin = $this->makeUser($this->mandant, 'Anna Admin', 'owner');
    $this->mitglied = $this->makeUser($this->mandant, 'Max Mitglied', 'member');
    $this->bereichsAdmin = $this->makeUser($this->bereich, 'Bea Bereich', 'owner');
    $this->nachbarAdmin = $this->makeUser($this->nachbar, 'Nils Nachbar', 'owner');
    $this->svc = app(RegelService::class);
    $this->wert = fn (string $s, Team $t) => Schwelle::text((array) RegelBuch::per($s, $t)->params);
    $this->fehlerTyp = function (callable $fn): ?string {
        try {
            $fn();
        } catch (RegelUngueltig $e) {
            return $e->typ . ': ' . implode(' ', $e->fehler);
        }

        return null;
    };
});

it('FA-Admin setzt einen Hausstandard: eigene Zeile, aktiv, Version 1, Art/Ziel/Wirkung von global, Untergrenze verworfen', function () {
    $r = $this->svc->speichereTeam($this->mandant, 'haccp.heisshalten', ['wert' => 70, 'untergrenze' => 50, 'untergrenze_quelle' => 'erfunden'], 'Haus', $this->admin);

    expect((int) $r->team_id)->toBe((int) $this->mandant->id)->and($r->aktiv)->toBeTrue()->and((int) $r->version)->toBe(1)
        ->and($r->art)->toBe('schwelle')->and($r->wirkung)->toBe('warnen')->and($r->beispiele)->toBeNull()
        ->and($r->params)->not->toHaveKey('untergrenze')->not->toHaveKey('untergrenze_quelle')
        ->and(($this->wert)('haccp.heisshalten', $this->mandant))->toBe('>= 70 °C')
        ->and(($this->wert)('haccp.heisshalten', $this->bereich))->toBe('>= 70 °C')       // Unter-Team erbt
        ->and(($this->wert)('haccp.heisshalten', $this->nachbar))->toBe('>= 65 °C')
        ->and(FoodAlchemistRuleVersion::where('rule_id', $r->id)->value('user_id'))->toBe($this->admin->id);
});

it('Rechte: Mitglied, Admin eines Nachbar-Teams und KI-Nutzer schreiben nie', function () {
    expect(fn () => $this->svc->speichereTeam($this->mandant, 'haccp.heisshalten', ['wert' => 70], null, $this->mitglied))->toThrow(FaRechtFehltException::class)
        ->and(fn () => $this->svc->speichereTeam($this->mandant, 'haccp.heisshalten', ['wert' => 70], null, $this->nachbarAdmin))->toThrow(FaRechtFehltException::class);
    $ki = $this->makeUser($this->mandant, 'KI Agent', 'owner');
    $ki->forceFill(['type' => 'ai_user'])->save();
    expect(fn () => $this->svc->speichereTeam($this->mandant, 'haccp.heisshalten', ['wert' => 70], null, $ki->fresh()))->toThrow(RuntimeException::class, 'nur ein Mensch')
        ->and(FoodAlchemistRule::ohneTeamSicht('test')->where('team_id', $this->mandant->id)->count())->toBe(0);
})->skip(fn () => ! method_exists(\Platform\Core\Models\User::class, 'isAiUser'), 'User::isAiUser fehlt');

it('nicht übersteuerbar und Art-fremde Felder: Typ NICHT_UEBERSTEUERBAR, Art bleibt die globale', function () {
    expect(($this->fehlerTyp)(fn () => $this->svc->speichereTeam($this->mandant, 'gp.7.1.gebinde', ['tokens' => ['x']], null, $this->admin)))
        ->toStartWith('NICHT_UEBERSTEUERBAR');
    $r = $this->svc->speichereTeam($this->mandant, 'haccp.heisshalten', ['wert' => 70], null, $this->admin);
    expect($r->art)->toBe('schwelle')->and($r->ziel)->toBe('haccp.temperatur');
});

it('Untergrenze im Schreibweg: Garen 72 °C/2 min erlaubt, 70 °C mit DIN-Quelle abgelehnt; Heißhalten 60 und Abkühlen 180 min nur strenger', function () {
    $this->svc->speichereTeam($this->mandant, 'haccp.garen_zerkleinert', ['wert' => 72], null, $this->admin);
    expect(($this->wert)('haccp.garen_zerkleinert', $this->mandant))->toBe('> 72 °C, 2 min');

    $f = ($this->fehlerTyp)(fn () => $this->svc->speichereTeam($this->mandant, 'haccp.garen_zerkleinert', ['wert' => 70], null, $this->admin));
    expect($f)->toStartWith('UNTERGRENZE')->toContain('DIN 10506');
    expect(($this->fehlerTyp)(fn () => $this->svc->speichereTeam($this->mandant, 'haccp.heisshalten', ['wert' => 60], null, $this->admin)))
        ->toStartWith('UNTERGRENZE')->toContain('nur strenger')
        ->and(($this->fehlerTyp)(fn () => $this->svc->speichereTeam($this->mandant, 'haccp.abkuehlen', ['dauer' => 180], null, $this->admin)))
        ->toStartWith('UNTERGRENZE')
        ->and(($this->wert)('haccp.garen_zerkleinert', $this->mandant))->toBe('> 72 °C, 2 min');   // Ablehnung ändert nichts
});

it('Entfernen: Oberteam/global gilt wieder; Versionskette läuft weiter; erneutes Setzen nutzt dieselbe Zeile', function () {
    expect($this->svc->entferneTeam($this->mandant, 'haccp.heisshalten', $this->admin))->toBeFalse();
    $r = $this->svc->speichereTeam($this->mandant, 'haccp.heisshalten', ['wert' => 70], null, $this->admin);
    expect($this->svc->entferneTeam($this->mandant, 'haccp.heisshalten', $this->admin))->toBeTrue()
        ->and(($this->wert)('haccp.heisshalten', $this->mandant))->toBe('>= 65 °C')
        ->and(FoodAlchemistRuleVersion::where('rule_id', $r->id)->orderByDesc('version')->value('aktiv'))->toBeFalse();

    $neu = $this->svc->speichereTeam($this->mandant, 'haccp.heisshalten', ['wert' => 68], null, $this->admin);
    expect((int) $neu->id)->toBe((int) $r->id)->and((int) $neu->version)->toBe(3)->and(($this->wert)('haccp.heisshalten', $this->mandant))->toBe('>= 68 °C');
});

it('Unter-Team ist eigenständig: eigene Abweichung schlägt die des Oberteams, Oberteam bleibt unberührt', function () {
    $this->svc->speichereTeam($this->mandant, 'haccp.heisshalten', ['wert' => 70], null, $this->admin);
    $this->svc->speichereTeam($this->bereich, 'haccp.heisshalten', ['wert' => 75], null, $this->bereichsAdmin);

    expect(($this->wert)('haccp.heisshalten', $this->bereich))->toBe('>= 75 °C')
        ->and(($this->wert)('haccp.heisshalten', $this->mandant))->toBe('>= 70 °C')
        ->and(fn () => $this->svc->speichereTeam($this->mandant, 'haccp.heisshalten', ['wert' => 72], null, $this->bereichsAdmin))->toThrow(FaRechtFehltException::class);
});

it('MCP: rules.GET ebene=team liest jedes Mitglied; PUT/DELETE ebene=team nur Admin, Fehlertypen; globale Pfade unverändert', function () {
    $mitglied = new ToolContext($this->mitglied, $this->mandant);
    $admin = new ToolContext($this->admin, $this->mandant);

    $get = app(RulesGetTool::class)->execute(['ebene' => 'team'], $mitglied);
    $garen = collect($get->data['hausstandards'])->firstWhere('schluessel', 'haccp.garen_zerkleinert');
    expect($get->success)->toBeTrue()->and($garen['herkunft'])->toBe('global')->and($garen['untergrenze'])->toContain('DIN 10506')
        ->and(collect($get->data['hausstandards'])->firstWhere('schluessel', 'haccp.heisshalten')['nur_strenger'])->toBeTrue()
        ->and($get->data['aendern'])->toContain('nur FA-Admin');

    expect(app(RulesPutTool::class)->execute(['ebene' => 'team', 'schluessel' => 'haccp.heisshalten', 'params' => ['wert' => 70]], $mitglied)->errorCode)->toBe('FORBIDDEN')
        ->and(app(RulesPutTool::class)->execute(['ebene' => 'team', 'schluessel' => 'haccp.garen_zerkleinert', 'params' => ['wert' => 70]], $admin)->errorCode)->toBe('UNTERGRENZE')
        ->and(app(RulesPutTool::class)->execute(['ebene' => 'team', 'schluessel' => 'gp.7.1.gebinde', 'params' => ['x' => 1]], $admin)->errorCode)->toBe('NICHT_UEBERSTEUERBAR');
    $put = app(RulesPutTool::class)->execute(['ebene' => 'team', 'schluessel' => 'haccp.heisshalten', 'params' => ['wert' => 70]], $admin);
    expect($put->success)->toBeTrue()->and($put->data['wert'])->toBe('>= 70 °C')
        ->and(collect(app(RulesGetTool::class)->execute(['ebene' => 'team'], $mitglied)->data['hausstandards'])->firstWhere('schluessel', 'haccp.heisshalten')['eigen'])->toBeTrue();

    expect(app(RulesDeleteTool::class)->execute(['ebene' => 'team', 'schluessel' => 'haccp.heisshalten'], $mitglied)->errorCode)->toBe('FORBIDDEN')
        ->and(app(RulesDeleteTool::class)->execute(['ebene' => 'team', 'schluessel' => 'haccp.heisshalten'], $admin)->data['entfernt'])->toBeTrue()
        ->and(app(RulesGetTool::class)->execute([], $admin)->errorCode)->toBe('FORBIDDEN');   // global bleibt Plattform-Admin
});

it('Oberfläche: Admin weicht ab (nach „Bearbeiten“), Fehler der Untergrenze im Formular, Mitglied nur lesend', function () {
    $this->actingAs($this->admin);
    $c = Livewire::test(Hausstandards::class)->call('bearbeitenStarten')
        ->call('oeffne', 'haccp.garen_zerkleinert')->assertSeeHtml('data-hausstandard-editor')->assertSee('DIN 10506')
        ->set('form.wert', '70')->call('speichern')->assertSee('Untergrenze');
    expect(FoodAlchemistRule::ohneTeamSicht('test')->where('team_id', $this->mandant->id)->count())->toBe(0);
    $c->set('form.wert', '72')->call('speichern')->assertSee('Hausstandard gespeichert');
    expect(($this->wert)('haccp.garen_zerkleinert', $this->mandant))->toBe('> 72 °C, 2 min');

    $this->actingAs($this->mitglied);
    Livewire::test(Hausstandards::class)->call('oeffne', 'haccp.heisshalten')->set('form.wert', '80')->call('speichern');
    expect(($this->wert)('haccp.heisshalten', $this->mandant))->toBe('>= 65 °C');
});
