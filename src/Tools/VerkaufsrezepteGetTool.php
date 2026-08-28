<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\SalesRecipeService;

/**
 * MCP-Steuerbarkeit · D3: Verkaufsrezept (Gericht) im Detail — schließt die Read-Lücke, damit ein Agent
 * Darreichungen/Preise/Klassifikation eines Gerichts sehen kann (bisher nur SEARCH/LIST). Read-only.
 */
class VerkaufsrezepteGetTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.verkaufsrezepte.GET';
    }

    public function getDescription(): string
    {
        return 'Liefert ein Verkaufsrezept (Gericht) im Detail: Klassifikation (Speisen-Klasse + Hauptgruppe), '
            . 'Wording/Texte, Darreichungsformen (Preis/W% je Form) und das Preis-Cockpit (VK/Marge/Wareneinsatz).';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => ['id' => ['type' => 'integer', 'description' => 'Gericht-Id.']],
            'required' => ['id'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }

        $svc = app(SalesRecipeService::class);
        $r = $svc->detail($team, (int) ($arguments['id'] ?? 0));
        if ($r === null) {
            return ToolResult::error('Gericht nicht sichtbar/vorhanden.', 'NOT_FOUND');
        }

        return ToolResult::success([
            'id' => (int) $r->id,
            'name' => $r->name,
            'status' => $this->statusWert($r),
            'speisen_klasse' => $r->dishClass?->label,
            'dish_main_group' => $r->dishClass?->mainGroup?->label,
            'dish_main_group_id' => $r->dish_main_group_id !== null ? (int) $r->dish_main_group_id : null,
            'sales_wording_standard' => $r->sales_wording_standard,
            'marketing_text' => $r->marketing_text,
            'description' => $r->description,
            'plating_text' => $r->plating_text,
            // Preis-Wahrheit liegt in den Darreichungen; sales_net ist nur Standard-Form-Spiegel.
            'sales_net_standard_mirror' => $r->sales_net !== null ? (float) $r->sales_net : null,
            'presentations' => $this->darreichungenSummary($r),
            'cockpit' => $svc->cockpit($r, $team),
        ]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'query',
            'read_only' => true, 'idempotent' => true, 'risk_level' => 'safe',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'tags' => ['foodalchemist', 'verkaufsrezept', 'gericht', 'detail', 'darreichung', 'preis'],
            'related_tools' => ['foodalchemist.verkaufsrezepte.SEARCH', 'foodalchemist.verkaufsrezepte.PUT'],
            'examples' => ['Zeig mir Gericht 501 im Detail.'],
        ];
    }
}
