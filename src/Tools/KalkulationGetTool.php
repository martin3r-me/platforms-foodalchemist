<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Models\FoodAlchemistConcept;
use Platform\FoodAlchemist\Models\FoodAlchemistPaket;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Services\KalkulationService;

/**
 * Phase C: Kalkulations-Werkstatt (read-only) — HK1/HK2-Zerlegung für
 * Rezept, Konzept oder Paket nach den Team-Kalkulationsparametern.
 */
class KalkulationGetTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.kalkulation.GET';
    }

    public function getDescription(): string
    {
        return 'Rechnet die Kalkulation (HK1/HK2, Wareneinsatz, Arbeitszeit, Nebenkosten) nach den '
            . 'Team-Parametern — GENAU EINES angeben: recipe_id, concept_id oder paket_id. Read-only.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'recipe_id' => ['type' => 'integer'],
                'concept_id' => ['type' => 'integer'],
                'paket_id' => ['type' => 'integer'],
            ],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        $keys = array_values(array_intersect(['recipe_id', 'concept_id', 'paket_id'], array_keys(array_filter($arguments))));
        if (count($keys) !== 1) {
            return ToolResult::error('Genau EINES von recipe_id, concept_id, paket_id angeben.', 'VALIDATION_ERROR');
        }
        $svc = app(KalkulationService::class);

        try {
            $ergebnis = match ($keys[0]) {
                'recipe_id' => ($m = FoodAlchemistRecipe::visibleToTeam($team)->find((int) $arguments['recipe_id']))
                    ? ['ziel' => ['typ' => 'recipe', 'id' => $m->id, 'name' => $m->name], 'kalkulation' => $svc->recipeHk($team, $m)] : null,
                'concept_id' => ($m = FoodAlchemistConcept::visibleToTeam($team)->find((int) $arguments['concept_id']))
                    ? ['ziel' => ['typ' => 'concept', 'id' => $m->id, 'name' => $m->name], 'kalkulation' => $svc->conceptHk($team, $m)] : null,
                'paket_id' => ($m = FoodAlchemistPaket::visibleToTeam($team)->find((int) $arguments['paket_id']))
                    ? ['ziel' => ['typ' => 'paket', 'id' => $m->id, 'name' => $m->name], 'kalkulation' => $svc->paketHk($team, $m)] : null,
            };
        } catch (\RuntimeException $e) {
            return ToolResult::error($e->getMessage(), 'EXECUTION_ERROR');
        }
        if ($ergebnis === null) {
            return ToolResult::error('Ziel nicht sichtbar/vorhanden.', 'NOT_FOUND');
        }

        return ToolResult::success($ergebnis);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'query',
            'tags' => ['foodalchemist', 'kalkulation', 'hk', 'wareneinsatz', 'marge', 'preis'],
            'read_only' => true, 'idempotent' => true, 'risk_level' => 'safe',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'related_tools' => ['foodalchemist.recipes.GET', 'foodalchemist.concepts.GET', 'foodalchemist.angebote.GET'],
            'examples' => ['Was kostet Rezept 456 in der Herstellung (HK1/HK2)?', 'Kalkuliere Konzept 42'],
        ];
    }
}
