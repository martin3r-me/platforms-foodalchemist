<?php

namespace Platform\FoodAlchemist\Console;

use Illuminate\Console\Command;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeDarreichung;
use Platform\FoodAlchemist\Services\DynamicPricingMigrationService;

class DynamicPricingMigrationCommand extends Command
{
    protected $signature = 'foodalchemist:pricing-v2
        {--team= : optional nur ein Team}
        {--chunk=200 : Chunk-Größe}
        {--apply : ausführen; ohne nur Umfang zeigen}';

    protected $description = 'Migriert aktive Preise idempotent auf den dynamischen Katalogpreis.';

    public function handle(DynamicPricingMigrationService $migration): int
    {
        $teamId = $this->option('team') !== null ? (int) $this->option('team') : null;
        $query = FoodAlchemistRecipeDarreichung::query()
            ->when($teamId !== null, fn ($q) => $q->where('team_id', $teamId));
        if (! $this->option('apply')) {
            $this->warn('DRY-RUN — würde ' . $query->count() . ' Darreichungen sowie abhängige Auto-Preise neu rechnen.');

            return self::SUCCESS;
        }

        $stats = $migration->migrate($teamId, max(10, (int) $this->option('chunk')));
        $this->info(implode(', ', array_map(fn ($key, $value) => "{$key}: {$value}", array_keys($stats), $stats)));

        return self::SUCCESS;
    }
}
