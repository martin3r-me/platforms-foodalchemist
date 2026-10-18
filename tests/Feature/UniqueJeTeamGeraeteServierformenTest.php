<?php

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Plan B Vorstufe (Kurator 18.10., F2): Koch-Equipment und Servierformen sind je Team eindeutig statt global — Voraussetzung, damit das
 * Grundeinstellungen-Paket sie je Mandant kopieren kann. Mit Vorprüfung und Rückweg; Code-Stellen, die per slug/code ohne Team suchten,
 * treffen jetzt die sichtbare (eigene vor globaler) Zeile.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->geraet = fn (?int $team, string $slug) => DB::table('foodalchemist_vocab_kitchen_equipment')->insertGetId(['uuid' => (string) Str::uuid7(),
        'team_id' => $team, 'slug' => $slug, 'name' => ucfirst($slug), 'created_at' => now(), 'updated_at' => now()]);
    $this->form = fn (?int $team, string $code) => DB::table('foodalchemist_serving_forms')->insertGetId(['uuid' => (string) Str::uuid7(),
        'team_id' => $team, 'code' => $code, 'label' => ucfirst($code), 'created_at' => now(), 'updated_at' => now()]);
    $this->migration = require __DIR__.'/../../database/migrations/2026_11_11_200000_unique_je_team_kochequipment_servierformen.php';
});

it('★ derselbe Slug/Code in zwei Teams geht, im selben Team zweimal nicht', function () {
    ($this->geraet)($this->childA->id, 'thermomix');
    ($this->geraet)($this->childB->id, 'thermomix');
    ($this->form)($this->childA->id, 'teller');
    ($this->form)($this->childB->id, 'teller');

    expect(fn () => ($this->geraet)($this->childA->id, 'thermomix'))->toThrow(QueryException::class)
        ->and(fn () => ($this->form)($this->childA->id, 'teller'))->toThrow(QueryException::class)
        ->and(DB::table('foodalchemist_vocab_kitchen_equipment')->where('slug', 'thermomix')->count())->toBe(2);
});

it('Rückweg: blockiert mit Liste, solange ein Schlüssel in zwei Teams steht; danach global eindeutig und erneut hin (idempotent)', function () {
    ($this->geraet)($this->childA->id, 'thermomix');
    ($this->geraet)($this->childB->id, 'thermomix');

    expect(fn () => $this->migration->down())->toThrow(RuntimeException::class, 'thermomix');

    DB::table('foodalchemist_vocab_kitchen_equipment')->where('team_id', $this->childB->id)->delete();
    $this->migration->down();
    expect(Schema::hasIndex('foodalchemist_vocab_kitchen_equipment', 'fa_kitchen_equipment_team_slug_uq'))->toBeFalse()
        ->and(fn () => ($this->geraet)($this->childB->id, 'thermomix'))->toThrow(QueryException::class);   // wieder global eindeutig

    $this->migration->up();
    $this->migration->up();   // zweiter Lauf ändert nichts
    ($this->geraet)($this->childB->id, 'thermomix');
    expect(Schema::hasIndex('foodalchemist_vocab_kitchen_equipment', 'fa_kitchen_equipment_team_slug_uq'))->toBeTrue()
        ->and(Schema::hasIndex('foodalchemist_serving_forms', 'fa_serving_forms_team_code_uq'))->toBeTrue();
});

it('Datenqualität „Servierform unbestimmt“ zählt auch die Mandanten-Kopie von „unbestimmt“', function () {
    ($this->form)(null, 'unbestimmt');
    $kopie = ($this->form)($this->rootTeam->id, 'unbestimmt');
    $gericht = $this->makeRecipe($this->rootTeam, 'Gericht mit Kopie', ['is_sales_recipe' => true]);
    DB::table('foodalchemist_recipe_presentations')->insert(['uuid' => (string) Str::uuid7(), 'team_id' => $this->rootTeam->id, 'recipe_id' => $gericht->id,
        'serving_form_id' => $kopie, 'is_standard' => true, 'created_at' => now(), 'updated_at' => now()]);

    $dq = app(\Platform\FoodAlchemist\Services\DataQualityService::class);
    $methode = new ReflectionMethod($dq, 'unbestimmteServierform');
    $methode->setAccessible(true);
    expect($methode->invoke($dq, $this->rootTeam))->toBe(1);
});

it('MCP-Auflösung einer Servierform nimmt die eigene Kopie vor der globalen Zeile', function () {
    $global = ($this->form)(null, 'teller');
    $eigen = ($this->form)($this->childA->id, 'teller');
    ($this->form)($this->childB->id, 'teller');   // fremde Kopie: nie sichtbar
    $tool = new class extends \Platform\FoodAlchemist\Tools\FoodAlchemistTool
    {
        public function getName(): string { return 'test.servierform'; }
        public function getDescription(): string { return ''; }
        public function getSchema(): array { return ['type' => 'object']; }
        public function execute(array $arguments, \Platform\Core\Contracts\ToolContext $context): \Platform\Core\Contracts\ToolResult { return \Platform\Core\Contracts\ToolResult::success(); }
        public function aufloesen($team, string $w): ?int { return $this->resolveServierformId($team, $w); }
    };

    expect($tool->aufloesen($this->childA, 'teller'))->toBe($eigen)
        ->and($tool->aufloesen($this->rootTeam, 'teller'))->toBe($global);
});

it('Pflegeseite Konzept-Dimensionen: eine Servierform, die ein anderes Team schon hat, darf das eigene Team anlegen', function () {
    config(['foodalchemist.bearbeitungssperre' => false]);
    DB::table('foodalchemist_serving_forms')->insert(['uuid' => (string) Str::uuid7(), 'team_id' => $this->childB->id, 'code' => 'bowl', 'label' => 'Bowl',
        'created_at' => now(), 'updated_at' => now()]);
    $this->actingAs($this->makeUser($this->childA, 'Koch A'));

    \Livewire\Livewire::test(\Platform\FoodAlchemist\Livewire\Settings\ConcepterDimensionen::class)
        ->set('neu.servierformen', 'Bowl')->call('create', 'servierformen')->assertSet('fehler', null);

    expect(DB::table('foodalchemist_serving_forms')->where('code', 'bowl')->pluck('team_id')->map(fn ($t) => (int) $t)->sort()->values()->all())
        ->toBe(collect([$this->childA->id, $this->childB->id])->sort()->values()->all());
});
