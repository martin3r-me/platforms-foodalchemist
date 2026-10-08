<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\InventurService;

/** Spec 66 §6 · Zählen + Positionen nachtragen in einer offenen Inventur. */
class InventoryCountsPutTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.inventory_counts.PUT';
    }

    public function getDescription(): string
    {
        return 'Trägt in einer OFFENEN Inventur gezählte Mengen ein und/oder fügt Grundprodukte als neue Zeilen hinzu '
            . '(neue_gp_ids). zaehlungen: [{line_id, menge}] mit Menge in kg / l / Stk — ODER bei Zeilen mit Gebinde '
            . '[{line_id, kartons, einheiten, lose}] (lose in kg/l/Stk), FA rechnet die Summe. Alles leer/null = nicht gezählt. '
            . 'Gebuchte Inventuren sind gesperrt.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'count_id' => ['type' => 'integer', 'description' => 'Inventur-Id.'],
                'zaehlungen' => [
                    'type' => 'array',
                    'description' => 'Gezählte Mengen je Zeile.',
                    'items' => ['type' => 'object', 'properties' => [
                        'line_id' => ['type' => 'integer'],
                        'menge' => ['type' => ['number', 'string', 'null'], 'description' => 'kg / l / Stk'],
                        'kartons' => ['type' => ['number', 'string', 'null']],
                        'einheiten' => ['type' => ['number', 'string', 'null']],
                        'lose' => ['type' => ['number', 'string', 'null'], 'description' => 'kg / l / Stk'],
                    ], 'required' => ['line_id']],
                ],
                'neue_gp_ids' => ['type' => 'array', 'items' => ['type' => 'integer'], 'description' => 'Grundprodukte als neue Zeilen.'],
            ],
            'required' => ['count_id'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        $svc = app(InventurService::class);
        $countId = (int) ($arguments['count_id'] ?? 0);
        try {
            $c = $svc->detail($team, $countId);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            return ToolResult::error('Inventur nicht gefunden.', 'NOT_FOUND');
        }
        $zeilenIds = $c->lines->pluck('id')->all();
        $gezaehlt = 0;
        $neu = [];
        $fehler = [];
        try {
            foreach ((array) ($arguments['neue_gp_ids'] ?? []) as $gpId) {
                $l = $svc->positionHinzu($team, $countId, (int) $gpId);
                $neu[] = ['gp_id' => (int) $gpId, 'line_id' => $l->id];
            }
            foreach ((array) ($arguments['zaehlungen'] ?? []) as $z) {
                $lineId = (int) ($z['line_id'] ?? 0);
                if (! in_array($lineId, $zeilenIds, true)) {
                    $fehler[] = ['line_id' => $lineId, 'fehler' => 'Zeile gehört nicht zu dieser Inventur.'];

                    continue;
                }
                try {
                    if (array_key_exists('kartons', $z) || array_key_exists('einheiten', $z) || array_key_exists('lose', $z)) {
                        $svc->zaehlenGebinde($team, $lineId, $z['kartons'] ?? null, $z['einheiten'] ?? null, $z['lose'] ?? null);
                    } else {
                        $svc->zaehlen($team, $lineId, $z['menge'] ?? null);
                    }
                    $gezaehlt++;
                } catch (\RuntimeException $e) {
                    if (str_contains($e->getMessage(), 'gebucht')) {
                        throw $e;
                    }
                    $fehler[] = ['line_id' => $lineId, 'fehler' => $e->getMessage()];
                }
            }
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            return ToolResult::error('Grundprodukt nicht gefunden.', 'NOT_FOUND');
        } catch (\RuntimeException $e) {
            return ToolResult::error($e->getMessage(), 'VALIDATION_ERROR');
        }

        return ToolResult::success([
            'count_id' => $countId, 'gezaehlt' => $gezaehlt, 'neue_zeilen' => $neu, 'fehler' => $fehler,
            'summen' => $svc->summen($svc->detail($team, $countId)),
        ]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['foodalchemist', 'lager', 'inventur', 'write'],
            'read_only' => false, 'idempotent' => true, 'risk_level' => 'write',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'side_effects' => ['updates', 'creates'],
            'related_tools' => ['foodalchemist.inventory_counts.GET', 'foodalchemist.inventory_counts.BOOK'],
            'examples' => ['Trag in Inventur 3 für Mehl 4,5 kg ein.'],
        ];
    }
}
