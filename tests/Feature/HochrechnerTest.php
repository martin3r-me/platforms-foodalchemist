<?php

use Livewire\Livewire;
use Platform\FoodAlchemist\Livewire\Recipes\DetailPanel as RezeptDetail;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeIngredient;
use Platform\FoodAlchemist\Models\FoodAlchemistVocabEinheit;
use Platform\FoodAlchemist\Services\RecipeService;
use Platform\FoodAlchemist\Services\ReportExportService;
use Platform\FoodAlchemist\Support\HandyLesemodus;
use Platform\FoodAlchemist\Support\Hochrechnung;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * #1003 Hochrechnen am Basisrezept („2 kg mehr Reis"): Feld „auf ___ kg" oder „nach Zutat", nur Ansicht —
 * dieselbe Rechnung wie das Dokument (Support\Hochrechnung). Am Computer zusätzlich „Als neuen Ansatz übernehmen"
 * über den Speicherweg des Zutaten-Editors (syncIngredients) und des Rezept-Editors (update, gepflegte Ausbeute).
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->user = $this->makeUser($this->rootTeam);
    $this->actingAs($this->user);
    $this->svc = app(RecipeService::class);

    $t = $this->rootTeam->id;
    $this->g = FoodAlchemistVocabEinheit::ohneTeamSicht('test')->firstOrCreate(['team_id' => $t, 'slug' => 'g'], ['display_de' => 'Gramm', 'dimension' => 'mass', 'default_in_g' => 1]);
    $this->kg = FoodAlchemistVocabEinheit::ohneTeamSicht('test')->firstOrCreate(['team_id' => $t, 'slug' => 'kg'], ['display_de' => 'Kilogramm', 'dimension' => 'mass', 'default_in_g' => 1000]);
    $this->stk = FoodAlchemistVocabEinheit::ohneTeamSicht('test')->firstOrCreate(['team_id' => $t, 'slug' => 'stk'], ['display_de' => 'Stück', 'dimension' => 'count']);

    $this->unter = $this->svc->create($this->rootTeam, ['name' => 'Fond: Gemüse HOCH']);
    $this->svc->syncIngredients($this->rootTeam, $this->unter->id, [
        ['id' => null, 'gp_id' => $this->makeGp($this->rootTeam, 'Möhre: frisch, ganz')->id, 'raw_text' => 'Möhre', 'quantity' => '1000', 'unit_vocab_id' => $this->g->id],
    ]);

    $this->reis = $this->svc->create($this->rootTeam, ['name' => 'Reis: gekocht HOCH']);
    $this->svc->syncIngredients($this->rootTeam, $this->reis->id, [
        ['id' => null, 'gp_id' => $this->makeGp($this->rootTeam, 'Reis: Basmati, trocken')->id, 'raw_text' => 'Reis', 'quantity' => '20', 'unit_vocab_id' => $this->kg->id],
        ['id' => null, 'gp_id' => $this->makeGp($this->rootTeam, 'Salz: Meersalz, fein')->id, 'raw_text' => 'Salz', 'quantity' => '200', 'quantity_max' => '250', 'unit_vocab_id' => $this->g->id],
        ['id' => null, 'gp_id' => $this->makeGp($this->rootTeam, 'Eier: frisch')->id, 'raw_text' => 'Eier', 'quantity' => '12', 'unit_vocab_id' => $this->stk->id],
        ['id' => null, 'referenced_recipe_id' => $this->unter->id, 'raw_text' => 'Gemüsefond', 'quantity' => '500', 'unit_vocab_id' => $this->g->id],
    ]);
    // Reis nimmt Wasser auf: gepflegte Ausbeute 50 kg, errechnet deutlich weniger
    $this->reis->update(['yield_kg_manual' => 50]);
    $this->reis->refresh();
});

function hochZutaten(FoodAlchemistRecipe $r): array
{
    return FoodAlchemistRecipeIngredient::where('recipe_id', $r->id)->orderBy('position')->get()
        ->map(fn ($z) => [$z->id, (float) $z->quantity, $z->quantity_max === null ? null : (float) $z->quantity_max, (string) $z->updated_at])->all();
}

