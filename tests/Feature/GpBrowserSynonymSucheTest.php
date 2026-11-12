<?php

use Livewire\Livewire;
use Platform\FoodAlchemist\Livewire\Gps\Browser;
use Platform\FoodAlchemist\Models\FoodAlchemistTerminologyAlias;
use Platform\FoodAlchemist\Services\GpService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * 12.11.2026 (Dominique suchte „Anchovis“ und fand das Sardellen-GP nicht): die GP-Browser-Suche kennt die
 * Terminologie-Alias-Gruppen. Trifft ein Suchwort genau ein Gruppenmitglied, werden die anderen Mitglieder
 * mitgesucht (ODER, am Wortanfang); das UND zwischen mehreren Suchwörtern bleibt.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam));
    $this->sardelle = $this->makeGp($this->rootTeam, 'Sardellenfilet: konserviert');
    $this->makeGp($this->rootTeam, 'Thunfisch: konserviert');
    $this->makeGp($this->rootTeam, 'Sardellenpaste: frisch');
    $this->namen = fn (string $suche): array => app(GpService::class)->paginateBrowser(['search' => $suche], $this->rootTeam)->getCollection()->pluck('name')->all();
});

it('ohne Alias-Gruppe findet „anchovis“ das Sardellen-GP nicht (Gegentest)', function () {
    expect(($this->namen)('anchovis'))->toBe([]);
});

it('mit Alias-Gruppe [sardelle, anchovis] findet „anchovis“ das Sardellenfilet — auch in Groß-/Kleinschreibung und im Browser', function () {
    FoodAlchemistTerminologyAlias::create(['team_id' => null, 'members' => ['sardelle', 'anchovis']]);

    expect(($this->namen)('anchovis'))->toBe(['Sardellenfilet: konserviert', 'Sardellenpaste: frisch'])
        ->and(($this->namen)('Anchovis'))->toBe(['Sardellenfilet: konserviert', 'Sardellenpaste: frisch'])
        ->and(app(GpService::class)->paginateBrowser(['search' => 'anchovis'], $this->rootTeam)->total())->toBe(2);   // Zähler = Zeilen

    Livewire::test(Browser::class)->set('search', 'anchovis')
        ->assertSee('Sardellenfilet: konserviert')->assertDontSee('Thunfisch: konserviert');
});

it('Mehrwort-UND bleibt: „anchovis konserviert“ trifft nur das konservierte GP, „anchovis tk“ nichts', function () {
    FoodAlchemistTerminologyAlias::create(['team_id' => null, 'members' => ['sardelle', 'anchovis']]);

    expect(($this->namen)('anchovis konserviert'))->toBe(['Sardellenfilet: konserviert'])
        ->and(($this->namen)('konserviert anchovis'))->toBe(['Sardellenfilet: konserviert'])
        ->and(($this->namen)('anchovis tk'))->toBe([])
        ->and(($this->namen)('konserviert'))->toBe(['Sardellenfilet: konserviert', 'Thunfisch: konserviert']);   // ohne Alias-Wort unverändert
});

it('Mehrwort-Mitglied: „rote bete“ findet „Rande“, die Alternative trifft nur am Wortanfang', function () {
    $this->makeGp($this->rootTeam, 'Rande: frisch, ganz');
    $this->makeGp($this->rootTeam, 'Rote Bete: frisch, ganz');
    $this->makeGp($this->rootTeam, 'Grande-Mix: frisch');   // enthält „rande“ mitten im Wort → kein Alias-Treffer

    expect(($this->namen)('rote bete'))->toBe(['Rande: frisch, ganz', 'Rote Bete: frisch, ganz'])
        ->and(($this->namen)('rande'))->toBe(['Grande-Mix: frisch', 'Rande: frisch, ganz', 'Rote Bete: frisch, ganz']);   // das Originalwort bleibt Teilstring
});
