<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Services\PairingService;

/**
 * R6.11 · S1: Hypothesen-Modus (read-only). „Paar mir X ungewöhnlich" — rankt
 * Kandidaten-Anker nach geteilten Aroma-/Molekül-Compound-Klassen (mit Mechanismus
 * + Evidenz-Stufe T3 = Hypothese, nie Fakt). Quelle = gp_id (sichtbar) ODER anchor
 * (Slug/Name). Kein Schreibpfad — Übernahme als Draft/Lab-Notiz ist S3.
 */
class KnowledgeHypothesizeTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.knowledge.HYPOTHESIZE';
    }

    public function getDescription(): string
    {
        return 'Hypothesen-Modus (R&D, read-only): schlägt zu einem Grundprodukt (gp_id) oder '
            . 'Aroma-Anker (anchor = Slug/Name) ungewöhnliche Pairing-Kandidaten vor. mode="harmonie" '
            . '(Default) rankt nach GETEILTEN Compound-Klassen (Aroma-Verwandtschaft); mode="kontrast" '
            . 'rankt nach Geschmacks-GEGENSATZ (Spannung: Fett↔Säure, Süß↔Bitter …) + liefert die '
            . 'kuratierten kontrast-Kanten. Jede Zeile ist Hypothese (Evidenz-Stufe T3), kein Fakt; '
            . 'ist_etabliert=true = im Graph bereits bekannt. gp_id vorher per foodalchemist.gps.SEARCH.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'gp_id' => ['type' => 'integer', 'description' => 'Quell-Grundprodukt (sichtbar im Team)'],
                'anchor' => ['type' => 'string', 'description' => 'Alternativ: Aroma-Anker als Slug oder Name (z. B. "erdbeere")'],
                'mode' => ['type' => 'string', 'enum' => ['harmonie', 'kontrast'], 'default' => 'harmonie', 'description' => 'harmonie = geteilte Aromen; kontrast = Geschmacks-Gegensatz'],
                'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 50, 'default' => 12],
            ],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        $svc = app(PairingService::class);
        $limit = min(50, max(1, (int) ($arguments['limit'] ?? 12)));

        if (isset($arguments['gp_id'])) {
            $gp = FoodAlchemistGp::visibleToTeam($team)->whereKey((int) $arguments['gp_id'])->first(['id']);
            if ($gp === null) {
                return ToolResult::error('Grundprodukt nicht sichtbar/vorhanden.', 'NOT_FOUND');
            }
            $source = ['gp' => (int) $gp->id];
        } elseif (! empty($arguments['anchor'])) {
            $anchorId = $svc->resolveByName((string) $arguments['anchor']);
            if ($anchorId === null) {
                return ToolResult::error('Aroma-Anker nicht auflösbar: ' . $arguments['anchor'], 'NOT_FOUND');
            }
            $source = ['anchor' => $anchorId];
        } else {
            return ToolResult::error('gp_id oder anchor angeben.', 'BAD_INPUT');
        }

        $mode = ($arguments['mode'] ?? 'harmonie') === 'kontrast' ? 'kontrast' : 'harmonie';

        return ToolResult::success($mode === 'kontrast'
            ? $svc->contrastHypothesesFor($source, $limit)
            : $svc->hypothesizeFor($source, $limit));
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'query',
            'tags' => ['foodalchemist', 'pairing', 'hypothese', 'r&d', 'compound', 'aroma', 'forschung'],
            'read_only' => true,
            'idempotent' => true,
            'risk_level' => 'safe',
            'requires_auth' => true,
            'requires_team' => true,
            'cost_class' => 'local_db',
            'related_tools' => ['foodalchemist.pairings.SUGGEST', 'foodalchemist.gps.SEARCH'],
            'examples' => ['Paar mir Erdbeere ungewöhnlich', 'Welche ungewöhnlichen Partner hat GP 123 über geteilte Aroma-Klassen?'],
        ];
    }
}
