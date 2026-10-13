<?php

use Illuminate\Support\Facades\DB;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplier;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItemStructure;
use Platform\FoodAlchemist\Services\AboService;
use Platform\FoodAlchemist\Services\FassungService;
use Platform\FoodAlchemist\Services\InhaltsFreigabeService;
use Platform\FoodAlchemist\Services\KostenCacheService;
use Platform\FoodAlchemist\Services\LeadLaService;
use Platform\FoodAlchemist\Services\PriceService;
use Platform\FoodAlchemist\Services\RecipeRecomputeService;
use Platform\FoodAlchemist\Support\StammPaket;
use Platform\FoodAlchemist\Support\TeamAncestryRegistry;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 82 §12 C / Stufe 3b · Durchstich Schritt 3: Kosten je (Rezept-Fassung × Team).
 *
 * Abnahme §12 C3: derselbe Jus kostet bei Broich anders als bei Soulkitchen; ändert Broich einen Einkaufspreis, wird nur
 * Broich neu gerechnet. Dazu: Unter-Rezepte mit den Preisen DESSELBEN Teams, „unvollständig" (Variante 2), Besitzer
 * bitgleich zur Rezept-Spalte, Master-Fassung ohne Master-Spalte.
 *
 * Mengen: Fond 1000 g Knochen (Ansatz → Yield laut Recompute), Jus 500 g Fond + 100 g Butter.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->master = Team::create(['name' => 'BHG.DIGITAL (Master)', 'user_id' => 1, 'personal_team' => false]);
    config(['foodalchemist.master_team_id' => $this->master->id]);
    $this->broich = Team::create(['name' => 'Broich', 'user_id' => 1, 'personal_team' => false]);
    $this->kita = Team::create(['name' => 'Broich Kita', 'user_id' => 1, 'personal_team' => false, 'parent_team_id' => $this->broich->id]);
    $this->soul = Team::create(['name' => 'Soulkitchen', 'user_id' => 1, 'personal_team' => false]);
    TeamAncestryRegistry::flushAll();
    StammPaket::freischalten($this->broich);
    StammPaket::freischalten($this->soul);
    $this->kurator = $this->makeUser($this->master, 'Kurator', 'owner');
    $this->preise = app(PriceService::class);
    $this->cache = app(KostenCacheService::class);

    // globaler Stamm OHNE Preise (Entscheidung 13.10.): GP + Artikel, Preise nur von den Mandanten
    $sup = FoodAlchemistSupplier::create(['team_id' => null, 'name' => 'Stamm-Großhandel']);
    $this->mkGp = function (string $name) use ($sup) {
        $gp = FoodAlchemistGp::create(['team_id' => null, 'gp_key' => 'k82|' . $name, 'name' => $name]);
        $la = FoodAlchemistSupplierItem::create(['team_id' => null, 'supplier_id' => $sup->id, 'designation' => $name . ' 1 kg', 'qty' => 1.0, 'unit_code' => 'kg']);
        FoodAlchemistSupplierItemStructure::create(['team_id' => null, 'supplier_item_id' => $la->id, 'gp_id' => $gp->id]);
        $gp->update(['lead_la_supplier_item_id' => $la->id]);

        return [$gp->refresh(), $la];
    };
    [$this->knochen, $this->laKnochen] = ($this->mkGp)('Kalbsknochen');
    [$this->butter, $this->laButter] = ($this->mkGp)('Butter');

    $this->fond = $this->makeRecipe($this->master, 'Kalbsfond');
    $this->makeIngredient($this->fond, 'Kalbsknochen', $this->knochen, '1000');
    $this->jus = $this->makeRecipe($this->master, 'Kalbsjus');
    $this->makeIngredient($this->jus, 'Kalbsfond', null, '500')->update(['referenced_recipe_id' => $this->fond->id]);
    $this->makeIngredient($this->jus, 'Butter', $this->butter, '100', 2);
    $rc = app(RecipeRecomputeService::class);
    $rc->recomputePipeline($this->fond->id);
    $rc->recomputePipeline($this->jus->id);

    $inhalte = app(InhaltsFreigabeService::class);
    $sid = $inhalte->sammlungAnlegen($this->master, 'Basis', null, $this->kurator);
    $inhalte->sammlungHinzu($this->master, $sid, 'recipe', [$this->jus->id], $this->kurator);
    app(AboService::class)->abonnieren($this->master, $this->broich, $sid, $this->kurator);
    app(AboService::class)->abonnieren($this->master, $this->soul, $sid, $this->kurator);
    $this->jusF = FoodAlchemistRecipe::mitFassungen()->findOrFail(app(FassungService::class)->veroeffentlichen($this->master, $this->jus->id, 'Erst', $this->kurator)['fassung_id']);
    $this->fondF = FoodAlchemistRecipe::mitFassungen()->where('fassung_von_id', $this->fond->id)->sole();

    // Teampreise: Broich günstiger als Soulkitchen
    $this->preise->createTeamPrice($this->broich, $this->laKnochen, 4.00);
    $this->preise->createTeamPrice($this->broich, $this->laButter, 10.00);
    $this->preise->createTeamPrice($this->soul, $this->laKnochen, 5.00);
    $this->preise->createTeamPrice($this->soul, $this->laButter, 12.00);

    // Erwartung aus derselben Engine je Team (Kontrolle unabhängig vom Cache-Speichern)
    $this->erwartet = function (Team $t) {
        $memo = [];

        return app(RecipeRecomputeService::class)->kostenFuerTeam($this->jusF->id, $t, $memo);
    };
});

