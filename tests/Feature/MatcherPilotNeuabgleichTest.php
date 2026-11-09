<?php

use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Services\IngredientMatchService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Neuabgleich der Pilot-Rezepte 09.11. (00_INBOX/_GP_Cleanup/matcher_befunde_neuabgleich_2026-11-09.md + rezeptimport_befunde_pilot §3):
 * GP-Namen, Zustand, WG und Slug wie auf foodalchemist.io. Gemeinsame Regel: was der Text nicht nennt (Bio, Farbe, Füllung, Zuschnitt,
 * Weiterverarbeitung zu Öl/Sirup/Püree), gewinnt nie automatisch.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->user = $this->makeUser($this->rootTeam);
    $this->actingAs($this->user);
    config(['foodalchemist.semantic_search.enabled' => false]);
    $this->nr = 0;
    $this->gp = fn (string $name, string $wg, ?string $zustand, ?string $slug, array $extra = []) => FoodAlchemistGp::create([
        'team_id' => $this->rootTeam->id, 'name' => $name, 'gp_key' => 'pilot1109-' . (++$this->nr) . '||', 'status' => 'approved', 'requires_la' => true,
        'commodity_group_code' => $wg, 'condition' => $zustand, 'main_ingredient_slug' => $slug] + $extra);
    $this->match = fn (string $text, ?string $einheit = null, ?float $menge = null) => app(IngredientMatchService::class)
        ->matchIngredient($this->rootTeam, $text, einheit: $einheit, menge: $menge);
    $this->info = fn (array $t) => json_encode(['gp' => $t['gp_name'] ?? null, 'status' => ($t['status'] ?? null) instanceof \BackedEnum ? $t['status']->value : ($t['status'] ?? null),
        'score' => $t['score'] ?? null, 'offen' => $t['variante_offen'] ?? null], JSON_UNESCAPED_UNICODE);
});

it('(1) „Rote Chili“ in Gewürzmenge: die genannte Farbe der Frischware schlägt die Trockenform — ohne Farbe bleibt die Gewürzmenge-Regel', function () {
    $geschrotet = ($this->gp)('Chili: geschrotet, trocken', '10', 'trocken', 'chili');
    $rot = ($this->gp)('Chilischote: frisch, rot', '01', 'frisch', 'chilischote');
    ($this->gp)('Chili: frisch, grün', '01', 'frisch', 'chili');

    $t = ($this->match)('Rote Chili', 'g', 5.0);
    expect($t['gp_id'])->toBe($rot->id, ($this->info)($t));
});

it('(2) „Hokkaido-Kürbis (mit Schale)“: der Zuschnitt Wedges ist keine gekaufte Form — die ganze Rohware gewinnt', function () {
    ($this->gp)('Hokkaido-Kürbis: Wedges, frisch, mit Schale', '01', 'frisch', 'hokkaido_kuerbis');
    $ganz = ($this->gp)('Kürbis Hokkaido: ganz, frisch', '01', 'frisch', 'kuerbis_hokkaido');

    $t = ($this->match)('Hokkaido-Kürbis (mit Schale)');
    expect($t['gp_id'])->toBe($ganz->id, ($this->info)($t));
});

it('(3) Draw: „Petersilienblätter“ zieht das Kraut, nie das Petersilienöl', function () {
    $oel = ($this->gp)('Petersilienöl', '11', null, 'petersilienoel');
    $glatt = ($this->gp)('Petersilie glatt: ganz, frisch', '03', 'frisch', 'petersilie');
    $svc = app(IngredientMatchService::class);
    $liste = [['kind' => 'gp', 'id' => $glatt->id, 'score' => 0.7453, 'origin' => 'semantic'], ['kind' => 'gp', 'id' => $oel->id, 'score' => 0.7447, 'origin' => 'semantic']];

    expect(array_column($svc->drawKandidaten($liste, 'gp', 'hybrid', $this->rootTeam, 'Petersilienblätter'), 'id'))->toBe([$glatt->id])
        ->and(array_column($svc->drawKandidaten(array_reverse($liste), 'gp', 'hybrid', $this->rootTeam, 'Petersilienblätter'), 'id'))->toBe([$glatt->id])
        ->and(array_column($svc->drawKandidaten([$liste[1]], 'gp', 'hybrid', $this->rootTeam, 'Petersilienöl'), 'id'))->toBe([$oel->id]);   // genannt → darf
});

