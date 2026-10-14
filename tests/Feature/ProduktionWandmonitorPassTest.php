<?php

use Livewire\Livewire;
use Platform\FoodAlchemist\Livewire\Produktion\Tagesplan;
use Platform\FoodAlchemist\Models\FoodAlchemistProductionOrderLine as Line;
use Platform\FoodAlchemist\Models\FoodAlchemistProductionStation as Posten;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Services\ProductionOrderService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 83 M1/M2 — Küchenmonitor: Pass-Ansicht je Auftrag/Ausgabe gruppiert (Gerichte als Liste mit Portionen und Haken, Allergene erst im Detail)
 * und Unterrezepte verknüpft (Unterrezept „für: …“, Oberrezept „braucht: … ✓/–“, Unterrezept vor dem Oberrezept). Quelle der Verknüpfung:
 * der Zutaten-Snapshot der Auftragszeile (`typ = sub`, `ref_recipe_id`) mit dem Unterrezept als eigene Zeile im selben Auftrag.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->svc = app(ProductionOrderService::class);
    $this->actingAs($this->makeUser($this->rootTeam, 'Küchenchef'));
    $this->wand = fn () => Livewire::test(Tagesplan::class, ['von' => '2026-08-20', 'display' => 'wall'])->set('modus', 'editor');
    $this->vk = fn (string $key, string $name, array $extra = []) => FoodAlchemistRecipe::create(array_merge([
        'team_id' => $this->rootTeam->id, 'recipe_key' => $key, 'name' => $name, 'status' => 'approved', 'is_sales_recipe' => true, 'yield_kg' => 1.0, 'work_time_min' => 10,
    ], $extra));
    $this->basis = fn (string $key, string $name) => FoodAlchemistRecipe::create([
        'team_id' => $this->rootTeam->id, 'recipe_key' => $key, 'name' => $name, 'status' => 'approved', 'is_sales_recipe' => false, 'yield_kg' => 1.0, 'work_time_min' => 10,
    ]);
    // Püree Petersilienwurzel (Grün) braucht (Grund) und (Pacojet). Echte Rezept-Zutaten (referenced_recipe_id): der Auftrag explodiert sie selbst in drei
    // eigene Aufgaben, und der Zutaten-Snapshot überlebt das Neurechnen beim Abhaken (anders als von Hand gesetzte zutaten).
    $this->pueree = function (string $datum = '2026-08-20', string $auftragName = 'Probe Küche') {
        $gruen = ($this->basis)('pw-gruen', 'Püree Petersilienwurzel (Grün)');
        $grund = ($this->basis)('pw-grund', 'Püree Petersilienwurzel (Grund)');
        $pacojet = ($this->basis)('pw-pacojet', 'Püree Petersilie-Spinat (Pacojet)');
        foreach ([[$grund, 1], [$pacojet, 2]] as [$kind, $pos]) {
            \Platform\FoodAlchemist\Models\FoodAlchemistRecipeIngredient::create([
                'team_id' => $this->rootTeam->id, 'recipe_id' => $gruen->id, 'referenced_recipe_id' => $kind->id, 'raw_text' => $kind->name,
                'quantity' => '300', 'unit_vocab_id' => $this->unitG($this->rootTeam)->id, 'position' => $pos,
            ]);
        }
        $auftrag = $this->svc->saveNew($this->rootTeam, $datum, $auftragName, [['source_ref' => 'r:pw-gruen', 'recipe_id' => $gruen->id, 'amount_kg' => 1.0]]);
        Line::where('production_order_id', $auftrag->id)->update(['is_basisrezept' => true]);
        $zeile = fn ($r) => Line::where('production_order_id', $auftrag->id)->where('recipe_id', $r->id)->firstOrFail();

        return [$auftrag, $zeile($gruen), $zeile($grund), $zeile($pacojet)];
    };
});

// ── M1 · Pass-Ansicht je Auftrag/Ausgabe ─────────────────────────────────────

