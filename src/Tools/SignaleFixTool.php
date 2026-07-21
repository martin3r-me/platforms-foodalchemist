<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Models\FoodAlchemistSignal;
use Platform\FoodAlchemist\Services\SignalFixService;
use Platform\FoodAlchemist\Support\SignalCockpit;

/**
 * „KI erledigen lassen" per MCP (Lockstep zum Cockpit-Knopf).
 *
 * Plan metrik-fein (SignalCockpit::planFor):
 *  - deterministic → behebt den betroffenen Satz (scoped) und schließt das Signal bei count 0.
 *    Reversibel: das Schließen über foodalchemist.signale.PUT (wieder_oeffnen).
 *  - assist        → erzeugt einen Entwurf/Vorschlag via LLM (kein Schreiben, kein Close).
 *  - kein Plan     → ACTION_NOT_AVAILABLE (reine Urteilssache / externe Daten).
 */
class SignaleFixTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.signale.FIX';
    }

    public function getDescription(): string
    {
        return 'Führt „KI erledigen lassen" für ein Signal aus: deterministischer Auto-Fix (Allergen-Konfidenz, '
            . 'Lead-LA-Repick+Recompute, Flavor-Anker) über den betroffenen Satz → Signal schließt bei 0; ODER '
            . 'eine KI-Assistenz (Lieferanten-Mail-Entwurf, Marge-Hebel, Servierform-Vorschlag) als Entwurf. '
            . 'Nicht jeder Signaltyp ist fixbar (dann ACTION_NOT_AVAILABLE).';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'signal_id' => ['type' => 'integer', 'description' => 'ID des Signals (foodalchemist.signale.SEARCH).'],
            ],
            'required' => ['signal_id'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }

        $sig = FoodAlchemistSignal::visibleToTeam($team)->find((int) ($arguments['signal_id'] ?? 0));
        if ($sig === null) {
            return ToolResult::error('Signal nicht gefunden.', 'NOT_FOUND');
        }

        $plan = SignalCockpit::planFor($sig);
        if ($plan === null) {
            return ToolResult::error('Für dieses Signal gibt es keinen automatischen Fix/Assistenz-Schritt (Urteilssache).', 'ACTION_NOT_AVAILABLE');
        }

        $svc = app(SignalFixService::class);
        try {
            if ($plan['kind'] === 'deterministic') {
                $res = $svc->execute($team, $sig);   // MCP = synchron; UI nutzt den Job

                return ToolResult::success([
                    'signal_id' => (int) $sig->id, 'kind' => 'deterministic',
                    'fixed' => $res['fixed'], 'remaining' => $res['remaining'], 'closed' => $res['closed'],
                ]);
            }

            $res = $svc->assist($team, $sig);

            return ToolResult::success([
                'signal_id' => (int) $sig->id, 'kind' => 'assist',
                'draft' => $res['draft'], 'confidence' => $res['confidence'], 'signal_closed' => false,
            ]);
        } catch (\RuntimeException $e) {
            return ToolResult::error($e->getMessage(), 'EXECUTION_ERROR');
        }
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['foodalchemist', 'signal', 'fix', 'ki', 'assist', 'datenqualitaet'],
            'read_only' => false, 'idempotent' => true, 'risk_level' => 'write',
            'requires_auth' => true, 'requires_team' => true,
            'side_effects' => ['updates'], 'cost_class' => 'local_db',
            'related_tools' => ['foodalchemist.signale.SEARCH', 'foodalchemist.signale.PUT'],
            'examples' => ['Behebe Signal 12 automatisch', 'Erzeuge den KI-Entwurf für Signal 7'],
        ];
    }
}
