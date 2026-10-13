<?php

use Livewire\Livewire;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Exceptions\FaRechtFehltException;
use Platform\FoodAlchemist\Livewire\Projekte\Detail;
use Platform\FoodAlchemist\Models\FoodAlchemistFoodbook;
use Platform\FoodAlchemist\Services\CanvasService;
use Platform\FoodAlchemist\Services\FoodbookService;
use Platform\FoodAlchemist\Services\ProjektService;
use Platform\FoodAlchemist\Support\MasterTeam;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;
use Platform\FoodAlchemist\Tools\ProjekteGetTool;
use Platform\FoodAlchemist\Tools\ProjektePutTool;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 82 §9 · Paketlogik, hartes Gate „Struktur + Musterseite freigeben“ und Foodbook aus der Akte.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->master = Team::create(['name' => 'BHG.DIGITAL', 'user_id' => 1, 'personal_team' => false]);
    config(['foodalchemist.master_team_id' => $this->master->id]);
    MasterTeam::flush();
    $this->kurator = $this->makeUser($this->master, 'Kurator', 'member');
    $this->leser = $this->makeUser($this->master, 'Gast', 'viewer');
    $this->firma = \Platform\Crm\Models\CrmCompany::create(['team_id' => $this->master->id, 'name' => 'Soulkitchen GmbH', 'is_active' => true]);
    $this->svc = app(ProjektService::class);
    $this->projekt = $this->svc->anlegen($this->master, $this->kurator, $this->firma->id, 'Soulkitchen LAGO', 'Ein Haus am See');
    $this->canvas = app(CanvasService::class);
    $this->akte = fn () => $this->canvas->canvasFor($this->master, 'projekt', 'projekt', $this->projekt->id);
    $this->paketlogikFuellen = function () {
        $c = ($this->akte)();
        $this->canvas->addEntry($c, 'paket_stufen', 'Basis', ['preis' => '45']);
        $this->canvas->addEntry($c, 'paket_stufen', 'Premium', ['preis' => '95']);
        $this->canvas->addEntry($c, 'paket_anlaesse', 'Hochzeit');
        $this->canvas->setSkalar($c, 'musterseite', 'Eine Seite je Paket mit Foto links.');
        $this->canvas->setSkalar($c, 'zielbild_wortlaut', 'Wie ein Sommerabend am See.');
    };
    $this->kontakt = fn () => \Platform\Crm\Models\CrmContact::create(['team_id' => $this->master->id, 'first_name' => 'Anna', 'last_name' => 'Soul', 'is_active' => true]);
    $this->actingAs($this->kurator);
});

it('Gate offen: ohne Stufe, Anlass und Musterseite keine Freigabe, und die Meldung nennt, was fehlt', function () {
    expect($this->svc->gateFehlt($this->projekt))->toBe(['mindestens eine Paketstufe', 'mindestens ein Anlass', 'die Musterseite']);
    expect(fn () => $this->svc->strukturFreigeben($this->master, $this->kurator, $this->projekt->id, 1))->toThrow(\RuntimeException::class, 'Paketstufe');
    expect($this->projekt->fresh()->strukturFreigegeben())->toBeFalse();
});

it('HARTES Gate: ohne Freigabe entsteht kein Foodbook aus der Akte — weder über den Service noch direkt am Modell', function () {
    ($this->paketlogikFuellen)();   // vollständig, aber nicht freigegeben

    expect(fn () => $this->svc->foodbookAnlegen($this->master, $this->kurator, $this->projekt->id))->toThrow(\RuntimeException::class, 'Gate');
    expect(fn () => app(FoodbookService::class)->create($this->master, ['label' => 'Umweg', 'projekt_id' => $this->projekt->id]))->toThrow(\RuntimeException::class, 'Gate');
    expect(fn () => FoodAlchemistFoodbook::create(['team_id' => $this->master->id, 'label' => 'Direkt', 'projekt_id' => $this->projekt->id]))->toThrow(\RuntimeException::class, 'Gate');
    expect(FoodAlchemistFoodbook::where('projekt_id', $this->projekt->id)->count())->toBe(0);
    // ein gewöhnliches Foodbook ohne Projekt bleibt unberührt
    expect(app(FoodbookService::class)->create($this->master, ['label' => 'Normal'])->projekt_id)->toBeNull();
});

it('Freigabe: Person = CRM-Kontakt + Zeitpunkt; danach legt der Service das vorbefüllte Foodbook im Master an', function () {
    ($this->paketlogikFuellen)();
    $k = ($this->kontakt)();

    $p = $this->svc->strukturFreigeben($this->master, $this->kurator, $this->projekt->id, $k->id);
    expect($p->strukturFreigegeben())->toBeTrue()->and($p->struktur_freigegeben_von_contact_id)->toBe((int) $k->id)->and($p->struktur_freigegeben_von_name)->toContain('Soul');

    $fb = $this->svc->foodbookAnlegen($this->master, $this->kurator, $this->projekt->id);
    expect($fb->team_id)->toBe((int) $this->master->id)->and($fb->projekt_id)->toBe((int) $this->projekt->id)->and($fb->crm_company_id)->toBe((int) $this->firma->id)
        ->and($this->projekt->fresh()->phase)->toBe('foodbook');
    $werte = $this->canvas->werte($this->canvas->find('foodbook', 'foodbook', $fb->id));
    expect($werte['leitidee'])->toBe('Wie ein Sommerabend am See.')->and($werte['zweck_anforderungen'])->toBe('Ein Haus am See')
        ->and($werte['pflicht_konzepte'])->toContain('Basis, Premium')->and($werte['spektrum'])->toBe('Hochzeit')->and($werte['struktur_kapitel'])->toContain('Musterseite');
    expect($this->svc->foodbooks($this->master, $this->projekt->id)->pluck('id')->all())->toBe([$fb->id]);
});

