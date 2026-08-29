<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem;
use Platform\FoodAlchemist\Services\SupplierItemService;

/** MCP-Steuerbarkeit · D4: Stammdaten eines team-eigenen Lieferantenartikels bearbeiten (Whitelist im Service). */
class ArtikelPutTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.artikel.PUT';
    }

    public function getDescription(): string
    {
        return 'Bearbeitet Stammdaten/Verpackung/Eigenschaften eines team-eigenen Lieferantenartikels. '
            . 'felder: designation, article_number, brand, manufacturer, origin, qty, unit_code, ean_packaging, '
            . 'is_organic, vat, origin_country, preorder_days … (nur Whitelist wirkt).';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'id' => ['type' => 'integer', 'description' => 'Artikel-Id (team-eigen).'],
                'felder' => ['type' => 'object', 'description' => 'Zu schreibende Artikel-Felder (nur Whitelist wirkt).'],
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
        $id = (int) ($arguments['id'] ?? 0);
        $item = FoodAlchemistSupplierItem::visibleToTeam($team)->whereKey($id)->first();
        if ($item === null) {
            return ToolResult::error('Artikel nicht sichtbar/vorhanden.', 'NOT_FOUND');
        }
        if (! $item->isOwnedBy($team)) {
            return ToolResult::error('Geerbter Katalog-Artikel — nur fürs Besitzer-Team.', 'ACCESS_DENIED');
        }

        try {
            $item = app(SupplierItemService::class)->update($team, $item, $felder);
        } catch (\RuntimeException $e) {
            return ToolResult::error($e->getMessage(), 'VALIDATION_ERROR');
        }

        return ToolResult::success(['id' => (int) $item->id, 'designation' => $item->designation]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['foodalchemist', 'artikel', 'bearbeiten', 'write'],
            'read_only' => false, 'idempotent' => true, 'risk_level' => 'write',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'side_effects' => ['updates'],
            'related_tools' => ['foodalchemist.artikel.POST', 'foodalchemist.artikel_preise.PUT'],
            'examples' => ['Ändere bei Artikel 4711 die Bezeichnung und die Verpackungsmenge.'],
        ];
    }
}
