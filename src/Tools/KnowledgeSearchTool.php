<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\Ai\KnowledgeContextService;

/**
 * Phase K: Wissens-Discovery für externe LLM-Clients — 836 Dokumente aus der
 * Cooking-Jarvis-Wissensbasis (Mengen-Defaults, Substitutionen, Techniken,
 * Domains, Pairings), gesynct nach foodalchemist_knowledge_documents.
 */
class KnowledgeSearchTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.knowledge.SEARCH';
    }

    public function getDescription(): string
    {
        return 'Durchsucht die Catering-Wissensbasis (Techniken, Mengen-Defaults, Substitutionen, '
            . 'Lebensmittel-Domains, Flavor-Pairings) nach Stichworten. Liefert slug/titel/kategorie — '
            . 'Volltext via foodalchemist.knowledge.GET. Vor Rezept-Kreation IMMER relevantes Wissen laden.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'q' => ['type' => 'string', 'description' => 'Suchbegriffe, z. B. "Mengen Buffet" oder "Substitution Sahne"'],
                'category' => ['type' => 'string', 'enum' => ['cross_cutting', 'domain', 'pairing', 'regelwerk', 'trend', 'niveau', 'kueche', 'skill'], 'description' => 'Optionaler Filter (skill = MCP-Workflows)'],
                'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 50, 'default' => 10],
            ],
            'required' => ['q'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }

        $treffer = app(KnowledgeContextService::class)->searchDocuments(
            (string) $arguments['q'],
            isset($arguments['category']) ? (string) $arguments['category'] : null,
            (int) ($arguments['limit'] ?? 10),
        );

        return ToolResult::success(['total' => count($treffer), 'documents' => $treffer]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'query',
            'tags' => ['foodalchemist', 'wissen', 'knowledge', 'techniken', 'mengen', 'substitution', 'search'],
            'read_only' => true,
            'idempotent' => true,
            'risk_level' => 'safe',
            'requires_auth' => true,
            'requires_team' => true,
            'cost_class' => 'local_db',
            'related_tools' => ['foodalchemist.knowledge.GET', 'foodalchemist.pairings.GET'],
            'examples' => ['Welche Mengen-Defaults gelten für Buffets?', 'Suche Wissen zu Substitution von Sahne'],
        ];
    }
}
