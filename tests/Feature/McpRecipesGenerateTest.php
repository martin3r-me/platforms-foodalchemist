<?php

use Illuminate\Support\Facades\DB;
use Platform\Core\Contracts\LLMProviderContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Tools\ToolRegistry;
use Platform\FoodAlchemist\Models\FoodAlchemistPrice;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeIngredient;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplier;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItemStructure;
use Platform\FoodAlchemist\Models\FoodAlchemistVocabEinheit;
use Platform\FoodAlchemist\Services\Ai\FakeAiProvider;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * 03·L5: MCP `foodalchemist.recipes.GENERATE` — Lockstep-Schuld aus #505.
 *
 * Der Generator selbst ist in RecipeGeneratorTest/VkGeneratorTest abgedeckt;
 * hier wird die MCP-Fläche geprüft: Registry, Parameter-Durchreichung (vk als
 * Parameter statt zweitem Tool), Draft-Quarantäne + created_via=mcp, ehrliche
 * Lücken-Meldung, Tenancy (#504-Muster).
 *
 * Test-Grenze: der FakeAiProvider ist ein Kontext-Echo und kann strukturell
 * kein Rezept erfinden — deshalb hängt hier ein LLM-Stub am Core-Contract, der
 * ein festes Rezept-JSON liefert (Muster aus AiGatewayTest).
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->user = $this->makeUser($this->rootTeam);
    $this->actingAs($this->user);
    config(['foodalchemist.ai.provider' => 'fake', 'foodalchemist.ai.backoff' => []]);
    $this->registry = app(ToolRegistry::class);
    $this->kontext = new ToolContext($this->user, $this->rootTeam);

    foreach ([
        ['slug' => 'g', 'display_de' => 'Gramm', 'dimension' => 'mass', 'default_in_g' => 1],
        ['slug' => 'ml', 'display_de' => 'Milliliter', 'dimension' => 'volume', 'default_in_ml' => 1],
    ] as $e) {
        FoodAlchemistVocabEinheit::create(['team_id' => $this->rootTeam->id, ...$e]);
    }

    $supplier = FoodAlchemistSupplier::create(['team_id' => $this->rootTeam->id, 'name' => 'Necta']);
    $this->mkGpMitPreis = function (\Platform\Core\Models\Team $team, string $name, ?string $slug, float $preis) use ($supplier) {
        $gp = $this->makeGp($team, $name);
        $gp->update(['main_ingredient_slug' => $slug, 'status' => 'approved']);
        $la = FoodAlchemistSupplierItem::create([
            'team_id' => $team->id, 'supplier_id' => $supplier->id,
            'designation' => $name, 'qty' => 1.0, 'unit_code' => 'kg',
        ]);
        FoodAlchemistSupplierItemStructure::create(['team_id' => $team->id, 'supplier_item_id' => $la->id, 'gp_id' => $gp->id]);
        FoodAlchemistPrice::create(['team_id' => $team->id, 'supplier_item_id' => $la->id, 'price' => $preis, 'status' => '0']);
        $gp->update(['lead_la_supplier_item_id' => $la->id]);

        return $gp->refresh();
    };

    /** Bindet einen LLM-Stub, der IMMER dasselbe Rezept-JSON liefert. */
    $this->stubKi = function (array $werte) {
        config(['foodalchemist.ai.provider' => 'core']);
        app()->instance(LLMProviderContract::class, new class($werte) extends FakeAiProvider
        {
            public function __construct(private array $werte)
            {
            }

            public function chat(array $messages, array $options = []): array
            {
                return [
                    'content' => json_encode(['werte' => $this->werte, 'confidence' => 0.8, 'reasoning' => 'stub'], JSON_UNESCAPED_UNICODE),
                    'usage' => [], 'model' => 'stub-1', 'tool_calls' => null,
                ];
            }
        });
    };
});

it('L5: registriert als foodalchemist.recipes.GENERATE mit description-Pflichtfeld', function () {
    $tool = $this->registry->get('foodalchemist.recipes.GENERATE');

    expect($tool)->not->toBeNull()
        ->and($tool->getSchema()['type'])->toBe('object')
        ->and($tool->getSchema()['required'])->toBe(['description'])
        ->and($tool->getSchema()['properties'])->toHaveKeys(['vk', 'convenience', 'frische', 'diaet_hart', 'use_favorites_list'])
        ->and($tool->getMetadata()['risk_level'])->toBe('write')
        ->and($tool->getMetadata()['side_effects'])->toBe(['creates']);
});

