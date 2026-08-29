<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem;
use Platform\FoodAlchemist\Services\SupplierItemService;

/** MCP-Steuerbarkeit · D4: Deklarationen (Zusatzstoffe/Kennzeichnungen) eines team-eigenen Artikels setzen. */
class ArtikelDeklarationenPutTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.artikel_deklarationen.PUT';
    }

    public function getDescription(): string
    {
        return 'Setzt die Deklarationen (Zusatzstoffe/Kennzeichnungen) eines team-eigenen Lieferantenartikels.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'id' => ['type' => 'integer', 'description' => 'Artikel-Id (team-eigen).'],
                'werte' => ['type' => 'object', 'description' => 'Deklarations-Map.'],
            ],
            'required' => ['id', 'werte'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        $werte = $arguments['werte'] ?? null;
        if (! is_array($werte)) {
            return ToolResult::error('werte muss ein Objekt sein.', 'VALIDATION_ERROR');
        }
        $id = (int) ($arguments['id'] ?? 0);
        $item = FoodAlchemistSupplierItem::visibleToTeam($team)->whereKey($id)->first();
        if ($item === null) {
            return ToolResult::error('Artikel nicht sichtbar/vorhanden.', 'NOT_FOUND');
        }
        if (! $item->isOwnedBy($team)) {
            return ToolResult::error('Geerbter Katalog-Artikel — nur fürs Besitzer-Team.', 'ACCESS_DENIED');
        }

        try {
            app(SupplierItemService::class)->setDeclarations($team, $item, $werte, 'manual');
        } catch (\RuntimeException $e) {
            return ToolResult::error($e->getMessage(), 'VALIDATION_ERROR');
        }

        return ToolResult::success(['id' => $id, 'updated' => true]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['foodalchemist', 'artikel', 'deklarationen', 'write'],
            'read_only' => false, 'idempotent' => true, 'risk_level' => 'write',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'side_effects' => ['updates'],
            'related_tools' => ['foodalchemist.artikel_allergene.PUT', 'foodalchemist.artikel_naehrwerte.PUT'],
            'examples' => ['Setze die Deklarationen von Artikel 4711.'],
        ];
    }
}
