<?php

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Models\Team;
use Platform\Core\Tools\ToolRegistry;
use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplier;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem;
use Platform\FoodAlchemist\Support\MasterTeam;
use Platform\FoodAlchemist\Support\StammPaket;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 82 (Stamm global): Das Master-Team pflegt den globalen STAMM (GP, Lieferant, Lieferantenartikel mit stamm_class = stamm_paket).
 * Ein Mandant (auch mit gebuchtem Paket) liest nur. Basis-Zeilen der Stamm-Modelle bleiben wie bisher nur lesbar; Basis-Kataloge unverändert.
 * Die Regel steht in BelongsToTeamHierarchy::isOwnedBy — Dienste, Livewire und MCP laufen darüber.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->master = Team::create(['name' => 'BHG.DIGITAL', 'user_id' => 1, 'personal_team' => false]);
    config(['foodalchemist.master_team_id' => $this->master->id]);
    MasterTeam::flush();
    StammPaket::freischalten($this->rootTeam);          // Mandant mit gebuchtem Paket

    $this->sup = FoodAlchemistSupplier::create(['team_id' => null, 'name' => 'Hanos', 'stamm_class' => 'stamm_paket']);
    $this->supBasis = FoodAlchemistSupplier::create(['team_id' => null, 'name' => 'Basis-Lieferant']);
    $this->item = FoodAlchemistSupplierItem::create(['team_id' => null, 'supplier_id' => $this->sup->id, 'designation' => 'Mehl', 'article_number' => 'M1', 'stamm_class' => 'stamm_paket']);
    $this->itemBasis = FoodAlchemistSupplierItem::create(['team_id' => null, 'supplier_id' => $this->supBasis->id, 'designation' => 'Salz', 'article_number' => 'S1']);
    $this->gp = FoodAlchemistGp::create(['team_id' => null, 'gp_key' => 'mp|1', 'name' => 'Mehl', 'stamm_class' => 'stamm_paket']);
    $this->gpBasis = FoodAlchemistGp::create(['team_id' => null, 'gp_key' => 'mp|2', 'name' => 'Salz']);

    $this->reg = app(ToolRegistry::class);
    $this->als = function (Team $team): ToolContext {
        $user = $this->makeUser($team, 'U '.$team->id.uniqid());
        $this->actingAs($user);

        return new ToolContext($user, $team);
    };
});

it('★ Master darf globale Stamm-Zeilen pflegen (Lieferant, Artikel, GP)', function () {
    foreach ([$this->sup, $this->item, $this->gp] as $zeile) {
        expect($zeile->isOwnedBy($this->master))->toBeTrue(get_class($zeile));
    }
});

it('★ ein Mandant mit gebuchtem Paket (und ein Unter-Team) liest nur', function () {
    foreach ([$this->rootTeam, $this->childA] as $team) {
        foreach ([$this->sup, $this->item, $this->gp] as $zeile) {
            expect($zeile->isOwnedBy($team))->toBeFalse(get_class($zeile).' '.$team->name);
        }
    }
});

it('Basis-Zeilen der Stamm-Modelle bleiben auch für den Master nur lesbar (wie bisher)', function () {
    foreach ([$this->supBasis, $this->itemBasis, $this->gpBasis] as $zeile) {
        expect($zeile->isOwnedBy($this->master))->toBeFalse(get_class($zeile));
    }
});

it('ohne konfiguriertes Master-Team pflegt niemand den globalen Stamm (fail closed)', function () {
    config(['foodalchemist.master_team_id' => null]);
    MasterTeam::flush();

    expect($this->item->isOwnedBy($this->master))->toBeFalse()->and($this->item->isOwnedBy($this->rootTeam))->toBeFalse();
});

it('eigene Team-Zeilen bleiben wie bisher beim Besitzer-Team', function () {
    $eigen = FoodAlchemistSupplier::create(['team_id' => $this->rootTeam->id, 'name' => 'Eigener']);

    expect($eigen->isOwnedBy($this->rootTeam))->toBeTrue()->and($eigen->isOwnedBy($this->master))->toBeFalse();
});

it('★ MCP-Schreibwege folgen derselben Regel: Master ja, Mandant ACCESS_DENIED (suppliers.PUT, artikel_allergene.PUT)', function () {
    $m = ($this->als)($this->master);
    $c = ($this->als)($this->rootTeam);
    $putSup = fn ($k) => $this->reg->get('foodalchemist.suppliers.PUT')->execute(['id' => $this->sup->id, 'input' => ['name' => 'Hanos GmbH']], $k);
    $putAll = fn ($k) => $this->reg->get('foodalchemist.artikel_allergene.PUT')->execute(['id' => $this->item->id, 'werte' => []], $k);

    $mandant = $putSup($c);
    expect($mandant->success)->toBeFalse()->and($mandant->errorCode)->toBe('ACCESS_DENIED');
    expect($putAll($c)->errorCode)->toBe('ACCESS_DENIED');

    $master = $putSup($m);
    expect($master->success)->toBeTrue('Master suppliers.PUT: '.($master->error ?? ''))
        ->and($this->sup->refresh()->name)->toBe('Hanos GmbH');
    expect($putAll($m)->success)->toBeTrue();
});
