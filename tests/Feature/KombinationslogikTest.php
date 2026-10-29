<?php

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\DB;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeIngredient;
use Platform\FoodAlchemist\Services\Pairing\AnkerVarianten;
use Platform\FoodAlchemist\Services\Pairing\Kombinationslogik;
use Platform\FoodAlchemist\Services\Pairing\RezeptProfil;
use Platform\FoodAlchemist\Tests\Support\Harmonie;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;
use Symfony\Component\Uid\UuidV7;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 60 · P6: Kombinationslogik eines Gerichts — Bestandteile sind Basisrezepte, die Anker
 * liegen im Hintergrund. Fall: Rosmarinkartoffeln + Chimichurri.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->a = [];
    $mk = function (string $slug, string $name, float $int) {
        DB::table('foodalchemist_vocab_pairing_anchors')->insert(['uuid' => (string) UuidV7::generate(), 'slug' => $slug,
            'display_de' => $name, 'aroma_intensitaet' => $int, 'created_at' => now(), 'updated_at' => now()]);

        return $this->a[$slug] = (int) DB::getPdo()->lastInsertId();
    };
    $mk('potato', 'Kartoffel', 1.0);
    $mk('rosemary', 'Rosmarin', 3.0);
    $mk('parsley', 'Petersilie', 3.0);
    $mk('vinegar', 'Rotweinessig', 2.0);
    $mk('crouton', 'Croutons', 1.0);
    $mk('chocolate', 'Schokolade', 3.0);
    app(AnkerVarianten::class)->ableiten();

    Harmonie::kante($this->a['potato'], $this->a['parsley'], 3);
    Harmonie::kante($this->a['potato'], $this->a['crouton'], 3);
    Harmonie::kante($this->a['potato'], $this->a['chocolate'], 3);

    $wissen = fn (string $t, array $z) => DB::table($t)->insert($z + ['status' => 'entwurf', 'created_at' => now(), 'updated_at' => now()]);
    $wissen('foodalchemist_anchor_bedarfe', ['anchor_id' => $this->a['potato'], 'achse' => 'saeure', 'staerke' => 'muss']);
    $wissen('foodalchemist_anchor_bedarfe', ['anchor_id' => $this->a['potato'], 'achse' => 'knusprig', 'staerke' => 'soll']);
    $wissen('foodalchemist_anchor_eigenschaften', ['anchor_id' => $this->a['vinegar'], 'achse' => 'saeure', 'stufe' => 3, 'quelle' => 'dossier']);
    $wissen('foodalchemist_anchor_eigenschaften', ['anchor_id' => $this->a['crouton'], 'achse' => 'knusprig', 'stufe' => 3, 'quelle' => 'dossier']);
    $wissen('foodalchemist_anchor_eigenschaften', ['anchor_id' => $this->a['chocolate'], 'achse' => 'knusprig', 'stufe' => 3, 'quelle' => 'dossier']);
    $wissen('foodalchemist_anchor_komponenten', ['anchor_id' => $this->a['potato'], 'name' => 'Kartoffelchips', 'liefert' => json_encode(['knusprig'])]);

    $this->gp = function (string $name, string $slug) {
        $gp = $this->makeGp($this->rootTeam, $name);
        DB::table('foodalchemist_gp_anchor_mappings')->insert(['uuid' => (string) UuidV7::generate(), 'team_id' => $this->rootTeam->id,
            'gp_id' => $gp->id, 'anchor_id' => $this->a[$slug], 'role' => 'kern', 'created_at' => now(), 'updated_at' => now()]);

        return $gp;
    };
    $this->basis = function (string $name, array $zutaten, array $attrs = []) {
        $r = $this->makeRecipe($this->rootTeam, $name);
        $r->update($attrs + ['is_sales_recipe' => false, 'taste_direction' => 'herzhaft', 'function' => 'Komponente']);
        foreach ($zutaten as $i => [$gpName, $slug, $menge]) {
            $this->makeIngredient($r, $gpName, ($this->gp)($gpName, $slug), $menge, $i + 1);
        }

        return $r->fresh();
    };
    $this->einsetzen = fn (FoodAlchemistRecipe $gericht, FoodAlchemistRecipe $sub, int $pos) => FoodAlchemistRecipeIngredient::create([
        'team_id' => $gericht->team_id, 'recipe_id' => $gericht->id, 'referenced_recipe_id' => $sub->id, 'raw_text' => $sub->name,
        'quantity' => '100', 'unit_vocab_id' => $this->unitG($this->rootTeam)->id, 'position' => $pos]);

    $this->kartoffeln = ($this->basis)('Beilage: Rosmarinkartoffeln', [['Kartoffeln: frisch', 'potato', '400'], ['Rosmarin: frisch', 'rosemary', '20']], ['function' => 'Beilage']);
    $this->chimi = ($this->basis)('Sauce: Chimichurri', [['Petersilie: frisch', 'parsley', '50'], ['Rotweinessig', 'vinegar', '50']], ['function' => 'Sauce']);
    $this->croutons = ($this->basis)('Garnitur: Croutons', [['Croutons', 'crouton', '100']], ['function' => 'Garnitur', 'spec_is_vegan' => true]);
    $this->crumble = ($this->basis)('Crumble: Schokolade', [['Schokolade', 'chocolate', '100']], ['taste_direction' => 'suess', 'function' => 'Dessert']);
    $this->beize = ($this->basis)('Trockenbeize: Knusper', [['Brotkrume', 'crouton', '100']], ['function' => 'Basis']);

    $this->gericht = $this->makeRecipe($this->rootTeam, 'Rosmarinkartoffeln mit Chimichurri');
    $this->gericht->update(['is_sales_recipe' => true, 'taste_direction' => 'herzhaft']);
    ($this->einsetzen)($this->gericht, $this->kartoffeln, 1);
    ($this->einsetzen)($this->gericht, $this->chimi, 2);
    ($this->einsetzen)($this->gericht, $this->chimi, 3);                    // doppelt eingesetzt
    $this->makeIngredient($this->gericht, 'Xylo-Würze', null, '5', 4);      // ohne Anker

    $profil = app(RezeptProfil::class);
    foreach ([$this->kartoffeln, $this->chimi, $this->croutons, $this->crumble, $this->beize] as $r) {
        $profil->fuer($r->id);
    }
    $this->logik = fn () => app(Kombinationslogik::class);
    $this->texte = fn (array $aussagen, string $typ) => array_values(array_map(fn ($x) => $x->text,
        array_filter($aussagen, fn ($x) => $x->typ->value === $typ)));
});

