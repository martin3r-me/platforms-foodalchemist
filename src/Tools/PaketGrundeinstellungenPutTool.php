<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Services\PaketGrundeinstellungenService;

/** Spec 82 §13 I · Paket „Grundeinstellungen" für einen Mandanten buchen (Kopie ins Oberteam; idempotent; nur Plattform-Admin). */
class PaketGrundeinstellungenPutTool extends WareneingangTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.paket_grundeinstellungen.PUT';
    }

    public function getDescription(): string
    {
        return 'Bucht das Paket „Grundeinstellungen“ für einen Mandanten (Kunden-Hauptteam): kopiert die Vorlagen des Master-Teams ins Oberteam, Unter-Teams erben. '
            .'Idempotent: ein zweites Buchen kopiert nichts doppelt und überschreibt keine gleichnamige Team-Zeile. Vorher paket_grundeinstellungen.GET mit team_id für die Vorschau. '
            .'Wird protokolliert. Nur Plattform-Admin, sonst FORBIDDEN.';
    }

    public function getSchema(): array
    {
        return ['type' => 'object', 'properties' => ['team_id' => ['type' => 'integer']], 'required' => ['team_id']];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        return $this->ausfuehren($context, function (Team $team) use ($arguments, $context) {
            $mandant = Team::findOrFail((int) ($arguments['team_id'] ?? 0));

            return ['gebucht' => app(PaketGrundeinstellungenService::class)->buchen($mandant, $context->user)];
        });
    }

    public function getMetadata(): array
    {
        return array_merge($this->metadaten(true, ['paket_grundeinstellungen'], ['Soulkitchen bucht das Paket Grundeinstellungen.'], ['foodalchemist.paket_grundeinstellungen.GET']), [
            'tags' => ['foodalchemist', 'paket', 'grundeinstellungen', 'plattform', 'write'],
        ]);
    }
}
