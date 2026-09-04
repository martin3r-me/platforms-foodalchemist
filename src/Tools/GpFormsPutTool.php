<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\GpFormService;
use Platform\FoodAlchemist\Services\GpService;

/**
 * MCP-Steuerbarkeit · D1: Naturaleinheit-Form eines GP setzen/aktualisieren (Stück/Scheibe/…​ mit Gramm).
 * Upsert auf gp_id+form_slug; „stk" spiegelt piece_default_g. Nur team-eigene GPs (Katalog-Gate).
 */
class GpFormsPutTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.gp_forms.PUT';
    }

    public function getDescription(): string
    {
        return 'Setzt/aktualisiert eine Naturaleinheit-Form eines team-eigenen GP (Gramm je Stück/Scheibe/…). '
            . 'Erlaubte Formen: ' . implode(', ', GpFormService::formSlugs()) . '. „stk" pflegt zugleich das Stückgewicht.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'gp_id' => ['type' => 'integer', 'description' => 'GP-Id (team-eigen).'],
                'form_slug' => ['type' => 'string', 'enum' => GpFormService::formSlugs(), 'description' => 'Form-Slug.'],
                'gramm' => ['type' => 'number', 'description' => 'Gewicht je Einheit in Gramm (> 0).'],
            ],
            'required' => ['gp_id', 'form_slug', 'gramm'],
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
            $form = app(GpFormService::class)->setForm(
                $team, (int) $gp->id, (string) $arguments['form_slug'], (float) $arguments['gramm'], 'manual'
            );
        } catch (\RuntimeException $e) {
            return ToolResult::error($e->getMessage(), 'VALIDATION_ERROR');
        }

        return ToolResult::success([
            'gp_id' => (int) $gp->id,
            'form_slug' => $form->form_slug,
            'gramm' => (float) $form->gramm,
            'source' => $form->source,
        ]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['foodalchemist', 'gp', 'form', 'naturaleinheit', 'write'],
            'read_only' => false, 'idempotent' => true, 'risk_level' => 'write',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'side_effects' => ['updates'],
            'related_tools' => ['foodalchemist.gp_forms.DELETE', 'foodalchemist.gp_forms.ESTIMATE'],
            'examples' => ['Setze bei GP 123 die Form „scheibe" auf 25 g.'],
        ];
    }
}
