<?php

use Illuminate\Support\Facades\Queue;
use Platform\FoodAlchemist\Enums\MatchBand;
use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeIngredient;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplier;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem;
use Platform\FoodAlchemist\Models\FoodAlchemistVocabEinheit;
use Platform\FoodAlchemist\Services\IngredientMatchService;
use Platform\FoodAlchemist\Services\RecipeExtractService;
use Platform\FoodAlchemist\Services\RecipeOneShotService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Folge-Entscheidungen Dominique zum Rezeptimport (Kurator 28.10.): Eier, Gewürze mit Stück, Frucht-Püree §3, Produktnamen, Menge vor dem
 * §5-Default, TK als Zukauf-Signal — und das Überspringen der Basiskomponenten beim Minten.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam));
    config(['foodalchemist.semantic_search.enabled' => false]);
    $this->gp = fn (string $name, string $slug, ?string $zustand, string $wg, ?string $zusatz = null) => FoodAlchemistGp::create([
        'team_id' => $this->rootTeam->id, 'name' => $name, 'gp_key' => 'ie-' . bin2hex(random_bytes(4)) . '||', 'status' => 'approved', 'requires_la' => true,
        'commodity_group_code' => $wg, 'condition' => $zustand, 'main_ingredient_slug' => $slug, 'zusatz' => $zusatz]);
    $this->svc = app(IngredientMatchService::class);
    $this->m = fn (string $t, ?string $einheit = null) => $this->svc->matchIngredient($this->rootTeam, $t, null, 'gp_first', 'fresh_first', false, 'conventional', einheit: $einheit);
    $this->auto = fn (string $t, ?string $einheit = null) => IngredientMatchService::istAutomatischVerdrahtbar($r = ($this->m)($t, $einheit)) ? ($r['gp_id'] ?? null) : null;
});

it('(1) Eier: „Volleier“/„Vollei“ ohne Hinweis → Schalenei; Flüssigei nur mit „flüssig“; „flüssig“ ist bei Eiern keine Naturform', function () {
    $schale = ($this->gp)('Eier: frisch, Größe M', 'eier', 'frisch', '06');
    $fluessig = ($this->gp)('Volleier: frisch, flüssig', 'volleier', 'frisch', '06');
    ($this->gp)('Eigelb: frisch, flüssig', 'eigelb', 'frisch', '06');

    expect(($this->auto)('4 Stk. Volleier'))->toBe($schale->id)
        ->and(($this->auto)('Vollei'))->toBe($schale->id)
        ->and(($this->auto)('Volleier flüssig'))->toBe($fluessig->id)
        ->and(($this->auto)('Eigelb'))->toBeNull();                       // einziges Eigelb-GP ist flüssig → Review
});

it('(2) Gewürz mit Einheit Stück → ganze Form (Zimtstange), ohne ganze Form Review; ohne Stück bleibt gemahlen', function () {
    ($this->gp)('Zimt: gemahlen, trocken', 'zimt', 'trocken', '10');
    $stange = ($this->gp)('Zimt: Stange, trocken', 'zimt', 'trocken', '10');
    $gemahlen = FoodAlchemistGp::where('name', 'Zimt: gemahlen, trocken')->first();
    ($this->gp)('Muskat: gemahlen, trocken', 'muskat', 'trocken', '10');

    expect(($this->auto)('Zimt', 'Stück'))->toBe($stange->id)
        ->and(($this->auto)('0,5 Stk. Zimt'))->toBe($stange->id)
        ->and(($this->auto)('Zimt'))->toBe($gemahlen->id)
        ->and(($this->m)('Muskat', 'Stück')['status'])->toBe(MatchBand::FuzzyLow);   // keine ganze Muskatnuss im Bestand
});

it('(2) Import: die Einheit der Zeile erreicht den Abgleich — Zimt in Stück wird die Stange', function () {
    Queue::fake();
    FoodAlchemistVocabEinheit::create(['team_id' => $this->rootTeam->id, 'slug' => 'stk', 'display_de' => 'Stück', 'dimension' => 'count', 'default_in_g' => 1]);
    ($this->gp)('Zimt: gemahlen, trocken', 'zimt', 'trocken', '10');
    $stange = ($this->gp)('Zimt: Stange, trocken', 'zimt', 'trocken', '10');

    $res = app(RecipeExtractService::class)->legeAn($this->rootTeam, ['typ' => 'basisrezept', 'name' => 'Glühwein-Sud Test', 'preparation' => 'Ziehen lassen.',
        'zutaten' => [['text' => 'Zimt', 'quantity' => '2', 'unit' => 'Stück']]]);

    expect($res['recipe']->ingredients()->first()->gp_id)->toBe($stange->id);
});

it('(3) Frucht-Püree ist Zukauf (§3) — jede Frucht des Bestands, auch mit Fugen-n; Gemüse-Püree bleibt Basiskomponente; Püree-GP ohne Frischware-Review', function () {
    ($this->gp)('Johannisbeere: frisch', 'johannisbeere', 'frisch', '02');
    ($this->gp)('Birne: frisch', 'birne', 'frisch', '02');
    ($this->gp)('Erbse: frisch', 'erbse', 'frisch', '01');
    $birnenpueree = ($this->gp)('Birnenpüree: konserviert', 'birnenpueree', 'konserviert', '02');

    expect($this->svc->basiskomponenteOhneZukauf($this->rootTeam, 'Johannisbeerpüree'))->toBeFalse()
        ->and($this->svc->basiskomponenteOhneZukauf($this->rootTeam, 'Püree: Johannisbeere'))->toBeFalse()
        ->and($this->svc->basiskomponenteOhneZukauf($this->rootTeam, 'Birnenpüree'))->toBeFalse()
        ->and($this->svc->basiskomponenteOhneZukauf($this->rootTeam, 'Erbsenpüree'))->toBeTrue()
        ->and(($this->auto)('Birnenpüree'))->toBe($birnenpueree->id);
});

