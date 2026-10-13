<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Services\StammPaketService;

/** Spec 82 §13 B1 · Stamm-Paket: Übersicht aller Mandanten oder Status + Abbestell-Vorschau eines Mandanten (nur Plattform-Admin). */
class StammPaketGetTool extends WareneingangTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.stamm_paket.GET';
    }

    public function getDescription(): string
    {
        return 'Ohne team_id: alle Mandanten (Kunden-Hauptteams) mit Paketstatus (gebucht, seit, Zahl der Abos am Paket). '
            .'Mit team_id: Status des Mandanten plus Wirkungs-Vorschau des Abbestellens (welche Abos ungültig würden, ob eine Bestätigung nötig ist). '
            .'Nur Plattform-Admin, sonst FORBIDDEN.';
    }

    public function getSchema(): array
    {
        return ['type' => 'object', 'properties' => ['team_id' => ['type' => 'integer']]];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        return $this->ausfuehren($context, function (Team $team) use ($arguments, $context) {
            $svc = app(StammPaketService::class);
            $svc->nurPlattformAdminOeffentlich($context->user, 'Stamm-Paket einsehen');
            if (empty($arguments['team_id'])) {
                return ['mandanten' => $svc->uebersicht()];
            }
            $mandant = Team::findOrFail((int) $arguments['team_id']);

            return ['status' => $svc->status($mandant), 'abbestellen_vorschau' => $svc->vorschauAbbestellen($mandant)];
        });
    }

    public function getMetadata(): array
    {
        return array_merge($this->metadaten(false, ['stamm_paket'], ['Welche Mandanten haben das Stamm-Paket?'], ['foodalchemist.stamm_paket.PUT']), [
            'tags' => ['foodalchemist', 'stamm_paket', 'plattform'],
        ]);
    }
}
