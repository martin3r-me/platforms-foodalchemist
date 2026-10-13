<?php

use Livewire\Livewire;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Models\Team;
use Platform\Core\Tools\ToolRegistry;
use Platform\FoodAlchemist\Livewire\Recipes\DetailPanel;
use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Models\FoodAlchemistOfferBlock;
use Platform\FoodAlchemist\Models\FoodAlchemistOfferChapter;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplier;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItemStructure;
use Platform\FoodAlchemist\Services\AboService;
use Platform\FoodAlchemist\Services\AngebotService;
use Platform\FoodAlchemist\Services\FassungService;
use Platform\FoodAlchemist\Services\InhaltsFreigabeService;
use Platform\FoodAlchemist\Services\KostenCacheService;
use Platform\FoodAlchemist\Services\OfferCompositionService;
use Platform\FoodAlchemist\Services\PriceService;
use Platform\FoodAlchemist\Services\RecipeRecomputeService;
use Platform\FoodAlchemist\Support\StammPaket;
use Platform\FoodAlchemist\Support\TeamAncestryRegistry;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 82 · Durchstich Schritt 3, E4: Rezept-Detail, Angebot und MCP recipes.GET lesen die Kosten aus Sicht des Teams.
 * Pflicht-Test Auflage 4 „0 € ist kein Preis": ein unvollständiger EK erscheint nie als vollständige Zahl, das Angebot
 * rechnet aus einer Teilsumme keine Food-Cost-Quote. Dazu: ein Angebots-Block auf eine Abo-FASSUNG löst auf
 * (der globale Scope OhneFassungen blendet Verweise nicht aus).
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->master = Team::create(['name' => 'BHG.DIGITAL (Master)', 'user_id' => 1, 'personal_team' => false]);
    config(['foodalchemist.master_team_id' => $this->master->id]);
    $this->broich = Team::create(['name' => 'Broich', 'user_id' => 1, 'personal_team' => false]);
    $this->neu = Team::create(['name' => 'Neukunde', 'user_id' => 1, 'personal_team' => false]);
    TeamAncestryRegistry::flushAll();
    StammPaket::freischalten($this->broich);
    StammPaket::freischalten($this->neu);
    $this->kurator = $this->makeUser($this->master, 'Kurator', 'owner');
    $this->broichChef = $this->makeUser($this->broich, 'Broich Chef', 'owner');
    $this->neuChef = $this->makeUser($this->neu, 'Neu Chef', 'owner');

    $sup = FoodAlchemistSupplier::create(['team_id' => null, 'name' => 'Stamm-Großhandel']);
    $mk = function (string $name) use ($sup) {
        $gp = FoodAlchemistGp::create(['team_id' => null, 'gp_key' => 'kk|' . $name, 'name' => $name]);
        $la = FoodAlchemistSupplierItem::create(['team_id' => null, 'supplier_id' => $sup->id, 'designation' => $name . ' 1 kg', 'qty' => 1.0, 'unit_code' => 'kg']);
        FoodAlchemistSupplierItemStructure::create(['team_id' => null, 'supplier_item_id' => $la->id, 'gp_id' => $gp->id]);
        $gp->update(['lead_la_supplier_item_id' => $la->id]);

        return [$gp->refresh(), $la];
    };
    [$knochen, $laKnochen] = $mk('Kalbsknochen');
    [$butter, $laButter] = $mk('Butter');
    $this->jus = $this->makeRecipe($this->master, 'Kalbsjus', ['is_sales_recipe' => false]);   // detail()/recipes.GET = Basis-Sicht
    $this->makeIngredient($this->jus, 'Kalbsknochen', $knochen, '1000');
    $this->makeIngredient($this->jus, 'Butter', $butter, '100', 2);
    app(RecipeRecomputeService::class)->recomputePipeline($this->jus->id);

    $inhalte = app(InhaltsFreigabeService::class);
    $sid = $inhalte->sammlungAnlegen($this->master, 'Basis', null, $this->kurator);
    $inhalte->sammlungHinzu($this->master, $sid, 'recipe', [$this->jus->id], $this->kurator);
    app(AboService::class)->abonnieren($this->master, $this->broich, $sid, $this->kurator);
    app(AboService::class)->abonnieren($this->master, $this->neu, $sid, $this->kurator);
    $this->jusF = FoodAlchemistRecipe::mitFassungen()->findOrFail(app(FassungService::class)->veroeffentlichen($this->master, $this->jus->id, 'Erst', $this->kurator)['fassung_id']);

    $p = app(PriceService::class);
    $p->createTeamPrice($this->broich, $laKnochen, 4.00);
    $p->createTeamPrice($this->broich, $laButter, 10.00);
    $p->createTeamPrice($this->neu, $laButter, 10.00);        // Neukunde: Knochen ohne Preis → unvollständig

    $this->angebotMitJus = function (Team $team, $user) {
        $this->actingAs($user);
        $angebot = app(AngebotService::class)->create($team, ['name' => 'Gala', 'personen' => 50]);
        $kap = FoodAlchemistOfferChapter::create(['team_id' => $team->id, 'offer_id' => $angebot->id, 'title' => 'Hauptgang', 'position' => 0]);
        FoodAlchemistOfferBlock::create(['team_id' => $team->id, 'chapter_id' => $kap->id, 'type' => 'recipe_ref', 'position' => 0,
            'visible' => true, 'sales_recipe_id' => $this->jusF->id]);

        return $kap->fresh();
    };
});

