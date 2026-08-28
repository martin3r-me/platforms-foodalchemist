<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\BulkEnrichService;

/**
 * MCP-Steuerbarkeit · D1: GP-Anreicherungs-Vorschläge übernehmen/verwerfen (nach gps.ENRICH-Lauf).
 *
 * - accept_all + run_id  → alle offenen Vorschläge des Laufs übernehmen (Override-First, Lineage ki)
 * - accept  + proposal_id → einen Vorschlag übernehmen
 * - reject  + proposal_id → einen Vorschlag verwerfen
 * Übernahme schreibt nur team-eigene GPs (Service-Guard).
 */
class GpEnrichResolveTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.gp_enrich.RESOLVE';
    }

    public function getDescription(): string
    {
        return 'Übernimmt/verwirft GP-Anreicherungs-Vorschläge aus einem gps.ENRICH-Lauf. '
            . 'action=accept_all (mit run_id) übernimmt alle offenen; accept/reject (mit proposal_id) einen einzelnen.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'action' => ['type' => 'string', 'enum' => ['accept_all', 'accept', 'reject'], 'description' => 'Aktion.'],
                'run_id' => ['type' => 'integer', 'description' => 'Lauf-Id (bei accept_all).'],
                'proposal_id' => ['type' => 'integer', 'description' => 'Vorschlags-Id (bei accept/reject).'],
            ],
            'required' => ['action'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }

        $action = (string) ($arguments['action'] ?? '');
        $svc = app(BulkEnrichService::class);

        if ($action === 'accept_all') {
            $runId = (int) ($arguments['run_id'] ?? 0);
            if ($runId <= 0) {
                return ToolResult::error('accept_all erfordert run_id.', 'VALIDATION_ERROR');
            }
            $n = $svc->alleUebernehmenGp($team, $runId);

            return ToolResult::success(['action' => $action, 'run_id' => $runId, 'uebernommen' => $n]);
        }

        if ($action === 'accept' || $action === 'reject') {
            $proposalId = (int) ($arguments['proposal_id'] ?? 0);
            if ($proposalId <= 0) {
                return ToolResult::error("{$action} erfordert proposal_id.", 'VALIDATION_ERROR');
            }
            if ($action === 'accept') {
                $ok = $svc->uebernehmenGp($team, $proposalId);
                if (! $ok) {
                    return ToolResult::error('Vorschlag nicht offen/sichtbar oder GP nicht eigen.', 'NOT_FOUND');
                }

                return ToolResult::success(['action' => $action, 'proposal_id' => $proposalId, 'uebernommen' => true]);
            }
            $svc->verwerfenGp($team, $proposalId);

            return ToolResult::success(['action' => $action, 'proposal_id' => $proposalId, 'verworfen' => true]);
        }

        return ToolResult::error('action muss accept_all|accept|reject sein.', 'VALIDATION_ERROR');
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['foodalchemist', 'gp', 'anreicherung', 'freigabe', 'write'],
            'read_only' => false, 'idempotent' => false, 'risk_level' => 'write',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'side_effects' => ['updates'],
            'related_tools' => ['foodalchemist.gps.ENRICH'],
            'examples' => ['Übernimm alle Anreicherungs-Vorschläge aus Lauf 42.'],
        ];
    }
}
