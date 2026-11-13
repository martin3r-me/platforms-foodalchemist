<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\PaketService;

/**
 * Abo-/Alt-Pakete (foodalchemist_packages) lesend per MCP — Spec 82: Mandanten sehen ihren Team-Preis.
 * Seit 2026-11 führen foodalchemist.pakete.* die Concepter-Pakete; dieses Tool hält den Lesezugang auf das Paket-Modell
 * der Abos, Speisepläne und Bestellungen (getrennte Id-Räume, darum ein eigenes Tool).
 */
class AboPaketeGetTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.abo_pakete.GET';
    }

    public function getDescription(): string
    {
        return 'Liefert ein Abo-/Alt-Paket (Paket-Modell der Abos, Speisepläne und Bestellungen — NICHT die Concepter-Pakete, '
            . 'dafür foodalchemist.pakete.GET) mit Stammdaten, Preis-Modus und Gericht-Positionen. Bei einer Abo-Fassung steht der '
            . 'Team-Preis des Mandanten in price_per_person (unvollständiger EK = null + price_reason, nie 0 €). Read-only.';
    }

    public function getSchema(): array
    {
        return ['type' => 'object', 'properties' => [
            'id' => ['type' => 'integer', 'description' => 'Paket-Id.'],
            'outlet_id' => ['type' => 'integer', 'description' => 'Optional: Kalkulationsprofil — VK/Person je Kalkulationsprofil (price_per_person_outlet). Fehlt = aktive Brille bzw. Team-Baseline.'],
        ], 'required' => ['id']];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        $paket = app(PaketService::class)->detail($team, (int) ($arguments['id'] ?? 0));
        if ($paket === null) {
            return ToolResult::error('Paket nicht sichtbar/vorhanden.', 'NOT_FOUND');
        }
        $outlet = null;
        if (! empty($arguments['outlet_id'])) {
            $outlet = \Platform\FoodAlchemist\Models\FoodAlchemistOutlet::where('team_id', $team->id)->find((int) $arguments['outlet_id']);
            if ($outlet === null) {
                return ToolResult::error('Kalkulationsprofil nicht gefunden im Team.', 'NOT_FOUND');
            }
        } else {
            $outlet = app(\Platform\FoodAlchemist\Services\ActiveOutletContext::class)
                ->current($team, $context->user?->id !== null ? (int) $context->user->id : null);
        }

        return ToolResult::success(['paket' => $this->paketPayload($paket, true, $outlet, $team)]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'read',
            'tags' => ['foodalchemist', 'paket', 'abo', 'read'],
            'read_only' => true, 'idempotent' => true, 'risk_level' => 'safe',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'side_effects' => [],
            'related_tools' => ['foodalchemist.abo_pakete.LIST', 'foodalchemist.abos.GET', 'foodalchemist.pakete.GET'],
            'examples' => ['Zeig mir Paket 12 mit seinen Gerichten.'],
        ];
    }
}
