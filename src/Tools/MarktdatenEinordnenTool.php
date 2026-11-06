<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\MarktEinordnungService;

/**
 * KI-Einordnung „Markt in Zahlen“ aus den gespeicherten Werten anstoßen — ohne neuen Destatis-Abruf (Kuratieren). Ruft selbst
 * keine KI: MarktEinordnungJob, per MCP extern (KI-Auftrag), KiUmleitung::LAUF_FLAG. Gleicher Werte-Stand → kein zweiter Aufruf.
 */
class MarktdatenEinordnenTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.marktdaten.EINORDNEN';
    }

    public function getDescription(): string
    {
        return 'Stößt die KI-Einordnung „Was heißt das für Caterer und Gastronomie“ aus den gespeicherten Marktdaten an (Kuratieren, ohne neuen Abruf). '
            .'Nur wenn es zum aktuellen Werte-Stand noch keine Einordnung gibt (eigene oder geerbte). Per MCP entsteht ein KI-Auftrag '
            .'(foodalchemist.ki_auftraege.GET → ANTWORT); Sätze mit unbelegten Zahlen, Prognosen oder Empfehlungen werden verworfen. '
            .'Ergebnis in foodalchemist.marktdaten.GET (einordnung).';
    }

    public function getSchema(): array
    {
        return ['type' => 'object', 'properties' => new \stdClass];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        try {
            $r = app(MarktEinordnungService::class)->einordnen($team, $context->user?->id);
        } catch (\RuntimeException $e) {
            return ToolResult::error($e->getMessage(), 'VALIDATION_ERROR');
        }

        return ToolResult::success($r + ['hinweis' => $r['angestossen'] ? 'Per MCP: offenen KI-Auftrag mit foodalchemist.ki_auftraege.GET holen und beantworten.' : null]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action', 'tags' => ['foodalchemist', 'destatis', 'markt', 'ki', 'write'],
            'read_only' => false, 'idempotent' => true, 'risk_level' => 'write',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'llm', 'side_effects' => ['KI-Einordnung anlegen'],
            'related_tools' => ['foodalchemist.marktdaten.GET', 'foodalchemist.marktdaten.ABRUF', 'foodalchemist.ki_auftraege.GET'],
            'examples' => ['Erzeug die Markt-Einordnung zum aktuellen Stand.'],
        ];
    }
}
