<?php

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Platform\Core\Models\ContextFile;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistAbo;
use Platform\FoodAlchemist\Models\FoodAlchemistComponentEquivalent;
use Platform\FoodAlchemist\Models\FoodAlchemistConcept;
use Platform\FoodAlchemist\Models\FoodAlchemistFoodbook;
use Platform\FoodAlchemist\Models\FoodAlchemistFoodbookBlock;
use Platform\FoodAlchemist\Models\FoodAlchemistFoodbookKapitel;
use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Models\FoodAlchemistPaket;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Models\FoodAlchemistServierform;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplier;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItemStructure;
use Platform\FoodAlchemist\Models\FoodAlchemistVocabEinheit;
use Platform\FoodAlchemist\Services\AboKuendigungService;
use Platform\FoodAlchemist\Services\AboService;
use Platform\FoodAlchemist\Services\ConceptFassungService;
use Platform\FoodAlchemist\Services\ConceptService;
use Platform\FoodAlchemist\Services\DarreichungService;
use Platform\FoodAlchemist\Services\FassungService;
use Platform\FoodAlchemist\Services\InhaltsFreigabeService;
use Platform\FoodAlchemist\Services\KostenCacheService;
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
 * Spec 82 Abo Konzepte/Pakete, PR 6b (Entscheid Dominique 14.10. + Nachtrag Lisa): (1) Bilder werden bei Kündigung/Kopie in den Speicher des Mandanten
 * mitkopiert, fehlt die Datei: Kopie ohne Bild + Hinweis, nie ein Abbruch; (2) was über ein anderes aktives Abo sichtbar bleibt, wird NICHT kopiert und nicht
 * umgehängt — für Rezepte UND Pakete; vierte Vorschau-Liste „bleibt“; Sichtbarkeit je Familie; Ersatz-Kanten gehen nicht verloren; der EK einer Kopie, die auf
 * eine weiter sichtbare Fassung zeigt, rechnet mit den Preisen des Mandanten (nie unvollständig, nie 0 €).
 *
 * Broich: S1 {Jus} + S2 {Demi} (Fond teilen sich beide) · Soul: S3 {Konzept K} + S4 {Paket P}.
 */
