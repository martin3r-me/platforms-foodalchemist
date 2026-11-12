<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Models\FoodAlchemistConcept;
use Platform\FoodAlchemist\Services\ConceptPaketService;
use Platform\FoodAlchemist\Services\ConceptService;

/** Preis-/EK-Cache eines Concepter-Pakets neu rechnen. */
class PaketeRecomputeTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.pakete.RECOMPUTE';
    }

    public function getDescription(): string
    {
        return 'Pakete = Concepter-Pakete (concepts mit kind=paket), dieselben, die der Concepter im Reiter „Pakete“ zeigt. Berechnet EK/Person und (im auto-Modus) den Preis eines team-eigenen Pakets aus seinen Gerichten neu.';
    }

    public function getSchema(): array
    {
        return ['type' => 'object', 'properties' => ['id' => ['type' => 'integer', 'description' => 'Paket-Id.']], 'required' => ['id']];
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
        if (! $paket->isOwnedBy($team)) {
            return ToolResult::error('Paket: nur fürs Besitzer-Team.', 'ACCESS_DENIED');
        }
        $paket = app(ConceptService::class)->recomputeCache($paket);

        return ToolResult::success(['paket' => app(ConceptPaketService::class)->payload($paket), 'recomputed' => true]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['foodalchemist', 'paket', 'price', 'write'],
            'read_only' => false, 'idempotent' => true, 'risk_level' => 'write',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'side_effects' => ['updates'],
            'related_tools' => ['foodalchemist.pakete.PUT', 'foodalchemist.paket_gerichte.SET'],
            'examples' => ['Berechne den Preis von Paket 12 neu.'],
        ];
    }
}
