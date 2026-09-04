<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Services\RecipeService;

/**
 * MCP-Steuerbarkeit · D2a: Rezept in ALLEN eigenen Verwendungen durch ein anderes ersetzen —
 * das Pendant zu `gps.REPLACE` (Web: Verwaltungs-Block in Rezept-Panel + Rezept-Editor).
 *
 * Geschrieben werden ausschließlich Zutat-Zeilen in Rezepten des eigenen Teams; geerbte
 * Master-/Seed-Eltern bleiben unberührt und werden gezählt gemeldet. `from` darf deshalb
 * bewusst auch ein geerbtes Rezept sein („die geerbte Komponente in MEINEN Gerichten
 * ersetzen") — das from-Rezept selbst wird dabei nie verändert.
 *
 * Hohe Reichweite (Referenzen umhängen + Neuberechnung je betroffenem Eltern-Rezept
 * inkl. Propagation) → confirm=true Pflicht.
 */
class RecipesReplaceTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.recipes.REPLACE';
    }

    public function getDescription(): string
    {
        return 'Ersetzt ein Rezept in allen eigenen Verwendungen als Komponente (from → to) und rechnet die '
            . 'betroffenen Eltern-Rezepte neu. Menge/Einheit der Zeilen bleiben. Geerbte Eltern bleiben '
            . 'unberührt; Zyklen werden abgewiesen. Reichweitenstark → confirm=true Pflicht.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'from_id' => ['type' => 'integer', 'description' => 'Zu ersetzendes Rezept (sichtbar; darf geerbt sein).'],
                'to_id' => ['type' => 'integer', 'description' => 'Ziel-Rezept (sichtbar, nicht „deprecated").'],
                'confirm' => ['type' => 'boolean', 'description' => 'Muss true sein (reichweitenstarke Aktion).'],
            ],
            'required' => ['from_id', 'to_id', 'confirm'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        if (($arguments['confirm'] ?? false) !== true) {
            return ToolResult::error('Ersetzen erfordert confirm=true (reichweitenstarke Aktion).', 'CONFIRM_REQUIRED');
        }

        $von = FoodAlchemistRecipe::visibleToTeam($team)->whereKey((int) ($arguments['from_id'] ?? 0))->first();
        if ($von === null) {
            return ToolResult::error('from-Rezept nicht sichtbar/vorhanden.', 'NOT_FOUND');
        }
        $nach = FoodAlchemistRecipe::visibleToTeam($team)->whereKey((int) ($arguments['to_id'] ?? 0))->first();
        if ($nach === null) {
            return ToolResult::error('to-Rezept nicht sichtbar/vorhanden.', 'NOT_FOUND');
        }

        try {
            $res = app(RecipeService::class)->ersetzeInVerwendungen($team, (int) $von->id, (int) $nach->id);
        } catch (\RuntimeException $e) {
            return ToolResult::error($e->getMessage(), 'VALIDATION_ERROR');
        }

        return ToolResult::success([
            'from_id' => (int) $von->id,
            'to_id' => (int) $nach->id,
            'zeilen' => (int) $res['zeilen'],
            'rezepte' => (int) $res['rezepte'],
            'geerbt_unberuehrt' => (int) $res['fremd_rezepte'],
            'uebersprungen_zyklus' => $res['zyklus'],
            'ziel_war_schon_drin' => $res['doppelt'],
        ]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['foodalchemist', 'recipe', 'basisrezept', 'ersetzen', 'destructive', 'recompute', 'write'],
            'read_only' => false, 'idempotent' => false, 'risk_level' => 'destructive',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'confirmation_required' => true,
            'side_effects' => ['updates'],
            'related_tools' => ['foodalchemist.recipes.DELETE', 'foodalchemist.gps.REPLACE'],
            'examples' => ['Ersetze Rezept 12 durch Rezept 34 in allen eigenen Gerichten (confirm=true).'],
        ];
    }
}
