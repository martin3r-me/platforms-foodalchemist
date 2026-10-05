<?php

namespace Platform\FoodAlchemist\Tools;

use Illuminate\Support\Carbon;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\SpeiseplanService;

/**
 * Spec 57 · Paket 4: Zutatenbedarf einer Speiseplan-Woche (oder eines Tages) — dieselbe
 * Einkaufsliste wie das Planungsblatt (GP-Ebene, Lead-LA, ganze Gebinde). Nur lesend.
 */
class SpeiseplanBedarfTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.speiseplan_bedarf.GET';
    }

    public function getDescription(): string
    {
        return 'Zutatenbedarf einer Speiseplan-Woche (montag = ein Tag der Woche) oder eines Tages (tag), je Mahlzeit: '
            . 'Rezepte bis zum Grundprodukt aufgelöst, Mengen × geplante Essen, gruppiert nach Lead-Lieferant mit Gebinden '
            . 'und EK. Nur lesend — an den Einkauf geht der Bedarf über speiseplan.ANPRODUKTION.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'plan_id' => ['type' => 'integer', 'description' => 'Speiseplan-Id.'],
                'montag' => ['type' => 'string', 'description' => 'Ein Tag der Woche, YYYY-MM-DD.'],
                'tag' => ['type' => 'string', 'description' => 'Optional: nur dieser Tag, YYYY-MM-DD.'],
                'mahlzeit' => ['type' => 'string', 'enum' => ['fruehstueck', 'mittag', 'abend', 'snack'], 'default' => 'mittag'],
            ],
            'required' => ['plan_id', 'montag'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        $svc = app(SpeiseplanService::class);
        $plan = $svc->detail($team, (int) ($arguments['plan_id'] ?? 0));
        if ($plan === null) {
            return ToolResult::error('Speiseplan nicht sichtbar/vorhanden.', 'NOT_FOUND');
        }
        try {
            $res = $svc->wochenBedarf($team, $plan, (string) ($arguments['mahlzeit'] ?? 'mittag'), Carbon::parse((string) $arguments['montag']), $arguments['tag'] ?? null);
        } catch (\Carbon\Exceptions\InvalidFormatException $e) {
            return ToolResult::error('Datum unlesbar: ' . $e->getMessage(), 'VALIDATION_ERROR');
        }

        return ToolResult::success(['plan_id' => (int) $plan->id] + $res);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'read',
            'tags' => ['foodalchemist', 'speiseplan', 'bedarf', 'einkauf', 'read'],
            'read_only' => true, 'idempotent' => true, 'risk_level' => 'safe',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'side_effects' => [],
            'related_tools' => ['foodalchemist.speiseplan.ANPRODUKTION', 'foodalchemist.speiseplan_mengen.GET'],
            'examples' => ['Was muss für Speiseplan 3 in KW 41 mittags eingekauft werden?'],
        ];
    }
}
