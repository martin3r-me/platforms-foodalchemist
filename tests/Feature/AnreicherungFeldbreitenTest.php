<?php

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Tools\ToolRegistry;
use Platform\FoodAlchemist\Services\RecipeService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Gemessener Anlass 2026-09-12 (Dominiques MCP-Testlauf, Rezept 3748).
 *
 * Der Anreicherungs-Schritt `recipe.eigenschaften` starb an
 * `SQLSTATE[22001] Data too long for column 'function'` — und riss NEUN weitere Felder mit,
 * weil alles in EINEM `forceFill()->save()` hängt: setup_time_min, variable_work_time_min,
 * variable_work_time_basis, standzeit_min, max_vorlauf_tage, batch_max_kg, batch_max_pieces,
 * temperature und function selbst. Der einzige rote Reife-Blocker war danach ausgerechnet die
 * Arbeitszeit — das Modell hatte sie geliefert, die Datenbank hat sie verworfen.
 *
 * Gemessen über den Bestand: `function` war zu 98 % der Spaltenbreite ausgereizt (längster Wert
 * 63 von 64 Zeichen über 2.347 Rezepte), `temperature` zu 86 %. Es war also nie eine Regression,
 * sondern ein Münzwurf bei jeder Anreicherung — und er fällt umso öfter falsch, je ausführlicher
 * das Modell antwortet.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->user = $this->makeUser($this->rootTeam);
    $this->actingAs($this->user);
});

it('★ nimmt einen ueberlangen Freitext an, statt am Schreiben zu sterben', function () {
    $lang = str_repeat('kaltes Gemüse-Püree / frische Beilagenkomponente ', 8);   // ~384 Zeichen

    $r = app(RecipeService::class)->create($this->rootTeam, [
        'name' => 'Breitentest', 'function' => $lang, 'temperature' => $lang,
    ]);

    // ⚠ NICHT nur "kein Fehler" pruefen: die Suite laeuft auf SQLite, und SQLite erzwingt keine
    // varchar-Laenge — ein reiner "es hat nicht geworfen"-Test waere auch OHNE Fix gruen gewesen.
    // Der Vertrag ist die GEKAPPTE Laenge, und die gilt auf jeder Datenbank.
    $frisch = $r->fresh();
    expect(mb_strlen((string) $frisch->function))->toBe(255)
        ->and(mb_strlen((string) $frisch->temperature))->toBe(255)
        ->and($frisch->function)->toStartWith('kaltes Gemüse-Püree');
});

it('★ eine unbekannte Geschmacksrichtung wird zu null, nicht in die Spalte gequetscht', function () {
    // Genau der Fall aus dem Protokoll: das Modell antwortet mit einem Satz statt einem Wort.
    $r = app(RecipeService::class)->create($this->rootTeam, [
        'name' => 'Enum-Test',
        'taste_direction' => 'herzhaft-säuerlich mit deutlicher Senfnote und Honigsüße',
    ]);

    expect($r->fresh()->taste_direction)->toBeNull();
});

it('nimmt die drei erlaubten Richtungen an — auch in anderer Schreibweise', function () {
    foreach (['suess', 'HERZHAFT', ' neutral '] as $i => $wert) {
        $r = app(RecipeService::class)->create($this->rootTeam, [
            'name' => 'Enum-ok-'.$i, 'taste_direction' => $wert,
        ]);
        expect($r->fresh()->taste_direction)->toBe(trim(mb_strtolower($wert)));
    }
});

it('★ update() haelt denselben Riegel wie create() — sonst ist die Luecke nur verschoben', function () {
    $r = app(RecipeService::class)->create($this->rootTeam, ['name' => 'Update-Test', 'taste_direction' => 'suess']);

    app(RecipeService::class)->update($this->rootTeam, $r->id, ['taste_direction' => 'ein ganzer Satz als Antwort']);

    expect($r->fresh()->taste_direction)->toBeNull();
});

it('das Vokabular steht EINMAL — vier Kopien im Code waren der Grund fuer die Luecke', function () {
    expect(RecipeService::TASTE_DIRECTIONS)->toBe(['suess', 'herzhaft', 'neutral']);
});

/**
 * ★ Dominiques zweiter Stolperstein: `recipe_anchors.PUT` verlangt eine Id aus dem
 * Aroma-Anker-Vokabular, das Schema sagte aber „Anker-GP-Id" und das Beispiel „Anker-GP 88".
 * Wer dem Etikett folgte, schickte eine gp_id und bekam ein nacktes „nicht sichtbar/vorhanden".
 * Das Etikett log, und die Fehlermeldung half nicht weiter.
 */
it('★ nennt bei falscher Anker-Id den Weg zur richtigen, statt nur NOT_FOUND zu sagen', function () {
    $rezept = $this->makeRecipe($this->rootTeam, 'Anker-Test');

    $res = app(ToolRegistry::class)->get('foodalchemist.recipe_anchors.PUT')->execute(
        ['recipe_id' => $rezept->id, 'anker_id' => 999999, 'action' => 'set'],
        new ToolContext($this->user, $this->rootTeam)
    );

    expect($res->errorCode)->toBe('NOT_FOUND')
        ->and($res->error)->toContain('ANKER_SUCHE')
        ->and($res->error)->toContain('gp_id');
});

it('das Schema warnt vor der gp_id, bevor man den Fehler macht', function () {
    $schema = app(ToolRegistry::class)->get('foodalchemist.recipe_anchors.PUT')->getSchema();

    expect($schema['properties']['anker_id']['description'])->toContain('ANKER_SUCHE')
        ->and($schema['properties']['anker_id']['description'])->toContain('gp_id');
});
