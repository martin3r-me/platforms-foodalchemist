<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\PlanningCascadeService;

/**
 * Etappe 9 (Roadmap »Mise en Place«) — MCP-Kaskaden-Status, READ-ONLY.
 *
 * Der headless-Blick auf einen Planungs-Kaskaden-Lauf: Lauf-Kopf (scope/status/gestuft/Lineage),
 * je Ebene (rezept|gericht|concept|gp) ein Status-Aggregat, die Einzel-Schritte (inkl.
 * Anreicherungs-/Bild-Status) und ein Handlungs-Hinweis aus dem Run-Status.
 *
 * GO/FREIGABE OHNE MCP-TRIGGER: analog zur Foodbook-Leitstelle ({@see LeitstelleGetTool}) ist der
 * Start der Kaskade und jede Freigabe human-only (Nordstern »nichts läuft still«, Spec MCP-Lockstep).
 * Dieses Tool LIEST nur — es startet, verwirft oder gibt nichts frei.
 */
class PlanungKaskadeStatusGetTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.planung_kaskade.GET';
    }

    public function getDescription(): string
    {
        return 'Den Status eines Planungs-Kaskaden-Laufs lesen (headless). Liefert den Lauf-Kopf '
            . '(scope rezept|gericht|concept|vollkaskade · status running|review|done|failed · gestuft · '
            . 'planning_session_id · origin_dish_idea_id), je Ebene ein Status-Aggregat (gesamt/geplant/laufend/'
            . 'entwurf_offen/freigegeben/uebernommen/verworfen/fehlgeschlagen), die Einzel-Schritte '
            . '(ebene/label/status/tiefe/ref + Anreicherungs-/Bild-Status) sowie einen Handlungs-Hinweis. '
            . 'READ-ONLY — Start und Freigabe der Kaskade sind human-only und laufen NICHT über MCP.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'run_id' => ['type' => 'integer', 'description' => 'ID des Kaskaden-Laufs (team-sichtbar).'],
            ],
            'required' => ['run_id'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }

        $status = app(PlanningCascadeService::class)->laufStatus($team, (int) $arguments['run_id']);
        if ($status === null) {
            return ToolResult::error('Kaskaden-Lauf nicht gefunden oder nicht team-sichtbar.', 'NOT_FOUND');
        }

        return ToolResult::success($status);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'query',
            'tags' => ['foodalchemist', 'planung', 'kaskade', 'leitstelle', 'status', 'headless'],
            'read_only' => true,
            'idempotent' => true,
            'risk_level' => 'safe',
            'requires_auth' => true,
            'requires_team' => true,
            'cost_class' => 'local_db',
            'related_tools' => ['foodalchemist.planung_session.GET', 'foodalchemist.planning.GET', 'foodalchemist.leitstelle.GET'],
            'examples' => ['Wo steht der Kaskaden-Lauf 42?', 'Welche Stufen des Planungs-Laufs 42 warten noch auf Freigabe?'],
        ];
    }
}
