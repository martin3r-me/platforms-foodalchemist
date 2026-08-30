<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\PlanningCascadeService;

/**
 * Kapitel-Reconcile (Refinement zum Kapitel-Gate): nachdem der Mensch VOR der Freigabe die Kapitel eines
 * gestuften Foodbook-Laufs editiert hat (foodbook_kapitel.POST/PUT/DELETE), gleicht dieses Tool die
 * geplanten Kapitel-Concept-Schritte mit der aktuellen Struktur ab (ADD neuer, REMOVE gelöschter, Rename).
 * Danach geht man Kapitel für Kapitel über planung_kaskade.FREIGABE. Tenancy: isOwnedBy (Slice 4).
 */
class PlanungKaskadeSyncPostTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.planung_kaskade.SYNC';
    }

    public function getDescription(): string
    {
        return 'Gleicht die geplanten Kapitel-Concept-Schritte eines gestuften Foodbook-Laufs mit der '
            . 'aktuellen Kapitel-Struktur ab — nach dem Editieren der Kapitel (add/remove/rename) VOR der '
            . 'Freigabe. Neu hinzugefügte Kapitel bekommen einen geplanten Schritt, gelöschte werden '
            . 'verworfen, umbenannte bekommen das neue Label. Nur gestufte, team-eigene Foodbook-Läufe. '
            . 'Liefert die Reconcile-Zähler + den Lauf-Status wie planung_kaskade.GET.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'required' => ['run_id'],
            'properties' => [
                'run_id' => ['type' => 'integer', 'description' => 'ID des gestuften Foodbook-Kaskaden-Laufs.'],
            ],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        if (! isset($arguments['run_id'])) {
            return ToolResult::error('run_id ist Pflicht.', 'VALIDATION_ERROR');
        }
        $runId = (int) $arguments['run_id'];
        $svc = app(PlanningCascadeService::class);
        $res = $svc->synchronisiereKapitelSteps($team, $runId);
        if (($res['ok'] ?? false) !== true) {
            return ToolResult::error((string) ($res['grund'] ?? 'Sync nicht möglich.'), 'SYNC_FAILED');
        }
        $status = $svc->laufStatus($team, $runId);

        return ToolResult::success([
            'reconcile' => [
                'ergaenzt' => (int) ($res['ergaenzt'] ?? 0),
                'verworfen' => (int) ($res['verworfen'] ?? 0),
                'umbenannt' => (int) ($res['umbenannt'] ?? 0),
            ],
            'run' => $status,
        ]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'mutation',
            'tags' => ['foodalchemist', 'planung', 'kaskade', 'foodbook', 'kapitel', 'reconcile', 'gestuft'],
            'read_only' => false,
            'idempotent' => true,
            'risk_level' => 'write',
            'requires_auth' => true,
            'requires_team' => true,
            'cost_class' => 'local_db',
            'related_tools' => ['foodalchemist.planung_kaskade.FREIGABE', 'foodalchemist.planung_kaskade.GET', 'foodalchemist.foodbook_kapitel.POST'],
            'examples' => ['Gleiche die Kapitel von Lauf 43 nach dem Editieren ab', 'Reconcile die geplanten Kapitel-Schritte'],
        ];
    }
}
