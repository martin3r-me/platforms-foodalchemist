<?php

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Tools\ToolRegistry;
use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Models\FoodAlchemistGpForm;
use Platform\FoodAlchemist\Models\FoodAlchemistPrice;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplier;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItemStructure;
use Platform\FoodAlchemist\Models\FoodAlchemistVocabEinheit;
use Platform\FoodAlchemist\Services\Ai\AiGatewayService;
use Platform\FoodAlchemist\Services\Ai\AiProposal;
use Platform\FoodAlchemist\Services\DerivatAusbeuteService;
use Platform\FoodAlchemist\Services\GpFormService;
use Platform\FoodAlchemist\Services\IngredientMatchService;
use Platform\FoodAlchemist\Services\Matching\FruchtDerivat;
use Platform\FoodAlchemist\Services\PlanungsblattService;
use Platform\FoodAlchemist\Services\RecipeRecomputeService;
use Platform\FoodAlchemist\Services\Reife\RecipeReifeAdapter;
use Platform\FoodAlchemist\Support\Derivat\DerivatArt;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 96 · Derivate mit Ausbeute (Dominique 05./06.11.): Zweck-Derivate (Saft, Abrieb) kaufen und kosten über die Mutter ÷ Ausbeute,
 * Nebenprodukte (Abschnitte, Parüren) kosten 0 € und werden nie bestellt. Die Ausbeute schätzt die KI (Herkunft ki), manuell gewinnt.
 *
 * Limette: 0,30 € je Stück, 80 g je Stück. Saft 30 g je Limette, Abrieb 2 g je Limette.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam, 'Root User'));
    $this->g = FoodAlchemistVocabEinheit::firstOrCreate(['team_id' => $this->rootTeam->id, 'slug' => 'g'], ['display_de' => 'Gramm', 'dimension' => 'mass', 'default_in_g' => 1]);
    $this->stk = FoodAlchemistVocabEinheit::firstOrCreate(['team_id' => $this->rootTeam->id, 'slug' => 'stk'], ['display_de' => 'Stück', 'dimension' => 'count']);
    $this->ausbeute = app(DerivatAusbeuteService::class);

    $chefs = FoodAlchemistSupplier::firstOrCreate(['team_id' => $this->rootTeam->id, 'name' => 'Chefs']);
    $this->limette = $this->makeGp($this->rootTeam, 'Limette: frisch');
    $this->limette->update(['commodity_group_code' => '02', 'main_ingredient_slug' => 'limette', 'status' => 'approved']);
    $la = FoodAlchemistSupplierItem::create(['team_id' => $this->rootTeam->id, 'supplier_id' => $chefs->id, 'designation' => 'Limetten Stk',
        'article_number' => 'LIM-1', 'qty' => 1.0, 'unit_code' => 'Stk']);
    FoodAlchemistSupplierItemStructure::create(['team_id' => $this->rootTeam->id, 'supplier_item_id' => $la->id, 'gp_id' => $this->limette->id]);
    FoodAlchemistPrice::create(['team_id' => $this->rootTeam->id, 'supplier_item_id' => $la->id, 'price' => 0.30, 'status' => '0']);
    $this->limette->update(['lead_la_supplier_item_id' => $la->id]);
    app(GpFormService::class)->setForm($this->rootTeam, (int) $this->limette->id, 'stk', 80.0);

    $derivat = function (string $name, int $mutterId) {
        $gp = $this->makeGp($this->rootTeam, $name);
        $gp->update(['is_derivat' => true, 'derivat_von_gp_id' => $mutterId, 'requires_la' => false, 'status' => 'approved']);

        return $gp->refresh();
    };
    $this->saft = $derivat('Limette: frisch, Saft', (int) $this->limette->id);
    $this->abrieb = $derivat('Limette: frisch, Abrieb', (int) $this->limette->id);
    $this->rind = $this->makeGp($this->rootTeam, 'Rind: frisch, Hüfte');
    $this->rind->update(['commodity_group_code' => '03']);
    $this->parueren = $derivat('Rind: frisch, Parüren', (int) $this->rind->id);

    $this->rezept = function (string $name, array $zeilen, array $attrs = []) {
        $r = FoodAlchemistRecipe::create(['team_id' => $this->rootTeam->id, 'recipe_key' => 'r-' . md5($name), 'name' => $name,
            'status' => 'approved', 'is_sales_recipe' => false, 'yield_kg' => 1.0, ...$attrs]);
        foreach ($zeilen as $i => [$gp, $menge, $einheit]) {
            $r->ingredients()->create(['team_id' => $this->rootTeam->id, 'position' => $i, 'gp_id' => $gp->id, 'raw_text' => $gp->name,
                'quantity' => $menge, 'unit_vocab_id' => $einheit->id]);
        }
        app(RecipeRecomputeService::class)->recomputePipeline($r->id);

        return $r->refresh();
    };
});

