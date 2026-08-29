<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\PresentationDesignService;

/** MCP-Steuerbarkeit · D12: aus einem Brief KI-CSS/Tokens für ein Präsentations-Design vorschlagen. */
class PresentationDesignsGenerateCssTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.presentation_designs.GENERATE_CSS';
    }

    public function getDescription(): string
    {
        return 'Erzeugt aus einem Gestaltungs-Brief einen KI-Vorschlag für Präsentations-CSS/Design-Tokens. '
            . 'Liefert den Vorschlag zurück (Übernahme via presentation_designs.PUT/POST).';
    }

    public function getSchema(): array
    {
        return ['type' => 'object', 'properties' => ['brief' => ['type' => 'string', 'description' => 'Gestaltungs-Brief (Stil/Marke/Anmutung).']], 'required' => ['brief']];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        $brief = trim((string) ($arguments['brief'] ?? ''));
        if ($brief === '') {
            return ToolResult::error('brief ist Pflicht.', 'VALIDATION_ERROR');
        }

        try {
            $res = app(PresentationDesignService::class)->generateCss($team, $brief);
        } catch (\RuntimeException $e) {
            return ToolResult::error($e->getMessage(), 'VALIDATION_ERROR');
        }

        return ToolResult::success(['brief' => $brief, 'vorschlag' => $res]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['foodalchemist', 'presentation', 'design', 'ki'],
            'read_only' => false, 'idempotent' => false, 'risk_level' => 'safe',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'llm',
            'side_effects' => [],
            'related_tools' => ['foodalchemist.presentation_designs.DUPLICATE'],
            'examples' => ['Schlag CSS für ein „minimalistisch, warm, editorial"-Design vor.'],
        ];
    }
}
