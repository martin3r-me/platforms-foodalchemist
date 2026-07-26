<?php

use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Platform\Core\Contracts\LLMProviderContract;
use Platform\FoodAlchemist\Livewire\Recipes\RecipeModal;
use Platform\FoodAlchemist\Livewire\Verkauf\VkModal;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 03 · L6b — Copilot-Fläche in BEIDEN Editoren (RecipeModal + VkModal).
 *
 * Die Service-Hälfte ist in `RecipeReviewServiceTest` bewiesen; hier zählen die
 * drei Dinge, die nur das UI leisten kann:
 *  1. **Beide Flächen existieren** (die L1-Lücke wird nicht wiederholt).
 *  2. **Der Apply ist granular und stoppt, wo der Service Nein sagt** — ein
 *     nicht anwendbarer Befund schreibt nichts, sondern erzeugt eine Fehlzeile.
 *  3. **Übrige Befunde werden nach jedem Apply neu bewertet.** Ein `entfernen`
 *     kann die Zielzeile eines anderen Befunds gerade gelöscht haben — dessen
 *     Knopf darf danach nicht grün bleiben.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam));

    $this->g = \Platform\FoodAlchemist\Models\FoodAlchemistVocabEinheit::create([
        'team_id' => $this->rootTeam->id, 'slug' => 'g', 'display_de' => 'Gramm', 'dimension' => 'mass', 'default_in_g' => 1,
    ]);

    $this->recipe = FoodAlchemistRecipe::create([
        'team_id' => $this->rootTeam->id, 'recipe_key' => 'l6b-basis', 'name' => 'Kartoffelpüree',
        'status' => 'draft', 'preparation' => 'Kochen, stampfen, montieren.',
    ]);

    $zeile = function (string $text, float $menge, int $pos) {
        $gp = $this->makeGp($this->rootTeam, $text);

        return DB::table('foodalchemist_recipe_ingredients')->insertGetId([
            'uuid' => (string) \Symfony\Component\Uid\UuidV7::generate(), 'team_id' => $this->rootTeam->id,
            'recipe_id' => $this->recipe->id, 'gp_id' => $gp->id, 'raw_text' => $text, 'display_name' => $text,
            'quantity' => $menge, 'unit_vocab_id' => $this->g->id, 'position' => $pos,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    };
    $this->idKartoffel = $zeile('Kartoffel', 1000, 1);
    $this->idButter = $zeile('Butter', 80, 2);
});

/** Provider-Stub (eigener Name — Pest lädt alle Testdateien in denselben Namensraum). */
function bindCopilotUiStub(array $befunde, string $urteil = 'Fett zu knapp.'): void
{
    config(['foodalchemist.ai.provider' => 'core']);
    app()->bind(LLMProviderContract::class, fn () => new class($befunde, $urteil) implements LLMProviderContract
    {
        public function __construct(private array $befunde, private string $urteil) {}

        public function getName(): string
        {
            return 'test-stub';
        }

        public function chat(array $messages, array $options = []): array
        {
            return ['content' => json_encode(['werte' => ['befunde' => $this->befunde, 'gesamturteil' => $this->urteil],
                'confidence' => 0.8, 'reasoning' => 'stub']), 'usage' => [], 'model' => 'stub', 'tool_calls' => null];
        }

        public function streamChat(array $messages, callable $onDelta, array $options = []): void
        {
            $onDelta($this->chat($messages, $options)['content']);
        }

        public function getAvailableModels(): array
        {
            return ['stub'];
        }

        public function getDefaultModel(): string
        {
            return 'stub';
        }

        public function isAvailable(): bool
        {
            return true;
        }
    });
}

it('L6b: beide Editoren tragen den Copilot-Knopf', function () {
    $basisHtml = Livewire::test(RecipeModal::class)->call('oeffnen', $this->recipe->id)->html();
    expect($basisHtml)->toContain('data-copilot');

    $vk = FoodAlchemistRecipe::create([
        'team_id' => $this->rootTeam->id, 'recipe_key' => 'l6b-vk', 'name' => 'Püree-Teller',
        'status' => 'draft', 'is_sales_recipe' => true,
    ]);
    $vkHtml = Livewire::test(VkModal::class)->call('oeffnen', $vk->id)->html();
    expect($vkHtml)->toContain('data-vk-copilot');
});

