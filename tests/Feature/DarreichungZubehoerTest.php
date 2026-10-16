<?php

use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Livewire\Verkauf\VkModal;
use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Models\FoodAlchemistPrice;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeDarreichung;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeDarreichungZubehoer;
use Platform\FoodAlchemist\Models\FoodAlchemistServierform;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplier;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItemStructure;
use Platform\FoodAlchemist\Services\AboKuendigungService;
use Platform\FoodAlchemist\Services\AboService;
use Platform\FoodAlchemist\Services\DarreichungService;
use Platform\FoodAlchemist\Services\FassungService;
use Platform\FoodAlchemist\Services\InhaltsFreigabeService;
use Platform\FoodAlchemist\Services\KostenCacheService;
use Platform\FoodAlchemist\Services\PaketFassungService;
use Platform\FoodAlchemist\Services\PriceService;
use Platform\FoodAlchemist\Services\TeamVkService;
use Platform\FoodAlchemist\Support\StammPaket;
use Platform\FoodAlchemist\Support\TeamAncestryRegistry;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;
use Platform\FoodAlchemist\Tools\RecipeDarreichungZubehoerPutTool;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Non-Food PR 2b: Zubehör & Verpackung an der DARREICHUNG (Schraubglas zur Form „im Schraubglas“). Nur Non-Food-GPs (WG 16), Menge je Einheit
 * der Darreichung, wirkt NUR auf den EK der Darreichung — nie auf Allergene, Nährwerte, Profil oder Yield des Kernrezepts. Kopien in Fassung,
 * Paket-Fassung, Kündigung und Team-Kalkulation. Gegenprobe jeweils: ohne Zubehör ändert sich nichts.
 */
function zubehoerGp(Team $team, string $name, string $wg, ?float $gebindePreis, float $stkJeGebinde = 12.0): FoodAlchemistGp
{
    $gp = FoodAlchemistGp::create(['team_id' => $team->id, 'gp_key' => 'zub|'.mb_strtolower($name), 'name' => $name, 'status' => 'approved', 'commodity_group_code' => $wg]);
    $sup = FoodAlchemistSupplier::firstOrCreate(['team_id' => $team->id, 'name' => 'Verpackungs-Großhandel']);
    $la = FoodAlchemistSupplierItem::create(['team_id' => $team->id, 'supplier_id' => $sup->id, 'designation' => $name, 'qty' => $stkJeGebinde, 'unit_code' => 'Stk']);
    FoodAlchemistSupplierItemStructure::create(['team_id' => $team->id, 'supplier_item_id' => $la->id, 'gp_id' => $gp->id]);
    if ($gebindePreis !== null) {
        FoodAlchemistPrice::create(['team_id' => $team->id, 'supplier_item_id' => $la->id, 'price' => $gebindePreis, 'status' => '0']);
    }
    $gp->update(['lead_la_supplier_item_id' => $la->id]);

    return $gp->refresh();
}

beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->user = $this->makeUser($this->rootTeam, 'Einkauf');
    $this->actingAs($this->user);
    $this->svc = app(DarreichungService::class);
    FoodAlchemistServierform::firstOrCreate(['code' => 'unbestimmt', 'team_id' => $this->rootTeam->id], ['label' => 'Unbestimmt']);

    // Gericht: 10 €/kg, Darreichung 200 g je Einheit ⇒ Lebensmittel-EK 2,00 €
    $this->gericht = FoodAlchemistRecipe::create(['team_id' => $this->rootTeam->id, 'recipe_key' => 'zub-salat', 'name' => 'Salat im Schraubglas', 'status' => 'approved',
        'is_sales_recipe' => true, 'ek_per_kg_eur' => 10.0, 'yield_kg' => 1.0, 'allergens_confidence' => 'high', 'allergen_milk' => 'enthalten', 'energy_kcal_100g' => 120]);
    $this->dar = $this->svc->ensureStandard($this->rootTeam, $this->gericht->id);
    $this->dar->update(['quantity_per_unit_g' => 200, 'unit_count' => 1]);
    $this->svc->recomputePreise($this->dar->fresh());
    $this->glas = zubehoerGp($this->rootTeam, 'Schraubglas: Glas, 212 ml', '16', 6.00);   // 6,00 € je 12 Stk = 0,50 €/Stk
    $this->ek = fn () => (float) $this->dar->fresh()->ek_portion;

    $this->zubehoerAboWelt = function () {
        $this->master = Team::create(['name' => 'BHG.DIGITAL (Master)', 'user_id' => 1, 'personal_team' => false]);
        config(['foodalchemist.master_team_id' => $this->master->id]);
        $this->broich = Team::create(['name' => 'Broich', 'user_id' => 1, 'personal_team' => false]);
        TeamAncestryRegistry::flushAll();
        StammPaket::freischalten($this->broich);
        $this->kurator = $this->makeUser($this->master, 'Kurator', 'owner');
        $this->actingAs($this->kurator);
    
        $sup = FoodAlchemistSupplier::create(['team_id' => null, 'name' => 'Stamm-Großhandel']);
        $knochen = FoodAlchemistGp::create(['team_id' => null, 'gp_key' => 'zub|knochen', 'name' => 'Kalbsknochen']);
        $laK = FoodAlchemistSupplierItem::create(['team_id' => null, 'supplier_id' => $sup->id, 'designation' => 'Kalbsknochen 1 kg', 'qty' => 1.0, 'unit_code' => 'kg']);
        FoodAlchemistSupplierItemStructure::create(['team_id' => null, 'supplier_item_id' => $laK->id, 'gp_id' => $knochen->id]);
        $knochen->update(['lead_la_supplier_item_id' => $laK->id]);
        $glas = FoodAlchemistGp::create(['team_id' => null, 'gp_key' => 'zub|glas', 'name' => 'Schraubglas', 'commodity_group_code' => '16', 'status' => 'approved']);
        $laG = FoodAlchemistSupplierItem::create(['team_id' => null, 'supplier_id' => $sup->id, 'designation' => 'Schraubglas', 'qty' => 12.0, 'unit_code' => 'Stk']);
        FoodAlchemistSupplierItemStructure::create(['team_id' => null, 'supplier_item_id' => $laG->id, 'gp_id' => $glas->id]);
        $glas->update(['lead_la_supplier_item_id' => $laG->id]);
        $this->glasStamm = $glas;
        $this->laKnochen = $laK;
    
        $form = FoodAlchemistServierform::create(['team_id' => $this->master->id, 'code' => 'glas', 'label' => 'Im Glas']);
        $this->gerichtM = $this->makeRecipe($this->master, 'Kalbsjus im Glas', ['is_sales_recipe' => true]);
        $this->makeIngredient($this->gerichtM, 'Kalbsknochen', $knochen, '1000');
        app(\Platform\FoodAlchemist\Services\RecipeRecomputeService::class)->recomputePipeline($this->gerichtM->id);
        $this->darM = app(DarreichungService::class)->anlegen($this->master, $this->gerichtM->id, $form->id, ['quantity_per_unit_g' => 200]);
        $this->darM->forceFill(['is_standard' => true])->save();
    
        $p = app(PriceService::class);
        $p->createTeamPrice($this->broich, $laK, 4.00);
        $p->createTeamPrice($this->broich, $laG, 6.00);   // Broich zahlt 0,50 €/Stk
    
        $inhalte = app(InhaltsFreigabeService::class);
        $sid = $inhalte->sammlungAnlegen($this->master, 'Gerichte', null, $this->kurator);
        $inhalte->sammlungHinzu($this->master, $sid, 'recipe', [$this->gerichtM->id], $this->kurator);
        app(AboService::class)->abonnieren($this->master, $this->broich, $sid, $this->kurator);
        $this->sammlungId = $sid;
    };
});

it('Zubehör: nur Non-Food-GPs, Menge größer 0; ein Lebensmittel-GP gehört in die Zutaten', function () {
    $butter = zubehoerGp($this->rootTeam, 'Butter: frisch', '06', 12.0);

    expect(fn () => $this->svc->setzeZubehoer($this->rootTeam, $this->dar->id, $butter->id, 1))->toThrow(\RuntimeException::class, 'Non-Food');
    expect(fn () => $this->svc->setzeZubehoer($this->rootTeam, $this->dar->id, $this->glas->id, 0))->toThrow(\RuntimeException::class, 'Menge');
    expect(FoodAlchemistRecipeDarreichungZubehoer::count())->toBe(0);

    $z = $this->svc->setzeZubehoer($this->rootTeam, $this->dar->id, $this->glas->id, 1);
    expect($z->gp_id)->toBe($this->glas->id)->and($z->quantity)->toBe(1.0)->and($z->position)->toBe(1);
});

