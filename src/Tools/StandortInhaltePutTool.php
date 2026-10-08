<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\InhaltsFreigabeService;

/** Spec 77d · Haken „übernimmt alles" setzen, Ausgaben/Sammlungen freigeben oder entziehen. Nur FA-Admin des Oberteams. */
class StandortInhaltePutTool extends WareneingangTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.standort_inhalte.PUT';
    }

    public function getDescription(): string
    {
        return 'Eine Aktion je Aufruf (nur Inhaber/Admin des Oberteams): (1) aktion=erbt_alles + standort_team_id + an (bool) — '
            .'Standort übernimmt alles vom Oberteam (Standard) oder sieht nur Freigegebenes. (2) aktion=freigeben + standort_team_id + '
            .'ausgabe_typ (sammlung | foodbook | speiseplan | speisekarte) + ausgabe_id — nur eigene Ausgaben. (3) aktion=entziehen + '
            .'freigabe_id. Die Hülle (Konzepte, Gerichte, Basisrezepte) rechnet das System selbst.';
    }

    public function getSchema(): array
    {
        return ['type' => 'object', 'required' => ['aktion'], 'properties' => [
            'aktion' => ['type' => 'string', 'enum' => ['erbt_alles', 'freigeben', 'entziehen']],
            'standort_team_id' => ['type' => 'integer'], 'an' => ['type' => 'boolean'],
            'ausgabe_typ' => ['type' => 'string', 'enum' => ['sammlung', 'foodbook', 'speiseplan', 'speisekarte']],
            'ausgabe_id' => ['type' => 'integer'], 'freigabe_id' => ['type' => 'integer'],
        ]];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        return $this->ausfuehren($context, function ($team) use ($arguments, $context) {
            $svc = app(InhaltsFreigabeService::class);
            match ($arguments['aktion'] ?? '') {
                'erbt_alles' => $svc->setzeErbtAlles($team, (int) ($arguments['standort_team_id'] ?? 0), (bool) ($arguments['an'] ?? true), $context->user),
                'freigeben' => $svc->freigeben($team, (string) ($arguments['ausgabe_typ'] ?? ''), (int) ($arguments['ausgabe_id'] ?? 0), (int) ($arguments['standort_team_id'] ?? 0), $context->user),
                'entziehen' => $svc->freigabeEntziehen($team, (int) ($arguments['freigabe_id'] ?? 0), $context->user),
                default => throw new \RuntimeException('aktion: erbt_alles, freigeben oder entziehen.'),
            };

            return ['freigaben' => $svc->freigaben($team)];
        });
    }

    public function getMetadata(): array
    {
        return $this->metadaten(true, ['standorte', 'freigaben'], ['Gib dem Standort Köln die Sammlung Grundsortiment frei.'], ['foodalchemist.standort_inhalte.GET']);
    }
}
