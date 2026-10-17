<?php

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Models\ContextFile;
use Platform\Core\Models\Team;
use Platform\Core\Tools\ToolRegistry;
use Platform\FoodAlchemist\Enums\SignalStatus;
use Platform\FoodAlchemist\Enums\SignalTyp;
use Platform\FoodAlchemist\Models\FoodAlchemistAngebotEinfrierung;
use Platform\FoodAlchemist\Models\FoodAlchemistConcept;
use Platform\FoodAlchemist\Models\FoodAlchemistFoodbook;
use Platform\FoodAlchemist\Models\FoodAlchemistFoodbookKapitel;
use Platform\FoodAlchemist\Models\FoodAlchemistFormat;
use Platform\FoodAlchemist\Models\FoodAlchemistFormatSlot;
use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Models\FoodAlchemistOfferChapter;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Models\FoodAlchemistSignal;
use Platform\FoodAlchemist\Models\FoodAlchemistSpeisekarte;
use Platform\FoodAlchemist\Models\FoodAlchemistSpeisekarteRubrik;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplier;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItemStructure;
use Platform\FoodAlchemist\Models\FoodAlchemistVocabEinheit;
use Platform\FoodAlchemist\Services\AboKuendigungService;
use Platform\FoodAlchemist\Services\AboService;
use Platform\FoodAlchemist\Services\AngebotService;
use Platform\FoodAlchemist\Services\BausteinFolgeService;
use Platform\FoodAlchemist\Services\ConceptFassungService;
use Platform\FoodAlchemist\Services\ConceptPreisService;
use Platform\FoodAlchemist\Services\ConceptService;
use Platform\FoodAlchemist\Services\FassungService;
use Platform\FoodAlchemist\Services\FormatFassungService;
use Platform\FoodAlchemist\Services\FormatService;
use Platform\FoodAlchemist\Services\InhaltsFreigabeService;
use Platform\FoodAlchemist\Services\PriceService;
use Platform\FoodAlchemist\Services\RecipeRecomputeService;
use Platform\FoodAlchemist\Support\StammPaket;
use Platform\FoodAlchemist\Support\TeamAncestryRegistry;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 82 Abo Konzepte/Pakete/Formate, PR 7: das Format als Abo-Objekt — Fassung ohne Kosten, Abo-Zweig, D8-Sperre (origin=kunde nie), Signale/Folgen inkl. Kapitel-
 * Verweise (`format_id` an Foodbook-Kapitel, Angebots-Kapitel, Speisekarten-Rubrik), Kündigung inkl. Bild-Kopie und „bleibt über anderes Abo“.
 */
