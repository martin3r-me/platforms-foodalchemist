<?php

use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistCanvasTemplate;
use Platform\FoodAlchemist\Services\CanvasService;
use Platform\FoodAlchemist\Services\DnaResolver;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 82 · Schritt 6 — DnaResolver: Team-DNA FELDWEISE über die Kette Oberteam → Unter-Team,
 * verbindliche Felder, Vereinigung der Repeatables, Kundengrenze, Zusatzfelder aus canvas_templates.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam));
    $this->svc = app(CanvasService::class);
    $this->dna = app(DnaResolver::class);

    $this->setze = function (Team $team, string $key, ?string $wert, ?bool $verbindlich = null) {
        $canvas = $this->svc->canvasFor($team, 'food_dna', 'team', $team->id);
        $this->svc->setSkalar($canvas, $key, $wert, $verbindlich);
    };
});

it('Kette: von oben nach unten, an der Kundengrenze zu Ende', function () {
    expect($this->dna->teamIds($this->childA))->toBe([(int) $this->rootTeam->id, (int) $this->childA->id])
        ->and($this->dna->teamIds($this->rootTeam))->toBe([(int) $this->rootTeam->id]);

    // Root ist hier das Master-Team: ein Mandant darunter erbt nichts von ihm
    config(['foodalchemist.master_team_id' => $this->rootTeam->id]);
    expect($this->dna->teamIds($this->childA))->toBe([(int) $this->childA->id]);
});

it('Kette: ein Zyklus in parent_team_id endet, statt zu hängen', function () {
    \Illuminate\Support\Facades\DB::table('teams')->where('id', $this->rootTeam->id)->update(['parent_team_id' => $this->childA->id]);
    \Illuminate\Support\Facades\DB::table('teams')->where('id', $this->childA->id)->update(['parent_team_id' => $this->rootTeam->id]);

    $ids = $this->dna->teamIds(Team::find($this->childA->id));

    expect(count($ids))->toBeLessThanOrEqual(2)->and($ids)->toContain((int) $this->childA->id);
    expect(fn () => $this->dna->foodDna(Team::find($this->childA->id)))->not->toThrow(\Throwable::class);
});

it('feldweise: das Unter-Team überschreibt ein Feld, den Rest erbt es vom Oberteam', function () {
    ($this->setze)($this->rootTeam, 'leitbild', 'OBER-LEITBILD');
    ($this->setze)($this->rootTeam, 'no_gos', 'OBER-NOGO');
    ($this->setze)($this->childA, 'leitbild', 'KIND-LEITBILD');

    $dna = $this->dna->foodDna($this->childA);

    expect($dna['leitbild']['wert'])->toBe('KIND-LEITBILD')
        ->and($dna['leitbild']['quelle_team_id'])->toBe((int) $this->childA->id)
        ->and($dna['leitbild']['verbindlich'])->toBeFalse()
        ->and($dna['no_gos']['wert'])->toBe('OBER-NOGO')
        ->and($dna['no_gos']['quelle_team_id'])->toBe((int) $this->rootTeam->id)
        ->and($dna['aromatik']['wert'])->toBeNull()
        ->and($dna['aromatik']['quelle_team_id'])->toBeNull();
});

it('feldweise: ein leerer Wert im Unter-Team überschreibt nichts (nicht-leer gewinnt)', function () {
    ($this->setze)($this->rootTeam, 'leitbild', 'OBER-LEITBILD');
    ($this->setze)($this->childA, 'leitbild', '   ');   // setSkalar löscht leere Werte

    expect($this->dna->foodDna($this->childA)['leitbild']['wert'])->toBe('OBER-LEITBILD');
});

it('verbindlich: der Wert des Oberteams gewinnt, das Unter-Team überschreibt ihn nicht', function () {
    ($this->setze)($this->rootTeam, 'no_gos', 'Kein Foie gras.', true);
    ($this->setze)($this->childA, 'no_gos', 'KIND-VERSUCH');

    $zeile = $this->dna->foodDna($this->childA)['no_gos'];

    expect($zeile['wert'])->toBe('Kein Foie gras.')
        ->and($zeile['verbindlich'])->toBeTrue()
        ->and($zeile['quelle_team_id'])->toBe((int) $this->rootTeam->id);
    // und das Oberteam selbst sieht es genauso
    expect($this->dna->foodDna($this->rootTeam)['no_gos']['wert'])->toBe('Kein Foie gras.');
});

it('verbindlich: nicht-verbindliche Felder des Oberteams bleiben überschreibbar', function () {
    ($this->setze)($this->rootTeam, 'leitbild', 'OBER', false);
    ($this->setze)($this->childA, 'leitbild', 'KIND');

    expect($this->dna->foodDna($this->childA)['leitbild']['wert'])->toBe('KIND');
});

