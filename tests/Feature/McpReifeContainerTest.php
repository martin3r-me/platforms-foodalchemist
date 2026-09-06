<?php

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Models\Team;
use Platform\Core\Tools\ToolRegistry;
use Platform\FoodAlchemist\Services\AngebotService;
use Platform\FoodAlchemist\Services\ReifeService;
use Platform\FoodAlchemist\Services\SpeiseplanService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 50 · Etappe 7: `foodalchemist.reife.GET` — Reife-Messung für die sechs Container
 * (Konzept, Format, Foodbook, Speisekarte, Speiseplan, Angebot). Jeder Adapter degradiert
 * ehrlich: was kein Messer hat, steht in `nicht_messbar`; was kein Werkzeug reparieren kann,
 * trägt `wie: null` statt eines erfundenen Tools.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->user = $this->makeUser($this->rootTeam);
    $this->actingAs($this->user);
    $this->registry = app(ToolRegistry::class);
    $this->kontext = new ToolContext($this->user, $this->rootTeam);
    $this->run = fn (string $n, array $a, ?ToolContext $k = null) => $this->registry->get($n)->execute($a, $k ?? $this->kontext);
    $this->reife = fn (string $kind, int $id) => ($this->run)('foodalchemist.reife.GET', ['kind' => $kind, 'id' => $id]);
    $this->codes = fn ($res) => array_column($res->data['luecken'] ?? [], 'code');
    $this->nm = fn ($res) => array_column($res->data['nicht_messbar'] ?? [], 'code');
});

it('Konzept: leerer Pflicht-Slot ist blockierend, Kopf-Lücken zeigen auf concepts.ENRICH', function () {
    $c = $this->makeConcept($this->rootTeam, 'Reife-Konzept', ['status' => 'draft']);
    $this->makeConceptSlot($c, ['sales_recipe_id' => null]);

    $res = ($this->reife)('concept', (int) $c->id);

    expect($res->success)->toBeTrue('reife: ' . ($res->error ?? ''))
        ->and($res->data['kind'])->toBe('concept')
        ->and($res->data['ampel'])->toBe('rot')
        ->and(($this->codes)($res))->toContain('pflicht_slot_leer', 'keine_belegte_position');

    $kopf = collect($res->data['luecken'])->firstWhere('code', 'target_price_per_person');
    expect($kopf)->not->toBeNull()
        ->and($kopf['wie']['tool'])->toBe('foodalchemist.concepts.ENRICH');

    // Pflicht-Schritte stehen vorn, dedupliziert nach Tool.
    $tools = array_column($res->data['naechste_schritte'], 'tool');
    expect($tools)->toBe(array_values(array_unique($tools)))
        ->and($res->data['naechste_schritte'][0]['pflicht'])->toBeTrue();
});

it('Konzept: belegter Slot mit Wording und gesetztem Kopf wird gelb/grün — kein pflicht_slot_leer', function () {
    $dish = $this->makeRecipe($this->rootTeam, 'Gericht A', ['is_sales_recipe' => true]);
    $c = $this->makeConcept($this->rootTeam, 'Reife-Konzept', [
        'status' => 'draft', 'description' => 'Text', 'target_price_per_person' => 12.5,
        'consumer_name' => 'Kunde', 'claim' => 'Claim',
    ]);
    $this->makeConceptSlot($c, ['sales_recipe_id' => $dish->id]);

    $res = ($this->reife)('concept', (int) $c->id);

    expect($res->success)->toBeTrue()
        ->and(($this->codes)($res))->not->toContain('pflicht_slot_leer', 'keine_belegte_position')
        ->and($res->data['ampel'])->not->toBe('rot')
        ->and($res->data['kennzahlen']['positionen_belegt'])->toBe(1);
});

it('Kind paket läuft über den Konzept-Adapter', function () {
    $c = $this->makeConcept($this->rootTeam, 'Paket', ['status' => 'draft', 'kind' => 'paket']);
    $res = ($this->reife)('paket', (int) $c->id);
    expect($res->success)->toBeTrue()->and(($this->codes)($res))->toContain('keine_positionen');
});

it('Format: ohne Edition blockiert, Gerüst nicht messbar, Kopf-Lücken zeigen auf formats.PUT', function () {
    $post = ($this->run)('foodalchemist.formats.POST', ['name' => 'Reife-Format', 'origin' => 'eigen']);
    expect($post->success)->toBeTrue('post: ' . ($post->error ?? ''));
    $id = (int) $post->data['format']['id'];

    $res = ($this->reife)('format', $id);

    expect($res->success)->toBeTrue('reife: ' . ($res->error ?? ''))
        ->and($res->data['ampel'])->toBe('rot')
        ->and(($this->codes)($res))->toContain('keine_edition', 'consumer_name')
        ->and(($this->nm)($res))->toContain('geruest')
        ->and($res->data['erfuellt'])->toContain('origin');

    $edition = collect($res->data['luecken'])->firstWhere('code', 'keine_edition');
    expect($edition['wie']['tool'])->toBe('foodalchemist.format_editions.POST')
        ->and($edition['wie']['args']['format_id'])->toBe($id);
});

