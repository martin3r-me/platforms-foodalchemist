<?php

use Illuminate\Http\Request;
use Illuminate\Routing\Route as LaravelRoute;
use Livewire\Livewire;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Models\Team;
use Platform\Core\Tools\ToolRegistry;
use Platform\FoodAlchemist\Exceptions\FaRechtFehltException;
use Platform\FoodAlchemist\Http\Middleware\FaBereichMiddleware;
use Platform\FoodAlchemist\Livewire\Settings\FoodDna;
use Platform\FoodAlchemist\Livewire\Verwaltung\MandantBeenden;
use Platform\FoodAlchemist\Models\FoodAlchemistMandant;
use Platform\FoodAlchemist\Services\AboAuskunft;
use Platform\FoodAlchemist\Services\CanvasService;
use Platform\FoodAlchemist\Services\ExportAuskunft;
use Platform\FoodAlchemist\Services\FaRechte;
use Platform\FoodAlchemist\Services\MandantBeendenService;
use Platform\FoodAlchemist\Services\PlattformProtokoll;
use Platform\FoodAlchemist\Services\StammPaketService;
use Platform\FoodAlchemist\Support\MasterTeam;
use Platform\FoodAlchemist\Support\TeamSperre;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;
use Platform\FoodAlchemist\Tools\MandantenBeendenPostTool;
use Platform\FoodAlchemist\Tools\TeamProfilGetTool;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 82 §13 C3 (PR 2): Schritte ausführen (Abos → Stamm-Paket → Export → Bereiche aus → sperren), Vollsperre durchsetzen
 * (Seiten, Livewire, MCP), Rückgängig. Nur Plattform-Admin; keine Inhalts-Ausnahme für ihn (§13 G).
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    config(['foodalchemist.master_team_id' => null]);
    MasterTeam::flush();
    TeamSperre::flush();
    $this->admin = $this->makeUser($this->rootTeam, 'Plattform Admin');
    config(['platform-shell.admins' => [strtolower($this->admin->email)]]);
    $this->mandant = Team::create(['name' => 'Soulkitchen', 'user_id' => 1, 'personal_team' => false]);
    $this->kind = Team::create(['name' => 'LAGO', 'user_id' => 1, 'personal_team' => false, 'parent_team_id' => $this->mandant->id]);
    $this->chef = $this->makeUser($this->mandant, 'Soul-Chef', 'owner');
    $this->kindChef = $this->makeUser($this->kind, 'Lago-Chef', 'owner');
    $this->rechte = app(FaRechte::class);

    $this->protokolliert = [];
    $this->protokoll = Mockery::mock(PlattformProtokoll::class);
    $this->protokoll->shouldReceive('schreibe')->andReturnUsing(function ($aktion, $daten) { $this->protokolliert[] = [$aktion, $daten['schritt'] ?? null]; });
    app()->instance(PlattformProtokoll::class, $this->protokoll);

    $this->exportDa = fn (bool $da) => app()->instance(ExportAuskunft::class, new class($da) extends ExportAuskunft {
        public function __construct(private bool $da) {}

        public function vorhanden(\Platform\Core\Models\Team $mandant): bool
        {
            return $this->da;
        }
    });
    ($this->exportDa)(true);
    $this->svc = function () {
        app()->forgetInstance(MandantBeendenService::class);

        return app(MandantBeendenService::class);
    };
    $this->aktiveBereiche = fn () => array_keys(array_filter($this->rechte->teamBereiche($this->mandant)));
});

it('Ausführen braucht die Bestätigung und den Plattform-Admin', function () {
    expect(fn () => ($this->svc)()->fuehreAus($this->mandant, $this->admin, false))->toThrow(\RuntimeException::class, 'Bestätigung');
    expect(fn () => ($this->svc)()->fuehreAus($this->mandant, $this->chef, true))->toThrow(FaRechtFehltException::class);
    expect(TeamSperre::istGesperrt($this->mandant))->toBeFalse();
});

