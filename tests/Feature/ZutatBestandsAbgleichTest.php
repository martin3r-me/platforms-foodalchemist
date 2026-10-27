<?php

use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Services\IngredientMatchService;
use Platform\FoodAlchemist\Services\LaFirstGpService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Bestands-Abgleich freier Zutatenbezeichnungen (Kurator/Dominique 27.10., Fall PDF-Import „Roastbeef“): alte Schreibweisen, Plural, Füllwörter,
 * Klammern, Zustandswort bei Ölen — der Matcher trifft das Bestands-GP; andere Hauptzutat nie (Apfelessig ≠ Apfelmus); vor einem Mint wird
 * der Bestand gefragt (Rapsöl → kein neues Team-GP neben dem Bestands-GP).
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam));
    config(['foodalchemist.semantic_search.enabled' => false]);
    $this->nr = 0;
    $this->gp = fn (string $name, string $wg, ?string $zustand, string $slug, ?string $anzeige = null) => FoodAlchemistGp::create([
        'team_id' => $this->rootTeam->id, 'name' => $name, 'gp_key' => 'bz-' . (++$this->nr) . '||', 'status' => 'approved', 'requires_la' => true,
        'commodity_group_code' => $wg, 'condition' => $zustand, 'main_ingredient_slug' => $slug, 'main_ingredient_display' => $anzeige]);
    $this->bestand = [
        'raps' => ($this->gp)('Rapsöl / Pflanzenöl: raffiniert', '11', null, 'rapsoel_pflanzenoel', 'Rapsöl / Pflanzenöl'),
        'salz' => ($this->gp)('Salz: raffiniert, trocken, jodiert', '10', 'trocken', 'salz'),
        'essig' => ($this->gp)('Apfelessig', '11', null, 'apfelessig'),
        'mus' => ($this->gp)('Apfelmus: konserviert', '02', 'konserviert', 'apfelmus'),
        'dressing' => ($this->gp)('Dressing: Apfelessig-Apfel-Vinaigrette', '13', null, 'dressing'),
        'ruebe' => ($this->gp)('Rübenkraut / Zuckerrübensirup', '09', null, 'ruebenkraut_zuckerruebensirup', 'Rübenkraut / Zuckerrübensirup'),
        'gold' => ($this->gp)('Goldsaft / Zuckerrübensirup', '09', null, 'goldsaft', 'Goldsaft / Zuckerrübensirup'),
        'pganz' => ($this->gp)('Pfeffer weiß: ganz, trocken', '10', 'trocken', 'pfeffer_weiss', 'Pfeffer weiß'),
        'pgem' => ($this->gp)('Pfeffer weiß: gemahlen, trocken', '10', 'trocken', 'pfeffer_weiss', 'Pfeffer weiß'),
        'alt' => ($this->gp)('Altbier', '15', null, 'altbier'),
        'fett' => ($this->gp)('Frittierfett', '11', null, 'frittierfett'),
        'lorbeer' => ($this->gp)('Lorbeerblatt: trocken', '03', 'trocken', 'lorbeerblatt'),
    ];
    $this->treffer = fn (string $text) => app(IngredientMatchService::class)->matchIngredient($this->rootTeam, $text)['gp_id'] ?? null;
});

it('alte GP-Schreibweise und freier Text treffen das Bestands-GP', function (string $text, string $erwartet) {
    expect(($this->treffer)($text))->toBe($this->bestand[$erwartet]->id);
})->with([
    'alter Name Rapsöl (ae/oe, Zustandswort bei Öl)' => ['Rapsoel / Pflanzenoel: trocken, raffiniert', 'raps'],
    'Plural + ae' => ['Lorbeerblaetter', 'lorbeer'],
    'alter Name Rübenkraut' => ['Ruebenkraut / Zuckerruebensirup', 'ruebe'],
    'Apfelessig mit Zustandswort' => ['Apfelessig: konserviert', 'essig'],
    'Pfeffer weiss: ganz bleibt ganz' => ['Pfeffer weiss: ganz', 'pganz'],
    'Altbier' => ['Altbier', 'alt'],
    'Frittierfett' => ['Frittierfett', 'fett'],
    'frisches Rapsöl' => ['frisches Rapsöl', 'raps'],
    'Rapsöl zum Braten' => ['Rapsöl zum Braten', 'raps'],
    'Öl (Raps)' => ['Öl (Raps)', 'raps'],
    'Apfelessig naturtrüb' => ['Apfelessig naturtrüb', 'essig'],
    'Menge und Füllwort vorn' => ['2 EL gutes Rapsöl', 'raps'],
]);

it('„Zuckerrübensirup (Grafschafter)“ trifft einen Zuckerrübensirup, nie etwas anderes', function () {
    expect(($this->treffer)('Zuckerrübensirup (Grafschafter)'))->toBeIn([$this->bestand['ruebe']->id, $this->bestand['gold']->id]);
});

it('„weiße Pfefferkörner“: Pfeffer weiß ganz oder offen — nie ein anderes GP („richtiges Bestands-GP bzw. offen“)', function () {
    expect(($this->treffer)('weiße Pfefferkörner'))->toBeIn([null, $this->bestand['pganz']->id]);
});

it('Hauptzutat-Schutz: ohne Apfelessig im Bestand bleibt „Apfelessig: konserviert“ offen — nie Apfelmus oder Dressing', function () {
    $this->bestand['essig']->delete();

    expect(($this->treffer)('Apfelessig: konserviert'))->toBeNull()
        ->and(($this->treffer)('Ruebenkraut'))->not->toBe($this->bestand['gold']->id);
});

it('Hauptzutat-Schutz: ohne Rapsöl im Bestand bleibt der alte Rapsöl-Name offen — nie „Salz: raffiniert, trocken“ (Fall io)', function () {
    $this->bestand['raps']->delete();

    expect(($this->treffer)('Rapsoel / Pflanzenoel: trocken, raffiniert'))->toBeNull();
});

it('Kein Mint, wenn ein Bestands-GP passt: mintFromLa liefert das Bestands-GP, kein neues GP entsteht', function () {
    $vorher = FoodAlchemistGp::count();

    $gp = app(LaFirstGpService::class)->mintFromLa($this->rootTeam, 'Rapsoel / Pflanzenoel: trocken, raffiniert');

    expect($gp?->id)->toBe($this->bestand['raps']->id)
        ->and(FoodAlchemistGp::count())->toBe($vorher);
});
