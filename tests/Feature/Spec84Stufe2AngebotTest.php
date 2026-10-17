<?php

use Illuminate\Support\Facades\DB;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistAngebot;
use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Services\AboService;
use Platform\FoodAlchemist\Services\AngebotEinfrierService;
use Platform\FoodAlchemist\Services\AngebotService;
use Platform\FoodAlchemist\Services\FassungService;
use Platform\FoodAlchemist\Services\InhaltsFreigabeService;
use Platform\FoodAlchemist\Services\OfferCompositionService;
use Platform\FoodAlchemist\Services\PricingCascadeService;
use Platform\FoodAlchemist\Support\FaTeam;
use Platform\FoodAlchemist\Support\StammPaket;
use Platform\FoodAlchemist\Support\TeamAncestryRegistry;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 84 B3 Stufe 2 · Angebote im automatischen Filter (Kurator 17.10.). Zwei Stellen lesen Angebote bewusst über die Teamgrenze:
 *  - PricingCascadeService rechnet die Angebote des AUSDRÜCKLICH übergebenen Teams nach — auch aus fremdem Kontext → ohneTeamSicht('preis-kaskade')
 *  - AngebotEinfrierService::betroffeneAusgaben listet Angebote des ganzen Mandanten inkl. Unter-Teams → ohneTeamSicht('mandant-weit')
 * Maßstab je Fall: dasselbe Ergebnis wie bei Stufe 0.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->master = Team::create(['name' => 'Master', 'user_id' => 1, 'personal_team' => false]);
    config(['foodalchemist.master_team_id' => $this->master->id]);
    $this->a = Team::create(['name' => 'Mandant A', 'user_id' => 1, 'personal_team' => false]);
    $this->u = Team::create(['name' => 'Standort U', 'user_id' => 1, 'personal_team' => false, 'parent_team_id' => $this->a->id]);
    $this->b = Team::create(['name' => 'Mandant B', 'user_id' => 1, 'personal_team' => false]);
    TeamAncestryRegistry::flushAll();
    StammPaket::freischalten($this->a);
    $this->kurator = $this->makeUser($this->master, 'Kurator', 'owner');
    $this->chefA = $this->makeUser($this->a, 'Chef A', 'owner');
    $this->actingAs($this->kurator);

    // Master-Jus im Abo von A, veröffentlicht
    $knochen = FoodAlchemistGp::create(['team_id' => null, 'gp_key' => 's2|knochen', 'name' => 'Kalbsknochen']);
    $this->jus = $this->makeRecipe($this->master, 'Kalbsjus');
    $this->makeIngredient($this->jus, 'Kalbsknochen', $knochen, '2000');
    $inhalte = app(InhaltsFreigabeService::class);
    $s = $inhalte->sammlungAnlegen($this->master, 'Basis', null, $this->kurator);
    $inhalte->sammlungHinzu($this->master, $s, 'recipe', [$this->jus->id], $this->kurator);
    app(AboService::class)->abonnieren($this->master, $this->a, $s, $this->kurator);
    $f1 = app(FassungService::class)->veroeffentlichen($this->master, $this->jus->id, 'Erst', $this->kurator)['fassung_id'];

    // je ein Angebot in A und im Unter-Team U, beide mit einem Gericht, das die Jus-Fassung nutzt
    $this->angebotMit = function (Team $t, string $name) use ($f1) {
        return FaTeam::imTeam($t, function () use ($t, $name, $f1) {
            $g = $this->makeRecipe($t, 'Lamm '.$name, ['is_sales_recipe' => true]);
            $this->makeIngredient($g, 'Jus')->update(['referenced_recipe_id' => $f1, 'gp_id' => null]);
            $angebot = app(AngebotService::class)->create($t, ['name' => $name]);
            $kap = app(OfferCompositionService::class)->addKapitel($t, $angebot->id, ['title' => 'Menü']);
            $kap->blocks()->create(['team_id' => $t->id, 'type' => 'recipe_ref', 'sales_recipe_id' => $g->id, 'position' => 1, 'visible' => true]);

            return $angebot;
        });
    };
    $this->angebotA = ($this->angebotMit)($this->a, 'Hochzeit A');
    $this->angebotU = ($this->angebotMit)($this->u, 'Firmenfeier U');
});

it('betroffeneAusgaben aus Sicht von A enthält das Angebot des Unter-Teams — bei Stufe 2 wie bei Stufe 0', function () {
    $liste = function (int $stufe) {
        config(['foodalchemist.team_sicht.stufe' => $stufe]);

        return FaTeam::imTeam($this->a, fn () => collect(app(AngebotEinfrierService::class)->betroffeneAusgaben($this->angebotA, $this->jus->id))
            ->pluck('angebot_id')->sort()->values()->all());
    };
    $null = $liste(0);
    expect($null)->toContain((int) $this->angebotU->id);
    // Plausibilität: A sieht U-Angebote bei Stufe 2 sonst NICHT (Sicht geht nur aufwärts)
    config(['foodalchemist.team_sicht.stufe' => 2]);
    expect(FaTeam::imTeam($this->a, fn () => FoodAlchemistAngebot::whereKey($this->angebotU->id)->exists()))->toBeFalse();
    expect($liste(2))->toBe($null);
    expect($liste(1))->toBe($null);   // Gerichte sind Stufe-1-Modelle: der Fehler betraf schon Stufe 1
});

it('Preis-Kaskade für Team A aus fremdem Kontext (B) rechnet dieselben Angebote nach wie bei Stufe 0', function () {
    DB::table('foodalchemist_offers')->where('id', $this->angebotA->id)->update(['price_mode' => 'auto', 'status' => 'anfrage']);
    $lauf = function (int $stufe) {
        config(['foodalchemist.team_sicht.stufe' => $stufe]);
        DB::beginTransaction();
        try {
            return FaTeam::imTeam($this->b, fn () => app(PricingCascadeService::class)->recomputeTeam($this->a));
        } finally {
            DB::rollBack();
        }
    };
    $null = $lauf(0);
    expect($null['offers'])->toBe(1);
    expect($lauf(2))->toBe($null)->and($lauf(1))->toBe($null);   // Stufe 1: Rezepte von A aus B-Kontext
});

it('Unter-Team ohne „übernimmt alles“ sieht Angebote des Oberteams auch über den automatischen Filter nicht (E8)', function () {
    app(InhaltsFreigabeService::class)->setzeErbtAlles($this->a, $this->u->id, false, $this->chefA);
    TeamAncestryRegistry::flushAll();
    config(['foodalchemist.team_sicht.stufe' => 2]);
    expect(FaTeam::imTeam($this->u, fn () => FoodAlchemistAngebot::whereKey($this->angebotA->id)->exists()))->toBeFalse()
        ->and(FaTeam::imTeam($this->u, fn () => FoodAlchemistAngebot::whereKey($this->angebotU->id)->exists()))->toBeTrue()
        ->and(FaTeam::imTeam($this->a, fn () => FoodAlchemistAngebot::whereKey($this->angebotA->id)->exists()))->toBeTrue();
});
