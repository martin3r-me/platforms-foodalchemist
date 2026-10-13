<?php

use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Exceptions\FaRechtFehltException;
use Platform\FoodAlchemist\Models\FoodAlchemistProjekt;
use Platform\FoodAlchemist\Services\FaRechte;
use Platform\FoodAlchemist\Services\ProjektService;
use Platform\FoodAlchemist\Support\FaBereiche;
use Platform\FoodAlchemist\Support\MasterTeam;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 82 §9 (Entscheidung Dominique 13.10.): Projekt = Agentur-Akte im MASTER-Team. Daten, Service, Master-only-Bereich.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->master = Team::create(['name' => 'BHG.DIGITAL', 'user_id' => 1, 'personal_team' => false]);
    config(['foodalchemist.master_team_id' => $this->master->id]);
    MasterTeam::flush();
    $this->kurator = $this->makeUser($this->master, 'Kurator', 'member');
    $this->leser = $this->makeUser($this->master, 'Gast', 'viewer');
    $this->masterAdmin = $this->makeUser($this->master, 'Master-Admin', 'admin');
    $this->admin = $this->makeUser($this->rootTeam, 'Plattform-Admin');
    config(['platform-shell.admins' => [strtolower($this->admin->email)]]);
    $this->firma = \Platform\Crm\Models\CrmCompany::create(['team_id' => $this->master->id, 'name' => 'Soulkitchen GmbH', 'is_active' => true]);
    $this->svc = app(ProjektService::class);
});

it('Projekt anlegen: Master-Team, CRM-Firma aus dem Master-CRM, Standardwerte', function () {
    $p = $this->svc->anlegen($this->master, $this->kurator, $this->firma->id, '  Soulkitchen LAGO  ', 'Neues Gastro-Konzept');

    expect($p->team_id)->toBe((int) $this->master->id)->and($p->name)->toBe('Soulkitchen LAGO')->and($p->crm_company_id)->toBe((int) $this->firma->id)
        ->and($p->phase)->toBe('zielbild')->and($p->status)->toBe('aktiv')->and($p->ziel)->toBe('Neues Gastro-Konzept')->and($p->mandant_team_id)->toBeNull();
    expect($this->svc->liste($this->master)->pluck('id')->all())->toBe([$p->id]);
});

it('Projekt anlegen: nur im Master-Team, nur ab Kuratieren, nur mit Master-CRM-Firma, Name Pflicht', function () {
    expect(fn () => $this->svc->anlegen($this->rootTeam, $this->admin, $this->firma->id, 'X'))->toThrow(\RuntimeException::class, 'Master-Team');
    expect(fn () => $this->svc->anlegen($this->master, $this->leser, $this->firma->id, 'X'))->toThrow(FaRechtFehltException::class);
    expect(fn () => $this->svc->anlegen($this->master, $this->kurator, 987654, 'X'))->toThrow(\RuntimeException::class, 'CRM-Firma');
    $fremd = \Platform\Crm\Models\CrmCompany::create(['team_id' => $this->rootTeam->id, 'name' => 'Fremde Firma', 'is_active' => true]);
    expect(fn () => $this->svc->anlegen($this->master, $this->kurator, $fremd->id, 'X'))->toThrow(\RuntimeException::class, 'CRM-Firma');
    expect(fn () => $this->svc->anlegen($this->master, $this->kurator, $this->firma->id, '  '))->toThrow(\RuntimeException::class, 'Namen');
    expect(FoodAlchemistProjekt::count())->toBe(0);
});

it('Kopf pflegen: Name, Ziel, Phase, Status, Planner-Verweis; Unbekanntes wird abgewiesen', function () {
    $p = $this->svc->anlegen($this->master, $this->kurator, $this->firma->id, 'Akte');

    $p = $this->svc->setze($this->master, $this->kurator, $p->id, ['name' => 'Akte Soul', 'ziel' => 'Ziel', 'phase' => 'dna', 'status' => 'pausiert', 'planner_projekt_id' => '42']);
    expect($p->name)->toBe('Akte Soul')->and($p->phase)->toBe('dna')->and($p->status)->toBe('pausiert')->and($p->planner_projekt_id)->toBe(42);
    $p = $this->svc->setze($this->master, $this->kurator, $p->id, ['planner_projekt_id' => null, 'ziel' => '']);
    expect($p->planner_projekt_id)->toBeNull()->and($p->ziel)->toBeNull()->and($p->name)->toBe('Akte Soul');   // nicht Übergebenes bleibt

    foreach ([['phase' => 'x'], ['status' => 'x'], ['name' => ' '], ['planner_projekt_id' => 'abc']] as $falsch) {
        expect(fn () => $this->svc->setze($this->master, $this->kurator, $p->id, $falsch))->toThrow(\RuntimeException::class);
    }
    expect(fn () => $this->svc->setze($this->master, $this->leser, $p->id, ['name' => 'Nein']))->toThrow(FaRechtFehltException::class);
});

