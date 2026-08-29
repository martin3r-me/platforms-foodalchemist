<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\VocabularyService;

/** MCP-Steuerbarkeit · D13: Sub-Kategorien einer Warengruppe neu ordnen (nach Namen). */
class VocabSubkategorienReorderTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.vocab_subkategorien.REORDER';
    }

    public function getDescription(): string
    {
        return 'Ordnet die Sub-Kategorien einer Warengruppe neu (warengruppe_code, names in Zielreihenfolge).';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'warengruppe_code' => ['type' => 'string', 'description' => 'Code der Warengruppe.'],
                'names' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Sub-Kategorie-Namen in Zielreihenfolge.'],
            ],
            'required' => ['warengruppe_code', 'names'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        $code = trim((string) ($arguments['warengruppe_code'] ?? ''));
        $names = $arguments['names'] ?? null;
        if ($code === '' || ! is_array($names) || $names === []) {
            return ToolResult::error('warengruppe_code und names (nicht-leer) sind Pflicht.', 'VALIDATION_ERROR');
        }

        try {
            app(VocabularyService::class)->reorderSubCategories($team, $code, array_map('strval', $names));
        } catch (\RuntimeException $e) {
            return ToolResult::error($e->getMessage(), 'VALIDATION_ERROR');
        }

        return ToolResult::success(['warengruppe_code' => $code, 'names' => array_map('strval', $names)]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['foodalchemist', 'vocab', 'subkategorie', 'reorder', 'write'],
            'read_only' => false, 'idempotent' => true, 'risk_level' => 'write',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'side_effects' => ['updates'],
            'related_tools' => ['foodalchemist.vocab_subkategorien.PUT'],
            'examples' => ['Ordne die Sub-Kategorien von WG 13 neu.'],
        ];
    }
}
