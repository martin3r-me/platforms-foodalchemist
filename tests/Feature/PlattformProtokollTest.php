<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistMandant;
use Platform\FoodAlchemist\Models\FoodAlchemistPlattformEinstellung;
use Platform\FoodAlchemist\Models\FoodAlchemistTeamStammOptin;
use Platform\FoodAlchemist\Services\MandantenService;
use Platform\FoodAlchemist\Services\MasterTeamService;
use Platform\FoodAlchemist\Services\StammPaketService;
use Platform\FoodAlchemist\Support\MasterTeam;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 82 §13 C4: Plattform-Admin-Aktionen landen WIRKLICH im activity-log.
 *
 * Bewusst OHNE Mock von PlattformProtokoll: Der frühere Stand rief `activity()`, die es im echten Paket nicht gibt, und schrieb in
 * Produktion nichts, was ein gemockter Test nie sehen konnte. Hier läuft das echte Trait (`logActivity`) gegen die echte Tabelle
 * `activity_log_activities`. In einer Sandbox mit dem Stub-Paket (ohne logActivity) werden die Tests übersprungen.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    config(['foodalchemist.master_team_id' => null]);
    MasterTeam::flush();

    if (! method_exists(FoodAlchemistTeamStammOptin::class, 'logActivity')) {
        $this->markTestSkipped('Echtes platform-activity-log nicht installiert (Sandbox-Stub ohne logActivity).');
    }
    if (! Schema::hasTable('activity_log_activities')) {
        $this->artisan('migrate', ['--realpath' => true, '--path' => [base_path('vendor/martin3r/platform-activity-log/database/migrations')]]);
    }

    $this->admin = $this->makeUser($this->rootTeam, 'Plattform Admin');
    config(['platform-shell.admins' => [strtolower($this->admin->email)]]);
    $this->soul = Team::create(['name' => 'Soulkitchen', 'user_id' => 1, 'personal_team' => false]);
    $this->master = Team::create(['name' => 'BHG.DIGITAL', 'user_id' => 1, 'personal_team' => false]);
    $this->zeilen = fn (string $modell) => DB::table('activity_log_activities')->where('activityable_type', $modell)->orderBy('id')->get();
});

it('Die drei Plattform-Modelle tragen LogsActivity', function () {
    foreach ([FoodAlchemistTeamStammOptin::class, FoodAlchemistPlattformEinstellung::class, FoodAlchemistMandant::class] as $klasse) {
        expect(class_uses_recursive($klasse))->toContain(\Platform\ActivityLog\Traits\LogsActivity::class);
    }
});

it('Stamm-Paket buchen und abbestellen schreibt je einen Eintrag mit Klartext und Akteur, auch für die gelöschte Zeile', function () {
    $svc = app(StammPaketService::class);

    $svc->buchen($this->soul, $this->admin);
    $optin = FoodAlchemistTeamStammOptin::where('team_id', $this->soul->id)->first();
    $gebucht = ($this->zeilen)(FoodAlchemistTeamStammOptin::class)->firstWhere('message', 'Stamm-Paket gebucht');
    expect($gebucht)->not->toBeNull()->and((int) $gebucht->activityable_id)->toBe($optin->id)->and($gebucht->activity_type)->toBe('manual');
    $meta = json_decode($gebucht->metadata, true);
    expect($meta)->toMatchArray(['aktion' => 'stamm_paket_gebucht', 'team_id' => $this->soul->id, 'akteur_id' => $this->admin->id]);

    $svc->buchen($this->soul, $this->admin);                    // schon gebucht: kein zweiter Klartext-Eintrag
    expect(($this->zeilen)(FoodAlchemistTeamStammOptin::class)->where('message', 'Stamm-Paket gebucht'))->toHaveCount(1);

    $svc->abbestellen($this->soul, $this->admin);
    $weg = ($this->zeilen)(FoodAlchemistTeamStammOptin::class)->firstWhere('message', 'Stamm-Paket abbestellt');
    expect($weg)->not->toBeNull()->and((int) $weg->activityable_id)->toBe($optin->id)
        ->and(json_decode($weg->metadata, true))->toMatchArray(['aktion' => 'stamm_paket_abbestellt', 'abos_betroffen' => 0]);
    expect(FoodAlchemistTeamStammOptin::where('team_id', $this->soul->id)->exists())->toBeFalse();   // die Zeile ist wirklich weg
});

it('Master-Team setzen, wechseln und entfernen schreibt je einen Eintrag (auch beim Entfernen)', function () {
    $svc = app(MasterTeamService::class);

    $svc->setze($this->master, $this->admin);
    $svc->setze($this->soul, $this->admin);
    $svc->setze(null, $this->admin);                            // Eintrag entfernen → ENV

    $eintraege = ($this->zeilen)(FoodAlchemistPlattformEinstellung::class)->where('message', 'Master-Team festgelegt');
    expect($eintraege)->toHaveCount(3);
    $metas = $eintraege->map(fn ($e) => json_decode($e->metadata, true))->values();
    expect($metas[0]['nach'])->toBe($this->master->id)->and($metas[1]['von'])->toBe($this->master->id)->and($metas[1]['nach'])->toBe($this->soul->id)
        ->and($metas[2]['nach'])->toBeNull()->and($metas[2]['akteur_id'])->toBe($this->admin->id);
    expect(FoodAlchemistPlattformEinstellung::count())->toBe(0);
});

it('Mandanten-Modus und Pflege werden protokolliert; der Freitext der Notiz steht in keinem Eintrag', function () {
    app(MasterTeamService::class)->setze($this->master, $this->admin);
    app(MandantenService::class)->setze($this->soul, ['status' => 'projekt', 'modus' => 'kuratiert', 'notiz' => 'GEHEIM-FREITEXT'], $this->admin);

    $zeilen = ($this->zeilen)(FoodAlchemistMandant::class);
    expect($zeilen->pluck('message'))->toContain('Mandanten-Modus geändert')->toContain('Mandant gepflegt');
    expect(json_encode($zeilen->all()))->not->toContain('GEHEIM-FREITEXT');
});

it('Auch die automatischen created/updated/deleted-Einträge des Traits landen am Modell (Notiz bleibt draußen)', function () {
    config(['activity-log.events' => ['created', 'updated', 'deleted']]);
    // Das Trait liest die Ereignisse beim ersten Booten der Klasse. In diesem Prozess kann sie schon gebootet sein, dann prüft der Test nur den Klartext.
    $zeile = FoodAlchemistPlattformEinstellung::create(['schluessel' => 'probe', 'wert' => '1']);
    $zeile->logActivity('Probe', ['x' => 1]);

    expect(($this->zeilen)(FoodAlchemistPlattformEinstellung::class)->where('activityable_id', $zeile->id)->pluck('message'))->toContain('Probe');
});
