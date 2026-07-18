<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Services\ProcessAnchorService;

/**
 * 05·P5 (MCP-Lockstep) — Prozessanker eines Rezepts deterministisch aus dem
 * Zubereitungstext erden (roestaromen/karamell/rauch/ferment). apply=false =
 * Vorschau (dry-run), apply=true schreibt (source='parser'; fremde manual/ki/
 * auto-Anker bleiben unangetastet). Kein Marker im Text → kein Anker.
 */
class ProcessAnchorsGroundTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.process_anchors.GROUND';
    }

    public function getDescription(): string
    {
        return 'Erdet die Prozess-/Kocharomen-Anker (roestaromen/karamell/rauch/ferment) eines '
            . 'Rezepts deterministisch aus dem Zubereitungstext. Nur wo ein echter Prozess-Marker '
            . 'steht (Rösten/Anbraten/Schmoren/Grillen/Karamellisieren/Räuchern/Fermentieren) — kein '
            . 'Marker → kein Anker. apply=false liefert den Vorschlag, apply=true schreibt (source=parser).';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'recipe_id' => ['type' => 'integer', 'description' => 'ID des Rezepts'],
                'apply' => ['type' => 'boolean', 'default' => false, 'description' => 'true = schreiben, false = nur Vorschau'],
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

        $recipe = FoodAlchemistRecipe::visibleToTeam($team)->whereKey((int) $arguments['recipe_id'])->first();
        if ($recipe === null) {
            return ToolResult::error('Rezept nicht sichtbar/vorhanden.', 'NOT_FOUND');
        }

        $apply = (bool) ($arguments['apply'] ?? false);
        if ($apply && ! $recipe->isOwnedBy($team)) {
            return ToolResult::error('Rezept gehört nicht dem aktiven Team — Schreiben nicht erlaubt.', 'ACCESS_DENIED');
        }

        $result = app(ProcessAnchorService::class)->groundRecipe($recipe, $apply);
        $result['applied'] = $apply;

        return ToolResult::success($result);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'enrichment',
            'tags' => ['foodalchemist', 'prozessanker', 'process-anchor', 'roestaromen', 'rauch', 'ferment', 'karamell', 'grounding'],
            'read_only' => false,
            'idempotent' => true,
            'risk_level' => 'write',
            'requires_auth' => true,
            'requires_team' => true,
            'cost_class' => 'local_db',
            'side_effects' => ['creates', 'deletes'],
            'related_tools' => ['foodalchemist.pairings.GET', 'foodalchemist.recipes.GET'],
            'examples' => ['Erde die Prozessanker für Rezept 42', 'Welche Kocharomen erkennt der Parser in Rezept 7?'],
        ];
    }
}
