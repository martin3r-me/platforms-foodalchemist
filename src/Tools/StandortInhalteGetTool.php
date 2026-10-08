<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Services\InhaltsFreigabeService;
use Platform\FoodAlchemist\Services\StandortService;

/** Spec 77d · Was die Standorte vom Oberteam sehen: Haken „übernimmt alles", Freigaben, Sammlungen, Hülle. */
class StandortInhalteGetTool extends WareneingangTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.standort_inhalte.GET';
    }

    public function getDescription(): string
    {
        return 'Liest je Standort (Unter-Team), ob er alles vom Oberteam übernimmt (erbt_alles) oder nur Freigegebenes sieht, '
            .'die Freigaben (Sammlungen, Foodbooks, Speisepläne, Speisekarten) und die Sammlungen des Teams. Mit '
            .'standort_team_id zusätzlich die berechnete Hülle (welche Rezepte, Konzepte, Formate, Pakete, Ausgaben der '
            .'Standort dadurch sieht). Grundprodukte und Lieferantenartikel sind nie eingeschränkt.';
    }

    public function getSchema(): array
    {
        return ['type' => 'object', 'properties' => ['standort_team_id' => ['type' => 'integer']]];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        return $this->ausfuehren($context, function ($team) use ($arguments) {
            $svc = app(InhaltsFreigabeService::class);
            $standorte = array_map(fn ($s) => $s + ['erbt_alles' => $svc->erbtAlles(Team::find($s['id']))], app(StandortService::class)->unterTeams($team));
            $out = ['standorte' => $standorte, 'freigaben' => $svc->freigaben($team), 'sammlungen' => $svc->sammlungen($team)];
            if (! empty($arguments['standort_team_id'])) {
                $id = (int) $arguments['standort_team_id'];
                if (! in_array($id, array_column($standorte, 'id'), true)) {
                    throw new \RuntimeException('Dieses Team ist kein Standort dieses Teams.');
                }
                $out['huelle'] = $svc->huelle($id);
            }

            return $out;
        });
    }

    public function getMetadata(): array
    {
        return $this->metadaten(false, ['standorte', 'freigaben', 'sammlungen'], ['Was sieht der Standort Köln von unseren Rezepten?'],
            ['foodalchemist.standort_inhalte.PUT', 'foodalchemist.sammlungen.PUT']);
    }
}
