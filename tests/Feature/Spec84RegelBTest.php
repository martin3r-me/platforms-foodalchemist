<?php

use Illuminate\Support\Facades\DB;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistConcept;
use Platform\FoodAlchemist\Models\FoodAlchemistFoodbook;
use Platform\FoodAlchemist\Models\FoodAlchemistFoodbookKapitel;
use Platform\FoodAlchemist\Models\FoodAlchemistFormat;
use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Models\FoodAlchemistPaket;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeIngredient;
use Platform\FoodAlchemist\Services\AboKuendigungService;
use Platform\FoodAlchemist\Services\AboService;
use Platform\FoodAlchemist\Services\ConceptFassungService;
use Platform\FoodAlchemist\Services\ConceptService;
use Platform\FoodAlchemist\Services\FassungService;
use Platform\FoodAlchemist\Services\FormatFassungService;
use Platform\FoodAlchemist\Services\FormatService;
use Platform\FoodAlchemist\Services\InhaltsFreigabeService;
use Platform\FoodAlchemist\Services\PaketFassungService;
use Platform\FoodAlchemist\Services\PaketService;
use Platform\FoodAlchemist\Services\RezeptFolgeService;
use Platform\FoodAlchemist\Support\AboSicht;
use Platform\FoodAlchemist\Support\FaTeam;
use Platform\FoodAlchemist\Support\StammPaket;
use Platform\FoodAlchemist\Support\TeamAncestryRegistry;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 84 B3 · Regel B (Dominique 17.10.): Ein Mandant sieht die aktuelle Fassung eines abonnierten Master-Inhalts plus jede ältere Fassung,
 * auf die noch etwas Eigenes von ihm verweist — für alle vier Abo-Arten. Dazu „Historie ist keine Nutzung“ (Kurator 17.10.).
 *
 * Mandant A abonniert alles, Mandant C abonniert dasselbe ohne eigenen Verweis, Mandant B hat KEIN Abo (sein Verweis zählt nicht).
 * A hat zwei Unter-Teams U und V (übernehmen alles). Gemessen wird bei Stufe 1 über die echte Sicht (visibleToTeam / automatischer Filter).
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    \Platform\FoodAlchemist\Models\Scopes\TeamSicht::zuruecksetzen();
    $this->master = Team::create(['name' => 'Master', 'user_id' => 1, 'personal_team' => false]);
    config(['foodalchemist.master_team_id' => $this->master->id]);
    $this->a = Team::create(['name' => 'Mandant A', 'user_id' => 1, 'personal_team' => false]);
    $this->b = Team::create(['name' => 'Mandant B', 'user_id' => 1, 'personal_team' => false]);
    $this->c = Team::create(['name' => 'Mandant C', 'user_id' => 1, 'personal_team' => false]);
    $this->u = Team::create(['name' => 'Standort U', 'user_id' => 1, 'personal_team' => false, 'parent_team_id' => $this->a->id]);
    $this->v = Team::create(['name' => 'Standort V', 'user_id' => 1, 'personal_team' => false, 'parent_team_id' => $this->a->id]);
    TeamAncestryRegistry::flushAll();
    foreach ([$this->a, $this->b, $this->c] as $t) {
        StammPaket::freischalten($t);
    }
    $this->kurator = $this->makeUser($this->master, 'Kurator', 'owner');
    $this->chefA = $this->makeUser($this->a, 'Chef A', 'owner');
    $this->actingAs($this->kurator);

    $this->knochen = FoodAlchemistGp::create(['team_id' => null, 'gp_key' => 'rb|knochen', 'name' => 'Kalbsknochen']);
    $this->butter = FoodAlchemistGp::create(['team_id' => null, 'gp_key' => 'rb|butter', 'name' => 'Butter', 'allergen_milk' => 'enthalten']);

    // Je Abo-Art: Arbeitszeile anlegen, veröffentlichen, ändern
    $this->arten = [
        'recipe' => [
            'modell' => FoodAlchemistRecipe::class,
            'neu' => function () {
                $r = $this->makeRecipe($this->master, 'Kalbsjus', ['is_sales_recipe' => true]);
                $this->makeIngredient($r, 'Kalbsknochen', $this->knochen, '2000');

                return $r->id;
            },
            'aendern' => fn (int $id) => FoodAlchemistRecipeIngredient::where('recipe_id', $id)->update(['quantity' => (string) random_int(2100, 2900)]),
            'publizieren' => fn (int $id, string $n) => app(FassungService::class)->veroeffentlichen($this->master, $id, $n, $this->kurator)['fassung_id'],
            // eigenes Rezept von $t nutzt die Fassung als Unter-Rezept
            'verweis' => function (Team $t, int $fassungId) {
                $r = $this->makeRecipe($t, 'Lamm '.$t->id.'-'.$fassungId);

                return FoodAlchemistRecipeIngredient::create(['team_id' => $t->id, 'recipe_id' => $r->id, 'referenced_recipe_id' => $fassungId,
                    'raw_text' => 'Kalbsjus', 'quantity' => '100', 'unit_vocab_id' => $this->unitG($t)->id, 'position' => 1]);
            },
        ],
        'paket' => [
            'modell' => FoodAlchemistPaket::class,
            'neu' => fn () => app(PaketService::class)->create($this->master, ['name' => 'Fingerfood-Paket'])->id,
            'aendern' => fn (int $id) => DB::table('foodalchemist_packages')->where('id', $id)->update(['name' => 'Fingerfood-Paket '.random_int(2, 999)]),
            'publizieren' => fn (int $id, string $n) => app(PaketFassungService::class)->veroeffentlichen($this->master, $id, $n, $this->kurator)['fassung_id'],
            // eigenes Konzept von $t mit einem Paket-Slot
            'verweis' => function (Team $t, int $fassungId) {
                $cs = app(ConceptService::class);
                $k = FaTeam::imTeam($t, fn () => $cs->create($t, ['name' => 'Menü '.$t->id.'-'.$fassungId]));
                $slot = FaTeam::imTeam($t, fn () => $cs->addSlot($t, $k->id, ['role' => 'Beilage']));
                DB::table('foodalchemist_concept_slots')->where('id', $slot->id)->update(['type' => 'paket', 'package_id' => $fassungId]);

                return $slot;
            },
        ],
        'concept' => [
            'modell' => FoodAlchemistConcept::class,
            'neu' => fn () => app(ConceptService::class)->create($this->master, ['name' => 'Schwaben-Menü'])->id,
            'aendern' => fn (int $id) => DB::table('foodalchemist_concepts')->where('id', $id)->update(['name' => 'Schwaben-Menü '.random_int(2, 999)]),
            'publizieren' => fn (int $id, string $n) => app(ConceptFassungService::class)->veroeffentlichen($this->master, $id, $n, $this->kurator)['fassung_id'],
            // Foodbook-Block von $t verweist auf das Konzept
            'verweis' => function (Team $t, int $fassungId) {
                $fb = FoodAlchemistFoodbook::create(['team_id' => $t->id, 'label' => 'Sommer '.$t->id.'-'.$fassungId]);
                $kap = FoodAlchemistFoodbookKapitel::create(['team_id' => $t->id, 'foodbook_id' => $fb->id, 'title' => 'K', 'position' => 0]);

                return \Platform\FoodAlchemist\Models\FoodAlchemistFoodbookBlock::create(['team_id' => $t->id, 'chapter_id' => $kap->id, 'type' => 'concept_ref',
                    'position' => 0, 'visible' => true, 'concept_id' => $fassungId]);
            },
        ],
        'format' => [
            'modell' => FoodAlchemistFormat::class,
            'neu' => fn () => app(FormatService::class)->create($this->master, ['name' => 'Wirtshaus-Format', 'origin' => 'eigen'])->id,
            'aendern' => fn (int $id) => DB::table('foodalchemist_formats')->where('id', $id)->update(['name' => 'Wirtshaus-Format '.random_int(2, 999)]),
            'publizieren' => fn (int $id, string $n) => app(FormatFassungService::class)->veroeffentlichen($this->master, $id, $n, $this->kurator)['fassung_id'],
            // Foodbook-Kapitel von $t nutzt das Format
            'verweis' => function (Team $t, int $fassungId) {
                $fb = FoodAlchemistFoodbook::create(['team_id' => $t->id, 'label' => 'Format '.$t->id.'-'.$fassungId]);

                return FoodAlchemistFoodbookKapitel::create(['team_id' => $t->id, 'foodbook_id' => $fb->id, 'title' => 'K', 'position' => 0, 'format_id' => $fassungId]);
            },
        ],
    ];

    // Master: je Art eine Arbeitszeile in EINER Sammlung, A und C abonnieren; F1 und F2 veröffentlicht
    $inhalte = app(InhaltsFreigabeService::class);
    $this->sammlung = $inhalte->sammlungAnlegen($this->master, 'Alles', null, $this->kurator);
    $this->f = [];
    foreach ($this->arten as $art => $d) {
        $id = ($d['neu'])();
        $inhalte->sammlungHinzu($this->master, $this->sammlung, $art, [$id], $this->kurator);
        $this->f[$art] = ['arbeit' => $id];
    }
    $this->aboA = app(AboService::class)->abonnieren($this->master, $this->a, $this->sammlung, $this->kurator);
    app(AboService::class)->abonnieren($this->master, $this->c, $this->sammlung, $this->kurator);
    foreach ($this->arten as $art => $d) {
        $this->f[$art]['f1'] = ($d['publizieren'])($this->f[$art]['arbeit'], 'Erst');
        ($d['aendern'])($this->f[$art]['arbeit']);
        $this->f[$art]['f2'] = ($d['publizieren'])($this->f[$art]['arbeit'], 'Zweit');
        expect($this->f[$art]['f2'])->not->toBe($this->f[$art]['f1']);
    }

    $this->sieht = fn (Team $t, string $art, int $id) => $this->arten[$art]['modell']::visibleToTeam($t)->whereKey($id)->exists();
    config(['foodalchemist.team_sicht.stufe' => 1]);
});

