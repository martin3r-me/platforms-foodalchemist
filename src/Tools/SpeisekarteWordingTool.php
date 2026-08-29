<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Models\FoodAlchemistSpeisekarte;
use Platform\FoodAlchemist\Services\SpeisekarteService;

/**
 * MCP-Steuerbarkeit · D8: das Anzeigename-Wording aller Positionen einer Speisekarte aus der
 * Wording-Kette neu ableiten (Concept → Standard → Name). Gibt die Anzahl aktualisierter Positionen zurück.
 */
class SpeisekarteWordingTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.speisekarte_wording.GENERATE';
    }

    public function getDescription(): string
    {
        return 'Leitet das Wording (Anzeigenamen) aller Positionen einer team-eigenen Speisekarte aus der Wording-Kette neu ab.';
    }

    public function getSchema(): array
    {
        return ['type' => 'object', 'properties' => ['speisekarte_id' => ['type' => 'integer', 'description' => 'Speisekarte-Id.']], 'required' => ['speisekarte_id']];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        $id = (int) ($arguments['speisekarte_id'] ?? 0);
        if (($guard = $this->guardOwned($team, FoodAlchemistSpeisekarte::class, $id, 'Speisekarte')) !== null) {
            return $guard;
        }

        try {
            $count = app(SpeisekarteService::class)->speisekarteWordingRegenerieren($team, $id);
        } catch (\RuntimeException $e) {
            return ToolResult::error($e->getMessage(), 'VALIDATION_ERROR');
        }

        return ToolResult::success(['speisekarte_id' => $id, 'updated_positions' => (int) $count]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['foodalchemist', 'speisekarte', 'wording', 'write'],
            'read_only' => false, 'idempotent' => true, 'risk_level' => 'write',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'side_effects' => ['updates'],
            'related_tools' => ['foodalchemist.speisekarte_positionen.PUT'],
            'examples' => ['Regeneriere das Wording von Speisekarte 3.'],
        ];
    }
}
