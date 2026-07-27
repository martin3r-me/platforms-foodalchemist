<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\IngestStatusService;

/**
 * Spec 13 · S3 — Lese-Fläche des Katalog-Ingests (Kanal B). Read-only: der Import
 * selbst bleibt artisan (`foodalchemist:import-articles`, DoD „Bulk bleibt artisan").
 *
 * Beantwortet die drei Fragen nach einem Quartals-Import: **ist er gelaufen?**
 * (Läufe), **was fehlt noch?** (Lücken), **was hat sich am Preis bewegt?** (Deltas).
 * Was das Tool bewusst NICHT behauptet: welche Datei zu welchem Lauf gehört — die
 * Bestands-Lauf-Zeile hat kein Feld dafür (V-047), und die Antwort sagt das.
 */
class IngestStatusTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.ingest.STATUS';
    }

    public function getDescription(): string
    {
        return 'Stand des Katalog-/Preis-Ingests (Kanal B) für das Team: letzte Import-Läufe (Zeilen, '
            . 'verarbeitet, Fehler, Zeitpunkt), Lücken-Liste der sichtbaren Lieferantenartikel '
            . '(ohne aktiven EK / ohne GP-Struktur / ohne Allergen-Aussage / ohne Nährwerte, je mit '
            . 'Beispielen) und die Preis-Deltas des Zeitfensters (aktueller EK gegen Vorgänger, '
            . 'stärkste Bewegungen zuerst). Optional auf einen Lieferanten eingeschränkt. Read-only — '
            . 'der Import selbst läuft als Kommando foodalchemist:import-articles. '
            . 'Hinweis: Läufe lassen sich zählen und datieren, aber nicht benennen (die Lauf-Zeile '
            . 'kennt weder Datei noch Lieferant); was angekommen ist, sagen Lücken und Deltas.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'supplier_id' => ['type' => 'integer', 'description' => 'Optional: nur dieser Lieferant (muss in der Team-Kette sichtbar sein).'],
                'tage' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 365, 'default' => 30, 'description' => 'Zeitfenster der Preis-Deltas in Tagen.'],
                'beispiele' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 50, 'default' => 10, 'description' => 'Beispiele je Lücken-Art bzw. Länge der Delta-Liste (0 = nur Zahlen).'],
                'laeufe' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 20, 'default' => 10, 'description' => 'Anzahl der zurückgelieferten Import-Läufe.'],
            ],
            'required' => [],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }

        $supplierId = isset($arguments['supplier_id']) && $arguments['supplier_id'] !== null && $arguments['supplier_id'] !== ''
            ? (int) $arguments['supplier_id'] : null;

        try {
            return ToolResult::success(app(IngestStatusService::class)->status(
                $team,
                $supplierId,
                (int) ($arguments['tage'] ?? 30),
                (int) ($arguments['beispiele'] ?? 10),
                (int) ($arguments['laeufe'] ?? 10),
            ));
        } catch (\RuntimeException $e) {
            return ToolResult::error($e->getMessage(), 'NOT_FOUND');
        }
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'query',
            'read_only' => true, 'idempotent' => true, 'risk_level' => 'safe',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'tags' => ['foodalchemist', 'ingest', 'import', 'katalog', 'preis', 'lieferantenartikel', 'status', 'luecken'],
            'related_tools' => ['foodalchemist.artikel.LIST', 'foodalchemist.artikel.SEARCH', 'foodalchemist.signale.SEARCH'],
            'examples' => [
                'Ist der Katalog-Import durchgelaufen?',
                'Welche Artikel von Hanos haben keinen EK?',
                'Welche Preise haben sich im letzten Monat am stärksten bewegt?',
            ],
        ];
    }
}
