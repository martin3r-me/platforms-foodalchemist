<?php

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Tools\ToolRegistry;
use Platform\FoodAlchemist\Models\FoodAlchemistPrice;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplier;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItemStructure;
use Platform\FoodAlchemist\Services\RecipeRecomputeService;
use Platform\FoodAlchemist\Services\SignalCauseService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 21 · Tranche P · Punkt 5 (S3b-3) — die Ursachen-Kette nach unten.
 *
 * Das Cockpit zeigt zwei völlig verschiedene Arbeitspakete identisch an: „Lead-LA zeigt
 * ins Leere" (ein Klick) und „GP hat gar keinen bepreisten Lieferantenartikel" (Einkauf).
 * Diese Tests halten genau diese Unterscheidung fest — plus den Deep-Link aufs verletzte §.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->ursachen = app(SignalCauseService::class);
    $this->supplier = FoodAlchemistSupplier::create(['team_id' => $this->rootTeam->id, 'name' => 'Necta']);

    /** LA am GP anlegen; $preis === null ⇒ LA ohne Preiszeile. */
    $this->mkLa = function ($gp, ?float $preis) {
        $la = FoodAlchemistSupplierItem::create([
            'team_id' => $this->rootTeam->id, 'supplier_id' => $this->supplier->id,
            'designation' => $gp->name, 'qty' => 1.0, 'unit_code' => 'kg',
        ]);
        FoodAlchemistSupplierItemStructure::create([
            'team_id' => $this->rootTeam->id, 'supplier_item_id' => $la->id, 'gp_id' => $gp->id,
        ]);
        if ($preis !== null) {
            FoodAlchemistPrice::create([
                'team_id' => $this->rootTeam->id, 'supplier_item_id' => $la->id, 'price' => $preis, 'status' => '0',
            ]);
        }

        return $la;
    };

    /** Rezept mit genau einer Zutat am gegebenen GP, frisch gerechnet. */
    $this->mkRezept = function (string $name, $gp) {
        $r = $this->makeRecipe($this->rootTeam, $name);
        $this->makeIngredient($r, '500 g ' . $gp->name, $gp, '500');
        app(RecipeRecomputeService::class)->recomputeAndPropagate($r->id);

        return $r->refresh();
    };
});

it('behauptet den fehlenden Lead-LA NICHT als EK-Ursache — die Kaskade mittelt', function () {
    // GP mit bepreistem LA, aber ohne Lead: die T3-Kaskade fällt auf den Durchschnitt
    // zurück, die Zeile IST bepreist. Wer hier „kein Lead-LA" als Ursache zeigte,
    // schickte jemanden auf eine Reparatur, die die Zahl nicht bewegt.
    $gp = $this->makeGp($this->rootTeam, 'Butter');
    ($this->mkLa)($gp, 8.90);
    $r = ($this->mkRezept)('Fond: Butter', $gp);

    expect($r->ek_total_eur)->not->toBeNull()
        ->and((int) $r->ek_n_ingredients_priced)->toBe((int) $r->ek_n_ingredients_total)
        ->and(collect($this->ursachen->fuerObjekt($this->rootTeam, 'recipe', $r->id))->firstWhere('art', 'ek'))->toBeNull();
});

it('nennt bei einem GP ohne Lieferantenartikel den GP als Glied der Kette', function () {
    $gp = $this->makeGp($this->rootTeam, 'Trüffelbutter');
    $r = ($this->mkRezept)('Fond: Trueffelbutter', $gp);

    $ek = collect($this->ursachen->fuerObjekt($this->rootTeam, 'recipe', $r->id))->firstWhere('art', 'ek');

    expect($ek)->not->toBeNull()
        ->and($ek['glieder'][0]['gp_name'])->toBe('Trüffelbutter')
        ->and($ek['glieder'][0]['gp_id'])->toBe($gp->id)
        ->and($ek['glieder'][0]['ursache'])->toBe('GP ohne Lieferantenartikel')
        ->and($ek['glieder'][0]['fixbar'])->toBeFalse();
});

