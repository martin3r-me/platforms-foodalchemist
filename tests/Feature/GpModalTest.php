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
        ->set('builder.condition', 'TK')
        ->set('builder.form', 'Filet')
        ->assertSeeHtml('Zander: TK, Filet')          // AUTO-SYNC-Vorschau
        ->assertSeeHtml('zander||filet')              // gp_key-Vorschau (3 Slots)
        ->call('speichern')
        ->assertSet('fehler', null)
        ->assertDispatched('gp-gespeichert');

    $gp = FoodAlchemistGp::where('name', 'Zander: TK, Filet')->firstOrFail();
    expect($gp->gp_key)->toBe('zander||filet')
        ->and($gp->main_ingredient_slug)->toBe('zander')
        ->and($gp->status->value)->toBe('tentative')
        ->and($gp->team_id)->toBe($this->rootTeam->id);
});

it('Hard-Error (§7.1 Verpackungswort) blockt den Insert mit Fehlertext', function () {
    Livewire::test(GpModal::class)
        ->call('oeffnen')
        ->set('builder.hauptzutat', 'Tomaten Kiste')
        ->set('builder.condition', 'frisch')
        ->call('speichern')
        ->assertSet('fehler', fn ($f) => str_contains((string) $f, '§7.1'))
        ->assertNotDispatched('gp-gespeichert');

    expect(FoodAlchemistGp::where('name', 'like', '%Kiste%')->exists())->toBeFalse();
});

it('GT-12-10 im Modal: Duplikat ⇒ HARD_STOP-Fehler, force-Checkbox legt trotzdem an', function () {
    $modal = Livewire::test(GpModal::class)
        ->call('oeffnen')
        ->set('builder.hauptzutat', 'Tomate')
        ->set('builder.condition', 'trocken')
        ->set('builder.processing', 'pulverfoermig')
        ->call('speichern');
    expect(FoodAlchemistGp::where('gp_key', 'tomate|pulverfoermig|')->count())->toBe(1);

    $modal->call('oeffnen')
        ->set('builder.hauptzutat', 'Tomate')
        ->set('builder.condition', 'trocken')
        ->set('builder.processing', 'pulverfoermig')
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
        ->set('builder.condition', 'TK')                 // Kontext fürs Fake-Echo
        ->call('ai_zustand')
        ->assertSet('kiVorschlag.condition.confidence', 0.87);

    $modal->call('accept_zustand');
    $gp->refresh();
    expect($gp->condition)->toBe('TK')
        ->and($gp->condition_source)->toBe('ki')
        ->and((float) $gp->condition_ai_confidence)->toBe(0.87)
        ->and($gp->condition_ai_reasoning)->toContain('FakeAiProvider');

    $modal->call('clear_zustand');
    $gp->refresh();
    expect($gp->condition)->toBeNull()->and($gp->condition_source)->toBeNull();
});

it('GL-07 Override-First: manuell gepflegter zustand wird vom accept NICHT überschrieben', function () {
    $gp = $this->makeGp($this->rootTeam, 'Erbsen: frisch');
    $gp->update(['condition' => 'frisch', 'condition_source' => 'manual']);

    Livewire::test(GpModal::class)
        ->call('oeffnen', $gp->id)
        ->set('builder.condition', 'TK')
        ->call('ai_zustand')
        ->call('accept_zustand')
        ->assertSet('fehler', fn ($f) => str_contains((string) $f, 'manuell'));

    expect($gp->fresh()->condition)->toBe('frisch');     // unverändert
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
        ->and($gp->tag_source)->toBe('ki')
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

// ── 06·H4: Convenience-Highlight direkt im GP-Editor pinnen (2. Andockpunkt) ──

it('highlightToggle pinnt einen Convenience-GP und nimmt ihn wieder heraus', function () {
    $gp = $this->makeGp($this->rootTeam, 'TK-Spätzle');
    $gp->update(['status' => 'approved', 'tag_is_convenience' => true]);

    Livewire::test(GpModal::class)
        ->call('oeffnen', $gp->id)
        ->call('highlightToggle')
        ->assertSet('fehler', null)
        ->assertDispatched('gp-gespeichert');
    expect($gp->refresh()->is_convenience_highlight)->toBeTrue();

    Livewire::test(GpModal::class)
        ->call('oeffnen', $gp->id)
        ->call('highlightToggle');
    expect($gp->refresh()->is_convenience_highlight)->toBeFalse();
});

it('highlightToggle verweigert das Pinnen, wenn der GP nicht als Convenience getaggt ist (§4)', function () {
    $gp = $this->makeGp($this->rootTeam, 'Frischer Spinat');
    $gp->update(['status' => 'approved']); // tag_is_convenience bleibt null/false

    Livewire::test(GpModal::class)
        ->call('oeffnen', $gp->id)
        ->call('highlightToggle')
        ->assertSet('fehler', fn ($f) => str_contains((string) $f, 'Convenience'));

    expect($gp->refresh()->is_convenience_highlight)->toBeFalse();
});

it('highlightToggle blockt geerbte Katalog-GPs (D1: nur Besitzer-Team)', function () {
    // GP gehört dem Root; aktiver User sitzt im Kind-Team → read-only.
    $gp = $this->makeGp($this->rootTeam, 'TK-Erbsen');
    $gp->update(['status' => 'approved', 'tag_is_convenience' => true]);
    $this->actingAs($this->makeUser($this->childA, 'Kind User'));

    Livewire::test(GpModal::class)
        ->call('oeffnen', $gp->id)
        ->call('highlightToggle')
        ->assertSet('fehler', fn ($f) => str_contains((string) $f, 'D1'));

    expect($gp->refresh()->is_convenience_highlight)->toBeFalse();
});
