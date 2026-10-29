<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Exceptions\FaRechtFehltException;
use Platform\FoodAlchemist\Enums\FaRolle;
use Platform\FoodAlchemist\Services\PreislistenImportService;
use Platform\FoodAlchemist\Services\Stammdaten\StammdatenImportService;

/**
 * Spec 82 §3 / Schritt 4d, Stufe 2 · Preisliste des eigenen Teams abgleichen (CSV oder Excel als Base64).
 * Schreibt keinen Preis und legt keinen Artikel an. Die Datei landet in einem vom System erzeugten Temp-Pfad,
 * der Dateiname wird nur für Endung und Anzeige gelesen.
 */
class PreislisteUploadTool extends WareneingangTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.preisliste.UPLOAD';
    }

    public function getDescription(): string
    {
        return 'Gleicht eine Preisliste des eigenen Teams gegen die sichtbaren Artikel ab (Artikelnummer + Lieferant, danach EAN) und legt einen Lauf an. '
            .'art=stammdaten prüft statt Preisen die ganzen Artikel-Stammdaten (neu | ändert | Konflikt | Fehler je Zeile, Allergene und Nährwerte mit Soll/Ist) und schreibt NICHTS. '
            .'Datei als content_base64 (CSV mit ; , Tab oder | oder Excel .xlsx/.ods, bis 10 MB) plus filename (nur Endung und Anzeigename). '
            .'Lieferant: supplier_id oder eine Lieferant-Spalte in der Datei. mapping {feld: Überschrift|Spaltenindex} überschreibt die Erkennung '
            .'(Felder: supplier, article_number, ean, designation, unit, price, valid_from). Es wird KEIN Preis geschrieben und KEIN Artikel angelegt; '
            .'Zeilen ohne Treffer stehen in der Prüfliste (preisliste_laeufe.GET); übernommen werden die Treffer erst mit preisliste.APPLY. Nur das Haupt-Team des Kunden mit Rolle Kuratieren, sonst FORBIDDEN.';
    }

    public function getSchema(): array
    {
        return ['type' => 'object', 'properties' => [
            'filename' => ['type' => 'string', 'description' => 'z. B. preisliste-hanos.xlsx'],
            'content_base64' => ['type' => 'string'],
            'supplier_id' => ['type' => 'integer'],
            'mapping' => ['type' => 'object'],
            'art' => ['type' => 'string', 'enum' => ['preisliste', 'stammdaten'], 'description' => 'preisliste (Standard) | stammdaten: Artikel-Stammdaten (Vorlage: preisliste.VORLAGE_GET art=stammdaten). Stammdaten legen nur eine Prüfliste an, nichts geht in den Bestand.'],
        ], 'required' => ['filename', 'content_base64']];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        return $this->ausfuehren($context, function (Team $team) use ($arguments, $context) {
            $svc = app(PreislistenImportService::class);
            if (! $svc->darfImportieren($team, $context->user)) {
                throw new FaRechtFehltException(FaRolle::Kuratieren, null, 'Preisliste importieren (nur das Haupt-Team des Kunden)');
            }
            $roh = base64_decode((string) ($arguments['content_base64'] ?? ''), true);
            if ($roh === false || $roh === '') {
                throw new \RuntimeException('content_base64 ist leer oder kein gültiges Base64.');
            }
            if (strlen($roh) > PreislistenImportService::MAX_BYTES) {
                throw new \RuntimeException('Datei ist größer als ' . (PreislistenImportService::MAX_BYTES / 1024 / 1024) . ' MB.');
            }
            $name = basename((string) ($arguments['filename'] ?? 'preisliste'));
            $endung = strtolower((string) pathinfo($name, PATHINFO_EXTENSION));
            if (! in_array($endung, [...PreislistenImportService::ENDUNGEN_CSV, ...PreislistenImportService::ENDUNGEN_EXCEL], true)) {
                throw new \RuntimeException('filename braucht eine Endung: csv, tsv, txt, xlsx, xlsm oder ods.');
            }

            $tmp = tempnam(sys_get_temp_dir(), 'preisliste');   // vom System erzeugt, nie aus der Eingabe
            try {
                file_put_contents($tmp, $roh);
                $supplier = isset($arguments['supplier_id']) ? (int) $arguments['supplier_id'] : null;
                $run = ($arguments['art'] ?? 'preisliste') === 'stammdaten'
                    ? app(StammdatenImportService::class)->stage($team, $tmp, $supplier, $context->user?->id, $endung, $name)
                    : $svc->stage($team, $tmp, (array) ($arguments['mapping'] ?? []), $supplier, $context->user?->id, $endung, $name);
            } finally {
                @unlink($tmp);
            }

            return ['lauf_id' => $run->id, 'art' => $run->art, 'datei' => $run->filename, 'zaehler' => $run->counts, 'preise_geschrieben' => 0,
                'hinweis' => $run->art === 'stammdaten' ? 'Nur geprüft, nichts im Bestand geändert. Die Prüfliste steht in preisliste_laeufe.GET (run_id, optional aktion).' : 'Nur abgeglichen. Mit preisliste.APPLY (lauf_id) werden die Treffer als Teampreise übernommen, die Prüfliste steht in preisliste_laeufe.GET.'];
        });
    }

    public function getMetadata(): array
    {
        return array_merge($this->metadaten(true, ['preisliste'], ['Hier ist die Preisliste von Hanos als Excel.'], ['foodalchemist.preisliste_laeufe.GET']), [
            'tags' => ['foodalchemist', 'preisliste', 'einkauf', 'write'],
        ]);
    }
}
