<?php

use Livewire\Livewire;
use Platform\FoodAlchemist\Livewire\Settings\DnaFelder;
use Platform\FoodAlchemist\Livewire\Settings\FoodDna;
use Platform\FoodAlchemist\Livewire\Settings\Index as SettingsIndex;
use Platform\FoodAlchemist\Livewire\Settings\KundeDna;
use Platform\FoodAlchemist\Models\FoodAlchemistCanvasTemplate;
use Platform\FoodAlchemist\Services\CanvasService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 82 · Schritt 6 · Oberfläche: Verbindlich-Schalter an der Oberteam-DNA, „geerbt von …“ und gesperrte
 * Felder im Unter-Team, Feldgruppen als Reiter, Pflegeseite der Zusatzfelder.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->owner = $this->makeUser($this->rootTeam, 'Chef', 'owner');
    $this->kind = $this->makeUser($this->childA, 'Kind-Chef', 'owner');
    $this->svc = app(CanvasService::class);
    $this->actingAs($this->owner);
    $this->setze = fn ($team, string $key, string $wert, ?bool $verb = null) => $this->svc->setSkalar($this->svc->canvasFor($team, 'food_dna', 'team', $team->id), $key, $wert, $verb);
});

it('Oberteam: Verbindlich-Schalter an einem gefüllten Feld setzt und hebt das Flag; ohne Wert erst eintragen', function () {
    ($this->setze)($this->rootTeam, 'no_gos', 'Kein Foie gras.');

    $lw = Livewire::test(FoodDna::class)->call('bearbeitenStarten')
        ->assertSeeHtml('data-canvas-verbindlich-schalter="no_gos"')
        ->call('verbindlichSchalten', 'no_gos');
    expect($this->svc->find('food_dna', 'team', $this->rootTeam->id)->entries->firstWhere('field_key', 'no_gos')->is_binding)->toBeTrue();
    $lw->assertSet('canvasVerbindlich', ['no_gos' => true])->call('verbindlichSchalten', 'no_gos');
    expect($this->svc->find('food_dna', 'team', $this->rootTeam->id)->entries->firstWhere('field_key', 'no_gos')->is_binding)->toBeFalse();

    // Feld ohne Wert: kein Flag, Hinweis
    $lw->call('verbindlichSchalten', 'aromatik')->assertDispatched('fa-saved');
    expect($this->svc->find('food_dna', 'team', $this->rootTeam->id)->entries->firstWhere('field_key', 'aromatik'))->toBeNull();
});

it('Mitglied ohne Admin-Recht: das Flag bleibt unverändert', function () {
    ($this->setze)($this->rootTeam, 'no_gos', 'Kein Foie gras.');
    $this->actingAs($this->makeUser($this->rootTeam, 'Koch', 'member'));

    Livewire::test(FoodDna::class)->call('bearbeitenStarten')->call('verbindlichSchalten', 'no_gos');

    expect($this->svc->find('food_dna', 'team', $this->rootTeam->id)->entries->firstWhere('field_key', 'no_gos')->is_binding)->toBeFalse();
});

it('Admin ohne „Bearbeiten“ (Sperre nicht geholt): das Flag bleibt unverändert', function () {
    config(['foodalchemist.bearbeitungssperre' => true]);   // in der Test-Umgebung standardmäßig aus
    ($this->setze)($this->rootTeam, 'no_gos', 'Kein Foie gras.');

    Livewire::test(FoodDna::class)->call('verbindlichSchalten', 'no_gos');

    expect($this->svc->find('food_dna', 'team', $this->rootTeam->id)->entries->firstWhere('field_key', 'no_gos')->is_binding)->toBeFalse();
});

