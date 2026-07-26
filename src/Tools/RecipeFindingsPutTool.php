<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\RecipeFindingService;

/**
 * Spec 21 · S5a — die menschliche Entscheidung an einem abgelegten KI-Befund.
 *
 * **Ändert das Rezept nicht.** `verwerfen` stellt den Befund dauerhaft ruhig (ein
 * Folgelauf öffnet ihn nicht wieder und er wird kein Signal); `uebernommen` ist der
 * Stempel „ist eingearbeitet". Das tatsächliche Anwenden bleibt der Copilot-Pfad
 * (`RecipeReviewService::uebernehmen`, UI) — ein Tool, das nebenbei Zutaten
 * umschreibt, wäre ein zweiter Schreibweg auf die Rezeptur.
 */
class RecipeFindingsPutTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.recipe_findings.PUT';
    }

    public function getDescription(): string
    {
        return 'Entscheidet über einen abgelegten KI-Befund am Rezept: verworfen (bewusst liegenlassen — '
            . 'wird nicht wieder gemeldet und nie Signal) oder uebernommen (ist eingearbeitet). '
            . 'Ändert die Rezeptur NICHT.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'finding_id' => ['type' => 'integer'],
                'status' => ['type' => 'string', 'enum' => ['verworfen', 'uebernommen']],
            ],
            'required' => ['finding_id', 'status'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }

        try {
            $zeile = app(RecipeFindingService::class)
                ->entscheide($team, (int) $arguments['finding_id'], (string) $arguments['status']);
        } catch (\InvalidArgumentException $e) {
            return ToolResult::error($e->getMessage(), 'VALIDATION_ERROR');
        } catch (\Throwable $e) {
            return ToolResult::error('Befund nicht gefunden.', 'NOT_FOUND');
        }

        return ToolResult::success([
            'finding_id' => $zeile->id,
            'recipe_id' => $zeile->recipe_id,
            'status' => $zeile->status,
            'decided_at' => (string) $zeile->decided_at,
        ]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['foodalchemist', 'recipe', 'copilot', 'befund', 'status'],
            'read_only' => false, 'idempotent' => true, 'risk_level' => 'write',
            'requires_auth' => true, 'requires_team' => true,
            'side_effects' => ['updates'], 'cost_class' => 'local_db',
            'related_tools' => ['foodalchemist.recipe_findings.SEARCH'],
            'examples' => ['Verwirf Befund 7', 'Markiere Befund 7 als eingearbeitet'],
        ];
    }
}
