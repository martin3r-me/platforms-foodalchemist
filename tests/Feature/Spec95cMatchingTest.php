<?php

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Tools\ToolRegistry;
use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Models\FoodAlchemistVocabEinheit;
use Platform\FoodAlchemist\Services\IngredientMatchService;
use Platform\FoodAlchemist\Support\Zaehlform;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 95c (Neuabgleich der 286 Pilot-Zeilen 03.11., Entscheid Dominique): Pflichtangabe mit nur einer Basis, Gewürzmenge, Zehe als
 * Einheit, Synonyme Abrieb/Limone, Treffer nie schlechter als Kandidat 1.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->user = $this->makeUser($this->rootTeam);
    $this->actingAs($this->user);
    config(['foodalchemist.semantic_search.enabled' => false]);
    $this->nr = 0;
    $this->gp = fn (string $name, string $wg, ?string $zustand, ?string $slug) => FoodAlchemistGp::create([
        'team_id' => $this->rootTeam->id, 'name' => $name, 'gp_key' => 's95c-' . (++$this->nr) . '||', 'status' => 'approved', 'requires_la' => true,
        'commodity_group_code' => $wg, 'condition' => $zustand, 'main_ingredient_slug' => $slug]);
    $this->match = fn (string $text, ?string $einheit = null, ?float $menge = null) => app(IngredientMatchService::class)
        ->matchIngredient($this->rootTeam, $text, einheit: $einheit, menge: $menge);
    $this->auto = fn (array $t) => IngredientMatchService::istAutomatischVerdrahtbar($t) ? (int) $t['gp_id'] : null;
});

it('(1) Pflichtangabe mit nur einer Basis ist keine offene Variante — zwei Fettstufen bleiben Review', function () {
    $kochwein = ($this->gp)('Weißwein: zum Kochen', '15', null, 'weisswein');
    expect(($this->auto)(($this->match)('Weißwein')))->toBe($kochwein->id);

    ($this->gp)('Creme Fraiche: frisch, 30 % Fett', '06', 'frisch', 'creme_fraiche');
    ($this->gp)('Creme Fraiche: frisch, 38 % Fett', '06', 'frisch', 'creme_fraiche');
    expect(($this->auto)(($this->match)('Crème fraîche')))->toBeNull();   // Gegenprobe: zwei Basen → der Mensch entscheidet
});

it('(2) Gewürzmenge wählt die getrocknete Form — große Menge und ohne Menge bleibt die Rohform', function () {
    $frisch = ($this->gp)('Kurkuma: ganz, Wurzel, frisch', '10', 'frisch', 'kurkuma');
    $gemahlen = ($this->gp)('Kurkuma: gemahlen, trocken', '10', 'trocken', 'kurkuma');

    expect(($this->auto)(($this->match)('Kurkuma', 'g', 3.0)))->toBe($gemahlen->id)
        ->and(($this->auto)(($this->match)('Kurkuma', 'tl', 1.0)))->toBe($gemahlen->id)
        ->and(($this->auto)(($this->match)('Kurkuma', 'g', 200.0)))->toBe($frisch->id)
        ->and(($this->auto)(($this->match)('Kurkuma')))->toBe($frisch->id)
        ->and(($this->auto)(($this->match)('Kurkuma, frisch', 'g', 3.0)))->toBe($frisch->id);   // genannter Zustand entscheidet
});

it('(2) gps.MATCH spiegelt die Gewürzmenge (menge/einheit wie der Import)', function () {
    ($this->gp)('Oregano: ganz, frisch', '03', 'frisch', 'oregano');
    $getrocknet = ($this->gp)('Oregano: gerebelt, trocken', '03', 'trocken', 'oregano');

    $res = app(ToolRegistry::class)->get('foodalchemist.gps.MATCH')
        ->execute(['zutat' => 'Oregano', 'menge' => 2, 'einheit' => 'tl'], new ToolContext($this->user, $this->rootTeam));

    expect($res->data['best_match']['gp_id'])->toBe($getrocknet->id);
});

it('(3) „Knoblauchzehen“ sucht Knoblauch; die Zeile bekommt die Zehe als Einheit, wenn ihr Gewicht bekannt ist', function () {
    $ganz = ($this->gp)('Knoblauch: ganz, frisch', '01', 'frisch', 'knoblauch');
    ($this->gp)('Knoblauch: geschälte Zehen, TK', '01', 'TK', 'knoblauch');
    $stk = FoodAlchemistVocabEinheit::firstOrCreate(['team_id' => $this->rootTeam->id, 'slug' => 'stk'], ['display_de' => 'Stück', 'dimension' => 'count']);
    $zehe = FoodAlchemistVocabEinheit::firstOrCreate(['team_id' => $this->rootTeam->id, 'slug' => 'zehe'], ['display_de' => 'Zehe', 'dimension' => 'count']);

    expect(($this->auto)(($this->match)('Knoblauchzehen')))->toBe($ganz->id);

    $zeile = ['gp_id' => $ganz->id, 'unit_vocab_id' => $stk->id];
    expect(Zaehlform::einheitAnpassen($this->rootTeam, $zeile, 'Knoblauchzehen')['unit_vocab_id'])->toBe($stk->id);   // ohne Gewicht: bleibt Stück

    \Platform\FoodAlchemist\Models\FoodAlchemistGpForm::create(['gp_id' => $ganz->id, 'form_slug' => 'zehe', 'gramm' => 5, 'source' => 'manual']);
    expect(Zaehlform::einheitAnpassen($this->rootTeam, $zeile, 'Knoblauchzehen')['unit_vocab_id'])->toBe($zehe->id)
        ->and(Zaehlform::einheitAnpassen($this->rootTeam, $zeile, 'Knoblauch')['unit_vocab_id'])->toBe($stk->id)   // Gegenprobe: ganze Knolle
        ->and(Zaehlform::ohneForm('Rinderbeinscheibe'))->toBe('Rinderbeinscheibe');
});

it('(4) Synonyme: Zitronenabrieb = Zitronenschale, Limonenöl = Zitronenöl', function () {
    $schale = ($this->gp)('Zitronenschale', '02', null, 'zitronenschale');
    $oel = ($this->gp)('Zitronenöl', '11', null, 'zitronenoel');

    expect(($this->auto)(($this->match)('Zitronenabrieb')))->toBe($schale->id)
        ->and(($this->auto)(($this->match)('Limonenöl')))->toBe($oel->id);
});

it('(5) der Treffer ist nie schlechter als Kandidat 1 — „Cheddar, gerieben“ nie die Cheddar-Sauce; Hauptzutat-Schutz bleibt', function () {
    $sauce = ($this->gp)('Cheddar-Sauce: konserviert', '13', 'konserviert', 'cheddar_sauce');
    $kaese = ($this->gp)('Cheddarkäse: gerieben, frisch', '06', 'frisch', 'cheddarkaese');

    $t = ($this->match)('Cheddar, gerieben');

    expect($t['gp_id'])->not->toBe($sauce->id)
        ->and($t['gp_id'])->toBe($kaese->id);
});
