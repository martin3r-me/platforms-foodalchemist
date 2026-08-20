<?php

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Tools\ToolRegistry;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Speisekarte MCP-Lockstep (Stufe A): externe Clients können Karte → Rubrik → Position
 * bauen und wieder entfernen. Writes sind team-scoped (isOwnedBy).
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->user = $this->makeUser($this->rootTeam);
    $this->actingAs($this->user);
    $this->registry = app(ToolRegistry::class);
    $this->kontext = new ToolContext($this->user, $this->rootTeam);

    $this->gericht = FoodAlchemistRecipe::create([
        'team_id' => $this->rootTeam->id, 'recipe_key' => 'mcp1', 'name' => 'Zanderfilet', 'status' => 'approved',
        'is_sales_recipe' => true, 'sales_net' => 26.00, 'ek_total_eur' => 9.00,
    ]);
});

it('Stufe A MCP: Karte → Rubrik → Gericht-Position → Löschen', function () {
    $karte = $this->registry->get('foodalchemist.speisekarten.POST')->execute(['name' => 'Mittagskarte'], $this->kontext);
    expect($karte->success)->toBeTrue();
    $karteId = $karte->data['speisekarte']['id'];

    $rubrik = $this->registry->get('foodalchemist.speisekarte_rubrik.POST')->execute([
        'speisekarte_id' => $karteId, 'title' => 'Fischgerichte', 'art' => 'speisen',
    ], $this->kontext);
    expect($rubrik->success)->toBeTrue();
    $rubrikId = $rubrik->data['rubrik']['id'];

    $pos = $this->registry->get('foodalchemist.speisekarte_positionen.POST')->execute([
        'rubrik_id' => $rubrikId, 'type' => 'gericht_ref', 'sales_recipe_id' => $this->gericht->id,
    ], $this->kontext);
    expect($pos->success)->toBeTrue()
        ->and($pos->data['position']['vk_netto'])->toBe(26.0)
        ->and($pos->data['position']['preis_quelle'])->toBe('legacy');
    $posId = $pos->data['position']['id'];

    $del = $this->registry->get('foodalchemist.speisekarte_positionen.DELETE')->execute(['position_id' => $posId], $this->kontext);
    expect($del->success)->toBeTrue()->and($del->data['deleted'])->toBeTrue();
});

it('Stufe A MCP: gericht_ref ohne sales_recipe_id → VALIDATION_ERROR', function () {
    $karte = $this->registry->get('foodalchemist.speisekarten.POST')->execute(['name' => 'K'], $this->kontext);
    $rubrik = $this->registry->get('foodalchemist.speisekarte_rubrik.POST')->execute([
        'speisekarte_id' => $karte->data['speisekarte']['id'], 'title' => 'R',
    ], $this->kontext);
    $pos = $this->registry->get('foodalchemist.speisekarte_positionen.POST')->execute([
        'rubrik_id' => $rubrik->data['rubrik']['id'], 'type' => 'gericht_ref',
    ], $this->kontext);
    expect($pos->success)->toBeFalse();
});

// ── Werkstrang M Phase A (Spec 40 §6): speisekarten.PUT (Kopf-Update, Lockstep-Luecke) ────────

it('Phase A MCP: speisekarten.PUT aktualisiert die Kontext-Leitplanken', function () {
    $karte = $this->registry->get('foodalchemist.speisekarten.POST')->execute(['name' => 'Abendkarte'], $this->kontext);
    $karteId = $karte->data['speisekarte']['id'];

    $put = $this->registry->get('foodalchemist.speisekarten.PUT')->execute([
        'id' => $karteId, 'kundentyp' => 'Fine-Dining-Gäste', 'default_niveau' => 'fine_dining',
        'default_convenience' => 'from_scratch',
    ], $this->kontext);

    expect($put->success)->toBeTrue()
        ->and($put->data['speisekarte']['default_niveau'])->toBe('fine_dining')
        ->and($put->data['speisekarte']['kundentyp'])->toBe('Fine-Dining-Gäste');
    expect(\Platform\FoodAlchemist\Models\FoodAlchemistSpeisekarte::find($karteId)->default_convenience)->toBe('from_scratch');
});

it('Phase A MCP: speisekarten.PUT ist team-scoped (fremdes Team → Fehler, kein Write)', function () {
    $karte = $this->registry->get('foodalchemist.speisekarten.POST')->execute(['name' => 'Root-Karte'], $this->kontext);
    $karteId = $karte->data['speisekarte']['id'];

    $fremdKontext = new ToolContext($this->makeUser($this->childB), $this->childB);
    $put = $this->registry->get('foodalchemist.speisekarten.PUT')->execute([
        'id' => $karteId, 'default_niveau' => 'gehoben',
    ], $fremdKontext);

    expect($put->success)->toBeFalse();
    expect(\Platform\FoodAlchemist\Models\FoodAlchemistSpeisekarte::find($karteId)->default_niveau)->toBeNull();
});