dataset('arten', ['recipe', 'paket', 'concept', 'format']);

it('aktuelle Fassung sichtbar, alte ohne Verweis nicht, alte mit eigenem Verweis sichtbar, fremder Verweis zählt nicht', function (string $art) {
    ['f1' => $f1, 'f2' => $f2] = $this->f[$art];
    expect(($this->sieht)($this->a, $art, $f2))->toBeTrue()->and(($this->sieht)($this->a, $art, $f1))->toBeFalse();

    ($this->arten[$art]['verweis'])($this->b, $f1);   // B hat kein Abo — sein Verweis darf nichts öffnen
    ($this->arten[$art]['verweis'])($this->a, $f1);
    app(AboService::class)->neuBerechnenAlle();

    expect(($this->sieht)($this->a, $art, $f1))->toBeTrue("A sieht die alte {$art}-Fassung über den eigenen Verweis")
        ->and(($this->sieht)($this->a, $art, $f2))->toBeTrue()
        ->and(($this->sieht)($this->c, $art, $f1))->toBeFalse("C hat das Abo, aber keinen Verweis: A' Verweis zählt nicht für C")
        ->and(($this->sieht)($this->b, $art, $f1))->toBeFalse('B hat kein Abo: der eigene Verweis öffnet nichts');
    // auch über den automatischen Filter (Stufe 1)
    expect(FaTeam::imTeam($this->a, fn () => $this->arten[$art]['modell']::mitFassungen()->whereKey($f1)->exists()))->toBeTrue();
})->with('arten');

