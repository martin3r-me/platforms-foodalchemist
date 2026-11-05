<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\WettbewerbService;

class WettbewerberZuordnenTool extends WettbewerberTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.wettbewerber.ZUORDNEN';
    }

    public function getDescription(): string
    {
        return 'Prüft den Firmenbezug eines bestehenden eigenen Eingangsartikels: status=bestaetigt veröffentlicht die Zuordnung im Observatorium; abgelehnt verwirft nur diese Zuordnung, vorgeschlagen nimmt sie zur erneuten Prüfung zurück. wettbewerber_id und eingang_id Pflicht. bewegung aus Vokabular, einordnung=eigene Bewertung, kein Quellenfakt. Ändert keine Trendfreigaben.';
    }

    public function getSchema(): array
    {
        return ['type' => 'object', 'properties' => ['wettbewerber_id' => ['type' => 'integer'], 'eingang_id' => ['type' => 'integer'], 'status' => ['type' => 'string', 'enum' => ['vorgeschlagen', 'bestaetigt', 'abgelehnt']], 'bewegung' => ['type' => 'string', 'enum' => array_keys(WettbewerbService::BEWEGUNGEN)], 'einordnung' => ['type' => 'string']], 'required' => ['wettbewerber_id', 'eingang_id', 'status']];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        return $this->ausfuehren($context, fn ($svc, $team) => ['zuordnung' => $svc->entscheiden($team, (int) ($arguments['wettbewerber_id'] ?? 0), (int) ($arguments['eingang_id'] ?? 0), (string) ($arguments['status'] ?? ''), $arguments['bewegung'] ?? null, $arguments['einordnung'] ?? null, $context->user?->id)->toArray()]);
    }
}
