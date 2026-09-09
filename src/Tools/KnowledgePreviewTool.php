<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\Knowledge\KnowledgePreviewService;

class KnowledgePreviewTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string { return 'foodalchemist.knowledge.PREVIEW'; }

    public function getDescription(): string
    {
        return 'Zeigt für einen Arbeitsschritt und Auftrag die tatsächliche Wissensauswahl: Kanon, Retrieval, ausgelassene Quellen und Zeichen. Entspricht der Vorschau im Wissens-Browser; erstellt kein Rezept.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object', 'additionalProperties' => false,
            'properties' => [
                'prompt_key' => ['type' => 'string', 'description' => 'Arbeitsschritt aus der Prompt-Registry, z. B. recipe.generator oder vk.generator.'],
                'q' => ['type' => 'string', 'description' => 'Der konkrete Auftrag.'],
                'parameters' => ['type' => 'object', 'description' => 'Optionale fachliche Leitplanken wie level, occasion, sektor, saison, rezept_typ, kompositions_stil. Keine internen Steuerparameter.'],
            ],
            'required' => ['prompt_key', 'q'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        try {
            if (isset($arguments['parameters']) && ! is_array($arguments['parameters'])) {
                throw new \InvalidArgumentException('Leitplanken müssen als Objekt übergeben werden.');
            }
            return ToolResult::success(app(KnowledgePreviewService::class)->preview($team,
                (string) ($arguments['prompt_key'] ?? ''), (string) ($arguments['q'] ?? ''),
                is_array($arguments['parameters'] ?? null) ? $arguments['parameters'] : []));
        } catch (\InvalidArgumentException|\RuntimeException $exception) {
            return ToolResult::error($exception->getMessage(), 'KNOWLEDGE_PREVIEW_ERROR');
        }
    }

    public function getMetadata(): array
    {
        return ['category' => 'query', 'tags' => ['foodalchemist', 'wissen', 'preview'],
            'read_only' => true, 'idempotent' => true, 'risk_level' => 'safe',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'related_tools' => ['foodalchemist.knowledge.SEARCH', 'foodalchemist.knowledge.GET']];
    }
}
