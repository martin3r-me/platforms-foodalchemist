<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;

/**
 * M7-10: UI-Aktions-Tool (kein DB-Write) — der Voice-Loop signalisiert damit
 * »öffne Datensatz X«; das Frontend setzt die Aktion um (recipe-selected).
 */
class UiOpenTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.ui.OPEN';
    }

    public function getDescription(): string
    {
        return 'Öffnet einen Datensatz in der Oberfläche (reine UI-Aktion, kein Schreiben). '
            . 'typ: recipe (Basis) | verkaufsrezept | gp. Vorher per SEARCH die id ermitteln.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'type' => ['type' => 'string', 'enum' => ['recipe', 'verkaufsrezept', 'gp']],
                'id' => ['type' => 'integer'],
            ],
            'required' => ['type', 'id'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        // Sichtbarkeits-Guard: nur öffnen, was das Team sehen darf
        $sichtbar = match ($arguments['type']) {
            'recipe', 'verkaufsrezept' => FoodAlchemistRecipe::visibleToTeam($team)->whereKey((int) $arguments['id'])->exists(),
            'gp' => \Platform\FoodAlchemist\Models\FoodAlchemistGp::visibleToTeam($team)->whereKey((int) $arguments['id'])->exists(),
            default => false,
        };
        if (! $sichtbar) {
            return ToolResult::error('Datensatz nicht sichtbar/vorhanden.', 'NOT_FOUND');
        }

        return ToolResult::success(['open' => ['type' => $arguments['type'], 'id' => (int) $arguments['id']]]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'utility',
            'tags' => ['foodalchemist', 'ui', 'navigation', 'open', 'voice'],
            'read_only' => true,
            'idempotent' => true,
            'risk_level' => 'safe',
            'requires_auth' => true,
            'requires_team' => true,
            'cost_class' => 'local_db',
            'examples' => ['Öffne Rezept 456 in der Oberfläche'],
        ];
    }
}
