<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\GpService;
use Platform\FoodAlchemist\Services\LeadLaService;

/**
 * MCP-Steuerbarkeit · D1: LA↔GP-Beziehung steuern (action-Enum).
 *
 * Zwei Autorisierungs-Klassen (spiegelt DetailPanel::laAktion):
 * - link/unlink = Katalog-Struktur → nur Besitzer-Team (isOwnedBy, „nurKurator").
 * - lock/unlock/pin/unpin = Team-Overlay (V-27) → jedes Team, das das GP sieht; schreibt eine
 *   team-scoped Präferenz. Lead-LA direkt setzen → gp_lead.PUT.
 */
class GpLaPutTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    private const ACTIONS = ['link', 'unlink', 'lock', 'unlock', 'pin', 'unpin'];

    /** Aktionen, die das Besitzer-Team erfordern (Katalog-Struktur). */
    private const KATALOG = ['link', 'unlink'];

    public function getName(): string
    {
        return 'foodalchemist.gp_la.PUT';
    }

    public function getDescription(): string
    {
        return 'Steuert die LA↔GP-Beziehung: link/unlink (Struktur-Mapping, nur Besitzer-Team), '
            . 'lock/unlock (LA aus der Lead-Wahl ausschließen, team-eigen), pin/unpin (LA bevorzugen, team-eigen). '
            . 'la_item_id ist Pflicht außer bei unpin. Lead-LA direkt setzen → gp_lead.PUT.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'gp_id' => ['type' => 'integer', 'description' => 'GP-Id.'],
                'action' => ['type' => 'string', 'enum' => self::ACTIONS, 'description' => 'Aktion.'],
                'la_item_id' => ['type' => 'integer', 'description' => 'Lieferantenartikel-Id (Pflicht außer unpin).'],
            ],
            'required' => ['gp_id', 'action'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }

        $action = (string) ($arguments['action'] ?? '');
        if (! in_array($action, self::ACTIONS, true)) {
            return ToolResult::error('action muss einer von: ' . implode(', ', self::ACTIONS) . ' sein.', 'VALIDATION_ERROR');
        }

        $gp = app(GpService::class)->find((int) ($arguments['gp_id'] ?? 0), $team);
        if ($gp === null) {
            return ToolResult::error('GP nicht sichtbar/vorhanden.', 'NOT_FOUND');
        }
        if (in_array($action, self::KATALOG, true) && ! $gp->isOwnedBy($team)) {
            return ToolResult::error('Struktur-Mapping nur fürs Besitzer-Team (link/unlink). Team-eigen: lock/pin.', 'ACCESS_DENIED');
        }

        $laId = isset($arguments['la_item_id']) ? (int) $arguments['la_item_id'] : 0;
        if ($action !== 'unpin' && $laId <= 0) {
            return ToolResult::error("action {$action} erfordert la_item_id.", 'VALIDATION_ERROR');
        }

        $svc = app(LeadLaService::class);
        try {
            match ($action) {
                'link' => $svc->verknuepfen($team, $gp, $laId),
                'unlink' => $svc->entknuepfen($team, $gp, $laId),
                'lock' => $svc->sperren($team, $gp, $laId, true),
                'unlock' => $svc->sperren($team, $gp, $laId, false),
                'pin' => $svc->pinnen($team, $gp, $laId),
                'unpin' => $svc->pinnen($team, $gp, null),
            };
        } catch (\RuntimeException $e) {
            return ToolResult::error($e->getMessage(), 'VALIDATION_ERROR');
        }

        return ToolResult::success([
            'gp_id' => (int) $gp->id,
            'action' => $action,
            'la_item_id' => $action === 'unpin' ? null : $laId,
        ]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['foodalchemist', 'gp', 'la', 'lieferantenartikel', 'mapping', 'write'],
            'read_only' => false, 'idempotent' => true, 'risk_level' => 'write',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'side_effects' => ['updates'],
            'related_tools' => ['foodalchemist.gp_lead.PUT', 'foodalchemist.gps.GET'],
            'examples' => ['Verknüpfe LA 4711 mit GP 123.', 'Sperre LA 4711 bei GP 123 aus der Lead-Wahl.'],
        ];
    }
}
