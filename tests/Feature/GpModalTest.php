<?php

use Livewire\Livewire;
use Platform\FoodAlchemist\Livewire\Gps\GpModal;
use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * M3-09/10: GP-Modal — Neuanlage validiert (GL-12), AUTO-SYNC-Vorschau,
 * Hard-Stop + force, KI-Felder mit GL-07-Lebenszyklus (Fake-Provider-Roundtrip).
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam, 'Root User'));
    config(['foodalchemist.ai.provider' => 'fake']);
});

it('Neuanlage über den Builder: AUTO-SYNC-Name, Slug/gp_key-Vorschau, Insert validiert (DoD M3-09)', function () {
    Livewire::test(GpModal::class)
        ->call('oeffnen')
        ->set('builder.hauptzutat', 'Zander')
        ->set('builder.zustand', 'TK')
        ->set('builder.form', 'Filet')
        ->assertSeeHtml('Zander: TK, Filet')          // AUTO-SYNC-Vorschau
        ->assertSeeHtml('zander||filet')              // gp_key-Vorschau (3 Slots)
        ->call('speichern')
        ->assertSet('fehler', null)
        ->assertDispatched('gp-gespeichert');

    $gp = FoodAlchemistGp::where('name', 'Zander: TK, Filet')->firstOrFail();
    expect($gp->gp_key)->toBe('zander||filet')
        ->and($gp->hauptzutat_slug)->toBe('zander')
        ->and($gp->status->value)->toBe('tentative')
        ->and($gp->team_id)->toBe($this->rootTeam->id);
});

it('Hard-Error (§7.1 Verpackungswort) blockt den Insert mit Fehlertext', function () {
    Livewire::test(GpModal::class)
        ->call('oeffnen')
        ->set('builder.hauptzutat', 'Tomaten Kiste')
        ->set('builder.zustand', 'frisch')
        ->call('speichern')
        ->assertSet('fehler', fn ($f) => str_contains((string) $f, '§7.1'))
        ->assertNotDispatched('gp-gespeichert');

    expect(FoodAlchemistGp::where('name', 'like', '%Kiste%')->exists())->toBeFalse();
});

it('GT-12-10 im Modal: Duplikat ⇒ HARD_STOP-Fehler, force-Checkbox legt trotzdem an', function () {
    $modal = Livewire::test(GpModal::class)
        ->call('oeffnen')
        ->set('builder.hauptzutat', 'Tomate')
        ->set('builder.zustand', 'trocken')
        ->set('builder.verarbeitung', 'pulverfoermig')
        ->call('speichern');
    expect(FoodAlchemistGp::where('gp_key', 'tomate|pulverfoermig|')->count())->toBe(1);

    $modal->call('oeffnen')
        ->set('builder.hauptzutat', 'Tomate')
        ->set('builder.zustand', 'trocken')
        ->set('builder.verarbeitung', 'pulverfoermig')
        ->call('speichern')
        ->assertSet('fehler', fn ($f) => str_contains((string) $f, 'HARD_STOP_EXISTING_GP'))
        ->set('force', true)
        ->call('speichern')
        ->assertSet('fehler', null);

    expect(FoodAlchemistGp::where('gp_key', 'like', 'tomate|pulverfoermig|%')->count())->toBe(2);
});

it('M3-10 (DoD): Fake-Provider-Roundtrip zustand — ai → accept ändert Feld + Lineage, clear setzt zurück', function () {
    $gp = $this->makeGp($this->rootTeam, 'Erbsen: TK');

    $modal = Livewire::test(GpModal::class)
        ->call('oeffnen', $gp->id)
        ->set('builder.zustand', 'TK')                 // Kontext fürs Fake-Echo
        ->call('ai_zustand')
        ->assertSet('kiVorschlag.zustand.confidence', 0.87);

    $modal->call('accept_zustand');
    $gp->refresh();
    expect($gp->zustand)->toBe('TK')
        ->and($gp->zustand_quelle)->toBe('ki')
        ->and((float) $gp->zustand_ai_confidence)->toBe(0.87)
        ->and($gp->zustand_ai_begruendung)->toContain('FakeAiProvider');

    $modal->call('clear_zustand');
    $gp->refresh();
    expect($gp->zustand)->toBeNull()->and($gp->zustand_quelle)->toBeNull();
});

it('GL-07 Override-First: manuell gepflegter zustand wird vom accept NICHT überschrieben', function () {
    $gp = $this->makeGp($this->rootTeam, 'Erbsen: frisch');
    $gp->update(['zustand' => 'frisch', 'zustand_quelle' => 'manual']);

    Livewire::test(GpModal::class)
        ->call('oeffnen', $gp->id)
        ->set('builder.zustand', 'TK')
        ->call('ai_zustand')
        ->call('accept_zustand')
        ->assertSet('fehler', fn ($f) => str_contains((string) $f, 'manuell'));

    expect($gp->fresh()->zustand)->toBe('frisch');     // unverändert
});

it('M3-10: Fake-Roundtrip tags — accept schreibt tag_-Spalten + Lineage-Trio', function () {
    $gp = $this->makeGp($this->rootTeam, 'Erbsen: TK');

    Livewire::test(GpModal::class)
        ->call('oeffnen', $gp->id)
        ->set('tags.is_vegan', '1')                    // Kontext fürs Fake-Echo
        ->set('tags.is_gluten_free', '0')
        ->call('ai_tags')
        ->call('accept_tags');

    $gp->refresh();
    expect($gp->tag_is_vegan)->toBeTrue()
        ->and($gp->tag_is_gluten_free)->toBeFalse()
        ->and($gp->tag_quelle)->toBe('ki')
        ->and((float) $gp->tag_ai_confidence)->toBe(0.87);
});

it('✨ gp.suggest (Neuanlage): Fake-Echo befüllt die Builder-Felder nicht mit Fremd-Keys', function () {
    Livewire::test(GpModal::class)
        ->call('oeffnen')
        ->set('kiRohtext', 'Zanderfilet TK 400g')
        ->call('kiVorschlagNaming')
        // Fake echo't {bezeichnung: …} — kein hauptzutat-Key ⇒ Builder bleibt leer (kein Müll-Mapping)
        ->assertSet('builder.hauptzutat', '');
});
