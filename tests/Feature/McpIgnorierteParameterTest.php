<?php

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistAngebot;
use Platform\FoodAlchemist\Models\FoodAlchemistSignal;
use Platform\FoodAlchemist\Services\SpeiseplanService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;
use Platform\FoodAlchemist\Tools\AngeboteSearchTool;
use Platform\FoodAlchemist\Tools\ConceptsSearchTool;
use Platform\FoodAlchemist\Tools\RecipesSearchTool;
use Platform\FoodAlchemist\Tools\SignaleListTool;
use Platform\FoodAlchemist\Tools\SignaleSearchTool;
use Platform\FoodAlchemist\Tools\SpeiseplaeneListTool;

uses(TestCase::class, SeedsTeamHierarchy::class);

trait Spec91SemantischFest
{
    public function __construct(private array $semIds) {}

    protected function semanticPoolIds(Team $team, string $query, string $entityType, array $existingIds, int $limit): array
    {
        return array_fill_keys(array_values(array_diff($this->semIds, $existingIds)), 0.9);
    }
}

/**
 * Spec 91 PR 2 (A2–A4): Parameter, die deklariert oder dokumentiert waren, aber nicht wirkten — speiseplaene.LIST `status`,
 * signale.SEARCH `status=abgeschlossen` (gibt es nicht → still 0 Treffer), und die semantischen Pässe von recipes/concepts/angebote.SEARCH,
 * die `status`/`vorlagen` übergingen. Falsche enum-Werte sind jetzt ein VALIDATION_ERROR statt still 0 Treffer.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->user = $this->makeUser($this->rootTeam);
    $this->actingAs($this->user);
    config(['foodalchemist.semantic_search.enabled' => false]);
    $this->ctx = new ToolContext($this->user, $this->rootTeam);
    // Semantischer Pass als fester Treffer: das Tool bekommt die übergebenen IDs als „semantisch gefunden“, die Filter muss es selbst anwenden.
    $this->semantisch = fn (string $klasse, array $ids) => match ($klasse) {
        RecipesSearchTool::class => new class($ids) extends RecipesSearchTool { use Spec91SemantischFest; },
        ConceptsSearchTool::class => new class($ids) extends ConceptsSearchTool { use Spec91SemantischFest; },
        AngeboteSearchTool::class => new class($ids) extends AngeboteSearchTool { use Spec91SemantischFest; },
    };
});

it('A2 · speiseplaene.LIST filtert nach status; unbekannter Status ist ein Fehler', function () {
    $svc = app(SpeiseplanService::class);
    $svc->create($this->rootTeam, ['name' => 'Kantine Nord', 'status' => 'aktiv']);
    $svc->create($this->rootTeam, ['name' => 'Kantine Süd']);
    $namen = fn (array $a) => array_column(app(SpeiseplaeneListTool::class)->execute($a, $this->ctx)->data['speiseplaene'], 'name');

    expect($namen(['status' => 'aktiv']))->toBe(['Kantine Nord'])
        ->and($namen([]))->toBe(['Kantine Nord', 'Kantine Süd'])
        ->and(app(SpeiseplaeneListTool::class)->execute(['status' => 'laufend'], $this->ctx)->errorCode)->toBe('VALIDATION_ERROR');
});

it('A3 · signale.SEARCH/LIST: erledigt filtert, „abgeschlossen“ ist ein Fehler mit den erlaubten Werten statt still 0 Treffer', function () {
    foreach (['offen', 'erledigt', 'ignoriert'] as $i => $status) {
        FoodAlchemistSignal::create(['team_id' => $this->rootTeam->id, 'type' => 'preis_anomalie', 'severity' => 'warnung',
            'status' => $status, 'title' => "Signal {$status}", 'dedup_key' => "k{$i}", 'source' => 'test']);
    }
    $such = fn (array $a) => app(SignaleSearchTool::class)->execute($a, $this->ctx);
    $fehler = $such(['status' => 'abgeschlossen']);

    expect(array_column($such(['status' => 'erledigt'])->data['signale'], 'title'))->toBe(['Signal erledigt'])
        ->and($such(['status' => ''])->data['total'])->toBe(3)
        ->and($fehler->errorCode)->toBe('VALIDATION_ERROR')
        ->and($fehler->error)->toContain('erledigt')
        ->and(app(SignaleListTool::class)->execute(['status' => 'abgeschlossen'], $this->ctx)->errorCode)->toBe('VALIDATION_ERROR')
        ->and(array_column(app(SignaleListTool::class)->execute(['status' => 'ignoriert'], $this->ctx)->data['signale'], 'title'))->toBe(['Signal ignoriert']);
});

it('A4 · recipes.SEARCH: status gilt auch für semantische Treffer', function () {
    $frei = $this->makeRecipe($this->rootTeam, 'Rinderfond', ['status' => 'approved']);
    $entwurf = $this->makeRecipe($this->rootTeam, 'Kalbsjus', ['status' => 'draft']);
    $tool = ($this->semantisch)(RecipesSearchTool::class, [$frei->id, $entwurf->id]);

    $mit = $tool->execute(['q' => 'braune Basis', 'status' => 'approved'], $this->ctx)->data['recipes'];
    $ohne = $tool->execute(['q' => 'braune Basis'], $this->ctx)->data['recipes'];

    expect(array_column($mit, 'name'))->toBe(['Rinderfond'])->and($mit[0]['via'])->toBe('semantic')
        ->and(array_column($ohne, 'name'))->toEqualCanonicalizing(['Rinderfond', 'Kalbsjus'])
        ->and(app(RecipesSearchTool::class)->execute(['q' => 'x', 'status' => 'freigegeben'], $this->ctx)->errorCode)->toBe('VALIDATION_ERROR');
});

it('A4 · concepts.SEARCH: status und vorlagen gelten auch für semantische Treffer', function () {
    $aktiv = $this->makeConcept($this->rootTeam, 'Sommerbuffet', ['status' => 'active']);
    $entwurf = $this->makeConcept($this->rootTeam, 'Herbstbuffet', ['status' => 'draft']);
    $vorlage = $this->makeConcept($this->rootTeam, 'Buffet-Vorlage', ['status' => 'active', 'is_template' => true]);
    $tool = ($this->semantisch)(ConceptsSearchTool::class, [$aktiv->id, $entwurf->id, $vorlage->id]);
    $namen = fn (array $a) => array_column($tool->execute(['q' => 'Fingerfood'] + $a, $this->ctx)->data['concepts'], 'name');

    expect($namen(['status' => 'active']))->toBe(['Sommerbuffet'])
        ->and($namen([]))->toEqualCanonicalizing(['Sommerbuffet', 'Herbstbuffet'])
        ->and($namen(['vorlagen' => true]))->toBe(['Buffet-Vorlage']);
});

it('A4 · angebote.SEARCH: status gilt auch für semantische Treffer; unbekannter Status ist ein Fehler', function () {
    $neu = fn (string $name, string $status) => FoodAlchemistAngebot::create(['team_id' => $this->rootTeam->id, 'name' => $name, 'status' => $status, 'personen' => 50]);
    $anfrage = $neu('Sommerfest', 'anfrage');
    $angebot = $neu('Weihnachtsfeier', 'angebot');
    $tool = ($this->semantisch)(AngeboteSearchTool::class, [$anfrage->id, $angebot->id]);

    expect(array_column($tool->execute(['q' => 'Firmenevent', 'status' => 'anfrage'], $this->ctx)->data['angebote'], 'name'))->toBe(['Sommerfest'])
        ->and(app(AngeboteSearchTool::class)->execute(['status' => 'gewonnen_offen'], $this->ctx)->errorCode)->toBe('VALIDATION_ERROR');
});

it('die PR-2-Tools melden unbekannte Parameter als warnungen', function () {
    $r = app(SpeiseplaeneListTool::class)->execute(['kunde' => 'Nord'], $this->ctx);

    expect($r->success)->toBeTrue()->and($r->data['warnungen'][0])->toContain("unbekannter Parameter 'kunde'")
        ->and(app(SignaleSearchTool::class)->execute(['suche' => 'x'], $this->ctx)->data['warnungen'][0])->toContain("'suche'");
});
