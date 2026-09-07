<?php

use Illuminate\Support\Facades\DB;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Tools\ToolRegistry;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * `knowledge.POST` legt AKTIV an (Entscheid Dominique 2026-09-07).
 *
 * Vorher landete jede Anlage in Quarantäne und brauchte ein zweites `SET_ACTIVE`. Gedacht als
 * Schutz, gewirkt als stille Falle: der vergessene zweite Call hinterlässt ein fertiges
 * Dossier, das nirgends wirkt — und man sieht ihm nicht an, ob das Absicht war. Gemessen am
 * 2026-09-07 lagen 423 von 1.303 Dokumenten inaktiv.
 *
 * Der Entwurfs-Weg bleibt: `active: false` macht die Quarantäne zur Entscheidung statt zum
 * Standard.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->user = $this->makeUser($this->rootTeam);
    $this->actingAs($this->user);
    $this->registry = app(ToolRegistry::class);
    $this->kontext = new ToolContext($this->user, $this->rootTeam);
    $this->post = fn (array $a) => $this->registry->get('foodalchemist.knowledge.POST')->execute($a, $this->kontext);
    $this->aktiv = fn (string $slug) => (bool) DB::table('foodalchemist_knowledge_documents')
        ->where('slug', $slug)->value('active');
});

it('legt ohne active-Angabe sofort aktiv an', function () {
    $res = ($this->post)([
        'title' => 'Fermentierte Chili-Pasten', 'category' => 'trend',
        'content_md' => "# Trend\n\nText.",
    ]);

    expect($res->success)->toBeTrue('post: ' . ($res->error ?? ''))
        ->and($res->data['document']['active'])->toBeTrue()
        ->and(($this->aktiv)($res->data['document']['slug']))->toBeTrue()
        // Die Rückmeldung muss den Zustand treffen — die alte Zeile behauptete immer „Entwurf".
        ->and($res->data['note'])->toContain('Aktiv');
});

it('legt mit active=false weiterhin als Entwurf an und sagt es auch', function () {
    $res = ($this->post)([
        'title' => 'Erst lesen, dann freigeben', 'category' => 'trend',
        'content_md' => "# Entwurf\n\nText.", 'active' => false,
    ]);

    expect($res->data['document']['active'])->toBeFalse()
        ->and(($this->aktiv)($res->data['document']['slug']))->toBeFalse()
        ->and($res->data['note'])->toContain('Entwurf');
});

it('das Schema deklariert active mit Default true', function () {
    $schema = $this->registry->get('foodalchemist.knowledge.POST')->getSchema();

    expect($schema['properties'])->toHaveKey('active')
        ->and($schema['properties']['active']['default'])->toBeTrue()
        // `active` ist optional — bestehende Aufrufer ohne das Feld bleiben gültig.
        ->and($schema['required'] ?? [])->not->toContain('active');
});

it('die Beschreibung verspricht keine Quarantäne mehr', function () {
    // Ein Etikett, das das Gegenteil des Verhaltens behauptet, führt Agenten in die Irre —
    // sie würden weiter ein SET_ACTIVE hinterherschicken oder das Dossier für unwirksam halten.
    $d = $this->registry->get('foodalchemist.knowledge.POST')->getDescription();

    expect($d)->toContain('SOFORT AKTIV')
        ->and($d)->toContain('active=false')
        ->and($d)->not->toContain('als ENTWURF an (inaktiv');
});
