<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\ConceptGeneratorService;
use Platform\FoodAlchemist\Services\PlanningFrameService;

/**
 * 12·S2b (R2.4) — die **explizite** Übernahme einer Assemblierung in ein Draft-Konzept.
 * Bewusst ein eigenes Tool neben `assemblierung.POST` (read-only): kein Auto-Commit,
 * kein schreibender Seiteneffekt an einem Werkzeug, das sich als lesend ausgibt.
 *
 * Ergebnis IMMER `status=draft` + `created_via=menu_assembly_mcp` — die Freigabe bleibt
 * menschlich. Schreibt in ein leeres Draft-Konzept oder legt eines an; ein befülltes
 * Konzept wird abgelehnt statt überschrieben.
 */
class AssemblierungApplyTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.assemblierung.APPLY';
    }

    public function getDescription(): string
    {
        return 'Übernimmt die marge-optimale Assemblierung (foodalchemist.assemblierung.POST) als Positionen in ein '
            . 'Draft-Konzept: owner_type=foodbook|concept + owner_id benennen das Planungs-Gerüst. Ohne concept_id '
            . 'entsteht ein NEUES Konzept (status=draft), mit concept_id wird ein LEERES Draft-Konzept befüllt — ein '
            . 'Konzept mit Positionen wird abgelehnt, nichts wird überschrieben. erwartetes_db_pp (aus der Vorschau) '
            . 'wirkt als Riegel: hat sich der Bestand zwischenzeitlich bewegt, bricht die Übernahme ab statt ein '
            . 'anderes Menü zu schreiben. Leere Slots werden mit Begründung angelegt (nie erfundene Gerichte). '
            . 'Übernommen wird das Solver-Ergebnis inklusive Slot-Rollen-Ebene (Hauptgruppe passt zum Slot, '
            . 'lexikografisch vor dem DB) — bleibt ein Rollen-Bruch stehen, steht er in der Vorschau. '
            . 'Liefert Konzept-ID, Protokoll, Kohäsion und R4.2-Coverage. Freigabe bleibt menschlich.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'owner_type' => ['type' => 'string', 'enum' => ['foodbook', 'concept'], 'description' => 'Owner des Planungs-Gerüsts'],
                'owner_id' => ['type' => 'integer', 'description' => 'Foodbook- bzw. Konzept-ID'],
                'concept_id' => ['type' => 'integer', 'description' => 'Ziel-Konzept (muss draft und ohne Positionen sein). Weglassen = neues Draft-Konzept anlegen.'],
                'name' => ['type' => 'string', 'description' => 'Name des neuen Konzepts (nur ohne concept_id)'],
                'gaeste' => ['type' => 'integer', 'description' => 'Gästezahl — skaliert NUR die Ausgabe, nicht die Auswahl'],
                'erwartetes_db_pp' => ['type' => 'number', 'description' => 'DB p. P. aus der Vorschau; Abweichung > 0,01 € bricht ab (Gegenzeichnungs-Riegel)'],
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

        try {
            $ergebnis = app(ConceptGeneratorService::class)->uebernehmeAssemblierung(
                $team,
                $frame,
                isset($arguments['concept_id']) ? (int) $arguments['concept_id'] : null,
                isset($arguments['name']) && is_string($arguments['name']) ? $arguments['name'] : null,
                $gaeste,
                isset($arguments['erwartetes_db_pp']) ? (float) $arguments['erwartetes_db_pp'] : null,
                'mcp'
            );
        } catch (\RuntimeException $e) {
            return ToolResult::error($e->getMessage(), 'VALIDATION_ERROR');
        }

        $a = $ergebnis['assemblierung'];

        return ToolResult::success([
            'concept_id' => $ergebnis['concept']->id,
            'name' => $ergebnis['concept']->name,
            'status' => $ergebnis['concept']->status,
            'created_via' => $ergebnis['concept']->created_via,
            'verfahren' => $a['verfahren'],
            'exakt' => $a['exakt'],
            'zielfunktion' => $a['zielfunktion'],
            'db_gesamt_gaeste' => $a['db_gesamt_gaeste'],
            'unvollstaendig' => $a['unvollstaendig'],
            'protokoll' => $ergebnis['protokoll'],
            'kohaesion' => [
                'score' => $ergebnis['kohaesion']['score'] ?? null,
                'coverage_pct' => $ergebnis['kohaesion']['coverage_pct'] ?? null,
                'weakest_pair' => $ergebnis['kohaesion']['weakest_pair'] ?? null,
            ],
            'coverage' => [
                'ampel_gesamt' => $ergebnis['coverage']['ampel_gesamt'] ?? null,
                'zusammenfassung' => $ergebnis['coverage']['zusammenfassung'] ?? null,
            ],
            'hinweis' => 'Konzept ist ein ENTWURF — Freigabe bleibt menschlich.',
        ]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['foodalchemist', 'assemblierung', 'uebernahme', 'menue', 'konzept', 'draft', 'anlegen', 'menu', 'apply'],
            'read_only' => false, 'idempotent' => false, 'risk_level' => 'write',
            'requires_auth' => true, 'requires_team' => true,
            'side_effects' => ['creates'], 'cost_class' => 'local_db',
            'related_tools' => ['foodalchemist.assemblierung.POST', 'foodalchemist.concepts.GENERATE', 'foodalchemist.coverage.GET'],
            'examples' => [
                'Übernimm die Assemblierung aus dem Gerüst von Foodbook 12 als neues Konzept',
                'Befülle Konzept 88 mit der margenoptimalen Zusammenstellung (erwartetes_db_pp 39.25)',
            ],
        ];
    }
}
