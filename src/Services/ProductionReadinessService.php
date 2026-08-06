<?php

namespace Platform\FoodAlchemist\Services;

use Illuminate\Support\Collection;
use Platform\Core\Models\Team;

/** Eine Wahrheit für Klärfälle vor Produktionsstart. */
class ProductionReadinessService
{
    public function __construct(private ProductionCapacityService $capacity) {}

    /** @return list<array{level:string,code:string,label:string,count:int}> */
    public function findings(Team $team, string $von, string $bis): array
    {
        $lines = $this->capacity->tagesplanZeilen($team, $von, $bis, true);
        $findings = collect([
            ['level' => 'warning', 'code' => 'unassigned', 'label' => 'Nicht zugeteilt', 'count' => $lines->whereNull('station_id')->count()],
            ['level' => 'warning', 'code' => 'without_time', 'label' => 'Ohne Arbeitszeit', 'count' => $lines->whereNull('arbeitszeit_min')->count()],
            ['level' => 'warning', 'code' => 'without_instructions', 'label' => 'Ohne Anleitung', 'count' => $lines->filter(fn ($l) => empty($l->schritte) && empty($l->zubereitung))->count()],
            ['level' => 'warning', 'code' => 'material_unchecked', 'label' => 'Material nicht geprüft', 'count' => $lines->filter(fn ($l) => $l->recipe_id !== null && empty($l->zutaten))->count()],
            ['level' => 'warning', 'code' => 'overdue', 'label' => 'Überfällig', 'count' => $lines->filter(fn ($l) => $l->line_status !== 'done' && $l->plan_date < now()->toDateString())->count()],
            ['level' => 'blocker', 'code' => 'blocked', 'label' => 'Blockiert', 'count' => $lines->filter(fn ($l) => ! empty($l->blocked_reason))->count()],
        ]);

        $overload = collect($this->capacity->auslastung($team, $von, $bis))->flatten(1)
            ->where('stufe', 'ueberlast')->count();
        $findings->push(['level' => 'warning', 'code' => 'overload', 'label' => 'Posten über Kapazität', 'count' => $overload]);

        return $findings->where('count', '>', 0)->values()->all();
    }

    /** @return array{blockers:list<array>,warnings:list<array>} */
    public function split(Team $team, string $von, string $bis): array
    {
        $findings = collect($this->findings($team, $von, $bis));

        return [
            'blockers' => $findings->where('level', 'blocker')->values()->all(),
            'warnings' => $findings->where('level', 'warning')->values()->all(),
        ];
    }
}
