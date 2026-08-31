<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Models\FoodAlchemistOutlet;
use Platform\FoodAlchemist\Services\OutletSettingsService;

/**
 * Ebene 2: Kalkulations-Overrides EINES Betriebs (Outlet-Ebene) schreiben — Gegenstück zu
 * team_settings.PUT, aber je Betrieb. Kaskade Outlet→Team→Default: ein NULL-Feld setzt zurück
 * auf „erbt vom Team". Preisklassen bleiben team-geteilt (hier NICHT setzbar). Nur eigene Betriebe.
 */
class OutletSettingsPutTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    /** Setzbare Skalare (float ≥ 0 ODER null = zurück auf Team-Erbe). */
    private const NUM_KEYS = ['margin_pct', 'target_food_cost_pct', 'stundensatz_eur', 'hk2_surcharge_pct', 'labor_overhead_pct'];

    /** Enum-Felder: labor_cost_source ∈ {team_flat, station_roles} ODER null = Team-Erbe. */
    private const STR_KEYS = ['labor_cost_source'];

    /** JSON-Felder: calculation_schema (Blockliste), calculation_reference_bases ({mek,fek,hk}), outlet_role_rates ({role_id:€/Std}); null = Team-Erbe. */
    private const JSON_KEYS = ['calculation_schema', 'calculation_reference_bases', 'outlet_role_rates'];

    private const LABOR_SOURCES = ['team_flat', 'station_roles'];

    public function getName(): string
    {
        return 'foodalchemist.outlet_settings.PUT';
    }

    public function getDescription(): string
    {
        return 'Schreibt die Kalkulations-Overrides EINES Betriebs (Outlet): Marge, Ziel-Wareneinsatz, '
            . 'Stundensatz, Material-GK-/Lohnnebenkosten-Zuschlag, Lohnquelle (labor_cost_source), eigenes '
            . 'Zuschlagsschema (calculation_schema) + Bezugsbasen. NULL setzt ein Feld zurück auf „erbt vom Team". '
            . 'Preisklassen bleiben team-geteilt (nicht setzbar). Nur eigene Betriebe. Idempotent (PUT). '
            . 'Betrieb-IDs via outlets.GET; VK-Wirkung prüfen via kalkulation.GET(outlet_id).';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'outlet_id' => ['type' => 'integer', 'description' => 'ID des Betriebs (outlets.GET / outlets.POST).'],
                'settings' => [
                    'type' => 'object',
                    'description' => 'Override-Felder; Wert null setzt zurück auf Team-Erbe. Nur diese Keys erlaubt.',
                    'properties' => [
                        'margin_pct' => ['type' => ['number', 'null'], 'description' => 'Marge % auf HK → VK.'],
                        'target_food_cost_pct' => ['type' => ['number', 'null'], 'description' => 'Ziel-Wareneinsatzquote %.'],
                        'stundensatz_eur' => ['type' => ['number', 'null'], 'description' => 'Lohnsatz €/h.'],
                        'hk2_surcharge_pct' => ['type' => ['number', 'null'], 'description' => 'Material-GK-Zuschlag %.'],
                        'labor_overhead_pct' => ['type' => ['number', 'null'], 'description' => 'Lohnnebenkosten-Zuschlag %.'],
                        'labor_cost_source' => ['type' => ['string', 'null'], 'enum' => ['team_flat', 'station_roles', null], 'description' => 'Lohnquelle: team_flat|station_roles. null = erbt vom Team.'],
                        'calculation_schema' => ['type' => ['array', 'null'], 'description' => 'Ganzes Zuschlagsschema (Blockliste) ersetzt das Team-Schema; null = erbt.'],
                        'calculation_reference_bases' => ['type' => ['object', 'null'], 'description' => 'Monats-Bezugsbasen {mek,fek,hk}; null = erbt vom Team.'],
                        'outlet_role_rates' => ['type' => ['object', 'null'], 'description' => 'Küchen-Rollen-Sätze je Betrieb als {kitchen_role_id: €/Std}; null oder fehlender Key = erbt den Team-Rollensatz.'],
                    ],
                    'additionalProperties' => false,
                ],
            ],
            'required' => ['outlet_id', 'settings'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        $outlet = FoodAlchemistOutlet::where('team_id', $team->id)->find((int) ($arguments['outlet_id'] ?? 0));
        if ($outlet === null) {
            return ToolResult::error('Betrieb nicht gefunden im Team.', 'NOT_FOUND');
        }
        $settings = $arguments['settings'] ?? null;
        if (! is_array($settings) || $settings === []) {
            return ToolResult::error('settings muss ein nicht-leeres Objekt sein.', 'VALIDATION_ERROR');
        }
        $erlaubt = array_merge(self::NUM_KEYS, self::STR_KEYS, self::JSON_KEYS);
        $unbekannt = array_values(array_diff(array_keys($settings), $erlaubt));
        if ($unbekannt !== []) {
            return ToolResult::error('Unbekannte/nicht erlaubte Keys: ' . implode(', ', $unbekannt) . '. Erlaubt: ' . implode(', ', $erlaubt), 'VALIDATION_ERROR');
        }

        $clean = [];
        foreach ($settings as $key => $wert) {
            if ($wert === null) {
                $clean[$key] = null;
                continue;
            }
            if (in_array($key, self::STR_KEYS, true)) {
                if (! in_array($wert, self::LABOR_SOURCES, true)) {
                    return ToolResult::error("Feld {$key} muss eines von " . implode('|', self::LABOR_SOURCES) . ' oder null sein.', 'VALIDATION_ERROR');
                }
                $clean[$key] = $wert;
                continue;
            }
            if ($key === 'calculation_schema') {
                if (! is_array($wert) || ! array_is_list($wert)) {
                    return ToolResult::error('calculation_schema muss eine Liste von Block-Objekten oder null sein.', 'VALIDATION_ERROR');
                }
                $clean[$key] = $wert;
                continue;
            }
            if ($key === 'calculation_reference_bases') {
                if (! is_array($wert)) {
                    return ToolResult::error('calculation_reference_bases muss ein Objekt {mek,fek,hk} oder null sein.', 'VALIDATION_ERROR');
                }
                $clean[$key] = [
                    'mek' => (float) ($wert['mek'] ?? 0),
                    'fek' => (float) ($wert['fek'] ?? 0),
                    'hk' => (float) ($wert['hk'] ?? 0),
                ];
                continue;
            }
            if ($key === 'outlet_role_rates') {
                if (! is_array($wert)) {
                    return ToolResult::error('outlet_role_rates muss ein Objekt {kitchen_role_id: €/Std} oder null sein.', 'VALIDATION_ERROR');
                }
                $map = [];
                foreach ($wert as $roleId => $satz) {
                    if (! is_numeric($roleId) || ! is_numeric($satz) || (float) $satz < 0) {
                        return ToolResult::error('outlet_role_rates: Keys = Rollen-IDs, Werte = Zahl ≥ 0.', 'VALIDATION_ERROR');
                    }
                    $map[(string) (int) $roleId] = (float) $satz;
                }
                $clean[$key] = $map === [] ? null : $map;
                continue;
            }
            if (! is_numeric($wert) || (float) $wert < 0) {
                return ToolResult::error("Feld {$key} muss eine Zahl ≥ 0 oder null sein.", 'VALIDATION_ERROR');
            }
            $clean[$key] = (float) $wert;
        }

        $row = app(OutletSettingsService::class)->update($team, $outlet, $clean);

        return ToolResult::success([
            'outlet_id' => (int) $outlet->id,
            'updated' => array_keys($clean),
            'settings' => $clean,
            'setting_id' => (int) $row->id,
        ]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['foodalchemist', 'betrieb', 'outlet', 'settings', 'kalkulation', 'write'],
            'read_only' => false, 'idempotent' => true, 'risk_level' => 'write',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'side_effects' => ['updates'],
            'related_tools' => ['foodalchemist.outlets.GET', 'foodalchemist.outlets.POST', 'foodalchemist.kalkulation.GET', 'foodalchemist.team_settings.PUT'],
            'examples' => ['Setze für Betrieb 3 die Marge auf 45 % und den Ziel-Wareneinsatz auf 22 %.'],
        ];
    }
}
