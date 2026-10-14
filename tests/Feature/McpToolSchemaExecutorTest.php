<?php

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Services\ToolPermissionService;
use Platform\Core\Tools\ToolExecutor;
use Platform\Core\Tools\ToolRegistry;
use Platform\FoodAlchemist\Models\FoodAlchemistProductionStation;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 83 B1 · FA-Umgehung (kein Eingriff in platforms-core): Der Core-ToolExecutor kennt nur EINFACHE Schema-Typen. Ein Union-Typ (`"type": ["integer","null"]`)
 * stürzt ihn mit einem TypeError ab (`validateType(): Argument #2 must be of type string, array given`). Deshalb tragen die FA-Tool-Schemas keine Array-Typen mehr;
 * nullbare Felder haben KEINEN `type` (ein einfacher Typ ließe `null` nicht durch: is_int(null) ist falsch) und sagen in der Beschreibung „oder null (hebt auf)“.
 *
 * Diese Tests laufen über den ECHTEN Ausführungsweg (ToolExecutor::execute → Registry → Validierung → Tool), ohne den Executor zu mocken. Nur die Modul-Berechtigung
 * des Tests wird freigegeben (die Sandbox hat keine Module-Tabelle), sie ist nicht Gegenstand dieser Tests.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->user = $this->makeUser($this->rootTeam);
    $this->actingAs($this->user);
    app()->instance(ToolPermissionService::class, new class extends ToolPermissionService {
        public function hasAccess(string $toolName): bool
        {
            return true;
        }
    });
    // Der Executor protokolliert jede Ausführung und prüft die Idempotenz in `tool_executions` (Core-Tabelle, im Test-Harness nicht migriert) — schmaler Stub wie crm_companies.
    if (! \Illuminate\Support\Facades\Schema::hasTable('tool_executions')) {
        \Illuminate\Support\Facades\Schema::create('tool_executions', function ($t) {
            $t->id();
            $t->string('tool_name', 255)->nullable();
            $t->unsignedBigInteger('user_id')->nullable();
            $t->unsignedBigInteger('team_id')->nullable();
            $t->json('arguments')->nullable();
            $t->boolean('success')->default(false);
            $t->string('error_code', 100)->nullable();
            $t->text('error_message')->nullable();
            $t->text('error_trace')->nullable();
            $t->integer('duration_ms')->default(0);
            $t->integer('memory_usage_bytes')->default(0);
            $t->integer('token_usage_input')->nullable();
            $t->integer('token_usage_output')->nullable();
            $t->json('result_data')->nullable();
            $t->text('result_message')->nullable();
            $t->string('trace_id', 32)->nullable();
            $t->string('chain_id', 32)->nullable();
            $t->integer('chain_position')->nullable();
            $t->json('metadata')->nullable();
            $t->timestamps();
        });
    }
    $this->kontext = new ToolContext($this->user, $this->rootTeam);
    $this->ausfuehren = fn (string $tool, array $args) => app(ToolExecutor::class)->execute($tool, $args, $this->kontext);
    $this->rezept = fn (array $over = []) => FoodAlchemistRecipe::create(array_merge([
        'team_id' => $this->rootTeam->id, 'recipe_key' => 'rk_'.bin2hex(random_bytes(4)), 'name' => 'Fond: Test', 'status' => 'draft', 'yield_kg' => 1.0, 'is_sales_recipe' => false,
    ], $over));
    $this->posten = FoodAlchemistProductionStation::create(['team_id' => $this->rootTeam->id, 'slug' => 'saucier', 'name' => 'Saucier', 'sort_order' => 1]);
});

it('Wächter: kein FA-Tool-Schema trägt irgendwo einen Array-Typ (Union), auch nicht verschachtelt', function () {
    $befunde = [];
    $suche = function ($knoten, string $pfad, string $tool) use (&$suche, &$befunde) {
        if (! is_array($knoten)) {
            return;
        }
        if (array_key_exists('type', $knoten) && is_array($knoten['type']) && $pfad !== '' && ! str_ends_with($pfad, '.properties')) {
            $befunde[] = $tool.' '.$pfad;
        }
        foreach ($knoten as $k => $v) {
            $suche($v, $pfad.'.'.$k, $tool);
        }
    };
    foreach (app(ToolRegistry::class)->all() as $name => $tool) {
        if (str_starts_with($tool->getName(), 'foodalchemist.')) {
            $suche($tool->getSchema(), '', $tool->getName());
        }
    }

    expect($befunde)->toBe([], "Union-Typ im Schema (stürzt den Core-ToolExecutor ab):\n".implode("\n", $befunde));
});

it('recipes.PUT über den Executor: default_station_id setzen, mit null aufheben (der Aufruf stürzt nicht mehr ab, null kommt durch)', function () {
    $r = ($this->rezept)();

    $setzen = ($this->ausfuehren)('foodalchemist.recipes.PUT', ['recipe_id' => $r->id, 'default_station_id' => $this->posten->id]);
    expect($setzen->success)->toBeTrue()->and($r->refresh()->default_station_id)->toBe((int) $this->posten->id);

    $aufheben = ($this->ausfuehren)('foodalchemist.recipes.PUT', ['recipe_id' => $r->id, 'default_station_id' => null]);
    expect($aufheben->success)->toBeTrue()->and($r->refresh()->default_station_id)->toBeNull();
});

