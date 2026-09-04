<?php

use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Platform\FoodAlchemist\Enums\MatchMethod;
use Platform\FoodAlchemist\Livewire\Recipes\DetailPanel;
use Platform\FoodAlchemist\Livewire\Recipes\RecipeModal;
use Platform\FoodAlchemist\Models\FoodAlchemistComponentEquivalent;
use Platform\FoodAlchemist\Models\FoodAlchemistProductionOrder;
use Platform\FoodAlchemist\Models\FoodAlchemistProductionOrderLine;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeIngredient;
use Platform\FoodAlchemist\Services\RecipeService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * „Basisrezept in allen Verwendungen tauschen" (Dominique 2026-09-04) — Pendant zum
 * GP-Tausch, gleichzeitig im Detail-Panel und im Editor (RecipeModal). Umgehängt wird
 * NUR in eigenen Eltern (D1); Zyklen und Selbstreferenz bleiben harte Invarianten.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();

    // Sub-Rezept-Zeile: makeIngredient legt die Zeile an, die FK zeigt danach aufs Rezept.
    $this->subZeile = function ($parent, $sub, string $menge = '300', int $pos = 1) {
        $z = $this->makeIngredient($parent, $sub->name, null, $menge, $pos);
        $z->update(['referenced_recipe_id' => $sub->id]);

        return $z->refresh();
    };
});

it('Detail-Panel: hängt alle Verwendungen aufs Ziel um und markiert die Provenienz', function () {
    $this->actingAs($this->makeUser($this->rootTeam));
    $alt = $this->makeRecipe($this->rootTeam, 'Jus: Kalb');
    $neu = $this->makeRecipe($this->rootTeam, 'Jus: Kalb dunkel');
    $teller = $this->makeRecipe($this->rootTeam, 'Kalbsrücken mit Jus', ['is_sales_recipe' => true]);
    $sauce = $this->makeRecipe($this->rootTeam, 'Sauce: Rahm');
    $z1 = ($this->subZeile)($teller, $alt);
    $z2 = ($this->subZeile)($sauce, $alt);

    Livewire::test(DetailPanel::class)
        ->call('zeige', $alt->id)
        ->set('tauschSuche', 'dunkel')
        ->call('rezeptErsetzen', $neu->id)
        ->assertSet('fehlerTausch', null)
        ->assertSet('tauschSuche', '')
        ->assertSet('hinweisTausch', fn ($h) => is_string($h) && str_contains($h, 'umgehängt'))
        ->assertDispatched('recipe-gespeichert');

    expect((int) $z1->refresh()->referenced_recipe_id)->toBe($neu->id)
        ->and((int) $z2->refresh()->referenced_recipe_id)->toBe($neu->id)
        ->and($z1->match_method)->toBe(MatchMethod::OverrideSubrecipe)
        ->and((float) $z1->quantity)->toBe(300.0);           // Menge/Einheit bleiben unberührt
});

it('Editor: derselbe Tausch läuft im Verwaltungs-Reiter des RecipeModal', function () {
    $this->actingAs($this->makeUser($this->rootTeam));
    $alt = $this->makeRecipe($this->rootTeam, 'Fond: Geflügel');
    $neu = $this->makeRecipe($this->rootTeam, 'Fond: Geflügel klar');
    $suppe = $this->makeRecipe($this->rootTeam, 'Suppe: Geflügel');
    $zeile = ($this->subZeile)($suppe, $alt);

    Livewire::test(RecipeModal::class)
        ->call('oeffnen', $alt->id)
        ->assertSeeHtml("tab === 'verwaltung'")
        ->set('tauschSuche', 'klar')
        ->call('rezeptErsetzen', $neu->id)
        ->assertSet('fehlerTausch', null)
        ->assertSet('hinweisTausch', fn ($h) => is_string($h) && str_contains($h, 'umgehängt'));

    expect((int) $zeile->refresh()->referenced_recipe_id)->toBe($neu->id);
});

