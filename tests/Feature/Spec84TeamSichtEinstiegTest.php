<?php

use Illuminate\Support\Facades\DB;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistAbo;
use Platform\FoodAlchemist\Models\FoodAlchemistAngebotEinfrierung;
use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Services\AboKuendigungService;
use Platform\FoodAlchemist\Services\AboService;
use Platform\FoodAlchemist\Services\AngebotEinfrierService;
use Platform\FoodAlchemist\Services\AngebotService;
use Platform\FoodAlchemist\Services\FassungService;
use Platform\FoodAlchemist\Services\InhaltsFreigabeService;
use Platform\FoodAlchemist\Services\MandantBeendenService;
use Platform\FoodAlchemist\Services\MandantExportService;
use Platform\FoodAlchemist\Services\OfferCompositionService;
use Platform\FoodAlchemist\Services\RecipeRecomputeService;
use Platform\FoodAlchemist\Support\FaTeam;
use Platform\FoodAlchemist\Support\StammPaket;
use Platform\FoodAlchemist\Support\TeamAncestryRegistry;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 84 B3 · C (Kurator 17.10.): Services mit ausdrücklichem Team lesen in dessen Sicht (`TeamSicht::lesenIm`, nur ab Stufe 1).
 *  1. Die Sicht verleiht kein Recht: ein Fremder, der den Service mit einem fremden Team aufruft, wird weiter abgewiesen — je Kategorie.
 *  2. Ergebnis gleich wie bei Stufe 0: Einfrier-Inhalt (Angebot) und Kosten nach Recompute einer Fremd-/Master-Zeile.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    \Platform\FoodAlchemist\Models\Scopes\TeamSicht::zuruecksetzen();
    $this->master = Team::create(['name' => 'Master', 'user_id' => 1, 'personal_team' => false]);
    config(['foodalchemist.master_team_id' => $this->master->id]);
    $this->broich = Team::create(['name' => 'Broich', 'user_id' => 1, 'personal_team' => false]);
    $this->soul = Team::create(['name' => 'Soulkitchen', 'user_id' => 1, 'personal_team' => false]);
    TeamAncestryRegistry::flushAll();
    StammPaket::freischalten($this->broich);
    StammPaket::freischalten($this->soul);
    $this->kurator = $this->makeUser($this->master, 'Kurator', 'owner');
    $this->koch = $this->makeUser($this->broich, 'Broich-Koch', 'owner');
    $this->fremd = $this->makeUser($this->soul, 'Soul-Chef', 'owner');

    $this->knochen = FoodAlchemistGp::create(['team_id' => null, 'gp_key' => 'e84|knochen', 'name' => 'Kalbsknochen']);
    $this->jus = $this->makeRecipe($this->master, 'Kalbsjus');
    $this->makeIngredient($this->jus, 'Kalbsknochen', $this->knochen, '2000');
    $inhalte = app(InhaltsFreigabeService::class);
    $this->sammlung = $inhalte->sammlungAnlegen($this->master, 'Basis', null, $this->kurator);
    $inhalte->sammlungHinzu($this->master, $this->sammlung, 'recipe', [$this->jus->id], $this->kurator);
    $this->aboBroich = app(AboService::class)->abonnieren($this->master, $this->broich, $this->sammlung, $this->kurator);
    $this->fassungId = app(FassungService::class)->veroeffentlichen($this->master, $this->jus->id, 'Erst', $this->kurator)['fassung_id'];

    $this->lamm = $this->makeRecipe($this->broich, 'Lamm mit Jus');
    $this->makeIngredient($this->lamm, 'Kalbsjus')->update(['referenced_recipe_id' => $this->fassungId, 'gp_id' => null]);
    $this->actingAs($this->koch);
    $this->angebot = app(AngebotService::class)->create($this->broich, ['name' => 'Hochzeit']);
    $kapitel = app(OfferCompositionService::class)->addKapitel($this->broich, $this->angebot->id, ['title' => 'Menü']);
    $kapitel->blocks()->create(['team_id' => $this->broich->id, 'type' => 'recipe_ref', 'sales_recipe_id' => $this->lamm->id, 'position' => 1, 'visible' => true]);

    config(['foodalchemist.team_sicht.stufe' => 1]);
});

// ── 1. imTeam verleiht kein Recht: Fremder mit fremdem Team wird abgewiesen ──

