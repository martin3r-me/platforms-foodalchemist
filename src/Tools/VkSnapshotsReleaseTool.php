<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\VkSnapshotService;

/**
 * R2.5 (write): VK-Freigabe — friert den aktuellen Live-VK der genannten Darreichungen
 * als Kunden-Snapshot ein (Batch). EINZIGE Art, den veröffentlichten Preis zu ändern
 * — bewusst, menschlich angestoßen, kein stiller Kunden-Preissprung. Nur team-EIGENE
 * Darreichungen (isOwnedBy); fremde werden übersprungen.
 */
class VkSnapshotsReleaseTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.vk_snapshots.RELEASE';
    }

    public function getDescription(): string
    {
        return 'Gibt den aktuellen Live-VK der genannten Darreichungen (presentation_ids) als '
            . 'Kunden-Snapshot frei (Batch). Danach zeigt die Kundensicht diesen freigegebenen VK. '
            . 'Nur eigene Darreichungen des aktuellen Teams. presentation_ids via '
            . 'foodalchemist.vk_snapshots.GET (pending) ermitteln.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'presentation_ids' => [
                    'type' => 'array',
                    'items' => ['type' => 'integer'],
                    'description' => 'IDs der freizugebenden Darreichungen (recipe_presentations)',
                ],
            ],
            'required' => ['presentation_ids'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        $ids = $arguments['presentation_ids'] ?? [];
        if (! is_array($ids) || $ids === []) {
            return ToolResult::error('presentation_ids (Liste) ist erforderlich.', 'VALIDATION_ERROR');
        }
        $userId = is_object($context->user ?? null) && isset($context->user->id) ? (int) $context->user->id : null;
        $n = app(VkSnapshotService::class)->release($team, array_map('intval', $ids), $userId);

        return ToolResult::success([
            'freigegeben' => $n,
            'angefordert' => count($ids),
            'hinweis' => $n < count($ids) ? 'Nicht-eigene/unbekannte Darreichungen wurden übersprungen (D1-Schreibrecht).' : 'Alle freigegeben.',
        ]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'command',
            'tags' => ['foodalchemist', 'vk', 'preis', 'snapshot', 'freigabe', 'publish'],
            'read_only' => false,
            'idempotent' => false,
            'risk_level' => 'write',
            'requires_auth' => true,
            'requires_team' => true,
            'cost_class' => 'local_db',
            'related_tools' => ['foodalchemist.vk_snapshots.GET'],
            'examples' => ['Gib den neuen VK für Darreichung 4471 frei.'],
        ];
    }
}