it('Bestandteile: Basisrezepte mit Profil, doppeltes nur einmal, ohne Anker als unbekannt', function () {
    $a = ($this->logik)()->analysiere($this->gericht->fresh());

    expect(array_column($a['bestandteile'], 'label'))->toBe(['Beilage: Rosmarinkartoffeln', 'Sauce: Chimichurri', 'Xylo-Würze'])
        ->and(($this->texte)($a['aussagen'], 'unbekannt'))->toBe(['Xylo-Würze: noch keinem Aroma zugeordnet']);
});

it('Harmonie, Spannung und offener Bedarf mit Grundlage', function () {
    $a = ($this->logik)()->analysiere($this->gericht->fresh());
    $harm = array_values(array_filter($a['aussagen'], fn ($x) => $x->typ->value === 'harmoniert'))[0];

    expect($harm->text)->toBe('Beilage: Rosmarinkartoffeln und Sauce: Chimichurri: harmonieren')
        ->and($harm->grundlage->value)->toBe('inspire_gemessen')
        // Kartoffel 87 % × Petersilie 60 % harmonieren gemessen
        ->and($harm->wert)->toBe(0.5218)
        ->and(($this->texte)($a['aussagen'], 'spannung'))->toBe(['Spannung: Säure von Sauce: Chimichurri für Beilage: Rosmarinkartoffeln'])
        ->and(($this->texte)($a['aussagen'], 'bedarf_offen'))->toBe(['Es könnte fehlen: Knusper (für Beilage: Rosmarinkartoffeln)'])
        ->and(array_column($a['offene_bedarfe'], 'achse'))->toBe(['knusprig']);
});

it('Vorschlag: zuerst Formwechsel, dann Teller-Basisrezepte — nicht süß, keine Vorbereitung', function () {
    $v = ($this->logik)()->vorschlaege($this->gericht->fresh());

    expect($v)->toHaveCount(1)
        ->and($v[0]['achse'])->toBe('knusprig')
        ->and($v[0]['formwechsel'])->toBe(['Beilage: Rosmarinkartoffeln: als Kartoffelchips'])
        // Croutons: herzhaft, Garnitur, harmonieren mit der Kartoffel. Crumble (süß) und die Beize (Vorbereitung) nicht.
        ->and(array_column($v[0]['basisrezepte'], 'name'))->toBe(['Garnitur: Croutons'])
        ->and($v[0]['basisrezepte'][0]['mit'])->toBe('Beilage: Rosmarinkartoffeln');
});

