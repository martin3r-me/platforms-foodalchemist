<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\LagerEinrichtungService;

/** Spec 66b · Stellplatz löschen; seine Grundprodukte verlieren den Stammplatz. */
class StorageBinsDeleteTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.storage_bins.DELETE';
    }

    public function getDescription(): string
    {
        return 'Löscht einen Stellplatz. Die dort einsortierten Grundprodukte verlieren ihren Stammplatz (Bestand bleibt unberührt).';
    }

    public function getSchema(): array
    {
        return ['type' => 'object', 'properties' => ['bin_id' => ['type' => 'integer']], 'required' => ['bin_id']];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        try {
            app(LagerEinrichtungService::class)->stellplatzLoeschen($team, (int) ($arguments['bin_id'] ?? 0));
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            return ToolResult::error('Stellplatz nicht gefunden.', 'NOT_FOUND');
        }

        return ToolResult::success(['bin_id' => (int) $arguments['bin_id'], 'geloescht' => true]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['foodalchemist', 'lager', 'stellplatz', 'write'],
            'read_only' => false, 'idempotent' => true, 'risk_level' => 'write',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'side_effects' => ['deletes'],
            'related_tools' => ['foodalchemist.storage_bins.GET'],
            'examples' => ['Lösch den Stellplatz „Altes Regal".'],
        ];
    }
}
