<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\GpService;

/**
 * MCP-Steuerbarkeit · D1: GP löschen (Soft-Delete). Destruktiv → `confirm:true` Pflicht.
 *
 * Nur team-eigene GPs (globale/geerbte → ACCESS_DENIED). Der Service blockt das Löschen, sobald
 * das GP referenziert wird (LAs, Rezept-Zeilen, Derivate, Merge/Ersatz) — dann VALIDATION_ERROR
 * mit Klartext (erst per gps.REPLACE umhängen). Platzhalter laufen über platzhalter.DELETE.
 */
class GpsDeleteTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.gps.DELETE';
    }

    public function getDescription(): string
    {
        return 'Löscht ein team-eigenes Grundprodukt (Soft-Delete). Erfordert confirm=true. '
            . 'Blockiert, solange das GP referenziert wird (dann erst gps.REPLACE). Platzhalter → platzhalter.DELETE.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'id' => ['type' => 'integer', 'description' => 'GP-Id (team-eigen).'],
                'confirm' => ['type' => 'boolean', 'description' => 'Muss true sein (destruktive Aktion).'],
            ],
            'required' => ['id', 'confirm'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        if (($arguments['confirm'] ?? false) !== true) {
            return ToolResult::error('Löschen erfordert confirm=true (destruktive Aktion).', 'CONFIRM_REQUIRED');
        }

        $gp = app(GpService::class)->find((int) ($arguments['id'] ?? 0), $team);
        if ($gp === null) {
            return ToolResult::error('GP nicht sichtbar/vorhanden.', 'NOT_FOUND');
        }
        if (! $gp->isOwnedBy($team)) {
            return ToolResult::error('GP gehört einem anderen/globalen Team — nur eigene GPs löschbar.', 'ACCESS_DENIED');
        }

        $id = (int) $gp->id;
        try {
            app(GpService::class)->deleteGp($gp);
        } catch (\RuntimeException $e) {
            return ToolResult::error($e->getMessage(), 'VALIDATION_ERROR');
        }

        return ToolResult::success(['id' => $id, 'deleted' => true]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['foodalchemist', 'gp', 'grundprodukt', 'loeschen', 'destructive', 'write'],
            'read_only' => false, 'idempotent' => false, 'risk_level' => 'destructive',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'confirmation_required' => true,
            'side_effects' => ['deletes'],
            'related_tools' => ['foodalchemist.gps.REPLACE', 'foodalchemist.platzhalter.DELETE'],
            'examples' => ['Lösche GP 123 (confirm=true).'],
        ];
    }
}