it('L5 DoD: Draft-Quarantäne — Rezept entsteht als draft mit created_via=mcp, Bestands-GP wird gematcht', function () {
    $gp = ($this->mkGpMitPreis)($this->rootTeam, 'Schalotten: frisch, ganz', 'schalotten', 4.00);
    ($this->stubKi)([
        'name' => 'Reduktion: Rotwein-Schalotte',
        'description' => 'Sirupartige Saucenbasis.',
        'preparation' => '1. Schalotten anschwitzen.',
        'zutaten' => [
            ['text' => 'Schalotten', 'slug' => 'schalotten', 'quantity' => 300, 'unit' => 'g'],
        ],
    ]);

    $res = $this->registry->get('foodalchemist.recipes.GENERATE')
        ->execute(['description' => 'Dunkle Rotwein-Schalotten-Reduktion', 'convenience' => 'from_scratch'], $this->kontext);

    expect($res->success)->toBeTrue()
        ->and($res->data['recipe']['name'])->toBe('Reduktion: Rotwein-Schalotte')
        ->and($res->data['recipe']['status'])->toBe('draft')          // nie automatisch aktiv
        ->and($res->data['recipe']['created_via'])->toBe('mcp')        // Lineage (nicht "generator" wie UI-Pfad)
        ->and($res->data['recipe']['is_sales_recipe'])->toBeFalse()
        ->and($res->data['statistik']['bestand_gp'])->toBe(1)
        ->and($res->data['statistik']['offen'])->toBe(0)
        ->and($res->data['offene'])->toBe([]);

    $recipe = FoodAlchemistRecipe::find($res->data['recipe']['id']);
    $zeile = FoodAlchemistRecipeIngredient::where('recipe_id', $recipe->id)->first();
    expect($recipe->team_id)->toBe($this->rootTeam->id)
        ->and($recipe->preparation)->toBe('1. Schalotten anschwitzen.')
        ->and($zeile->gp_id)->toBe($gp->id);
    // Bewusst NICHT auf match_method geprueft: der Generator setzt 'gemini_proposed',
    // RecipeService::syncIngredients liest das Feld des Aufrufers aber nicht und
    // schreibt 'manual' (Lineage-Drift, als Bug gemeldet) — den Ist-Wert hier
    // festzuschreiben wuerde den Drift zementieren.
});

it('L5 DoD: vk=true ist ein PARAMETER, kein zweites Tool — dasselbe Tool liefert das VK-Gericht', function () {
    ($this->mkGpMitPreis)($this->rootTeam, 'Lachs: frisch, Filet', 'lachs', 24.00);
    ($this->stubKi)([
        'name' => 'VS: Lachs gebeizt',
        'zutaten' => [['text' => 'Lachs', 'slug' => 'lachs', 'quantity' => 120, 'unit' => 'g']],
    ]);

    $basis = $this->registry->get('foodalchemist.recipes.GENERATE')
        ->execute(['description' => 'Gebeizter Lachs'], $this->kontext);
    $vk = $this->registry->get('foodalchemist.recipes.GENERATE')
        ->execute(['description' => 'Gebeizter Lachs für einen Empfang', 'vk' => true, 'occasion' => 'empfang', 'diaet_hart' => ['glutenfrei']], $this->kontext);

    expect($basis->data['recipe']['is_sales_recipe'])->toBeFalse()
        ->and($vk->success)->toBeTrue()
        ->and($vk->data['recipe']['is_sales_recipe'])->toBeTrue()
        ->and($vk->data['recipe']['status'])->toBe('draft')
        ->and($vk->data['recipe']['created_via'])->toBe('mcp')
        ->and($vk->data['recipe']['id'])->not->toBe($basis->data['recipe']['id']);
});