it('L6b: Prüfen füllt die Befunde und rendert Karten — ohne irgendetwas zu schreiben', function () {
    bindCopilotUiStub([
        ['art' => 'menge', 'zutat_id' => $this->idButter, 'quantity' => 120, 'begruendung' => 'Zu wenig Fett.', 'konfidenz' => 0.8],
        ['art' => 'hinweis', 'begruendung' => 'Butter erst nach dem Stampfen einrühren.'],
    ]);

    $test = Livewire::test(RecipeModal::class)
        ->call('oeffnen', $this->recipe->id)
        ->call('copilotPruefen')
        ->assertSet('fehler', null)
        ->assertSet('copilotOffen', true)
        ->assertSet('copilot.gesamturteil', 'Fett zu knapp.')
        ->assertSet('copilot.befunde.0.auto_applicable', true)
        ->assertSet('copilot.befunde.1.status', 'nur_hinweis');

    expect($test->html())->toContain('data-copilot-befund')
        ->and($test->html())->toContain('data-copilot-apply');

    // Read-only: die Zeile steht unverändert (GL-07 I3).
    expect((float) DB::table('foodalchemist_recipe_ingredients')->find($this->idButter)->quantity)->toBe(80.0);
});

it('L6b: ein Befund wird einzeln übernommen — Zeile geschrieben, Karte weg, Editor neu gemountet', function () {
    bindCopilotUiStub([
        ['art' => 'menge', 'zutat_id' => $this->idButter, 'quantity' => 120, 'begruendung' => 'Zu wenig Fett.'],
        ['art' => 'hinweis', 'begruendung' => 'Salz am Ende abschmecken.'],
    ]);

    Livewire::test(RecipeModal::class)
        ->call('oeffnen', $this->recipe->id)
        ->call('copilotPruefen')
        ->assertSet('zutatenVersion', 0)
        ->call('copilotUebernehmen', 0)
        ->assertSet('fehler', null)
        ->assertSet('zutatenVersion', 1)                                // #511-Kette: Zutaten-Editor neu mounten
        ->assertSet('copilot.befunde.0.art', 'hinweis')                 // übernommener Befund ist raus
        ->assertCount('copilot.befunde', 1)
        ->assertDispatched('recipe-gespeichert');

    expect((float) DB::table('foodalchemist_recipe_ingredients')->find($this->idButter)->quantity)->toBe(120.0)
        ->and((float) DB::table('foodalchemist_recipe_ingredients')->find($this->idKartoffel)->quantity)->toBe(1000.0);
});

it('L6b: ein nicht anwendbarer Befund schreibt nichts, sondern erklärt sich', function () {
    bindCopilotUiStub([
        ['art' => 'fehlt', 'zutat_text' => 'Trüffel-Espuma vom Périgord', 'quantity' => 20, 'begruendung' => 'Würde heben.'],
    ]);

    $test = Livewire::test(RecipeModal::class)
        ->call('oeffnen', $this->recipe->id)
        ->call('copilotPruefen')
        ->assertSet('copilot.befunde.0.auto_applicable', false)
        ->assertSet('copilot.befunde.0.status', 'kein_treffer');

    expect($test->html())->toContain('data-copilot-hardstop');

    $test->call('copilotUebernehmen', 0)
        ->assertSet('fehler', 'Befund ist nicht anwendbar — er ist ein Hinweis, kein Auftrag.')
        ->assertCount('copilot.befunde', 1);                            // Karte bleibt stehen

    expect(DB::table('foodalchemist_recipe_ingredients')
        ->where('recipe_id', $this->recipe->id)->whereNull('deleted_at')->count())->toBe(2);
});

