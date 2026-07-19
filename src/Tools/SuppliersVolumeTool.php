<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\SupplierService;

/**
 * R9.2 (read, E6): Bündelungs-Ranking — Nutzungs-Proxy (Rezept-Zutaten via Lead-LA)
 * je Lieferant × Konditionen → „wo lohnt Bündelung/Nachverhandlung". EHRLICH als
 * Proxy markiert (kein echtes Spend/Umsatz im Modul).
 */
class SuppliersVolumeTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.suppliers.VOLUME';
    }

    public function getDescription(): string
    {
        return 'Rangliste der Lieferanten nach Nutzungs-Volumen (Proxy: Zahl der Rezept-Zutaten, '
            . 'deren GP-Lead-LA zum Lieferanten gehört) kombiniert mit Konditionen (Rückvergütung, '
            . 'Zahlungsziel) — zeigt, wo Bündelung/Nachverhandlung lohnt. Read-only. '
            . 'Hinweis: Nutzungs-Proxy, KEIN echtes Spend/Umsatz.';
    }

    public function getSchema(): array
    {
        return ['type' => 'object', 'properties' => []];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        $ranking = app(SupplierService::class)->volumenProxyRanking($team);

        return ToolResult::success([
            'anzahl' => count($ranking),
            'ranking' => $ranking,
            'hinweis' => 'Nutzungs-Proxy (Rezept-Zutaten via Lead-LA), kein echtes Spend/Umsatz — für Bündelungs-Indiz.',
        ]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'query',
            'tags' => ['foodalchemist', 'lieferant', 'volumen', 'bündelung', 'konditionen', 'proxy'],
            'read_only' => true,
            'idempotent' => true,
            'risk_level' => 'safe',
            'requires_auth' => true,
            'requires_team' => true,
            'cost_class' => 'local_db',
            'related_tools' => ['foodalchemist.suppliers.GET', 'foodalchemist.gp_lead.GET'],
            'examples' => ['Bei welchem Lieferanten lohnt sich Bündelung/Nachverhandlung?'],
        ];
    }
}
