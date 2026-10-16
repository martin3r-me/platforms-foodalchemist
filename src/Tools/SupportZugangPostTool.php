<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistSupportFreigabe;
use Platform\FoodAlchemist\Services\SupportFreigabeService;

/** Spec 84 §4.4 (E3) · Support-Zugang befristet erteilen (nur Owner/Admin des Hauptteams). */
class SupportZugangPostTool extends WareneingangTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.support_zugang.POST';
    }

    public function getDescription(): string
    {
        return 'Erteilt dem Support (einem Plattform-Admin per fuer_user_id oder allen, wenn leer) befristet Zugang zu den Inhalten des eigenen '
            .'Kunden-Hauptteams inkl. Unter-Teams. Dauer in Stunden (Standard 48, höchstens 168). Jeder Zugriff wird protokolliert; '
            .'widerrufen jederzeit über foodalchemist.support_zugang.DELETE. Nur Owner/Admin des Hauptteams.';
    }

    public function getSchema(): array
    {
        return ['type' => 'object', 'properties' => [
            'stunden' => ['type' => 'integer', 'description' => 'Dauer, 1–168 (Standard 48).'],
            'fuer_user_id' => ['type' => 'integer', 'description' => 'Nur dieser Plattform-Admin; leer = alle.'],
            'grund' => ['type' => 'string', 'description' => 'Anlass, z. B. Ticket-Nummer.'],
        ]];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        return $this->ausfuehren($context, fn (Team $team) => ['freigabe' => SupportZugangGetTool::zeile(app(SupportFreigabeService::class)->erteilen(
            $team, $context->user,
            isset($arguments['stunden']) ? (int) $arguments['stunden'] : null,
            isset($arguments['fuer_user_id']) ? (int) $arguments['fuer_user_id'] : null,
            isset($arguments['grund']) ? (string) $arguments['grund'] : null,
        ))]);
    }

    public function getMetadata(): array
    {
        return array_merge($this->metadaten(true, ['support', 'zugang'], ['Gib dem Support für 48 Stunden Zugang wegen Ticket 4711.'],
            ['foodalchemist.support_zugang.GET', 'foodalchemist.support_zugang.DELETE']), ['tags' => ['foodalchemist', 'support', 'zugang', 'datenschutz']]);
    }
}
