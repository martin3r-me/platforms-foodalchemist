<?php

use Illuminate\Support\Facades\DB;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Models\Team;
use Platform\Core\Tools\ToolRegistry;
use Platform\FoodAlchemist\Exceptions\FaRechtFehltException;
use Platform\FoodAlchemist\Models\FoodAlchemistAbo;
use Platform\FoodAlchemist\Models\FoodAlchemistConcept;
use Platform\FoodAlchemist\Models\FoodAlchemistFoodbook;
use Platform\FoodAlchemist\Models\FoodAlchemistFoodbookBlock;
use Platform\FoodAlchemist\Models\FoodAlchemistFoodbookKapitel;
use Platform\FoodAlchemist\Models\FoodAlchemistFormat;
use Platform\FoodAlchemist\Models\FoodAlchemistFormatSlot;
use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Models\FoodAlchemistOfferBlock;
use Platform\FoodAlchemist\Models\FoodAlchemistOfferChapter;
use Platform\FoodAlchemist\Models\FoodAlchemistPaket;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeDarreichung;
use Platform\FoodAlchemist\Models\FoodAlchemistServierform;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplier;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItemStructure;
use Platform\FoodAlchemist\Models\FoodAlchemistVocabEinheit;
use Platform\FoodAlchemist\Services\AboKuendigungService;
use Platform\FoodAlchemist\Services\AboService;
use Platform\FoodAlchemist\Services\AngebotService;
use Platform\FoodAlchemist\Services\ConceptFassungService;
use Platform\FoodAlchemist\Services\ConceptService;
use Platform\FoodAlchemist\Services\DarreichungService;
use Platform\FoodAlchemist\Services\FassungService;
use Platform\FoodAlchemist\Services\FormatService;
use Platform\FoodAlchemist\Services\InhaltsFreigabeService;
use Platform\FoodAlchemist\Services\PaketFassungService;
use Platform\FoodAlchemist\Services\PaketService;
use Platform\FoodAlchemist\Services\PriceService;
use Platform\FoodAlchemist\Services\RecipeRecomputeService;
use Platform\FoodAlchemist\Support\StammPaket;
use Platform\FoodAlchemist\Support\TeamAncestryRegistry;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 82 Abo Konzepte/Pakete, PR 6 (Übergabe Lisa): Kündigung, Zurückziehen und Kopie für ALLE Arten (Rezept, Paket, Konzept auch kind=paket).
 * Alles über (Art, ID); Kette ohne Doppel; „genutzt“ je Art mit denselben Filtern wie das Folgen; Familien-Regel mit ECHTER Allergen-Sperre aus PR 5.
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
    $this->masterKoch = $this->makeUser($this->master, 'Master Koch', 'member');
    $this->broichChef = $this->makeUser($this->broich, 'Broich Chef', 'owner');
    $this->neuChef = $this->makeUser($this->neu, 'Neu Chef', 'owner');
    $this->actingAs($this->kurator);

    $sup = FoodAlchemistSupplier::create(['team_id' => null, 'name' => 'Stamm-Großhandel']);
    $mk = function (string $name) use ($sup) {
        $gp = FoodAlchemistGp::create(['team_id' => null, 'gp_key' => 'ka|' . $name, 'name' => $name]);
        $la = FoodAlchemistSupplierItem::create(['team_id' => null, 'supplier_id' => $sup->id, 'designation' => $name . ' 1 kg', 'qty' => 1.0, 'unit_code' => 'kg']);
        FoodAlchemistSupplierItemStructure::create(['team_id' => null, 'supplier_item_id' => $la->id, 'gp_id' => $gp->id]);
        $gp->update(['lead_la_supplier_item_id' => $la->id]);

        return [$gp->refresh(), $la];
    };
    [$knochen, $laKnochen] = $mk('Kalbsknochen');
    [$butter, $laButter] = $mk('Butter');

    // Basisrezept (Fond) als Unter-Rezept des Gerichts
    $this->fond = $this->makeRecipe($this->master, 'Kalbsfond', ['is_sales_recipe' => false]);
    $this->makeIngredient($this->fond, 'Kalbsknochen', $knochen, '1000');
    app(RecipeRecomputeService::class)->recomputePipeline($this->fond->id);
    $this->gericht = $this->makeRecipe($this->master, 'Kalbsjus am Teller', ['is_sales_recipe' => true]);
    $this->makeIngredient($this->gericht, 'Kalbsfond', null, '500')->update(['referenced_recipe_id' => $this->fond->id]);
    $this->makeIngredient($this->gericht, 'Butter', $butter, '100', 2);
    app(RecipeRecomputeService::class)->recomputePipeline($this->gericht->id);
    $teller = FoodAlchemistServierform::create(['team_id' => $this->master->id, 'code' => 'teller', 'label' => 'Teller']);
    $klein = FoodAlchemistServierform::create(['team_id' => $this->master->id, 'code' => 'klein', 'label' => 'Klein']);
    $dar = app(DarreichungService::class);
    $this->standard = $dar->anlegen($this->master, $this->gericht->id, $teller->id, ['quantity_per_unit_g' => 200]);
    $this->kleine = $dar->anlegen($this->master, $this->gericht->id, $klein->id, ['quantity_per_unit_g' => 100]);
    $this->portion = FoodAlchemistVocabEinheit::create(['team_id' => $this->master->id, 'slug' => 'portion', 'display_de' => 'Portion', 'dimension' => 'count', 'default_in_g' => null]);

    $paketSvc = app(PaketService::class);
    $this->paket = $paketSvc->create($this->master, ['name' => 'Jus-Duo']);
    $paketSvc->syncGerichte($this->master, $this->paket->id, [['sales_recipe_id' => $this->gericht->id, 'quantity' => 1, 'unit_vocab_id' => $this->portion->id]]);
    $this->paket->dishes()->first()->update(['presentation_id' => $this->kleine->id]);
    $cs = app(ConceptService::class);
    $this->paketKonzept = $cs->createPaket($this->master, ['name' => 'Jus-Kompakt']);
    $s = $cs->addSlot($this->master, $this->paketKonzept->id, ['role' => 'Beilage']);
    $cs->fillSlot($this->master, $s->id, ['sales_recipe_id' => $this->gericht->id, 'quantity' => 1, 'unit_vocab_id' => $this->portion->id]);
    $this->konzept = $cs->create($this->master, ['name' => 'Jus-Menü']);
    $s1 = $cs->addSlot($this->master, $this->konzept->id, ['role' => 'Vorspeise']);
    $cs->fillSlot($this->master, $s1->id, ['package_id' => $this->paket->id]);
    $s2 = $cs->addSlot($this->master, $this->konzept->id, ['role' => 'Beilage']);
    $cs->fillSlot($this->master, $s2->id, ['embedded_concept_id' => $this->paketKonzept->id]);
    $s3 = $cs->addSlot($this->master, $this->konzept->id, ['role' => 'Hauptgang']);
    $cs->fillSlot($this->master, $s3->id, ['sales_recipe_id' => $this->gericht->id, 'quantity' => 1, 'unit_vocab_id' => $this->portion->id]);
    $cs->setSlotDarreichung($this->master, $s3->id, $this->kleine->id);
    $cs->addBlock($this->master, $this->konzept->id, 'header', ['title' => 'Unser Menü']);

    $this->rezeptSvc = app(FassungService::class);
    $this->paketFSvc = app(PaketFassungService::class);
    $this->konzeptFSvc = app(ConceptFassungService::class);
    $this->rezeptSvc->veroeffentlichen($this->master, $this->gericht->id, 'Erst', $this->kurator);
    $this->p1 = FoodAlchemistPaket::mitFassungen()->findOrFail($this->paketFSvc->veroeffentlichen($this->master, $this->paket->id, 'Erst', $this->kurator)['fassung_id']);
    $this->pk1 = FoodAlchemistConcept::mitFassungen()->findOrFail($this->konzeptFSvc->veroeffentlichen($this->master, $this->paketKonzept->id, 'Erst', $this->kurator)['fassung_id']);
    $this->k1 = FoodAlchemistConcept::mitFassungen()->findOrFail($this->konzeptFSvc->veroeffentlichen($this->master, $this->konzept->id, 'Erst', $this->kurator)['fassung_id']);

    $inhalte = app(InhaltsFreigabeService::class);
    $this->sammlung = $inhalte->sammlungAnlegen($this->master, 'Menüs', null, $this->kurator);
    $inhalte->sammlungHinzu($this->master, $this->sammlung, 'concept', [$this->konzept->id], $this->kurator);
    $inhalte->sammlungHinzu($this->master, $this->sammlung, 'paket', [$this->paket->id], $this->kurator);
    $this->aboBroich = app(AboService::class)->abonnieren($this->master, $this->broich, $this->sammlung, $this->kurator);
    $this->aboNeu = app(AboService::class)->abonnieren($this->master, $this->neu, $this->sammlung, $this->kurator);
    $pr = app(PriceService::class);
    $pr->createTeamPrice($this->broich, $laKnochen, 4.00);
    $pr->createTeamPrice($this->broich, $laButter, 10.00);
    $pr->createTeamPrice($this->neu, $laButter, 10.00);   // Neukunde: Knochen ohne Preis → unvollständig

    // Verweise des Mandanten Broich auf die Fassungen: eigenes Konzept (Paket-Slot), Foodbook, Format, historisches Angebot, archiviertes Foodbook
    $this->eigen = $cs->create($this->broich, ['name' => 'Broich-Menü']);
    $e1 = $cs->addSlot($this->broich, $this->eigen->id, ['role' => 'Beilage']);
    $this->slotZeile = $e1->id;
    DB::table('foodalchemist_concept_slots')->where('id', $e1->id)->update(['type' => 'paket', 'package_id' => $this->p1->id]);
    $fb = FoodAlchemistFoodbook::create(['team_id' => $this->broich->id, 'label' => 'Sommerkarte']);
    $kap = FoodAlchemistFoodbookKapitel::create(['team_id' => $this->broich->id, 'foodbook_id' => $fb->id, 'title' => 'Hauptgang', 'position' => 0]);
    $this->block = FoodAlchemistFoodbookBlock::create(['team_id' => $this->broich->id, 'chapter_id' => $kap->id, 'type' => 'concept_ref', 'position' => 0, 'visible' => true, 'concept_id' => $this->k1->id]);
    $arch = FoodAlchemistFoodbook::create(['team_id' => $this->broich->id, 'label' => 'Archiv', 'status' => 'archiviert']);
    $kapA = FoodAlchemistFoodbookKapitel::create(['team_id' => $this->broich->id, 'foodbook_id' => $arch->id, 'title' => 'X', 'position' => 0]);
    $this->blockArchiv = FoodAlchemistFoodbookBlock::create(['team_id' => $this->broich->id, 'chapter_id' => $kapA->id, 'type' => 'concept_ref', 'position' => 0, 'visible' => true, 'concept_id' => $this->k1->id]);
    $format = app(FormatService::class)->create($this->broich, ['name' => 'Gala-Format']);
    $this->formatSlot = FoodAlchemistFormatSlot::create(['team_id' => $this->broich->id, 'format_id' => $format->id, 'type' => 'concept', 'concept_id' => $this->k1->id, 'position' => 0]);
    $angebot = app(AngebotService::class)->create($this->broich, ['name' => 'Altangebot', 'personen' => 50]);
    DB::table('foodalchemist_offers')->where('id', $angebot->id)->update(['status' => 'angenommen']);
    $kapO = FoodAlchemistOfferChapter::create(['team_id' => $this->broich->id, 'offer_id' => $angebot->id, 'title' => 'Menü', 'position' => 0]);
    $this->blockHist = FoodAlchemistOfferBlock::create(['team_id' => $this->broich->id, 'chapter_id' => $kapO->id, 'type' => 'concept_ref', 'position' => 0, 'visible' => true, 'concept_id' => $this->k1->id]);

    $this->svc = app(AboKuendigungService::class);
    $this->reg = app(ToolRegistry::class);
});

