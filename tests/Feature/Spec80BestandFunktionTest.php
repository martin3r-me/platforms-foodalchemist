<?php

use Illuminate\Support\Facades\DB;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Services\RecipeGeneratorService;
use Platform\FoodAlchemist\Services\RecipeService;
use Platform\FoodAlchemist\Support\BestandsPassung;
use Platform\FoodAlchemist\Support\RezeptTypVokabular;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;
use Symfony\Component\Uid\UuidV7;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 80 B3/B4 — Bestand = nur freigegebene Basisrezepte, und ein Treffer muss funktional passen.
 * Das Typ-Vokabular kommt aus dem Wissens-Dossier §1.2 (hier ein Auszug als Fixture), nicht aus dem Code.
 */
const SPEC80_VOKABULAR_MD = <<<'MD'
# Regelwerk Basisrezepte — §1.2 Typ-Vokabular je Hauptgruppe

| Hauptgruppe (recipe_hauptgruppen) | Erlaubte Typen |
|---|---|
| Fonds & Reduktionen | `Fond`, `Jus`, `Demi-Glace`, `Glace`, `Sud`, `Reduktion`, `Essenz`, `Consommé`, `Brühe` |
| Saucen | `Sauce`, `Schaumsauce`, `Buttersauce`, `Beurre Blanc`, `Hollandaise` |
| Pürees & Marken | `Püree`, `Mark`, `Coulis` |
| Beilagen | `Beilage`, `Risotto`, `Polenta`, `Gnocchi`, `Knödel` *(+ Eigenname-Gerichte)* |
| Knusprige Komponenten | `Crumble`, `Tuile`, `Chip`, `Krokant`, `Crunch` |
| Aromen & Öle | `Aromaöl`, `Öl`, `Aroma`, `Extrakt`, `Tinktur` |
| Sonstiges | (kein fester Typ — Eigenname zulässig) |
MD;

beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->mkVokabular = function (): void {
        DB::table('foodalchemist_knowledge_documents')->insert([
            'uuid' => (string) UuidV7::generate(),
            'slug' => 'regelwerk-basisrezepte-10-12-naming-grundprinzip-typ-vokabular--1-2-typ-vokabular-kontrolliert',
            'title' => '§1.2 Typ-Vokabular', 'category' => 'regelwerk', 'content_md' => SPEC80_VOKABULAR_MD,
            'version' => 3, 'content_hash' => hash('sha256', SPEC80_VOKABULAR_MD), 'char_count' => strlen(SPEC80_VOKABULAR_MD),
            'active' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
        RezeptTypVokabular::vergessen();
    };
    $this->mkRezept = fn (string $name, string $status, array $extra = []) => FoodAlchemistRecipe::create([
        'team_id' => $this->rootTeam->id, 'recipe_key' => 'k' . md5($name . $status . microtime()), 'name' => $name, 'status' => $status,
    ] + $extra);
});

it('liest das Typ-Vokabular aus der Regel (Spec 81 Seed, Sonstiges ohne Typ fehlt)', function () {
    expect(RezeptTypVokabular::istGeladen())->toBeTrue();   // Seed per Migration — kein Dossier nötig

    expect(RezeptTypVokabular::tabelle())->toHaveKey('Beilagen')->not->toHaveKey('Sonstiges')
        ->and(RezeptTypVokabular::tabelle()['Beilagen'])->toBe(['Beilage', 'Risotto', 'Polenta', 'Gnocchi', 'Knödel'])
        ->and(RezeptTypVokabular::finde('püree'))->toBe('Püree')
        ->and(RezeptTypVokabular::typImText('Petersilienpüree'))->toBe('Püree')
        ->and(RezeptTypVokabular::typImText('Cremige Polenta'))->toBe('Polenta')
        ->and(RezeptTypVokabular::typImText('Kalbsfond'))->toBe('Fond')
        ->and(RezeptTypVokabular::typImText('Petersilienmatte'))->toBe('Matte');   // „Matte" seit 09.10. im Vokabular
});

it('Funktionsprüfung lehnt die Fehlzuordnungen von demo ab', function () {
    ($this->mkVokabular)();

    expect(BestandsPassung::grund('Petersilienmatte', 'Garnitur: Kräutermatte Petersilie (Vegan)'))->toContain('Garnitur')
        ->and(BestandsPassung::grund('Cremige Polenta', 'Chip: Polenta'))->toContain('Chip')
        ->and(BestandsPassung::grund('Rote-Bete-Püree', 'Püree: Kartoffel-Rote-Bete'))->toContain('kartoffel')
        ->and(BestandsPassung::grund('Dashi-Beurre-blanc', 'Beurre Blanc'))->toContain('dashi')
        ->and(BestandsPassung::grund('Gemüsefond', 'Fond: Gemüse', true, false, ['vegetarisch']))->toContain('vegetarisch');
});

