<?php

use Illuminate\Support\Facades\DB;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Models\FoodAlchemistItemAllergen;
use Platform\FoodAlchemist\Models\FoodAlchemistItemDeclaration;
use Platform\FoodAlchemist\Models\FoodAlchemistItemNutritional;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplier;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierGroup;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItemStructure;
use Platform\FoodAlchemist\Services\DarreichungService;
use Platform\FoodAlchemist\Services\GpAggregateService;
use Platform\FoodAlchemist\Services\KostenCacheService;
use Platform\FoodAlchemist\Services\LeadLaService;
use Platform\FoodAlchemist\Services\Preis\LeadSichtNachlauf;
use Platform\FoodAlchemist\Services\PriceService;
use Platform\FoodAlchemist\Services\RecipeRecomputeService;
use Platform\FoodAlchemist\Support\StammPaket;
use Platform\FoodAlchemist\Support\TeamAncestryRegistry;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;
use Platform\FoodAlchemist\Tools\GpLaPutTool;
use Platform\FoodAlchemist\Tools\LeadSichtGetTool;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Lead-Sicht (Kurator/Dominique 29.10.2026). Mandant mit Stamm-Paket, Lieferant A freigeschaltet, B nicht; der globale Lead des GP liegt bei B.
 *  (a) EK: ein für das Team unsichtbarer Lead wird übergangen wie ein inaktiver Lieferant — Ersatz nach Rangliste statt Ø aller sichtbaren Artikel.
 *  (b) Allergene/Zusatzstoffe: globale GPs bleiben ALL-MAX über alle Artikel; team-eigene GPs eines Mandanten zählen nur sichtbare. Nährwerte des
 *      Mandanten nur aus sichtbaren Artikeln. Verknüpfen und Pin nur mit sichtbaren Artikeln.
 *  (c) Verleih-Prüfung der Darreichung mit demselben effektiven Lead. Nachlauf: Vorschau per Befehl und MCP lead_sicht.GET.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->master = Team::create(['name' => 'Master', 'user_id' => 1, 'personal_team' => false]);
    config(['foodalchemist.master_team_id' => $this->master->id]);
    TeamAncestryRegistry::flushAll();
    StammPaket::flush();

    $this->gp = FoodAlchemistGp::create(['team_id' => null, 'gp_key' => 'ls|butter', 'name' => 'Butter', 'status' => 'approved', 'stamm_class' => StammPaket::PAKET]);
    $this->mkLief = fn (string $n, array $extra = []) => FoodAlchemistSupplier::create($extra + ['team_id' => null, 'name' => $n, 'stamm_class' => StammPaket::PAKET]);
    $this->liefA = ($this->mkLief)('Großhandel A');
    $this->liefB = ($this->mkLief)('Großhandel B');
    $this->mkLa = function (FoodAlchemistSupplier $l, string $n, ?float $listenpreis, ?FoodAlchemistGp $gp = null) {
        $la = FoodAlchemistSupplierItem::create(['team_id' => null, 'supplier_id' => $l->id, 'designation' => $n, 'qty' => 1.0, 'unit_code' => 'kg', 'stamm_class' => StammPaket::PAKET]);
        if ($gp !== null || func_num_args() < 4) {
            FoodAlchemistSupplierItemStructure::create(['team_id' => null, 'supplier_item_id' => $la->id, 'gp_id' => ($gp ?? $this->gp)->id]);
        }
        if ($listenpreis !== null) {
            app(PriceService::class)->createTeamPrice($this->master, $la, $listenpreis);   // BHG-Konditionen
        }

        return $la;
    };
    $this->freischalten = function (FoodAlchemistSupplier $l) {
        DB::table(StammPaket::LIEFERANTEN_TABELLE)->insert(['team_id' => $this->rootTeam->id, 'supplier_id' => $l->id, 'created_at' => now(), 'updated_at' => now()]);
        StammPaket::flush();
    };
    $this->laA = ($this->mkLa)($this->liefA, 'Butter 1 kg A', 9.0);
    $this->laB = ($this->mkLa)($this->liefB, 'Butter 1 kg B', 7.0);
    // B mit Gluten (Signal), A ohne; Nährwerte an beiden
    FoodAlchemistItemAllergen::create(['team_id' => null, 'supplier_item_id' => $this->laA->id, 'allergen_milk' => 'enthalten', 'allergen_gluten' => 'nicht_enthalten']);
    FoodAlchemistItemAllergen::create(['team_id' => null, 'supplier_item_id' => $this->laB->id, 'allergen_milk' => 'enthalten', 'allergen_gluten' => 'enthalten']);
    FoodAlchemistItemNutritional::create(['team_id' => null, 'supplier_item_id' => $this->laA->id, 'energy_kcal' => 700]);
    FoodAlchemistItemNutritional::create(['team_id' => null, 'supplier_item_id' => $this->laB->id, 'energy_kcal' => 999]);
    $this->gp->update(['lead_la_supplier_item_id' => $this->laB->id]);   // Plattform-Lead (Master) bei B

    StammPaket::freischalten($this->rootTeam);
    ($this->freischalten)($this->liefA);
    app(PriceService::class)->createTeamPrice($this->rootTeam, $this->laA, 8.0);   // Mandanten-Preis A
    // zweiter sichtbarer Artikel, teurer: der Ø (10 €) unterscheidet sich vom Ersatz nach Rangliste (8 €)
    $this->laA2 = ($this->mkLa)($this->liefA, 'Butter 1 kg A2', 12.0);
    app(PriceService::class)->createTeamPrice($this->rootTeam, $this->laA2, 12.0);

    $this->rezept = $this->makeRecipe($this->rootTeam, 'Buttersauce');
    $this->makeIngredient($this->rezept, 'Butter', $this->gp, '100');
    $this->masterRezept = $this->makeRecipe($this->master, 'Buttersauce Master');
    $this->makeIngredient($this->masterRezept, 'Butter', $this->gp, '100');

    $this->rechne = function () {
        app(RecipeRecomputeService::class)->recomputePipeline($this->rezept->id);

        return $this->rezept->fresh();
    };
    $this->teamKosten = fn () => app(KostenCacheService::class)->fuer($this->masterRezept->fresh(), $this->rootTeam);
    $this->basis = fn ($r) => $r->ek_price_basis?->value ?? $r->ek_price_basis;
});

