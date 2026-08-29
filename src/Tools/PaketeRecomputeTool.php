<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Models\FoodAlchemistPaket;
use Platform\FoodAlchemist\Services\PaketService;

/** MCP-Steuerbarkeit · D5d: Paket-Preis/EK aus den Gerichten neu ableiten. */
class PaketeRecomputeTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.pakete.RECOMPUTE';
    }

    public function getDescription(): string
    {
        return 'Berechnet EK/Person + Wareneinsatz (im auto-Modus zusätzlich den Preis) eines team-eigenen Pakets aus seinen Gerichten neu.';
    }

    public function getSchema(): array
    {
        return ['type' => 'object', 'properties' => ['id' => ['type' => 'integer', 'description' => 'Paket-Id.']], 'required' => ['id']];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        $id = (int) ($arguments['id'] ?? 0);
        if (($guard = $this->guardOwned($team, FoodAlchemistPaket::class, $id, 'Paket')) !== null) {
            return $guard;
        }
        $svc = app(PaketService::class);
        $paket = $svc->detail($team, $id);
        if ($paket === null) {
            return ToolResult::error('Paket nicht sichtbar/vorhanden.', 'NOT_FOUND');
        }

        $paket = $svc->recomputePrice($paket);

        return ToolResult::success(['paket' => $this->paketPayload($paket), 'recomputed' => true]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['foodalchemist', 'paket', 'price', 'write'],
            'read_only' => false, 'idempotent' => true, 'risk_level' => 'write',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'side_effects' => ['updates'],
            'related_tools' => ['foodalchemist.pakete.PUT', 'foodalchemist.paket_gerichte.SET'],
            'examples' => ['Berechne den Preis von Paket 12 neu.'],
        ];
    }
}
