<?php

use Platform\FoodAlchemist\Services\PresentationService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 43 — PresentationService (Kern): Allowlist-Sanitizer (Interna-Freiheit inkl. des
 * einzel-`ek`-Lecks), Pflicht-Datum, resolveByToken-Matrix, Snapshot-Stabilität,
 * Token-Stabilität, Fremd-Team-Guard. Reine Array-Ebene (kein Blade).
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->svc = app(PresentationService::class);

    // Foodbook mit einzel-Concept (löst im dokumentDaten den ek-Leak aus) + Einzelgericht,
    // Kapitel mit consumer_title != title (title_intern-Leak-Kandidat).
    $this->baue = function ($team) {
        $fb = $this->makeFoodbook($team, 'Sommerkatalog', [
            'personen' => 10, 'jahr' => 2027, 'description' => 'Feine Auswahl',
            'brand_color' => '#123456', 'footer_text' => 'BHG Catering',
        ]);
        $kap = $this->makeChapter($fb, [
            'title' => 'INTERN Vorspeisen', 'consumer_title' => 'Vorspeisen', 'description' => 'Zum Auftakt', 'position' => 1,
        ]);
        $dish = $this->makeRecipe($team, 'HG Lachsfilet', ['is_sales_recipe' => true, 'sales_net' => 12.0]);
        $concept = $this->makeConcept($team, 'Menü A', [
            'kind' => 'concept', 'consumer_name' => 'Genussreise', 'price_display' => 'einzel', 'price_per_person_cache' => 40.0,
        ]);
        $this->makeConceptSlot($concept, ['sales_recipe_id' => $dish->id, 'wording' => 'Lachs auf Spinat', 'position' => 1]);
        $this->makeFoodbookBlock($kap, ['type' => 'concept_ref', 'concept_id' => $concept->id, 'position' => 1]);
        $suppe = $this->makeRecipe($team, 'Kürbissuppe', ['is_sales_recipe' => true, 'sales_net' => 6.5]);
        $this->makeFoodbookBlock($kap, ['type' => 'recipe_ref', 'sales_recipe_id' => $suppe->id, 'position' => 2]);

        return $fb;
    };

    // Rekursiver Key-Scan (nur im content-Teilbaum — resolved_design.source/freigabe.at sind legitim).
    $this->alleKeys = function (array $node) use (&$fn) {
        $fn = function ($n, &$acc) use (&$fn) {
            foreach ($n as $k => $v) {
                if (is_string($k)) {
                    $acc[] = $k;
                }
                if (is_array($v)) {
                    $fn($v, $acc);
                }
            }
        };
        $acc = [];
        $fn($node, $acc);

        return $acc;
    };
});

it('buildSnapshot baut eine interna-freie Kundensicht (Allowlist)', function () {
    $team = $this->rootTeam;
    $fb = ($this->baue)($team);
    $this->actingAs($this->makeUser($team, 'Publisher'));

    $snap = $this->svc->buildSnapshot($team, $fb->refresh(), 'foodbook', ['design' => 'editorial']);

    // Kundeninhalt da.
    expect($snap['title'])->toBe('Sommerkatalog')
        ->and($snap['type'])->toBe('foodbook')
        ->and($snap['content']['sections'][0]['title'])->toBe('Vorspeisen'); // consumer_title, NICHT der interne Titel

    // Interna-Freiheit: keiner der verbotenen Keys taucht im content-Teilbaum auf.
    $keys = ($this->alleKeys)($snap['content']);
    foreach (['ek', 'ek_per_person', 'ek_pro_person', 'food_cost_percent', 'gesamt_ek', 'title_intern',
        'preis_quelle', 'slot_id', 'recipe_id', 'kaskaden', 'fb', 'source', 'interne_bemerkung', 'notes_manual'] as $verboten) {
        expect($keys)->not->toContain($verboten, "verbotener Key '{$verboten}' im Public-Snapshot");
    }

    // Der interne Kapitel-Titel darf nirgends im content auftauchen.
    expect(json_encode($snap['content']))->not->toContain('INTERN Vorspeisen');

    // Branding als Identifier (nicht base64).
    expect($snap['branding'])->toHaveKey('logo')
        ->and($snap['branding']['logo'])->toHaveKeys(['context_file_id', 'path'])
        ->and($snap['branding']['color'])->toBe('#123456');

    // Total trägt Kunden-VK, aber keine EK/WE%-Zahlen.
    expect($snap['content']['total'])->toHaveKey('vk_pro_person')
        ->and($snap['content']['total'])->not->toHaveKey('ek_per_person')
        ->and($snap['content']['total'])->not->toHaveKey('food_cost_percent');
});

