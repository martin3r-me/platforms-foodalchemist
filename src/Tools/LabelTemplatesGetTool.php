<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\EtikettService;

/** Spec 70 · Etikett-Vorlagen lesen (inkl. Feldkatalog und Formaten). */
class LabelTemplatesGetTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.label_templates.GET';
    }

    public function getDescription(): string
    {
        return 'Etikett-Vorlagen des Teams: Format, Typ (intern|verkauf), Felder in Reihenfolge mit an/aus, Pflicht und '
            . 'Datums-Modus (vorbelegt|leer), Gestaltung (Betrieb/Logo, Design, Allergen-Darstellung, Schrift, Fußtext). '
            . 'Dazu Feldkatalog und Formate für label_templates.POST/PUT.';
    }

    public function getSchema(): array
    {
        return ['type' => 'object', 'properties' => ['vorlage_id' => ['type' => 'integer']]];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        $svc = app(EtikettService::class);
        $liste = $svc->vorlagen($team);
        if (! empty($arguments['vorlage_id'])) {
            $liste = $liste->where('id', (int) $arguments['vorlage_id']);
            if ($liste->isEmpty()) {
                return ToolResult::error('Vorlage nicht gefunden.', 'NOT_FOUND');
            }
        }

        return ToolResult::success([
            'vorlagen' => $liste->map(fn ($v) => [
                'vorlage_id' => $v->id, 'name' => $v->name, 'format' => $v->format, 'typ' => $v->typ, 'standard' => $v->is_default,
                'betrieb_id' => $v->outlet_id, 'design' => $v->presentation_design, 'allergen_darstellung' => $v->allergen_darstellung,
                'schriftgroesse' => $v->schriftgroesse, 'datum_gross' => $v->datum_gross, 'logo' => $v->zeige_logo, 'fusstext' => $v->fusstext,
                'felder' => $svc->normalisiereFelder((string) $v->typ, $v->felder),
            ])->values()->all(),
            'feldkatalog' => array_map(fn ($f) => ['label' => $f['label'], 'art' => $f['art'], 'pflicht_bei' => $f['pflicht']], EtikettService::FELDER),
            'formate' => array_map(fn ($f) => $f['label'], EtikettService::FORMATE),
        ]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'query',
            'tags' => ['foodalchemist', 'etikett'],
            'read_only' => true, 'idempotent' => true, 'risk_level' => 'safe',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'side_effects' => [],
            'related_tools' => ['foodalchemist.label_templates.POST', 'foodalchemist.labels.POST'],
            'examples' => ['Welche Etikett-Vorlagen haben wir?'],
        ];
    }
}
