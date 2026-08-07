<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeFinding;
use Platform\FoodAlchemist\Services\RecipeFindingService;
use Platform\FoodAlchemist\Services\RecipeReviewService;

/**
 * Spec 21 · S5a — abgelegte KI-Befunde am Rezept lesen (MCP-Lockstep zur neuen Ablage).
 *
 * Abgrenzung zu `foodalchemist.recipes.REVIEW`: das prüft EIN Rezept frisch (kostet
 * einen Provider-Call und legt nichts ab), dieses Tool liest den Bestand aus dem
 * Batch-Lauf — ohne Egress.
 */
class RecipeFindingsSearchTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.recipe_findings.SEARCH';
    }

    public function getDescription(): string
    {
        return 'Listet abgelegte KI-Befunde am Rezept (Batch-Pässe): Menge/Einheit falsch, Zutat '
            . 'entfernen, Zutat fehlt, Fremdkörper (fachlich unpassende, verdrahtete Zutat → Übernahme '
            . 'löst die Verknüpfung), Hinweis (Rezept-Copilot) sowie bauart = Zweifel, ob das Rezept ein '
            . 'Gericht oder eine Komponente ist — je Befund Konfidenz, Anwendbarkeit und wie oft er schon '
            . 'gemeldet wurde. Default: offene. Entscheiden via foodalchemist.recipe_findings.PUT.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'status' => ['type' => 'string', 'enum' => FoodAlchemistRecipeFinding::STATUS,
                    'description' => 'Default offen; leer = alle'],
                'kind' => ['type' => 'string', 'enum' => RecipeReviewService::ARTEN],
                'recipe_id' => ['type' => 'integer', 'description' => 'nur Befunde dieses Rezepts'],
                'min_confidence' => ['type' => 'number', 'minimum' => 0, 'maximum' => 1,
                    'description' => 'Default 0; die Signal-Schwelle liegt bei ' . RecipeFindingService::KONFIDENZ_SCHWELLE],
                'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 50, 'default' => 20],
            ],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }

        $status = $arguments['status'] ?? 'offen';                     // '' = ausdrücklich alle
        $q = FoodAlchemistRecipeFinding::query()->with('recipe:id,name,is_sales_recipe')
            ->where('team_id', $team->id)
            ->when($status !== '' && $status !== null, fn ($x) => $x->where('status', $status))
            ->when(($arguments['kind'] ?? null) !== null, fn ($x) => $x->where('kind', $arguments['kind']))
            ->when(($arguments['recipe_id'] ?? null) !== null, fn ($x) => $x->where('recipe_id', (int) $arguments['recipe_id']))
            ->where('confidence', '>=', (float) ($arguments['min_confidence'] ?? 0))
            ->orderByDesc('confidence')->orderByDesc('id');

        $gesamt = (clone $q)->count();
        $zeilen = $q->limit(min(50, max(1, (int) ($arguments['limit'] ?? 20))))->get();

        return ToolResult::success([
            'total' => $gesamt,
            // Zwei Zahlen, weil zwei Signale daran hängen (S5b-1 / S5b-2). Eine Summe
            // wäre hier irreführend: sie zählte Rezeptur- und Bauart-Zweifel zusammen,
            // die im Cockpit getrennt stehen und getrennt aufgelöst werden.
            'signal_kandidaten' => [
                'copilot' => app(RecipeFindingService::class)
                    ->offeneUeberSchwelle($team, null, RecipeReviewService::ARTEN_COPILOT)->count(),
                'bauart' => app(RecipeFindingService::class)
                    ->offeneUeberSchwelle($team, null, RecipeReviewService::ARTEN_STRUKTUR)->count(),
            ],
            'schwelle' => RecipeFindingService::KONFIDENZ_SCHWELLE,
            'befunde' => $zeilen->map(fn ($f) => [
                'id' => $f->id,
                'recipe_id' => $f->recipe_id,
                'recipe' => $f->recipe?->name,
                'ebene' => $f->recipe?->is_sales_recipe ? 'gericht' : 'basisrezept',
                'kind' => $f->kind,
                'zutat' => $f->ingredient_text,
                'quantity' => $f->quantity,
                'einheit_slug' => $f->unit_slug,
                'begruendung' => $f->reason,
                'konfidenz' => $f->confidence,
                'auto_applicable' => $f->auto_applicable,
                'applicability' => $f->applicability,
                'status' => $f->status,
                'seen_count' => $f->seen_count,
                'last_seen_at' => (string) $f->last_seen_at,
            ])->all(),
        ]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'query',
            'tags' => ['foodalchemist', 'recipe', 'copilot', 'befund', 'datenqualität', 'ki'],
            'read_only' => true, 'idempotent' => true, 'risk_level' => 'safe',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'related_tools' => ['foodalchemist.recipe_findings.PUT', 'foodalchemist.recipes.REVIEW'],
            'examples' => ['Welche KI-Befunde sind an Rezept 12 offen?', 'Zeig die Copilot-Befunde über 0.8 Konfidenz'],
        ];
    }
}