beforeEach(function () {
    Storage::fake('public');
    $this->seedTeamHierarchy();
    $this->master = Team::create(['name' => 'BHG.DIGITAL (Master)', 'user_id' => 1, 'personal_team' => false]);
    config(['foodalchemist.master_team_id' => $this->master->id]);
    $this->broich = Team::create(['name' => 'Broich', 'user_id' => 1, 'personal_team' => false]);
    $this->soul = Team::create(['name' => 'Soulkitchen', 'user_id' => 1, 'personal_team' => false]);
    TeamAncestryRegistry::flushAll();
    StammPaket::freischalten($this->broich);
    StammPaket::freischalten($this->soul);
    $this->kurator = $this->makeUser($this->master, 'Kurator', 'owner');
    $this->broichChef = $this->makeUser($this->broich, 'Broich Chef', 'owner');
    $this->soulChef = $this->makeUser($this->soul, 'Soul Chef', 'owner');
    $this->actingAs($this->kurator);

    $sup = FoodAlchemistSupplier::create(['team_id' => null, 'name' => 'Stamm-Großhandel']);
    $mk = function (string $name, array $extra = []) use ($sup) {
        $gp = FoodAlchemistGp::create(['team_id' => null, 'gp_key' => 'kb|' . $name, 'name' => $name] + $extra);
        $la = FoodAlchemistSupplierItem::create(['team_id' => null, 'supplier_id' => $sup->id, 'designation' => $name . ' 1 kg', 'qty' => 1.0, 'unit_code' => 'kg']);
        FoodAlchemistSupplierItemStructure::create(['team_id' => null, 'supplier_item_id' => $la->id, 'gp_id' => $gp->id]);
        $gp->update(['lead_la_supplier_item_id' => $la->id]);

        return [$gp->refresh(), $la];
    };
    [$this->knochen, $laKnochen] = $mk('Kalbsknochen');
    [$butter, $laButter] = $mk('Butter');
    [$this->milchGp, $laMilch] = $mk('Sahne', ['allergen_milk' => 'enthalten']);
    $pr = app(PriceService::class);
    foreach ([$this->broich, $this->soul] as $t) {
        $pr->createTeamPrice($t, $laKnochen, 4.00);
        $pr->createTeamPrice($t, $laButter, 10.00);
        $pr->createTeamPrice($t, $laMilch, 6.00);
    }

    $this->fond = $this->makeRecipe($this->master, 'Kalbsfond', ['is_sales_recipe' => false]);
    $this->makeIngredient($this->fond, 'Kalbsknochen', $this->knochen, '1000');
    app(RecipeRecomputeService::class)->recomputePipeline($this->fond->id);
    $mkGericht = function (string $name, string $menge) use ($butter) {
        $g = $this->makeRecipe($this->master, $name, ['is_sales_recipe' => true]);
        $this->makeIngredient($g, 'Kalbsfond', null, $menge)->update(['referenced_recipe_id' => $this->fond->id]);
        $this->makeIngredient($g, 'Butter', $butter, '100', 2);
        app(RecipeRecomputeService::class)->recomputePipeline($g->id);

        return $g;
    };
    $this->jus = $mkGericht('Kalbsjus', '500');
    $this->demi = $mkGericht('Demi-glace', '300');
    // Ersatz-Kante Rezept ↔ Rezept (Master): Jus kann durch den Fond ersetzt werden — darf bei der Kopie nicht verloren gehen
    FoodAlchemistComponentEquivalent::create(['team_id' => $this->master->id, 'source_kind' => 'recipe', 'source_id' => $this->jus->id,
        'alt_kind' => 'recipe', 'alt_id' => $this->fond->id, 'umrechnungsfaktor' => 1, 'standard_seite' => 'source']);

    $teller = FoodAlchemistServierform::create(['team_id' => $this->master->id, 'code' => 'teller', 'label' => 'Teller']);
    app(DarreichungService::class)->anlegen($this->master, $this->jus->id, $teller->id, ['quantity_per_unit_g' => 200]);
    $this->portion = FoodAlchemistVocabEinheit::create(['team_id' => $this->master->id, 'slug' => 'portion', 'display_de' => 'Portion', 'dimension' => 'count', 'default_in_g' => null]);

    $this->fassungen = app(FassungService::class);
    $this->jusF = FoodAlchemistRecipe::mitFassungen()->findOrFail($this->fassungen->veroeffentlichen($this->master, $this->jus->id, 'Erst', $this->kurator)['fassung_id']);
    $this->demiF = FoodAlchemistRecipe::mitFassungen()->findOrFail($this->fassungen->veroeffentlichen($this->master, $this->demi->id, 'Erst', $this->kurator)['fassung_id']);
    $this->fondF = FoodAlchemistRecipe::mitFassungen()->where('fassung_von_id', $this->fond->id)->where('ist_fassung', true)->sole();   // EINE Fond-Fassung für beide

    $paketSvc = app(PaketService::class);
    $this->paket = $paketSvc->create($this->master, ['name' => 'Jus-Duo']);
    $paketSvc->syncGerichte($this->master, $this->paket->id, [['sales_recipe_id' => $this->jus->id, 'quantity' => 1, 'unit_vocab_id' => $this->portion->id]]);
    $this->p1 = FoodAlchemistPaket::mitFassungen()->findOrFail(app(PaketFassungService::class)->veroeffentlichen($this->master, $this->paket->id, 'Erst', $this->kurator)['fassung_id']);
    $cs = app(ConceptService::class);
    $this->konzept = $cs->create($this->master, ['name' => 'Jus-Menü']);
    $s1 = $cs->addSlot($this->master, $this->konzept->id, ['role' => 'Beilage']);
    $cs->fillSlot($this->master, $s1->id, ['package_id' => $this->paket->id]);

    $this->inhalte = app(InhaltsFreigabeService::class);
    $sammlung = function (string $name, string $typ, array $ids) {
        $id = $this->inhalte->sammlungAnlegen($this->master, $name, null, $this->kurator);
        $this->inhalte->sammlungHinzu($this->master, $id, $typ, $ids, $this->kurator);

        return $id;
    };
    $this->abos = app(AboService::class);
    $this->aboS1 = $this->abos->abonnieren($this->master, $this->broich, $sammlung('Jus-Rezept', 'recipe', [$this->jus->id]), $this->kurator);
    $this->aboS2 = $this->abos->abonnieren($this->master, $this->broich, $sammlung('Saucen', 'recipe', [$this->demi->id]), $this->kurator);
    $this->svc = app(AboKuendigungService::class);

    // Broich nutzt den Jus: eigenes Rezept (Zutat). Soul bekommt Konzept und Paket erst im jeweiligen Test (Konzept-Fassung nach dem Bild)
    $this->eigen = $this->makeRecipe($this->broich, 'Broich Kalbsrücken');
    $this->eigenZeile = $this->makeIngredient($this->eigen, 'Jus', null, '100');
    $this->eigenZeile->update(['referenced_recipe_id' => $this->jusF->id]);

    $this->soulAbos = function (?string $bildDatei = null) use ($sammlung, $cs) {
        if ($bildDatei !== null) {
            $cs->storeImage($this->master, $this->konzept->id, UploadedFile::fake()->image($bildDatei, 400, 200));
            $cs->addGalleryImage($this->master, $this->konzept->id, UploadedFile::fake()->image('g1.jpg', 300, 200));
            $this->konzept->refresh();
        }
        $this->k1 = FoodAlchemistConcept::mitFassungen()->findOrFail(app(ConceptFassungService::class)->veroeffentlichen($this->master, $this->konzept->id, 'Erst', $this->kurator)['fassung_id']);
        $this->aboS3 = $this->abos->abonnieren($this->master, $this->soul, $sammlung('Menü', 'concept', [$this->konzept->id]), $this->kurator);
        $this->aboS4 = $this->abos->abonnieren($this->master, $this->soul, $sammlung('Pakete', 'paket', [$this->paket->id]), $this->kurator);
        // Soul nutzt das Konzept (Foodbook) und das Paket (eigenes Konzept)
        $fb = FoodAlchemistFoodbook::create(['team_id' => $this->soul->id, 'label' => 'Sommerkarte']);
        $kap = FoodAlchemistFoodbookKapitel::create(['team_id' => $this->soul->id, 'foodbook_id' => $fb->id, 'title' => 'Hauptgang', 'position' => 0]);
        $this->block = FoodAlchemistFoodbookBlock::create(['team_id' => $this->soul->id, 'chapter_id' => $kap->id, 'type' => 'concept_ref', 'position' => 0, 'visible' => true, 'concept_id' => $this->k1->id]);
        $this->soulEigen = $cs->create($this->soul, ['name' => 'Soul-Menü']);
        $z = $cs->addSlot($this->soul, $this->soulEigen->id, ['role' => 'Beilage']);
        $this->soulSlot = $z->id;
        $p1 = FoodAlchemistPaket::mitFassungen()->where('fassung_von_id', $this->paket->id)->where('ist_fassung', true)->value('id');
        DB::table('foodalchemist_concept_slots')->where('id', $z->id)->update(['type' => 'paket', 'package_id' => $p1]);
    };
});

