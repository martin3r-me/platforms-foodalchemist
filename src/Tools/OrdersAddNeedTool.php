<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\OrderService;

/**
 * Spec 17/S2 (write): übernimmt den Bedarf EINES Ziels (Konzept/Event, Gericht ODER
 * einzelne Produktion — E9) in die Lieferanten-Bestellschienen. source_ref identifiziert
 * die Quelle; erneutes Übernehmen derselben Quelle ersetzt ihren Beitrag (E10, idempotent).
 * Nur eigene Team-Belege; GPs ohne Lead-LA landen in skipped_ohne_la (nicht bestellbar).
 */
class OrdersAddNeedTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.orders.ADD_NEED';
    }

    public function getDescription(): string
    {
        return 'Übernimmt den Bedarf eines Ziels in die Bestellschienen je Lieferant (in ganzen Gebinden). '
            . 'Ziel = concept_id + persons ODER recipe_id + portions. portions ist doppeldeutig: VK-Gericht = '
            . 'Portionen, Basisrezept = Anzahl Ansätze; beim Basisrezept alternativ amount_kg (Ziel-Kilogramm). '
            . 'source_ref = Quell-Kennung (z. B. "concept:12@100p"); gleiche Quelle erneut ⇒ ersetzt ihren Beitrag '
            . '(idempotent). Liefert die berührten order_ids + GPs ohne Lead-LA (skipped_ohne_la).';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'concept_id' => ['type' => 'integer'],
                'recipe_id' => ['type' => 'integer'],
                'persons' => ['type' => 'number'],
                'portions' => ['type' => 'number', 'description' => 'VK-Gericht: Portionen. Basisrezept: Anzahl Ansätze.'],
                'amount_kg' => ['type' => 'number', 'description' => 'Nur Basisrezept: Ziel-Kilogramm (Alternative zu portions/Ansätze).'],
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

        $ziel = [];
        if ($hatConcept) {
            $ziel['concept_id'] = (int) $arguments['concept_id'];
            $ziel['persons'] = (float) ($arguments['persons'] ?? 0);
        } else {
            $ziel['recipe_id'] = (int) $arguments['recipe_id'];
            if (isset($arguments['amount_kg']) && (float) $arguments['amount_kg'] > 0) {
                $ziel['amount_kg'] = (float) $arguments['amount_kg']; // Basisrezept nach kg (P1)
            } else {
                $ziel['portions'] = (float) ($arguments['portions'] ?? $arguments['persons'] ?? 0);
            }
        }

        try {
            $res = app(OrderService::class)->addNeedFromTarget($team, $ziel, (string) $arguments['source_ref']);
        } catch (\Throwable $e) {
            return ToolResult::error($e->getMessage(), 'ERROR');
        }

        return ToolResult::success($res);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'command',
            'tags' => ['foodalchemist', 'bestellung', 'order', 'bestellschiene', 'bedarf'],
            'read_only' => false,
            'idempotent' => true,   // gleiche (source_ref, ziel) ⇒ gleicher Endzustand (E10)
            'risk_level' => 'low',
        ];
    }
}