it('(4) Produktname aus dem Bestand (trockenes Fertigprodukt) ist Zukauf-Signal — „Pro Espuma“; „Shiso-Espuma“ bleibt Basiskomponente', function () {
    ($this->gp)('Pro Espuma: trocken, warm / (Creative Cuisine)', 'pro_espuma', 'trocken', '13', 'Creative Cuisine');
    ($this->gp)('Shiso-Espuma: TK / (Achenbach)', 'shiso_espuma', 'TK', '13', 'Achenbach');

    expect($this->svc->basiskomponenteOhneZukauf($this->rootTeam, '2 g Pro Espuma Warm'))->toBeFalse()
        ->and($this->svc->basiskomponenteOhneZukauf($this->rootTeam, 'Shiso-Espuma'))->toBeTrue();
});

it('(5) Menge/Einheit vor dem §5-Default abschneiden — „200 g Sahne“ wie „Sahne“', function () {
    $sahne30 = ($this->gp)('Sahne: konserviert, 30 % Fett', 'sahne', 'konserviert', '06');
    ($this->gp)('Sahne: konserviert, 15 % Fett', 'sahne', 'konserviert', '06');

    $sahne15 = FoodAlchemistGp::where('name', 'Sahne: konserviert, 15 % Fett')->first();
    expect(($this->auto)('Sahne'))->toBe($sahne30->id)
        ->and(($this->auto)('200 g Sahne'))->toBe($sahne30->id)
        // genannte Fettstufe schlägt den Default (Nachmessung: „100 ml Milch (1,5 %)“ ging auf 3,5 %)
        ->and(($this->m)('100 ml Sahne (15 %)')['gp_id'])->toBe($sahne15->id)
        // genannte Fettstufe, die es nicht gibt → nie automatisch eine andere (Regel a gilt dann nicht, auch beim einzigen Kandidaten)
        ->and(($this->auto)('100 ml Sahne (10 %)'))->toBeNull()
        // „1,5 %“: die Zahlteile sind zu kurz für das Wort-Scoring, der §5-Default träfe „Sahne“ — der genannte Wert verhindert das (Fall „Milch (1,5 %)“)
        ->and(($this->auto)('100 ml Sahne (1,5 %)'))->toBeNull();

    $kokos = ($this->gp)('Kokosmilch: konserviert, 17 % Fett', 'kokosmilch', 'konserviert', '11');
    expect(($this->auto)('Kokosmilch'))->toBe($kokos->id)                // einziger Kandidat, Fettstufe weich (Regel a)
        ->and(($this->auto)('Kokosmilch (5 %)'))->toBeNull();            // Text nennt 5 % → nicht die 17 %

    // Toleranz 0,2 (§7.4a Handelsklasse, Nachmessung „Schlagsahne 35 %“ ≈ GP „35,01 %“)
    $kondens = ($this->gp)('Kondensmilch: konserviert, 7,5 % Fett', 'kondensmilch', 'konserviert', '06');
    expect(($this->auto)('Kondensmilch 7,4 %'))->toBe($kondens->id)
        ->and(\Platform\FoodAlchemist\Support\ZutatFreitext::fremdeWertangaben('Sahne 35 %', 'Sahne: konserviert, 35,01 % Fett'))->toBeFalse()
        ->and(\Platform\FoodAlchemist\Support\ZutatFreitext::fremdeWertangaben('Sahne 30 %', 'Sahne: konserviert, 35,01 % Fett'))->toBeTrue();
});

it('(6) „TK“ ist Zukauf-Signal einer Basiskomponente — TK-Pizzateig → GP, Pizzateig ohne Signal → Stub', function () {
    $tk = ($this->gp)('Pizzateig: TK', 'pizzateig', 'TK', '09');

    $mitTk = ($this->m)('TK-Pizzateig');
    expect(IngredientMatchService::istAutomatischVerdrahtbar($mitTk))->toBeTrue()->and($mitTk['gp_id'])->toBe($tk->id)
        ->and(($this->m)('Pizzateig')['basiskomponente'] ?? null)->toBe('stub');
});

it('Anreicherung (minteFehlendeGps) mintet keine Basiskomponente ohne Zukauf-Signal — mit Convenience-Leitplanke schon', function () {
    $lieferant = FoodAlchemistSupplier::create(['team_id' => $this->rootTeam->id, 'name' => 'Necta']);
    FoodAlchemistSupplierItem::create(['team_id' => $this->rootTeam->id, 'supplier_id' => $lieferant->id, 'designation' => 'Geflügelfond', 'qty' => 1.0, 'unit_code' => 'kg']);
    $r = $this->makeRecipe($this->rootTeam, 'Geflügelrahmsauce Mint', ['status' => 'approved']);
    $zeile = FoodAlchemistRecipeIngredient::create(['team_id' => $this->rootTeam->id, 'recipe_id' => $r->id,
        'raw_text' => 'Geflügelfond', 'quantity' => '500', 'unit_vocab_id' => $this->unitG($this->rootTeam)->id, 'position' => 1]);

    $ohne = app(RecipeOneShotService::class)->minteFehlendeGps($this->rootTeam, $r->fresh());
    expect($zeile->fresh()->gp_id)->toBeNull()->and($ohne['basisrezept_luecken'])->toBe(1);

    $mit = app(RecipeOneShotService::class)->minteFehlendeGps($this->rootTeam, $r->fresh(), ['convenience' => 'voll_convenience']);
    expect($mit['basisrezept_luecken'] ?? 0)->toBe(0);
});