it('Rezept: Fond bleibt über das zweite Abo sichtbar → nicht kopiert, die Kopie zeigt weiter auf die Fassung; Vorschau-Liste „bleibt“ nennt die Sammlung', function () {
    $r = $this->svc->kuendigen($this->broich, $this->aboS1->id, $this->broichChef, bestaetigt: false);
    $v = $r['vorschau'];
    expect($v['genutzt_ids'])->toBe([$this->jusF->id])
        ->and($v['bleibt_ids'])->toBe([$this->fondF->id])
        ->and($v['mitkopiert_ids'])->toBe([])->and($v['verschwindet_ids'])->toBe([])
        ->and($v['bleibt'][0])->toMatchArray(['typ' => 'recipe', 'id' => $this->fondF->id, 'name' => 'Kalbsfond', 'via' => ['Saucen']]);

    $k = $this->svc->kuendigen($this->broich, $this->aboS1->id, $this->broichChef, bestaetigt: true)['kopien'];
    expect(array_keys($k))->toBe([$this->jusF->id]);                                                  // nur der Jus, der Fond NICHT
    $jusK = FoodAlchemistRecipe::ohneTeamSicht('test')->findOrFail($k[$this->jusF->id]);
    expect($jusK->ingredients()->whereNotNull('referenced_recipe_id')->value('referenced_recipe_id'))->toBe($this->fondF->id)   // bleibt auf der Fassung (bekommt Updates)
        ->and(DB::table('foodalchemist_recipe_ingredients')->where('id', $this->eigenZeile->id)->value('referenced_recipe_id'))->toBe($jusK->id);   // der Verweis des eigenen Rezepts auf die Kopie
});

