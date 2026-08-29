<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\PaketService;

/** MCP-Steuerbarkeit · D5d: Paket duplizieren (Stamm + Gerichte, „(Kopie)"). */
class PaketeDuplicateTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.pakete.DUPLICATE';
    }

    public function getDescription(): string
    {
        return 'Dupliziert ein sichtbares Paket (Stamm + Gericht-Positionen) als team-eigene Kopie „(Kopie)".';
    }

    public function getSchema(): array
    {
        return ['type' => 'object', 'properties' => ['id' => ['type' => 'integer', 'description' => 'Quell-Paket-Id.']], 'required' => ['id']];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        $id = (int) ($arguments['id'] ?? 0);
        // Sichtbarkeit reicht (Kopie entsteht im eigenen Team); NOT_FOUND wenn unsichtbar.
        if (! \Platform\FoodAlchemist\Models\FoodAlchemistPaket::visibleToTeam($team)->whereKey($id)->exists()) {
            return ToolResult::error('Paket nicht sichtbar/vorhanden.', 'NOT_FOUND');
        }

        try {
            $neu = app(PaketService::class)->duplicate($team, $id);
        } catch (\RuntimeException $e) {
            return ToolResult::error($e->getMessage(), 'VALIDATION_ERROR');
        }

        return ToolResult::success(['paket' => $this->paketPayload($neu), 'source_id' => $id]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['foodalchemist', 'paket', 'write'],
            'read_only' => false, 'idempotent' => false, 'risk_level' => 'write',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'side_effects' => ['creates'],
            'related_tools' => ['foodalchemist.pakete.POST'],
            'examples' => ['Dupliziere Paket 12.'],
        ];
    }
}
