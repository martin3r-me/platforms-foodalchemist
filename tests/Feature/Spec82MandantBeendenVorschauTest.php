<?php

use Illuminate\Support\Facades\DB;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Exceptions\FaRechtFehltException;
use Platform\FoodAlchemist\Models\FoodAlchemistMandant;
use Platform\FoodAlchemist\Services\AboAuskunft;
use Platform\FoodAlchemist\Services\MandantBeendenService;
use Platform\FoodAlchemist\Services\PlattformProtokoll;
use Platform\FoodAlchemist\Services\StammPaketService;
use Platform\FoodAlchemist\Support\MasterTeam;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;
use Platform\FoodAlchemist\Tools\MandantenBeendenGetTool;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 82 §13 C3 (PR 1): „Mandant beenden“ — Vorschau und Lauf-Gerüst. Reihenfolge Abos → Stamm-Paket → Datenexport →
 * Bereiche aus → sperren; nur Plattform-Admin; nur Zählungen, nie Inhalte (§13 G).
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    config(['foodalchemist.master_team_id' => null]);
    MasterTeam::flush();
    $this->admin = $this->makeUser($this->rootTeam, 'Plattform Admin');
    config(['platform-shell.admins' => [strtolower($this->admin->email)]]);
    $this->inhaber = $this->makeUser($this->rootTeam, 'Inhaber', 'owner');
    $this->mandant = Team::create(['name' => 'Soulkitchen', 'user_id' => 1, 'personal_team' => false]);
    $this->makeUser($this->mandant, 'Soul-Chef', 'owner');
    $this->svc = fn () => app(MandantBeendenService::class);
    $this->protokoll = Mockery::mock(PlattformProtokoll::class)->shouldIgnoreMissing();
    app()->instance(PlattformProtokoll::class, $this->protokoll);
    $this->schritt = fn (array $v, string $key) => collect($v['schritte'])->firstWhere('schritt', $key);
});

it('Vorschau: fünf Schritte in der festen Reihenfolge (Export vor Bereiche aus und sperren)', function () {
    $v = ($this->svc)()->vorschau($this->mandant, $this->admin);

    expect(array_column($v['schritte'], 'schritt'))->toBe(['abos', 'stamm_paket', 'export', 'bereiche', 'sperren'])
        ->and(MandantBeendenService::SCHRITTE)->toBe(['abos', 'stamm_paket', 'export', 'bereiche', 'sperren'])
        ->and($v['team_id'])->toBe((int) $this->mandant->id)->and($v['status'])->toBe('aktiv')->and($v['modus'])->toBe('autark')
        ->and($v['bestaetigung_noetig'])->toBeTrue()->and($v['offener_lauf'])->toBeNull();
    foreach ($v['schritte'] as $s) {
        expect($s['titel'])->not->toBeEmpty()->and($s['wirkung'])->not->toBeEmpty()->and($s['zustand'])->toBeIn(['offen', 'erledigt', 'entfaellt']);
    }
});

it('Vorschau nur für Plattform-Admin: Inhaber, Master-Admin und KI-Benutzer bekommen FORBIDDEN', function () {
    expect(fn () => ($this->svc)()->vorschau($this->mandant, $this->inhaber))->toThrow(FaRechtFehltException::class);
    expect(fn () => ($this->svc)()->vorschau($this->mandant, null))->toThrow(FaRechtFehltException::class);
});

it('Master-Team und Unter-Teams sind kein Mandant, den man beendet', function () {
    config(['foodalchemist.master_team_id' => $this->mandant->id]);
    MasterTeam::flush();
    expect(fn () => ($this->svc)()->vorschau($this->mandant, $this->admin))->toThrow(\RuntimeException::class, 'Master');

    config(['foodalchemist.master_team_id' => null]);
    MasterTeam::flush();
    expect(fn () => ($this->svc)()->vorschau($this->childA, $this->admin))->toThrow(\RuntimeException::class, 'Haupt-Team');
});

