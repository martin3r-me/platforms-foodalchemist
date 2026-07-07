<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Models\FoodAlchemistSpeiseplan;
use Platform\FoodAlchemist\Services\SpeiseplanService;

/** Phase C: Eintrag in einen draft-Speiseplan (Gericht/Konzept/Paket an Tag+Mahlzeit+Linie). */
class SpeiseplanEintraegePostTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.speiseplan_eintraege.POST';
    }

    public function getDescription(): string
    {
        return 'Hängt einen Eintrag an einen draft-Speiseplan: datum (YYYY-MM-DD) + mahlzeit '
            . '(fruehstueck|mittag|abend|snack) + line_id (aus speiseplaene.POST) + GENAU EINES von '
            . 'concept_id | package_id | sales_recipe_id.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'menu_plan_id' => ['type' => 'integer'],
                'entry_date' => ['type' => 'string', 'description' => 'YYYY-MM-DD'],
                'mahlzeit' => ['type' => 'string', 'enum' => ['fruehstueck', 'mittag', 'abend', 'snack'], 'default' => 'mittag'],
                'line_id' => ['type' => 'integer'],
                'concept_id' => ['type' => 'integer'],
                'package_id' => ['type' => 'integer'],
                'sales_recipe_id' => ['type' => 'integer'],
            ],
            'required' => ['menu_plan_id', 'entry_date'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        $plan = FoodAlchemistSpeiseplan::visibleToTeam($team)->whereKey((int) $arguments['menu_plan_id'])->first();
        if ($plan === null) {
            return ToolResult::error('Speiseplan nicht sichtbar/vorhanden.', 'NOT_FOUND');
        }
        if ((string) $plan->status !== 'draft') {
            return ToolResult::error("Speiseplan hat Status \"{$plan->status}\" — via MCP ist nur draft editierbar.", 'ACCESS_DENIED');
        }
        $ziele = array_values(array_intersect(['concept_id', 'package_id', 'sales_recipe_id'], array_keys(array_filter($arguments))));
        if (count($ziele) !== 1) {
            return ToolResult::error('Genau EINES von concept_id, package_id, sales_recipe_id angeben.', 'VALIDATION_ERROR');
        }

        try {
            $e = app(SpeiseplanService::class)->addEintrag($team, $plan->id, [
                'entry_date' => (string) $arguments['entry_date'],
                'mahlzeit' => $arguments['mahlzeit'] ?? 'mittag',
                'line_id' => $arguments['line_id'] ?? null,
                'concept_id' => $arguments['concept_id'] ?? null,
                'package_id' => $arguments['package_id'] ?? null,
                'sales_recipe_id' => $arguments['sales_recipe_id'] ?? null,
            ]);
        } catch (\Throwable $ex) {
            return ToolResult::error($ex->getMessage(), 'VALIDATION_ERROR');
        }

        return ToolResult::success(['eintrag' => [
            'id' => $e->id, 'entry_date' => (string) $e->entry_date, 'mahlzeit' => $e->meal,
            'line_id' => $e->line_id, 'concept_id' => $e->concept_id,
            'package_id' => $e->package_id, 'sales_recipe_id' => $e->sales_recipe_id,
        ]]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['foodalchemist', 'speiseplan', 'eintrag', 'kantine', 'draft'],
            'read_only' => false, 'idempotent' => false, 'risk_level' => 'write',
            'requires_auth' => true, 'requires_team' => true,
            'side_effects' => ['creates'], 'cost_class' => 'local_db',
            'related_tools' => ['foodalchemist.speiseplaene.POST', 'foodalchemist.verkaufsrezepte.SEARCH', 'foodalchemist.concepts.SEARCH'],
            'examples' => ['Setze Verkaufsrezept 1373 am 2026-07-08 mittags auf Linie Menü 1'],
        ];
    }
}
