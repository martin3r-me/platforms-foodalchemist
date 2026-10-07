<?php

use Livewire\Livewire;
use Platform\FoodAlchemist\Livewire\Angebote\Editor as AngebotEditor;
use Platform\FoodAlchemist\Livewire\Foodbooks\Index as FoodbookIndex;
use Platform\FoodAlchemist\Livewire\Speisekarte\Index as SpeisekarteIndex;
use Platform\FoodAlchemist\Livewire\Speiseplan\Editor as SpeiseplanEditor;
use Platform\FoodAlchemist\Models\FoodAlchemistAngebot;
use Platform\FoodAlchemist\Models\FoodAlchemistFoodbook;
use Platform\FoodAlchemist\Models\FoodAlchemistSpeisekarte;
use Platform\FoodAlchemist\Models\FoodAlchemistSpeiseplan;
use Platform\FoodAlchemist\Services\AngebotService;
use Platform\FoodAlchemist\Services\BearbeitungssperreService;
use Platform\FoodAlchemist\Services\FoodbookService;
use Platform\FoodAlchemist\Services\SpeisekarteService;
use Platform\FoodAlchemist\Services\SpeiseplanService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 65 · Bearbeitungssperre, Rollout Gruppe B: Foodbook, Speisekarte, Speiseplan, Angebot.
 * Je Editor: ohne „Bearbeiten" wird die Schreibaktion serverseitig abgewiesen (DB unverändert), mit Sperre
 * schreibt sie, eine zweite Person wird abgewiesen.
 */
beforeEach(function () {
    config(['foodalchemist.bearbeitungssperre' => true]);
    $this->seedTeamHierarchy();
    $this->anna = $this->makeUser($this->rootTeam, 'Anna');
    $this->ben = $this->makeUser($this->rootTeam, 'Ben');
    $this->svc = app(BearbeitungssperreService::class);
});

// ── Foodbook ─────────────────────────────────────────────────────────────────

it('Foodbook: ohne „Bearbeiten" Lesemodus, Speichern abgewiesen', function () {
    $fb = app(FoodbookService::class)->create($this->rootTeam, ['label' => 'Sommer']);
    $this->actingAs($this->anna);

    Livewire::test(FoodbookIndex::class)->call('waehle', $fb->id)
        ->assertSeeHtml('data-bearbeiten-starten')
        ->assertSeeHtml('data-fa-lesemodus="1"')
        ->assertDontSeeHtml('data-fb-speichern')
        ->set('form.label', 'Geändert')->call('speichern')
        ->call('kapitelNeu');

    expect(FoodAlchemistFoodbook::find($fb->id)->label)->toBe('Sommer')
        ->and($fb->chapters()->count())->toBe(0);
});

it('Foodbook: „Bearbeiten" schaltet frei, Speichern schreibt und lässt den Editor im Lesemodus', function () {
    $fb = app(FoodbookService::class)->create($this->rootTeam, ['label' => 'Sommer']);
    $this->actingAs($this->anna);

    $c = Livewire::test(FoodbookIndex::class)->call('waehle', $fb->id)
        ->call('bearbeitenStarten')
        ->assertSeeHtml('data-fb-speichern')
        ->assertSeeHtml('data-fa-lesemodus="0"');
    expect($this->svc->haelt('foodbook', $fb->id, $this->anna->id))->toBeTrue();

    $c->set('form.label', 'Sommer neu')->call('speichern')
        ->assertSeeHtml('data-bearbeiten-starten');
    expect(FoodAlchemistFoodbook::find($fb->id)->label)->toBe('Sommer neu')
        ->and($this->svc->haelt('foodbook', $fb->id, $this->anna->id))->toBeFalse();
});

it('Foodbook: zweite Person sieht „wird bearbeitet" und kann nicht schreiben', function () {
    $fb = app(FoodbookService::class)->create($this->rootTeam, ['label' => 'Sommer']);
    $this->svc->sperren('foodbook', $fb->id, $this->anna->id, 'Anna');
    $this->actingAs($this->ben);

    Livewire::test(FoodbookIndex::class)->call('waehle', $fb->id)
        ->assertSeeHtml('data-bearbeiten-fremd')->assertSee('Wird von Anna bearbeitet')
        ->call('bearbeitenStarten')
        ->set('form.label', 'Von Ben')->call('speichern');

    expect(FoodAlchemistFoodbook::find($fb->id)->label)->toBe('Sommer')
        ->and($this->svc->haelt('foodbook', $fb->id, $this->ben->id))->toBeFalse();
});

it('Foodbook: Wechsel auf ein anderes Foodbook und Schließen des Editors geben die Sperre frei', function () {
    $a = app(FoodbookService::class)->create($this->rootTeam, ['label' => 'A']);
    $b = app(FoodbookService::class)->create($this->rootTeam, ['label' => 'B']);
    $this->actingAs($this->anna);

    $c = Livewire::test(FoodbookIndex::class)->call('waehle', $a->id)->call('bearbeitenStarten')
        ->call('waehle', $b->id);
    expect($this->svc->haelt('foodbook', $a->id, $this->anna->id))->toBeFalse();

    $c->call('bearbeitenStarten')->dispatch('modal.closed', name: 'foodbook-editor');
    expect($this->svc->haelt('foodbook', $b->id, $this->anna->id))->toBeFalse();
});

