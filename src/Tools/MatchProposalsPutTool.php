<?php

namespace Platform\FoodAlchemist\Tools;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\MatchService;

/** MCP-Steuerbarkeit · D4: LA→GP-Match-Vorschlag übernehmen (accept) oder verwerfen (reject). */
class MatchProposalsPutTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.match_proposals.PUT';
    }

    public function getDescription(): string
    {
        return 'Übernimmt (accept) oder verwirft (reject) einen LA→GP-Match-Vorschlag aus einem match.RUN.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'proposal_id' => ['type' => 'integer', 'description' => 'Match-Vorschlags-Id.'],
                'action' => ['type' => 'string', 'enum' => ['accept', 'reject'], 'description' => 'Übernehmen oder verwerfen.'],
            ],
            'required' => ['proposal_id', 'action'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        $action = (string) ($arguments['action'] ?? '');
        if (! in_array($action, ['accept', 'reject'], true)) {
            return ToolResult::error('action muss accept|reject sein.', 'VALIDATION_ERROR');
        }
        $proposalId = (int) ($arguments['proposal_id'] ?? 0);

        $svc = app(MatchService::class);
        try {
            $action === 'accept' ? $svc->uebernehmeVorschlag($team, $proposalId) : $svc->verwerfeVorschlag($team, $proposalId);
        } catch (ModelNotFoundException $e) {
            return ToolResult::error('Vorschlag nicht vorhanden/sichtbar.', 'NOT_FOUND');
        } catch (\RuntimeException $e) {
            return ToolResult::error($e->getMessage(), 'VALIDATION_ERROR');
        }

        return ToolResult::success(['proposal_id' => $proposalId, 'action' => $action]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['foodalchemist', 'matching', 'vorschlag', 'write'],
            'read_only' => false, 'idempotent' => true, 'risk_level' => 'write',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'side_effects' => ['updates'],
            'related_tools' => ['foodalchemist.match.RUN'],
            'examples' => ['Übernimm Match-Vorschlag 88.'],
        ];
    }
}
