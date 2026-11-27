<?php

use Illuminate\Support\Facades\DB;
use Platform\FoodAlchemist\Exceptions\NochVerwendetException;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeDarreichung;
use Platform\FoodAlchemist\Models\FoodAlchemistServierform;
use Platform\FoodAlchemist\Services\Ai\FakeAiProvider;
use Platform\FoodAlchemist\Services\Einstellungen\BehaelterService;
use Platform\FoodAlchemist\Services\Plattform\BulkEnrichService;
use Platform\FoodAlchemist\Services\Pruefung\VollstaendigkeitReportService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * 98 · 3.5 Schritt 6a, Gruppe Prüfung/Stamm/Plattform: Leser der Spiegelfelder am Rezept lesen die Standard-Darreichung.
 * Wie Spec98SpiegelLeserAusgabeTest (Hans): der Spiegel am Rezept wird absichtlich ABWEICHEND gesetzt (direkt in der Tabelle);
 * liest eine Stelle noch den Spiegel, fällt das hier auf. Nach 6c fällt der Spiegel-Teil der Vorbereitung weg, die Erwartung bleibt.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam, 'Root User'));
    $this->teller = FoodAlchemistServierform::create(['team_id' => $this->rootTeam->id, 'code' => 'teller', 'label' => 'Teller']);
    $this->gericht = function (string $key, ?float $standardG, ?float $spiegelG, array $darreichung = [], array $spiegel = []) {
        $r = FoodAlchemistRecipe::create(['team_id' => $this->rootTeam->id, 'recipe_key' => $key, 'name' => ucfirst($key),
            'status' => 'approved', 'is_sales_recipe' => true, 'ek_total_eur' => 10.0, 'yield_kg' => 2.0]);
        FoodAlchemistRecipeDarreichung::create(['team_id' => $this->rootTeam->id, 'recipe_id' => $r->id, 'serving_form_id' => $this->teller->id,
            'is_standard' => true, 'quantity_per_unit_g' => $standardG, 'unit_count' => 1, 'ek_portion' => 2.0, 'sales_net' => 5.0, 'price_mode' => 'auto'] + $darreichung);
        // 98 · 3.5 6c: Spiegel-Teil der Vorbereitung entfällt (Spalten gedroppt), die Erwartungen bleiben

        return $r->fresh();
    };
});

it('Vollständigkeits-Report: „ohne Portion“ und die Beispiel-Grammatur kommen aus der Standard-Darreichung, nicht aus dem Spiegel', function () {
    ($this->gericht)('mit_standard', 250.0, null);      // Spiegel leer, Darreichung hat Grammatur
    ($this->gericht)('ohne_standard', null, 999.0);     // Spiegel gesetzt, Darreichung ohne Grammatur

    $a = app(VollstaendigkeitReportService::class)->messe($this->rootTeam, 10)['a_vk_vorbedingungen'];
    $g = collect($a['beispiele_ohne_klasse'])->keyBy('name');

    expect($a['ohne_portion_am_rezept'])->toBe(1)                 // nur „ohne_standard“ (vorher ebenfalls 1, aber „mit_standard“)
        ->and($g['Mit_standard']['portion_g'])->toBe(250.0)       // vorher null (Spiegel)
        ->and($g['Ohne_standard']['portion_g'])->toBeNull();      // vorher 999 (Spiegel)
});

it('Bulk-Anreicherung: Plating-Kontext trägt die Grammatur der Standard-Darreichung, nicht den Spiegel', function () {
    config(['foodalchemist.ai.provider' => 'fake', 'foodalchemist.ai.backoff' => []]);
    $gesehen = new ArrayObject;
    app()->singleton(FakeAiProvider::class, fn () => new class($gesehen) extends FakeAiProvider
    {
        public function __construct(private ArrayObject $gesehen) {}

        public function chat(array $messages, array $options = []): array
        {
            $user = (string) collect($messages)->last()['content'];
            $this->gesehen[] = $user;

            return ['content' => json_encode(['werte' => str_contains($user, '"portion_g"') ? ['preparation' => 'Anrichten.'] : [], 'confidence' => 0.9]), 'model' => 'fake-6a', 'usage' => []];
        }
    });
    $r = ($this->gericht)('ragout', 250.0, 999.0);

    app(BulkEnrichService::class)->starte($this->rootTeam, [$r->id], ['plating'], \Platform\FoodAlchemist\Enums\BulkRunType::EnrichVk);

    $plating = collect($gesehen->getArrayCopy())->first(fn ($u) => str_contains($u, '"portion_g"'));
    expect($plating)->not->toBeNull()
        ->and($plating)->toMatch('/"portion_g":\s*"?250/')
        ->and($plating)->not->toContain('999');
});

it('Behälter-Lösch-Schutz: zählt die Darreichung, nicht den Rezept-Spiegel', function () {
    $svc = app(BehaelterService::class);
    $svc->anlegen($this->rootTeam, 'behaelter', ['name' => 'GN 1/1 tief']);
    $svc->anlegen($this->rootTeam, 'behaelter', ['name' => 'GN 1/2 flach']);
    $nurSpiegel = DB::table('foodalchemist_vocab_containers')->where('name', 'GN 1/1 tief')->value('id');
    $anDarreichung = DB::table('foodalchemist_vocab_containers')->where('name', 'GN 1/2 flach')->value('id');
    ($this->gericht)('suppe', 300.0, 300.0, ['container_warm_vocab_id' => $anDarreichung], ['container_warm_vocab_id' => $nurSpiegel]);

    expect($svc->loeschen($this->rootTeam, 'behaelter', $nurSpiegel))->toBe('GN 1/1 tief')   // vorher NochVerwendet (Spiegel)
        ->and(fn () => $svc->loeschen($this->rootTeam, 'behaelter', $anDarreichung))->toThrow(NochVerwendetException::class);
});
