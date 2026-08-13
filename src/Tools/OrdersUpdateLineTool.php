<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Models\FoodAlchemistOrderLine;
use Platform\FoodAlchemist\Services\OrderService;

/**
 * Spec 17/S2+S3 (write): eine Bestellzeile im OFFENEN Entwurf pflegen — Gebinde-Anzahl
 * manuell übersteuern (qty_packs), Auto-Menge wiederherstellen (reset_qty) oder Zeile
 * entfernen (remove). Nach dem Absenden zusätzlich Wareneingang und Rechnungsprüfung.
 * Nur eigene Team-Belege; Status-Guards liegen im Service.
 */
class OrdersUpdateLineTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.orders.UPDATE_LINE';
    }

    public function getDescription(): string
    {
        return 'Pflegt eine Bestellzeile im offenen Entwurf: qty_packs (Gebinde-Anzahl manuell setzen — '
            . 'bleibt bei Recompute stehen), reset_qty=true (Auto-Menge wiederherstellen) oder remove=true '
            . '(Zeile entfernen). Nach dem Absenden zusätzlich: received_qty_packs/received_note für '
            . 'Wareneingang, invoice_qty_packs/invoice_pack_price/invoice_note für Rechnungsprüfung '
            . 'sowie claim_status/claim_qty_packs/credit_expected_net/claim_note für Reklamation/Gutschrift. '
            . 'Kontingent am Lieferantenartikel: quota_qty_packs, quota_used_packs, quota_valid_from, '
            . 'quota_valid_to, quota_note.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'line_id' => ['type' => 'integer'],
                'qty_packs' => ['type' => 'number', 'description' => 'Gebinde-Anzahl manuell (übersteuert Auto-Rundung)'],
                'reset_qty' => ['type' => 'boolean', 'description' => 'true = zurück auf Auto-Menge'],
                'remove' => ['type' => 'boolean', 'description' => 'true = Zeile entfernen'],
                'received_qty_packs' => ['type' => 'number', 'description' => 'Gelieferte Gebinde-Anzahl im Wareneingang'],
                'received_note' => ['type' => 'string', 'description' => 'Wareneingangsnotiz; "" löscht'],
                'invoice_qty_packs' => ['type' => 'number', 'description' => 'Berechnete Gebinde-Anzahl laut Rechnung'],
                'invoice_pack_price' => ['type' => 'number', 'description' => 'Berechneter Preis je Gebinde laut Rechnung'],
                'invoice_note' => ['type' => 'string', 'description' => 'Rechnungsnotiz; "" löscht'],
                'claim_status' => ['type' => 'string', 'enum' => ['', 'open', 'credit_expected', 'credited', 'resolved'], 'description' => 'Reklamationsstatus; "" löscht'],
                'claim_qty_packs' => ['type' => 'number', 'description' => 'Reklamierte Gebinde-Anzahl'],
                'credit_expected_net' => ['type' => 'number', 'description' => 'Erwartete Gutschrift netto in EUR'],
                'claim_note' => ['type' => 'string', 'description' => 'Reklamations-/Gutschriftnotiz; "" löscht'],
                'quota_qty_packs' => ['type' => 'number', 'description' => 'Kontingent-/Rahmenmenge in Gebinden; "" löscht'],
                'quota_used_packs' => ['type' => 'number', 'description' => 'Bereits abgerufene Kontingentmenge in Gebinden; "" löscht'],
                'quota_valid_from' => ['type' => 'string', 'description' => 'Kontingent gültig ab YYYY-MM-DD; "" löscht'],
                'quota_valid_to' => ['type' => 'string', 'description' => 'Kontingent gültig bis YYYY-MM-DD; "" löscht'],
                'quota_note' => ['type' => 'string', 'description' => 'Kontingentnotiz; "" löscht'],
            ],
            'required' => ['line_id'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        $svc = app(OrderService::class);
        $lineId = (int) $arguments['line_id'];

        try {
            if (! empty($arguments['remove'])) {
                $svc->removeLine($team, $lineId);

                return ToolResult::success(['line_id' => $lineId, 'removed' => true]);
            }
            $line = null;
            $input = [];
            if (array_key_exists('qty_packs', $arguments)) {
                $input['qty_packs'] = $arguments['qty_packs'];
            }
            if (! empty($arguments['reset_qty'])) {
                $input['reset_qty'] = true;
            }
            if ($input !== []) {
                $line = $svc->updateLine($team, $lineId, $input);
            }

            if (array_key_exists('received_qty_packs', $arguments)) {
                $line = $svc->updateReceiptLine(
                    $team,
                    $lineId,
                    $arguments['received_qty_packs'],
                    array_key_exists('received_note', $arguments) ? (string) $arguments['received_note'] : null
                );
            } elseif (array_key_exists('received_note', $arguments)) {
                $line = $svc->updateReceiptNote($team, $lineId, (string) $arguments['received_note']);
            }

            if (array_key_exists('invoice_qty_packs', $arguments) || array_key_exists('invoice_pack_price', $arguments)) {
                $current = FoodAlchemistOrderLine::findOrFail($lineId);
                $line = $svc->updateInvoiceLine(
                    $team,
                    $lineId,
                    array_key_exists('invoice_qty_packs', $arguments) ? $arguments['invoice_qty_packs'] : $current->invoice_qty_packs,
                    array_key_exists('invoice_pack_price', $arguments) ? $arguments['invoice_pack_price'] : $current->invoice_pack_price,
                    array_key_exists('invoice_note', $arguments) ? (string) $arguments['invoice_note'] : null
                );
            } elseif (array_key_exists('invoice_note', $arguments)) {
                $line = $svc->updateInvoiceNote($team, $lineId, (string) $arguments['invoice_note']);
            }

            $claim = array_intersect_key($arguments, array_flip([
                'claim_status',
                'claim_qty_packs',
                'credit_expected_net',
                'claim_note',
            ]));
            if ($claim !== []) {
                $line = $svc->updateClaimLine($team, $lineId, $claim);
            }
            $quota = array_intersect_key($arguments, array_flip([
                'quota_qty_packs',
                'quota_used_packs',
                'quota_valid_from',
                'quota_valid_to',
                'quota_note',
            ]));
            if ($quota !== []) {
                $line = $svc->updateLineQuota($team, $lineId, $quota);
            }

            if ($line === null) {
                return ToolResult::error('Nichts zu ändern (Mengen-, WE-, RE-, Reklamations- oder Kontingentfeld angeben).', 'NO_CHANGE');
            }
        } catch (\RuntimeException $e) {
            return ToolResult::error($e->getMessage(), 'NOT_ALLOWED');
        } catch (\Throwable $e) {
            return ToolResult::error('Bestellzeile nicht im Zugriff.', 'NOT_FOUND');
        }

        $line->loadMissing('supplierItem');
        $item = $line->supplierItem;

        return ToolResult::success([
            'line_id' => (int) $line->id,
            'qty_packs' => (float) $line->qty_packs,
            'is_manual_qty' => (bool) $line->is_manual_qty,
            'line_total' => (float) $line->line_total,
            'received_qty_packs' => $line->received_qty_packs !== null ? (float) $line->received_qty_packs : null,
            'received_note' => $line->received_note,
            'received_at' => $line->received_at?->format('Y-m-d H:i'),
            'invoice_qty_packs' => $line->invoice_qty_packs !== null ? (float) $line->invoice_qty_packs : null,
            'invoice_pack_price' => $line->invoice_pack_price !== null ? (float) $line->invoice_pack_price : null,
            'invoice_note' => $line->invoice_note,
            'invoice_checked_at' => $line->invoice_checked_at?->format('Y-m-d H:i'),
            'claim_status' => $line->claim_status,
            'claim_qty_packs' => $line->claim_qty_packs !== null ? (float) $line->claim_qty_packs : null,
            'credit_expected_net' => $line->credit_expected_net !== null ? (float) $line->credit_expected_net : null,
            'claim_note' => $line->claim_note,
            'quota_qty_packs' => $item?->quota_qty_packs !== null ? (float) $item->quota_qty_packs : null,
            'quota_used_packs' => $item?->quota_used_packs !== null ? (float) $item->quota_used_packs : null,
            'quota_valid_from' => $item?->quota_valid_from?->toDateString(),
            'quota_valid_to' => $item?->quota_valid_to?->toDateString(),
            'quota_note' => $item?->quota_note,
        ]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'command',
            'tags' => ['foodalchemist', 'bestellung', 'order', 'zeile', 'gebinde'],
            'read_only' => false,
            'idempotent' => false,
            'risk_level' => 'low',
        ];
    }
}
