<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplier;
use Platform\FoodAlchemist\Services\SupplierAgreementService;

/** MCP-Steuerbarkeit · D4: Dokument-Metadaten an einem team-eigenen Lieferanten hinterlegen. */
class SupplierDocumentsPostTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.supplier_documents.POST';
    }

    public function getDescription(): string
    {
        return 'Hinterlegt Dokument-Metadaten (Titel/Typ/Referenz) an einem team-eigenen Lieferanten. '
            . 'Binär-Upload läuft separat über die UI.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'supplier_id' => ['type' => 'integer', 'description' => 'Lieferanten-Id (team-eigen).'],
                'input' => ['type' => 'object', 'description' => 'Dokument-Metadaten (title, type, …).'],
            ],
            'required' => ['supplier_id', 'input'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        $input = $arguments['input'] ?? null;
        if (! is_array($input) || $input === []) {
            return ToolResult::error('input muss ein nicht-leeres Objekt sein.', 'VALIDATION_ERROR');
        }
        $supplierId = (int) ($arguments['supplier_id'] ?? 0);
        if (($guard = $this->guardOwned($team, FoodAlchemistSupplier::class, $supplierId, 'Lieferant')) !== null) {
            return $guard;
        }

        try {
            $d = app(SupplierAgreementService::class)->addDocument($team, $supplierId, $input);
        } catch (\RuntimeException $e) {
            return ToolResult::error($e->getMessage(), 'VALIDATION_ERROR');
        }

        return ToolResult::success(['supplier_id' => $supplierId, 'document_id' => (int) $d->id]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['foodalchemist', 'lieferant', 'dokument', 'write'],
            'read_only' => false, 'idempotent' => false, 'risk_level' => 'write',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'side_effects' => ['creates'],
            'related_tools' => ['foodalchemist.supplier_agreements.POST'],
            'examples' => ['Hinterlege bei Lieferant 12 das Dokument „Rahmenvertrag 2026".'],
        ];
    }
}
