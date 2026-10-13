<?php

use Livewire\Livewire;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Livewire\Projekte\Detail;
use Platform\FoodAlchemist\Livewire\Projekte\KundeDnaPhase;
use Platform\FoodAlchemist\Services\CanvasService;
use Platform\FoodAlchemist\Services\KontextResolver;
use Platform\FoodAlchemist\Services\ProjektService;
use Platform\FoodAlchemist\Support\MasterTeam;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;
use Platform\FoodAlchemist\Tools\CanvasPutTool;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 82 §9 · Kunden-DNA „Bleibt – unverhandelbar“ (= verbindlich) und Phase 3 der Projekt-Akte.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->master = Team::create(['name' => 'BHG.DIGITAL', 'user_id' => 1, 'personal_team' => false]);
    config(['foodalchemist.master_team_id' => $this->master->id]);
    MasterTeam::flush();
    $this->kurator = $this->makeUser($this->master, 'Kurator', 'member');
    $this->leser = $this->makeUser($this->master, 'Gast', 'viewer');
    $this->firma = \Platform\Crm\Models\CrmCompany::create(['team_id' => $this->master->id, 'name' => 'Soulkitchen GmbH', 'is_active' => true]);
    $this->projekt = app(ProjektService::class)->anlegen($this->master, $this->kurator, $this->firma->id, 'Soulkitchen LAGO');
    $this->svc = app(CanvasService::class);
    $this->res = app(KontextResolver::class);
    $this->setzeKunde = fn (string $key, string $wert, ?bool $bleibt = null) => $this->svc->setSkalar($this->svc->canvasFor($this->master, 'kunde_dna', 'crm_company', $this->firma->id), $key, $wert, $bleibt);
    $this->actingAs($this->kurator);
});

it('Phase 3: das Board schreibt die Kunden-DNA der CRM-Firma, „Bleibt“ schaltet verbindlich', function () {
    ($this->setzeKunde)('kommunikation_ton', 'Du, locker.');

    Livewire::test(Detail::class, ['projekt' => $this->projekt->id])->call('phaseSetzen', 'dna')
        ->assertSeeHtml('data-projekt-kunde-dna');
    $lw = Livewire::test(KundeDnaPhase::class, ['companyId' => $this->firma->id])->assertSeeHtml('data-canvas-verbindlich-schalter="kommunikation_ton"')->assertSee('Bleibt – unverhandelbar')
        ->call('verbindlichSchalten', 'kommunikation_ton');

    $canvas = $this->svc->find('kunde_dna', 'crm_company', $this->firma->id);
    expect($canvas->entries->firstWhere('field_key', 'kommunikation_ton')->is_binding)->toBeTrue();
    $lw->call('verbindlichSchalten', 'kommunikation_ton');
    expect($this->svc->find('kunde_dna', 'crm_company', $this->firma->id)->entries->firstWhere('field_key', 'kommunikation_ton')->is_binding)->toBeFalse();
});

it('Phase 3: Leser kann „Bleibt“ nicht setzen, eine fremde Firma ist 404', function () {
    ($this->setzeKunde)('kommunikation_ton', 'Du.');
    $this->actingAs($this->leser);
    Livewire::test(KundeDnaPhase::class, ['companyId' => $this->firma->id])->call('verbindlichSchalten', 'kommunikation_ton');
    expect($this->svc->find('kunde_dna', 'crm_company', $this->firma->id)->entries->firstWhere('field_key', 'kommunikation_ton')->is_binding)->toBeFalse();

    $fremd = \Platform\Crm\Models\CrmCompany::create(['team_id' => $this->rootTeam->id, 'name' => 'Fremd', 'is_active' => true]);
    $this->actingAs($this->kurator);
    Livewire::test(KundeDnaPhase::class, ['companyId' => $fremd->id])->assertNotFound();
});