it('recipes.PUT über den Executor: variable_work_time_min und -basis setzen (Basis kg/piece/portion), ohne Basis greift kg, ungültige Basis scheitert', function () {
    $r = ($this->rezept)();

    $a = ($this->ausfuehren)('foodalchemist.recipes.PUT', ['recipe_id' => $r->id, 'variable_work_time_min' => 12.5, 'variable_work_time_basis' => 'portion']);
    expect($a->success)->toBeTrue()->and((float) $r->refresh()->variable_work_time_min)->toBe(12.5)->and($r->variable_work_time_basis)->toBe('portion');

    $r2 = ($this->rezept)();
    $b = ($this->ausfuehren)('foodalchemist.recipes.PUT', ['recipe_id' => $r2->id, 'variable_work_time_min' => 3]);
    expect($b->success)->toBeTrue()->and((float) $r2->refresh()->variable_work_time_min)->toBe(3.0)->and($r2->variable_work_time_basis)->toBe('kg');

    $c = ($this->ausfuehren)('foodalchemist.recipes.PUT', ['recipe_id' => $r->id, 'variable_work_time_basis' => 'stueck']);
    expect($c->success)->toBeFalse();   // ungültige Basis (Executor-Enum)
    expect(($this->kontext ? app(\Platform\FoodAlchemist\Tools\RecipesPutTool::class)->execute(['recipe_id' => $r->id, 'variable_work_time_basis' => 'stueck'], $this->kontext) : null)->errorCode)->toBe('VALIDATION_ERROR');   // und direkt am Tool
    expect(app(\Platform\FoodAlchemist\Tools\RecipesPutTool::class)->execute(['recipe_id' => $r->id, 'variable_work_time_min' => -1], $this->kontext)->errorCode)->toBe('VALIDATION_ERROR');
    expect($r->refresh()->variable_work_time_basis)->toBe('portion');
});

it('recipes.PUT über den Executor: die übrigen nullbaren Felder (storage_type, storage_types, Haltbarkeit) setzen und mit null aufheben', function () {
    $r = ($this->rezept)();

    $set = ($this->ausfuehren)('foodalchemist.recipes.PUT', ['recipe_id' => $r->id, 'storage_type' => 'gekuehlt', 'storage_types' => ['gekuehlt', 'tiefgekuehlt'], 'shelf_life_chilled_days' => 5]);
    expect($set->success)->toBeTrue();
    $r->refresh();
    expect($r->storage_type)->toBe('gekuehlt')->and($r->storage_types)->toBe(['gekuehlt', 'tiefgekuehlt'])->and((int) $r->shelf_life_chilled_days)->toBe(5);

    $weg = ($this->ausfuehren)('foodalchemist.recipes.PUT', ['recipe_id' => $r->id, 'storage_type' => null, 'storage_types' => null, 'shelf_life_chilled_days' => null]);
    expect($weg->success)->toBeTrue();
    $r->refresh();
    expect($r->storage_type)->toBeNull()->and($r->storage_types)->toBeNull()->and($r->shelf_life_chilled_days)->toBeNull();
});

it('recipes.POST über den Executor: legt ein Rezept mit variabler Zeit an (Basis Standard kg), mit ausdrücklicher Basis und lehnt eine ungültige ab', function () {
    $a = ($this->ausfuehren)('foodalchemist.recipes.POST', ['name' => 'Fond: Spec83 A', 'variable_work_time_min' => 4.5]);
    expect($a->success)->toBeTrue();
    $ra = FoodAlchemistRecipe::findOrFail($a->data['recipe']['id']);
    expect((float) $ra->variable_work_time_min)->toBe(4.5)->and($ra->variable_work_time_basis)->toBe('kg');

    $b = ($this->ausfuehren)('foodalchemist.recipes.POST', ['name' => 'Fond: Spec83 B', 'variable_work_time_min' => 20, 'variable_work_time_basis' => 'piece']);
    expect(FoodAlchemistRecipe::findOrFail($b->data['recipe']['id'])->variable_work_time_basis)->toBe('piece');

    expect(($this->ausfuehren)('foodalchemist.recipes.POST', ['name' => 'Fond: Spec83 C', 'variable_work_time_basis' => 'liter'])->success)->toBeFalse();
});

it('Weitere Tools mit nullbaren Feldern laufen über den Executor: production_orders.LINE_ASSIGN-Schema (station_id/assignee) und outlets.SET_ACTIVE kommen durch die Validierung', function () {
    foreach (['foodalchemist.production_orders.LINE_ASSIGN' => ['line_id' => 999999, 'station_id' => null, 'assignee' => null],
        'foodalchemist.outlets.SET_ACTIVE' => ['outlet_id' => null]] as $name => $args) {
        $r = ($this->ausfuehren)($name, $args);
        // Die Validierung darf nicht an Typ/Absturz scheitern; ein fachlicher Fehler (Zeile unbekannt) ist in Ordnung.
        expect($r->errorCode ?? null)->not->toBe('VALIDATION_ERROR_TYPE')->and(str_contains((string) ($r->error ?? ''), 'falschen Typ'))->toBeFalse();
    }
});
