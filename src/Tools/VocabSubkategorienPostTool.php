<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\VocabularyService;

/** MCP-Steuerbarkeit · D13: Sub-Kategorie einer Warengruppe anlegen — Plattform-Basis, nur Master-Team, global. */
class VocabSubkategorienPostTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.vocab_subkategorien.POST';
    }

    public function getDescription(): string
    {
        return 'Legt eine globale Sub-Kategorie unter einer Warengruppe an (warengruppe_code, name). Nur Master-Team, Mandanten erhalten ACCESS_DENIED.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'warengruppe_code' => ['type' => 'string', 'description' => 'Code der Warengruppe.'],
                'name' => ['type' => 'string', 'description' => 'Sub-Kategorie-Name.'],
            ],
            'required' => ['warengruppe_code', 'name'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        $code = trim((string) ($arguments['warengruppe_code'] ?? ''));
        $name = trim((string) ($arguments['name'] ?? ''));
        if ($code === '' || $name === '') {
            return ToolResult::error('warengruppe_code und name sind Pflicht.', 'VALIDATION_ERROR');
        }

        try {
            $sub = app(VocabularyService::class)->createSubCategory($team, $code, $name);
        } catch (\RuntimeException $e) {
            // Plattform-Basis: Mandanten legen keine Einheiten/Warengruppen/Sub-Kategorien an (Typ, nicht Wortlaut)
            $code = $e instanceof \Platform\FoodAlchemist\Exceptions\GeerbtNurLesenException ? 'ACCESS_DENIED' : 'VALIDATION_ERROR';

            return ToolResult::error($e->getMessage(), $code);
        }

        return ToolResult::success(['id' => (int) $sub->id, 'warengruppe_code' => $code, 'name' => $name]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['foodalchemist', 'vocab', 'subkategorie', 'write'],
            'read_only' => false, 'idempotent' => false, 'risk_level' => 'write',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'side_effects' => ['creates'],
            'related_tools' => ['foodalchemist.vocab_subkategorien.PUT', 'foodalchemist.vocab_subkategorien.REORDER'],
            'examples' => ['Lege unter Warengruppe „13“ die Sub-Kategorie „Fermentiert“ an.'],
        ];
    }
}
