<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\PlanungsblattService;

/**
 * R7.1 — Einkaufsliste (read-only): über MEHRERE Ziele (Event / mehrere
 * Konzepte) zusammengeführter GP-Bedarf, gruppiert nach Lead-LA-Lieferant.
 * Gleiche Rezepte werden VOR der Ganze-Ansätze-Rundung zusammengeführt
 * (weniger Rundungs-Verschnitt über das Event).
 */
class EinkaufslisteGetTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.einkaufsliste.GET';
    }

    public function getDescription(): string
    {
        return 'Einkaufsliste (read-only): GP-Bedarf über mehrere Ziele zusammengeführt, nach Lieferant '
            . 'gruppiert. ziele = Liste aus {concept_id, persons} und/oder {recipe_id, portions}.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'ziele' => [
                    'type' => 'array',
                    'minItems' => 1,
                    'description' => 'Liste der Ziele; je Eintrag concept_id+persons ODER recipe_id+portions/persons.',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'concept_id' => ['type' => 'integer'],
                            'recipe_id' => ['type' => 'integer'],
                            'persons' => ['type' => 'integer', 'minimum' => 1],
                            'portions' => ['type' => 'number', 'minimum' => 1],
                        ],
                    ],
                ],
            ],
            'required' => ['ziele'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        $ziele = $arguments['ziele'] ?? null;
        if (! is_array($ziele) || $ziele === []) {
            return ToolResult::error('ziele muss eine nicht-leere Liste sein.', 'VALIDATION_ERROR');
        }
        // Nur bekannte Schlüssel je Ziel durchreichen, Werte typkoercieren.
        $ziele = array_map(fn ($z) => array_intersect_key(
            is_array($z) ? $z : [],
            array_flip(['concept_id', 'recipe_id', 'persons', 'portions'])
        ), $ziele);

        try {
            $blatt = app(PlanungsblattService::class)->einkaufsliste($team, $ziele);
        } catch (\RuntimeException $e) {
            return ToolResult::error($e->getMessage(), 'EXECUTION_ERROR');
        }

        return ToolResult::success($blatt);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'query',
            'tags' => ['foodalchemist', 'einkaufsliste', 'einkauf', 'event', 'lieferant', 'planung'],
            'read_only' => true, 'idempotent' => true, 'risk_level' => 'safe',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'related_tools' => ['foodalchemist.bestellvorschlag.GET', 'foodalchemist.produktionsblatt.GET'],
            'examples' => ['Einkaufsliste für ein Event aus Konzept 42 (120 P.) und Konzept 43 (80 P.)'],
        ];
    }
}
