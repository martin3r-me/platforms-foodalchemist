<?php

use Livewire\Livewire;
use Platform\Core\Contracts\ToolContext;
use Platform\FoodAlchemist\Livewire\Planung\Index as PlanungIndex;
use Platform\FoodAlchemist\Livewire\Settings\BriefVorlagen;
use Platform\FoodAlchemist\Models\FoodAlchemistBriefTemplate;
use Platform\FoodAlchemist\Services\BriefTemplateService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;
use Platform\FoodAlchemist\Tools\BriefTemplatesDeleteTool;
use Platform\FoodAlchemist\Tools\BriefTemplatesListTool;
use Platform\FoodAlchemist\Tools\BriefTemplatesPostTool;
use Platform\FoodAlchemist\Tools\BriefTemplatesPutTool;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Schnellstart-Vorlagen (Brief-Templates): kunden-anlegbare Startpunkte für die Planung-Erzeugung.
 * Eine Vorlage = benannter Snapshot (Brief + Kreativ-Modus + kompletter Leitplanken-Stand) je Scope.
 * Beweisziele (Spec 84 L1: Vorlagen gehören dem Team): Snapshot aus dem Editor legt team-eigene an, Unter-Teams lesen die
 * des Oberteams, Anwenden setzt Brief + Regler, Owns-Guard schützt fremde Vorlagen, kein Master-Sonderweg, nichts ohne Team.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->user = $this->makeUser($this->rootTeam);
    $this->actingAs($this->user);
});

it('Spec 84 L1: Vorlagen ohne Team (frühere Globals) sieht niemand', function () {
    // In der Test-DB laufen die Migrationen vor dem Anlegen der Teams → die Kopier-Migration findet kein Hauptteam
    // und lässt die 6 Seed-Zeilen stehen. Sie sind ohne Team und damit für kein Team sichtbar.
    expect(FoodAlchemistBriefTemplate::whereNull('team_id')->count())->toBe(6);
    expect(app(BriefTemplateService::class)->fuer($this->rootTeam, 'gericht'))->toBe([])
        ->and(app(BriefTemplateService::class)->verwaltung($this->childA))->toHaveCount(0);
});

it('speichere: legt eine team-eigene Vorlage mit Regler-Snapshot an und zeigt sie im Scope', function () {
    $svc = app(BriefTemplateService::class);
    $tpl = $svc->speichere(
        $this->rootTeam, 'rezept', 'Meine Sauce', 'Kräftige Grundsauce, reduziert.',
        ['level' => 'gehoben', 'frische' => ['frisch'], 'bio_praeferenz' => 'bio'],
        titel: null, creativeMode: 'hybrid',
    );

    expect($tpl->team_id)->toBe($this->rootTeam->id)
        ->and($tpl->scope)->toBe('rezept')
        ->and($tpl->payload['regler']['level'])->toBe('gehoben')
        ->and($tpl->payload['creative_mode'])->toBe('hybrid');

    $fuer = $svc->fuer($this->rootTeam, 'rezept');
    expect($fuer)->toHaveKey((string) $tpl->id)
        ->and($fuer[(string) $tpl->id]['is_global'])->toBeFalse()
        ->and($fuer[(string) $tpl->id]['regler']['frische'])->toBe(['frisch']);
});

it('speichere: leerer Name oder leerer Brief wirft', function () {
    $svc = app(BriefTemplateService::class);
    expect(fn () => $svc->speichere($this->rootTeam, 'gericht', '  ', 'Brief da', []))->toThrow(RuntimeException::class);
    expect(fn () => $svc->speichere($this->rootTeam, 'gericht', 'Name', '   ', []))->toThrow(RuntimeException::class);
});

it('Spec 84 L1: Kind-Team sieht die Vorlagen des Oberteams nur lesend, das Oberteam pflegt sie', function () {
    $svc = app(BriefTemplateService::class);
    $tpl = $svc->speichere($this->rootTeam, 'gericht', 'Oberteam-Vorlage', 'Brief.', []);

    $fuerKind = $svc->fuer($this->childA, 'gericht');
    expect($fuerKind)->toHaveKey((string) $tpl->id)
        ->and($fuerKind[(string) $tpl->id]['is_global'])->toBeTrue();   // „geerbt, nur lesbar“
    expect(fn () => $svc->umbenennen($this->childA, (int) $tpl->id, 'Hack'))->toThrow(RuntimeException::class);
    expect(fn () => $svc->loeschen($this->childA, (int) $tpl->id))->toThrow(RuntimeException::class);

    $svc->umbenennen($this->rootTeam, (int) $tpl->id, 'Oberteam-Vorlage v2');
    expect(FoodAlchemistBriefTemplate::find($tpl->id)->label)->toBe('Oberteam-Vorlage v2');
});

