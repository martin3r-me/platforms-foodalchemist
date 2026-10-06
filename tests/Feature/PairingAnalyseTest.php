<?php

use Illuminate\Support\Facades\DB;
use Platform\FoodAlchemist\Services\PairingAnalyseService;
use Platform\FoodAlchemist\Services\SensorikService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;
use Symfony\Component\Uid\UuidV7;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 58 · Paket 2 — Harmonie (Foodpairing-Sterne, 3★ zählt, 2★ „passt", Brücke über einen
 * dritten Bestandteil) und Kontrast (Geschmacks-Gegensätze + Textur) eines Gerichts.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();

    $anker = function (string $slug): int {
        DB::table('foodalchemist_vocab_pairing_anchors')->insert([
            'uuid' => (string) UuidV7::generate(), 'slug' => $slug, 'display_de' => ucfirst($slug),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return (int) DB::getPdo()->lastInsertId();
    };
    $kante = function (int $a, int $b, int $level): void {
        foreach ([[$a, $b], [$b, $a]] as [$x, $y]) {
            DB::table('foodalchemist_pairing_anchor_edges')->insert([
                'uuid' => (string) UuidV7::generate(), 'anchor_a_id' => $x, 'anchor_b_id' => $y,
                'type' => 'aroma', 'level' => $level, 'axis' => 'harmony', 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    };
    $this->mapping = function (int $gpId, int $ankerId): void {
        DB::table('foodalchemist_gp_anchor_mappings')->insert([
            'uuid' => (string) UuidV7::generate(), 'team_id' => $this->rootTeam->id, 'gp_id' => $gpId,
            'anchor_id' => $ankerId, 'role' => 'kern', 'created_at' => now(), 'updated_at' => now(),
        ]);
    };
    $this->geschmack = function (int $gpId, array $dims): void {
        DB::table('foodalchemist_gp_taste_vectors')->insert(array_merge(
            array_fill_keys(SensorikService::DIMS, 0),
            ['gp_id' => $gpId, 'source' => 'gemini', 'created_at' => now(), 'updated_at' => now()],
            $dims,
        ));
    };
    $textur = function (string $slug): int {
        DB::table('foodalchemist_vocab_textures')->insert(['slug' => $slug, 'display_de' => ucfirst($slug), 'created_at' => now(), 'updated_at' => now()]);

        return (int) DB::getPdo()->lastInsertId();
    };
    $this->texturGp = function (int $gpId, int $texturId): void {
        DB::table('foodalchemist_gp_textures')->insert(['gp_id' => $gpId, 'texture_vocab_id' => $texturId, 'intensity' => 1, 'created_at' => now(), 'updated_at' => now()]);
    };

    $rind = $anker('rind');
    $fond = $anker('fond');
    $sauce = $anker('sauce');
    $anker('rosmarin');                 // ohne Mapping, nur über den exakten Grundnamen erreichbar
    $kante($rind, $fond, 3);            // ★★★
    $kante($sauce, $fond, 3);           // ★★★
    $kante($rind, $this->ankerId = DB::table('foodalchemist_vocab_pairing_anchors')->where('slug', 'rosmarin')->value('id'), 2); // ★★

    $this->knusprig = $textur('knusprig');
    $this->cremig = $textur('cremig');

    $this->gpRind = $this->makeGp($this->rootTeam, 'Rind: Hüfte');
    ($this->mapping)($this->gpRind->id, $rind);
    $this->gpFond = $this->makeGp($this->rootTeam, 'Fond: Kalb');
    ($this->mapping)($this->gpFond->id, $fond);
    $this->gpSauce = $this->makeGp($this->rootTeam, 'Sauce: Pfeffer');
    ($this->mapping)($this->gpSauce->id, $sauce);
    $this->gpRosmarin = $this->makeGp($this->rootTeam, 'Rosmarin: frisch');

    ($this->geschmack)($this->gpRind->id, ['fettig' => 0.8, 'umami' => 0.7]);
    ($this->geschmack)($this->gpSauce->id, ['sauer' => 0.7]);
    ($this->texturGp)($this->gpRind->id, $this->knusprig);
    ($this->texturGp)($this->gpFond->id, $this->cremig);

    $this->gericht = $this->makeRecipe($this->rootTeam, 'Rinderhüfte mit Pfeffersauce');
    $zeile = function ($gp, string $menge, int $pos, ?string $rolle) {
        $z = $this->makeIngredient($this->gericht, $gp?->name ?? 'Xylo Quirk', $gp, $menge, $pos);
        $z->update(['role' => $rolle]);
    };
    $zeile($this->gpRind, '200', 1, 'aroma_treiber');
    $zeile($this->gpFond, '100', 2, 'komponente');
    $zeile($this->gpSauce, '20', 3, 'garnitur');
    $zeile($this->gpRosmarin, '5', 4, null);
    $this->svc = app(PairingAnalyseService::class);
});

it('Harmonie: 3★ harmoniert sehr gut, 2★ passt nur, ohne Direktpaar trägt der dritte Bestandteil die Brücke', function () {
    $a = $this->svc->analyseRezept($this->gericht->id);
    $stufe = collect($a['harmonie']['paare'])->mapWithKeys(fn ($p) => [
        $a['komponenten'][$p['a']]['kurz'] . '|' . $a['komponenten'][$p['b']]['kurz'] => $p,
    ]);

    expect($stufe['Rind|Fond']['stufe'])->toBe('sehr_gut')
        ->and($stufe['Fond|Sauce']['stufe'])->toBe('sehr_gut')
        ->and($stufe['Rind|Sauce']['stufe'])->toBe('bruecke')
        ->and($a['komponenten'][$stufe['Rind|Sauce']['ueber']]['kurz'])->toBe('Fond')
        ->and($stufe['Rind|Sauce']['satz'])->toContain('beide harmonieren sehr gut mit Fond')
        ->and($stufe['Rind|Rosmarin']['stufe'])->toBe('passt');

    // Rosmarin ohne Mapping über den exakten Grundnamen (Paket 1), nicht geraten.
    expect($a['komponenten'][3]['via'])->toBe('exakt_name');
});

it('Zusammenhalt zählt nur 3★ und nennt die Abdeckung — keine Zahl ohne Abdeckung', function () {
    $z = $this->svc->analyseRezept($this->gericht->id)['harmonie']['zusammenhalt'];

    expect($z['bewertet'])->toBe(6)->and($z['gesamt'])->toBe(6)->and($z['abdeckung_pct'])->toBe(100)
        ->and($z['wert'])->toBeGreaterThan(0)->toBeLessThan(100)
        ->and($z['stufe'])->not->toBeNull();
});

it('Kontrast: Fett gegen Säure und knusprig gegen weich, Rolle steht im Satz, geschätzte Achsen sind markiert', function () {
    $k = collect($this->svc->analyseRezept($this->gericht->id)['kontrast']);

    $fettSaeure = $k->first(fn ($c) => $c['achse_a'] === 'fettig' && $c['achse_b'] === 'sauer');
    expect($fettSaeure)->not->toBeNull()
        ->and($fettSaeure['satz'])->toBe('Spannung: Fett von Rind (Aromaträger) gegen Säure von Sauce (Garnitur). (geschätzt)')
        ->and($fettSaeure['belegt'])->toBeFalse();

    $textur = $k->first(fn ($c) => $c['art'] === 'textur');
    expect($textur['satz'])->toBe('Spannung: das Knusprige von Rind (Aromaträger) gegen das Weiche von Fond.');
});

it('Lücke: viel Fett ohne Säure wird als fehlender Gegenpol gemeldet', function () {
    DB::table('foodalchemist_gp_taste_vectors')->where('gp_id', $this->gpSauce->id)->update(['sauer' => 0]);

    $a = $this->svc->analyseRezept($this->gericht->id);
    expect(array_column($a['luecken'], 'code'))->toContain('saeure_fehlt')
        ->and($a['zusammenfassung'])->toContain('Lücke');
});

it('nicht zuordenbare Bestandteile ergeben „keine Aussage", nie eine erfundene Stufe', function () {
    $this->makeIngredient($this->gericht, 'Xylo Quirk', null, '10', 5);

    $a = $this->svc->analyseRezept($this->gericht->id);
    $unbekannt = collect($a['harmonie']['paare'])->where('stufe', 'unbekannt');
    expect($unbekannt)->toHaveCount(4)
        ->and($unbekannt->first()['satz'])->toContain('Xylo Quirk: noch keinem Aroma zugeordnet')
        ->and($a['harmonie']['zusammenhalt']['abdeckung_pct'])->toBe(60);
});

it('Composer: freie Anker-Menge liefert Harmonie ohne Geschmacksprofil', function () {
    $ids = DB::table('foodalchemist_vocab_pairing_anchors')->whereIn('slug', ['rind', 'fond', 'sauce'])->pluck('id')->all();
    $a = $this->svc->analyseAnker($ids);

    expect(collect($a['harmonie']['paare'])->pluck('stufe')->sort()->values()->all())->toBe(['bruecke', 'sehr_gut', 'sehr_gut'])
        ->and($a['kontrast'])->toBe([]);
});

it('MCP: pairings.SUGGEST und composer.KOHAESION liefern harmonie_kontrast (ausgeführt, nicht nur registriert)', function () {
    $user = $this->makeUser($this->rootTeam);
    $kontext = new \Platform\Core\Contracts\ToolContext($user, $this->rootTeam);
    $registry = app(\Platform\Core\Tools\ToolRegistry::class);

    $suggest = $registry->get('foodalchemist.pairings.SUGGEST')->execute(['recipe_id' => $this->gericht->id], $kontext);
    expect($suggest->success)->toBeTrue($suggest->error ?? '')
        ->and($suggest->data['harmonie_kontrast']['harmonie']['zusammenhalt']['bewertet'])->toBe(6)
        ->and(collect($suggest->data['harmonie_kontrast']['kontrast'])->pluck('satz')->implode(' '))->toContain('Fett von Rind');

    $ids = DB::table('foodalchemist_vocab_pairing_anchors')->whereIn('slug', ['rind', 'fond'])->pluck('id')->all();
    $kohaesion = $registry->get('foodalchemist.composer.KOHAESION')->execute(['anker_ids' => $ids], $kontext);
    expect($kohaesion->success)->toBeTrue($kohaesion->error ?? '')
        ->and($kohaesion->data['harmonie_kontrast']['harmonie']['paare'][0]['stufe'])->toBe('sehr_gut');
});

it('Vorschläge: veganes Gericht bekommt keinen Hühnerfond und keinen Speck, nur 3★ zählt', function () {
    $neu = function (string $slug, string $kat, ?string $sub = null): int {
        DB::table('foodalchemist_vocab_pairing_anchors')->insert([
            'uuid' => (string) UuidV7::generate(), 'slug' => $slug, 'display_de' => ucfirst($slug),
            'category' => $kat, 'subcategory' => $sub, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return (int) DB::getPdo()->lastInsertId();
    };
    $kante = function (int $a, int $b, int $level): void {
        foreach ([[$a, $b], [$b, $a]] as [$x, $y]) {
            DB::table('foodalchemist_pairing_anchor_edges')->insert([
                'uuid' => (string) UuidV7::generate(), 'anchor_a_id' => $x, 'anchor_b_id' => $y,
                'type' => 'aroma', 'level' => $level, 'axis' => 'harmony', 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    };
    $kohl = $neu('kohl', 'Gemüse');
    $moehre = $neu('moehre', 'Gemüse');
    $huehnerfond = $neu('chicken_fond', 'Brühen und Fonds');
    $speck = $neu('speck', 'Protein', 'Protein/Fleisch');
    $kuemmel = $neu('kuemmel', 'Gewuerze');
    $nurGut = $neu('apfel', 'Obst');
    foreach ([$huehnerfond, $speck, $kuemmel] as $partner) {
        $kante($partner, $kohl, 3);
        $kante($partner, $moehre, 3);
    }
    $kante($nurGut, $kohl, 2);          // nur ★★ → kein Vorschlag
    $kante($nurGut, $moehre, 2);

    $gericht = $this->makeRecipe($this->rootTeam, 'Kohl und Möhre vegan');
    $gericht->update(['spec_is_vegan' => true]);
    foreach (['Kohl: frisch' => $kohl, 'Moehre: frisch' => $moehre] as $name => $anker) {
        $gp = $this->makeGp($this->rootTeam, $name);
        ($this->mapping)($gp->id, $anker);
        $this->makeIngredient($gericht, $name, $gp, '100', 1);
    }

    $sug = app(\Platform\FoodAlchemist\Services\PairingService::class)->componentSuggestions($gericht->fresh());
    $slugs = collect($sug['klassiker'])->pluck('slug')->merge(collect($sug['signature'])->pluck('slug'))->unique()->values()->all();

    expect($slugs)->toBe(['kuemmel']);
});