it('Repeatable: Vereinigung über die Ebenen, Oberteam zuerst, Dubletten einmal, verbindlich bleibt', function () {
    $ober = $this->svc->canvasFor($this->rootTeam, 'food_dna', 'team', $this->rootTeam->id);
    $kind = $this->svc->canvasFor($this->childA, 'food_dna', 'team', $this->childA->id);
    // food_dna hat im Kern kein Repeatable — über ein Zusatzfeld
    FoodAlchemistCanvasTemplate::create([
        'team_id' => $this->rootTeam->id, 'canvas_type' => 'food_dna', 'field_key' => 'leitaromen_liste',
        'label' => 'Leit-Aromen (Liste)', 'group_name' => 'Aromatik & Tabus', 'type' => 'repeatable',
        'sub' => ['claim' => 'Claim'], 'position' => 1, 'active' => true,
    ]);
    $this->svc->addEntry($ober, 'leitaromen_liste', 'Zitrus', ['claim' => 'frisch'], true);
    $this->svc->addEntry($ober, 'leitaromen_liste', 'Salz');
    $this->svc->addEntry($kind, 'leitaromen_liste', 'zitrus');          // Dublette (Groß/Klein egal)
    $this->svc->addEntry($kind, 'leitaromen_liste', 'Räucher');

    $zeile = $this->dna->foodDna($this->childA)['leitaromen_liste'];

    expect(array_column($zeile['wert'], 'value'))->toBe(['Zitrus', 'Salz', 'Räucher'])
        ->and($zeile['wert'][0]['verbindlich'])->toBeTrue()
        ->and($zeile['wert'][0]['quelle_team_id'])->toBe((int) $this->rootTeam->id)
        ->and($zeile['wert'][2]['quelle_team_id'])->toBe((int) $this->childA->id)
        ->and($zeile['verbindlich'])->toBeTrue();
});

it('Zusatzfelder: das Oberteam definiert sie, das Unter-Team sieht sie; Kern-Felder bleiben unantastbar', function () {
    FoodAlchemistCanvasTemplate::create([
        'team_id' => $this->rootTeam->id, 'canvas_type' => 'food_dna', 'field_key' => 'nachhaltigkeit',
        'label' => 'Nachhaltigkeit', 'group_name' => 'Haltung', 'type' => 'longtext', 'position' => 1, 'active' => true,
    ]);
    FoodAlchemistCanvasTemplate::create([   // gleicher Key wie ein Kern-Feld: ignoriert
        'team_id' => $this->rootTeam->id, 'canvas_type' => 'food_dna', 'field_key' => 'leitbild',
        'label' => 'FALSCH', 'type' => 'text', 'position' => 2, 'active' => true,
    ]);
    FoodAlchemistCanvasTemplate::create([   // inaktiv: nicht dabei
        'team_id' => $this->rootTeam->id, 'canvas_type' => 'food_dna', 'field_key' => 'alt',
        'label' => 'Alt', 'type' => 'text', 'position' => 3, 'active' => false,
    ]);

    $keys = array_column($this->svc->felder('food_dna', $this->childA), 'key');
    $labels = array_column($this->svc->felder('food_dna', $this->childA), 'label', 'key');

    expect($keys)->toContain('nachhaltigkeit')->not->toContain('alt')
        ->and($labels['leitbild'])->toBe('Leitbild / Positionierung')
        ->and(array_column($this->svc->felder('food_dna'), 'key'))->not->toContain('nachhaltigkeit');   // ohne Team nur Kern

    ($this->setze)($this->rootTeam, 'nachhaltigkeit', 'Saisonal und regional.');
    expect($this->dna->foodDna($this->childA)['nachhaltigkeit']['wert'])->toBe('Saisonal und regional.');
});

it('Zusatzfelder eines fremden Teams erscheinen nicht', function () {
    FoodAlchemistCanvasTemplate::create([
        'team_id' => $this->childB->id, 'canvas_type' => 'food_dna', 'field_key' => 'nur_b',
        'label' => 'Nur B', 'type' => 'text', 'position' => 1, 'active' => true,
    ]);

    expect(array_column($this->svc->felder('food_dna', $this->childA), 'key'))->not->toContain('nur_b');
});

it('promptKontext: gleiches Format wie der Canvas-Block; ohne Vererbung identisch zum Bestand', function () {
    ($this->setze)($this->rootTeam, 'leitbild', 'Regionale Küche.');
    ($this->setze)($this->rootTeam, 'no_gos', 'Kein Foie gras.');

    $alt = $this->svc->promptKontext($this->svc->find('food_dna', 'team', $this->rootTeam->id));

    expect($this->dna->promptKontext($this->rootTeam))->toBe($alt);

    // das Unter-Team ergänzt ein Feld: Block zeigt feldweise Mischung in fester Feld-Reihenfolge
    ($this->setze)($this->childA, 'aromatik', 'Zitrus');
    expect($this->dna->promptKontext($this->childA))->toBe(
        "Küchen-/Marken-DNA des Mandanten (verbindlicher Stil-/Geschmacks-Rahmen):\n- Leitbild / Positionierung: Regionale Küche.\n- Aromatik / Leit-Aromen: Zitrus\n- No-Gos / Tabus: Kein Foie gras."
    );
});

it('setSkalar/addEntry: Verbindlich-Flag wird gesetzt und beim Aktualisieren nur auf Wunsch geändert', function () {
    $canvas = $this->svc->canvasFor($this->rootTeam, 'food_dna', 'team', $this->rootTeam->id);
    $this->svc->setSkalar($canvas, 'no_gos', 'A', true);
    $this->svc->setSkalar($canvas, 'no_gos', 'B');   // null = Flag bleibt
    expect($canvas->entries()->where('field_key', 'no_gos')->first()->is_binding)->toBeTrue();

    $this->svc->setSkalar($canvas, 'no_gos', 'B', false);
    expect($canvas->entries()->where('field_key', 'no_gos')->first()->is_binding)->toBeFalse();
});
