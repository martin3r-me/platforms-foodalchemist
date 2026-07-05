<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\SpeiseplanService;

/** Phase C: Speiseplan anlegen (draft) — Starter-Linien Menü 1/Vegetarisch/Dessert kommen automatisch. */
class SpeiseplaenePostTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.speiseplaene.POST';
    }

    public function getDescription(): string
    {
        return 'Legt einen Speiseplan als ENTWURF an (status=draft, Starter-Linien Menü 1/Vegetarisch/Dessert '
            . 'automatisch). Einträge danach via foodalchemist.speiseplan_eintraege.POST. '
            . 'start_date = Montag der ersten Woche (YYYY-MM-DD).';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'name' => ['type' => 'string'],
                'start_date' => ['type' => 'string', 'description' => 'YYYY-MM-DD, Default: aktueller Wochenstart'],
                'zyklus_wochen' => ['type' => 'integer', 'minimum' => 1, 'default' => 4],
                'min_abstand_tage' => ['type' => 'integer', 'minimum' => 0, 'default' => 0, 'description' => 'Wiederholungs-Sperre pro Gericht'],
            ],
            'required' => ['name'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }

        try {
            $plan = app(SpeiseplanService::class)->create($team, [
                'name' => (string) $arguments['name'],
                'start_date' => $arguments['start_date'] ?? null,
                'zyklus_wochen' => $arguments['zyklus_wochen'] ?? 4,
                'min_abstand_tage' => $arguments['min_abstand_tage'] ?? 0,
                'status' => 'draft',
            ]);
        } catch (\Throwable $e) {
            return ToolResult::error($e->getMessage(), 'VALIDATION_ERROR');
        }

        return ToolResult::success([
            'speiseplan' => [
                'id' => $plan->id, 'name' => $plan->name, 'status' => $plan->status,
                'start_date' => (string) $plan->start_date, 'zyklus_wochen' => $plan->zyklus_wochen,
            ],
            'linien' => $plan->linien->map(fn ($l) => ['id' => $l->id, 'name' => $l->name, 'is_vegetarian' => (bool) $l->is_vegetarian])->all(),
        ]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['foodalchemist', 'speiseplan', 'kantine', 'anlegen', 'draft'],
            'read_only' => false, 'idempotent' => false, 'risk_level' => 'write',
            'requires_auth' => true, 'requires_team' => true,
            'side_effects' => ['creates'], 'cost_class' => 'local_db',
            'related_tools' => ['foodalchemist.speiseplan_eintraege.POST', 'foodalchemist.concepts.SEARCH'],
            'examples' => ['Lege einen 4-Wochen-Speiseplan "Kantine Q3" ab 2026-07-06 an'],
        ];
    }
}
