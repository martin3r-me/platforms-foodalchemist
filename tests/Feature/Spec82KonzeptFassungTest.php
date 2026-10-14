<?php

use Illuminate\Support\Facades\DB;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Models\Team;
use Platform\Core\Tools\ToolRegistry;
use Platform\FoodAlchemist\Models\FoodAlchemistConcept;
use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Models\FoodAlchemistPaket;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeDarreichung;
use Platform\FoodAlchemist\Models\FoodAlchemistServierform;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplier;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItemStructure;
use Platform\FoodAlchemist\Models\FoodAlchemistVocabEinheit;
use Platform\FoodAlchemist\Services\AboService;
use Platform\FoodAlchemist\Services\ConceptFassungService;
use Platform\FoodAlchemist\Services\ConceptPreisService;
use Platform\FoodAlchemist\Services\ConceptService;
use Platform\FoodAlchemist\Services\DarreichungService;
use Platform\FoodAlchemist\Services\FassungService;
use Platform\FoodAlchemist\Services\InhaltsFreigabeService;
use Platform\FoodAlchemist\Services\PaketFassungService;
use Platform\FoodAlchemist\Services\PaketPreisService;
use Platform\FoodAlchemist\Services\PaketService;
use Platform\FoodAlchemist\Services\PriceService;
use Platform\FoodAlchemist\Services\RecipeRecomputeService;
use Platform\FoodAlchemist\Services\TeamVkService;
use Platform\FoodAlchemist\Support\StammPaket;
use Platform\FoodAlchemist\Support\TeamAncestryRegistry;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 82 Abo Konzepte, PR 4: Konzept-Fassung (kind concept UND paket, beide Slot-Arten package_id / embedded_concept_id) und
 * der Konzeptpreis je (Fassung × Team) über die Kosten-Dienste. Entscheide 14.10.: D2/D11 blockieren, D10 Hinweis, D9 Darreichung anhängen.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->master = Team::create(['name' => 'BHG.DIGITAL (Master)', 'user_id' => 1, 'personal_team' => false]);
    config(['foodalchemist.master_team_id' => $this->master->id]);
    $this->broich = Team::create(['name' => 'Broich', 'user_id' => 1, 'personal_team' => false]);
    $this->soul = Team::create(['name' => 'Soulkitchen', 'user_id' => 1, 'personal_team' => false]);
    $this->neu = Team::create(['name' => 'Neukunde', 'user_id' => 1, 'personal_team' => false]);
    TeamAncestryRegistry::flushAll();
    foreach ([$this->broich, $this->soul, $this->neu] as $t) {
        StammPaket::freischalten($t);
    }
    $this->kurator = $this->makeUser($this->master, 'Kurator', 'owner');
    $this->masterKoch = $this->makeUser($this->master, 'Master Koch', 'member');
    $this->broichChef = $this->makeUser($this->broich, 'Broich Chef', 'owner');
    $this->neuChef = $this->makeUser($this->neu, 'Neu Chef', 'owner');
    $this->actingAs($this->kurator);

    $sup = FoodAlchemistSupplier::create(['team_id' => null, 'name' => 'Stamm-Großhandel']);
    $mk = function (string $name) use ($sup) {
        $gp = FoodAlchemistGp::create(['team_id' => null, 'gp_key' => 'kz|' . $name, 'name' => $name]);
        $la = FoodAlchemistSupplierItem::create(['team_id' => null, 'supplier_id' => $sup->id, 'designation' => $name . ' 1 kg', 'qty' => 1.0, 'unit_code' => 'kg']);
        FoodAlchemistSupplierItemStructure::create(['team_id' => null, 'supplier_item_id' => $la->id, 'gp_id' => $gp->id]);
        $gp->update(['lead_la_supplier_item_id' => $la->id]);

        return [$gp->refresh(), $la];
    };
    [$knochen, $laKnochen] = $mk('Kalbsknochen');
    [$butter, $this->laButter] = $mk('Butter');

    $this->gericht = $this->makeRecipe($this->master, 'Kalbsjus am Teller', ['is_sales_recipe' => true]);
    $this->makeIngredient($this->gericht, 'Kalbsknochen', $knochen, '1000');
    $this->makeIngredient($this->gericht, 'Butter', $butter, '100', 2);
    app(RecipeRecomputeService::class)->recomputePipeline($this->gericht->id);
    $teller = FoodAlchemistServierform::create(['team_id' => $this->master->id, 'code' => 'teller', 'label' => 'Teller']);
    $klein = FoodAlchemistServierform::create(['team_id' => $this->master->id, 'code' => 'klein', 'label' => 'Klein']);
    $dar = app(DarreichungService::class);
    $this->standard = $dar->anlegen($this->master, $this->gericht->id, $teller->id, ['quantity_per_unit_g' => 200]);
    $this->kleine = $dar->anlegen($this->master, $this->gericht->id, $klein->id, ['quantity_per_unit_g' => 100]);
    $this->portion = FoodAlchemistVocabEinheit::create(['team_id' => $this->master->id, 'slug' => 'portion', 'display_de' => 'Portion', 'dimension' => 'count', 'default_in_g' => null]);

    // Paket (Tabelle packages) mit dem Gericht
    $paketSvc = app(PaketService::class);
    $this->paket = $paketSvc->create($this->master, ['name' => 'Jus-Duo']);
    $paketSvc->syncGerichte($this->master, $this->paket->id, [['sales_recipe_id' => $this->gericht->id, 'quantity' => 1, 'unit_vocab_id' => $this->portion->id]]);

    // Paket-Konzept (kind=paket) mit dem Gericht in der kleinen Portion
    $cs = app(ConceptService::class);
    $this->paketKonzept = $cs->createPaket($this->master, ['name' => 'Jus-Kompakt']);
    $s = $cs->addSlot($this->master, $this->paketKonzept->id, ['role' => 'Beilage']);
    $cs->fillSlot($this->master, $s->id, ['sales_recipe_id' => $this->gericht->id, 'quantity' => 1, 'unit_vocab_id' => $this->portion->id]);
    $cs->setSlotDarreichung($this->master, $s->id, $this->kleine->id);

    // Konzept: Paket-Slot (package_id), eingebettetes Paket-Konzept (embedded_concept_id), festes Gericht, Struktur-Block
    $this->konzept = $cs->create($this->master, ['name' => 'Jus-Menü']);
    $s1 = $cs->addSlot($this->master, $this->konzept->id, ['role' => 'Vorspeise']);
    $cs->fillSlot($this->master, $s1->id, ['package_id' => $this->paket->id]);
    $s2 = $cs->addSlot($this->master, $this->konzept->id, ['role' => 'Beilage']);
    $cs->fillSlot($this->master, $s2->id, ['embedded_concept_id' => $this->paketKonzept->id]);
    $s3 = $cs->addSlot($this->master, $this->konzept->id, ['role' => 'Hauptgang']);
    $cs->fillSlot($this->master, $s3->id, ['sales_recipe_id' => $this->gericht->id, 'quantity' => 1, 'unit_vocab_id' => $this->portion->id]);
    $cs->setSlotDarreichung($this->master, $s3->id, $this->standard->id);
    $cs->addBlock($this->master, $this->konzept->id, 'header', ['title' => 'Unser Menü']);

    $this->cs = $cs;
    $this->svc = app(ConceptFassungService::class);
    $this->reg = app(ToolRegistry::class);
    $this->preis = app(ConceptPreisService::class);
    $this->hilfe = new class($this) {
        public function __construct(private $t) {}

        public function alleBausteine(): void
        {
            $t = $this->t;
            app(FassungService::class)->veroeffentlichen($t->master, $t->gericht->id, 'Erst', $t->kurator);
            app(PaketFassungService::class)->veroeffentlichen($t->master, $t->paket->id, 'Erst', $t->kurator);
            $t->svc->veroeffentlichen($t->master, $t->paketKonzept->id, 'Erst', $t->kurator);
        }

        public function abo(array $mandanten): void
        {
            $t = $this->t;
            $inhalte = app(InhaltsFreigabeService::class);
            $sid = $inhalte->sammlungAnlegen($t->master, 'Konzepte', null, $t->kurator);
            $inhalte->sammlungHinzu($t->master, $sid, 'concept', [$t->konzept->id], $t->kurator);
            foreach ($mandanten as $m) {
                app(AboService::class)->abonnieren($t->master, $m, $sid, $t->kurator);
            }
        }

        public function preise(): void
        {
            $p = app(PriceService::class);
            $laKnochen = FoodAlchemistSupplierItem::where('designation', 'Kalbsknochen 1 kg')->first();
            $p->createTeamPrice($this->t->broich, $laKnochen, 4.00);
            $p->createTeamPrice($this->t->broich, $this->t->laButter, 10.00);
            $p->createTeamPrice($this->t->soul, $laKnochen, 5.00);
            $p->createTeamPrice($this->t->soul, $this->t->laButter, 12.00);
            $p->createTeamPrice($this->t->neu, $this->t->laButter, 10.00);   // Neukunde: Knochen ohne Preis
        }
    };
});