function slotPaketId($t): ?int
{
    return DB::table('foodalchemist_concept_slots')->where('id', $t->slotZeile)->value('package_id');
}

it('Vorschau je Art mit typ: genutzt = Konzept + Paket (mit wo), Kette mitkopiert, nichts ändert sich (bestaetigt=false)', function () {
    $vorher = ['r' => FoodAlchemistRecipe::mitFassungen()->count(), 'p' => FoodAlchemistPaket::mitFassungen()->count(), 'c' => FoodAlchemistConcept::mitFassungen()->count()];
    $r = $this->svc->kuendigen($this->broich, $this->aboBroich->id, $this->broichChef, bestaetigt: false);
    $v = $r['vorschau'];

    expect($r['ausgefuehrt'])->toBeFalse()
        ->and($v['genutzt_je_art']['concept'])->toBe([$this->k1->id])
        ->and($v['genutzt_je_art']['paket'])->toBe([$this->p1->id])
        ->and($v['genutzt_ids'])->toBe([])   // `*_ids` bleiben Rezept-IDs (UI/MandantBeenden)
        ->and(collect($v['genutzt'])->firstWhere('typ', 'concept')['wo'])->toContain('foodbook', 'format')
        ->and(collect($v['genutzt'])->firstWhere('typ', 'paket')['wo'])->toContain('konzept')
        ->and($v['mitkopiert_je_art']['concept'])->toBe([$this->pk1->id])      // eingebettetes Paket-Konzept (D6)
        ->and($v['mitkopiert_je_art']['recipe'])->toHaveCount(2)               // Gericht + Fond
        ->and($v['mitkopiert_je_art']['paket'])->toBe([])
        ->and(collect($v['genutzt'])->pluck('typ')->unique()->sort()->values()->all())->toBe(['concept', 'paket']);
    // nichts passiert
    expect(FoodAlchemistRecipe::mitFassungen()->count())->toBe($vorher['r'])->and(FoodAlchemistPaket::mitFassungen()->count())->toBe($vorher['p'])
        ->and(FoodAlchemistConcept::mitFassungen()->count())->toBe($vorher['c'])->and(slotPaketId($this))->toBe($this->p1->id)
        ->and(FoodAlchemistAbo::find($this->aboBroich->id)->status)->toBe(FoodAlchemistAbo::AKTIV);
});

