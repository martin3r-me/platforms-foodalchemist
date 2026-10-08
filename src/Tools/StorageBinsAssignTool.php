<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\LagerEinrichtungService;

/** Spec 66b · Grundprodukte einem Stellplatz zuordnen — einzeln, gesammelt oder automatisch. */
class StorageBinsAssignTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.storage_bins.ASSIGN';
    }

    public function getDescription(): string
    {
        return 'Setzt den Stammplatz von Grundprodukten in einem Lagerort. Entweder gp_ids + bin_id (bin_id null = Stammplatz '
            . 'entfernen) oder automatisch=true: alles ohne Stammplatz wird nach Zustand und Warengruppe in den ersten '
            . 'Stellplatz der passenden Zone sortiert (TK → tk, frisch Fleisch/Fisch/Molkerei/Obst/Gemüse → kuehl, '
            . 'Getränke → getraenke, sonst trocken). Bestehende Zuordnungen bleiben dabei unangetastet.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'location_id' => ['type' => 'integer', 'description' => 'Lagerort-Id.'],
                'gp_ids' => ['type' => 'array', 'items' => ['type' => 'integer']],
                'bin_id' => ['type' => ['integer', 'null'], 'description' => 'Ziel-Stellplatz; null = Stammplatz entfernen.'],
                'automatisch' => ['type' => 'boolean', 'description' => 'Vorschlag für alles ohne Stammplatz übernehmen.'],
            ],
            'required' => ['location_id'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        $svc = app(LagerEinrichtungService::class);
        $ort = (int) ($arguments['location_id'] ?? 0);
        try {
            if (! empty($arguments['automatisch'])) {
                return ToolResult::success(['location_id' => $ort, 'einsortiert' => $svc->vorschlagUebernehmen($team, $ort)]);
            }
            $gpIds = array_map('intval', (array) ($arguments['gp_ids'] ?? []));
            if ($gpIds === [] || ! array_key_exists('bin_id', $arguments)) {
                return ToolResult::error('gp_ids und bin_id angeben — oder automatisch=true.', 'VALIDATION_ERROR');
            }
            $binId = $arguments['bin_id'] !== null ? (int) $arguments['bin_id'] : null;
            $n = $svc->zuordnen($team, $ort, $gpIds, $binId);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            return ToolResult::error('Lagerort oder Stellplatz nicht gefunden.', 'NOT_FOUND');
        }

        return ToolResult::success(['location_id' => $ort, 'bin_id' => $binId, 'geaendert' => $n]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['foodalchemist', 'lager', 'stellplatz', 'write'],
            'read_only' => false, 'idempotent' => true, 'risk_level' => 'write',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'side_effects' => ['updates'],
            'related_tools' => ['foodalchemist.storage_bins.GET', 'foodalchemist.inventory_counts.POST'],
            'examples' => ['Sortier im Hauptlager alles automatisch ein.', 'Stell Butter und Sahne ins Kühlhaus Regal B.'],
        ];
    }
}
