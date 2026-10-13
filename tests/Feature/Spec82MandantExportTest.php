<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Exceptions\FaRechtFehltException;
use Platform\FoodAlchemist\Http\Controllers\MandantExportDownloadController;
use Platform\FoodAlchemist\Jobs\MandantExportJob;
use Platform\FoodAlchemist\Models\FoodAlchemistMandant;
use Platform\FoodAlchemist\Models\FoodAlchemistMandantExport;
use Platform\FoodAlchemist\Services\CanvasService;
use Platform\FoodAlchemist\Services\MandantBeendenService;
use Platform\FoodAlchemist\Services\MandantExportService;
use Platform\FoodAlchemist\Services\PlattformProtokoll;
use Platform\FoodAlchemist\Support\MasterTeam;
use Platform\FoodAlchemist\Support\TeamSperre;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;
use Platform\FoodAlchemist\Tools\FaRechteToolHuelle;
use Platform\FoodAlchemist\Tools\MandantExportGetTool;
use Platform\FoodAlchemist\Tools\MandantExportPostTool;
use Platform\FoodAlchemist\Tools\TeamProfilGetTool;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 82 §13 C3 (PR 3): Datenexport je Mandant (ZIP). Inhalt nur der eigene des Mandanten + Unter-Teams; auslösen
 * Plattform-Admin oder Mandanten-Inhaber, LADEN nur wer wirklich Inhaber/Admin im Mandanten ist (Master bei kuratierten);
 * der Plattform-Admin sieht nie Inhalte (§13 G); auch im gesperrten Team erreichbar.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    config(['foodalchemist.master_team_id' => null]);
    MasterTeam::flush();
    TeamSperre::flush();
    Storage::fake('local');
    $this->admin = $this->makeUser($this->rootTeam, 'Plattform Admin');
    config(['platform-shell.admins' => [strtolower($this->admin->email)]]);
    $this->mandant = Team::create(['name' => 'Soulkitchen', 'user_id' => 1, 'personal_team' => false]);
    $this->kind = Team::create(['name' => 'LAGO', 'user_id' => 1, 'personal_team' => false, 'parent_team_id' => $this->mandant->id]);
    $this->fremd = Team::create(['name' => 'Andere Firma', 'user_id' => 1, 'personal_team' => false]);
    $this->chef = $this->makeUser($this->mandant, 'Soul-Chef', 'owner');
    $this->koch = $this->makeUser($this->mandant, 'Soul-Koch', 'member');
    $this->kindChef = $this->makeUser($this->kind, 'Lago-Chef', 'owner');
    $this->svc = fn () => app(MandantExportService::class);
    $this->protokoll = Mockery::mock(PlattformProtokoll::class)->shouldIgnoreMissing();
    app()->instance(PlattformProtokoll::class, $this->protokoll);

    $this->daten = function () {
        $this->makeRecipe($this->mandant, 'Soul-Rezept A');
        $this->makeRecipe($this->mandant, 'Soul-Rezept B');
        $this->makeRecipe($this->kind, 'Lago-Rezept');
        $this->makeRecipe($this->fremd, 'FREMD-GEHEIM');
        $geloescht = $this->makeRecipe($this->mandant, 'Soul-Geloescht');
        $geloescht->delete();
        $this->makeFoodbook($this->mandant, 'Soul-Gala');
        $canvas = app(CanvasService::class)->canvasFor($this->mandant, 'food_dna', 'team', $this->mandant->id);
        app(CanvasService::class)->setSkalar($canvas, 'leitbild', 'SOUL-LEITBILD');
    };
    $this->zip = function (FoodAlchemistMandantExport $e) {
        $tmp = tempnam(sys_get_temp_dir(), 'tz');
        file_put_contents($tmp, Storage::disk('local')->get($e->pfad));
        $z = new ZipArchive();
        $z->open($tmp);

        return $z;
    };
});

it('Rechte: auslösen Plattform-Admin oder Mandanten-Inhaber; laden NUR wer wirklich Inhaber im Mandanten ist', function () {
    $s = ($this->svc)();

    expect($s->darfAusloesen($this->admin, $this->mandant))->toBeTrue()->and($s->darfAusloesen($this->chef, $this->mandant))->toBeTrue()
        ->and($s->darfAusloesen($this->koch, $this->mandant))->toBeFalse()->and($s->darfAusloesen($this->kindChef, $this->mandant))->toBeFalse()
        ->and($s->darfAusloesen(null, $this->mandant))->toBeFalse();
    // §13 G: der Plattform-Admin lädt NIE (keine Mitgliedschaft im Mandanten), der Inhaber schon
    expect($s->darfLaden($this->admin, $this->mandant))->toBeFalse()->and($s->darfLaden($this->chef, $this->mandant))->toBeTrue()
        ->and($s->darfLaden($this->koch, $this->mandant))->toBeFalse()->and($s->darfLaden($this->kindChef, $this->mandant))->toBeFalse();
});

