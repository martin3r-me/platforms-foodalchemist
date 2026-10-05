<?php

namespace Platform\FoodAlchemist\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistSpeiseplan;
use Platform\FoodAlchemist\Services\PresentationService;

/**
 * Spec 57 · E9: Speiseplan-Aushänge mit „immer die laufende Woche“ jeden Montag neu einfrieren.
 *
 * Der digitale Aushang bleibt ein absoluter Snapshot (Spec 43) — dieser Lauf veröffentlicht ihn
 * nur mit denselben Einstellungen neu, sodass er die aktuelle Woche zeigt. Token und Link-Name
 * bleiben, abgelaufene oder zurückgezogene Aushänge werden nicht angefasst. Idempotent: ein
 * Aushang, der schon die laufende Woche zeigt, wird übersprungen.
 */
class SpeiseplanAushangRollierenCommand extends Command
{
    protected $signature = 'foodalchemist:speiseplan-aushang-rollieren {--dry-run : nur zählen, nichts veröffentlichen}';

    protected $description = 'Spec 57: Speiseplan-Aushänge mit „laufende Woche“ auf die aktuelle Woche neu einfrieren.';

    public function handle(PresentationService $presentations): int
    {
        $montag = Carbon::now()->startOfWeek(Carbon::MONDAY)->format('Y-m-d');
        $erneuert = 0;
        $uebersprungen = 0;

        FoodAlchemistSpeiseplan::query()
            ->where('presentation_enabled', true)
            ->whereNotNull('presentation_expires_at')
            ->where('presentation_expires_at', '>=', now())
            ->select(['id', 'team_id', 'presentation_settings_json', 'presentation_design', 'presentation_expires_at', 'presentation_enabled'])
            ->chunkById(100, function ($plaene) use ($presentations, $montag, &$erneuert, &$uebersprungen) {
                foreach ($plaene as $plan) {
                    $s = $plan->presentation_settings_json;
                    $s = is_string($s) ? (json_decode($s, true) ?: []) : (array) ($s ?? []);
                    if (! ($s['laufende_woche'] ?? false) || ($s['montag'] ?? null) === $montag) {
                        $uebersprungen++;

                        continue;
                    }
                    $team = Team::find($plan->team_id);
                    if ($team === null) {
                        $uebersprungen++;

                        continue;
                    }
                    if ($this->option('dry-run')) {
                        $erneuert++;

                        continue;
                    }
                    try {
                        $presentations->publish($team, 'speiseplan', (int) $plan->id, [
                            'design' => $s['design'] ?? $plan->presentation_design,
                            'expires_at' => $plan->presentation_expires_at?->format('Y-m-d'),
                            'price_display' => (bool) ($s['price_display'] ?? false),
                            'price_mode' => 'auto',
                            'cta' => $s['cta'] ?? [],
                            'mahlzeit' => $s['mahlzeit'] ?? 'mittag',
                            'laufende_woche' => true,
                        ]);
                        $erneuert++;
                    } catch (\Throwable $e) {
                        $this->warn("Speiseplan {$plan->id}: {$e->getMessage()}");
                    }
                }
            });

        $this->info("Fertig — {$erneuert} Aushang/Aushänge erneuert, {$uebersprungen} übersprungen (Woche ab {$montag}).");

        return self::SUCCESS;
    }
}