it('M1: Nicht zugeteilt zeigt eine Karte je Auftrag mit den Gerichten als Liste (Portionen, Haken), sortiert nach Ausgabe; Allergene erst im Detail', function () {
    $a = ($this->vk)('g-a', '[HG] Rinderbraten | Anrichten', ['allergen_milk' => 'enthalten']);
    $b = ($this->vk)('g-b', '[HG] Lachs | Anrichten');
    $c = ($this->vk)('g-c', '[DE] Tiramisu | Anrichten');
    $spaet = $this->svc->saveNew($this->rootTeam, '2026-08-20', 'Tagung Schmitt', [['source_ref' => 'r:g-c', 'recipe_id' => $c->id, 'amount_kg' => 1.0]]);
    $frueh = $this->svc->saveNew($this->rootTeam, '2026-08-20', 'Hochzeit Meyer', [
        ['source_ref' => 'r:g-a', 'recipe_id' => $a->id, 'amount_kg' => 1.0], ['source_ref' => 'r:g-b', 'recipe_id' => $b->id, 'amount_kg' => 1.0],
    ]);
    Line::whereIn('production_order_id', [$frueh->id, $spaet->id])->update(['portionen' => 40]);

    $html = ($this->wand)()->html();

    expect($html)->toContain('data-tagesplan-pass-auftrag="'.$frueh->id.'|2026-08-20"')->and($html)->toContain('data-tagesplan-pass-auftrag="'.$spaet->id.'|2026-08-20"')
        ->and(substr_count($html, 'data-tagesplan-pass-auftrag='))->toBe(2)                                      // eine Karte je Auftrag
        ->and(substr_count($html, 'data-tagesplan-pass-gericht='))->toBe(3)                                      // drei Gerichte als Listenzeilen
        ->and(strpos($html, 'Hochzeit Meyer'))->toBeLessThan(strpos($html, 'Tagung Schmitt'))                    // nach Ausgabe sortiert (Auftrag)
        ->and($html)->toContain('40 Portionen')->toContain('2 Gerichte')->toContain('data-tagesplan-abhaken')
        ->not->toContain('data-tagesplan-wall-allergen')                                                         // Allergene erst im Detail
        ->not->toContain('data-tagesplan-wall-gericht-gruppe');                                                  // keine große Karte je Gericht
    $rinder = $this->svc;
    // Detail: Antippen öffnet das Gericht mit den Allergenen
    $key = implode('|', [$frueh->id, '[HG] Rinderbraten', '2026-08-20']);
    ($this->wand)()->call('oeffneGericht', $key)->assertSeeHtml('data-tagesplan-wall-gericht-detail')->assertSeeHtml('data-tagesplan-wall-allergen')->assertSee('Milch');
});

it('M1: der Haken am Gericht erledigt die Gericht-Zeile und zählt im Auftrag hoch', function () {
    $a = ($this->vk)('g-a', '[HG] Rinderbraten | Anrichten');
    $b = ($this->vk)('g-b', '[HG] Lachs | Anrichten');
    $auftrag = $this->svc->saveNew($this->rootTeam, '2026-08-20', 'Hochzeit Meyer', [
        ['source_ref' => 'r:g-a', 'recipe_id' => $a->id, 'amount_kg' => 1.0], ['source_ref' => 'r:g-b', 'recipe_id' => $b->id, 'amount_kg' => 1.0],
    ]);
    $zeile = Line::where('production_order_id', $auftrag->id)->where('recipe_id', $a->id)->firstOrFail();

    $c = ($this->wand)();
    expect($c->html())->toMatch('/data-tagesplan-pass-fortschritt[^>]*>0\/2</');
    $c->call('abhaken', $zeile->id)->assertSet('fehler', null);

    // Das Abhaken startet den Auftrag (planned → in_progress) und rechnet die Zeilen neu: per Rezept suchen, nicht per alter ID
    expect(Line::where('production_order_id', $auftrag->id)->where('recipe_id', $a->id)->firstOrFail()->line_status->value)->toBe('done');
    expect($c->html())->toMatch('/data-tagesplan-pass-fortschritt[^>]*>1\/2</');
});

