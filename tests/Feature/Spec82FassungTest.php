<?php

use Illuminate\Support\Facades\DB;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Models\Team;
use Platform\Core\Tools\ToolRegistry;
use Platform\FoodAlchemist\Exceptions\FaRechtFehltException;
use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeStep;
use Platform\FoodAlchemist\Services\AboService;
use Platform\FoodAlchemist\Services\FassungService;
use Platform\FoodAlchemist\Services\InhaltsFreigabeService;
use Platform\FoodAlchemist\Support\StammPaket;
use Platform\FoodAlchemist\Support\TeamAncestryRegistry;
use Platform\FoodAlchemist\Support\TeamScope;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 82 §12 B · Durchstich Schritt 2: Arbeitsstand → Veröffentlichen → Fassung.
 * Master bearbeitet die Arbeitszeile, Abonnenten sehen nur die veröffentlichte Fassung (samt Unter-Fassung),
 * Fassungen tragen nie Master-Kosten, unveränderte Unter-Rezepte werden wiederverwendet.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->master = Team::create(['name' => 'BHG.DIGITAL (Master)', 'user_id' => 1, 'personal_team' => false]);
    config(['foodalchemist.master_team_id' => $this->master->id]);
    $this->broich = Team::create(['name' => 'Broich', 'user_id' => 1, 'personal_team' => false]);
    TeamAncestryRegistry::flushAll();
    StammPaket::freischalten($this->broich);
    $this->kurator = $this->makeUser($this->master, 'Kurator', 'owner');
    $this->masterKoch = $this->makeUser($this->master, 'Master Koch', 'member');

    $this->knochen = FoodAlchemistGp::create(['team_id' => null, 'gp_key' => 'f82|knochen', 'name' => 'Kalbsknochen']);
    $this->butter = FoodAlchemistGp::create(['team_id' => null, 'gp_key' => 'f82|butter', 'name' => 'Butter', 'allergen_milk' => 'enthalten']);
    $this->wein = FoodAlchemistGp::create(['team_id' => null, 'gp_key' => 'f82|wein', 'name' => 'Rotwein']);

    $this->fond = $this->makeRecipe($this->master, 'Kalbsfond', ['ek_total_eur' => 3.10, 'sales_net' => 9.0]);
    $this->makeIngredient($this->fond, 'Kalbsknochen', $this->knochen, '2000');
    $this->jus = $this->makeRecipe($this->master, 'Kalbsjus', ['ek_total_eur' => 4.20, 'additional_costs_eur' => 1.0]);
    $this->makeIngredient($this->jus, 'Kalbsfond')->update(['referenced_recipe_id' => $this->fond->id, 'gp_id' => null]);
    $this->rotwein = $this->makeIngredient($this->jus, 'Rotwein', $this->wein, '200');
    FoodAlchemistRecipeStep::create(['team_id' => $this->master->id, 'recipe_id' => $this->jus->id, 'ebene' => 'produktion', 'position' => 1, 'text' => 'Reduzieren']);

    $this->fassungen = app(FassungService::class);
    $this->abos = app(AboService::class);
    $inhalte = app(InhaltsFreigabeService::class);
    $this->sammlung = $inhalte->sammlungAnlegen($this->master, 'Basis', null, $this->kurator);
    $inhalte->sammlungHinzu($this->master, $this->sammlung, 'recipe', [$this->jus->id], $this->kurator);
    $this->abos->abonnieren($this->master, $this->broich, $this->sammlung, $this->kurator);

    $this->sieht = fn (Team $t) => FoodAlchemistRecipe::visibleToTeam($t)->pluck('foodalchemist_recipes.id')->map(fn ($v) => (int) $v)->all();
    $this->fassungVon = fn (FoodAlchemistRecipe $r) => FoodAlchemistRecipe::mitFassungen()->where('fassung_von_id', $r->id)->where('ist_fassung', true)->orderBy('fassung_nr')->get();
});

