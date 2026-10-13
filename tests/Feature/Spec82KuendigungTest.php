<?php

use Illuminate\Support\Facades\DB;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Models\Team;
use Platform\Core\Tools\ToolRegistry;
use Platform\FoodAlchemist\Exceptions\FaRechtFehltException;
use Platform\FoodAlchemist\Models\FoodAlchemistComponentEquivalent;
use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Models\FoodAlchemistOfferBlock;
use Platform\FoodAlchemist\Models\FoodAlchemistOfferChapter;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplier;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItemStructure;
use Platform\FoodAlchemist\Services\AboKuendigungService;
use Platform\FoodAlchemist\Services\AboService;
use Platform\FoodAlchemist\Services\AngebotService;
use Platform\FoodAlchemist\Services\FassungService;
use Platform\FoodAlchemist\Services\InhaltsFreigabeService;
use Platform\FoodAlchemist\Services\PriceService;
use Platform\FoodAlchemist\Services\RecipeService;
use Platform\FoodAlchemist\Support\StammPaket;
use Platform\FoodAlchemist\Support\TeamAncestryRegistry;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 82 §12 D · Durchstich Schritt 5: Kündigung und Zurückziehen mit tiefer Kopie.
 *
 * Aufbau: Master-Jus mit Unter-Rezept Fond (beide veröffentlicht), Sammlung „Basis" an Broich und Soulkitchen.
 * Broich nutzt den Jus in einem OFFENEN Angebot und als Zutat eines eigenen Rezepts; ein ANGENOMMENES Angebot ist Historie.
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
    $this->masterKoch = $this->makeUser($this->master, 'Master Koch', 'member');
    $this->broichChef = $this->makeUser($this->broich, 'Broich Chef', 'owner');
    $this->soulChef = $this->makeUser($this->soul, 'Soul Chef', 'owner');

    $sup = FoodAlchemistSupplier::create(['team_id' => null, 'name' => 'Stamm-Metzger']);
    $this->knochen = FoodAlchemistGp::create(['team_id' => null, 'gp_key' => 'kd|knochen', 'name' => 'Kalbsknochen']);
    $la = FoodAlchemistSupplierItem::create(['team_id' => null, 'supplier_id' => $sup->id, 'designation' => 'Kalbsknochen 1 kg', 'qty' => 1.0, 'unit_code' => 'kg']);
    FoodAlchemistSupplierItemStructure::create(['team_id' => null, 'supplier_item_id' => $la->id, 'gp_id' => $this->knochen->id]);
    $this->knochen->update(['lead_la_supplier_item_id' => $la->id]);
    app(PriceService::class)->createTeamPrice($this->broich, $la, 4.00);

    $this->fond = $this->makeRecipe($this->master, 'Kalbsfond');
    $this->makeIngredient($this->fond, 'Kalbsknochen', $this->knochen, '1000');
    $this->jus = $this->makeRecipe($this->master, 'Kalbsjus');
    $this->makeIngredient($this->jus, 'Kalbsfond', null, '500')->update(['referenced_recipe_id' => $this->fond->id]);
    $this->zweit = $this->makeRecipe($this->master, 'Demi-glace');           // im Abo, aber von niemandem genutzt
    $this->makeIngredient($this->zweit, 'Kalbsknochen', $this->knochen, '300');
    FoodAlchemistComponentEquivalent::create(['team_id' => $this->master->id, 'source_kind' => 'recipe', 'source_id' => $this->fond->id,
        'alt_kind' => 'gp', 'alt_id' => $this->knochen->id, 'umrechnungsfaktor' => 1, 'standard_seite' => 'source']);

    $this->inhalte = app(InhaltsFreigabeService::class);
    $this->sammlung = $this->inhalte->sammlungAnlegen($this->master, 'Basis', null, $this->kurator);
    $this->inhalte->sammlungHinzu($this->master, $this->sammlung, 'recipe', [$this->jus->id, $this->zweit->id], $this->kurator);
    $this->abos = app(AboService::class);
    $this->aboBroich = $this->abos->abonnieren($this->master, $this->broich, $this->sammlung, $this->kurator);
    $this->aboSoul = $this->abos->abonnieren($this->master, $this->soul, $this->sammlung, $this->kurator);
    $fs = app(FassungService::class);
    $this->jusF = FoodAlchemistRecipe::mitFassungen()->findOrFail($fs->veroeffentlichen($this->master, $this->jus->id, 'Erst', $this->kurator)['fassung_id']);
    $this->fondF = FoodAlchemistRecipe::mitFassungen()->where('fassung_von_id', $this->fond->id)->sole();
    $this->zweitF = FoodAlchemistRecipe::mitFassungen()->findOrFail($fs->veroeffentlichen($this->master, $this->zweit->id, 'Erst', $this->kurator)['fassung_id']);

    // Broich nutzt den Jus: offenes Angebot (lebend), angenommenes Angebot (Historie), eigenes Rezept (Zutat)
    $this->actingAs($this->broichChef);
    $angebot = fn (string $status) => tap(app(AngebotService::class)->create($this->broich, ['name' => "Gala {$status}", 'personen' => 40]),
        fn ($a) => DB::table('foodalchemist_offers')->where('id', $a->id)->update(['status' => $status]));
    $block = function ($a) {
        $k = FoodAlchemistOfferChapter::create(['team_id' => $this->broich->id, 'offer_id' => $a->id, 'title' => 'Hauptgang', 'position' => 0]);

        return FoodAlchemistOfferBlock::create(['team_id' => $this->broich->id, 'chapter_id' => $k->id, 'type' => 'recipe_ref', 'position' => 0, 'visible' => true, 'sales_recipe_id' => $this->jusF->id]);
    };
    $this->blockOffen = $block($angebot('in_arbeit'));
    $this->blockHistorie = $block($angebot('angenommen'));
    $this->eigen = $this->makeRecipe($this->broich, 'Broich Kalbsrücken');
    $this->eigenZeile = $this->makeIngredient($this->eigen, 'Jus', null, '100');
    $this->eigenZeile->update(['referenced_recipe_id' => $this->jusF->id]);

    $this->svc = app(AboKuendigungService::class);
    $this->sieht = fn (Team $t) => FoodAlchemistRecipe::visibleToTeam($t)->pluck('foodalchemist_recipes.id')->map(fn ($v) => (int) $v)->all();
});

