<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\VocabularyService;

/** MCP-Steuerbarkeit · D13: Sub-Kategorie einer Warengruppe umbenennen (team-eigen, propagiert auf GPs). */
class VocabSubkategorienPutTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.vocab_subkategorien.PUT';
    }

    public function getDescription(): string
    {
        return 'Benennt eine Sub-Kategorie um (warengruppe_code, alt, neu). Gibt die Anzahl angepasster GPs zurück.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'warengruppe_code' => ['type' => 'string', 'description' => 'Code der Warengruppe.'],
                'alt' => ['type' => 'string', 'description' => 'Bisheriger Name.'],
                'neu' => ['type' => 'string', 'description' => 'Neuer Name.'],
            ],
            'required' => ['warengruppe_code', 'alt', 'neu'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        $code = trim((string) ($arguments['warengruppe_code'] ?? ''));
        $alt = trim((string) ($arguments['alt'] ?? ''));
        $neu = trim((string) ($arguments['neu'] ?? ''));
        if ($code === '' || $alt === '' || $neu === '') {
            return ToolResult::error('warengruppe_code, alt und neu sind Pflicht.', 'VALIDATION_ERROR');
        }

        try {
            $count = app(VocabularyService::class)->renameSubCategory($team, $code, $alt, $neu);
        } catch (\RuntimeException $e) {
            return ToolResult::error($e->getMessage(), 'VALIDATION_ERROR');
        }

        return ToolResult::success(['warengruppe_code' => $code, 'alt' => $alt, 'neu' => $neu, 'angepasste_gps' => (int) $count]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['foodalchemist', 'vocab', 'subkategorie', 'write'],
            'read_only' => false, 'idempotent' => true, 'risk_level' => 'write',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'side_effects' => ['updates'],
            'related_tools' => ['foodalchemist.vocab_subkategorien.REORDER'],
            'examples' => ['Benenne die Sub-Kategorie „Pickles“ in „Fermentiert“ um.'],
        ];
    }
}
