<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Models\FoodAlchemistPrice;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem;
use Platform\FoodAlchemist\Services\PriceService;

/** MCP-Steuerbarkeit · D4: eigenen Preis-Eintrag (team_id = Team) eines sichtbaren Lieferantenartikels löschen (Spec 82 3a). */
class ArtikelPreiseDeleteTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.artikel_preise.DELETE';
    }

    public function getDescription(): string
    {
        return 'Löscht einen eigenen Preis-Eintrag des Teams an einem sichtbaren Lieferantenartikel. Fremde und globale Preise sind gesperrt.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'id' => ['type' => 'integer', 'description' => 'Artikel-Id (für das Team sichtbar).'],
                'price_id' => ['type' => 'integer', 'description' => 'Preis-Id.'],
            ],
            'required' => ['id', 'price_id'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        $id = (int) ($arguments['id'] ?? 0);
        $item = FoodAlchemistSupplierItem::visibleToTeam($team)->whereKey($id)->first();
        if ($item === null) {
            return ToolResult::error('Artikel nicht sichtbar/vorhanden.', 'NOT_FOUND');
        }
        // Spec 82 3a: Preiszeilen gehören dem Team, das sie geschrieben hat — nie fremde ändern.
        $fremd = FoodAlchemistPrice::where('supplier_item_id', $item->id)->whereKey((int) ($arguments['price_id'] ?? 0))
            ->where(fn ($w) => $w->whereNull('team_id')->orWhere('team_id', '!=', $team->id))->exists();
        if ($fremd) {
            return ToolResult::error('Preis-Eintrag gehört einem anderen Team (oder ist global) — nur eigene Preise sind änderbar.', 'ACCESS_DENIED');
        }

        try {
            app(PriceService::class)->deleteFor($team, $item, (int) ($arguments['price_id'] ?? 0));
        } catch (\RuntimeException $e) {
            return ToolResult::error($e->getMessage(), 'VALIDATION_ERROR');
        }

        return ToolResult::success(['id' => $id, 'price_id' => (int) ($arguments['price_id'] ?? 0), 'deleted' => true]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['foodalchemist', 'artikel', 'preis', 'loeschen', 'write'],
            'read_only' => false, 'idempotent' => true, 'risk_level' => 'write',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'side_effects' => ['deletes'],
            'related_tools' => ['foodalchemist.artikel_preise.POST'],
            'examples' => ['Lösche Preis 55 von Artikel 4711.'],
        ];
    }
}