it('Veröffentlichen friert Rezept + Unter-Rezept ein; Abonnent sieht nur die Fassungen; Master-Bibliothek zeigt keine Fassung', function () {
    expect(array_intersect(($this->sieht)($this->broich), [$this->jus->id, $this->fond->id]))->toBe([]);   // vor Veröffentlichung: nichts

    $r = $this->fassungen->veroeffentlichen($this->master, $this->jus->id, 'Erstveröffentlichung', $this->kurator);
    $jusF = ($this->fassungVon)($this->jus)->sole();
    $fondF = ($this->fassungVon)($this->fond)->sole();

    expect($r['fassung_id'])->toBe($jusF->id)
        ->and($r['neu'])->toBe([$fondF->id, $jusF->id])
        ->and($jusF->recipe_key)->toBe($this->jus->recipe_key . '@f1')
        ->and($jusF->ingredients()->whereNotNull('referenced_recipe_id')->value('referenced_recipe_id'))->toBe($fondF->id)   // auf die Unter-FASSUNG umverdrahtet
        ->and(FoodAlchemistRecipeStep::where('recipe_id', $jusF->id)->value('text'))->toBe('Reduzieren')
        ->and(($this->sieht)($this->broich))->toContain($jusF->id, $fondF->id)->not->toContain($this->jus->id, $this->fond->id)
        ->and(($this->sieht)($this->master))->toContain($this->jus->id, $this->fond->id)->not->toContain($jusF->id, $fondF->id)
        ->and(TeamScope::applyVisible(DB::table('foodalchemist_recipes'), 'foodalchemist_recipes.team_id', $this->master)->pluck('id')->all())->not->toContain($jusF->id);
});

it('Leck-Sperre: Fassungen tragen nie ek_*, Zusatzkosten oder VK des Masters — auch nicht nach erneutem Recompute', function () {
    // Master hat einen echten Einkaufspreis (Teampreis am globalen Stamm-Artikel) — ohne Sperre landete er in der Fassung
    $sup = \Platform\FoodAlchemist\Models\FoodAlchemistSupplier::create(['team_id' => null, 'name' => 'Stamm-Metzger']);
    $la = \Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem::create(['team_id' => null, 'supplier_id' => $sup->id, 'designation' => 'Kalbsknochen 1 kg', 'qty' => 1.0, 'unit_code' => 'kg']);
    \Platform\FoodAlchemist\Models\FoodAlchemistSupplierItemStructure::create(['team_id' => null, 'supplier_item_id' => $la->id, 'gp_id' => $this->knochen->id]);
    $this->knochen->update(['lead_la_supplier_item_id' => $la->id]);
    app(\Platform\FoodAlchemist\Services\PriceService::class)->createTeamPrice($this->master, $la, 5.00);
    app(\Platform\FoodAlchemist\Services\RecipeRecomputeService::class)->recomputePipeline($this->fond->id);
    expect((float) $this->fond->fresh()->ek_total_eur)->toBeGreaterThan(0.0);   // Master-Arbeitszeile ist bepreist

    $this->fassungen->veroeffentlichen($this->master, $this->jus->id, 'Erst', $this->kurator);
    $ids = ($this->fassungVon)($this->jus)->pluck('id')->merge(($this->fassungVon)($this->fond)->pluck('id'))->all();
    app(\Platform\FoodAlchemist\Services\RecipeRecomputeService::class)->recomputeMany($ids);

    foreach (FoodAlchemistRecipe::mitFassungen()->whereIn('id', $ids)->get() as $f) {
        expect([$f->ek_total_eur, $f->ek_per_kg_eur, $f->additional_costs_eur, $f->sales_net, $f->sales_gross])->toBe([null, null, null, null, null], $f->name);
    }
    // Arbeitszeilen des Masters behalten ihre Werte
    expect((float) $this->fond->fresh()->sales_net)->toBe(9.0);
});

