<?php

use Platform\Core\Models\Team;
use Platform\Crm\Models\CrmCompany;
use Platform\FoodAlchemist\Services\FoodbookService;
use Platform\FoodAlchemist\Support\CrmKunden;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 64 §1 · CRM-Kunden mandantensicher: ein Team sieht und verknüpft nur CRM-Firmen seines Haupt-Teams.
 * Vorher lieferte CompanyLinkService::searchCompanies() Firmen ALLER Teams (kein Team-Filter).
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->fremdTeam = Team::create(['name' => 'Fremder Kunde', 'user_id' => 1, 'personal_team' => false]);
    $this->eigen = CrmCompany::create(['team_id' => $this->rootTeam->id, 'name' => 'Hotel Adler', 'is_active' => true]);
    $this->fremd = CrmCompany::create(['team_id' => $this->fremdTeam->id, 'name' => 'Adler Catering (fremd)', 'is_active' => true]);
    CrmCompany::create(['team_id' => $this->rootTeam->id, 'name' => 'Adlerhorst (inaktiv)', 'is_active' => false]);
});

it('Suche liefert nur aktive Firmen des eigenen Haupt-Teams', function () {
    $namen = CrmKunden::firmen($this->rootTeam, 'Adler')->pluck('name')->all();
    expect($namen)->toBe(['Hotel Adler']);
});

it('Unter-Team sucht im CRM seines Haupt-Teams (CRM ist root-scoped)', function () {
    $kind = Team::create(['name' => 'Küche Nord', 'user_id' => 1, 'personal_team' => false, 'parent_team_id' => $this->rootTeam->id]);
    expect(CrmKunden::firmen($kind, 'Adler')->pluck('name')->all())->toBe(['Hotel Adler']);
});

it('Verknüpfen mit fremder Firma wird abgelehnt, eigene und Lösen gehen', function () {
    $fb = app(FoodbookService::class)->create($this->rootTeam, ['label' => 'Bankett']);

    expect(fn () => app(FoodbookService::class)->verknuepfeKunde($this->rootTeam, $fb->id, $this->fremd->id, null))
        ->toThrow(RuntimeException::class, 'nicht zu deinem Team');
    expect($fb->refresh()->crm_company_id)->toBeNull();

    app(FoodbookService::class)->verknuepfeKunde($this->rootTeam, $fb->id, $this->eigen->id, null);
    expect((int) $fb->refresh()->crm_company_id)->toBe((int) $this->eigen->id);

    app(FoodbookService::class)->verknuepfeKunde($this->rootTeam, $fb->id, null, null);
    expect($fb->refresh()->crm_company_id)->toBeNull();
});
