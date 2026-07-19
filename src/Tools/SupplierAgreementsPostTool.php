<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\SupplierAgreementService;

/**
 * R9.1 (write): Absprache/Zusage ODER Vertrags-/Dokument-Metadaten zu einem Lieferanten
 * anlegen. Absprachen tragen Gültigkeit + Wiedervorlage; Dokumente Laufzeit +
 * Kündigungsfrist (speist das Vertragsfrist-Signal). Datensatz gehört dem aktuellen Team.
 */
class SupplierAgreementsPostTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.supplier_agreements.POST';
    }

    public function getDescription(): string
    {
        return 'Legt zu einem Lieferanten eine Absprache/Zusage an (record=agreement: type, note, '
            . 'valid_from?, valid_to?, follow_up_at?) ODER ein Vertrags-/Dokument (record=document: '
            . 'kind, title?, file_ref?, term_start?, term_end?, notice_period_days?). Datumsformat YYYY-MM-DD. '
            . 'Dokumente mit term_end + notice_period_days lösen rechtzeitig das Vertragsfrist-Signal aus.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'supplier_id' => ['type' => 'integer'],
                'record' => ['type' => 'string', 'enum' => ['agreement', 'document'], 'default' => 'agreement'],
                'type' => ['type' => 'string', 'description' => 'agreement: absprache|zusage|kondition|sonstiges'],
                'note' => ['type' => 'string', 'description' => 'agreement: Text der Absprache'],
                'valid_from' => ['type' => 'string'],
                'valid_to' => ['type' => 'string'],
                'follow_up_at' => ['type' => 'string', 'description' => 'agreement: Wiedervorlage YYYY-MM-DD'],
                'kind' => ['type' => 'string', 'description' => 'document: vertrag|rahmenvereinbarung|zertifikat|sonstiges'],
                'title' => ['type' => 'string'],
                'file_ref' => ['type' => 'string'],
                'term_start' => ['type' => 'string'],
                'term_end' => ['type' => 'string'],
                'notice_period_days' => ['type' => 'integer'],
            ],
            'required' => ['supplier_id'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        $svc = app(SupplierAgreementService::class);
        $id = (int) $arguments['supplier_id'];
        $record = ($arguments['record'] ?? 'agreement') === 'document' ? 'document' : 'agreement';
        $userId = is_object($context->user ?? null) && isset($context->user->id) ? (int) $context->user->id : null;

        try {
            if ($record === 'document') {
                $doc = $svc->addDocument($team, $id, $arguments);

                return ToolResult::success(['record' => 'document', 'id' => (int) $doc->id, 'notice_deadline' => $doc->noticeDeadline()?->toDateString()]);
            }
            $a = $svc->create($team, $id, $arguments, $userId);

            return ToolResult::success(['record' => 'agreement', 'id' => (int) $a->id, 'follow_up_at' => $a->follow_up_at?->toDateString()]);
        } catch (\RuntimeException $e) {
            return ToolResult::error($e->getMessage(), 'VALIDATION_ERROR');
        }
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'command',
            'tags' => ['foodalchemist', 'lieferant', 'supplier', 'absprache', 'vertrag', 'dokument', 'frist'],
            'read_only' => false,
            'idempotent' => false,
            'risk_level' => 'write',
            'requires_auth' => true,
            'requires_team' => true,
            'cost_class' => 'local_db',
            'related_tools' => ['foodalchemist.suppliers.GET', 'foodalchemist.suppliers.PUT'],
            'examples' => ['Notiere: Lieferant 42 sagt 3 % Bonus ab 500 € zu, Wiedervorlage in 3 Monaten.'],
        ];
    }
}