it('Spec 84 L1: kein Master-Sonderweg — auch das konfigurierte Master-Team ändert keine Vorlage ohne Team', function () {
    config(['foodalchemist.master_team_id' => $this->rootTeam->id]);
    $mandant = \Platform\Core\Models\Team::create(['name' => 'Mandant ohne Eltern', 'user_id' => 1, 'personal_team' => false]);
    $ohneTeam = FoodAlchemistBriefTemplate::whereNull('team_id')->firstOrFail();
    $svc = app(BriefTemplateService::class);

    foreach ([$this->rootTeam, $mandant] as $team) {
        expect(fn () => $svc->loeschen($team, (int) $ohneTeam->id))->toThrow(RuntimeException::class);
        expect(fn () => $svc->umbenennen($team, (int) $ohneTeam->id, 'Hack'))->toThrow(RuntimeException::class);
    }
    expect(FoodAlchemistBriefTemplate::find($ohneTeam->id))->not->toBeNull();
});

it('Tenancy: ein fremdes Team kann eine Vorlage nicht löschen (Owns-Guard)', function () {
    $svc = app(BriefTemplateService::class);
    $tpl = $svc->speichere($this->rootTeam, 'gericht', 'Root-Vorlage', 'Brief.', ['level' => 'klassisch']);

    // childA (anderes Team) darf NICHT löschen — auch wenn es die Vorlage evtl. sieht.
    expect(fn () => $svc->loeschen($this->childA, (int) $tpl->id))->toThrow(RuntimeException::class);
    expect(FoodAlchemistBriefTemplate::find($tpl->id))->not->toBeNull();

    // Eigenes Team darf.
    $svc->loeschen($this->rootTeam, (int) $tpl->id);
    expect(FoodAlchemistBriefTemplate::find($tpl->id))->toBeNull();
});

it('Livewire: alsVorlageSpeichern snapshotet den aktuellen Stand, briefVorlage wendet ihn an', function () {
    $c = Livewire::test(PlanungIndex::class)
        ->set('regler.gericht.level', 'gehoben')
        ->set('regler.gericht.bio_praeferenz', 'bio')
        ->set('eingabe.gericht.brief', 'Snapshot-Brief für den Test.')
        ->set('vorlageName', 'Test-Snapshot')
        ->call('alsVorlageSpeichern', 'gericht');

    $tpl = FoodAlchemistBriefTemplate::where('team_id', $this->rootTeam->id)->where('label', 'Test-Snapshot')->first();
    expect($tpl)->not->toBeNull()
        ->and($tpl->payload['regler']['level'])->toBe('gehoben')
        ->and($tpl->brief)->toBe('Snapshot-Brief für den Test.');

    // Anwenden auf einen frisch defaulteten Stand setzt Brief + Regler zurück auf den Snapshot.
    $c->set('regler.gericht.level', '')->set('eingabe.gericht.brief', '')
        ->call('briefVorlage', 'gericht', (string) $tpl->id)
        ->assertSet('eingabe.gericht.brief', 'Snapshot-Brief für den Test.')
        ->assertSet('regler.gericht.level', 'gehoben')
        ->assertSet('aktiveVorlage.gericht', (string) $tpl->id);
});

it('Livewire: loeschenVorlage entfernt eine eigene Vorlage', function () {
    $tpl = app(BriefTemplateService::class)->speichere($this->rootTeam, 'gericht', 'Weg damit', 'Brief.', []);

    Livewire::test(PlanungIndex::class)->call('loeschenVorlage', 'gericht', (int) $tpl->id);

    expect(FoodAlchemistBriefTemplate::find($tpl->id))->toBeNull();
});

// ── MCP-Tools (foodalchemist.brief_templates.*) ────────────────────────────

