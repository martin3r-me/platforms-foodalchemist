<?php

use Illuminate\Support\Facades\DB;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Models\Team;
use Platform\Core\Tools\ToolRegistry;
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
 * Spec 82 Abo Pakete, PR 3 (§12 C): Paketpreis je (Paket-Fassung × Team) über KostenCacheService + TeamVkService.
 * Abnahme: gleiches Paket, zwei Mandanten, zwei Preise; der Master sieht seinen Preis unverändert; unvollständiger EK =
 * „kein Preis" (nie 0 €, nie Teilsumme).
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
    $this->broichChef = $this->makeUser($this->broich, 'Broich Chef', 'owner');
    $this->neuChef = $this->makeUser($this->neu, 'Neu Chef', 'owner');
    $this->actingAs($this->kurator);

    $sup = FoodAlchemistSupplier::create(['team_id' => null, 'name' => 'Stamm-Großhandel']);
    $mk = function (string $name) use ($sup) {
        $gp = FoodAlchemistGp::create(['team_id' => null, 'gp_key' => 'pp|' . $name, 'name' => $name]);
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

    $this->teller = FoodAlchemistServierform::create(['team_id' => $this->master->id, 'code' => 'teller', 'label' => 'Teller']);
    $this->klein = FoodAlchemistServierform::create(['team_id' => $this->master->id, 'code' => 'klein', 'label' => 'Klein']);
    $dar = app(DarreichungService::class);
    $this->standard = $dar->anlegen($this->master, $this->gericht->id, $this->teller->id, ['quantity_per_unit_g' => 200]);
    $this->kleine = $dar->anlegen($this->master, $this->gericht->id, $this->klein->id, ['quantity_per_unit_g' => 100]);
    $this->portion = FoodAlchemistVocabEinheit::create(['team_id' => $this->master->id, 'slug' => 'portion', 'display_de' => 'Portion', 'dimension' => 'count', 'default_in_g' => null]);

    $paketSvc = app(PaketService::class);
    $this->paket = $paketSvc->create($this->master, ['name' => 'Jus-Duo']);
    $paketSvc->syncGerichte($this->master, $this->paket->id, [['sales_recipe_id' => $this->gericht->id, 'quantity' => 1, 'unit_vocab_id' => $this->portion->id]]);

    $this->fassungen = app(FassungService::class);
    $this->fassungen->veroeffentlichen($this->master, $this->gericht->id, 'Erst', $this->kurator);
    $inhalte = app(InhaltsFreigabeService::class);
    $sid = $inhalte->sammlungAnlegen($this->master, 'Pakete', null, $this->kurator);
    $inhalte->sammlungHinzu($this->master, $sid, 'paket', [$this->paket->id], $this->kurator);
    foreach ([$this->broich, $this->soul, $this->neu] as $t) {
        app(AboService::class)->abonnieren($this->master, $t, $sid, $this->kurator);
    }
    $this->paketF = FoodAlchemistPaket::mitFassungen()->findOrFail(
        app(PaketFassungService::class)->veroeffentlichen($this->master, $this->paket->id, 'Erst', $this->kurator)['fassung_id']);
    $this->gerichtF = FoodAlchemistRecipe::mitFassungen()->where('fassung_von_id', $this->gericht->id)->where('ist_fassung', true)->sole();

    $p = app(PriceService::class);
    $p->createTeamPrice($this->broich, $laKnochen, 4.00);
    $p->createTeamPrice($this->broich, $this->laButter, 10.00);
    $p->createTeamPrice($this->soul, $laKnochen, 5.00);
    $p->createTeamPrice($this->soul, $this->laButter, 12.00);
    $p->createTeamPrice($this->neu, $this->laButter, 10.00);   // Neukunde: Knochen ohne Preis → unvollständig

    $this->preis = app(PaketPreisService::class);
    $this->reg = app(ToolRegistry::class);
});

