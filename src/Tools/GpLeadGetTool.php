<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Services\LeadLaService;

/**
 * R9.2 (read): Lead-Lieferant-Steuerung eines GP — aktueller (gesetzter + effektiver)
 * Lead-LA, Heuristik-Vorschlag, Ausweichquellen (Rangliste ab Rang 2) + Override-Begründung.
 */
class GpLeadGetTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.gp_lead.GET';
    }

    public function getDescription(): string
    {
        return 'Zeigt die Lead-Lieferant-Situation eines Grundprodukts: gesetzter + effektiver '
            . 'Lead-LA, Heuristik-Vorschlag (pickLeadLa), Ausweich-/Zweitquellen (Rangliste ab Rang 2) '
            . 'und die Begründung eines etwaigen manuellen Overrides. Read-only. Setzen via gp_lead.PUT.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => ['gp_id' => ['type' => 'integer']],
            'required' => ['gp_id'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        $gp = FoodAlchemistGp::visibleToTeam($team)->find((int) $arguments['gp_id']);
        if ($gp === null) {
            return ToolResult::error('GP nicht sichtbar/vorhanden.', 'NOT_FOUND');
        }

        return ToolResult::success(app(LeadLaService::class)->leadSteuerung($gp, $team));
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'query',
            'tags' => ['foodalchemist', 'lead', 'lieferant', 'gp', 'ausweichquelle'],
            'read_only' => true,
            'idempotent' => true,
            'risk_level' => 'safe',
            'requires_auth' => true,
            'requires_team' => true,
            'cost_class' => 'local_db',
            'related_tools' => ['foodalchemist.gp_lead.PUT', 'foodalchemist.suppliers.VOLUME'],
            'examples' => ['Wer ist Lead-Lieferant für GP 812 und welche Ausweichquellen gibt es?'],
        ];
    }
}
