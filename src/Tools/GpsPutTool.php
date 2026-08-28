<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Services\GpNamingService;
use Platform\FoodAlchemist\Services\GpService;

/**
 * MCP-Steuerbarkeit · D1: Grundprodukt bearbeiten (Strukturfelder, §6-Naming).
 *
 * Nur team-eigene GPs sind editierbar (Web-Pendant `Curate::canCurate` = team_id == current) —
 * globale/geerbte Katalog-GPs sind read-only (ACCESS_DENIED). `gp_key` wird nicht umgeschrieben
 * (Seed-Stabilität). Name wird aus `name` übernommen oder — wenn leer — aus den Strukturfeldern
 * neu gerendert; darum ist bei leerem `name` `hauptzutat` nötig, sonst würde der Name falsch neu
 * gerendert. `derivat_von_gp_id` wird team-scoped re-autorisiert.
 */
class GpsPutTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    private const IN_KEYS = [
        'name', 'hauptzutat', 'condition', 'processing', 'form', 'portion', 'pflichtangabe',
        'commodity_group_code', 'sub_category', 'is_derivat', 'derivat_von_gp_id',
    ];

    public function getName(): string
    {
        return 'foodalchemist.gps.PUT';
    }

    public function getDescription(): string
    {
        return 'Bearbeitet ein team-eigenes Grundprodukt über die Strukturfelder (Regelwerk §6). '
            . 'Bei leerem `name` wird der Name aus den Feldern neu gerendert — dann `hauptzutat` mitgeben. '
            . 'Globale/geerbte GPs sind read-only. Status ändern → gps.STATUS.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'id' => ['type' => 'integer', 'description' => 'GP-Id (team-eigen).'],
                'name' => ['type' => 'string', 'description' => 'Fertiger GP-Name (optional; sonst aus Strukturfeldern gerendert — dann hauptzutat nötig).'],
                'hauptzutat' => ['type' => 'string', 'description' => 'Hauptzutat (nötig, wenn name leer bleibt).'],
                'condition' => ['type' => 'string', 'description' => 'Zustand (§9): frisch|TK|trocken|konserviert.'],
                'processing' => ['type' => 'string', 'description' => 'Verarbeitung/Zuschnitt.'],
                'form' => ['type' => 'string', 'description' => 'Form.'],
                'portion' => ['type' => 'string', 'description' => 'Portionsgrammatur pro Stück.'],
                'pflichtangabe' => ['type' => 'string', 'description' => 'Kategorie-Pflichtangabe (§8).'],
                'commodity_group_code' => ['type' => 'string', 'description' => 'Warengruppen-Code (§3).'],
                'sub_category' => ['type' => 'string', 'description' => 'Sub-Kategorie.'],
                'is_derivat' => ['type' => 'boolean', 'description' => 'Nebenprodukt-Derivat (§11.2).'],
                'derivat_von_gp_id' => ['type' => 'integer', 'description' => 'Mutter-GP bei Derivat (team-scoped geprüft).'],
            ],
            'required' => ['id'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }

        $gp = app(GpService::class)->find((int) ($arguments['id'] ?? 0), $team);
        if ($gp === null) {
            return ToolResult::error('GP nicht sichtbar/vorhanden.', 'NOT_FOUND');
        }
        if (! $gp->isOwnedBy($team)) {
            return ToolResult::error('GP gehört einem anderen/globalen Team — nur eigene GPs editierbar.', 'ACCESS_DENIED');
        }

        if (trim((string) ($arguments['name'] ?? '')) === '' && trim((string) ($arguments['hauptzutat'] ?? '')) === '') {
            return ToolResult::error('Bei leerem name bitte hauptzutat mitgeben, sonst würde der Name falsch neu gerendert.', 'VALIDATION_ERROR');
        }

        $derivatVon = $arguments['derivat_von_gp_id'] ?? null;
        if ($derivatVon !== null && $derivatVon !== '') {
            if (! FoodAlchemistGp::visibleToTeam($team)->whereKey((int) $derivatVon)->exists()) {
                return ToolResult::error('derivat_von_gp_id nicht sichtbar/vorhanden.', 'NOT_FOUND');
            }
        }

        $in = array_intersect_key($arguments, array_flip(self::IN_KEYS));

        try {
            $gp = app(GpNamingService::class)->updateGp($team, $gp, $in);
        } catch (\RuntimeException $e) {
            return ToolResult::error($e->getMessage(), 'VALIDATION_ERROR');
        }

        return ToolResult::success([
            'id' => (int) $gp->id,
            'name' => $gp->name,
            'condition' => $gp->condition,
            'commodity_group_code' => $gp->commodity_group_code,
            'sub_category' => $gp->sub_category,
            'is_derivat' => (bool) $gp->is_derivat,
            'derivat_von_gp_id' => $gp->derivat_von_gp_id !== null ? (int) $gp->derivat_von_gp_id : null,
        ]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['foodalchemist', 'gp', 'grundprodukt', 'bearbeiten', 'katalog', 'write'],
            'read_only' => false, 'idempotent' => true, 'risk_level' => 'write',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'side_effects' => ['updates'],
            'related_tools' => ['foodalchemist.gps.POST', 'foodalchemist.gps.STATUS', 'foodalchemist.gps.GET'],
            'examples' => ['Ändere bei GP 123 den Zustand auf TK.'],
        ];
    }
}
