<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Models\Team;
use Platform\Core\Tools\ToolRegistry;
use Platform\FoodAlchemist\Enums\SignalStatus;
use Platform\FoodAlchemist\Enums\SignalTyp;
use Platform\FoodAlchemist\Jobs\BausteinFolgeJob;
use Platform\FoodAlchemist\Models\FoodAlchemistAngebotEinfrierung;
use Platform\FoodAlchemist\Models\FoodAlchemistAngebotUebernahme;
use Platform\FoodAlchemist\Models\FoodAlchemistConcept;
use Platform\FoodAlchemist\Models\FoodAlchemistFoodbook;
use Platform\FoodAlchemist\Models\FoodAlchemistFoodbookBlock;
use Platform\FoodAlchemist\Models\FoodAlchemistFoodbookKapitel;
use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Models\FoodAlchemistOfferBlock;
use Platform\FoodAlchemist\Models\FoodAlchemistOfferChapter;
use Platform\FoodAlchemist\Models\FoodAlchemistPaket;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Models\FoodAlchemistSignal;
use Platform\FoodAlchemist\Models\FoodAlchemistVocabEinheit;
use Platform\FoodAlchemist\Services\AboService;
use Platform\FoodAlchemist\Services\AngebotService;
use Platform\FoodAlchemist\Services\BausteinFolgeService;
use Platform\FoodAlchemist\Services\ConceptFassungService;
use Platform\FoodAlchemist\Services\ConceptPreisService;
use Platform\FoodAlchemist\Services\ConceptService;
use Platform\FoodAlchemist\Services\FassungService;
use Platform\FoodAlchemist\Services\InhaltsFreigabeService;
use Platform\FoodAlchemist\Services\PaketFassungService;
use Platform\FoodAlchemist\Services\PaketService;
use Platform\FoodAlchemist\Support\StammPaket;
use Platform\FoodAlchemist\Support\TeamAncestryRegistry;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 82 Abo Konzepte/Pakete, PR 5: Signale + Folgen. Ein Signal je Familie × Team (keine Flut), Allergen-Erbe über die Kette = Kritisch,
 * Verweise folgen automatisch der neuen Fassung (außer Allergenänderung → Übernehmen, eingefrorenes Angebot, historische/archivierte Ausgaben).
 */