it('(a) unsichtbarer Lead B: Rezept-EK, Team-Kosten und Editor rechnen mit dem Ersatz nach Rangliste (A, 8 €) statt dem Ø der sichtbaren (10 €)', function () {
    $r = ($this->rechne)();
    $k = ($this->teamKosten)();
    $editor = app(RecipeRecomputeService::class)->preisVariantenProGrammPublic(collect([$this->gp->fresh()]), $this->rootTeam)[$this->gp->id];

    expect((float) $r->ek_total_eur)->toBe(0.8)->and(($this->basis)($r))->toBe('lead')
        ->and((float) $k['ek_summe'])->toBe(0.8)->and($k['basis']?->value ?? $k['basis'])->toBe('lead')
        ->and($editor['lead'])->toBe(0.008)->and($editor['avg'])->toBe(0.01);
});

it('(a) Gegenprobe Master: sieht B und rechnet weiter mit dem Plattform-Lead zum Listenpreis (7 €)', function () {
    app(RecipeRecomputeService::class)->recomputePipeline($this->masterRezept->id);
    $m = $this->masterRezept->fresh();

    expect((float) $m->ek_total_eur)->toBe(0.7)->and(($this->basis)($m))->toBe('lead');
});

it('(a) kein sichtbarer bepreister Artikel: unbepreist, nie der Preis von B', function () {
    DB::table('foodalchemist_prices')->where('team_id', $this->rootTeam->id)->delete();   // Mandant ohne eigene Preise; die Listenpreise des Masters sieht er nicht
    $r = ($this->rechne)();
    $k = ($this->teamKosten)();

    expect($r->ek_total_eur)->toBeNull()->and($k['vollstaendig'])->toBeFalse()->and($k['ek_summe'])->toBeNull();
});

it('(a) gemeinsame Stelle: Lead unsichtbar UND Lieferant A inaktiv → bestellbarer Ersatz C (Rangliste), nicht A', function () {
    $this->liefA->update(['is_inactive' => true]);
    $liefC = ($this->mkLief)('Großhandel C');
    ($this->freischalten)($liefC);
    $laC = ($this->mkLa)($liefC, 'Butter 1 kg C', 15.0);
    app(PriceService::class)->createTeamPrice($this->rootTeam, $laC, 15.0);
    $r = ($this->rechne)();

    expect((float) $r->ek_total_eur)->toBe(1.5)->and(($this->basis)($r))->toBe('lead');
});