it('L6b: «Alle übernehmen» nimmt nur die anwendbaren und lässt den Rest zur Ansicht stehen', function () {
    bindCopilotUiStub([
        ['art' => 'menge', 'zutat_id' => $this->idButter, 'quantity' => 120, 'begruendung' => 'Zu wenig Fett.'],
        ['art' => 'fehlt', 'zutat_text' => 'Trüffel-Espuma vom Périgord', 'quantity' => 20, 'begruendung' => 'Würde heben.'],
        ['art' => 'hinweis', 'begruendung' => 'Butter zuletzt.'],
    ]);

    Livewire::test(RecipeModal::class)
        ->call('oeffnen', $this->recipe->id)
        ->call('copilotPruefen')
        ->call('copilotAlleUebernehmen')
        ->assertSet('fehler', null)
        ->assertCount('copilot.befunde', 2)                             // Hard-Stop + Hinweis bleiben
        ->assertSet('copilot.befunde.0.status', 'kein_treffer')
        ->assertSet('copilot.befunde.1.art', 'hinweis');

    expect((float) DB::table('foodalchemist_recipe_ingredients')->find($this->idButter)->quantity)->toBe(120.0);
});

it('L6b: nach einem «entfernen» wird der Befund auf derselben Zeile neu bewertet (kein toter Knopf)', function () {
    bindCopilotUiStub([
        ['art' => 'entfernen', 'zutat_id' => $this->idButter, 'begruendung' => 'Gehört nicht ins vegane Püree.'],
        ['art' => 'menge', 'zutat_id' => $this->idButter, 'quantity' => 150, 'begruendung' => 'Und mehr davon.'],
    ]);

    Livewire::test(RecipeModal::class)
        ->call('oeffnen', $this->recipe->id)
        ->call('copilotPruefen')
        ->assertSet('copilot.befunde.1.auto_applicable', true)          // vor dem Entfernen anwendbar
        ->call('copilotUebernehmen', 0)
        ->assertSet('fehler', null)
        ->assertSet('copilot.befunde.0.auto_applicable', false)         // danach: Zielzeile existiert nicht mehr
        ->assertSet('copilot.befunde.0.status', 'kein_ziel');

    expect(DB::table('foodalchemist_recipe_ingredients')
        ->where('recipe_id', $this->recipe->id)->whereNull('deleted_at')->count())->toBe(1);
});

it('L6b: die Befunde lecken nicht ins nächste Rezept', function () {
    bindCopilotUiStub([['art' => 'menge', 'zutat_id' => $this->idButter, 'quantity' => 120, 'begruendung' => 'Zu wenig Fett.']]);

    $andere = FoodAlchemistRecipe::create([
        'team_id' => $this->rootTeam->id, 'recipe_key' => 'l6b-zweit', 'name' => 'Rotweinjus', 'status' => 'draft',
    ]);

    Livewire::test(RecipeModal::class)
        ->call('oeffnen', $this->recipe->id)
        ->call('copilotPruefen')
        ->assertSet('copilotOffen', true)
        ->call('oeffnen', $andere->id)
        ->assertSet('copilot', null)
        ->assertSet('copilotOffen', false);
});

