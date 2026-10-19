<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Models\FoodAlchemistOutlet;
use Platform\FoodAlchemist\Services\AusgabeDriftService;

/**
 * Spec 85 F5 (Dominique 19.10.): Kundenschutz ist das Einfrieren der Preise beim Veröffentlichen eines Links. Dieses Tool zeigt,
 * welche veröffentlichten Links (Foodbook, Speisekarte, Speiseplan, Angebot) Preise tragen, die über die Team-Leitplanke vom
 * aktuell gerechneten VK abweichen. Abhilfe ist neu veröffentlichen — eine eigene VK-Freigabe gibt es nicht mehr
 * (ersetzt vk_snapshots.GET/.RELEASE).
 */
class AusgabeDriftGetTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.ausgabe_drift.GET';
    }

    public function getDescription(): string
    {
        return 'Listet veröffentlichte Links (Foodbook, Speisekarte, Speiseplan, Angebot), deren beim Veröffentlichen eingefrorene Preise '
            . 'über die Team-Leitplanke (max_vk_delta_pct) vom aktuell gerechneten VK abweichen — je Zeile eingefrorener Preis, aktueller '
            . 'Preis, Delta % und Richtung. Ohne outlet_id die Team-Links, mit outlet_id die Links dieses Betriebs. Read-only; '
            . 'übernommen werden aktuelle Preise durch erneutes Veröffentlichen mit „Preise aktualisieren“.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'outlet_id' => ['type' => 'integer', 'description' => 'Optional: Betrieb (IDs via outlets.GET) — dann dessen veröffentlichte Links statt der Team-Links.'],
            ],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        $outlet = null;
        if (! empty($arguments['outlet_id'])) {
            $outlet = FoodAlchemistOutlet::where('team_id', $team->id)->find((int) $arguments['outlet_id']);
            if ($outlet === null) {
                return ToolResult::error('Betrieb nicht gefunden im Team.', 'NOT_FOUND');
            }
        }
        $drift = app(AusgabeDriftService::class)->abgedriftet($team, $outlet);

        return ToolResult::success([
            'anzahl' => count($drift),
            'ausgaben' => $drift,
            'hinweis' => $drift === [] ? 'Alle veröffentlichten Links tragen aktuelle Preise (innerhalb der Leitplanke).'
                : 'Diese Links zeigen Kunden ältere Preise. Erneut veröffentlichen mit „Preise aktualisieren“ übernimmt die aktuellen.',
        ]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'query',
            'tags' => ['foodalchemist', 'vk', 'preis', 'veroeffentlichung', 'link', 'drift', 'controlling'],
            'read_only' => true,
            'idempotent' => true,
            'risk_level' => 'safe',
            'requires_auth' => true,
            'requires_team' => true,
            'cost_class' => 'local_db',
            'related_tools' => ['foodalchemist.signale.SEARCH'],
            'examples' => ['Welche veröffentlichten Links zeigen veraltete Preise?'],
        ];
    }
}
