<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Services\GpNamingService;

/**
 * MCP-Steuerbarkeit · D1: Grundprodukt anlegen (Regelwerk_Grundprodukte §6-Naming).
 *
 * Legt team-eigen an (status=tentative). Der Name wird aus den Strukturfeldern gerendert,
 * wenn kein `name` übergeben ist, und gegen das Regelwerk validiert (§7.1 Verpackungswörter,
 * §9 Zustands-Vokabular). Der Anlage-Guard (gp_key-UNIQUE + Namens-Jaccard) blockt Dubletten
 * hart — `force=true` legt bewusst trotzdem an (~n-Suffix). `derivat_von_gp_id` wird team-scoped
 * re-autorisiert.
 */
class GpsPostTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    private const IN_KEYS = [
        'name', 'hauptzutat', 'condition', 'processing', 'form', 'portion', 'pflichtangabe',
        'commodity_group_code', 'sub_category', 'is_derivat', 'derivat_von_gp_id',
    ];

    public function getName(): string
    {
        return 'foodalchemist.gps.POST';
    }

    public function getDescription(): string
    {
        return 'Legt ein Grundprodukt an (team-eigen, status=tentative), Name nach Regelwerk_Grundprodukte §6. '
            . 'Entweder `name` direkt oder Strukturfelder (hauptzutat + condition/processing/form/…) zum Rendern. '
            . 'hauptzutat ist Pflicht. Dubletten werden hart geblockt — force=true legt bewusst trotzdem an. '
            . 'Für den Zutaten→GP-Sourcing-Backlog stattdessen gps.MATCH/gp_proposals.POST nutzen.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'name' => ['type' => 'string', 'description' => 'Fertiger GP-Name (optional; sonst aus Strukturfeldern gerendert).'],
                'hauptzutat' => ['type' => 'string', 'description' => 'Hauptzutat (Pflicht) — Basis des Namens + main_ingredient_slug.'],
                'condition' => ['type' => 'string', 'description' => 'Zustand (§9): frisch|TK|trocken|konserviert.'],
                'processing' => ['type' => 'string', 'description' => 'Verarbeitung/Zuschnitt (z.B. Wuerfel 10 mm).'],
                'form' => ['type' => 'string', 'description' => 'Form (falls keine processing).'],
                'portion' => ['type' => 'string', 'description' => 'Portionsgrammatur pro Stück (optional).'],
                'pflichtangabe' => ['type' => 'string', 'description' => 'Kategorie-Pflichtangabe (§8), z.B. Kochtyp/Käseart.'],
                'commodity_group_code' => ['type' => 'string', 'description' => 'Warengruppen-Code (§3).'],
                'sub_category' => ['type' => 'string', 'description' => 'Sub-Kategorie.'],
                'is_derivat' => ['type' => 'boolean', 'description' => 'Nebenprodukt-Derivat (§11.2) — requires_la wird dann 0.'],
                'derivat_von_gp_id' => ['type' => 'integer', 'description' => 'Mutter-GP bei Derivat (team-scoped geprüft).'],
                'force' => ['type' => 'boolean', 'description' => 'Dubletten-Hard-Stop bewusst übergehen.'],
            ],
            'required' => ['hauptzutat'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }

        if (trim((string) ($arguments['hauptzutat'] ?? '')) === '') {
            return ToolResult::error('hauptzutat ist Pflicht.', 'VALIDATION_ERROR');
        }

        $derivatVon = $arguments['derivat_von_gp_id'] ?? null;
        if ($derivatVon !== null && $derivatVon !== '') {
            if (! FoodAlchemistGp::visibleToTeam($team)->whereKey((int) $derivatVon)->exists()) {
                return ToolResult::error('derivat_von_gp_id nicht sichtbar/vorhanden.', 'NOT_FOUND');
            }
        }

        $in = array_intersect_key($arguments, array_flip(self::IN_KEYS));

        try {
            $gp = app(GpNamingService::class)->createGp($team, $in, (bool) ($arguments['force'] ?? false));
        } catch (\RuntimeException $e) {
            return ToolResult::error($e->getMessage(), 'VALIDATION_ERROR');
        }

        return ToolResult::success([
            'id' => (int) $gp->id,
            'gp_key' => $gp->gp_key,
            'name' => $gp->name,
            'status' => $gp->status instanceof \BackedEnum ? $gp->status->value : (string) $gp->status,
            'is_derivat' => (bool) $gp->is_derivat,
            'requires_la' => (bool) $gp->requires_la,
        ]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['foodalchemist', 'gp', 'grundprodukt', 'anlegen', 'katalog', 'write'],
            'read_only' => false, 'idempotent' => false, 'risk_level' => 'write',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'side_effects' => ['creates'],
            'related_tools' => ['foodalchemist.gps.PUT', 'foodalchemist.gps.MATCH', 'foodalchemist.gp_proposals.POST'],
            'examples' => ['Lege ein GP „Zanderfilet: frisch" an.'],
        ];
    }
}
