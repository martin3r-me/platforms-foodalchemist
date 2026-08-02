<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\SalesImportService;
use RuntimeException;

/**
 * Spec 32 · C3 (write, Trockenlauf per Default): Verkaufs-Ist aus einer CSV einlesen.
 *
 * Zwei Dinge sind hier bewusst eng geführt:
 *
 *  1. **Dateiname statt Pfad.** Gelesen wird ausschließlich aus dem festen Ablage-Ordner
 *     ({@see SalesImportService::ORDNER}) — ein Tool, das einen freien Pfad annimmt, ist ein
 *     Lesezugriff auf das Server-Dateisystem. Dieselbe Linie wie beim Artikel-Import.
 *  2. **`apply` muss ausdrücklich gesetzt werden.** Ohne Flag ist der Lauf trocken und
 *     schreibt nichts. `read_only` steht deshalb auf `false`: das Tool KANN schreiben, und
 *     ein Discovery-Index, der es als lesend führt, wäre eine Lüge.
 *
 * Vor dem ersten Lauf hilft `columns: true` — dann kommt nur die Kopfzeile mit
 * Zuordnungsvorschlag zurück, ohne irgendetwas zu verarbeiten.
 */
class SalesImportPostTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.sales_import.POST';
    }

    public function getDescription(): string
    {
        return 'Verkaufs-Ist (CSV/TSV) ins Verkaufsjournal einlesen. `file` ist ein DATEINAME aus der '
            . 'team-eigenen Ablage (foodalchemist/sales-import/<team>), kein Pfad. `mapping` ordnet Felder auf Spalten-Indizes '
            . '(0-basiert): bezeichnung, umsatz und datum sind Pflicht, menge und bereich optional. '
            . 'OHNE apply=true ist der Lauf ein Trockenlauf und schreibt nichts — der Bericht ist derselbe. '
            . 'Mit columns=true kommt nur die Kopfzeile samt Zuordnungsvorschlag zurück. Zeilen ohne '
            . 'erkennbares Gericht werden mit Roh-Text gespeichert (recipe_id=null) statt verworfen; '
            . 'Wiederholungen sind idempotent (Team+Tag+Bezeichnung+Bereich).';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'file' => ['type' => 'string', 'description' => 'Dateiname in der team-eigenen Ablage (kein Pfad)'],
                'columns' => ['type' => 'boolean', 'description' => 'nur Kopfzeile + Zuordnungsvorschlag lesen'],
                'mapping' => [
                    'type' => 'object',
                    'description' => 'Feld => Spalten-Index (0-basiert)',
                    'properties' => [
                        'bezeichnung' => ['type' => 'integer'],
                        'menge' => ['type' => 'integer'],
                        'umsatz' => ['type' => 'integer'],
                        'datum' => ['type' => 'integer'],
                        'bereich' => ['type' => 'integer'],
                    ],
                ],
                'apply' => ['type' => 'boolean', 'description' => 'true = wirklich schreiben (Default false)'],
            ],
            'required' => ['file'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }

        $datei = trim((string) ($arguments['file'] ?? ''));
        if ($datei === '') {
            return ToolResult::error('file ist Pflicht (Dateiname im Ablage-Ordner).', 'VALIDATION_ERROR');
        }

        $svc = app(SalesImportService::class);

        try {
            if ((bool) ($arguments['columns'] ?? false)) {
                return ToolResult::success($svc->kopf($team, $datei));
            }

            $mapping = [];
            foreach ((array) ($arguments['mapping'] ?? []) as $feld => $idx) {
                if (in_array($feld, SalesImportService::FELDER, true) && is_numeric($idx)) {
                    $mapping[$feld] = (int) $idx;
                }
            }

            return ToolResult::success(
                $svc->importiere($team, $datei, $mapping, (bool) ($arguments['apply'] ?? false))
            );
        } catch (RuntimeException $e) {
            return ToolResult::error($e->getMessage(), 'VALIDATION_ERROR');
        }
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'command',
            'tags' => ['foodalchemist', 'controlling', 'verkauf', 'import', 'umsatz', 'csv'],
            // Das Tool schreibt, wenn apply gesetzt ist — read_only=true wäre hier falsch,
            // auch wenn der Default-Aufruf harmlos ist.
            'read_only' => false,
            'idempotent' => true,
            'risk_level' => 'write',
            'requires_auth' => true,
            'requires_team' => true,
            'cost_class' => 'local_db',
            'confirmation_required' => true,
            'related_tools' => ['foodalchemist.sales_facts.GET', 'foodalchemist.menu_engineering.GET'],
            'examples' => ['Lies verkauf_juli.csv als Trockenlauf ein und zeig mir, wie viele Zeilen zugeordnet werden.'],
        ];
    }
}
