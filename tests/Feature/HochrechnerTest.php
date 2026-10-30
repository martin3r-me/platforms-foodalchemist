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
 * dieselbe Rechnung wie das Dokument (Support\Hochrechnung). Am Computer sind die hochgerechneten Mengen Felder:
 * geänderte Zeilen rechnet „Ins Rezept übernehmen" durch den Faktor auf den Ansatz zurück (Speicherweg des
 * Zutaten-Editors, syncIngredients). Der Ansatz bleibt, nur das Verhältnis ändert sich.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->user = $this->makeUser($this->rootTeam);
    $this->actingAs($this->user);
    config(['foodalchemist.bearbeitungssperre' => true]);   // Spec 65 an: vor „Bearbeiten“ Text, danach Felder
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

    // Dominiques Beispiel: Ansatz 10 kg, auf 50 kg hochgerechnet (×5) sind 2 kg Zwiebeln zu wenig, es müssen 4 kg sein
    $this->sauce = $this->svc->create($this->rootTeam, ['name' => 'Zwiebelsauce HOCH']);
    $this->svc->syncIngredients($this->rootTeam, $this->sauce->id, [
        ['id' => null, 'gp_id' => $this->makeGp($this->rootTeam, 'Zwiebel: gelb, frisch')->id, 'raw_text' => 'Zwiebel', 'quantity' => '0.4', 'unit_vocab_id' => $this->kg->id],
        ['id' => null, 'gp_id' => $this->makeGp($this->rootTeam, 'Butter: Süßrahm')->id, 'raw_text' => 'Butter', 'quantity' => '0.1', 'unit_vocab_id' => $this->kg->id],
        ['id' => null, 'gp_id' => $this->makeGp($this->rootTeam, 'Salz: Meersalz, grob')->id, 'raw_text' => 'Salz', 'quantity' => '30.5', 'unit_vocab_id' => $this->g->id],
    ]);
    $this->sauce->update(['yield_kg_manual' => 10]);
    $this->sauce->refresh();
});

function hochZutaten(FoodAlchemistRecipe $r): array
{
    return FoodAlchemistRecipeIngredient::where('recipe_id', $r->id)->orderBy('position')->get()
        ->map(fn ($z) => [$z->id, (float) $z->quantity, $z->quantity_max === null ? null : (float) $z->quantity_max, (string) $z->updated_at])->all();
}

/** Mengen so, wie sie in der Tabelle stehen (bit-genau): [id => [quantity, quantity_max]]. */
function hochRoh(FoodAlchemistRecipe $r): array
{
    return FoodAlchemistRecipeIngredient::where('recipe_id', $r->id)->orderBy('position')->get()
        ->mapWithKeys(fn ($z) => [$z->id => [$z->getRawOriginal('quantity'), $z->getRawOriginal('quantity_max')]])->all();
}