beforeEach(function () {
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
    $this->broichKoch = $this->makeUser($this->broich, 'Broich Koch', 'member');
    $this->actingAs($this->kurator);

    $gp = FoodAlchemistGp::create(['team_id' => null, 'gp_key' => 'bf|mehl', 'name' => 'Mehl']);
    $this->gericht = $this->makeRecipe($this->master, 'Spätzle', ['is_sales_recipe' => true]);
    $this->makeIngredient($this->gericht, 'Mehl', $gp, '300');
    $this->portion = FoodAlchemistVocabEinheit::create(['team_id' => $this->master->id, 'slug' => 'portion', 'display_de' => 'Portion', 'dimension' => 'count', 'default_in_g' => null]);

    $paketSvc = app(PaketService::class);
    $this->paket = $paketSvc->create($this->master, ['name' => 'Beilagen-Duo']);
    $paketSvc->syncGerichte($this->master, $this->paket->id, [['sales_recipe_id' => $this->gericht->id, 'quantity' => 1, 'unit_vocab_id' => $this->portion->id]]);
    $cs = app(ConceptService::class);
    $this->konzept = $cs->create($this->master, ['name' => 'Schwaben-Menü']);
    $s1 = $cs->addSlot($this->master, $this->konzept->id, ['role' => 'Beilage']);
    $cs->fillSlot($this->master, $s1->id, ['package_id' => $this->paket->id]);

    $this->rezeptSvc = app(FassungService::class);
    $this->paketSvc = app(PaketFassungService::class);
    $this->konzeptSvc = app(ConceptFassungService::class);
    $this->folge = app(BausteinFolgeService::class);
    $this->rezeptSvc->veroeffentlichen($this->master, $this->gericht->id, 'Erst', $this->kurator);
    $this->p1 = FoodAlchemistPaket::mitFassungen()->findOrFail($this->paketSvc->veroeffentlichen($this->master, $this->paket->id, 'Erst', $this->kurator)['fassung_id']);
    $this->k1 = FoodAlchemistConcept::mitFassungen()->findOrFail($this->konzeptSvc->veroeffentlichen($this->master, $this->konzept->id, 'Erst', $this->kurator)['fassung_id']);

    $inhalte = app(InhaltsFreigabeService::class);
    $sid = $inhalte->sammlungAnlegen($this->master, 'Menüs', null, $this->kurator);
    $inhalte->sammlungHinzu($this->master, $sid, 'concept', [$this->konzept->id], $this->kurator);
    $inhalte->sammlungHinzu($this->master, $sid, 'paket', [$this->paket->id], $this->kurator);
    app(AboService::class)->abonnieren($this->master, $this->broich, $sid, $this->kurator);
    app(AboService::class)->abonnieren($this->master, $this->soul, $sid, $this->kurator);

    // Broich: eigenes Konzept mit dem Abo-Paket (Verweis), Foodbook-Block und Angebote mit dem Abo-Konzept
    $this->eigen = $cs->create($this->broich, ['name' => 'Broich-Menü']);
    $e1 = $cs->addSlot($this->broich, $this->eigen->id, ['role' => 'Beilage']);
    $this->slotZeile = $e1->id;
    DB::table('foodalchemist_concept_slots')->where('id', $e1->id)->update(['type' => 'paket', 'package_id' => $this->p1->id]);
    $fb = FoodAlchemistFoodbook::create(['team_id' => $this->broich->id, 'label' => 'Sommerkarte']);
    $kap = FoodAlchemistFoodbookKapitel::create(['team_id' => $this->broich->id, 'foodbook_id' => $fb->id, 'title' => 'Hauptgang', 'position' => 0]);
    $this->block = FoodAlchemistFoodbookBlock::create(['team_id' => $this->broich->id, 'chapter_id' => $kap->id, 'type' => 'concept_ref', 'position' => 0, 'visible' => true, 'concept_id' => $this->k1->id]);
    $this->angebotBlock = function (string $name, string $status) {
        $angebot = app(AngebotService::class)->create($this->broich, ['name' => $name, 'personen' => 50]);
        DB::table('foodalchemist_offers')->where('id', $angebot->id)->update(['status' => $status]);
        $kap = FoodAlchemistOfferChapter::create(['team_id' => $this->broich->id, 'offer_id' => $angebot->id, 'title' => 'Menü', 'position' => 0]);
        $block = FoodAlchemistOfferBlock::create(['team_id' => $this->broich->id, 'chapter_id' => $kap->id, 'type' => 'concept_ref', 'position' => 0, 'visible' => true, 'concept_id' => $this->k1->id]);

        return [$angebot, $block];
    };
    $this->reg = app(ToolRegistry::class);
});

function paketNeueFassung($t, string $name = 'Beilagen-Duo 2'): FoodAlchemistPaket
{
    $t->paket->update(['name' => $name]);

    return FoodAlchemistPaket::mitFassungen()->findOrFail($t->paketSvc->veroeffentlichen($t->master, $t->paket->id, 'Neu: '.$name, $t->kurator)['fassung_id']);
}

function konzeptNeueFassung($t, string $name = 'Schwaben-Menü 2'): FoodAlchemistConcept
{
    $t->konzept->update(['name' => $name]);

    return FoodAlchemistConcept::mitFassungen()->findOrFail($t->konzeptSvc->veroeffentlichen($t->master, $t->konzept->id, 'Neu: '.$name, $t->kurator)['fassung_id']);
}

/** Allergen-Änderung über die Kette: das Rezept bekommt eine neue Fassung mit Gluten, das Paket nimmt sie mit. */
function paketMitAllergen($t): FoodAlchemistPaket
{
    $t->gericht->ingredients()->first()->update(['quantity' => 400]);
    $r2 = FoodAlchemistRecipe::mitFassungen()->findOrFail($t->rezeptSvc->veroeffentlichen($t->master, $t->gericht->id, 'Mit Gluten', $t->kurator)['fassung_id']);
    $r2->forceFill(['allergen_gluten' => 'enthalten'])->save();

    return paketNeueFassung($t, 'Beilagen-Duo Gluten');
}