it('Abnahme · gleiches Paket, zwei Mandanten, zwei Preise — jeder aus der Team-Kalkulation der Rezept-Fassung', function () {
    $b = $this->preis->fuer($this->paketF, $this->broich);
    $s = $this->preis->fuer($this->paketF, $this->soul);
    $vkB = app(TeamVkService::class)->fuer($this->gerichtF, $this->broich);
    $vkS = app(TeamVkService::class)->fuer($this->gerichtF, $this->soul);

    expect($b['quelle'])->toBe('team_kalkulation')->and($b['vollstaendig'])->toBeTrue()->and($s['vollstaendig'])->toBeTrue()
        ->and($b['vk_pro_person'])->toBe($vkB['vk'])->and($s['vk_pro_person'])->toBe($vkS['vk'])
        ->and($b['ek_pro_person'])->toBe(round($vkB['mek'], 4))->and($s['ek_pro_person'])->toBe(round($vkS['mek'], 4))
        ->and($b['vk_pro_person'])->toBeGreaterThan(0.0)->and($b['vk_pro_person'])->toBeLessThan($s['vk_pro_person'])
        ->and($b['food_cost_percent'])->toBeGreaterThan(0.0);

    // die Fassung selbst trägt weiter keine Master-Preise (Leck-Sperre)
    $roh = FoodAlchemistPaket::mitFassungen()->find($this->paketF->id);
    expect($roh->price_per_person)->toBeNull()->and($roh->ek_per_person)->toBeNull();
});

it('Master sieht seinen Preis unverändert — das Veröffentlichen und die Mandantenpreise fassen ihn nicht an', function () {
    $this->paket->update(['price_mode' => 'manuell', 'price_per_person' => 12.50, 'ek_per_person' => 3.10]);
    $vorher = FoodAlchemistPaket::find($this->paket->id);

    $m = $this->preis->fuer($vorher, $this->master);
    app(PaketFassungService::class)->veroeffentlichen($this->master, $this->paket->id, 'Nochmal', $this->kurator);
    $this->preis->fuer($this->paketF, $this->broich);
    $nachher = FoodAlchemistPaket::find($this->paket->id);

    expect($m['quelle'])->toBe('gespeichert')->and($m['vk_pro_person'])->toBe(12.5)->and($m['ek_pro_person'])->toBe(3.1)
        ->and((float) $nachher->price_per_person)->toBe(12.5)->and((float) $nachher->ek_per_person)->toBe(3.1)
        ->and(app(PaketService::class)->detail($this->master, $this->paket->id)->price_per_person)->toBe($nachher->price_per_person);
});

it('Auflage 4 · unvollständiger EK = kein Preis (null + Grund + fehlendes Rezept), nie 0 € und keine Teilsumme', function () {
    $r = $this->preis->fuer($this->paketF, $this->neu);

    expect($r['vk_pro_person'])->toBeNull()->and($r['ek_pro_person'])->toBeNull()->and($r['food_cost_percent'])->toBeNull()
        ->and($r['vollstaendig'])->toBeFalse()->and($r['grund'])->toBe('ek_unvollstaendig')
        ->and($r['fehlende'])->toBe(['Kalbsjus am Teller']);
});

it('der Preis folgt der Preisänderung des Teams, ohne dass ein anderer Mandant sich bewegt', function () {
    $vorherB = $this->preis->fuer($this->paketF, $this->broich)['vk_pro_person'];
    $vorherS = $this->preis->fuer($this->paketF, $this->soul)['vk_pro_person'];

    app(PriceService::class)->createTeamPrice($this->broich, $this->laButter, 20.00);

    expect($this->preis->fuer($this->paketF, $this->broich)['vk_pro_person'])->toBeGreaterThan($vorherB)
        ->and($this->preis->fuer($this->paketF, $this->soul)['vk_pro_person'])->toBe($vorherS);
});

it('gewählte Darreichung wird gerechnet: kleine Portion kostet weniger als die Standard-Portion', function () {
    $standardF = $this->preis->fuer($this->paketF, $this->broich)['vk_pro_person'];
    $this->actingAs($this->kurator);
    $this->paket->dishes()->first()->update(['presentation_id' => $this->kleine->id]);
    $kleinF = FoodAlchemistPaket::mitFassungen()->findOrFail(
        app(PaketFassungService::class)->veroeffentlichen($this->master, $this->paket->id, 'Kleine Portion', $this->kurator)['fassung_id']);

    $kleinPreis = $this->preis->fuer($kleinF, $this->broich)['vk_pro_person'];
    expect($kleinPreis)->toBeGreaterThan(0.0)->and($kleinPreis)->toBeLessThan($standardF);
});

