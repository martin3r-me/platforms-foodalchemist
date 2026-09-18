<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\Ai\KnowledgeEmbeddingService;

/**
 * Briefing Zutaten-Bulk-Import, 2026-09-18: die Kontrolle für die blockweise Aktivierung.
 * Ein Dossier ist nach `POST`/`IMPORT` lexikalisch sofort auffindbar, aber ohne Embedding
 * semantisch unsichtbar (Regelwerk Zutaten-Dossier §8) — dieses Tool sagt, wie viele aktive
 * Dossiers (optional gefiltert auf `category`/Slug-`prefix`) das Embedding schon nachgezogen
 * haben und wie viele noch fehlen, plus eine Stichprobe der fehlenden Slugs. Rein lesend.
 */
class KnowledgeEmbedStatusTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.knowledge.EMBED_STATUS';
    }

    public function getDescription(): string
    {
        return 'Kontrolle nach blockweisem SET_ACTIVE: wie viele aktive Dossiers (optional '
            . 'gefiltert auf category und/oder Slug-prefix) haben ein Embedding, wie viele noch '
            . 'nicht (semantisch unsichtbar trotz aktiv), dazu inaktiv-Zahl und bis zu 20 Slugs ohne '
            . 'Embedding als Stichprobe. Rein lesend, kein Team-Bezug (Embeddings sind ueber Team- '
            . 'Partitionen hinweg gezaehlt).';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object', 'additionalProperties' => false,
            'properties' => [
                'category' => ['type' => 'string', 'description' => 'Optional: nur diese Wissens-Kategorie, z. B. "zutat".'],
                'prefix' => ['type' => 'string', 'description' => 'Optional: nur Slugs mit diesem Praefix, z. B. "zutat.acai".'],
            ],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        $category = is_string($arguments['category'] ?? null) && trim($arguments['category']) !== '' ? trim($arguments['category']) : null;
        $prefix = is_string($arguments['prefix'] ?? null) && trim($arguments['prefix']) !== '' ? trim($arguments['prefix']) : null;

        $status = app(KnowledgeEmbeddingService::class)->embedStatus($category, $prefix);

        return ToolResult::success($status);
    }

    public function getMetadata(): array
    {
        return ['category' => 'query', 'tags' => ['foodalchemist', 'wissen', 'embedding', 'kontrolle', 'bulk'],
            'read_only' => true, 'idempotent' => true, 'risk_level' => 'low',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'related_tools' => ['foodalchemist.knowledge.SET_ACTIVE', 'foodalchemist.knowledge.IMPORT']];
    }
}
