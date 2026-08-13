<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\OrderService;

/**
 * Spec 20 · E1 (write): Kopf-Felder einer Bestellschiene pflegen. Draft-Felder bleiben
 * auf offene Entwürfe begrenzt; WaWi-Felder (AB/Bestätigung/Rechnungskopf) sind nach dem
 * Absenden erlaubt. Zeilen werden hier NICHT angefasst (dafür ADD_NEED/UPDATE_LINE).
 */
class OrdersUpdateTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.orders.UPDATE';
    }

    public function getDescription(): string
    {
        return 'Ändert die Kopf-Felder einer offenen Bestellschiene: reference (Anlass; "" löscht), '
            . 'desired_delivery_date (YYYY-MM-DD; "" löscht), note (Notiz; "" löscht). Nur eigene Belege, '
            . 'nur im draft. Nach dem Absenden zusätzlich WaWi-Kopf: supplier_order_number, '
            . 'confirmed_delivery_date, supplier_confirmation_note, approval_status=requested|approved|rejected, '
            . 'approval_note, invoice_number, invoice_date, invoice_note sowie Zahlungsstatus '
            . 'payment_status=open|paid|disputed, invoice_paid_at, payment_note. '
            . 'Außerdem complete_receipt=true, complete_invoice_from_receipt=true und '
            . 'create_backorder_from_receipt=true für WaWi-Massenaktionen.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'order_id' => ['type' => 'integer'],
                'reference' => ['type' => 'string', 'description' => 'Anlass; "" löscht'],
                'desired_delivery_date' => ['type' => 'string', 'description' => 'Wunsch-Liefertermin YYYY-MM-DD; "" löscht'],
                'note' => ['type' => 'string', 'description' => 'Notiz; "" löscht'],
                'supplier_order_number' => ['type' => 'string', 'description' => 'Lieferanten-Auftrags-/AB-Nummer; "" löscht'],
                'confirmed_delivery_date' => ['type' => 'string', 'description' => 'Vom Lieferanten bestätigter Liefertag YYYY-MM-DD; "" löscht'],
                'supplier_confirmation_note' => ['type' => 'string', 'description' => 'Notiz zur Lieferantenbestätigung; "" löscht'],
                'invoice_number' => ['type' => 'string', 'description' => 'Rechnungsnummer; "" löscht'],
                'invoice_date' => ['type' => 'string', 'description' => 'Rechnungsdatum YYYY-MM-DD; "" löscht'],
                'invoice_note' => ['type' => 'string', 'description' => 'Notiz zum Rechnungskopf; "" löscht'],
                'payment_status' => ['type' => 'string', 'enum' => ['', 'open', 'paid', 'disputed'], 'description' => 'Zahlungsstatus: open, paid, disputed; "" löscht'],
                'invoice_paid_at' => ['type' => 'string', 'description' => 'Bezahlt am YYYY-MM-DD; "" löscht'],
                'payment_note' => ['type' => 'string', 'description' => 'Zahlungsnotiz; "" löscht'],
                'approval_status' => ['type' => 'string', 'enum' => ['', 'requested', 'approved', 'rejected'], 'description' => 'Freigabe: requested, approved, rejected; "" löscht'],
                'approval_note' => ['type' => 'string', 'description' => 'Freigabenotiz; "" löscht'],
                'complete_receipt' => ['type' => 'boolean', 'description' => 'true = Wareneingang vollständig aus Bestellmengen übernehmen'],
                'complete_invoice_from_receipt' => ['type' => 'boolean', 'description' => 'true = Rechnungsprüfung aus Wareneingang, sonst Bestellung, übernehmen'],
                'create_backorder_from_receipt' => ['type' => 'boolean', 'description' => 'true = Nachlieferungs-Draft aus unterlieferten Wareneingangszeilen erzeugen'],
                'backorder_delivery_date' => ['type' => 'string', 'description' => 'Optionaler Liefertag für die Nachlieferung YYYY-MM-DD'],
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

        $header = array_intersect_key($arguments, array_flip(['reference', 'desired_delivery_date', 'note']));
        $confirmation = array_intersect_key($arguments, array_flip([
            'supplier_order_number',
            'confirmed_delivery_date',
            'supplier_confirmation_note',
        ]));
        $invoice = array_intersect_key($arguments, array_flip([
            'invoice_number',
            'invoice_date',
            'invoice_note',
        ]));
        $payment = array_intersect_key($arguments, array_flip([
            'payment_status',
            'invoice_paid_at',
            'payment_note',
        ]));
        $approval = array_intersect_key($arguments, array_flip([
            'approval_status',
            'approval_note',
        ]));
        $completeReceipt = ! empty($arguments['complete_receipt']);
        $completeInvoice = ! empty($arguments['complete_invoice_from_receipt']);
        $createBackorder = ! empty($arguments['create_backorder_from_receipt']);
        if ($header === [] && $confirmation === [] && $invoice === [] && $payment === [] && $approval === [] && ! $completeReceipt && ! $completeInvoice && ! $createBackorder) {
            return ToolResult::error('Nichts zu ändern (Kopf-/Bestätigungs-/Freigabe-/Rechnungsfeld angeben).', 'NO_CHANGE');
        }

        try {
            $svc = app(OrderService::class);
            $order = null;
            if ($header !== []) {
                $order = $svc->updateHeader($team, (int) $arguments['order_id'], $header);
            }
            if ($confirmation !== []) {
                $order = $svc->updateSupplierConfirmation($team, (int) $arguments['order_id'], $confirmation);
            }
            if ($invoice !== []) {
                $order = $svc->updateInvoiceHeader($team, (int) $arguments['order_id'], $invoice);
            }
            if ($payment !== []) {
                $order = $svc->updatePayment($team, (int) $arguments['order_id'], $payment);
            }
            if ($approval !== []) {
                $order = $svc->updateApproval($team, (int) $arguments['order_id'], $approval, (int) $context->user->getAuthIdentifier());
            }
            if ($completeReceipt) {
                $order = $svc->completeReceipt($team, (int) $arguments['order_id']);
            }
            if ($completeInvoice) {
                $order = $svc->completeInvoiceFromReceipt($team, (int) $arguments['order_id']);
            }
            if ($createBackorder) {
                $backorder = $svc->createBackorderFromReceipt(
                    $team,
                    (int) $arguments['order_id'],
                    ($arguments['backorder_delivery_date'] ?? null) ?: null,
                    (int) $context->user->getAuthIdentifier()
                );
            }
            $detail = $svc->detail($team, (int) ($order?->id ?? $arguments['order_id']));
            if (isset($backorder)) {
                $detail['backorder'] = $backorder;
            }
        } catch (\RuntimeException $e) {
            return ToolResult::error($e->getMessage(), 'NOT_ALLOWED');
        } catch (\Throwable $e) {
            return ToolResult::error('Bestellung nicht im Zugriff.', 'NOT_FOUND');
        }

        return ToolResult::success($detail);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'command',
            'tags' => ['foodalchemist', 'bestellung', 'order', 'kopf', 'einkauf'],
            'read_only' => false,
            'idempotent' => true,
            'risk_level' => 'low',
        ];
    }
}
