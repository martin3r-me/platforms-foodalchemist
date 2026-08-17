<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Models\FoodAlchemistPlanningSession;
use Platform\FoodAlchemist\Services\PlanningCascadeService;

/**
 * Etappe 9 · Slice 2 (Roadmap »Mise en Place«) — MCP-Kaskaden-**START** (Go), WRITE.
 *
 * Startet für eine team-eigene Planungs-Session einen gestuften Kaskaden-Lauf (scope
 * rezept|gericht|concept) headless — der Lauf hält danach an den Ebenen-Gates (Freigabe) an,
 * generiert also nicht durch. Brief aus der Session (oder `brief`-Override), Leitplanken aus den
 * persistierten `generation_params` der Session.
 *
 * ⚠ **Bewusste Aufhebung der bisherigen human-only-Regel** (Entscheidung Dominique 2026-08-17):
 * bis hierher waren „Go"/Freigabe per Nordstern menschlich (UI-only); der Kaskaden-Trigger via MCP
 * ist nun freigegeben. Der Schutz bleibt die **Tenancy** — nur das BESITZER-Team darf starten
 * (`isOwnedBy`, D1/Slice 4), eine bloß geerbte Session wird abgewiesen.
 */
class PlanungKaskadeStartPostTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    /** MCP-startbare Einstiegs-Ebenen (NICHT vollkaskade — die braucht einen Ausgabe-Owner). */
    private const SCOPES = ['rezept', 'gericht', 'concept'];

    public function getName(): string
    {
        return 'foodalchemist.planung_kaskade.START';
    }

    public function getDescription(): string
    {
        return 'Startet einen gestuften Planungs-Kaskaden-Lauf für eine team-EIGENE Session (Go), headless. '
            . 'scope = rezept|gericht|concept (Einstiegs-Ebene). Der Lauf hält an den Ebenen-Gates an '
            . '(Freigabe separat). Brief aus der Session (oder brief-Override), Leitplanken aus den '
            . 'generation_params der Session. Nur das Besitzer-Team darf starten (isOwnedBy). Liefert den '
            . 'Lauf-Status wie planung_kaskade.GET zurück.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'session_id' => ['type' => 'integer', 'description' => 'ID der Planungs-Session (team-eigen).'],
                'scope' => ['type' => 'string', 'enum' => self::SCOPES, 'description' => 'Einstiegs-Ebene der Kaskade.'],
                'brief' => ['type' => 'string', 'description' => 'Optionaler Brief-Override (sonst der Session-Brief).'],
            ],
            'required' => ['session_id', 'scope'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        $scope = (string) ($arguments['scope'] ?? '');
        if (! in_array($scope, self::SCOPES, true)) {
            return ToolResult::error('scope muss rezept|gericht|concept sein.', 'VALIDATION_ERROR');
        }

        // Tenancy: team-sichtbar UND team-EIGEN (Writes isOwnedBy, Slice 4). Geerbt/fremd → abgewiesen.
        $session = FoodAlchemistPlanningSession::visibleToTeam($team)->find((int) $arguments['session_id']);
        if ($session === null) {
            return ToolResult::error('Planungs-Session nicht gefunden oder nicht team-sichtbar.', 'NOT_FOUND');
        }
        if (! $session->isOwnedBy($team)) {
            return ToolResult::error('Geerbte Session — nur das Besitzer-Team darf die Kaskade starten (D1).', 'INHERITED');
        }

        $optionen = [
            'created_via' => 'mcp',
            'params' => is_array($session->generation_params) ? $session->generation_params : [],
            'voll_anreichern' => false,
        ];
        $brief = trim((string) ($arguments['brief'] ?? ''));
        if ($brief !== '') {
            $optionen['brief'] = $brief;
        }

        try {
            $run = app(PlanningCascadeService::class)->starteKaskade($team, $scope, $session, (string) $session->creative_mode, $optionen);
        } catch (\RuntimeException $e) {
            return ToolResult::error($e->getMessage(), 'START_FAILED');
        }

        $status = app(PlanningCascadeService::class)->laufStatus($team, (int) $run->id);

        return ToolResult::success($status ?? ['run_id' => (int) $run->id, 'status' => (string) $run->status]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'mutation',
            'tags' => ['foodalchemist', 'planung', 'kaskade', 'leitstelle', 'go', 'start', 'headless'],
            'read_only' => false,
            'idempotent' => false,
            'risk_level' => 'write',
            'requires_auth' => true,
            'requires_team' => true,
            'cost_class' => 'local_db',
            'related_tools' => ['foodalchemist.planung_kaskade.GET', 'foodalchemist.planung_kaskade.FREIGABE', 'foodalchemist.planung_session.POST'],
            'examples' => ['Starte die Gericht-Kaskade für Planung 42', 'Go: Concept-Kaskade für Session 42'],
        ];
    }
}
