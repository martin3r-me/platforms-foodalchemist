<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\Trends\TrendAbleitungService;

/**
 * Spec 93 D · „Was heißt das für uns“ für einen Trend erzeugen bzw. neu erzeugen (TrendAbleitungJob). Ruft selbst keine KI: per MCP
 * läuft der Job extern (KI-Auftrag), aus der Oberfläche mit Plattform-KI. Eine von Hand bearbeitete Ableitung bleibt.
 */
class TrendsAbleitungTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.trends.ABLEITUNG';
    }

    public function getDescription(): string
    {
        return 'Erzeugt für einen Trend des eigenen Teams den Abschnitt „Was heißt das für uns“ (Kuratieren): Kurzfazit, Bedeutung je Sparte, '
            .'3–5 Gericht-/Konzept-Impulse. Per MCP entsteht ein KI-Auftrag (foodalchemist.ki_auftraege.GET → ANTWORT). Eine von Hand bearbeitete '
            .'Ableitung bleibt (von Hand ändern: foodalchemist.trends.PUT mit ableitung). „In Planung öffnen“ übernimmt die Ableitung ins Briefing.';
    }

    public function getSchema(): array
    {
        return ['type' => 'object', 'properties' => ['id' => ['type' => 'integer']], 'required' => ['id']];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        try {
            $ok = app(TrendAbleitungService::class)->anstossen($team, (int) ($arguments['id'] ?? 0), $context->user?->id);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            return ToolResult::error('Trend nicht gefunden oder gehört einem anderen Team.', 'NOT_FOUND');
        } catch (\RuntimeException $e) {
            return ToolResult::error($e->getMessage(), 'VALIDATION_ERROR');
        }

        return ToolResult::success($ok
            ? ['angestossen' => true, 'hinweis' => 'Per MCP: offenen KI-Auftrag mit foodalchemist.ki_auftraege.GET holen und beantworten.']
            : ['angestossen' => false, 'hinweis' => 'Die Ableitung wurde von Hand bearbeitet und bleibt. Zum Neuerzeugen erst mit trends.PUT ableitung={} entfernen.']);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action', 'tags' => ['foodalchemist', 'trendradar', 'trends', 'ki', 'write'],
            'read_only' => false, 'idempotent' => true, 'risk_level' => 'write',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'llm_call', 'side_effects' => ['updates'],
            'related_tools' => ['foodalchemist.trends.GET', 'foodalchemist.trends.PUT', 'foodalchemist.ki_auftraege.GET'],
            'examples' => ['Was heißt der Trend „Fermentation“ für uns? Erzeug die Ableitung.'],
        ];
    }
}
