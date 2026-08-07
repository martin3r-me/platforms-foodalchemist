<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\KnowledgeRoutingService;

/**
 * S1b: Wissens-Routing LESEN — welches KI-Feature lädt welche Wissens-Kategorie in welchem Modus.
 * Der Lese-Gegenpart zu knowledge_routings.PUT, damit man vor dem Ändern den Ist-Stand sieht.
 */
class KnowledgeRoutingsGetTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.knowledge_routings.GET';
    }

    public function getDescription(): string
    {
        return 'Listet das Wissens-Routing (welches KI-Feature lädt welche Wissens-Kategorie wie): pro '
            . 'feature × category der Lade-Modus (always | discovery | grounding | none) + Caps '
            . '(max_docs/max_chars_per_doc). discovery = wachsend & gedeckelt (skalierbar), always = kleine '
            . 'fixe Kuratier-Liste, none = bewusst leer, keine Route = search-only. Optional auf ein feature '
            . 'filtern. Ändern via foodalchemist.knowledge_routings.PUT.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'feature' => ['type' => 'string', 'description' => 'optional: nur dieses KI-Feature (z. B. ai_generate_recipe)'],
            ],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        $feature = ($f = trim((string) ($arguments['feature'] ?? ''))) !== '' ? $f : null;
        $routings = app(KnowledgeRoutingService::class)->list($feature);

        return ToolResult::success([
            'total' => count($routings),
            'modi' => KnowledgeRoutingService::MODES,
            'routings' => $routings,
        ]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'query',
            'tags' => ['foodalchemist', 'knowledge', 'routing', 'wissen', 'konfiguration'],
            'read_only' => true, 'idempotent' => true, 'risk_level' => 'safe',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'related_tools' => ['foodalchemist.knowledge_routings.PUT'],
            'examples' => ['Welche Wissens-Kategorien lädt ai_generate_recipe?', 'Zeig das komplette Wissens-Routing'],
        ];
    }
}