it('Vorschau (D4): genutzt = Jus (Angebot + eigenes Rezept), Fond geht als Kette mit, Demi-glace verschwindet — nichts passiert', function () {
    $r = $this->svc->kuendigen($this->broich, $this->aboBroich->id, $this->broichChef, bestaetigt: false);

    expect($r['ausgefuehrt'])->toBeFalse()
        ->and($r['vorschau']['genutzt_ids'])->toBe([$this->jusF->id])
        ->and($r['vorschau']['genutzt'][0]['wo'])->toContain('angebot', 'zutat')
        ->and($r['vorschau']['mitkopiert_ids'])->toBe([$this->fondF->id])
        ->and($r['vorschau']['verschwindet_ids'])->toBe([$this->zweitF->id])
        ->and(DB::table('foodalchemist_abos')->where('id', $this->aboBroich->id)->value('status'))->toBe('aktiv');
});

it('Kündigung (D1/D2): tiefe Kopie ohne Doppel, lebende Verweise umgehängt, Historie bleibt, Kopie rechnet mit Broichs Preisen', function () {
    $r = $this->svc->kuendigen($this->broich, $this->aboBroich->id, $this->broichChef, bestaetigt: true);
    $jusK = FoodAlchemistRecipe::findOrFail($r['kopien'][$this->jusF->id]);
    $fondK = FoodAlchemistRecipe::findOrFail($r['kopien'][$this->fondF->id]);

    expect(array_keys($r['kopien']))->toEqualCanonicalizing([$this->jusF->id, $this->fondF->id])   // Demi-glace nicht kopiert
        ->and((int) $jusK->team_id)->toBe($this->broich->id)->and((bool) $jusK->ist_fassung)->toBeFalse()
        ->and((int) $jusK->kopie_von_id)->toBe($this->jus->id)                                       // Familie → originalGeaendert()
        ->and($jusK->recipe_key)->not->toContain('@f')
        ->and((int) $jusK->ingredients()->value('referenced_recipe_id'))->toBe($fondK->id)          // Kette auf die Kopie
        ->and((int) $this->blockOffen->fresh()->sales_recipe_id)->toBe($jusK->id)                    // lebend: umgehängt
        ->and((int) $this->blockHistorie->fresh()->sales_recipe_id)->toBe($this->jusF->id)          // Historie: bleibt
        ->and((int) $this->eigenZeile->fresh()->referenced_recipe_id)->toBe($jusK->id)
        ->and(DB::table('foodalchemist_abos')->where('id', $this->aboBroich->id)->value('status'))->toBe('gekuendigt')
        // Broich sieht die Kopien (eigene), nicht mehr die Fassungen; die Fassung existiert weiter (Historie auflösbar)
        ->and(($this->sieht)($this->broich))->toContain($jusK->id, $fondK->id)->not->toContain($this->jusF->id, $this->zweitF->id)
        ->and($this->blockHistorie->fresh()->dish?->id)->toBe($this->jusF->id)
        // eigene Preise: Fond-Kopie = 1 kg Knochen × 4 € bei Broich
        ->and((float) $fondK->fresh()->ek_total_eur)->toBe(4.00)
        // Ersatz-Beziehung der Kette kam mit (Fond ↔ Knochen-GP)
        ->and(FoodAlchemistComponentEquivalent::where('team_id', $this->broich->id)->where('source_kind', 'recipe')->where('source_id', $fondK->id)->exists())->toBeTrue();

    // Soulkitchen unberührt
    expect(($this->sieht)($this->soul))->toContain($this->jusF->id, $this->zweitF->id);
});