it('Lesestellen: Browser, Detail, Kandidaten und MCP pakete.GET/LIST zeigen beim Mandanten den Team-Preis, ohne Preis nicht 0', function () {
    $svc = app(PaketService::class);
    $erwartetB = $this->preis->fuer($this->paketF, $this->broich);

    $detail = $svc->detail($this->broich, $this->paketF->id);
    $zeile = $svc->paginateBrowser([], $this->broich)->getCollection()->firstWhere('id', $this->paketF->id);
    $kand = $svc->paketKandidaten($this->broich, 'Jus')->firstWhere('id', $this->paketF->id);
    expect((float) $detail->price_per_person)->toBe($erwartetB['vk_pro_person'])
        ->and((float) $zeile->price_per_person)->toBe($erwartetB['vk_pro_person'])
        ->and((float) $kand->price_per_person)->toBe($erwartetB['vk_pro_person']);

    $this->actingAs($this->broichChef);
    $get = $this->reg->get('foodalchemist.pakete.GET')->execute(['id' => $this->paketF->id], new ToolContext($this->broichChef, $this->broich));
    expect($get->success)->toBeTrue((string) ($get->error ?? ''))
        ->and($get->data['paket']['price_per_person'])->toBe($erwartetB['vk_pro_person'])
        ->and($get->data['paket']['price_complete'])->toBeTrue();

    $this->actingAs($this->neuChef);
    $list = $this->reg->get('foodalchemist.pakete.LIST')->execute([], new ToolContext($this->neuChef, $this->neu));
    $paket = collect($list->data['pakete'])->firstWhere('id', $this->paketF->id);
    expect($paket['price_per_person'])->toBeNull()->and($paket['price_complete'])->toBeFalse()->and($paket['price_reason'])->toBe('ek_unvollstaendig');
});

it('eigene Kopie eines Abo-Pakets: Preis mit den Team-Preisen des Mandanten, ohne vollständigen EK kein Preis', function () {
    $inhalte = app(InhaltsFreigabeService::class);
    $this->actingAs($this->broichChef);
    $kopieB = $inhalte->kopieAnlegen($this->broich, 'paket', $this->paketF->id, $this->broichChef);
    $erwartet = $this->preis->fuer($this->paketF, $this->broich);
    expect((float) $kopieB->price_per_person)->toBe($erwartet['vk_pro_person'])
        ->and((float) $kopieB->ek_per_person)->toBe($erwartet['ek_pro_person']);

    $this->actingAs($this->neuChef);
    $kopieN = $inhalte->kopieAnlegen($this->neu, 'paket', $this->paketF->id, $this->neuChef);
    expect($kopieN->price_per_person)->toBeNull()->and($kopieN->ek_per_person)->toBeNull();
});

it('Leck-Sperre · eine Overlay-Instanz ist nicht dirty und save()/update() wirft, die Fassung in der DB bleibt ohne Preis', function () {
    $overlay = app(PaketService::class)->detail($this->broich, $this->paketF->id);
    expect((float) $overlay->price_per_person)->toBeGreaterThan(0.0)
        ->and($overlay->preisOverlay)->toBeTrue()
        ->and($overlay->isDirty(['price_per_person', 'ek_per_person', 'food_cost_percent', 'price_mode']))->toBeFalse();

    expect(fn () => $overlay->save())->toThrow(\LogicException::class, 'Overlay-Instanz')
        ->and(fn () => $overlay->update(['note' => 'x']))->toThrow(\LogicException::class)
        ->and(fn () => $overlay->touch())->toThrow(\LogicException::class);

    $roh = DB::table('foodalchemist_packages')->where('id', $this->paketF->id)->first();
    expect($roh->price_per_person)->toBeNull()->and($roh->ek_per_person)->toBeNull()->and($roh->food_cost_percent)->toBeNull();

    // Nicht-Fassungen sind keine Overlay-Instanz (Master speichert wie bisher)
    $eigen = app(PaketService::class)->detail($this->master, $this->paket->id);
    expect($eigen->preisOverlay)->toBeFalse();
    $eigen->update(['note' => 'ok']);
    expect(FoodAlchemistPaket::find($this->paket->id)->note)->toBe('ok');
});