it('Vollständiger Lauf: Stamm-Paket ab, Bereiche aus, Team gesperrt, Lauf abgeschlossen, Schritte in fester Reihenfolge protokolliert', function () {
    app(StammPaketService::class)->buchen($this->mandant, $this->admin);
    expect(($this->aktiveBereiche)())->not->toBe([]);

    $r = ($this->svc)()->fuehreAus($this->mandant, $this->admin, true);

    expect($r['abgeschlossen'])->toBeTrue()->and($r['angehalten_bei'])->toBeNull()->and($r['lauf']['status'])->toBe('abgeschlossen')
        ->and(app(StammPaketService::class)->status($this->mandant)['gebucht'])->toBeFalse()
        ->and(($this->aktiveBereiche)())->toBe([])
        ->and(FoodAlchemistMandant::where('team_id', $this->mandant->id)->value('status'))->toBe('beendet')
        ->and(TeamSperre::istGesperrt($this->mandant))->toBeTrue()->and(TeamSperre::istGesperrt($this->kind))->toBeTrue();
    expect($r['lauf']['fortschritt']['abos']['status'])->toBe('entfaellt')->and($r['lauf']['fortschritt']['sperren']['status'])->toBe('erledigt');
    $schritte = array_values(array_filter(array_map(fn ($p) => $p[0] === 'mandant_beenden_schritt' ? $p[1] : null, $this->protokolliert)));
    expect($schritte)->toBe(['abos', 'stamm_paket', 'export', 'bereiche', 'sperren']);
    expect(collect($this->protokolliert)->pluck(0))->toContain('mandant_beenden_gestartet')->toContain('mandant_beenden_abgeschlossen');
});

it('Ohne Export hält der Lauf VOR dem Sperren an; mit Export fortsetzbar, Erledigtes bleibt erledigt', function () {
    ($this->exportDa)(false);
    app(StammPaketService::class)->buchen($this->mandant, $this->admin);

    $r = ($this->svc)()->fuehreAus($this->mandant, $this->admin, true);

    expect($r['abgeschlossen'])->toBeFalse()->and($r['angehalten_bei'])->toBe('export')->and($r['grund'])->toContain('Datenexport')
        ->and($r['lauf']['status'])->toBe('offen')->and($r['lauf']['fortschritt']['stamm_paket']['status'])->toBe('erledigt')
        ->and($r['lauf']['fortschritt']['export']['status'])->toBe('wartet')
        ->and(TeamSperre::istGesperrt($this->mandant))->toBeFalse()               // NICHT gesperrt, solange kein Export da ist
        ->and(($this->aktiveBereiche)())->not->toBe([]);                          // Bereiche noch an

    ($this->exportDa)(true);
    $r2 = ($this->svc)()->fuehreAus($this->mandant, $this->admin, true);

    expect($r2['abgeschlossen'])->toBeTrue()->and($r2['lauf']['id'])->toBe($r['lauf']['id'])->and(TeamSperre::istGesperrt($this->mandant))->toBeTrue();
});

it('Export bewusst weglassen: Schritte ohne „export“ laufen durch', function () {
    ($this->exportDa)(false);

    $r = ($this->svc)()->fuehreAus($this->mandant, $this->admin, true, ['abos', 'stamm_paket', 'bereiche', 'sperren']);

    expect($r['abgeschlossen'])->toBeTrue()->and(array_keys($r['lauf']['fortschritt']))->not->toContain('export')->and(TeamSperre::istGesperrt($this->mandant))->toBeTrue();
});

