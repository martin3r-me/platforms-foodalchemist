<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\PaketService;

/** MCP-Steuerbarkeit · D5d: Paket-Detail (Stamm + Gericht-Positionen). Read-only. */
class PaketeGetTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.pakete.GET';
    }

    public function getDescription(): string
    {
        return 'Liefert ein (sichtbares) Paket mit Stammdaten, Preis-Modus und seinen Gericht-Positionen. '
            . 'Paket-Ids werden von concept_slots.PUT (package_id) und foodbook_blocks referenziert.';
    }

    public function getSchema(): array
    {
        return ['type' => 'object', 'properties' => ['id' => ['type' => 'integer', 'description' => 'Paket-Id.']], 'required' => ['id']];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        $paket = app(PaketService::class)->detail($team, (int) ($arguments['id'] ?? 0));
        if ($paket === null) {
            return ToolResult::error('Paket nicht sichtbar/vorhanden.', 'NOT_FOUND');
        }

        return ToolResult::success(['paket' => $this->paketPayload($paket, true)]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'read',
            'tags' => ['foodalchemist', 'paket', 'read'],
            'read_only' => true, 'idempotent' => true, 'risk_level' => 'safe',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'side_effects' => [],
            'related_tools' => ['foodalchemist.pakete.LIST', 'foodalchemist.pakete.PUT'],
            'examples' => ['Zeig mir Paket 12 mit seinen Gerichten.'],
        ];
    }
}