it('leitet die Art aus dem Namen ab: Saft/Abrieb = Zweck, Parüren = Nebenprodukt, Schale nur bei Obst', function () {
    expect(DerivatArt::von($this->saft))->toBe('zweck')
        ->and(DerivatArt::von($this->abrieb))->toBe('zweck')
        ->and(DerivatArt::von($this->parueren))->toBe('nebenprodukt')
        ->and(DerivatArt::ausName('Zitrone: frisch, Schale', '02'))->toBe('zweck')
        ->and(DerivatArt::ausName('Karotte: frisch, Schale', '01'))->toBe('nebenprodukt');
    $this->saft->update(['derivat_art' => 'nebenprodukt']);
    expect(DerivatArt::von($this->saft->refresh()))->toBe('nebenprodukt');   // die Spalte gewinnt
});

it('EK über die Mutter: „Limette (Saft), 1 Stück“ kostet eine Limette, 60 g Saft zwei', function () {
    $this->ausbeute->setze($this->rootTeam, (int) $this->saft->id, ['derivat_ausbeute_wert' => 30, 'derivat_ausbeute_basis' => 'g_je_stueck']);

    $stueck = ($this->rezept)('Chimichurri', [[$this->saft, 1, $this->stk]]);
    $gramm = ($this->rezept)('Limettendressing', [[$this->saft, 60, $this->g]]);

    expect((float) $stueck->ek_total_eur)->toBe(0.30)
        ->and((int) $stueck->ek_n_ingredients_priced)->toBe(1)
        ->and((float) $gramm->ek_total_eur)->toBe(0.60)
        // Stück-Form des Derivats = Ausbeute je Frucht (Yield/Planung rechnen damit)
        ->and((float) FoodAlchemistGpForm::where('gp_id', $this->saft->id)->where('form_slug', 'stk')->value('gramm'))->toBe(30.0);
});

it('ohne Ausbeute bleibt die Zeile unbepreist und die Reife meldet „Ausbeute fehlt“', function () {
    $r = ($this->rezept)('Limettendressing', [[$this->saft, 60, $this->g]]);
    $reife = app(RecipeReifeAdapter::class)->messe($this->rootTeam, (int) $r->id);

    expect($r->ek_total_eur)->toBeNull()
        ->and(collect($reife['luecken'])->pluck('code'))->toContain('derivat_ausbeute');

    $this->ausbeute->setze($this->rootTeam, (int) $this->saft->id, ['derivat_ausbeute_wert' => 30, 'derivat_ausbeute_basis' => 'g_je_stueck']);
    $reife = app(RecipeReifeAdapter::class)->messe($this->rootTeam, (int) $r->id);
    expect(collect($reife['luecken'])->pluck('code'))->not->toContain('derivat_ausbeute');
});

it('Nebenprodukt kostet 0 € und zählt als bepreist', function () {
    $r = ($this->rezept)('Rinderfond', [[$this->parueren, 500, $this->g]]);

    expect((int) $r->ek_n_ingredients_priced)->toBe(1)
        ->and((float) ($r->ek_total_eur ?? 0))->toBe(0.0);
});

