<?php

use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplier;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItemStructure;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierMarke;
use Platform\FoodAlchemist\Services\Ai\AiGatewayService;
use Platform\FoodAlchemist\Services\Ai\AiProposal;
use Platform\FoodAlchemist\Services\GpAbgleich\GpAbgleichService;
use Platform\FoodAlchemist\Services\GpAbgleich\LaFelderService;
use Platform\FoodAlchemist\Services\GpNamingService;
use Platform\FoodAlchemist\Services\GpPaket\GpPaketValidator;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * GP-Regelwerk v3.7.1 §5 Ausnahme 3 — Anbindung: Klammer-Zusatz (einzige weitere Quelle = MarkeAusArtikel), rezepturware/marke_offen an Anlage und
 * Bearbeiten, Feld-Ableitung (gp.suggest), Abgleich (Rezeptur-GP mit anderer Marke → eigenes GP; die SPALTE entscheidet) und GP-Paket. KI gemockt.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->user = $this->makeUser($this->rootTeam, 'Root User');
    $this->actingAs($this->user);
    config(['foodalchemist.ai.provider' => 'fake', 'foodalchemist.bearbeitungssperre' => false, 'foodalchemist.master_team_id' => $this->rootTeam->id]);
    \Platform\FoodAlchemist\Support\MasterTeam::flush();
    $this->naming = app(GpNamingService::class);
    $this->cc = FoodAlchemistSupplier::create(['team_id' => $this->rootTeam->id, 'name' => 'Chefs Culinar West Weeze', 'rolle' => 'haendler']);
    $this->heisters = FoodAlchemistSupplier::create(['team_id' => $this->rootTeam->id, 'name' => 'Bäckerei Heisters', 'rolle' => 'hersteller', 'kurzname' => 'Heisters']);
    FoodAlchemistSupplierMarke::create(['supplier_id' => $this->cc->id, 'kuerzel' => 'bc', 'marke' => 'Base Culinar', 'typ' => 'eigenmarke']);
    $this->nr = 0;
    $this->la = fn (FoodAlchemistSupplier $s, string $bez, array $extra = []) => FoodAlchemistSupplierItem::create($extra + [
        'team_id' => $this->rootTeam->id, 'supplier_id' => $s->id, 'designation' => $bez, 'article_number' => 'R' . (++$this->nr), 'qty' => 1, 'unit_code' => 'kg']);
    $this->ki = function (array $suggest, ?array $abgleich = null) {
        $this->mock(AiGatewayService::class, fn ($m) => $m->shouldReceive('propose')->andReturnUsing(fn ($key, $kontext = []) => new AiProposal(
            $key === 'gp.suggest' ? $suggest : ($abgleich ?? ['aktion' => 'neu', 'begruendung' => 'x', 'sicherheit' => 0.5]), 0.8, 'Mock', [], $key)));
    };
    $this->ochsenbacke = ['hauptzutat' => 'Ochsenbacke', 'condition' => 'TK', 'processing' => 'gegart, in Sauce', 'commodity_group_code' => '13'];
});

it('Zusatz: zulässig ist zusätzlich NUR die Marke aus MarkeAusArtikel — Erfundenes bleibt verworfen', function () {
    expect($this->naming->zusatzAusArtikel('base culinar', ['BC Bierschinken'], 'Base Culinar'))->toBe('Base Culinar')
        ->and($this->naming->zusatzAusArtikel('Heisters', ['Brötchen Gouda belegt'], 'Heisters'))->toBe('Heisters')
        ->and($this->naming->zusatzAusArtikel('Chefs Culinar', ['Brötchen Gouda belegt'], 'Heisters'))->toBe('')
        ->and($this->naming->zusatzAusArtikel('Heisters', ['Brötchen Gouda belegt']))->toBe('');   // ohne Marken-Ergebnis wie bisher wörtlich
});

it('Anlage/Bearbeiten: marke_offen nur bei Rezepturware ohne Klammer; Wurst und Getränke nie Rezepturware', function () {
    $offen = $this->naming->createGp($this->rootTeam, $this->ochsenbacke + ['rezepturware' => true]);
    $mitMarke = $this->naming->createGp($this->rootTeam, ['hauptzutat' => 'Ochsenschwanz', 'condition' => 'TK', 'commodity_group_code' => '13', 'rezepturware' => '1', 'zusatz' => 'Block House']);
    $einzel = $this->naming->createGp($this->rootTeam, ['hauptzutat' => 'Mangopüree', 'condition' => 'TK', 'commodity_group_code' => '13', 'rezepturware' => false]);
    $wurst = $this->naming->createGp($this->rootTeam, ['hauptzutat' => 'Fleischwurst', 'condition' => 'frisch', 'commodity_group_code' => '04', 'rezepturware' => true]);
    $offenGelassen = $this->naming->createGp($this->rootTeam, ['hauptzutat' => 'Rotkohl', 'condition' => 'konserviert', 'commodity_group_code' => '13', 'rezepturware' => '']);

    expect([$offen->rezepturware, $offen->marke_offen])->toBe([true, true])
        ->and([$mitMarke->rezepturware, $mitMarke->marke_offen, $mitMarke->name])->toBe([true, false, 'Ochsenschwanz: TK / (Block House)'])
        ->and([$einzel->rezepturware, $einzel->marke_offen])->toBe([false, false])
        ->and([$wurst->rezepturware, $wurst->marke_offen])->toBe([false, false])
        ->and($offenGelassen->rezepturware)->toBeNull();

    $nachgetragen = $this->naming->updateGp($this->rootTeam, $offen, $this->ochsenbacke + ['zusatz' => 'Block House']);
    expect($nachgetragen->marke_offen)->toBeFalse()->and($nachgetragen->rezepturware)->toBeTrue();   // Teil-Schreiber ohne rezepturware lässt den Wert stehen
});

