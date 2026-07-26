<?php

use Platform\FoodAlchemist\Enums\SignalSeverity;
use Platform\FoodAlchemist\Enums\SignalTyp;
use Platform\FoodAlchemist\Jobs\SignalFixJob;
use Platform\FoodAlchemist\Services\SignalFixService;
use Platform\FoodAlchemist\Services\SignalService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 21 · Tranche P / Etappe S3b — der Teilmengen-Pfad im Fixer (Punkt 7) und der
 * Dry-Run davor (Punkt 3). Beides hängt am SELBEN `satz()`, darum wird beides gegen
 * dieselbe Erwartung geprüft:
 *
 *  - eine Auswahl fixt NUR die Auswahl (der Rest bleibt messbar unangetastet),
 *  - eine ID außerhalb des Metrik-Prädikats wird NIE angefasst (Autorisierungs-Schnitt),
 *  - der Dry-Run sagt Felder und Werte vorher und schreibt dabei nichts.
 *
 * Beobachtbare Wirkung des `recompute`-Fixers: `allergens_aggregated_at` (die Fixture
 * lässt den Stempel null, jeder Recompute setzt ihn).
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->fix = app(SignalFixService::class);
    $this->signals = app(SignalService::class);

    // Drei Basisrezepte ohne EK ⇒ alle drei treffen `br_ek_null` (Fixer: recompute).
    $this->r1 = $this->makeRecipe($this->rootTeam, 'Fond: braun');
    $this->r2 = $this->makeRecipe($this->rootTeam, 'Fond: hell');
    $this->r3 = $this->makeRecipe($this->rootTeam, 'Fond: Fisch');

    $this->sig = $this->signals->erzeuge($this->rootTeam, SignalTyp::EkKetteUnvollstaendig,
        SignalSeverity::Warnung, 'Basisrezepte ohne EK', [
            'dedup_key' => 'dq-br-ek-null',
            'source' => 'data-quality',
            'payload' => ['metrik' => 'br_ek_null', 'anzahl' => 3],
        ]);
});

it('fixt bei einer Auswahl NUR die Auswahl — der Rest bleibt unangetastet', function () {
    $res = $this->fix->execute($this->rootTeam, $this->sig, [$this->r1->id]);

    expect($res['scope'])->toBe('teilmenge')
        ->and($res['angefragt'])->toBe(1)
        ->and($res['fixed'])->toBe(1)
        ->and($this->r1->refresh()->allergens_aggregated_at)->not->toBeNull()
        ->and($this->r2->refresh()->allergens_aggregated_at)->toBeNull()
        ->and($this->r3->refresh()->allergens_aggregated_at)->toBeNull();
});

it('ignoriert IDs, die das Metrik-Prädikat nicht treffen (Autorisierungs-Schnitt)', function () {
    // Rezept MIT EK ⇒ nicht in `br_ek_null`. Auch wenn es explizit angefragt wird,
    // darf der Fixer es nicht anfassen — das Panel ist keine freie Schreib-Schnittstelle.
    $fremd = $this->makeRecipe($this->rootTeam, 'Fond: bepreist', [
        'ek_total_eur' => 12.5, 'ek_n_ingredients_priced' => 2, 'ek_n_ingredients_total' => 2,
    ]);

    $res = $this->fix->execute($this->rootTeam, $this->sig, [$fremd->id]);

    expect($res['angefragt'])->toBe(1)
        ->and($res['fixed'])->toBe(0)
        ->and($fremd->refresh()->allergens_aggregated_at)->toBeNull();
});

it('fixt ohne Auswahl weiter den vollen Satz (Scope-Default unverändert)', function () {
    $res = $this->fix->execute($this->rootTeam, $this->sig);

    expect($res['scope'])->toBe('alle')
        ->and($res['fixed'])->toBe(3)
        ->and($this->r1->refresh()->allergens_aggregated_at)->not->toBeNull()
        ->and($this->r3->refresh()->allergens_aggregated_at)->not->toBeNull();
});

it('Dry-Run nennt Objekte, Felder und Werte — und schreibt dabei nichts', function () {
    $v = $this->fix->vorschau($this->rootTeam, $this->sig);

    expect($v['fixer'])->toBe('recompute')
        ->and($v['scope'])->toBe('alle')
        ->and($v['total'])->toBe(3)
        ->and($v['gezeigt'])->toBe(3)
        ->and($v['wirkt'])->toBe(3)
        ->and($v['wirkt_nicht'])->toBe(0)
        ->and($v['items'][0]['felder'])->not->toBeEmpty();

    // Der entscheidende Teil: keine Mutation durch die Vorschau.
    expect($this->r1->refresh()->allergens_aggregated_at)->toBeNull()
        ->and($this->r2->refresh()->allergens_aggregated_at)->toBeNull()
        ->and($this->r3->refresh()->allergens_aggregated_at)->toBeNull();
});