// ── Speisekarte ──────────────────────────────────────────────────────────────

it('Speisekarte: ohne „Bearbeiten" Speichern und Rubrik anlegen abgewiesen', function () {
    $k = app(SpeisekarteService::class)->create($this->rootTeam, ['name' => 'Mittagskarte']);
    $this->actingAs($this->anna);

    Livewire::test(SpeisekarteIndex::class)->call('waehle', $k->id)
        ->assertSeeHtml('data-bearbeiten-starten')
        ->assertSeeHtml('data-fa-lesemodus="1"')
        ->set('name', 'Geändert')->call('speichern')
        ->set('neueRubrik', 'Vorspeisen')->call('rubrikNeu');

    expect(FoodAlchemistSpeisekarte::find($k->id)->name)->toBe('Mittagskarte')
        ->and($k->sections()->count())->toBe(0);
});

it('Speisekarte: „Bearbeiten" schaltet frei, Speichern schreibt und gibt frei', function () {
    $k = app(SpeisekarteService::class)->create($this->rootTeam, ['name' => 'Mittagskarte']);
    $this->actingAs($this->anna);

    Livewire::test(SpeisekarteIndex::class)->call('waehle', $k->id)
        ->call('bearbeitenStarten')
        ->assertSeeHtml('data-sk-speichern')
        ->set('neueRubrik', 'Vorspeisen')->call('rubrikNeu')
        ->set('name', 'Abendkarte')->call('speichern')
        ->assertSeeHtml('data-bearbeiten-starten');

    expect(FoodAlchemistSpeisekarte::find($k->id)->name)->toBe('Abendkarte')
        ->and($k->sections()->count())->toBe(1)
        ->and($this->svc->haelt('speisekarte', $k->id, $this->anna->id))->toBeFalse();
});

it('Speisekarte: zweite Person abgewiesen, neue Karte anlegen bleibt möglich', function () {
    $k = app(SpeisekarteService::class)->create($this->rootTeam, ['name' => 'Mittagskarte']);
    $this->svc->sperren('speisekarte', $k->id, $this->anna->id, 'Anna');
    $this->actingAs($this->ben);

    $c = Livewire::test(SpeisekarteIndex::class)->call('waehle', $k->id)
        ->assertSeeHtml('data-bearbeiten-fremd')
        ->set('name', 'Von Ben')->call('speichern');
    expect(FoodAlchemistSpeisekarte::find($k->id)->name)->toBe('Mittagskarte');

    // Neu anlegen ist kein Bearbeiten der gesperrten Karte — und startet gleich im Bearbeiten-Modus.
    $c->call('neu');
    $neu = FoodAlchemistSpeisekarte::where('id', '!=', $k->id)->first();
    expect($neu)->not->toBeNull()
        ->and($this->svc->haelt('speisekarte', $neu->id, $this->ben->id))->toBeTrue();
});

// ── Speiseplan (Sofort-Aktionen: Bearbeiten / Fertig) ─────────────────────────

it('Speiseplan: ohne „Bearbeiten" Linie anlegen und Stammdaten abgewiesen, Navigation bleibt frei', function () {
    $sp = app(SpeiseplanService::class)->create($this->rootTeam, ['name' => 'Kantine']);
    $linien = $sp->lines()->count();   // create() legt Standard-Linien an
    $this->actingAs($this->anna);

    $c = Livewire::test(SpeiseplanEditor::class)->call('oeffnenBearbeiten', $sp->id)
        ->assertSeeHtml('data-bearbeiten-starten')
        ->assertDontSeeHtml('data-bearbeiten-abbrechen')
        ->set('neueLinie', 'Menü 1')->call('linieAdd')
        ->set('form.name', 'Geändert')->call('speichern');
    expect(FoodAlchemistSpeiseplan::find($sp->id)->name)->toBe('Kantine')
        ->and($sp->lines()->count())->toBe($linien);

    $montag = $c->get('montag');
    $c->call('wocheVerschieben', 1);
    expect($c->get('montag'))->not->toBe($montag);
});

