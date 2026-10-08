<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\LagerEinrichtungService;

/** Spec 66b · Stellplatz anlegen (hinten an den Laufweg). */
class StorageBinsPostTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.storage_bins.POST';
    }

    public function getDescription(): string
    {
        return 'Legt einen Stellplatz in einem Lagerort an, z. B. „Kühlhaus Regal A". zone (optional): kuehl | tk | trocken | '
            . 'getraenke | sonstig — steuert das automatische Einsortieren. Neue Plätze kommen ans Ende des Laufwegs.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'location_id' => ['type' => 'integer', 'description' => 'Lagerort-Id.'],
                'name' => ['type' => 'string', 'description' => 'Name des Stellplatzes.'],
                'zone' => ['type' => 'string', 'enum' => ['kuehl', 'tk', 'trocken', 'getraenke', 'sonstig']],
            ],
            'required' => ['location_id', 'name'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        try {
            $b = app(LagerEinrichtungService::class)->stellplatzAnlegen($team, (int) ($arguments['location_id'] ?? 0), (string) ($arguments['name'] ?? ''), $arguments['zone'] ?? null);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            return ToolResult::error('Lagerort nicht gefunden.', 'NOT_FOUND');
        } catch (\RuntimeException $e) {
            return ToolResult::error($e->getMessage(), 'VALIDATION_ERROR');
        }

        return ToolResult::success(['bin_id' => $b->id, 'name' => $b->name, 'zone' => $b->zone, 'reihenfolge' => $b->sort_order]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['foodalchemist', 'lager', 'stellplatz', 'write'],
            'read_only' => false, 'idempotent' => false, 'risk_level' => 'write',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'side_effects' => ['creates'],
            'related_tools' => ['foodalchemist.storage_bins.PUT', 'foodalchemist.storage_bins.ASSIGN'],
            'examples' => ['Leg im Hauptlager die Stellplätze Kühlhaus, TK-Raum und Trockenlager an.'],
        ];
    }
}
