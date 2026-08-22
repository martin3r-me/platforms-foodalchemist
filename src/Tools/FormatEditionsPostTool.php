<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\FormatService;

/** Format-Modul: bestehendes Konzept als Edition einem Format zuordnen. */
class FormatEditionsPostTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.format_editions.POST';
    }

    public function getDescription(): string
    {
        return 'Ordnet ein bestehendes Konzept (Zusammenstellung) als Edition einem Format zu. '
            . 'Guardet BEIDE Seiten (Format UND Konzept müssen team-eigen sein). Kein Recompute.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'format_id' => ['type' => 'integer'],
                'concept_id' => ['type' => 'integer'],
                'position' => ['type' => 'integer', 'description' => 'optionale Reihenfolge-Position'],
            ],
            'required' => ['format_id', 'concept_id'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }

        try {
            $c = app(FormatService::class)->attachEdition(
                $team,
                (int) $arguments['format_id'],
                (int) $arguments['concept_id'],
                isset($arguments['position']) ? (int) $arguments['position'] : null,
            );
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return ToolResult::error('Format oder Konzept nicht sichtbar/vorhanden.', 'NOT_FOUND');
        } catch (\RuntimeException $e) {
            return ToolResult::error($e->getMessage(), 'VALIDATION_ERROR');
        }

        return ToolResult::success([
            'edition' => ['concept_id' => $c->id, 'name' => $c->name, 'format_id' => $c->format_id, 'position' => (int) $c->format_position],
        ]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['foodalchemist', 'format', 'edition', 'zuordnen'],
            'read_only' => false, 'idempotent' => true, 'risk_level' => 'write',
            'requires_auth' => true, 'requires_team' => true,
            'side_effects' => ['updates'], 'cost_class' => 'local_db',
            'related_tools' => ['foodalchemist.format_editions.DELETE', 'foodalchemist.formats.GET'],
            'examples' => ['Ordne Konzept 12 dem Format 3 zu'],
        ];
    }
}