it('Speiseplan: „Bearbeiten" schaltet die Sofort-Aktionen frei, „Fertig" gibt frei', function () {
    $sp = app(SpeiseplanService::class)->create($this->rootTeam, ['name' => 'Kantine']);
    $linien = $sp->lines()->count();   // create() legt Standard-Linien an
    $this->actingAs($this->anna);

    $c = Livewire::test(SpeiseplanEditor::class)->call('oeffnenBearbeiten', $sp->id)
        ->call('bearbeitenStarten')
        ->assertSeeHtml('data-bearbeiten-fertig')
        ->set('neueLinie', 'Menü 1')->call('linieAdd')
        ->set('form.name', 'Kantine Nord')->call('speichern');
    expect(FoodAlchemistSpeiseplan::find($sp->id)->name)->toBe('Kantine Nord')
        ->and($sp->lines()->count())->toBe($linien + 1)
        ->and($this->svc->haelt('speiseplan', $sp->id, $this->anna->id))->toBeTrue();

    $c->call('bearbeitenFertig');
    expect($this->svc->haelt('speiseplan', $sp->id, $this->anna->id))->toBeFalse();
});

it('Speiseplan: zweite Person abgewiesen, Schließen gibt die eigene Sperre frei', function () {
    $sp = app(SpeiseplanService::class)->create($this->rootTeam, ['name' => 'Kantine']);
    $linien = $sp->lines()->count();   // create() legt Standard-Linien an
    $this->svc->sperren('speiseplan', $sp->id, $this->anna->id, 'Anna');
    $this->actingAs($this->ben);

    Livewire::test(SpeiseplanEditor::class)->call('oeffnenBearbeiten', $sp->id)
        ->assertSeeHtml('data-bearbeiten-fremd')
        ->set('neueLinie', 'Von Ben')->call('linieAdd');
    expect($sp->lines()->count())->toBe($linien);

    $this->actingAs($this->anna);
    Livewire::test(SpeiseplanEditor::class)->call('oeffnenBearbeiten', $sp->id)
        ->call('bearbeitenStarten')
        ->dispatch('modal.closed', name: 'speiseplan-editor');
    expect($this->svc->haelt('speiseplan', $sp->id, $this->anna->id))->toBeFalse();
});

// ── Angebot ──────────────────────────────────────────────────────────────────

it('Angebot: ohne „Bearbeiten" Lesemodus, Speichern und Kapitel anlegen abgewiesen', function () {
    $a = app(AngebotService::class)->create($this->rootTeam, ['name' => 'Firmenfeier', 'personen' => 40]);
    $this->actingAs($this->anna);

    Livewire::test(AngebotEditor::class)->call('oeffnen', $a->id)
        ->assertSeeHtml('data-bearbeiten-starten')
        ->assertSeeHtml('data-fa-lesemodus="1"')
        ->assertDontSeeHtml('data-angebot-speichern')
        ->set('form.name', 'Geändert')->call('speichern')
        ->call('kapitelNeu');

    expect(FoodAlchemistAngebot::find($a->id)->name)->toBe('Firmenfeier')
        ->and(\Platform\FoodAlchemist\Models\FoodAlchemistOfferChapter::where('offer_id', $a->id)->count())->toBe(0);
});

it('Angebot: „Bearbeiten" schaltet frei, Speichern schreibt, Editor bleibt offen im Lesemodus', function () {
    $a = app(AngebotService::class)->create($this->rootTeam, ['name' => 'Firmenfeier', 'personen' => 40]);
    $this->actingAs($this->anna);

    Livewire::test(AngebotEditor::class)->call('oeffnen', $a->id)
        ->call('bearbeitenStarten')
        ->assertSeeHtml('data-angebot-speichern')
        ->call('kapitelNeu')
        ->set('form.name', 'Firmenfeier 2027')->call('speichern')
        ->assertNotDispatched('modal.close')
        ->assertSeeHtml('data-bearbeiten-starten')
        ->assertSeeHtml('data-fa-lesemodus="1"');

    expect(FoodAlchemistAngebot::find($a->id)->name)->toBe('Firmenfeier 2027')
        ->and(\Platform\FoodAlchemist\Models\FoodAlchemistOfferChapter::where('offer_id', $a->id)->count())->toBe(1)
        ->and($this->svc->haelt('angebot', $a->id, $this->anna->id))->toBeFalse();
});

it('Angebot: zweite Person abgewiesen, Schließen gibt die eigene Sperre frei', function () {
    $a = app(AngebotService::class)->create($this->rootTeam, ['name' => 'Firmenfeier', 'personen' => 40]);
    $this->svc->sperren('angebot', $a->id, $this->anna->id, 'Anna');
    $this->actingAs($this->ben);

    Livewire::test(AngebotEditor::class)->call('oeffnen', $a->id)
        ->assertSeeHtml('data-bearbeiten-fremd')->assertSee('Wird von Anna bearbeitet')
        ->call('bearbeitenStarten')
        ->set('form.name', 'Von Ben')->call('speichern');
    expect(FoodAlchemistAngebot::find($a->id)->name)->toBe('Firmenfeier');

    $this->actingAs($this->anna);
    Livewire::test(AngebotEditor::class)->call('oeffnen', $a->id)
        ->call('bearbeitenStarten')
        ->dispatch('modal.closed', name: 'angebot-editor');
    expect($this->svc->haelt('angebot', $a->id, $this->anna->id))->toBeFalse();
});
