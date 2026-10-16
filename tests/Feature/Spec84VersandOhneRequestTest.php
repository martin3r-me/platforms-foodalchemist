<?php

use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Jobs\Middleware\ImTeamKontext;
use Platform\FoodAlchemist\Services\RecipeFindingsBatchService;
use Platform\FoodAlchemist\Support\FaTeam;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 84 A-4 · Arbeit und Versand OHNE Request (Scheduler, Artisan, Folge-Dienste) laufen ausdrücklich im Team, für das sie
 * arbeiten — sonst sehen KI-Riegel, Abrechnung und Messung kein bzw. das falsche Team. §3.5 Test 5.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
});

it('Test 5: ein Befehl über mehrere Teams arbeitet je Team in dessen Kontext (recipe-findings)', function () {
    $gesehen = [];
    app()->instance(RecipeFindingsBatchService::class, new class($gesehen) extends RecipeFindingsBatchService
    {
        public function __construct(private array &$log) {}

        public function laufe(Team $team, int $limit = 25, bool $nurVerkauf = false, string $pass = 'copilot', ?int $nurRezept = null,
            bool $trocken = false, ?callable $log = null, ?int $userId = null, ?int $runId = null): array
        {
            $this->log[] = [$team->id, FaTeam::aktuellId()];

            return ['geprueft' => 0, 'run_id' => null, 'offen' => 0, 'neu' => 0, 'wieder' => 0, 'verschwunden' => 0, 'fehler' => 0];
        }
    });

    $this->artisan('foodalchemist:recipe-findings', ['--dry-run' => true])->assertSuccessful();

    expect($gesehen)->not->toBeEmpty();
    foreach ($gesehen as [$team, $kontext]) {
        expect($kontext)->toBe($team);                                   // vorher: null (CLI ohne Nutzer)
    }
    expect(FaTeam::ausdruecklich())->toBeFalse();                        // danach kein Rest-Kontext
});

it('afterCommit-Job: Payload entsteht beim Commit außerhalb des Rahmens — keine Fehlalarm-Warnung, Auftragsteam gewinnt', function () {
    Log::spy();
    $job = new class { public bool $afterCommit = true; };
    $gesehen = null;

    Context::addHidden(FaTeam::SCHLUESSEL, $this->childA->id);             // Versand-Team ≠ Auftragsteam
    (new ImTeamKontext($this->childB->id))->handle($job, function () use (&$gesehen) { $gesehen = FaTeam::aktuellId(); });

    expect($gesehen)->toBe($this->childB->id);
    Log::shouldNotHaveReceived('warning');
});

it('TODO A-4: Abweichung Auftrags- ≠ Versand-Team wirft TeamKontextWiderspruch statt nur zu warnen')
    ->todo(note: 'Spec 84 §3.3a: erst nach einer Woche io-Log ohne unerklärte „Job-Team weicht vom Versand-Team ab“-Warnung umstellen (Kurator 16.10.).');
