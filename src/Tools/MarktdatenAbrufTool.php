<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\MarktdatenService;

class MarktdatenAbrufTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.marktdaten.ABRUF';
    }

    public function getDescription(): string
    {
        return 'Ruft zwei feste monatliche Destatis-Preisindexreihen über die kostenlose GENESIS-API ab. Benötigt serverseitigen GENESIS-Token. Standard Probelauf ohne Speicherung; apply=true übernimmt ausschließlich Daten des aktuellen Teams. Keine KI-Aufträge, Artikel oder Radarfreigaben. Ergebnisfehler je Reihe prüfen.';
    }

    public function getSchema(): array
    {
        return ['type' => 'object', 'properties' => ['apply' => ['type' => 'boolean', 'default' => false]]];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);

        return $team ? ToolResult::success(app(MarktdatenService::class)->abrufen($team, ($arguments['apply'] ?? false) === true)) : ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
    }

    public function getMetadata(): array
    {
        return ['category' => 'action', 'tags' => ['foodalchemist', 'destatis', 'markt'], 'read_only' => false,
            'idempotent' => true, 'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'external_api',
            'side_effects' => ['HTTP-Abruf Destatis', 'apply=true: eigene Marktdaten aktualisieren']];
    }
}
