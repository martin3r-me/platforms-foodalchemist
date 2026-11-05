<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\TrendVertiefungService;

/**
 * Vertiefung im Trend-Eingang anstoßen (TrendEingangVertiefenJob). Ruft selbst keine KI: per MCP läuft der Job extern
 * (KI-Auftrag statt Plattform-Provider, KiUmleitung::LAUF_FLAG), aus der Oberfläche mit Plattform-KI.
 */
class TrendEingangVertiefenTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.trend_eingang.VERTIEFEN';
    }

    public function getDescription(): string
    {
        return 'Vertieft Einträge im Trend-Eingang (Kuratieren, eigenes Team, höchstens 20 je Aufruf): liest die verlinkte Seite bzw. das PDF '
            .'und hält bis zu 6 Kernaussagen mit Zahl, Bezug und Fundstelle fest. Nur Aussagen, deren Zahlen im abgerufenen Text stehen, '
            .'werden übernommen; kein Volltext. Bezahlschranke/Login → Status „bezahl“ (Etikett „Bezahlinhalt – nur Inhaltsangabe“), Bot-Sperre → '
            .'„gesperrt“, ohne KI-Aufruf. Per MCP entsteht je Eintrag ein KI-Auftrag (foodalchemist.ki_auftraege.GET → ANTWORT). '
            .'Bereits vertiefte Einträge nur mit neu=true. Ergebnis in foodalchemist.trend_eingang.GET (vertiefung).';
    }

    public function getSchema(): array
    {
        return ['type' => 'object', 'required' => ['ids'], 'properties' => [
            'ids' => ['type' => 'array', 'items' => ['type' => 'integer'], 'description' => 'Eintrag-IDs (höchstens 20).'],
            'neu' => ['type' => 'boolean', 'default' => false, 'description' => 'Auch bereits vertiefte Einträge neu vertiefen.'],
        ]];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        $ids = array_values(array_map('intval', (array) ($arguments['ids'] ?? [])));
        if ($ids === []) {
            return ToolResult::error('ids fehlt.', 'VALIDATION_ERROR');
        }
        try {
            $angestossen = app(TrendVertiefungService::class)->anstossen($team, $ids, $context->user?->id, ($arguments['neu'] ?? false) === true);
        } catch (\RuntimeException $e) {
            return ToolResult::error($e->getMessage(), 'VALIDATION_ERROR');
        }

        return ToolResult::success(['angestossen' => count($angestossen), 'ids' => $angestossen,
            'hinweis' => $angestossen === [] ? 'Nichts angestoßen (unbekannt, läuft schon oder schon vertieft — dann neu=true).'
                : 'Per MCP: offene KI-Aufträge mit foodalchemist.ki_auftraege.GET holen und beantworten.']);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action', 'tags' => ['foodalchemist', 'trendradar', 'eingang', 'observatorium', 'ki', 'write'],
            'read_only' => false, 'idempotent' => true, 'risk_level' => 'write',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'llm', 'side_effects' => ['updates', 'HTTP-Abruf der verlinkten Seite'],
            'related_tools' => ['foodalchemist.trend_eingang.GET', 'foodalchemist.ki_auftraege.GET', 'foodalchemist.ki_auftraege.ANTWORT'],
            'examples' => ['Vertief den Eintrag 605 (Kitchen Barometer).'],
        ];
    }
}
