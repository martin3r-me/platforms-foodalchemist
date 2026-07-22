<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\KnowledgeService;

/**
 * #469: löst eine Layer-Bindung eines bestehenden Dokuments — aber NUR eine
 * team-eigene Bindung (globale/Fremd-Bindungen bleiben unberührt). Gegenstück
 * zu knowledge.BIND. Soft-Delete, idempotent.
 */
class KnowledgeUnbindTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.knowledge.UNBIND';
    }

    public function getDescription(): string
    {
        return 'Löst eine Einsatzort-Bindung eines Wissens-Dokuments (Gegenstück zu knowledge.BIND). '
            . 'Entfernt nur team-eigene Bindungen. Wenn keine passende (aktive) Bindung existiert, passiert nichts (idempotent).';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'slug' => ['type' => 'string', 'description' => 'Slug des Dokuments'],
                'target_key' => ['type' => 'string', 'description' => 'Einsatzort-Slug, dessen Bindung gelöst werden soll'],
            ],
            'required' => ['slug', 'target_key'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }

        try {
            $removed = app(KnowledgeService::class)->unbindExisting(
                $team,
                (string) $arguments['slug'],
                (string) $arguments['target_key'],
            );
        } catch (\RuntimeException $e) {
            $code = str_contains($e->getMessage(), 'nicht gefunden') ? 'NOT_FOUND' : 'VALIDATION_ERROR';

            return ToolResult::error($e->getMessage(), $code);
        }

        return ToolResult::success([
            'slug' => (string) $arguments['slug'],
            'target_key' => (string) $arguments['target_key'],
            'removed' => $removed,
            'note' => $removed ? 'Bindung gelöst.' : 'Keine passende team-eigene Bindung gefunden — nichts geändert.',
        ]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['foodalchemist', 'wissen', 'knowledge', 'binden', 'loesen', 'einsatzort', 'layer', 'mcp'],
            'read_only' => false, 'idempotent' => true, 'risk_level' => 'write',
            'requires_auth' => true, 'requires_team' => true,
            'side_effects' => ['deletes'], 'cost_class' => 'local_db',
            'related_tools' => ['foodalchemist.knowledge.BIND', 'foodalchemist.knowledge.SEARCH'],
            'examples' => ['Löse die Bindung von "regelwerk_grundprodukte" am Einsatzort "gp.suggest"'],
        ];
    }
}
