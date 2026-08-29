<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\FoodbookService;

/**
 * MCP-Steuerbarkeit · D7: das Anzeigename-Wording aller Blöcke eines Kapitels aus der Wording-Kette
 * neu ableiten (Konzept → Standard → Name). Nur im Entwurf-Foodbook.
 */
class FoodbookKapitelWordingTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.foodbook_kapitel.WORDING_GENERATE';
    }

    public function getDescription(): string
    {
        return 'Leitet das Wording (Anzeigenamen) aller Blöcke eines Kapitels aus der Wording-Kette neu ab. '
            . 'Gibt die Anzahl aktualisierter Blöcke zurück.';
    }

    public function getSchema(): array
    {
        return ['type' => 'object', 'properties' => ['kapitel_id' => ['type' => 'integer', 'description' => 'Kapitel-Id.']], 'required' => ['kapitel_id']];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        $kapitelId = (int) ($arguments['kapitel_id'] ?? 0);
        if (($guard = $this->guardFoodbookEditable($team, $this->foodbookVonKapitel($team, $kapitelId))) !== null) {
            return $guard;
        }

        try {
            $count = app(FoodbookService::class)->kapitelWordingRegenerieren($team, $kapitelId);
        } catch (\RuntimeException $e) {
            return ToolResult::error($e->getMessage(), 'VALIDATION_ERROR');
        }

        return ToolResult::success(['kapitel_id' => $kapitelId, 'updated_blocks' => (int) $count]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['foodalchemist', 'foodbook', 'kapitel', 'wording', 'write'],
            'read_only' => false, 'idempotent' => true, 'risk_level' => 'write',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'side_effects' => ['updates'],
            'related_tools' => ['foodalchemist.foodbook_kapitel.KUNDENTEXT_GENERATE'],
            'examples' => ['Regeneriere das Wording von Kapitel 12.'],
        ];
    }
}