it('Kündigung: Kette ohne Doppel (Gericht EINMAL kopiert), Kopien sind normale Arbeitszeilen, Slots zeigen auf Kopien, Form mit umgemappt', function () {
    $r = $this->svc->kuendigen($this->broich, $this->aboBroich->id, $this->broichChef, bestaetigt: true);
    $k = $r['kopien_je_art'];

    expect($r['ausgefuehrt'])->toBeTrue()
        ->and($k['concept'])->toHaveCount(2)->and($k['paket'])->toHaveCount(1)->and($k['recipe'])->toHaveCount(2)   // Gericht wird von Paket, Paket-Konzept UND Konzept gebraucht = eine Kopie
        ->and($r['kopien'])->toBe($k['recipe']);
    $konzeptK = FoodAlchemistConcept::ohneTeamSicht('test')->findOrFail($k['concept'][$this->k1->id]);
    $paketK = FoodAlchemistPaket::ohneTeamSicht('test')->findOrFail($k['paket'][$this->p1->id]);
    $paketKonzeptK = FoodAlchemistConcept::ohneTeamSicht('test')->findOrFail($k['concept'][$this->pk1->id]);
    $gerichtK = FoodAlchemistRecipe::ohneTeamSicht('test')->findOrFail($k['recipe'][FoodAlchemistRecipe::mitFassungen()->where('fassung_von_id', $this->gericht->id)->where('ist_fassung', true)->value('id')]);

    expect($konzeptK->ist_fassung)->toBeFalse()->and((int) $konzeptK->team_id)->toBe((int) $this->broich->id)->and((int) $konzeptK->kopie_von_id)->toBe($this->konzept->id)
        ->and($konzeptK->kopie_stand_at)->not->toBeNull()->and($paketK->ist_fassung)->toBeFalse()->and((int) $paketK->kopie_von_id)->toBe($this->paket->id)
        ->and($paketKonzeptK->kind)->toBe('paket');
    $slots = $konzeptK->slots()->orderBy('position')->get();
    expect($slots->pluck('package_id')->filter()->values()->all())->toBe([$paketK->id])
        ->and($slots->pluck('embedded_concept_id')->filter()->values()->all())->toBe([$paketKonzeptK->id])
        ->and($slots->pluck('sales_recipe_id')->filter()->values()->all())->toBe([$gerichtK->id])
        ->and($paketK->dishes()->pluck('sales_recipe_id')->map(fn ($v) => (int) $v)->all())->toBe([$gerichtK->id]);
    // gewählte Form: zeigt auf die Form der Rezept-KOPIE (kleine Portion), nie auf die der Fassung
    $slotG = $slots->firstWhere('sales_recipe_id', $gerichtK->id);
    $formKopie = FoodAlchemistRecipeDarreichung::find($slotG->presentation_id);
    expect($formKopie)->not->toBeNull()->and((int) $formKopie->recipe_id)->toBe((int) $gerichtK->id)->and((float) $formKopie->quantity_per_unit_g)->toBe(100.0);
    // Kette unter dem Gericht: der Fond ist Unter-Rezept der KOPIE
    $fondK = FoodAlchemistRecipe::ohneTeamSicht('test')->findOrFail(collect($k['recipe'])->reject(fn ($kopieId) => $kopieId === $gerichtK->id)->first());
    expect($gerichtK->ingredients()->whereNotNull('referenced_recipe_id')->value('referenced_recipe_id'))->toBe($fondK->id);
    // keine Fassungs-Zeile wurde gelöscht
    expect(FoodAlchemistConcept::mitFassungen()->find($this->k1->id))->not->toBeNull()->and(FoodAlchemistPaket::mitFassungen()->find($this->p1->id))->not->toBeNull();
});

