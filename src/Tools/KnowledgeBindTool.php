<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\KnowledgeService;

/**
 * #469: bindet ein BESTEHENDES Wissens-Dokument an einen Einsatzort (Layer) —
 * auch globalen Seed / Vault-Kanon. Anders als knowledge.PUT (Inhalts-Edit, für
 * Vault-Docs gesperrt) ist Binden ein kuratorischer Akt: der Doc-Inhalt wird
 * nicht angefasst, die Bindung trägt team_id des Callers (tenancy-scoped). So
 * wird kuratiertes Kanon-Wissen an KI-Prompts/Bereiche verdrahtet.
 */
class KnowledgeBindTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.knowledge.BIND';
    }

    public function getDescription(): string
    {
        return 'Bindet ein bestehendes Wissens-Dokument (slug aus knowledge.SEARCH/LIST) an einen Einsatzort, '
            . 'damit es bei den passenden KI-Prompts mitgeladen wird. target_key = Slug eines Einsatzorts: '
            . 'ein Bereich (grob, z. B. gp/recipe/vk/concept) ODER ein einzelner Prompt (fein, z. B. recipe.geschmack, vk.plating). '
            . 'Wirkt auch für globalen Seed / Vault-Regelwerke (Binden ≠ Inhalt editieren). Idempotent.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'slug' => ['type' => 'string', 'description' => 'Slug des zu bindenden Dokuments'],
                'target_key' => ['type' => 'string', 'description' => 'Einsatzort-Slug: Bereich (gp/recipe/vk/concept/price/chat/signal) oder Prompt-Key (z. B. recipe.geschmack)'],
                'mode' => ['type' => 'string', 'enum' => ['always', 'discovery', 'grounding', 'reference'], 'default' => 'discovery'],
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
            $doc = app(KnowledgeService::class)->bindExisting(
                $team,
                (string) $arguments['slug'],
                (string) $arguments['target_key'],
                (string) ($arguments['mode'] ?? 'discovery'),
            );
        } catch (\RuntimeException $e) {
            $code = str_contains($e->getMessage(), 'nicht gefunden') ? 'NOT_FOUND' : 'VALIDATION_ERROR';

            return ToolResult::error($e->getMessage(), $code);
        }

        return ToolResult::success([
            'document' => ['slug' => $doc->slug, 'title' => $doc->title, 'category' => $doc->category],
            'bound_to' => (string) $arguments['target_key'],
            'note' => 'Bindung gesetzt (team-scoped). Wirkt sofort bei Prompts, deren Key/Bereich matcht.',
        ]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['foodalchemist', 'wissen', 'knowledge', 'binden', 'einsatzort', 'layer', 'mcp'],
            'read_only' => false, 'idempotent' => true, 'risk_level' => 'write',
            'requires_auth' => true, 'requires_team' => true,
            'side_effects' => ['creates'], 'cost_class' => 'local_db',
            'related_tools' => ['foodalchemist.knowledge.UNBIND', 'foodalchemist.knowledge.SEARCH', 'foodalchemist.settings.GET'],
            'examples' => ['Binde das Doc "regelwerk_grundprodukte" an den Einsatzort "gp.suggest"'],
        ];
    }
}
