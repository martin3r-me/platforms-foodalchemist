<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Models\FoodAlchemistSpeiseplan;
use Platform\FoodAlchemist\Services\SpeiseplanService;

/** MCP-Steuerbarkeit · D9: Ausgabe-Linie an einem Speiseplan anlegen. */
class SpeiseplanLinienPostTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    /** Spec 57 · Paket 2: durchgereichte Linien-Felder (Säuberung im SpeiseplanService). */
    public const LINIEN_FELDER = [
        'color', 'role', 'meal', 'plu', 'price_mode', 'price_value',
        'target_wes_min_pct', 'target_wes_max_pct', 'default_pax', 'is_standing',
    ];

    public function getName(): string
    {
        return 'foodalchemist.speiseplan_linien.POST';
    }

    public function getDescription(): string
    {
        return 'Legt eine Ausgabe-Linie an einem team-eigenen Speiseplan an (name; optional color, is_vegetarian, role, meal, plu, price_mode, price_value, target_wes_min_pct, target_wes_max_pct, default_pax, is_standing).';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'plan_id' => ['type' => 'integer', 'description' => 'Speiseplan-Id.'],
                'name' => ['type' => 'string', 'description' => 'Linien-Name.'],
                'color' => ['type' => 'string', 'description' => 'Optionale Farbe.'],
                'is_vegetarian' => ['type' => 'boolean', 'description' => 'Vegetarische Linie.'],
                'role' => ['type' => 'string', 'enum' => ['suppe', 'hauptgang', 'salat', 'beilage', 'dessert', 'sonstiges'], 'description' => 'Rolle an der Ausgabe (Hauptgang zählt die Gäste).'],
                'meal' => ['type' => 'string', 'enum' => ['fruehstueck', 'mittag', 'abend', 'snack'], 'description' => 'Nur für diese Mahlzeit (leer = alle).'],
                'plu' => ['type' => 'string', 'description' => 'Kassen-/PLU-Nummer.'],
                'price_mode' => ['type' => 'string', 'enum' => ['auto', 'manuell'], 'description' => 'auto = VK des Gerichts, manuell = price_value.'],
                'price_value' => ['type' => 'number', 'description' => 'Linienpreis netto (nur bei price_mode=manuell).'],
                'target_wes_min_pct' => ['type' => 'number', 'description' => 'Zielband Wareneinsatz von (%).'],
                'target_wes_max_pct' => ['type' => 'number', 'description' => 'Zielband Wareneinsatz bis (%). Leer = Team-Ziel.'],
                'default_pax' => ['type' => 'integer', 'description' => 'Standard-Essen je Tag.'],
                'is_standing' => ['type' => 'boolean', 'description' => 'Dauerangebot (zählt nicht für die Wiederholungsregel).'],
            ],
            'required' => ['plan_id', 'name'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        $name = trim((string) ($arguments['name'] ?? ''));
        if ($name === '') {
            return ToolResult::error('name ist Pflicht.', 'VALIDATION_ERROR');
        }
        $planId = (int) ($arguments['plan_id'] ?? 0);
        if (($guard = $this->guardOwned($team, FoodAlchemistSpeiseplan::class, $planId, 'Speiseplan')) !== null) {
            return $guard;
        }

        try {
            // Spec 57 · Paket 2: alle Linien-Felder; der Service säubert (Whitelist, Typen, Grenzen).
            $linie = app(SpeiseplanService::class)->addLinie($team, $planId, array_merge(
                array_intersect_key($arguments, array_flip(self::LINIEN_FELDER)),
                ['name' => $name, 'is_vegetarian' => (bool) ($arguments['is_vegetarian'] ?? false)],
            ));
        } catch (\RuntimeException $e) {
            return ToolResult::error($e->getMessage(), 'VALIDATION_ERROR');
        }

        return ToolResult::success(['plan_id' => $planId, 'linie_id' => (int) $linie->id, 'name' => $linie->name]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['foodalchemist', 'speiseplan', 'linie', 'write'],
            'read_only' => false, 'idempotent' => false, 'risk_level' => 'write',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'side_effects' => ['creates'],
            'related_tools' => ['foodalchemist.speiseplan_linien.PUT', 'foodalchemist.speiseplan_linien.DELETE'],
            'examples' => ['Lege am Speiseplan 3 die Linie „Vegetarisch" an.'],
        ];
    }
}
