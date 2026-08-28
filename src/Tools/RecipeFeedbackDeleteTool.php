<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeFeedback;
use Platform\FoodAlchemist\Services\FeedbackService;

/**
 * MCP-Steuerbarkeit · D2: Praxis-Feedback (Küche/Kunde/Event) zu einem Rezept löschen. Team-eigen.
 */
class RecipeFeedbackDeleteTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.recipe_feedback.DELETE';
    }

    public function getDescription(): string
    {
        return 'Löscht ein team-eigenes Praxis-Feedback zu einem Rezept (per feedback_id).';
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

        app(FeedbackService::class)->loeschen($team, $id);

        return ToolResult::success(['feedback_id' => $id, 'deleted' => true]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['foodalchemist', 'recipe', 'feedback', 'write'],
            'read_only' => false, 'idempotent' => true, 'risk_level' => 'write',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'side_effects' => ['deletes'],
            'related_tools' => ['foodalchemist.recipe_feedback.POST', 'foodalchemist.recipe_feedback.DEVELOP'],
            'examples' => ['Lösche Feedback 55.'],
        ];
    }
}