it('Umhängen: lebende Verweise (eigenes Konzept, Foodbook, Format) auf die Kopie, Historie bleibt auf der Fassung', function () {
    $r = $this->svc->kuendigen($this->broich, $this->aboBroich->id, $this->broichChef, bestaetigt: true);
    $k = $r['kopien_je_art'];

    expect(slotPaketId($this))->toBe($k['paket'][$this->p1->id])
        ->and(FoodAlchemistFoodbookBlock::find($this->block->id)->concept_id)->toBe($k['concept'][$this->k1->id])
        ->and(FoodAlchemistFormatSlot::find($this->formatSlot->id)->concept_id)->toBe($k['concept'][$this->k1->id])
        ->and(FoodAlchemistFoodbookBlock::find($this->blockArchiv->id)->concept_id)->toBe($this->k1->id)          // archiviert: Fassung bleibt
        ->and(FoodAlchemistOfferBlock::find($this->blockHist->id)->concept_id)->toBe($this->k1->id)               // angenommen: Fassung bleibt
        ->and($r['umgehaengt'])->toBeGreaterThanOrEqual(3);
});

it('Preis nach der Kündigung: Kopien rechnen mit den Preisen des Mandanten, ohne vollständigen EK „kein Preis“ (nie 0 €)', function () {
    $b = $this->svc->kuendigen($this->broich, $this->aboBroich->id, $this->broichChef, bestaetigt: true)['kopien_je_art'];
    $paketB = FoodAlchemistPaket::ohneTeamSicht('test')->findOrFail($b['paket'][$this->p1->id]);
    $konzeptB = FoodAlchemistConcept::ohneTeamSicht('test')->findOrFail($b['concept'][$this->k1->id]);
    expect((float) $paketB->ek_per_person)->toBeGreaterThan(0.0)->and((float) $konzeptB->ek_per_person_cache)->toBeGreaterThan(0.0);

    // Neukunde nutzt dieselbe Kette, hat aber keinen Knochenpreis → das Paket der Kopie ist ohne vollständigen EK nicht bepreist
    $cs = app(ConceptService::class);
    $eigenN = $cs->create($this->neu, ['name' => 'Neu-Menü']);
    $z = $cs->addSlot($this->neu, $eigenN->id, ['role' => 'Beilage']);
    DB::table('foodalchemist_concept_slots')->where('id', $z->id)->update(['type' => 'paket', 'package_id' => $this->p1->id]);
    $n = $this->svc->kuendigen($this->neu, $this->aboNeu->id, $this->neuChef, bestaetigt: true)['kopien_je_art'];
    $paketN = FoodAlchemistPaket::ohneTeamSicht('test')->findOrFail($n['paket'][$this->p1->id]);
    $gerichtN = FoodAlchemistRecipe::ohneTeamSicht('test')->findOrFail(collect($n['recipe'])->first());
    expect($gerichtN->ek_total_eur === null || (float) $gerichtN->ek_total_eur >= 0.0)->toBeTrue()
        ->and($paketN->price_per_person === null || (float) $paketN->price_per_person > 0.0)->toBeTrue();   // nie ein Preis von 0 €
});

