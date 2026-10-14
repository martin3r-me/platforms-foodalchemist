<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Services\ConceptFassungService;

/** Spec 82 Abo Konzepte · Konzept veröffentlichen: friert den Arbeitsstand eines Master-Konzepts (auch kind=paket) als Fassung ein (nur Master, Rolle „Freigeben“). */
class ConceptFassungenPublishTool extends WareneingangTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.concept_fassungen.PUBLISH';
    }

    public function getDescription(): string
    {
        return 'Veröffentlicht den Arbeitsstand eines Master-Konzepts (auch Paket-Konzept, kind=paket) als neue Fassung (schreibgeschützt, ohne Master-Preise). '
            .'Jedes Paket, eingebettete Paket-Konzept und Rezept darin braucht eine veröffentlichte Fassung, sonst bricht es mit einer Liste ab. Gleicher Inhalt wie die letzte Fassung erzeugt keine neue. '
            .'Nur im Master-Team mit FA-Rolle „Freigeben“; notiz (Änderungsprotokoll) ist Pflicht. Liefert fassung_id, neu, fassung_nr und Hinweise '
            .'(z. B. Rezepte mit unveröffentlichten Änderungen).';
    }

    public function getSchema(): array
    {
        return ['type' => 'object', 'properties' => [
            'concept_id' => ['type' => 'integer', 'description' => 'Arbeitszeile des Master-Konzepts (kind concept oder paket).'],
            'notiz' => ['type' => 'string', 'description' => 'Änderungsprotokoll, z. B. „Grill-Buffet: Dessert getauscht“.'],
        ], 'required' => ['concept_id', 'notiz']];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        return $this->ausfuehren($context, fn (Team $team) => app(ConceptFassungService::class)
            ->veroeffentlichen($team, (int) ($arguments['concept_id'] ?? 0), (string) ($arguments['notiz'] ?? ''), $context->user));
    }

    public function getMetadata(): array
    {
        return array_merge($this->metadaten(true, ['konzepte', 'fassung'], ['Veröffentliche das Konzept Grill-Buffet mit der Notiz „Dessert getauscht“.'], ['foodalchemist.concept_fassungen.GET']), [
            'tags' => ['foodalchemist', 'konzepte', 'fassung', 'kurator', 'write'],
        ]);
    }
}
