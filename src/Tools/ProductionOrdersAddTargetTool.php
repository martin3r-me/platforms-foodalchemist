<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\ProductionOrderService;

/**
 * Spec 18 (write): fügt ein Ziel (Konzept+Personen ODER Gericht+Portionen) zum
 * Produktionsauftrag EINES Tages hinzu — legt den Auftrag bei Bedarf an
 * (production_date). Löst danach die VOLLE Neu-Explosion über alle Ziele des
 * Tages aus (nicht additiv, Rundungs-Korrektheit). source_ref = Quell-Kennung;
 * erneutes Hinzufügen derselben Quelle ersetzt ihren Beitrag (idempotent).
 */
class ProductionOrdersAddTargetTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.production_orders.ADD_TARGET';
    }

    public function getDescription(): string
    {
        return 'Fügt ein Ziel (concept_id+persons ODER recipe_id+portions) zu einem Produktionsauftrag hinzu. '
            . 'Adressierung: entweder order_id (bestehender, geplanter Auftrag) ODER production_date '
            . '(+ optional name; legt bei Bedarf an — mehrere Aufträge pro Tag sind erlaubt, name grenzt ab). '
            . 'Rechnet die Ansätze für ALLE Ziele dieses Auftrags gemeinsam neu (nicht additiv gerundet). '
            . 'source_ref = Quell-Kennung; gleiche Quelle erneut ⇒ ersetzt ihren Beitrag (idempotent).';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'order_id' => ['type' => 'integer', 'description' => 'Bestehender geplanter Auftrag; Alternative zu production_date'],
                'production_date' => ['type' => 'string', 'description' => 'YYYY-MM-DD (legt bei Bedarf an, wenn kein order_id)'],
                'name' => ['type' => 'string', 'description' => 'Optionaler Auftrags-Name beim Anlegen/Abgrenzen über production_date'],
                'concept_id' => ['type' => 'integer'],
                'recipe_id' => ['type' => 'integer'],
                'persons' => ['type' => 'number'],
                'portions' => ['type' => 'number'],
                'source_ref' => ['type' => 'string', 'description' => 'Quell-Kennung; Re-Import ersetzt diesen Schlüssel'],
            ],
            'required' => ['source_ref'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        $hatConcept = ! empty($arguments['concept_id']);
        $hatRecipe = ! empty($arguments['recipe_id']);
        if ($hatConcept === $hatRecipe) {
            return ToolResult::error('Genau eines von concept_id/recipe_id angeben.', 'VALIDATION_ERROR');
        }
        if (empty($arguments['order_id']) && empty($arguments['production_date'])) {
            return ToolResult::error('order_id ODER production_date erforderlich.', 'VALIDATION_ERROR');
        }

        $ziel = [];
        if ($hatConcept) {
            $ziel['concept_id'] = (int) $arguments['concept_id'];
            $ziel['persons'] = (float) ($arguments['persons'] ?? 0);
        } else {
            $ziel['recipe_id'] = (int) $arguments['recipe_id'];
            $ziel['portions'] = (float) ($arguments['portions'] ?? $arguments['persons'] ?? 0);
        }

        try {
            $svc = app(ProductionOrderService::class);
            $order = $svc->resolveOrCreate(
                $team,
                ! empty($arguments['order_id']) ? (int) $arguments['order_id'] : null,
                ! empty($arguments['production_date']) ? (string) $arguments['production_date'] : null,
                $arguments['name'] ?? null,
                $context->user->id,
            );
            $order = $svc->addTarget($team, (int) $order->id, $ziel, (string) $arguments['source_ref']);
        } catch (\Throwable $e) {
            return ToolResult::error($e->getMessage(), 'ERROR');
        }

        return ToolResult::success([
            'order_id' => (int) $order->id,
            'name' => $order->name,
            'production_date' => $order->production_date?->toDateString(),
            'targets' => $order->targets,
        ]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'command',
            'tags' => ['foodalchemist', 'produktion', 'production_order', 'ziel'],
            'read_only' => false,
            'idempotent' => true, // gleiche (source_ref, ziel, production_date) ⇒ gleicher Endzustand
            'risk_level' => 'low',
        ];
    }
}
