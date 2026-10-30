<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Services\LieferantenDokumentService;

/** Dokumente vom Lieferanten übernehmen (Produktpass-Weg) oder verwerfen. */
class LieferantenDokumentEntscheidenTool extends WareneingangTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.lieferanten_dokument.ENTSCHEIDEN';
    }

    public function getDescription(): string
    {
        return 'Übernimmt (entscheidung=uebernehmen) oder verwirft (verwerfen) eingereichte Lieferanten-Dokumente (zeilen_ids aus foodalchemist.lieferanten_dokument.GET). '
            .'Übernehmen läuft über den Produktpass-Weg des Artikels: die Datei wird abgelegt, die KI liest sie im Hintergrund, Allergene bleiben Vorschlag bis zur Bestätigung. '
            .'Zeilen ohne Artikel oder mit einem Artikel eines anderen Lieferanten werden nicht übernommen. Nur Rolle Kuratieren, sonst FORBIDDEN.';
    }

    public function getSchema(): array
    {
        return ['type' => 'object', 'properties' => [
            'zeilen_ids' => ['type' => 'array', 'items' => ['type' => 'integer']],
            'entscheidung' => ['type' => 'string', 'enum' => ['uebernehmen', 'verwerfen']],
        ], 'required' => ['zeilen_ids', 'entscheidung']];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        return $this->ausfuehren($context, fn (Team $team) => app(LieferantenDokumentService::class)->entscheide(
            $team, $context->user, array_map('intval', (array) ($arguments['zeilen_ids'] ?? [])), (string) ($arguments['entscheidung'] ?? '')));
    }

    public function getMetadata(): array
    {
        return array_merge($this->metadaten(true, ['lieferanten_dokument', 'lieferant', 'produktpass'], ['Übernimm das Datenblatt von Hanos zu Artikel A-1.'], ['foodalchemist.lieferanten_dokument.GET']), [
            'tags' => ['foodalchemist', 'lieferanten_dokument', 'lieferant', 'einkauf', 'write'],
        ]);
    }
}
