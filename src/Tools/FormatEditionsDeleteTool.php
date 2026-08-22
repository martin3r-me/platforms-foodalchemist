<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\FormatService;

/** Format-Modul: Edition aus ihrem Format lösen (Konzept wird wieder freistehend). */
class FormatEditionsDeleteTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.format_editions.DELETE';
    }

    public function getDescription(): string
    {
        return 'Löst ein Konzept aus seinem Format (die Zusammenstellung wird wieder freistehend). '
            . 'Nur das Besitzer-Team. Kein Recompute.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => ['concept_id' => ['type' => 'integer']],
            'required' => ['concept_id'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }

        try {
            $c = app(FormatService::class)->detachEdition($team, (int) $arguments['concept_id']);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return ToolResult::error('Konzept nicht sichtbar/vorhanden.', 'NOT_FOUND');
        } catch (\RuntimeException $e) {
            return ToolResult::error($e->getMessage(), 'VALIDATION_ERROR');
        }

        return ToolResult::success(['concept_id' => $c->id, 'format_id' => $c->format_id]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['foodalchemist', 'format', 'edition', 'loesen'],
            'read_only' => false, 'idempotent' => true, 'risk_level' => 'write',
            'requires_auth' => true, 'requires_team' => true,
            'side_effects' => ['updates'], 'cost_class' => 'local_db',
            'related_tools' => ['foodalchemist.format_editions.POST'],
            'examples' => ['Löse Konzept 12 aus seinem Format'],
        ];
    }
}
