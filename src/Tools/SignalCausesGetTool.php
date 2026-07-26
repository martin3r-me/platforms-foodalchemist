<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\SignalCauseService;

/**
 * Spec 21 · Tranche P · Punkt 5 (S3b-3) — Ursachen-Lesetool.
 *
 * `signale.SEARCH`/`LIST` sagen WAS offen ist, `signale.FIX --dry_run` sagt, was ein Fix
 * ändern WÜRDE. Offen blieb das WARUM — und daran hängt, ob überhaupt ein Fix in Frage
 * kommt: „Lead-LA zeigt ins Leere" ist ein Klick, „GP hat keinen Lieferantenartikel" ist
 * eine Einkaufs-Aufgabe. Beide sehen in der Signal-Liste identisch aus.
 *
 * Read-only. Fragt das OBJEKT, nicht das Signal: ein Aggregat-Signal deckt n Objekte ab,
 * die Ursache hat nur je Objekt eine Antwort (Objekt-IDs liefert `signale.LIST`).
 */
class SignalCausesGetTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.signal_causes.GET';
    }

    public function getDescription(): string
    {
        return 'Ursachen-Kette zu EINEM Objekt (Rezept oder GP): warum löst dessen EK-Kette nicht auf '
            . 'und/oder welche Benennungs-Regel verletzt es. Löst drei Stufen nach unten auf — unbepreiste '
            . 'Zutat → Grundprodukt → Beschaffungs-Lage (kein LA / kein bepreister LA / kein Lead-LA / Lead '
            . 'ohne Preis) — und markiert je Glied, ob ein automatischer Fix greifen kann (fixbar) oder ob es '
            . 'eine echte Beschaffungs-/Pflege-Aufgabe ist. Bei Regelwerk-Verstößen kommt das verletzte § '
            . 'samt Link auf das Regelwerk im Wissens-Modul zurück. Leeres Ergebnis = an diesem Objekt ist '
            . 'nichts zu erklären.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'kind' => ['type' => 'string', 'enum' => ['recipe', 'gp'], 'description' => 'Objekt-Art.'],
                'id' => ['type' => 'integer', 'minimum' => 1, 'description' => 'ID des Rezepts bzw. Grundprodukts.'],
            ],
            'required' => ['kind', 'id'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        $kind = (string) ($arguments['kind'] ?? '');
        $id = (int) ($arguments['id'] ?? 0);
        if (! in_array($kind, ['recipe', 'gp'], true) || $id <= 0) {
            return ToolResult::error("kind muss 'recipe' oder 'gp' sein, id > 0.", 'VALIDATION_ERROR');
        }

        $bloecke = app(SignalCauseService::class)->fuerObjekt($team, $kind, $id);

        return ToolResult::success([
            'kind' => $kind,
            'id' => $id,
            'anzahl' => count($bloecke),
            'hinweis' => $bloecke === []
                ? 'Keine auflösbare Ursache an diesem Objekt — entweder ist es (nicht mehr) betroffen oder es ist im Team nicht sichtbar.'
                : null,
            'ursachen' => $bloecke,
        ]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'query',
            'read_only' => true, 'idempotent' => true, 'risk_level' => 'safe',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'tags' => ['foodalchemist', 'signal', 'ursache', 'diagnose', 'ek-kette', 'lead-la', 'regelwerk', 'datenqualitaet'],
            'related_tools' => ['foodalchemist.signale.LIST', 'foodalchemist.signale.FIX', 'foodalchemist.signal_trend.GET'],
            'examples' => [
                'Warum ist Rezept 412 teil-unbepreist?',
                'Welches Grundprodukt blockiert die EK-Kette dieses Gerichts?',
                'Gegen welche Namensregel verstößt dieses Verkaufsgericht?',
            ],
        ];
    }
}
