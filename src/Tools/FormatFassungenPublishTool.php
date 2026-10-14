<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Services\FormatFassungService;

/** Spec 82 Abo Formate · Format veröffentlichen: friert den Arbeitsstand eines Master-Formats als Fassung ein (nur Master, Rolle „Freigeben“). */
class FormatFassungenPublishTool extends WareneingangTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.format_fassungen.PUBLISH';
    }

    public function getDescription(): string
    {
        return 'Veröffentlicht den Arbeitsstand eines Master-Formats als neue Fassung (schreibgeschützt, ohne Preise). '
            .'Jedes Konzept in den Slots braucht eine veröffentlichte Fassung, sonst bricht es mit einer Liste ab. Ein Kunden-Format (origin=kunde) ist nie veröffentlichbar (Kunden-IP). Gleicher Inhalt wie die letzte Fassung erzeugt keine neue. '
            .'Nur im Master-Team mit FA-Rolle „Freigeben“; notiz (Änderungsprotokoll) ist Pflicht. Liefert fassung_id, neu, fassung_nr und Hinweise '
            .'(z. B. Rezepte mit unveröffentlichten Änderungen).';
    }

    public function getSchema(): array
    {
        return ['type' => 'object', 'properties' => [
            'format_id' => ['type' => 'integer', 'description' => 'Arbeitszeile des Master-Formats.'],
            'notiz' => ['type' => 'string', 'description' => 'Änderungsprotokoll, z. B. „Hochzeit: Konzept getauscht“.'],
        ], 'required' => ['format_id', 'notiz']];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        return $this->ausfuehren($context, fn (Team $team) => app(FormatFassungService::class)
            ->veroeffentlichen($team, (int) ($arguments['format_id'] ?? 0), (string) ($arguments['notiz'] ?? ''), $context->user));
    }

    public function getMetadata(): array
    {
        return array_merge($this->metadaten(true, ['formate', 'fassung'], ['Veröffentliche das Format Hochzeit mit der Notiz „Konzept getauscht“.'], ['foodalchemist.format_fassungen.GET']), [
            'tags' => ['foodalchemist', 'formate', 'fassung', 'kurator', 'write'],
        ]);
    }
}