it('EK der Kopie (Lisa 1): Fond-Fassung als Zutat rechnet mit den Preisen des Mandanten — vollständig, nie 0 €; ebenso ein eigenes Rezept mit Abo-Fassung als Zutat', function () {
    $k = $this->svc->kuendigen($this->broich, $this->aboS1->id, $this->broichChef, bestaetigt: true)['kopien'];
    $jusK = FoodAlchemistRecipe::ohneTeamSicht('test')->findOrFail($k[$this->jusF->id]);
    // Erwartung aus der Kostenschicht des Mandanten: Fond 500 g à 4 €/kg (Knochen) + Butter 100 g à 10 €/kg
    $erwartetFond = app(KostenCacheService::class)->fuer($this->fondF, $this->broich);
    expect($erwartetFond['vollstaendig'])->toBeTrue()
        ->and((int) $jusK->ek_n_ingredients_priced)->toBe((int) $jusK->ek_n_ingredients_total)             // vollständig bepreist
        ->and((float) $jusK->ek_total_eur)->toBeGreaterThan(0.0)
        ->and((float) $jusK->ek_total_eur)->toBeGreaterThan(1.0);                                          // Butter 1 € + Fond-Anteil, nicht nur die Butter

    // der Fall „Zutat“ gab es schon vor PR 6b: eigenes Rezept mit einer Abo-Fassung als Zutat
    app(RecipeRecomputeService::class)->recomputePipeline($this->eigen->id);
    $eigen = $this->eigen->refresh();
    expect((int) $eigen->ek_n_ingredients_priced)->toBe((int) $eigen->ek_n_ingredients_total)->and((float) $eigen->ek_total_eur)->toBeGreaterThan(0.0);
});

it('Ersatz-Kanten (Lisa 2): die Kante Jus↔Fond geht bei der Kopie nicht verloren — die nicht kopierte Fassung bildet sich auf sich selbst ab', function () {
    $k = $this->svc->kuendigen($this->broich, $this->aboS1->id, $this->broichChef, bestaetigt: true)['kopien'];
    $kante = FoodAlchemistComponentEquivalent::where('team_id', $this->broich->id)->where('source_kind', 'recipe')->where('alt_kind', 'recipe')->get();
    expect($kante)->toHaveCount(1)->and((int) $kante->first()->source_id)->toBe($k[$this->jusF->id])->and((int) $kante->first()->alt_id)->toBe($this->fondF->id);
});

it('zweistufig (Lisa 4): erst Abo A (Fond bleibt Fassung), dann Abo B → jetzt wird der Fond zur Kopie, die Kopie-Jus zeigt auf die Fond-Kopie', function () {
    $a = $this->svc->kuendigen($this->broich, $this->aboS1->id, $this->broichChef, bestaetigt: true)['kopien'];
    $jusK = FoodAlchemistRecipe::ohneTeamSicht('test')->findOrFail($a[$this->jusF->id]);
    expect($jusK->ingredients()->whereNotNull('referenced_recipe_id')->value('referenced_recipe_id'))->toBe($this->fondF->id);

    $vor = $this->svc->kuendigen($this->broich, $this->aboS2->id, $this->broichChef, bestaetigt: false)['vorschau'];
    expect($vor['genutzt_ids'])->toBe([$this->fondF->id])                      // „Zutat“ im eigenen (kopierten) Jus
        ->and($vor['verschwindet_ids'])->toBe([$this->demiF->id])
        ->and($vor['bleibt'])->toBe([]);

    $b = $this->svc->kuendigen($this->broich, $this->aboS2->id, $this->broichChef, bestaetigt: true)['kopien'];
    $fondK = FoodAlchemistRecipe::ohneTeamSicht('test')->findOrFail($b[$this->fondF->id]);
    expect((bool) $fondK->ist_fassung)->toBeFalse()->and((int) $fondK->team_id)->toBe((int) $this->broich->id)
        ->and($jusK->ingredients()->whereNotNull('referenced_recipe_id')->value('referenced_recipe_id'))->toBe($fondK->id)   // jetzt auf der Fond-Kopie
        ->and((float) $jusK->refresh()->ek_total_eur)->toBeGreaterThan(1.0)->and((int) $jusK->ek_n_ingredients_priced)->toBe((int) $jusK->ek_n_ingredients_total);
});

