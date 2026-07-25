<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\SignalTrendService;

/**
 * Spec 21 · E1 — Trend-Lesetool. `signale.SEARCH`/`LIST` beantworten „was ist offen?";
 * dieses Tool beantwortet „wird es besser oder schlechter?" (Serie + Delta zum Vorlauf).
 * Read-only; die Zeitreihe entsteht im Detektor-Lauf (`foodalchemist:signale-detektor`)
 * bzw. mit `foodalchemist:data-quality --snapshot`.
 */
class SignalTrendGetTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.signal_trend.GET';
    }

    public function getDescription(): string
    {
        return 'Zeitreihe der Qualitäts-Zähler des Teams: OHNE metric_key eine Übersicht aller Metriken '
            . 'des letzten Laufs mit Delta/Prozent zum Vorlauf (only_worse=true zeigt nur die verschlechterten); '
            . 'MIT metric_key die Serie dieser einen Metrik (älteste zuerst) plus Delta. metric_key ist entweder '
            . 'ein Signal-Typ (source=signals, offene Signale je Typ) oder ein Lücken-Metrik-Key der '
            . 'Datenqualitäts-Ampel (source=data-quality, z. B. rezept_mengen_luecke, br_ek_teil). '
            . 'previous=null heißt: im Vorlauf nicht gemessen (neuer Check) — keine Verschlechterung. '
            . 'Die gültigen Keys stehen in der Übersichts-Antwort (Aufruf ohne metric_key).';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'metric_key' => ['type' => 'string', 'description' => 'Optional: Metrik-Key oder Signal-Typ. Leer = Übersicht aller Metriken.'],
                'source' => ['type' => 'string', 'enum' => ['data-quality', 'signals'], 'description' => 'Optionaler Quellen-Filter.'],
                'limit' => ['type' => 'integer', 'minimum' => 2, 'maximum' => 200, 'default' => 30, 'description' => 'Länge der Serie (nur mit metric_key).'],
                'only_worse' => ['type' => 'boolean', 'default' => false, 'description' => 'Nur Metriken mit delta > 0 (nur in der Übersicht).'],
            ],
            'required' => [],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        $svc = app(SignalTrendService::class);
        $source = ($arguments['source'] ?? '') !== '' ? (string) $arguments['source'] : null;
        $key = trim((string) ($arguments['metric_key'] ?? ''));

        if ($key !== '') {
            $serie = $svc->serie($team, $key, min(200, max(2, (int) ($arguments['limit'] ?? 30))), $source);
            if ($serie === []) {
                return ToolResult::error(
                    "Für '{$key}' gibt es keinen Snapshot. Lauf 'foodalchemist:signale-detektor' erzeugt die Zeitreihe; Keys siehe Aufruf ohne metric_key.",
                    'NOT_FOUND'
                );
            }

            return ToolResult::success([
                'metric_key' => $key,
                'punkte' => count($serie),
                'serie' => $serie,
                'delta' => $svc->delta($team, $key, $source),
            ]);
        }

        $u = $svc->uebersicht($team, $source);
        if ($u['measured_at'] === null) {
            return ToolResult::success([
                'measured_at' => null,
                'hinweis' => 'Noch keine Zeitreihe — ein Lauf von foodalchemist:signale-detektor (oder data-quality --snapshot) schreibt den ersten Snapshot.',
                'metriken' => [],
            ]);
        }
        $metriken = $u['metriken'];
        if ((bool) ($arguments['only_worse'] ?? false)) {
            $metriken = array_values(array_filter($metriken, fn ($m) => ($m['delta'] ?? 0) > 0));
        }

        return ToolResult::success([
            'measured_at' => $u['measured_at'],
            'previous_at' => $u['previous_at'],
            'anzahl' => count($metriken),
            'metriken' => $metriken,
        ]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'query',
            'read_only' => true, 'idempotent' => true, 'risk_level' => 'safe',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'tags' => ['foodalchemist', 'signal', 'trend', 'zeitreihe', 'datenqualitaet', 'delta', 'historie'],
            'related_tools' => ['foodalchemist.signale.SEARCH', 'foodalchemist.signale.LIST', 'foodalchemist.signale.FIX'],
            'examples' => [
                'Wird die Datenqualität besser oder schlechter?',
                'Zeig mir den Verlauf von rezept_mengen_luecke',
                'Welche Qualitäts-Zähler sind seit dem letzten Lauf gestiegen?',
            ],
        ];
    }
}