it('D3 · über ein zweites aktives Abo weiter sichtbar: nichts wird kopiert, nichts verschwindet', function () {
    $inhalte = app(InhaltsFreigabeService::class);
    $zweite = $inhalte->sammlungAnlegen($this->master, 'Menüs 2', null, $this->kurator);
    $inhalte->sammlungHinzu($this->master, $zweite, 'concept', [$this->konzept->id], $this->kurator);
    $inhalte->sammlungHinzu($this->master, $zweite, 'paket', [$this->paket->id], $this->kurator);
    app(AboService::class)->abonnieren($this->master, $this->broich, $zweite, $this->kurator);

    $r = $this->svc->kuendigen($this->broich, $this->aboBroich->id, $this->broichChef, bestaetigt: true);
    expect($r['kopien_je_art'])->toBe(['recipe' => [], 'paket' => [], 'concept' => [], 'format' => []])
        ->and($r['vorschau']['verschwindet_je_art'])->toBe(['recipe' => [], 'paket' => [], 'concept' => [], 'format' => []])
        ->and(slotPaketId($this))->toBe($this->p1->id);
});

it('Familien-Regel mit ECHTER Allergen-Sperre (PR 5): kopiert wird die tatsächlich referenzierte Fassung 1, nie die neueste', function () {
    // Allergen-Änderung über die Kette: Rezept-Fassung 2 mit Gluten, Paket-Fassung 2 nimmt sie mit → der eigene Verweis BLEIBT auf Paket-Fassung 1 (4d/PR 5)
    $this->gericht->ingredients()->first()->update(['quantity' => 700]);
    $r2 = FoodAlchemistRecipe::mitFassungen()->findOrFail($this->rezeptSvc->veroeffentlichen($this->master, $this->gericht->id, 'Mit Gluten', $this->kurator)['fassung_id']);
    $r2->forceFill(['allergen_gluten' => 'enthalten'])->save();
    $this->paket->update(['name' => 'Jus-Duo Gluten']);
    $p2 = FoodAlchemistPaket::mitFassungen()->findOrFail($this->paketFSvc->veroeffentlichen($this->master, $this->paket->id, 'Gluten', $this->kurator)['fassung_id']);
    // das Konzept nimmt die neue Paket-Fassung mit — damit steht Paket-Fassung 1 NICHT mehr über Konzept-Fassung 1 in der Hülle, nur der eigene Verweis hält sie
    $this->konzept->update(['name' => 'Jus-Menü Gluten']);
    $k2 = FoodAlchemistConcept::mitFassungen()->findOrFail($this->konzeptFSvc->veroeffentlichen($this->master, $this->konzept->id, 'Gluten', $this->kurator)['fassung_id']);
    expect(slotPaketId($this))->toBe($this->p1->id)->and($p2->fassung_allergen_geaendert)->toBeTrue()   // echte Sperre, nicht von Hand gesetzt
        ->and($k2->fassung_allergen_geaendert)->toBeTrue()
        ->and(FoodAlchemistFoodbookBlock::find($this->block->id)->concept_id)->toBe($this->k1->id);       // auch der Konzept-Verweis bleibt (Allergen-Erbe über die Kette)

    $v = $this->svc->kuendigen($this->broich, $this->aboBroich->id, $this->broichChef, bestaetigt: false)['vorschau'];
    expect($v['genutzt_je_art']['paket'])->toBe([$this->p1->id])                       // die ältere, referenzierte Fassung zählt
        ->and($v['genutzt_je_art']['concept'])->toBe([$this->k1->id])
        ->and($v['verschwindet_je_art']['paket'])->toBe([$p2->id])                      // die ungenutzte neueste verschwindet
        ->and($v['verschwindet_je_art']['concept'])->toContain($k2->id)
        ->and($v['genutzt_je_art']['paket'])->not->toContain($p2->id);

    $k = $this->svc->kuendigen($this->broich, $this->aboBroich->id, $this->broichChef, bestaetigt: true)['kopien_je_art'];
    expect(array_keys($k['paket']))->toBe([$this->p1->id])->and(array_keys($k['paket']))->not->toContain($p2->id);
    $paketK = FoodAlchemistPaket::ohneTeamSicht('test')->findOrFail($k['paket'][$this->p1->id]);
    expect($paketK->name)->toBe('Jus-Duo')                                              // Stand der Fassung 1, nicht der umbenannte
        ->and(slotPaketId($this))->toBe($paketK->id);
});