beforeEach(function () {
    Storage::fake('public');
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
    $this->actingAs($this->kurator);

    $sup = FoodAlchemistSupplier::create(['team_id' => null, 'name' => 'Stamm-Großhandel']);
    $mk = function (string $name, array $extra = []) use ($sup) {
        $gp = FoodAlchemistGp::create(['team_id' => null, 'gp_key' => 'ff|' . $name, 'name' => $name] + $extra);
        $la = FoodAlchemistSupplierItem::create(['team_id' => null, 'supplier_id' => $sup->id, 'designation' => $name . ' 1 kg', 'qty' => 1.0, 'unit_code' => 'kg']);
        FoodAlchemistSupplierItemStructure::create(['team_id' => null, 'supplier_item_id' => $la->id, 'gp_id' => $gp->id]);
        $gp->update(['lead_la_supplier_item_id' => $la->id]);

        return [$gp->refresh(), $la];
    };
    [$mehl, $laMehl] = $mk('Mehl');
    [$this->nuss, $laNuss] = $mk('Haselnuss', ['allergen_tree_nuts' => 'enthalten']);
    $pr = app(PriceService::class);
    $pr->createTeamPrice($this->broich, $laMehl, 3.00);
    $pr->createTeamPrice($this->broich, $laNuss, 12.00);
    $pr->createTeamPrice($this->neu, $laNuss, 12.00);   // Neukunde: Mehl ohne Preis → unvollständig

    $this->portion = FoodAlchemistVocabEinheit::create(['team_id' => $this->master->id, 'slug' => 'portion', 'display_de' => 'Portion', 'dimension' => 'count', 'default_in_g' => null]);
    $this->gericht = $this->makeRecipe($this->master, 'Spätzle', ['is_sales_recipe' => true]);
    $this->makeIngredient($this->gericht, 'Mehl', $mehl, '300');
    app(RecipeRecomputeService::class)->recomputePipeline($this->gericht->id);
    $this->gericht2 = $this->makeRecipe($this->master, 'Knödel', ['is_sales_recipe' => true]);
    $this->makeIngredient($this->gericht2, 'Mehl', $mehl, '250');
    app(RecipeRecomputeService::class)->recomputePipeline($this->gericht2->id);

    $teller = \Platform\FoodAlchemist\Models\FoodAlchemistServierform::create(['team_id' => $this->master->id, 'code' => 'teller', 'label' => 'Teller']);
    foreach ([$this->gericht, $this->gericht2] as $g) {
        app(\Platform\FoodAlchemist\Services\DarreichungService::class)->anlegen($this->master, $g->id, $teller->id, ['quantity_per_unit_g' => 200]);   // ohne Darreichung kein VK
    }
    $cs = app(ConceptService::class);
    $mkKonzept = function (string $name, $gericht) use ($cs) {
        $c = $cs->create($this->master, ['name' => $name]);
        $s = $cs->addSlot($this->master, $c->id, ['role' => 'Hauptgang']);
        $cs->fillSlot($this->master, $s->id, ['sales_recipe_id' => $gericht->id, 'quantity' => 1, 'unit_vocab_id' => $this->portion->id]);

        return $c;
    };
    $this->konzept1 = $mkKonzept('Schwaben-Menü', $this->gericht);
    $this->konzept2 = $mkKonzept('Bayern-Menü', $this->gericht2);

    $this->formatSvc = app(FormatService::class);
    $this->format = $this->formatSvc->create($this->master, ['name' => 'Wirtshaus-Format', 'origin' => 'eigen']);
    $this->s1 = $this->formatSvc->slotConceptEinfuegen($this->master, $this->format->id, $this->konzept1->id);
    $this->s2 = $this->formatSvc->slotConceptEinfuegen($this->master, $this->format->id, $this->konzept2->id);
    $this->formatSvc->slotBlockEinfuegen($this->master, $this->format->id, 'header', ['title' => 'Unsere Menüs']);
    $konzeptSlot = DB::table('foodalchemist_concept_slots')->where('concept_id', $this->konzept1->id)->value('id');
    $this->formatSvc->setSlotWording($this->master, $this->s1->id, (int) $konzeptSlot, 'Hausgemachte Spätzle');
    $this->formatSvc->storeImage($this->master, $this->format->id, UploadedFile::fake()->image('hero.jpg', 400, 200), 'Hero');

    $this->fRezept = app(FassungService::class);
    $this->fKonzept = app(ConceptFassungService::class);
    $this->fFormat = app(FormatFassungService::class);
    $this->veroeffentlicheKonzepte = function () {
        $this->fRezept->veroeffentlichen($this->master, $this->gericht->id, 'Erst', $this->kurator);
        $this->fRezept->veroeffentlichen($this->master, $this->gericht2->id, 'Erst', $this->kurator);
        $this->k1 = FoodAlchemistConcept::mitFassungen()->findOrFail($this->fKonzept->veroeffentlichen($this->master, $this->konzept1->id, 'Erst', $this->kurator)['fassung_id']);
        $this->k2 = FoodAlchemistConcept::mitFassungen()->findOrFail($this->fKonzept->veroeffentlichen($this->master, $this->konzept2->id, 'Erst', $this->kurator)['fassung_id']);
    };
    $this->inhalte = app(InhaltsFreigabeService::class);
    $this->abos = app(AboService::class);
    $this->svc = app(AboKuendigungService::class);
    $this->folge = app(BausteinFolgeService::class);
    $this->reg = app(ToolRegistry::class);
    $this->abo = function (array $mandanten = null, string $name = 'Formate') {
        $sid = $this->inhalte->sammlungAnlegen($this->master, $name, null, $this->kurator);
        $this->inhalte->sammlungHinzu($this->master, $sid, 'format', [$this->format->id], $this->kurator);
        $out = [];
        foreach ($mandanten ?? [$this->broich] as $m) {
            $out[] = $this->abos->abonnieren($this->master, $m, $sid, $this->kurator);
        }

        return [$sid, $out];
    };
});