it('(4) „Gochugaru“ trifft „Gochugaru Paprika / Chili“ (Kandidat 1)', function () {
    $g = ($this->gp)('Gochugaru Paprika / Chili: trocken, Gewürzmischung', '10', 'trocken', 'gochugaru_paprika_chili');

    $t = ($this->match)('Gochugaru');
    expect($t['gp_id'])->toBe($g->id, ($this->info)($t));
});

it('(5) Gleichstand: Bio nur, wenn der Text es nennt', function () {
    $konv = ($this->gp)('Paprikapulver: trocken, edelsüß', '10', 'trocken', 'paprikapulver');
    $bio = ($this->gp)('Paprikapulver edelsüß: trocken / (Bio)', '10', 'trocken', 'paprikapulver_edelsuess', ['bio' => 'bio']);

    $t = ($this->match)('Paprikapulver edelsüß');
    expect($t['gp_id'])->toBe($konv->id, ($this->info)($t));
    $t = ($this->match)('Paprikapulver edelsüß, Bio');
    expect($t['gp_id'])->toBe($bio->id, ($this->info)($t));
});

it('(7) „Schalotten, fein gewürfelt“ → die ganze Schalotte (Verarbeitung macht die Küche)', function () {
    ($this->gp)('Schalotte: Würfel 5 mm, frisch', '01', 'frisch', 'schalotte');
    $ganz = ($this->gp)('Schalotte: ganz, frisch', '01', 'frisch', 'schalotte');

    $t = ($this->match)('Schalotten, fein gewürfelt');
    expect($t['gp_id'])->toBe($ganz->id, ($this->info)($t));
});

it('(8) „Schwarze Oliven“ → nie die ungenannte Füllung', function () {
    ($this->gp)('Schwarze Olive: konserviert, gefüllt mit Frischkäse / (Kluth)', '01', 'konserviert', 'olive_schwarz', ['zusatz' => 'Kluth']);
    $entsteint = ($this->gp)('Olive schwarz: entsteint, konserviert', '01', 'konserviert', 'olive_schwarz');

    $t = ($this->match)('Schwarze Oliven');
    expect($t['gp_id'])->toBe($entsteint->id, ($this->info)($t));
});

it('(9) „Rote Pfefferschoten“ → Chilischote, nie Pfeffer', function () {
    $pfeffer = ($this->gp)('Pfeffer bunt: geschrotet, trocken', '10', 'trocken', 'pfeffer');
    $rot = ($this->gp)('Chilischote: frisch, rot', '01', 'frisch', 'chilischote');

    $t = ($this->match)('Rote Pfefferschoten');
    expect($t['gp_id'])->not->toBe($pfeffer->id, ($this->info)($t));
});

it('(11) „Strauchtomaten“ → nie die ungenannte Farbe', function () {
    ($this->gp)('Tomate am Strauch: frisch, gelb', '01', 'frisch', 'tomate');
    $strauch = ($this->gp)('Strauchtomate: ganz, frisch', '01', 'frisch', 'strauchtomate');

    $t = ($this->match)('Strauchtomaten');
    expect($t['gp_id'])->toBe($strauch->id, ($this->info)($t));
});

it('(12) „Beef Jerky“ bleibt offen — die Übersetzung beef → Rindfleisch ersetzt ein Wort, nicht die Zutat', function () {
    ($this->gp)('Rindfleisch: Bürgermeisterstück / Tri Tip, frisch', '04', 'frisch', 'rindfleisch');

    $t = ($this->match)('Beef Jerky');
    expect($t['gp_id'])->toBeNull(($this->info)($t));
});

it('(Import) „Datteln“ nie Dattelsirup, „Birnen“ nie Birnenpüree', function () {
    ($this->gp)('Dattelsirup', '12', null, 'dattelsirup');
    $dattel = ($this->gp)('Dattel: trocken, entsteint', '02', 'trocken', 'dattel');
    ($this->gp)('Birnenpüree: konserviert', '13', 'konserviert', 'birnenpueree');
    $birne = ($this->gp)('Birne: ganz, frisch', '02', 'frisch', 'birne');

    $t = ($this->match)('Datteln');
    expect($t['gp_id'])->toBe($dattel->id, ($this->info)($t));
    $t = ($this->match)('Birnen');
    expect($t['gp_id'])->toBe($birne->id, ($this->info)($t));
});

