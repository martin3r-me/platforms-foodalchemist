<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem;
use Platform\FoodAlchemist\Services\NonFoodService;
use Platform\FoodAlchemist\Support\NonFoodRegeln;

/** Non-Food-Urteil eines Lieferantenartikels von Hand setzen oder zurücknehmen (team-eigen). Ein Urteil von Hand überschreibt kein Lauf. */
class NonfoodSetTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.nonfood.SET';
    }

    public function getDescription(): string
    {
        return 'Setzt das Non-Food-Urteil eines team-eigenen Lieferantenartikels: ist_nonfood=true (Non-Food, optional mit unterkategorie), '
            .'false (Food bestätigt) oder null (Urteil zurücknehmen). Das Urteil gilt als „manuell“ und wird von keinem Erkennungslauf überschrieben. '
            .'Unterkategorien: '.implode(', ', NonFoodRegeln::unterkategorien()).'.';
    }

    public function getSchema(): array
    {
        return ['type' => 'object', 'properties' => [
            'id' => ['type' => 'integer', 'description' => 'Artikel-Id (team-eigen).'],
            'ist_nonfood' => ['type' => ['boolean', 'null'], 'description' => 'true Non-Food, false Food, null Urteil zurücknehmen.'],
            'unterkategorie' => ['type' => 'string', 'enum' => NonFoodRegeln::unterkategorien()],
        ], 'required' => ['id', 'ist_nonfood']];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        if (! array_key_exists('ist_nonfood', $arguments) || ($arguments['ist_nonfood'] !== null && ! is_bool($arguments['ist_nonfood']))) {
            return ToolResult::error('ist_nonfood muss true, false oder null sein.', 'VALIDATION_ERROR');
        }
        $item = FoodAlchemistSupplierItem::visibleToTeam($team)->whereKey((int) ($arguments['id'] ?? 0))->first();
        if ($item === null) {
            return ToolResult::error('Artikel nicht sichtbar/vorhanden.', 'NOT_FOUND');
        }
        if (! $item->isOwnedBy($team)) {
            return ToolResult::error('Geerbter Katalog-Artikel — nur fürs Besitzer-Team.', 'ACCESS_DENIED');
        }

        try {
            $z = app(NonFoodService::class)->setzeManuell($item, $arguments['ist_nonfood'], $arguments['unterkategorie'] ?? null);
        } catch (\RuntimeException $e) {
            return ToolResult::error($e->getMessage(), 'VALIDATION_ERROR');
        }

        return ToolResult::success(['id' => $item->id, 'ist_nonfood' => $z?->ist_nonfood, 'quelle' => $z?->quelle, 'unterkategorie' => $z?->subkategorie, 'zurueckgenommen' => $z === null]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action', 'tags' => ['foodalchemist', 'nonfood', 'artikel', 'write'],
            'read_only' => false, 'idempotent' => true, 'risk_level' => 'write', 'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'side_effects' => ['updates'], 'related_tools' => ['foodalchemist.nonfood.PRUEFLISTE'],
            'examples' => ['Artikel 4711 ist Non-Food (Reinigung & Hygiene).', 'Artikel 4712 ist doch ein Lebensmittel.'],
        ];
    }
}
