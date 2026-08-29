<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\ActiveOutletContext;

/**
 * Ebene 2: setzt die aktive Betriebs-„Brille" (analog core.team.switch, nur für Betriebe).
 * Durabel je (User, Team) — danach lösen Reads OHNE explizites outlet_id (Kalkulation,
 * Präsentation, Controlling-KPIs) automatisch gegen diesen Betrieb auf. Deckt die Lücke,
 * dass MCP-Calls die Web-Session des Sidebar-Dropdowns nicht teilen.
 */
class OutletsSetActiveTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.outlets.SET_ACTIVE';
    }

    public function getDescription(): string
    {
        return 'Setzt die aktive Betriebs-Brille für diese MCP-Session (durabel je User+Team) — wie '
            . 'der linke Sidebar-Dropdown, nur per MCP. Danach rechnen Kalkulation/Präsentation/'
            . 'Controlling ohne explizites outlet_id automatisch gegen diesen Betrieb. outlet_id '
            . 'weglassen oder null = zurück auf Team-Baseline. Betriebe listen: outlets.GET.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'outlet_id' => ['type' => ['integer', 'null'], 'description' => 'Betrieb, dessen Brille aktiv wird; null/weglassen = Team-Baseline. Muss dem Team gehören und aktiv sein.'],
            ],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        $uid = $context->user?->id !== null ? (int) $context->user->id : null;
        $angefragt = array_key_exists('outlet_id', $arguments) && $arguments['outlet_id'] !== null
            ? (int) $arguments['outlet_id'] : null;

        $outlet = app(ActiveOutletContext::class)->set($team, $angefragt, $uid);

        // Ein angefragter Betrieb, der nicht auflöst (fremd/inaktiv), ist ein Fehler — nicht stiller Reset.
        if ($angefragt !== null && $outlet === null) {
            return ToolResult::error('Betrieb nicht gefunden, nicht im Team oder inaktiv.', 'NOT_FOUND');
        }

        return ToolResult::success([
            'active_outlet_id' => $outlet?->id,
            'active_outlet_name' => $outlet?->name,
            'team_baseline' => $outlet === null,
            'hinweis' => $outlet === null
                ? 'Zurück auf Team-Baseline — Reads rechnen wieder team-weit.'
                : 'Aktiv: „' . $outlet->name . '". Reads ohne outlet_id rechnen jetzt gegen diesen Betrieb.',
        ]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'command',
            'tags' => ['foodalchemist', 'betrieb', 'outlet', 'brille', 'kontext', 'switch'],
            'read_only' => false,
            'idempotent' => true,
            'risk_level' => 'safe',
            'requires_auth' => true,
            'requires_team' => true,
            'cost_class' => 'local_db',
            'related_tools' => ['foodalchemist.outlets.GET', 'foodalchemist.kalkulation.GET'],
            'examples' => ['Schalte die Betriebs-Brille auf „Testbetrieb Nord".', 'Zurück auf Team-Baseline (kein aktiver Betrieb).'],
        ];
    }
}
