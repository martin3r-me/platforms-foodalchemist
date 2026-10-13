<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Services\PlanPreisService;

/** Spec 82 §13 D/B5 · Neuen Gebühren-Preis anlegen (append-only, gültig ab; nur Plattform-Admin, protokolliert). */
class PlanPreisePostTool extends WareneingangTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.plan_preise.POST';
    }

    public function getDescription(): string
    {
        return 'Legt einen neuen Monatspreis (netto) für einen Baustein an. Append-only: die Historie bleibt, die Vorgängerzeile endet am Vortag. '
            .'gueltig_ab = heute oder später und nach dem bisherigen Beginn (Standard heute); Tage, die schon berechnet wurden, ändern sich nie. '
            .'Vorher plan_preise.GET mit baustein + neuer_betrag für die Auswirkung. Jede Änderung wird protokolliert. Nur Plattform-Admin, sonst FORBIDDEN.';
    }

    public function getSchema(): array
    {
        return ['type' => 'object', 'properties' => [
            'baustein' => ['type' => 'string', 'enum' => ['basis', 'user', 'unterteam', 'stamm_paket', 'master_abo']],
            'betrag_monat' => ['type' => 'number'], 'gueltig_ab' => ['type' => 'string', 'description' => 'Y-m-d, Standard heute'],
            'notiz' => ['type' => 'string'],
        ], 'required' => ['baustein', 'betrag_monat']];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        return $this->ausfuehren($context, fn (Team $team) => ['preis' => app(PlanPreisService::class)->setze(
            (string) ($arguments['baustein'] ?? ''), $arguments['betrag_monat'] ?? '', $arguments['gueltig_ab'] ?? null, $arguments['notiz'] ?? null, $context->user,
        )]);
    }

    public function getMetadata(): array
    {
        return array_merge($this->metadaten(true, ['gebuehren'], ['Der User-Preis steigt ab 1.1. auf 21 €.'], ['foodalchemist.plan_preise.GET']), [
            'tags' => ['foodalchemist', 'gebuehren', 'plattform', 'write'],
            'idempotent' => false,
        ]);
    }
}
