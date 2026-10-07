<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\SpeiseplanService;

/**
 * Spec 57 · Paket 5: die Einträge eines Speiseplans lesen. Vorher kannte ein Agent die
 * Eintrags-IDs nur aus der Antwort von speiseplan_eintraege.POST — DELETE/PAX/PUT waren damit
 * für bestehende Pläne praktisch nicht nutzbar.
 */
class SpeiseplanEintraegeGetTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.speiseplan_eintraege.GET';
    }

    public function getDescription(): string
    {
        return 'Listet die Einträge eines Speiseplans (id, entry_date, mahlzeit, line_id, Inhalt concept_id|package_id|'
            . 'sales_recipe_id, presentation_id + darreichung (geltende Form, null = Standard), wording (Name im Plan), Anzeigename, '
            . 'vk/ek je Person (ek null = Portion unbekannt), '
            . 'pax, pax_effektiv). Optional von/bis (YYYY-MM-DD) und mahlzeit.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'plan_id' => ['type' => 'integer', 'description' => 'Speiseplan-Id.'],
                'von' => ['type' => 'string', 'description' => 'Ab Datum YYYY-MM-DD (optional).'],
                'bis' => ['type' => 'string', 'description' => 'Bis Datum YYYY-MM-DD (optional).'],
                'mahlzeit' => ['type' => 'string', 'enum' => ['fruehstueck', 'mittag', 'abend', 'snack']],
            ],
            'required' => ['plan_id'],
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
            $liste = $svc->eintragsListe($plan, $arguments['von'] ?? null, $arguments['bis'] ?? null, $arguments['mahlzeit'] ?? null);
        } catch (\Carbon\Exceptions\InvalidFormatException $e) {
            return ToolResult::error('Datum unlesbar: ' . $e->getMessage(), 'VALIDATION_ERROR');
        }

        return ToolResult::success(['plan_id' => (int) $plan->id, 'anzahl' => count($liste), 'eintraege' => $liste]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'read',
            'tags' => ['foodalchemist', 'speiseplan', 'eintrag', 'read'],
            'read_only' => true, 'idempotent' => true, 'risk_level' => 'safe',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'side_effects' => [],
            'related_tools' => ['foodalchemist.speiseplan_eintraege.PUT', 'foodalchemist.speiseplan_eintraege.DELETE', 'foodalchemist.speiseplan_eintraege.PAX'],
            'examples' => ['Zeig mir die Mittags-Einträge von Speiseplan 3 in KW 41.'],
        ];
    }
}
