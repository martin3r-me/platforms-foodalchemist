<?php

use Illuminate\Support\Facades\DB;
use Platform\FoodAlchemist\Services\SensorikService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;
use Symfony\Component\Uid\UuidV7;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Ursprünglich Spec 58 · Paket 2 (PairingAnalyseService). Seit Spec 60 · P7 geht die Analyse in der
 * Kombinationslogik auf (eigene Tests: KombinationslogikTest). Hier bleiben die Riegel, dass die
 * MCP-Werkzeuge die Kombinationslogik wirklich ausführen, und die Vorschläge nach Ernährungsform.
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
            \Platform\FoodAlchemist\Tests\Support\Harmonie::ausFixture([
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
});

it('MCP: pairings.SUGGEST, composer.KOHAESION und kombination.GET führen die Kombinationslogik aus', function () {
    $user = $this->makeUser($this->rootTeam);
    $kontext = new \Platform\Core\Contracts\ToolContext($user, $this->rootTeam);
    $registry = app(\Platform\Core\Tools\ToolRegistry::class);

    $suggest = $registry->get('foodalchemist.pairings.SUGGEST')->execute(['recipe_id' => $this->gericht->id], $kontext);
    $harmoniert = fn ($daten) => collect($daten['aussagen']['harmoniert'] ?? [])->pluck('text')->all();
    expect($suggest->success)->toBeTrue($suggest->error ?? '')
        ->and($harmoniert($suggest->data['kombination']))->toBe(['Rind: Hüfte und Fond: Kalb: harmonieren', 'Fond: Kalb und Sauce: Pfeffer: harmonieren'])
        ->and(collect($suggest->data['kombination']['aussagen']['passt'] ?? [])->pluck('text')->all())->toBe(['Rind: Hüfte und Rosmarin: frisch: passen'])
        ->and($suggest->data['kombination']['aussagen']['harmoniert'][0]['grundlage'])->toBe('inspire_gemessen');

    $get = $registry->get('foodalchemist.kombination.GET')->execute(['recipe_id' => $this->gericht->id], $kontext);
    expect($get->success)->toBeTrue($get->error ?? '')
        ->and($harmoniert($get->data))->toBe($harmoniert($suggest->data['kombination']))           // eine Logik, eine Antwort
        ->and(array_column($get->data['bestandteile'], 'label'))->toBe(['Rind: Hüfte', 'Fond: Kalb', 'Sauce: Pfeffer', 'Rosmarin: frisch']);

    $id = fn (string $slug) => (int) DB::table('foodalchemist_vocab_pairing_anchors')->where('slug', $slug)->value('id');
    $ids = [$id('rind'), $id('fond')];                                   // Reihenfolge der Auswahl bleibt erhalten
    $kohaesion = $registry->get('foodalchemist.composer.KOHAESION')->execute(['anker_ids' => $ids], $kontext);
    expect($kohaesion->success)->toBeTrue($kohaesion->error ?? '')
        ->and($harmoniert($kohaesion->data['kombination']))->toBe(['Rind und Fond: harmonieren']);

    expect($registry->get('foodalchemist.kombination.GET')->execute(['anker_ids' => [$ids[0]]], $kontext)->success)->toBeFalse();
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
            \Platform\FoodAlchemist\Tests\Support\Harmonie::ausFixture([
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