it('D2 · ohne veröffentlichte Fassung von Paket, Paket-Konzept und Rezept bricht das Veröffentlichen mit der Liste ab', function () {
    expect(fn () => $this->svc->veroeffentlichen($this->master, $this->konzept->id, 'Erst', $this->kurator))
        ->toThrow(\RuntimeException::class, 'Jus-Duo');
    expect(FoodAlchemistConcept::mitFassungen()->where('ist_fassung', true)->count())->toBe(0);
});

it('Veröffentlichen: Fassung ohne Master-Preise, Slots zeigen auf Fassungen (beide Paket-Wege + Rezept), Arbeitszeile bleibt', function () {
    $this->hilfe->alleBausteine();
    $this->konzept->update(['price_per_person_manual' => 99.0]);
    $r = $this->svc->veroeffentlichen($this->master, $this->konzept->id, 'Menü erstmals', $this->kurator);

    $f = FoodAlchemistConcept::mitFassungen()->find($r['fassung_id']);
    $slots = $f->slots()->orderBy('position')->get();
    $paketF = FoodAlchemistPaket::mitFassungen()->where('fassung_von_id', $this->paket->id)->where('ist_fassung', true)->sole();
    $konzeptF = FoodAlchemistConcept::mitFassungen()->where('fassung_von_id', $this->paketKonzept->id)->where('ist_fassung', true)->sole();
    $rezeptF = FoodAlchemistRecipe::mitFassungen()->where('fassung_von_id', $this->gericht->id)->where('ist_fassung', true)->sole();

    expect($r['neu'])->toBeTrue()->and($r['fassung_nr'])->toBe(1)
        ->and($f->ist_fassung)->toBeTrue()->and($f->fassung_von_id)->toBe($this->konzept->id)->and($f->aenderungsnotiz)->toBe('Menü erstmals')
        ->and($f->price_per_person_cache)->toBeNull()->and($f->price_per_person_manual)->toBeNull()->and($f->ek_per_person_cache)->toBeNull()
        ->and($slots->pluck('package_id')->filter()->values()->all())->toBe([$paketF->id])
        ->and($slots->pluck('embedded_concept_id')->filter()->values()->all())->toBe([$konzeptF->id])
        ->and($slots->pluck('sales_recipe_id')->filter()->values()->all())->toBe([$rezeptF->id])
        ->and($slots->where('type', 'header')->count())->toBe(1)   // Struktur-Block bleibt
        ->and($this->konzept->refresh()->ist_fassung)->toBeFalse()
        ->and((float) $this->konzept->price_per_person_manual)->toBe(99.0);
});

