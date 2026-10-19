<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Enums\SignalSeverity;
use Platform\FoodAlchemist\Enums\SignalTyp;
use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Models\FoodAlchemistOutlet;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Services\AboKuendigungService;
use Platform\FoodAlchemist\Services\AboService;
use Platform\FoodAlchemist\Services\FassungService;
use Platform\FoodAlchemist\Services\InhaltsFreigabeService;
use Platform\FoodAlchemist\Services\RecipeRecomputeService;
use Platform\FoodAlchemist\Services\SignalService;
use Platform\FoodAlchemist\Services\StandortService;
use Platform\FoodAlchemist\Support\FaTeam;
use Platform\FoodAlchemist\Support\FremdSchreiben;
use Platform\FoodAlchemist\Support\StammPaket;
use Platform\FoodAlchemist\Support\TeamAncestryRegistry;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 84 B4 · Schreiben auf fremde Zeilen — Protokoll-Modus. Nie werfen (auch nicht unter „werfen“ in diesem Ausbau), „ohne Kontext“
 * eigene Kategorie, bewusste Fremdschreiber über FremdSchreiben::erlaubt('grund') ohne Zeile, Mengen-Begrenzung je Prozess.
 */
beforeEach(function () {
    config(['foodalchemist.fremd_schreiben.modus' => 'aus']);   // Aufbau ohne Protokoll, auch wenn die Umgebung „log“ setzt
    $this->seedTeamHierarchy();
    FremdSchreiben::zuruecksetzen();
    $this->logDatei = storage_path('logs/fa-fremdschreiben-test-'.getmypid().'.log');
    @unlink($this->logDatei);
    config(['logging.channels.'.FremdSchreiben::KANAL => ['driver' => 'single', 'path' => $this->logDatei, 'level' => 'debug',
        'formatter' => \Monolog\Formatter\JsonFormatter::class]]);
    Log::forgetChannel(FremdSchreiben::KANAL);

    $this->master = Team::create(['name' => 'Master', 'user_id' => 1, 'personal_team' => false]);
    config(['foodalchemist.master_team_id' => $this->master->id]);
    $this->a = Team::create(['name' => 'Mandant A', 'user_id' => 1, 'personal_team' => false]);
    $this->b = Team::create(['name' => 'Mandant B', 'user_id' => 1, 'personal_team' => false]);
    TeamAncestryRegistry::flushAll();
    $this->rezeptA = $this->makeRecipe($this->a, 'Rezept von A');
    config(['foodalchemist.fremd_schreiben.modus' => 'log']);   // erst nach dem Aufbau (der schreibt ohne Kontext)

    $this->zeilen = function (): array {
        Log::forgetChannel(FremdSchreiben::KANAL);   // Puffer schreiben
        if (! is_file($this->logDatei)) {
            return [];
        }

        return array_values(array_filter(array_map(fn ($l) => json_decode($l, true)['context'] ?? null, file($this->logDatei))));
    };
});

afterEach(fn () => @unlink($this->logDatei));

it('fremdes Schreiben wird protokolliert, nicht geworfen — das Speichern gelingt', function () {
    FaTeam::imTeam($this->b, fn () => $this->rezeptA->update(['name' => 'von B geändert']));
    expect($this->rezeptA->fresh()->name)->toBe('von B geändert');
    $z = ($this->zeilen)();
    expect($z)->toHaveCount(1)->and($z[0])->toMatchArray(['art' => 'fremd', 'modell' => 'FoodAlchemistRecipe', 'vorgang' => 'speichern',
        'zeilen_team' => $this->a->id, 'kontext_team' => $this->b->id]);
    expect($z[0]['aufrufer'])->toBeNull();   // Aufruf kam aus dem Test, nicht aus src/
});

it('eigenes Schreiben und Löschen erzeugt keine Zeile', function () {
    FaTeam::imTeam($this->a, function () {
        $this->rezeptA->update(['name' => 'eigen']);
        $this->rezeptA->delete();
    });
    expect(($this->zeilen)())->toBe([]);
});

it('Löschen einer fremden Zeile wird protokolliert', function () {
    FaTeam::imTeam($this->b, fn () => $this->rezeptA->delete());
    expect(($this->zeilen)()[0] ?? [])->toMatchArray(['art' => 'fremd', 'vorgang' => 'loeschen']);
});

it('Team-Wechsel einer Zeile zählt auch den alten Besitzer (Wegnehmen aus fremdem Team)', function () {
    FaTeam::imTeam($this->b, fn () => $this->rezeptA->update(['team_id' => $this->b->id]));   // neues Team = Kontext, altes = A
    expect(($this->zeilen)()[0] ?? [])->toMatchArray(['art' => 'fremd', 'zeilen_team' => $this->a->id]);
});