it('(a) Pin des Mandanten gewinnt weiter — auch gegen den Ersatz nach Rangliste', function () {
    $this->actingAs($this->makeUser($this->rootTeam, 'Mandant Admin', 'owner'));
    app(LeadLaService::class)->setzeLeitartikel($this->rootTeam, $this->gp, $this->laA2->id);
    $r = ($this->rechne)();

    expect((float) $r->ek_total_eur)->toBe(1.2)->and((float) ($this->teamKosten)()['ek_summe'])->toBe(1.2);
});

it('(c) Verleih-Prüfung mit dem effektiven Lead: Lead B beim Verleiher, unsichtbar → für den Mandanten keine Miete; Master und ohne Team Miete', function () {
    $verleih = FoodAlchemistSupplierGroup::whereNull('team_id')->where('code', 'VERLEIH')->first()
        ?? FoodAlchemistSupplierGroup::create(['team_id' => null, 'code' => 'VERLEIH', 'name' => 'Verleih & Eventausstattung']);
    $this->liefB->update(['group_id' => $verleih->id]);
    $svc = app(DarreichungService::class);

    expect($svc->istVerleihGp($this->gp->fresh(), $this->rootTeam))->toBeFalse()
        ->and($svc->istVerleihGp($this->gp->fresh(), $this->master))->toBeTrue()
        ->and($svc->istVerleihGp($this->gp->fresh()))->toBeTrue();
});

it('(b) Nährwerte: Mandant nur aus sichtbaren Artikeln (A 700 kcal), Master Ø über alle (849,5); GP-Panel mit Team-Sicht', function () {
    $r = ($this->rechne)();
    app(RecipeRecomputeService::class)->recomputePipeline($this->masterRezept->id);
    $agg = app(GpAggregateService::class);

    expect((float) $r->nutri_kcal_per_100g)->toBe(700.0)
        ->and((float) $this->masterRezept->fresh()->nutri_kcal_per_100g)->toBe(849.5)
        ->and($agg->naehrwerte($this->gp->fresh(), sicht: $this->rootTeam)['energy_kcal']['avg'])->toBe(700.0)
        ->and($agg->naehrwerte($this->gp->fresh(), sicht: $this->master)['energy_kcal']['avg'])->toBe(849.5)
        ->and($agg->naehrwerte($this->gp->fresh())['energy_kcal']['avg'])->toBe(849.5);
});

it('(b) Allergene globales Stamm-GP bleiben bewusst ALL-MAX über alle Artikel (Dominique): Gluten aus B auch beim Mandanten', function () {
    expect(($this->rechne)()->allergen_gluten)->toBe('enthalten');
});

it('(b) team-eigenes GP des Mandanten: Allergene und Zusatzstoffe nur aus sichtbaren Artikeln (Alt-Struktur auf B zählt nicht); Master-GP zählt alle', function () {
    $eigen = $this->makeGp($this->rootTeam, 'Kräuterbutter');
    $mstr = $this->makeGp($this->master, 'Kräuterbutter Master');
    foreach ([$eigen, $mstr] as $gp) {
        $a = ($this->mkLa)($this->liefA, 'KB A ' . $gp->id, null, $gp);
        $b = ($this->mkLa)($this->liefB, 'KB B ' . $gp->id, null, $gp);   // Alt-Struktur am Riegel vorbei (Bestand)
        FoodAlchemistItemAllergen::create(['team_id' => null, 'supplier_item_id' => $a->id, 'allergen_gluten' => 'nicht_enthalten']);
        FoodAlchemistItemAllergen::create(['team_id' => null, 'supplier_item_id' => $b->id, 'allergen_gluten' => 'enthalten']);
        FoodAlchemistItemDeclaration::create(['team_id' => null, 'supplier_item_id' => $b->id, 'with_dye' => 3]);
    }
    $agg = app(GpAggregateService::class);

    expect($agg->allergene($eigen->fresh())['gluten']['value']->value)->toBe('nicht_enthalten')
        ->and($agg->zusatzstoffeUndFreiwillig($eigen->fresh())['with_dye'])->toBeNull()
        ->and($agg->allergene($mstr->fresh())['gluten']['value']->value)->toBe('enthalten')
        ->and($agg->zusatzstoffeUndFreiwillig($mstr->fresh())['with_dye'])->toBe(3);
});

