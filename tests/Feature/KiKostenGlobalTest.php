<?php

use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Platform\FoodAlchemist\Livewire\Settings\Ki;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * „Nutzung und Kosten“ zeigte auf io 4 Aufrufe für 0,18 $, obwohl die Non-Food-Erkennung 629 Aufrufe für 26,30 $ verbraucht hatte: die globalen
 * Stamm-Jobs (la.nonfood, produktpass.extract) stehen mit team_id NULL im Log, die Abfrage filterte `team_id = aktuelles Team`.
 * Jetzt sieht das Master-Team (pflegt den globalen Stamm) sie als eigene Zeilen „Global (Stamm)“; ein anderes Team nie.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    config(['foodalchemist.master_team_id' => $this->rootTeam->id, 'foodalchemist.ai.provider' => 'fake']);
    $this->masterUser = $this->makeUser($this->rootTeam, 'Master Owner', 'owner');
    $this->fremdUser = $this->makeUser($this->childB, 'Fremd Owner', 'owner');

    $this->log = fn (?int $team, string $feature, array $extra = []) => DB::table('foodalchemist_ai_call_log')->insert($extra + [
        'uuid' => (string) Symfony\Component\Uid\UuidV7::generate(), 'team_id' => $team, 'user_id' => null, 'feature' => $feature, 'tier' => 'B',
        'model' => 'gpt-5.5-2026-04-23', 'tokens_in' => 0, 'tokens_cached' => 0, 'tokens_out' => 0, 'created_at' => now(), 'updated_at' => now(),
    ]);
    $this->nutzungstabelle = function (string $html): string {
        $von = strpos($html, 'data-ki-statistik');

        return $von === false ? '' : substr($html, $von, strpos($html, '</table>', $von) - $von);
    };
    // eigener Aufruf des Master-Teams, globaler Non-Food-Aufruf (1 Mio rein/raus = 35,00 $ bei gpt-5.5), globaler FEHLER-Aufruf ohne Modell, Aufruf des fremden Teams
    ($this->log)($this->rootTeam->id, 'recipe.description');
    ($this->log)(null, 'la.nonfood', ['tokens_in' => 1_000_000, 'tokens_out' => 1_000_000]);
    ($this->log)(null, 'la.nonfood', ['model' => null, 'error' => 'Provider 503']);
    ($this->log)($this->rootTeam->id, 'la.nonfood');                      // dasselbe Feature/Modell, aber vom Team selbst: darf nicht in die globale Zeile fallen
    ($this->log)($this->childB->id, 'recipe.steps', ['tokens_in' => 1_000_000, 'tokens_out' => 1_000_000]);
});

it('Master sieht die globalen Stamm-Aufrufe als „Global (Stamm)“, Summe stimmt, Fehler ohne Modell zählen als Fehler mit', function () {
    $this->actingAs($this->masterUser);

    $c = Livewire::test(Ki::class)
        ->assertSeeHtml('data-ki-global')->assertSee('Global (Stamm)')
        ->assertSee('35,00 $')                                  // nur der globale Aufruf kostet; der des fremden Teams (35 $) ist NICHT dabei
        ->assertSee('Embeddings')->assertSee('nicht enthalten');
    $tabelle = ($this->nutzungstabelle)($c->html());             // die Registry-Liste weiter oben nennt alle Schlüssel, geprüft wird nur die Nutzungstabelle
    expect(substr_count($tabelle, 'data-global="1"'))->toBe(2)  // la.nonfood mit Modell + la.nonfood als Fehler (ohne Modell)
        ->and(substr_count($tabelle, 'data-global="0"'))->toBe(2) // die eigenen Aufrufe (recipe.description, la.nonfood des Teams) bleiben getrennt von den globalen
        ->and($tabelle)->toContain('la.nonfood')->not->toContain('recipe.steps');   // Gegenprobe: das fremde Team bleibt draußen

    // die Fehler-Zeile: 1 Aufruf, 1 Fehler, kein Modell
    $zeile = DB::table('foodalchemist_ai_call_log')->whereNull('team_id')->whereNull('model')->selectRaw('COUNT(*) AS n, SUM(CASE WHEN error IS NOT NULL THEN 1 ELSE 0 END) AS e')->first();
    expect([(int) $zeile->n, (int) $zeile->e])->toBe([1, 1]);
    $c->assertSeeHtml('text-[var(--fa-crit)] font-medium');      // Fehlerzähler wird rot ausgewiesen
});

it('Gegenprobe: ein anderes Team sieht die globalen Aufrufe nie und zahlt nicht für sie', function () {
    $this->actingAs($this->fremdUser);

    $c = Livewire::test(Ki::class)->assertDontSeeHtml('data-ki-global')->assertDontSee('Global (Stamm)')->assertSee('35,00 $');   // nur der eigene Aufruf
    $tabelle = ($this->nutzungstabelle)($c->html());
    expect(substr_count($tabelle, 'data-global="1"'))->toBe(0)
        ->and($tabelle)->toContain('recipe.steps')->not->toContain('la.nonfood')->not->toContain('recipe.description');
});
