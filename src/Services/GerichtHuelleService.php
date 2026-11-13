<?php

namespace Platform\FoodAlchemist\Services;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Str;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Enums\RecipeStatus;
use Platform\FoodAlchemist\Models\FoodAlchemistCascadeRunStep;
use Platform\FoodAlchemist\Models\FoodAlchemistConceptSlot;
use Platform\FoodAlchemist\Models\FoodAlchemistDishIdea;
use Platform\FoodAlchemist\Models\FoodAlchemistPlanningSession;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use RuntimeException;

/**
 * „Rezeptur erstellen" für ein VORHANDENES Gericht (Dominique 13.11.2026).
 *
 * Ein Gericht kann als Hülle existieren: Name, Beschreibung, Einordnung — aber keine Komponenten (von Hand oder per
 * MCP angelegt, oft schon in einem Concept oder Angebot verbaut). Der KI-Assistent am Gericht reicherte nur Texte an,
 * und die Planung konnte nur NEUE Gerichte erzeugen. Hier wird die Hülle zum Ziel der Planung:
 *
 *   - {@see sessionFuer}: Knopf am Gericht → vorbefüllte Planung (Titel = Name, Briefing = Beschreibung), wie der
 *     Zukauf-Knopf am Grundprodukt. Gestartet wird dort mit dem normalen Go (Bauplan → Freigabe → Bau).
 *   - {@see generiere}: der Bau schreibt Komponenten und Basisrezepte IN die Hülle, statt ein zweites Gericht anzulegen
 *     (Generator-Parameter `gericht_fuellen_id`, dieselbe Mechanik wie `stub_fuellen_id` beim Import-Unterrezept).
 *     Was der Mensch gesetzt hat — Name, Beschreibung, Klasse, Hauptgruppe, Portion — bleibt stehen.
 *   - {@see planeHuellen}: im Fan-out eines Concepts bekommt jede Hülle in einer Position einen geplanten Schritt
 *     („Leere Gerichte dieses Concepts erstellen").
 *
 * Hülle heißt: Gericht des eigenen Teams, Entwurf, ohne Komponenten. Freigegebene Gerichte sind Bestand und bleiben
 * unangetastet.
 */
final class GerichtHuelleService
{
    public const QUELLE = 'gericht_huelle';

    /** Am Gericht gesetzte Felder, die der Bau nicht überschreibt (nur wenn sie vorher gefüllt waren). */
    private const GESCHUETZT = ['name', 'description', 'dish_class_id', 'dish_main_group_id', 'sales_quantity_per_unit_g'];

    /** Die Hülle zu einer ID — oder null, wenn das Gericht fremd, kein Entwurf mehr oder schon ausrezeptiert ist. */
    public static function huelle(Team $team, int $recipeId): ?FoodAlchemistRecipe
    {
        $gericht = FoodAlchemistRecipe::query()->where('team_id', $team->id)->where('is_sales_recipe', true)
            ->whereIn('status', [RecipeStatus::Draft, RecipeStatus::Stub])->find($recipeId);

        return $gericht !== null && ! $gericht->ingredients()->whereNull('deleted_at')->exists() ? $gericht : null;
    }

    /**
     * Vorbefüllte Planung für die Hülle. Eine offene Planung desselben Gerichts wird wiederverwendet.
     *
     * @throws ModelNotFoundException Gericht nicht sichtbar
     * @throws RuntimeException       geerbt, schon freigegeben oder schon mit Komponenten
     */
    public function sessionFuer(Team $team, int $recipeId, string $createdVia = 'gericht'): FoodAlchemistPlanningSession
    {
        $gericht = FoodAlchemistRecipe::visibleToTeam($team)->where('is_sales_recipe', true)->find($recipeId);
        if ($gericht === null) {
            throw new ModelNotFoundException('Gericht nicht sichtbar.');
        }
        if ((int) $gericht->team_id !== (int) $team->id) {
            throw new RuntimeException('Geerbtes Gericht: eine Rezeptur erstellen kann nur das Besitzer-Team.');
        }
        if (self::huelle($team, (int) $gericht->id) === null) {
            throw new RuntimeException('„Rezeptur erstellen“ gibt es nur für Gerichte im Entwurf ohne Komponenten. Dieses Gericht hat schon Komponenten oder ist freigegeben. Zum Ändern „Mit Anweisung überarbeiten“ nutzen.');
        }
        $offen = FoodAlchemistPlanningSession::where('team_id', $team->id)->where('target_recipe_id', $gericht->id)
            ->where('status', '!=', 'erledigt')->orderByDesc('id')->first();
        if ($offen !== null) {
            return $offen;
        }
        $session = app(PlanningSessionService::class)->create($team, [
            'title' => mb_substr((string) $gericht->name, 0, 255),
            'brief' => trim((string) $gericht->description) !== '' ? (string) $gericht->description : (string) $gericht->name,
            'created_via' => $createdVia,
        ]);
        $session->update(['target_recipe_id' => (int) $gericht->id]);

        return $session->refresh();
    }

    /** Das Ziel-Gericht einer Planung (für den Hinweis in der Leitstelle), sonst null. */
    public function zielDerSession(Team $team, ?FoodAlchemistPlanningSession $session): ?FoodAlchemistRecipe
    {
        return $session?->target_recipe_id !== null
            ? FoodAlchemistRecipe::query()->where('team_id', $team->id)->where('is_sales_recipe', true)->find((int) $session->target_recipe_id)
            : null;
    }

