<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Services\RecipeService;

/**
 * Phase A: Rezept-Update aus dem LLM-Pfad — NUR stub/draft (Draft-Quarantäne).
 * Einzige erlaubte Status-Transition: draft → review (»fertig, bitte Review«).
 * approved setzt nur ein Mensch im Editor.
 */
class RecipesPutTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.recipes.PUT';
    }

    public function getDescription(): string
    {
        return 'Aktualisiert ein Rezept im Status stub/draft (Felder: name, description, preparation, '
            . 'taste_direction, vorgabe_convenience, vorgabe_niveau, work_time_min, variable_work_time_min + variable_work_time_basis (Zeit je kg/Stück/Portion), yield_kg_manual, category_id). status="review" reicht '
            . 'den Entwurf zum menschlichen Review ein (einzige erlaubte Transition). Gepflegte Rezepte '
            . '(review/approved/archived) sind für den MCP-Pfad locked. '
            . '`preparation` (Markdown) wird beim Schreiben in Schritte geparst, ABER nur wenn das '
            . 'Rezept noch keine hat — bestehende Schritte änderst du über foodalchemist.recipe_steps.PUT.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => [
                'recipe_id' => ['type' => 'integer'],
                'name' => ['type' => 'string'],
                'description' => ['type' => 'string'],
                'preparation' => ['type' => 'string'],
                'taste_direction' => ['type' => 'string'],
                // 29.10.: Leitplanken-Vorgabe am Rezept — entscheidet bei Basiskomponenten selbst oder gekauft; "" = keine Vorgabe
                'vorgabe_convenience' => ['type' => 'string', 'enum' => ['', 'from_scratch', 'teil_convenience', 'voll_convenience'],
                    'description' => 'Eigenleistung: from_scratch (alles selbst) | teil_convenience | voll_convenience; "" = keine Vorgabe (Standard: Unterrezept).'],
                'vorgabe_niveau' => ['type' => 'string', 'enum' => ['', 'haute_cuisine', 'gehoben', 'klassisch'],
                    'description' => 'Niveau: haute_cuisine | gehoben (selbst) | klassisch (Zukauf, wenn im Bestand); "" = keine Vorgabe. Eigenleistung geht vor.'],
                'work_time_min' => ['type' => 'integer', 'description' => 'Aktive Arbeitszeit (min) je Koch-Vorgang'],
                'setup_time_min' => ['type' => 'integer', 'description' => 'Rüstzeit (min), einmal je Produktionslauf'],
                'standzeit_min' => ['type' => 'integer', 'description' => 'Passive Gar-/Standzeit (min) je Lauf — Durchlaufzeit, bindet keinen Posten'],
                'variable_work_time_min' => ['type' => 'number', 'description' => 'Personenminuten je Mengeneinheit (siehe variable_work_time_basis), zusätzlich zur Arbeitszeit je Koch-Vorgang; wächst mit der Menge'],
                'variable_work_time_basis' => ['type' => 'string', 'enum' => \Platform\FoodAlchemist\Models\FoodAlchemistRecipe::ZEIT_BASEN, 'description' => 'Bezug der variablen Zeit: kg | piece (Stück) | portion. Ohne Angabe bleibt die bisherige Basis; neue Rezepte starten mit kg'],
                'batch_max_kg' => ['type' => 'number', 'description' => 'Topf-Deckel: max kg je Koch-Vorgang'],
                'batch_max_pieces' => ['type' => 'number', 'description' => 'Topf-Deckel: max Stück je Koch-Vorgang'],
                'max_vorlauf_tage' => ['type' => 'integer', 'description' => 'Vorproduzierbarkeit in Tagen (0 = nur am Produktionstag)'],
                'storage_type' => ['enum' => ['gekuehlt', 'tiefgekuehlt', 'trocken', null], 'description' => 'Text oder null (null hebt auf) — Übliche Lagerart (Vorgabe für Etikett und Einlagerung)'],
                'shelf_life_chilled_days' => ['description' => 'Ganzzahl oder null (null hebt auf) — Haltbarkeit gekühlt in Tagen'],
                'shelf_life_frozen_days' => ['description' => 'Ganzzahl oder null (null hebt auf) — Haltbarkeit tiefgekühlt in Tagen (ab Einfrieren)'],
                'shelf_life_dry_days' => ['description' => 'Ganzzahl oder null (null hebt auf) — Haltbarkeit trocken/kühl in Tagen'],
                'storage_types' => ['items' => ['type' => 'string', 'enum' => ['gekuehlt', 'tiefgekuehlt', 'trocken']], 'description' => 'Liste oder null (null hebt auf) — Mögliche Lagerarten (Mehrfachauswahl); storage_type ist die Standard-Lagerart daraus. Das Etikett wählt nur aus diesen.'],
                'default_station_id' => ['description' => 'Ganzzahl oder null — Default-Posten fürs Planer-Routing. IDs aus production_stations.GET; null hebt die Zuordnung auf.'],
                'yield_kg_manual' => ['type' => 'number'],
                'category_id' => ['type' => 'integer'],
                'status' => ['type' => 'string', 'enum' => ['review'], 'description' => 'Nur draft→review erlaubt'],
                'rollen' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Mögliche Teller-Rollen des Basisrezepts '
                    . '(Vokabular Regel gericht.teller_rolle), wichtigste zuerst. Ersetzt die Liste; Quelle manuell — KI und Kaskade '
                    . 'überschreiben sie danach nicht mehr. Ungültige Werte fallen weg.'],
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
        $recipe = FoodAlchemistRecipe::visibleToTeam($team)->whereKey((int) $arguments['recipe_id'])->first();
        if ($recipe === null) {
            return ToolResult::error('Rezept nicht sichtbar/vorhanden.', 'NOT_FOUND');
        }
        if (($sperre = $this->kiEditGesperrt($recipe)) !== null) {
            return ToolResult::error($sperre, 'ACCESS_DENIED');
        }

        $unknown = array_diff(array_keys($arguments), array_keys($this->getSchema()['properties']));
        if ($unknown !== []) {
            return ToolResult::error('Unbekannte Rezeptfelder: ' . implode(', ', $unknown)
                . '. Garverlust gehört je Zutat in recipe_ingredients.PUT: zutaten[].cooking_loss_pct; Posten in default_station_id.', 'VALIDATION_ERROR');
        }
        if (($fehler = $this->pruefeVariableZeit($arguments)) !== null) {
            return $fehler;
        }
        if (isset($arguments['status']) && $arguments['status'] !== 'review') {
            return ToolResult::error('Nur status=review ist über MCP erlaubt.', 'VALIDATION_ERROR');
        }
        if (count($arguments) === 1) {
            return ToolResult::error('Keine Änderungen angegeben.', 'VALIDATION_ERROR');
        }

        $in = array_intersect_key($arguments, array_flip([
            'name', 'description', 'preparation', 'taste_direction', 'vorgabe_convenience', 'vorgabe_niveau',
            'work_time_min', 'setup_time_min', 'standzeit_min', 'variable_work_time_min', 'variable_work_time_basis', 'batch_max_kg', 'batch_max_pieces',
            'max_vorlauf_tage', 'default_station_id', 'yield_kg_manual', 'category_id',
            'storage_type', 'shelf_life_chilled_days', 'shelf_life_frozen_days', 'shelf_life_dry_days', 'storage_types',
        ]));
        // Spec 83 B1: eine variable Zeit ohne Basis bekommt die Standardbasis des Rezept-Modals (kg), sonst bliebe sie ohne Bezug
        if (isset($in['variable_work_time_min']) && ! isset($in['variable_work_time_basis']) && ($recipe->variable_work_time_basis ?? '') === '') {
            $in['variable_work_time_basis'] = 'kg';
        }
        if (($arguments['status'] ?? null) === 'review') {
            $in['status'] = 'review';
        }

        try {
            if ($in !== []) {
                $recipe = app(RecipeService::class)->update($team, $recipe->id, $in);
            }
            if (array_key_exists('rollen', $arguments)) {
                app(\Platform\FoodAlchemist\Services\RecipeRollenService::class)->setze((int) $recipe->id, (array) $arguments['rollen'], 'manuell');
            }
        } catch (\RuntimeException $e) {
            return ToolResult::error($e->getMessage(), 'VALIDATION_ERROR');
        }

        return ToolResult::success($this->mitReife([
            'recipe' => [
                'id' => $recipe->id, 'name' => $recipe->name, 'status' => $this->statusWert($recipe),
                'version' => $recipe->version, 'yield_kg' => $recipe->yield_kg,
                'rollen' => app(\Platform\FoodAlchemist\Services\RecipeRollenService::class)->rollen((int) $recipe->id),
            ],
        ], $team, (int) $recipe->id, (bool) $recipe->is_sales_recipe));
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['foodalchemist', 'rezept', 'recipe', 'update', 'draft', 'review'],
            'read_only' => false,
            'idempotent' => true,
            'risk_level' => 'write',
            'requires_auth' => true,
            'requires_team' => true,
            'side_effects' => ['updates'],
            'cost_class' => 'local_db',
            'related_tools' => ['foodalchemist.recipes.POST', 'foodalchemist.recipe_ingredients.PUT'],
            'examples' => ['Ergänze die Zubereitung von Rezept 4711', 'Reiche Entwurf 4711 zum Review ein'],
        ];
    }
}
