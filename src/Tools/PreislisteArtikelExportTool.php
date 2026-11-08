<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplier;
use Platform\FoodAlchemist\Services\PreislistenImportService;

/** Artikelliste zum Ausfüllen (Dominique 08.11.2026): alle sichtbaren Artikel in Vorlagen-Spalten, Preis = eigener Teampreis oder leer. */
class PreislisteArtikelExportTool extends WareneingangTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.preisliste.ARTIKEL_EXPORT';
    }

    public function getDescription(): string
    {
        return 'Liefert ALLE für das Team sichtbaren, lieferbaren Artikel (eigene und freigeschaltete Stamm-Artikel) als Preisliste zum Ausfüllen: Spalten wie die Vorlage '
            .'(Lieferant, Artikelnummer, EAN, Bezeichnung, Gebinde / Einheit, Preis netto, Gültig ab) plus Info-Spalte Grundprodukt. „Preis netto“ ist der aktuelle EIGENE Teampreis, '
            .'sonst leer — nie ein Master- oder Listenpreis. Optional supplier_id = nur dieser Lieferant. Ausgefüllt mit preisliste.UPLOAD wieder hochladen: leere Preise werden '
            .'übersprungen, unveränderte nicht doppelt gespeichert. Antwort: zeilen (Anzahl), csv (UTF-8 mit BOM, Trennzeichen ;), dateiname.';
    }

    public function getSchema(): array
    {
        return ['type' => 'object', 'properties' => [
            'supplier_id' => ['type' => 'integer', 'minimum' => 1, 'description' => 'Nur Artikel dieses Lieferanten (muss für das Team sichtbar sein).'],
        ]];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        return $this->ausfuehren($context, function (Team $team) use ($arguments) {
            $lieferant = isset($arguments['supplier_id']) ? (int) $arguments['supplier_id'] : null;
            if ($lieferant !== null && ! FoodAlchemistSupplier::visibleToTeam($team)->whereKey($lieferant)->exists()) {
                throw new \RuntimeException('Lieferant nicht in der Team-Kette sichtbar (supplier_id prüfen).');
            }
            $svc = app(PreislistenImportService::class);
            $csv = $svc->artikelListeCsv($team, $lieferant);

            return ['zeilen' => max(0, substr_count($csv, "\n") - 1), 'spalten' => PreislistenImportService::ARTIKELLISTE_KOPF,
                'csv' => $csv, 'dateiname' => $svc->artikelListeDateiname($lieferant)];
        });
    }

    public function getMetadata(): array
    {
        return array_merge($this->metadaten(false, ['preisliste'], ['Gib mir meine Artikel als Preisliste zum Ausfüllen.'], ['foodalchemist.preisliste.UPLOAD']), [
            'tags' => ['foodalchemist', 'preisliste', 'einkauf', 'export'],
        ]);
    }
}