    /**
     * Das Gericht, in das dieser Schritt bauen soll: am Schritt vermerkt (Fan-out eines Concepts) oder das Ziel der
     * Planung (Wurzel-Schritt eines Gericht-Laufs).
     */
    public function zielFuerStep(Team $team, int $stepId): ?FoodAlchemistRecipe
    {
        $step = FoodAlchemistCascadeRunStep::with('run')->find($stepId);
        if ($step === null || $step->kind !== 'gericht') {
            return null;
        }
        $id = (int) (($step->context_snapshot ?? [])['ziel_recipe_id'] ?? 0);
        if ($id <= 0 && $step->parent_step_id === null && $step->run?->scope === 'gericht' && $step->run?->planning_session_id !== null) {
            $id = (int) FoodAlchemistPlanningSession::where('team_id', $team->id)->whereKey((int) $step->run->planning_session_id)->value('target_recipe_id');
        }

        return $id > 0 ? FoodAlchemistRecipe::query()->where('team_id', $team->id)->where('is_sales_recipe', true)->find($id) : null;
    }

    /**
     * Gericht bauen — in die Hülle, wenn der Schritt eine hat, sonst wie bisher als neues Gericht.
     * Ist die Hülle inzwischen ausrezeptiert (dasselbe Gericht an zwei Positionen), wird nichts Zweites gebaut.
     *
     * @param  array<string, mixed>  $params
     * @return array{recipe: FoodAlchemistRecipe, statistik?: array, offene?: array}
     */
    public function generiere(Team $team, int $stepId, string $beschreibung, array $params, ?array $context, callable $melde): array
    {
        $generator = app(RecipeGeneratorService::class);
        $ziel = $this->zielFuerStep($team, $stepId);
        if ($ziel === null) {
            return $generator->generiere($team, $beschreibung, $params, null, true, 'plan_go', $context, $melde);
        }
        if (self::huelle($team, (int) $ziel->id) === null) {
            return ['recipe' => $ziel, 'statistik' => [], 'offene' => []];
        }

        $vorher = $ziel->only([...self::GESCHUETZT, 'description_source', 'dish_class_source']);
        $gen = $generator->generiere($team, $beschreibung,
            [...$params, 'gericht_fuellen_id' => (int) $ziel->id, 'titel_vorgabe' => (string) $ziel->name],
            null, true, 'plan_go', $context, $melde);

        $recipe = $gen['recipe'] ?? null;
        if ($recipe !== null && (int) $recipe->id === (int) $ziel->id) {
            $zurueck = array_filter(array_intersect_key($vorher, array_flip(self::GESCHUETZT)), fn ($v) => $v !== null && $v !== '');
            foreach (['description' => 'description_source', 'dish_class_id' => 'dish_class_source'] as $feld => $quelle) {
                if (array_key_exists($feld, $zurueck)) {
                    $zurueck[$quelle] = $vorher[$quelle];   // die Herkunft des Werts bleibt die des Menschen
                }
            }
            $recipe->forceFill($zurueck)->save();
            $gen['recipe'] = $recipe->refresh();
        }

        return $gen;
    }

    /**
     * Fan-out eines Concepts: jede Hülle in einer Gericht-Position bekommt einen geplanten Schritt (Vorschlag-Gate
     * wie bei erfundenen Gerichten). Gebaut wird erst nach der Freigabe, und zwar in die Hülle.
     *
     * @return int Zahl der angelegten Schritte
     */
    public function planeHuellen(Team $team, FoodAlchemistCascadeRunStep $conceptStep, int $conceptId, int $sortAb = 0): int
    {
        $slots = FoodAlchemistConceptSlot::where('concept_id', $conceptId)->whereNotNull('sales_recipe_id')->where('type', 'gericht')
            ->orderBy('position')->orderBy('id')->get(['id', 'sales_recipe_id']);
        $gesehen = [];
        $angelegt = 0;
        foreach ($slots as $slot) {
            $recipeId = (int) $slot->sales_recipe_id;
            if (isset($gesehen[$recipeId])) {
                continue;   // dasselbe Gericht an mehreren Positionen: ein Schritt reicht
            }
            $gesehen[$recipeId] = true;
            $gericht = self::huelle($team, $recipeId);
            if ($gericht === null) {
                continue;
            }
            $beschreibung = trim((string) $gericht->description);
            $idee = FoodAlchemistDishIdea::create([
                'team_id' => $team->id,
                'chapter_id' => null,
                'concept_id' => $conceptId,
                'position' => (int) FoodAlchemistDishIdea::where('team_id', $team->id)->where('concept_id', $conceptId)->max('position') + 1,
                'title' => (string) $gericht->name,
                'description' => $beschreibung !== '' ? $beschreibung : null,
                'sales_recipe_id' => null,
                'target_form' => 'einzel',
                'group_id' => null,
                'status' => 'entwurf',
                'created_via' => 'ui',
                'source_meta' => ['quelle' => self::QUELLE, 'target_concept_slot_id' => (int) $slot->id, 'ziel_recipe_id' => $recipeId, 'komponenten' => []],
            ]);
            FoodAlchemistCascadeRunStep::create([
                'team_id' => $team->id,
                'cascade_run_id' => (int) $conceptStep->cascade_run_id,
                'parent_step_id' => (int) $conceptStep->id,
                'kind' => 'gericht',
                'label' => Str::limit('Rezeptur: ' . $gericht->name, 120),
                'status' => 'geplant',
                'sort' => $sortAb + (++$angelegt),
                'context_snapshot' => [
                    'dish_idea_id' => (int) $idee->id,
                    'beschreibung' => $beschreibung !== '' ? $beschreibung : 'Das Gericht steht schon in der Position, hat aber noch keine Komponenten. Die Freigabe erstellt die Rezeptur in diesem Gericht.',
                    'komponenten' => [],
                    'ziel_recipe_id' => $recipeId,
                ],
            ]);
        }

        return $angelegt;
    }
}
