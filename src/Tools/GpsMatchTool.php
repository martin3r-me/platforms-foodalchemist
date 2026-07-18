<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\IngredientMatchService;
use Platform\FoodAlchemist\Services\LaFirstGpService;

/**
 * Phase 0: Zutat-Text → GP-Ground-Truth. Top-Match (GP oder Sub-Rezept) +
 * Kandidatenliste. Findet nichts Brauchbares (target=none): mit mint_if_missing=true
 * mintet der Tool LA-First einen GP aus passender LA (07·M3, tentative + LA-verknüpft);
 * ohne Flag / ohne passende LA → foodalchemist.gp_proposals.POST (Beschaffungs-Wunsch),
 * NIE einen GP frei erfinden (Doktrin: kein GP ohne LA).
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
            . 'Mit mint_if_missing=true wird bei target=none LA-First ein GP aus passender LA gemintet '
            . '(tentative, sofort verwendbar); ohne LA bleibt es none. '
            . 'Kein Treffer und kein Mint → foodalchemist.gp_proposals.POST (Beschaffungs-Wunsch), nie raten.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'zutat' => ['type' => 'string', 'description' => 'Zutat-Freitext, z. B. "Kürbispüree" oder "Zanderfilet ohne Haut"'],
                'main_ingredient_slug' => ['type' => 'string', 'description' => 'Optionaler Slug der Hauptzutat zur Präzisierung'],
                'k' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 10, 'default' => 5, 'description' => 'Anzahl Kandidaten'],
                'mint_if_missing' => ['type' => 'boolean', 'default' => false, 'description' => 'Bei target=none LA-First ein GP aus passender LA minten (tentative). Ohne passende LA bleibt es none.'],
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
        $slug = isset($arguments['main_ingredient_slug']) ? (string) $arguments['main_ingredient_slug'] : null;
        $svc = app(IngredientMatchService::class);

        $match = $svc->matchIngredient($team, $zutat, $slug);

        // 07·M3: mint-if-missing — Bestand-Miss + passende LA → LA-First-Mint (tentative),
        // damit der Rezept-Flow nicht bei GP-Lücken dead-endet. Ohne LA bleibt target=none.
        $minted = false;
        if (($match['target'] ?? null) === 'none' && ($arguments['mint_if_missing'] ?? false)) {
            $gp = app(LaFirstGpService::class)->mintFromLa($team, $zutat, $slug);
            if ($gp !== null) {
                $minted = true;
                $match = [
                    'target' => 'gp',
                    'status' => 'mint',   // Provenienz-Band: frisch LA-First gemintet (tentative)
                    'gp_id' => $gp->id,
                    'gp_name' => $gp->name,
                    'recipe_id' => null, 'recipe_name' => null,
                    'score' => null,
                ];
            }
        }

        if ($match['status'] instanceof \BackedEnum) {
            $match['status'] = $match['status']->value;
        }

        return ToolResult::success([
            'best_match' => $match,
            'minted' => $minted,
            'candidates' => $svc->candidatesFor($team, $zutat, $slug, min(10, max(1, (int) ($arguments['k'] ?? 5)))),
        ]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'query',
            'tags' => ['foodalchemist', 'gp', 'grundprodukt', 'zutat', 'match', 'ground-truth', 'la-first'],
            // Default = reiner Read; NUR mit mint_if_missing=true entsteht ein GP (tentative,
            // LA-belegt, dedup-idempotent, reversibel) → als schreibfähig deklariert (Lockstep-Ehrlichkeit).
            'read_only' => false,
            'idempotent' => true,
            'risk_level' => 'write',
            'requires_auth' => true,
            'requires_team' => true,
            'side_effects' => ['creates'],   // nur im mint_if_missing-Zweig
            'cost_class' => 'local_db',
            'related_tools' => ['foodalchemist.gps.MINT_FROM_LA', 'foodalchemist.gp_proposals.POST', 'foodalchemist.gps.GET'],
            'examples' => ['Welcher GP passt zu "Kürbispüree"?', 'Matche "Ruby-Schokolade" und minte LA-First, falls kein GP existiert (mint_if_missing)'],
        ];
    }
}