it('Dry-Run auf eine Auswahl zeigt genau diese Objekte', function () {
    $v = $this->fix->vorschau($this->rootTeam, $this->sig, [$this->r2->id]);

    expect($v['scope'])->toBe('teilmenge')
        ->and($v['total'])->toBe(1)
        ->and($v['gezeigt'])->toBe(1)
        ->and(array_column($v['items'], 'id'))->toBe([(int) $this->r2->id]);
});

it('verweigert Dry-Run für Signale ohne automatischen Fix', function () {
    $assist = $this->signals->erzeuge($this->rootTeam, SignalTyp::PreisAnomalie,
        SignalSeverity::Warnung, 'Preis-Anomalie', ['dedup_key' => 'preis-anomalie-test']);

    $this->fix->vorschau($this->rootTeam, $assist);
})->throws(RuntimeException::class);

it('trägt die Auswahl durch den Job — ohne sie selbst zu prüfen', function () {
    (new SignalFixJob((int) $this->sig->id, (int) $this->rootTeam->id, [$this->r3->id]))
        ->handle(app(SignalFixService::class));

    expect($this->r3->refresh()->allergens_aggregated_at)->not->toBeNull()
        ->and($this->r1->refresh()->allergens_aggregated_at)->toBeNull();
});

// ── MCP-Lockstep: die neuen Fähigkeiten am foodalchemist.signale.FIX-Tool ───

it('MCP: dry_run liefert die Vorschau ohne Mutation, object_ids fixt nur die Auswahl', function () {
    $tool = app(\Platform\Core\Tools\ToolRegistry::class)->get('foodalchemist.signale.FIX');
    $kontext = new \Platform\Core\Contracts\ToolContext($this->makeUser($this->rootTeam, 'MCP User'), $this->rootTeam);

    $dry = $tool->execute(['signal_id' => (int) $this->sig->id, 'dry_run' => true], $kontext);
    expect($dry->success)->toBeTrue()
        ->and($dry->data['dry_run'])->toBeTrue()
        ->and($dry->data['fixer'])->toBe('recompute')
        ->and($dry->data['would_change'])->toBe(3)
        ->and($this->r1->refresh()->allergens_aggregated_at)->toBeNull();   // nichts geschrieben

    $res = $tool->execute(['signal_id' => (int) $this->sig->id, 'object_ids' => [(int) $this->r2->id]], $kontext);
    expect($res->success)->toBeTrue()
        ->and($res->data['scope'])->toBe('teilmenge')
        ->and($res->data['fixed'])->toBe(1)
        ->and($this->r2->refresh()->allergens_aggregated_at)->not->toBeNull()
        ->and($this->r1->refresh()->allergens_aggregated_at)->toBeNull();
});

it('MCP: dry_run auf einem Assist-Signal wird abgewiesen statt still zu schreiben', function () {
    $tool = app(\Platform\Core\Tools\ToolRegistry::class)->get('foodalchemist.signale.FIX');
    $kontext = new \Platform\Core\Contracts\ToolContext($this->makeUser($this->rootTeam, 'MCP Assist'), $this->rootTeam);

    $assist = $this->signals->erzeuge($this->rootTeam, SignalTyp::PreisAnomalie,
        SignalSeverity::Warnung, 'Preis-Anomalie', ['dedup_key' => 'preis-anomalie-mcp']);

    $res = $tool->execute(['signal_id' => (int) $assist->id, 'dry_run' => true], $kontext);
    expect($res->success)->toBeFalse()->and($res->errorCode)->toBe('ACTION_NOT_AVAILABLE');
});

it('MCP: ohne auflösbares Team → NO_TEAM (Tenancy-Negativtest)', function () {
    $ohneTeam = \Platform\Core\Models\User::forceCreate([
        'name' => 'Teamlos', 'email' => 'teamlos-fix@test.local',
        'password' => bcrypt('secret'), 'current_team_id' => null,
    ]);
    $res = app(\Platform\Core\Tools\ToolRegistry::class)->get('foodalchemist.signale.FIX')
        ->execute(['signal_id' => (int) $this->sig->id, 'dry_run' => true],
            new \Platform\Core\Contracts\ToolContext($ohneTeam, null));

    expect($res->success)->toBeFalse()->and($res->errorCode)->toBe('NO_TEAM');
});