it('Riegel: Verknüpfen und Pin nur mit sichtbaren Artikeln — Dienst, MCP gp_la.PUT und Leitartikel', function () {
    $eigen = $this->makeGp($this->rootTeam, 'Kräuterbutter');
    $frei = ($this->mkLa)($this->liefB, 'Butter 250 g B', 7.0, null);   // ohne Struktur: verknüpfbar, aber für den Mandanten unsichtbar
    $user = $this->makeUser($this->rootTeam, 'Mandant Admin', 'owner');
    $this->actingAs($user);
    $svc = app(LeadLaService::class);

    expect(fn () => $svc->verknuepfen($this->rootTeam, $eigen, $frei->id))->toThrow(RuntimeException::class, 'nicht beziehen')
        ->and(fn () => $svc->pinnen($this->rootTeam, $this->gp, $this->laB->id))->toThrow(RuntimeException::class, 'nicht beziehen')
        ->and(fn () => $svc->setzeLeitartikel($this->rootTeam, $this->gp, $this->laB->id))->toThrow(RuntimeException::class, 'nicht beziehen');
    $r = app(GpLaPutTool::class)->execute(['gp_id' => $eigen->id, 'action' => 'link', 'la_item_id' => $frei->id], new ToolContext($user, $this->rootTeam));
    expect($r->success)->toBeFalse()->and((string) $r->error)->toContain('nicht beziehen')
        ->and(FoodAlchemistSupplierItemStructure::where('supplier_item_id', $frei->id)->exists())->toBeFalse();

    // Gegenzustand: ein sichtbarer Artikel lässt sich verknüpfen
    $sichtbar = ($this->mkLa)($this->liefA, 'Butter 250 g A', 8.0, null);
    $svc->verknuepfen($this->rootTeam, $eigen, $sichtbar->id);
    expect((int) FoodAlchemistSupplierItemStructure::where('supplier_item_id', $sichtbar->id)->value('gp_id'))->toBe((int) $eigen->id);
});

it('Nachlauf: Vorschau (Dienst + MCP lead_sicht.GET) nennt Mandant, GP, bisherigen und künftigen Lead, Rezepte — schreibt nichts; anwenden rechnet neu', function () {
    $this->rezept->forceFill(['ek_total_eur' => 9.99])->saveQuietly();   // alter Stand (Ø-Zeit), nicht neu gerechnet
    ($this->teamKosten)();                                               // Team-Kosten-Zeile anlegen
    $nachlauf = app(LeadSichtNachlauf::class);
    $v = $nachlauf->vorschau();

    expect($v)->toHaveCount(1)->and($v[0]['team_id'])->toBe((int) $this->rootTeam->id)
        ->and($v[0]['gps'][0])->toMatchArray(['gp_id' => (int) $this->gp->id, 'lead_la_id' => (int) $this->laB->id, 'neu_la_id' => (int) $this->laA->id, 'neu_lieferant' => 'Großhandel A'])
        ->and($v[0]['rezepte_eigen'])->toBe([(int) $this->rezept->id])
        ->and($v[0]['kosten_zeilen'])->toBe(1)
        ->and((float) $this->rezept->fresh()->ek_total_eur)->toBe(9.99);

    $kurator = $this->makeUser($this->master, 'Kurator', 'owner');
    $mcpMaster = app(LeadSichtGetTool::class)->execute([], new ToolContext($kurator, $this->master));
    $mandant = $this->makeUser($this->rootTeam, 'Mandant', 'owner');
    $mcpMandant = app(LeadSichtGetTool::class)->execute(['team_id' => 999999], new ToolContext($mandant, $this->rootTeam));   // team_id wird beim Mandanten ignoriert
    expect($mcpMaster->success)->toBeTrue()->and($mcpMaster->data['summe'])->toBe(['teams' => 1, 'gps' => 1, 'rezepte_eigen' => 1])
        ->and($mcpMandant->success)->toBeTrue()->and($mcpMandant->data['teams'][0]['team_id'])->toBe((int) $this->rootTeam->id)
        ->and((float) $this->rezept->fresh()->ek_total_eur)->toBe(9.99);

    $erg = $nachlauf->anwenden();
    expect($erg)->toMatchArray(['teams' => 1, 'kosten_veraltet' => 1, 'fehler' => []])
        ->and((float) $this->rezept->fresh()->ek_total_eur)->toBe(0.8)
        ->and((float) ($this->teamKosten)()['ek_summe'])->toBe(0.8);
});
