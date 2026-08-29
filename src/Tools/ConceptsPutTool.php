<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Models\FoodAlchemistConcept;
use Platform\FoodAlchemist\Services\ConceptService;

/** MCP-Steuerbarkeit · D5: Konzept-Stammdaten bearbeiten (team-eigen; Service-Whitelist). */
class ConceptsPutTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.concepts.PUT';
    }

    public function getDescription(): string
    {
        return 'Bearbeitet ein team-eigenes Gerichte-Konzept. felder: name, occasion, level, class, description, '
            . 'target_price_per_person, price_mode, price_display, kundentyp, default_niveau, default_convenience, '
            . 'writing_style_id … (nur Service-Whitelist wirkt). Status → concepts.STATUS.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'id' => ['type' => 'integer', 'description' => 'Konzept-Id (team-eigen).'],
                'felder' => ['type' => 'object', 'description' => 'Zu schreibende Konzept-Felder.'],
            ],
            'required' => ['id', 'felder'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        $felder = $arguments['felder'] ?? null;
        if (! is_array($felder) || $felder === []) {
            return ToolResult::error('felder muss ein nicht-leeres Objekt sein.', 'VALIDATION_ERROR');
        }
        $id = (int) ($arguments['id'] ?? 0);
        if (($guard = $this->guardOwned($team, FoodAlchemistConcept::class, $id, 'Konzept')) !== null) {
            return $guard;
        }

        try {
            $c = app(ConceptService::class)->update($team, $id, $felder);
        } catch (\RuntimeException $e) {
            return ToolResult::error($e->getMessage(), 'VALIDATION_ERROR');
        }

        return ToolResult::success(['id' => (int) $c->id, 'name' => $c->name]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['foodalchemist', 'concept', 'konzept', 'bearbeiten', 'write'],
            'read_only' => false, 'idempotent' => true, 'risk_level' => 'write',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'side_effects' => ['updates'],
            'related_tools' => ['foodalchemist.concepts.STATUS', 'foodalchemist.concepts.GET'],
            'examples' => ['Setze bei Konzept 7 den Zielpreis/Preis-Modus.'],
        ];
    }
}
