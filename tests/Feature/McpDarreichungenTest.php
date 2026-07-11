<?php

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Tools\ToolRegistry;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeDarreichung;
use Platform\FoodAlchemist\Models\FoodAlchemistServierform;
use Platform\FoodAlchemist\Services\DarreichungService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * R0.2 (MCP-Darreichungs-Nachzug M1–M4): externe MCP-Clients sehen das
 * Darreichungs-Modell und die Money-Path-Auflösung.
 *  - M4: recipes.POST erzeugt eine Standard-Darreichung.
 *  - M1: verkaufsrezepte.SEARCH liefert die Formen je Gericht.
 *  - M2: kalkulation.GET zieht den VK aus der Standard-Darreichung (Resolver).
 *  - M3: Konzept mit serving_form=buffet → Slot löst die Buffet-Darreichung auf
 *        (Cockpit-Beweis wie Phase-5: Buffet-Preis statt Standard-Preis).
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->user = $this->makeUser($this->rootTeam);
    $this->actingAs($this->user);
    $this->registry = app(ToolRegistry::class);
    $this->kontext = new ToolContext($this->user, $this->rootTeam);

    // Servierformen sicherstellen (unbestimmt = Standard-Fallback, buffet = Variante). Team-scoped.
    $this->buffet = FoodAlchemistServierform::firstOrCreate(
        ['code' => 'buffet', 'team_id' => $this->rootTeam->id], ['label' => 'Buffet']
    )->id;
    FoodAlchemistServierform::firstOrCreate(
        ['code' => 'unbestimmt', 'team_id' => $this->rootTeam->id], ['label' => 'Unbestimmt']
    );
});

// HINWEIS: Der Zwei-Darreichungen-Fall (Buffet gewinnt über Standard) ist auf der
// In-Memory-SQLite der Testsuite NICHT abbildbar — dort wirkt der partielle Ein-Standard-
// Unique-Index (recipe_id WHERE is_standard=1) wie ein voller unique(recipe_id) und verbietet
// eine zweite Darreichung. Auf MySQL (Dev-Kanon) existiert der Index nicht (Service-Invariante),
// dort ist der Buffet-gewinnt-Beweis per Smoke verifiziert. Hier: Single-Standard-Pfad (M1/M2/M4)
// + Facetten-Setzen + Fallback-Auflösung (M3).
it('M1/M2/M4 E2E: MCP-VK-Gericht bekommt Standard-Darreichung, SEARCH/Kalkulation ziehen deren Preis', function () {
    // M4: VK-Gericht via MCP → automatisch Standard-Darreichung
    $post = $this->registry->get('foodalchemist.recipes.POST')->execute([
        'name' => 'HG: Rinderfilet', 'is_sales_recipe' => true,
    ], $this->kontext);
    expect($post->success)->toBeTrue()
        ->and($post->data['recipe']['standard_presentation_form_id'])->not->toBeNull();
    $recipeId = $post->data['recipe']['id'];

    // Standard-Preis 25,00 € setzen
    $standard = FoodAlchemistRecipeDarreichung::where('recipe_id', $recipeId)->where('is_standard', true)->first();
    app(DarreichungService::class)->aktualisieren($this->rootTeam, $standard->id, ['sales_net' => 25.00, 'price_mode' => 'manuell']);

    // M1: SEARCH liefert die Form inkl. Standard-Marker
    $such = $this->registry->get('foodalchemist.verkaufsrezepte.SEARCH')->execute(['q' => 'Rinderfilet'], $this->kontext);
    $treffer = collect($such->data['verkaufsrezepte'])->firstWhere('id', $recipeId);
    expect($treffer)->not->toBeNull()
        ->and(collect($treffer['presentations'])->firstWhere('is_standard', true))->not->toBeNull()
        ->and((float) collect($treffer['presentations'])->firstWhere('is_standard', true)['sales_net'])->toBe(25.00);

    // M2: kalkulation.GET zieht den Standard-Darreichungs-VK (25,00), nicht recipes.sales_net
    $kalk = $this->registry->get('foodalchemist.kalkulation.GET')->execute(['recipe_id' => $recipeId], $this->kontext);
    expect($kalk->success)->toBeTrue()
        ->and((float) $kalk->data['kalkulation']['sales_net'])->toBe(25.00);

    // M3: Konzept mit Facette buffet; Slot ohne Buffet-Variante → Resolver fällt auf Standard zurück
    $konzept = $this->registry->get('foodalchemist.concepts.POST')->execute([
        'name' => 'Sommerfest', 'serving_form' => 'buffet', 'service_moments' => [], 'seasons' => [],
    ], $this->kontext);
    expect($konzept->success)->toBeTrue()->and($konzept->data['concept']['serving_form_id'])->toBe($this->buffet);
    $conceptId = $konzept->data['concept']['id'];

    $slot = $this->registry->get('foodalchemist.concept_slots.POST')->execute([
        'concept_id' => $conceptId, 'role' => 'hauptgang', 'sales_recipe_id' => $recipeId,
    ], $this->kontext);
    expect($slot->success)->toBeTrue();

    $get = $this->registry->get('foodalchemist.concepts.GET')->execute(['concept_id' => $conceptId], $this->kontext);
    expect($get->success)->toBeTrue()
        ->and($get->data['concept']['serving_form'])->toBe('buffet');                 // Facette gelesen
    $resolved = $get->data['slots'][0]['resolved_presentation'];
    expect($resolved['is_standard'])->toBeTrue()                                       // Fallback auf Standard (keine Buffet-Variante)
        ->and((float) $resolved['sales_net'])->toBe(25.00);
});

it('M3: unbekannte Servierform im Konzept wird mit Verfügbar-Liste abgewiesen', function () {
    $res = $this->registry->get('foodalchemist.concepts.POST')->execute([
        'name' => 'X', 'serving_form' => 'gibtsnicht',
    ], $this->kontext);
    expect($res->success)->toBeFalse()
        ->and($res->errorCode)->toBe('VALIDATION_ERROR')
        ->and($res->error)->toContain('Verfügbar:');
});