it('Abos: ohne Abo erledigt (seit Durchstich Schritt 1 gibt es das Abo-Modell); mit Abos steht die Wirkung (tiefe Kopie) und die Zahl da', function () {
    $v = ($this->svc)()->vorschau($this->mandant, $this->admin);
    expect(($this->schritt)($v, 'abos')['zustand'])->toBe('erledigt')->and(($this->schritt)($v, 'abos')['details']['verfuegbar'])->toBeTrue();

    app()->instance(AboAuskunft::class, new class extends AboAuskunft {
        public function verfuegbar(): bool
        {
            return true;
        }

        public function abosDesMandanten(Team $mandant): array
        {
            return [['id' => 1, 'sammlung' => 'Jus & Fonds', 'master_team_id' => 9], ['id' => 2, 'sammlung' => 'Saucen', 'master_team_id' => 9]];
        }
    });
    app()->forgetInstance(MandantBeendenService::class);
    $s = ($this->schritt)(($this->svc)()->vorschau($this->mandant, $this->admin), 'abos');

    expect($s['zustand'])->toBe('offen')->and($s['details']['abos'])->toBe(2)->and($s['details']['sammlungen'])->toBe(['Jus & Fonds', 'Saucen'])
        ->and($s['wirkung'])->toContain('2 Abo(s)')->and($s['wirkung'])->toContain('tiefe Kopie');
});

it('Stamm-Paket: gebucht = offen, nicht gebucht = erledigt', function () {
    expect(($this->schritt)(($this->svc)()->vorschau($this->mandant, $this->admin), 'stamm_paket')['zustand'])->toBe('erledigt');

    app(StammPaketService::class)->buchen($this->mandant, $this->admin);
    $s = ($this->schritt)(($this->svc)()->vorschau($this->mandant, $this->admin), 'stamm_paket');
    expect($s['zustand'])->toBe('offen')->and($s['details']['gebucht'])->toBeTrue();
});

it('Export: nur ZÄHLUNGEN des Mandanten und seiner Unter-Teams, nie Inhalte, nie fremde oder Stamm-Zeilen', function () {
    $this->makeRecipe($this->mandant, 'Geheimes Rezept');
    $this->makeRecipe($this->mandant, 'Zweites Rezept');
    $this->makeRecipe($this->rootTeam, 'Fremdes Rezept');                      // anderer Mandant
    $this->makeGp($this->mandant, 'Eigener GP');
    $this->makeFoodbook($this->mandant, 'Gala');
    $kind = Team::create(['name' => 'LAGO', 'user_id' => 1, 'personal_team' => false, 'parent_team_id' => $this->mandant->id]);
    $this->makeRecipe($kind, 'Rezept im Unter-Team');                           // zählt zum Mandanten
    // Stamm-Zeile (team_id NULL) zählt nicht
    DB::table('foodalchemist_gps')->where('name', 'Eigener GP')->limit(0)->get();

    $v = ($this->svc)()->vorschau($this->mandant, $this->admin);
    $s = ($this->schritt)($v, 'export');

    expect($s['details']['manifest']['rezepte'])->toBe(3)->and($s['details']['manifest']['grundprodukte'])->toBe(1)
        ->and($s['details']['manifest']['foodbooks'])->toBe(1)->and($s['details']['zeilen_gesamt'])->toBeGreaterThanOrEqual(5)
        ->and($s['zustand'])->toBe('offen')->and($v['unter_teams'])->toBe(1);
    // §13 G: nirgends ein Name oder Inhalt in der Vorschau
    expect(json_encode($v))->not->toContain('Geheimes Rezept')->and(json_encode($v))->not->toContain('Eigener GP')->and(json_encode($v))->not->toContain('Gala');
});