it('Foodbook: leeres Kapitel ist blockierend (kein Inhalt), befülltes Kapitel löst es', function () {
    $fb = $this->makeFoodbook($this->rootTeam, 'Reife-Buch');
    $kap = $this->makeChapter($fb);

    $res = ($this->reife)('foodbook', (int) $fb->id);
    expect($res->success)->toBeTrue('reife: ' . ($res->error ?? ''))
        ->and(($this->codes)($res))->toContain('kein_inhalt')
        ->and($res->data['ampel'])->toBe('rot');

    $dish = $this->makeRecipe($this->rootTeam, 'Gericht B', ['is_sales_recipe' => true]);
    $this->makeFoodbookBlock($kap, ['sales_recipe_id' => $dish->id]);

    $res2 = ($this->reife)('foodbook', (int) $fb->id);
    expect(($this->codes)($res2))->not->toContain('kein_inhalt', 'kapitel_leer', 'keine_kapitel')
        ->and($res2->data['erfuellt'])->toContain('kapitel');
});

it('Speisekarte: ohne Rubrik blockiert, Rubrik ohne Position ist wichtig', function () {
    $post = ($this->run)('foodalchemist.speisekarten.POST', ['name' => 'Reife-Karte']);
    expect($post->success)->toBeTrue('post: ' . ($post->error ?? ''));
    $id = (int) $post->data['speisekarte']['id'];

    $res = ($this->reife)('speisekarte', $id);
    expect($res->success)->toBeTrue('reife: ' . ($res->error ?? ''))
        ->and(($this->codes)($res))->toContain('keine_rubriken')
        ->and(($this->nm)($res))->toContain('ampel');

    $rub = ($this->run)('foodalchemist.speisekarte_rubrik.POST', ['speisekarte_id' => $id, 'title' => 'Vorspeisen', 'art' => 'rubrik']);
    expect($rub->success)->toBeTrue('rubrik: ' . ($rub->error ?? ''));

    $res2 = ($this->reife)('speisekarte', $id);
    expect(($this->codes)($res2))->toContain('kein_inhalt')->not->toContain('keine_rubriken');
});

it('Speiseplan: Kopf-Lücken tragen wie=null (kein MCP-PUT), default_pax nicht messbar (DB-Default), keine Einträge blockiert', function () {
    $plan = app(SpeiseplanService::class)->create($this->rootTeam, ['name' => 'Reife-Plan', 'start_date' => '2026-09-07', 'cycle_weeks' => 1]);

    $res = ($this->reife)('speiseplan', (int) $plan->id);

    expect($res->success)->toBeTrue('reife: ' . ($res->error ?? ''))
        ->and(($this->codes)($res))->toContain('keine_eintraege', 'budget_wareneinsatz')
        ->and(($this->nm)($res))->toContain('struktur_text', 'geruest', 'ampel', 'default_pax');

    $budget = collect($res->data['luecken'])->firstWhere('code', 'budget_wareneinsatz');
    expect($budget['wie'])->toBeNull();

    // Kein erfundenes Werkzeug: Speiseplan-Kopf-Lücken landen nicht in den nächsten Schritten.
    $tools = array_column($res->data['naechste_schritte'], 'tool');
    expect($tools)->not->toContain(null);
});

it('Angebot: ohne Kapitel und ohne Konzept blockiert (keine_substanz), personen blockiert, Preis als Lücke', function () {
    $a = app(AngebotService::class)->create($this->rootTeam, ['name' => 'Reife-Angebot']);

    $res = ($this->reife)('angebot', (int) $a->id);

    expect($res->success)->toBeTrue('reife: ' . json_encode([$res->error ?? null, $res->errorCode ?? null]))
        ->and($res->data['ampel'])->toBe('rot')
        // Kein Preis = Lücke `preis`, nicht „vorläufig" — vorläufig heißt: Preis da, aber nie gerechnet.
        ->and($res->data['vorlaeufig'])->toBeFalse()
        ->and(($this->codes)($res))->toContain('keine_substanz', 'personen', 'occasion', 'preis')
        // C-7 hat das frühere pauschale `geruest` aufgeteilt: die MATERIALISIERUNG (sind die
        // geplanten Slots Kapitel geworden?) ist seither messbar, die BELEGUNG bleibt es nicht
        // — CoverageService kennt den Owner-Typ offer nach wie vor nicht. Ohne Gerüst am
        // Angebot ist auch die Materialisierung nichts, was fehlen könnte.
        ->and(($this->nm)($res))->toContain('geruest_belegung', 'ampel', 'geruest_nicht_materialisiert');

    $personen = collect($res->data['luecken'])->firstWhere('code', 'personen');
    expect($personen['schwere'])->toBe('blockiert')
        ->and($personen['wie']['tool'])->toBe('foodalchemist.angebote.PUT');

    // Alias `offer` trifft denselben Adapter.
    expect(($this->reife)('offer', (int) $a->id)->data['kind'])->toBe('offer');
});

it('unbekannter kind → VALIDATION_ERROR; fremdes Team → NOT_FOUND', function () {
    $falsch = ($this->reife)('kochbuch', 1);
    expect($falsch->success)->toBeFalse()->and($falsch->errorCode)->toBe('VALIDATION_ERROR');

    $fremdesTeam = Team::create(['name' => 'Fremd', 'user_id' => 1, 'personal_team' => false]);
    $fremd = $this->makeConcept($fremdesTeam, 'Fremd', ['status' => 'draft']);
    $res = ($this->reife)('concept', (int) $fremd->id);
    expect($res->success)->toBeFalse()->and($res->errorCode)->toBe('NOT_FOUND');
});

it('ReifeService::KINDS deckt alle Adapter-Kinds, Schema-Enum ist identisch', function () {
    $schema = $this->registry->get('foodalchemist.reife.GET')->getSchema();
    expect($schema['properties']['kind']['enum'])->toBe(array_values(ReifeService::KINDS))
        ->and(ReifeService::KINDS)->toContain('concept', 'format', 'foodbook', 'speisekarte', 'speiseplan', 'angebot', 'recipe');
});