it('Fehler in einem Schritt halten den Lauf an (offen), danach fortsetzbar', function () {
    $zustand = new \stdClass();
    $zustand->fehler = true;   // per Objekt, damit die zweite Runde den geänderten Wert sieht
    app()->instance(AboAuskunft::class, new class(fn () => $zustand->fehler) extends AboAuskunft {
        public function __construct(private \Closure $soll) {}

        public function verfuegbar(): bool
        {
            return true;
        }

        public function kuendigen(\Platform\Core\Models\Team $mandant, ?\Platform\Core\Models\User $user): array
        {
            if (($this->soll)()) {
                throw new \RuntimeException('Tiefe Kopie fehlgeschlagen.');
            }

            return [['id' => 1, 'sammlung' => 'Jus']];
        }
    });

    $r = ($this->svc)()->fuehreAus($this->mandant, $this->admin, true);
    expect($r['abgeschlossen'])->toBeFalse()->and($r['angehalten_bei'])->toBe('abos')->and($r['grund'])->toContain('Tiefe Kopie')
        ->and($r['lauf']['fortschritt']['abos']['status'])->toBe('fehler')->and(TeamSperre::istGesperrt($this->mandant))->toBeFalse();

    $zustand->fehler = false;
    $r2 = ($this->svc)()->fuehreAus($this->mandant, $this->admin, true);
    expect($r2['abgeschlossen'])->toBeTrue()->and($r2['lauf']['fortschritt']['abos']['status'])->toBe('erledigt')->and($r2['lauf']['fortschritt']['abos']['details']['gekuendigt'])->toBe(1);
});

it('Rückgängig: Status zurück auf aktiv, Zugriff wieder offen, Bereiche bleiben aus; nur wenn gesperrt, nur Admin', function () {
    expect(fn () => ($this->svc)()->entsperren($this->mandant, $this->admin))->toThrow(\RuntimeException::class, 'nicht gesperrt');
    ($this->svc)()->fuehreAus($this->mandant, $this->admin, true);
    expect(fn () => ($this->svc)()->entsperren($this->mandant, $this->chef))->toThrow(FaRechtFehltException::class);

    ($this->svc)()->entsperren($this->mandant, $this->admin);

    expect(TeamSperre::istGesperrt($this->mandant))->toBeFalse()->and(TeamSperre::istGesperrt($this->kind))->toBeFalse()
        ->and(FoodAlchemistMandant::where('team_id', $this->mandant->id)->value('status'))->toBe('aktiv')
        ->and(($this->aktiveBereiche)())->toBe([]);                              // Bereiche bleiben aus bis neu gebucht
    expect(collect($this->protokolliert)->pluck(0))->toContain('mandant_entsperrt');
});

it('Sperre: Unter-Teams erben, das Master-Team und fremde Teams nie', function () {
    ($this->svc)()->fuehreAus($this->mandant, $this->admin, true);
    $master = Team::create(['name' => 'BHG.DIGITAL', 'user_id' => 1, 'personal_team' => false]);
    config(['foodalchemist.master_team_id' => $master->id]);
    MasterTeam::flush();
    TeamSperre::flush();

    expect(TeamSperre::istGesperrt($this->kind))->toBeTrue()->and(TeamSperre::istGesperrt($master))->toBeFalse()
        ->and(TeamSperre::istGesperrt($this->rootTeam))->toBeFalse()->and(TeamSperre::istGesperrt(null))->toBeFalse();
});

it('★ Gesperrtes Team: JEDES FA-Tool ist FORBIDDEN, außer Plattform-Steuerung und Export — auch für den Plattform-Admin', function () {
    ($this->svc)()->fuehreAus($this->mandant, $this->admin, true);
    $this->admin->update(['current_team_id' => $this->mandant->id]);   // der Admin steht im gesperrten Team
    $reg = app(ToolRegistry::class);
    $ctx = fn ($user) => new ToolContext($user, $this->mandant);

    $nichtGesperrt = [];
    $geprueft = 0;
    foreach ($reg->all() as $tool) {
        $name = $tool->getName();
        if (! str_starts_with($name, 'foodalchemist.') || TeamSperre::toolErlaubt($name)) {
            continue;
        }
        foreach ([$this->chef, $this->admin] as $user) {
            $r = $tool->execute([], $ctx($user));
            $geprueft++;
            if ($r->success || $r->errorCode !== 'FORBIDDEN' || $r->error !== TeamSperre::MELDUNG) {
                $nichtGesperrt[] = $name.' ('.($r->errorCode ?? 'ok').')';
            }
        }
    }

    expect($geprueft)->toBeGreaterThan(200)->and($nichtGesperrt)->toBe([]);

    // Ausnahmen laufen weiter: Plattform-Steuerung (Wiederfreigabe!) für den Admin
    $r = $reg->get('foodalchemist.mandanten.GET')->execute(['team_id' => $this->mandant->id], $ctx($this->admin));
    expect($r->success)->toBeTrue()->and($r->data['mandant']['status'])->toBe('beendet');
    expect($reg->get('foodalchemist.mandanten_beenden.GET')->execute(['team_id' => $this->mandant->id], $ctx($this->admin))->success)->toBeTrue();
});