it('Vorschlag für ein veganes Gericht: nur ausdrücklich vegane Basisrezepte', function () {
    $this->gericht->update(['spec_is_vegan' => true]);
    $this->croutons->update(['spec_is_vegan' => null]);
    expect(($this->logik)()->vorschlaege($this->gericht->fresh())[0]['basisrezepte'])->toBe([]);

    $this->croutons->update(['spec_is_vegan' => true]);
    expect(array_column(($this->logik)()->vorschlaege($this->gericht->fresh())[0]['basisrezepte'], 'name'))->toBe(['Garnitur: Croutons']);
});

it('Bedarf-Deckung „zubereitung": kein Partner deckt ihn, kein Basisrezept-Vorschlag, nur Formwechsel; „partner" wie bisher', function () {
    ($this->einsetzen)($this->gericht, $this->croutons, 5);       // Croutons liefern Knusper
    $deckung = function (string $wie) {
        DB::table('foodalchemist_anchor_bedarfe')->where('anchor_id', $this->a['potato'])->where('achse', 'knusprig')->update(['deckung' => $wie]);
        app(RezeptProfil::class)->fuer($this->kartoffeln->id);
    };

    $deckung('partner');
    $a = ($this->logik)()->analysiere($this->gericht->fresh());
    expect(($this->texte)($a['aussagen'], 'spannung'))->toContain('Spannung: Knusper von Garnitur: Croutons für Beilage: Rosmarinkartoffeln')
        ->and(array_column($a['offene_bedarfe'], 'achse'))->toBe([]);

    $deckung('zubereitung');
    $a = ($this->logik)()->analysiere($this->gericht->fresh());
    $v = ($this->logik)()->vorschlaege($this->gericht->fresh());
    expect(($this->texte)($a['aussagen'], 'spannung'))->not->toContain('Spannung: Knusper von Garnitur: Croutons für Beilage: Rosmarinkartoffeln')
        ->and(($this->texte)($a['aussagen'], 'bedarf_offen'))->toBe(['Es könnte fehlen: Knusper (für Beilage: Rosmarinkartoffeln) – über die Zubereitung'])
        ->and($v[0]['deckung'])->toBe('zubereitung')
        ->and($v[0]['formwechsel'])->toBe(['Beilage: Rosmarinkartoffeln: als Kartoffelchips'])
        ->and($v[0]['basisrezepte'])->toBe([]);

    $deckung('beides');
    $a = ($this->logik)()->analysiere($this->gericht->fresh());
    expect(($this->texte)($a['aussagen'], 'spannung'))->toContain('Spannung: Knusper von Garnitur: Croutons für Beilage: Rosmarinkartoffeln');
});

it('Abgeschmeckt: ein Spritzer Essig deckt den Säure-Bedarf im Basisrezept, ohne Säure-Zutat bleibt er offen; Panel zeigt „Liefert"', function () {
    $offen = fn (FoodAlchemistRecipe $r) => array_column(app(RezeptProfil::class)->fuer($r->id)['offene_bedarfe'], 'achse');
    $ohne = ($this->basis)('Beilage: Kartoffeln natur', [['Kartoffeln: mehlig', 'potato', '400']], ['function' => 'Beilage']);
    $mit = ($this->basis)('Beilage: Kartoffeln mit Essig', [['Kartoffeln: festkochend', 'potato', '400'], ['Rotweinessig: mild', 'vinegar', '2']], ['function' => 'Beilage']);

    expect(app(RezeptProfil::class)->fuer($mit->id)['eigenschaften']['saeure']['stufe'] ?? 0)->toBeLessThan(2)   // nach Masse: kaum Säure
        ->and($offen($ohne))->toContain('saeure')
        ->and($offen($mit))->not->toContain('saeure')
        ->and(app(RezeptProfil::class)->fuer($mit->id)['abgeschmeckt'])->toBe(['saeure']);

    $html = Blade::render('<x-foodalchemist::kombination :daten="$d" />', ['d' => ($this->logik)()->daten($this->croutons->fresh())]);
    expect($html)->toContain('data-profil-liefert')->and($html)->toContain('Knusper')->and($html)->toContain('●●●');
});

