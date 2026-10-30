<?php

namespace Platform\FoodAlchemist\Console;

use Illuminate\Console\Command;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistTrendInspiration;
use Platform\FoodAlchemist\Services\Ai\PoolEmbeddingService;

/**
 * Spec 93 B · Messbefehl für `trends.quellen.buendel_aehnlichkeit` (Bündel-Schritt 2). Die Schwelle wird NICHT geraten: dieser
 * Befehl zeigt für die Themen EINES Teams (nie global, nie fremde Teams) die ähnlichsten Paare mit Kosinus-Score, damit
 * Dominique auf demo sieht, ab welchem Wert zwei Themen wirklich dasselbe meinen. Er schreibt nichts — weder in die
 * Datenbank noch in den Vektor-Index (die Themen werden nur für die Messung embeddet, die Vektoren bleiben im Speicher).
 */
class TrendThemenAehnlichkeitCommand extends Command
{
    protected $signature = 'foodalchemist:trend-themen-aehnlichkeit
        {--team= : Team-ID (Pflicht) — gemessen werden nur die Themen dieses Teams}
        {--paare=40 : so viele ähnlichste Paare ausgeben}
        {--mit-pinnwand : manuelle Inspirationen (Pinnwand) mit einbeziehen}';

    protected $description = 'Trendradar → Ähnlichkeit der Themen eines Teams messen (schreibt nichts), für buendel_aehnlichkeit';

    public function handle(): int
    {
        $team = $this->option('team') ? Team::find((int) $this->option('team')) : null;
        if ($team === null) {
            $this->error('--team=<ID> ist Pflicht (gemessen wird nur in der Partition eines Teams).');

            return self::FAILURE;
        }
        $herkunft = $this->option('mit-pinnwand') ? ['automatisch', 'manuell'] : ['automatisch'];
        $themen = FoodAlchemistTrendInspiration::where('team_id', $team->id)->whereIn('herkunft', $herkunft)
            ->orderBy('id')->get(['id', 'titel', 'herkunft']);
        if ($themen->count() < 2) {
            $this->warn('Weniger als zwei Themen im Team — nichts zu messen.');

            return self::SUCCESS;
        }
        $provider = $this->provider();
        if ($provider === null) {
            $this->warn('Kein Embedding-Provider verfügbar — Messung nur auf einer Umgebung mit Vektorsuche (z. B. demo).');

            return self::SUCCESS;
        }
        $vektoren = $provider->embed($themen->map(fn ($t) => PoolEmbeddingService::normalizeForEmbedding((string) $t->titel))->all(), 'document');
        $paare = [];
        $liste = $themen->values();
        for ($i = 0; $i < $liste->count(); $i++) {
            for ($j = $i + 1; $j < $liste->count(); $j++) {
                if (isset($vektoren[$i], $vektoren[$j])) {
                    $paare[] = [round(self::kosinus($vektoren[$i], $vektoren[$j]), 3), $liste[$i]->titel, $liste[$j]->titel];
                }
            }
        }
        usort($paare, fn ($a, $b) => $b[0] <=> $a[0]);
        $this->info(sprintf('Team %d (%s): %d Themen, %d Paare, Provider %s/%s. Nichts gespeichert.', $team->id, $team->name,
            $themen->count(), count($paare), $provider->getName(), $provider->getModel()));
        $this->table(['Score', 'Thema A', 'Thema B'], array_slice($paare, 0, max(1, (int) $this->option('paare'))));

        return self::SUCCESS;
    }

    /** @param list<float> $a @param list<float> $b */
    public static function kosinus(array $a, array $b): float
    {
        $skalar = $na = $nb = 0.0;
        foreach ($a as $k => $x) {
            $y = (float) ($b[$k] ?? 0.0);
            $skalar += $x * $y;
            $na += $x * $x;
            $nb += $y * $y;
        }

        return $na > 0 && $nb > 0 ? $skalar / (sqrt($na) * sqrt($nb)) : 0.0;
    }

    private function provider(): ?\Platform\Core\Contracts\EmbeddingProviderContract
    {
        try {
            $registry = app(\Platform\Core\Services\EmbeddingProviderRegistry::class);
            $name = config('foodalchemist.semantic_search.provider');
            $p = is_string($name) && $name !== '' ? $registry->get($name) : $registry->getDefaultProvider();

            return $p !== null && $p->isAvailable() ? $p : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