it('gleicher Inhalt = keine neue Fassung, Änderung = nächste Nummer', function () {
    $this->hilfe->alleBausteine();
    $a = $this->svc->veroeffentlichen($this->master, $this->konzept->id, 'Erst', $this->kurator);
    $b = $this->svc->veroeffentlichen($this->master, $this->konzept->id, 'Nochmal', $this->kurator);
    expect($b['neu'])->toBeFalse()->and($b['fassung_id'])->toBe($a['fassung_id']);

    $this->konzept->update(['name' => 'Jus-Menü Premium']);
    $c = $this->svc->veroeffentlichen($this->master, $this->konzept->id, 'Umbenannt', $this->kurator);
    expect($c['neu'])->toBeTrue()->and($c['fassung_nr'])->toBe(2);
});

it('D10 + D9 · unveröffentlichte Rezept-Änderung ist ein Hinweis, die gewählte Form wird an die Rezept-Fassung angehängt', function () {
    $this->hilfe->alleBausteine();
    $this->gericht->update(['name' => 'Kalbsjus neu']);
    $this->gericht->ingredients()->first()->update(['quantity' => 700]);
    $r = $this->svc->veroeffentlichen($this->master, $this->konzept->id, 'Erst', $this->kurator);

    expect(implode(' ', $r['hinweise']))->toContain('unveröffentlichte Änderungen');
    $rezeptF = FoodAlchemistRecipe::mitFassungen()->where('fassung_von_id', $this->gericht->id)->where('ist_fassung', true)->sole();
    $slotG = FoodAlchemistConcept::mitFassungen()->find($r['fassung_id'])->slots()->whereNotNull('sales_recipe_id')->sole();
    $standardF = FoodAlchemistRecipeDarreichung::where('recipe_id', $rezeptF->id)->where('is_standard', true)->sole();
    expect($slotG->presentation_id)->toBe($standardF->id);
});