it('weist den Tausch auf sich selbst ab', function () {
    $this->actingAs($this->makeUser($this->rootTeam));
    $alt = $this->makeRecipe($this->rootTeam, 'Jus: Kalb');
    $teller = $this->makeRecipe($this->rootTeam, 'Kalbsrücken');
    ($this->subZeile)($teller, $alt);

    Livewire::test(DetailPanel::class)
        ->call('zeige', $alt->id)
        ->call('rezeptErsetzen', $alt->id)
        ->assertSet('fehlerTausch', fn ($f) => is_string($f) && str_contains($f, 'identisch'));
});

it('weist einen Tausch ab, der einen Zyklus erzeugen würde', function () {
    $this->actingAs($this->makeUser($this->rootTeam));
    $alt = $this->makeRecipe($this->rootTeam, 'Jus: Kalb');
    $ziel = $this->makeRecipe($this->rootTeam, 'Jus: Kalb Reduktion');
    $teller = $this->makeRecipe($this->rootTeam, 'Kalbsrücken');
    ($this->subZeile)($teller, $alt);
    ($this->subZeile)($ziel, $teller);                       // Ziel enthält den Eltern-Teller

    Livewire::test(DetailPanel::class)
        ->call('zeige', $alt->id)
        ->call('rezeptErsetzen', $ziel->id)
        ->assertSet('fehlerTausch', fn ($f) => is_string($f) && str_contains($f, 'Zyklus'));

    expect(FoodAlchemistRecipeIngredient::where('recipe_id', $teller->id)
        ->where('referenced_recipe_id', $alt->id)->exists())->toBeTrue();
});

it('lässt geerbte Eltern-Rezepte unberührt und meldet sie (D1)', function () {
    $this->actingAs($this->makeUser($this->childA));
    $alt = $this->makeRecipe($this->rootTeam, 'Jus: Kalb');            // geerbt, aber sichtbar
    $neu = $this->makeRecipe($this->childA, 'Jus: Kalb eigen');
    $eigen = $this->makeRecipe($this->childA, 'Kalbsrücken (Kind A)');
    $master = $this->makeRecipe($this->rootTeam, 'Kalbsrücken (Master)');
    $zEigen = ($this->subZeile)($eigen, $alt);
    $zMaster = ($this->subZeile)($master, $alt);

    Livewire::test(DetailPanel::class)
        ->call('zeige', $alt->id)
        ->call('rezeptErsetzen', $neu->id)
        ->assertSet('fehlerTausch', null)
        ->assertSet('hinweisTausch', fn ($h) => is_string($h) && str_contains($h, 'geerbt'));

    expect((int) $zEigen->refresh()->referenced_recipe_id)->toBe($neu->id)
        ->and((int) $zMaster->refresh()->referenced_recipe_id)->toBe($alt->id);
});

it('meldet Eltern, in denen das Ziel schon steckte (zwei Zeilen danach)', function () {
    $this->actingAs($this->makeUser($this->rootTeam));
    $alt = $this->makeRecipe($this->rootTeam, 'Jus: Kalb');
    $neu = $this->makeRecipe($this->rootTeam, 'Jus: Kalb dunkel');
    $teller = $this->makeRecipe($this->rootTeam, 'Kalbsrücken');
    ($this->subZeile)($teller, $alt, '300', 1);
    ($this->subZeile)($teller, $neu, '100', 2);

    Livewire::test(DetailPanel::class)
        ->call('zeige', $alt->id)
        ->call('rezeptErsetzen', $neu->id)
        ->assertSet('hinweisTausch', fn ($h) => is_string($h) && str_contains($h, 'schon enthalten'));

    expect(FoodAlchemistRecipeIngredient::where('recipe_id', $teller->id)
        ->where('referenced_recipe_id', $neu->id)->count())->toBe(2);
});

it('Bilanz trennt eigene von geerbten Verwendungen', function () {
    $this->actingAs($this->makeUser($this->childA));
    $alt = $this->makeRecipe($this->rootTeam, 'Jus: Kalb');
    $eigen = $this->makeRecipe($this->childA, 'Kalbsrücken (Kind A)');
    $master = $this->makeRecipe($this->rootTeam, 'Kalbsrücken (Master)');
    ($this->subZeile)($eigen, $alt);
    ($this->subZeile)($master, $alt);

    $bilanz = app(RecipeService::class)->verwendungsBilanz($this->childA, $alt->id);

    expect($bilanz)->toMatchArray(['zeilen' => 1, 'rezepte' => 1, 'fremd_zeilen' => 1, 'fremd_rezepte' => 1]);
});