it('Master pflegt globale Stamm-Paket-Zeilen ohne Zeile; globale Basis-Zeilen sind für ihn fremd (wie isOwnedBy)', function () {
    FaTeam::imTeam($this->master, function () {
        FoodAlchemistGp::create(['team_id' => null, 'gp_key' => 'b4|paket', 'name' => 'Paket-GP', 'stamm_class' => StammPaket::PAKET]);
    });
    expect(($this->zeilen)())->toBe([]);
    FaTeam::imTeam($this->master, fn () => FoodAlchemistGp::create(['team_id' => null, 'gp_key' => 'b4|basis', 'name' => 'Basis-GP']));
    expect(($this->zeilen)()[0] ?? [])->toMatchArray(['art' => 'fremd', 'modell' => 'FoodAlchemistGp', 'zeilen_team' => null]);
});

it('ohne Team-Kontext: eigene Kategorie, auch unter „werfen“ nie geworfen', function () {
    config(['foodalchemist.fremd_schreiben.modus' => 'werfen']);
    FaTeam::imTeam(null, fn () => $this->rezeptA->update(['name' => 'Konsole']));
    expect($this->rezeptA->fresh()->name)->toBe('Konsole');
    expect(($this->zeilen)()[0] ?? [])->toMatchArray(['art' => 'ohne_kontext', 'modus' => 'werfen']);
});

it('„werfen“ verhält sich in diesem Ausbau wie „log“ (nicht erreichbar ohne Code-Änderung)', function () {
    config(['foodalchemist.fremd_schreiben.modus' => 'werfen']);
    FaTeam::imTeam($this->b, fn () => $this->rezeptA->update(['name' => 'trotzdem gespeichert']));
    expect($this->rezeptA->fresh()->name)->toBe('trotzdem gespeichert')->and(($this->zeilen)())->toHaveCount(1);
});

it('Modus aus: nichts', function () {
    config(['foodalchemist.fremd_schreiben.modus' => 'aus']);
    FaTeam::imTeam($this->b, fn () => $this->rezeptA->update(['name' => 'aus']));
    expect(($this->zeilen)())->toBe([]);
});

it('Menge: gleicher Schlüssel einmal je Prozess; hart 200 Zeilen, danach eine Schlusszeile „unterdrückt“', function () {
    FaTeam::imTeam($this->b, function () {
        foreach (range(1, 50) as $i) {
            $this->rezeptA->update(['name' => 'x'.$i]);
        }
    });
    expect(($this->zeilen)())->toHaveCount(1);

    // 260 verschiedene Zeilen-Teams → 260 Schlüssel
    config(['foodalchemist.fremd_schreiben.modus' => 'aus']);
    $rezepte = [];
    foreach (range(1, 260) as $i) {
        $t = Team::create(['name' => 'T'.$i, 'user_id' => 1, 'personal_team' => false]);
        $rezepte[] = $this->makeRecipe($t, 'R'.$i);
    }
    config(['foodalchemist.fremd_schreiben.modus' => 'log']);
    foreach ($rezepte as $i => $r) {
        FaTeam::imTeam($this->b, fn () => $r->update(['name' => 'y'.$i]));
    }
    FremdSchreiben::schlusszeile();
    $z = ($this->zeilen)();
    expect(count(array_filter($z, fn ($k) => $k['art'] === 'fremd')))->toBe(FremdSchreiben::MAX_ZEILEN);
    $schluss = array_values(array_filter($z, fn ($k) => $k['art'] === 'unterdrueckt'));
    expect($schluss)->toHaveCount(1)->and($schluss[0]['anzahl'])->toBe(61);   // 1 + 260 Schlüssel, 200 geschrieben
});

// ── Bewusste Fremdschreiber: je Grund kein Eintrag ──

it("Ausnahme 'recompute': Recompute eines A-Rezepts aus B-Kontext schreibt keine Zeile", function () {
    FaTeam::imTeam($this->b, fn () => app(RecipeRecomputeService::class)->recomputePipeline($this->rezeptA->id));
    expect(($this->zeilen)())->toBe([]);
});

it("Ausnahme 'signal': Signal an Team A aus B-Kontext schreibt keine Zeile", function () {
    FaTeam::imTeam($this->b, fn () => app(SignalService::class)->erzeuge($this->a, SignalTyp::PreisAnomalie, SignalSeverity::Info, 'Test'));
    expect(DB::table('foodalchemist_signals')->where('team_id', $this->a->id)->count())->toBe(1)->and(($this->zeilen)())->toBe([]);
});

it("Ausnahme 'unterteam-verwaltung': Oberteam ordnet dem Unter-Team einen Betrieb zu, keine Zeile", function () {
    config(['foodalchemist.fremd_schreiben.modus' => 'aus']);
    $ober = $this->rootTeam;
    $outlet = FoodAlchemistOutlet::create(['team_id' => $ober->id, 'name' => 'Haus 1']);
    $admin = $this->makeUser($ober, 'Ober-Admin', 'owner');
    $this->actingAs($admin);
    config(['foodalchemist.fremd_schreiben.modus' => 'log']);
    FaTeam::imTeam($ober, fn () => app(StandortService::class)->betriebZuordnen($ober, $this->childA->id, $outlet->id, $admin));
    expect(DB::table('foodalchemist_team_betriebe')->where('team_id', $this->childA->id)->value('outlet_id'))->toBe($outlet->id)
        ->and(array_filter(($this->zeilen)(), fn ($k) => $k['modell'] === 'FoodAlchemistTeamBetrieb'))->toBe([]);
});