it('Bereiche und Sperren: Zustand folgt dem Stand', function () {
    $v = ($this->svc)()->vorschau($this->mandant, $this->admin);
    expect(($this->schritt)($v, 'bereiche')['zustand'])->toBe('offen')->and(count(($this->schritt)($v, 'bereiche')['details']['aktiv']))->toBeGreaterThan(5)
        ->and(($this->schritt)($v, 'sperren')['zustand'])->toBe('offen')->and(($this->schritt)($v, 'sperren')['wirkung'])->toContain('NICHT gelöscht');

    foreach (array_keys(\Platform\FoodAlchemist\Support\FaBereiche::KATALOG) as $b) {
        app(\Platform\FoodAlchemist\Services\FaRechte::class)->setzeTeamBereich($this->mandant, $this->admin, $b, false);
    }
    FoodAlchemistMandant::updateOrCreate(['team_id' => $this->mandant->id], ['status' => 'beendet', 'modus' => 'autark']);
    $v = ($this->svc)()->vorschau($this->mandant, $this->admin);

    expect(($this->schritt)($v, 'bereiche')['zustand'])->toBe('erledigt')->and(($this->schritt)($v, 'sperren')['zustand'])->toBe('erledigt')
        ->and($v['status'])->toBe('beendet');
});

it('Lauf: starten legt einen offenen Lauf an (alle Schritte), ein zweiter Aufruf liefert denselben; abbrechen schließt ihn', function () {
    $lauf = ($this->svc)()->starte($this->mandant, $this->admin);

    expect($lauf->status)->toBe('offen')->and($lauf->schritte)->toBe(MandantBeendenService::SCHRITTE)->and($lauf->gestartet_von)->toBe($this->admin->id);
    expect(($this->svc)()->starte($this->mandant, $this->admin)->id)->toBe($lauf->id);
    expect(($this->svc)()->vorschau($this->mandant, $this->admin)['offener_lauf']['id'])->toBe($lauf->id);

    ($this->svc)()->abbrechen($this->mandant, $this->admin);
    expect($lauf->fresh()->status)->toBe('abgebrochen')->and($lauf->fresh()->abgeschlossen_at)->not->toBeNull()
        ->and(($this->svc)()->vorschau($this->mandant, $this->admin)['offener_lauf'])->toBeNull()
        ->and(($this->svc)()->abbrechen($this->mandant, $this->admin))->toBeNull();
});

it('Lauf: Teilmenge der Schritte bleibt in der festen Reihenfolge, Unbekanntes wird abgewiesen, nur Plattform-Admin', function () {
    $lauf = ($this->svc)()->starte($this->mandant, $this->admin, ['sperren', 'export']);
    expect($lauf->schritte)->toBe(['export', 'sperren']);
    ($this->svc)()->abbrechen($this->mandant, $this->admin);

    expect(fn () => ($this->svc)()->starte($this->mandant, $this->admin, ['loeschen']))->toThrow(\RuntimeException::class, 'Schritte');
    expect(fn () => ($this->svc)()->starte($this->mandant, $this->inhaber))->toThrow(FaRechtFehltException::class);
});

it('Lauf: Start und Abbruch gehen ins Plattform-Protokoll', function () {
    $this->protokoll->shouldReceive('schreibe')->once()->with('mandant_beenden_gestartet', Mockery::on(fn ($d) => $d['team_id'] === (int) $this->mandant->id), Mockery::any(), Mockery::any());
    $this->protokoll->shouldReceive('schreibe')->once()->with('mandant_beenden_abgebrochen', Mockery::any(), Mockery::any(), Mockery::any());

    ($this->svc)()->starte($this->mandant, $this->admin);
    ($this->svc)()->abbrechen($this->mandant, $this->admin);
});

it('MCP mandanten_beenden.GET: Plattform-Admin bekommt die Vorschau, alle anderen FORBIDDEN', function () {
    $ctx = fn ($user) => new ToolContext($user, $this->rootTeam);

    $r = (new MandantenBeendenGetTool())->execute(['team_id' => $this->mandant->id], $ctx($this->admin));
    expect($r->success)->toBeTrue()->and(array_column($r->data['schritte'], 'schritt'))->toBe(['abos', 'stamm_paket', 'export', 'bereiche', 'sperren']);

    $nein = (new MandantenBeendenGetTool())->execute(['team_id' => $this->mandant->id], $ctx($this->inhaber));
    expect($nein->success)->toBeFalse()->and($nein->errorCode)->toBe('FORBIDDEN');
    $unbekannt = (new MandantenBeendenGetTool())->execute(['team_id' => 987654], $ctx($this->admin));
    expect($unbekannt->success)->toBeFalse()->and($unbekannt->errorCode)->toBe('NOT_FOUND');
});