it('D2 · ohne veröffentlichte Konzept-Fassung bricht das Veröffentlichen mit der Liste ab', function () {
    expect(fn () => $this->fFormat->veroeffentlichen($this->master, $this->format->id, 'Erst', $this->kurator))->toThrow(\RuntimeException::class, 'Schwaben-Menü');
    expect(FoodAlchemistFormat::mitFassungen()->where('ist_fassung', true)->count())->toBe(0);
});

it('Veröffentlichen: Slots zeigen auf Konzept-Fassungen, Struktur-Blöcke bleiben, Wording-Overrides sind auf die Slots der Fassung umgeschlüsselt, Bilder bleiben Verweise', function () {
    ($this->veroeffentlicheKonzepte)();
    $r = $this->fFormat->veroeffentlichen($this->master, $this->format->id, 'Format erstmals', $this->kurator);
    $f = FoodAlchemistFormat::mitFassungen()->findOrFail($r['fassung_id']);
    $slots = $f->slots()->orderBy('position')->get();

    expect($r['neu'])->toBeTrue()->and($r['fassung_nr'])->toBe(1)->and((bool) $f->ist_fassung)->toBeTrue()->and((int) $f->fassung_von_id)->toBe($this->format->id)
        ->and($f->aenderungsnotiz)->toBe('Format erstmals')->and($f->origin)->toBe('eigen')
        ->and($slots->where('type', 'concept')->pluck('concept_id')->map(fn ($v) => (int) $v)->all())->toBe([$this->k1->id, $this->k2->id])
        ->and($slots->where('type', 'header')->count())->toBe(1)
        ->and((bool) $this->format->refresh()->ist_fassung)->toBeFalse();
    // Wording-Override hängt am Slot der Konzept-FASSUNG, nicht am Slot der Arbeitszeile
    $konzeptFassungsSlot = (int) DB::table('foodalchemist_concept_slots')->where('concept_id', $this->k1->id)->value('id');
    expect(array_map('strval', array_keys($slots->firstWhere('concept_id', $this->k1->id)->payload_json['wording_overrides'])))->toBe([(string) $konzeptFassungsSlot])
        ->and($slots->firstWhere('concept_id', $this->k1->id)->payload_json['wording_overrides'][(string) $konzeptFassungsSlot])->toBe('Hausgemachte Spätzle');
    // Bild = Verweis auf die Master-Datei
    expect($f->images()->count())->toBe(1)->and($f->images()->first()->context_file_id)->toBe($this->format->images()->first()->context_file_id);
});

it('gleicher Inhalt = keine neue Fassung, eine Änderung die nächste Nummer', function () {
    ($this->veroeffentlicheKonzepte)();
    $a = $this->fFormat->veroeffentlichen($this->master, $this->format->id, 'Erst', $this->kurator);
    $b = $this->fFormat->veroeffentlichen($this->master, $this->format->id, 'Nochmal', $this->kurator);
    expect($b['neu'])->toBeFalse()->and($b['fassung_id'])->toBe($a['fassung_id']);
    $this->format->update(['name' => 'Wirtshaus-Format 2']);
    expect($this->fFormat->veroeffentlichen($this->master, $this->format->id, 'Umbenannt', $this->kurator)['fassung_nr'])->toBe(2);
});