function hochZeile(FoodAlchemistRecipe $r, string $raw): FoodAlchemistRecipeIngredient
{
    return FoodAlchemistRecipeIngredient::where('recipe_id', $r->id)->where('raw_text', $raw)->firstOrFail();
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

it('Faktor 5: 4 kg statt 2 kg Zwiebeln ergibt gespeichert 0,8 kg, alle anderen Zeilen bit-gleich, Ausbeute bleibt mit Hinweis', function () {
    $zwiebel = hochZeile($this->sauce, 'Zwiebel');
    $vorher = hochRoh($this->sauce);

    Livewire::withCookie(HandyLesemodus::COOKIE, '0')
        ->test(RezeptDetail::class, ['recipeId' => $this->sauce->id])
        ->set('hochZielKg', '50')->assertSee('×5,00')
        ->assertDontSeeHtml('data-fa-hoch-eingabe')                  // Lesemodus: keine Felder
        ->call('bearbeitenStarten')
        ->assertSeeHtml('data-fa-hoch-eingabe')
        ->assertSet('hochNeu.z' . $zwiebel->id, '2')                 // vorbelegt: hochgerechnet in der Anzeige-Einheit
        ->assertDontSeeHtml('data-fa-hoch-uebernehmen')              // ohne Änderung nichts zu übernehmen
        ->set('hochNeu.z' . $zwiebel->id, '4')
        ->assertSeeHtml('data-fa-hoch-geaendert')->assertSee('im Rezept 0,4 kg → 0,8 kg')
        ->assertSeeHtml('wire:confirm="' . e("1 Zutat ändern (von 50 kg auf den Ansatz 10 kg zurückgerechnet):\n– Zwiebel: gelb, frisch 0,4 kg → 0,8 kg\n\nAusbeute ist von Hand eingetragen (10 kg) und bleibt so – bitte prüfen, ob sie noch stimmt.") . '"')
        ->call('hochUebernehmen')
        ->assertDispatched('recipe-gespeichert')->assertDispatched('kosten-aktualisiert')
        ->assertSet('hochZielKg', '10')->assertSet('hochNeu', [])     // zurück auf ×1, Ansatz unverändert
        ->assertDontSeeHtml('data-fa-hoch-faktor')->assertDontSeeHtml('data-fa-hoch-eingabe');

    $nachher = hochRoh($this->sauce);
    expect(array_keys($nachher))->toBe(array_keys($vorher))
        ->and((float) $nachher[$zwiebel->id][0])->toBe(0.8)
        ->and(array_diff_key($nachher, [$zwiebel->id => 1]))->toBe(array_diff_key($vorher, [$zwiebel->id => 1]))   // bit-gleich
        ->and((float) $this->sauce->refresh()->yield_kg_manual)->toBe(10.0);
});

it('Einheiten-Rückweg: Zeile in kg wird als g gezeigt, die Eingabe in g wird als kg gespeichert', function () {
    $butter = hochZeile($this->sauce, 'Butter');

    Livewire::withCookie(HandyLesemodus::COOKIE, '0')
        ->test(RezeptDetail::class, ['recipeId' => $this->sauce->id])
        ->set('hochZielKg', '50')->call('bearbeitenStarten')
        ->assertSet('hochNeu.z' . $butter->id, '500')                // 0,1 kg × 5 = 0,5 kg → 500 g
        ->set('hochNeu.z' . $butter->id, '600')
        ->assertSee('im Rezept 0,1 kg → 0,12 kg')
        ->call('hochUebernehmen');

    expect((float) $butter->refresh()->quantity)->toBe(0.12)->and($butter->unit_vocab_id)->toBe($this->kg->id);
});

it('Unterrezept-Zeile und Spanne änderbar: Menge zurückgerechnet, Spanne im selben Verhältnis, Unterrezept selbst unverändert', function () {
    $fond = hochZeile($this->reis, 'Gemüsefond');
    $salz = hochZeile($this->reis, 'Salz');
    $vorher = hochRoh($this->reis);
    $unterVorher = hochRoh($this->unter);

    Livewire::withCookie(HandyLesemodus::COOKIE, '0')
        ->test(RezeptDetail::class, ['recipeId' => $this->reis->id])
        ->set('hochZielKg', '100')->call('bearbeitenStarten')       // ×2
        ->assertSee('bis 500 g')
        ->set('hochNeu.z' . $fond->id, '1500')
        ->set('hochNeu.z' . $salz->id, '600')
        ->assertSeeHtml(e('2 Zutaten ändern (von 100 kg auf den Ansatz 50 kg zurückgerechnet)'))
        ->call('hochUebernehmen');

    $nachher = hochRoh($this->reis);
    expect(array_keys($nachher))->toBe(array_keys($vorher))
        ->and((float) $nachher[$fond->id][0])->toBe(750.0)->and($nachher[$fond->id][1])->toBeNull()
        ->and((float) $nachher[$salz->id][0])->toBe(300.0)->and((float) $nachher[$salz->id][1])->toBe(375.0)
        ->and(array_diff_key($nachher, [$fond->id => 1, $salz->id => 1]))->toBe(array_diff_key($vorher, [$fond->id => 1, $salz->id => 1]))
        ->and($fond->refresh()->referenced_recipe_id)->toBe($this->unter->id)
        ->and(hochRoh($this->unter))->toBe($unterVorher);
});

it('„Änderungen zurücknehmen“, Abbrechen und ein neuer Faktor schreiben nichts', function () {
    $zwiebel = hochZeile($this->sauce, 'Zwiebel');
    $vorher = hochRoh($this->sauce);

    Livewire::withCookie(HandyLesemodus::COOKIE, '0')
        ->test(RezeptDetail::class, ['recipeId' => $this->sauce->id])
        ->set('hochZielKg', '50')->call('bearbeitenStarten')
        ->set('hochNeu.z' . $zwiebel->id, '4')
        ->assertSeeHtml('wire:confirm=')                               // Abbrechen im Dialog = keine Anfrage
        ->call('hochAenderungenZuruecknehmen')
        ->assertSet('hochNeu.z' . $zwiebel->id, '2')->assertDontSeeHtml('data-fa-hoch-geaendert')
        ->assertSee('×5,00')                                           // Faktor bleibt
        ->set('hochNeu.z' . $zwiebel->id, '4')
        ->set('hochZielKg', '20')                                      // neuer Maßstab verwirft die Eingabe
        ->assertSet('hochNeu.z' . $zwiebel->id, '800')->assertDontSeeHtml('data-fa-hoch-geaendert');

    expect(hochRoh($this->sauce))->toBe($vorher);
});

it('Zeile mit Menge 0: Hinweis und kein Übernehmen — trägt man sie hier ein, geht es', function () {
    $null = FoodAlchemistRecipeIngredient::create(['team_id' => $this->rootTeam->id, 'recipe_id' => $this->reis->id,
        'raw_text' => 'Pfeffer nach Bedarf', 'quantity' => 0, 'unit_vocab_id' => $this->g->id, 'position' => 9]);
    $reis = hochZeile($this->reis, 'Reis');

    expect(Hochrechnung::faktor(5, 0.0, Hochrechnung::OHNE_ZUTATMENGE))->toBe(['faktor' => null, 'hinweis' => Hochrechnung::OHNE_ZUTATMENGE])
        ->and(Hochrechnung::faktor(5, null))->toBe(['faktor' => null, 'hinweis' => Hochrechnung::OHNE_AUSBEUTE])
        ->and(Hochrechnung::faktor(0, 50.0))->toBe(['faktor' => null, 'hinweis' => null]);

    $vorher = hochZutaten($this->reis);
    $c = Livewire::withCookie(HandyLesemodus::COOKIE, '0')
        ->test(RezeptDetail::class, ['recipeId' => $this->reis->id])
        ->assertDontSeeHtml('data-fa-hoch-menge="' . $null->id . '"')   // Menge 0 ist nicht antippbar
        ->call('hochNachZutat', $null->id)->set('hochHabe', '3')
        ->assertSee(Hochrechnung::OHNE_ZUTATMENGE)
        ->set('hochZielKg', '52')->call('bearbeitenStarten')
        ->set('hochNeu.z' . $reis->id, '26')
        ->assertSeeHtml('data-fa-hoch-ohne-menge')->assertDontSeeHtml('data-fa-hoch-uebernehmen')
        ->call('hochUebernehmen')->assertDispatched('fa-saved', type: 'error');
    expect(hochZutaten($this->reis))->toBe($vorher);

    $c->set('hochNeu.z' . $null->id, '10')                             // 10 g bei ×1,04 → 9,6 g
        ->assertDontSeeHtml('data-fa-hoch-ohne-menge')->assertSeeHtml('data-fa-hoch-uebernehmen')
        ->call('hochUebernehmen');
    expect((float) $null->refresh()->quantity)->toBe(9.6)->and((float) $reis->refresh()->quantity)->toBe(25.0);
});

it('Ansicht schreibt nichts: Rezept und Zutaten unverändert nach Eingeben, Antippen und Zurücksetzen', function () {
    $this->travel(5)->minutes();
    $rezeptVorher = (string) $this->reis->refresh()->updated_at;
    $vorher = hochZutaten($this->reis);
    $salz = hochZeile($this->reis, 'Salz');

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

it('Handy: Rechner im Handbuch ohne Eingabefelder, Übernehmen weder sichtbar noch erlaubt', function () {
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
        ->assertSet('hochNeu', [])->assertDontSeeHtml('data-fa-hoch-eingabe')->assertDontSeeHtml('data-fa-hoch-uebernehmen')
        ->call('hochNachZutat', $vorher[0][0])->assertSeeHtml('data-fa-hoch-zutat')
        ->set('hochZielKg', '52')->set('hochNeu.z' . $vorher[0][0], '30')   // Feld per Hand untergeschoben
        ->call('bearbeitenStarten')->call('hochUebernehmen');               // vom Handy abgewiesen (HandyLesebereich)
    expect(hochZutaten($this->reis))->toBe($vorher);
});

it('Übernehmen ohne gepflegte Ausbeute: nur der Zutaten-Weg, errechnete Ausbeute folgt dem Recompute, Version wie beim Editor', function () {
    $this->reis->update(['yield_kg_manual' => null]);
    $this->reis->refresh();
    $ansatz = (float) $this->reis->yield_kg;
    $version = $this->reis->version;
    $reis = hochZeile($this->reis, 'Reis');

    Livewire::withCookie(HandyLesemodus::COOKIE, '0')
        ->test(RezeptDetail::class, ['recipeId' => $this->reis->id])
        ->set('hochZielKg', (string) round($ansatz * 2, 3))->call('bearbeitenStarten')
        ->assertDontSee('von Hand eingetragen')
        ->set('hochNeu.z' . $reis->id, '60')                          // 40 kg → 60 kg: Reis 20 → 30 kg
        ->call('hochUebernehmen');

    $this->reis->refresh();
    expect((float) $reis->refresh()->quantity)->toBe(30.0)
        ->and((float) $this->reis->yield_kg)->toBeGreaterThan($ansatz)
        ->and($this->reis->yield_kg_manual)->toBeNull()
        ->and($this->reis->version)->toBe($version);                  // der Zutaten-Editor zählt die Version nicht hoch
});

it('Übernehmen abgewiesen: ohne „Bearbeiten“ (Sperre), bei ×1 oder Faktor 0, bei einem geerbten Rezept', function () {
    $reis = hochZeile($this->reis, 'Reis');
    $vorher = hochZutaten($this->reis);

    // Lesemodus (Sperre nicht geholt): keine Felder, Bearbeitungssperre weist ab — zuerst, die Sperre bleibt sonst beim Nutzer
    Livewire::withCookie(HandyLesemodus::COOKIE, '0')->test(RezeptDetail::class, ['recipeId' => $this->reis->id])
        ->set('hochZielKg', '52')->assertDontSeeHtml('data-fa-hoch-eingabe')
        ->set('hochNeu.z' . $reis->id, '30')->call('hochUebernehmen');
    expect(hochZutaten($this->reis))->toBe($vorher);

    // ×1: keine Felder (dafür ist der Zutaten-Editor da), untergeschobene Eingabe wird abgewiesen; ebenso Faktor 0
    Livewire::withCookie(HandyLesemodus::COOKIE, '0')->test(RezeptDetail::class, ['recipeId' => $this->reis->id])
        ->call('bearbeitenStarten')->assertDontSeeHtml('data-fa-hoch-eingabe')
        ->set('hochNeu.z' . $reis->id, '30')->call('hochUebernehmen')->assertDispatched('fa-saved', type: 'error');
    Livewire::withCookie(HandyLesemodus::COOKIE, '0')->test(RezeptDetail::class, ['recipeId' => $this->reis->id])
        ->set('hochZielKg', '0')->call('bearbeitenStarten')->assertDontSeeHtml('data-fa-hoch-uebernehmen')
        ->set('hochNeu.z' . $reis->id, '30')->call('hochUebernehmen')->assertDispatched('fa-saved', type: 'error');
    expect(hochZutaten($this->reis))->toBe($vorher);

    // Kind-Team sieht das Rezept des Katalog-Besitzers, darf es aber nicht pflegen (D1): Mengen nur als Text
    $kind = $this->makeUser($this->childA, 'Kind');
    $this->actingAs($kind);
    Livewire::withCookie(HandyLesemodus::COOKIE, '0')->test(RezeptDetail::class, ['recipeId' => $this->reis->id])
        ->set('hochZielKg', '52')->assertSee('×1,04')->assertSee('20,8 kg')
        ->call('bearbeitenStarten')->assertDontSeeHtml('data-fa-hoch-eingabe')
        ->set('hochNeu.z' . $reis->id, '30')->call('hochUebernehmen');
    expect(hochZutaten($this->reis))->toBe($vorher);
});

it('Rundung: Anzeige wie beim Speichern (g/ml ganz, unter 10 eine Stelle; kg/l 3 Stellen), sonst genau mit Prüf-Markierung', function () {
    $u = fn ($slug, $de = null) => (object) ['slug' => $slug, 'display_de' => $de];
    expect(Hochrechnung::speicherWert(208.4, $u('g')))->toBe(['wert' => 208.0, 'pruefen' => false])
        ->and(Hochrechnung::speicherWert(0.416, $u('g')))->toBe(['wert' => 0.4, 'pruefen' => false])
        ->and(Hochrechnung::speicherWert(20.80004, $u('kg')))->toBe(['wert' => 20.8, 'pruefen' => false])
        ->and(Hochrechnung::speicherWert(12.48, $u('stk')))->toBe(['wert' => 12.48, 'pruefen' => true])
        ->and(Hochrechnung::speicherWert(13.0, $u('stk')))->toBe(['wert' => 13.0, 'pruefen' => false])
        ->and(Hochrechnung::anzeige(207.756, $u('g')))->toBe(['wert' => 208.0, 'einheit' => 'g', 'je' => 1.0])   // kein „207,756 g“
        ->and(Hochrechnung::anzeige(9.66, $u('ml')))->toBe(['wert' => 9.7, 'einheit' => 'ml', 'je' => 1.0])
        ->and(Hochrechnung::anzeige(0.75, $u('kg')))->toBe(['wert' => 750.0, 'einheit' => 'g', 'je' => 0.001])
        ->and(Hochrechnung::anzeige(1.23456, $u('l')))->toBe(['wert' => 1.235, 'einheit' => 'l', 'je' => 1.0])
        ->and(Hochrechnung::anzeige(12.48, $u('stk', 'Stück')))->toBe(['wert' => 12.48, 'einheit' => 'Stück', 'je' => 1.0])
        ->and(Hochrechnung::zahl('1.000,5'))->toBe(1000.5)->and(Hochrechnung::zahl('2.5'))->toBe(2.5)
        ->and(Hochrechnung::eingabeText(1000.5))->toBe('1000,5');
});
