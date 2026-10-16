<?php

use Platform\FoodAlchemist\Models\FoodAlchemistAngebot;
use Platform\FoodAlchemist\Services\InhaltsFreigabeService;
use Platform\FoodAlchemist\Support\TeamScope;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\Support\SeedsWareneingang;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class, SeedsWareneingang::class);

/**
 * Spec 84 E8 (Dominique 16.10.: „Nein, so nicht gewollt.“) · Ein Unter-Team mit leerer 77d-Hülle sieht die Angebote
 * des Oberteams NICHT. Angebote stehen dafür in InhaltsFreigabe::MODELLE/TABELLEN. Sie sind (noch) kein freigebbarer
 * Ausgabe-Typ: sichtbar bleiben sie bei „übernimmt alles“ (Standard) und für das eigene Team.
 */
beforeEach(function () {
    $this->seedWareneingang();
    $this->inhalte = app(InhaltsFreigabeService::class);
    $this->oberAngebot = FoodAlchemistAngebot::create(['team_id' => $this->rootTeam->id, 'name' => 'Sommerfest Oberteam', 'status' => 'anfrage', 'personen' => 80]);
    $this->sieht = fn ($team) => FoodAlchemistAngebot::visibleToTeam($team)->pluck('id')->map(fn ($v) => (int) $v)->all();
    $this->siehtRoh = fn ($team) => TeamScope::applyVisible(\Illuminate\Support\Facades\DB::table('foodalchemist_offers'), 'team_id', $team)
        ->whereNull('deleted_at')->pluck('id')->map(fn ($v) => (int) $v)->all();
});

it('Standard „übernimmt alles“: das Unter-Team sieht die Angebote des Oberteams weiter', function () {
    expect(($this->sieht)($this->childA))->toContain($this->oberAngebot->id)
        ->and(($this->siehtRoh)($this->childA))->toContain($this->oberAngebot->id);
});

it('Haken aus (leere Hülle): das Unter-Team sieht die Angebote des Oberteams nicht — Modell- und Roh-Abfrage', function () {
    $this->inhalte->setzeErbtAlles($this->rootTeam, $this->childA->id, false, $this->inhaber);

    expect(($this->sieht)($this->childA))->not->toContain($this->oberAngebot->id)
        ->and(($this->siehtRoh)($this->childA))->not->toContain($this->oberAngebot->id);
});

it('Haken aus: eigenes Angebot des Unter-Teams bleibt sichtbar, Geschwister und Oberteam unberührt', function () {
    $this->inhalte->setzeErbtAlles($this->rootTeam, $this->childA->id, false, $this->inhaber);
    $eigen = FoodAlchemistAngebot::create(['team_id' => $this->childA->id, 'name' => 'Standort-Fest', 'status' => 'anfrage', 'personen' => 20]);

    expect(($this->sieht)($this->childA))->toBe([$eigen->id])
        ->and(($this->sieht)($this->childB))->toContain($this->oberAngebot->id)          // Geschwister übernimmt weiter alles
        ->and(($this->sieht)($this->rootTeam))->toContain($this->oberAngebot->id)->not->toContain($eigen->id);
});