it('EK: Zubehör × Menge je Einheit × Anzahl kommt auf ek_portion, Entfernen nimmt es zurück', function () {
    expect(($this->ek)())->toBe(2.0);

    $this->svc->setzeZubehoer($this->rootTeam, $this->dar->id, $this->glas->id, 1);
    expect(($this->ek)())->toBe(2.5);

    $this->svc->setzeZubehoer($this->rootTeam, $this->dar->id, $this->glas->id, 2);   // ändert dieselbe Zeile, kein zweites Zubehör
    expect(($this->ek)())->toBe(3.0)->and(FoodAlchemistRecipeDarreichungZubehoer::count())->toBe(1);

    $this->dar->update(['unit_count' => 2]);                                          // zwei Einheiten: Lebensmittel 4,00 + 2 × (2 × 0,50)
    $this->svc->recomputePreise($this->dar->fresh());
    expect(($this->ek)())->toBe(6.0);

    $this->svc->entferneZubehoer($this->rootTeam, $this->dar->id, $this->glas->id);
    expect(($this->ek)())->toBe(4.0)->and(FoodAlchemistRecipeDarreichungZubehoer::count())->toBe(0);
});

it('Zubehör wirkt NUR auf den EK: Allergene, Nährwerte, Yield und Kern-EK des Rezepts bleiben unberührt', function () {
    $vorher = $this->gericht->fresh()->only(['yield_kg', 'allergen_milk', 'allergens_confidence', 'energy_kcal_100g', 'ek_per_kg_eur', 'ek_total_eur']);

    $this->svc->setzeZubehoer($this->rootTeam, $this->dar->id, $this->glas->id, 3);

    expect($this->gericht->fresh()->only(['yield_kg', 'allergen_milk', 'allergens_confidence', 'energy_kcal_100g', 'ek_per_kg_eur', 'ek_total_eur']))->toEqual($vorher)
        ->and(($this->ek)())->toBe(3.5);
});

it('0 € ist kein Preis: ohne Lebensmittel-EK bleibt ek_portion leer, ein Zubehör ohne Preis fehlt in der Summe und wird ausgewiesen', function () {
    $ohnePreis = zubehoerGp($this->rootTeam, 'Deko-Pick', '16', null);
    $this->svc->setzeZubehoer($this->rootTeam, $this->dar->id, $ohnePreis->id, 1);

    $k = $this->svc->zubehoerKosten($this->dar->fresh(), $this->rootTeam);
    expect($k['vollstaendig'])->toBeFalse()->and($k['summe'])->toBe(0.0)->and($k['zeilen'][0]['kosten'])->toBeNull()->and(($this->ek)())->toBe(2.0);   // Zubehör ohne Preis zählt nicht mit

    $this->gericht->update(['ek_per_kg_eur' => null]);                                  // Lebensmittel-EK unbepreist
    $this->svc->setzeZubehoer($this->rootTeam, $this->dar->id, $this->glas->id, 1);
    expect($this->dar->fresh()->ek_portion)->toBeNull();                                // das Glas allein täuscht keinen Preis vor
});

it('MCP recipe_darreichung_zubehoer.PUT: set, remove, Fehler bei Lebensmittel und fremder Darreichung', function () {
    $tool = new RecipeDarreichungZubehoerPutTool;
    $ctx = new ToolContext($this->user, $this->rootTeam);

    $r = $tool->execute(['presentation_id' => $this->dar->id, 'gp_id' => $this->glas->id, 'quantity' => 2, 'action' => 'set'], $ctx);
    expect($r->success)->toBeTrue((string) ($r->error ?? ''))->and($r->data['zubehoer_ek_je_einheit'])->toBe(1.0)->and($r->data['vollstaendig'])->toBeTrue()->and(($this->ek)())->toBe(3.0);

    $butter = zubehoerGp($this->rootTeam, 'Butter: frisch', '06', 12.0);
    expect($tool->execute(['presentation_id' => $this->dar->id, 'gp_id' => $butter->id, 'action' => 'set'], $ctx)->errorCode)->toBe('VALIDATION_ERROR')
        ->and($tool->execute(['presentation_id' => $this->dar->id, 'gp_id' => $this->glas->id, 'action' => 'quatsch'], $ctx)->errorCode)->toBe('VALIDATION_ERROR');

    $r = $tool->execute(['presentation_id' => $this->dar->id, 'gp_id' => $this->glas->id, 'action' => 'remove'], $ctx);
    expect($r->success)->toBeTrue()->and($r->data['zubehoer'])->toBe([])->and(($this->ek)())->toBe(2.0);

    $fremd = new ToolContext($this->makeUser($this->childA, 'Kind'), $this->childA);
    expect($tool->execute(['presentation_id' => $this->dar->id, 'gp_id' => $this->glas->id, 'action' => 'set'], $fremd)->success)->toBeFalse();
});