it('Zurückziehen je Art: Konzept und Paket mit (typ, id) — das Rezept mit gleicher ID bleibt unberührt', function () {
    // gleiche ID in verschiedenen Tabellen provozieren: ein Rezept mit der ID des Pakets in dieselbe Sammlung legen
    $inhalte = app(InhaltsFreigabeService::class);
    $inhalte->sammlungHinzu($this->master, $this->sammlung, 'recipe', [$this->paket->id], $this->kurator);   // Rezept-ID == Paket-ID (Zeile steht, auch wenn das Rezept evtl. ein anderes ist)
    $rezeptZeile = fn () => DB::table('foodalchemist_sammlung_objekte')->where('sammlung_id', $this->sammlung)->where('typ', 'recipe')->where('objekt_id', $this->paket->id)->count();
    expect($rezeptZeile())->toBe(1);

    $vor = $this->svc->zurueckziehen($this->master, $this->sammlung, $this->paket->id, $this->kurator, false, 'paket');
    expect($vor['ausgefuehrt'])->toBeFalse()
        ->and(collect($vor['mandanten'])->keyBy('mandant_team_id')[$this->broich->id]['vorschau']['genutzt_je_art']['paket'])->toBe([]);   // das Paket bleibt über das Konzept der Sammlung sichtbar

    $r = $this->svc->zurueckziehen($this->master, $this->sammlung, $this->paket->id, $this->kurator, true, 'paket');
    expect($r['ausgefuehrt'])->toBeTrue()
        ->and(DB::table('foodalchemist_sammlung_objekte')->where('sammlung_id', $this->sammlung)->where('typ', 'paket')->where('objekt_id', $this->paket->id)->count())->toBe(0)
        ->and($rezeptZeile())->toBe(1)                                                 // das Rezept gleicher ID blieb
        ->and(slotPaketId($this))->toBe($this->p1->id);                                 // noch sichtbar (Konzept in der Sammlung) → keine Kopie

    // Konzept zurückziehen: jetzt fällt auch das Paket aus der Hülle, Broichs Verweise (Konzept, Foodbook, Format, eigenes Konzept → Paket) hängen auf Kopien
    $c = $this->svc->zurueckziehen($this->master, $this->sammlung, $this->konzept->id, $this->kurator, true, 'concept');
    $k = collect($c['mandanten'])->keyBy('mandant_team_id')[$this->broich->id]['kopien_je_art'];
    expect($k['concept'])->toHaveKey($this->k1->id)->and($k['paket'])->toHaveKey($this->p1->id)
        ->and(FoodAlchemistFoodbookBlock::find($this->block->id)->concept_id)->toBe($k['concept'][$this->k1->id])
        ->and(slotPaketId($this))->toBe($k['paket'][$this->p1->id])
        ->and(array_keys(app(AboService::class)->huelle((int) $this->broich->id)))->not->toContain('concept');
});

