<?php

use Platform\FoodAlchemist\Enums\SignalSeverity;
use Platform\FoodAlchemist\Enums\SignalTyp;
use Platform\FoodAlchemist\Models\FoodAlchemistSignal;
use Platform\FoodAlchemist\Services\DataQualityService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 21 · S0 — Fundament der Rezept-Inhalts-Qualität.
 *
 * Prüft das Gerüst, auf dem Tranche A (S1) aufsetzt: die Enum-Typen, die neue
 * Ebene `rezeptqualitaet` im DataQualityService, den fixen Schweregrad je Check
 * und die betroffene()/countFor()-Auflösung. Jeder Check braucht Positiv- UND
 * Negativfall — ein Check, der sauber gebaute Rezepte mitflaggt, macht das
 * Cockpit schlechter statt besser.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->dq = app(DataQualityService::class);
});

/** Metrik über alle Ebenen hinweg per key finden. */
function rqMetrik(array $ebenen, string $key): array
{
    foreach ($ebenen as $ebene) {
        foreach ($ebene['metriken'] as $m) {
            if ($m['key'] === $key) {
                return $m;
            }
        }
    }
    throw new RuntimeException("Metrik {$key} nicht gefunden");
}

it('hat für jeden Signal-Typ Label und Icon (Enum-Vollständigkeit)', function () {
    foreach (SignalTyp::cases() as $typ) {
        expect($typ->label())->not->toBe('')
            ->and($typ->icon())->toStartWith('heroicon-o-');
    }

    // Tranche A ist vollständig angelegt (S1 füllt nur noch die Checks).
    $rezept = array_filter(SignalTyp::cases(), fn (SignalTyp $t) => $t->istRezeptQualitaet());
    expect($rezept)->toHaveCount(11)
        ->and(SignalTyp::EkKetteUnvollstaendig->istRezeptQualitaet())->toBeFalse();
});

it('führt die Ebene Rezept-Qualität getrennt von den Kaskaden-Ebenen', function () {
    $e = $this->dq->messeAlleEbenen($this->rootTeam);

    expect($e)->toHaveKey('rezeptqualitaet')
        ->and($e['rezeptqualitaet']['label'])->toBe('Rezept-Qualität')
        ->and($e['rezeptqualitaet']['metriken'])->not->toBeEmpty();
});

it('flaggt Rezepte mit ungemappten Zutaten — und saubere nicht', function () {
    $gp = $this->makeGp($this->rootTeam, 'Zanderfilet');

    // sauber: alle Zutaten gemappt
    $ok = $this->makeRecipe($this->rootTeam, 'Sauber');
    $this->makeIngredient($ok, 'Zander', $gp);

    // auffällig: eine Zutat ohne GP-Mapping
    $kaputt = $this->makeRecipe($this->rootTeam, 'Ungemappt', ['n_ingredients_unmapped' => 1]);
    $this->makeIngredient($kaputt, 'Irgendwas Unbekanntes', null);

    $m = rqMetrik($this->dq->messeAlleEbenen($this->rootTeam), 'rezept_zutaten_ungemappt');

    expect($m['wert'])->toBe(1)                    // nur das kaputte, nicht das saubere
        ->and($m['severity'])->toBe('gelb')
        ->and($m['signal']['typ'])->toBe(SignalTyp::RezeptZutatenUngemappt)
        ->and($m['signal']['sev'])->toBe(SignalSeverity::Warnung);   // fixer Schweregrad statt Mengen-Heuristik
});

it('meldet grün, wenn kein Rezept ungemappte Zutaten hat', function () {
    $gp = $this->makeGp($this->rootTeam, 'Lachs');
    $ok = $this->makeRecipe($this->rootTeam, 'Ganz sauber');
    $this->makeIngredient($ok, 'Lachs', $gp);

    $m = rqMetrik($this->dq->messeAlleEbenen($this->rootTeam), 'rezept_zutaten_ungemappt');

    expect($m['wert'])->toBe(0)->and($m['severity'])->toBe('gruen');
});

it('leakt Rezepte fremder Teams nicht in die Messung', function () {
    $this->makeRecipe($this->childA, 'Fremd', ['n_ingredients_unmapped' => 3]);

    $m = rqMetrik($this->dq->messeAlleEbenen($this->childB), 'rezept_zutaten_ungemappt');

    expect($m['wert'])->toBe(0);
});

it('löst die betroffenen Rezepte auf (betroffene/countFor teilen dieselbe Query)', function () {
    $this->makeRecipe($this->rootTeam, 'Alpha', ['n_ingredients_unmapped' => 2]);
    $this->makeRecipe($this->rootTeam, 'Beta', ['n_ingredients_unmapped' => 1]);
    $this->makeRecipe($this->rootTeam, 'Gamma');   // sauber → darf nicht auftauchen

    $items = $this->dq->betroffene($this->rootTeam, 'rezept_zutaten_ungemappt');

    expect($items)->toHaveCount(2)
        ->and(array_column($items, 'name'))->toBe(['Alpha', 'Beta'])
        ->and($items[0]['kind'])->toBe('recipe')
        ->and($this->dq->countFor($this->rootTeam, 'rezept_zutaten_ungemappt'))->toBe(2);
});

it('emittiert das Rezept-Qualitätssignal idempotent in die Inbox', function () {
    $this->makeRecipe($this->rootTeam, 'Delta', ['n_ingredients_unmapped' => 1]);

    $this->dq->emittiereSignale($this->rootTeam);
    $this->dq->emittiereSignale($this->rootTeam);   // zweiter Lauf: aktualisiert statt dupliziert

    $sig = FoodAlchemistSignal::where('team_id', $this->rootTeam->id)
        ->where('type', SignalTyp::RezeptZutatenUngemappt->value)->get();

    expect($sig)->toHaveCount(1)
        ->and($sig->first()->severity)->toBe(SignalSeverity::Warnung)
        ->and($sig->first()->payload['metrik'])->toBe('rezept_zutaten_ungemappt');
});
