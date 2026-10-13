<?php

use Livewire\Livewire;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Livewire\Projekte\Detail;
use Platform\FoodAlchemist\Livewire\Projekte\Index;
use Platform\FoodAlchemist\Models\FoodAlchemistCanvasEntry;
use Platform\FoodAlchemist\Models\FoodAlchemistProjekt;
use Platform\FoodAlchemist\Services\CanvasService;
use Platform\FoodAlchemist\Services\ProjektService;
use Platform\FoodAlchemist\Support\MasterTeam;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 82 §9 · Projekt-Akte: Oberfläche (Liste, Detail, Listen-Felder im Board), Canvas-Typ „projekt“, MCP projekte.*.
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
    $this->actingAs($this->kurator);
});

it('Liste: zeigt Projekt mit Kunde und Phase, Anlegen über die Seite führt zum Detail', function () {
    $zweite = \Platform\Crm\Models\CrmCompany::create(['team_id' => $this->master->id, 'name' => 'Zentrag', 'is_active' => true]);

    Livewire::test(Index::class)->assertSee('Soulkitchen LAGO')->assertSee('Soulkitchen GmbH')->assertSee('Zielbild &amp; Umfang', false)
        ->call('firmaWaehlen', $zweite->id)->assertSet('name', 'Zentrag')->call('anlegen')->assertRedirect();

    expect(FoodAlchemistProjekt::count())->toBe(2);
});

it('Liste: Leser sieht keine Anlege-Box; Firma ausserhalb des Master-CRM wird abgewiesen', function () {
    $fremd = \Platform\Crm\Models\CrmCompany::create(['team_id' => $this->rootTeam->id, 'name' => 'Fremd', 'is_active' => true]);
    Livewire::test(Index::class)->call('firmaWaehlen', $fremd->id)->assertSet('firmaId', null);

    $this->actingAs($this->leser);
    Livewire::test(Index::class)->assertDontSeeHtml('data-projekt-anlegen-box');
});

it('Detail: Kopf, Brotkrumen, Standorte mit Pilot, Phasen-Reiter', function () {
    $lw = Livewire::test(Detail::class, ['projekt' => $this->projekt->id])
        ->assertSeeHtml('data-projekt-brotkrumen')->assertSee('Soulkitchen GmbH')
        ->set('kopf.ziel', 'Neues Konzept')->call('kopfSpeichern')->assertSet('meldung', 'Projektkopf gespeichert.')
        ->call('phaseSetzen', 'standort')->set('neuerStandort', 'LAGO')->call('standortAnlegen')->assertSee('LAGO')->assertSeeHtml('data-projekt-standort');
    expect($this->projekt->fresh()->ziel)->toBe('Neues Konzept')->and($this->projekt->fresh()->pilot_standort_id)->not->toBeNull();

    $lw->call('phaseSetzen', 'paketlogik')->call('phaseAlsStand');
    expect($this->projekt->fresh()->phase)->toBe('paketlogik');
});

it('Detail: fremdes Team bekommt 404, Leser darf nicht schreiben', function () {
    $this->actingAs($this->makeUser($this->rootTeam, 'Anderer', 'owner'));
    Livewire::test(Detail::class, ['projekt' => $this->projekt->id])->assertNotFound();

    $this->actingAs($this->leser);
    Livewire::test(Detail::class, ['projekt' => $this->projekt->id])->set('kopf.name', 'Hack')->call('kopfSpeichern')->assertSet('fehler', fn ($f) => $f !== null);
    expect($this->projekt->fresh()->name)->toBe('Soulkitchen LAGO');
});

it('Board-Listen-Felder: Eintrag mit Zusatzwerten, Check umschalten, Löschen, nur Phase-Gruppe', function () {
    $lw = Livewire::test(Detail::class, ['projekt' => $this->projekt->id])->call('phaseSetzen', 'zielbild')
        ->assertSeeHtml('data-canvas-liste="umfang_anlaesse"')->assertDontSeeHtml('data-canvas-liste="begehung"')
        ->set('canvasNeu.material.value', 'Speisekarte alt')->call('listeHinzu', 'material');

    $e = FoodAlchemistCanvasEntry::where('field_key', 'material')->first();
    expect($e)->not->toBeNull()->and($e->value)->toBe('Speisekarte alt')->and($e->meta['erhalten'])->toBeFalse();
    $lw->call('listeUmschalten', $e->id, 'erhalten');
    expect($e->fresh()->meta['erhalten'])->toBeTrue();
    $lw->call('listeUmschalten', $e->id, 'erhalten');
    expect($e->fresh()->meta['erhalten'])->toBeFalse();
    $lw->call('listeLoeschen', $e->id);
    expect(FoodAlchemistCanvasEntry::where('field_key', 'material')->count())->toBe(0);
});