it('VK-Modal: Block Zubehör & Verpackung je Form — hinzufügen (nur Non-Food in der Suche), Menge ändern, entfernen; Kosten in der Zeile', function () {
    $butter = zubehoerGp($this->rootTeam, 'Butterpapier', '16', 3.0);
    zubehoerGp($this->rootTeam, 'Butter: frisch', '06', 12.0);

    $c = Livewire::test(VkModal::class)->call('oeffnen', $this->gericht->id);
    $c->set('darZubehoerSuche.'.$this->dar->id, 'butter');
    $html = $c->html();
    expect($html)->toContain('data-dar-zubehoer="'.$this->dar->id.'"')->toContain('Butterpapier')->not->toContain('Butter: frisch');   // Lebensmittel taucht in der Suche nicht auf

    $c->call('darZubehoerHinzu', $this->dar->id, $this->glas->id);
    expect(($this->ek)())->toBe(2.5);
    $c->assertSeeHtml('data-zubehoer-zeile="'.$this->glas->id.'"')->assertSeeHtml('data-zubehoer-kosten');

    $c->call('darZubehoerMenge', $this->dar->id, $this->glas->id, '2');
    expect(($this->ek)())->toBe(3.0);
    $c->call('darZubehoerWeg', $this->dar->id, $this->glas->id);
    expect(($this->ek)())->toBe(2.0);

    $c->call('darZubehoerHinzu', $this->dar->id, $butter->id)->call('darZubehoerHinzu', $this->dar->id, zubehoerGp($this->rootTeam, 'Pappteller', '06', 5.0)->id);
    expect($c->get('fehler'))->toContain('Non-Food');   // ein Lebensmittel-GP wird auch hier abgelehnt
});

// ── Kopien: Fassung, Paket-Fassung, Kündigung, Team-Kalkulation ──────────────────────────────────

it('Preis-Anstoß: ändert sich der Lead-Preis des Zubehör-GP (Preiszeile, Lead-Wechsel), rechnen NUR die Darreichungen mit diesem Zubehör neu', function () {
    $this->svc->setzeZubehoer($this->rootTeam, $this->dar->id, $this->glas->id, 1);
    expect(($this->ek)())->toBe(2.5);

    // zweites Gericht ohne Zubehör: bleibt unberührt (kein Massen-Recompute)
    $anderes = FoodAlchemistRecipe::create(['team_id' => $this->rootTeam->id, 'recipe_key' => 'zub-andere', 'name' => 'Anderes Gericht', 'status' => 'approved', 'is_sales_recipe' => true, 'ek_per_kg_eur' => 10.0]);
    $andereDar = $this->svc->ensureStandard($this->rootTeam, $anderes->id);
    $andereDar->update(['quantity_per_unit_g' => 200]);
    $this->svc->recomputePreise($andereDar->fresh());
    $andereDar->update(['ek_portion' => 99.0]);   // Marker: würde ein Massen-Recompute ihn überschreiben, sähe man es

    // 1) Preiszeile am Lead-Artikel: 6,00 → 12,00 je 12 Stk = 1,00 €/Stk
    $la = FoodAlchemistSupplierItem::findOrFail($this->glas->lead_la_supplier_item_id);
    app(PriceService::class)->createFor($this->rootTeam, $la, 12.00);
    expect(($this->ek)())->toBe(3.0)->and((float) $andereDar->fresh()->ek_portion)->toBe(99.0);

    // 2) Lead-Wechsel auf einen günstigeren Artikel desselben GP: 3,00 je 12 Stk = 0,25 €/Stk
    $sup = FoodAlchemistSupplier::first();
    $la2 = FoodAlchemistSupplierItem::create(['team_id' => $this->rootTeam->id, 'supplier_id' => $sup->id, 'designation' => 'Schraubglas günstig', 'qty' => 12, 'unit_code' => 'Stk']);
    FoodAlchemistSupplierItemStructure::create(['team_id' => $this->rootTeam->id, 'supplier_item_id' => $la2->id, 'gp_id' => $this->glas->id]);
    FoodAlchemistPrice::create(['team_id' => $this->rootTeam->id, 'supplier_item_id' => $la2->id, 'price' => 3.00, 'status' => '0']);
    app(\Platform\FoodAlchemist\Services\LeadLaService::class)->setLeadLa($this->rootTeam, $this->glas->fresh(), $la2->id);
    expect(($this->ek)())->toBe(2.25)->and((float) $andereDar->fresh()->ek_portion)->toBe(99.0);

    // Gegenprobe: ein Lebensmittel-GP ohne Zubehör-Zeile löst keinen Darreichungs-Recompute aus
    expect($this->svc->recomputeFuerZubehoerGps([999999]))->toBe(0)->and($this->svc->nachLaPreisAenderung(999999))->toBe(0);
});

