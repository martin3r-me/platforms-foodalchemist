<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Enums\FaRolle;
use Platform\FoodAlchemist\Services\FaRechte;
use Platform\FoodAlchemist\Services\LieferantenStammdatenblattService;

/** Offene Stammdatenblatt-Vorschläge, die Lieferanten über ihren Link eingereicht haben. */
class LieferantenStammdatenblattGetTool extends WareneingangTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.lieferanten_stammdatenblatt.GET';
    }

    public function getDescription(): string
    {
        return 'Listet die offenen Stammdatenblatt-Vorschläge, die Lieferanten über ihren Link eingereicht haben (Firmen- und Bestelldaten, Ansprechpartner je Rolle, '
            .'Liefergebiete, Zertifikate): je Zeile Lieferant, Datensatz-Art und Vorher/Nachher je Feld. Entscheiden mit foodalchemist.lieferanten_stammdatenblatt.ENTSCHEIDEN. '
            .'Nur Rolle Kuratieren, sonst FORBIDDEN.';
    }

    public function getSchema(): array
    {
        return ['type' => 'object', 'properties' => []];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        return $this->ausfuehren($context, function (Team $team) use ($context) {
            app(FaRechte::class)->pruefe($context->user, $team, FaRolle::Kuratieren, 'Offene Stammdatenblatt-Vorschläge ansehen');

            return ['vorschlaege' => app(LieferantenStammdatenblattService::class)->offene($team)->map(fn ($z) => ['zeile_id' => $z->id, 'lauf_id' => $z->run_id, 'lieferant_id' => $z->supplier_id,
                'lieferant' => $z->supplier_name, 'art' => $z->daten['typ'] ?? null, 'bezeichnung' => $z->designation, 'aktion' => $z->aktion, 'aenderungen' => $z->diff,
                'eingegangen' => $z->created_at?->toIso8601String()])->values()->all()];
        });
    }

    public function getMetadata(): array
    {
        return array_merge($this->metadaten(false, ['lieferanten_stammdatenblatt', 'lieferant'], ['Welche Stammdatenblatt-Angaben haben Lieferanten eingereicht?'], ['foodalchemist.lieferanten_stammdatenblatt.ENTSCHEIDEN']), [
            'tags' => ['foodalchemist', 'lieferanten_stammdatenblatt', 'lieferant', 'einkauf', 'read'],
        ]);
    }
}
