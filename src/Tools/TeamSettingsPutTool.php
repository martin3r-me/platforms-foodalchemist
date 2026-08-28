<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Models\FoodAlchemistMarkupClass;
use Platform\FoodAlchemist\Services\TeamSettingsService;

/**
 * MCP-Steuerbarkeit · Phase 0: Team-Einstellungen (Skalar-Config) schreiben.
 *
 * Gegenstück zu settings.GET. Deckt die „sichere Config"-Fläche der Einstellungs-Panels
 * ab (Kennzahlen/Küche/Kalkulation/Ki-Kill-Switch/Herstellkosten-Skalare/Topf-Deckel/
 * Trendradar/Einkauf-Journal). Schreibt IMMER nur die eigene Team-Zeile
 * (`TeamSettingsService::update` → firstOrNew(team_id)); es gibt hier keine fremden
 * Datensätze. Genau EINE FK — `default_markup_class_id` — wird team-scoped re-autorisiert.
 *
 * Bewusst NICHT hier (eigene, komplexere Fläche → spätere Tools): Vokabular-/Taxonomie-CRUD,
 * `calculation_schema`, `lead_la_*`, `vat_defaults`, `rundungsregeln`, `type_colors`,
 * `calculation_reference_bases`. Das Löschen/Umbenennen von Vokabular bleibt human-only.
 */
class TeamSettingsPutTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    /** Boolean-Schalter. */
    private const BOOL_KEYS = ['ai_active', 'show_fallback_chain', 'trend_auto_enabled', 'trend_signal_enabled'];

    /** Numerische Skalare (float, ≥ 0). */
    private const NUM_KEYS = [
        'target_food_cost_pct', 'stundensatz_eur', 'margin_pct', 'hk2_surcharge_pct', 'labor_overhead_pct',
        'price_alarm_threshold_pct', 'season_margin_band_min_pct', 'season_margin_band_max_pct',
        'max_vk_delta_pct', 'min_margin_pct', 'default_batch_max_kg', 'default_batch_max_pieces',
    ];

    /** Ganzzahlige Skalare (≥ 0). */
    private const INT_KEYS = ['trend_auto_limit'];

    /** Enum-Felder → erlaubte Werte. */
    private const ENUM_KEYS = [
        'labor_cost_source' => ['team_flat', 'station_roles'],
        'purchase_journal_trigger' => ['sent', 'delivered'],
    ];

    /** WG-Code → %-Verlust-Maps (assoziatives Array, Werte numerisch). */
    private const LOSS_MAP_KEYS = ['cooking_loss_defaults', 'trimming_loss_defaults'];

    public function getName(): string
    {
        return 'foodalchemist.team_settings.PUT';
    }

    public function getDescription(): string
    {
        return 'Schreibt Team-Einstellungen (nur eigene Team-Zeile): Ki-Kill-Switch, Küchen-Typ, '
            . 'Ziel-Wareneinsatz-%, Stundensatz/Marge/Zuschläge, Topf-Deckel-Defaults, Trendradar-Automatik, '
            . 'Einkaufsjournal-Trigger, Garverlust-/Putzverlust-Defaults, Standard-Preisklasse (team-scoped geprüft). '
            . 'Nur die im Schema gelisteten Keys sind erlaubt; unbekannte Keys werden abgewiesen. '
            . 'Idempotent (PUT). Vokabular/Taxonomie und komplexe JSON-Konfigs laufen über eigene Tools bzw. die UI.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'settings' => [
                    'type' => 'object',
                    'description' => 'Zu schreibende Einstellungen. Nur diese Keys sind erlaubt.',
                    'properties' => [
                        'ai_active' => ['type' => 'boolean', 'description' => 'KI-Kill-Switch (false stoppt alle KI-Calls des Teams).'],
                        'kitchen_type' => ['type' => 'string', 'description' => 'Küchen-Typ: restaurant|grosskueche|catering|hotel|boutique_patisserie.'],
                        'target_food_cost_pct' => ['type' => 'number', 'description' => 'Ziel-Wareneinsatzquote (%, gastro-üblich 28–35).'],
                        'stundensatz_eur' => ['type' => 'number', 'description' => 'Default-Lohnsatz €/h für den Arbeitszeit-Block.'],
                        'margin_pct' => ['type' => 'number', 'description' => 'Marge % auf die HK → VK-Vorschlag.'],
                        'hk2_surcharge_pct' => ['type' => 'number', 'description' => 'Material-Gemeinkosten-Zuschlag % auf den Wareneinsatz.'],
                        'labor_overhead_pct' => ['type' => 'number', 'description' => 'Lohnnebenkosten-Zuschlag % auf den Produktionslohn.'],
                        'labor_cost_source' => ['type' => 'string', 'description' => 'Lohnquelle: team_flat|station_roles.'],
                        'price_alarm_threshold_pct' => ['type' => 'number', 'description' => 'Preis-Alarm-Schwelle (relative LA-Preisänderung %).'],
                        'season_margin_band_min_pct' => ['type' => 'number', 'description' => 'Margen-Zielband Untergrenze %.'],
                        'season_margin_band_max_pct' => ['type' => 'number', 'description' => 'Margen-Zielband Obergrenze %.'],
                        'max_vk_delta_pct' => ['type' => 'number', 'description' => 'Max. VK-Delta % ggü. freigegebenem Snapshot.'],
                        'min_margin_pct' => ['type' => 'number', 'description' => 'Mindestmarge %.'],
                        'default_batch_max_kg' => ['type' => 'number', 'description' => 'Standard-Topf-Deckel je Koch-Vorgang (kg).'],
                        'default_batch_max_pieces' => ['type' => 'number', 'description' => 'Standard-Topf-Deckel je Koch-Vorgang (Stück).'],
                        'default_markup_class_id' => ['type' => 'integer', 'description' => 'ID der Standard-Aufschlagsklasse (team-scoped geprüft).'],
                        'show_fallback_chain' => ['type' => 'boolean', 'description' => 'Ausweich-Kette (Lead-LA) anzeigen.'],
                        'trend_auto_enabled' => ['type' => 'boolean', 'description' => 'Trendradar-Konzept-Automatik an/aus.'],
                        'trend_auto_limit' => ['type' => 'integer', 'description' => 'Anzahl Top-Trends je Automatik-Lauf.'],
                        'trend_signal_enabled' => ['type' => 'boolean', 'description' => 'Trend-Vorschlag als Signal in die Inbox.'],
                        'purchase_journal_trigger' => ['type' => 'string', 'description' => 'Einkaufsjournal-Buchung ab Status: sent|delivered.'],
                        'cooking_loss_defaults' => ['type' => 'object', 'description' => 'Garverlust-Default % je WG-Code ("*" = global). Werte numerisch.'],
                        'trimming_loss_defaults' => ['type' => 'object', 'description' => 'Putzverlust-Default % je WG-Code ("*" = global). Werte numerisch.'],
                    ],
                    'additionalProperties' => false,
                ],
            ],
            'required' => ['settings'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }

        $settings = $arguments['settings'] ?? null;
        if (! is_array($settings) || $settings === []) {
            return ToolResult::error('settings muss ein nicht-leeres Objekt sein.', 'VALIDATION_ERROR');
        }

        $erlaubt = array_merge(
            self::BOOL_KEYS,
            self::NUM_KEYS,
            self::INT_KEYS,
            array_keys(self::ENUM_KEYS),
            self::LOSS_MAP_KEYS,
            ['kitchen_type', 'default_markup_class_id'],
        );
        $unbekannt = array_values(array_diff(array_keys($settings), $erlaubt));
        if ($unbekannt !== []) {
            return ToolResult::error(
                'Unbekannte/nicht erlaubte Keys: ' . implode(', ', $unbekannt) . '. Erlaubt: ' . implode(', ', $erlaubt),
                'VALIDATION_ERROR'
            );
        }

        $clean = [];
        foreach ($settings as $key => $wert) {
            if (in_array($key, self::BOOL_KEYS, true)) {
                $clean[$key] = (bool) $wert;
            } elseif (in_array($key, self::NUM_KEYS, true)) {
                if (! is_numeric($wert) || (float) $wert < 0) {
                    return ToolResult::error("Feld {$key} muss eine Zahl ≥ 0 sein.", 'VALIDATION_ERROR');
                }
                $clean[$key] = (float) $wert;
            } elseif (in_array($key, self::INT_KEYS, true)) {
                if (! is_numeric($wert) || (int) $wert < 0) {
                    return ToolResult::error("Feld {$key} muss eine Ganzzahl ≥ 0 sein.", 'VALIDATION_ERROR');
                }
                $clean[$key] = (int) $wert;
            } elseif ($key === 'kitchen_type') {
                if (! array_key_exists((string) $wert, TeamSettingsService::KUECHEN_TYPEN)) {
                    return ToolResult::error('kitchen_type unbekannt. Erlaubt: ' . implode(', ', array_keys(TeamSettingsService::KUECHEN_TYPEN)), 'VALIDATION_ERROR');
                }
                $clean[$key] = (string) $wert;
            } elseif (isset(self::ENUM_KEYS[$key])) {
                if (! in_array((string) $wert, self::ENUM_KEYS[$key], true)) {
                    return ToolResult::error("Feld {$key} muss einer von: " . implode(', ', self::ENUM_KEYS[$key]) . ' sein.', 'VALIDATION_ERROR');
                }
                $clean[$key] = (string) $wert;
            } elseif (in_array($key, self::LOSS_MAP_KEYS, true)) {
                if (! is_array($wert)) {
                    return ToolResult::error("Feld {$key} muss ein Objekt {WG-Code: %} sein.", 'VALIDATION_ERROR');
                }
                $map = [];
                foreach ($wert as $wg => $pct) {
                    if (! is_numeric($pct)) {
                        return ToolResult::error("Feld {$key}: Wert für \"{$wg}\" muss numerisch sein.", 'VALIDATION_ERROR');
                    }
                    $map[(string) $wg] = (float) $pct;
                }
                $clean[$key] = $map;
            } elseif ($key === 'default_markup_class_id') {
                $id = (int) $wert;
                if ($id <= 0) {
                    return ToolResult::error('default_markup_class_id muss eine positive ID sein.', 'VALIDATION_ERROR');
                }
                $sichtbar = FoodAlchemistMarkupClass::visibleToTeam($team)->whereKey($id)->where('is_inactive', false)->exists();
                if (! $sichtbar) {
                    return ToolResult::error('Aufschlagsklasse nicht sichtbar/aktiv im Team.', 'NOT_FOUND');
                }
                $clean[$key] = $id;
            }
        }

        $gespeichert = app(TeamSettingsService::class)->update($team, $clean);

        return ToolResult::success([
            'team_id' => (int) $team->id,
            'updated' => array_keys($clean),
            'settings' => $clean,
            'setting_id' => (int) $gespeichert->id,
        ]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['foodalchemist', 'settings', 'einstellungen', 'team', 'config', 'write'],
            'read_only' => false, 'idempotent' => true, 'risk_level' => 'write',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'side_effects' => ['updates'],
            'related_tools' => ['foodalchemist.settings.GET', 'foodalchemist.kalkulation.GET'],
            'examples' => [
                'Setze das Ziel für den Wareneinsatz auf 30 %.',
                'Schalte die KI für dieses Team ab.',
                'Ändere den Küchen-Typ auf catering.',
            ],
        ];
    }
}
