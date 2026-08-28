<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\CoherenceService;

/**
 * MCP-Steuerbarkeit · D3: kulinarische Kohärenz eines team-eigenen Gerichts prüfen (mode=judge) oder
 * eine gezielte Aufwertung vorschlagen (mode=heber). Ergebnis wird als Kohärenz-Datensatz persistiert.
 */
class RecipeCoherencePostTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.recipe_coherence.POST';
    }

    public function getDescription(): string
    {
        return 'Prüft die kulinarische Kohärenz eines team-eigenen Gerichts (mode=judge) oder schlägt eine '
            . 'gezielte Aufwertung vor (mode=heber). Persistiert das Kohärenz-Ergebnis.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'recipe_id' => ['type' => 'integer', 'description' => 'Gericht-Id (team-eigen).'],
                'mode' => ['type' => 'string', 'enum' => ['judge', 'heber'], 'description' => 'judge = prüfen, heber = Aufwertung.'],
            ],
            'required' => ['recipe_id', 'mode'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        $mode = (string) ($arguments['mode'] ?? '');
        if (! in_array($mode, ['judge', 'heber'], true)) {
            return ToolResult::error('mode muss judge|heber sein.', 'VALIDATION_ERROR');
        }

        $recipeId = (int) ($arguments['recipe_id'] ?? 0);
        if (($guard = $this->guardVkRecipe($team, $recipeId)) !== null) {
            return $guard;
        }

        $svc = app(CoherenceService::class);
        try {
            $c = $mode === 'judge' ? $svc->judge($team, $recipeId) : $svc->tellerHeber($team, $recipeId);
        } catch (\RuntimeException $e) {
            return ToolResult::error($e->getMessage(), 'VALIDATION_ERROR');
        }

        return ToolResult::success(['recipe_id' => $recipeId, 'mode' => $mode, 'coherence_id' => (int) $c->id]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['foodalchemist', 'gericht', 'kohaerenz', 'pairing', 'ki', 'write'],
            'read_only' => false, 'idempotent' => false, 'risk_level' => 'write',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'llm',
            'side_effects' => ['creates'],
            'related_tools' => ['foodalchemist.recipe_rollen.POST'],
            'examples' => ['Prüfe die Kohärenz von Gericht 501.', 'Schlage einen Teller-Heber für Gericht 501 vor.'],
        ];
    }
}
