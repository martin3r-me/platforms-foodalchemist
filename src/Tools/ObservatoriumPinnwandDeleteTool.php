<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\ObservatoriumPinnwandService;

/** Eintrag von der Team-Pinnwand nehmen (weich gelöscht, eigenes Team, ab Kuratieren). */
class ObservatoriumPinnwandDeleteTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.observatorium_pinnwand.DELETE';
    }

    public function getDescription(): string
    {
        return 'Nimmt einen Eintrag von der Team-Pinnwand im Observatorium (pin_id aus foodalchemist.observatorium_pinnwand.GET). Weich gelöscht; die Meldung selbst bleibt.';
    }

    public function getSchema(): array
    {
        return ['type' => 'object', 'required' => ['pin_id'], 'properties' => ['pin_id' => ['type' => 'integer']]];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        try {
            app(ObservatoriumPinnwandService::class)->entfernen($team, (int) ($arguments['pin_id'] ?? 0));
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            return ToolResult::error('Eintrag nicht gefunden.', 'NOT_FOUND');
        }

        return ToolResult::success(['entfernt' => true]);
    }

    public function getMetadata(): array
    {
        return ['category' => 'action', 'tags' => ['foodalchemist', 'observatorium', 'pinnwand', 'write'], 'read_only' => false,
            'idempotent' => true, 'risk_level' => 'write', 'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'side_effects' => ['Eintrag von der Team-Pinnwand nehmen'], 'related_tools' => ['foodalchemist.observatorium_pinnwand.GET']];
    }
}
