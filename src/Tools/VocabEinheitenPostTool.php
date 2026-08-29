<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\VocabularyService;

/** MCP-Steuerbarkeit · D13: Einheit-Vokabular anlegen (team-eigen). Safe-additiv, kein Delete. */
class VocabEinheitenPostTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.vocab_einheiten.POST';
    }

    public function getDescription(): string
    {
        return 'Legt eine team-eigene Einheit an (slug, display_de, dimension mass|volume|count; optional default_in_g/default_in_ml).';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'slug' => ['type' => 'string', 'description' => 'Einheit-Slug (z.B. el, tl).'],
                'display_de' => ['type' => 'string', 'description' => 'Anzeigename.'],
                'dimension' => ['type' => 'string', 'description' => 'mass|volume|count.'],
                'default_in_g' => ['type' => 'number', 'description' => 'Umrechnung in Gramm (bei mass).'],
                'default_in_ml' => ['type' => 'number', 'description' => 'Umrechnung in ml (bei volume).'],
            ],
            'required' => ['slug', 'display_de', 'dimension'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        $in = array_intersect_key($arguments, array_flip(['slug', 'display_de', 'dimension', 'default_in_g', 'default_in_ml']));

        try {
            $unit = app(VocabularyService::class)->createEinheit($team, $in);
        } catch (\RuntimeException $e) {
            return ToolResult::error($e->getMessage(), 'VALIDATION_ERROR');
        }

        return ToolResult::success(['id' => (int) $unit->id, 'slug' => $unit->slug]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['foodalchemist', 'vocab', 'einheit', 'write'],
            'read_only' => false, 'idempotent' => false, 'risk_level' => 'write',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'side_effects' => ['creates'],
            'related_tools' => ['foodalchemist.vocab_einheiten.PUT', 'foodalchemist.vocab_einheiten.TOGGLE'],
            'examples' => ['Lege die Einheit „EL" (Esslöffel, volume, 15 ml) an.'],
        ];
    }
}