it('D8 · ein Kunden-Format (origin=kunde) ist nie veröffentlichbar, nie in einer abonnierten Sammlung, nie in der Hülle', function () {
    ($this->veroeffentlicheKonzepte)();
    $this->fFormat->veroeffentlichen($this->master, $this->format->id, 'Erst', $this->kurator);
    [$sid] = ($this->abo)();

    // Veröffentlichen
    $kunde = $this->formatSvc->create($this->master, ['name' => 'Kunden-Format', 'origin' => 'kunde', 'customer' => 'Broich']);
    expect(fn () => $this->fFormat->veroeffentlichen($this->master, $kunde->id, 'x', $this->kurator))->toThrow(\RuntimeException::class, 'Kunden-IP');
    // in eine ABONNIERTE Sammlung legen
    expect(fn () => $this->inhalte->sammlungHinzu($this->master, $sid, 'format', [$kunde->id], $this->kurator))->toThrow(\RuntimeException::class, 'Kunden-IP');
    // Sammlung mit Kunden-Format abonnieren
    $sid2 = $this->inhalte->sammlungAnlegen($this->master, 'Mit Kunde', null, $this->kurator);
    $this->inhalte->sammlungHinzu($this->master, $sid2, 'format', [$kunde->id], $this->kurator);   // ohne Abo erlaubt (77d-Freigabe)
    expect(fn () => $this->abos->abonnieren($this->master, $this->neu, $sid2, $this->kurator))->toThrow(\RuntimeException::class, 'Kunden-IP');
    // Verteidigung in der Hülle: kippt die Herkunft NACH der Veröffentlichung auf „kunde“, verschwindet das Format aus der Hülle
    expect(array_keys($this->abos->huelle((int) $this->broich->id)))->toContain('format');
    $this->format->update(['origin' => 'kunde']);
    expect(array_keys($this->abos->huelle((int) $this->broich->id)))->not->toContain('format');
});

it('Abo: der Mandant sieht Format-Fassung samt Konzept- und Rezept-Fassungen, nie die Arbeitszeilen; ohne Abo nichts', function () {
    ($this->veroeffentlicheKonzepte)();
    ($this->abo)();
    $f = FoodAlchemistFormat::mitFassungen()->findOrFail($this->fFormat->veroeffentlichen($this->master, $this->format->id, 'Erst', $this->kurator)['fassung_id']);

    $sieht = fn ($klasse, Team $t) => $klasse::visibleToTeam($t)->pluck((new $klasse)->getTable() . '.id')->map(fn ($v) => (int) $v)->all();
    expect($sieht(FoodAlchemistFormat::class, $this->broich))->toBe([$f->id])
        ->and($sieht(FoodAlchemistConcept::class, $this->broich))->toContain($this->k1->id)->and($sieht(FoodAlchemistConcept::class, $this->broich))->not->toContain($this->konzept1->id)
        ->and($sieht(FoodAlchemistRecipe::class, $this->broich))->not->toContain($this->gericht->id)
        ->and($sieht(FoodAlchemistFormat::class, $this->neu))->toBe([])
        ->and(array_keys($this->abos->huelle((int) $this->broich->id)))->toEqualCanonicalizing(['recipe', 'concept', 'format']);
});

it('Preis beim Mandanten: Konzepte der Format-Fassung rechnen live mit seinen Preisen (Preisspanne), ohne vollständigen EK kein Preis; das Format selbst trägt keine Preise', function () {
    ($this->veroeffentlicheKonzepte)();
    ($this->abo)([$this->broich, $this->neu]);
    $f = FoodAlchemistFormat::mitFassungen()->findOrFail($this->fFormat->veroeffentlichen($this->master, $this->format->id, 'Erst', $this->kurator)['fassung_id']);

    $b = $this->formatSvc->detail($this->broich, $f->id);
    $erwartet = app(ConceptPreisService::class)->cockpit($this->k1, $this->broich)['price_per_person'];
    expect((float) $b->slots->firstWhere('concept_id', $this->k1->id)->concept->price_per_person_cache)->toBe($erwartet)->and($erwartet)->toBeGreaterThan(0.0)
        ->and($b->priceRange()['min'])->not->toBeNull();
    $n = $this->formatSvc->detail($this->neu, $f->id);
    expect($n->slots->firstWhere('concept_id', $this->k1->id)->concept->price_per_person_cache)->toBeNull()->and($n->priceRange())->toBe(['min' => null, 'max' => null]);
    // die Fassung selbst: keine Preisspalten, nichts gespeichert
    expect(DB::table('foodalchemist_concepts')->where('id', $this->k1->id)->value('price_per_person_cache'))->toBeNull();
});

