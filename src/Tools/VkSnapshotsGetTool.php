<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\VkSnapshotService;

/**
 * R2.5 (read): veröffentlichter VK-Stand — welche Darreichungen weichen mit ihrem
 * LIVE gerechneten VK vom freigegebenen Snapshot ab (Kandidaten für „VK-Anpassung
 * empfohlen"), Richtung + Delta. Die Kundensicht zeigt nur den freigegebenen VK;
 * dieses Tool macht die interne Abweichung sichtbar. Freigabe = vk_snapshots.RELEASE.
 */
class VkSnapshotsGetTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.vk_snapshots.GET';
    }

    public function getDescription(): string
    {
        return 'Listet Darreichungen, deren intern gerechneter (Live-)VK vom freigegebenen '
            . 'Kunden-VK-Snapshot über die Leitplanke abweicht — mit freigegebenem vs. Live-Preis, '
            . 'Delta % und Richtung (erhöhen/senken). Read-only. Freigabe (Snapshot schreiben) '
            . 'läuft über foodalchemist.vk_snapshots.RELEASE. Optional max_delta_pct überschreibt die Team-Leitplanke.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'max_delta_pct' => ['type' => 'number', 'description' => 'Optionale Schwelle statt Team-Leitplanke max_vk_delta_pct'],
            ],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        $maxDelta = isset($arguments['max_delta_pct']) ? (float) $arguments['max_delta_pct'] : null;
        $pending = app(VkSnapshotService::class)->pending($team, $maxDelta);

        return ToolResult::success([
            'anzahl' => count($pending),
            'pending' => $pending,
            'hinweis' => 'Live-VK weicht vom freigegebenen Snapshot ab — bewusst per vk_snapshots.RELEASE freigeben oder Live-Kalkulation prüfen.',
        ]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'query',
            'tags' => ['foodalchemist', 'vk', 'preis', 'snapshot', 'freigabe', 'marge', 'saison'],
            'read_only' => true,
            'idempotent' => true,
            'risk_level' => 'safe',
            'requires_auth' => true,
            'requires_team' => true,
            'cost_class' => 'local_db',
            'related_tools' => ['foodalchemist.vk_snapshots.RELEASE', 'foodalchemist.signale.SEARCH'],
            'examples' => ['Welche VK weichen vom freigegebenen Kundenpreis ab?'],
        ];
    }
}
