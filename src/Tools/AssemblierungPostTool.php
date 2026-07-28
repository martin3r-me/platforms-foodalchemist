<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\MenuAssemblyService;
use Platform\FoodAlchemist\Services\PlanningFrameService;

/**
 * 12·S2b (R2.4) — marge-optimale Assemblierung als **Vorschau**. Rahmen (Planungs-Gerüst)
 * rein, DB-maximale Zusammenstellung raus. REIN LESEND (legt nichts an, ändert nichts),
 * trotz POST-Verb — `read_only` in der Metadata gesetzt, Vorlage `SimulationPostTool`.
 *
 * Die Übernahme ist ausdrücklich ein **zweiter** Aufruf (`assemblierung.APPLY`): ein
 * Werkzeug, das mit `read_only=true` schreibt, wäre eine Lüge im Discovery-Index — und
 * „kein Auto-Commit" heißt, dass zwischen Vorschlag und Konzept ein Mensch steht.
 */
class AssemblierungPostTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.assemblierung.POST';
    }

    public function getDescription(): string
    {
        return 'Marge-optimale Menü-Zusammenstellung für ein Planungs-Gerüst (read-only, schreibt NICHTS): '
            . 'owner_type=foodbook|concept + owner_id → je Slot die DB-stärksten zulässigen VK-Gerichte. '
            . 'Ausschließlich echte Gerichte des Teams (Slot ohne Treffer bleibt leer MIT Begründung, nie erfunden). '
            . 'Harte Vorgaben sind die Slot-Filter (No-Go-Zutat/-Allergen, Slot-Preisrahmen); Menü-weite Vorgaben '
            . '(Diät-Quoten, Preisband p. P.) sind lexikografisch — erst wenige Verletzungen, dann wenige '
            . 'Slot-Rollen-Brüche (die Speisen-Hauptgruppe muss zum Slot passen: kein Dessert im Hauptgang), dann '
            . 'hohes DB; eine unerfüllbare Vorgabe liefert also eine Antwort PLUS roten Befund statt gar nichts. '
            . 'Die Rollen-Ebene bindet, sperrt aber nicht: bleibt ein Fremdling stehen, steht er in slot_semantik '
            . '(brueche) und je Gericht in passt_zum_slot; Slots ohne bekannte Rolle sagen das (rolle_aufloesbar=false). '
            . 'rolle_quelle je Slot = gebunden (dish_main_group_id am Slot, verbindlich) | label (Näherung über die '
            . 'Bezeichnung) | unbekannt; slot_semantik.quellen zählt sie. '
            . 'Ampel = die '
            . 'R4.2-Coverage (keine zweite Messlatte). erklaerung=true sagt zusätzlich, welche Vorgabe bindet und '
            . 'wie viel DB hinter ihrer Lockerung liegt. Übernahme in ein Draft-Konzept: foodalchemist.assemblierung.APPLY.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'owner_type' => ['type' => 'string', 'enum' => ['foodbook', 'concept'], 'description' => 'Owner des Planungs-Gerüsts'],
                'owner_id' => ['type' => 'integer', 'description' => 'Foodbook- bzw. Konzept-ID'],
                'gaeste' => ['type' => 'integer', 'description' => 'Gästezahl — skaliert NUR die Ausgabe (DB gesamt), nicht die Auswahl'],
                'erklaerung' => ['type' => 'boolean', 'default' => false, 'description' => 'zusätzlich je lockerbarer Vorgabe: bindend? welches DB-Delta? (n+1 Solver-Läufe)'],
            ],
            'required' => ['owner_type', 'owner_id'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        $ownerType = (string) ($arguments['owner_type'] ?? '');
        $ownerId = (int) ($arguments['owner_id'] ?? 0);
        if (! in_array($ownerType, ['foodbook', 'concept'], true) || $ownerId <= 0) {
            return ToolResult::error('owner_type (foodbook|concept) und owner_id sind Pflicht.', 'VALIDATION_ERROR');
        }
        $gaeste = isset($arguments['gaeste']) ? max(0, (int) $arguments['gaeste']) : null;
        if ($gaeste === 0) {
            $gaeste = null;
        }

        $frames = app(PlanningFrameService::class);
        try {
            $frames->resolveOwner($team, $ownerType, $ownerId);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            return ToolResult::error('Owner nicht gefunden oder nicht team-sichtbar.', 'NOT_FOUND');
        } catch (\RuntimeException $e) {
            return ToolResult::error($e->getMessage(), 'VALIDATION_ERROR');
        }
        $frame = $frames->find($ownerType, $ownerId);
        if ($frame === null) {
            return ToolResult::error('Kein Planungs-Gerüst an diesem Owner — erst foodalchemist.planning.PUT.', 'NOT_FOUND');
        }

        $svc = app(MenuAssemblyService::class);
        try {
            $ergebnis = ($arguments['erklaerung'] ?? false) === true
                ? $svc->erklaere($team, $frame, $gaeste)
                : $svc->assembliere($team, $frame, $gaeste);
        } catch (\RuntimeException $e) {
            return ToolResult::error($e->getMessage(), 'VALIDATION_ERROR');
        }

        return ToolResult::success($ergebnis + [
            'frame_id' => $frame->id,
            'hinweis' => 'Vorschau — nichts gespeichert. Übernahme: foodalchemist.assemblierung.APPLY '
                . '(mit erwartetes_db_pp=' . number_format((float) $ergebnis['zielfunktion']['db_pp'], 2, '.', '') . ' als Riegel gegen einen zwischenzeitlich bewegten Bestand).',
        ]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'query',
            'tags' => ['foodalchemist', 'assemblierung', 'menue', 'solver', 'marge', 'db', 'konzept', 'planungsgeruest', 'menu', 'optimization'],
            'read_only' => true, 'idempotent' => true, 'risk_level' => 'safe',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'related_tools' => ['foodalchemist.assemblierung.APPLY', 'foodalchemist.planning.GET', 'foodalchemist.coverage.GET', 'foodalchemist.concepts.GENERATE'],
            'examples' => [
                'Stelle mir aus dem Gerüst von Foodbook 12 das margenstärkste Menü zusammen',
                'Assemblierung für Konzept 42 bei 120 Gästen — welche Vorgabe bindet?',
            ],
        ];
    }
}