it('Gesperrtes Team: ein Nicht-Gesperrtes Team (Geschwister-Mandant) arbeitet unverändert', function () {
    ($this->svc)()->fuehreAus($this->mandant, $this->admin, true);
    $andere = Team::create(['name' => 'Andere Firma', 'user_id' => 1, 'personal_team' => false]);
    $user = $this->makeUser($andere, 'Andere-Chefin', 'owner');

    $r = app(ToolRegistry::class)->get('foodalchemist.recipes.LIST')->execute([], new ToolContext($user, $andere));

    expect($r->errorCode ?? null)->not->toBe('FORBIDDEN');
});

it('Gesperrtes Team: Seiten 403, Ausnahmen (Präsentation, Export) laufen, andere Teams nicht betroffen', function () {
    ($this->svc)()->fuehreAus($this->mandant, $this->admin, true);
    $mw = new FaBereichMiddleware();
    $anfrage = function (string $routeName, $user) {
        $route = (new LaravelRoute(['GET'], '/x', fn () => 'ok'))->name($routeName);
        $r = Request::create('/x');
        $r->setRouteResolver(fn () => $route);
        $r->setUserResolver(fn () => $user);

        return $r;
    };
    $durch = fn (string $name, $user) => $mw->handle($anfrage($name, $user), fn () => response('ok'))->getContent() === 'ok';

    expect(fn () => $mw->handle($anfrage('foodalchemist.recipes.index', $this->chef), fn () => response('ok')))
        ->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class, 'gesperrt');
    expect(fn () => $mw->handle($anfrage('foodalchemist.dashboard', $this->kindChef), fn () => response('ok')))
        ->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);                         // Unter-Team erbt
    expect(fn () => $mw->handle($anfrage('foodalchemist.einstellungen', $this->chef), fn () => response('ok')))
        ->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    expect($durch('foodalchemist.presentation.show', $this->chef))->toBeTrue()                            // öffentliche Präsentation
        ->and($durch('foodalchemist.mandant-export.download', $this->chef))->toBeTrue()                   // Export-Download
        ->and($durch('dashboard', $this->chef))->toBeTrue()                                                // Nicht-FA-Route
        ->and($durch('foodalchemist.recipes.index', $this->admin))->toBeTrue();                            // Admin steht im eigenen, nicht gesperrten Team
});