it('Feld-Ableitung: Rezepturware bekommt die Marke aus dem Dienst (Hersteller-Kurzname, Eigenmarken-Kürzel), Händler ohne Marke → marke_offen', function () {
    ($this->ki)($this->ochsenbacke + ['rezepturware' => true, 'zusatz' => '']);
    $felder = app(LaFelderService::class);

    $heisters = $felder->fuer($this->rootTeam, ($this->la)($this->heisters, 'Ochsenbacke in Sauce TK 2kg'));
    $bc = $felder->fuer($this->rootTeam, ($this->la)($this->cc, 'BC Ochsenbacke in Sauce TK 2kg'));
    $haendler = $felder->fuer($this->rootTeam, ($this->la)($this->cc, 'Ochsenbacke in Sauce TK 2kg'));

    expect([$heisters['zusatz'], $heisters['marke_offen'], $heisters['marke_regel']['quelle']])->toBe(['Heisters', false, 'kurzname'])
        ->and([$bc['zusatz'], $bc['marke_offen']])->toBe(['Base Culinar', false])
        ->and([$haendler['zusatz'], $haendler['marke_offen'], $haendler['rezepturware']])->toBe(['', true, true]);

    ($this->ki)(['hauptzutat' => 'Mangopüree', 'condition' => 'TK', 'commodity_group_code' => '13', 'rezepturware' => false]);
    $einzel = app(LaFelderService::class)->fuer($this->rootTeam, ($this->la)($this->heisters, 'Mangopüree TK 1kg'));
    expect([$einzel['zusatz'], $einzel['marke_offen']])->toBe(['', false]);   // Einzelzutat ohne Marke, auch beim Hersteller
});

it('Abgleich: Rezeptur-GP mit anderer Marke → neu (eigenes GP je Hersteller); gleiche Marke → verknüpfen; die Spalte entscheidet, nicht der Prompt', function () {
    $gp = FoodAlchemistGp::create(['team_id' => $this->rootTeam->id, 'name' => 'Ochsenbacke: TK, gegart, in Sauce / (Block House)', 'gp_key' => 'ochsenbacke_bh',
        'status' => 'approved', 'requires_la' => true, 'commodity_group_code' => '13', 'condition' => 'TK', 'processing' => 'gegart, in Sauce', 'zusatz' => 'Block House',
        'main_ingredient_slug' => 'ochsenbacke', 'main_ingredient_display' => 'Ochsenbacke', 'rezepturware' => true]);
    $vorhanden = ($this->la)($this->cc, 'Ochsenbacke Block House TK', ['brand' => 'Block House']);
    FoodAlchemistSupplierItemStructure::create(['team_id' => $this->rootTeam->id, 'supplier_item_id' => $vorhanden->id, 'gp_id' => $gp->id]);
    $verknuepfen = ['aktion' => 'verknuepfen', 'gp_id' => $gp->id, 'begruendung' => 'Gleiche Ware.', 'sicherheit' => 0.9];

    ($this->ki)($this->ochsenbacke + ['rezepturware' => true], $verknuepfen);
    $andere = app(GpAbgleichService::class)->entscheide($this->rootTeam, ($this->la)($this->cc, 'Ochsenbacke in Sauce TK', ['brand' => 'Gourmet Küche']));
    expect($andere->aktion)->toBe('neu')->and($andere->begruendung)->toContain('eigenes GP je Hersteller')->and($andere->begruendung)->toContain('Gourmet Küche');

    $gleiche = app(GpAbgleichService::class)->entscheide($this->rootTeam, ($this->la)($this->cc, 'Ochsenbacke in Sauce TK 2', ['brand' => 'Block House']));
    expect($gleiche->aktion)->toBe('verknuepfen')->and($gleiche->gpId)->toBe($gp->id);

    $gp->update(['rezepturware' => null]);   // nicht entschieden: Marke ist (wie bisher) kein Unterscheidungsmerkmal
    $ohneSpalte = app(GpAbgleichService::class)->entscheide($this->rootTeam, ($this->la)($this->cc, 'Ochsenbacke in Sauce TK 3', ['brand' => 'Gourmet Küche']));
    expect($ohneSpalte->aktion)->toBe('verknuepfen');
});

it('Abgleich ausführen (neu): das angelegte GP trägt rezepturware und marke_offen nach Regel', function () {
    ($this->ki)($this->ochsenbacke + ['rezepturware' => true]);
    $la = ($this->la)($this->cc, 'Ochsenbacke in Sauce TK 2kg');
    $dienst = app(GpAbgleichService::class);

    $r = $dienst->fuehreAus($this->rootTeam, $la, $dienst->entscheide($this->rootTeam, $la));

    expect($r['ausgefuehrt'])->toBe('neu_angelegt')->and($r['gp']->rezepturware)->toBeTrue()->and($r['gp']->marke_offen)->toBeTrue()
        ->and($r['gp']->zusatz)->toBeNull();   // Händler ohne Marke: ohne Klammer, nie „Chefs Culinar“
});

it('GP-Paket: rezepturware und marke_offen reisen mit (Whitelist, Schreib-Spalten)', function () {
    expect(GpPaketValidator::GP_SPALTEN)->toContain('rezepturware')->toContain('marke_offen')
        ->and(GpPaketValidator::WRITE_SPALTEN)->toContain('rezepturware')->toContain('marke_offen');
});
