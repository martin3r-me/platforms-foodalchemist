<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\ProportionService;

/**
 * #513 Tier 1 / Punkt 1 — Grammaturen-Rechner als MCP-Tool (read-only, deterministisch).
 * Ein Einstieg für alle vier Formeln + die Bäckerprozent-Sicht eines Rezepts. Der
 * LLM ruft das als FAKT (Formel ist Code, nie im Prompt); Grammatur bleibt Master,
 * Prozente sind abgeleitete Sicht.
 */
class ProportionCalcTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.proportion.CALC';
    }

    public function getDescription(): string
    {
        return 'Deterministischer Grammaturen-Rechner (Modernist Cuisine). operation: '
            . 'baker_percent (m/ref×100) · baker_mass (pct/100×ref) · extra_mass (Hydrokolloid/Salz '
            . 'pct×Σandere) · brining (Lake-Masse d·M/S + Zielgewicht) · bloom (Gelatine-Marke A→B: '
            . 'M·BloomA/BloomB, sorte bronze/silber/gold/platin ODER bloom_a/bloom_b) · '
            . 'recipe_baker_percent (Bäckerprozent-Sicht je Zutat eines Rezepts, Referenz = schwerste '
            . 'Zutat oder ref_ingredient_id). Grammatur bleibt Master — Prozente sind abgeleitete Sicht.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'operation' => [
                    'type' => 'string',
                    'enum' => ['baker_percent', 'baker_mass', 'extra_mass', 'brining', 'bloom', 'recipe_baker_percent'],
                ],
                'mass_g' => ['type' => 'number', 'description' => 'Masse in Gramm (baker_percent, extra_mass, bloom)'],
                'ref_mass_g' => ['type' => 'number', 'description' => 'Referenzmasse in g (baker_percent/baker_mass)'],
                'pct' => ['type' => 'number', 'description' => 'Prozentwert (baker_mass, extra_mass)'],
                'sum_other_g' => ['type' => 'number', 'description' => 'Σ aller anderen Komponenten in g (extra_mass)'],
                'start_mass_g' => ['type' => 'number', 'description' => 'Startgewicht M in g (brining)'],
                'target_salinity' => ['type' => 'number', 'description' => 'Ziel-Salinität d (brining) — gleiche Einheit wie brine_salt'],
                'brine_salt' => ['type' => 'number', 'description' => 'Salzgehalt der Lake S (brining) — gleiche Einheit wie target_salinity'],
                'bloom_a' => ['type' => 'number', 'description' => 'Bloom der Ausgangs-Gelatine (bloom)'],
                'bloom_b' => ['type' => 'number', 'description' => 'Bloom der Ziel-Gelatine (bloom)'],
                'sorte_a' => ['type' => 'string', 'description' => 'Sortengrad Ausgang: bronze|silber|gold|platin (bloom, statt bloom_a)'],
                'sorte_b' => ['type' => 'string', 'description' => 'Sortengrad Ziel: bronze|silber|gold|platin (bloom, statt bloom_b)'],
                'recipe_id' => ['type' => 'integer', 'description' => 'Rezept-ID (recipe_baker_percent)'],
                'ref_ingredient_id' => ['type' => 'integer', 'description' => 'Referenzzutat = 100 % (recipe_baker_percent; Default schwerste Zutat)'],
            ],
            'required' => ['operation'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $svc = app(ProportionService::class);
        $op = (string) ($arguments['operation'] ?? '');
        $num = fn (string $k) => (float) ($arguments[$k] ?? 0);

        switch ($op) {
            case 'baker_percent':
                return ToolResult::success(['baker_percent' => $svc->bakerPercent($num('mass_g'), $num('ref_mass_g'))]);

            case 'baker_mass':
                return ToolResult::success(['mass_g' => $svc->bakerMass($num('pct'), $num('ref_mass_g'))]);

            case 'extra_mass':
                return ToolResult::success(['mass_g' => $svc->extraMass($num('pct'), $num('sum_other_g'))]);

            case 'brining':
                return ToolResult::success([
                    'brine_mass_g' => $svc->briningBrineMassG($num('start_mass_g'), $num('target_salinity'), $num('brine_salt')),
                    'target_total_g' => $svc->briningTotalG($num('start_mass_g'), $num('target_salinity'), $num('brine_salt')),
                ]);

            case 'bloom':
                $result = isset($arguments['sorte_a'], $arguments['sorte_b'])
                    ? $svc->bloomConvertBySorte($num('mass_g'), (string) $arguments['sorte_a'], (string) $arguments['sorte_b'])
                    : $svc->bloomConvert($num('mass_g'), $num('bloom_a'), $num('bloom_b'));

                return ToolResult::success(['mass_g' => $result]);

            case 'recipe_baker_percent':
                $team = $this->team($context);
                if ($team === null) {
                    return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
                }
                if (($arguments['recipe_id'] ?? null) === null) {
                    return ToolResult::error('recipe_id ist Pflicht für recipe_baker_percent.', 'VALIDATION_ERROR');
                }
                try {
                    return ToolResult::success($svc->bakerPercentagesForRecipe(
                        $team, (int) $arguments['recipe_id'],
                        isset($arguments['ref_ingredient_id']) ? (int) $arguments['ref_ingredient_id'] : null,
                    ));
                } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
                    return ToolResult::error('Rezept nicht gefunden oder nicht sichtbar.', 'NOT_FOUND');
                }

            default:
                return ToolResult::error("Unbekannte operation »{$op}«.", 'VALIDATION_ERROR');
        }
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'query',
            'tags' => ['foodalchemist', 'grammatur', 'baeckerprozent', 'brining', 'bloom', 'gelatine', 'skalierung', 'rechner'],
            'read_only' => true,
            'idempotent' => true,
            'risk_level' => 'safe',
            'requires_auth' => true,
            'requires_team' => true,   // nur recipe_baker_percent braucht das Team; die reinen Rechner sind team-frei, Tool bleibt aber team-gebunden
            'cost_class' => 'local_db',
            'related_tools' => ['foodalchemist.recipes.GET', 'foodalchemist.recipe_ingredients.PUT'],
            'examples' => [
                'Rechne 12 g Salz bei 800 g Gesamtmasse in Extraprozent um',
                'Ich habe Gold-Gelatine (200) im Rezept, wie viel Silber (160) brauche ich für 8 g?',
                'Zeig mir die Bäckerprozent-Sicht von Rezept 1234',
            ],
        ];
    }
}
