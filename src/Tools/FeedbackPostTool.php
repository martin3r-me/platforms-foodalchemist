<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\FeedbackService;

/**
 * R2.6 — Praxis-Feedback zu einem Gericht/Basisrezept anlegen (Quelle Küche/Kunde/
 * Event). created_via=mcp (Lineage). Küchen-Feedback trägt strukturierte Achsen.
 * Schreibt echtes Feedback (kein Draft-Quarantäne-Objekt — Feedback ist per se
 * eine Meinung, kein freizugebender Inhalt), bleibt aber team-eigen (D1).
 */
class FeedbackPostTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.recipe_feedback.POST';
    }

    public function getDescription(): string
    {
        return 'Legt Praxis-Feedback zu einem Gericht/Basisrezept an. quelle=kueche|kunde|event, '
            . 'score 1–5, optional Kommentar. Bei quelle=kueche zusätzlich die Achsen machbarkeit/'
            . 'aufwand/geschmack/gaeste_reaktion (1–5). Optionaler Kontext (Konzept/Event/Datum).';
    }

    public function getSchema(): array
    {
        $skala = ['type' => 'integer', 'minimum' => 1, 'maximum' => 5];

        return [
            'type' => 'object',
            'properties' => [
                'recipe_id' => ['type' => 'integer', 'description' => 'Gericht- oder Basisrezept-id'],
                'quelle' => ['type' => 'string', 'enum' => ['kueche', 'kunde', 'event']],
                'score' => array_merge($skala, ['description' => 'Gesamt-Score 1–5']),
                'machbarkeit' => $skala, 'aufwand' => $skala, 'geschmack' => $skala, 'gaeste_reaktion' => $skala,
                'comment' => ['type' => 'string'],
                'kontext_kind' => ['type' => 'string', 'enum' => ['concept', 'event']],
                'kontext_id' => ['type' => 'integer'],
                'kontext_datum' => ['type' => 'string', 'description' => 'YYYY-MM-DD'],
                'kontext_label' => ['type' => 'string'],
            ],
            'required' => ['recipe_id', 'quelle'],
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

        try {
            $f = app(FeedbackService::class)->erstelle($team, $recipeId, [
                ...$arguments,
                'created_via' => 'mcp',
            ]);
        } catch (\InvalidArgumentException $e) {
            return ToolResult::error($e->getMessage(), 'VALIDATION_ERROR');
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return ToolResult::error('Rezept nicht gefunden oder nicht im Team-Scope.', 'NOT_FOUND');
        }

        return ToolResult::success([
            'id' => $f->id,
            'recipe_id' => $f->recipe_id,
            'quelle' => $f->quelle->value,
            'score' => $f->score,
            'created_via' => $f->created_via,
        ], 'Feedback angelegt.');
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'read_only' => false,
            'idempotent' => false,
            'risk_level' => 'write',
            'side_effects' => ['creates'],
            'requires_auth' => true,
            'requires_team' => true,
            'cost_class' => 'local_db',
            'tags' => ['foodalchemist', 'feedback', 'bewertung', 'kueche', 'kunde', 'event'],
            'related_tools' => ['foodalchemist.recipe_feedback.SEARCH'],
            'examples' => [
                'Küchen-Feedback zu Gericht 812: Machbarkeit 4, Aufwand 2, Geschmack 5, „läuft super im Bankett"',
                'Kunden-Feedback zu Gericht 812: score 5',
            ],
        ];
    }
}