it('Bestellung: Saft + Abrieb derselben Limette → der größere Bedarf an Limetten, Parüren aus dem Sammelbestand', function () {
    $this->ausbeute->setze($this->rootTeam, (int) $this->saft->id, ['derivat_ausbeute_wert' => 30, 'derivat_ausbeute_basis' => 'g_je_stueck']);
    $this->ausbeute->setze($this->rootTeam, (int) $this->abrieb->id, ['derivat_ausbeute_wert' => 2, 'derivat_ausbeute_basis' => 'g_je_stueck']);
    // 10 Ansätze: 30 g Saft (1 Limette) + 4 g Abrieb (2 Limetten) + 100 g Parüren je Ansatz
    $r = ($this->rezept)('Limettensauce', [[$this->saft, 30, $this->g], [$this->abrieb, 4, $this->g], [$this->parueren, 100, $this->g]]);

    $liste = app(PlanungsblattService::class)->einkaufsliste($this->rootTeam, [['recipe_id' => $r->id, 'portions' => 10]]);
    $gruppen = collect($liste['lieferanten']);
    $chefs = $gruppen->firstWhere('lieferant', 'Chefs');
    $limette = collect($chefs['positionen'])->firstWhere('gp_id', $this->limette->id);
    $sammel = $gruppen->firstWhere('sammelbestand', true);

    expect($limette['menge_g'])->toBe(1600.0)   // max(10 × 1, 10 × 2) = 20 Limetten × 80 g, nicht 30
        ->and($limette['aus_derivaten'])->toContain('Limette: frisch, Abrieb')
        ->and($gruppen->pluck('positionen')->flatten(1)->pluck('gp_id'))->not->toContain($this->saft->id)
        ->and(collect($sammel['positionen'])->pluck('gp_id')->all())->toBe([$this->parueren->id])
        ->and($sammel['ek_summe'])->toBe(0.0);
});

it('Matcher: Fruchtteil-Zeilen gehen aufs Derivat, das Kompositum entscheidet die Leitplanke, Gemüsesaft nie', function () {
    $team = $this->rootTeam;

    expect(FruchtDerivat::treffer($team, 'Limette (Saft)')['gp_id'] ?? null)->toBe($this->saft->id)
        ->and(FruchtDerivat::treffer($team, 'Saft von 2 Limetten')['gp_id'] ?? null)->toBe($this->saft->id)
        ->and(FruchtDerivat::treffer($team, 'Abrieb einer Limette')['gp_id'] ?? null)->toBe($this->abrieb->id)
        ->and(FruchtDerivat::treffer($team, 'Limettensaft')['gp_id'] ?? null)->toBe($this->saft->id)
        // Convenience ohne gekauften Saft im Bestand → trotzdem Derivat (Dominique 07.11.: „Fertigware, wenn GP vorhanden“)
        ->and(FruchtDerivat::treffer($team, 'Limettensaft', 'teil_convenience')['gp_id'] ?? null)->toBe($this->saft->id);

    $gekauft = $this->makeGp($team, 'Limettensaft: konserviert');
    $gekauft->update(['commodity_group_code' => '02', 'status' => 'approved']);

    expect(FruchtDerivat::treffer($team, 'Limettensaft', 'teil_convenience'))->toBeNull()
        ->and(FruchtDerivat::treffer($team, 'Limettensaft', 'voll_convenience'))->toBeNull()
        ->and(FruchtDerivat::treffer($team, 'Limettensaft', 'from_scratch')['gp_id'] ?? null)->toBe($this->saft->id)
        ->and(FruchtDerivat::treffer($team, 'Limette (Saft)', 'voll_convenience')['gp_id'] ?? null)->toBe($this->saft->id)   // Frucht genannt = frisch
        ->and(FruchtDerivat::treffer($team, 'frisch gepresster Limettensaft', 'voll_convenience')['gp_id'] ?? null)->toBe($this->saft->id)
        ->and(FruchtDerivat::treffer($team, 'Tomatensaft'))->toBeNull()
        ->and(app(IngredientMatchService::class)->matchIngredient($team, 'Limette (Saft)')['gp_id'])->toBe($this->saft->id);
});