it('M1: der Posten „Pass“ zeigt Auftrags-Karten, jeder andere Posten die klassischen Gericht-Karten', function () {
    $a = ($this->vk)('g-a', '[HG] Rinderbraten | Anrichten');
    $auftrag = $this->svc->saveNew($this->rootTeam, '2026-08-20', 'Hochzeit Meyer', [['source_ref' => 'r:g-a', 'recipe_id' => $a->id, 'amount_kg' => 1.0]]);
    $pass = Posten::create(['team_id' => $this->rootTeam->id, 'slug' => 'pass', 'name' => 'Pass']);
    $saucier = Posten::create(['team_id' => $this->rootTeam->id, 'slug' => 'saucier', 'name' => 'Saucier']);

    Line::where('production_order_id', $auftrag->id)->update(['station_id' => $pass->id]);
    expect(($this->wand)()->html())->toContain('data-tagesplan-pass-auftrag')->not->toContain('data-tagesplan-wall-gericht-gruppe');

    Line::where('production_order_id', $auftrag->id)->update(['station_id' => $saucier->id]);
    expect(($this->wand)()->html())->toContain('data-tagesplan-wall-gericht-gruppe')->not->toContain('data-tagesplan-pass-auftrag');
});

it('M1: mit 100 Gerichten bleibt es eine Auftrags-Karte mit 100 kompakten Zeilen, ohne Allergene', function () {
    $ziele = [];
    foreach (range(1, 100) as $n) {
        $r = ($this->vk)('g-'.$n, sprintf('[HG] Gericht %03d | Anrichten', $n), ['allergen_milk' => 'enthalten']);
        $ziele[] = ['source_ref' => 'r:g-'.$n, 'recipe_id' => $r->id, 'amount_kg' => 1.0];
    }
    $auftrag = $this->svc->saveNew($this->rootTeam, '2026-08-20', 'Gala 100', $ziele);
    Line::where('production_order_id', $auftrag->id)->update(['portionen' => 12]);

    $html = ($this->wand)()->html();

    expect(substr_count($html, 'data-tagesplan-pass-auftrag='))->toBe(1)->and(substr_count($html, 'data-tagesplan-pass-gericht='))->toBe(100)
        ->and($html)->toContain('100 Gerichte')->toContain('1200 Portionen')->not->toContain('data-tagesplan-wall-allergen');
    expect(strpos($html, 'Gericht 001'))->toBeLessThan(strpos($html, 'Gericht 100'));
});

// ── M2 · Unterrezepte verknüpft ──────────────────────────────────────────────