it('Zurücknehmen schließt das Gate wieder, vorhandene Foodbooks bleiben, neue gehen nicht mehr', function () {
    ($this->paketlogikFuellen)();
    $this->svc->strukturFreigeben($this->master, $this->kurator, $this->projekt->id, ($this->kontakt)()->id);
    $fb = $this->svc->foodbookAnlegen($this->master, $this->kurator, $this->projekt->id);

    $p = $this->svc->strukturZuruecknehmen($this->master, $this->kurator, $this->projekt->id);

    expect($p->strukturFreigegeben())->toBeFalse()->and($p->struktur_freigegeben_von_name)->toBeNull()->and(FoodAlchemistFoodbook::find($fb->id))->not->toBeNull();
    expect(fn () => $this->svc->foodbookAnlegen($this->master, $this->kurator, $this->projekt->id))->toThrow(\RuntimeException::class, 'Gate');
});

it('Rechte und Kontakt: Leser darf nicht freigeben, fremder Kontakt wird abgewiesen', function () {
    ($this->paketlogikFuellen)();
    $k = ($this->kontakt)();
    expect(fn () => $this->svc->strukturFreigeben($this->master, $this->leser, $this->projekt->id, $k->id))->toThrow(FaRechtFehltException::class);
    $fremd = \Platform\Crm\Models\CrmContact::create(['team_id' => $this->rootTeam->id, 'first_name' => 'Fred', 'last_name' => 'Fremd', 'is_active' => true]);
    expect(fn () => $this->svc->strukturFreigeben($this->master, $this->kurator, $this->projekt->id, $fremd->id))->toThrow(\RuntimeException::class);
    expect(fn () => $this->svc->strukturFreigeben($this->master, $this->kurator, $this->projekt->id, 99999))->toThrow(\RuntimeException::class);
    expect($this->projekt->fresh()->strukturFreigegeben())->toBeFalse();
});

it('Seite: Gate-Abschnitt in der Phase Paketlogik, Freigabe und Zurücknehmen, Foodbook-Phase', function () {
    $k = ($this->kontakt)();
    $lw = Livewire::test(Detail::class, ['projekt' => $this->projekt->id])->call('phaseSetzen', 'paketlogik')
        ->assertSeeHtml('data-canvas-liste="paket_stufen"')->assertSeeHtml('data-projekt-gate')->assertSeeHtml('data-projekt-gate-fehlt');
    ($this->paketlogikFuellen)();

    $lw->call('$refresh')->assertDontSeeHtml('data-projekt-gate-fehlt')->assertSeeHtml('data-projekt-gate-suche')
        ->call('strukturFreigeben', $k->id)->assertSeeHtml('data-projekt-gate-frei')->assertSee('Soul')
        ->call('phaseSetzen', 'foodbook')->assertSeeHtml('data-projekt-foodbook-anlegen')->call('foodbookAnlegen')->assertSeeHtml('data-projekt-foodbook-zeile');
    expect(FoodAlchemistFoodbook::where('projekt_id', $this->projekt->id)->count())->toBe(1);

    $lw->call('phaseSetzen', 'paketlogik')->call('strukturZuruecknehmen')->assertDontSeeHtml('data-projekt-gate-frei');
    $lw->call('phaseSetzen', 'foodbook')->assertSeeHtml('data-projekt-foodbook-gate')->assertDontSeeHtml('data-projekt-foodbook-anlegen');
});

it('MCP: Gate-Stand lesbar, Freigabe und Foodbook über projekte.PUT, Leser abgewiesen', function () {
    ($this->paketlogikFuellen)();
    $k = ($this->kontakt)();
    $ctx = new ToolContext($this->kurator, $this->master);
    $put = app(ProjektePutTool::class);

    $vorher = app(ProjekteGetTool::class)->execute(['id' => $this->projekt->id], $ctx);
    expect($vorher->data['projekt']['struktur_freigegeben'])->toBeFalse()->and($vorher->data['projekt']['gate_fehlt'])->toBe([]);
    expect($put->execute(['id' => $this->projekt->id, 'foodbook_anlegen' => true], $ctx)->success)->toBeFalse();   // Gate zu

    $frei = $put->execute(['id' => $this->projekt->id, 'struktur_freigeben_kontakt_id' => $k->id, 'foodbook_anlegen' => true], $ctx);
    expect($frei->success)->toBeTrue()->and($frei->data['projekt']['struktur_freigegeben'])->toBeTrue()->and($frei->data['foodbook_id'])->not->toBeNull();

    expect($put->execute(['id' => $this->projekt->id, 'struktur_zuruecknehmen' => true], new ToolContext($this->leser, $this->master))->success)->toBeFalse();
});
