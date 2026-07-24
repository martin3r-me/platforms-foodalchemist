<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\PlanungsblattService;

/**
 * R7.1 — Bestellvorschlag (read-only): Konzept + Personen ODER Gericht +
 * Portionen → GP-Bedarf gruppiert nach Lead-LA-Lieferant, mit EK-Summe je
 * Lieferant + Ausweichquelle. Rein rechnend, keine Bestellung.
 */
class BestellvorschlagGetTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.bestellvorschlag.GET';
    }

    public function getDescription(): string
    {
        return 'Bestellvorschlag (read-only): GP-Bedarf gruppiert nach Lead-LA-Lieferant, je Position in GANZEN '
            . 'Gebinden (gebinde: qty_packs, pack_qty, packaging_unit, article_number, pack_price, line_total, '
            . 'needed_base, ueberkauf_base) + EK-Summe je Lieferant. GENAU EINES angeben: concept_id (+ persons) '
            . 'ODER recipe_id (+ portions oder persons; beim Basisrezept ist portions = Ansätze, alternativ amount_kg) '
            . 'ODER chapter_id (+ persons; Foodbook-Kapitel-Scope, variant_choices {gruppe: block_id} für Wahl-Gruppen). '
            . 'Kein Bestellvorgang.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'concept_id' => ['type' => 'integer', 'description' => 'Konzept-ID (mit persons)'],
                'recipe_id' => ['type' => 'integer', 'description' => 'Gericht-/Rezept-ID (mit portions oder persons)'],
                'chapter_id' => ['type' => 'integer', 'description' => 'Foodbook-Kapitel-ID (mit persons) — GP-Bedarf des Kapitel-Scopes'],
                'persons' => ['type' => 'integer', 'minimum' => 1],
                'portions' => ['type' => 'number', 'minimum' => 1, 'description' => 'VK-Gericht: Portionen. Basisrezept: Anzahl Ansätze.'],
                'amount_kg' => ['type' => 'number', 'minimum' => 0, 'description' => 'Nur Basisrezept: Ziel-Kilogramm (Alternative zu portions/Ansätze).'],
                'variant_choices' => ['type' => 'object', 'description' => 'Nur chapter_id: {variant_group_id: block_id} — gewählter Block je Wahl-Gruppe (Default: erster Block).'],
            ],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        $keys = array_values(array_intersect(['concept_id', 'recipe_id', 'chapter_id'], array_keys(array_filter($arguments))));
        if (count($keys) !== 1) {
            return ToolResult::error('Genau EINES von concept_id, recipe_id oder chapter_id angeben.', 'VALIDATION_ERROR');
        }
        $ziel = array_intersect_key($arguments, array_flip(['concept_id', 'recipe_id', 'chapter_id', 'persons', 'portions', 'amount_kg', 'variant_choices']));

        try {
            $blatt = app(PlanungsblattService::class)->bestellvorschlag($team, $ziel);
        } catch (\RuntimeException $e) {
            return ToolResult::error($e->getMessage(), 'EXECUTION_ERROR');
        }

        return ToolResult::success($blatt);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'query',
            'tags' => ['foodalchemist', 'bestellvorschlag', 'einkauf', 'lieferant', 'lead-la', 'planung'],
            'read_only' => true, 'idempotent' => true, 'risk_level' => 'safe',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'related_tools' => ['foodalchemist.produktionsblatt.GET', 'foodalchemist.einkaufsliste.GET'],
            'examples' => ['Bestellvorschlag für Konzept 42 bei 120 Personen', 'Was bestellen für 100 Portionen Gericht 456?'],
        ];
    }
}
