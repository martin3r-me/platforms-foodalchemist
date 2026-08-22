<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\FormatService;

/** Format-Modul: Format-Identität pflegen (nur Besitzer-Team). */
class FormatsPutTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.formats.PUT';
    }

    public function getDescription(): string
    {
        return 'Aktualisiert die Identität eines Formats (name, consumer_name, claim, story, origin, customer, status). '
            . 'Nur das Besitzer-Team darf pflegen (geerbte Formate sind read-only).';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'format_id' => ['type' => 'integer'],
                'name' => ['type' => 'string'],
                'consumer_name' => ['type' => 'string'],
                'claim' => ['type' => 'string'],
                'story' => ['type' => 'string'],
                'origin' => ['type' => 'string', 'enum' => ['eigen', 'gruppe', 'kunde']],
                'customer' => ['type' => 'string'],
                'status' => ['type' => 'string', 'enum' => ['draft', 'active', 'archiviert']],
            ],
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
            $f = app(FormatService::class)->update($team, (int) $arguments['format_id'], array_intersect_key($arguments, array_flip([
                'name', 'consumer_name', 'claim', 'story', 'origin', 'customer', 'status',
            ])));
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return ToolResult::error('Format nicht sichtbar/vorhanden.', 'NOT_FOUND');
        } catch (\RuntimeException $e) {
            return ToolResult::error($e->getMessage(), 'VALIDATION_ERROR');
        }

        return ToolResult::success([
            'format' => ['id' => $f->id, 'name' => $f->name, 'status' => $f->status, 'origin' => $f->origin],
        ]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['foodalchemist', 'format', 'foodkonzept', 'bearbeiten'],
            'read_only' => false, 'idempotent' => true, 'risk_level' => 'write',
            'requires_auth' => true, 'requires_team' => true,
            'side_effects' => ['updates'], 'cost_class' => 'local_db',
            'related_tools' => ['foodalchemist.formats.GET', 'foodalchemist.format_editions.POST'],
            'examples' => ['Setze die Story von Format 3', 'Aktiviere Format 3'],
        ];
    }
}