it('Master lädt nur bei KURATIERTEN Mandanten', function () {
    $master = Team::create(['name' => 'BHG.DIGITAL', 'user_id' => 1, 'personal_team' => false]);
    config(['foodalchemist.master_team_id' => $master->id]);
    MasterTeam::flush();
    $kurator = $this->makeUser($master, 'Kurator', 'admin');
    $s = ($this->svc)();

    expect($s->darfLaden($kurator, $this->mandant))->toBeFalse();                        // autark (Standard)
    FoodAlchemistMandant::updateOrCreate(['team_id' => $this->mandant->id], ['modus' => 'kuratiert', 'status' => 'aktiv']);
    expect($s->darfLaden($kurator, $this->mandant))->toBeTrue()
        ->and($s->darfLaden($this->makeUser($master, 'Master-Mitglied', 'member'), $this->mandant))->toBeFalse();
});

it('Export: ZIP mit eigenen Inhalten des Mandanten und seiner Unter-Teams; nie Fremdes, Gelöschtes oder Verwaltung', function () {
    ($this->daten)();

    $e = ($this->svc)()->starte($this->mandant, $this->admin);   // Queue ist sync → fertig

    $e->refresh();
    expect($e->status)->toBe('fertig')->and($e->pfad)->toContain('foodalchemist/mandant-exporte/'.$this->mandant->id.'/')
        ->and($e->groesse)->toBeGreaterThan(100)->and($e->pruefsumme)->toBe(hash('sha256', Storage::disk('local')->get($e->pfad)))
        ->and($e->manifest['foodalchemist_recipes'])->toBe(3)->and($e->manifest['foodalchemist_foodbooks'])->toBe(1);
    $z = ($this->zip)($e);
    $manifest = json_decode($z->getFromName('manifest.json'), true);
    $rezepte = $z->getFromName('daten/foodalchemist_recipes.jsonl');

    expect($manifest['mandant']['name'])->toBe('Soulkitchen')->and($manifest['teams'])->toHaveCount(2)->and($manifest['tabellen']['foodalchemist_recipes'])->toBe(3)
        ->and($manifest['zeilen_gesamt'])->toBe(array_sum($manifest['tabellen']))->and($z->getFromName('LIESMICH.txt'))->toContain('Nicht enthalten');
    expect($rezepte)->toContain('Soul-Rezept A')->toContain('Lago-Rezept')->not->toContain('FREMD-GEHEIM')->not->toContain('Soul-Geloescht');
    // Kind-Tabelle ohne team_id (canvas_entries) kommt über den Canvas des Mandanten mit
    expect($z->getFromName('daten/foodalchemist_canvas_entries.jsonl'))->toContain('SOUL-LEITBILD');
    // Verwaltungs-/Technik-Tabellen sind nie dabei
    foreach (['foodalchemist_mandanten', 'foodalchemist_mandant_exporte', 'foodalchemist_mandant_beenden_laeufe', 'foodalchemist_team_bereiche'] as $t) {
        expect($z->getFromName('daten/'.$t.'.jsonl'))->toBeFalse();
    }
    // Prüfsummen im Manifest stimmen mit den Dateien überein
    foreach ($manifest['pruefsummen_sha256'] as $datei => $summe) {
        expect(hash('sha256', $z->getFromName('daten/'.$datei)))->toBe($summe);
    }
});

it('Auslösen: ein laufender Export wird wiederverwendet; ohne Recht FORBIDDEN; Unter-Team ist kein Mandant', function () {
    Bus::fake();
    $e1 = ($this->svc)()->starte($this->mandant, $this->chef);
    $e2 = ($this->svc)()->starte($this->mandant, $this->admin);

    expect($e1->status)->toBe('laeuft')->and($e2->id)->toBe($e1->id);
    Bus::assertDispatchedTimes(MandantExportJob::class, 1);
    expect(fn () => ($this->svc)()->starte($this->mandant, $this->koch))->toThrow(FaRechtFehltException::class);
    expect(fn () => ($this->svc)()->starte($this->kind, $this->admin))->toThrow(\RuntimeException::class, 'Haupt-Team');
});

it('Job-Fehler setzt den Export auf „fehler“', function () {
    $e = FoodAlchemistMandantExport::create(['team_id' => $this->mandant->id, 'status' => 'laeuft', 'ausgeloest_von' => $this->admin->id]);

    (new MandantExportJob($e))->failed(new \RuntimeException('Platte voll'));

    expect($e->fresh()->status)->toBe('fehler')->and($e->fresh()->fehler)->toBe('Platte voll');
});