it('Fassung veröffentlichen: ein Mandanten-Chef im Namen des Masters wird abgewiesen, keine neue Fassung', function () {
    $this->actingAs($this->fremd);
    $vorher = FoodAlchemistRecipe::mitFassungen()->ohneTeamSicht('test')->count();
    expect(fn () => app(FassungService::class)->veroeffentlichen($this->master, $this->jus->id, 'Fremd', $this->fremd))->toThrow(\RuntimeException::class);
    expect(FoodAlchemistRecipe::mitFassungen()->ohneTeamSicht('test')->count())->toBe($vorher);
});

it('Abo kündigen: ein fremder Mandant kündigt Broichs Abo nicht, das Abo bleibt aktiv', function () {
    $this->actingAs($this->fremd);
    expect(fn () => app(AboKuendigungService::class)->kuendigen($this->soul, $this->aboBroich->id, $this->fremd, bestaetigt: true))->toThrow(\RuntimeException::class);
    expect(FoodAlchemistAbo::find($this->aboBroich->id)->status)->toBe(FoodAlchemistAbo::AKTIV);
});

it('Zurückziehen: nur der Master — ein Mandant mit fremdem Team wird abgewiesen', function () {
    $this->actingAs($this->fremd);
    expect(fn () => app(AboKuendigungService::class)->zurueckziehen($this->soul, $this->sammlung, $this->jus->id, $this->fremd, true))->toThrow(\RuntimeException::class);
    expect(DB::table('foodalchemist_sammlung_objekte')->where('sammlung_id', $this->sammlung)->count())->toBe(1);
});

it('Mandant beenden und Export: ein Mandanten-Chef am fremden Mandanten wird abgewiesen', function () {
    $this->actingAs($this->fremd);
    expect(fn () => app(MandantBeendenService::class)->vorschau($this->broich, $this->fremd))->toThrow(\Exception::class);
    expect(fn () => app(MandantBeendenService::class)->fuehreAus($this->broich, $this->fremd, true))->toThrow(\Exception::class);
    expect(fn () => app(MandantExportService::class)->starte($this->broich, $this->fremd))->toThrow(\Exception::class);
    expect(DB::table('foodalchemist_mandant_exporte')->count())->toBe(0);
});

it('Einfrieren: ein Fremder mit eigenem Team liest in SEINER Sicht — Broichs Abo-Gericht bleibt ihm verborgen, nichts wird eingefroren', function () {
    $this->actingAs($this->fremd);
    expect(app(AngebotEinfrierService::class)->einfrieren($this->soul, $this->fremd, $this->angebot))->toBeNull();
    expect(FoodAlchemistAngebotEinfrierung::count())->toBe(0);
});

// ── 2. Ergebnis gleich wie bei Stufe 0 ──

it('Einfrier-Inhalt bei Stufe 1 = Stufe 0 (Fassungs-IDs, Allergene, EK-Status)', function () {
    $inhalt = function (int $stufe) {
        config(['foodalchemist.team_sicht.stufe' => $stufe]);
        DB::beginTransaction();
        try {
            $e = app(AngebotEinfrierService::class)->einfrieren($this->broich, $this->koch, $this->angebot);

            return $e->zeilen->map(fn ($z) => collect($z->getAttributes())->except(['id', 'uuid', 'einfrierung_id', 'created_at', 'updated_at'])->all())->all();
        } finally {
            DB::rollBack();
        }
    };
    $null = $inhalt(0);
    expect($null)->toHaveCount(1);
    expect($inhalt(1))->toBe($null);
});

it('Recompute einer Master-Fassung aus Broich-Kontext: Kosten bei Stufe 1 = Stufe 0', function () {
    $kosten = function (int $stufe) {
        config(['foodalchemist.team_sicht.stufe' => $stufe]);
        DB::beginTransaction();
        try {
            FaTeam::imTeam($this->broich, fn () => app(RecipeRecomputeService::class)->recomputePipeline($this->fassungId));

            return (array) DB::table('foodalchemist_recipes')->where('id', $this->fassungId)->first();
        } finally {
            DB::rollBack();
        }
    };
    $null = $kosten(0);
    $eins = $kosten(1);
    unset($null['updated_at'], $eins['updated_at']);
    expect($eins)->toBe($null);
});
