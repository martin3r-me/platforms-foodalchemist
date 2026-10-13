<?php

use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Livewire\Projekte\Detail;
use Platform\FoodAlchemist\Models\FoodAlchemistVocabKochequipment;
use Platform\FoodAlchemist\Services\CanvasService;
use Platform\FoodAlchemist\Services\FoodbookService;
use Platform\FoodAlchemist\Services\KontextResolver;
use Platform\FoodAlchemist\Services\ProjektService;
use Platform\FoodAlchemist\Services\TeamProfilService;
use Platform\FoodAlchemist\Support\MasterTeam;
use Platform\FoodAlchemist\Support\Profiltraeger;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;
use Platform\FoodAlchemist\Tools\KontextResolveTool;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 82 §9 · KI-Kaskade der Agentur-Akte: Team → Standort → Kunde → Projekt, Kunden-„Bleibt“ mit Vorrang vor dem Team.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->master = Team::create(['name' => 'BHG.DIGITAL', 'user_id' => 1, 'personal_team' => false]);
    config(['foodalchemist.master_team_id' => $this->master->id]);
    MasterTeam::flush();
    $this->kurator = $this->makeUser($this->master, 'Kurator', 'owner');
    $this->firma = \Platform\Crm\Models\CrmCompany::create(['team_id' => $this->master->id, 'name' => 'Soulkitchen GmbH', 'is_active' => true]);
    $this->svc = app(ProjektService::class);
    $this->projekt = $this->svc->anlegen($this->master, $this->kurator, $this->firma->id, 'Soulkitchen LAGO', 'Ein Haus am See');
    $this->lago = $this->svc->standortAnlegen($this->master, $this->kurator, $this->projekt->id, 'LAGO');   // erster = Pilot
    $this->canvas = app(CanvasService::class);
    $this->res = app(KontextResolver::class);
    $this->kombi = FoodAlchemistVocabKochequipment::create(['team_id' => null, 'slug' => 'kombi', 'name' => 'Kombidämpfer', 'sort_order' => 10]);
    $this->team = fn (string $key, string $wert, bool $verb = false) => $this->canvas->setSkalar($this->canvas->canvasFor($this->master, 'food_dna', 'team', $this->master->id), $key, $wert, $verb);
    $this->kunde = fn (string $key, string $wert, ?bool $bleibt = null) => $this->canvas->setSkalar($this->canvas->canvasFor($this->master, 'kunde_dna', 'crm_company', $this->firma->id), $key, $wert, $bleibt);
    $this->ids = ['crm_company_id' => $this->firma->id, 'projekt_id' => $this->projekt->id];
    $this->actingAs($this->kurator);
});

it('Vorrang: Kunden-Bleibt steht VOR den Team-Vorgaben, mit Vorrang- und Konflikt-Anweisung; das Team trägt den Vorbehalt', function () {
    ($this->team)('no_gos', 'TEAM-MARKER Kein Fisch.', true);
    ($this->kunde)('erwartungen_nogos', 'KUNDE-MARKER Fisch ist Pflicht.', true);

    $r = $this->res->fuer($this->master, ['crm_company_id' => $this->firma->id]);
    $ebenen = array_column($r['quellen'], 'ebene');

    expect($ebenen[0])->toBe('kunde_bleibt')->and($ebenen)->toContain('vorgaben')
        ->and(strpos($r['marken_kontext'], 'KUNDE-MARKER'))->toBeLessThan(strpos($r['marken_kontext'], 'TEAM-MARKER'))
        ->and($r['marken_kontext'])->toContain('gehen sie VOR den Vorgaben des Teams')
        ->and($r['marken_kontext'])->toContain('benenne den Widerspruch im Ergebnis ausdrücklich')
        ->and($r['marken_kontext'])->toContain('Vorbehalt: Bei Widerspruch zu den Kunden-Vorgaben (Bleibt) gelten für diesen Kunden die Kunden-Vorgaben.');
});

it('Ohne Kunden-Bleibt bleibt der Team-Block unverändert (kein Vorbehalt, keine Vorrang-Anweisung)', function () {
    ($this->team)('no_gos', 'TEAM-MARKER', true);
    ($this->kunde)('kommunikation_ton', 'Du.');

    $r = $this->res->fuer($this->master, ['crm_company_id' => $this->firma->id]);

    expect($r['marken_kontext'])->not->toContain('Vorbehalt')->and($r['marken_kontext'])->not->toContain('VOR den Vorgaben')
        ->and(array_column($r['quellen'], 'ebene'))->toBe(['vorgaben', 'kunde']);
});

