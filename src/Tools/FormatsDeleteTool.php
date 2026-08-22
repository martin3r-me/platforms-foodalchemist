<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\FormatService;

/** Format-Modul: Format löschen. Editionen werden wieder freistehend (nullOnDelete). */
class FormatsDeleteTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.formats.DELETE';
    }

    public function getDescription(): string
    {
        return 'Löscht ein Format. Die zugeordneten Editionen (Konzepte) bleiben erhalten und werden wieder '
            . 'freistehend; die Marketing-Bildwelt wird mitgelöscht. Nur das Besitzer-Team.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => ['format_id' => ['type' => 'integer']],
            'required' => ['format_id'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }

        try {
            app(FormatService::class)->delete($team, (int) $arguments['format_id']);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return ToolResult::error('Format nicht sichtbar/vorhanden.', 'NOT_FOUND');
        } catch (\RuntimeException $e) {
            return ToolResult::error($e->getMessage(), 'VALIDATION_ERROR');
        }

        return ToolResult::success(['deleted' => true]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['foodalchemist', 'format', 'foodkonzept', 'loeschen'],
            'read_only' => false, 'idempotent' => false, 'risk_level' => 'write',
            'requires_auth' => true, 'requires_team' => true,
            'side_effects' => ['deletes'], 'cost_class' => 'local_db',
            'related_tools' => ['foodalchemist.formats.GET'],
            'examples' => ['Lösche Format 3'],
        ];
    }
}