it('Unter-Team: sieht „Geerbt von …“, verbindliche Felder sind gesperrt und werden nicht überschrieben, kein Schalter', function () {
    ($this->setze)($this->rootTeam, 'leitbild', 'Regional.');
    ($this->setze)($this->rootTeam, 'no_gos', 'Kein Foie gras.', true);
    $this->actingAs($this->kind);

    $lw = Livewire::test(FoodDna::class)->call('bearbeitenStarten')
        ->assertSee('Geerbt von '.$this->rootTeam->name)
        ->assertSee('Verbindlich vom Oberteam '.$this->rootTeam->name)
        ->assertSeeHtml('data-canvas-gesperrt="no_gos"')
        ->assertDontSeeHtml('data-canvas-verbindlich-schalter');

    $lw->set('canvasForm.no_gos', 'KIND-VERSUCH')->set('canvasForm.leitbild', 'Kita-Küche.')->call('canvasSpeichern');
    $kind = $this->svc->find('food_dna', 'team', $this->childA->id);
    expect($kind->entries->firstWhere('field_key', 'no_gos'))->toBeNull()                 // gesperrt: nichts geschrieben
        ->and($kind->entries->firstWhere('field_key', 'leitbild')->value)->toBe('Kita-Küche.');   // frei: überschrieben
    expect(app(\Platform\FoodAlchemist\Services\DnaResolver::class)->foodDna($this->childA)['no_gos']['wert'])->toBe('Kein Foie gras.');
});

it('Unter-Team: ein Verbindlich-Aufruf von Hand wird abgewiesen', function () {
    ($this->setze)($this->childA, 'leitbild', 'Kita-Küche.');
    $this->actingAs($this->kind);

    Livewire::test(FoodDna::class)->call('bearbeitenStarten')->call('verbindlichSchalten', 'leitbild')->assertDispatched('fa-saved');
    expect($this->svc->find('food_dna', 'team', $this->childA->id)->entries->firstWhere('field_key', 'leitbild')->is_binding)->toBeFalse();
});

it('Repeatable: das Unter-Team sieht geerbte (verbindliche) Einträge, ergänzt eigene und kann fremde nicht entfernen', function () {
    $this->svc->zusatzfeldAnlegen($this->rootTeam, 'food_dna', 'leitaromen', 'Leit-Aromen', ['type' => 'repeatable', 'group_name' => 'Aromatik & Tabus']);
    $ober = $this->svc->addEntry($this->svc->canvasFor($this->rootTeam, 'food_dna', 'team', $this->rootTeam->id), 'leitaromen', 'Zitrus', [], true);
    $this->actingAs($this->kind);

    $lw = Livewire::test(FoodDna::class)->call('bearbeitenStarten')
        ->assertSeeHtml('data-canvas-geerbt')->assertSee('Zitrus')->assertSee('Verbindlich')
        ->set('canvasNeuWelt.value', 'Salz')->call('weltHinzu')->assertSee('Salz');
    $lw->call('weltLoeschen', $ober->id);   // Eintrag des Oberteams: wird NICHT entfernt
    expect($ober->fresh())->not->toBeNull()->and($ober->fresh()->trashed())->toBeFalse();
});

it('Oberteam: verbindlicher Schalter an Repeatable-Einträgen', function () {
    $this->svc->zusatzfeldAnlegen($this->rootTeam, 'food_dna', 'leitaromen', 'Leit-Aromen', ['type' => 'repeatable']);
    $e = $this->svc->addEntry($this->svc->canvasFor($this->rootTeam, 'food_dna', 'team', $this->rootTeam->id), 'leitaromen', 'Zitrus');

    Livewire::test(FoodDna::class)->call('bearbeitenStarten')
        ->assertSeeHtml('data-canvas-eintrag-verbindlich="'.$e->id.'"')
        ->call('weltVerbindlichSchalten', $e->id);
    expect($e->fresh()->is_binding)->toBeTrue();
});

it('Feldgruppen sind Reiter an der Team-DNA, Zusatzfelder bringen ihren Reiter mit', function () {
    $this->svc->zusatzfeldAnlegen($this->rootTeam, 'food_dna', 'nachhaltigkeit', 'Nachhaltigkeit', ['group_name' => 'Haltung']);

    Livewire::test(FoodDna::class)->assertSeeHtml('data-canvas-reiter')
        ->assertSeeHtml('data-canvas-reiter-tab="'.md5('Identität').'"')
        ->assertSeeHtml('data-canvas-reiter-tab="'.md5('Haltung').'"')
        ->assertSee('Nachhaltigkeit');
});

