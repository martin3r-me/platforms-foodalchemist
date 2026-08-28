<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\GpFormService;
use Platform\FoodAlchemist\Services\GpService;

/**
 * MCP-Steuerbarkeit · D1: Naturaleinheit-Form eines GP entfernen. „stk" leert zugleich piece_default_g.
 * Nur team-eigene GPs (Katalog-Gate).
 */
class GpFormsDeleteTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.gp_forms.DELETE';
    }

    public function getDescription(): string
    {
        return 'Entfernt eine Naturaleinheit-Form eines team-eigenen GP. „stk" leert zugleich das Stückgewicht.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'gp_id' => ['type' => 'integer', 'description' => 'GP-Id (team-eigen).'],
                'form_slug' => ['type' => 'string', 'enum' => GpFormService::FORM_SLUGS, 'description' => 'Form-Slug.'],
            ],
            'required' => ['gp_id', 'form_slug'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }

        $gp = app(GpService::class)->find((int) ($arguments['gp_id'] ?? 0), $team);
        if ($gp === null) {
            return ToolResult::error('GP nicht sichtbar/vorhanden.', 'NOT_FOUND');
        }
        if (! $gp->isOwnedBy($team)) {
            return ToolResult::error('Formen pflegen nur fürs Besitzer-Team (Katalog-Aktion).', 'ACCESS_DENIED');
        }

        try {
            app(GpFormService::class)->removeForm($team, (int) $gp->id, (string) $arguments['form_slug']);
        } catch (\RuntimeException $e) {
            return ToolResult::error($e->getMessage(), 'VALIDATION_ERROR');
        }

        return ToolResult::success(['gp_id' => (int) $gp->id, 'form_slug' => (string) $arguments['form_slug'], 'removed' => true]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['foodalchemist', 'gp', 'form', 'naturaleinheit', 'write'],
            'read_only' => false, 'idempotent' => true, 'risk_level' => 'write',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'side_effects' => ['deletes'],
            'related_tools' => ['foodalchemist.gp_forms.PUT'],
            'examples' => ['Entferne bei GP 123 die Form „scheibe".'],
        ];
    }
}