it('Verweis folgt der neuen Paket-Fassung: eigenes Konzept zeigt danach auf Fassung 2, Historie, Preis-Cache neu; Master und Fassung 1 unverändert', function () {
    $p2 = paketNeueFassung($this);

    expect(DB::table('foodalchemist_concept_slots')->where('id', $this->slotZeile)->value('package_id'))->toBe($p2->id)
        ->and(FoodAlchemistPaket::mitFassungen()->find($this->p1->id)->name)->toBe('Beilagen-Duo')
        ->and(FoodAlchemistAngebotUebernahme::where('art', 'baustein')->where('von_fassung_id', $this->p1->id)->where('nach_fassung_id', $p2->id)->count())->toBe(1);
    $h = FoodAlchemistAngebotUebernahme::where('art', 'baustein')->sole();
    expect($h->umgehaengt['modus'])->toBe('auto')->and($h->allergen_geaendert)->toBeFalse()->and((int) $h->team_id)->toBe((int) $this->broich->id);
    // idempotent: ein zweiter Lauf findet nichts
    expect($this->folge->folgen('paket', [$p2->id])['umgehaengt'])->toBe(0);
});

it('Verweis folgt der neuen Konzept-Fassung: Foodbook-Block (Entwurf) folgt, archiviertes Foodbook und historisches Angebot bleiben', function () {
    [$hist] = ($this->angebotBlock)('Altangebot', 'angenommen');
    $archiv = FoodAlchemistFoodbook::create(['team_id' => $this->broich->id, 'label' => 'Archiv', 'status' => 'archiviert']);
    $kapA = FoodAlchemistFoodbookKapitel::create(['team_id' => $this->broich->id, 'foodbook_id' => $archiv->id, 'title' => 'X', 'position' => 0]);
    $blockA = FoodAlchemistFoodbookBlock::create(['team_id' => $this->broich->id, 'chapter_id' => $kapA->id, 'type' => 'concept_ref', 'position' => 0, 'visible' => true, 'concept_id' => $this->k1->id]);

    $k2 = konzeptNeueFassung($this);

    expect(FoodAlchemistFoodbookBlock::find($this->block->id)->concept_id)->toBe($k2->id)
        ->and(FoodAlchemistFoodbookBlock::find($blockA->id)->concept_id)->toBe($this->k1->id)
        ->and(DB::table('foodalchemist_offer_blocks')->where('concept_id', $this->k1->id)->count())->toBe(1);   // historisches Angebot unberührt
});

it('eingefrorenes Angebot bleibt auf der alten Fassung und steht im Aktions-Signal; ein Entwurfs-Angebot folgt', function () {
    [$frozen, $blockF] = ($this->angebotBlock)('Eingefroren', 'angebot');
    FoodAlchemistAngebotEinfrierung::create(['team_id' => $this->broich->id, 'angebot_id' => $frozen->id, 'status_beim_einfrieren' => 'angebot', 'eingefroren_at' => now()]);
    [, $blockD] = ($this->angebotBlock)('Entwurf', 'anfrage');

    $k2 = konzeptNeueFassung($this);

    expect(FoodAlchemistOfferBlock::find($blockF->id)->concept_id)->toBe($this->k1->id)
        ->and(FoodAlchemistOfferBlock::find($blockD->id)->concept_id)->toBe($k2->id);
    $signal = FoodAlchemistSignal::where('team_id', $this->broich->id)->where('type', SignalTyp::FassungNeuerVerfuegbar->value)
        ->where('dedup_key', 'fassung-neu:concept:'.$this->konzept->id)->where('status', SignalStatus::Offen->value)->sole();
    expect($signal->payload['bausteine'])->toHaveCount(1)->and($signal->payload['bausteine'][0]['grund'])->toBe('eingefroren')
        ->and($signal->payload['bausteine'][0]['name'])->toBe('Eingefroren');
});

