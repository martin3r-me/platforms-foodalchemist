<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Models\FoodAlchemistProduktpass;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem;
use Platform\FoodAlchemist\Services\Produktpass\ProduktpassService;

/** Stand der Produktpässe eines Lieferantenartikels: Versionen, gelesene Felder mit Konfidenz und Übernahme-Status, Allergen-Vorschlag. */
class ProduktpassGetTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.produktpass.GET';
    }

    public function getDescription(): string
    {
        return 'Stand des Produktpasses eines Lieferantenartikels: alle Versionen (Datum, Status, Quelle), die gelesenen Felder der jüngsten gelesenen '
            .'Version (Wert, Konfidenz, aktueller FA-Wert, status übernommen|vorschlag), den Allergen-Vorschlag mit Gegenüberstellung (allergene_status '
            .'vorschlag = noch nicht bestätigt, der alte Allergenstand gilt) und die Quelle je Feld (produktpass_ki/websuche). Optional version für eine bestimmte Version.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'supplier_item_id' => ['type' => 'integer'],
                'version' => ['type' => 'integer', 'description' => 'Optional: bestimmte Version statt der jüngsten gelesenen'],
            ],
            'required' => ['supplier_item_id'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        $item = FoodAlchemistSupplierItem::visibleToTeam($team)->find((int) ($arguments['supplier_item_id'] ?? 0));
        if ($item === null) {
            return ToolResult::error('Artikel nicht gefunden.', 'NOT_FOUND');
        }
        $pass = null;
        if (! empty($arguments['version'])) {
            $pass = FoodAlchemistProduktpass::where('supplier_item_id', $item->id)->where('version', (int) $arguments['version'])->first();
            if ($pass === null) {
                return ToolResult::error('Diese Version gibt es nicht.', 'NOT_FOUND');
            }
        }

        return ToolResult::success(['supplier_item_id' => $item->id, 'designation' => $item->designation] + app(ProduktpassService::class)->stand($item, $pass));
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'read', 'tags' => ['foodalchemist', 'produktpass', 'lieferantenartikel', 'read'],
            'read_only' => true, 'idempotent' => true, 'risk_level' => 'safe',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db', 'side_effects' => [],
            'related_tools' => ['foodalchemist.produktpass.POST', 'foodalchemist.produktpass.ALLERGENE_BESTAETIGEN'],
            'examples' => ['Was steht im Produktpass von Artikel 4711?'],
        ];
    }
}