it('KI schätzt die fehlende Ausbeute (Herkunft ki), ein manueller Wert bleibt', function () {
    $this->mock(AiGatewayService::class, fn ($m) => $m->shouldReceive('propose')->once()
        ->withArgs(fn (string $key, array $ctx) => $key === 'gp.derivat_ausbeute' && $ctx['mutter'] === 'Limette: frisch')
        ->andReturn(new AiProposal(['basis' => 'g_je_stueck', 'wert' => 30], 0.8, 'Mock')));

    $this->ausbeute = app(DerivatAusbeuteService::class);   // nach dem Mock auflösen
    $r = $this->ausbeute->schaetzeKi($this->rootTeam, (int) $this->saft->id);
    $saft = $this->saft->refresh();

    expect($r)->toMatchArray(['geschrieben' => true])
        ->and((float) $saft->derivat_ausbeute_wert)->toBe(30.0)
        ->and($saft->derivat_ausbeute_quelle)->toBe('ki')
        ->and(FoodAlchemistGpForm::where('gp_id', $saft->id)->where('form_slug', 'stk')->value('source'))->toBe('ki');

    $this->ausbeute->setze($this->rootTeam, (int) $this->abrieb->id, ['derivat_ausbeute_wert' => 3, 'derivat_ausbeute_basis' => 'g_je_stueck']);
    expect($this->ausbeute->schaetzeKi($this->rootTeam, (int) $this->abrieb->id)['grund'])->toBe('manuell_gepflegt');   // kein zweiter propose()
});

it('MCP: gp_derivat.PUT setzt Art und Ausbeute von Hand, gps.GET zeigt sie', function () {
    $registry = app(ToolRegistry::class);
    $ctx = ToolContext::fromAuth();

    $put = $registry->get('foodalchemist.gp_derivat.PUT')->execute(['gp_id' => $this->saft->id, 'derivat_art' => 'zweck',
        'derivat_ausbeute_wert' => 28, 'derivat_ausbeute_basis' => 'g_je_stueck'], $ctx);
    $get = $registry->get('foodalchemist.gps.GET')->execute(['id' => $this->saft->id], $ctx);
    $falsch = $registry->get('foodalchemist.gp_derivat.PUT')->execute(['gp_id' => $this->limette->id, 'derivat_art' => 'zweck'], $ctx);

    expect($put->success)->toBeTrue()
        ->and($put->data['derivat_ausbeute_quelle'])->toBe('manual')
        ->and($get->data['derivat']['derivat_ausbeute_wert'] ?? ($get->data['gp']['derivat']['derivat_ausbeute_wert'] ?? null))->toBe(28.0)
        ->and($falsch->success)->toBeFalse();
});

it('Bestellung direkt auf ein Derivat: Vorschau UND Entwurf gehen auf die Mutter, Parüren werden nie bestellt', function () {
    $this->ausbeute->setze($this->rootTeam, (int) $this->saft->id, ['derivat_ausbeute_wert' => 30, 'derivat_ausbeute_basis' => 'g_je_stueck']);
    $svc = app(\Platform\FoodAlchemist\Services\OrderService::class);
    $quellen = [
        ['type' => 'gp', 'id' => $this->saft->id, 'qty' => 60, 'unit' => 'g', 'delivery_date' => '2026-12-01'],
        ['type' => 'gp', 'id' => $this->parueren->id, 'qty' => 500, 'unit' => 'g', 'delivery_date' => '2026-12-01'],
    ];

    $vorschau = $svc->previewFromSources($this->rootTeam, $quellen);
    $positionen = collect($vorschau['orders_preview'])->pluck('positionen')->flatten(1);
    expect($positionen->pluck('gp_id')->all())->toBe([$this->limette->id])
        ->and(collect($vorschau['unresolved'])->pluck('code')->all())->toBe(['sammelbestand']);

    $svc->generateDraftsFromSources($this->rootTeam, $quellen);
    $zeilen = \Platform\FoodAlchemist\Models\FoodAlchemistOrderLine::query()->get();
    expect($zeilen->pluck('gp_id')->unique()->values()->all())->toBe([$this->limette->id])
        ->and((float) $zeilen->sum('needed_base_g'))->toBe(160.0);   // 60 g Saft = 2 Limetten × 80 g
});