it('MCP recipes.GET: Mandant liest Team-Kosten der Fassung; unvollständig ist ausgewiesen; Master-Arbeitszeile unverändert', function () {
    $reg = app(ToolRegistry::class);
    $this->actingAs($this->broichChef);
    $b = $reg->get('foodalchemist.recipes.GET')->execute(['id' => $this->jusF->id], new ToolContext($this->broichChef, $this->broich));
    expect($b->success)->toBeTrue((string) ($b->error ?? ''))
        ->and($b->data['ek_quelle'])->toBe('team_cache')
        ->and($b->data['ek_vollstaendig'])->toBeTrue()
        ->and((float) $b->data['ek_total_eur'])->toBe(5.00);              // 1 kg × 4 € + 100 g × 10 €/kg

    $this->actingAs($this->neuChef);
    $n = $reg->get('foodalchemist.recipes.GET')->execute(['id' => $this->jusF->id], new ToolContext($this->neuChef, $this->neu));
    expect($n->data['ek_vollstaendig'])->toBeFalse()
        ->and((float) $n->data['ek_total_eur'])->toBe(1.00)               // Teilsumme
        ->and($n->data['ek_fehlende_zutaten'][0]['name'])->toBe('Kalbsknochen')
        ->and($n->data['ek_fehlend_anteil_masse'])->toBeGreaterThan(0.9);

    $this->actingAs($this->kurator);
    $m = $reg->get('foodalchemist.recipes.GET')->execute(['id' => $this->jus->id], new ToolContext($this->kurator, $this->master));
    expect($m->data['ek_quelle'])->toBe('rezept')->and($m->data['ek_total_eur'])->toBe($this->jus->fresh()->ek_total_eur);
});

it('Angebot: Block auf eine Abo-Fassung löst auf und rechnet mit den Team-Kosten', function () {
    $kap = ($this->angebotMitJus)($this->broich, $this->broichChef);
    $agg = app(OfferCompositionService::class)->kapitelAggregat($this->broich, $kap);

    expect($kap->blocks()->first()->dish)->not->toBeNull()          // Verweis auf Fassung trotz globalem Scope
        ->and($agg['ek_pro_person'])->toBe(5.00)
        ->and($agg['ek_vollstaendig'])->toBeTrue();
});

it('Pflicht (Auflage 4): 0 € ist kein Preis — unvollständiger EK im Angebot ergibt keine Food-Cost-Quote, im Detail „ab …"', function () {
    $kap = ($this->angebotMitJus)($this->neu, $this->neuChef);
    $kap->blocks()->first()->update(['price_basis' => 'person']);
    $komp = app(OfferCompositionService::class);
    $agg = $komp->kapitelAggregat($this->neu, $kap);
    expect($agg['ek_vollstaendig'])->toBeFalse()
        ->and($agg['food_cost_percent'])->toBeNull()
        ->and($komp->blockPreis($kap->blocks()->first(), null, $this->neu)['ek_vollstaendig'])->toBeFalse();

    // Detail-Panel: Teilsumme als „ab …", fehlende Zutat mit Pfad
    $this->actingAs($this->neuChef);
    Livewire::test(DetailPanel::class, ['recipeId' => $this->jusF->id])
        ->assertSeeHtml('data-kosten-unvollstaendig')
        ->assertSee('ab 1,00 €')
        ->assertSee('Kalbsjus › Kalbsknochen');

    // vollständiger EK: kein „ab"
    $this->actingAs($this->broichChef);
    Livewire::test(DetailPanel::class, ['recipeId' => $this->jusF->id])
        ->assertDontSeeHtml('data-kosten-unvollstaendig')
        ->assertSee('5,00 €')
        ->assertDontSee('ab 5,00 €');
});

it('KostenCacheService lädt Teil-Spalten nach: ein Gericht nur mit id/sales_net/ek_total_eur wird richtig zugeordnet', function () {
    $teil = FoodAlchemistRecipe::mitFassungen()->select(['id', 'sales_net', 'ek_total_eur'])->find($this->jus->id);
    expect(app(KostenCacheService::class)->fuer($teil, $this->master)['quelle'])->toBe('rezept');
});

it('Pflicht (Auflage 4): VK-Vorschlag und Marge — aus einer EK-Teilsumme kein Wareneinsatz/DB, VK-Vorschlag nur vorläufig', function () {
    $kalk = app(\Platform\FoodAlchemist\Services\KalkulationService::class);
    $this->jusF->forceFill(['sales_net' => null]);   // Fassung trägt keinen VK des Masters (Leck-Sperre)

    $neu = $kalk->recipeHk($this->neu, $this->jusF);
    expect($neu['ek_vollstaendig'])->toBeFalse()
        ->and($neu['vk_vorschlag_vorlaeufig'])->toBeTrue()
        ->and($neu['wareneinsatz_pct'])->toBeNull()
        ->and($neu['db_eur'])->toBeNull()
        ->and($neu['hk1_total'])->toBe(1.0);                 // Teilsumme, ausgewiesen als solche

    $broich = $kalk->recipeHk($this->broich, $this->jusF);
    expect($broich['ek_vollstaendig'])->toBeTrue()
        ->and($broich['vk_vorschlag_vorlaeufig'])->toBeFalse()
        ->and($broich['hk1_total'])->toBe(5.0);
});