it('Andere Boards bleiben unverändert: Kunden-DNA ohne Reiter, ohne Vererbungszeile, ohne Verbindlich-Schalter', function () {
    $firma = \Platform\Crm\Models\CrmCompany::create(['team_id' => $this->rootTeam->id, 'name' => 'Hotel Adler', 'is_active' => true]);

    Livewire::test(KundeDna::class)->call('firmaWaehlen', (int) $firma->id)
        ->assertSeeHtml('data-canvas-board="kunde_dna"')
        ->assertDontSeeHtml('data-canvas-reiter')->assertDontSeeHtml('data-canvas-vererbung')->assertDontSeeHtml('data-canvas-verbindlich-schalter');
});

it('Pflegeseite: Zusatzfeld anlegen, Duplikat/Kern-Key abweisen, ausschalten, entfernen — wirkt im Board', function () {
    $lw = Livewire::test(DnaFelder::class)->call('bearbeitenStarten')
        ->set('form.label', 'Nachhaltigkeit')->set('form.key', 'nachhaltigkeit')->set('form.group_name', 'Haltung')->call('anlegen')
        ->assertSee('Nachhaltigkeit')->assertDontSeeHtml('data-dnaf-fehler');
    $feld = FoodAlchemistCanvasTemplate::where('team_id', $this->rootTeam->id)->where('field_key', 'nachhaltigkeit')->first();
    expect($feld)->not->toBeNull()->and($feld->group_name)->toBe('Haltung');

    $lw->set('form.label', 'Nochmal')->set('form.key', 'nachhaltigkeit')->call('anlegen')->assertSeeHtml('data-dnaf-fehler');
    $lw->set('form.label', 'Kern')->set('form.key', 'leitbild')->call('anlegen')->assertSee('Kern-Feld');

    expect(array_column($this->svc->felder('food_dna', $this->rootTeam), 'key'))->toContain('nachhaltigkeit');
    $lw->call('aktivSchalten', $feld->id);
    expect(array_column($this->svc->felder('food_dna', $this->rootTeam), 'key'))->not->toContain('nachhaltigkeit');
    $lw->call('aktivSchalten', $feld->id)->call('loeschen', $feld->id);
    expect(FoodAlchemistCanvasTemplate::where('field_key', 'nachhaltigkeit')->count())->toBe(0);
});

it('Pflegeseite: fremde Zusatzfelder lassen sich nicht schalten oder entfernen; nur im Oberteam sichtbar', function () {
    $fremd = $this->svc->zusatzfeldAnlegen($this->childB, 'food_dna', 'nur_b', 'Nur B');
    Livewire::test(DnaFelder::class)->call('bearbeitenStarten')->call('aktivSchalten', $fremd->id)->call('loeschen', $fremd->id);
    expect($fremd->fresh()->active)->toBeTrue()->and($fremd->fresh()->trashed())->toBeFalse();

    expect(SettingsIndex::SEKTIONEN)->toHaveKey('dna-felder')->and(SettingsIndex::NUR_OBERTEAM)->toContain('dna-felder');
    expect(Livewire::test(SettingsIndex::class)->viewData('sektionen'))->toHaveKey('dna-felder');
    $this->actingAs($this->kind);
    expect(Livewire::test(SettingsIndex::class)->viewData('sektionen'))->not->toHaveKey('dna-felder');
    Livewire::test(SettingsIndex::class, ['sektion' => 'dna-felder'])->assertNotFound();
});

it('Zusatzfeld an Foodbook-Canvas: erscheint im KI-Kontext des Foodbooks', function () {
    $this->svc->zusatzfeldAnlegen($this->rootTeam, 'foodbook', 'dresscode', 'Dresscode', ['type' => 'text']);
    $fb = $this->makeFoodbook($this->rootTeam, 'Gala');
    $this->svc->setSkalar($this->svc->canvasFor($this->rootTeam, 'foodbook', 'foodbook', $fb->id), 'dresscode', 'Smoking');

    expect($this->svc->cascadeKontext($this->rootTeam, null, (int) $fb->id)['marken_kontext'] ?? '')->toContain('Dresscode: Smoking');
});
