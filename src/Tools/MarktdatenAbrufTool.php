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
        return 'Ruft die festen monatlichen Destatis-Reihen (Preisindizes, Gastgewerbe-Umsatz, Erzeugerpreise) über die kostenlose GENESIS-API ab. Benötigt serverseitigen GENESIS-Token. Standard Probelauf ohne Speicherung; apply=true übernimmt ausschließlich Daten des aktuellen Teams. Reihen ohne Codes stehen in noch_nicht_abrufbar. Nachschlagen statt raten: erkunden_tabelle (nur konfigurierte Tabellen) liefert den Tabellenaufbau; mit erkunden_merkmal (+ suche) die Schlüssel eines Merkmals. Erkunden schreibt nie. Keine KI-Aufträge, Artikel oder Radarfreigaben. Ergebnisfehler je Reihe prüfen.';
    }

    public function getSchema(): array
    {
        return ['type' => 'object', 'properties' => [
            'apply' => ['type' => 'boolean', 'default' => false],
            'erkunden_tabelle' => ['type' => 'string', 'enum' => MarktdatenService::TABELLEN, 'description' => 'Nur nachschlagen: Aufbau dieser Tabelle (Merkmale).'],
            'erkunden_merkmal' => ['type' => 'string', 'description' => 'Mit erkunden_tabelle: Schlüssel dieses Merkmals (GENESIS-Code).'],
            'suche' => ['type' => 'string', 'description' => 'Filter für erkunden_merkmal (Code oder Text, z. B. „fleisch“).'],
        ]];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);

        if (! $team) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        $svc = app(MarktdatenService::class);
        if (($tabelle = $arguments['erkunden_tabelle'] ?? null) !== null) {
            try {
                return ToolResult::success($svc->erkunden($team, (string) $tabelle, isset($arguments['erkunden_merkmal']) ? (string) $arguments['erkunden_merkmal'] : null,
                    isset($arguments['suche']) ? (string) $arguments['suche'] : null));
            } catch (\RuntimeException $e) {
                return ToolResult::error($e->getMessage(), 'ERKUNDEN_FEHLGESCHLAGEN');
            }
        }

        return ToolResult::success($svc->abrufen($team, ($arguments['apply'] ?? false) === true));
    }

    public function getMetadata(): array
    {
        return ['category' => 'action', 'tags' => ['foodalchemist', 'destatis', 'markt'], 'read_only' => false,
            'idempotent' => true, 'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'external_api',
            'side_effects' => ['HTTP-Abruf Destatis', 'apply=true: eigene Marktdaten aktualisieren']];
    }
}
