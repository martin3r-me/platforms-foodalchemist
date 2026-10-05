<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Enums\AusgabeStatus;
use Platform\FoodAlchemist\Models\FoodAlchemistSpeiseplanEintrag;
use Platform\FoodAlchemist\Services\SpeiseplanService;

/**
 * Spec 57 · Paket 5: einen Eintrag verschieben (Datum, Linie, Mahlzeit) und/oder seinen Inhalt
 * ersetzen, optional Pax setzen. Wie speiseplan_eintraege.POST nur an Entwürfen (E6: Agenten
 * machen Vorschläge, sie schreiben nicht in laufende Pläne).
 */
class SpeiseplanEintraegePutTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.speiseplan_eintraege.PUT';
    }

    public function getDescription(): string
    {
        return 'Ändert einen Speiseplan-Eintrag (nur Entwurf): verschieben mit entry_date (YYYY-MM-DD), line_id '
            . '(0 = ohne Linie), mahlzeit; ersetzen mit GENAU EINEM von concept_id | package_id | sales_recipe_id; '
            . 'optional pax (0 = Standard). Eintrags-IDs via speiseplan_eintraege.GET.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'eintrag_id' => ['type' => 'integer', 'description' => 'Eintrag-Id.'],
                'entry_date' => ['type' => 'string', 'description' => 'Neues Datum YYYY-MM-DD.'],
                'line_id' => ['type' => 'integer', 'description' => 'Neue Linie (0 = ohne Linie).'],
                'mahlzeit' => ['type' => 'string', 'enum' => ['fruehstueck', 'mittag', 'abend', 'snack']],
                'concept_id' => ['type' => 'integer'],
                'package_id' => ['type' => 'integer'],
                'sales_recipe_id' => ['type' => 'integer'],
                'pax' => ['type' => 'integer', 'description' => 'Essen (0 = Standard der Linie/des Plans).'],
            ],
            'required' => ['eintrag_id'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        $id = (int) ($arguments['eintrag_id'] ?? 0);
        if (($guard = $this->guardSpeiseplanEintragOwned($team, $id)) !== null) {
            return $guard;
        }
        $e = FoodAlchemistSpeiseplanEintrag::visibleToTeam($team)->with('mealPlan')->find($id);
        if ($e === null || $e->mealPlan === null) {
            return ToolResult::error('Eintrag nicht sichtbar/vorhanden.', 'NOT_FOUND');
        }
        if ($e->mealPlan->statusWert() !== AusgabeStatus::Entwurf) {
            return ToolResult::error("Speiseplan hat Status \"{$e->mealPlan->statusWert()->label()}\" — via MCP ist nur ein Entwurf editierbar.", 'ACCESS_DENIED');
        }

        $inhalt = array_intersect_key(array_filter($arguments), array_flip(['concept_id', 'package_id', 'sales_recipe_id']));
        if (count($inhalt) > 1) {
            return ToolResult::error('Zum Ersetzen GENAU EINES von concept_id, package_id, sales_recipe_id angeben.', 'VALIDATION_ERROR');
        }
        $verschieben = array_key_exists('entry_date', $arguments) || array_key_exists('line_id', $arguments) || array_key_exists('mahlzeit', $arguments);
        if ($inhalt === [] && ! $verschieben && ! array_key_exists('pax', $arguments)) {
            return ToolResult::error('Nichts zu ändern — entry_date/line_id/mahlzeit, ein Inhalt oder pax angeben.', 'VALIDATION_ERROR');
        }

        $svc = app(SpeiseplanService::class);
        $geaendert = [];
        try {
            if ($inhalt !== []) {
                $svc->ersetzeEintrag($team, $id, $inhalt);
                $geaendert[] = 'inhalt';
            }
            if ($verschieben) {
                $lineId = array_key_exists('line_id', $arguments) ? ((int) $arguments['line_id'] > 0 ? (int) $arguments['line_id'] : null) : $e->line_id;
                $svc->verschiebeEintrag($team, $id, (string) ($arguments['entry_date'] ?? $e->entry_date?->format('Y-m-d')), $lineId, $arguments['mahlzeit'] ?? null);
                $geaendert[] = 'zelle';
            }
            if (array_key_exists('pax', $arguments)) {
                $svc->setEintragPax($team, $id, $arguments['pax']);
                $geaendert[] = 'pax';
            }
        } catch (\RuntimeException | \Carbon\Exceptions\InvalidFormatException $ex) {
            return ToolResult::error($ex->getMessage(), 'VALIDATION_ERROR');
        }

        $neu = $e->fresh();

        return ToolResult::success(['eintrag' => [
            'id' => (int) $neu->id, 'entry_date' => $neu->entry_date?->format('Y-m-d'), 'mahlzeit' => $neu->meal,
            'line_id' => $neu->line_id, 'concept_id' => $neu->concept_id, 'package_id' => $neu->package_id,
            'sales_recipe_id' => $neu->sales_recipe_id, 'pax' => $neu->pax,
        ], 'geaendert' => $geaendert]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['foodalchemist', 'speiseplan', 'eintrag', 'write'],
            'read_only' => false, 'idempotent' => true, 'risk_level' => 'write',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'side_effects' => ['updates'],
            'related_tools' => ['foodalchemist.speiseplan_eintraege.GET', 'foodalchemist.speiseplan_eintraege.POST'],
            'examples' => ['Verschiebe Eintrag 88 auf Dienstag 2026-10-06, Linie 4.', 'Ersetze bei Eintrag 90 das Gericht durch sales_recipe_id 512.'],
        ];
    }
}
