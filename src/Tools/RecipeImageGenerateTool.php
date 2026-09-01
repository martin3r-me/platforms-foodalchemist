<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeStep;
use Platform\FoodAlchemist\Services\RecipeImageService;
use Platform\FoodAlchemist\Services\TeamSettingsService;

/**
 * MCP-Gegenstück zum „KI-Bilder"-Knopf: erzeugt KI-Fotos (gpt-image-1.5) für
 * ein Rezept und hängt sie korrekt an — Titel-/Produktfoto ans Rezept, Schrittfotos an die Steps
 * (M:N-Pivot). Wickelt RecipeImageService ein (gleiche Speicherung + ai_call_log wie die UI).
 * SYNCHRON — `all` kann bei vielen Schritten dauern. Respektiert den KI-Kill-Switch.
 */
class RecipeImageGenerateTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.recipe_images.GENERATE';
    }

    public function getDescription(): string
    {
        return 'Erzeugt KI-Fotos für ein Rezept und hängt sie an: scope=hero (Titel-/Produktfoto), '
            .'step (EIN Schritt — step_id oder position), all (Titel + je Schritt). replace=true '
            .'löscht vorher die bisherigen KI-Fotos (Ersetzen statt Anhäufen). Synchron; „all" dauert '
            .'bei vielen Schritten. Kosten je Bild (gpt-image-1.5). Nur eigene Rezepte; '
            .'respektiert den KI-Kill-Switch (Einstellungen › KI).';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'recipe_id' => ['type' => 'integer', 'description' => 'Rezept-Id (eigenes Team).'],
                'scope' => ['type' => 'string', 'enum' => ['hero', 'step', 'all'], 'description' => 'hero=Titelfoto, step=ein Schritt, all=Titel + alle Schritte. Default all.'],
                'step_id' => ['type' => 'integer', 'description' => 'Nur scope=step: Ziel-Schritt per Id.'],
                'position' => ['type' => 'integer', 'description' => 'Nur scope=step: Ziel-Schritt per 1-basierter Position (Alternative zu step_id).'],
                'replace' => ['type' => 'boolean', 'description' => 'true = vorhandene KI-Fotos vorher löschen. Default false.'],
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
        if ((int) $recipe->team_id !== (int) $team->id) {
            return ToolResult::error('Geerbtes Rezept — Bilder nur durchs Besitzer-Team.', 'ACCESS_DENIED');
        }
        if (! app(TeamSettingsService::class)->kiAktiv($team)) {
            return ToolResult::error('KI ist für dieses Team deaktiviert (Kill-Switch).', 'KI_DISABLED');
        }

        $scope = in_array($arguments['scope'] ?? 'all', ['hero', 'step', 'all'], true) ? $arguments['scope'] : 'all';
        $svc = app(RecipeImageService::class);

        if (! empty($arguments['replace'])) {
            $svc->loescheKiFotos($team, $recipe->refresh());
        }

        if ($scope === 'step') {
            $step = $this->schritt($recipe, $arguments);
            if ($step === null) {
                return ToolResult::error('Schritt nicht gefunden (step_id/position prüfen).', 'NOT_FOUND');
            }
            $ok = $svc->schrittFoto($team, $recipe->refresh(), $step);

            return ToolResult::success([
                'recipe_id' => (int) $recipe->id,
                'scope' => 'step',
                'step_id' => (int) $step->id,
                'generated' => $ok ? 1 : 0,
            ]);
        }

        if ($scope === 'hero') {
            $foto = $svc->produktFoto($team, $recipe->refresh());

            return ToolResult::success([
                'recipe_id' => (int) $recipe->id,
                'scope' => 'hero',
                'generated' => 1,
                'photo' => ['id' => (int) $foto->id, 'url' => $foto->url(), 'caption' => $foto->caption],
            ]);
        }

        // scope = all: Titel + je Schritt (synchron).
        $res = $svc->erzeugeFuerRezept($team, $recipe->refresh(), true, true);

        return ToolResult::success([
            'recipe_id' => (int) $recipe->id,
            'scope' => 'all',
            'generated' => (int) ($res['erzeugt'] ?? 0),
            'errors' => (int) ($res['fehler'] ?? 0),
            'last_error' => $res['letzter_fehler'] ?? null,
        ]);
    }

    /** Ziel-Schritt per step_id oder position (beide team-/rezept-gescoped). */
    private function schritt(FoodAlchemistRecipe $recipe, array $arguments): ?FoodAlchemistRecipeStep
    {
        $q = FoodAlchemistRecipeStep::where('recipe_id', $recipe->id);
        if (! empty($arguments['step_id'])) {
            return $q->whereKey((int) $arguments['step_id'])->first();
        }
        if (! empty($arguments['position'])) {
            return $q->where('position', (int) $arguments['position'])->first();
        }

        return null;
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['foodalchemist', 'rezept', 'bild', 'foto', 'ki', 'write'],
            'read_only' => false, 'idempotent' => false, 'risk_level' => 'write',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'external_api',
            'side_effects' => ['creates', 'ai_generation'],
            'related_tools' => ['foodalchemist.recipe_steps.PUT', 'foodalchemist.recipes.GET'],
            'examples' => ['Erzeuge für Rezept 42 ein Titelfoto und je Schritt ein Foto.', 'Generiere nur für Schritt 5 von Rezept 42 ein neues KI-Foto.'],
        ];
    }
}
