<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Enums\GpStatus;
use Platform\FoodAlchemist\Services\GpService;

/**
 * MCP-Steuerbarkeit · D1: GP-Status setzen (approved|tentative|review|rejected).
 *
 * Nur team-eigene GPs (globale/geerbte read-only → ACCESS_DENIED). `merged` ist gesperrt
 * (System-Zustand des Merge-Werkzeugs). Freigabe (approved) stößt den Konformitäts-Check
 * gegen das GP-Regelwerk an (Schicht 3, best-effort).
 */
class GpsStatusTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    private const ERLAUBT = ['approved', 'tentative', 'review', 'rejected'];

    public function getName(): string
    {
        return 'foodalchemist.gps.STATUS';
    }

    public function getDescription(): string
    {
        return 'Setzt den Status eines team-eigenen Grundprodukts: approved|tentative|review|rejected. '
            . '`merged` ist nicht erlaubt (nur Merge-Werkzeug). approved löst den Regelwerk-Konformitäts-Check aus.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'id' => ['type' => 'integer', 'description' => 'GP-Id (team-eigen).'],
                'status' => ['type' => 'string', 'enum' => self::ERLAUBT, 'description' => 'Neuer Status.'],
            ],
            'required' => ['id', 'status'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }

        $statusWert = (string) ($arguments['status'] ?? '');
        if (! in_array($statusWert, self::ERLAUBT, true) || ($status = GpStatus::tryFrom($statusWert)) === null) {
            return ToolResult::error('status muss einer von: ' . implode(', ', self::ERLAUBT) . ' sein.', 'VALIDATION_ERROR');
        }

        $gp = app(GpService::class)->find((int) ($arguments['id'] ?? 0), $team);
        if ($gp === null) {
            return ToolResult::error('GP nicht sichtbar/vorhanden.', 'NOT_FOUND');
        }
        if (! $gp->isOwnedBy($team)) {
            return ToolResult::error('GP gehört einem anderen/globalen Team — nur eigene GPs editierbar.', 'ACCESS_DENIED');
        }

        try {
            $gp = app(GpService::class)->setStatus($gp, $status);
        } catch (\RuntimeException $e) {
            return ToolResult::error($e->getMessage(), 'VALIDATION_ERROR');
        }

        return ToolResult::success([
            'id' => (int) $gp->id,
            'status' => $gp->status instanceof \BackedEnum ? $gp->status->value : (string) $gp->status,
        ]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['foodalchemist', 'gp', 'grundprodukt', 'status', 'freigabe', 'write'],
            'read_only' => false, 'idempotent' => true, 'risk_level' => 'write',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'side_effects' => ['updates'],
            'related_tools' => ['foodalchemist.gps.PUT', 'foodalchemist.gps.GET'],
            'examples' => ['Gib GP 123 frei (approved).'],
        ];
    }
}
