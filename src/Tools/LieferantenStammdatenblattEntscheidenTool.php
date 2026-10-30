<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Services\LieferantenStammdatenblattService;

/** Stammdatenblatt-Vorschläge vom Lieferanten übernehmen oder verwerfen. */
class LieferantenStammdatenblattEntscheidenTool extends WareneingangTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.lieferanten_stammdatenblatt.ENTSCHEIDEN';
    }

    public function getDescription(): string
    {
        return 'Übernimmt (entscheidung=uebernehmen) oder verwirft (verwerfen) Stammdatenblatt-Vorschläge von Lieferanten (zeilen_ids aus foodalchemist.lieferanten_stammdatenblatt.GET). '
            .'Geschrieben wird nur über eine feste Feld-Liste (nie Rückvergütung, Status, Rolle, Vereinbarungen). Globale Stamm-Lieferanten darf nur das Master-Team '
            .'übernehmen, sonst FORBIDDEN. Nur Rolle Kuratieren, sonst FORBIDDEN.';
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
        return $this->ausfuehren($context, fn (Team $team) => app(LieferantenStammdatenblattService::class)->entscheide(
            $team, $context->user, array_map('intval', (array) ($arguments['zeilen_ids'] ?? [])), (string) ($arguments['entscheidung'] ?? '')));
    }

    public function getMetadata(): array
    {
        return array_merge($this->metadaten(true, ['lieferanten_stammdatenblatt', 'lieferant'], ['Übernimm die Bestellzeiten von Hanos.'], ['foodalchemist.lieferanten_stammdatenblatt.GET']), [
            'tags' => ['foodalchemist', 'lieferanten_stammdatenblatt', 'lieferant', 'einkauf', 'write'],
        ]);
    }
}
