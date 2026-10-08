<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\FaRechte;
use Platform\FoodAlchemist\Support\FaBereiche;

/** Spec 77b · Bereiche, Einschränkungen und Kontingente des Teams lesen. */
class TeamBereicheGetTool extends WareneingangTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.team_bereiche.GET';
    }

    public function getDescription(): string
    {
        return 'Liest, welche FA-Bereiche das Team gebucht hat (Katalog: uebersicht, planung, controlling, stammdaten, rezepte, concepter, '
            .'foodbook, speisekarte, speiseplan, angebote, produktion, einkauf, lager, wissen, einstellungen), welche Bereiche für '
            .'einzelne Mitglieder abgeschaltet sind, die Kontingente des Haupt-Teams (Standorte, Benutzer, KI-Budget € je Monat) '
            .'und die aktuelle Nutzung. Optional user_id = nur die Einschränkungen dieses Mitglieds.';
    }

    public function getSchema(): array
    {
        return ['type' => 'object', 'properties' => ['user_id' => ['type' => 'integer']]];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        return $this->ausfuehren($context, function ($team) use ($arguments) {
            $rechte = app(FaRechte::class);
            $mitglieder = array_map(fn ($m) => ['user_id' => $m['user_id'], 'name' => $m['name'],
                'gesperrte_bereiche' => $rechte->userSperren($team, $m['user_id'])], $rechte->mitglieder($team));
            if (! empty($arguments['user_id'])) {
                $mitglieder = array_values(array_filter($mitglieder, fn ($m) => $m['user_id'] === (int) $arguments['user_id']));
            }

            return [
                'katalog' => FaBereiche::KATALOG,
                'team_bereiche' => $rechte->teamBereiche($team),
                'mitglieder' => $mitglieder,
                'kontingente' => $rechte->kontingente($team),
                'nutzung' => $rechte->kontingentNutzung($team),
            ];
        });
    }

    public function getMetadata(): array
    {
        return $this->metadaten(false, ['rechte', 'bereiche', 'kontingente'], ['Welche Bereiche hat unser Team gebucht?'], ['foodalchemist.team_bereiche.PUT']);
    }
}
