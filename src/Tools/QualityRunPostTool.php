<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\QualityRunService;

/**
 * „Ampel neu messen" über MCP — der Detektor-Lauf eines Teams, asynchron.
 *
 * Warum es dieses Tool braucht: der Lauf war ausschließlich über `artisan
 * foodalchemist:signale-detektor` erreichbar, in **keinem** Scheduler registriert (der
 * Command verwies dafür auf den Host-Console-Kernel, wo es nie passierte), und der
 * Cockpit-Knopf lag im nicht-rendernden Core-Slot. Ergebnis auf demo: 20+ Signal-Typen
 * (Rezept/Konzept/Foodbook) und die komplette Zeitreihe existierten im Code, aber nie in
 * den Daten. Eine Ampel, die nur leuchtet, wenn jemand eine Shell öffnet, ist keine Ampel.
 *
 * Deterministisch und **ohne** Provider-Kosten — im Gegensatz zu
 * {@see RecipeFindingsRunPostTool}. Das ist der Grund für zwei Tools statt eines mit Flag:
 * ein Aufrufer, der die Ampel aktualisieren will, darf nicht versehentlich eine
 * Modell-Rechnung auslösen.
 */
class QualityRunPostTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.quality_run.POST';
    }

    public function getDescription(): string
    {
        return 'Startet den Qualitäts-Lauf („Ampel neu messen") für das eigene Team: alle Signal-Detektoren, '
            . 'die Datenqualitäts-Kaskade (LA→GP→Basisrezept→Gericht), den Zeitreihen-Snapshot und den '
            . 'Drift-Vergleich. Deterministisch, kostet KEIN Provider-Geld, idempotent (dedup_key) — '
            . 'aufgelöste Befunde schließt der Lauf selbst. '
            . 'Läuft ASYNCHRON: das Tool gibt eine run_id zurück, der Stand kommt über foodalchemist.runs.GET. '
            . 'Läuft für das Team bereits eine Messung, wird DEREN run_id zurückgegeben (bereits_laufend=true) '
            . 'und kein zweiter Lauf gestartet — zwei parallele Läufe würden beide in dieselbe Zeitreihe '
            . 'schreiben und den Drift-Vergleich verfälschen. '
            . 'Das ist der Lauf, der die Signal-Typen für Rezepte/Konzepte/Foodbooks und die Zeitreihe '
            . 'überhaupt entstehen lässt: OHNE mindestens einen Lauf sind die Signal-Liste unvollständig und '
            . 'der Trend leer (für ein Delta braucht der Trend ZWEI Läufe). '
            . 'Nicht für KI-Befunde am Rezept — das ist foodalchemist.recipe_findings_run.POST (kostet Geld).';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => new \stdClass(),
            'required' => [],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }

        $userId = $context->user?->id !== null ? (int) $context->user->id : null;

        ['run_id' => $runId, 'bereits_laufend' => $schonDa] = app(QualityRunService::class)
            ->starteAmpelLauf($team, $userId);

        return ToolResult::success([
            'run_id' => $runId,
            'bereits_laufend' => $schonDa,
            'hinweis' => $schonDa
                ? "Für dieses Team läuft schon eine Messung (Lauf {$runId}) — kein zweiter Lauf gestartet. "
                    . 'Stand über foodalchemist.runs.GET abfragen.'
                : "Messung eingereiht (Lauf {$runId}). Stand über foodalchemist.runs.GET; die neuen/geschlossenen "
                    . 'Signale danach über foodalchemist.signale.SEARCH.',
        ]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'command',
            // Schreibt Signale, Snapshots und einen Lauf-Datensatz. `idempotent` ist hier
            // ehrlich true: Wiederholung erzeugt keine Dubletten (dedup_key), und der
            // Doppelklick-Schutz verhindert den parallelen Zweitlauf.
            'read_only' => false,
            'idempotent' => true,
            'risk_level' => 'safe',
            'requires_auth' => true,
            'requires_team' => true,
            'cost_class' => 'local_db',
            'tags' => ['foodalchemist', 'quality_run', 'signal', 'detektor', 'ampel', 'datenqualitaet', 'trend', 'zeitreihe', 'lauf'],
            'related_tools' => [
                'foodalchemist.runs.GET',
                'foodalchemist.signale.SEARCH',
                'foodalchemist.signal_trend.GET',
                'foodalchemist.recipe_findings_run.POST',
            ],
            'examples' => [
                'Ampel neu messen',
                'Signale neu erkennen lassen',
                'Warum ist die Zeitreihe leer? (dann diesen Lauf zweimal fahren)',
            ],
        ];
    }
}