it('M2: Oberrezept zeigt „braucht“ mit Haken-Stand, Unterrezepte zeigen „für“, Unterrezepte stehen vor dem Oberrezept', function () {
    [$auftrag, $gruen, $grund, $pacojet] = ($this->pueree)();
    $p = Posten::create(['team_id' => $this->rootTeam->id, 'slug' => 'wk', 'name' => 'Warme Küche']);
    Line::where('production_order_id', $auftrag->id)->update(['station_id' => $p->id]);

    $c = ($this->wand)();
    $html = $c->html();

    expect($html)->toContain('data-tagesplan-wall-braucht')->toContain('braucht:')->toContain('data-tagesplan-wall-fuer')->toContain('für:')
        ->and(substr_count($html, 'data-tagesplan-wall-braucht>'))->toBe(1)       // nur das Oberrezept braucht etwas
        ->and(substr_count($html, 'data-tagesplan-wall-fuer>'))->toBe(2)          // beide Unterrezepte zeigen „für“
        ->and($html)->toContain('für:</span> Püree Petersilienwurzel (Grün)');
    // Reihenfolge der Karten: Unterrezepte zuerst, Oberrezept zuletzt
    preg_match_all('/data-tagesplan-zeile="(\d+)"/', $html, $m);
    $ids = array_map('intval', $m[1]);
    expect(array_search($grund->id, $ids))->toBeLessThan(array_search($gruen->id, $ids))->and(array_search($pacojet->id, $ids))->toBeLessThan(array_search($gruen->id, $ids));

    // Haken-Stand: offen, nach dem Abhaken des Unterrezepts erledigt (Haken-Icon)
    expect($html)->toMatch('/data-tagesplan-wall-braucht-eintrag="'.$grund->id.'".*?data-braucht-stand="offen"/s');
    $c->call('abhaken', $grund->id)->assertSet('fehler', null);
    // Das Abhaken startet den Auftrag und rechnet die Zeilen neu (neue IDs): die Unterrezept-Zeilen per Rezept neu holen
    $neuId = fn ($zeile) => Line::where('production_order_id', $auftrag->id)->where('recipe_id', $zeile->recipe_id)->firstOrFail()->id;
    $nach = $c->html();
    expect($nach)->toMatch('/data-tagesplan-wall-braucht-eintrag="'.$neuId($grund).'".*?data-braucht-stand="erledigt"/s')
        ->and($nach)->toMatch('/data-tagesplan-wall-braucht-eintrag="'.$neuId($pacojet).'".*?data-braucht-stand="offen"/s');
});

it('M2: auch in der Auftrags-Liste des Pass (Nicht zugeteilt) und im Gericht-Detail steht die Verknüpfung', function () {
    [$auftrag, $gruen] = ($this->pueree)();

    $html = ($this->wand)()->html();   // alle drei ohne Posten → Pass-Ansicht
    expect($html)->toContain('data-tagesplan-pass-auftrag')->toContain('braucht:')->toContain('für:');

    $key = implode('|', [$auftrag->id, 'Basisrezepte ohne Gericht', '2026-08-20']);
    ($this->wand)()->call('oeffneGericht', $key)->assertSeeHtml('data-tagesplan-wall-gericht-detail-card')->assertSeeHtml('data-tagesplan-wall-braucht')->assertSeeHtml('data-tagesplan-wall-fuer');
});

it('M2: die Verknüpfung gilt nur im selben Auftrag (ein gleichnamiges Unterrezept in einem anderen Auftrag zählt nicht)', function () {
    $gruen = ($this->basis)('pw-gruen', 'Püree Petersilienwurzel (Grün)');
    $grund = ($this->basis)('pw-grund', 'Püree Petersilienwurzel (Grund)');
    $a = $this->svc->saveNew($this->rootTeam, '2026-08-20', 'Auftrag A', [['source_ref' => 'r:pw-gruen', 'recipe_id' => $gruen->id, 'amount_kg' => 1.0]]);
    $b = $this->svc->saveNew($this->rootTeam, '2026-08-20', 'Auftrag B', [['source_ref' => 'r:pw-grund', 'recipe_id' => $grund->id, 'amount_kg' => 1.0]]);
    Line::whereIn('production_order_id', [$a->id, $b->id])->update(['is_basisrezept' => true]);
    Line::where('production_order_id', $a->id)->firstOrFail()->forceFill(['zutaten' => [['typ' => 'sub', 'name' => 'Grund', 'ref_recipe_id' => $grund->id, 'menge' => 0.5, 'einheit' => 'kg']]])->save();

    expect(($this->wand)()->html())->not->toContain('data-tagesplan-wall-braucht>')->not->toContain('data-tagesplan-wall-fuer>');
});

it('M2: ohne Unterrezept-Zeilen (Zutaten ohne eigene Aufgabe) erscheint keine Verknüpfung, nichts wird geraten', function () {
    $gruen = ($this->basis)('pw-gruen', 'Püree Petersilienwurzel (Grün)');
    $auftrag = $this->svc->saveNew($this->rootTeam, '2026-08-20', 'Probe', [['source_ref' => 'r:pw-gruen', 'recipe_id' => $gruen->id, 'amount_kg' => 1.0]]);
    Line::where('production_order_id', $auftrag->id)->update(['is_basisrezept' => true]);
    Line::where('production_order_id', $auftrag->id)->firstOrFail()->forceFill(['zutaten' => [['typ' => 'sub', 'name' => 'Grund', 'ref_recipe_id' => 99999, 'menge' => 0.5, 'einheit' => 'kg']]])->save();

    expect(($this->wand)()->html())->not->toContain('braucht:');
});

