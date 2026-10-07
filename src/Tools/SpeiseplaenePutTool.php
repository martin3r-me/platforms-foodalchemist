<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Models\FoodAlchemistSpeiseplan;
use Platform\FoodAlchemist\Services\SpeiseplanService;
use Platform\FoodAlchemist\Services\SpeiseplanVorgabenService;

/** MCP-Steuerbarkeit · D9: Speiseplan-Stammdaten bearbeiten (Zyklus, Pax, Budget, Betrieb). */
class SpeiseplaenePutTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    private const FELDER = ['name', 'start_date', 'cycle_weeks', 'min_abstand_tage', 'description', 'note',
        'default_pax', 'budget_wareneinsatz', 'outlet_id', 'opening_days', 'writing_style_id'];

    public function getName(): string
    {
        return 'foodalchemist.speiseplaene.PUT';
    }

    public function getDescription(): string
    {
        return 'Bearbeitet die Stammdaten eines team-eigenen Speiseplans (felder: name, start_date, cycle_weeks, '
            . 'min_abstand_tage, default_pax, budget_wareneinsatz, outlet_id, writing_style_id = Schreibstil fürs Wording, opening_days = ISO-Wochentage 1–7, '
            . 'z. B. [1,2,3,4,5,6]; leer = Mo–Fr; vorgaben = Wochen-Vorgaben, ersetzt die Liste komplett: '
            . '[{chip_id, mahlzeit: null|fruehstueck|mittag|abend|snack, min, max}], Chips via speiseplan_chips.GET). '
            . 'Status via speiseplaene.STATUS.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'id' => ['type' => 'integer', 'description' => 'Speiseplan-Id.'],
                'felder' => ['type' => 'object', 'description' => 'Zu ändernde Felder (Allow-List).'],
            ],
            'required' => ['id', 'felder'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        $felder = $arguments['felder'] ?? null;
        if (! is_array($felder) || $felder === []) {
            return ToolResult::error('felder muss ein nicht-leeres Objekt sein.', 'VALIDATION_ERROR');
        }
        $in = array_intersect_key($felder, array_flip(self::FELDER));
        // Spec 59: Vorgaben laufen NICHT über update()/FELDER, sondern über den Vorgaben-Service
        // (dieselbe Validierung wie im Editor: Chip im Team, min ≤ max, nicht negativ).
        $mitVorgaben = array_key_exists('vorgaben', $felder);
        if ($in === [] && ! $mitVorgaben) {
            return ToolResult::error('Keine bekannten Felder in felder (Status via speiseplaene.STATUS).', 'VALIDATION_ERROR');
        }
        $id = (int) ($arguments['id'] ?? 0);
        if (($guard = $this->guardOwned($team, FoodAlchemistSpeiseplan::class, $id, 'Speiseplan')) !== null) {
            return $guard;
        }

        try {
            if ($mitVorgaben) {
                // zuerst validieren — ein Fehler hier lässt auch die übrigen Felder unangetastet
                $vorgaben = app(SpeiseplanVorgabenService::class)->normVorgaben($team, $felder['vorgaben']);
            }
            if ($in !== []) {
                app(SpeiseplanService::class)->update($team, $id, $in);
            }
            if ($mitVorgaben) {
                app(SpeiseplanVorgabenService::class)->setzeVorgaben($team, $id, $vorgaben);
                $in['vorgaben'] = true;
            }
        } catch (\RuntimeException $e) {
            return ToolResult::error($e->getMessage(), 'VALIDATION_ERROR');
        }

        return ToolResult::success(['id' => $id, 'updated' => array_keys($in)]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['foodalchemist', 'speiseplan', 'write'],
            'read_only' => false, 'idempotent' => true, 'risk_level' => 'write',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'side_effects' => ['updates'],
            'related_tools' => ['foodalchemist.speiseplaene.STATUS', 'foodalchemist.speiseplan.BRANDING'],
            'examples' => ['Setze bei Speiseplan 3 den Zyklus auf 4 Wochen.'],
        ];
    }
}
