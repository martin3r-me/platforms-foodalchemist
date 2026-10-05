<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Jobs\LeadRepickJob;
use Platform\FoodAlchemist\Services\LeadLaService;

/**
 * Lead-Neuwahl nach Strategie + Stamm-Matrix (MCP-Spiegel von „Leads neu wählen" in
 * Settings/Einkauf). Default dry_run: nur die Vorschau. Mit dry_run=false werden die Wechsel
 * (alle oder gp_ids) übernommen und die nutzenden Rezepte neu gerechnet (async).
 */
class LeadLaRepickTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.lead_la.REPICK';
    }

    public function getDescription(): string
    {
        return 'Wählt die Lead-Lieferantenartikel der EIGENEN Grundprodukte nach aktueller Lead-LA-Strategie '
            . 'und Stamm-Lieferanten-Matrix neu. Strategie-/Matrix-Änderungen allein ändern keinen bestehenden '
            . 'Lead — erst dieses Tool (oder der Knopf in den Einkauf-Einstellungen). dry_run=true (Default) '
            . 'liefert nur die Vorschau: Wechsel je GP (bisher/neu, Vergleichspreis, Anzahl Rezepte) plus Zähler. '
            . 'Manuell gesetzte Leads und Kandidaten ohne Preis bleiben. dry_run=false übernimmt alle Wechsel '
            . 'oder nur gp_ids und rechnet die nutzenden Rezepte neu. Der Lead ist global — er gilt für alle Teams.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'dry_run' => ['type' => 'boolean', 'default' => true, 'description' => 'true = nur Vorschau, nichts schreiben'],
                'warengruppe' => ['type' => 'string', 'description' => 'Optional: nur GPs dieses WG-Codes (z. B. "01")'],
                'gp_ids' => ['type' => 'array', 'items' => ['type' => 'integer'], 'description' => 'Optional (nur mit dry_run=false): nur diese GPs übernehmen'],
            ],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        $wg = isset($arguments['warengruppe']) && $arguments['warengruppe'] !== '' ? (string) $arguments['warengruppe'] : null;
        $vorschau = app(LeadLaService::class)->repickVorschau($team, $wg);

        if ((bool) ($arguments['dry_run'] ?? true)) {
            return ToolResult::success(['dry_run' => true] + $vorschau);
        }

        $moeglich = array_column($vorschau['wechsel'], 'gp_id');
        $ids = isset($arguments['gp_ids']) && is_array($arguments['gp_ids'])
            ? array_values(array_intersect(array_map('intval', $arguments['gp_ids']), $moeglich))
            : $moeglich;
        if ($ids !== []) {
            LeadRepickJob::dispatch((int) $team->id, $ids);
        }

        return ToolResult::success([
            'dry_run' => false,
            'uebernommen_gp_ids' => $ids,
            'umgestellt' => count($ids),
            'hinweis' => $ids === [] ? 'Keine Wechsel zu übernehmen.' : 'Leads werden umgestellt, nutzende Rezepte danach neu gerechnet.',
        ]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'command',
            'tags' => ['foodalchemist', 'lead', 'lieferant', 'stamm', 'strategie', 'gp', 'recompute'],
            'read_only' => false,
            'idempotent' => true,
            'risk_level' => 'write',
            'requires_auth' => true,
            'requires_team' => true,
            'cost_class' => 'local_db',
            'side_effects' => ['updates'],
            'related_tools' => ['foodalchemist.gp_lead.GET', 'foodalchemist.gp_lead.PUT', 'foodalchemist.settings.GET'],
            'examples' => [
                'Zeig mir, welche Leads sich mit der neuen Stamm-Matrix ändern würden.',
                'Übernimm die neuen Leads für Warengruppe 04.',
            ],
        ];
    }
}
