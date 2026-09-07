<?php

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Tools\ToolRegistry;
use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Services\IngredientMatchService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * POST-MATCH-DRAW in `foodalchemist.gps.MATCH` (2026-09-07).
 *
 * Befund: der Generator zieht nach einer erfolglosen — bewusst lexikalischen —
 * Matcher-Entscheidung noch aus der semantisch-inklusiven Shortlist
 * ({@see \Platform\FoodAlchemist\Services\RecipeGeneratorService::ziehtAusBestand}, Erdungs-Stärke
 * B2). Das MCP-Tool kannte diesen Zug NICHT und meldete `none` — bei „Tomaten, geschält, aus der
 * Dose" mit dem korrekten `Tomaten: konserviert, ganz, geschaelt / Pelati` bei **0,769** in
 * derselben Antwort.
 *
 * Das ist nicht bloß eine unschöne Anzeige. Die Tool-Anleitung sagt „PFLICHT vor jeder
 * Rezept-Zutat … kein Treffer und kein Mint → gp_proposals.POST (Beschaffungs-Wunsch)". Ein Agent,
 * der ihr folgt, legt daraufhin ein GP-Duplikat (mint_if_missing) oder Phantom-Bedarf an, obwohl
 * ein approved-Kandidat direkt daneben steht — die Klasse „das Etikett lügt, die Diagnose kippt".
 *
 * Die AUSWAHL liegt jetzt geteilt in `IngredientMatchService::drawKandidaten`, die PRÜFUNG beim
 * Aufrufer. Der Matcher selbst ist unangetastet: die Entscheidung bleibt lexikalisch mit
 * Band-Gate, der Draw ist ein eigener Zug über einem HÖHEREN Boden.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->user = $this->makeUser($this->rootTeam);
    $this->actingAs($this->user);
    $this->registry = app(ToolRegistry::class);
    $this->kontext = new ToolContext($this->user, $this->rootTeam);

    /**
     * Der Matcher mit erzwungener Ausgangslage: Entscheidung `none`, aber ein semantischer
     * Kandidat in der Shortlist. `drawKandidaten`/`drawFloor` bleiben ECHT — genau die sollen
     * geprüft werden. Nötig, weil die semantische Suche im Test aus ist und `candidatesFor`
     * sonst nur `origin: lexical` liefert, was der Draw korrekt verwirft.
     */
    $this->matcherMit = function (array $shortlist) {
        $mock = Mockery::mock(IngredientMatchService::class)->makePartial();
        $mock->shouldReceive('matchIngredient')->andReturn([
            'target' => 'none', 'status' => 'no_match', 'gp_id' => null, 'gp_name' => null,
            'recipe_id' => null, 'recipe_name' => null, 'score' => 0.4,
        ]);
        $mock->shouldReceive('candidatesFor')->andReturn($shortlist);
        app()->instance(IngredientMatchService::class, $mock);
    };
});

// ── Die Auswahl selbst (reine Funktion, kein Provider nötig) ────────────────────────────

it('drawKandidaten: nur origin both|semantic, ueber dem Modus-Boden, score-desc mit Abbruch', function () {
    $svc = app(IngredientMatchService::class);
    $shortlist = [
        ['kind' => 'gp', 'id' => 1, 'name' => 'A', 'score' => 0.90, 'origin' => 'lexical'],   // raus: lexical
        ['kind' => 'sub', 'id' => 2, 'name' => 'B', 'score' => 0.85, 'origin' => 'semantic'], // raus: falsche Art
        ['kind' => 'gp', 'id' => 3, 'name' => 'C', 'score' => 0.80, 'origin' => 'both'],      // rein
        ['kind' => 'gp', 'id' => 4, 'name' => 'D', 'score' => 0.72, 'origin' => 'semantic'],  // rein
        ['kind' => 'gp', 'id' => 5, 'name' => 'E', 'score' => 0.60, 'origin' => 'semantic'],  // raus: unter 0,70
        ['kind' => 'gp', 'id' => 6, 'name' => 'F', 'score' => 0.99, 'origin' => 'semantic'],  // raus: nach dem Abbruch
    ];

    $ids = array_column($svc->drawKandidaten($shortlist, 'gp', 'hybrid'), 'id');

    // Der Abbruch bei Unterschreitung ist ABSICHT (desc-sortierte Liste) — er ist der Grund,
    // warum ein spaeterer 0,99-Eintrag NICHT mehr gezogen wird. Wer die Liste unsortiert
    // uebergibt, bekommt genau das zu sehen.
    expect($ids)->toBe([3, 4]);
});