it('nur Master und nur ab Rolle Freigeben', function () {
    $this->hilfe->alleBausteine();
    expect(fn () => $this->svc->veroeffentlichen($this->master, $this->konzept->id, 'x', $this->masterKoch))->toThrow(\RuntimeException::class)
        ->and(fn () => $this->svc->veroeffentlichen($this->broich, $this->konzept->id, 'x', $this->broichChef))->toThrow(\RuntimeException::class)
        ->and(fn () => $this->svc->veroeffentlichen($this->master, $this->konzept->id, ' ', $this->kurator))->toThrow(\RuntimeException::class, 'Änderungsnotiz');
});

it('Abo: Mandant sieht Konzept-Fassung samt Paket-Fassung, Paket-Konzept-Fassung und Rezept-Fassung, nie Arbeitszeilen; ohne Abo nichts', function () {
    $this->hilfe->alleBausteine();
    $this->hilfe->abo([$this->broich]);
    $r = $this->svc->veroeffentlichen($this->master, $this->konzept->id, 'Erst', $this->kurator);

    $sieht = fn ($klasse, Team $t) => $klasse::visibleToTeam($t)->pluck((new $klasse)->getTable() . '.id')->map(fn ($v) => (int) $v)->all();
    $konzepteB = $sieht(FoodAlchemistConcept::class, $this->broich);
    $konzeptF = FoodAlchemistConcept::mitFassungen()->where('fassung_von_id', $this->paketKonzept->id)->where('ist_fassung', true)->sole();
    expect($konzepteB)->toContain($r['fassung_id'])->and($konzepteB)->toContain($konzeptF->id)
        ->and($konzepteB)->not->toContain($this->konzept->id)->and($konzepteB)->not->toContain($this->paketKonzept->id)
        ->and($sieht(FoodAlchemistPaket::class, $this->broich))->toHaveCount(1)
        ->and($sieht(FoodAlchemistPaket::class, $this->broich))->not->toContain($this->paket->id)
        ->and($sieht(FoodAlchemistRecipe::class, $this->broich))->not->toContain($this->gericht->id)
        ->and($sieht(FoodAlchemistConcept::class, $this->soul))->toBe([])
        ->and(array_keys(app(AboService::class)->huelle((int) $this->broich->id)))->toEqualCanonicalizing(['recipe', 'paket', 'concept']);
});