it('D3: Ist das Rezept über ein zweites aktives Abo verfügbar, wird nichts kopiert und nichts verschwindet', function () {
    $zweite = $this->inhalte->sammlungAnlegen($this->master, 'Saucen', null, $this->kurator);
    $this->inhalte->sammlungHinzu($this->master, $zweite, 'recipe', [$this->jus->id, $this->zweit->id], $this->kurator);
    $this->abos->abonnieren($this->master, $this->broich, $zweite, $this->kurator);

    $r = $this->svc->kuendigen($this->broich, $this->aboBroich->id, $this->broichChef, bestaetigt: true);
    expect($r['kopien'])->toBe([])->and($r['vorschau']['verschwindet_ids'])->toBe([])
        ->and((int) $this->blockOffen->fresh()->sales_recipe_id)->toBe($this->jusF->id)
        ->and(($this->sieht)($this->broich))->toContain($this->jusF->id);
});

it('Zurückziehen (§8): Löschen eines abonnierten Rezepts gesperrt; Zurückziehen kopiert bei Broich, lässt es bei Soulkitchen verschwinden', function () {
    expect(app(RecipeService::class)->referenzen($this->jus->id)['abos'])->toBe(2)
        ->and(fn () => app(RecipeService::class)->delete($this->master, $this->jus->id))->toThrow(RuntimeException::class);

    $vorschau = $this->svc->zurueckziehen($this->master, $this->sammlung, $this->jus->id, $this->kurator, bestaetigt: false);
    $je = collect($vorschau['mandanten'])->keyBy('mandant_team_id');
    expect($je[$this->broich->id]['vorschau']['genutzt_ids'])->toBe([$this->jusF->id])
        ->and($je[$this->soul->id]['vorschau']['genutzt_ids'])->toBe([])
        ->and($je[$this->soul->id]['vorschau']['verschwindet_ids'])->toEqualCanonicalizing([$this->jusF->id, $this->fondF->id]);

    $r = $this->svc->zurueckziehen($this->master, $this->sammlung, $this->jus->id, $this->kurator, bestaetigt: true);
    expect($r['ausgefuehrt'])->toBeTrue()
        ->and(DB::table('foodalchemist_sammlung_objekte')->where('sammlung_id', $this->sammlung)->where('objekt_id', $this->jus->id)->exists())->toBeFalse()
        ->and(($this->sieht)($this->soul))->not->toContain($this->jusF->id)->toContain($this->zweitF->id)    // Abo selbst bleibt
        ->and((int) $this->blockOffen->fresh()->sales_recipe_id)->not->toBe($this->jusF->id)
        ->and(DB::table('foodalchemist_abos')->where('id', $this->aboBroich->id)->value('status'))->toBe('aktiv');
});

it('Rechte: Master mit „Freigeben" oder Oberteam-Admin des Mandanten — nicht Unter-Team, nicht fremder Mandant, nicht Master ohne Rolle', function () {
    $kitaChef = $this->makeUser($this->kita, 'Kita Chef', 'owner');
    expect(fn () => $this->svc->kuendigen($this->soul, $this->aboBroich->id, $this->soulChef, true))->toThrow(RuntimeException::class, 'Oberteam')
        ->and(fn () => $this->svc->kuendigen($this->kita, $this->aboBroich->id, $kitaChef, true))->toThrow(RuntimeException::class, 'Oberteam')
        ->and(fn () => $this->svc->kuendigen($this->master, $this->aboBroich->id, $this->masterKoch, true))->toThrow(FaRechtFehltException::class)
        ->and($this->svc->kuendigen($this->master, $this->aboBroich->id, $this->kurator, false)['ausgefuehrt'])->toBeFalse();
});

it('MCP abos.DELETE: Vorschau ohne bestaetigt, Ausführung mit; abos.WITHDRAW nur Master', function () {
    $reg = app(ToolRegistry::class);
    $this->actingAs($this->broichChef);
    $ctx = new ToolContext($this->broichChef, $this->broich);
    $v = $reg->get('foodalchemist.abos.DELETE')->execute(['abo_id' => $this->aboBroich->id], $ctx);
    expect($v->success)->toBeTrue((string) ($v->error ?? ''))->and($v->data['ausgefuehrt'])->toBeFalse();
    $x = $reg->get('foodalchemist.abos.DELETE')->execute(['abo_id' => $this->aboBroich->id, 'bestaetigt' => true], $ctx);
    expect($x->data['ausgefuehrt'])->toBeTrue()->and($x->data['kopien'])->toHaveCount(2);

    expect($reg->get('foodalchemist.abos.WITHDRAW')->execute(['sammlung_id' => $this->sammlung, 'recipe_id' => $this->zweit->id], $ctx)->errorCode)->toBe('VALIDATION_ERROR');
});