it('Kunden-Standorte: der erste ist Pilot, genau einer ist Pilot, Löschen des Pilots rückt nach, Duplikate abgewiesen', function () {
    $p = $this->svc->anlegen($this->master, $this->kurator, $this->firma->id, 'Akte');
    $lago = $this->svc->standortAnlegen($this->master, $this->kurator, $p->id, 'LAGO');
    $see = $this->svc->standortAnlegen($this->master, $this->kurator, $p->id, 'Seeterrasse', 'Sommer');

    expect($lago->ist_pilot)->toBeTrue()->and($see->ist_pilot)->toBeFalse()->and($p->fresh()->pilot_standort_id)->toBe((int) $lago->id);
    expect(fn () => $this->svc->standortAnlegen($this->master, $this->kurator, $p->id, ' lago '))->toThrow(\RuntimeException::class, 'schon');

    $this->svc->pilotSetzen($this->master, $this->kurator, $p->id, $see->id);
    expect($lago->fresh()->ist_pilot)->toBeFalse()->and($see->fresh()->ist_pilot)->toBeTrue()->and($p->fresh()->pilot_standort_id)->toBe((int) $see->id);

    $this->svc->standortLoeschen($this->master, $this->kurator, $p->id, $see->id);
    expect($p->fresh()->pilot_standort_id)->toBe((int) $lago->id)->and($lago->fresh()->ist_pilot)->toBeTrue()->and($p->standorte()->count())->toBe(1);

    // über den Kopf: Pilot setzen, fremder Standort abgewiesen
    $andere = $this->svc->anlegen($this->master, $this->kurator, $this->firma->id, 'Andere Akte');
    $fremderStandort = $this->svc->standortAnlegen($this->master, $this->kurator, $andere->id, 'Fremd');
    expect(fn () => $this->svc->setze($this->master, $this->kurator, $p->id, ['pilot_standort_id' => $fremderStandort->id]))->toThrow(\RuntimeException::class, 'gehört nicht');
    $this->svc->setze($this->master, $this->kurator, $p->id, ['pilot_standort_id' => null]);
    expect($p->fresh()->pilot_standort_id)->toBeNull()->and($lago->fresh()->ist_pilot)->toBeFalse();
});

it('Master-only-Bereich „projekte“: im Master-Team an, überall sonst aus — auch für den Plattform-Admin', function () {
    $rechte = app(FaRechte::class);

    expect(FaBereiche::NUR_MASTER)->toBe(['projekte'])->and(FaBereiche::KATALOG)->toHaveKey('projekte');
    expect($rechte->bereichAktiv($this->master, 'projekte'))->toBeTrue()->and($rechte->darfBereich($this->kurator, $this->master, 'projekte'))->toBeTrue()
        ->and($rechte->teamBereiche($this->master)['projekte'])->toBeTrue();
    expect($rechte->bereichAktiv($this->rootTeam, 'projekte'))->toBeFalse()->and($rechte->teamBereiche($this->rootTeam)['projekte'])->toBeFalse()
        ->and($rechte->darfBereich($this->admin, $this->rootTeam, 'projekte'))->toBeFalse()                                // kein Admin-Durchgriff
        ->and($rechte->darfBereich($this->makeUser($this->childA, 'Kind'), $this->childA, 'projekte'))->toBeFalse();
    // andere Bereiche bleiben beim Admin-Durchgriff
    expect($rechte->darfBereich($this->admin, $this->rootTeam, 'rezepte'))->toBeTrue();
    // im Master ausdrücklich abschaltbar
    $rechte->setzeTeamBereich($this->master, $this->admin, 'projekte', false);
    expect($rechte->bereichAktiv($this->master, 'projekte'))->toBeFalse();
});

it('Navigation: „Projekte“ erscheint nur im Master-Team, auch der Plattform-Admin sieht sie woanders nicht', function () {
    $nav = [['group' => 'Übersicht', 'items' => [['label' => 'Dashboard', 'route' => 'foodalchemist.dashboard'], ['label' => 'Projekte', 'route' => 'foodalchemist.projekte.index']]]];
    $labels = fn ($user) => collect(FaBereiche::sichtbareNavigation($nav, $user))->flatMap(fn ($g) => $g['items'])->pluck('label')->all();

    expect($labels($this->kurator))->toContain('Projekte');
    $this->admin->update(['current_team_id' => $this->rootTeam->id]);
    expect($labels($this->admin->fresh()))->toBe(['Dashboard']);
    expect($labels($this->makeUser($this->childA, 'Kind')))->toBe(['Dashboard']);
});

it('Projekte außerhalb des Masters: Liste und Zugriff weisen ab; die Akte eines anderen Teams ist nicht sichtbar', function () {
    $p = $this->svc->anlegen($this->master, $this->kurator, $this->firma->id, 'Akte');

    expect(fn () => $this->svc->liste($this->rootTeam))->toThrow(\RuntimeException::class, 'Master-Team');
    expect(fn () => $this->svc->finde($this->rootTeam, $p->id))->toThrow(\RuntimeException::class, 'Master-Team');
    expect(FoodAlchemistProjekt::visibleToTeam($this->rootTeam)->count())->toBe(0);
});
