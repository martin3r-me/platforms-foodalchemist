<?php

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Jobs\GenerateRecipeJob;
use Platform\FoodAlchemist\Models\FoodAlchemistMandant;
use Platform\FoodAlchemist\Support\FaTeam;
use Platform\FoodAlchemist\Support\KiVerursacher;
use Platform\FoodAlchemist\Support\MitKiVerursacher;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/** Job mit Auftragsteam (wie die KI-Jobs): merkt sich FaTeam und Verursacher beim Ausführen. */
final class Spec84AuftragsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, MitKiVerursacher;

    /** @var list<array{team: ?int, verursacher: array}> */
    public static array $gesehen = [];

    public function __construct(public int $teamId) {}

    public function handle(): void
    {
        self::$gesehen[] = ['team' => FaTeam::aktuellId(), 'verursacher' => KiVerursacher::aufloesen()];
    }
}

/**
 * Spec 84 A-3 · Jobs laufen im Team ihres Auftrags (ImTeamKontext über MitKiVerursacher) — nicht im UI-Team des Nutzers zur
 * Laufzeit, nicht im alten Login des Workers. KiVerursacher hat keinen eigenen Kontext mehr, sondern liest FaTeam.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->master = Team::create(['name' => 'Master', 'user_id' => 1, 'personal_team' => false]);
    config(['foodalchemist.master_team_id' => $this->master->id]);
    \Platform\FoodAlchemist\Support\MasterTeam::flush();
    $this->a = Team::create(['name' => 'Mandant A', 'user_id' => 1, 'personal_team' => false]);
    $this->b = Team::create(['name' => 'Mandant B', 'user_id' => 1, 'personal_team' => false]);
    FoodAlchemistMandant::create(['team_id' => $this->a->id, 'status' => 'aktiv']);
    FoodAlchemistMandant::create(['team_id' => $this->b->id, 'status' => 'aktiv']);
    Spec84AuftragsJob::$gesehen = [];
});

it('Test 3: Job für B läuft in B, obwohl der Nutzer inzwischen in der UI auf A steht (bzw. der Worker A angemeldet hat)', function () {
    $this->actingAs($this->makeUser($this->a, 'Koch A'));

    FaTeam::imTeam($this->b, fn () => Spec84AuftragsJob::dispatch($this->b->id));

    expect(Spec84AuftragsJob::$gesehen[0]['team'])->toBe($this->b->id)
        ->and(Spec84AuftragsJob::$gesehen[0]['verursacher'])->toBe(['abrechnung_team_id' => $this->b->id, 'verursacher' => 'mandant']);
});

it('Test 7: Auftragsteam ≠ Versand-Team (Versand ohne imTeam) → Warnung ins Log, Auftragsteam gewinnt', function () {
    $this->actingAs($this->makeUser($this->a, 'Koch A'));
    Log::spy();

    Spec84AuftragsJob::dispatch($this->b->id);                                     // Versand im UI-Team A, Auftrag B

    expect(Spec84AuftragsJob::$gesehen[0]['team'])->toBe($this->b->id);
    Log::shouldHaveReceived('warning')->withArgs(fn ($m, $c) => str_contains($m, 'Job-Team weicht vom Versand-Team ab')
        && $c['auftragsteam'] === $this->b->id && $c['versandteam'] === $this->a->id)->once();
});

it('Plattform-Lauf (imKontext KONTEXT_PLATTFORM) bleibt Plattform, auch mit altem Login des Workers', function () {
    $this->actingAs($this->makeUser($this->a, 'Alter Login'));

    $r = KiVerursacher::imKontext(KiVerursacher::KONTEXT_PLATTFORM, fn () => KiVerursacher::aufloesen());

    expect($r)->toBe(['abrechnung_team_id' => null, 'verursacher' => 'plattform'])
        ->and(KiVerursacher::aufloesen()['abrechnung_team_id'])->toBe($this->a->id);   // danach wieder der Nutzer
});

it('die KI-Jobs mit Auth::login tragen jetzt die Team-Middleware (Stichprobe GenerateRecipeJob)', function () {
    $job = new GenerateRecipeJob(...array_values(array_map(fn ($p) => match ((string) $p->getType()) {
        'int' => $p->getName() === 'teamId' ? $this->b->id : 1, '?int' => null, 'string' => 'x', '?string' => null, 'array' => [], 'bool' => false,
        default => null,
    }, (new ReflectionMethod(GenerateRecipeJob::class, '__construct'))->getParameters())));
    $mw = $job->middleware();

    expect($mw)->toHaveCount(1)->and($mw[0])->toBeInstanceOf(\Platform\FoodAlchemist\Jobs\Middleware\ImTeamKontext::class);
    Auth::logout();
    $gesehen = null;
    $mw[0]->handle($job, function () use (&$gesehen) { $gesehen = FaTeam::aktuellId(); });
    expect($gesehen)->toBe($this->b->id);
});
