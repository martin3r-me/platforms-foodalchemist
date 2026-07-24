<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\OrderService;

/**
 * Spec 20 · E2 (write): Direktbestellung — einen Lieferantenartikel MANUELL an die
 * Draft-Schiene seines Lieferanten hängen (unabhängig von jeder Produktion/jedem Ziel).
 * Die Schiene wird angelegt, wenn nötig; die Zeile trägt is_manual_qty=true und übersteht
 * den Recompute. qty_packs = Anzahl Gebinde. Existiert der Artikel schon in der Schiene,
 * wird dessen Menge manuell übersteuert (idempotent), keine Dublette. Nur eigene Belege.
 */
class OrdersAddLineTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.orders.ADD_LINE';
    }

    public function getDescription(): string
    {
        return 'Fügt einen Lieferantenartikel manuell zur offenen Bestellschiene seines Lieferanten hinzu '
            . '(Direktbestellung, ohne Ziel/Produktion). supplier_item_id = Lieferantenartikel-ID, '
            . 'qty_packs = Anzahl Gebinde (manuelle Menge, bleibt beim Recompute stehen), note = optionale '
            . 'Zeilen-Notiz. Legt die Draft-Schiene an, wenn nötig; erneuter Aufruf für denselben Artikel '
            . 'setzt die Menge neu (idempotent). Liefert die Zeile + berührte order_id zurück.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'supplier_item_id' => ['type' => 'integer', 'description' => 'Lieferantenartikel-ID (bestimmt den Lieferanten der Schiene)'],
                'qty_packs' => ['type' => 'number', 'description' => 'Anzahl Gebinde (manuelle Menge)'],
                'note' => ['type' => 'string', 'description' => 'Zeilen-Notiz (optional; "" löscht)'],
            ],
            'required' => ['supplier_item_id', 'qty_packs'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }

        try {
            $line = app(OrderService::class)->addManualLine(
                $team,
                (int) $arguments['supplier_item_id'],
                (float) $arguments['qty_packs'],
                array_key_exists('note', $arguments) ? (string) $arguments['note'] : null,
                method_exists($context->user, 'getKey') ? (int) $context->user->getKey() : null,
            );
        } catch (\RuntimeException $e) {
            return ToolResult::error($e->getMessage(), 'NOT_ALLOWED');
        } catch (\Throwable $e) {
            return ToolResult::error('Lieferantenartikel nicht im Zugriff.', 'NOT_FOUND');
        }

        return ToolResult::success([
            'order_id' => (int) $line->order_id,
            'line_id' => (int) $line->id,
            'supplier_item_id' => (int) $line->supplier_item_id,
            'designation' => $line->designation,
            'qty_packs' => (float) $line->qty_packs,
            'pack_price' => $line->pack_price !== null ? (float) $line->pack_price : null,
            'line_total' => (float) $line->line_total,
            'is_manual_qty' => (bool) $line->is_manual_qty,
            'note' => $line->note,
        ]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'command',
            'tags' => ['foodalchemist', 'bestellung', 'order', 'direktbestellung', 'einkauf', 'artikel'],
            'read_only' => false,
            'idempotent' => true,   // Setter-Semantik: gleiche (Artikel, qty) ⇒ gleicher Endzustand
            'risk_level' => 'low',
        ];
    }
}