it('Gesperrtes Team: Livewire-Aktionen schreiben nichts (auch nicht für Admins)', function () {
    ($this->svc)()->fuehreAus($this->mandant, $this->admin, true);
    $this->actingAs($this->chef);

    Livewire::test(FoodDna::class)->set('canvasForm.leitbild', 'GESPERRT-VERSUCH')->call('canvasSpeichern')->assertDispatched('fa-saved');

    expect(app(CanvasService::class)->find('food_dna', 'team', $this->mandant->id)?->entries->firstWhere('field_key', 'leitbild'))->toBeNull();

    // Entsperren hebt die Sperre auf, die Bereiche bleiben aber AUS (Dominique 13.10.): erst nach erneutem Buchen geht es wieder
    ($this->svc)()->entsperren($this->mandant, $this->admin);
    expect(TeamSperre::istGesperrt($this->mandant))->toBeFalse();
    Livewire::test(FoodDna::class)->set('canvasForm.leitbild', 'BEREICH-NOCH-AUS')->call('canvasSpeichern')->assertDispatched('fa-saved');
    expect(app(CanvasService::class)->find('food_dna', 'team', $this->mandant->id)?->entries->firstWhere('field_key', 'leitbild'))->toBeNull();

    $this->rechte->setzeTeamBereich($this->mandant, $this->admin, 'einstellungen', true);   // neu gebucht
    Livewire::test(FoodDna::class)->set('canvasForm.leitbild', 'WIEDER-OFFEN')->call('canvasSpeichern');
    expect(app(CanvasService::class)->find('food_dna', 'team', $this->mandant->id)->entries->firstWhere('field_key', 'leitbild')->value)->toBe('WIEDER-OFFEN');
});

it('MCP mandanten_beenden.POST: ausführen mit Bestätigung, abbrechen, entsperren; Inhaber FORBIDDEN', function () {
    $ctx = fn ($user) => new ToolContext($user, $this->rootTeam);
    $tool = new MandantenBeendenPostTool();

    expect($tool->execute(['team_id' => $this->mandant->id, 'aktion' => 'ausfuehren', 'bestaetigt' => true], $ctx($this->chef))->errorCode)->toBe('FORBIDDEN');
    expect($tool->execute(['team_id' => $this->mandant->id, 'aktion' => 'ausfuehren'], $ctx($this->admin))->errorCode)->toBe('VALIDATION_ERROR');   // ohne Bestätigung
    expect($tool->execute(['team_id' => $this->mandant->id, 'aktion' => 'unbekannt'], $ctx($this->admin))->errorCode)->toBe('VALIDATION_ERROR');

    $r = $tool->execute(['team_id' => $this->mandant->id, 'aktion' => 'ausfuehren', 'bestaetigt' => true], $ctx($this->admin));
    expect($r->success)->toBeTrue()->and($r->data['abgeschlossen'])->toBeTrue()->and(TeamSperre::istGesperrt($this->mandant))->toBeTrue();

    $e = $tool->execute(['team_id' => $this->mandant->id, 'aktion' => 'entsperren'], $ctx($this->admin));
    expect($e->success)->toBeTrue()->and($e->data['mandant']['status'])->toBe('aktiv');
    expect($tool->execute(['team_id' => $this->mandant->id, 'aktion' => 'abbrechen'], $ctx($this->admin))->data['lauf'])->toBeNull();
});

it('Verwaltung: Vorschau, ohne Häkchen kein Beenden, mit Häkchen beendet; Entsperren; Nicht-Admin sieht nichts', function () {
    $this->actingAs($this->admin);

    $lw = Livewire::test(MandantBeenden::class)->assertSeeHtml('data-mandant-beenden-zeile="'.$this->mandant->id.'"')
        ->call('vorschau', $this->mandant->id)
        ->assertSeeHtml('data-mandant-beenden-schritt="export"')->assertSeeHtml('data-mandant-beenden-schritt="sperren"')->assertSee('Zählung');
    $lw->call('beenden', $this->mandant->id)->assertSeeHtml('data-mandant-beenden-fehler');
    expect(TeamSperre::istGesperrt($this->mandant))->toBeFalse();

    $lw->set('bestaetigt', true)->call('beenden', $this->mandant->id)->assertSee('ist beendet und gesperrt');
    expect(TeamSperre::istGesperrt($this->mandant))->toBeTrue();
    $lw->assertSeeHtml('data-mandant-entsperren')->call('entsperren', $this->mandant->id)->assertSee('wieder aktiv');
    expect(TeamSperre::istGesperrt($this->mandant))->toBeFalse();

    $this->actingAs($this->chef);
    Livewire::test(MandantBeenden::class)->assertSee('nur Plattform-Admins')->assertDontSeeHtml('data-mandant-beenden-tabelle');
});
