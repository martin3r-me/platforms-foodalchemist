<?php

use Illuminate\Support\Facades\Queue;
use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Services\GenerationContextService;
use Platform\FoodAlchemist\Services\RecipeExtractService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Rezeptimport-Pilot (io 09.11.2026): (1) über MCP muss der EXTRACT-Prompt bei jedem Neustart wortgleich sein — gleich bewertete
 * Kandidaten wechselten die Reihenfolge, Scores rauschten in der 3. Nachkommastelle → je Antwort ein neuer Auftrag (Schleife).
 * (2) Kein Unterrezept-Stub für Zeilen, die die Extraktion als Kaufware führt (sub_rezept=false oder GP-Vorschlag).
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam));
    config(['foodalchemist.semantic_search.enabled' => false]);
    Queue::fake();
    $this->unitG($this->rootTeam);
});

it('(1) Kandidaten: gleiche Eingabe in anderer Reihenfolge oder mit Score-Rauschen ergibt dieselbe Liste', function () {
    $a = [['id' => 13294, 'name' => 'A', 'score' => 0.581], ['id' => 7848, 'name' => 'B', 'score' => 0.581], ['id' => 50, 'name' => 'C', 'score' => 0.6943]];
    $b = [['id' => 50, 'name' => 'C', 'score' => 0.6948], ['id' => 13294, 'name' => 'A', 'score' => 0.581], ['id' => 7848, 'name' => 'B', 'score' => 0.5812]];

    $sa = GenerationContextService::kandidatenStabil($a);
    expect($sa)->toBe(GenerationContextService::kandidatenStabil($b))
        ->and(array_column($sa, 'id'))->toBe([50, 7848, 13294])            // Score absteigend, Gleichstand nach id
        ->and($sa[0]['score'])->toBe(0.69);
});

it('(2) Import: sub_rezept=false oder GP-Vorschlag → kein Stub; ohne Angabe bleibt der Stub (Basiskomponente)', function () {
    $import = app(RecipeExtractService::class);
    $gp = FoodAlchemistGp::create(['team_id' => $this->rootTeam->id, 'name' => 'Kräutermischung: frisch', 'gp_key' => 'ip-' . bin2hex(random_bytes(4)) . '||',
        'status' => 'approved', 'requires_la' => true, 'commodity_group_code' => '04', 'main_ingredient_slug' => 'kraeutermischung']);
    $rezept = fn (string $name, array $zeile) => ['typ' => 'basisrezept', 'name' => $name, 'preparation' => 'Kochen.',
        'zutaten' => [['text' => 'Geflügelfond', 'quantity' => '500', 'unit' => 'g'] + $zeile]];

    $ohne = $import->legeAn($this->rootTeam, $rezept('Rahmsauce A', []));
    $kauf = $import->legeAn($this->rootTeam, $rezept('Rahmsauce B', ['sub_rezept' => false]));
    $mitGp = $import->legeAn($this->rootTeam, $rezept('Rahmsauce C', ['gp_id' => $gp->id]));
    $explizit = $import->legeAn($this->rootTeam, $rezept('Rahmsauce D', ['gp_id' => $gp->id, 'sub_rezept' => true]));

    expect($ohne['stubs'])->toHaveCount(1)
        ->and($kauf['stubs'])->toBe([])
        ->and($mitGp['stubs'])->toBe([])
        ->and($explizit['stubs'])->toHaveCount(1);                         // ausdrücklich Unterrezept: Stub bleibt
});
