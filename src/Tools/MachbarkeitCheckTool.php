<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Services\MachbarkeitService;
use Platform\FoodAlchemist\Services\TeamProfilService;
use Platform\FoodAlchemist\Support\MachbarkeitKontext;

/** Spec 82 §6 · Machbarkeits-Check ohne KI: braucht ein Rezept Equipment, das dem Team ausdrücklich fehlt? */
class MachbarkeitCheckTool extends WareneingangTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.machbarkeit.CHECK';
    }

    public function getDescription(): string
    {
        return 'Prüft Rezepte (inkl. Unter-Rezepte, bis 3 Ebenen) gegen das Teamprofil eines Teams, ohne KI. Je Rezept: status warnung '
            .'(Equipment ausdrücklich nicht_vorhanden) | hinweis (Zustand unbekannt: „Profil unvollständig") | ok | ohne_angabe (am Rezept steht kein Equipment, '
            .'das ist NICHT „machbar") | nicht_sichtbar. Warnung nur bei ausdrücklich nicht_vorhanden; Standardausstattung zählt als vorhanden. '
            .'Ohne team_id das eigene Team; mit team_id ein Unter-Team (Oberteam-Brille). gaeste/termin werden mitgeführt, noch nicht ausgewertet.';
    }

    public function getSchema(): array
    {
        return ['type' => 'object', 'properties' => [
            'recipe_ids' => ['type' => 'array', 'items' => ['type' => 'integer'], 'description' => 'Rezepte (max. 200).'],
            'team_id' => ['type' => 'integer', 'description' => 'Ziel-Team (Unter-Team), Standard: eigenes Team.'],
            'gaeste' => ['type' => 'integer'], 'termin' => ['type' => 'string', 'description' => 'YYYY-MM-DD'],
        ], 'required' => ['recipe_ids']];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        return $this->ausfuehren($context, function (Team $team) use ($arguments, $context) {
            $ids = array_slice(array_map('intval', (array) ($arguments['recipe_ids'] ?? [])), 0, 200);
            if ($ids === []) {
                throw new \RuntimeException('recipe_ids angeben.');
            }
            $ziel = ! empty($arguments['team_id']) ? Team::findOrFail((int) $arguments['team_id']) : $team;
            if (! app(TeamProfilService::class)->darfLesen($team, $ziel)) {
                throw new \Platform\FoodAlchemist\Exceptions\FaRechtFehltException(\Platform\FoodAlchemist\Enums\FaRolle::Lesen, null, 'Machbarkeit für ein fremdes Team prüfen');
            }
            $termin = ! empty($arguments['termin']) ? \Carbon\Carbon::parse((string) $arguments['termin']) : null;
            $kontext = new MachbarkeitKontext($ziel, $context->user, isset($arguments['gaeste']) ? (int) $arguments['gaeste'] : null, $termin);

            return ['team_id' => (int) $ziel->id, 'ergebnisse' => app(MachbarkeitService::class)->pruefe($kontext, $ids)];
        });
    }

    public function getMetadata(): array
    {
        return array_merge($this->metadaten(false, ['machbarkeit'], ['Geht dieses Rezept in der Küche von LAGO?'], ['foodalchemist.team_profile.GET']), [
            'tags' => ['foodalchemist', 'teamprofil', 'machbarkeit'],
        ]);
    }
}
