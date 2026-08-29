<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Models\FoodAlchemistPaket;
use Platform\FoodAlchemist\Services\PaketService;

/** MCP-Steuerbarkeit · D5d: Paket-Stammdaten/Preis-Modus bearbeiten. */
class PaketePutTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    private const FELDER = [
        'name', 'consumer_name', 'role', 'class', 'level', 'price_mode', 'price_per_person',
        'ek_per_person', 'food_cost_percent', 'description', 'note', 'is_inactive',
        'price_override_reason', 'price_override_expires_at',
    ];

    public function getName(): string
    {
        return 'foodalchemist.pakete.PUT';
    }

    public function getDescription(): string
    {
        return 'Bearbeitet ein team-eigenes Paket (felder: name/role/class/level/price_mode/price_per_person/…). '
            . 'price_mode=auto leitet den Preis aus den Gerichten ab; fixed erfordert Preis + Begründung.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'id' => ['type' => 'integer', 'description' => 'Paket-Id.'],
                'felder' => ['type' => 'object', 'description' => 'Zu ändernde Felder (Allow-List).'],
            ],
            'required' => ['id', 'felder'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        $felder = $arguments['felder'] ?? null;
        if (! is_array($felder) || $felder === []) {
            return ToolResult::error('felder muss ein nicht-leeres Objekt sein.', 'VALIDATION_ERROR');
        }
        $in = array_intersect_key($felder, array_flip(self::FELDER));
        if ($in === []) {
            return ToolResult::error('Keine bekannten Felder in felder.', 'VALIDATION_ERROR');
        }
        $id = (int) ($arguments['id'] ?? 0);
        if (($guard = $this->guardOwned($team, FoodAlchemistPaket::class, $id, 'Paket')) !== null) {
            return $guard;
        }

        try {
            $paket = app(PaketService::class)->update($team, $id, $in);
        } catch (\RuntimeException $e) {
            return ToolResult::error($e->getMessage(), 'VALIDATION_ERROR');
        }

        return ToolResult::success(['paket' => $this->paketPayload($paket)]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['foodalchemist', 'paket', 'write'],
            'read_only' => false, 'idempotent' => true, 'risk_level' => 'write',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'side_effects' => ['updates'],
            'related_tools' => ['foodalchemist.pakete.RECOMPUTE', 'foodalchemist.paket_gerichte.SET'],
            'examples' => ['Setze bei Paket 12 den Preis-Modus auf auto.'],
        ];
    }
}
