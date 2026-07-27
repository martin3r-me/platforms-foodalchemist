<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\ArticleImportTriggerService;

/**
 * Spec 13 · S3b — die **Auslösung** des Kanal-B-Imports über MCP. Nicht ein zweiter
 * Import-Pfad: geschrieben wird von `FileArticleImportService`, genau wie beim Kommando
 * (DoD „Bulk bleibt artisan" bleibt damit gültig — das Tool startet, es importiert nicht
 * selbst).
 *
 * Drei Festlegungen, die dieses Tool ausmachen:
 *  - **Dateiname statt Pfad.** Gelesen wird nur aus dem festen Ablage-Ordner
 *    `storage/app/foodalchemist/import`. Ohne `datei` listet das Tool, was dort liegt —
 *    damit muss niemand Namen raten.
 *  - **Trockenlauf ist Default** (`apply=false`), wie beim Kommando: ein Import berührt
 *    den Katalog, aus dem jede Kalkulation ihren EK zieht.
 *  - **Scharf läuft als Job.** Zurück kommt die `run_id`; die Quittung liest
 *    `foodalchemist.ingest.STATUS`.
 */
class IngestImportTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.ingest.IMPORT';
    }

    public function getDescription(): string
    {
        return 'Löst den Katalog-/Preis-Import einer Lieferanten-Artikel-Datei aus (Kanal B, Spec 13). '
            . 'Gelesen wird ausschließlich aus dem festen Ablage-Ordner storage/app/foodalchemist/import — '
            . 'der Parameter datei ist ein reiner Dateiname (CSV/TSV), kein Pfad; Pfade werden nicht gelesen. '
            . 'OHNE datei liefert das Tool die Liste der dort bereitliegenden Dateien. '
            . 'apply=false (Default) ist ein Trockenlauf: er schreibt nichts und liefert die Vorschau '
            . '(erkannte Spalten, Zeilen-Befunde, Preis-/Detail-/Konditions-Bilanz, betroffene Rezept-Kette). '
            . 'apply=true stellt scharf — der Lauf wird als Job eingereiht (er kann bis zu 1.000 Rezept-Ketten '
            . 'neu berechnen) und liefert eine run_id; Ergebnis und Fortschritt über foodalchemist.ingest.STATUS. '
            . 'Der Import ist idempotent (unveränderte Zeile schreibt nichts, leere Zelle löscht nichts) und '
            . 'überspringt geerbte Artikel fremder Teams. Spalten-Vorlage: docs/IMPORT_Kanal_B_Artikel_Vorlage.md.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'datei' => [
                    'type' => 'string',
                    'description' => 'Reiner Dateiname im Ablage-Ordner (z. B. "hanos_q3.csv"). Kein Pfad, keine '
                        . 'Verzeichnis-Anteile. Weglassen, um die bereitliegenden Dateien zu listen.',
                ],
                'supplier_id' => [
                    'type' => 'integer',
                    'description' => 'Lieferant der Datei (eine Datei = ein Lieferant). Muss in der Team-Kette '
                        . 'sichtbar sein. Pflicht, sobald datei gesetzt ist.',
                ],
                'apply' => [
                    'type' => 'boolean',
                    'default' => false,
                    'description' => 'false = Trockenlauf/Vorschau (Default, schreibt nichts) · true = scharf '
                        . 'stellen (Job, liefert run_id).',
                ],
                'zeilen' => [
                    'type' => 'integer',
                    'minimum' => 0,
                    'maximum' => ArticleImportTriggerService::MAX_BEFUNDE,
                    'default' => 25,
                    'description' => 'Höchstzahl der Zeilen-Befunde in der Vorschau (unveränderte Zeilen ohne '
                        . 'Preis-/Detail-Bewegung werden ohnehin nicht ausgegeben).',
                ],
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

        $trigger = app(ArticleImportTriggerService::class);
        $datei = trim((string) ($arguments['datei'] ?? ''));

        // Kein Dateiname = Discovery. Ein Datei-Parameter, dessen Wertemenge man nicht
        // erfragen kann, führt zum Raten — und Raten ist hier die falsche Übung.
        if ($datei === '') {
            $dateien = $trigger->dateien();

            return ToolResult::success([
                'ordner' => ArticleImportTriggerService::ORDNER,
                'dateien' => $dateien,
                'anleitung' => $dateien === []
                    ? 'Der Ablage-Ordner ist leer. Die Artikel-Datei muss dort abgelegt werden (CSV/TSV) — '
                        . 'der Import liest von keinem anderen Ort. Spalten-Vorlage: docs/IMPORT_Kanal_B_Artikel_Vorlage.md.'
                    : 'Zum Prüfen: datei + supplier_id angeben (apply=false, schreibt nichts). '
                        . 'Zum Schreiben derselbe Aufruf mit apply=true.',
            ]);
        }

        $supplierId = (int) ($arguments['supplier_id'] ?? 0);
        if ($supplierId <= 0) {
            return ToolResult::error(
                'supplier_id fehlt. Eine Datei gehört zu genau einem Lieferanten — welcher das ist, sagt die '
                . 'Datei nicht (sie enthält nur Artikelnummern). Lieferanten finden: foodalchemist.suppliers.SEARCH.',
                'VALIDATION_ERROR'
            );
        }

        $apply = (bool) ($arguments['apply'] ?? false);

        try {
            if ($apply) {
                $userId = $context->user?->id !== null ? (int) $context->user->id : null;

                return ToolResult::success($trigger->starteScharf($team, $supplierId, $datei, $userId));
            }

            return ToolResult::success($trigger->trockenlauf($team, $supplierId, $datei, (int) ($arguments['zeilen'] ?? 25)));
        } catch (\InvalidArgumentException $e) {
            // Datei-/Format-Problem: der Aufrufer behebt es an der Datei.
            return ToolResult::error($e->getMessage(), 'VALIDATION_ERROR');
        } catch (\RuntimeException $e) {
            // Lieferant nicht sichtbar (D1): der Aufrufer behebt es am Parameter.
            return ToolResult::error($e->getMessage(), 'NOT_FOUND');
        }
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'command',
            // Nicht-REST-Verb ⇒ read_only explizit setzen (sonst leitet der Resolver es aus
            // dem Namens-Segment ab). apply=false schreibt nichts, das Tool als Ganzes schon.
            'read_only' => false,
            'idempotent' => true,       // derselbe Lauf mit derselben Datei schreibt beim zweiten Mal nichts
            'risk_level' => 'write',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'confirmation_required' => true,
            'tags' => ['foodalchemist', 'ingest', 'import', 'katalog', 'preis', 'lieferantenartikel', 'datei', 'kanal-b'],
            'related_tools' => ['foodalchemist.ingest.STATUS', 'foodalchemist.suppliers.SEARCH', 'foodalchemist.artikel.LIST'],
            'examples' => [
                'Welche Import-Dateien liegen bereit?',
                'Prüf mal die Datei hanos_q3.csv für Lieferant 12 (ohne zu schreiben).',
                'Importiere hanos_q3.csv für Lieferant 12 scharf.',
            ],
        ];
    }
}
