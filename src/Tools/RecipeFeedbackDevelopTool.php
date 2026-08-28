<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeFeedback;
use Platform\FoodAlchemist\Services\FeedbackService;

/**
 * MCP-Steuerbarkeit · D2: Aus einem Praxis-Feedback eine Rezept-Weiterentwicklung erzeugen
 * (neue Draft-Iteration). Team-eigen. Ergebnis ist ein Entwurf (bleibt Vorschlag bis Freigabe).
 */
class RecipeFeedbackDevelopTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.recipe_feedback.DEVELOP';
    }

    public function getDescription(): string
    {
        return 'Erzeugt aus einem team-eigenen Feedback eine Rezept-Weiterentwicklung (neue Draft-Iteration). '
            . 'Liefert das neue Rezept (Status draft).';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => ['feedback_id' => ['type' => 'integer', 'description' => 'Feedback-Id (team-eigen).']],
            'required' => ['feedback_id'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }

        $id = (int) ($arguments['feedback_id'] ?? 0);
        if (! FoodAlchemistRecipeFeedback::where('team_id', $team->id)->whereKey($id)->exists()) {
            return ToolResult::error('Feedback nicht vorhanden oder nicht team-eigen.', 'NOT_FOUND');
        }

        try {
            $neu = app(FeedbackService::class)->weiterentwickeln($team, $id, 'mcp');
        } catch (\RuntimeException $e) {
            return ToolResult::error($e->getMessage(), 'VALIDATION_ERROR');
        }

        return ToolResult::success([
            'feedback_id' => $id,
            'neues_rezept_id' => (int) $neu->id,
            'name' => $neu->name,
            'status' => $this->statusWert($neu),
        ]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['foodalchemist', 'recipe', 'feedback', 'weiterentwicklung', 'write'],
            'read_only' => false, 'idempotent' => false, 'risk_level' => 'write',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'llm',
            'side_effects' => ['creates'],
            'related_tools' => ['foodalchemist.recipe_feedback.POST', 'foodalchemist.recipe_feedback.DELETE'],
            'examples' => ['Entwickle Rezept aus Feedback 55 weiter.'],
        ];
    }
}
