<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Models\FoodAlchemistSpeiseplan;
use Platform\FoodAlchemist\Services\SpeiseplanService;

/**
 * MCP-Steuerbarkeit · D11 (cross-module aus D9): eine Speiseplan-Woche in die Produktion geben —
 * erzeugt Produktionsaufträge für die Gerichte der Woche. Confirm=true Pflicht.
 */
class SpeiseplanAnproduktionTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.speiseplan.ANPRODUKTION';
    }

    public function getDescription(): string
    {
        return 'Gibt eine Speiseplan-Woche (mahlzeit + Montag YYYY-MM-DD) in die Produktion — erzeugt Produktionsaufträge. Erfordert confirm=true.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'plan_id' => ['type' => 'integer', 'description' => 'Speiseplan-Id.'],
                'mahlzeit' => ['type' => 'string', 'description' => 'Mahlzeit (z.B. mittag, abend).'],
                'montag' => ['type' => 'string', 'description' => 'Wochen-Montag YYYY-MM-DD.'],
                'confirm' => ['type' => 'boolean', 'description' => 'Muss true sein (erzeugt Produktionsaufträge).'],
            ],
            'required' => ['plan_id', 'mahlzeit', 'montag', 'confirm'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        if (($arguments['confirm'] ?? false) !== true) {
            return ToolResult::error('Anproduktion erfordert confirm=true (erzeugt Produktionsaufträge).', 'CONFIRM_REQUIRED');
        }
        $planId = (int) ($arguments['plan_id'] ?? 0);
        if (($guard = $this->guardOwned($team, FoodAlchemistSpeiseplan::class, $planId, 'Speiseplan')) !== null) {
            return $guard;
        }
        $svc = app(SpeiseplanService::class);
        $plan = $svc->detail($team, $planId);
        if ($plan === null) {
            return ToolResult::error('Speiseplan nicht sichtbar/vorhanden.', 'NOT_FOUND');
        }

        try {
            $montag = \Carbon\Carbon::parse((string) ($arguments['montag'] ?? ''))->startOfDay();
            $res = $svc->wocheAnProduktion($team, $plan, trim((string) ($arguments['mahlzeit'] ?? 'mittag')), $montag, $context->user->id ?? null);
        } catch (\RuntimeException | \Carbon\Exceptions\InvalidFormatException $e) {
            return ToolResult::error($e->getMessage(), 'VALIDATION_ERROR');
        }

        return ToolResult::success(['plan_id' => $planId, 'result' => $res]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['foodalchemist', 'speiseplan', 'produktion', 'write'],
            'read_only' => false, 'idempotent' => false, 'risk_level' => 'destructive',
            'confirmation_required' => true,
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'side_effects' => ['creates'],
            'related_tools' => ['foodalchemist.production_plan.APPLY'],
            'examples' => ['Gib die Mittags-Woche ab 2027-01-04 von Speiseplan 3 in die Produktion (confirm=true).'],
        ];
    }
}
