<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\PlanningSessionService;

/**
 * Planungs-/Kreativ-Sessions lesen (Doppel-Diamant, Spec 08). Ohne `id` alle team-sichtbaren
 * Sessions; mit `id` eine einzelne. Read-only, team-scoped. Sessions sind Container VOR dem
 * Grounding — sie erzeugen nichts; das „Go" (human-only, UI) materialisiert.
 */
class PlanungSessionGetTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.planung_session.GET';
    }

    public function getDescription(): string
    {
        return 'Listet Planungs-/Kreativ-Sessions (Doppel-Diamant) — ohne id alle team-sichtbaren, '
            . 'mit id eine einzelne. Felder: title, status (divergenz|konvergenz|erledigt), creative_mode, '
            . 'source_knowledge_document_id (Trend-Herkunft), brief, analysis, generation_params (gesetzte '
            . 'Richtungs-Regler/Leitplanken). Read-only.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'id' => ['type' => 'integer', 'description' => 'Optional: eine einzelne Session'],
            ],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        $svc = app(PlanningSessionService::class);

        if (isset($arguments['id'])) {
            $session = $svc->get($team, (int) $arguments['id']);
            if ($session === null) {
                return ToolResult::error('Session nicht sichtbar/vorhanden.', 'NOT_FOUND');
            }

            return ToolResult::success(['session' => $this->arr($session)]);
        }

        $sessions = $svc->list($team)->map(fn ($s) => $this->arr($s))->all();

        return ToolResult::success(['sessions' => $sessions, 'total' => count($sessions)]);
    }

    private function arr($s): array
    {
        return [
            'id' => (int) $s->id,
            'title' => (string) $s->title,
            'status' => (string) $s->status,
            'creative_mode' => (string) $s->creative_mode,
            'source_knowledge_document_id' => $s->source_knowledge_document_id !== null ? (int) $s->source_knowledge_document_id : null,
            'brief' => $s->brief,
            'analysis' => $s->analysis,
            'generation_params' => $s->generation_params,
            'created_via' => $s->created_via,
            'updated_at' => optional($s->updated_at)->toDateTimeString(),
        ];
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'query',
            'tags' => ['foodalchemist', 'planung', 'kreativ', 'session', 'doppel-diamant', 'trend'],
            'read_only' => true,
            'idempotent' => true,
            'risk_level' => 'safe',
            'requires_auth' => true,
            'requires_team' => true,
            'cost_class' => 'local_db',
            'related_tools' => ['foodalchemist.planung_session.POST', 'foodalchemist.planung_session.PUT'],
            'examples' => ['Zeig mir meine Planungs-Sessions', 'Was steht in Planungs-Session 3?'],
        ];
    }
}
