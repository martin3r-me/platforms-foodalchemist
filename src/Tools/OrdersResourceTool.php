<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Enums\LeadLaStrategie;
use Platform\FoodAlchemist\Services\OrderService;

/**
 * Spec 20 · E3 (write): „Neu quellen" — eine OFFENE Bestellschiene mit einer (optionalen)
 * Preisstrategie neu auflösen. Je Bedarfs-Zeile wird der Lead-LA neu bestimmt; landet er bei
 * einem anderen Lieferanten, wandern die Beiträge idempotent (E10) in dessen Draft-Schiene
 * (die Quell-Zeile fällt weg). Manuelle Zeilen bleiben unangetastet. preview=true rechnet nur
 * die Wechsel-Vorschau (nichts wird persistiert). Nur eigene, offene Belege.
 */
class OrdersResourceTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.orders.RESOURCE';
    }

    public function getDescription(): string
    {
        return 'Neu quellen (write): eine offene Bestellschiene mit einer Preisstrategie neu auflösen. '
            . 'order_id = Schiene, strategy = guenstigster_preis | stamm_lieferant | prioritaets_kette '
            . '(weglassen = Team-Haupteinstellung). Je Zeile wird der Lead-LA neu gewählt; anderer Lieferant '
            . '⇒ Beiträge wandern in dessen Draft-Schiene (Quell-Zeile fällt weg), gleicher Lieferant/anderer '
            . 'Artikel ⇒ nur der Artikel wechselt. Manuelle Zeilen unberührt. preview=true = nur Wechsel-Vorschau '
            . '(nichts persistiert). Liefert die Wechsel-Liste + berührte order_ids.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'order_id' => ['type' => 'integer'],
                'strategy' => ['type' => 'string', 'enum' => ['guenstigster_preis', 'stamm_lieferant', 'prioritaets_kette'], 'description' => 'Preisstrategie-Override; weglassen = Team-Haupteinstellung.'],
                'preview' => ['type' => 'boolean', 'description' => 'true = nur Vorschau der Wechsel (nichts wird gespeichert). Default false = anwenden.'],
            ],
            'required' => ['order_id'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        $strategie = isset($arguments['strategy']) ? LeadLaStrategie::tryFrom((string) $arguments['strategy']) : null;
        $apply = ! (bool) ($arguments['preview'] ?? false);
        $userId = method_exists($context->user, 'getKey') ? (int) $context->user->getKey() : null;

        try {
            $res = app(OrderService::class)->resourceOrder(
                $team,
                (int) $arguments['order_id'],
                $strategie,
                $apply,
                $userId,
            );
        } catch (\RuntimeException $e) {
            return ToolResult::error($e->getMessage(), 'EXECUTION_ERROR');
        } catch (\Throwable $e) {
            return ToolResult::error('Schiene konnte nicht neu gequellt werden.', 'ERROR');
        }

        return ToolResult::success($res + ['applied' => $apply]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'command',
            'tags' => ['foodalchemist', 'bestellung', 'order', 'einkauf', 'neu-quellen', 'strategie', 'lead-la'],
            'read_only' => false,
            'idempotent' => true,   // gleiche (order, strategie) ⇒ gleicher Endzustand (E10)
            'risk_level' => 'low',
        ];
    }
}