it('Verweis weg → nach dem Nachführen fällt die alte Fassung heraus', function (string $art) {
    $f1 = $this->f[$art]['f1'];
    $zeile = ($this->arten[$art]['verweis'])($this->a, $f1);
    app(AboService::class)->neuBerechnen($this->a->id);
    expect(($this->sieht)($this->a, $art, $f1))->toBeTrue();

    $zeile->delete();
    app(AboService::class)->huelleNachfuehren($this->a);
    expect(($this->sieht)($this->a, $art, $f1))->toBeFalse();
})->with('arten');

it('Verweis aus Unter-Team V macht die alte Fassung für Schwester U sichtbar (die Hülle gehört dem ganzen Mandanten)', function () {
    $f1 = $this->f['recipe']['f1'];
    ($this->arten['recipe']['verweis'])($this->v, $f1);
    app(AboService::class)->neuBerechnen($this->a->id);
    expect(($this->sieht)($this->u, 'recipe', $f1))->toBeTrue()->and(($this->sieht)($this->v, 'recipe', $f1))->toBeTrue();
});

it('transitiv: die alte Unter-Fassung unter einer referenzierten alten Fassung ist mit sichtbar', function () {
    // Jus (Familie im Abo) mit Unter-Rezept Fond — beide neu, eigene Familien
    $fond = $this->makeRecipe($this->master, 'Kalbsfond');
    $this->makeIngredient($fond, 'Kalbsknochen', $this->knochen, '3000');
    $jus = $this->makeRecipe($this->master, 'Jus mit Fond', ['is_sales_recipe' => true]);
    FoodAlchemistRecipeIngredient::create(['team_id' => $this->master->id, 'recipe_id' => $jus->id, 'referenced_recipe_id' => $fond->id, 'raw_text' => 'Fond', 'quantity' => '500', 'unit_vocab_id' => $this->unitG($this->master)->id, 'position' => 1]);
    app(InhaltsFreigabeService::class)->sammlungHinzu($this->master, $this->sammlung, 'recipe', [$jus->id], $this->kurator);
    $jus1 = app(FassungService::class)->veroeffentlichen($this->master, $jus->id, 'Erst', $this->kurator)['fassung_id'];
    $fond1 = (int) FoodAlchemistRecipe::mitFassungen()->ohneTeamSicht('test')->where('fassung_von_id', $fond->id)->max('id');
    FoodAlchemistRecipeIngredient::where('recipe_id', $fond->id)->update(['quantity' => '3500']);
    app(FassungService::class)->veroeffentlichen($this->master, $jus->id, 'Fond neu', $this->kurator);
    expect(($this->sieht)($this->a, 'recipe', $fond1))->toBeFalse();   // alte Fond-Fassung: nur über die alte Jus-Fassung erreichbar

    ($this->arten['recipe']['verweis'])($this->a, $jus1);
    app(AboService::class)->neuBerechnen($this->a->id);
    expect(($this->sieht)($this->a, 'recipe', $jus1))->toBeTrue()->and(($this->sieht)($this->a, 'recipe', $fond1))->toBeTrue();
});