it('Board-Listen-Felder: fremder Canvas-Eintrag wird weder umgeschaltet noch gelöscht', function () {
    $fremd = app(CanvasService::class)->canvasFor($this->rootTeam, 'food_dna', 'team', $this->rootTeam->id);
    $fe = $fremd->entries()->create(['field_key' => 'material', 'value' => 'x', 'position' => 0, 'meta' => ['erhalten' => false]]);

    Livewire::test(Detail::class, ['projekt' => $this->projekt->id])->call('listeUmschalten', $fe->id, 'erhalten')->call('listeLoeschen', $fe->id);

    expect($fe->fresh())->not->toBeNull()->and($fe->fresh()->meta['erhalten'])->toBeFalse();
});

it('Rollen: ein unbekannter CRM-Kontakt wird nicht verknüpft (Test-Stub hat keine Kontakttabelle)', function () {
    $lw = Livewire::test(Detail::class, ['projekt' => $this->projekt->id])->call('phaseSetzen', 'zielbild')
        ->set('canvasNeu.rollen.value', 'Geschäftsführung')->call('listeHinzu', 'rollen');
    $e = FoodAlchemistCanvasEntry::where('field_key', 'rollen')->first();

    $lw->call('kontaktWaehlen', 'rollen', 999999);   // unbekannter Kontakt: nichts verknüpft
    expect($e->fresh()->meta['crm_contact_id'] ?? null)->toBeNull();
});

it('Canvas-Tools: projekt nur im Master-Team, Werte gehen in den Projekt-Canvas', function () {
    $ctx = new ToolContext($this->kurator, $this->master);
    $put = app(\Platform\FoodAlchemist\Tools\CanvasPutTool::class)->execute(['type' => 'projekt', 'owner_id' => $this->projekt->id, 'felder' => ['zielbild_wortlaut' => 'Ein Haus am See']], $ctx);
    expect($put->success)->toBeTrue();
    $get = app(\Platform\FoodAlchemist\Tools\CanvasGetTool::class)->execute(['type' => 'projekt', 'owner_id' => $this->projekt->id], $ctx);
    expect($get->success)->toBeTrue()->and(json_encode($get->data))->toContain('Ein Haus am See');

    $fremd = app(\Platform\FoodAlchemist\Tools\CanvasPutTool::class)->execute(['type' => 'projekt', 'owner_id' => $this->projekt->id, 'felder' => ['zielbild_wortlaut' => 'x']], new ToolContext($this->makeUser($this->rootTeam, 'A', 'owner'), $this->rootTeam));
    expect($fremd->success)->toBeFalse();
});

it('MCP projekte: anlegen, lesen, pflegen, Standort; Nicht-Master und Leser abgewiesen', function () {
    $ctx = new ToolContext($this->kurator, $this->master);
    $post = app(\Platform\FoodAlchemist\Tools\ProjektePostTool::class)->execute(['crm_company_id' => $this->firma->id, 'name' => 'Zweite Akte'], $ctx);
    expect($post->success)->toBeTrue();
    $id = $post->data['projekt']['id'];

    $put = app(\Platform\FoodAlchemist\Tools\ProjektePutTool::class)->execute(['id' => $id, 'phase' => 'dna', 'standort_neu' => 'LAGO'], $ctx);
    expect($put->success)->toBeTrue()->and($put->data['projekt']['phase'])->toBe('dna')->and($put->data['projekt']['pilot_standort_id'])->not->toBeNull();

    $get = app(\Platform\FoodAlchemist\Tools\ProjekteGetTool::class)->execute(['id' => $id], $ctx);
    expect($get->data['projekt']['standorte'])->toHaveCount(1);
    expect(app(\Platform\FoodAlchemist\Tools\ProjekteGetTool::class)->execute([], $ctx)->data['projekte'])->toHaveCount(2);

    $leser = app(\Platform\FoodAlchemist\Tools\ProjektePutTool::class)->execute(['id' => $id, 'name' => 'Nein'], new ToolContext($this->leser, $this->master));
    expect($leser->success)->toBeFalse();
    $fremd = app(\Platform\FoodAlchemist\Tools\ProjekteGetTool::class)->execute([], new ToolContext($this->makeUser($this->rootTeam, 'B', 'owner'), $this->rootTeam));
    expect($fremd->success)->toBeFalse();
});