// ── „Wo ist es drin?" (2026-09-04, Dominique) — Mengen ohne Adresse helfen nicht ────────────

it('nennt die Verwendungen beim Namen, getrennt nach eigen und geerbt', function () {
    $this->actingAs($this->makeUser($this->childA));
    $alt = $this->makeRecipe($this->rootTeam, 'Jus: Kalb');
    $eigenBasis = $this->makeRecipe($this->childA, 'Fond-Ansatz (Kind A)');
    $eigenGericht = $this->makeRecipe($this->childA, 'Kalbsrücken (Kind A)', ['is_sales_recipe' => true]);
    $master = $this->makeRecipe($this->rootTeam, 'Kalbsrücken (Master)');
    ($this->subZeile)($eigenBasis, $alt);
    ($this->subZeile)($eigenGericht, $alt);
    ($this->subZeile)($master, $alt);

    $bilanz = app(RecipeService::class)->verwendungsBilanz($this->childA, $alt->id);

    // Alphabetisch, mit der Unterscheidung Gericht/Basis — sonst weiß niemand, wo er
    // zum Umhängen hinmuss („1 Zeile(n) in 1 Rezept(en)" war eine Sackgasse).
    expect(array_column($bilanz['eltern_namen'], 'name'))->toBe(['Fond-Ansatz (Kind A)', 'Kalbsrücken (Kind A)'])
        ->and(array_column($bilanz['eltern_namen'], 'ist_gericht'))->toBe([false, true])
        ->and(array_column($bilanz['fremd_namen'], 'name'))->toBe(['Kalbsrücken (Master)']);
});

it('nennt auch die blockierenden Eltern beim Namen', function () {
    $this->actingAs($this->makeUser($this->rootTeam));
    $alt = $this->makeRecipe($this->rootTeam, 'Jus: Kalb');
    $teller = $this->makeRecipe($this->rootTeam, 'Kalbsrücken mit Jus', ['is_sales_recipe' => true]);
    ($this->subZeile)($teller, $alt);

    $ref = app(RecipeService::class)->referenzen($alt->id);

    expect($ref['blocker'])->toBeGreaterThan(0)
        ->and(array_column($ref['eltern_namen'], 'name'))->toBe(['Kalbsrücken mit Jus']);
});

it('setzt den Verwaltungs-Titel ohne HTML-Entity — modal-section escapt selbst', function () {
    $modal = file_get_contents(__DIR__ . '/../../resources/views/livewire/recipes/recipe-modal.blade.php');
    $partial = file_get_contents(__DIR__ . '/../../resources/views/livewire/recipes/partials/verwaltung.blade.php');

    // „&amp;" im title-Attribut wird von der Komponente ein ZWEITES Mal escapt → „&AMP;".
    // Derselbe Fehler war am 2026-08-03 schon einmal da; deshalb ein Test statt nur ein Fix.
    expect($modal)->toContain('title="Verwaltung — Rezept tauschen & löschen"')
        ->and($modal)->not->toContain('tauschen &amp; löschen');

    // Und die Namens-Flächen sind da.
    expect($partial)->toContain('data-rezept-tausch-eltern')
        ->and($partial)->toContain('data-rezept-ref-eltern');
});

// ── Löschen (2026-09-04): der Guard prüft jetzt JEDE harte Referenz, nicht nur Eltern-Zeilen ──

it('löscht ein referenzloses eigenes Basisrezept (Soft-Delete) und leert die Auswahl', function () {
    $this->actingAs($this->makeUser($this->rootTeam));
    $rezept = $this->makeRecipe($this->rootTeam, 'Fond: unbenutzt');

    Livewire::test(DetailPanel::class)
        ->call('zeige', $rezept->id)
        ->call('rezeptLoeschen')
        ->assertSet('fehlerTausch', null)
        ->assertSet('recipeId', null)
        ->assertDispatched('recipe-gespeichert');

    expect(FoodAlchemistRecipe::find($rezept->id))->toBeNull()
        ->and(FoodAlchemistRecipe::withTrashed()->find($rezept->id))->not->toBeNull();
});

