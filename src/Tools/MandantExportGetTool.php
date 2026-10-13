<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Exceptions\FaRechtFehltException;
use Platform\FoodAlchemist\Enums\FaRolle;
use Platform\FoodAlchemist\Services\MandantExportService;

/**
 * Spec 82 §13 C3 · Datenexport eines Mandanten: Status und Manifest (nur Zählung, keine Inhalte). Die Datei selbst lädt nur
 * Inhaber/Admin des Mandanten über den Download-Link (`ladbar` + `download`); ein Plattform-Admin sieht nur Status und Zählung (§13 G).
 */
class MandantExportGetTool extends WareneingangTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.mandant_export.GET';
    }

    public function getDescription(): string
    {
        return 'Status des Datenexports eines Mandanten (Kunden-Hauptteam): zustand (laeuft|fertig|fehler), Größe, Prüfsumme, Manifest (Zeilen je Tabelle, '
            .'nie Inhalte), ob der Aufrufer die Datei laden darf (`ladbar`) und der Download-Pfad. Ohne team_id der eigene Mandant. '
            .'Plattform-Admin oder Inhaber/Admin des Mandanten; die Datei laden darf nur Inhaber/Admin des Mandanten (Master bei kuratierten). Nur lesend.';
    }

    public function getSchema(): array
    {
        return ['type' => 'object', 'properties' => ['team_id' => ['type' => 'integer', 'description' => 'Mandant (Standard: eigenes Team).']]];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        return $this->ausfuehren($context, function (Team $team) use ($arguments, $context) {
            $svc = app(MandantExportService::class);
            $mandant = ! empty($arguments['team_id']) ? Team::findOrFail((int) $arguments['team_id']) : $team;
            if (! $svc->darfAusloesen($context->user, $mandant) && ! $svc->darfLaden($context->user, $mandant)) {
                throw new FaRechtFehltException(FaRolle::Admin, null, 'Datenexport einsehen (Plattform-Admin oder Inhaber des Mandanten)');
            }
            $e = $svc->neuester($mandant);

            return ['team_id' => (int) $mandant->id, 'export' => $e === null ? null : $svc->darstellung($e, $context->user, $mandant)
                + ($svc->darfLaden($context->user, $mandant) ? ['download' => route('foodalchemist.mandant-export.download', $e->id, false)] : [])];
        });
    }

    public function getMetadata(): array
    {
        return array_merge($this->metadaten(false, ['mandanten', 'export'], ['Ist der Export für Soulkitchen fertig?'], ['foodalchemist.mandant_export.POST', 'foodalchemist.mandanten_beenden.GET']), [
            'tags' => ['foodalchemist', 'mandanten', 'export'],
        ]);
    }
}