it('Übernehmen am Rezept stellt den Verweis um — danach ist die alte Fassung weg', function () {
    $arbeit = $this->f['recipe']['arbeit'];
    $f2 = $this->f['recipe']['f2'];
    $zeile = ($this->arten['recipe']['verweis'])($this->a, $f2);   // A nutzt F2
    // F3 mit Allergen-Änderung: die Folge hält A auf F2 (Bestätigung nötig)
    $this->makeIngredient(FoodAlchemistRecipe::find($arbeit), 'Butter', $this->butter, '100', 2);
    app(FassungService::class)->veroeffentlichen($this->master, $arbeit, 'Butter', $this->kurator);
    expect(DB::table('foodalchemist_recipe_ingredients')->where('id', $zeile->id)->value('referenced_recipe_id'))->toBe($f2)
        ->and(($this->sieht)($this->a, 'recipe', $f2))->toBeTrue();

    $this->actingAs($this->chefA);
    FaTeam::imTeam($this->a, fn () => app(RezeptFolgeService::class)->rezeptUebernehmen($this->a, $this->chefA, (int) $zeile->recipe_id, $arbeit, bestaetigt: true));
    expect(DB::table('foodalchemist_recipe_ingredients')->where('id', $zeile->id)->value('referenced_recipe_id'))->not->toBe($f2)
        ->and(($this->sieht)($this->a, 'recipe', $f2))->toBeFalse();
});

it('Kündigung: eine alte, noch referenzierte Fassung wird kopiert, der Verweis des Mandanten zeigt auf die Kopie', function () {
    $f1 = $this->f['recipe']['f1'];
    $zeile = ($this->arten['recipe']['verweis'])($this->a, $f1);
    app(AboService::class)->neuBerechnen($this->a->id);

    $r = app(AboKuendigungService::class)->kuendigen($this->master, $this->aboA->id, $this->kurator, bestaetigt: true);
    expect($r['kopien'])->toHaveKey($f1);
    $kopie = $r['kopien'][$f1];
    expect(DB::table('foodalchemist_recipe_ingredients')->where('id', $zeile->id)->value('referenced_recipe_id'))->toBe($kopie)
        ->and(DB::table('foodalchemist_recipes')->where('id', $kopie)->value('team_id'))->toBe($this->a->id);
});

// ── Historie ist keine Nutzung ──

it('Historie: A liest das Protokoll seiner Familie inkl. Zwischenfassung; B ohne Abo nichts; per ID bleibt die Zwischenfassung unsichtbar; nach Kündigung keine Historie', function () {
    $arbeit = $this->f['recipe']['arbeit'];
    $f1 = $this->f['recipe']['f1'];
    // im Kontext des lesenden Teams (wie Tool und Vergleich)
    $nummern = fn (Team $t) => FaTeam::imTeam($t, fn () => AboSicht::historie($t, 'recipe', $arbeit)->orderBy('fassung_nr')->pluck('fassung_nr')->map(fn ($v) => (int) $v)->all());

    expect($nummern($this->a))->toBe([1, 2]);                         // inkl. F1, obwohl sie nicht in der Hülle ist
    expect($nummern($this->b))->toBe([]);                             // B: kein Abo → keine Historie
    expect(($this->sieht)($this->a, 'recipe', $f1))->toBeFalse()      // per ID / Liste weiter unsichtbar
        ->and(FaTeam::imTeam($this->a, fn () => FoodAlchemistRecipe::mitFassungen()->whereKey($f1)->exists()))->toBeFalse();

    app(AboKuendigungService::class)->kuendigen($this->master, $this->aboA->id, $this->kurator, bestaetigt: true);
    expect($nummern($this->a))->toBe([]);
});