it('Arbeitsstand ≠ Fassung: Master bearbeitet, Abonnent sieht weiter f1; erst Veröffentlichen bringt f2, unveränderter Fond wird wiederverwendet', function () {
    $this->fassungen->veroeffentlichen($this->master, $this->jus->id, 'Erst', $this->kurator);
    $jusF1 = ($this->fassungVon)($this->jus)->sole();
    $fondF1 = ($this->fassungVon)($this->fond)->sole();

    $this->rotwein->update(['quantity' => '300']);                       // Arbeitsstand ändern …
    expect(($this->sieht)($this->broich))->toContain($jusF1->id);          // … Abonnent merkt nichts
    expect((float) FoodAlchemistRecipe::mitFassungen()->find($jusF1->id)->ingredients()->where('gp_id', $this->wein->id)->value('quantity'))->toBe(200.0);

    $r = $this->fassungen->veroeffentlichen($this->master, $this->jus->id, 'Mehr Rotwein', $this->kurator);
    $jusF2 = ($this->fassungVon)($this->jus)->last();
    expect($jusF2->fassung_nr)->toBe(2)
        ->and($jusF2->recipe_key)->toBe($this->jus->recipe_key . '@f2')
        ->and($r['wiederverwendet'])->toBe([$fondF1->id])
        ->and(($this->fassungVon)($this->fond))->toHaveCount(1)
        ->and(($this->sieht)($this->broich))->toContain($jusF2->id, $fondF1->id)->not->toContain($jusF1->id);

    // ohne Änderung erneut veröffentlichen = nichts Neues
    $leer = $this->fassungen->veroeffentlichen($this->master, $this->jus->id, 'nochmal', $this->kurator);
    expect($leer['neu'])->toBe([])->and($leer['fassung_id'])->toBe($jusF2->id);
});

it('Allergen-Änderung wird an der neuen Fassung markiert (auch über das Unter-Rezept)', function () {
    $this->fassungen->veroeffentlichen($this->master, $this->jus->id, 'Erst', $this->kurator);
    $this->makeIngredient($this->fond, 'Butter', $this->butter, '100');   // Milch kommt über den Fond dazu

    $r = $this->fassungen->veroeffentlichen($this->master, $this->jus->id, 'Fond mit Butter', $this->kurator);
    $fondF2 = ($this->fassungVon)($this->fond)->last();
    $jusF2 = ($this->fassungVon)($this->jus)->last();
    expect($r['allergen_geaendert'])->toBe([$fondF2->id, $jusF2->id])
        ->and($jusF2->allergen_milk)->toBe('enthalten')
        ->and((bool) ($this->fassungVon)($this->jus)->first()->fassung_allergen_geaendert)->toBeFalse();
});

