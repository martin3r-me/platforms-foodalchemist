<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Services\PaketGrundeinstellungenService;

/** Spec 82 §13 I · Paket „Grundeinstellungen": Übersicht aller Mandanten oder Status + Vorschau eines Mandanten (nur Plattform-Admin). */
class PaketGrundeinstellungenGetTool extends WareneingangTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.paket_grundeinstellungen.GET';
    }

    public function getDescription(): string
    {
        return 'Ohne team_id: alle Mandanten (Kunden-Hauptteams) mit Paketstatus. Mit team_id: Status plus Vorschau, was Buchen kopieren würde '
            .'(je Tabelle: Quelle, schon zugeordnet, neu). Das Paket enthält reine Team-Arbeit (Konzept-Kategorien, Zielgruppen, Schreibstile, Rollen, Posten, Speiseplan-Chips, Präsentations-Designs, Trend-Startbestand, dazu der Posten "Pass" als Team-Vorgabe fuer Gerichte), '
            .'keine Basis-Kataloge. Nur Plattform-Admin, sonst FORBIDDEN.';
    }

    public function getSchema(): array
    {
        return ['type' => 'object', 'properties' => ['team_id' => ['type' => 'integer']]];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        return $this->ausfuehren($context, function (Team $team) use ($arguments, $context) {
            $svc = app(PaketGrundeinstellungenService::class);
            $svc->nurPlattformAdminOeffentlich($context->user, 'Paket „Grundeinstellungen“ einsehen');
            if (empty($arguments['team_id'])) {
                return ['mandanten' => $svc->uebersicht()];
            }
            $mandant = Team::findOrFail((int) $arguments['team_id']);

            return ['status' => $svc->status($mandant), 'vorschau' => $svc->vorschau($mandant)];
        });
    }

    public function getMetadata(): array
    {
        return array_merge($this->metadaten(false, ['paket_grundeinstellungen'], ['Hat Soulkitchen das Paket Grundeinstellungen?'], ['foodalchemist.paket_grundeinstellungen.PUT']), [
            'tags' => ['foodalchemist', 'paket', 'grundeinstellungen', 'plattform'],
        ]);
    }
}
