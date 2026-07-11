<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\KnowledgeService;

/**
 * #469 v3: bestehendes, NICHT Vault-verwaltetes Wissens-Dokument aktualisieren
 * (per slug). Inhalts-Änderung ⇒ version+1. Vault-Regelwerke (source_path gesetzt)
 * sind gesperrt — die pflegt der Vault-Import bzw. der Browser. Optional Aliase/
 * Bindungen ergänzen. active kann gesetzt werden (aktivieren bleibt bewusst auch
 * hier möglich, ist aber ein expliziter Schritt — Default beim Anlegen ist inaktiv).
 */
class KnowledgeUpdateTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.knowledge.PUT';
    }

    public function getDescription(): string
    {
        return 'Aktualisiert ein per MCP/Browser angelegtes Wissens-Dokument (slug aus knowledge.SEARCH/POST). '
            . 'Änderbar: title, category (Vokabular-Slug), content_md (⇒ version+1), active, aliases, bind_layers. '
            . 'Vault-verwaltete Dokumente sind gesperrt.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'slug' => ['type' => 'string'],
                'title' => ['type' => 'string'],
                'category' => ['type' => 'string'],
                'content_md' => ['type' => 'string'],
                'active' => ['type' => 'boolean'],
                'aliases' => ['type' => 'array', 'items' => ['type' => 'string']],
                'bind_layers' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'target_key' => ['type' => 'string'],
                            'mode' => ['type' => 'string', 'enum' => ['always', 'discovery', 'grounding', 'reference'], 'default' => 'discovery'],
                        ],
                        'required' => ['target_key'],
                    ],
                ],
            ],
            'required' => ['slug'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }

        $data = array_intersect_key($arguments, array_flip([
            'title', 'category', 'content_md', 'active', 'aliases', 'bind_layers',
        ]));

        try {
            $doc = app(KnowledgeService::class)->update($team, (string) $arguments['slug'], $data);
        } catch (\RuntimeException $e) {
            $code = str_contains($e->getMessage(), 'nicht gefunden') ? 'NOT_FOUND'
                : (str_contains($e->getMessage(), 'Vault-verwaltet') ? 'LOCKED' : 'VALIDATION_ERROR');

            return ToolResult::error($e->getMessage(), $code);
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
        ]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['foodalchemist', 'wissen', 'knowledge', 'bearbeiten', 'update', 'mcp'],
            'read_only' => false, 'idempotent' => false, 'risk_level' => 'write',
            'requires_auth' => true, 'requires_team' => true,
            'side_effects' => ['updates'], 'cost_class' => 'local_db',
            'related_tools' => ['foodalchemist.knowledge.POST', 'foodalchemist.knowledge.GET'],
            'examples' => ['Ergänze im Trend-Doc "fermentierte-chili-pasten" einen Abschnitt zu Anwendungen'],
        ];
    }
}