// ── B3g · Pass als Sammelposten: Komponenten je Gericht mit zulieferndem Posten und Fertig-Stand ──

it('B3g: die Pass-Karte zeigt je Gericht seine Komponenten mit Posten und Fertig-Stand und sagt, worauf der Pass noch wartet', function () {
    $gericht = ($this->vk)('g-a', '[HG] Rinderbraten | Anrichten');
    $jus = ($this->basis)('jus', 'Jus Altbier');
    $pueree = ($this->basis)('pueree', 'Püree Grün');
    foreach ([[$jus, 1], [$pueree, 2]] as [$kind, $pos]) {
        \Platform\FoodAlchemist\Models\FoodAlchemistRecipeIngredient::create([
            'team_id' => $this->rootTeam->id, 'recipe_id' => $gericht->id, 'referenced_recipe_id' => $kind->id, 'raw_text' => $kind->name,
            'quantity' => '200', 'unit_vocab_id' => $this->unitG($this->rootTeam)->id, 'position' => $pos,
        ]);
    }
    $auftrag = $this->svc->saveNew($this->rootTeam, '2026-08-20', 'Hochzeit Meyer', [['source_ref' => 'r:g-a', 'recipe_id' => $gericht->id, 'amount_kg' => 1.0]]);
    $pass = Posten::create(['team_id' => $this->rootTeam->id, 'slug' => 'pass', 'name' => 'Pass']);
    $saucier = Posten::create(['team_id' => $this->rootTeam->id, 'slug' => 'saucier', 'name' => 'Saucier']);
    $entremetier = Posten::create(['team_id' => $this->rootTeam->id, 'slug' => 'entremetier', 'name' => 'Entremetier']);
    $zeile = fn ($r) => Line::where('production_order_id', $auftrag->id)->where('recipe_id', $r->id)->firstOrFail();
    Line::where('production_order_id', $auftrag->id)->where('recipe_id', $gericht->id)->update(['station_id' => $pass->id]);
    $zeile($jus)->update(['station_id' => $saucier->id, 'is_basisrezept' => true]);
    $zeile($pueree)->update(['station_id' => $entremetier->id, 'is_basisrezept' => true]);

    $c = ($this->wand)();
    $html = $c->html();

    expect($html)->toContain('data-tagesplan-pass-komponenten')->toContain('wartet auf 2 von 2')
        ->and($html)->toContain('Jus Altbier · Saucier')->toContain('Püree Grün · Entremetier')
        ->and(substr_count($html, 'data-tagesplan-pass-komponente-stand="offen"'))->toBe(2)
        ->and($html)->not->toContain('data-tagesplan-wall-allergen');

    // Der Saucier meldet den Jus fertig (am eigenen Posten): der Pass sieht den Stand
    $c->call('abhaken', $zeile($jus)->id)->assertSet('fehler', null);
    $nach = $c->html();
    expect($nach)->toContain('wartet auf 1 von 2')->and(substr_count($nach, 'data-tagesplan-pass-komponente-stand="erledigt"'))->toBe(1)
        ->and($nach)->toMatch('/data-tagesplan-pass-komponente-stand="erledigt">Jus Altbier · Saucier/')   // der Jus ist der erledigte
        ->and($nach)->toMatch('/data-tagesplan-pass-komponente-stand="offen">Püree Grün · Entremetier/');

    // Beide da: der Pass hat alles
    $c->call('abhaken', $zeile($pueree)->id);
    expect($c->html())->toContain('alle Komponenten da');
});
