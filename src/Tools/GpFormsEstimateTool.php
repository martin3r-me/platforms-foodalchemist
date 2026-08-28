<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\GpFormService;
use Platform\FoodAlchemist\Services\GpService;

/**
 * MCP-Steuerbarkeit · D1: KI schätzt die Naturaleinheit-Formen + Gramm eines GP (gp.zaehl_einheiten)
 * und persistiert sie als source=ki. Override-First: manuell gepflegte Formen bleiben unangetastet.
 * Nur team-eigene GPs (Katalog-Gate).
 */
class GpFormsEstimateTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.gp_forms.ESTIMATE';
    }

    public function getDescription(): string
    {
        return 'KI schätzt anwendbare Naturaleinheit-Formen (Stück/Scheibe/…) + Gramm für ein team-eigenes GP '
            . 'und schreibt sie (source=ki). Manuelle Formen bleiben unberührt (Override-First). '
            . 'Gibt die Zahl geschriebener Formen zurück.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => ['gp_id' => ['type' => 'integer', 'description' => 'GP-Id (team-eigen).']],
            'required' => ['gp_id'],
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
            $n = app(GpFormService::class)->estimateKi($team, (int) $gp->id);
        } catch (\RuntimeException $e) {
            return ToolResult::error($e->getMessage(), 'VALIDATION_ERROR');
        }

        return ToolResult::success(['gp_id' => (int) $gp->id, 'formen_geschrieben' => $n]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['foodalchemist', 'gp', 'form', 'ki', 'schaetzung', 'write'],
            'read_only' => false, 'idempotent' => false, 'risk_level' => 'write',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'llm',
            'side_effects' => ['updates'],
            'related_tools' => ['foodalchemist.gp_forms.PUT'],
            'examples' => ['Schätze die Stück-Formen für GP 123.'],
        ];
    }
}
