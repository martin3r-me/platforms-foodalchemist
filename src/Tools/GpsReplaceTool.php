<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\GpService;

/**
 * MCP-Steuerbarkeit · D1: GP in ALLEN Rezept-Zeilen durch ein anderes ersetzen (Vorstufe zum Löschen).
 *
 * Hohe Reichweite: hängt Referenzen team-übergreifend um und stößt für jedes betroffene Rezept einen
 * Neuberechnungs-Lauf an (Yield/Allergene/EK + Propagation). Darum destruktiv → confirm=true Pflicht,
 * und nur fürs Besitzer-Team des zu ersetzenden GP (isOwnedBy = Web-canCurate). Ziel-GP muss sichtbar sein.
 */
class GpsReplaceTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.gps.REPLACE';
    }

    public function getDescription(): string
    {
        return 'Ersetzt ein GP in allen Rezept-Zeilen durch ein anderes (from → to) und rechnet die betroffenen '
            . 'Rezepte neu. Reichweitenstark → confirm=true Pflicht. Nur fürs Besitzer-Team des from-GP.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'from_id' => ['type' => 'integer', 'description' => 'Zu ersetzendes GP (team-eigen).'],
                'to_id' => ['type' => 'integer', 'description' => 'Ziel-GP (sichtbar).'],
                'confirm' => ['type' => 'boolean', 'description' => 'Muss true sein (reichweitenstarke Aktion).'],
            ],
            'required' => ['from_id', 'to_id', 'confirm'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        if (($arguments['confirm'] ?? false) !== true) {
            return ToolResult::error('Ersetzen erfordert confirm=true (reichweitenstarke Aktion).', 'CONFIRM_REQUIRED');
        }

        $svc = app(GpService::class);
        $von = $svc->find((int) ($arguments['from_id'] ?? 0), $team);
        if ($von === null) {
            return ToolResult::error('from-GP nicht sichtbar/vorhanden.', 'NOT_FOUND');
        }
        if (! $von->isOwnedBy($team)) {
            return ToolResult::error('Ersetzen nur fürs Besitzer-Team des from-GP.', 'ACCESS_DENIED');
        }
        $nach = $svc->find((int) ($arguments['to_id'] ?? 0), $team);
        if ($nach === null) {
            return ToolResult::error('to-GP nicht sichtbar/vorhanden.', 'NOT_FOUND');
        }

        try {
            $res = $svc->ersetzeInRezepten($von, $nach);
        } catch (\RuntimeException $e) {
            return ToolResult::error($e->getMessage(), 'VALIDATION_ERROR');
        }

        return ToolResult::success([
            'from_id' => (int) $von->id,
            'to_id' => (int) $nach->id,
            'zeilen' => (int) ($res['zeilen'] ?? 0),
            'rezepte' => (int) ($res['rezepte'] ?? 0),
        ]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['foodalchemist', 'gp', 'ersetzen', 'destructive', 'recompute', 'write'],
            'read_only' => false, 'idempotent' => false, 'risk_level' => 'destructive',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'confirmation_required' => true,
            'side_effects' => ['updates'],
            'related_tools' => ['foodalchemist.gps.DELETE'],
            'examples' => ['Ersetze GP 12 durch GP 34 in allen Rezepten (confirm=true).'],
        ];
    }
}
