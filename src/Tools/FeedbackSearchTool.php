<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\FeedbackService;

/**
 * R2.6 — Praxis-Feedback eines Gerichts/Basisrezepts lesen (read-only Aggregat):
 * Ø-Score, Count, Verteilung je Quelle (Küche/Kunde/Event) + jüngste Einträge.
 */
class FeedbackSearchTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.recipe_feedback.SEARCH';
    }

    public function getDescription(): string
    {
        return 'Liest das Praxis-Feedback eines Gerichts oder Basisrezepts (read-only): '
            . 'Ø-Score, Anzahl, Verteilung je Quelle (kueche|kunde|event) und die jüngsten '
            . 'Einträge inkl. Kommentar. Erwartet recipe_id.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'recipe_id' => ['type' => 'integer', 'description' => 'Gericht- oder Basisrezept-id'],
                'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 50, 'default' => 20],
            ],
            'required' => ['recipe_id'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        $recipeId = (int) ($arguments['recipe_id'] ?? 0);
        if ($recipeId <= 0) {
            return ToolResult::error('recipe_id ist Pflicht.', 'VALIDATION_ERROR');
        }

        $svc = app(FeedbackService::class);
        $agg = $svc->aggregat($team, $recipeId);
        $limit = min(50, max(1, (int) ($arguments['limit'] ?? 20)));

        return ToolResult::success([
            'recipe_id' => $recipeId,
            'avg_score' => $agg['avg'],
            'count' => $agg['count'],
            'per_source' => $agg['per_source'],
            'feedback' => $svc->fuerRezept($team, $recipeId, $limit)->map(fn ($f) => [
                'id' => $f->id,
                'quelle' => $f->quelle->value,
                'score' => $f->score,
                'machbarkeit' => $f->machbarkeit, 'aufwand' => $f->aufwand,
                'geschmack' => $f->geschmack, 'gaeste_reaktion' => $f->gaeste_reaktion,
                'comment' => $f->comment,
                'kontext' => $f->kontext_label ?? $f->kontext_kind,
                'datum' => $f->created_at?->format('Y-m-d'),
            ])->all(),
        ]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'query',
            'read_only' => true,
            'idempotent' => true,
            'risk_level' => 'safe',
            'requires_auth' => true,
            'requires_team' => true,
            'cost_class' => 'local_db',
            'tags' => ['foodalchemist', 'feedback', 'bewertung', 'rezept', 'gericht', 'kueche', 'search'],
            'related_tools' => ['foodalchemist.recipe_feedback.POST', 'foodalchemist.verkaufsrezepte.SEARCH'],
            'examples' => ['Wie ist das Küchen-Feedback zu Gericht 812?', 'Ø-Score von Rezept 145'],
        ];
    }
}
