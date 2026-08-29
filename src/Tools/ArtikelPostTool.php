<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplier;
use Platform\FoodAlchemist\Services\SupplierItemService;

/** MCP-Steuerbarkeit · D4: Lieferantenartikel an einem team-eigenen Lieferanten anlegen. */
class ArtikelPostTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.artikel.POST';
    }

    public function getDescription(): string
    {
        return 'Legt einen Lieferantenartikel an einem team-eigenen Lieferanten an. input.designation (Bezeichnung) '
            . 'ist Pflicht; weitere Stammdaten/Verpackung optional.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'supplier_id' => ['type' => 'integer', 'description' => 'Lieferanten-Id (team-eigen).'],
                'input' => ['type' => 'object', 'description' => 'Artikel-Stammdaten (designation Pflicht).'],
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
        if (! is_array($input) || trim((string) ($input['designation'] ?? '')) === '') {
            return ToolResult::error('input.designation ist Pflicht.', 'VALIDATION_ERROR');
        }
        $supplierId = (int) ($arguments['supplier_id'] ?? 0);
        if (($guard = $this->guardOwned($team, FoodAlchemistSupplier::class, $supplierId, 'Lieferant')) !== null) {
            return $guard;
        }

        try {
            $item = app(SupplierItemService::class)->create($team, $supplierId, $input);
        } catch (\RuntimeException $e) {
            return ToolResult::error($e->getMessage(), 'VALIDATION_ERROR');
        }

        return ToolResult::success(['id' => (int) $item->id, 'designation' => $item->designation, 'supplier_id' => $supplierId]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['foodalchemist', 'artikel', 'lieferantenartikel', 'anlegen', 'write'],
            'read_only' => false, 'idempotent' => false, 'risk_level' => 'write',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'side_effects' => ['creates'],
            'related_tools' => ['foodalchemist.artikel.SEARCH', 'foodalchemist.artikel_preise.POST'],
            'examples' => ['Lege bei Lieferant 12 den Artikel „Zanderfilet 200g" an.'],
        ];
    }
}
