<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\PairingService;

/**
 * R6.8 (Pairing-Offense): Aroma-treue Ersatz-Vorschläge für einen GP oder eine
 * Rezept-Zutat — Ersatz, der den GESCHMACK erhält, nicht nur den Preis senkt.
 * Rankt über den Pairing-Anker-Graph (Kanten-Überlappung + Aroma-Vektor-Cosinus),
 * boostet manuell kuratierte Äquivalente, zeigt erhaltene/verlorene Aroma-Brücken,
 * Allergen-Neubewertung VOR Tausch und (mit Rezept-Kontext) das Kohäsions-Delta.
 * READ-ONLY — der eigentliche Tausch bleibt der Editor-/Ersatz-Weg (tauscheZutat).
 */
class SubstitutionSuggestTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.substitution.SUGGEST';
    }

    public function getDescription(): string
    {
        return 'Schlägt aroma-treue Ersatz-Grundprodukte vor — Ersatz, der den Geschmack erhält, '
            . 'nicht nur den Preis. Entweder gp_id ODER recipe_ingredient_id angeben (letzteres '
            . 'liefert zusätzlich das Kohäsions-Delta fürs Gesamtgericht + swap_locked-Status). '
            . 'mode=flavor (Aroma-Treue, Default), cost (mit EK-Achse) oder both. Read-only: der '
            . 'eigentliche Tausch läuft über den Editor. gp_id via foodalchemist.gps.SEARCH ermitteln.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'gp_id' => ['type' => 'integer', 'description' => 'Quell-Grundprodukt (XOR recipe_ingredient_id)'],
                'recipe_ingredient_id' => ['type' => 'integer', 'description' => 'Rezept-Zutat (leitet gp_id ab, liefert Kohäsions-Delta)'],
                'mode' => ['type' => 'string', 'enum' => ['flavor', 'cost', 'both'], 'default' => 'flavor'],
                'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 20, 'default' => 8],
            ],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }

        $gpId = isset($arguments['gp_id']) ? (int) $arguments['gp_id'] : null;
        $riId = isset($arguments['recipe_ingredient_id']) ? (int) $arguments['recipe_ingredient_id'] : null;
        if ($gpId === null && $riId === null) {
            return ToolResult::error('gp_id oder recipe_ingredient_id angeben.', 'VALIDATION_ERROR');
        }

        $mode = in_array($arguments['mode'] ?? 'flavor', ['flavor', 'cost', 'both'], true) ? $arguments['mode'] : 'flavor';
        $limit = min(20, max(1, (int) ($arguments['limit'] ?? 8)));

        $result = app(PairingService::class)->aromaTrueSubstitutes($team, $gpId ?? 0, $limit, $riId);

        if ($result['source'] === null) {
            return ToolResult::error('Quell-GP/Zutat nicht sichtbar oder nicht vorhanden.', 'NOT_FOUND');
        }

        // mode=flavor: die EK-Achse ausblenden (bleibt intern berechnet, wird nur nicht ausgespielt).
        if ($mode === 'flavor') {
            $result['candidates'] = array_map(function ($c) {
                unset($c['cost']);

                return $c;
            }, $result['candidates']);
        }
        $result['mode'] = $mode;

        return ToolResult::success($result);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'query',
            'tags' => ['foodalchemist', 'pairing', 'substitution', 'ersatz', 'aroma', 'grundprodukt', 'vorschlag'],
            'read_only' => true,
            'idempotent' => true,
            'risk_level' => 'safe',
            'requires_auth' => true,
            'requires_team' => true,
            'cost_class' => 'local_db',
            'related_tools' => ['foodalchemist.pairings.SUGGEST', 'foodalchemist.gps.SEARCH', 'foodalchemist.pairings.GET'],
            'examples' => [
                'Welcher aroma-treue Ersatz gibt es für GP 812?',
                'Was kann ich für Zutat (recipe_ingredient) 4471 tauschen, ohne den Geschmack zu verlieren?',
            ],
        ];
    }
}
