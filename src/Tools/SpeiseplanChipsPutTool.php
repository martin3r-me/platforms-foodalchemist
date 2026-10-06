<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\SpeiseplanVorgabenService;

/**
 * Spec 59 · MCP im Lockstep: Speiseplan-Prüf-Chip ändern. Kein DELETE — Lösch-Schutz wie bei
 * Posten (is_active=false legt still, Pläne referenzieren den Chip per id).
 */
class SpeiseplanChipsPutTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    private const FELDER = ['label', 'kriterien', 'default_min', 'default_max', 'sort_order', 'is_active'];

    public function getName(): string
    {
        return 'foodalchemist.speiseplan_chips.PUT';
    }

    public function getDescription(): string
    {
        return 'Ändert einen team-eigenen Speiseplan-Chip (felder: label, kriterien, default_min, default_max, '
            . 'sort_order, is_active). Löschen gibt es nicht — is_active=false legt den Chip still.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'id' => ['type' => 'integer', 'description' => 'Chip-Id.'],
                'felder' => ['type' => 'object', 'description' => 'Zu ändernde Felder (Allow-List).'],
            ],
            'required' => ['id', 'felder'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        $felder = $arguments['felder'] ?? null;
        $in = is_array($felder) ? array_intersect_key($felder, array_flip(self::FELDER)) : [];
        if ($in === []) {
            return ToolResult::error('felder braucht mindestens eines von: ' . implode(', ', self::FELDER) . '.', 'VALIDATION_ERROR');
        }

        try {
            $chip = app(SpeiseplanVorgabenService::class)->chipAendern($team, (int) ($arguments['id'] ?? 0), $in);
        } catch (\RuntimeException $e) {
            return ToolResult::error($e->getMessage(), 'VALIDATION_ERROR');
        }

        return ToolResult::success(['id' => (int) $chip->id, 'updated' => array_keys($in)]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['foodalchemist', 'speiseplan', 'vorgaben', 'write'],
            'read_only' => false, 'idempotent' => true, 'risk_level' => 'write',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'side_effects' => ['updates'],
            'related_tools' => ['foodalchemist.speiseplan_chips.GET', 'foodalchemist.speiseplan_chips.POST'],
            'examples' => ['Setze beim Chip „Vegan“ den Standardwert auf mind. 2.'],
        ];
    }
}