it('drawFloor: hybrid 0,70 · nur_bestand 0,55 · komplett_neu zieht NIE', function () {
    $svc = app(IngredientMatchService::class);

    expect($svc->drawFloor('hybrid'))->toBe(0.70)
        ->and($svc->drawFloor('nur_bestand'))->toBe(0.55)
        ->and($svc->drawFloor('komplett_neu'))->toBeNull()
        ->and($svc->drawFloor('quatsch'))->toBeNull()
        ->and($svc->drawKandidaten(
            [['kind' => 'gp', 'id' => 1, 'score' => 0.99, 'origin' => 'semantic']], 'gp', 'komplett_neu'
        ))->toBe([]);
});

it('drawKandidaten: nur_bestand erdet breiter als hybrid (0,55 gegen 0,70)', function () {
    $svc = app(IngredientMatchService::class);
    $shortlist = [['kind' => 'gp', 'id' => 7, 'score' => 0.60, 'origin' => 'semantic']];

    expect($svc->drawKandidaten($shortlist, 'gp', 'hybrid'))->toBe([])
        ->and(array_column($svc->drawKandidaten($shortlist, 'gp', 'nur_bestand'), 'id'))->toBe([7]);
});

// ── Das Tool: kein `none` mehr, wo die Pipeline zieht ───────────────────────────────────

it('gps.MATCH zieht den semantischen Bestandstreffer statt none zu melden', function () {
    $pelati = $this->makeGp($this->rootTeam, 'Tomaten: konserviert, ganz, geschaelt / Pelati');
    ($this->matcherMit)([
        ['kind' => 'gp', 'id' => $pelati->id, 'name' => $pelati->name, 'score' => 0.769, 'origin' => 'both'],
    ]);

    $res = $this->registry->get('foodalchemist.gps.MATCH')
        ->execute(['zutat' => 'Tomaten, geschält, aus der Dose'], $this->kontext);

    expect($res->success)->toBeTrue()
        ->and($res->data['best_match']['target'])->toBe('gp')
        ->and($res->data['best_match']['status'])->toBe('gezogen')
        ->and($res->data['best_match']['gp_id'])->toBe($pelati->id)
        // Herkunft muss sichtbar sein — sonst ist ein gezogener Treffer nicht von einem
        // Namenstreffer zu unterscheiden.
        ->and($res->data['best_match']['origin'])->toBe('semantic')
        ->and($res->data['best_match']['draw_floor'])->toBe(0.70)
        ->and($res->data['draw'])->toBe(['bestand' => 'hybrid', 'floor' => 0.70]);
});

it('gps.MATCH mintet NICHT, wenn gezogen werden konnte — der Weg zur GP-Dublette', function () {
    $pelati = $this->makeGp($this->rootTeam, 'Tomaten: konserviert, ganz, geschaelt / Pelati');
    ($this->matcherMit)([
        ['kind' => 'gp', 'id' => $pelati->id, 'name' => $pelati->name, 'score' => 0.769, 'origin' => 'both'],
    ]);
    $vorher = FoodAlchemistGp::count();

    $res = $this->registry->get('foodalchemist.gps.MATCH')
        ->execute(['zutat' => 'Tomaten, geschält, aus der Dose', 'mint_if_missing' => true], $this->kontext);

    // Genau das war der Schaden: mint_if_missing sah `none` und legte ein zweites GP an.
    expect($res->data['minted'])->toBeFalse()
        ->and($res->data['best_match']['gp_id'])->toBe($pelati->id)
        ->and(FoodAlchemistGp::count())->toBe($vorher);
});

it('gps.MATCH: komplett_neu zieht nicht — none bleibt none', function () {
    $pelati = $this->makeGp($this->rootTeam, 'Tomaten: konserviert, ganz, geschaelt / Pelati');
    ($this->matcherMit)([
        ['kind' => 'gp', 'id' => $pelati->id, 'name' => $pelati->name, 'score' => 0.99, 'origin' => 'both'],
    ]);

    $res = $this->registry->get('foodalchemist.gps.MATCH')
        ->execute(['zutat' => 'Tomaten', 'bestand' => 'komplett_neu'], $this->kontext);

    expect($res->data['best_match']['target'])->toBe('none')
        ->and($res->data['draw']['floor'])->toBeNull();
});

it('gps.MATCH: ein rein lexikalischer Kandidat wird NICHT gezogen — das Band-Gate bleibt scharf', function () {
    // Gegenprobe zur Invariante: unter dem Band heisst „zu schwach", nicht „ungeprueft".
    // Wuerde der Draw auch lexikalische Kandidaten ziehen, haette er das Gate ausgehebelt.
    $gp = $this->makeGp($this->rootTeam, 'Tomatenmark: konserviert, konzentriert');
    ($this->matcherMit)([
        ['kind' => 'gp', 'id' => $gp->id, 'name' => $gp->name, 'score' => 0.95, 'origin' => 'lexical'],
    ]);

    $res = $this->registry->get('foodalchemist.gps.MATCH')
        ->execute(['zutat' => 'Tomaten, geschält, aus der Dose'], $this->kontext);

    expect($res->data['best_match']['target'])->toBe('none');
});
