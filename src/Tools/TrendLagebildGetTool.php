<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\LagebildService;

/** Observatorium zentral: wöchentliche Lagebilder lesen (neueste zuerst, Belege aufgelöst). Kein KI-Aufruf. */
class TrendLagebildGetTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.trend_lagebild.GET';
    }

    public function getDescription(): string
    {
        return 'Liest die wöchentlichen Lagebilder des Gastro-Observatoriums (neueste zuerst): Titel, Zeitraum, Kurzfazit, Aussagen und Impulse, '
            .'jeweils mit aufgelösten Belegen (Eingangs-ID, Titel, Herausgeber, URL) bzw. markt=true für „Markt in Zahlen“. Kein KI-Aufruf.';
    }

    public function getSchema(): array
    {
        return ['type' => 'object', 'properties' => ['limit' => ['type' => 'integer', 'description' => 'Anzahl (1–52, Standard 6)']]];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }

        return ToolResult::success(['lagebilder' => app(LagebildService::class)->liste($team, (int) ($arguments['limit'] ?? 6))->all()]);
    }

    public function getMetadata(): array
    {
        return ['category' => 'query', 'tags' => ['foodalchemist', 'observatorium', 'lagebild'], 'read_only' => true,
            'idempotent' => true, 'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db', 'side_effects' => [],
            'related_tools' => ['foodalchemist.trend_lagebild.POST', 'foodalchemist.trend_eingang.GET']];
    }
}