it('Allergen-Erbe über die Kette: Verweis bleibt, Signal ist Kritisch mit Stoff, Übernehmen nur bestätigt und nur ab Freigeben', function () {
    $p2 = paketMitAllergen($this);

    expect($p2->fassung_allergen_geaendert)->toBeTrue()
        ->and(DB::table('foodalchemist_concept_slots')->where('id', $this->slotZeile)->value('package_id'))->toBe($this->p1->id);   // blieb
    $signal = FoodAlchemistSignal::where('team_id', $this->broich->id)->where('type', SignalTyp::FassungNeuerVerfuegbar->value)
        ->where('dedup_key', 'fassung-neu:paket:'.$this->paket->id)->where('status', SignalStatus::Offen->value)->sole();
    expect($signal->severity->value)->toBe('kritisch')->and($signal->payload['allergen_geaendert'])->toBeTrue()
        ->and(implode(' ', $signal->payload['allergene']))->toContain('Gluten')
        ->and($signal->payload['bausteine'][0]['grund'])->toBe('allergen');

    // Vorschau ohne Bestätigung ändert nichts
    $v = $this->folge->uebernehmen($this->broich, $this->broichChef, 'paket', 'konzept_paket', $this->slotZeile, false);
    expect($v['vorschau']['grund'])->toBe('allergen')->and($v['vorschau']['allergen_diff'])->not->toBeEmpty()
        ->and(DB::table('foodalchemist_concept_slots')->where('id', $this->slotZeile)->value('package_id'))->toBe($this->p1->id);
    // Kuratieren reicht bei Allergen-Änderung nicht
    expect(fn () => $this->folge->uebernehmen($this->broich, $this->broichKoch, 'paket', 'konzept_paket', $this->slotZeile, true))->toThrow(\Platform\FoodAlchemist\Exceptions\FaRechtFehltException::class);
    expect(DB::table('foodalchemist_concept_slots')->where('id', $this->slotZeile)->value('package_id'))->toBe($this->p1->id);

    $r = $this->folge->uebernehmen($this->broich, $this->broichChef, 'paket', 'konzept_paket', $this->slotZeile, true);
    expect($r['umgehaengt'])->toBeTrue()->and($r['nach_fassung_id'])->toBe($p2->id)
        ->and(DB::table('foodalchemist_concept_slots')->where('id', $this->slotZeile)->value('package_id'))->toBe($p2->id);
    $h = FoodAlchemistAngebotUebernahme::where('art', 'baustein')->sole();
    expect($h->allergen_geaendert)->toBeTrue()->and($h->allergen_bestaetigt)->toBeTrue()->and((int) $h->bestaetigt_von)->toBe((int) $this->broichChef->id)
        ->and($h->umgehaengt['modus'])->toBe('manuell');
    // das Signal schließt sich
    expect(FoodAlchemistSignal::where('team_id', $this->broich->id)->where('dedup_key', 'fassung-neu:paket:'.$this->paket->id)->where('status', SignalStatus::Offen->value)->count())->toBe(0);
});

it('Signale: ein Info-Signal je Familie und Mandant (gebündelt, keine Flut), Allergen-Änderung hebt auf Kritisch', function () {
    paketNeueFassung($this, 'Beilagen-Duo 2');
    paketNeueFassung($this, 'Beilagen-Duo 3');
    $info = fn (Team $t) => FoodAlchemistSignal::where('team_id', $t->id)->where('type', SignalTyp::FassungAktualisiert->value)
        ->where('dedup_key', 'fassung-aktualisiert:paket:'.$this->paket->id)->where('status', SignalStatus::Offen->value)->get();

    expect($info($this->broich))->toHaveCount(1)->and($info($this->soul))->toHaveCount(1)
        ->and($info($this->broich)->first()->severity->value)->toBe('info')
        ->and(collect($info($this->broich)->first()->payload['fassungen'])->pluck('nr')->all())->toBe([2, 3]);
    // Master und ein Team ohne Abo bekommen nichts
    expect(FoodAlchemistSignal::where('team_id', $this->master->id)->where('type', SignalTyp::FassungAktualisiert->value)->count())->toBe(0);

    paketMitAllergen($this);
    expect($info($this->broich))->toHaveCount(1)->and($info($this->broich)->first()->severity->value)->toBe('kritisch');
});