it('Preis · gleiches Konzept, zwei Mandanten, zwei Preise — jede Zeile aus den Kosten-Diensten des Teams', function () {
    $this->hilfe->alleBausteine();
    $this->hilfe->abo([$this->broich, $this->soul]);
    $this->hilfe->preise();
    $f = FoodAlchemistConcept::mitFassungen()->findOrFail($this->svc->veroeffentlichen($this->master, $this->konzept->id, 'Erst', $this->kurator)['fassung_id']);

    $b = $this->preis->cockpit($f, $this->broich);
    $s = $this->preis->cockpit($f, $this->soul);
    $paketF = FoodAlchemistPaket::mitFassungen()->where('ist_fassung', true)->sole();
    $paketB = app(PaketPreisService::class)->fuer($paketF, $this->broich);
    $rezeptF = FoodAlchemistRecipe::mitFassungen()->where('fassung_von_id', $this->gericht->id)->where('ist_fassung', true)->sole();
    $gerichtB = app(TeamVkService::class)->fuer($rezeptF, $this->broich);

    expect($b['quelle'])->toBe('team_kalkulation')->and($b['vollstaendig'])->toBeTrue()->and($s['vollstaendig'])->toBeTrue()
        ->and($b['price_per_person'])->toBeGreaterThan(0.0)->and($b['price_per_person'])->toBeLessThan($s['price_per_person'])
        ->and(collect($b['zeilen'])->firstWhere('role', 'Vorspeise')['price'])->toBe(round($paketB['vk_pro_person'], 2))
        ->and(collect($b['zeilen'])->firstWhere('role', 'Hauptgang')['price'])->toBe(round($gerichtB['vk'], 2))
        ->and($b['price_per_person'])->toBe(round(collect($b['zeilen'])->sum('price'), 2));

    // die Fassung trägt keine Master-Preise
    $roh = DB::table('foodalchemist_concepts')->where('id', $f->id)->first();
    expect($roh->price_per_person_cache)->toBeNull()->and($roh->ek_per_person_cache)->toBeNull();
});

it('Master sieht seinen Preis unverändert — Veröffentlichen und Mandantenpreise fassen ihn nicht an', function () {
    $this->hilfe->alleBausteine();
    $this->konzept->update(['price_mode' => 'manuell', 'price_per_person_manual' => 49.50]);
    $vorher = app(ConceptService::class)->preisCockpit(FoodAlchemistConcept::find($this->konzept->id));

    $this->hilfe->abo([$this->broich]);
    $this->hilfe->preise();
    $f = FoodAlchemistConcept::mitFassungen()->findOrFail($this->svc->veroeffentlichen($this->master, $this->konzept->id, 'Erst', $this->kurator)['fassung_id']);
    $this->preis->cockpit($f, $this->broich);
    $nachher = app(ConceptService::class)->preisCockpit(FoodAlchemistConcept::find($this->konzept->id));

    expect($vorher['price_per_person'])->toBe(49.5)->and($nachher['price_per_person'])->toBe(49.5)
        ->and($nachher['zeilen'])->toBe($vorher['zeilen'])->and($nachher['quelle'] ?? null)->toBeNull();
});

it('Auflage 4 · unvollständiger EK = kein Preis (null + Grund + fehlende Bestandteile), nie 0 €', function () {
    $this->hilfe->alleBausteine();
    $this->hilfe->abo([$this->neu]);
    $this->hilfe->preise();
    $f = FoodAlchemistConcept::mitFassungen()->findOrFail($this->svc->veroeffentlichen($this->master, $this->konzept->id, 'Erst', $this->kurator)['fassung_id']);

    $r = $this->preis->cockpit($f, $this->neu);
    expect($r['price_per_person'])->toBeNull()->and($r['ek_per_person'])->toBeNull()->and($r['summe_pro_person'])->toBeNull()
        ->and($r['vollstaendig'])->toBeFalse()->and($r['grund'])->not->toBeNull()->and($r['fehlende'])->not->toBeEmpty();
    $w = $this->preis->preisWerte($f, $this->neu);
    expect($w['vollstaendig'])->toBeFalse()->and($w['vk'])->toBeNull();
});

it('Leck-Sperre · Overlay-Instanz nicht dirty, save()/update()/touch() werfen, Fassung bleibt in der DB ohne Preis', function () {
    $this->hilfe->alleBausteine();
    $this->hilfe->abo([$this->broich]);
    $this->hilfe->preise();
    $fid = $this->svc->veroeffentlichen($this->master, $this->konzept->id, 'Erst', $this->kurator)['fassung_id'];

    $overlay = app(ConceptService::class)->detail($this->broich, $fid);
    expect((float) $overlay->price_per_person_cache)->toBeGreaterThan(0.0)->and($overlay->preisOverlay)->toBeTrue()
        ->and($overlay->isDirty(['price_per_person_cache', 'ek_per_person_cache', 'calculated_price_per_person', 'price_mode']))->toBeFalse()
        ->and(app(ConceptService::class)->preisCockpit($overlay)['price_per_person'])->toBe($this->preis->cockpit($overlay, $this->broich)['price_per_person']);
    expect(fn () => $overlay->save())->toThrow(\LogicException::class, 'Overlay-Instanz')
        ->and(fn () => $overlay->update(['note' => 'x']))->toThrow(\LogicException::class)
        ->and(fn () => $overlay->touch())->toThrow(\LogicException::class);
    $roh = DB::table('foodalchemist_concepts')->where('id', $fid)->first();
    expect($roh->price_per_person_cache)->toBeNull()->and($roh->ek_per_person_cache)->toBeNull();

    $eigen = app(ConceptService::class)->detail($this->master, $this->konzept->id);
    expect($eigen->preisOverlay)->toBeFalse();
    $eigen->update(['note' => 'ok']);
    expect(FoodAlchemistConcept::find($this->konzept->id)->note)->toBe('ok');
});