it('KI-Texturen fließen ins Profil (ungeprüft gekennzeichnet) und decken als Bestandteil einen Bedarf', function () {
    $chips = ($this->basis)('Garnitur: Kartoffelchips', [['Kartoffeln: Chips', 'potato', '100']], ['function' => 'Garnitur']);
    $tex = fn (string $slug) => DB::table('foodalchemist_vocab_textures')->where('slug', $slug)->value('id')
        ?? DB::table('foodalchemist_vocab_textures')->insertGetId(['slug' => $slug, 'display_de' => ucfirst($slug), 'created_at' => now(), 'updated_at' => now()]);
    DB::table('foodalchemist_recipe_textures')->insert(['recipe_id' => $chips->id, 'texture_vocab_id' => $tex('knusprig'), 'intensity' => 0.9, 'source' => 'ai', 'created_at' => now(), 'updated_at' => now()]);
    DB::table('foodalchemist_recipe_textures')->insert(['recipe_id' => $chips->id, 'texture_vocab_id' => $tex('kuehlend'), 'intensity' => 0.9, 'source' => 'ai', 'created_at' => now(), 'updated_at' => now()]);

    $p = app(RezeptProfil::class)->fuer($chips->id);
    expect($p['eigenschaften']['knusprig'])->toBe(['stufe' => 2.7, 'quelle' => 'ki_textur'])
        ->and($p['eigenschaften'])->not->toHaveKey('kaelte');                     // „kühlend" hat keine Liefer-Achse

    $liefert = collect(($this->logik)()->daten($chips->fresh())['profil']['eigenschaften'])->firstWhere('achse', 'knusprig');
    expect($liefert['grundlage'])->toBe('KI-Einschätzung, ungeprüft');

    ($this->einsetzen)($this->gericht, $chips, 5);
    $a = ($this->logik)()->analysiere($this->gericht->fresh());
    expect(($this->texte)($a['aussagen'], 'spannung'))->toContain('Spannung: Knusper von Garnitur: Kartoffelchips für Beilage: Rosmarinkartoffeln');
});

// ── K1/K2/K4a (Kurator für Dominique, 29.10.2026): Rezept deckt Bedarf, Lücken nur im Gericht, Wahrnehmung ersetzt Anker-Stufe ──

it('K1 Gericht: was das Gericht selbst liefert (Stufe ≥ 2), deckt den Bedarf; Gegenprobe ohne Rezept-Deckung bleibt er offen', function () {
    $vocab = DB::table('foodalchemist_vocab_textures')->where('slug', 'knusprig')->value('id')
        ?? DB::table('foodalchemist_vocab_textures')->insertGetId(['slug' => 'knusprig', 'display_de' => 'Knusprig', 'created_at' => now(), 'updated_at' => now()]);
    DB::table('foodalchemist_recipe_textures')->insert(['recipe_id' => $this->gericht->id, 'texture_vocab_id' => $vocab, 'intensity' => 0.9,
        'source' => 'ai', 'created_at' => now(), 'updated_at' => now()]);          // Gericht liefert Knusper (KI-Textur → Stufe 2,7)
    app(RezeptProfil::class)->vergiss();
    $logik = ($this->logik)();

    expect(array_column($logik->analysiere($this->gericht->fresh())['offene_bedarfe'], 'achse'))->toBe([])
        ->and(array_column($logik->analysiereBestandteile($logik->bestandteile($this->gericht->fresh()))['offene_bedarfe'], 'achse'))->toBe(['knusprig'])
        ->and($logik->daten($this->gericht->fresh())['zusammenfassung'])->not->toContain('fehlt:');
});

it('K2: im Basisrezept heißt ein offener Bedarf „Passt als Kontrast", im Gericht bleibt „Es fehlt"', function () {
    $natur = ($this->basis)('Beilage: Kartoffeln natur', [['Kartoffeln: mehlig', 'potato', '400']], ['function' => 'Beilage']);
    $logik = ($this->logik)();

    $b = $logik->daten($natur->fresh());
    $texte = array_column($b['aussagen']['bedarf_offen'] ?? [], 'text');
    expect($b['art'])->toBe('basisrezept')
        ->and($texte)->toContain('Passt als Kontrast: Säure (für Kartoffeln: mehlig)')
        ->and(implode(' ', $texte))->not->toContain('fehlt')
        ->and($b['zusammenfassung'])->toContain('passt als Kontrast: ')->not->toContain('fehlt:');
    $html = Blade::render('<x-foodalchemist::kombination :daten="$d" />', ['d' => $b]);
    expect($html)->toContain('Passt als Kontrast')->not->toContain('Es fehlt')->not->toContain('könnte fehlen');

    $g = $logik->daten($this->gericht->fresh());
    expect($g['art'])->toBe('gericht')
        ->and(array_column($g['aussagen']['bedarf_offen'] ?? [], 'text'))->toBe(['Es könnte fehlen: Knusper (für Beilage: Rosmarinkartoffeln)'])
        ->and(Blade::render('<x-foodalchemist::kombination :daten="$d" />', ['d' => $g]))->toContain('Es fehlt');
});

