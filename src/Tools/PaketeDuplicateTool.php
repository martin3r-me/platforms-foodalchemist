<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Models\FoodAlchemistConcept;
use Platform\FoodAlchemist\Services\ConceptPaketService;
use Platform\FoodAlchemist\Services\ConceptService;

/** Concepter-Paket duplizieren (Kopie im eigenen Team). */
class PaketeDuplicateTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.pakete.DUPLICATE';
    }

    public function getDescription(): string
    {
        return 'Pakete = Concepter-Pakete (concepts mit kind=paket), dieselben, die der Concepter im Reiter „Pakete“ zeigt. Dupliziert ein sichtbares Paket (Stamm + Positionen) als team-eigene Kopie. Bleibt ein Paket.';
    }

    public function getSchema(): array
    {
        return ['type' => 'object', 'properties' => ['id' => ['type' => 'integer', 'description' => 'Quell-Paket-Id.']], 'required' => ['id']];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        $paket = $this->paketConcept($team, (int) ($arguments['id'] ?? 0), false);
        if ($paket instanceof ToolResult) {
            return $paket;
        }

        try {
            $neu = app(ConceptService::class)->duplicate($team, $paket->id);
        } catch (\RuntimeException $e) {
            return ToolResult::error($e->getMessage(), 'VALIDATION_ERROR');
        }

        return ToolResult::success(['paket' => app(ConceptPaketService::class)->payload($neu), 'source_id' => (int) $paket->id]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['foodalchemist', 'paket', 'write'],
            'read_only' => false, 'idempotent' => false, 'risk_level' => 'write',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'side_effects' => ['creates'],
            'related_tools' => ['foodalchemist.pakete.POST'],
            'examples' => ['Dupliziere Paket 12.'],
        ];
    }
}