it('blockiert das Löschen bei Eltern-Zeile, Ersatz-Verknüpfung und gepinnter Ausgabe-Position', function () {
    $this->actingAs($this->makeUser($this->rootTeam));

    // (a) als Komponente eingesetzt
    $alsSub = $this->makeRecipe($this->rootTeam, 'Fond: verwendet');
    ($this->subZeile)($this->makeRecipe($this->rootTeam, 'Suppe'), $alsSub);
    Livewire::test(DetailPanel::class)->call('zeige', $alsSub->id)->call('rezeptLoeschen')
        ->assertSet('fehlerTausch', fn ($f) => is_string($f) && str_contains($f, 'Löschen blockiert'));
    expect(FoodAlchemistRecipe::find($alsSub->id))->not->toBeNull();

    // (b) hängt im Ersatz-Katalog
    $mitErsatz = $this->makeRecipe($this->rootTeam, 'Fond: mit Ersatz');
    FoodAlchemistComponentEquivalent::create([
        'team_id' => $this->rootTeam->id,
        'source_kind' => FoodAlchemistComponentEquivalent::KIND_RECIPE, 'source_id' => $mitErsatz->id,
        'alt_kind' => FoodAlchemistComponentEquivalent::KIND_GP, 'alt_id' => $this->makeGp($this->rootTeam, 'Fond fertig')->id,
    ]);
    Livewire::test(DetailPanel::class)->call('zeige', $mitErsatz->id)->call('rezeptLoeschen')
        ->assertSet('fehlerTausch', fn ($f) => is_string($f) && str_contains($f, 'Ersatz-Verknüpfung'));
    expect(FoodAlchemistRecipe::find($mitErsatz->id))->not->toBeNull();

    // (c) direkt in einen Foodbook-Block gepinnt (Spalte nimmt technisch jede Rezept-ID)
    $gepinnt = $this->makeRecipe($this->rootTeam, 'Fond: gepinnt');
    $this->makeFoodbookBlock($this->makeChapter($this->makeFoodbook($this->rootTeam, 'FB')), ['sales_recipe_id' => $gepinnt->id]);
    Livewire::test(DetailPanel::class)->call('zeige', $gepinnt->id)->call('rezeptLoeschen')
        ->assertSet('fehlerTausch', fn ($f) => is_string($f) && str_contains($f, 'Ausgabe-Position'));
    expect(FoodAlchemistRecipe::find($gepinnt->id))->not->toBeNull();
});

it('blockt offene Produktionsaufträge, abgeschlossene stehen nur als Info', function () {
    $this->actingAs($this->makeUser($this->rootTeam));
    $auftragszeile = function (FoodAlchemistRecipe $r, string $status) {
        $order = FoodAlchemistProductionOrder::create([
            'team_id' => $this->rootTeam->id, 'production_date' => '2026-09-10', 'status' => $status,
        ]);
        FoodAlchemistProductionOrderLine::create([
            'team_id' => $this->rootTeam->id, 'production_order_id' => $order->id, 'recipe_id' => $r->id,
        ]);
    };

    $offen = $this->makeRecipe($this->rootTeam, 'Fond: in Produktion');
    $auftragszeile($offen, 'in_progress');
    Livewire::test(DetailPanel::class)->call('zeige', $offen->id)->call('rezeptLoeschen')
        ->assertSet('fehlerTausch', fn ($f) => is_string($f) && str_contains($f, 'offenen Produktionsaufträgen'));
    expect(FoodAlchemistRecipe::find($offen->id))->not->toBeNull();

    $erledigt = $this->makeRecipe($this->rootTeam, 'Fond: schon produziert');
    $auftragszeile($erledigt, 'done');
    $ref = app(RecipeService::class)->referenzen($erledigt->id);
    expect($ref['produktion_historie'])->toBe(1)->and($ref['blocker'])->toBe(0);
    Livewire::test(DetailPanel::class)->call('zeige', $erledigt->id)->call('rezeptLoeschen')
        ->assertSet('fehlerTausch', null);
    expect(FoodAlchemistRecipe::find($erledigt->id))->toBeNull();
});

