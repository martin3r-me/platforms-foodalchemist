<?php

use Livewire\Livewire;
use Platform\FoodAlchemist\Livewire\Planung\Index as PlanungIndex;
use Platform\FoodAlchemist\Models\FoodAlchemistBriefTemplate;
use Platform\FoodAlchemist\Services\BriefTemplateService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Schnellstart-Vorlagen (Brief-Templates): kunden-anlegbare Startpunkte für die Planung-Erzeugung.
 * Eine Vorlage = benannter Snapshot (Brief + Kreativ-Modus + kompletter Leitplanken-Stand) je Scope.
 * Beweisziele: kuratierte Globals sichtbar + read-only, Snapshot aus dem Editor legt team-eigene an,
 * Anwenden setzt Brief + Regler, Owns-Guard schützt fremde/kuratierte Vorlagen (D1-Tenancy).
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam));
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

it('kuratierte Globals sind read-only (loeschen/umbenennen wirft)', function () {
    $global = FoodAlchemistBriefTemplate::whereNull('team_id')->first();
    $svc = app(BriefTemplateService::class);

    expect(fn () => $svc->loeschen($this->rootTeam, (int) $global->id))->toThrow(RuntimeException::class);
    expect(fn () => $svc->umbenennen($this->rootTeam, (int) $global->id, 'Neu'))->toThrow(RuntimeException::class);
    expect(FoodAlchemistBriefTemplate::find($global->id))->not->toBeNull();   // unangetastet
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