it('unterscheidet die Beschaffungs-Lücke vom Lead-Problem — sie ist NICHT fixbar', function () {
    // Fall A: gar kein LA. Fall B: LA da, aber ohne Preiszeile.
    $ohneLa = $this->makeGp($this->rootTeam, 'Trüffel');
    $ohnePreis = $this->makeGp($this->rootTeam, 'Safran');
    ($this->mkLa)($ohnePreis, null);

    $a = ($this->mkRezept)('Fond: Trueffel', $ohneLa);
    $b = ($this->mkRezept)('Fond: Safran', $ohnePreis);

    $ekA = collect($this->ursachen->fuerObjekt($this->rootTeam, 'recipe', $a->id))->firstWhere('art', 'ek');
    $ekB = collect($this->ursachen->fuerObjekt($this->rootTeam, 'recipe', $b->id))->firstWhere('art', 'ek');

    expect($ekA['glieder'][0]['ursache'])->toBe('GP ohne Lieferantenartikel')
        ->and($ekA['glieder'][0]['fixbar'])->toBeFalse()
        ->and($ekB['glieder'][0]['ursache'])->toBe('Kein Lieferantenartikel mit gültigem Preis')
        ->and($ekB['glieder'][0]['fixbar'])->toBeFalse();
});

it('schweigt bei einem Rezept, dessen EK-Kette vollständig auflöst (kein Über-Erklären)', function () {
    $gp = $this->makeGp($this->rootTeam, 'Karotte');
    $la = ($this->mkLa)($gp, 2.00);
    $gp->update(['lead_la_supplier_item_id' => $la->id]);
    $r = ($this->mkRezept)('Fond: Karotte', $gp);

    expect($r->ek_total_eur)->not->toBeNull()
        ->and(collect($this->ursachen->fuerObjekt($this->rootTeam, 'recipe', $r->id))->firstWhere('art', 'ek'))->toBeNull();
});

it('nennt eine ungemappte Zutat als eigene Ursache', function () {
    $r = $this->makeRecipe($this->rootTeam, 'Fond: ungemappt');
    $this->makeIngredient($r, '500 g Irgendwas', null, '500');
    app(RecipeRecomputeService::class)->recomputeAndPropagate($r->id);

    $ek = collect($this->ursachen->fuerObjekt($this->rootTeam, 'recipe', $r->refresh()->id))->firstWhere('art', 'ek');

    // Ungemappte Zeilen stehen gar nicht in der Kaskade — sie tauchen als eigener Zähler auf.
    expect($ek)->not->toBeNull()->and($ek['ungemappt'])->toBe(1);
});

it('liefert für ein Verkaufsgericht das verletzte § mit Regeltext', function () {
    // [HG]-Präfix fehlt (Regelwerk_Verkaufsgerichte §1.1) UND Trenner am Rand.
    $vk = $this->makeRecipe($this->rootTeam, 'Rinderfilet mit Gratin -', ['is_sales_recipe' => true]);

    $block = collect($this->ursachen->fuerObjekt($this->rootTeam, 'recipe', $vk->id))->firstWhere('art', 'regelwerk');
    $faelle = array_column($block['glieder'], 'fall');

    expect($block)->not->toBeNull()
        ->and($faelle)->toContain('vk_praefix')
        ->and($faelle)->toContain('trenner_rand')
        ->and($block['glieder'][0]['paragraph'])->toContain('Regelwerk')
        ->and($block['glieder'][0]['regel'])->not->toBeEmpty();
});