it('Folgen über Kapitel-Verweise (format_id): Foodbook-Kapitel und Speisekarten-Rubrik folgen der neuen Fassung, archiviert bleibt, eingefrorenes Angebots-Kapitel bleibt (Signal)', function () {
    ($this->veroeffentlicheKonzepte)();
    ($this->abo)();
    $f1 = FoodAlchemistFormat::mitFassungen()->findOrFail($this->fFormat->veroeffentlichen($this->master, $this->format->id, 'Erst', $this->kurator)['fassung_id']);
    $fb = FoodAlchemistFoodbook::create(['team_id' => $this->broich->id, 'label' => 'Sommerkarte']);
    $kap = FoodAlchemistFoodbookKapitel::create(['team_id' => $this->broich->id, 'foodbook_id' => $fb->id, 'title' => 'Formate', 'position' => 0, 'format_id' => $f1->id]);
    $arch = FoodAlchemistFoodbook::create(['team_id' => $this->broich->id, 'label' => 'Archiv', 'status' => 'archiviert']);
    $kapA = FoodAlchemistFoodbookKapitel::create(['team_id' => $this->broich->id, 'foodbook_id' => $arch->id, 'title' => 'X', 'position' => 0, 'format_id' => $f1->id]);
    $karte = FoodAlchemistSpeisekarte::create(['team_id' => $this->broich->id, 'name' => 'Karte', 'code' => 'K1']);
    $rubrik = FoodAlchemistSpeisekarteRubrik::create(['team_id' => $this->broich->id, 'menu_card_id' => $karte->id, 'title' => 'Formate', 'position' => 0, 'format_id' => $f1->id]);
    $angebot = app(AngebotService::class)->create($this->broich, ['name' => 'Eingefroren', 'personen' => 40]);
    DB::table('foodalchemist_offers')->where('id', $angebot->id)->update(['status' => 'angebot']);
    $oc = FoodAlchemistOfferChapter::create(['team_id' => $this->broich->id, 'offer_id' => $angebot->id, 'title' => 'Formate', 'position' => 0, 'format_id' => $f1->id]);
    FoodAlchemistAngebotEinfrierung::create(['team_id' => $this->broich->id, 'angebot_id' => $angebot->id, 'status_beim_einfrieren' => 'angebot', 'eingefroren_at' => now()]);
    $angebot2 = app(AngebotService::class)->create($this->broich, ['name' => 'Entwurf', 'personen' => 40]);
    $oc2 = FoodAlchemistOfferChapter::create(['team_id' => $this->broich->id, 'offer_id' => $angebot2->id, 'title' => 'Formate', 'position' => 0, 'format_id' => $f1->id]);

    $this->format->update(['name' => 'Wirtshaus-Format 2']);
    $f2 = FoodAlchemistFormat::mitFassungen()->findOrFail($this->fFormat->veroeffentlichen($this->master, $this->format->id, 'Umbenannt', $this->kurator)['fassung_id']);

    expect(FoodAlchemistFoodbookKapitel::find($kap->id)->format_id)->toBe($f2->id)
        ->and(FoodAlchemistSpeisekarteRubrik::find($rubrik->id)->format_id)->toBe($f2->id)
        ->and(FoodAlchemistOfferChapter::find($oc2->id)->format_id)->toBe($f2->id)
        ->and(FoodAlchemistFoodbookKapitel::find($kapA->id)->format_id)->toBe($f1->id)       // archiviert
        ->and(FoodAlchemistOfferChapter::find($oc->id)->format_id)->toBe($f1->id);           // eingefroren: bleibt
    $signal = FoodAlchemistSignal::where('team_id', $this->broich->id)->where('type', SignalTyp::FassungNeuerVerfuegbar->value)
        ->where('dedup_key', 'fassung-neu:format:'.$this->format->id)->where('status', SignalStatus::Offen->value)->sole();
    expect($signal->payload['bausteine'])->toHaveCount(1)->and($signal->payload['bausteine'][0]['grund'])->toBe('eingefroren')->and($signal->payload['bausteine'][0]['wo'])->toBe('Angebot');
    // Info-Signal (ein Signal je Familie × Mandant)
    expect(FoodAlchemistSignal::where('team_id', $this->broich->id)->where('type', SignalTyp::FassungAktualisiert->value)->where('dedup_key', 'fassung-aktualisiert:format:'.$this->format->id)->count())->toBe(1);
});

