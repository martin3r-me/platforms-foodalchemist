<?php

use Illuminate\Support\Facades\DB;
use Platform\FoodAlchemist\Console\EquipmentStandardSetzenCommand;
use Platform\FoodAlchemist\Models\FoodAlchemistVocabKochequipment;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 82 §6: Standardausstattung per Befehl (nicht per Migration), idempotent, Trockenlauf, nach Name.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->eq = fn (string $name, bool $standard = false) => FoodAlchemistVocabKochequipment::create([
        'team_id' => $this->rootTeam->id, 'slug' => \Illuminate\Support\Str::slug($name), 'name' => $name, 'is_standard' => $standard,
    ]);
    $this->standard = fn () => DB::table('foodalchemist_vocab_kitchen_equipment')->where('is_standard', 1)->orderBy('name')->pluck('name')->all();
});

it('setzt die 15 Namen (Groß-/Kleinschreibung und Umlaute egal), alles andere bleibt aus', function () {
    ($this->eq)('Topf');
    ($this->eq)('Bräter');
    ($this->eq)('MÖRSER');
    ($this->eq)('Chinois / Passiersieb');
    ($this->eq)('Kombidämpfer');
    ($this->eq)('Thermomix');

    $this->artisan('foodalchemist:equipment-standard-setzen')->assertSuccessful();

    expect(($this->standard)())->toBe(['Bräter', 'Chinois / Passiersieb', 'MÖRSER', 'Topf']);
});

it('ist idempotent: zweiter Lauf ändert nichts; ein fälschlich gesetztes Flag wird zurückgenommen', function () {
    ($this->eq)('Topf');
    ($this->eq)('Kombidämpfer', true);     // Altlast, steht nicht auf der Liste

    $this->artisan('foodalchemist:equipment-standard-setzen')->expectsOutputToContain('zurückgesetzt:      Kombidämpfer')->assertSuccessful();
    expect(($this->standard)())->toBe(['Topf']);

    $this->artisan('foodalchemist:equipment-standard-setzen')->expectsOutputToContain('schon gesetzt:      Topf')->assertSuccessful();
    expect(($this->standard)())->toBe(['Topf']);
});

it('Trockenlauf schreibt nichts und nennt nicht gefundene Namen', function () {
    ($this->eq)('Topf');

    $this->artisan('foodalchemist:equipment-standard-setzen', ['--dry-run' => true])
        ->expectsOutputToContain('[Trockenlauf]')->expectsOutputToContain('neu gesetzt:        Topf')
        ->expectsOutputToContain('nicht gefunden:')->assertSuccessful();

    expect(($this->standard)())->toBe([]);
});

it('die Liste hat genau die 15 bestätigten Einträge', function () {
    expect(EquipmentStandardSetzenCommand::STANDARD)->toHaveCount(15)->toContain('Stabmixer', 'Mandoline / Hobel', 'Kuchenform');
});