it('(Import) „Cashewkerne“ nie Bio, „Äpfel“ nie „Rote Äpfel“', function () {
    $cashew = ($this->gp)('Cashewkern: trocken, natur', '12', 'trocken', 'cashewkern', ['sub_category' => '12.3']);
    ($this->gp)('Cashewkern: trocken / (Bio)', '12', 'trocken', 'cashewkern', ['bio' => 'bio', 'sub_category' => '12.3']);
    $apfel = ($this->gp)('Apfel: ganz, frisch', '02', 'frisch', 'apfel');
    ($this->gp)('Rote Äpfel', '02', null, 'rote_aepfel');

    $t = ($this->match)('Cashewkerne');
    expect($t['gp_id'])->toBe($cashew->id, ($this->info)($t));
    $t = ($this->match)('Äpfel');
    expect($t['gp_id'])->toBe($apfel->id, ($this->info)($t));
});

it('(1) die genannte Farbe zählt auch unter Geschwistern — Gewürzmenge ohne Farbe bleibt bei der Trockenform', function () {
    $rot = ($this->gp)('Chili: frisch, rot', '01', 'frisch', 'chili');
    $geschrotet = ($this->gp)('Chili: geschrotet, trocken', '10', 'trocken', 'chili');
    ($this->gp)('Chili: frisch, grün', '01', 'frisch', 'chili');

    $t = ($this->match)('Rote Chili', 'g', 5.0);
    expect($t['gp_id'])->toBe($rot->id, ($this->info)($t));
    $t = ($this->match)('Chili', 'g', 5.0);
    expect($t['gp_id'])->toBe($geschrotet->id, ($this->info)($t));   // Gegenprobe: Spec 95c §2 Gewürzmenge
});

it('(Import) eine Farbe im Namen der Hauptzutat, die die Zeile nicht nennt, verdrahtet nie automatisch', function () {
    $rote = ($this->gp)('Rote Äpfel', '02', null, 'rote_aepfel');

    expect(IngredientMatchService::istAutomatischVerdrahtbar(($this->match)('Äpfel')))->toBeFalse()
        ->and(($this->match)('Rote Äpfel')['gp_id'])->toBe($rote->id)
        ->and(IngredientMatchService::istAutomatischVerdrahtbar(($this->match)('Rote Äpfel')))->toBeTrue();
});

it('(10) „Getrocknete Tomaten in Öl“ ist konservierte Ware — „Getrocknete Tomaten“ bleibt trocken', function () {
    $ohneOel = ($this->gp)('Tomate: trocken, ohne Öl', '01', 'trocken', 'tomate');
    $inOel = ($this->gp)('Tomate: getrocknet, konserviert, in Öl', '01', 'konserviert', 'tomate');

    $t = ($this->match)('Getrocknete Tomaten in Öl');
    expect($t['gp_id'])->toBe($inOel->id, ($this->info)($t));
    $t = ($this->match)('Getrocknete Tomaten');
    expect($t['gp_id'])->not->toBe($inOel->id, ($this->info)($t));
});

it('(10) „Mondamin“: nennt die Zeile nur die Marke, ist sie der Produktname — zwei Produkte derselben Marke bleiben Review', function () {
    $staerke = ($this->gp)('Maisstärke: trocken / (Mondamin)', '12', 'trocken', 'maisstaerke', ['zusatz' => 'Mondamin']);

    $t = ($this->match)('Mondamin');
    expect($t['gp_id'])->toBe($staerke->id, ($this->info)($t))
        ->and(IngredientMatchService::istAutomatischVerdrahtbar($t))->toBeTrue();

    ($this->gp)('Soßenbinder: hell, trocken / (Mondamin)', '12', 'trocken', 'sossenbinder', ['zusatz' => 'Mondamin']);
    expect(IngredientMatchService::istAutomatischVerdrahtbar(($this->match)('Mondamin')))->toBeFalse();
});