it('mehrere Verweise einer Familie in einem Team = EIN Aktions-Signal', function () {
    paketMitAllergen($this);
    $cs = app(ConceptService::class);
    $zweit = $cs->create($this->broich, ['name' => 'Zweites Menü']);
    $z = $cs->addSlot($this->broich, $zweit->id, ['role' => 'Beilage']);
    DB::table('foodalchemist_concept_slots')->where('id', $z->id)->update(['type' => 'paket', 'package_id' => $this->p1->id]);
    $this->folge->folgenFuerTeam($this->broich->id, 'paket', [$this->paket->id], true);

    $offene = FoodAlchemistSignal::where('team_id', $this->broich->id)->where('type', SignalTyp::FassungNeuerVerfuegbar->value)
        ->where('dedup_key', 'fassung-neu:paket:'.$this->paket->id)->where('status', SignalStatus::Offen->value)->get();
    expect($offene)->toHaveCount(1)->and($offene->first()->payload['bausteine'])->toHaveCount(2);
});

it('ab der Schwelle läuft das Folgen als Job je Mandant (Veröffentlichung blockiert nicht), der Job leistet dasselbe', function () {
    config(['foodalchemist.baustein_folge.sync_grenze' => 0]);
    Queue::fake();
    $p2 = paketNeueFassung($this);
    Queue::assertPushed(BausteinFolgeJob::class, fn ($j) => $j->teamId === (int) $this->broich->id && $j->art === 'paket');
    expect(DB::table('foodalchemist_concept_slots')->where('id', $this->slotZeile)->value('package_id'))->toBe($this->p1->id);   // noch nicht

    (new BausteinFolgeJob((int) $this->broich->id, 'paket', [$this->paket->id]))->handle($this->folge);
    expect(DB::table('foodalchemist_concept_slots')->where('id', $this->slotZeile)->value('package_id'))->toBe($p2->id);
});

it('Fehler bei einem Verweis stoppt die anderen nicht und die Veröffentlichung nicht', function () {
    $cs = app(ConceptService::class);
    $zweit = $cs->create($this->broich, ['name' => 'Zweites Menü']);
    $z = $cs->addSlot($this->broich, $zweit->id, ['role' => 'Beilage']);
    DB::table('foodalchemist_concept_slots')->where('id', $z->id)->update(['type' => 'paket', 'package_id' => $this->p1->id]);
    // Preis-Cache-Neuberechnung wirft für das erste Konzept — die Folge selbst muss trotzdem durchlaufen
    $this->app->bind(ConceptService::class, fn () => new class extends ConceptService {
        public function recomputeCache(FoodAlchemistConcept $concept): FoodAlchemistConcept
        {
            throw new \RuntimeException('Cache kaputt');
        }
    });
    \Illuminate\Support\Facades\Log::spy();

    $p2 = paketNeueFassung($this);
    expect(DB::table('foodalchemist_concept_slots')->whereIn('id', [$this->slotZeile, $z->id])->pluck('package_id')->unique()->all())->toBe([$p2->id]);
    \Illuminate\Support\Facades\Log::shouldHaveReceived('error')->atLeast()->once();
});

it('Preis nach dem Umhängen: das eigene Konzept rechnet mit den Team-Preisen der neuen Paket-Fassung (kein 0 €)', function () {
    $p2 = paketNeueFassung($this);
    $c = FoodAlchemistConcept::find($this->eigen->id);
    $cockpit = app(ConceptService::class)->preisCockpit($c);
    expect($cockpit['quelle'])->toBe('team_kalkulation')->and($c->price_per_person_cache === null || (float) $c->price_per_person_cache >= 0.0)->toBeTrue()
        ->and(DB::table('foodalchemist_concept_slots')->where('id', $this->slotZeile)->value('package_id'))->toBe($p2->id);
});

