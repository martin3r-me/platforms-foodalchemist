<?php

namespace Platform\FoodAlchemist\Tools;

use Illuminate\Support\Carbon;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\SpeiseplanService;

/** Spec 57 · Paket 8: Plan gegen Ist einer Speiseplan-Woche (Verkaufsjournal), nur lesend. */
class SpeiseplanPlanIstTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.speiseplan_planist.GET';
    }

    public function getDescription(): string
    {
        return 'Plan/Ist einer Speiseplan-Woche (montag = ein Tag der Woche) je Gericht: geplante Essen und Plan-Umsatz '
            . 'gegen verkaufte Menge und Umsatz aus dem Verkaufsjournal des Teams (alle Verkaufsstellen), Abweichung in %. '
            . 'Concepts/Pakete sind nicht vergleichbar.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'plan_id' => ['type' => 'integer'],
                'montag' => ['type' => 'string', 'description' => 'Ein Tag der Woche, YYYY-MM-DD.'],
                'mahlzeit' => ['type' => 'string', 'enum' => ['fruehstueck', 'mittag', 'abend', 'snack'], 'default' => 'mittag'],
            ],
            'required' => ['plan_id', 'montag'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        $svc = app(SpeiseplanService::class);
        $plan = $svc->detail($team, (int) ($arguments['plan_id'] ?? 0));
        if ($plan === null) {
            return ToolResult::error('Speiseplan nicht sichtbar/vorhanden.', 'NOT_FOUND');
        }
        try {
            $montag = Carbon::parse((string) $arguments['montag']);
        } catch (\Carbon\Exceptions\InvalidFormatException $e) {
            return ToolResult::error('montag unlesbar: ' . $e->getMessage(), 'VALIDATION_ERROR');
        }

        return ToolResult::success(['plan_id' => (int) $plan->id] + $svc->planIst($team, $plan, (string) ($arguments['mahlzeit'] ?? 'mittag'), $montag));
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'read',
            'tags' => ['foodalchemist', 'speiseplan', 'planist', 'controlling', 'read'],
            'read_only' => true, 'idempotent' => true, 'risk_level' => 'safe',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'side_effects' => [],
            'related_tools' => ['foodalchemist.speiseplan_mengen.GET'],
            'examples' => ['Wie viel wurde in KW 41 von Speiseplan 3 verkauft, verglichen mit dem Plan?'],
        ];
    }
}
