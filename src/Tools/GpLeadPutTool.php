<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Services\LeadLaService;

/**
 * R9.2 (write): manueller Lead-LA-Override für einen GP — mit Begründung (E5, geht in
 * die Override-Historie via LogsActivity) + Recompute der GP-nutzenden Rezepte (neuer
 * Lead ⇒ neuer EK). Nur team-EIGENE GPs (D1); geerbte Katalog-GPs steuert man über
 * Team-Overlay (Pin/Sperre), nicht über den globalen Lead.
 */
class GpLeadPutTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.gp_lead.PUT';
    }

    public function getDescription(): string
    {
        return 'Setzt den Lead-Lieferantenartikel eines Grundprodukts manuell (la_id; null = zurück '
            . 'auf Heuristik) mit Begründung (reason). Rechnet die GP-nutzenden Rezepte neu (neuer '
            . 'Lead-EK). Nur eigene GPs des aktuellen Teams. la_id muss mit dem GP verknüpft sein.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'gp_id' => ['type' => 'integer'],
                'la_id' => ['type' => ['integer', 'null'], 'description' => 'Lead-LA; null = Override lösen (Heuristik)'],
                'reason' => ['type' => 'string', 'description' => 'Begründung des Overrides (Historie)'],
                'recompute' => ['type' => 'boolean', 'default' => true],
            ],
            'required' => ['gp_id'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        $gp = FoodAlchemistGp::visibleToTeam($team)->find((int) $arguments['gp_id']);
        if ($gp === null) {
            return ToolResult::error('GP nicht sichtbar/vorhanden.', 'NOT_FOUND');
        }
        if (! $gp->isOwnedBy($team)) {
            return ToolResult::error('Geerbtes Katalog-GP — globalen Lead nur im Besitzer-Team ändern; im eigenen Team via Pin/Sperre steuern (D1).', 'ACCESS_DENIED');
        }

        $laId = array_key_exists('la_id', $arguments) && $arguments['la_id'] !== null ? (int) $arguments['la_id'] : null;
        $reason = isset($arguments['reason']) ? (string) $arguments['reason'] : null;
        $recompute = (bool) ($arguments['recompute'] ?? true);

        try {
            app(LeadLaService::class)->setLeadLa($team, $gp, $laId, $reason, $recompute);
        } catch (\RuntimeException $e) {
            return ToolResult::error($e->getMessage(), 'VALIDATION_ERROR');
        }

        return ToolResult::success(app(LeadLaService::class)->leadSteuerung($gp->refresh(), $team));
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'command',
            'tags' => ['foodalchemist', 'lead', 'lieferant', 'gp', 'override', 'recompute'],
            'read_only' => false,
            'idempotent' => false,
            'risk_level' => 'write',
            'requires_auth' => true,
            'requires_team' => true,
            'cost_class' => 'local_db',
            'related_tools' => ['foodalchemist.gp_lead.GET', 'foodalchemist.suppliers.VOLUME'],
            'examples' => ['Setz für GP 812 die LA 4471 als Lead, weil bessere Liefertreue.'],
        ];
    }
}
