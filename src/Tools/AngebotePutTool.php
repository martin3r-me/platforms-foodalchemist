<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Models\FoodAlchemistAngebot;
use Platform\FoodAlchemist\Services\AngebotService;

/** MCP-Steuerbarkeit · D10: Angebot-Stammdaten bearbeiten (Anlass, Pax, Datum, Preis-Modus). */
class AngebotePutTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    private const FELDER = ['name', 'occasion', 'personen', 'budget', 'event_date', 'location', 'diet_requirement',
        'brief', 'total_price', 'valid_until', 'description', 'note', 'price_mode', 'price_override_reason', 'price_override_expires_at'];

    public function getName(): string
    {
        return 'foodalchemist.angebote.PUT';
    }

    public function getDescription(): string
    {
        return 'Bearbeitet die Stammdaten eines team-eigenen Angebots (felder: name, occasion, personen, event_date, '
            . 'valid_until, price_mode, total_price, …). price_mode=fixed erfordert total_price + Begründung. Status via angebote.STATUS.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'id' => ['type' => 'integer', 'description' => 'Angebot-Id.'],
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
            return ToolResult::error('Keine bekannten Felder in felder (Status via angebote.STATUS).', 'VALIDATION_ERROR');
        }
        $id = (int) ($arguments['id'] ?? 0);
        if (($guard = $this->guardOwned($team, FoodAlchemistAngebot::class, $id, 'Angebot')) !== null) {
            return $guard;
        }

        try {
            app(AngebotService::class)->update($team, $id, $in);
        } catch (\RuntimeException $e) {
            return ToolResult::error($e->getMessage(), 'VALIDATION_ERROR');
        }

        return ToolResult::success(['id' => $id, 'updated' => array_keys($in)]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['foodalchemist', 'angebot', 'write'],
            'read_only' => false, 'idempotent' => true, 'risk_level' => 'write',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'side_effects' => ['updates'],
            'related_tools' => ['foodalchemist.angebote.STATUS', 'foodalchemist.angebote.RECOMPUTE'],
            'examples' => ['Setze bei Angebot 5 die Personenzahl auf 80.'],
        ];
    }
}
