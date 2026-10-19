<?php

use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Exceptions\FaRechtFehltException;
use Platform\FoodAlchemist\Livewire\Favorites\Index as FavoritenSeite;
use Platform\FoodAlchemist\Livewire\Settings\Einkauf;
use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Models\FoodAlchemistTeamFavorit;
use Platform\FoodAlchemist\Services\FavoriteGpService;
use Platform\FoodAlchemist\Services\TeamSettingsService;
use Platform\FoodAlchemist\Support\EinkaufsKette;
use Platform\FoodAlchemist\Support\FavoritenOverlay;
use Platform\FoodAlchemist\Support\TeamAncestryRegistry;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;
use Platform\FoodAlchemist\Tools\FavoritesGetTool;
use Platform\FoodAlchemist\Tools\FavoritesPutTool;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 85 FAV-1 (Dominique 19.10.): Favoriten je Team. Eigene Liste je Team; ein Mandant ohne Liste startet leer (nie die Liste des
 * Masters); Standorte übernehmen die Liste des Hauptteams (eigener Schalter, Standard AN), eigene Favoriten/Ausschlüsse gehen vor;
 * schreiben mit Rolle Kuratieren an jedem sichtbaren GP; die alten GP-Spalten werden die Liste des Masters (Migration).
 * Mandant = rootTeam mit Standort childA, Nachbar = eigener Mandant.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->master = Team::create(['name' => 'Master', 'user_id' => 1, 'personal_team' => false]);
    $this->nachbar = Team::create(['name' => 'Nachbar', 'user_id' => 1, 'personal_team' => false]);
    config(['foodalchemist.master_team_id' => $this->master->id]);
    TeamAncestryRegistry::flushAll();
    EinkaufsKette::vergessen();
    $mk = fn (string $n) => FoodAlchemistGp::create(['team_id' => null, 'gp_key' => 'f85|' . $n, 'name' => $n, 'status' => 'approved']);
    $this->gp = ['butter' => $mk('Butter'), 'sahne' => $mk('Sahne'), 'mehl' => $mk('Mehl')];
    $this->svc = app(FavoriteGpService::class);
    $this->liste = function (Team $t): array {
        EinkaufsKette::vergessen();

        return array_map(fn ($id) => FoodAlchemistGp::find($id)->name, FavoritenOverlay::gpIds($t));
    };
});

it('eigene Liste je Team; Nachbar und Master unberührt; Mandant ohne Liste ist leer', function () {
    $this->svc->pin($this->rootTeam, $this->gp['butter'], 1);
    $this->svc->pin($this->master, $this->gp['mehl']);

    expect(($this->liste)($this->rootTeam))->toBe(['Butter'])
        ->and(($this->liste)($this->nachbar))->toBe([])
        ->and(($this->liste)($this->master))->toBe(['Mehl'])
        ->and($this->svc->current($this->nachbar))->toHaveCount(0);
});

it('Standort übernimmt (Standard AN); eigener Ausschluss und eigener Favorit gehen vor; Schalter AUS = nur eigene', function () {
    $this->svc->pin($this->rootTeam, $this->gp['butter'], 1);
    $this->svc->pin($this->rootTeam, $this->gp['sahne'], 2);
    expect(($this->liste)($this->childA))->toBe(['Butter', 'Sahne']);

    $this->svc->exclude($this->childA, $this->gp['sahne']);   // übernommen → für den Standort ausgeschlossen
    $this->svc->pin($this->childA, $this->gp['mehl'], 1);
    expect(($this->liste)($this->childA))->toBe(['Mehl', 'Butter'])   // gleicher Rang 1: der eigene vor dem übernommenen
        ->and(($this->liste)($this->rootTeam))->toBe(['Butter', 'Sahne'])
        ->and(FoodAlchemistTeamFavorit::where('team_id', $this->childA->id)->where('gp_id', $this->gp['sahne']->id)->value('ausgeschlossen'))->toBeTrue();

    $this->svc->entfernen($this->childA, $this->gp['sahne']);   // Ausschluss weg → wieder übernommen
    expect(($this->liste)($this->childA))->toContain('Sahne');

    app(TeamSettingsService::class)->update($this->rootTeam, ['standorte_uebernehmen_favoriten' => false]);   // Systemweg
    expect(($this->liste)($this->childA))->toBe(['Mehl']);
});

