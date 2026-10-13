<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Services\PlanNutzungService;

/** Spec 82 §13 D/C1 · Plan und Nutzung eines Mandanten: Positionen, Mengen, Monatsvorschau, gebuchte Tageswerte. Nur Gebühren und Mengen, nie Inhalte. */
class PlanNutzungGetTool extends WareneingangTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.plan_nutzung.GET';
    }

    public function getDescription(): string
    {
        return 'Was ein Mandant im Monat zahlt: Basis, weitere User, Unter-Teams, Stamm-Paket, Abo mit Menge, Monatspreis (netto), Vorschau und bisher gebuchten Tageswerten. '
            .'Ohne team_id: der Mandant des aktuellen Teams. Nur Gebühren und Mengen, keine Inhalte. '
            .'Erlaubt für Plattform-Admin, Admin im Master-Team und Inhaber/Admin des Mandanten, sonst FORBIDDEN.';
    }

    public function getSchema(): array
    {
        return ['type' => 'object', 'properties' => ['team_id' => ['type' => 'integer'], 'monat' => ['type' => 'string', 'description' => 'Y-m, Standard laufender Monat']]];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        return $this->ausfuehren($context, function (Team $team) use ($arguments, $context) {
            $ziel = ! empty($arguments['team_id']) ? Team::findOrFail((int) $arguments['team_id']) : $team;

            return app(PlanNutzungService::class)->monat($ziel, $context->user, $arguments['monat'] ?? null);
        });
    }

    public function getMetadata(): array
    {
        return array_merge($this->metadaten(false, ['gebuehren'], ['Was zahlt Soulkitchen diesen Monat?'], ['foodalchemist.plan_preise.GET']), [
            'tags' => ['foodalchemist', 'gebuehren', 'plattform'],
        ]);
    }
}