it('Allergen-Erbe über die Kette (Format → Konzept → Rezept): Kapitel bleibt, Signal Kritisch, Übernehmen nur bestätigt und ab Freigeben', function () {
    ($this->veroeffentlicheKonzepte)();
    ($this->abo)();
    $f1 = FoodAlchemistFormat::mitFassungen()->findOrFail($this->fFormat->veroeffentlichen($this->master, $this->format->id, 'Erst', $this->kurator)['fassung_id']);
    $fb = FoodAlchemistFoodbook::create(['team_id' => $this->broich->id, 'label' => 'Sommerkarte']);
    $kap = FoodAlchemistFoodbookKapitel::create(['team_id' => $this->broich->id, 'foodbook_id' => $fb->id, 'title' => 'Formate', 'position' => 0, 'format_id' => $f1->id]);

    // Haselnuss ins Gericht → neue Rezept-Fassung (Schalenfrüchte) → Konzept-Fassung 2 → Format-Fassung 2
    $this->makeIngredient($this->gericht, 'Haselnuss', $this->nuss, '50', 2);
    app(RecipeRecomputeService::class)->recomputePipeline($this->gericht->id);
    $this->fRezept->veroeffentlichen($this->master, $this->gericht->id, 'Mit Nuss', $this->kurator);
    $this->konzept1->update(['name' => 'Schwaben-Menü Nuss']);
    $this->fKonzept->veroeffentlichen($this->master, $this->konzept1->id, 'Nuss', $this->kurator);
    $f2 = FoodAlchemistFormat::mitFassungen()->findOrFail($this->fFormat->veroeffentlichen($this->master, $this->format->id, 'Nuss', $this->kurator)['fassung_id']);

    expect((bool) $f2->fassung_allergen_geaendert)->toBeTrue()->and(FoodAlchemistFoodbookKapitel::find($kap->id)->format_id)->toBe($f1->id);
    $signal = FoodAlchemistSignal::where('team_id', $this->broich->id)->where('type', SignalTyp::FassungNeuerVerfuegbar->value)
        ->where('dedup_key', 'fassung-neu:format:'.$this->format->id)->where('status', SignalStatus::Offen->value)->sole();
    expect($signal->severity->value)->toBe('kritisch')->and(implode(' ', $signal->payload['allergene']))->toContain('Schalenfrüchte');

    $koch = $this->makeUser($this->broich, 'Broich Koch', 'member');
    expect(fn () => $this->folge->uebernehmen($this->broich, $koch, 'format', 'foodbook_kapitel_format', $kap->id, true))->toThrow(\Platform\FoodAlchemist\Exceptions\FaRechtFehltException::class);
    $r = $this->folge->uebernehmen($this->broich, $this->broichChef, 'format', 'foodbook_kapitel_format', $kap->id, true);
    expect($r['umgehaengt'])->toBeTrue()->and(FoodAlchemistFoodbookKapitel::find($kap->id)->format_id)->toBe($f2->id)
        ->and(FoodAlchemistSignal::where('team_id', $this->broich->id)->where('dedup_key', 'fassung-neu:format:'.$this->format->id)->where('status', SignalStatus::Offen->value)->count())->toBe(0);
});