it('Sichtbarkeit je FAMILIE (Lisa 3): hält eine echte Allergen-Sperre ein eigenes Rezept auf der älteren Fond-Fassung, bleibt es dort — keine Kopie, solange die Familie sichtbar ist', function () {
    $f2Gp = $this->milchGp;
    // eigenes Rezept auf Fond-Fassung 1
    $sauce = $this->makeRecipe($this->broich, 'Broich Sauce');
    $zeile = $this->makeIngredient($sauce, 'Fond', null, '200');
    $zeile->update(['referenced_recipe_id' => $this->fondF->id]);
    // Fond 2 mit Sahne (Milch) → echte Allergen-Änderung; Demi (S2) nimmt Fond 2 mit, Jus (S1) bleibt auf Fond 1
    $this->makeIngredient($this->fond, 'Sahne', $f2Gp, '100', 2);
    app(RecipeRecomputeService::class)->recomputePipeline($this->fond->id);
    $fond2 = FoodAlchemistRecipe::mitFassungen()->findOrFail($this->fassungen->veroeffentlichen($this->master, $this->fond->id, 'Mit Sahne', $this->kurator)['fassung_id']);
    $this->demi->update(['name' => 'Demi-glace 2']);
    $this->fassungen->veroeffentlichen($this->master, $this->demi->id, 'Fond 2', $this->kurator);
    expect((bool) $fond2->fassung_allergen_geaendert)->toBeTrue()
        ->and(DB::table('foodalchemist_recipe_ingredients')->where('id', $zeile->id)->value('referenced_recipe_id'))->toBe($this->fondF->id);   // echte Sperre hält

    $v = $this->svc->kuendigen($this->broich, $this->aboS1->id, $this->broichChef, bestaetigt: false)['vorschau'];
    expect($v['bleibt_ids'])->toContain($this->fondF->id)                              // Fond 1: Familie bleibt über „Saucen“ sichtbar
        ->and($v['genutzt_ids'])->not->toContain($this->fondF->id);
    $k = $this->svc->kuendigen($this->broich, $this->aboS1->id, $this->broichChef, bestaetigt: true)['kopien'];
    expect($k)->not->toHaveKey($this->fondF->id)
        ->and(DB::table('foodalchemist_recipe_ingredients')->where('id', $zeile->id)->value('referenced_recipe_id'))->toBe($this->fondF->id);   // bleibt auf Fassung 1
});

it('Paket: über ein zweites Abo sichtbares Paket wird NICHT kopiert und nicht umgehängt, die Konzept-Kopie zeigt auf die Paket-Fassung', function () {
    ($this->soulAbos)();
    $p1 = FoodAlchemistPaket::mitFassungen()->where('fassung_von_id', $this->paket->id)->where('ist_fassung', true)->value('id');
    $r = $this->svc->kuendigen($this->soul, $this->aboS3->id, $this->soulChef, bestaetigt: false);
    $v = $r['vorschau'];
    expect($v['genutzt_je_art']['concept'])->toBe([$this->k1->id])
        ->and($v['bleibt_je_art']['paket'])->toBe([$p1])
        ->and($v['bleibt_je_art']['recipe'])->toContain($this->jusF->id)           // das Paket trägt Jus und Fond mit
        ->and(collect($v['bleibt'])->firstWhere('typ', 'paket')['via'])->toBe(['Pakete'])
        ->and($v['mitkopiert_je_art']['paket'])->toBe([]);

    $k = $this->svc->kuendigen($this->soul, $this->aboS3->id, $this->soulChef, bestaetigt: true)['kopien_je_art'];
    $konzeptK = FoodAlchemistConcept::ohneTeamSicht('test')->findOrFail($k['concept'][$this->k1->id]);
    expect($k['paket'])->toBe([])->and($k['recipe'])->toBe([])
        ->and($konzeptK->slots()->whereNotNull('package_id')->value('package_id'))->toBe($p1)                                  // zeigt auf die Fassung (Updates)
        ->and(DB::table('foodalchemist_concept_slots')->where('id', $this->soulSlot)->value('package_id'))->toBe($p1)          // eigener Verweis unverändert
        ->and(FoodAlchemistConcept::ohneTeamSicht('test')->find($konzeptK->id)->ist_fassung)->toBeFalse();
});

