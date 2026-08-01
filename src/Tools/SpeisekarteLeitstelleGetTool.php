<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\SpeisekarteLeitstelleService;

/** Fertigstellungs-Checkliste einer Speisekarte (read-only Cockpit). */
class SpeisekarteLeitstelleGetTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.speisekarte_leitstelle.GET';
    }

    public function getDescription(): string
    {
        return 'Abgeleitete Checkliste zum Karten-Zustand (Rubriken, Positionen, Preise vollständig, '
            . 'Allergene bekannt, Branding) + „bereit"-Flag. Read-only.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'speisekarte_id' => ['type' => 'integer'],
            ],
            'required' => ['speisekarte_id'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }

        try {
            $stand = app(SpeisekarteLeitstelleService::class)->checkliste($team, (int) $arguments['speisekarte_id']);
        } catch (\Throwable $e) {
            return ToolResult::error($e->getMessage(), 'NOT_FOUND');
        }

        return ToolResult::success($stand);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'query',
            'tags' => ['foodalchemist', 'speisekarte', 'leitstelle', 'checkliste'],
            'read_only' => true, 'idempotent' => true, 'risk_level' => 'read',
            'requires_auth' => true, 'requires_team' => true,
            'side_effects' => [], 'cost_class' => 'local_db',
            'related_tools' => ['foodalchemist.speisekarte.GET'],
            'examples' => ['Ist Speisekarte 3 fertig?'],
        ];
    }
}