it('L5: Zutat ohne Treffer wird NICHT geraten — kommt als offene Zeile mit Handlungsempfehlung zurück', function () {
    ($this->stubKi)([
        'name' => 'Salat: Sanddorn-Vinaigrette',
        'zutaten' => [['text' => 'Sanddorn-Direktsaft', 'slug' => 'sanddornsaft', 'quantity' => 50, 'unit' => 'ml']],
    ]);

    $res = $this->registry->get('foodalchemist.recipes.GENERATE')
        ->execute(['description' => 'Vinaigrette mit Sanddorn'], $this->kontext);

    expect($res->success)->toBeTrue()
        ->and($res->data['statistik']['offen'])->toBe(1)
        ->and($res->data['offene'][0]['text'])->toBe('Sanddorn-Direktsaft')
        ->and($res->data['offene'][0]['primaer'])->toBeIn(['lieferantenartikel_waehlen', 'basisrezept_anlegen'])
        ->and($res->data['hinweis'])->toContain('Lieferantenartikel')
        ->and(FoodAlchemistRecipeIngredient::where('recipe_id', $res->data['recipe']['id'])->first()->match_method->value)->toBe('unmatched');   // Lücke bleibt Lücke
});

it('L8b-2: ziel_vk ausserhalb des Bandes wird abgewiesen — kein Lauf gegen eine Fantasie-Vorgabe', function () {
    foreach ([0.2, 900.0, 'acht'] as $unsinn) {
        $res = $this->registry->get('foodalchemist.recipes.GENERATE')
            ->execute(['description' => 'Teller', 'vk' => true, 'ziel_vk' => $unsinn], $this->kontext);

        expect($res->success)->toBeFalse()
            ->and($res->errorCode)->toBe('VALIDATION_ERROR');
    }
});

it('L8b-2: ziel_vk ohne vk=true ist ein Fehler, keine stille Verwerfung', function () {
    // Ein Basisrezept hat keinen Verkaufspreis. Würde die Vorgabe hier nur ignoriert,
    // hielte der Client das Ergebnis für eine Antwort auf seinen Zielpreis.
    $res = $this->registry->get('foodalchemist.recipes.GENERATE')
        ->execute(['description' => 'Kalbsfond', 'ziel_vk' => 8.5], $this->kontext);

    expect($res->success)->toBeFalse()
        ->and($res->errorCode)->toBe('VALIDATION_ERROR')
        ->and($res->error)->toContain('vk=true');
});

it('L5: leere description wird sauber abgewiesen (kein LLM-Call)', function () {
    $res = $this->registry->get('foodalchemist.recipes.GENERATE')->execute(['description' => '   '], $this->kontext);

    expect($res->success)->toBeFalse()
        ->and($res->errorCode)->toBe('VALIDATION_ERROR');
});

it('L5: unbrauchbare KI-Antwort reißt nicht durch — typisierter Tool-Fehler statt Exception', function () {
    ($this->stubKi)(['name' => 'Ohne Zutaten']);      // strukturell unbrauchbar (name ohne zutaten)

    $res = $this->registry->get('foodalchemist.recipes.GENERATE')
        ->execute(['description' => 'Irgendwas'], $this->kontext);

    expect($res->success)->toBeFalse()
        ->and($res->errorCode)->toBe('VALIDATION_ERROR')
        ->and(FoodAlchemistRecipe::count())->toBe(0);   // keine Rumpf-Anlage (Transaktion)
});

it('#504-Muster: Tenancy — Rezept landet im Kontext-Team, Fremd-Team-GP wird NICHT gematcht', function () {
    // childA ist NICHT Vorfahr von childB → dessen GP für childB unsichtbar.
    ($this->mkGpMitPreis)($this->childA, 'Wacholder: getrocknet, ganz', 'wacholder', 30.00);
    ($this->stubKi)([
        'name' => 'Marinade: Nordisch',
        'zutaten' => [['text' => 'Wacholderbeeren', 'slug' => 'wacholder', 'quantity' => 10, 'unit' => 'g']],
    ]);

    $res = $this->registry->get('foodalchemist.recipes.GENERATE')
        ->execute(['description' => 'Nordische Marinade'], new ToolContext($this->makeUser($this->childB, 'Kind B User'), $this->childB));

    expect($res->success)->toBeTrue()
        ->and($res->data['statistik']['bestand_gp'])->toBe(0)          // kein Leak über die Team-Grenze
        ->and($res->data['statistik']['offen'])->toBe(1)
        ->and(FoodAlchemistRecipe::find($res->data['recipe']['id'])->team_id)->toBe($this->childB->id);
});

