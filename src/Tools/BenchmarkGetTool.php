<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\BenchmarkService;

/**
 * R2.7 — Portfolio-Benchmark (read-only): eigene Portfolio-Kennzahlen vs.
 * anonymisierter Peer-Median der Root-Team-Kette. Nur Aggregat, keine
 * Fremd-Gericht-Details, keine Peer-Namen.
 */
class BenchmarkGetTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.benchmark.GET';
    }

    public function getDescription(): string
    {
        return 'Portfolio-Benchmark (read-only): Kennzahlen des eigenen Teams (EK-Abdeckung, '
            . 'Allergen-Konfidenz, Formen-Vollständigkeit, Ø-Wareneinsatz, Ø-Bewertung, Gericht-Zahl) '
            . 'vs. anonymisierter Peer-Median der internen Team-Kette. Nur Aggregat, keine Fremd-Details.';
    }

    public function getSchema(): array
    {
        return ['type' => 'object', 'properties' => [], 'required' => []];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }

        $b = app(BenchmarkService::class)->benchmark($team);

        // Aggregat-Zeilen aufbereiten (kein Peer-Name, kein Gericht-Detail)
        $zeilen = [];
        foreach ($b['kennzahlen'] as $key => $meta) {
            $zeilen[] = [
                'kennzahl' => $meta['label'],
                'eigen' => $b['team_kpis'][$key],
                'peer_median' => $b['peer_median'][$key],
                'einheit' => $meta['unit'],
                'besser' => $meta['besser'],
            ];
        }

        return ToolResult::success([
            'n_peers' => $b['n_peers'],
            'kennzahlen' => $zeilen,
            'hinweis' => $b['n_peers'] === 0
                ? 'Kein Peer mit Portfolio in der Team-Kette — Benchmark erst ab 2 Teams aussagekräftig.'
                : 'Peer-Median über ' . $b['n_peers'] . ' anonyme Team(s) derselben Kette.',
        ]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'query',
            'read_only' => true,
            'idempotent' => true,
            'risk_level' => 'safe',
            'requires_auth' => true,
            'requires_team' => true,
            'cost_class' => 'local_db',
            'tags' => ['foodalchemist', 'benchmark', 'portfolio', 'kennzahlen', 'peer', 'controlling'],
            'examples' => ['Wie steht unser Portfolio im internen Benchmark da?'],
        ];
    }
}
