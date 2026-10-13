<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Services\TeamProfilService;

/** Spec 82 §6 · Teamprofil lesen: Küchen-Fakten (Pax, Convenience-Grad), Flächen, Equipment dreiwertig. */
class TeamProfilGetTool extends WareneingangTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.team_profile.GET';
    }

    public function getDescription(): string
    {
        return 'Liest das Teamprofil (was die Küche kann): max. Pax parallel, Convenience-Grad, Flächen (Name, max. Pax, Saison) und die '
            .'Equipment-Ausstattung. Equipment ist dreiwertig: vorhanden | nicht_vorhanden | unbekannt (keine Angabe). `wirksam` zeigt, '
            .'was der Machbarkeits-Check daraus macht: Standardausstattung zählt ohne Gegenangabe als vorhanden. Ohne team_id das eigene Team; '
            .'mit team_id ein Unter-Team (nur lesen über die Oberteam-Brille, sonst FORBIDDEN).';
    }

    public function getSchema(): array
    {
        return ['type' => 'object', 'properties' => ['team_id' => ['type' => 'integer', 'description' => 'Unter-Team, dessen Profil gelesen wird (optional).']]];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        return $this->ausfuehren($context, function (Team $team) use ($arguments) {
            $ziel = ! empty($arguments['team_id']) ? Team::findOrFail((int) $arguments['team_id']) : $team;

            return app(TeamProfilService::class)->lies($team, $ziel);
        });
    }

    public function getMetadata(): array
    {
        return array_merge($this->metadaten(false, ['teamprofil'], ['Welche Geräte hat die Küche von LAGO?'], ['foodalchemist.team_profile.PUT', 'foodalchemist.machbarkeit.CHECK']), [
            'tags' => ['foodalchemist', 'teamprofil', 'machbarkeit'],
        ]);
    }
}
