<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Services\RecipeService;

/**
 * MCP-Steuerbarkeit · D2: Status eines/mehrerer team-eigener Basisrezepte setzen
 * (draft|review|approved|deprecated). Einzel via `id`, Bulk via `ids`. Nur eigene
 * Basisrezepte (is_sales_recipe=false). VK → verkaufsrezepte.STATUS.
 */
class RecipesStatusTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    private const ERLAUBT = ['draft', 'review', 'approved', 'deprecated'];

    public function getName(): string
    {
        return 'foodalchemist.recipes.STATUS';
    }

    public function getDescription(): string
    {
        return 'Setzt den Status team-eigener Basisrezepte: draft|review|approved|deprecated. '
            . 'Einzel (id) oder Bulk (ids). „stub" ist generator-intern und nicht setzbar.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'id' => ['type' => 'integer', 'description' => 'Einzel-Basisrezept-Id.'],
                'ids' => ['type' => 'array', 'items' => ['type' => 'integer'], 'description' => 'Mehrere Basisrezept-Ids (Bulk).'],
                'status' => ['type' => 'string', 'enum' => self::ERLAUBT, 'description' => 'Neuer Status.'],
            ],
            'required' => ['status'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }

        $status = (string) ($arguments['status'] ?? '');
        if (! in_array($status, self::ERLAUBT, true)) {
            return ToolResult::error('status muss einer von: ' . implode(', ', self::ERLAUBT) . ' sein.', 'VALIDATION_ERROR');
        }

        $svc = app(RecipeService::class);

        // Bulk-Pfad
        if (isset($arguments['ids']) && is_array($arguments['ids']) && $arguments['ids'] !== []) {
            $ids = array_map('intval', $arguments['ids']);
            $eligible = FoodAlchemistRecipe::visibleToTeam($team)->where('is_sales_recipe', false)
                ->where('team_id', $team->id)->whereIn('id', $ids)->pluck('id')->all();
            if ($eligible === []) {
                return ToolResult::error('Keine eigenen Basisrezepte in ids gefunden.', 'NOT_FOUND');
            }
            $n = $svc->bulkStatus($team, $eligible, $status);

            return ToolResult::success(['status' => $status, 'aktualisiert' => (int) $n, 'ids' => $eligible]);
        }

        // Einzel-Pfad
        $id = (int) ($arguments['id'] ?? 0);
        $recipe = FoodAlchemistRecipe::visibleToTeam($team)->where('is_sales_recipe', false)->whereKey($id)->first();
        if ($recipe === null) {
            return ToolResult::error('Basisrezept nicht sichtbar/vorhanden.', 'NOT_FOUND');
        }
        if (! $recipe->isOwnedBy($team)) {
            return ToolResult::error('Geerbtes Rezept — Status nur durchs Besitzer-Team.', 'ACCESS_DENIED');
        }
        $svc->setStatus($team, $id, $status);

        return ToolResult::success(['id' => $id, 'status' => $status]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['foodalchemist', 'recipe', 'basisrezept', 'status', 'write'],
            'read_only' => false, 'idempotent' => true, 'risk_level' => 'write',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'side_effects' => ['updates'],
            'related_tools' => ['foodalchemist.recipes.GET', 'foodalchemist.recipes.DELETE'],
            'examples' => ['Setze Basisrezept 12 auf approved.', 'Setze Rezepte [1,2,3] auf review.'],
        ];
    }
}