it('Bilder (Entscheid 1): Titelbild und Galerie werden in den Speicher des Mandanten kopiert', function () {
    ($this->soulAbos)('titel.jpg');
    expect($this->k1->image_context_file_id)->not->toBeNull();
    $r = $this->svc->kuendigen($this->soul, $this->aboS3->id, $this->soulChef, bestaetigt: true);
    $k = FoodAlchemistConcept::ohneTeamSicht('test')->findOrFail($r['kopien_je_art']['concept'][$this->k1->id]);
    $datei = ContextFile::find($k->image_context_file_id);

    expect($k->image_context_file_id)->not->toBe($this->k1->image_context_file_id)->and($datei)->not->toBeNull()
        ->and((int) $datei->team_id)->toBe((int) $this->soul->id)->and(Storage::disk($datei->disk)->exists($datei->path))->toBeTrue()
        ->and($k->images()->count())->toBe(1)
        ->and($r['hinweise'])->toBe([]);
    $galerie = $k->images()->first();
    expect($galerie->context_file_id)->not->toBe($this->k1->images()->first()->context_file_id)
        ->and(Storage::disk(ContextFile::find($galerie->context_file_id)->disk)->exists(ContextFile::find($galerie->context_file_id)->path))->toBeTrue();
});

it('Bilder (Entscheid 1): fehlt die Datei, bleibt die Kopie ohne Bild, mit Hinweis — die Kündigung bricht nie ab', function () {
    ($this->soulAbos)('titel.jpg');
    $quelle = ContextFile::findOrFail($this->k1->image_context_file_id);
    Storage::disk($quelle->disk)->delete($quelle->path);
    $galerieDatei = ContextFile::findOrFail($this->k1->images()->first()->context_file_id);
    Storage::disk($galerieDatei->disk)->delete($galerieDatei->path);

    $r = $this->svc->kuendigen($this->soul, $this->aboS3->id, $this->soulChef, bestaetigt: true);
    $k = FoodAlchemistConcept::ohneTeamSicht('test')->findOrFail($r['kopien_je_art']['concept'][$this->k1->id]);
    expect($r['ausgefuehrt'])->toBeTrue()->and(FoodAlchemistAbo::find($this->aboS3->id)->status)->toBe(FoodAlchemistAbo::GEKUENDIGT)
        ->and($k->image_path)->toBeNull()->and($k->image_context_file_id)->toBeNull()->and($k->images()->count())->toBe(0)
        ->and(implode(' ', $r['hinweise']))->toContain('Bilddatei')->and($r['hinweise'])->toHaveCount(2);
});

it('Eigene Kopie (kopieAnlegen) nimmt das Bild mit; fehlt die Datei, Kopie ohne Bild + Hinweis', function () {
    ($this->soulAbos)('titel.jpg');
    $this->actingAs($this->soulChef);
    $svc = app(InhaltsFreigabeService::class);
    $kopie = $svc->kopieAnlegen($this->soul, 'concept', $this->k1->id, $this->soulChef);
    expect($kopie->image_context_file_id)->not->toBeNull()->and($kopie->image_context_file_id)->not->toBe($this->k1->image_context_file_id)
        ->and($svc->letzteHinweise)->toBe([]);

    $quelle = ContextFile::findOrFail($this->k1->image_context_file_id);
    Storage::disk($quelle->disk)->delete($quelle->path);
    $zweite = $svc->kopieAnlegen($this->soul, 'concept', $this->k1->id, $this->soulChef);
    expect($zweite->image_path)->toBeNull()->and(implode(' ', $svc->letzteHinweise))->toContain('Bilddatei');
});

it('Bilder: auch ein Fehler beim Schreiben ins Mandanten-Speicher bricht die Kündigung nicht ab (Kopie ohne Bild + Hinweis)', function () {
    ($this->soulAbos)('titel.jpg');
    $this->app->bind(\Platform\FoodAlchemist\Services\FoodAlchemistMediaService::class, fn () => new class(app(\Platform\Core\Services\ContextFileService::class)) extends \Platform\FoodAlchemist\Services\FoodAlchemistMediaService {
        public function storeImage(\Illuminate\Http\UploadedFile $file, \Platform\Core\Models\Team $team, string $contextType, int $contextId, string $folder): array
        {
            throw new \RuntimeException('Speicher voll');
        }
    });
    $r = $this->svc->kuendigen($this->soul, $this->aboS3->id, $this->soulChef, bestaetigt: true);
    $k = FoodAlchemistConcept::ohneTeamSicht('test')->findOrFail($r['kopien_je_art']['concept'][$this->k1->id]);
    expect($r['ausgefuehrt'])->toBeTrue()->and($k->image_path)->toBeNull()->and(implode(' ', $r['hinweise']))->toContain('Bilddatei');
});