it('bietet kein Löschen für geerbte Rezepte und keins für Gerichte', function () {
    $this->actingAs($this->makeUser($this->childA));
    $geerbt = $this->makeRecipe($this->rootTeam, 'Fond: Master');
    Livewire::test(DetailPanel::class)->call('zeige', $geerbt->id)
        ->assertViewHas('tauschReferenzen', null)
        ->call('rezeptLoeschen')
        ->assertSet('fehlerTausch', fn ($f) => is_string($f) && str_contains($f, 'nicht möglich'));
    expect(FoodAlchemistRecipe::find($geerbt->id))->not->toBeNull();

    $this->actingAs($this->makeUser($this->rootTeam));
    $gericht = $this->makeRecipe($this->rootTeam, 'HG: Teller', ['is_sales_recipe' => true]);
    Livewire::test(DetailPanel::class)->call('zeige', $gericht->id)
        ->assertViewHas('tauschReferenzen', null);
    expect(FoodAlchemistRecipe::find($gericht->id))->not->toBeNull();
});

it('Durchstich: erst tauschen, dann löschen — der Editor schließt sich danach', function () {
    $this->actingAs($this->makeUser($this->rootTeam));
    $dublette = $this->makeRecipe($this->rootTeam, 'Jus: Kalb');
    $behalten = $this->makeRecipe($this->rootTeam, 'Jus: Kalb dunkel');
    $teller = $this->makeRecipe($this->rootTeam, 'Kalbsrücken', ['is_sales_recipe' => true]);
    ($this->subZeile)($teller, $dublette);

    $editor = Livewire::test(RecipeModal::class)->call('oeffnen', $dublette->id);

    // Solange die Dublette hängt, blockt das Löschen …
    $editor->call('rezeptLoeschen')
        ->assertSet('fehlerTausch', fn ($f) => is_string($f) && str_contains($f, 'Löschen blockiert'));

    // … nach dem Tausch nicht mehr.
    $editor->set('tauschSuche', 'dunkel')
        ->call('rezeptErsetzen', $behalten->id)
        ->assertSet('fehlerTausch', null)
        ->call('rezeptLoeschen')
        ->assertSet('fehlerTausch', null)
        ->assertSet('recipeId', null)
        ->assertDispatched('modal.close');

    expect(FoodAlchemistRecipe::find($dublette->id))->toBeNull()
        ->and(FoodAlchemistRecipeIngredient::where('recipe_id', $teller->id)
            ->where('referenced_recipe_id', $behalten->id)->count())->toBe(1);
});

/**
 * Invariante statt Beispiel: die Bilanz-Query zählt sechs Ausgabe-Tabellen per Namen. Der
 * Paket-Umbau hat `packages`/`package_dishes` (ex-`bausteine`) fachlich abgelöst, physisch
 * aber noch nicht gedroppt — genau daran ist die erste Fassung zerbrochen (`baustein_gerichte`).
 * Dieser Test hält beide Zustände aus: Tabelle da → Name UND Spalte müssen stimmen; gedroppt →
 * der hasTable-Guard nimmt sie aus der Query.
 */
it('die Referenz-Query bleibt gültig, solange die Alt-Paket-Tabelle physisch existiert', function () {
    $this->actingAs($this->makeUser($this->rootTeam));
    if (Schema::hasTable('foodalchemist_package_dishes')) {
        expect(Schema::hasColumn('foodalchemist_package_dishes', 'sales_recipe_id'))->toBeTrue()
            ->and(Schema::hasColumn('foodalchemist_package_dishes', 'deleted_at'))->toBeTrue();
    }

    // Läuft die Query durch, stimmen alle sechs Tabellen-/Spaltennamen (sonst: »no such table/column«).
    $bilanz = app(RecipeService::class)->referenzen($this->makeRecipe($this->rootTeam, 'Fond: frisch angelegt')->id);
    expect($bilanz['ausgaben'])->toBe(0)->and($bilanz['blocker'])->toBe(0);
});