it('L6b: das Gericht prüft über denselben Weg (VK-Zweig, Komponenten-Wort in der Fläche)', function () {
    $vk = FoodAlchemistRecipe::create([
        'team_id' => $this->rootTeam->id, 'recipe_key' => 'l6b-vk2', 'name' => 'Püree-Teller',
        'status' => 'draft', 'is_sales_recipe' => true, 'sales_unit_count' => 1, 'sales_quantity_per_unit_g' => 220,
    ]);
    $gp = $this->makeGp($this->rootTeam, 'Kartoffelpüree-Basis');
    $zeileId = DB::table('foodalchemist_recipe_ingredients')->insertGetId([
        'uuid' => (string) \Symfony\Component\Uid\UuidV7::generate(), 'team_id' => $this->rootTeam->id,
        'recipe_id' => $vk->id, 'gp_id' => $gp->id, 'raw_text' => 'Püree', 'display_name' => 'Püree',
        'quantity' => 150, 'unit_vocab_id' => $this->g->id, 'position' => 1, 'role' => 'komponente',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    bindCopilotUiStub([['art' => 'menge', 'zutat_id' => $zeileId, 'quantity' => 200, 'begruendung' => 'Portion zu klein für die Klasse.']]);

    $test = Livewire::test(VkModal::class)
        ->call('oeffnen', $vk->id)
        ->call('copilotPruefen')
        ->assertSet('fehler', null)
        ->assertSet('copilot.befunde.0.auto_applicable', true);

    expect($test->html())->toContain('data-vk-copilot-befunde');

    $test->call('copilotUebernehmen', 0)->assertSet('fehler', null);

    $zeile = DB::table('foodalchemist_recipe_ingredients')->find($zeileId);
    expect((float) $zeile->quantity)->toBe(200.0)
        ->and($zeile->role)->toBe('komponente');                        // Facette der Zeile überlebt (V-027-Umweg)
});

/**
 * Spec 21 · S5b — der Landeplatz des Signals `rezept_plausi_ki`.
 *
 * Der Sprung aus dem Cockpit öffnet das Rezept mit den ABGELEGTEN Befunden, nicht
 * mit einem frischen Prüf-Pass. Das ist keine Sparmaßnahme: das Signal zählt genau
 * die abgelegten Zeilen, ein Live-Pass könnte etwas anderes zeigen — und wäre bei
 * jedem Sprung ein Provider-Call.
 */
it('S5b: der Sprung aus dem Cockpit klappt die abgelegten Befunde auf — ohne Provider-Call', function () {
    // Kein Stub gebunden: ein versehentlicher Prüf-Pass müsste hier scheitern.
    $befund = \Platform\FoodAlchemist\Models\FoodAlchemistRecipeFinding::create([
        'team_id' => $this->rootTeam->id, 'recipe_id' => $this->recipe->id,
        'fingerprint' => sha1('hinweis|butter'), 'kind' => 'hinweis',
        'reason' => 'Butter erst nach dem Stampfen einrühren.', 'confidence' => 0.9,
        'auto_applicable' => false, 'applicability' => 'nur_hinweis', 'status' => 'offen',
        'seen_count' => 1, 'first_seen_at' => now(), 'last_seen_at' => now(),
    ]);

    $test = Livewire::test(RecipeModal::class)
        ->call('oeffnen', $this->recipe->id, true)
        ->assertSet('fehler', null)
        ->assertSet('copilotOffen', true)
        ->assertCount('copilot.befunde', 1)
        ->assertSet('copilot.befunde.0.finding_id', $befund->id)
        ->assertSet('copilot.befunde.0.status', 'nur_hinweis');        // frisch bewertet, nicht aus der Ablage gelesen

    expect($test->html())->toContain('data-copilot-dismiss');

    // „Lass das so" schließt die Zeile dauerhaft (S5a: `verworfen` hält).
    $test->call('copilotBefundVerwerfen', 0)
        ->assertSet('fehler', null)
        ->assertCount('copilot.befunde', 0);

    expect($befund->refresh()->status)->toBe('verworfen')
        ->and($befund->decided_at)->not->toBeNull();
});

it('S5b: ohne abgelegte Befunde bleibt die Fläche zu (kein leerer Kasten)', function () {
    Livewire::test(RecipeModal::class)
        ->call('oeffnen', $this->recipe->id, true)
        ->assertSet('copilotOffen', false)
        ->assertSet('copilot', null)
        ->assertSet('fehler', null);
});

it('S5b: eine Übernahme aus der Ablage schließt auch die Befund-Zeile', function () {
    $befund = \Platform\FoodAlchemist\Models\FoodAlchemistRecipeFinding::create([
        'team_id' => $this->rootTeam->id, 'recipe_id' => $this->recipe->id,
        'fingerprint' => sha1('menge|butter'), 'kind' => 'menge', 'ingredient_id' => $this->idButter,
        'ingredient_text' => 'Butter', 'quantity' => 120, 'reason' => 'Zu wenig Fett.', 'confidence' => 0.85,
        'auto_applicable' => true, 'applicability' => 'anwendbar', 'status' => 'offen',
        'seen_count' => 1, 'first_seen_at' => now(), 'last_seen_at' => now(),
    ]);

    Livewire::test(RecipeModal::class)
        ->call('oeffnen', $this->recipe->id, true)
        ->assertSet('copilot.befunde.0.auto_applicable', true)
        ->call('copilotUebernehmen', 0)
        ->assertSet('fehler', null);

    expect((float) DB::table('foodalchemist_recipe_ingredients')->find($this->idButter)->quantity)->toBe(120.0)
        // Bewusst `uebernommen` statt `verworfen`: greift der Fix nicht, darf der
        // Befund im nächsten Batch wiederkommen (S5a, Entscheidung 2).
        ->and($befund->refresh()->status)->toBe('uebernommen');
});
