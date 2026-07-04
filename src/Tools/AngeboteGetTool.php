<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\AngebotService;

/** Phase C: Angebots-Detail inkl. Kalkulation (EK/VK/Marge über alle Concepts). */
class AngeboteGetTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.angebote.GET';
    }

    public function getDescription(): string
    {
        return 'Liefert ein Angebot im Detail: Stammdaten, verknüpfte Concepts und die Kalkulation '
            . '(Preis/EK/Marge pro Concept + gesamt).';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => ['offer_id' => ['type' => 'integer']],
            'required' => ['offer_id'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        $svc = app(AngebotService::class);
        $a = $svc->detail($team, (int) $arguments['offer_id']);
        if ($a === null) {
            return ToolResult::error('Angebot nicht sichtbar/vorhanden.', 'NOT_FOUND');
        }

        return ToolResult::success([
            'angebot' => [
                'id' => $a->id, 'name' => $a->name,
                'status' => $a->status instanceof \BackedEnum ? $a->status->value : $a->status,
                'anlass' => $a->anlass, 'personen' => $a->personen,
            ],
            'kalkulation' => $svc->kalkulation($team, $a),
        ]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'query',
            'tags' => ['foodalchemist', 'angebot', 'kalkulation', 'marge', 'detail'],
            'read_only' => true, 'idempotent' => true, 'risk_level' => 'safe',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'related_tools' => ['foodalchemist.angebote.SEARCH', 'foodalchemist.concepts.GET'],
            'examples' => ['Zeig mir Angebot 7 mit Kalkulation'],
        ];
    }
}
