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
 * Beweisziele: kuratierte Globals sichtbar + read-only, Snapshot aus dem Editor legt team-eigene an,
 * Anwenden setzt Brief + Regler, Owns-Guard schützt fremde/kuratierte Vorlagen (D1-Tenancy).
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->user = $this->makeUser($this->rootTeam);
    $this->actingAs($this->user);
});

it('seedet die 6 kuratierten Globals (Gericht-Scope, team_id NULL, read-only)', function () {
    $globals = FoodAlchemistBriefTemplate::whereNull('team_id')->get();
    expect($globals)->toHaveCount(6)
        ->and($globals->pluck('scope')->unique()->all())->toBe(['gericht']);

    $fuerGericht = app(BriefTemplateService::class)->fuer($this->rootTeam, 'gericht');
    expect($fuerGericht)->toHaveCount(6)
        ->and(collect($fuerGericht)->every(fn ($v) => $v['is_global']))->toBeTrue();

    // Kein Global auf dem Basisrezept-Tab → leer, bis der Kunde dort selbst eine anlegt.
    expect(app(BriefTemplateService::class)->fuer($this->rootTeam, 'rezept'))->toBe([]);
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

it('Globals: Master-Team (root) kuratiert sie, ein Kind-Team nicht', function () {
    $global = FoodAlchemistBriefTemplate::whereNull('team_id')->firstOrFail();
    $svc = app(BriefTemplateService::class);

    // Kind-Team (childA, parent gesetzt) ist NICHT Master → globale Vorlage read-only.
    expect(fn () => $svc->umbenennen($this->childA, (int) $global->id, 'Hack'))->toThrow(RuntimeException::class);
    expect(fn () => $svc->loeschen($this->childA, (int) $global->id))->toThrow(RuntimeException::class);

    // rootTeam (parent NULL = Master) DARF global kuratieren.
    $svc->umbenennen($this->rootTeam, (int) $global->id, 'BHG-Empfang v2');
    expect(FoodAlchemistBriefTemplate::find($global->id)->label)->toBe('BHG-Empfang v2');
    $svc->loeschen($this->rootTeam, (int) $global->id);
    expect(FoodAlchemistBriefTemplate::find($global->id))->toBeNull();
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

it('MCP LIST: liefert die kuratierten Globals (read-only, nicht editierbar)', function () {
    $res = (new BriefTemplatesListTool())->execute(['scope' => 'gericht'], new ToolContext($this->user, $this->rootTeam));

    expect($res->success)->toBeTrue()
        ->and($res->data['total'])->toBe(6)
        ->and(collect($res->data['brief_templates'])->every(fn ($v) => $v['is_global'] && ! $v['editable']))->toBeTrue();
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

it('MCP: ein Kind-Team kann Globals NICHT kuratieren (PUT/DELETE → error)', function () {
    $ctx = new ToolContext($this->makeUser($this->childA), $this->childA);
    $global = FoodAlchemistBriefTemplate::whereNull('team_id')->firstOrFail();

    $put = (new BriefTemplatesPutTool())->execute(['id' => (int) $global->id, 'label' => 'Hack'], $ctx);
    expect($put->success)->toBeFalse()->and($put->errorCode)->toBe('ACCESS_DENIED');

    $del = (new BriefTemplatesDeleteTool())->execute(['id' => (int) $global->id], $ctx);
    expect($del->success)->toBeFalse();
    expect(FoodAlchemistBriefTemplate::find($global->id))->not->toBeNull();
});

it('MCP: Master-Team (root) kuratiert einen Global (PUT active=false greift)', function () {
    $ctx = new ToolContext($this->user, $this->rootTeam);   // rootTeam = Master (parent NULL)
    $global = FoodAlchemistBriefTemplate::whereNull('team_id')->firstOrFail();

    $put = (new BriefTemplatesPutTool())->execute(['id' => (int) $global->id, 'active' => false], $ctx);
    expect($put->success)->toBeTrue()->and($put->data['active'])->toBeFalse();
    expect(FoodAlchemistBriefTemplate::find($global->id)->active)->toBeFalse();
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
