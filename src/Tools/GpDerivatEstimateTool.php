<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\DerivatAusbeuteService;
use Platform\FoodAlchemist\Services\GpService;

/**
 * Spec 96 · KI schätzt die Ausbeute eines Derivats (gp.derivat_ausbeute) und schreibt sie als Herkunft ki. Ein manueller Wert bleibt.
 * Per MCP umgeleitet (KiUmleitung, Tabelle D): ein propose(), danach nur DB-Schreiben.
 */
class GpDerivatEstimateTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.gp_derivat.ESTIMATE';
    }

    public function getDescription(): string
    {
        return 'KI schätzt die Ausbeute eines team-eigenen Derivat-GP aus seiner Mutter (Saft/Abrieb: Gramm je Stück Mutter; '
            . 'Abschnitte/Parüren: Prozent der Mutter-Masse) und schreibt sie mit Herkunft ki. Ein manuell gepflegter Wert bleibt '
            . 'unangetastet; ist die KI unsicher, wird nichts geschrieben (Lücke „Ausbeute fehlt“ bleibt sichtbar).';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => ['gp_id' => ['type' => 'integer', 'description' => 'Derivat-GP (team-eigen).']],
            'required' => ['gp_id'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        $gp = app(GpService::class)->find((int) ($arguments['gp_id'] ?? 0), $team);
        if ($gp === null) {
            return ToolResult::error('GP nicht sichtbar/vorhanden.', 'NOT_FOUND');
        }
        if (! $gp->isOwnedBy($team)) {
            return ToolResult::error('Derivate pflegt nur das Besitzer-Team.', 'ACCESS_DENIED');
        }
        try {
            $r = app(DerivatAusbeuteService::class)->schaetzeKi($team, (int) $gp->id);
        } catch (\RuntimeException $e) {
            return ToolResult::error($e->getMessage(), 'VALIDATION_ERROR');
        }

        return ToolResult::success(['gp_id' => (int) $gp->id, ...$r]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['foodalchemist', 'gp', 'derivat', 'ausbeute', 'ki', 'schaetzung', 'write'],
            'read_only' => false, 'idempotent' => false, 'risk_level' => 'write',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'llm',
            'side_effects' => ['updates'],
            'related_tools' => ['foodalchemist.gp_derivat.PUT'],
            'examples' => ['Schätze die Ausbeute für GP 123 (Limette: frisch, Saft).'],
        ];
    }
}
