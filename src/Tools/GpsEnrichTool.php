<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\BulkEnrichService;
use Platform\FoodAlchemist\Services\GpService;

/**
 * MCP-Steuerbarkeit · D1: KI-Anreicherung eines team-eigenen GP anstoßen (Zustand/Tags/Allergene/Nährwerte).
 *
 * Startet einen Bulk-Anreicherungs-Lauf (asynchroner Job) → erzeugt Vorschläge, schreibt NICHTS direkt
 * (GL-07: Vorschlag bis Freigabe). Übernehmen/Verwerfen nach dem Lauf über gp_enrich.RESOLVE. Nur
 * team-eigene GPs — die Freigabe schreibt nur eigene Datensätze.
 */
class GpsEnrichTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    /** Standard-Lauf = BulkEnrichService::SCHRITTE_GP; allergene/naehrwerte nur auf ausdrückliche Wahl (LA-lose GPs). */
    private const FELDER = [...BulkEnrichService::SCHRITTE_GP, ...BulkEnrichService::SCHRITTE_GP_EXPLIZIT];

    public function getName(): string
    {
        return 'foodalchemist.gps.ENRICH';
    }

    public function getDescription(): string
    {
        return 'Stößt die KI-Anreicherung eines team-eigenen GP an. Standard-Schrittfolge (ohne felder): '
            . 'condition (Zustand §9), tags (Eigenschafts-Tags), anker (Kern-Anker = Aroma-Identität, macht den GP im '
            . 'Pairing-Graph sichtbar; „neutral" = kein Aroma-Träger). Nach dem LA-Match und nach gps.MINT_FROM_LA ist '
            . 'das der Schritt, der ein neues GP vollständig macht. NICHT im Standard: allergene und naehrwerte — '
            . 'Allergene, Nährwerte und Zusatzstoffe kommen aus den Lieferantenartikeln, nicht aus der KI; die beiden '
            . 'Felder nur ausdrücklich wählen, wenn das GP keinen LA hat (KI-Schätzung, Override-Ebene). '
            . 'Liefert eine run_id; die Vorschläge werden nach dem Lauf über gp_enrich.RESOLVE übernommen/verworfen '
            . '(nichts wird direkt geschrieben).';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'gp_id' => ['type' => 'integer', 'description' => 'GP-Id (team-eigen).'],
                'felder' => [
                    'type' => 'array',
                    'items' => ['type' => 'string', 'enum' => self::FELDER],
                    'description' => 'Optional: Teilmenge der Anreicherungs-Felder. Weggelassen = Standard (condition, tags, anker); allergene/naehrwerte nur explizit.',
                ],
            ],
            'required' => ['gp_id'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }

        $gp = app(GpService::class)->find((int) ($arguments['gp_id'] ?? 0), $team);
        if ($gp === null) {
            return ToolResult::error('GP nicht sichtbar/vorhanden.', 'NOT_FOUND');
        }
        if (! $gp->isOwnedBy($team)) {
            return ToolResult::error('Anreicherung nur fürs Besitzer-Team (Freigabe schreibt nur Eigenes).', 'ACCESS_DENIED');
        }

        $svc = app(BulkEnrichService::class);
        $felder = $arguments['felder'] ?? null;
        if (is_array($felder) && $felder !== []) {
            $ungueltig = array_values(array_diff($felder, self::FELDER));
            if ($ungueltig !== []) {
                return ToolResult::error('Unbekannte felder: ' . implode(', ', $ungueltig) . '. Erlaubt: ' . implode(', ', self::FELDER), 'VALIDATION_ERROR');
            }
            $runId = $svc->starteGp($team, [(int) $gp->id], array_values($felder));
        } else {
            $runId = $svc->starteGp($team, [(int) $gp->id]);
        }

        return ToolResult::success([
            'run_id' => (int) $runId,
            'gp_id' => (int) $gp->id,
            'status' => 'queued',
            'hinweis' => 'Vorschläge nach dem Lauf mit gp_enrich.RESOLVE übernehmen/verwerfen.',
        ]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['foodalchemist', 'gp', 'anreicherung', 'ki', 'bulk', 'write'],
            'read_only' => false, 'idempotent' => false, 'risk_level' => 'write',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'llm',
            'side_effects' => ['creates'],
            'related_tools' => ['foodalchemist.gp_enrich.RESOLVE', 'foodalchemist.runs.GET'],
            'examples' => ['Reichere GP 123 an (Zustand + Tags + Kern-Anker).', 'GP 456 ohne LA: felder ["allergene"] — KI-Schätzung, weil kein Lieferantenartikel.'],
        ];
    }
}