it("Ausnahme 'abo-pflege': Kündigung durch den Master kopiert in den Mandanten, ohne Zeile", function () {
    config(['foodalchemist.fremd_schreiben.modus' => 'aus']);
    StammPaket::freischalten($this->a);
    $kurator = $this->makeUser($this->master, 'Kurator', 'owner');
    $this->actingAs($kurator);
    $jus = $this->makeRecipe($this->master, 'Jus', ['is_sales_recipe' => true]);
    $inhalte = app(InhaltsFreigabeService::class);
    $s = $inhalte->sammlungAnlegen($this->master, 'S', null, $kurator);
    $inhalte->sammlungHinzu($this->master, $s, 'recipe', [$jus->id], $kurator);
    $abo = app(AboService::class)->abonnieren($this->master, $this->a, $s, $kurator);
    $f1 = app(FassungService::class)->veroeffentlichen($this->master, $jus->id, 'Erst', $kurator)['fassung_id'];
    $lamm = $this->makeRecipe($this->a, 'Lamm');
    $this->makeIngredient($lamm, 'Jus')->update(['referenced_recipe_id' => $f1, 'gp_id' => null]);
    FremdSchreiben::zuruecksetzen();
    @unlink($this->logDatei);
    config(['foodalchemist.fremd_schreiben.modus' => 'log']);

    $r = FaTeam::imTeam($this->master, fn () => app(AboKuendigungService::class)->kuendigen($this->master, $abo->id, $kurator, bestaetigt: true));
    expect($r['kopien'])->not->toBeEmpty();
    $fremd = array_filter(($this->zeilen)(), fn ($k) => $k['art'] === 'fremd' && str_starts_with((string) ($k['aufrufer'] ?? ''), 'Services/AboKuendigungService.php'));
    expect($fremd)->toBe([]);
});

it('Auswertung: foodalchemist:fremd-schreiben gruppiert die Tages-Dateien', function () {
    $pfad = storage_path('logs/fa-fremdschreiben-auswertung-'.getmypid().'.log');
    config(['logging.channels.'.FremdSchreiben::KANAL.'.path' => $pfad]);
    $tagesDatei = storage_path('logs/fa-fremdschreiben-auswertung-'.getmypid().'-'.now()->format('Y-m-d').'.log');
    $zeile = fn (array $k) => json_encode(['message' => 'x', 'context' => $k])."\n";
    file_put_contents($tagesDatei, $zeile(['art' => 'fremd', 'modell' => 'FoodAlchemistRecipe', 'eingang' => 'seite', 'aufrufer' => 'Services/X.php:1', 'zeilen_team' => 2, 'kontext_team' => 3])
        .$zeile(['art' => 'fremd', 'modell' => 'FoodAlchemistRecipe', 'eingang' => 'seite', 'aufrufer' => 'Services/X.php:1', 'zeilen_team' => 4, 'kontext_team' => 3])
        .$zeile(['art' => 'unterdrueckt', 'anzahl' => 7, 'eingang' => 'job:Y']));
    try {
        $this->artisan('foodalchemist:fremd-schreiben', ['--tage' => 1])
            ->expectsOutputToContain('davon fremd: 1')
            ->assertSuccessful();
    } finally {
        @unlink($tagesDatei);
    }
});

it("Ausnahme 'unterteam-verwaltung': Oberteam setzt die Preis-Toleranz des Unter-Teams — ausdrücklich, keine Zeile; die Zeile des Unter-Teams wird gefunden", function () {
    config(['foodalchemist.fremd_schreiben.modus' => 'aus']);
    $ober = $this->rootTeam;
    $admin = $this->makeUser($ober, 'Ober-Admin', 'owner');
    $this->actingAs($admin);
    $vorhanden = \Platform\FoodAlchemist\Models\FoodAlchemistTeamSetting::create(['team_id' => $this->childA->id, 'ai_active' => false]);
    config(['foodalchemist.fremd_schreiben.modus' => 'log']);

    foreach ([0, 4] as $stufe) {
        config(['foodalchemist.team_sicht.stufe' => $stufe]);
        FaTeam::imTeam($ober, fn () => app(\Platform\FoodAlchemist\Services\TripleMatchService::class)->setzeToleranz($this->childA, 3.0 + $stufe, 0.5, $admin->id));

        $zeilen = DB::table('foodalchemist_team_settings')->where('team_id', $this->childA->id)->get();
        expect($zeilen)->toHaveCount(1)->and((int) $zeilen[0]->id)->toBe((int) $vorhanden->id)
            ->and((float) $zeilen[0]->tm_toleranz_preis_pct)->toBe(3.0 + $stufe)
            ->and(array_filter(($this->zeilen)(), fn ($k) => $k['modell'] === 'FoodAlchemistTeamSetting'))->toBe([]);
    }
});
