<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Enums\FaRolle;
use Platform\FoodAlchemist\Services\FaRechte;
use Platform\FoodAlchemist\Services\GpHomogenitaetService;

/** Spec 82 §2 · Bericht „GPs mit uneinheitlichen Artikeln“ lesen (nur lesend, auf das beschränkt, was das Team sieht). */
class GpsHomogenitaetGetTool extends WareneingangTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.gps_homogenitaet.GET';
    }

    public function getDescription(): string
    {
        return 'Bericht „GPs mit uneinheitlichen Artikeln“: GPs, unter denen Artikel mit abweichenden Allergenen oder Zusatzstoffen hängen. Es zählt nur „enthalten“, Spuren zählen nicht, '
            .'fehlende Daten sind unbekannt. Zusammenfassung (verknüpfte Artikel, davon unbekannt, GPs mit mehreren Artikeln, uneinheitliche GPs) plus Liste (limit, Standard 30, max. 200). '
            .'Nur lesend, ändert keine Verknüpfung. Beschränkt auf Artikel und GPs, die das aufrufende Team sieht. Rolle Kuratieren, sonst FORBIDDEN.';
    }

    public function getSchema(): array
    {
        return ['type' => 'object', 'properties' => [
            'limit' => ['type' => 'integer', 'description' => 'Zeilen der Liste (Standard 30, max. 200; 0 = nur die Zusammenfassung)'],
        ]];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        return $this->ausfuehren($context, function (Team $team) use ($arguments, $context) {
            app(FaRechte::class)->pruefe($context->user, $team, FaRolle::Kuratieren, 'Homogenitäts-Bericht lesen');
            $limit = max(0, min(200, (int) ($arguments['limit'] ?? 30)));
            $bericht = app(GpHomogenitaetService::class)->bericht(300, $team);

            return ['zusammenfassung' => $bericht['zusammenfassung'], 'gps' => array_slice($bericht['gps'], 0, $limit), 'gezeigt' => min($limit, count($bericht['gps'])), 'gesamt' => count($bericht['gps'])];
        });
    }

    public function getMetadata(): array
    {
        return array_merge($this->metadaten(false, ['gps_homogenitaet'], ['Welche GPs haben Artikel mit unterschiedlichen Allergenen?'], ['foodalchemist.gps.MATCH']), [
            'tags' => ['foodalchemist', 'gps', 'allergene', 'homogenitaet'],
        ]);
    }
}