it('Download: Inhaber lädt (und der Zeitpunkt wird gemerkt), Plattform-Admin 403, Mitglied 403, Unbekannt 404, nicht fertig 404', function () {
    ($this->daten)();
    $e = ($this->svc)()->starte($this->mandant, $this->admin);
    $laden = function ($user, int $id) {
        $req = Request::create('/x');
        $req->setUserResolver(fn () => $user);

        return app(MandantExportDownloadController::class)($req, $id, app(MandantExportService::class));
    };

    $r = $laden($this->chef, $e->id);
    expect($r->headers->get('Content-Disposition'))->toContain('foodalchemist-export-'.$this->mandant->id)->and($r->headers->get('Cache-Control'))->toContain('no-store')
        ->and($e->fresh()->zuletzt_geladen_at)->not->toBeNull()->and($e->fresh()->zuletzt_geladen_von)->toBe($this->chef->id);

    foreach ([$this->admin, $this->koch, $this->kindChef] as $user) {
        expect(fn () => $laden($user, $e->id))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    }
    try {
        $laden($this->admin, $e->id);
    } catch (\Symfony\Component\HttpKernel\Exception\HttpException $ex) {
        expect($ex->getStatusCode())->toBe(403);
    }
    expect(fn () => $laden($this->chef, 987654))->toThrow(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
    $e->update(['status' => 'laeuft']);
    try {
        $laden($this->chef, $e->id);
    } catch (\Symfony\Component\HttpKernel\Exception\HttpException $ex) {
        expect($ex->getStatusCode())->toBe(404);
    }
});

it('MCP: Plattform-Admin sieht Status und Zählung, aber weder `ladbar` noch Download; Inhaber bekommt den Download-Pfad', function () {
    ($this->daten)();
    $ctx = fn ($user, $team) => new ToolContext($user, $team);

    $post = (new MandantExportPostTool())->execute(['team_id' => $this->mandant->id], $ctx($this->admin, $this->rootTeam));
    expect($post->success)->toBeTrue()->and($post->data['export']['status'])->toBe('fertig')->and($post->data['export']['manifest']['foodalchemist_recipes'])->toBe(3);

    $admin = (new MandantExportGetTool())->execute(['team_id' => $this->mandant->id], $ctx($this->admin, $this->rootTeam));
    expect($admin->success)->toBeTrue()->and($admin->data['export']['ladbar'])->toBeFalse()->and($admin->data['export'])->not->toHaveKey('download')
        ->and(json_encode($admin->data))->not->toContain('Soul-Rezept A');

    $chef = (new MandantExportGetTool())->execute([], $ctx($this->chef, $this->mandant));
    expect($chef->data['export']['ladbar'])->toBeTrue()->and($chef->data['export']['download'])->toContain('mandant-export/');

    expect((new MandantExportGetTool())->execute(['team_id' => $this->mandant->id], $ctx($this->koch, $this->mandant))->errorCode)->toBe('FORBIDDEN');
    expect((new MandantExportPostTool())->execute(['team_id' => $this->mandant->id], $ctx($this->koch, $this->mandant))->errorCode)->toBe('FORBIDDEN');
});

it('Ablauf „Mandant beenden“: ohne Export hält er an, mit Export (echte Auskunft) läuft er durch und sperrt', function () {
    ($this->daten)();
    $beenden = fn () => app()->make(MandantBeendenService::class);
    app()->forgetInstance(MandantBeendenService::class);

    $r = $beenden()->fuehreAus($this->mandant, $this->admin, true);
    expect($r['angehalten_bei'])->toBe('export')->and(TeamSperre::istGesperrt($this->mandant))->toBeFalse();

    ($this->svc)()->starte($this->mandant, $this->admin);
    app()->forgetInstance(MandantBeendenService::class);
    $r2 = $beenden()->fuehreAus($this->mandant, $this->admin, true);

    expect($r2['abgeschlossen'])->toBeTrue()->and(TeamSperre::istGesperrt($this->mandant))->toBeTrue();
});

it('Im GESPERRTEN Team bleiben Export-Download und Export-Tools erreichbar, alles andere FORBIDDEN', function () {
    ($this->daten)();
    ($this->svc)()->starte($this->mandant, $this->admin);
    app()->forgetInstance(MandantBeendenService::class);
    app(MandantBeendenService::class)->fuehreAus($this->mandant, $this->admin, true);
    expect(TeamSperre::istGesperrt($this->mandant))->toBeTrue();
    $ctx = new ToolContext($this->chef, $this->mandant);

    $get = (new FaRechteToolHuelle(new MandantExportGetTool()))->execute([], $ctx);
    expect($get->success)->toBeTrue()->and($get->data['export']['ladbar'])->toBeTrue();
    $andere = (new FaRechteToolHuelle(new TeamProfilGetTool()))->execute([], $ctx);
    expect($andere->success)->toBeFalse()->and($andere->errorCode)->toBe('FORBIDDEN');

    // Route: Ausnahme der Sperre (Name foodalchemist.mandant-export.*)
    expect(TeamSperre::routeErlaubt('foodalchemist.mandant-export.download'))->toBeTrue()->and(TeamSperre::routeErlaubt('foodalchemist.recipes.index'))->toBeFalse();
    // und der Download selbst läuft für den Inhaber
    $e = FoodAlchemistMandantExport::where('team_id', $this->mandant->id)->latest('id')->first();
    $req = Request::create('/x');
    $req->setUserResolver(fn () => $this->chef);
    expect(app(MandantExportDownloadController::class)($req, $e->id, app(MandantExportService::class))->getStatusCode())->toBe(200);
});