it('Kaskade mit Projekt: Teamprofil → Standort → Kunde → Projekt, der Standort kommt aus dem Pilot', function () {
    ($this->kunde)('kommunikation_ton', 'Du.');
    app(TeamProfilService::class)->setzeProfil($this->master, $this->kurator, ['max_pax_parallel' => 300]);                    // Master-Team (nicht der Kunde)
    app(TeamProfilService::class)->setzeProfil(Profiltraeger::standort($this->master, $this->lago->id), $this->kurator, ['max_pax_parallel' => 80]);
    app(TeamProfilService::class)->setzeEquipment(Profiltraeger::standort($this->master, $this->lago->id), $this->kurator, $this->kombi->id, 'nicht_vorhanden');
    $c = $this->canvas->canvasFor($this->master, 'projekt', 'projekt', $this->projekt->id);
    $this->canvas->setSkalar($c, 'zielbild_wortlaut', 'Wie ein Sommerabend am See.');
    $this->canvas->addEntry($c, 'paket_stufen', 'Premium', ['beschreibung' => 'Fünf Gänge', 'preis' => '95']);
    $this->canvas->addEntry($c, 'rollen', 'GESCHÄFTSFÜHRUNG-INTERN', ['rolle' => 'GF']);

    $r = $this->res->fuer($this->master, $this->ids);
    $ebenen = array_column($r['quellen'], 'ebene');

    expect($ebenen)->toBe(['teamprofil', 'standort', 'kunde', 'projekt'])
        ->and($r['marken_kontext'])->toContain('Küchen-Fakten des Kunden-Standorts LAGO')->and($r['marken_kontext'])->toContain('Nicht vorhanden: Kombidämpfer')
        ->and($r['marken_kontext'])->toContain('Max. Gäste parallel zum Tagesgeschäft: 80')
        ->and($r['marken_kontext'])->toContain('Zielbild in Kundenworten: Wie ein Sommerabend am See.')
        ->and($r['marken_kontext'])->toContain('Premium – Fünf Gänge (95 je Gast)')
        ->and($r['marken_kontext'])->not->toContain('GESCHÄFTSFÜHRUNG-INTERN');   // Agentur-intern bleibt draußen
});

it('Ohne projekt_id gibt es keinen Standort- und keinen Projekt-Block; fremde Teams sehen die Akte nie', function () {
    ($this->kunde)('kommunikation_ton', 'Du.');
    $c = $this->canvas->canvasFor($this->master, 'projekt', 'projekt', $this->projekt->id);
    $this->canvas->setSkalar($c, 'zielbild_wortlaut', 'GEHEIM-MARKER');
    app(TeamProfilService::class)->setzeProfil(Profiltraeger::standort($this->master, $this->lago->id), $this->kurator, ['notes' => 'STANDORT-GEHEIM']);

    $ohne = $this->res->fuer($this->master, ['crm_company_id' => $this->firma->id]);
    expect(array_column($ohne['quellen'], 'ebene'))->not->toContain('projekt')->and(array_column($ohne['quellen'], 'ebene'))->not->toContain('standort');

    $fremd = $this->res->fuer($this->rootTeam, $this->ids);
    expect($fremd['marken_kontext'] ?? '')->not->toContain('GEHEIM-MARKER')->and($fremd['marken_kontext'] ?? '')->not->toContain('STANDORT-GEHEIM')
        ->and(array_column($fremd['quellen'], 'ebene'))->not->toContain('projekt')->and(array_column($fremd['quellen'], 'ebene'))->not->toContain('standort');
});

it('Foodbook aus der Akte: fuerObjekt zieht Standort, Kunde und Projekt über projekt_id', function () {
    ($this->kunde)('kommunikation_ton', 'Du.', true);
    app(TeamProfilService::class)->setzeProfil(Profiltraeger::standort($this->master, $this->lago->id), $this->kurator, ['max_pax_parallel' => 80]);
    $fb = app(FoodbookService::class)->create($this->master, ['label' => 'LAGO', 'crm_company_id' => $this->firma->id]);
    DB::table('foodalchemist_foodbooks')->where('id', $fb->id)->update(['projekt_id' => $this->projekt->id]);   // am Gate vorbei: hier prüfen wir nur das Lesen

    $r = $this->res->fuerObjekt($this->master, $fb->refresh());

    expect(array_column($r['quellen'], 'ebene'))->toContain('kunde_bleibt')->and(array_column($r['quellen'], 'ebene'))->toContain('standort');
});

it('Seitenleiste „Was die KI daraus liest“ und MCP kontext.RESOLVE mit projekt_id', function () {
    ($this->kunde)('kommunikation_ton', 'Du.', true);
    app(TeamProfilService::class)->setzeProfil(Profiltraeger::standort($this->master, $this->lago->id), $this->kurator, ['max_pax_parallel' => 80]);

    Livewire::test(Detail::class, ['projekt' => $this->projekt->id])->assertSeeHtml('data-projekt-ki')
        ->assertSeeHtml('data-projekt-ki-ebene="kunde_bleibt"')->assertSeeHtml('data-projekt-ki-ebene="standort"')->assertSee('nie gekürzt');

    $r = app(KontextResolveTool::class)->execute(['projekt_id' => $this->projekt->id], new ToolContext($this->kurator, $this->master));
    expect($r->success)->toBeTrue()->and(array_column($r->data['quellen'], 'ebene'))->toContain('standort');
    $nein = app(KontextResolveTool::class)->execute(['projekt_id' => 99999], new ToolContext($this->kurator, $this->master));
    expect($nein->success)->toBeFalse();
});
