<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\LagerEinrichtungService;

/** Spec 66b · Stellplatz umbenennen, Zone ändern, im Laufweg verschieben. */
class StorageBinsPutTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.storage_bins.PUT';
    }

    public function getDescription(): string
    {
        return 'Ändert einen Stellplatz: name, zone (kuehl|tk|trocken|getraenke|sonstig, leer = ohne Zone) und/oder '
            . 'verschieben (-1 = im Laufweg nach vorn, 1 = nach hinten).';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'bin_id' => ['type' => 'integer', 'description' => 'Stellplatz-Id.'],
                'name' => ['type' => 'string'],
                'zone' => ['type' => ['string', 'null']],
                'verschieben' => ['type' => 'integer', 'enum' => [-1, 1]],
            ],
            'required' => ['bin_id'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        $svc = app(LagerEinrichtungService::class);
        $id = (int) ($arguments['bin_id'] ?? 0);
        try {
            $daten = array_intersect_key($arguments, array_flip(['name', 'zone']));
            $b = $svc->stellplatzAendern($team, $id, $daten);
            if (! empty($arguments['verschieben'])) {
                $svc->stellplatzVerschieben($team, $id, (int) $arguments['verschieben']);
                $b->refresh();
            }
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            return ToolResult::error('Stellplatz nicht gefunden.', 'NOT_FOUND');
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
            'read_only' => false, 'idempotent' => true, 'risk_level' => 'write',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'side_effects' => ['updates'],
            'related_tools' => ['foodalchemist.storage_bins.GET'],
            'examples' => ['Setz das Getränkelager im Laufweg nach vorn.'],
        ];
    }
}
