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
 * Spec 84 B3 · Stufe-1-Nachzug (Kurator 17.10., vor dem Einschalten am 24.10.):
 *  - PricingCascadeService rechnet die Zeilen des ÜBERGEBENEN Teams — liest jetzt in dessen Sicht (lesenIm), auch aus fremdem Kontext
 *  - AngebotEinfrierService::betroffeneAusgaben: Teilbaum des Angebots-Teams (Hauptteam = ganzer Mandant, Unter-Team nie Geschwister),
 *    Gerichte je Angebot in der Sicht seines Teams geprüft; zurück nur Kennung, Name, Status, Art
 * Maßstab: dasselbe Ergebnis wie bei Stufe 0 (die Gerichte sind Stufe-1-Modelle).
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->master = Team::create(['name' => 'Master', 'user_id' => 1, 'personal_team' => false]);
    config(['foodalchemist.master_team_id' => $this->master->id]);
    $this->a = Team::create(['name' => 'Mandant A', 'user_id' => 1, 'personal_team' => false]);
    $this->u = Team::create(['name' => 'Standort U', 'user_id' => 1, 'personal_team' => false, 'parent_team_id' => $this->a->id]);
    $this->v = Team::create(['name' => 'Standort V', 'user_id' => 1, 'personal_team' => false, 'parent_team_id' => $this->a->id]);
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
    $this->angebotV = ($this->angebotMit)($this->v, 'Taufe V');
});

it('betroffeneAusgaben des Hauptteams A enthält die Angebote der Unter-Teams — Stufe 1 wie Stufe 0', function () {
    $liste = function (int $stufe) {
        config(['foodalchemist.team_sicht.stufe' => $stufe]);

        return FaTeam::imTeam($this->a, fn () => collect(app(AngebotEinfrierService::class)->betroffeneAusgaben($this->angebotA, $this->jus->id))
            ->pluck('angebot_id')->sort()->values()->all());
    };
    $null = $liste(0);
    expect($null)->toBe(collect([$this->angebotU->id, $this->angebotV->id])->map(fn ($v) => (int) $v)->sort()->values()->all());
    // Plausibilität: A sieht die Gerichte von U bei Stufe 1 sonst NICHT (Sicht geht nur aufwärts)
    config(['foodalchemist.team_sicht.stufe' => 1]);
    expect(FaTeam::imTeam($this->a, fn () => FoodAlchemistRecipe::where('team_id', $this->u->id)->exists()))->toBeFalse();
    expect($liste(1))->toBe($null);
});

it('Unter-Team U ruft auf: nur sein Teilbaum — Geschwister V und Oberteam A fehlen (kein Geschwister-Leck), auch bei Stufe 0', function () {
    foreach ([0, 1] as $stufe) {
        config(['foodalchemist.team_sicht.stufe' => $stufe]);
        $ids = FaTeam::imTeam($this->u, fn () => collect(app(AngebotEinfrierService::class)->betroffeneAusgaben($this->angebotU, $this->jus->id))->pluck('angebot_id')->all());
        expect($ids)->not->toContain((int) $this->angebotV->id)->not->toContain((int) $this->angebotA->id);
    }
});

it('Ergebnis trägt nur Kennung, Name, Status und Art — keine Gericht- oder Preisdaten', function () {
    config(['foodalchemist.team_sicht.stufe' => 1]);
    $liste = FaTeam::imTeam($this->a, fn () => app(AngebotEinfrierService::class)->betroffeneAusgaben($this->angebotA, $this->jus->id));
    expect($liste)->not->toBeEmpty();
    foreach ($liste as $zeile) {
        expect(array_keys($zeile))->toBe(['angebot_id', 'name', 'status', 'art']);
    }
});

it('Preis-Kaskade für Team A aus fremdem Kontext (B) rechnet bei Stufe 1 dieselben Angebote nach wie bei Stufe 0', function () {
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
    expect($lauf(1))->toBe($null);   // Stufe 1: Rezepte von A aus B-Kontext
});

it('Sweep: foodalchemist:teller-rollen findet die Gerichte von --team auch, wenn der angemeldete Nutzer ein anderes UI-Team hat', function () {
    $sig = (new \Platform\FoodAlchemist\Console\TellerRollenKlassifizierenCommand)->getName();
    $gericht = $this->makeRecipe($this->u, 'Gericht U', ['is_sales_recipe' => true]);
    $this->makeIngredient($gericht, 'Salz');
    DB::table('foodalchemist_recipe_ingredients')->where('recipe_id', $gericht->id)->update(['role' => null]);
    $nutzer = $this->makeUser($this->u, 'Koch U');
    $nutzer->forceFill(['current_team_id' => $this->b->id])->save();   // UI-Team ist ein fremder Mandant
    \Illuminate\Support\Facades\DB::table('team_user')->where('team_id', $this->u->id)->where('user_id', '!=', $nutzer->id)->delete();
    $scope = function (int $stufe) use ($sig) {
        config(['foodalchemist.team_sicht.stufe' => $stufe]);
        \Illuminate\Support\Facades\Artisan::call($sig, ['--team' => $this->u->id]);
        preg_match('/Scope: (\d+) Gerichte/', \Illuminate\Support\Facades\Artisan::output(), $m);

        return (int) ($m[1] ?? -1);
    };
    $null = $scope(0);
    expect($null)->toBeGreaterThan(0)->and($scope(1))->toBe($null);
});

it('Nachladen: KostenCache und TeamVk mit Teil-Spalten-Modell aus fremdem Kontext — Stufe 1 = Stufe 0', function () {
    $rezept = FoodAlchemistRecipe::ohneTeamSicht('test')->where('team_id', $this->a->id)->where('is_sales_recipe', true)->firstOrFail();   // Test-Aufbau über die Teamgrenze
    $ergebnis = function (int $stufe) use ($rezept) {
        config(['foodalchemist.team_sicht.stufe' => $stufe]);
        $teil = FoodAlchemistRecipe::ohneTeamSicht('test')->select(['id', 'name'])->findOrFail($rezept->id);   // wie das Angebot: nur Teil-Spalten
        $teil2 = FoodAlchemistRecipe::ohneTeamSicht('test')->select(['id', 'name'])->findOrFail($rezept->id);

        return FaTeam::imTeam($this->b, fn () => [
            app(\Platform\FoodAlchemist\Services\KostenCacheService::class)->fuer($teil, $this->a),
            app(\Platform\FoodAlchemist\Services\TeamVkService::class)->fuer($teil2, $this->a),
        ]);
    };
    $null = $ergebnis(0);
    expect($ergebnis(1))->toBe($null);
});
