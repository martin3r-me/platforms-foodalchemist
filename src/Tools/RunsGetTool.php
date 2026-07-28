<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Enums\BulkRunType;
use Platform\FoodAlchemist\Models\FoodAlchemistBulkRun;
use Platform\FoodAlchemist\Services\BulkRunStatusService;

/**
 * Spec 22 · H3c — die Quittung nach außen (V-055).
 *
 * Bis hier konnte ein LLM Läufe **auslösen** (`ingest.IMPORT`), aber nur einen einzigen
 * Lauf-Typ nachlesen, und das über eine fachliche Fläche (`ingest.STATUS`). Ein Autopilot-
 * oder Review-Lauf war über MCP gar nicht abfragbar. Das ist die Vorbedingung dafür, dass
 * „Bulk bleibt artisan" nicht „per MCP nur blind starten" heißt.
 *
 * Read-only: dieses Tool startet nichts und bricht nichts ab.
 */
class RunsGetTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.runs.GET';
    }

    public function getDescription(): string
    {
        return 'Stand eingereihter Massen-Läufe des Teams (Anreicherungs-Autopilot auf Rezepten/Gerichten/'
            . 'Grundprodukten, Artikel-Import, KI-Review) — die allgemeine Quittung zu jeder run_id, die ein '
            . 'auslösendes Tool zurückgegeben hat. Liefert Art, Zustand, Fortschritt (umfang/verarbeitet/fehler), '
            . 'Auslöser, Gegenstand des Laufs und den Fehlergrund. '
            . 'Das Entscheidungs-Feld ist offen: nur bei offen=true lohnt Warten. '
            . 'offen=false mit status=running heißt verwaist=true — der Lauf hat sich seit über '
            . FoodAlchemistBulkRun::VERWAIST_NACH_STUNDEN . ' Stunden nicht gemeldet und ist vermutlich '
            . 'abgebrochen; status=failed nennt den Grund in fehler_grund. In beiden Fällen NICHT blind erneut '
            . 'auslösen, ohne die Ursache zu kennen. '
            . 'hinweis="' . FoodAlchemistBulkRun::HINWEIS_LEERE_MENGE . '" heißt: der Lauf war sofort fertig, '
            . 'weil die Arbeitsmenge leer war (kein Fehler, aber auch kein Ergebnis). '
            . 'Read-only. Nicht enthalten: der Rezept-Generator, dessen Lauf-Stand nur in der Oberfläche liegt.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'run_id' => ['type' => 'integer', 'description' => 'Optional: genau dieser Lauf (muss dem eigenen Team gehören).'],
                'typ' => [
                    'type' => 'string',
                    'enum' => array_map(fn (BulkRunType $t) => $t->value, BulkRunType::cases()),
                    'description' => 'Optional: nur Läufe dieser Art.',
                ],
                'nur_offene' => ['type' => 'boolean', 'default' => false, 'description' => 'Nur Läufe, die noch auf running stehen (inkl. verwaister — die erkennt man an offen=false).'],
                'limit' => [
                    'type' => 'integer', 'minimum' => 1, 'maximum' => BulkRunStatusService::MAX_LIMIT,
                    'default' => BulkRunStatusService::DEFAULT_LIMIT,
                    'description' => 'Anzahl der zurückgelieferten Läufe (neueste zuerst).',
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

        $typ = null;
        if (isset($arguments['typ']) && $arguments['typ'] !== null && $arguments['typ'] !== '') {
            $typ = BulkRunType::tryFrom((string) $arguments['typ']);
            if ($typ === null) {
                return ToolResult::error(
                    'Unbekannte Lauf-Art "' . $arguments['typ'] . '". Verfügbar: '
                        . implode(', ', array_map(fn (BulkRunType $t) => $t->value, BulkRunType::cases())),
                    'VALIDATION_ERROR'
                );
            }
        }

        $runId = isset($arguments['run_id']) && $arguments['run_id'] !== null && $arguments['run_id'] !== ''
            ? (int) $arguments['run_id'] : null;

        $laeufe = app(BulkRunStatusService::class)->laeufe(
            $team,
            $runId,
            $typ,
            (int) ($arguments['limit'] ?? BulkRunStatusService::DEFAULT_LIMIT),
            (bool) ($arguments['nur_offene'] ?? false),
        );

        // Ein gezielt abgefragter, aber nicht sichtbarer Lauf ist ein Fehler, kein leeres
        // Ergebnis: „gibt es nicht" und „gehört einem anderen Team" sehen für den Aufrufer
        // gleich aus, und beide Male ist Weiterwarten falsch.
        if ($runId !== null && $laeufe === []) {
            return ToolResult::error("Lauf #{$runId} existiert nicht im eigenen Team.", 'NOT_FOUND');
        }

        return ToolResult::success([
            'anzahl' => count($laeufe),
            'laeufe' => $laeufe,
        ]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'query',
            'read_only' => true, 'idempotent' => true, 'risk_level' => 'safe',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'tags' => ['foodalchemist', 'lauf', 'run', 'status', 'quittung', 'bulk', 'anreicherung', 'import', 'review', 'queue'],
            'related_tools' => ['foodalchemist.ingest.STATUS', 'foodalchemist.ingest.IMPORT'],
            'examples' => [
                'Ist Lauf 42 durch?',
                'Läuft gerade noch ein Anreicherungs-Lauf?',
                'Welche Massen-Läufe sind zuletzt gescheitert?',
            ],
        ];
    }
}
