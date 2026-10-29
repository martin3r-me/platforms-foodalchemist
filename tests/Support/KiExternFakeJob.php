<?php

namespace Platform\FoodAlchemist\Tests\Support;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\DB;
use Platform\FoodAlchemist\Services\Ai\AiGatewayService;
use Platform\FoodAlchemist\Services\Ai\KiExternLauf;
use Platform\FoodAlchemist\Services\Ai\KiExternWiederholbar;

/**
 * Test-Job für P2a: zwei KI-Glieder mit Schreibzugriffen dazwischen — die Form von EnrichRecipeJob/Generatoren in klein.
 * `instabil` baut einen wechselnden Wert in den ersten Prompt (Fingerabdruck instabil → Schranke).
 */
class KiExternFakeJob implements KiExternWiederholbar, ShouldQueue
{
    use Dispatchable, Queueable;

    /** @var list<string> */
    public static array $nachCommit = [];

    /** @var list<int> Transaktions-Niveau, in dem der Rumpf lief */
    public static array $niveaus = [];

    public function __construct(
        public int $teamId,
        public bool $extern = false,
        public string $ziel = 'ziel-1',
        public int $versuch = 0,
        public bool $instabil = false,
        public bool $nebenwirkung = false,
    ) {
    }

    public function handle(KiExternLauf $lauf): string
    {
        return $lauf->fuehreAus($this, function () {
            self::$niveaus[] = DB::transactionLevel();
            DB::table('ki_fake_job')->insert(['x' => 'A']);
            if ($this->nebenwirkung) {                     // genau das, was ein Rollback NICHT zurücknimmt
                KiExternDummyJob::dispatch();
                \Illuminate\Support\Facades\Cache::put('ki-fake-job-cache', 1, 60);
            }
            DB::afterCommit(function () {
                self::$nachCommit[] = $this->ziel;
            });
            $gw = app(AiGatewayService::class);
            $gw->propose('recipe.description', ['description' => 'Eins'.($this->instabil ? uniqid('', true) : '')], ['target_table' => 'fake', 'target_id' => 1]);
            DB::table('ki_fake_job')->insert(['x' => 'B']);
            $gw->propose('recipe.description', ['description' => 'Zwei'], ['target_table' => 'fake', 'target_id' => 2]);
            DB::table('ki_fake_job')->insert(['x' => 'C']);
        });
    }

    public function externAktiv(): bool
    {
        return $this->extern;
    }

    public function externSchluessel(): string
    {
        return 'fake-job:'.$this->ziel;
    }

    public function externTeamId(): int
    {
        return $this->teamId;
    }

    public function externVersuch(): int
    {
        return $this->versuch;
    }

    public function externNutzlast(): array
    {
        return ['team_id' => $this->teamId, 'ziel' => $this->ziel, 'instabil' => $this->instabil];
    }

    public static function fromExternNutzlast(array $nutzlast, int $versuch): static
    {
        return new static((int) $nutzlast['team_id'], true, (string) $nutzlast['ziel'], $versuch, (bool) ($nutzlast['instabil'] ?? false));
    }
}

/** No-op-Job: nur um einen Queue-Dispatch auszulösen. */
class KiExternDummyJob implements ShouldQueue
{
    use Dispatchable, Queueable;

    public function handle(): void
    {
    }
}