// ── Kopien: Abo-Welt als Closure (braucht die geschützten Fixture-Helfer der Testbasis) ──
function zubehoerAboWeltLeer(): void {}

it('Fassung: das Zubehör geht in die Fassung mit, und eine Änderung des Zubehörs ist ein neuer Inhalt (neue Fassungsnummer)', function () {
    ($this->zubehoerAboWelt)();
    DB::table('foodalchemist_recipe_presentation_zubehoer')->insert(['uuid' => (string) \Symfony\Component\Uid\UuidV7::generate(), 'team_id' => $this->master->id,
        'presentation_id' => $this->darM->id, 'gp_id' => $this->glasStamm->id, 'quantity' => 1, 'position' => 1, 'created_at' => now(), 'updated_at' => now()]);

    $erste = app(FassungService::class)->veroeffentlichen($this->master, $this->gerichtM->id, 'Mit Glas', $this->kurator);
    $fd = FoodAlchemistRecipeDarreichung::where('recipe_id', $erste['fassung_id'])->sole();
    expect($fd->zubehoer()->count())->toBe(1)->and($fd->zubehoer()->first()->gp_id)->toBe($this->glasStamm->id);

    // Gegenprobe: nichts geändert ⇒ keine neue Fassung
    expect(app(FassungService::class)->veroeffentlichen($this->master, $this->gerichtM->id, 'nochmal', $this->kurator)['neu'])->toBe([]);

    // Zubehör-Menge geändert ⇒ anderer Inhalt ⇒ neue Fassung
    DB::table('foodalchemist_recipe_presentation_zubehoer')->where('presentation_id', $this->darM->id)->update(['quantity' => 2]);
    expect(app(FassungService::class)->veroeffentlichen($this->master, $this->gerichtM->id, 'Mehr Gläser', $this->kurator)['neu'])->not->toBe([]);
});

it('Team-Kalkulation: der Mandant bekommt den MEK samt Zubehör zu SEINEM Preis; ohne Preis kein VK (nie ein halber)', function () {
    ($this->zubehoerAboWelt)();
    DB::table('foodalchemist_recipe_presentation_zubehoer')->insert(['uuid' => (string) \Symfony\Component\Uid\UuidV7::generate(), 'team_id' => $this->master->id,
        'presentation_id' => $this->darM->id, 'gp_id' => $this->glasStamm->id, 'quantity' => 2, 'position' => 1, 'created_at' => now(), 'updated_at' => now()]);
    $fassung = FoodAlchemistRecipe::mitFassungen()->findOrFail(app(FassungService::class)->veroeffentlichen($this->master, $this->gerichtM->id, 'Erst', $this->kurator)['fassung_id']);

    $k = app(KostenCacheService::class)->fuer($fassung, $this->broich);
    $lebensmittel = round($k['ek_per_kg'] / 1000 * 200, 4);
    $vk = app(TeamVkService::class)->fuer($fassung, $this->broich);

    expect($vk['mek'])->toBe(round($lebensmittel + 2 * 0.5, 4));   // 2 Gläser à 0,50 € dazu

    // Mandant mit Knochen-, aber ohne Glas-Preis: kein VK, Grund ausgewiesen (kein halber Preis)
    $neu = Team::create(['name' => 'Neukunde', 'user_id' => 1, 'personal_team' => false]);
    TeamAncestryRegistry::flushAll();
    StammPaket::freischalten($neu);
    app(AboService::class)->abonnieren($this->master, $neu, $this->sammlungId, $this->kurator);
    app(PriceService::class)->createTeamPrice($neu, $this->laKnochen, 4.00);
    $ohne = app(TeamVkService::class)->fuer($fassung, $neu);
    expect($ohne['vk'])->toBeNull()->and($ohne['grund'])->toBe('zubehoer_ohne_preis');

    // Gegenprobe: dieselbe Fassung ohne Zubehör rechnet für den Neukunden einen VK
    DB::table('foodalchemist_recipe_presentation_zubehoer')->whereIn('presentation_id',
        FoodAlchemistRecipeDarreichung::where('recipe_id', $fassung->id)->pluck('id'))->delete();
    expect(app(TeamVkService::class)->fuer($fassung, $neu)['grund'])->not->toBe('zubehoer_ohne_preis');
});

