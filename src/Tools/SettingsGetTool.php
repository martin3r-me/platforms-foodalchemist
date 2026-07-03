<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\TeamSettingsService;

/**
 * Phase C: Team-Einstellungen (READ-ONLY via MCP — Settings ändern nur
 * Menschen in der UI). Liefert die fürs LLM relevanten Rahmenparameter.
 */
class SettingsGetTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.settings.GET';
    }

    public function getDescription(): string
    {
        return 'Liefert die Team-Einstellungen (read-only): KI aktiv, Küchen-Typ, Lead-LA-Strategie '
            . '(gesamt + pro Warengruppe), Garverlust-Default. Rahmen für alle Kalkulations- und Rezept-Aufgaben.';
    }

    public function getSchema(): array
    {
        return ['type' => 'object', 'properties' => new \stdClass()];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        $svc = app(TeamSettingsService::class);

        return ToolResult::success([
            'ki_aktiv' => $svc->kiAktiv($team),
            'kuechen_typ' => $svc->kuechenTyp($team),
            'lead_la_strategie' => $svc->leadLaStrategie($team)->value ?? (string) $svc->leadLaStrategie($team),
            'lead_la_strategie_pro_wg' => collect($svc->leadLaStrategiePerWg($team))
                ->map(fn ($s) => $s instanceof \BackedEnum ? $s->value : (string) $s)->all(),
            'garverlust_default_pct' => $svc->garverlustDefault($team),
            'hinweis' => 'Read-only: Einstellungen ändern nur Menschen in der UI.',
        ]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'query',
            'tags' => ['foodalchemist', 'settings', 'einstellungen', 'team', 'strategie'],
            'read_only' => true, 'idempotent' => true, 'risk_level' => 'safe',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'related_tools' => ['foodalchemist.kalkulation.GET'],
            'examples' => ['Welche Lead-LA-Strategie fährt das Team?'],
        ];
    }
}