it('Ansicht und Dokument rechnen gleich: Faktor = Ziel ÷ gepflegte Ausbeute (A-3), Mengen wie im Dokument gekürzt', function () {
    expect((float) $this->reis->yield_kg)->not->toBe(50.0);   // errechnet ≠ gepflegt, sonst prüft der Test nichts

    $report = app(ReportExportService::class);
    $doku = $report->rezeptDaten($this->rootTeam, $this->reis->id, $report->optionen(['ziel_kg' => '52'], 'recipe'));
    expect($doku['hochrechnung']['faktor'])->toEqualWithDelta(1.04, 1e-9);
    $reisZeile = collect($doku['recipe']['ingredients'])->firstWhere('raw_text', 'Reis');

    Livewire::test(RezeptDetail::class, ['recipeId' => $this->reis->id])
        ->assertSet('hochZielKg', '50')                          // vorbelegt mit dem Ansatz
        ->assertSeeHtml('data-fa-hochrechner')->assertDontSeeHtml('data-fa-hoch-faktor')
        ->set('hochZielKg', '52')
        ->assertSeeHtml('data-fa-hoch-faktor')->assertSee('×1,04')
        ->assertSee(Hochrechnung::kurz($reisZeile['menge']))    // 20,8 kg — derselbe Text wie im Dokument
        ->assertSee('20,8 kg')->assertSee('208 g – 260 g')     // quantity_max skaliert mit
        ->assertSee('statt 20 kg');
});

it('Gegenprobe: ohne gepflegte Ausbeute rechnen Ansicht und Dokument mit der errechneten', function () {
    $this->reis->update(['yield_kg_manual' => null]);
    $ansatz = (float) $this->reis->refresh()->yield_kg;
    $ziel = round($ansatz * 2, 3);

    $report = app(ReportExportService::class);
    $doku = $report->rezeptDaten($this->rootTeam, $this->reis->id, $report->optionen(['ziel_kg' => (string) $ziel], 'recipe'));
    expect($doku['hochrechnung']['faktor'])->toEqualWithDelta(2.0, 1e-6);

    Livewire::test(RezeptDetail::class, ['recipeId' => $this->reis->id])
        ->assertSet('hochZielKg', rtrim(rtrim(number_format($ansatz, 3, ',', ''), '0'), ','))
        ->set('hochZielKg', (string) $ziel)
        ->assertSee('×2,00')->assertSee('40 kg');
});

it('Nur-Stück-Rezept: bei kg ein Hinweis statt Faktor, „nach Zutat" geht trotzdem', function () {
    $toertchen = $this->svc->create($this->rootTeam, ['name' => 'Törtchen HOCH']);
    $this->svc->syncIngredients($this->rootTeam, $toertchen->id, [
        ['id' => null, 'gp_id' => $this->makeGp($this->rootTeam, 'Eier: Bio')->id, 'raw_text' => 'Eier', 'quantity' => '10', 'unit_vocab_id' => $this->stk->id],
    ]);
    $toertchen->update(['yield_kg' => null, 'yield_kg_manual' => null, 'yield_pieces' => 20]);
    $ei = FoodAlchemistRecipeIngredient::where('recipe_id', $toertchen->id)->first();

    Livewire::test(RezeptDetail::class, ['recipeId' => $toertchen->id])
        ->assertSet('hochZielKg', '')
        ->set('hochZielKg', '5')
        ->assertSee(Hochrechnung::OHNE_AUSBEUTE)->assertDontSeeHtml('data-fa-hoch-faktor')
        ->call('hochNachZutat', $ei->id)
        ->assertSeeHtml('data-fa-hoch-zutat="' . $ei->id . '"')
        ->set('hochHabe', '15')
        ->assertSee('×1,50')->assertSee('15 Stück')
        ->assertDontSee(Hochrechnung::OHNE_AUSBEUTE);
});

it('„nach Zutat" bei Zutatmenge 0: Hinweis, keine Division durch 0, Übernehmen gesperrt', function () {
    $null = FoodAlchemistRecipeIngredient::create(['team_id' => $this->rootTeam->id, 'recipe_id' => $this->reis->id,
        'raw_text' => 'Pfeffer nach Bedarf', 'quantity' => 0, 'unit_vocab_id' => $this->g->id, 'position' => 9]);

    expect(Hochrechnung::faktor(5, 0.0, Hochrechnung::OHNE_ZUTATMENGE))->toBe(['faktor' => null, 'hinweis' => Hochrechnung::OHNE_ZUTATMENGE])
        ->and(Hochrechnung::faktor(5, null))->toBe(['faktor' => null, 'hinweis' => Hochrechnung::OHNE_AUSBEUTE])
        ->and(Hochrechnung::faktor(0, 50.0))->toBe(['faktor' => null, 'hinweis' => null]);

    $vorher = hochZutaten($this->reis);
    Livewire::test(RezeptDetail::class, ['recipeId' => $this->reis->id])
        ->assertDontSeeHtml('data-fa-hoch-menge="' . $null->id . '"')   // Menge 0 ist nicht antippbar
        ->call('hochNachZutat', $null->id)->set('hochHabe', '3')
        ->assertSee(Hochrechnung::OHNE_ZUTATMENGE)
        ->set('hochZielKg', '52')->call('bearbeitenStarten')
        ->assertSeeHtml('data-fa-hoch-ohne-menge')->assertDontSeeHtml('data-fa-hoch-uebernehmen')
        ->call('hochUebernehmen')->assertDispatched('fa-saved');
    expect(hochZutaten($this->reis))->toBe($vorher);
});

