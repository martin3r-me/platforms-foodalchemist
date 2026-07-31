<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Services\LeadLaService;
use Platform\FoodAlchemist\Services\RebateService;
use Platform\FoodAlchemist\Support\Suche;

/**
 * Einkauf E3/E5 (read): Cross-Lieferanten-Preisvergleich je Grundprodukt — günstigster/
 * teuerster Lieferant + Spanne + alle Angebote (Vergleichspreis, optional inkl.
 * Rückvergütung). Über die GP↔LA-Abstraktion. Filter q/commodity_group/supplier_id.
 * team-scoped, read-only.
 */
class EinkaufPreisvergleichGetTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    private const MAX = 25;

    public function getName(): string
    {
        return 'foodalchemist.einkauf_preisvergleich.GET';
    }

    public function getDescription(): string
    {
        return 'Preisvergleich über alle Lieferanten je Grundprodukt: günstigster/teuerster Lieferant, '
            . 'Spanne und alle Angebote (Vergleichspreis €/Einheit). Filter: q (GP-Suche), commodity_group '
            . '(§3-WG-Code), supplier_id. mit_rabatt=true rechnet den effektiven Netto-Preis inkl. '
            . 'Rückvergütung (kann das Günstigster-Ranking kippen). Auf ' . self::MAX . ' GPs gedeckelt — enger filtern.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'q' => ['type' => 'string'],
                'commodity_group' => ['type' => 'string'],
                'supplier_id' => ['type' => 'integer'],
                'mit_rabatt' => ['type' => 'boolean'],
            ],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        $q = trim((string) ($arguments['q'] ?? ''));
        $wg = trim((string) ($arguments['commodity_group'] ?? ''));
        $supplierId = isset($arguments['supplier_id']) && (int) $arguments['supplier_id'] > 0 ? (int) $arguments['supplier_id'] : null;
        $mitRabatt = (bool) ($arguments['mit_rabatt'] ?? false);

        $lead = app(LeadLaService::class);
        $rebate = app(RebateService::class);

        $gps = FoodAlchemistGp::visibleToTeam($team)->where('status', 'approved')
            ->when($wg !== '', fn ($qb) => $qb->where('commodity_group_code', $wg))
            ->when($q !== '', fn ($qb) => Suche::likeAny($qb, ['name', 'gp_key'], $q))
            ->orderBy('name')->limit(self::MAX + 1)->get();

        $gekappt = $gps->count() > self::MAX;
        $out = [];
        foreach ($gps->take(self::MAX) as $gp) {
            $kette = $lead->rangliste($gp, $team);
            if ($mitRabatt) {
                $rebate->enrichRangliste($team, $kette, $gp->commodity_group_code);
            }
            $preis = fn ($la) => $mitRabatt ? ($la->vergleichspreis_mit_rabatt_wert ?? null) : ($la->vergleichspreis_wert ?? null);
            $bepreist = $kette->filter(fn ($la) => $preis($la) !== null)->values();
            if ($bepreist->isEmpty()) {
                continue;
            }
            if ($supplierId !== null && $bepreist->firstWhere('supplier_id', $supplierId) === null) {
                continue;
            }
            $sortiert = $bepreist->sortBy(fn ($la) => (float) $preis($la))->values();
            $min = (float) $preis($sortiert->first());
            $max = (float) $preis($sortiert->last());

            $out[] = [
                'gp_id' => (int) $gp->id,
                'name' => $gp->name,
                'commodity_group' => $gp->commodity_group_code,
                'n_lieferanten' => $bepreist->count(),
                'guenstigster' => ['supplier_id' => (int) $sortiert->first()->supplier_id, 'supplier' => $sortiert->first()->supplier_name, 'preis' => round($min, 4)],
                'teuerster' => ['supplier' => $sortiert->last()->supplier_name, 'preis' => round($max, 4)],
                'spanne_pct' => $min > 0 ? round(($max - $min) / $min * 100, 1) : null,
                'angebote' => $sortiert->map(fn ($la) => [
                    'supplier_id' => (int) $la->supplier_id,
                    'supplier' => $la->supplier_name,
                    'vergleichspreis' => round((float) $preis($la), 4),
                ])->all(),
            ];
        }

        return ToolResult::success(['mit_rabatt' => $mitRabatt, 'gekappt' => $gekappt, 'grundprodukte' => $out]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'query',
            'tags' => ['foodalchemist', 'einkauf', 'preisvergleich', 'lieferant', 'preis', 'rueckverguetung'],
            'read_only' => true,
            'idempotent' => true,
            'risk_level' => 'read',
            'requires_auth' => true,
            'requires_team' => true,
            'cost_class' => 'local_db',
            'related_tools' => ['foodalchemist.einkauf_optimierung.GET', 'foodalchemist.supplier_rebate.GET', 'foodalchemist.gp_lead.GET'],
            'examples' => ['Wer ist beim Grundprodukt "Sahne konserviert 30%" der günstigste Lieferant — inkl. Rückvergütung?'],
        ];
    }
}