it('vom Master wird nie übernommen: Mandant direkt unter dem Master bleibt leer', function () {
    $mandant = Team::create(['name' => 'Mandant unter Master', 'user_id' => 1, 'personal_team' => false, 'parent_team_id' => $this->master->id]);
    TeamAncestryRegistry::flushAll();
    $this->svc->pin($this->master, $this->gp['butter']);

    expect(($this->liste)($mandant))->toBe([]);
});

it('Rechte: Betrachter pflegt nicht; ein nicht sichtbarer GP wird abgelehnt; jeder sichtbare (auch globale) GP ist pinbar', function () {
    $this->actingAs($this->makeUser($this->rootTeam, 'Leser', 'viewer'));
    expect(fn () => $this->svc->pin($this->rootTeam, $this->gp['butter']))->toThrow(FaRechtFehltException::class);

    $this->actingAs($this->makeUser($this->rootTeam, 'Kuratorin', 'member'));
    $fremd = $this->makeGp($this->nachbar, 'Geheim');
    expect(fn () => $this->svc->pin($this->rootTeam, $fremd))->toThrow(RuntimeException::class, 'sieht euer Team nicht');
    $this->svc->pin($this->rootTeam, $this->gp['butter']);   // globaler GP, kein Besitz nötig
    expect(($this->liste)($this->rootTeam))->toBe(['Butter']);
});

it('Migration: alte GP-Spalten werden die Liste des Masters (global) bzw. des Besitzers (team-eigen) — idempotent', function () {
    $eigen = $this->makeGp($this->rootTeam, 'Hausbrühe');
    (require __DIR__ . '/../../database/migrations/2026_11_12_300000_drop_favoriten_spalten_von_gps.php')->down();   // FAV-3: Spalten für den Altbestand zurückholen
    DB::table('foodalchemist_gps')->where('id', $this->gp['butter']->id)->update(['is_favorite' => true, 'favorite_rank' => 3]);
    DB::table('foodalchemist_gps')->where('id', $eigen->id)->update(['is_favorite' => true]);
    $mig = require __DIR__ . '/../../database/migrations/2026_11_12_200100_favoriten_aus_gp_spalten_ins_team_overlay.php';
    $mig->up();
    $mig->up();

    expect(FoodAlchemistTeamFavorit::count())->toBe(2)
        ->and((int) FoodAlchemistTeamFavorit::where('gp_id', $this->gp['butter']->id)->value('team_id'))->toBe((int) $this->master->id)
        ->and((int) FoodAlchemistTeamFavorit::where('gp_id', $this->gp['butter']->id)->value('rang'))->toBe(3)
        ->and((int) FoodAlchemistTeamFavorit::where('gp_id', $eigen->id)->value('team_id'))->toBe((int) $this->rootTeam->id)
        ->and(($this->liste)($this->nachbar))->toBe([]);   // der Master-Favorit wird beim Mandanten nicht sichtbar
});

it('Pflege-Wege: Favoriten-Seite pinnt einen globalen GP, MCP favorites.PUT/GET arbeiten auf der Team-Liste, Schalter am Hauptteam', function () {
    $admin = $this->makeUser($this->rootTeam, 'Mandant Admin', 'owner');
    $this->actingAs($admin);
    Livewire::test(FavoritenSeite::class)->call('pin', $this->gp['sahne']->id, 2);
    expect(($this->liste)($this->rootTeam))->toBe(['Sahne']);

    $ctx = new ToolContext($admin, $this->rootTeam);
    $put = app(FavoritesPutTool::class)->execute(['gp_id' => $this->gp['butter']->id, 'action' => 'pin', 'rank' => 1], $ctx);
    expect($put->success)->toBeTrue()->and($put->data['is_favorite'])->toBeTrue()
        ->and(collect(app(FavoritesGetTool::class)->execute(['mode' => 'current'], $ctx)->data['items'])->pluck('name')->all())->toBe(['Butter', 'Sahne']);

    Livewire::test(Einkauf::class)->assertSeeHtml('data-standorte-uebernehmen-favoriten');
});

it('Verfügbarkeit (Planung): Bucket „führen“ nach der Liste des Teams', function () {
    $this->svc->pin($this->rootTeam, $this->gp['butter']);
    EinkaufsKette::vergessen();

    expect($this->svc->verfuegbarkeit($this->rootTeam, [$this->gp['butter']->id])[$this->gp['butter']->id]['bucket'])->toBe('fuehren')
        ->and($this->svc->verfuegbarkeit($this->nachbar, [$this->gp['butter']->id])[$this->gp['butter']->id]['bucket'])->not->toBe('fuehren');
});
