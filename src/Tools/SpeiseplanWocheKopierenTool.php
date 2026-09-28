<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Models\FoodAlchemistSpeiseplan;
use Platform\FoodAlchemist\Services\SpeiseplanService;

/** Spec 57 · Paket 5: eine Speiseplan-Woche auf eine andere Woche kopieren (Massen-Insert → confirm). */
class SpeiseplanWocheKopierenTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.speiseplan.WOCHE_KOPIEREN';
    }

    public function getDescription(): string
    {
        return 'Kopiert alle Einträge einer Woche (von_montag, YYYY-MM-DD) auf eine andere Woche (nach_montag). '
            . 'Standard: belegte Zielzellen werden ersetzt; zusammenfuehren=true ergänzt stattdessen. mit_pax (Standard true) '
            . 'nimmt die Mengen mit, mahlzeit begrenzt optional auf eine Mahlzeit. Erfordert confirm=true.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'plan_id' => ['type' => 'integer', 'description' => 'Speiseplan-Id.'],
                'von_montag' => ['type' => 'string', 'description' => 'Quellwoche (ein Tag der Woche genügt).'],
                'nach_montag' => ['type' => 'string', 'description' => 'Zielwoche (ein Tag der Woche genügt).'],
                'zusammenfuehren' => ['type' => 'boolean', 'description' => 'true = vorhandene Einträge behalten.'],
                'mit_pax' => ['type' => 'boolean', 'description' => 'Mengen mitnehmen (Standard true).'],
                'mahlzeit' => ['type' => 'string', 'enum' => ['fruehstueck', 'mittag', 'abend', 'snack']],
                'confirm' => ['type' => 'boolean', 'description' => 'Muss true sein (Massen-Insert).'],
            ],
            'required' => ['plan_id', 'von_montag', 'nach_montag', 'confirm'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        if (($arguments['confirm'] ?? false) !== true) {
            return ToolResult::error('Woche kopieren erfordert confirm=true (Massen-Insert).', 'CONFIRM_REQUIRED');
        }
        $planId = (int) ($arguments['plan_id'] ?? 0);
        if (($guard = $this->guardOwned($team, FoodAlchemistSpeiseplan::class, $planId, 'Speiseplan')) !== null) {
            return $guard;
        }

        try {
            $res = app(SpeiseplanService::class)->kopiereWoche(
                $team, $planId, (string) $arguments['von_montag'], (string) $arguments['nach_montag'],
                ($arguments['zusammenfuehren'] ?? false) === true,
                ($arguments['mit_pax'] ?? true) !== false,
                $arguments['mahlzeit'] ?? null,
            );
        } catch (\RuntimeException | \Carbon\Exceptions\InvalidFormatException $e) {
            return ToolResult::error($e->getMessage(), 'VALIDATION_ERROR');
        }

        return ToolResult::success(['plan_id' => $planId] + $res);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['foodalchemist', 'speiseplan', 'kopieren', 'write'],
            'read_only' => false, 'idempotent' => true, 'risk_level' => 'destructive',
            'confirmation_required' => true,
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'side_effects' => ['creates', 'deletes'],
            'related_tools' => ['foodalchemist.speiseplan.AUSROLLEN', 'foodalchemist.speiseplan_eintraege.GET'],
            'examples' => ['Kopiere bei Speiseplan 3 die Woche ab 2026-10-05 in die Woche ab 2026-10-19 (confirm=true).'],
        ];
    }
}
