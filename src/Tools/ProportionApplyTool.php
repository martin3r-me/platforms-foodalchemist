<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\ProportionService;

/**
 * #513 — %→Gramm-Rückschreiben als MCP-Tool (WRITE). Grammatur bleibt Master: das
 * Tool schreibt NUR Mengen (g/kg/…), nie ein Prozent. Zwei Modi:
 *   operation=rescale         → Batch-Skalierung (factor ODER new_ref_mass_g[+ref_ingredient_id])
 *   operation=set_baker_percent → eine Zutat übers % justieren (ingredient_id + pct)
 * Danach läuft der Recompute (Yield/Kosten/Allergene). Einheiten-Guard bei Modus B:
 * nur Masse-Einheiten (Stück/Liter read-only). Read-only-Sicht: gps.MATCH-Analog
 * ist foodalchemist.proportion.CALC (operation=recipe_baker_percent).
 */
class ProportionApplyTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.proportion.APPLY';
    }

    public function getDescription(): string
    {
        return 'Schreibt eine %-getriebene Mengen-Änderung ins Rezept zurück (Grammatur bleibt Master, '
            . 'nie ein Prozent gespeichert). operation=rescale: Batch-Skalierung aller Mengen — factor ODER '
            . 'new_ref_mass_g (+ optional ref_ingredient_id). operation=set_baker_percent: eine Zutat auf pct % '
            . 'der Referenzmasse setzen (ingredient_id, pct; nur Masse-Einheiten g/kg — Stück/Liter read-only). '
            . 'Danach recompute. Draft-Quarantäne wie die übrige Schreibkaskade.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'operation' => ['type' => 'string', 'enum' => ['rescale', 'set_baker_percent']],
                'recipe_id' => ['type' => 'integer', 'description' => 'Rezept-ID (Pflicht)'],
                'factor' => ['type' => 'number', 'description' => 'rescale: Skalierungs-Faktor > 0 (z. B. 2 = verdoppeln)'],
                'new_ref_mass_g' => ['type' => 'number', 'description' => 'rescale (Alternative zu factor): Referenzzutat auf X g setzen, Rest proportional'],
                'ref_ingredient_id' => ['type' => 'integer', 'description' => 'Referenzzutat = 100 % (Default schwerste Zutat) — rescale/set_baker_percent'],
                'ingredient_id' => ['type' => 'integer', 'description' => 'set_baker_percent: die zu justierende Zutat'],
                'pct' => ['type' => 'number', 'description' => 'set_baker_percent: Ziel-Bäckerprozent (≥ 0)'],
            ],
            'required' => ['operation', 'recipe_id'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        if (($arguments['recipe_id'] ?? null) === null) {
            return ToolResult::error('recipe_id ist Pflicht.', 'VALIDATION_ERROR');
        }
        $svc = app(ProportionService::class);
        $recipeId = (int) $arguments['recipe_id'];
        $refId = isset($arguments['ref_ingredient_id']) ? (int) $arguments['ref_ingredient_id'] : null;

        try {
            switch ((string) ($arguments['operation'] ?? '')) {
                case 'rescale':
                    if (isset($arguments['factor'])) {
                        $r = $svc->rescaleRecipe($team, $recipeId, (float) $arguments['factor']);
                    } elseif (isset($arguments['new_ref_mass_g'])) {
                        $r = $svc->rescaleToReferenceMass($team, $recipeId, (float) $arguments['new_ref_mass_g'], $refId);
                    } else {
                        return ToolResult::error('rescale braucht factor ODER new_ref_mass_g.', 'VALIDATION_ERROR');
                    }

                    return ToolResult::success([
                        'operation' => 'rescale',
                        'factor' => $r['factor'],
                        'changed' => count($r['changes']),
                        'changes' => $r['changes'],
                        'yield_kg' => $r['recipe']->yield_kg,
                    ]);

                case 'set_baker_percent':
                    if (($arguments['ingredient_id'] ?? null) === null || ($arguments['pct'] ?? null) === null) {
                        return ToolResult::error('set_baker_percent braucht ingredient_id + pct.', 'VALIDATION_ERROR');
                    }
                    $r = $svc->setIngredientBakerPercent($team, $recipeId, (int) $arguments['ingredient_id'], (float) $arguments['pct'], $refId);

                    return ToolResult::success([
                        'operation' => 'set_baker_percent',
                        'ingredient_id' => $r['ingredient_id'],
                        'baker_percent' => $r['baker_percent'],
                        'ref_mass_g' => $r['ref_mass_g'],
                        'new_mass_g' => $r['new_mass_g'],
                        'new_quantity' => $r['new_quantity'],
                    ]);

                default:
                    return ToolResult::error('operation muss rescale oder set_baker_percent sein.', 'VALIDATION_ERROR');
            }
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return ToolResult::error('Rezept nicht gefunden oder nicht sichtbar.', 'NOT_FOUND');
        } catch (\RuntimeException $e) {
            return ToolResult::error($e->getMessage(), 'VALIDATION_ERROR');
        }
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['foodalchemist', 'grammatur', 'baeckerprozent', 'skalierung', 'menge', 'rezept'],
            'read_only' => false,
            'idempotent' => false,   // rescale ist multiplikativ — wiederholtes Anwenden skaliert erneut
            'risk_level' => 'write',
            'requires_auth' => true,
            'requires_team' => true,
            'side_effects' => ['updates'],
            'cost_class' => 'local_db',
            'related_tools' => ['foodalchemist.proportion.CALC', 'foodalchemist.recipe_ingredients.PUT'],
            'examples' => [
                'Skaliere Rezept 1234 auf das Doppelte (factor 2)',
                'Setz die Referenzzutat von Rezept 1234 auf 1500 g',
                'Setz im Rezept 1234 die Zutat 88 auf 70 Bäckerprozent',
            ],
        ];
    }
}
