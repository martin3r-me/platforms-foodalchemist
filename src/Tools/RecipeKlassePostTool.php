<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\SpeisenKlassenService;

/**
 * M8-01: SCHREIB-Tool-Muster — ausschließlich via GL-07-Proposal-Flow
 * (nie Direkt-Write): classify liefert den Vorschlag; geschrieben wird NUR
 * mit accept=true über den Accept-Pfad (Lineage ki + Audit-Stempel,
 * Override-First-Guard inklusive). Vorbild für alle weiteren POST/PUT-Tools.
 */
class RecipeKlassePostTool extends FoodAlchemistTool implements ToolContract
{
    public function getName(): string
    {
        return 'foodalchemist.recipe_klasse.POST';
    }

    public function getDescription(): string
    {
        return 'Klassifiziert ein VERKAUFSREZEPT in eine Speisen-Klasse (GL-07-Proposal-Flow): '
            . 'ohne accept nur Vorschlag (klasse + confidence + Begründung); mit accept=true wird der '
            . 'Vorschlag übernommen (Lineage ki). Manuell gepflegte Klassen blockieren den Accept.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'recipe_id' => ['type' => 'integer'],
                'accept' => ['type' => 'boolean', 'default' => false, 'description' => 'true = Vorschlag direkt übernehmen (GL-07-Accept)'],
            ],
            'required' => ['recipe_id'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        $svc = app(SpeisenKlassenService::class);
        try {
            $vorschlag = $svc->classify($team, (int) $arguments['recipe_id']);
        } catch (\Throwable $e) {
            return ToolResult::error($e->getMessage(), 'PROPOSE_FAILED');
        }

        $angenommen = false;
        if (($arguments['accept'] ?? false) === true && $vorschlag['klasse_id'] !== null) {
            try {
                $svc->acceptKlasse($team, (int) $arguments['recipe_id'], $vorschlag['klasse_id'],
                    $vorschlag['confidence'], $vorschlag['begruendung'], $vorschlag['call_log_id']);
                $angenommen = true;
            } catch (\RuntimeException $e) {
                return ToolResult::error($e->getMessage(), 'OVERRIDE_FIRST');
            }
        }

        return ToolResult::success($vorschlag + ['accepted' => $angenommen]);
    }
}
