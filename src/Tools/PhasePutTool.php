<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\PhaseService;

/**
 * R4.3: Phase setzen — Statusmaschine Kontext → Struktur → Befüllung → Kalkulation →
 * Freigabe an Foodbook/Konzept. „freigabe" bleibt menschlich (UI-only, analog
 * Rezept-Status-Regel) — das Tool blockt sie typisiert.
 */
class PhasePutTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.phase.PUT';
    }

    public function getDescription(): string
    {
        return 'Setzt die Arbeits-Phase eines Foodbooks/Konzepts: kontext|struktur|befuellung|kalkulation. '
            . '„freigabe" wird NICHT über MCP gesetzt (bleibt menschlich, Coverage-Gate in der UI). '
            . 'Die Phase ergänzt den Sichtbarkeits-Status (draft/aktiv), ersetzt ihn nicht.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'owner_type' => ['type' => 'string', 'enum' => ['foodbook', 'concept']],
                'owner_id' => ['type' => 'integer'],
                'phase' => ['type' => 'string', 'enum' => ['kontext', 'struktur', 'befuellung', 'kalkulation']],
            ],
            'required' => ['owner_type', 'owner_id', 'phase'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }

        try {
            $owner = app(PhaseService::class)->setPhase(
                $team,
                (string) $arguments['owner_type'],
                (int) $arguments['owner_id'],
                (string) $arguments['phase'],
                null,
                'mcp',
            );
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            return ToolResult::error('Owner nicht gefunden oder nicht team-sichtbar.', 'NOT_FOUND');
        } catch (\RuntimeException $e) {
            return ToolResult::error($e->getMessage(), 'VALIDATION_ERROR');
        }

        return ToolResult::success([
            'owner_type' => (string) $arguments['owner_type'],
            'owner_id' => (int) $arguments['owner_id'],
            'phase' => $owner->phase,
        ]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['foodalchemist', 'phase', 'workflow', 'planung', 'statusmaschine'],
            'read_only' => false, 'idempotent' => true, 'risk_level' => 'write',
            'requires_auth' => true, 'requires_team' => true,
            'side_effects' => ['updates'], 'cost_class' => 'local_db',
            'related_tools' => ['foodalchemist.coverage.GET', 'foodalchemist.planning.GET', 'foodalchemist.concepts.GET', 'foodalchemist.foodbook.GET'],
            'examples' => ['Setze Konzept 7 in die Phase Kalkulation', 'Foodbook 12 zurück in die Befüllung'],
        ];
    }
}
