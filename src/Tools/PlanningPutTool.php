<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\PlanningFrameService;

/**
 * R4.1: Planungs-Gerüst setzen — die KI übersetzt ein Kunden-Brief in einen
 * messbaren Soll-Rahmen (ein Call: Kopf + Slots + Regeln deklarativ).
 * Neu angelegte Gerüste: status=draft + created_via='mcp_tool' (Lineage-Regel);
 * Freigabe (status=aktiv) bleibt menschlich.
 */
class PlanningPutTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.planning.PUT';
    }

    public function getDescription(): string
    {
        return 'Setzt das Planungs-Gerüst eines Foodbooks/Konzepts (legt es bei Bedarf als draft an). '
            . 'head = Preisarchitektur p. P. (target_price_pp, price_min_pp, price_max_pp, note). '
            . 'slots (ERSETZT alle Slots, Reihenfolge = Dramaturgie): {label*, slot_type gang|station|kapitel, target_count, '
            . 'price_anchor, price_min, price_max, is_pflicht, rules[]}. '
            . 'rules (ERSETZT Frame-Regeln): {rule_type* diet_quota|season_coverage|nogo_ingredient|nogo_allergen|allergen_line, '
            . 'ref_key (diet_form fleisch|fisch|vegi|vegan|neutral|allergie bzw. EU-14-Allergen-Key), ref_id (season_id), '
            . 'operator min|max|exact, value_num, unit count|percent, value_text, severity hart|weich}. '
            . 'Nur übergebene Teile werden angefasst — head/slots/rules sind einzeln optional. '
            . 'status=aktiv setzt NUR der Mensch (UI) — hier nicht erlaubt.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'owner_type' => ['type' => 'string', 'enum' => ['foodbook', 'concept']],
                'owner_id' => ['type' => 'integer'],
                'head' => ['type' => 'object', 'description' => 'target_price_pp, price_min_pp, price_max_pp, note (leer löscht)'],
                'slots' => ['type' => 'array', 'items' => ['type' => 'object'], 'description' => 'Ersetzt ALLE Slots (Reihenfolge = Dramaturgie); Slot-Regeln als rules[] im Slot'],
                'rules' => ['type' => 'array', 'items' => ['type' => 'object'], 'description' => 'Ersetzt alle Frame-Regeln (slot-unabhängige Quoten/Politik)'],
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
        if (array_key_exists('status', (array) ($arguments['head'] ?? []))) {
            return ToolResult::error('status wird nicht über MCP gesetzt — Freigabe bleibt menschlich (UI).', 'VALIDATION_ERROR');
        }

        $svc = app(PlanningFrameService::class);

        try {
            $frame = $svc->frameFor($team, (string) $arguments['owner_type'], (int) $arguments['owner_id'], 'mcp_tool');
            if (isset($arguments['head'])) {
                $frame = $svc->setHead($team, $frame, (array) $arguments['head']);
            }
            $frame = $svc->replaceStructure(
                $team,
                $frame,
                isset($arguments['slots']) ? (array) $arguments['slots'] : null,
                isset($arguments['rules']) ? (array) $arguments['rules'] : null,
            );
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            return ToolResult::error('Owner nicht gefunden oder nicht team-sichtbar.', 'NOT_FOUND');
        } catch (\RuntimeException $e) {
            return ToolResult::error($e->getMessage(), 'VALIDATION_ERROR');
        }

        return ToolResult::success([
            'geruest' => $svc->summary($frame),
            'prompt_kontext' => $svc->promptKontext($frame),
        ]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['foodalchemist', 'planung', 'geruest', 'soll', 'brief', 'update'],
            'read_only' => false, 'idempotent' => true, 'risk_level' => 'write',
            'requires_auth' => true, 'requires_team' => true,
            'side_effects' => ['creates', 'updates', 'deletes'], 'cost_class' => 'local_db',
            'related_tools' => ['foodalchemist.planning.GET', 'foodalchemist.canvas.PUT'],
            'examples' => ['Übersetze dieses Kunden-Brief in ein Planungs-Gerüst für Foodbook 12', 'Setze Zielpreis 45 € p. P. und 3 Gänge mit je 4 Gerichten für Konzept 7'],
        ];
    }
}
