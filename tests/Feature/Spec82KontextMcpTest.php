<?php

use Platform\Core\Contracts\ToolContext;
use Platform\FoodAlchemist\Models\FoodAlchemistAngebot;
use Platform\FoodAlchemist\Models\FoodAlchemistMandant;
use Platform\FoodAlchemist\Services\CanvasService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;
use Platform\FoodAlchemist\Tools\CanvasEntryAddTool;
use Platform\FoodAlchemist\Tools\CanvasGetTool;
use Platform\FoodAlchemist\Tools\CanvasPutTool;
use Platform\FoodAlchemist\Tools\KontextResolveTool;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 82 · Schritt 6 · MCP: is_binding an den Canvas-Tools, Zusatzfelder über felder(), kontext.RESOLVE (lesend)
 * mit der Sichtbarkeitsregel aus §13 G (Plattform-Admin sieht bei autarken Mandanten nichts).
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->owner = $this->makeUser($this->rootTeam, 'Chef', 'owner');
    $this->member = $this->makeUser($this->rootTeam, 'Koch', 'member');
    $this->kind = $this->makeUser($this->childA, 'Kind-Chef', 'owner');
    $this->actingAs($this->owner);
    $this->svc = app(CanvasService::class);
    $this->ctx = fn ($user, $team) => new ToolContext($user, $team);
});

it('canvas.PUT: das Oberteam setzt Felder verbindlich, canvas.GET zeigt es', function () {
    $put = (new CanvasPutTool())->execute(['type' => 'food_dna', 'felder' => ['no_gos' => 'Kein Foie gras.', 'leitbild' => 'Regional.'], 'verbindlich' => ['no_gos' => true]], ($this->ctx)($this->owner, $this->rootTeam));
    expect($put->success)->toBeTrue();

    $get = (new CanvasGetTool())->execute(['type' => 'food_dna'], ($this->ctx)($this->owner, $this->rootTeam));
    expect($get->data['verbindlich'])->toBe(['no_gos' => true])->and($get->data['werte']['no_gos'])->toBe('Kein Foie gras.');

    // Flag zurücknehmen, Wert bleibt
    (new CanvasPutTool())->execute(['type' => 'food_dna', 'felder' => [], 'verbindlich' => ['no_gos' => false]], ($this->ctx)($this->owner, $this->rootTeam));
    expect((new CanvasGetTool())->execute(['type' => 'food_dna'], ($this->ctx)($this->owner, $this->rootTeam))->data['verbindlich'])->toBe([]);
});

it('canvas.PUT: verbindlich nur im Oberteam, nur mit Admin-Recht, nur an food_dna, nur bekannte Keys', function () {
    $put = fn ($user, $team, array $a) => (new CanvasPutTool())->execute($a + ['type' => 'food_dna'], ($this->ctx)($user, $team));

    $kind = $put($this->kind, $this->childA, ['felder' => ['no_gos' => 'X'], 'verbindlich' => ['no_gos' => true]]);
    expect($kind->success)->toBeFalse()->and($kind->errorCode)->toBe('VALIDATION_ERROR');

    $member = $put($this->member, $this->rootTeam, ['felder' => ['no_gos' => 'X'], 'verbindlich' => ['no_gos' => true]]);
    expect($member->success)->toBeFalse()->and($member->errorCode)->toBe('FORBIDDEN');

    $unbekannt = $put($this->owner, $this->rootTeam, ['felder' => [], 'verbindlich' => ['gibt_es_nicht' => true]]);
    expect($unbekannt->success)->toBeFalse()->and($unbekannt->errorCode)->toBe('VALIDATION_ERROR');

    $fremderTyp = (new CanvasPutTool())->execute(['type' => 'foodbook', 'owner_id' => 1, 'felder' => [], 'verbindlich' => ['leitidee' => true]], ($this->ctx)($this->owner, $this->rootTeam));
    expect($fremderTyp->success)->toBeFalse();

    // ohne verbindlich bleibt das Schreiben für Mitglieder wie bisher möglich (nichts Neues gesperrt)
    expect($put($this->member, $this->rootTeam, ['felder' => ['leitbild' => 'Regional.']])->success)->toBeTrue();
    expect($this->svc->find('food_dna', 'team', $this->rootTeam->id)->entries->firstWhere('field_key', 'no_gos'))->toBeNull();
});

