<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplier;
use Platform\FoodAlchemist\Services\MatchService;

/** MCP-Steuerbarkeit · D4: LA→GP-Matching für alle Artikel eines team-eigenen Lieferanten anstoßen (Vorschläge). */
class MatchRunTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.match.RUN';
    }

    public function getDescription(): string
    {
        return 'Startet das LA→GP-Matching für die Artikel eines team-eigenen Lieferanten. Erzeugt Match-Vorschläge '
            . '(übernehmen/verwerfen via match_proposals.PUT). Liefert eine Statistik.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => ['supplier_id' => ['type' => 'integer', 'description' => 'Lieferanten-Id (team-eigen).']],
            'required' => ['supplier_id'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        $supplierId = (int) ($arguments['supplier_id'] ?? 0);
        if (($guard = $this->guardOwned($team, FoodAlchemistSupplier::class, $supplierId, 'Lieferant')) !== null) {
            return $guard;
        }

        try {
            $stats = app(MatchService::class)->bulkFuerLieferant($team, $supplierId);
        } catch (\RuntimeException $e) {
            return ToolResult::error($e->getMessage(), 'VALIDATION_ERROR');
        }

        return ToolResult::success(['supplier_id' => $supplierId, 'stats' => $stats]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['foodalchemist', 'matching', 'la', 'gp', 'write'],
            'read_only' => false, 'idempotent' => false, 'risk_level' => 'write',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'side_effects' => ['creates'],
            'related_tools' => ['foodalchemist.match_proposals.PUT', 'foodalchemist.gps.MATCH'],
            'examples' => ['Starte das Matching für Lieferant 12.'],
        ];
    }
}