it('Regeln: nur Master mit „Freigeben", Notiz Pflicht, nur globale Stamm-GPs, Fassungen sind schreibgeschützt', function () {
    expect(fn () => $this->fassungen->veroeffentlichen($this->broich, $this->jus->id, 'x', $this->kurator))->toThrow(RuntimeException::class, 'nur im Master-Team')
        ->and(fn () => $this->fassungen->veroeffentlichen($this->master, $this->jus->id, 'x', $this->masterKoch))->toThrow(FaRechtFehltException::class)
        ->and(fn () => $this->fassungen->veroeffentlichen($this->master, $this->jus->id, '  ', $this->kurator))->toThrow(RuntimeException::class, 'Änderungsnotiz');

    $eigenGp = $this->makeGp($this->master, 'Master-eigenes Salz');
    $this->makeIngredient($this->fond, 'Salz', $eigenGp, '10');
    expect(fn () => $this->fassungen->veroeffentlichen($this->master, $this->jus->id, 'x', $this->kurator))
        ->toThrow(RuntimeException::class, 'Master-eigenes Salz');
    expect(FoodAlchemistRecipe::mitFassungen()->where('ist_fassung', true)->count())->toBe(0);   // nichts halb veröffentlicht

    DB::table('foodalchemist_recipe_ingredients')->where('gp_id', $eigenGp->id)->delete();
    $this->fassungen->veroeffentlichen($this->master, $this->jus->id, 'Erst', $this->kurator);
    $jusF = ($this->fassungVon)($this->jus)->sole();
    $rs = app(\Platform\FoodAlchemist\Services\RecipeService::class);
    expect(fn () => $rs->update($this->master, $jusF->id, ['name' => 'Hack']))->toThrow(\Illuminate\Database\Eloquent\ModelNotFoundException::class)   // Master findet Fassungen nicht als Arbeitszeile
        ->and(fn () => $rs->update($this->broich, $jusF->id, ['name' => 'Hack']))->toThrow(RuntimeException::class, 'Geerbtes Rezept')
        ->and(fn () => $this->fassungen->veroeffentlichen($this->master, $jusF->id, 'x', $this->kurator))->toThrow(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
});

it('Eindeutigkeit: Fassungen kollidieren nicht am UNIQUE(team_id, recipe_key) — auch nicht mit einer soft-gelöschten', function () {
    $this->fassungen->veroeffentlichen($this->master, $this->jus->id, 'f1', $this->kurator);
    ($this->fassungVon)($this->jus)->sole()->delete();                    // soft-delete f1
    $this->rotwein->update(['quantity' => '250']);
    $this->fassungen->veroeffentlichen($this->master, $this->jus->id, 'f2', $this->kurator);

    $keys = FoodAlchemistRecipe::mitFassungen()->withTrashed()->where('fassung_von_id', $this->jus->id)->orderBy('id')->pluck('recipe_key')->all();
    expect($keys)->toBe([$this->jus->recipe_key . '@f1', $this->jus->recipe_key . '@f2']);
});

it('MCP recipe_fassungen.PUBLISH/GET im Lockstep; Mandant liest das Änderungsprotokoll, aber nicht über die Arbeitszeile', function () {
    $reg = app(ToolRegistry::class);
    $this->actingAs($this->kurator);
    $m = new ToolContext($this->kurator, $this->master);
    $mandant = new ToolContext($this->makeUser($this->broich, 'Broich Chef', 'owner'), $this->broich);

    expect($reg->get('foodalchemist.recipe_fassungen.PUBLISH')->execute(['recipe_id' => $this->jus->id, 'notiz' => 'x'],
        new ToolContext($this->masterKoch, $this->master))->errorCode)->toBe('FORBIDDEN');
    $pub = $reg->get('foodalchemist.recipe_fassungen.PUBLISH')->execute(['recipe_id' => $this->jus->id, 'notiz' => 'Erst'], $m);
    expect($pub->success)->toBeTrue((string) ($pub->error ?? ''));
    $this->rotwein->update(['quantity' => '300']);
    $pub2 = $reg->get('foodalchemist.recipe_fassungen.PUBLISH')->execute(['recipe_id' => $this->jus->id, 'notiz' => 'Mehr Rotwein'], $m);

    $protokoll = $reg->get('foodalchemist.recipe_fassungen.GET')->execute(['recipe_id' => $pub2->data['fassung_id']], $mandant);
    expect($protokoll->success)->toBeTrue()
        ->and(array_column($protokoll->data['fassungen'], 'notiz'))->toBe(['Mehr Rotwein', 'Erst'])
        ->and($protokoll->data['fassungen'][0])->not->toHaveKey('ek_total_eur')
        ->and($reg->get('foodalchemist.recipe_fassungen.GET')->execute(['recipe_id' => $this->jus->id], $mandant)->errorCode)->toBe('NOT_FOUND')
        ->and($reg->get('foodalchemist.recipe_fassungen.GET')->execute(['recipe_id' => $this->jus->id], $m)->data['fassungen'])->toHaveCount(2);
});
