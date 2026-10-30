<?php

use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Livewire\Settings\Einkauf;
use Platform\FoodAlchemist\Models\FoodAlchemistGpLaPreference;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplier;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItemStructure;
use Platform\FoodAlchemist\Services\LeadLaService;
use Platform\FoodAlchemist\Services\TeamSettingsService;
use Platform\FoodAlchemist\Support\LeadOverlay;
use Platform\FoodAlchemist\Support\TeamAncestryRegistry;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;
use Platform\FoodAlchemist\Tools\TeamSettingsPutTool;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 85 Controlling PR-B (Dominique 19.10.): „Standorte übernehmen unsere Leitartikel“ — Einstellung am Kunden-Hauptteam, Standard
 * AN. An: Pin des Standorts > Pin des Hauptteams > Plattform-Lead, Sperren entlang derselben Kette. Aus: nur das eigene Team. Der
 * eigene Pin eines Standorts gewinnt immer; vom Master wird nie übernommen. Mandant = rootTeam, Standort = childA.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->gp = $this->makeGp($this->rootTeam, 'Butter');
    $lief = FoodAlchemistSupplier::create(['team_id' => $this->rootTeam->id, 'name' => 'Großhandel', 'status' => 'aktiv']);
    $this->la = collect(['A', 'B', 'C'])->mapWithKeys(function ($n) use ($lief) {
        $la = FoodAlchemistSupplierItem::create(['team_id' => $this->rootTeam->id, 'supplier_id' => $lief->id, 'designation' => "Butter {$n}", 'unit_code' => 'kg', 'qty' => 1]);
        FoodAlchemistSupplierItemStructure::create(['team_id' => $this->rootTeam->id, 'supplier_item_id' => $la->id, 'gp_id' => $this->gp->id]);

        return [$n => $la];
    });
    $this->svc = app(LeadLaService::class);
    $this->wirksam = function (Team $t): ?string {
        LeadOverlay::vergessen();
        $la = $this->svc->effektiverLead($this->gp->fresh(), $t);

        return $la === null ? null : substr((string) $la->designation, -1);
    };
    $this->pin = fn (Team $t, string $n) => $this->svc->pinnen($t, $this->gp, $this->la[$n]->id);
});

it('Standard AN: Standort übernimmt den Pin des Hauptteams; eigener Pin gewinnt; das Hauptteam sieht den Standort-Pin nie', function () {
    ($this->pin)($this->rootTeam, 'B');
    expect(($this->wirksam)($this->childA))->toBe('B');

    ($this->pin)($this->childA, 'C');
    expect(($this->wirksam)($this->childA))->toBe('C')
        ->and(($this->wirksam)($this->rootTeam))->toBe('B');
});

it('Einstellung AUS: kein Übernehmen — Standort ohne eigenen Pin folgt der Rangliste, eigener Pin wirkt weiter', function () {
    $this->actingAs($this->makeUser($this->rootTeam, 'Mandant Admin', 'owner'));
    app(TeamSettingsService::class)->update($this->rootTeam, ['standorte_uebernehmen_leitartikel' => false]);
    ($this->pin)($this->rootTeam, 'B');

    expect(($this->wirksam)($this->childA))->not->toBe('B')
        ->and(LeadOverlay::kette($this->childA))->toBe([(int) $this->childA->id]);
    ($this->pin)($this->childA, 'C');
    expect(($this->wirksam)($this->childA))->toBe('C');
});

it('Sperren werden übernommen (an), nicht (aus)', function () {
    $this->svc->sperren($this->rootTeam, $this->gp, $this->la['A']->id);
    $gesperrt = fn (Team $t) => (bool) (LeadOverlay::fuer($t, [(int) $this->gp->id])[(int) $this->gp->id][(int) $this->la['A']->id]['locked'] ?? false);

    LeadOverlay::vergessen();
    expect($gesperrt($this->childA))->toBeTrue();
    app(TeamSettingsService::class)->update($this->rootTeam, ['standorte_uebernehmen_leitartikel' => false]);   // Systemweg, ohne Anmeldung
    LeadOverlay::vergessen();
    expect($gesperrt($this->childA))->toBeFalse();
});

it('vom Master wird nie übernommen: Mandant direkt unter dem Master bekommt dessen Pin nicht', function () {
    $master = Team::create(['name' => 'Master', 'user_id' => 1, 'personal_team' => false]);
    $mandant = Team::create(['name' => 'Mandant unter Master', 'user_id' => 1, 'personal_team' => false, 'parent_team_id' => $master->id]);
    config(['foodalchemist.master_team_id' => $master->id]);
    TeamAncestryRegistry::flushAll();
    LeadOverlay::vergessen();
    // Pin direkt als Zeile: der Sicht-Riegel (Lead-Sicht 29.10.) lässt den Master den team-eigenen Artikel des Mandanten nicht über pinnen() setzen.
    FoodAlchemistGpLaPreference::create(['team_id' => $master->id, 'gp_id' => $this->gp->id, 'supplier_item_id' => $this->la['B']->id, 'gepinnt' => true]);

    expect(LeadOverlay::kette($mandant))->toBe([(int) $mandant->id])
        ->and(LeadOverlay::fuer($mandant, [(int) $this->gp->id]))->toBe([]);
});

it('Umschalten markiert die Team-Kosten der Standorte veraltet', function () {
    DB::table('foodalchemist_recipe_team_kosten')->insert(['recipe_id' => 1, 'team_id' => $this->childA->id, 'veraltet' => false, 'created_at' => now(), 'updated_at' => now()]);
    $this->actingAs($this->makeUser($this->rootTeam, 'Mandant Admin', 'owner'));
    app(TeamSettingsService::class)->update($this->rootTeam, ['standorte_uebernehmen_leitartikel' => false]);

    expect((bool) DB::table('foodalchemist_recipe_team_kosten')->where('team_id', $this->childA->id)->value('veraltet'))->toBeTrue();
});

it('Pflege: MCP team_settings.PUT kennt den Schlüssel; Einkauf zeigt den Schalter nur am Hauptteam', function () {
    $admin = $this->makeUser($this->rootTeam, 'Mandant Admin', 'owner');
    $this->actingAs($admin);
    $r = app(TeamSettingsPutTool::class)->execute(['settings' => ['standorte_uebernehmen_leitartikel' => false]], new ToolContext($admin, $this->rootTeam));
    expect($r->success)->toBeTrue()->and(app(TeamSettingsService::class)->standorteUebernehmenLeitartikel($this->rootTeam))->toBeFalse();

    Livewire::test(Einkauf::class)->assertSeeHtml('data-standorte-uebernehmen');
    $this->actingAs($this->makeUser($this->childA, 'Standort Admin', 'owner'));
    Livewire::test(Einkauf::class)->assertDontSeeHtml('data-standorte-uebernehmen');
});
