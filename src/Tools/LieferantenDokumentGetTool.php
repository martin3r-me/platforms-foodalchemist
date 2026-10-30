<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Services\LieferantenDokumentService;

/** Offene Datenblätter/Produktpässe, die Lieferanten über ihren Link eingereicht haben. */
class LieferantenDokumentGetTool extends WareneingangTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.lieferanten_dokument.GET';
    }

    public function getDescription(): string
    {
        return 'Listet die offenen Dokumente (Datenblatt/Produktpass), die Lieferanten über ihren Link eingereicht haben: je Zeile Datei, Lieferant, Artikelnummer, '
            .'zugeordneter Artikel (oder „kein Artikel“) und Eingang. Entscheiden mit foodalchemist.lieferanten_dokument.ENTSCHEIDEN. Nur Rolle Kuratieren, sonst FORBIDDEN.';
    }

    public function getSchema(): array
    {
        return ['type' => 'object', 'properties' => []];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        return $this->ausfuehren($context, function (Team $team) use ($context) {
            $svc = app(LieferantenDokumentService::class);
            app(\Platform\FoodAlchemist\Services\FaRechte::class)->pruefe($context->user, $team, \Platform\FoodAlchemist\Enums\FaRolle::Kuratieren, 'Offene Lieferanten-Dokumente ansehen');

            return ['dokumente' => $svc->offene($team)->map(fn ($z) => ['zeile_id' => $z->id, 'lauf_id' => $z->run_id, 'datei' => $z->daten['name'] ?? null, 'lieferant' => $z->supplier_name,
                'artikelnummer' => $z->article_number, 'artikel_id' => $z->daten['supplier_item_id'] ?? null, 'bezeichnung' => $z->designation, 'zuordenbar' => $z->status === \Platform\FoodAlchemist\Models\FoodAlchemistPriceImportLine::STATUS_TREFFER,
                'eingegangen' => $z->created_at?->toIso8601String()])->values()->all()];
        });
    }

    public function getMetadata(): array
    {
        return array_merge($this->metadaten(false, ['lieferanten_dokument', 'lieferant', 'produktpass'], ['Welche Dokumente haben Lieferanten eingereicht?'], ['foodalchemist.lieferanten_dokument.ENTSCHEIDEN']), [
            'tags' => ['foodalchemist', 'lieferanten_dokument', 'lieferant', 'einkauf', 'read'],
        ]);
    }
}