it('Gegenproben: genannte Varianten bleiben — Bio, Marke, Zuschnitt mit Zukauf-Signal', function () {
    ($this->gp)('Paprikapulver: trocken, edelsüß', '10', 'trocken', 'paprikapulver');
    $bio = ($this->gp)('Paprikapulver edelsüß: trocken / (Bio)', '10', 'trocken', 'paprikapulver_edelsuess', ['bio' => 'bio']);
    $gefuellt = ($this->gp)('Schwarze Olive: konserviert, gefüllt mit Frischkäse / (Kluth)', '01', 'konserviert', 'olive_schwarz', ['zusatz' => 'Kluth']);
    ($this->gp)('Olive schwarz: entsteint, konserviert', '01', 'konserviert', 'olive_schwarz');
    $wuerfel = ($this->gp)('Schalotte: Würfel 5 mm, TK', '01', 'TK', 'schalotte');
    ($this->gp)('Schalotte: ganz, frisch', '01', 'frisch', 'schalotte');

    $t = ($this->match)('Bio Paprikapulver edelsüß');
    expect($t['gp_id'])->toBe($bio->id, ($this->info)($t));
    $t = ($this->match)('Schwarze Oliven (Kluth)');   // „Frischkäse“ in der Zeile liest die TokenEngine als Zustand frisch (eigener Befund)
    expect($t['gp_id'])->toBe($gefuellt->id, ($this->info)($t));
    $t = ($this->match)('Schalotten, Würfel, TK');
    expect($t['gp_id'])->toBe($wuerfel->id, ($this->info)($t));
});

it('(6) „Essig“: scheitert Kandidat 1 am Hauptzutat-Schutz (Essig-Essenz), gilt der nächste zulässige — mehrere gleich gute nur als Vorschlag', function () {
    $essenz = ($this->gp)('Essig-Essenz: konserviert, 25 Vol%', '11', 'konserviert', 'essig_essenz');
    $branntwein = ($this->gp)('Branntweinessig: konserviert', '11', 'konserviert', 'branntweinessig');

    $t = ($this->match)('Essig');
    expect($t['gp_id'])->toBe($branntwein->id, ($this->info)($t));

    ($this->gp)('Weißweinessig', '11', null, 'weissweinessig');
    $t = ($this->match)('Essig');
    expect($t['gp_id'])->not->toBe($essenz->id, ($this->info)($t))
        ->and($t['gp_id'])->not->toBeNull()
        ->and(IngredientMatchService::istAutomatischVerdrahtbar($t))->toBeFalse();
});

it('(1) Kandidatenliste: das Query-Wort zählt nur an Wortanfang oder -ende — „rote“ steckt nicht in „geschrotet“', function () {
    $geschrotet = ($this->gp)('Chili: geschrotet, trocken', '10', 'trocken', 'chili');
    $kuerbis = ($this->gp)('Hokkaidokürbis: ganz, frisch', '01', 'frisch', 'hokkaidokuerbis');
    $svc = app(IngredientMatchService::class);
    $score = fn (string $z, FoodAlchemistGp $g) => collect($svc->candidatesFor($this->rootTeam, $z, null, 5))->firstWhere('id', $g->id)['score'] ?? 0.0;

    expect($score('Rote Chili', $geschrotet))->toBeLessThan(1.0)
        ->and($score('Kürbis', $kuerbis))->toBeGreaterThanOrEqual(1.0);   // Kompositum-Ende zählt weiter
});

it('(9) Terminologie: Pfefferschote ↛ Pfeffer (Anti-Marker trotz „pfeffer“ im Trigger), Pfefferschote = Chilischote', function () {
    $t = app(\Platform\FoodAlchemist\Services\TerminologyService::class);

    expect($t->isAntiMarker('Rote Pfefferschoten', 'Pfeffer bunt: geschrotet, trocken'))->toBeTrue()
        ->and($t->isAntiMarker('Rote Pfefferschoten', 'Chilischote: frisch, rot'))->toBeFalse()
        ->and($t->isAntiMarker('Pfeffer', 'Pfeffer bunt: geschrotet, trocken'))->toBeFalse()
        ->and($t->aliasVariantenFor('Rote Pfefferschoten'))->toContain('rote chilischoten')
        ->and($t->aliasVariantenFor('Beef Jerky'))->toContain('rindfleisch jerky');
});
