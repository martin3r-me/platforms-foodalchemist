<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Models\FoodAlchemistCascadeRunStep;
use Platform\FoodAlchemist\Services\PlanningCascadeService;

/**
 * Etappe 9 · Slice 2 (Roadmap »Mise en Place«) — MCP-Kaskaden-**FREIGABE** (Gate 2), WRITE.
 *
 * Gibt EINEN `done`-Schritt eines Kaskaden-Laufs frei (Draft → live) headless. In gestuften Läufen
 * startet die Freigabe zugleich die nächste Ebene + die Anreicherung (wie in der UI). Ein Schritt,
 * der nicht im Zustand `done` ist, ist ein No-op (der zurückgegebene Status zeigt es).
 *
 * ⚠ **Bewusste Aufhebung der human-only-Regel** (Entscheidung Dominique 2026-08-17): die Freigabe
 * (Gate 2) war per Nordstern menschlich; der Trigger via MCP ist nun freigegeben. Der Schutz bleibt
 * die **Tenancy** — {@see PlanningCascadeService::gibStepFrei} routet über `ownedStep`
 * (visibleToTeam + isOwnedBy/D1), ein geerbter/fremder Schritt wird abgewiesen.
 */
class PlanungKaskadeFreigabePostTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.planung_kaskade.FREIGABE';
    }

    public function getDescription(): string
    {
        return 'Gibt EINEN Schritt eines Planungs-Kaskaden-Laufs frei, headless. Zwei Fälle: (1) ein done-'
            . 'Schritt wird live gesetzt (Draft → approved/active) und startet in gestuften Läufen die nächste '
            . 'Ebene + Anreicherung. (2) Kapitel-Gate der gestuften Foodbook-Vollkaskade: ein GEPLANTER '
            . 'Kapitel-Concept-Schritt wird durch die Freigabe ERZEUGT (die Concept-Generierung startet) — so '
            . 'geht man Kapitel für Kapitel durch (Struktur prüfen → Kapitel freigeben → Concept-Entwurf → '
            . 'freigeben → Gänge). Nur team-eigene Schritte (isOwnedBy); ein queued/running-Schritt ist ein '
            . 'No-op. Liefert den Lauf-Status wie planung_kaskade.GET zurück.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'step_id' => ['type' => 'integer', 'description' => 'ID des freizugebenden Kaskaden-Schritts (Zustand done, team-eigen).'],
            ],
            'required' => ['step_id'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        $stepId = (int) $arguments['step_id'];

        // gibStepFrei ist über ownedStep (visibleToTeam + isOwnedBy/D1) geschützt → fremder/geerbter
        // Schritt wirft. Nicht-done-Schritt = stiller No-op (kein Wurf).
        try {
            app(PlanningCascadeService::class)->gibStepFrei($team, $stepId);
        } catch (\Throwable $e) {
            return ToolResult::error('Freigabe nicht möglich: ' . $e->getMessage(), 'FREIGABE_FAILED');
        }

        // Lauf-Status zurückgeben (der Schritt ist nach dem Guard nachweislich team-eigen).
        $runId = (int) (FoodAlchemistCascadeRunStep::whereKey($stepId)->value('cascade_run_id') ?? 0);
        $status = $runId > 0 ? app(PlanningCascadeService::class)->laufStatus($team, $runId) : null;

        return ToolResult::success($status ?? ['step_id' => $stepId, 'freigegeben' => true]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'mutation',
            'tags' => ['foodalchemist', 'planung', 'kaskade', 'leitstelle', 'freigabe', 'gate', 'headless'],
            'read_only' => false,
            'idempotent' => false,
            'risk_level' => 'write',
            'requires_auth' => true,
            'requires_team' => true,
            'cost_class' => 'local_db',
            'related_tools' => ['foodalchemist.planung_kaskade.GET', 'foodalchemist.planung_kaskade.START'],
            'examples' => ['Gib Schritt 128 der Kaskade frei', 'Freigabe des Gericht-Schritts 128'],
        ];
    }
}
