<?php

namespace Platform\FoodAlchemist\Console;

use Illuminate\Console\Command;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Services\FavoriteGpService;

/**
 * 06·H2 — Kuratierung der Favoriten-GPs (kuratierter Haus-Standard).
 *
 * --suggest  : Auto-Score-Rangliste der GPs (Report, kein Schreiben).
 * --pin=ID   : GP als Favorit pinnen (optional --rank=N).
 * --exclude=ID : GP aus den Favoriten nehmen.
 * ohne Aktion + ohne --suggest → aktuelle Favoriten-Liste anzeigen.
 */
class FavoriteGpsCommand extends Command
{
    protected $signature = 'foodalchemist:favorites
        {--team= : Team-ID (default: alle sichtbar)}
        {--suggest : Auto-Score-Rangliste anzeigen (Report)}
        {--pin= : GP-ID als Favorit pinnen}
        {--exclude= : GP-ID aus den Favoriten nehmen}
        {--rank= : Anzeige-Rang beim Pinnen}
        {--limit=50 : max. Zeilen im Report}';

    protected $description = 'H2: Favoriten-GPs kuratieren (Auto-Score-Report + pin/exclude).';

    public function handle(FavoriteGpService $svc): int
    {
        $team = null;
        if ($this->option('team') !== null) {
            $team = Team::find((int) $this->option('team'));
            if ($team === null) {
                $this->error('Team ' . $this->option('team') . ' nicht gefunden.');

                return self::FAILURE;
            }
        }

        // Aktionen
        if ($this->option('pin') !== null) {
            $gp = FoodAlchemistGp::find((int) $this->option('pin'));
            if ($gp === null) {
                $this->error('GP nicht gefunden.');

                return self::FAILURE;
            }
            try {
                $svc->pin($gp, $this->option('rank') !== null ? (int) $this->option('rank') : null);
            } catch (\RuntimeException $e) {
                $this->error($e->getMessage());

                return self::FAILURE;
            }
            $this->info("GP {$gp->id} ({$gp->name}) gepinnt" . ($this->option('rank') !== null ? ' (Rang ' . (int) $this->option('rank') . ')' : '') . '.');

            return self::SUCCESS;
        }

        if ($this->option('exclude') !== null) {
            $gp = FoodAlchemistGp::find((int) $this->option('exclude'));
            if ($gp === null) {
                $this->error('GP nicht gefunden.');

                return self::FAILURE;
            }
            $svc->exclude($gp);
            $this->info("GP {$gp->id} ({$gp->name}) aus den Favoriten genommen.");

            return self::SUCCESS;
        }

        $limit = (int) $this->option('limit');

        if ($this->option('suggest')) {
            $rows = $svc->suggest($team, $limit)->map(fn ($r) => [
                $r['gp_id'], $r['name'], $r['usage'], $r['has_lead_la'] ? '✓' : '—',
                $r['has_price'] ? '✓' : '—', $r['priority_pos'] ?? '—', $r['score'], $r['is_favorite'] ? '★' : '',
            ])->all();
            $this->info('Favoriten-GPs — Auto-Score-Rangliste (★ = bereits gepinnt):');
            $this->table(['GP', 'Name', 'Nutzung', 'Lead-LA', 'Preis', 'Prio-Pos', 'Score', 'Pin'], $rows);

            return self::SUCCESS;
        }

        // Default: aktuelle Favoriten
        $cur = $svc->current($team);
        if ($cur->isEmpty()) {
            $this->warn('Keine Favoriten-GPs gepinnt. --suggest zeigt die Rangliste.');

            return self::SUCCESS;
        }
        $this->info("Gepinnte Favoriten-GPs ({$cur->count()}):");
        $this->table(['GP', 'Name', 'Rang'], $cur->map(fn ($g) => [$g->id, $g->name, $g->favorite_rank ?? '—'])->all());

        return self::SUCCESS;
    }
}