it('#504-Muster: ohne Team im Kontext kein Schreibzugriff', function () {
    $ohneTeam = \Platform\Core\Models\User::forceCreate([
        'name' => 'Teamlos', 'email' => 'teamlos@test.local', 'password' => bcrypt('secret'), 'current_team_id' => null,
    ]);

    $res = $this->registry->get('foodalchemist.recipes.GENERATE')
        ->execute(['description' => 'Egal'], new ToolContext($ohneTeam, null));

    expect($res->success)->toBeFalse()
        ->and($res->errorCode)->toBe('NO_TEAM');
});

it('L7a: voll_anreichern ist ein Parameter — Default aus lässt den Aufruf unverändert', function () {
    ($this->mkGpMitPreis)($this->rootTeam, 'Schalotten: frisch, ganz', 'schalotten', 4.00);
    ($this->stubKi)([
        'name' => 'Reduktion: Rotwein-Schalotte',
        'description' => 'Sirupartige Saucenbasis.',
        'zutaten' => [['text' => 'Schalotten', 'slug' => 'schalotten', 'quantity' => 300, 'unit' => 'g']],
    ]);
    $tool = $this->registry->get('foodalchemist.recipes.GENERATE');

    expect($tool->getSchema()['properties'])->toHaveKey('voll_anreichern')
        ->and($tool->getSchema()['properties']['voll_anreichern']['default'])->toBeFalse();

    $res = $tool->execute(['description' => 'Dunkle Rotwein-Schalotten-Reduktion'], $this->kontext);

    expect($res->success)->toBeTrue()
        ->and($res->data['anreicherung'])->toBeNull()                   // ohne Flag kein Pass
        ->and(DB::table('foodalchemist_bulk_runs')->count())->toBe(0);
});

it('L7a: voll_anreichern=true hängt die Kaskade an — die Lücken sind nach dem EINEN Aufruf gefüllt', function () {
    ($this->mkGpMitPreis)($this->rootTeam, 'Schalotten: frisch, ganz', 'schalotten', 4.00);
    $hg = \Platform\FoodAlchemist\Models\FoodAlchemistRecipeMainGroup::create([
        'team_id' => $this->rootTeam->id, 'code' => 'FND', 'label' => 'Fonds & Saucen',
    ]);
    $kat = \Platform\FoodAlchemist\Models\FoodAlchemistRecipeCategory::create([
        'team_id' => $this->rootTeam->id, 'main_group_id' => $hg->id, 'code' => 'RED', 'label' => 'Reduktionen',
    ]);
    // Derselbe Stub bedient beide Stufen: der Generator liest name/zutaten/description,
    // die Anreicherungs-Schritte lesen category_id/taste_direction aus demselben Objekt.
    ($this->stubKi)([
        'name' => 'Reduktion: Rotwein-Schalotte',
        'description' => 'Sirupartige Saucenbasis.',
        'zutaten' => [['text' => 'Schalotten', 'slug' => 'schalotten', 'quantity' => 300, 'unit' => 'g']],
        'category_id' => $kat->id,
        'taste_direction' => 'herzhaft',
    ]);

    $res = $this->registry->get('foodalchemist.recipes.GENERATE')
        ->execute(['description' => 'Dunkle Rotwein-Schalotten-Reduktion', 'voll_anreichern' => true], $this->kontext);

    // Nur `category` bleibt als Lücke: description UND taste_direction setzt der
    // Generator schon selbst (das Enum passt), und die Kaskade zahlt für ein
    // gefülltes Feld keinen zweiten Call — genau die L7a-Regel.
    expect($res->success)->toBeTrue()
        ->and($res->data['anreicherung']['schritte'])->toBe(['category'])
        ->and($res->data['anreicherung']['uebersprungen'])->toBe(['description', 'geschmack'])
        ->and($res->data['anreicherung']['uebernommen'])->toBe(1);

    $r = FoodAlchemistRecipe::find($res->data['recipe']['id']);
    expect((int) $r->category_id)->toBe((int) $kat->id)
        ->and($r->category_source)->toBe('ki')
        ->and($r->taste_direction)->toBe('herzhaft')
        ->and($r->status->value)->toBe('draft')                          // One-Shot ≠ Freigabe
        ->and($r->created_via)->toBe('mcp');
});
