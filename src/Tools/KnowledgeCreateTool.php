<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\KnowledgeService;

/**
 * #469 v3: neues Wissens-Dokument „von außen" anlegen (Trends/Know-how). Immer
 * INAKTIV (Quarantäne) + created_via='mcp'; ein Mensch aktiviert es im Browser,
 * erst dann wirkt es im KI-Kontext. Kategorie muss im Vokabular stehen
 * (foodalchemist.settings.GET / Browser). Optional Aliase + Einsatzort-Bindungen.
 */
class KnowledgeCreateTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.knowledge.POST';
    }

    public function getDescription(): string
    {
        return 'Legt ein neues Wissens-Dokument als ENTWURF an (inaktiv, created_via=mcp) — z. B. einen '
            . 'Trend oder Know-how-Baustein. Wirkt erst im KI-Kontext, wenn ein Mensch es im Wissens-Browser '
            . 'aktiviert. category muss ein bestehender Kategorie-Slug sein. Optional: aliases (Findbarkeit) '
            . 'und bind_layers (an Einsatzorte binden: target_key = Bereich/Prompt-Slug, mode). '
            . 'Vault-Regelwerke NICHT hier neu anlegen — die kommen aus dem Vault-Import.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'title' => ['type' => 'string'],
                'category' => ['type' => 'string', 'description' => 'Kategorie-Slug aus dem Vokabular, z. B. trend, domain, cross_cutting'],
                'content_md' => ['type' => 'string', 'description' => 'Inhalt als Markdown'],
                'aliases' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Begriffe, unter denen die KI das Doc findet'],
                'bind_layers' => [
                    'type' => 'array',
                    'description' => 'Einsatzort-Bindungen (optional)',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'target_key' => ['type' => 'string', 'description' => 'Slug eines Einsatzorts (Bereich wie gp/recipe/vk oder einzelner Prompt)'],
                            'mode' => ['type' => 'string', 'enum' => ['always', 'discovery', 'grounding', 'reference'], 'default' => 'discovery'],
                        ],
                        'required' => ['target_key'],
                    ],
                ],
            ],
            'required' => ['title', 'category'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }

        try {
            $doc = app(KnowledgeService::class)->create($team, [
                'title' => (string) $arguments['title'],
                'category' => (string) $arguments['category'],
                'content_md' => $arguments['content_md'] ?? '',
                'aliases' => $arguments['aliases'] ?? [],
                'bind_layers' => $arguments['bind_layers'] ?? [],
                'source' => 'mcp',
            ]);
        } catch (\RuntimeException $e) {
            return ToolResult::error($e->getMessage(), 'VALIDATION_ERROR');
        }

        return ToolResult::success([
            'document' => [
                'slug' => $doc->slug,
                'title' => $doc->title,
                'category' => $doc->category,
                'version' => (int) $doc->version,
                'active' => (bool) $doc->active,
                'created_via' => $doc->created_via,
            ],
            'note' => 'Entwurf (inaktiv). Aktivieren macht ein Mensch im Wissens-Browser — erst dann wirkt es in der KI.',
        ]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['foodalchemist', 'wissen', 'knowledge', 'anlegen', 'trend', 'mcp'],
            'read_only' => false, 'idempotent' => false, 'risk_level' => 'write',
            'requires_auth' => true, 'requires_team' => true,
            'side_effects' => ['creates'], 'cost_class' => 'local_db',
            'related_tools' => ['foodalchemist.knowledge.PUT', 'foodalchemist.knowledge.SEARCH', 'foodalchemist.knowledge.GET'],
            'examples' => ['Lege ein Wissens-Dokument zum Trend "Fermentierte Chili-Pasten" an'],
        ];
    }
}
