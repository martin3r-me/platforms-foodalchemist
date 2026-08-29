<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\PresentationDesignService;

/** MCP-Steuerbarkeit · D12: ein Präsentations-Design duplizieren (aus eigenem Design oder Builtin). */
class PresentationDesignsDuplicateTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.presentation_designs.DUPLICATE';
    }

    public function getDescription(): string
    {
        return 'Dupliziert ein Präsentations-Design (source = Slug eines eigenen Designs oder eines Builtins) als team-eigene Kopie (optional name).';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'source' => ['type' => 'string', 'description' => 'Quell-Design (Slug: eigenes Design oder Builtin, z.B. editorial).'],
                'name' => ['type' => 'string', 'description' => 'Optionaler Name der Kopie.'],
            ],
            'required' => ['source'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        $source = trim((string) ($arguments['source'] ?? ''));
        if ($source === '') {
            return ToolResult::error('source ist Pflicht.', 'VALIDATION_ERROR');
        }

        try {
            $design = app(PresentationDesignService::class)->duplicate($team, $source, ($n = trim((string) ($arguments['name'] ?? ''))) !== '' ? $n : null);
        } catch (\RuntimeException $e) {
            return ToolResult::error($e->getMessage(), 'VALIDATION_ERROR');
        }

        return ToolResult::success(['id' => (int) $design->id, 'slug' => $design->slug ?? null, 'name' => $design->name]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['foodalchemist', 'presentation', 'design', 'write'],
            'read_only' => false, 'idempotent' => false, 'risk_level' => 'write',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'side_effects' => ['creates'],
            'related_tools' => ['foodalchemist.presentation_designs.GENERATE_CSS', 'foodalchemist.presentation_designs.SEARCH'],
            'examples' => ['Dupliziere das Design „editorial" als „Sommer-Look".'],
        ];
    }
}