it('canvas.ENTRY_ADD: verbindlicher Eintrag an einem Zusatzfeld des Oberteams; im Unter-Team bleibt er in der Vereinigung', function () {
    $this->svc->zusatzfeldAnlegen($this->rootTeam, 'food_dna', 'leitaromen', 'Leit-Aromen', ['type' => 'repeatable', 'group_name' => 'Aromatik & Tabus']);

    $add = (new CanvasEntryAddTool())->execute(['type' => 'food_dna', 'key' => 'leitaromen', 'value' => 'Zitrus', 'verbindlich' => true], ($this->ctx)($this->owner, $this->rootTeam));
    expect($add->success)->toBeTrue()->and($add->data['verbindlich'])->toBeTrue();
    $kindAdd = (new CanvasEntryAddTool())->execute(['type' => 'food_dna', 'key' => 'leitaromen', 'value' => 'Salz'], ($this->ctx)($this->kind, $this->childA));
    expect($kindAdd->success)->toBeTrue();

    $zeile = app(\Platform\FoodAlchemist\Services\DnaResolver::class)->foodDna($this->childA)['leitaromen'];
    expect(array_column($zeile['wert'], 'value'))->toBe(['Zitrus', 'Salz'])->and($zeile['wert'][0]['verbindlich'])->toBeTrue();

    // Unter-Team darf den Eintrag nicht verbindlich machen
    $kindVerb = (new CanvasEntryAddTool())->execute(['type' => 'food_dna', 'key' => 'leitaromen', 'value' => 'X', 'verbindlich' => true], ($this->ctx)($this->kind, $this->childA));
    expect($kindVerb->success)->toBeFalse()->and($kindVerb->errorCode)->toBe('VALIDATION_ERROR');

    // canvas.GET kennt das Zusatzfeld und zeigt is_binding am Eintrag
    $get = (new CanvasGetTool())->execute(['type' => 'food_dna'], ($this->ctx)($this->owner, $this->rootTeam));
    expect(array_column($get->data['felder'], 'key'))->toContain('leitaromen')
        ->and($get->data['werte']['leitaromen'][0]['is_binding'])->toBeTrue();
});

it('kontext.RESOLVE: eigenes Team, mit Objekt, Quellenliste und Bilanz', function () {
    $this->svc->setSkalar($this->svc->canvasFor($this->rootTeam, 'food_dna', 'team', $this->rootTeam->id), 'leitbild', 'Regional.');
    $angebot = FoodAlchemistAngebot::create(['team_id' => $this->rootTeam->id, 'name' => 'A', 'status' => 'anfrage', 'personen' => 10]);
    $this->svc->setSkalar($this->svc->canvasFor($this->rootTeam, 'angebot', 'angebot', $angebot->id), 'budget', '50 €');

    $r = (new KontextResolveTool())->execute(['objekt_typ' => 'angebot', 'objekt_id' => $angebot->id, 'gaeste' => 80, 'termin' => '2026-06-20'], ($this->ctx)($this->owner, $this->rootTeam));

    expect($r->success)->toBeTrue()
        ->and($r->data['marken_kontext'])->toContain('Leitbild / Positionierung: Regional.')->and($r->data['marken_kontext'])->toContain('Budget-Rahmen: 50 €')
        ->and($r->data['marken_kontext'])->toContain('Anlass dieser Erzeugung: 80 Gäste, Termin 20.06.2026')
        ->and(array_column($r->data['quellen'], 'ebene'))->toBe(['team_dna', 'angebot', 'anlass'])
        ->and($r->data['bilanz']['budget'])->toBe(12000)
        ->and($r->data['team_id'])->toBe((int) $this->rootTeam->id);

    $nichtDa = (new KontextResolveTool())->execute(['objekt_typ' => 'angebot', 'objekt_id' => 99999], ($this->ctx)($this->owner, $this->rootTeam));
    expect($nichtDa->success)->toBeFalse()->and($nichtDa->errorCode)->toBe('NOT_FOUND');
    $ohneId = (new KontextResolveTool())->execute(['objekt_typ' => 'angebot'], ($this->ctx)($this->owner, $this->rootTeam));
    expect($ohneId->errorCode)->toBe('VALIDATION_ERROR');
});

