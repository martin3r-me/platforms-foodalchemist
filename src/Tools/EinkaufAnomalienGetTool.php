<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\PurchaseAnomalyService;

/**
 * Einkauf E2/E5 (read): Preis-Ausreißer im Einkaufsjournal (mögliche Fehlbuchungen).
 * Robuste Theil-Sen-Trendlinie pro Lieferant+Artikel (Fallback globaler Median), flaggt
 * Positionen, die um Faktor ≥ `factor` vom Trend abweichen. NUR zur fachlichen Prüfung —
 * korrigiert nichts. team-scoped, read-only.
 */
class EinkaufAnomalienGetTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.einkauf_anomalien.GET';
    }

    public function getDescription(): string
    {
        return 'Preis-Ausreißer im Einkaufsjournal via Theil-Sen-Trendlinie pro Lieferant+Artikel '
            . '(Fallback globaler Median bei < min_points Datenpunkten). Liefert je Treffer Ist- vs. '
            . 'erwarteten Preis, Abweichungsfaktor und Methode, größter Faktor zuerst. Optional factor '
            . '(Default 3.0) und min_points (Default 4). Rein diagnostisch — Muster fachlich prüfen, nicht auto-korrigieren.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'factor' => ['type' => 'number', 'description' => 'Ausreißer-Faktor (Default 3.0)'],
                'min_points' => ['type' => 'integer', 'description' => 'Mindest-Datenpunkte für Theil-Sen (Default 4)'],
                'limit' => ['type' => 'integer', 'description' => 'max. Treffer (Default 100)'],
            ],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        $factor = isset($arguments['factor']) && (float) $arguments['factor'] > 1 ? (float) $arguments['factor'] : 3.0;
        $minPoints = isset($arguments['min_points']) && (int) $arguments['min_points'] > 1 ? (int) $arguments['min_points'] : 4;
        $limit = isset($arguments['limit']) && (int) $arguments['limit'] > 0 ? (int) $arguments['limit'] : 100;

        $flags = app(PurchaseAnomalyService::class)->detect($team, $factor, $minPoints);

        return ToolResult::success([
            'count' => count($flags),
            'ausreisser' => array_slice($flags, 0, $limit),
        ]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'query',
            'tags' => ['foodalchemist', 'einkauf', 'anomalie', 'ausreisser', 'preis', 'datenqualitaet', 'journal'],
            'read_only' => true,
            'idempotent' => true,
            'risk_level' => 'read',
            'requires_auth' => true,
            'requires_team' => true,
            'cost_class' => 'local_db',
            'related_tools' => ['foodalchemist.einkauf_spend.GET'],
            'examples' => ['Zeig mir verdächtige Preis-Fehlbuchungen im Einkaufsjournal.'],
        ];
    }
}
