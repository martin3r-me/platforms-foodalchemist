<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeStep;
use Platform\FoodAlchemist\Services\RecipeStepService;

/**
 * Spec 27: die Schrittfolge eines Rezepts setzen — NUR stub/draft (Draft-Quarantäne
 * wie recipes.PUT). Ersetzt die Liste als Ganzes; Zeilen MIT `id` behalten ihre
 * Identität und damit ihre verknüpften Fotos, fehlende Zeilen werden gelöscht.
 * `recipes.preparation` wird danach automatisch als Spiegel neu gerendert.
 */
class RecipeStepsPutTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.recipe_steps.PUT';
    }

    public function getDescription(): string
    {
        return 'Setzt die Zubereitung eines Rezepts als Schrittfolge (nur Status stub/draft). Die '
            . 'Reihenfolge im Array IST die Nummerierung — position wird nicht übergeben. Eine Zeile '
            . 'mit `id` aktualisiert genau diesen Schritt und behält seine Fotos; weggelassene '
            . 'Schritte werden gelöscht. Alternativ `preparation_markdown` senden: `##` wird zum '
            . 'Abschnitt, `1.`/`-` zum Schritt. Gepflegte Rezepte (review/approved/archived) sind '
            . 'für den MCP-Pfad locked.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'recipe_id' => ['type' => 'integer'],
                'steps' => [
                    'type' => 'array',
                    'description' => 'Schrittfolge in Reihenfolge. Entweder dieses Feld ODER preparation_markdown.',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'id' => ['type' => 'integer', 'description' => 'bestehenden Schritt aktualisieren (behält seine Fotos)'],
                            'phase' => ['type' => 'string', 'description' => 'Abschnitt, z. B. „Mise en Place"; leer = kein Abschnitt'],
                            'text' => ['type' => 'string', 'description' => 'die Anweisung — eine Handlung pro Schritt'],
                        ],
                        'required' => ['text'],
                    ],
                ],
                'preparation_markdown' => [
                    'type' => 'string',
                    'description' => 'Markdown-Zubereitung; wird deterministisch in Schritte geparst (ersetzt die Liste).',
                ],
            ],
            'required' => ['recipe_id'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        $recipe = FoodAlchemistRecipe::visibleToTeam($team)->whereKey((int) ($arguments['recipe_id'] ?? 0))->first();
        if ($recipe === null) {
            return ToolResult::error('Rezept nicht sichtbar/vorhanden.', 'NOT_FOUND');
        }
        // Schreibrecht: Besitzer-Team (D1) …
        if ((int) $recipe->team_id !== (int) $team->id) {
            return ToolResult::error('Geerbtes Rezept — Pflege nur durchs Besitzer-Team (D1).', 'ACCESS_DENIED');
        }
        // … und Draft-Quarantäne wie bei recipes.PUT
        if (($sperre = $this->kiEditGesperrt($recipe)) !== null) {
            return ToolResult::error($sperre, 'ACCESS_DENIED');
        }

        $svc = app(RecipeStepService::class);
        $markdown = trim((string) ($arguments['preparation_markdown'] ?? ''));
        $rohSteps = $arguments['steps'] ?? null;

        if (is_array($rohSteps) && $rohSteps !== []) {
            $rows = [];
            foreach ($rohSteps as $s) {
                if (! is_array($s)) {
                    continue;
                }
                $text = trim((string) ($s['text'] ?? ''));
                if ($text === '') {
                    continue;
                }
                $rows[] = [
                    'id' => isset($s['id']) ? (int) $s['id'] : null,
                    'phase' => trim((string) ($s['phase'] ?? '')) ?: null,
                    'text' => $text,
                ];
            }
            if ($rows === []) {
                return ToolResult::error('Kein Schritt mit Text übergeben.', 'VALIDATION_ERROR');
            }
            $svc->sync($recipe, $rows);
        } elseif ($markdown !== '') {
            if ($svc->ausMarkdown($recipe, $markdown, ueberschreiben: true) === 0) {
                return ToolResult::error('Im Markdown war kein Schritt erkennbar.', 'VALIDATION_ERROR');
            }
        } else {
            return ToolResult::error('Entweder steps[] oder preparation_markdown angeben.', 'VALIDATION_ERROR');
        }

        $steps = FoodAlchemistRecipeStep::where('recipe_id', $recipe->id)->orderBy('position')->get();

        return ToolResult::success([
            'recipe' => ['id' => $recipe->id, 'name' => $recipe->name, 'status' => $this->statusWert($recipe)],
            'n_steps' => $steps->count(),
            'steps' => $steps->map(fn (FoodAlchemistRecipeStep $s) => [
                'id' => $s->id, 'position' => (int) $s->position, 'phase' => $s->phase, 'text' => $s->text,
            ])->values()->all(),
            // Der gerenderte Spiegel — das ist, was Produktionsdruck und Suche sehen.
            'preparation' => $recipe->fresh()->preparation,
        ]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['foodalchemist', 'rezept', 'recipe', 'zubereitung', 'steps', 'anleitung', 'update', 'draft'],
            'read_only' => false,
            'idempotent' => true,
            'risk_level' => 'write',
            'requires_auth' => true,
            'requires_team' => true,
            'side_effects' => ['updates', 'deletes'],
            'cost_class' => 'local_db',
            'related_tools' => ['foodalchemist.recipe_steps.GET', 'foodalchemist.recipes.PUT'],
            'examples' => [
                'Setze für Rezept 4711 die Arbeitsschritte: Mise en Place → Garen → Finish',
                'Übernimm diese getippte Zubereitung als Schritte für Rezept 4711',
            ],
        ];
    }
}
