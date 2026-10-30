<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\TrendEingangService;

/**
 * Spec 93 B · Erschließen im Trend-Eingang anstoßen (TrendEingangErschliessenJob). Ruft selbst keine KI: die Jobs laufen per
 * MCP extern (MCP-Herkunft-Merker → KI-Auftrag statt Plattform-Provider), aus der Oberfläche mit Plattform-KI.
 */
class TrendEingangErschliessenTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.trend_eingang.ERSCHLIESSEN';
    }

    public function getDescription(): string
    {
        return 'Stößt das Erschließen von Einträgen im Trend-Eingang an (Kuratieren, eigenes Team): ids oder ohne ids alle offenen (neu/fehler), '
            .'älteste zuerst, höchstens 50 je Aufruf. Per MCP entsteht je Artikel ein KI-Auftrag (foodalchemist.ki_auftraege.GET → ANTWORT): '
            .'relevant, eigene Kurz-Zusammenfassung, Thema, Kategorie, Sparten, Tiefe. Danach landen relevante Artikel gebündelt in einem '
            .'automatischen Thema (nie auf der Pinnwand), nicht relevante werden verworfen. Neue Einträge werden ohnehin automatisch erschlossen.';
    }

    public function getSchema(): array
    {
        return ['type' => 'object', 'properties' => [
            'ids' => ['type' => 'array', 'items' => ['type' => 'integer'], 'description' => 'Ohne ids: alle offenen (höchstens 50).'],
        ]];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        $ids = isset($arguments['ids']) ? array_values(array_map('intval', (array) $arguments['ids'])) : null;
        try {
            $angestossen = app(TrendEingangService::class)->erschliessenAnstossen($team, $ids, $context->user?->id);
        } catch (\RuntimeException $e) {
            return ToolResult::error($e->getMessage(), 'VALIDATION_ERROR');
        }

        return ToolResult::success(['angestossen' => count($angestossen), 'ids' => $angestossen,
            'hinweis' => $angestossen === [] ? 'Nichts offen.' : 'Per MCP: offene KI-Aufträge mit foodalchemist.ki_auftraege.GET holen und beantworten.']);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action', 'tags' => ['foodalchemist', 'trendradar', 'eingang', 'ki', 'write'],
            'read_only' => false, 'idempotent' => true, 'risk_level' => 'write',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'llm_call', 'side_effects' => ['updates'],
            'related_tools' => ['foodalchemist.trend_eingang.GET', 'foodalchemist.ki_auftraege.GET', 'foodalchemist.ki_auftraege.ANTWORT'],
            'examples' => ['Erschließ alle neuen Artikel im Trend-Eingang.'],
        ];
    }
}
