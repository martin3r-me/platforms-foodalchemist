<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\QualityRunService;
use Platform\FoodAlchemist\Services\RecipeFindingService;
use Platform\FoodAlchemist\Services\RecipeFindingsBatchService;

/**
 * „KI-Befunde sammeln" über MCP — der Copilot-Batch über die fällige Arbeitsmenge.
 *
 * Dieser Lauf ruft das Modell **pro Rezept**. Er ist bewusst von
 * {@see QualityRunPostTool} getrennt und nicht als Flag daran gehängt: die Ampel-Messung
 * ist gratis, dieser Lauf kostet, und ein Aufrufer soll die Rechnung wollen müssen.
 *
 * Er ist außerdem die Voraussetzung für zwei Dinge, die ohne ihn wie Bugs aussehen:
 * `rezept_plausi_ki`-Signale entstehen nur aus abgelegten Befunden, und den
 * „Lass das so"-Knopf im Copilot-Panel gibt es nur für Befunde mit `finding_id` — ein
 * Ad-hoc-„Rezept prüfen" im Modal legt keine ab.
 */
class RecipeFindingsRunPostTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.recipe_findings_run.POST';
    }

    public function getDescription(): string
    {
        return 'Startet den Rezept-Copilot als Batch über die FÄLLIGEN Rezepte des eigenen Teams (nie geprüft '
            . 'oder seit der Prüfung geändert) und legt die Befunde ab. '
            . '⚠️ KOSTET PROVIDER-GELD: ein Modell-Call pro Rezept. limit ist die Egress-Bremse '
            . '(Default ' . RecipeFindingsBatchService::DEFAULT_LIMIT . ', Maximum ' . RecipeFindingsBatchService::MAX_LIMIT
            . '); über mehrere Läufe arbeitet der Batch den Bestand ab, statt ihn jedes Mal komplett zu bezahlen. '
            . 'Läuft ASYNCHRON: gibt eine run_id zurück, Stand über foodalchemist.runs.GET. '
            . 'Mehrfach-Start ist erlaubt und sinnvoll (die Fälligkeits-Auswahl überspringt schon Geprüftes) — '
            . 'es gibt hier absichtlich KEINEN Doppelklick-Schutz, die Bremse ist das Limit. '
            . 'Wendet nichts an: Befunde sind Vorschläge, die Übernahme bleibt eine menschliche '
            . 'Einzel-Entscheidung im Copilot-Panel. '
            . 'pass=copilot prüft die Rezeptur (fehlende/falsch dosierte Zutaten), pass=bauart klärt '
            . 'Gericht-vs-Komponente. '
            . 'Für die kostenlose Ampel-Messung stattdessen foodalchemist.quality_run.POST nehmen.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'limit' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'maximum' => RecipeFindingsBatchService::MAX_LIMIT,
                    'default' => RecipeFindingsBatchService::DEFAULT_LIMIT,
                    'description' => 'Höchstens so viele fällige Rezepte in diesem Lauf. Jedes kostet einen Modell-Call.',
                ],
                'nur_verkauf' => [
                    'type' => 'boolean',
                    'default' => false,
                    'description' => 'Nur VK-Gerichte prüfen statt aller Rezepte.',
                ],
                'pass' => [
                    'type' => 'string',
                    'enum' => [RecipeFindingService::PASS_COPILOT, RecipeFindingService::PASS_BAUART],
                    'default' => RecipeFindingService::PASS_COPILOT,
                    'description' => 'copilot = Rezeptur-Befunde · bauart = Gericht-vs-Komponente.',
                ],
            ],
            'required' => [],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }

        $pass = (string) ($arguments['pass'] ?? RecipeFindingService::PASS_COPILOT);
        if (! in_array($pass, [RecipeFindingService::PASS_COPILOT, RecipeFindingService::PASS_BAUART], true)) {
            return ToolResult::error(
                "Unbekannter Pass \"{$pass}\". Erlaubt: " . RecipeFindingService::PASS_COPILOT
                    . ', ' . RecipeFindingService::PASS_BAUART . '.',
                'VALIDATION_ERROR'
            );
        }

        $userId = $context->user?->id !== null ? (int) $context->user->id : null;

        ['run_id' => $runId, 'limit' => $limit] = app(QualityRunService::class)->starteBefundeLauf(
            $team,
            (int) ($arguments['limit'] ?? RecipeFindingsBatchService::DEFAULT_LIMIT),
            (bool) ($arguments['nur_verkauf'] ?? false),
            $pass,
            $userId,
        );

        return ToolResult::success([
            'run_id' => $runId,
            'limit' => $limit,
            'pass' => $pass,
            'hinweis' => "Befunde-Lauf eingereiht (Lauf {$runId}), höchstens {$limit} fällige Rezepte. "
                . 'Stand über foodalchemist.runs.GET; die Befunde danach über foodalchemist.recipe_findings.SEARCH. '
                . 'Der Lauf wendet nichts an — jede Übernahme bleibt eine menschliche Entscheidung.',
        ]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'command',
            'read_only' => false,
            // Nicht idempotent: jeder Lauf nimmt die nächste fällige Tranche und kostet
            // erneut. Genau deshalb steht die Wiederholbarkeit hier als Warnung, nicht als
            // Zusicherung.
            'idempotent' => false,
            'risk_level' => 'caution',
            'requires_auth' => true,
            'requires_team' => true,
            'cost_class' => 'external_api_paid',
            'tags' => ['foodalchemist', 'recipe_findings', 'copilot', 'befund', 'ki', 'batch', 'lauf', 'rezept'],
            'related_tools' => [
                'foodalchemist.runs.GET',
                'foodalchemist.recipe_findings.SEARCH',
                'foodalchemist.quality_run.POST',
            ],
            'examples' => [
                'Copilot über die nächsten 25 fälligen Rezepte laufen lassen',
                'KI-Befunde für die Gerichte sammeln',
            ],
        ];
    }
}
