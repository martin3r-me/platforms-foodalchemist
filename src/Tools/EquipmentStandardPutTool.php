<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Services\EquipmentStandardService;

/** Spec 82 §6 · Standardausstattung im Equipment-Vokabular setzen (nur Plattform-Admin), mit Trockenlauf als Standard. */
class EquipmentStandardPutTool extends WareneingangTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.equipment_standard.PUT';
    }

    public function getDescription(): string
    {
        return 'Setzt das Flag Standardausstattung am Equipment-Vokabular auf die bestätigte Liste von 15 Geräten (Topf, Kasserolle, Pfanne, Chinois / Passiersieb, Backblech, '
            .'Bräter, Spritzbeutel, Kuchenform, Stabmixer, Pürierstab, Mixer, Küchenmaschine / Planetenrührwerk, Induktionsplatte, Mörser, Mandoline / Hobel), alle anderen auf aus. '
            .'Standardausstattung gilt in jedem Teamprofil als vorhanden, solange ein Team sie nicht ausdrücklich als fehlend markiert. '
            .'dry_run ist STANDARD (true): zeigt nur neu gesetzt, schon gesetzt, zurückgesetzt, nicht gefunden. Mit dry_run=false wird wirklich geschrieben und protokolliert (idempotent). '
            .'Nur Plattform-Admin, sonst FORBIDDEN. Das Vokabular gilt plattformweit.';
    }

    public function getSchema(): array
    {
        return ['type' => 'object', 'properties' => ['dry_run' => ['type' => 'boolean', 'description' => 'Standard true (nur anzeigen).']]];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        return $this->ausfuehren($context, function (Team $team) use ($arguments, $context) {
            $svc = app(EquipmentStandardService::class);
            $dry = (bool) ($arguments['dry_run'] ?? true);
            if ($dry) {
                $svc->nurPlattformAdminOeffentlich($context->user, 'Standardausstattung einsehen');

                return $svc->setzen(true);
            }

            return $svc->schreiben($context->user) + ['hinweis' => 'Geschrieben und protokolliert.'];
        });
    }

    public function getMetadata(): array
    {
        return array_merge($this->metadaten(true, ['equipment_standard'], ['Welche Geräte würden zur Standardausstattung?'], ['foodalchemist.team_profile.GET']), [
            'tags' => ['foodalchemist', 'equipment_standard', 'plattform', 'write'],
        ]);
    }
}