it('§12 C3 · derselbe Abo-Jus kostet bei Broich und Soulkitchen verschieden, je mit eigenen Preisen', function () {
    $b = $this->cache->fuer($this->jusF, $this->broich);
    $s = $this->cache->fuer($this->jusF, $this->soul);

    expect($b['quelle'])->toBe('team_cache')
        ->and($b['vollstaendig'])->toBeTrue()->and($s['vollstaendig'])->toBeTrue()
        ->and($b['ek_summe'])->toBeGreaterThan(0.0)
        ->and($b['ek_summe'])->toBeLessThan($s['ek_summe'])
        ->and($b['ek_summe'])->toBe(($this->erwartet)($this->broich)['ek_summe'])
        ->and($s['ek_summe'])->toBe(($this->erwartet)($this->soul)['ek_summe'])
        // die Fassung selbst trägt weiter keine Kosten (Leck-Sperre)
        ->and($this->jusF->fresh()?->ek_total_eur ?? FoodAlchemistRecipe::mitFassungen()->find($this->jusF->id)->ek_total_eur)->toBeNull();

    // Unter-Rezept mit den Preisen DESSELBEN Teams: Fond-Anteil am Jus folgt dem Knochenpreis des Teams
    $fondB = $this->cache->fuer($this->fondF, $this->broich);
    $fondS = $this->cache->fuer($this->fondF, $this->soul);
    expect($fondB['ek_summe'])->toBe(4.00)->and($fondS['ek_summe'])->toBe(5.00);
});

it('§12 C3 · Broich ändert einen Einkaufspreis → nur Broichs Kosten werden neu gerechnet, Soulkitchen bleibt', function () {
    $vorherB = $this->cache->fuer($this->jusF, $this->broich);
    $vorherS = $this->cache->fuer($this->jusF, $this->soul);
    $stempelS = DB::table('foodalchemist_recipe_team_kosten')->where('recipe_id', $this->jusF->id)->where('team_id', $this->soul->id)->value('berechnet_at');

    $this->preise->createTeamPrice($this->broich, $this->laButter, 20.00);   // Auslöser
    expect((bool) DB::table('foodalchemist_recipe_team_kosten')->where('recipe_id', $this->jusF->id)->where('team_id', $this->broich->id)->value('veraltet'))->toBeTrue()
        ->and((bool) DB::table('foodalchemist_recipe_team_kosten')->where('recipe_id', $this->jusF->id)->where('team_id', $this->soul->id)->value('veraltet'))->toBeFalse()
        // auch das Unter-Rezept-Elter (Jus) ist über die Butter direkt betroffen, der Fond nicht
        ->and((bool) DB::table('foodalchemist_recipe_team_kosten')->where('recipe_id', $this->fondF->id)->where('team_id', $this->broich->id)->value('veraltet'))->toBeFalse();

    $nachB = $this->cache->fuer($this->jusF, $this->broich);
    $nachS = $this->cache->fuer($this->jusF, $this->soul);
    expect($nachB['ek_summe'])->toBeGreaterThan($vorherB['ek_summe'])
        ->and($nachS['ek_summe'])->toBe($vorherS['ek_summe'])
        ->and(DB::table('foodalchemist_recipe_team_kosten')->where('recipe_id', $this->jusF->id)->where('team_id', $this->soul->id)->value('berechnet_at'))->toBe($stempelS);
});