it('kontext.RESOLVE §13 G: Oberteam liest sein Unter-Team, das Unter-Team nicht nach oben, fremde Mandanten nie', function () {
    $this->svc->setSkalar($this->svc->canvasFor($this->childA, 'food_dna', 'team', $this->childA->id), 'leitbild', 'KITA-MARKER');
    $fremd = \Platform\Core\Models\Team::create(['name' => 'Fremd', 'user_id' => 1, 'personal_team' => false]);
    $this->svc->setSkalar($this->svc->canvasFor($fremd, 'food_dna', 'team', $fremd->id), 'leitbild', 'FREMD-MARKER');
    $lies = fn ($user, $team, $ziel) => (new KontextResolveTool())->execute(['team_id' => $ziel->id], ($this->ctx)($user, $team));

    expect($lies($this->owner, $this->rootTeam, $this->childA)->data['marken_kontext'])->toContain('KITA-MARKER');   // Brille
    expect($lies($this->kind, $this->childA, $this->rootTeam)->success)->toBeFalse();                                  // nicht nach oben
    expect($lies($this->kind, $this->childA, $this->childB)->errorCode)->toBe('FORBIDDEN');                              // Geschwister
    $r = $lies($this->owner, $this->rootTeam, $fremd);
    expect($r->success)->toBeFalse()->and($r->errorCode)->toBe('FORBIDDEN');
    expect($lies($this->owner, $this->rootTeam, (object) ['id' => 987654])->errorCode ?? 'x')->toBe('FORBIDDEN');     // unbekannte Id = gleiche Antwort
});

it('kontext.RESOLVE §13 G: Master liest KURATIERTE Mandanten, bei AUTARKEN auch der Plattform-Admin nichts', function () {
    $master = \Platform\Core\Models\Team::create(['name' => 'BHG.DIGITAL', 'user_id' => 1, 'personal_team' => false]);
    $kurat = \Platform\Core\Models\Team::create(['name' => 'Kuratiert', 'user_id' => 1, 'personal_team' => false]);
    $autark = \Platform\Core\Models\Team::create(['name' => 'Autark', 'user_id' => 1, 'personal_team' => false]);
    config(['foodalchemist.master_team_id' => $master->id]);
    FoodAlchemistMandant::updateOrCreate(['team_id' => $kurat->id], ['modus' => 'kuratiert', 'status' => 'aktiv']);
    FoodAlchemistMandant::updateOrCreate(['team_id' => $autark->id], ['modus' => 'autark', 'status' => 'aktiv']);
    foreach ([$kurat, $autark] as $t) {
        $this->svc->setSkalar($this->svc->canvasFor($t, 'food_dna', 'team', $t->id), 'leitbild', 'GEHEIM-'.$t->name);
    }
    $masterUser = $this->makeUser($master, 'Kurator');
    $admin = $this->makeUser($master, 'Plattform-Admin');
    config(['platform-shell.admins' => [strtolower($admin->email)]]);
    $lies = fn ($user, $ziel) => (new KontextResolveTool())->execute(['team_id' => $ziel->id], ($this->ctx)($user, $master));

    expect($lies($masterUser, $kurat)->data['marken_kontext'])->toContain('GEHEIM-Kuratiert');
    foreach ([$masterUser, $admin] as $user) {
        $r = $lies($user, $autark);
        expect($r->success)->toBeFalse()->and($r->errorCode)->toBe('FORBIDDEN');
    }
});