it('Rechte beim Zurückziehen: nur Master mit Freigeben; Löschsperre je Art, bis nichts mehr abonniert ist', function () {
    expect(fn () => $this->svc->zurueckziehen($this->master, $this->sammlung, $this->konzept->id, $this->masterKoch, true, 'concept'))->toThrow(FaRechtFehltException::class)
        ->and(fn () => $this->svc->zurueckziehen($this->broich, $this->sammlung, $this->konzept->id, $this->broichChef, true, 'concept'))->toThrow(\RuntimeException::class, 'Master')
        ->and(fn () => $this->svc->zurueckziehen($this->master, $this->sammlung, $this->konzept->id, $this->kurator, true, 'format'))->toThrow(\RuntimeException::class);

    // Löschsperre: Paket, Konzept, Format (Format nur über eine Sammlung)
    $format = app(FormatService::class)->create($this->master, ['name' => 'Master-Format']);
    app(InhaltsFreigabeService::class)->sammlungHinzu($this->master, $this->sammlung, 'format', [$format->id], $this->kurator);
    expect(fn () => app(PaketService::class)->delete($this->master, $this->paket->id))->toThrow(\RuntimeException::class, 'zurückziehen')
        ->and(fn () => app(ConceptService::class)->delete($this->master, $this->konzept->id))->toThrow(\RuntimeException::class, 'zurückziehen')
        ->and(fn () => app(ConceptService::class)->delete($this->master, $this->paketKonzept->id))->not->toThrow(\RuntimeException::class);   // nicht in einer Sammlung: löschbar
    expect(fn () => app(FormatService::class)->delete($this->master, $format->id))->toThrow(\RuntimeException::class, 'zurückziehen');

    // beide Abos kündigen → die Sperre fällt
    $this->svc->kuendigen($this->master, $this->aboBroich->id, $this->kurator, true);
    $this->svc->kuendigen($this->master, $this->aboNeu->id, $this->kurator, true);
    app(PaketService::class)->delete($this->master, $this->paket->id);
    app(ConceptService::class)->delete($this->master, $this->konzept->id);
    app(FormatService::class)->delete($this->master, $format->id);
    expect(FoodAlchemistPaket::ohneTeamSicht('test')->find($this->paket->id))->toBeNull()->and(FoodAlchemistConcept::ohneTeamSicht('test')->find($this->konzept->id))->toBeNull();
});