it('Unter-Team ohne eigene Preise erbt die Preise des Oberteams (3a) und rechnet sie über den Cache', function () {
    expect($this->cache->fuer($this->jusF, $this->kita)['ek_summe'])->toBe($this->cache->fuer($this->jusF, $this->broich)['ek_summe']);

    // Preisänderung im Oberteam macht auch die Kita veraltet (Unter-Teams erben)
    $this->preise->createTeamPrice($this->broich, $this->laKnochen, 6.00);
    expect((bool) DB::table('foodalchemist_recipe_team_kosten')->where('recipe_id', $this->jusF->id)->where('team_id', $this->kita->id)->value('veraltet'))->toBeTrue();
});

it('Kosten unvollständig (Variante 2): fehlender Teampreis = Teilsumme, Anteil nach Anzahl und Masse, Pfad über das Unter-Rezept', function () {
    $neu = Team::create(['name' => 'Neukunde', 'user_id' => 1, 'personal_team' => false]);
    TeamAncestryRegistry::flushAll();
    StammPaket::freischalten($neu);
    $this->preise->createTeamPrice($neu, $this->laButter, 10.00);   // Knochen ohne Preis

    $k = $this->cache->fuer($this->jusF, $neu);
    expect($k['vollstaendig'])->toBeFalse()
        ->and($k['grund'])->toBe('unterrezept_unvollstaendig')
        ->and($k['ek_summe'])->toBe(1.00)                                // nur Butter: 100 g × 10 €/kg — TEILSUMME
        ->and($k['n_priced'])->toBe(1)->and($k['n_total'])->toBe(2)
        ->and($k['anteil_fehlend_anzahl'])->toBe(0.5)
        ->and($k['anteil_fehlend_masse'])->toBeGreaterThan(0.8)          // 500 g Fond von 600 g Ansatz fehlen
        ->and($k['fehlende'][0]['pfad'])->toBe('Kalbsjus › Kalbsfond › Kalbsknochen')
        ->and($k['fehlende'][0]['gp_id'])->toBe($this->knochen->id);

    // ohne einen einzigen Preis: keine Summe, nie 0 € als Preis
    $leer = Team::create(['name' => 'Ohne Preise', 'user_id' => 1, 'personal_team' => false]);
    TeamAncestryRegistry::flushAll();
    $l = $this->cache->fuer($this->jusF, $leer);
    expect($l['ek_summe'])->toBeNull()->and($l['vollstaendig'])->toBeFalse();
});

it('Besitzer einer Arbeitszeile liest die Rezept-Spalten (bitgleich), Fassungen nie — auch der Master nicht', function () {
    $owner = $this->cache->fuer($this->jus, $this->master);
    expect($owner['quelle'])->toBe('rezept')
        ->and($owner['ek_summe'])->toBe($this->jus->fresh()->ek_total_eur !== null ? (float) $this->jus->fresh()->ek_total_eur : null);
    expect($this->cache->fuer($this->jusF, $this->master)['quelle'])->toBe('team_cache');
});

it('Auslöser Pin: ein Team pinnt einen anderen Butter-Artikel → nur dieses Team wird veraltet und rechnet mit dem Pin', function () {
    $sup2 = FoodAlchemistSupplier::create(['team_id' => null, 'name' => 'Zweitlieferant']);
    $la2 = FoodAlchemistSupplierItem::create(['team_id' => null, 'supplier_id' => $sup2->id, 'designation' => 'Butter Premium 1 kg', 'qty' => 1.0, 'unit_code' => 'kg']);
    FoodAlchemistSupplierItemStructure::create(['team_id' => null, 'supplier_item_id' => $la2->id, 'gp_id' => $this->butter->id]);
    $this->preise->createTeamPrice($this->broich, $la2, 30.00);

    $vorher = $this->cache->fuer($this->jusF, $this->broich)['ek_summe'];
    $this->cache->fuer($this->jusF, $this->soul);
    app(LeadLaService::class)->pinnen($this->broich, $this->butter, $la2->id);

    expect((bool) DB::table('foodalchemist_recipe_team_kosten')->where('recipe_id', $this->jusF->id)->where('team_id', $this->soul->id)->value('veraltet'))->toBeFalse()
        ->and($this->cache->fuer($this->jusF, $this->broich)['ek_summe'])->toBeGreaterThan($vorher);
});
