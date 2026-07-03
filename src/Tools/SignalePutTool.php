<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\SignalService;

/** Phase C: Signal-Status setzen — alle Aktionen reversibel (wieder_oeffnen). */
class SignalePutTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.signale.PUT';
    }

    public function getDescription(): string
    {
        return 'Setzt den Status eines Signals: abschliessen (erledigt), ignorieren (bewusst weg), '
            . 'wieder_oeffnen (zurück auf offen). Reversibel — kein Datenverlust.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'signal_id' => ['type' => 'integer'],
                'aktion' => ['type' => 'string', 'enum' => ['abschliessen', 'ignorieren', 'wieder_oeffnen']],
            ],
            'required' => ['signal_id', 'aktion'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        $svc = app(SignalService::class);
        $id = (int) $arguments['signal_id'];

        try {
            match ($arguments['aktion']) {
                'abschliessen' => $svc->abschliessen($team, $id),
                'ignorieren' => $svc->ignorieren($team, $id),
                'wieder_oeffnen' => $svc->wiederOeffnen($team, $id),
            };
        } catch (\Throwable $e) {
            return ToolResult::error('Signal nicht gefunden oder Aktion fehlgeschlagen.', 'NOT_FOUND');
        }

        return ToolResult::success(['signal_id' => $id, 'aktion' => $arguments['aktion'], 'ok' => true]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['foodalchemist', 'signal', 'alert', 'abschliessen', 'status'],
            'read_only' => false, 'idempotent' => true, 'risk_level' => 'write',
            'requires_auth' => true, 'requires_team' => true,
            'side_effects' => ['updates'], 'cost_class' => 'local_db',
            'related_tools' => ['foodalchemist.signale.SEARCH'],
            'examples' => ['Schließe Signal 5 ab', 'Öffne Signal 5 wieder'],
        ];
    }
}