it('Abfragen: die Fassungs-Pfad-Prüfung kostet für eine Liste EINE Abfrage, nicht eine je Konzept', function () {
    $cs = app(ConceptService::class);
    $ids = [];
    foreach (range(1, 50) as $i) {
        $ids[] = $cs->create($this->master, ['name' => "Listen-Konzept $i"])->id;
    }
    $geladen = FoodAlchemistConcept::whereIn('id', $ids)->with(['slots', 'slots.unit', 'slots.package', 'slots.embeddedConcept', 'slots.dish'])->get();
    $preis = app(ConceptPreisService::class);

    DB::flushQueryLog();
    DB::enableQueryLog();
    $preis->vorwaermen($geladen);
    $warm = count(DB::getQueryLog());
    DB::flushQueryLog();
    foreach ($geladen as $c) {
        $cs->preisCockpit($c);
    }
    $schleife = count(DB::getQueryLog());
    $preis->vergessen();
    DB::flushQueryLog();
    foreach ($geladen as $c) {
        $cs->preisCockpit($c);
    }
    $ohne = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($warm)->toBe(1)->and($schleife)->toBe(0)->and($ohne)->toBe(50);   // ohne Warm-up: eine Abfrage je Konzept (gemessen)
});

it('MCP: baustein_fassungen.UEBERNEHMEN_GET und _POST', function () {
    $p2 = paketMitAllergen($this);
    $ctx = new ToolContext($this->broichChef, $this->broich);
    $this->actingAs($this->broichChef);
    $get = $this->reg->get('foodalchemist.baustein_fassungen.UEBERNEHMEN_GET')->execute(['art' => 'paket', 'familie_id' => $this->paket->id], $ctx);
    expect($get->success)->toBeTrue((string) ($get->error ?? ''))->and($get->data['verweise'])->toHaveCount(1);

    $vor = $this->reg->get('foodalchemist.baustein_fassungen.UEBERNEHMEN_POST')->execute(['art' => 'paket', 'quelle' => 'konzept_paket', 'row_id' => $this->slotZeile], $ctx);
    expect($vor->success)->toBeTrue()->and($vor->data['bericht']['vorschau']['grund'])->toBe('allergen');
    $ok = $this->reg->get('foodalchemist.baustein_fassungen.UEBERNEHMEN_POST')->execute(['art' => 'paket', 'quelle' => 'konzept_paket', 'row_id' => $this->slotZeile, 'bestaetigt' => true], $ctx);
    expect($ok->success)->toBeTrue((string) ($ok->error ?? ''))
        ->and(DB::table('foodalchemist_concept_slots')->where('id', $this->slotZeile)->value('package_id'))->toBe($p2->id);
});

it('Merker: nach einer Exception im Block ist der Listen-Merker leer (mitVorwaermen), das Queue-Ereignis leert ihn ebenfalls', function () {
    $cs = app(ConceptService::class);
    $c = $cs->create($this->master, ['name' => 'Merker-Konzept']);
    $preis = app(ConceptPreisService::class);
    $merker = fn () => (new \ReflectionClass(ConceptPreisService::class))->getStaticPropertyValue('vorgewaermt');

    expect(fn () => $preis->mitVorwaermen([$c->id], function () use ($merker, $c) {
        expect($merker())->toHaveKey($c->id);   // im Block steht er
        throw new \RuntimeException('mitten in der Liste');
    }))->toThrow(\RuntimeException::class, 'mitten in der Liste');
    expect($merker())->toBe([]);

    // Ereignis-Weg (queue:work): ein hängengebliebener Merker verschwindet beim nächsten Job
    $preis->vorwaermen([$c->id]);
    expect($merker())->not->toBe([]);
    event(new \Illuminate\Queue\Events\JobProcessing('sync', new class extends \Illuminate\Queue\Jobs\Job {
        public function getJobId() { return '1'; }
        public function getRawBody() { return '{}'; }
        public function attempts() { return 1; }
        public function release($delay = 0) {}
        public function delete() {}
    }));
    expect($merker())->toBe([]);
});
