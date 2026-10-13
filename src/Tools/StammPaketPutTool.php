<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Services\StammPaketService;

/** Spec 82 §13 B1 · Stamm-Paket eines Mandanten buchen oder abbestellen (nur Plattform-Admin). */
class StammPaketPutTool extends WareneingangTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.stamm_paket.PUT';
    }

    public function getDescription(): string
    {
        return 'Bucht (gebucht=true) oder bestellt ab (gebucht=false) das Stamm-Paket eines Mandanten (Kunden-Hauptteam; Unter-Teams erben, das Master-Team hat es immer). '
            .'Beim Abbestellen mit betroffenen Abos ist bestaetigt=true nötig (vorher stamm_paket.GET mit team_id für die Vorschau). Jede Aktion wird protokolliert. '
            .'Nur Plattform-Admin, sonst FORBIDDEN.';
    }

    public function getSchema(): array
    {
        return ['type' => 'object', 'properties' => [
            'team_id' => ['type' => 'integer'], 'gebucht' => ['type' => 'boolean'], 'bestaetigt' => ['type' => 'boolean'],
        ], 'required' => ['team_id', 'gebucht']];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        return $this->ausfuehren($context, function (Team $team) use ($arguments, $context) {
            $svc = app(StammPaketService::class);
            $mandant = Team::findOrFail((int) ($arguments['team_id'] ?? 0));

            return ['status' => (bool) ($arguments['gebucht'] ?? false)
                ? $svc->buchen($mandant, $context->user)
                : $svc->abbestellen($mandant, $context->user, (bool) ($arguments['bestaetigt'] ?? false))];
        });
    }

    public function getMetadata(): array
    {
        return array_merge($this->metadaten(true, ['stamm_paket'], ['Soulkitchen bucht das Stamm-Paket.'], ['foodalchemist.stamm_paket.GET']), [
            'tags' => ['foodalchemist', 'stamm_paket', 'plattform', 'write'],
        ]);
    }
}