it('Kündigung: Format samt Konzept-Kette als eigene Kopie, Slots zeigen auf Konzept-Kopien, Wording-Overrides umgeschlüsselt, Kapitel-Verweis umgehängt, Bilder mitkopiert', function () {
    ($this->veroeffentlicheKonzepte)();
    [, $abos] = ($this->abo)();
    $f1 = FoodAlchemistFormat::mitFassungen()->findOrFail($this->fFormat->veroeffentlichen($this->master, $this->format->id, 'Erst', $this->kurator)['fassung_id']);
    $fb = FoodAlchemistFoodbook::create(['team_id' => $this->broich->id, 'label' => 'Sommerkarte']);
    $kap = FoodAlchemistFoodbookKapitel::create(['team_id' => $this->broich->id, 'foodbook_id' => $fb->id, 'title' => 'Formate', 'position' => 0, 'format_id' => $f1->id]);

    $v = $this->svc->kuendigen($this->broich, $abos[0]->id, $this->broichChef, bestaetigt: false)['vorschau'];
    expect($v['genutzt_je_art']['format'])->toBe([$f1->id])->and(collect($v['genutzt'])->firstWhere('typ', 'format')['wo'])->toContain('foodbook')
        ->and($v['mitkopiert_je_art']['concept'])->toEqualCanonicalizing([$this->k1->id, $this->k2->id]);

    $r = $this->svc->kuendigen($this->broich, $abos[0]->id, $this->broichChef, bestaetigt: true);
    $k = $r['kopien_je_art'];
    $formatK = FoodAlchemistFormat::ohneTeamSicht('test')->findOrFail($k['format'][$f1->id]);
    expect((bool) $formatK->ist_fassung)->toBeFalse()->and((int) $formatK->team_id)->toBe((int) $this->broich->id)->and((int) $formatK->kopie_von_id)->toBe($this->format->id)
        ->and($k['concept'])->toHaveCount(2)->and($r['hinweise'])->toBe([])
        ->and(FoodAlchemistFoodbookKapitel::find($kap->id)->format_id)->toBe($formatK->id);
    $slotK1 = $formatK->slots()->where('type', 'concept')->get()->firstWhere('concept_id', $k['concept'][$this->k1->id]);
    expect($slotK1)->not->toBeNull();
    $konzeptKopieSlot = (int) DB::table('foodalchemist_concept_slots')->where('concept_id', $k['concept'][$this->k1->id])->value('id');
    expect(array_map('strval', array_keys($slotK1->payload_json['wording_overrides'])))->toBe([(string) $konzeptKopieSlot]);   // auf den Slot der Konzept-KOPIE umgeschlüsselt
    // Bild in den Speicher des Mandanten
    $bild = $formatK->images()->first();
    $datei = ContextFile::find($bild->context_file_id);
    expect($bild->context_file_id)->not->toBe($f1->images()->first()->context_file_id)->and((int) $datei->team_id)->toBe((int) $this->broich->id)
        ->and(Storage::disk($datei->disk)->exists($datei->path))->toBeTrue();
});

it('Kündigung: fehlt die Format-Bilddatei, Kopie ohne dieses Bild + Hinweis, nie ein Abbruch', function () {
    ($this->veroeffentlicheKonzepte)();
    [, $abos] = ($this->abo)();
    $f1 = FoodAlchemistFormat::mitFassungen()->findOrFail($this->fFormat->veroeffentlichen($this->master, $this->format->id, 'Erst', $this->kurator)['fassung_id']);
    $fb = FoodAlchemistFoodbook::create(['team_id' => $this->broich->id, 'label' => 'Sommerkarte']);
    FoodAlchemistFoodbookKapitel::create(['team_id' => $this->broich->id, 'foodbook_id' => $fb->id, 'title' => 'Formate', 'position' => 0, 'format_id' => $f1->id]);
    $datei = ContextFile::findOrFail($f1->images()->first()->context_file_id);
    Storage::disk($datei->disk)->delete($datei->path);

    $r = $this->svc->kuendigen($this->broich, $abos[0]->id, $this->broichChef, bestaetigt: true);
    $formatK = FoodAlchemistFormat::ohneTeamSicht('test')->findOrFail($r['kopien_je_art']['format'][$f1->id]);
    expect($r['ausgefuehrt'])->toBeTrue()->and($formatK->images()->count())->toBe(0)->and(implode(' ', $r['hinweise']))->toContain('Bilddatei');
});

it('Zweites Abo: ein Format, das über ein anderes Abo sichtbar bleibt, wird nicht kopiert — Kapitel-Verweis bleibt auf der Fassung; Zurückziehen mit typ=format', function () {
    ($this->veroeffentlicheKonzepte)();
    [, $a1] = ($this->abo)([$this->broich], 'Formate 1');
    [$sid2, ] = ($this->abo)([$this->broich], 'Formate 2');
    $f1 = FoodAlchemistFormat::mitFassungen()->findOrFail($this->fFormat->veroeffentlichen($this->master, $this->format->id, 'Erst', $this->kurator)['fassung_id']);
    $fb = FoodAlchemistFoodbook::create(['team_id' => $this->broich->id, 'label' => 'Sommerkarte']);
    $kap = FoodAlchemistFoodbookKapitel::create(['team_id' => $this->broich->id, 'foodbook_id' => $fb->id, 'title' => 'Formate', 'position' => 0, 'format_id' => $f1->id]);

    $r = $this->svc->kuendigen($this->broich, $a1[0]->id, $this->broichChef, bestaetigt: true);
    // das Format steht über Abo 2 weiter in der Hülle → es fällt nicht weg: keine Kopie, nichts verschwindet, der Kapitel-Verweis bleibt auf der Fassung (bekommt Updates)
    expect($r['kopien_je_art']['format'])->toBe([])->and($r['vorschau']['genutzt_je_art']['format'])->toBe([])->and($r['vorschau']['verschwindet_je_art']['format'])->toBe([])
        ->and(FoodAlchemistFoodbookKapitel::find($kap->id)->format_id)->toBe($f1->id);

    // Zurückziehen (typ=format) aus der letzten Sammlung → jetzt wird kopiert
    $z = $this->svc->zurueckziehen($this->master, $sid2, $this->format->id, $this->kurator, true, 'format');
    $kopie = $z['mandanten'][0]['kopien_je_art']['format'];
    expect($kopie)->toHaveKey($f1->id)->and(FoodAlchemistFoodbookKapitel::find($kap->id)->format_id)->toBe($kopie[$f1->id])
        ->and(DB::table('foodalchemist_sammlung_objekte')->where('sammlung_id', $sid2)->where('typ', 'format')->count())->toBe(0);
});