it('publish erzwingt das Pflicht-Datum', function () {
    $team = $this->rootTeam;
    $fb = ($this->baue)($team);
    $this->actingAs($this->makeUser($team, 'Publisher'));

    expect(fn () => $this->svc->publish($team, 'foodbook', $fb->id, ['design' => 'editorial']))
        ->toThrow(RuntimeException::class);
});

it('publish → resolveByToken liefert den eingefrorenen Snapshot (ohne Login)', function () {
    $team = $this->rootTeam;
    $fb = ($this->baue)($team);
    $this->actingAs($this->makeUser($team, 'Publisher'));

    $res = $this->svc->publish($team, 'foodbook', $fb->id, [
        'design' => 'menu', 'expires_at' => now()->addDays(30)->toDateString(),
    ]);
    expect($res['token'])->not->toBeEmpty()
        ->and($res['design'])->toBe('menu')
        ->and($res['url'])->toContain('/p/foodbook/' . $res['token']);

    $snap = $this->svc->resolveByToken('foodbook', $res['token']);
    expect($snap)->not->toBeNull()
        ->and($snap['title'])->toBe('Sommerkatalog')
        ->and($snap['resolved_design']['source'])->toBe('menu')
        ->and($snap['resolved_design']['layout'])->not->toBeEmpty();
});

it('der Snapshot ist absolut — Editor-Änderungen ändern den Public-Link nicht', function () {
    $team = $this->rootTeam;
    $fb = ($this->baue)($team);
    $this->actingAs($this->makeUser($team, 'Publisher'));
    $res = $this->svc->publish($team, 'foodbook', $fb->id, ['expires_at' => now()->addDays(30)->toDateString()]);

    // Nach der Freigabe den Kapitel-Kundentitel live ändern.
    $fb->chapters()->first()->update(['consumer_title' => 'GEÄNDERT NACH FREIGABE']);

    $snap = $this->svc->resolveByToken('foodbook', $res['token']);
    expect($snap['content']['sections'][0]['title'])->toBe('Vorspeisen'); // unverändert

    // Erneutes Veröffentlichen zieht den neuen Stand — bei GLEICHEM Token.
    $res2 = $this->svc->publish($team, 'foodbook', $fb->id, ['expires_at' => now()->addDays(30)->toDateString()]);
    expect($res2['token'])->toBe($res['token']);
    $snap2 = $this->svc->resolveByToken('foodbook', $res['token']);
    expect($snap2['content']['sections'][0]['title'])->toBe('GEÄNDERT NACH FREIGABE');
});

it('resolveByToken 404-Matrix: unbekannt / zurückgezogen / abgelaufen', function () {
    $team = $this->rootTeam;
    $fb = ($this->baue)($team);
    $this->actingAs($this->makeUser($team, 'Publisher'));
    $res = $this->svc->publish($team, 'foodbook', $fb->id, ['expires_at' => now()->addDays(30)->toDateString()]);

    expect($this->svc->resolveByToken('foodbook', 'gibtsnicht'))->toBeNull();

    $this->svc->withdraw($team, 'foodbook', $fb->id);
    expect($this->svc->resolveByToken('foodbook', $res['token']))->toBeNull();

    // Wieder aktiv, aber abgelaufen.
    $fb->refresh()->forceFill(['presentation_enabled' => true, 'presentation_expires_at' => now()->subDay()])->save();
    expect($this->svc->resolveByToken('foodbook', $res['token']))->toBeNull();
});

it('publish auf ein fremdes (nicht eigenes) Foodbook wirft (isOwnedBy-Guard)', function () {
    // Root-Foodbook ist für childA SICHTBAR (Ancestry), aber NICHT im Besitz → Guard greift.
    $fb = ($this->baue)($this->rootTeam);
    $this->actingAs($this->makeUser($this->childA, 'Kind A'));

    expect(fn () => $this->svc->publish($this->childA, 'foodbook', $fb->id, [
        'expires_at' => now()->addDays(30)->toDateString(),
    ]))->toThrow(RuntimeException::class);
});