it('Ansicht schreibt nichts: Rezept und Zutaten unverändert nach Eingeben, Antippen und Zurücksetzen', function () {
    $this->travel(5)->minutes();
    $rezeptVorher = (string) $this->reis->refresh()->updated_at;
    $vorher = hochZutaten($this->reis);
    $salz = FoodAlchemistRecipeIngredient::where('recipe_id', $this->reis->id)->where('raw_text', 'Salz')->first();

    Livewire::test(RezeptDetail::class, ['recipeId' => $this->reis->id])
        ->set('hochZielKg', '52')->assertSee('×1,04')
        ->call('hochNachZutat', $salz->id)->assertSet('hochZielKg', '50')
        ->set('hochHabe', '100')->assertSee('×0,50')->assertSet('hochZielKg', '25')   // kg-Feld folgt „davon habe ich“
        ->call('hochZuruecksetzen')
        ->assertSet('hochZielKg', '50')->assertSet('hochZutatId', null)->assertDontSeeHtml('data-fa-hoch-faktor');

    expect(hochZutaten($this->reis))->toBe($vorher)
        ->and((string) $this->reis->refresh()->updated_at)->toBe($rezeptVorher)
        ->and((float) $this->reis->yield_kg_manual)->toBe(50.0);
});

it('Handy: Rechner im Handbuch und erlaubt, Übernehmen weder sichtbar noch erlaubt', function () {
    request()->cookies->set(HandyLesemodus::COOKIE, '1');
    $detail = new RezeptDetail;
    expect(HandyLesemodus::abgewiesen($detail, 'hochNachZutat'))->toBeFalse()
        ->and(HandyLesemodus::abgewiesen($detail, 'hochZuruecksetzen'))->toBeFalse()
        ->and(HandyLesemodus::abgewiesen($detail, 'hochUebernehmen'))->toBeTrue()
        ->and(HandyLesemodus::abgewiesen($detail, 'statusSetzen'))->toBeTrue()
        ->and($detail->handyLeseMethoden())->toContain('hochNachZutat', 'hochZuruecksetzen')->not->toContain('hochUebernehmen');

    $vorher = hochZutaten($this->reis);
    Livewire::withCookie(HandyLesemodus::COOKIE, '1')
        ->test(RezeptDetail::class, ['recipeId' => $this->reis->id])
        ->assertSeeHtml('data-fa-handbuch="rezept"')->assertSeeHtml('data-fa-hochrechner')
        ->set('hochZielKg', '52')->assertSee('×1,04')->assertSee('20,8 kg')
        ->assertDontSeeHtml('data-fa-hoch-uebernehmen')
        ->call('hochNachZutat', $vorher[0][0])->assertSeeHtml('data-fa-hoch-zutat')
        ->call('bearbeitenStarten')->call('hochUebernehmen');      // vom Handy abgewiesen (HandyLesebereich)
    expect(hochZutaten($this->reis))->toBe($vorher);
});

it('Computer: Bestätigung nennt Faktor, Ansatz und nicht gerundete Stück-Mengen', function () {
    config(['foodalchemist.bearbeitungssperre' => true]);   // Spec 65 an: Knopf erst nach „Bearbeiten“
    Livewire::withCookie(HandyLesemodus::COOKIE, '0')
        ->test(RezeptDetail::class, ['recipeId' => $this->reis->id])
        ->set('hochZielKg', '52')
        ->assertDontSeeHtml('data-fa-hoch-uebernehmen')               // Lesemodus: erst „Bearbeiten“
        ->call('bearbeitenStarten')
        ->assertSeeHtml('data-fa-hoch-uebernehmen')
        ->assertSeeHtml(e("Alle Zutatmengen ×1,04 und Ansatz 50 → 52 kg speichern?\n\nBitte prüfen (nicht gerundet):\n– Eier: frisch 12 → 12,48 Stück"));
});

it('Übernehmen: Mengen × Faktor (gerundet je Einheit), gepflegte Ausbeute, Recompute; danach Rechner auf Standard', function () {
    $ids = array_column(hochZutaten($this->reis), 0);
    $unterVorher = hochZutaten($this->unter);

    Livewire::withCookie(HandyLesemodus::COOKIE, '0')
        ->test(RezeptDetail::class, ['recipeId' => $this->reis->id])
        ->set('hochZielKg', '52')->call('bearbeitenStarten')
        ->call('hochUebernehmen')
        ->assertDispatched('recipe-gespeichert')->assertDispatched('kosten-aktualisiert')
        ->assertSet('hochZielKg', '52')->assertSet('hochZutatId', null)   // neuer Ansatz, kein alter Faktor
        ->assertDontSeeHtml('data-fa-hoch-faktor')->assertDontSeeHtml('data-fa-hoch-neu')
        ->assertSee('20,8');                                           // Liste zeigt die gespeicherte Menge

    $nachher = hochZutaten($this->reis);
    expect(array_column($nachher, 0))->toBe($ids)                     // gleiche Zeilen, gleiche ids
        ->and(array_column($nachher, 1))->toBe([20.8, 208.0, 12.48, 520.0])   // kg 3 Stellen, g ganz, Stück genau
        ->and(array_column($nachher, 2))->toBe([null, 260.0, null, null])     // quantity_max mit, null bleibt null
        ->and((float) $this->reis->refresh()->yield_kg_manual)->toBe(52.0)
        ->and(hochZutaten($this->unter))->toBe($unterVorher);              // Unterrezept selbst unverändert
});