it('Löschsperre: ein abonniertes Format lässt sich nicht löschen, nur zurückziehen', function () {
    ($this->veroeffentlicheKonzepte)();
    ($this->abo)();
    expect(fn () => $this->formatSvc->delete($this->master, $this->format->id))->toThrow(\RuntimeException::class, 'zurückziehen');
});

it('Eigene Kopie einer Format-Fassung: normale Arbeitszeile des Teams, Bild mit, Hinweis bei fehlender Datei', function () {
    ($this->veroeffentlicheKonzepte)();
    ($this->abo)();
    $f1 = FoodAlchemistFormat::mitFassungen()->findOrFail($this->fFormat->veroeffentlichen($this->master, $this->format->id, 'Erst', $this->kurator)['fassung_id']);
    $this->actingAs($this->broichChef);
    $svc = app(InhaltsFreigabeService::class);
    $kopie = $svc->kopieAnlegen($this->broich, 'format', $f1->id, $this->broichChef);
    expect((bool) $kopie->ist_fassung)->toBeFalse()->and((int) $kopie->team_id)->toBe((int) $this->broich->id)->and((int) $kopie->kopie_von_id)->toBe($f1->id)
        ->and($kopie->fassung_von_id)->toBeNull()->and($kopie->images()->count())->toBe(1)->and($svc->letzteHinweise)->toBe([]);
    $datei = ContextFile::findOrFail($f1->images()->first()->context_file_id);
    Storage::disk($datei->disk)->delete($datei->path);
    $zweite = $svc->kopieAnlegen($this->broich, 'format', $f1->id, $this->broichChef);
    expect($zweite->images()->count())->toBe(0)->and(implode(' ', $svc->letzteHinweise))->toContain('Bilddatei');
});

it('MCP: format_fassungen.PUBLISH und .GET', function () {
    ($this->veroeffentlicheKonzepte)();
    $ctx = new ToolContext($this->kurator, $this->master);
    $pub = $this->reg->get('foodalchemist.format_fassungen.PUBLISH')->execute(['format_id' => $this->format->id, 'notiz' => 'Erst'], $ctx);
    expect($pub->success)->toBeTrue((string) ($pub->error ?? ''));
    $get = $this->reg->get('foodalchemist.format_fassungen.GET')->execute(['format_id' => $this->format->id], $ctx);
    expect($get->success)->toBeTrue((string) ($get->error ?? ''))->and(json_encode($get->data))->toContain('Erst');
});

it('Rechte: nur Master und nur ab Freigeben; Notiz Pflicht', function () {
    ($this->veroeffentlicheKonzepte)();
    expect(fn () => $this->fFormat->veroeffentlichen($this->master, $this->format->id, 'x', $this->masterKoch))->toThrow(\RuntimeException::class)
        ->and(fn () => $this->fFormat->veroeffentlichen($this->broich, $this->format->id, 'x', $this->broichChef))->toThrow(\RuntimeException::class)
        ->and(fn () => $this->fFormat->veroeffentlichen($this->master, $this->format->id, ' ', $this->kurator))->toThrow(\RuntimeException::class, 'Änderungsnotiz');
});