it('KI-Kaskade: Bleibt-Felder stehen als eigener, geschützter Block, der Rest bleibt im Kunden-Block', function () {
    ($this->setzeKunde)('kommunikation_ton', 'BLEIBT-MARKER', true);
    ($this->setzeKunde)('preis_erwartung', 'REST-MARKER');

    $r = $this->res->fuer($this->master, ['crm_company_id' => $this->firma->id]);
    $ebenen = array_column($r['quellen'], 'ebene');

    expect($ebenen)->toContain('kunde_bleibt')->and($ebenen)->toContain('kunde')
        ->and($r['marken_kontext'])->toContain('Verbindliche Vorgaben des Kunden (Bleibt, nicht abweichen):')
        ->and($r['marken_kontext'])->toContain('Kommunikation / Ton: BLEIBT-MARKER')->and($r['marken_kontext'])->toContain('Preis-Erwartung: REST-MARKER');
    // „Bleibt“ steht nur einmal (nicht doppelt im Kunden-Block)
    expect(substr_count($r['marken_kontext'], 'BLEIBT-MARKER'))->toBe(1);
});

it('KI-Kaskade: Bleibt wird unter knappem Budget nie gekürzt, der Kunden-Rest schon', function () {
    config(['foodalchemist.kontext.budget_zeichen' => 1000]);
    ($this->setzeKunde)('kommunikation_ton', 'BLEIBT-MARKER', true);
    ($this->setzeKunde)('marke_positionierung', str_repeat('M', 3000));

    $r = $this->res->fuer($this->master, ['crm_company_id' => $this->firma->id]);

    expect($r['marken_kontext'])->toContain('BLEIBT-MARKER')->and(mb_strlen($r['marken_kontext']))->toBeLessThanOrEqual(1000)
        ->and(($r['bilanz']['gekuerzt'] ?? []) + ($r['bilanz']['verworfen'] ?? []))->toHaveKey('kunde');
});

it('KI-Kaskade: ohne Bleibt-Feld bleibt der Kunden-Block unverändert (Format wie der Canvas-Block)', function () {
    ($this->setzeKunde)('kommunikation_ton', 'Du.');
    ($this->setzeKunde)('preis_erwartung', '50 €');

    $r = $this->res->fuer($this->master, ['crm_company_id' => $this->firma->id]);

    expect(array_column($r['quellen'], 'ebene'))->not->toContain('kunde_bleibt')
        ->and($r['marken_kontext'])->toContain($this->svc->promptKontext($this->svc->find('kunde_dna', 'crm_company', $this->firma->id)));
});

it('MCP canvas.PUT: Bleibt an der Kunden-DNA ab Kuratieren, Leser abgewiesen, an anderen Typen weiter nicht erlaubt', function () {
    $tool = app(CanvasPutTool::class);
    $ok = $tool->execute(['type' => 'kunde_dna', 'owner_id' => $this->firma->id, 'felder' => ['kommunikation_ton' => 'Du.'], 'verbindlich' => ['kommunikation_ton' => true]], new ToolContext($this->kurator, $this->master));
    expect($ok->success)->toBeTrue()
        ->and($this->svc->find('kunde_dna', 'crm_company', $this->firma->id)->entries->firstWhere('field_key', 'kommunikation_ton')->is_binding)->toBeTrue();

    $leser = $tool->execute(['type' => 'kunde_dna', 'owner_id' => $this->firma->id, 'felder' => [], 'verbindlich' => ['kommunikation_ton' => false]], new ToolContext($this->leser, $this->master));
    expect($leser->success)->toBeFalse()
        ->and($this->svc->find('kunde_dna', 'crm_company', $this->firma->id)->entries->firstWhere('field_key', 'kommunikation_ton')->is_binding)->toBeTrue();

    $projekt = $tool->execute(['type' => 'projekt', 'owner_id' => $this->projekt->id, 'felder' => [], 'verbindlich' => ['zielbild_wortlaut' => true]], new ToolContext($this->kurator, $this->master));
    expect($projekt->success)->toBeFalse();
});
