<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\GpService;
use Platform\FoodAlchemist\Services\PairingService;

/**
 * Spec 53 Paket J: Aroma-Anker eines GP verknüpfen/lösen. Anders als
 * {@see RecipeAnchorsPutTool} (numerische anker_id) adressiert dieses Tool den Anker über den
 * SLUG — stabiler für die Vault-Brücke alt→neu (Legacy-IDs sind hier nicht die Wahrheit).
 * Mehrere Anker je GP erlaubt (role kern|neben, CAP_GP im Service — s. Altdaten: zusammengesetzte
 * Produkte wie Ratatouille tragen mehrere Kern-Anker).
 */
class GpAnchorsPutTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.gp_anchors.PUT';
    }

    public function getDescription(): string
    {
        return 'Verknüpft/löst einen Aroma-Anker mit einem sichtbaren Grundprodukt (team-scoped). '
            . 'anchor_slug ist der Slug aus dem Aroma-Anker-Vokabular (foodalchemist.composer.ANKER_SUCHE), '
            . 'NICHT die gp_id. role=kern (Haupt-Aromaträger, Default) oder neben (Nebenträger) — mehrere '
            . 'Anker je GP sind erlaubt (CAP im Service, aktuell 3). remove=true löst die Verknüpfung.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'gp_id' => ['type' => 'integer', 'description' => 'GP-Id (sichtbar).'],
                'anchor_slug' => ['type' => 'string', 'description' => 'Slug aus dem Aroma-Anker-Vokabular — zu holen über foodalchemist.composer.ANKER_SUCHE.'],
                'role' => ['type' => 'string', 'enum' => ['kern', 'neben'], 'default' => 'kern', 'description' => 'Haupt- oder Nebenträger.'],
                'remove' => ['type' => 'boolean', 'default' => false, 'description' => 'true = Verknüpfung lösen statt setzen.'],
            ],
            'required' => ['gp_id', 'anchor_slug'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }

        $gpId = (int) ($arguments['gp_id'] ?? 0);
        $gp = app(GpService::class)->find($gpId, $team);
        if ($gp === null) {
            return ToolResult::error('GP nicht sichtbar/vorhanden.', 'NOT_FOUND');
        }

        $slug = trim((string) ($arguments['anchor_slug'] ?? ''));
        $ankerId = $this->pairingAnkerIdFuerSlug($team, $slug);
        if ($ankerId === null) {
            return ToolResult::error(
                "Anker-Slug „{$slug}“ gibt es im Aroma-Anker-Vokabular nicht (oder ist für dieses Team "
                . 'nicht sichtbar). Passende Anker findest du mit foodalchemist.composer.ANKER_SUCHE.',
                'NOT_FOUND'
            );
        }

        $remove = (bool) ($arguments['remove'] ?? false);
        $role = (string) ($arguments['role'] ?? 'kern');
        if (! in_array($role, ['kern', 'neben'], true)) {
            return ToolResult::error('role muss kern|neben sein.', 'VALIDATION_ERROR');
        }

        $svc = app(PairingService::class);
        try {
            $remove
                ? $svc->removeGpAnker($team, $gp->id, $ankerId)
                : $svc->setGpAnker($team, $gp->id, $ankerId, $role, 'mcp');
        } catch (\RuntimeException $e) {
            return ToolResult::error($e->getMessage(), 'VALIDATION_ERROR');
        }

        return ToolResult::success([
            'gp_id' => (int) $gp->id,
            'anchor_slug' => $slug,
            'anchor_id' => $ankerId,
            'role' => $remove ? null : $role,
            'action' => $remove ? 'remove' : 'set',
        ]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['foodalchemist', 'gp', 'grundprodukt', 'anker', 'pairing', 'aroma', 'write'],
            'read_only' => false, 'idempotent' => true, 'risk_level' => 'write',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'side_effects' => ['updates'],
            'related_tools' => ['foodalchemist.composer.ANKER_SUCHE', 'foodalchemist.gp_anchors.GET',
                'foodalchemist.gp_anchors.IMPORT'],
            'examples' => ['Verknüpfe GP 123 mit dem Anker "vanille" als Kern-Träger.',
                'Löse den Nebenträger-Anker "zimt" von GP 123.'],
        ];
    }
}
