<?php

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Exceptions\FaRechtFehltException;
use Platform\FoodAlchemist\Services\PlattformProtokoll;
use Platform\FoodAlchemist\Services\SupportFreigabeService;
use Platform\FoodAlchemist\Support\InhaltsSperre;
use Platform\FoodAlchemist\Support\MasterTeam;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;
use Platform\FoodAlchemist\Tools\FaRechteToolHuelle;
use Platform\FoodAlchemist\Tools\RecipesGetTool;
use Platform\FoodAlchemist\Tools\SupportZugangPostTool;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 84 §4.4 (E3, Dominique 16.10.) · Kein pauschaler Admin-Zugriff auf Mandanten-Inhalte, aber Support-Zugang auf Freigabe:
 * der Mandant (Owner/Admin des Hauptteams) erteilt befristet, widerruft jederzeit; jeder Zugriff wird protokolliert.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->master = Team::create(['name' => 'Master', 'user_id' => 1, 'personal_team' => false]);
    config(['foodalchemist.master_team_id' => $this->master->id]);
    MasterTeam::flush();
    $this->mandant = Team::create(['name' => 'Mandant A', 'user_id' => 1, 'personal_team' => false]);
    $this->standort = Team::create(['name' => 'Standort A1', 'user_id' => 1, 'personal_team' => false, 'parent_team_id' => $this->mandant->id]);
    $this->owner = $this->makeUser($this->mandant, 'Owner A', 'owner');
    $this->mitglied = $this->makeUser($this->mandant, 'Koch A', 'member');
    $this->admin = $this->makeUser($this->master, 'Support', 'owner');                   // Plattform-Admin, NICHT Mitglied von A
    $this->zweiterAdmin = $this->makeUser($this->master, 'Support 2', 'owner');
    config(['platform-shell.admins' => [strtolower($this->admin->email), strtolower($this->zweiterAdmin->email)]]);
    $this->rezept = $this->makeRecipe($this->mandant, 'Geheimrezept A');

    // Protokoll-Spion: zählt jede Zeile, unabhängig davon, ob das echte activity-log-Paket installiert ist
    $this->protokoll = new class extends PlattformProtokoll
    {
        /** @var list<array{0:string,1:array}> */
        public array $zeilen = [];

        public function schreibe(string $aktion, array $daten, ?\Platform\Core\Models\User $user, ?\Illuminate\Database\Eloquent\Model $subjekt = null): void
        {
            $this->zeilen[] = [$aktion, $daten];
        }
    };
    app()->instance(PlattformProtokoll::class, $this->protokoll);
    app()->forgetScopedInstances();
    $this->svc = fn () => app(SupportFreigabeService::class);
    $this->leseTool = fn (Team $team) => (new FaRechteToolHuelle(new RecipesGetTool()))
        ->execute(['id' => $this->rezept->id], new ToolContext($this->admin, $team));
});

it('ohne Freigabe: Plattform-Admin ist im Mandanten und im Standort gesperrt, das Lese-Tool gibt FORBIDDEN', function () {
    expect(InhaltsSperre::istGesperrt($this->admin, $this->mandant))->toBeTrue()
        ->and(InhaltsSperre::istGesperrt($this->admin, $this->standort))->toBeTrue();

    $r = ($this->leseTool)($this->mandant);
    expect($r->success)->toBeFalse()->and($r->errorCode)->toBe('FORBIDDEN');
});

it('mit Freigabe: lesbar in Mandant und Standort, jeder Tool-Zugriff landet im Protokoll', function () {
    ($this->svc)()->erteilen($this->mandant, $this->owner, 48, null, 'Ticket 4711');

    expect(InhaltsSperre::istGesperrt($this->admin, $this->mandant))->toBeFalse()
        ->and(InhaltsSperre::istGesperrt($this->admin, $this->standort))->toBeFalse();

    $r = ($this->leseTool)($this->mandant);
    expect($r->success)->toBeTrue();

    $zugriffe = array_values(array_filter($this->protokoll->zeilen, fn ($z) => $z[0] === 'support_zugriff'));
    expect(array_column($this->protokoll->zeilen, 0))->toContain('support_zugang_erteilt')
        ->and($zugriffe)->toHaveCount(1)
        ->and($zugriffe[0][1]['eingang'])->toBe('mcp:foodalchemist.recipes.GET')
        ->and($zugriffe[0][1]['schreibend'])->toBeFalse();
});

it('nach Ablauf wieder gesperrt; Widerruf wirkt sofort', function () {
    $f = ($this->svc)()->erteilen($this->mandant, $this->owner, 2);
    expect(InhaltsSperre::istGesperrt($this->admin, $this->mandant))->toBeFalse();

    $this->travel(3)->hours();
    app()->forgetScopedInstances();
    expect(InhaltsSperre::istGesperrt($this->admin, $this->mandant))->toBeTrue();
    $this->travelBack();

    app()->forgetScopedInstances();
    expect(InhaltsSperre::istGesperrt($this->admin, $this->mandant))->toBeFalse();      // noch gültig
    ($this->svc)()->widerrufen($this->mandant, $this->owner, (int) $f->id);
    expect(InhaltsSperre::istGesperrt($this->admin, $this->mandant))->toBeTrue()
        ->and(array_column($this->protokoll->zeilen, 0))->toContain('support_zugang_widerrufen');
});

it('Freigabe für einen bestimmten Admin gilt nur für ihn', function () {
    ($this->svc)()->erteilen($this->mandant, $this->owner, 48, $this->zweiterAdmin->id);

    expect(InhaltsSperre::istGesperrt($this->zweiterAdmin, $this->mandant))->toBeFalse()
        ->and(InhaltsSperre::istGesperrt($this->admin, $this->mandant))->toBeTrue();
});

it('erteilen darf nur Owner/Admin des Hauptteams — nicht ein Mitglied, nicht der Plattform-Admin selbst; Dauer begrenzt', function () {
    expect(fn () => ($this->svc)()->erteilen($this->mandant, $this->mitglied))->toThrow(FaRechtFehltException::class)
        ->and(fn () => ($this->svc)()->erteilen($this->mandant, $this->admin))->toThrow(FaRechtFehltException::class)
        ->and(fn () => ($this->svc)()->erteilen($this->mandant, $this->owner, 500))->toThrow(RuntimeException::class);

    $r = (new FaRechteToolHuelle(new SupportZugangPostTool()))->execute(['stunden' => 24], new ToolContext($this->admin, $this->mandant));
    expect($r->success)->toBeFalse();                                                     // gesperrt bzw. kein Recht — nie selbst freischalten
    expect(InhaltsSperre::istGesperrt($this->admin, $this->mandant))->toBeTrue();
});

it('MCP: der Owner erteilt per Tool, der Standort ist danach mit freigegeben', function () {
    $r = (new FaRechteToolHuelle(new SupportZugangPostTool()))->execute(['stunden' => 24, 'grund' => 'Fehlersuche'], new ToolContext($this->owner, $this->mandant));

    expect($r->success)->toBeTrue()->and($r->data['freigabe']['aktiv'])->toBeTrue();
    app()->forgetScopedInstances();
    expect(InhaltsSperre::istGesperrt($this->admin, $this->standort))->toBeFalse();
});