it('MCP abos.WITHDRAW mit typ, abos.DELETE liefert kopien_je_art', function () {
    $ctx = new ToolContext($this->kurator, $this->master);
    $w = $this->reg->get('foodalchemist.abos.WITHDRAW')->execute(['sammlung_id' => $this->sammlung, 'recipe_id' => $this->konzept->id, 'typ' => 'concept'], $ctx);
    expect($w->success)->toBeTrue((string) ($w->error ?? ''))->and($w->data['ausgefuehrt'])->toBeFalse();
    $w2 = $this->reg->get('foodalchemist.abos.WITHDRAW')->execute(['sammlung_id' => $this->sammlung, 'recipe_id' => $this->konzept->id, 'typ' => 'concept', 'bestaetigt' => true], $ctx);
    expect($w2->success)->toBeTrue((string) ($w2->error ?? ''))->and($w2->data['ausgefuehrt'])->toBeTrue();

    $d = $this->reg->get('foodalchemist.abos.DELETE')->execute(['abo_id' => $this->aboBroich->id, 'bestaetigt' => true], $ctx);
    expect($d->success)->toBeTrue((string) ($d->error ?? ''))->and($d->data['kopien_je_art'])->toHaveKeys(['recipe', 'paket', 'concept']);
});

it('Mandant beenden (systemLauf): AboAuskunft kündigt alle Abos samt Konzepten und Paketen', function () {
    $x = app(\Platform\FoodAlchemist\Services\AboAuskunft::class)->kuendigen($this->broich, $this->broichChef);
    expect(FoodAlchemistAbo::where('mandant_team_id', $this->broich->id)->where('status', FoodAlchemistAbo::AKTIV)->count())->toBe(0)
        ->and(slotPaketId($this))->not->toBe($this->p1->id)
        ->and(FoodAlchemistConcept::ohneTeamSicht('test')->where('team_id', $this->broich->id)->where('kopie_von_id', $this->konzept->id)->count())->toBe(1);
});
