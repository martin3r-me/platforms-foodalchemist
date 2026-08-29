<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem;
use Platform\FoodAlchemist\Services\PriceService;

/** MCP-Steuerbarkeit · D4: Preis-Eintrag eines team-eigenen Lieferantenartikels bearbeiten. */
class ArtikelPreisePutTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.artikel_preise.PUT';
    }

    public function getDescription(): string
    {
        return 'Bearbeitet einen Preis-Eintrag eines team-eigenen Lieferantenartikels. felder: price (≥0), valid_to, note.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'id' => ['type' => 'integer', 'description' => 'Artikel-Id (team-eigen).'],
                'price_id' => ['type' => 'integer', 'description' => 'Preis-Id.'],
                'felder' => ['type' => 'object', 'description' => 'price, valid_to, note.'],
            ],
            'required' => ['id', 'price_id', 'felder'],
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
            $p = app(PriceService::class)->updatePrice($team, $item, (int) ($arguments['price_id'] ?? 0), $felder);
        } catch (\RuntimeException $e) {
            return ToolResult::error($e->getMessage(), 'VALIDATION_ERROR');
        }

        return ToolResult::success(['id' => $id, 'price_id' => (int) $p->id, 'price' => (float) $p->price]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['foodalchemist', 'artikel', 'preis', 'bearbeiten', 'write'],
            'read_only' => false, 'idempotent' => true, 'risk_level' => 'write',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'side_effects' => ['updates'],
            'related_tools' => ['foodalchemist.artikel_preise.POST', 'foodalchemist.artikel_preise.DELETE'],
            'examples' => ['Ändere Preis 55 von Artikel 4711 auf 9,40.'],
        ];
    }
}
