<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\VocabularyService;

/** MCP-Steuerbarkeit · D13: team-eigene Einheit bearbeiten (geerbte/globale sind read-only). */
class VocabEinheitenPutTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.vocab_einheiten.PUT';
    }

    public function getDescription(): string
    {
        return 'Bearbeitet eine team-eigene Einheit (felder: display_de, dimension, default_in_g, default_in_ml).';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'id' => ['type' => 'integer', 'description' => 'Einheit-Id.'],
                'felder' => ['type' => 'object', 'description' => 'display_de, dimension, default_in_g, default_in_ml.'],
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
        $in = array_intersect_key($felder, array_flip(['display_de', 'dimension', 'default_in_g', 'default_in_ml']));
        if ($in === []) {
            return ToolResult::error('Keine bekannten Felder in felder.', 'VALIDATION_ERROR');
        }

        try {
            app(VocabularyService::class)->updateEinheit($team, (int) ($arguments['id'] ?? 0), $in);
        } catch (\RuntimeException $e) {
            return ToolResult::error($e->getMessage(), 'VALIDATION_ERROR');
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return ToolResult::error('Einheit nicht sichtbar/vorhanden.', 'NOT_FOUND');
        }

        return ToolResult::success(['id' => (int) ($arguments['id'] ?? 0), 'updated' => array_keys($in)]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['foodalchemist', 'vocab', 'einheit', 'write'],
            'read_only' => false, 'idempotent' => true, 'risk_level' => 'write',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'side_effects' => ['updates'],
            'related_tools' => ['foodalchemist.vocab_einheiten.TOGGLE'],
            'examples' => ['Setze bei Einheit 5 die Gramm-Umrechnung auf 15.'],
        ];
    }
}