it('Lesestellen: Browser, Detail und MCP concepts.GET zeigen beim Mandanten den Team-Preis, ohne Preis nicht 0', function () {
    $this->hilfe->alleBausteine();
    $this->hilfe->abo([$this->broich, $this->neu]);
    $this->hilfe->preise();
    $fid = $this->svc->veroeffentlichen($this->master, $this->konzept->id, 'Erst', $this->kurator)['fassung_id'];
    $f = FoodAlchemistConcept::mitFassungen()->find($fid);
    $erwartet = $this->preis->cockpit($f, $this->broich)['price_per_person'];

    $zeile = app(ConceptService::class)->paginateBrowser([], $this->broich)->getCollection()->firstWhere('id', $fid);
    expect((float) $zeile->price_per_person_cache)->toBe($erwartet);

    $this->actingAs($this->neuChef);
    $get = $this->reg->get('foodalchemist.concepts.GET')->execute(['concept_id' => $fid], new ToolContext($this->neuChef, $this->neu));
    expect($get->success)->toBeTrue((string) ($get->error ?? ''));
    $nullPreise = collect($get->data['concept']['slots'] ?? $get->data['slots'] ?? [])->pluck('paket.price_per_person')->filter(fn ($v) => $v !== null && (float) $v === 0.0);
    expect($nullPreise)->toBeEmpty();
});

it('eigene Kopie einer Abo-Konzept-Fassung: Preis mit den Team-Preisen des Mandanten, ohne vollständigen EK kein Preis', function () {
    $this->hilfe->alleBausteine();
    $this->hilfe->abo([$this->broich, $this->neu]);
    $this->hilfe->preise();
    $fid = $this->svc->veroeffentlichen($this->master, $this->konzept->id, 'Erst', $this->kurator)['fassung_id'];
    $erwartet = $this->preis->cockpit(FoodAlchemistConcept::mitFassungen()->find($fid), $this->broich);
    $inhalte = app(InhaltsFreigabeService::class);

    $this->actingAs($this->broichChef);
    $kopieB = $inhalte->kopieAnlegen($this->broich, 'concept', $fid, $this->broichChef);
    expect((int) $kopieB->team_id)->toBe((int) $this->broich->id)->and($kopieB->ist_fassung)->toBeFalse()->and((int) $kopieB->kopie_von_id)->toBe($fid)
        ->and((float) $kopieB->price_per_person_cache)->toBe($erwartet['price_per_person'])
        ->and((float) $kopieB->ek_per_person_cache)->toBe($erwartet['ek_per_person']);

    $this->actingAs($this->neuChef);
    $kopieN = $inhalte->kopieAnlegen($this->neu, 'concept', $fid, $this->neuChef);
    expect($kopieN->price_per_person_cache)->toBeNull()->and($kopieN->ek_per_person_cache)->toBeNull();
});

it('MCP: concept_fassungen.PUBLISH und .GET', function () {
    $this->hilfe->alleBausteine();
    $ctx = new ToolContext($this->kurator, $this->master);
    $pub = $this->reg->get('foodalchemist.concept_fassungen.PUBLISH')->execute(['concept_id' => $this->konzept->id, 'notiz' => 'Erst'], $ctx);
    expect($pub->success)->toBeTrue($pub->error ?? '');
    $get = $this->reg->get('foodalchemist.concept_fassungen.GET')->execute(['concept_id' => $this->konzept->id], $ctx);
    expect($get->success)->toBeTrue($get->error ?? '')->and(json_encode($get->data))->toContain('Erst');
});
