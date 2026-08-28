<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Services\SalesRecipeService;

/**
 * MCP-Steuerbarkeit · D3: Verkaufsrezept (Gericht) bearbeiten. Nur team-eigene Gerichte. Der Service
 * filtert auf die VK-Whitelist und re-autorisiert alle referenzierten FKs (Klasse/Hauptgruppe/
 * Aufschlagsklasse/Einheit/Posten) team-scoped. Preis-Wahrheit wird auf die Standard-Darreichung
 * umgeleitet — Darreichungen selbst pflegt recipe_darreichung.*.
 */
class VerkaufsrezeptePutTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.verkaufsrezepte.PUT';
    }

    public function getDescription(): string
    {
        return 'Bearbeitet ein team-eigenes Gericht. felder trägt die VK-Felder (name, sales_wording_standard, '
            . 'dish_class_id, dish_main_group_id, markup_class_id, sales_net, price_mode, description, marketing_text, '
            . 'plating_text, taste_direction, work_time_min, …); nur erlaubte Keys werden geschrieben, FKs geprüft.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'id' => ['type' => 'integer', 'description' => 'Gericht-Id (team-eigen).'],
                'felder' => ['type' => 'object', 'description' => 'Zu schreibende VK-Felder (nur Whitelist wirkt).'],
            ],
            'required' => ['id', 'felder'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        $felder = $arguments['felder'] ?? null;
        if (! is_array($felder) || $felder === []) {
            return ToolResult::error('felder muss ein nicht-leeres Objekt sein.', 'VALIDATION_ERROR');
        }

        $id = (int) ($arguments['id'] ?? 0);
        $r = FoodAlchemistRecipe::visibleToTeam($team)->where('is_sales_recipe', true)->whereKey($id)->first();
        if ($r === null) {
            return ToolResult::error('Gericht nicht sichtbar/vorhanden.', 'NOT_FOUND');
        }
        if (! $r->isOwnedBy($team)) {
            return ToolResult::error('Geerbtes Gericht — VK-Pflege nur durchs Besitzer-Team.', 'ACCESS_DENIED');
        }

        try {
            $r = app(SalesRecipeService::class)->updateVk($team, $id, $felder);
        } catch (\RuntimeException $e) {
            return ToolResult::error($e->getMessage(), 'VALIDATION_ERROR');
        }

        return ToolResult::success([
            'id' => (int) $r->id,
            'name' => $r->name,
            'status' => $this->statusWert($r),
        ]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['foodalchemist', 'verkaufsrezept', 'gericht', 'bearbeiten', 'write'],
            'read_only' => false, 'idempotent' => true, 'risk_level' => 'write',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'side_effects' => ['updates'],
            'related_tools' => ['foodalchemist.verkaufsrezepte.GET', 'foodalchemist.recipe_darreichung.PUT'],
            'examples' => ['Setze bei Gericht 501 das Wording und die Speisen-Klasse.'],
        ];
    }
}