it('Übernehmen ohne gepflegte Ausbeute: nur der Zutaten-Weg, errechnete Ausbeute steigt mit dem Recompute, Version wie beim Editor', function () {
    $this->reis->update(['yield_kg_manual' => null]);
    $this->reis->refresh();
    $ansatz = (float) $this->reis->yield_kg;
    $version = $this->reis->version;

    Livewire::withCookie(HandyLesemodus::COOKIE, '0')
        ->test(RezeptDetail::class, ['recipeId' => $this->reis->id])
        ->set('hochZielKg', (string) round($ansatz * 1.5, 3))->call('bearbeitenStarten')
        ->call('hochUebernehmen');

    $this->reis->refresh();
    expect((float) $this->reis->yield_kg)->toEqualWithDelta($ansatz * 1.5, 0.01)
        ->and($this->reis->yield_kg_manual)->toBeNull()
        ->and($this->reis->version)->toBe($version);                  // der Zutaten-Editor zählt die Version nicht hoch
});

it('Übernehmen abgewiesen: ohne Faktor, ohne „Bearbeiten“ (Sperre) und bei einem geerbten Rezept', function () {
    config(['foodalchemist.bearbeitungssperre' => true]);
    $vorher = hochZutaten($this->reis);

    // Lesemodus (Sperre nicht geholt): Bearbeitungssperre weist ab — zuerst, die Sperre bleibt sonst beim Nutzer
    Livewire::withCookie(HandyLesemodus::COOKIE, '0')->test(RezeptDetail::class, ['recipeId' => $this->reis->id])
        ->set('hochZielKg', '52')->call('hochUebernehmen');
    expect(hochZutaten($this->reis))->toBe($vorher);

    // ohne Faktor (Feld = Ansatz bzw. 0), Sperre geholt
    Livewire::withCookie(HandyLesemodus::COOKIE, '0')->test(RezeptDetail::class, ['recipeId' => $this->reis->id])
        ->call('bearbeitenStarten')->call('hochUebernehmen')->assertDispatched('fa-saved', type: 'error');
    Livewire::withCookie(HandyLesemodus::COOKIE, '0')->test(RezeptDetail::class, ['recipeId' => $this->reis->id])
        ->set('hochZielKg', '0')->call('bearbeitenStarten')->call('hochUebernehmen')->assertDispatched('fa-saved', type: 'error');
    expect(hochZutaten($this->reis))->toBe($vorher);

    // Kind-Team sieht das Rezept des Katalog-Besitzers, darf es aber nicht pflegen (D1)
    $kind = $this->makeUser($this->childA, 'Kind');
    $this->actingAs($kind);
    Livewire::withCookie(HandyLesemodus::COOKIE, '0')->test(RezeptDetail::class, ['recipeId' => $this->reis->id])
        ->set('hochZielKg', '52')->assertSee('×1,04')
        ->assertDontSeeHtml('data-fa-hoch-uebernehmen')
        ->call('bearbeitenStarten')->call('hochUebernehmen');
    expect(hochZutaten($this->reis))->toBe($vorher);
});

it('Speicher-Rundung: g/ml ganz (unter 10 eine Stelle), kg/l 3 Stellen, sonst genau mit Prüf-Markierung', function () {
    $u = fn ($slug) => (object) ['slug' => $slug];
    expect(Hochrechnung::speicherWert(208.4, $u('g')))->toBe(['wert' => 208.0, 'pruefen' => false])
        ->and(Hochrechnung::speicherWert(0.416, $u('g')))->toBe(['wert' => 0.4, 'pruefen' => false])
        ->and(Hochrechnung::speicherWert(20.80004, $u('kg')))->toBe(['wert' => 20.8, 'pruefen' => false])
        ->and(Hochrechnung::speicherWert(12.48, $u('stk')))->toBe(['wert' => 12.48, 'pruefen' => true])
        ->and(Hochrechnung::speicherWert(13.0, $u('stk')))->toBe(['wert' => 13.0, 'pruefen' => false]);
});
