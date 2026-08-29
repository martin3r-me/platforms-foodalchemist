<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem;
use Platform\FoodAlchemist\Services\PriceService;

/** MCP-Steuerbarkeit · D4: Preis für einen team-eigenen Lieferantenartikel anlegen. */
class ArtikelPreisePostTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.artikel_preise.POST';
    }

    public function getDescription(): string
    {
        return 'Legt einen Preis (netto) für einen team-eigenen Lieferantenartikel an. status optional (Preisstatus).';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'id' => ['type' => 'integer', 'description' => 'Artikel-Id (team-eigen).'],
                'preis' => ['type' => 'number', 'description' => 'Preis netto (≥ 0).'],
                'status' => ['type' => 'string', 'description' => 'Preisstatus (optional).'],
            ],
            'required' => ['id', 'preis'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        if (! is_numeric($arguments['preis'] ?? null) || (float) $arguments['preis'] < 0) {
            return ToolResult::error('preis muss eine Zahl ≥ 0 sein.', 'VALIDATION_ERROR');
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
            $p = app(PriceService::class)->createFor($team, $item, (float) $arguments['preis'], (string) ($arguments['status'] ?? '0'));
        } catch (\RuntimeException $e) {
            return ToolResult::error($e->getMessage(), 'VALIDATION_ERROR');
        }

        return ToolResult::success(['id' => $id, 'price_id' => (int) $p->id, 'price' => (float) $p->price]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['foodalchemist', 'artikel', 'preis', 'write'],
            'read_only' => false, 'idempotent' => false, 'risk_level' => 'write',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'side_effects' => ['creates'],
            'related_tools' => ['foodalchemist.artikel_preise.DELETE'],
            'examples' => ['Lege für Artikel 4711 den Preis 8,90 an.'],
        ];
    }
}
