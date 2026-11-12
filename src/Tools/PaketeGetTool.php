<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Models\FoodAlchemistConcept;
use Platform\FoodAlchemist\Services\ConceptPaketService;
use Platform\FoodAlchemist\Services\ConceptService;

/** Concepter-Paket mit Gericht-Positionen lesen. */
class PaketeGetTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.pakete.GET';
    }

    public function getDescription(): string
    {
        return 'Pakete = Concepter-Pakete (concepts mit kind=paket), dieselben, die der Concepter im Reiter „Pakete“ zeigt. Liefert ein sichtbares Paket mit Stammdaten, Status, Preis und Gericht-Positionen (row_id = concept_slot.id). '
            . 'Die Paket-Id ist package_id in concept_slots.POST/PUT.';
    }

    public function getSchema(): array
    {
        return ['type' => 'object', 'properties' => [
            'id' => ['type' => 'integer', 'description' => 'Paket-Id (Concept-Id mit kind=paket).'],
        ], 'required' => ['id']];
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
        $paket = app(\Platform\FoodAlchemist\Services\ConceptPreisService::class)->anwenden($paket, $team);

        return ToolResult::success(['paket' => app(ConceptPaketService::class)->payload($paket, true)]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'read',
            'tags' => ['foodalchemist', 'paket', 'read'],
            'read_only' => true, 'idempotent' => true, 'risk_level' => 'safe',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'side_effects' => [],
            'related_tools' => ['foodalchemist.pakete.LIST', 'foodalchemist.pakete.PUT'],
            'examples' => ['Zeig mir Paket 12 mit seinen Gerichten.'],
        ];
    }
}