it('Paket-Fassung und Kündigung: das Zubehör geht in die kopierte Darreichung mit', function () {
    ($this->zubehoerAboWelt)();
    DB::table('foodalchemist_recipe_presentation_zubehoer')->insert(['uuid' => (string) \Symfony\Component\Uid\UuidV7::generate(), 'team_id' => $this->master->id,
        'presentation_id' => $this->darM->id, 'gp_id' => $this->glasStamm->id, 'quantity' => 1, 'position' => 1, 'created_at' => now(), 'updated_at' => now()]);
    $fassungId = app(FassungService::class)->veroeffentlichen($this->master, $this->gerichtM->id, 'Erst', $this->kurator)['fassung_id'];
    $fassung = FoodAlchemistRecipe::mitFassungen()->findOrFail($fassungId);

    // Paket-Fassung (D9): eine NICHT-Standard-Form der Arbeitszeile wird an die Rezept-Fassung angehängt
    $form2 = FoodAlchemistServierform::create(['team_id' => $this->master->id, 'code' => 'teller', 'label' => 'Teller']);
    $quelle = FoodAlchemistRecipeDarreichung::create(['team_id' => $this->master->id, 'recipe_id' => $this->gerichtM->id, 'serving_form_id' => $form2->id,
        'is_standard' => false, 'quantity_per_unit_g' => 150, 'unit_count' => 1, 'price_mode' => 'auto']);
    DB::table('foodalchemist_recipe_presentation_zubehoer')->insert(['uuid' => (string) \Symfony\Component\Uid\UuidV7::generate(), 'team_id' => $this->master->id,
        'presentation_id' => $quelle->id, 'gp_id' => $this->glasStamm->id, 'quantity' => 3, 'position' => 1, 'created_at' => now(), 'updated_at' => now()]);
    $angehaengt = app(PaketFassungService::class)->fassungsDarreichung($fassung, $quelle);
    expect($angehaengt)->not->toBeNull()->and($angehaengt->id)->not->toBe($quelle->id)
        ->and($angehaengt->zubehoer()->count())->toBe(1)->and((float) $angehaengt->zubehoer()->first()->quantity)->toBe(3.0);

    // Kündigung: die Kopie beim Mandanten trägt das Zubehör
    $kopieId = (int) $this->makeRecipe($this->broich, 'Kopie', ['is_sales_recipe' => true])->id;
    $darMap = [];
    $m = new ReflectionMethod(AboKuendigungService::class, 'kopiereInhalt');
    $m->setAccessible(true);
    $args = [$fassung, $kopieId, [], $this->broich, &$darMap];
    $m->invokeArgs(app(AboKuendigungService::class), $args);
    $kopieDar = FoodAlchemistRecipeDarreichung::where('recipe_id', $kopieId)->get();
    expect($kopieDar)->not->toBeEmpty()->and($kopieDar->every(fn ($d) => (int) $d->team_id === (int) $this->broich->id))->toBeTrue()
        ->and($kopieDar)->toHaveCount(2)   // Standard-Form + die angehängte Teller-Form, je Form eine Zubehör-Zeile
        ->and(FoodAlchemistRecipeDarreichungZubehoer::whereIn('presentation_id', $kopieDar->pluck('id'))->where('team_id', $this->broich->id)->count())->toBe(2);
});