it('Funktionsprüfung lässt echte Treffer durch', function () {
    ($this->mkVokabular)();

    expect(BestandsPassung::grund('Kalbsfond', 'Fond: Kalb'))->toBeNull()
        ->and(BestandsPassung::grund('Pilzjus', 'Jus: Pilz'))->toBeNull()
        ->and(BestandsPassung::grund('Petersilienwurzelpüree', 'Püree: Petersilienwurzel'))->toBeNull()
        ->and(BestandsPassung::grund('Brauner Kalbsfond', 'Demi-Glace: Kalb'))->toBeNull()
        ->and(BestandsPassung::grund('Hollandaise', 'Sauce: Hollandaise'))->toBeNull()
        ->and(BestandsPassung::grund('Gemüsefond', 'Fond: Gemüse', null, null, ['vegan']))->toBeNull()   // unbekannt blockiert nicht
        ->and(BestandsPassung::grund('', 'Fond: Kalb'))->toBeNull();
});

it('ohne Vokabular greifen nur Bestandteil- und Diät-Prüfung', function () {
    $regel = \Platform\FoodAlchemist\Models\FoodAlchemistRule::where('schluessel', RezeptTypVokabular::REGEL)->firstOrFail();
    app(\Platform\FoodAlchemist\Services\Regeln\RegelService::class)->setzeAktiv($regel->id, false);   // Regel aus = kein Vokabular
    // Die Bestandteil-Prüfung allein erkennt die Kräutermatte schon (Hauptbestandteil fehlt in der Zeile).
    expect(BestandsPassung::grund('Petersilienmatte', 'Garnitur: Kräutermatte Petersilie (Vegan)'))->toContain('kraeutermatte')
        ->and(BestandsPassung::grund('Rote-Bete-Püree', 'Püree: Kartoffel-Rote-Bete'))->toContain('kartoffel')
        ->and(BestandsPassung::grund('Pesto, frisch', 'Pesto: Basilikum'))->toBeNull()
        ->and(BestandsPassung::grund('', 'Fond: Gemüse-Speck', null, false, ['vegetarisch']))->toContain('vegetarisch');
});

it('Übernahme per Namen findet nur freigegebene Basisrezepte (Entscheid 2026-10-09)', function () {
    $svc = app(RecipeService::class);
    ($this->mkRezept)('Püree: Petersilienwurzel', 'draft');

    expect($svc->findByTokenSetMitReife($this->rootTeam, 'Püree: Petersilienwurzel'))->toBeNull();

    $frei = ($this->mkRezept)('Püree: Petersilienwurzel', 'approved');
    expect($svc->findByTokenSetMitReife($this->rootTeam, 'Püree: Petersilienwurzel')['recipe']->id)->toBe($frei->id);
});

it('Generator: vorgeschlagenes Unterrezept muss freigegeben sein und funktional passen, Ablehnung mit Grund', function () {
    ($this->mkVokabular)();
    $eltern = ($this->mkRezept)('Püree: Petersilienwurzel (grün)', 'draft');
    $entwurf = ($this->mkRezept)('Püree: Petersilienwurzel', 'draft');
    $frei = ($this->mkRezept)('Püree: Petersilienwurzel', 'approved');
    $gelblatt = ($this->mkRezept)('Garnitur: Kräutermatte Petersilie (Vegan)', 'approved');

    $gen = app(RecipeGeneratorService::class);
    $abgelehnt = [];
    $call = function ($id, $text) use ($gen, $eltern, &$abgelehnt) {
        return Closure::bind(function ($team, $id, $text) use ($eltern, &$abgelehnt) {
            return $this->validiereProposedSub($team, (int) $eltern->id, $id, $text, [], $abgelehnt);
        }, $gen, RecipeGeneratorService::class)($this->rootTeam, $id, $text);
    };

    expect($call($entwurf->id, 'Petersilienwurzelpüree'))->toBeNull()          // Entwurf ist kein Bestand
        ->and($call($frei->id, 'Petersilienwurzelpüree'))->toBe($frei->id)
        ->and($call($gelblatt->id, 'Petersilienmatte'))->toBeNull()
        ->and($abgelehnt)->toHaveCount(1)
        ->and($abgelehnt[0]['recipe_id'])->toBe($gelblatt->id)
        ->and($abgelehnt[0]['grund'])->toContain('Garnitur');
});

it('Live-Test demo 09.10.: der Matcher entscheidet nie für Entwürfe oder einen fremden Typ', function () {
    $this->makeRecipe($this->rootTeam, 'Wurzel-Püree: Petersilienwurzel', ['status' => 'review']);
    $gel = $this->makeRecipe($this->rootTeam, 'Gel: Petersilie');   // freigegeben, aber andere Typ-Gruppe
    $matcher = app(\Platform\FoodAlchemist\Services\IngredientMatchService::class);

    expect($matcher->matchIngredient($this->rootTeam, 'Püree: Petersilienwurzel')['target'] ?? null)->not->toBe('sub_recipe')
        ->and($matcher->matchIngredient($this->rootTeam, 'Matte: Petersilie')['recipe_id'] ?? null)->not->toBe($gel->id)
        ->and(collect($matcher->candidatesFor($this->rootTeam, 'Püree: Petersilienwurzel', null, 5))->pluck('name')->implode(' '))
        ->toContain('Wurzel-Püree: Petersilienwurzel');   // Vorschlagsliste zeigt Entwürfe weiter
});
