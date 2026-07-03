<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\IngredientMatchService;

/**
 * Phase 0: Zutat-Text → GP-Ground-Truth. Top-Match (GP oder Sub-Rezept) +
 * Kandidatenliste. Findet nichts Brauchbares → foodalchemist.gp_proposals.POST
 * nutzen, NIE einen GP erfinden (LA-First bleibt WaWi).
 */
class GpsMatchTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.gps.MATCH';
    }

    public function getDescription(): string
    {
        return 'Matcht einen Zutat-Freitext gegen die Grundprodukte (GPs) und Sub-Rezepte des Teams. '
            . 'Liefert best_match (target gp|sub_recipe|none, score, band) + candidates (Top-k GPs). '
            . 'PFLICHT vor jeder Rezept-Zutat: nur gematchte gp_id/recipe_id verwenden. '
            . 'Kein brauchbarer Treffer → foodalchemist.gp_proposals.POST (NEW-GP-Vorschlag), nie raten.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'zutat' => ['type' => 'string', 'description' => 'Zutat-Freitext, z. B. "Kürbispüree" oder "Zanderfilet ohne Haut"'],
                'hauptzutat_slug' => ['type' => 'string', 'description' => 'Optionaler Slug der Hauptzutat zur Präzisierung'],
                'k' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 10, 'default' => 5, 'description' => 'Anzahl Kandidaten'],
            ],
            'required' => ['zutat'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        $zutat = trim((string) $arguments['zutat']);
        if ($zutat === '') {
            return ToolResult::error('zutat darf nicht leer sein.', 'VALIDATION_ERROR');
        }
        $slug = isset($arguments['hauptzutat_slug']) ? (string) $arguments['hauptzutat_slug'] : null;
        $svc = app(IngredientMatchService::class);

        $match = $svc->matchIngredient($team, $zutat, $slug);
        if ($match['status'] instanceof \BackedEnum) {
            $match['status'] = $match['status']->value;
        }

        return ToolResult::success([
            'best_match' => $match,
            'candidates' => $svc->candidatesFor($team, $zutat, $slug, min(10, max(1, (int) ($arguments['k'] ?? 5)))),
        ]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'query',
            'tags' => ['foodalchemist', 'gp', 'grundprodukt', 'zutat', 'match', 'ground-truth'],
            'read_only' => true,
            'idempotent' => true,
            'risk_level' => 'safe',
            'requires_auth' => true,
            'requires_team' => true,
            'cost_class' => 'local_db',
            'related_tools' => ['foodalchemist.gp_proposals.POST', 'foodalchemist.gps.GET'],
            'examples' => ['Welcher GP passt zu "Kürbispüree"?', 'Matche die Zutat "geräucherter Paprika" gegen den GP-Bestand'],
        ];
    }
}