// ── Werkstrang M Phase C (Spec 40 §6): positionen.MOVE/.REORDER + rubrik.REORDER ──────────────

it('Phase C MCP: positionen.MOVE verschiebt in andere Rubrik derselben Karte', function () {
    $karte = $this->registry->get('foodalchemist.speisekarten.POST')->execute(['name' => 'MC'], $this->kontext)->data['speisekarte']['id'];
    $rA = $this->registry->get('foodalchemist.speisekarte_rubrik.POST')->execute(['speisekarte_id' => $karte, 'title' => 'A'], $this->kontext)->data['rubrik']['id'];
    $rB = $this->registry->get('foodalchemist.speisekarte_rubrik.POST')->execute(['speisekarte_id' => $karte, 'title' => 'B'], $this->kontext)->data['rubrik']['id'];
    $p = $this->registry->get('foodalchemist.speisekarte_positionen.POST')->execute(['rubrik_id' => $rA, 'type' => 'gericht_ref', 'sales_recipe_id' => $this->gericht->id], $this->kontext)->data['position']['id'];

    $mv = $this->registry->get('foodalchemist.speisekarte_positionen.MOVE')->execute(['position_id' => $p, 'section_id' => $rB], $this->kontext);
    expect($mv->success)->toBeTrue();
    expect(\Platform\FoodAlchemist\Models\FoodAlchemistSpeisekartePosition::find($p)->section_id)->toBe($rB);
});

it('Phase C MCP: rubrik.REORDER + positionen.REORDER ordnen neu', function () {
    $karte = $this->registry->get('foodalchemist.speisekarten.POST')->execute(['name' => 'MC2'], $this->kontext)->data['speisekarte']['id'];
    $rA = $this->registry->get('foodalchemist.speisekarte_rubrik.POST')->execute(['speisekarte_id' => $karte, 'title' => 'A'], $this->kontext)->data['rubrik']['id'];
    $rB = $this->registry->get('foodalchemist.speisekarte_rubrik.POST')->execute(['speisekarte_id' => $karte, 'title' => 'B'], $this->kontext)->data['rubrik']['id'];

    $re = $this->registry->get('foodalchemist.speisekarte_rubrik.REORDER')->execute(['speisekarte_id' => $karte, 'ids' => [$rB, $rA]], $this->kontext);
    expect($re->success)->toBeTrue();
    expect(\Platform\FoodAlchemist\Models\FoodAlchemistSpeisekarteRubrik::where('menu_card_id', $karte)->whereNull('parent_id')->orderBy('position')->pluck('id')->all())->toBe([$rB, $rA]);

    $p1 = $this->registry->get('foodalchemist.speisekarte_positionen.POST')->execute(['rubrik_id' => $rA, 'type' => 'gericht_ref', 'sales_recipe_id' => $this->gericht->id], $this->kontext)->data['position']['id'];
    $p2 = $this->registry->get('foodalchemist.speisekarte_positionen.POST')->execute(['rubrik_id' => $rA, 'type' => 'gericht_ref', 'sales_recipe_id' => $this->gericht->id], $this->kontext)->data['position']['id'];
    $pre = $this->registry->get('foodalchemist.speisekarte_positionen.REORDER')->execute(['rubrik_id' => $rA, 'ids' => [$p2, $p1]], $this->kontext);
    expect($pre->success)->toBeTrue();
    expect(\Platform\FoodAlchemist\Models\FoodAlchemistSpeisekartePosition::where('section_id', $rA)->orderBy('position')->pluck('id')->all())->toBe([$p2, $p1]);
});

it('Phase C MCP: positionen.MOVE team-scoped (fremdes Team → Fehler, kein Write)', function () {
    $karte = $this->registry->get('foodalchemist.speisekarten.POST')->execute(['name' => 'MC3'], $this->kontext)->data['speisekarte']['id'];
    $rA = $this->registry->get('foodalchemist.speisekarte_rubrik.POST')->execute(['speisekarte_id' => $karte, 'title' => 'A'], $this->kontext)->data['rubrik']['id'];
    $rB = $this->registry->get('foodalchemist.speisekarte_rubrik.POST')->execute(['speisekarte_id' => $karte, 'title' => 'B'], $this->kontext)->data['rubrik']['id'];
    $p = $this->registry->get('foodalchemist.speisekarte_positionen.POST')->execute(['rubrik_id' => $rA, 'type' => 'gericht_ref', 'sales_recipe_id' => $this->gericht->id], $this->kontext)->data['position']['id'];

    $fremd = new ToolContext($this->makeUser($this->childB), $this->childB);
    $mv = $this->registry->get('foodalchemist.speisekarte_positionen.MOVE')->execute(['position_id' => $p, 'section_id' => $rB], $fremd);
    expect($mv->success)->toBeFalse();
    expect(\Platform\FoodAlchemist\Models\FoodAlchemistSpeisekartePosition::find($p)->section_id)->toBe($rA);
});
