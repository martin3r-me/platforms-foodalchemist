<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Services\PreislistenImportService;

/** Spec 82 §3 / Schritt 4d · Vorlage der Preisliste: Spalten in kanonischer Reihenfolge, Beispielzeile, Pflichtfelder, CSV-Text. */
class PreislisteVorlageGetTool extends WareneingangTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.preisliste.VORLAGE_GET';
    }

    public function getDescription(): string
    {
        return 'Liefert die Vorlage für Preislisten: Spalten in kanonischer Reihenfolge (Lieferant, Artikelnummer, EAN, Bezeichnung, Gebinde / Einheit, Preis netto, Gültig ab), '
            .'eine Beispielzeile, den Hinweis zu den Pflichtfeldern (Preis + Artikelnummer oder EAN) und die fertige CSV (UTF-8 mit BOM, Trennzeichen ;) als Text. '
            .'Mit art=stammdaten kommt stattdessen die Vorlage für ALLE Artikel-Stammdaten (zusätzlich Marke, Hersteller, Gebinde/Füllmenge, 14 Allergene, 18 Zusatzstoffe, Nährwerte je 100 g, Zutatenliste, Herkunft, Haltbarkeit, Bio). '
            .'Der eigene Import (preisliste.UPLOAD) erkennt diese Spalten ohne Nachzuordnung. Die Excel-Vorlage gibt es in der Oberfläche (nur wenn openspout installiert ist).';
    }

    public function getSchema(): array
    {
        return ['type' => 'object', 'properties' => ['art' => ['type' => 'string', 'enum' => ['preisliste', 'stammdaten'], 'description' => 'preisliste (Standard) | stammdaten: alle Artikel-Stammdaten (Allergene, Zusatzstoffe, Nährwerte, Zutaten, Herkunft, Haltbarkeit, Bio)']]];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        return $this->ausfuehren($context, function (Team $team) use ($arguments) {
            if (($arguments['art'] ?? 'preisliste') === 'stammdaten') {
                $stamm = app(\Platform\FoodAlchemist\Services\Stammdaten\StammdatenImportService::class);

                return $stamm->vorlage() + ['csv' => $stamm->vorlageCsv(), 'dateiname' => 'stammdaten_vorlage.csv'];
            }
            $svc = app(PreislistenImportService::class);

            return $svc->vorlage() + ['csv' => $svc->vorlageCsv(), 'dateiname' => 'preisliste_vorlage.csv'];
        });
    }

    public function getMetadata(): array
    {
        return array_merge($this->metadaten(false, ['preisliste'], ['Wie muss meine Preisliste aussehen?'], ['foodalchemist.preisliste.UPLOAD']), [
            'tags' => ['foodalchemist', 'preisliste', 'einkauf', 'vorlage'],
        ]);
    }
}