it('K1 Basisrezept (Fall Roastbeef „Salz fehlt" trotz „Liefert Salz"): die Wahrnehmung des gegarten Profils deckt den Bedarf', function () {
    $natur = ($this->basis)('Beilage: Kartoffeln natur', [['Kartoffeln: mehlig', 'potato', '400']], ['function' => 'Beilage']);
    $logik = ($this->logik)();
    expect(array_column($logik->analysiere($natur->fresh())['offene_bedarfe'], 'achse'))->toContain('saeure');

    DB::table('foodalchemist_recipe_taste_vectors')->insert(['recipe_id' => $natur->id, 'suess' => 0, 'salzig' => 0.3, 'sauer' => 0.8, 'bitter' => 0,
        'umami' => 0.2, 'fettig' => 0, 'scharf' => 0, 'source' => 'ai', 'created_at' => now(), 'updated_at' => now()]);
    $logik = ($this->logik)();                                                   // frische Instanz: das Profil hängt im Speicher-Cache

    expect(array_column($logik->analysiere($natur->fresh())['offene_bedarfe'], 'achse'))->not->toContain('saeure');
});

it('K4a: das gegarte Profil ersetzt die Anker-Stufe auf den 7 Geschmacksachsen, auch nach unten; ohne gegartes Profil bleibt der Anker', function () {
    DB::table('foodalchemist_vocab_pairing_anchors')->insert(['uuid' => (string) UuidV7::generate(), 'slug' => 'pepper',
        'display_de' => 'Schwarzer Pfeffer', 'aroma_intensitaet' => 1.0, 'created_at' => now(), 'updated_at' => now()]);
    $this->a['pepper'] = (int) DB::getPdo()->lastInsertId();
    DB::table('foodalchemist_anchor_eigenschaften')->insert(['anchor_id' => $this->a['pepper'], 'achse' => 'schaerfe', 'stufe' => 3,
        'quelle' => 'dossier', 'status' => 'entwurf', 'created_at' => now(), 'updated_at' => now()]);
    $r = ($this->basis)('Würze: Pfefferkruste', [['Pfeffer: schwarz', 'pepper', '100']]);
    $profil = app(RezeptProfil::class);

    $profil->vergiss();
    expect($profil->fuer($r->id)['eigenschaften']['schaerfe'])->toBe(['stufe' => 3.0, 'quelle' => 'dossier']);   // roh: Anker-Wissen

    DB::table('foodalchemist_recipe_taste_vectors')->insert(['recipe_id' => $r->id, 'suess' => 0, 'salzig' => 0.7, 'sauer' => 0, 'bitter' => 0,
        'umami' => 0, 'fettig' => 0, 'scharf' => 0.15, 'source' => 'ai', 'created_at' => now(), 'updated_at' => now()]);
    $profil->vergiss();
    $e = $profil->fuer($r->id)['eigenschaften'];
    expect($e['schaerfe'])->toBe(['stufe' => 0.45, 'quelle' => 'wahrnehmung'])            // ●●● → ●○○
        ->and($e['salz'])->toBe(['stufe' => 2.1, 'quelle' => 'wahrnehmung']);

    DB::table('foodalchemist_recipe_taste_vectors')->where('recipe_id', $r->id)->update(['scharf' => 0]);
    $profil->vergiss();
    expect($profil->fuer($r->id)['eigenschaften'])->not->toHaveKey('schaerfe');            // gegart nicht wahrnehmbar
});

it('Messbefehl rezept-luecken-vergleich zählt Änderungen und schreibt nichts', function () {
    $natur = ($this->basis)('Beilage: Kartoffeln natur', [['Kartoffeln: mehlig', 'potato', '400']], ['function' => 'Beilage']);
    app(RezeptProfil::class)->fuer($natur->id);                                             // „vorher" gespeichert
    DB::table('foodalchemist_recipe_taste_vectors')->insert(['recipe_id' => $natur->id, 'suess' => 0, 'salzig' => 0, 'sauer' => 0.8, 'bitter' => 0,
        'umami' => 0, 'fettig' => 0, 'scharf' => 0, 'source' => 'ai', 'created_at' => now(), 'updated_at' => now()]);
    $vorher = DB::table('foodalchemist_recipe_profile')->where('recipe_id', $natur->id)->value('eigenschaften');

    $this->artisan('foodalchemist:rezept-luecken-vergleich', ['--rezept' => [$natur->id]])
        ->expectsOutputToContain('Liefert neu: Säure 1')
        ->assertExitCode(0);

    expect(DB::table('foodalchemist_recipe_profile')->where('recipe_id', $natur->id)->value('eigenschaften'))->toBe($vorher);
});
