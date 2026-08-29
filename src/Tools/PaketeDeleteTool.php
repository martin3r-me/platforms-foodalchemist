<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Models\FoodAlchemistPaket;
use Platform\FoodAlchemist\Services\PaketService;

/**
 * MCP-Steuerbarkeit · D5d: Paket löschen (Soft-Delete). Pakete sind interne Bausteine (von Konzepten/
 * Speiseplänen referenzierbar) — destruktiv, `confirm:true` Pflicht.
 */
class PaketeDeleteTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.pakete.DELETE';
    }

    public function getDescription(): string
    {
        return 'Löscht ein team-eigenes Paket (Soft-Delete). Erfordert confirm=true. '
            . 'Prüfe vorher pakete.GET / Referenzen — Konzepte/Speisepläne können das Paket referenzieren.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'id' => ['type' => 'integer', 'description' => 'Paket-Id.'],
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
        $id = (int) ($arguments['id'] ?? 0);
        if (($guard = $this->guardOwned($team, FoodAlchemistPaket::class, $id, 'Paket')) !== null) {
            return $guard;
        }

        try {
            app(PaketService::class)->delete($team, $id);
        } catch (\RuntimeException $e) {
            return ToolResult::error($e->getMessage(), 'VALIDATION_ERROR');
        }

        return ToolResult::success(['id' => $id, 'deleted' => true]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['foodalchemist', 'paket', 'delete'],
            'read_only' => false, 'idempotent' => true, 'risk_level' => 'destructive',
            'confirmation_required' => true,
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'side_effects' => ['deletes'],
            'related_tools' => ['foodalchemist.pakete.PUT'],
            'examples' => ['Lösche Paket 12 (confirm=true).'],
        ];
    }
}