it('verlinkt das Regelwerk nur, wenn das Wissens-Dokument existiert (kein toter Link)', function () {
    // Der Wissens-Browser wird im Test-Host nicht geroutet — hier nachgestellt, damit die
    // URL-Bildung geprüft werden kann (der Service selbst hat dafür einen Route-Guard).
    // Achtung: `Route::get(...)->name(...)` trägt den Namen NACH dem Hinzufügen nach, die
    // Namensliste der RouteCollection sieht ihn dann nicht — `Route::name(...)->get(...)`.
    \Illuminate\Support\Facades\Route::name('foodalchemist.knowledge.index')->get('/wissen-test', fn () => '');

    $vk = $this->makeRecipe($this->rootTeam, 'Rinderfilet ohne Praefix', ['is_sales_recipe' => true]);

    $ohne = collect($this->ursachen->fuerObjekt($this->rootTeam, 'recipe', $vk->id))->firstWhere('art', 'regelwerk');
    expect($ohne['glieder'][0]['url'])->toBeNull();

    \Illuminate\Support\Facades\DB::table('foodalchemist_knowledge_documents')->insert([
        'uuid' => (string) \Illuminate\Support\Str::uuid(), 'team_id' => $this->rootTeam->id,
        'slug' => 'regelwerk.regelwerk_verkaufsgerichte', 'title' => 'Regelwerk Verkaufsgerichte (VK)',
        'category' => 'regelwerk', 'content_md' => '# VK', 'content_hash' => hash('sha256', '# VK'),
        'version' => 1, 'char_count' => 4, 'active' => 1,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $mit = collect($this->ursachen->fuerObjekt($this->rootTeam, 'recipe', $vk->id))->firstWhere('art', 'regelwerk');
    expect($mit['glieder'][0]['url'])->toContain('doc=');
});

it('beantwortet die Frage auch direkt am GP', function () {
    $gp = $this->makeGp($this->rootTeam, 'Kalbsfond');
    ($this->mkLa)($gp, 12.00);

    $block = collect($this->ursachen->fuerObjekt($this->rootTeam, 'gp', $gp->id))->firstWhere('art', 'gp');

    expect($block)->not->toBeNull()
        ->and($block['kopf'])->toBe('Kein Lead-Lieferantenartikel gesetzt')
        ->and($block['glieder'][0]['fixbar'])->toBeTrue();
});

it('MCP signal_causes.GET: ohne auflösbares Team NO_TEAM, bei falschem kind VALIDATION_ERROR', function () {
    $tool = app(ToolRegistry::class)->get('foodalchemist.signal_causes.GET');

    // Kein Team im Kontext UND keins am User — sonst fällt die Basisklasse bewusst
    // auf currentTeamRelation zurück (gleiches Verhalten wie die UI).
    $teamlos = \Platform\Core\Models\User::forceCreate([
        'name' => 'Teamlos', 'email' => 'teamlos-ursachen@test.local',
        'password' => bcrypt('secret'), 'current_team_id' => null,
    ]);
    $ohneTeam = $tool->execute(['kind' => 'recipe', 'id' => 1], new ToolContext($teamlos, null));
    expect($ohneTeam->success)->toBeFalse()->and($ohneTeam->errorCode)->toBe('NO_TEAM');

    $falsch = $tool->execute(['kind' => 'konzept', 'id' => 1],
        new ToolContext($this->makeUser($this->rootTeam), $this->rootTeam));
    expect($falsch->success)->toBeFalse()->and($falsch->errorCode)->toBe('VALIDATION_ERROR');
});

it('MCP signal_causes.GET: liefert dieselbe Kette wie die UI', function () {
    $gp = $this->makeGp($this->rootTeam, 'Sahne');   // ohne LA ⇒ die Kette bricht sichtbar
    $r = ($this->mkRezept)('Fond: Sahne', $gp);

    $res = app(ToolRegistry::class)->get('foodalchemist.signal_causes.GET')
        ->execute(['kind' => 'recipe', 'id' => $r->id],
            new ToolContext($this->makeUser($this->rootTeam), $this->rootTeam));

    expect($res->success)->toBeTrue()
        ->and($res->data['anzahl'])->toBeGreaterThan(0)
        ->and(collect($res->data['ursachen'])->firstWhere('art', 'ek')['glieder'][0]['gp_name'])->toBe('Sahne');
});

// Hinweis: Die Ursachen-Kette wird seit dem Panel-Verschlanken (2026-08) nicht mehr im
// DetailPanel gerendert — sie bleibt als SignalCauseService + MCP-Tool `SignalCausesGetTool`
// erhalten und ist oben darüber getestet. Der frühere Panel-Render-Test entfällt darum.