it('MCP LIST: eigene editierbar, die des Oberteams nur lesbar, nichts ohne Team', function () {
    $tpl = app(BriefTemplateService::class)->speichere($this->rootTeam, 'gericht', 'Oberteam-Vorlage', 'Brief.', []);

    $root = (new BriefTemplatesListTool())->execute(['scope' => 'gericht'], new ToolContext($this->user, $this->rootTeam));
    expect($root->success)->toBeTrue()->and($root->data['total'])->toBe(1)
        ->and($root->data['brief_templates'][0]['editable'])->toBeTrue()
        ->and($root->data['brief_templates'][0]['is_global'])->toBeFalse();

    $kind = (new BriefTemplatesListTool())->execute(['scope' => 'gericht'], new ToolContext($this->makeUser($this->childA), $this->childA));
    expect($kind->data['total'])->toBe(1)
        ->and($kind->data['brief_templates'][0]['id'])->toBe((int) $tpl->id)
        ->and($kind->data['brief_templates'][0]['editable'])->toBeFalse()
        ->and($kind->data['brief_templates'][0]['is_global'])->toBeTrue();
});

it('MCP: voller CRUD einer eigenen Vorlage (POST → LIST → PUT → DELETE)', function () {
    $ctx = new ToolContext($this->user, $this->rootTeam);

    $post = (new BriefTemplatesPostTool())->execute([
        'scope' => 'rezept', 'label' => 'MCP-Sauce', 'brief' => 'Kräftige Grundsauce.',
        'regler' => ['level' => 'gehoben', 'frische' => ['frisch']], 'creative_mode' => 'hybrid',
    ], $ctx);
    expect($post->success)->toBeTrue()
        ->and($post->data['scope'])->toBe('rezept')
        ->and($post->data['regler']['level'])->toBe('gehoben');
    $id = $post->data['id'];

    $list = (new BriefTemplatesListTool())->execute(['scope' => 'rezept'], $ctx);
    expect(collect($list->data['brief_templates'])->pluck('id'))->toContain($id);

    $put = (new BriefTemplatesPutTool())->execute(['id' => $id, 'label' => 'MCP-Sauce v2', 'active' => false], $ctx);
    expect($put->success)->toBeTrue()
        ->and($put->data['label'])->toBe('MCP-Sauce v2')
        ->and($put->data['active'])->toBeFalse();

    $del = (new BriefTemplatesDeleteTool())->execute(['id' => $id], $ctx);
    expect($del->success)->toBeTrue();
    expect(FoodAlchemistBriefTemplate::find($id))->toBeNull();
});

it('MCP: ein Kind-Team ändert die Vorlagen des Oberteams NICHT (PUT/DELETE → error)', function () {
    $ctx = new ToolContext($this->makeUser($this->childA), $this->childA);
    $tpl = app(BriefTemplateService::class)->speichere($this->rootTeam, 'gericht', 'Oberteam-Vorlage', 'Brief.', []);

    $put = (new BriefTemplatesPutTool())->execute(['id' => (int) $tpl->id, 'label' => 'Hack'], $ctx);
    expect($put->success)->toBeFalse()->and($put->errorCode)->toBe('ACCESS_DENIED');

    $del = (new BriefTemplatesDeleteTool())->execute(['id' => (int) $tpl->id], $ctx);
    expect($del->success)->toBeFalse();
    expect(FoodAlchemistBriefTemplate::find($tpl->id))->not->toBeNull();
});

it('MCP: auch das Master-Team ändert keine Vorlage ohne Team (kein Master-Sonderweg)', function () {
    config(['foodalchemist.master_team_id' => $this->rootTeam->id]);
    $ctx = new ToolContext($this->user, $this->rootTeam);
    $ohneTeam = FoodAlchemistBriefTemplate::whereNull('team_id')->firstOrFail();

    $put = (new BriefTemplatesPutTool())->execute(['id' => (int) $ohneTeam->id, 'active' => false], $ctx);
    expect($put->success)->toBeFalse();
    expect(FoodAlchemistBriefTemplate::find($ohneTeam->id)->active)->toBeTrue();
});

// ── Settings-Verwaltungsseite ──────────────────────────────────────────────

it('Settings BriefVorlagen: eigene umbenennen + löschen greift', function () {
    $tpl = app(BriefTemplateService::class)->speichere($this->rootTeam, 'gericht', 'Alt', 'Brief.', []);

    $c = Livewire::test(BriefVorlagen::class)
        ->call('edit', (int) $tpl->id)
        ->set('editLabel', 'Neu')
        ->call('save');
    expect(FoodAlchemistBriefTemplate::find($tpl->id)->label)->toBe('Neu');

    $c->call('loeschen', (int) $tpl->id);
    expect(FoodAlchemistBriefTemplate::find($tpl->id))->toBeNull();
});
