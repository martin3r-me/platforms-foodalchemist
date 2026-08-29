<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Models\FoodAlchemistConcept;
use Platform\FoodAlchemist\Services\ConceptService;

/** MCP-Steuerbarkeit · D5: Zielpreis für ein team-eigenes Konzept vorschlagen (und optional anwenden). */
class ConceptsPriceTargetTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.concepts.PRICE_TARGET';
    }

    public function getDescription(): string
    {
        return 'Berechnet einen Zielpreis-Vorschlag pro Person für ein team-eigenes Konzept. apply=true übernimmt ihn.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'id' => ['type' => 'integer', 'description' => 'Konzept-Id (team-eigen).'],
                'target_price_per_person' => ['type' => 'number', 'description' => 'Ziel pro Person (€).'],
                'apply' => ['type' => 'boolean', 'description' => 'true übernimmt den Vorschlag.'],
            ],
            'required' => ['id', 'target_price_per_person'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        if (! is_numeric($arguments['target_price_per_person'] ?? null) || (float) $arguments['target_price_per_person'] <= 0) {
            return ToolResult::error('target_price_per_person muss > 0 sein.', 'VALIDATION_ERROR');
        }
        $id = (int) ($arguments['id'] ?? 0);
        if (($guard = $this->guardOwned($team, FoodAlchemistConcept::class, $id, 'Konzept')) !== null) {
            return $guard;
        }

        $svc = app(ConceptService::class);
        try {
            $vorschlag = $svc->zielpreisVorschlag($team, $id, (float) $arguments['target_price_per_person']);
            $applied = ($arguments['apply'] ?? false) === true;
            if ($applied) {
                $svc->zielpreisAnwenden($team, $id, $vorschlag);
            }
        } catch (\RuntimeException $e) {
            return ToolResult::error($e->getMessage(), 'VALIDATION_ERROR');
        }

        return ToolResult::success(['id' => $id, 'applied' => $applied, 'vorschlag' => $vorschlag]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['foodalchemist', 'concept', 'zielpreis', 'write'],
            'read_only' => false, 'idempotent' => false, 'risk_level' => 'write',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'side_effects' => ['updates'],
            'related_tools' => ['foodalchemist.concepts.RECOMPUTE'],
            'examples' => ['Schlage für Konzept 7 einen Zielpreis von 45 €/Person vor (apply=true).'],
        ];
    }
}
