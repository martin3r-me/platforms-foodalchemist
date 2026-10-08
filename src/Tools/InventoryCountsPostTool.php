<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\InventurService;

/** Spec 66 §6 · Inventur anlegen (Zählliste vorbelegt aus Bestand + Einkäufen). */
class InventoryCountsPostTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.inventory_counts.POST';
    }

    public function getDescription(): string
    {
        return 'Legt eine Inventur für einen Lagerort zum Stichtag an. Die Zählliste wird vorbelegt: alle Grundprodukte '
            . 'mit Bestand an diesem Ort plus (am Standard-Lagerort) alles, was in den letzten 90 Tagen eingekauft wurde. '
            . 'Soll-Menge und Bewertung (EK je Einheit) werden beim Anlegen eingefroren. Status danach: offen.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'location_id' => ['type' => 'integer', 'description' => 'Lagerort-Id (siehe foodalchemist.inventory.GET).'],
                'stichtag' => ['type' => 'string', 'description' => 'YYYY-MM-DD, Default heute.'],
                'notiz' => ['type' => 'string', 'description' => 'Optionale Notiz.'],
            ],
            'required' => ['location_id'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        $svc = app(InventurService::class);
        try {
            $c = $svc->anlegen($team, (int) $arguments['location_id'], (string) ($arguments['stichtag'] ?? now()->toDateString()), $arguments['notiz'] ?? null);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            return ToolResult::error('Lagerort nicht gefunden.', 'NOT_FOUND');
        } catch (\RuntimeException $e) {
            return ToolResult::error($e->getMessage(), 'VALIDATION_ERROR');
        }
        $c->load('location');

        return ToolResult::success(InventoryCountsGetTool::kopf($c) + ['positionen' => $c->lines()->count()]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['foodalchemist', 'lager', 'inventur', 'write'],
            'read_only' => false, 'idempotent' => false, 'risk_level' => 'write',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'side_effects' => ['creates'],
            'related_tools' => ['foodalchemist.inventory_counts.PUT', 'foodalchemist.inventory_counts.BOOK'],
            'examples' => ['Leg die Monatsinventur fürs Hauptlager zum 31.10. an.'],
        ];
    }
}
