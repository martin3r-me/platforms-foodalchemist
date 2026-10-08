<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\EtikettService;

/** Spec 70 · Etikett-Vorlage anlegen oder ändern (mit vorlage_id). Pflichtfelder bleiben immer an. */
class LabelTemplatesPostTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.label_templates.POST';
    }

    public function getDescription(): string
    {
        return 'Legt eine Etikett-Vorlage an oder ändert sie (mit vorlage_id). Felder: name, format (a4_24|a4_40|rolle_62|dymo_54), '
            . 'typ (intern|verkauf — verkauf erzwingt LMIV-Pflichtfelder), betrieb_id (Logo), design (Slug oder design:{id}), '
            . 'felder [{key, an, modus vorbelegt|leer}] in Reihenfolge, allergen_darstellung (kuerzel|klartext|beides), '
            . 'schriftgroesse (s|m|l), datum_gross, logo, fusstext, standard. Pflichtfelder lassen sich nicht abschalten.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'vorlage_id' => ['type' => 'integer'], 'name' => ['type' => 'string'], 'format' => ['type' => 'string'], 'typ' => ['type' => 'string'],
                'betrieb_id' => ['type' => ['integer', 'null']], 'design' => ['type' => ['string', 'null']],
                'felder' => ['type' => 'array', 'items' => ['type' => 'object']],
                'allergen_darstellung' => ['type' => 'string'], 'schriftgroesse' => ['type' => 'string'],
                'datum_gross' => ['type' => 'boolean'], 'logo' => ['type' => 'boolean'], 'fusstext' => ['type' => ['string', 'null']], 'standard' => ['type' => 'boolean'],
                'standard_wandmonitor' => ['type' => 'boolean'], 'drucker_id' => ['type' => ['integer', 'null'], 'description' => 'Spec 78: Drucker — Format und Ränder kommen dann vom Drucker'],
            ],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        $map = ['name' => 'name', 'format' => 'format', 'typ' => 'typ', 'betrieb_id' => 'outlet_id', 'design' => 'presentation_design', 'felder' => 'felder',
            'allergen_darstellung' => 'allergen_darstellung', 'schriftgroesse' => 'schriftgroesse', 'datum_gross' => 'datum_gross', 'logo' => 'zeige_logo',
            'fusstext' => 'fusstext', 'standard' => 'is_default', 'standard_wandmonitor' => 'is_kitchen_default', 'drucker_id' => 'printer_id'];
        $daten = [];
        foreach ($map as $in => $feld) {
            if (array_key_exists($in, $arguments)) {
                $daten[$feld] = $arguments[$in];
            }
        }
        try {
            $v = app(EtikettService::class)->speichern($team, isset($arguments['vorlage_id']) ? (int) $arguments['vorlage_id'] : null, $daten);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            return ToolResult::error('Vorlage nicht gefunden.', 'NOT_FOUND');
        } catch (\RuntimeException $e) {
            return ToolResult::error($e->getMessage(), 'VALIDATION_ERROR');
        }

        return ToolResult::success(['vorlage_id' => $v->id, 'name' => $v->name, 'felder' => $v->felder]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['foodalchemist', 'etikett', 'write'],
            'read_only' => false, 'idempotent' => false, 'risk_level' => 'write',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'side_effects' => ['creates', 'updates'],
            'related_tools' => ['foodalchemist.label_templates.GET', 'foodalchemist.labels.POST'],
            'examples' => ['Leg eine TK-Etikett-Vorlage auf Dymo an, Datum leer zum Handschreiben.'],
        ];
    }
}
