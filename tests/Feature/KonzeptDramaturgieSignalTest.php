<?php

use Illuminate\Support\Facades\DB;
use Platform\FoodAlchemist\Enums\SignalTyp;
use Platform\FoodAlchemist\Models\FoodAlchemistPaket;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Services\DataQualityService;
use Platform\FoodAlchemist\Services\PairingService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;
use Symfony\Component\Uid\UuidV7;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 21 · S4b-2 — `konzept_dramaturgie`, die Anker-Graph-Hälfte von Tranche C.
 *
 * Zwei Kanten hält diese Datei fest:
 *  · Hauptzutat = die **mengenmäßig dominierende** Zutat, identifiziert über den
 *    Kern-Anker ihres GP. Nicht die Anker-Union aus `menuCohesion` (dort wäre „beide
 *    enthalten Butter" derselbe Befund wie „beide sind Lachs") und nicht
 *    `recipe_anchor_mappings` am Gericht (im Bestand ein Beutel aller Zutaten-Anker).
 *  · Wo nichts vergleichbar ist (kein Anker, `neutral`, ein einziges Gericht),
 *    schweigt der Check. Unbewertet ist keine Aussage über das Menü (T9-Ehrlichkeit).
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->dq = app(DataQualityService::class);

    // Anker sind global (team_id null) und der Slug ist unique — ein Slug, eine Zeile,
    // egal wie viele Gerichte ihn tragen.
    $this->anker = function (string $slug): int {
        $vorhanden = DB::table('foodalchemist_vocab_pairing_anchors')->where('slug', $slug)->value('id');

        return $vorhanden !== null ? (int) $vorhanden : DB::table('foodalchemist_vocab_pairing_anchors')->insertGetId([
            'uuid' => (string) UuidV7::generate(),
            'team_id' => null,
            'slug' => $slug,
            'display_de' => ucfirst($slug),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    };

    // Ein GP je Slug+Team — derselbe „Lachs" in zwei Gerichten ist genau der reale Fall.
    $this->gpCache = [];

    /** GP mit Kern-Anker (idempotent je Slug+Team). */
    $this->gp = function (string $slug, $team = null) {
        $team ??= $this->rootTeam;
        if (isset($this->gpCache[$team->id . '|' . $slug])) {
            return $this->gpCache[$team->id . '|' . $slug];
        }
        $gp = $this->gpCache[$team->id . '|' . $slug] = $this->makeGp($team, ucfirst($slug));
        DB::table('foodalchemist_gp_anchor_mappings')->insert([
            'uuid' => (string) UuidV7::generate(),
            'team_id' => $team->id,
            'gp_id' => $gp->id,
            'anchor_id' => ($this->anker)($slug),
            'role' => 'kern',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $gp;
    };

    /**
     * VK-Gericht mit einer dominierenden Zutat; $slug = null ⇒ Zutat ohne GP-Anker
     * (unbewertet). Weitere Zutaten hängt der Test selbst über makeIngredient an.
     */
    $this->gericht = function (string $name, ?string $slug, $team = null): FoodAlchemistRecipe {
        $team ??= $this->rootTeam;
        $g = $this->makeRecipe($team, 'HG: ' . $name, [
            'is_sales_recipe' => true,
            'sales_wording_standard' => $name,
        ]);
        $this->makeIngredient($g, $slug !== null ? ucfirst($slug) : 'Unbestimmt',
            $slug !== null ? ($this->gp)($slug, $team) : $this->makeGp($team, 'Unbestimmt ' . $g->id), '500');

        return $g;
    };

    /** Konzept in Gebrauch mit je einem Gericht-Slot pro Gericht. */
    $this->menue = function (string $name, array $gerichte, $team = null) {
        $team ??= $this->rootTeam;
        $k = $this->makeConcept($team, $name);
        foreach ($gerichte as $i => $g) {
            $this->makeConceptSlot($k, ['position' => $i + 1, 'sales_recipe_id' => $g->id]);
        }

        return $k;
    };
});

it('führt den Typ in der Konzept-Ebene und zählt ihn zur Tranche C', function () {
    $e = $this->dq->messeAlleEbenen($this->rootTeam);

    expect(array_column($e['konzept']['metriken'], 'key'))->toBe([
        'konzept_slot_luecke', 'konzept_ohne_wording', 'konzept_preisband_verletzt',
        'konzept_regel_verletzt', 'konzept_dramaturgie',
    ])->and(SignalTyp::KonzeptDramaturgie->istKonzeptQualitaet())->toBeTrue();
});

it('meldet zwei Gänge mit derselben Hauptzutat — als Hinweis, nicht als Warnung', function () {
    ($this->menue)('Lachs-Menü', [
        ($this->gericht)('Lachstatar', 'lachs'),
        ($this->gericht)('Lachsfilet', 'lachs'),
    ]);

    $m = collect($this->dq->messeAlleEbenen($this->rootTeam)['konzept']['metriken'])
        ->firstWhere('key', 'konzept_dramaturgie');
    $items = $this->dq->betroffene($this->rootTeam, 'konzept_dramaturgie');

    expect($m['wert'])->toBe(1)
        // `info` statt `gelb`: eine Wiederholung kann gewollt sein (Themen-Menü).
        ->and($m['severity'])->toBe('info')
        ->and(array_column($items, 'name'))->toBe(['Lachs-Menü'])
        ->and($items[0]['kind'])->toBe('concept')
        ->and($this->dq->trifftObjekt($this->rootTeam, 'konzept_dramaturgie', 'concept', $items[0]['id']))->toBeTrue();
});

it('schweigt bei verschiedenen Hauptzutaten', function () {
    ($this->menue)('Sauberes Menü', [
        ($this->gericht)('Lachsfilet', 'lachs'),
        ($this->gericht)('Rehrücken', 'reh'),
        ($this->gericht)('Schokomousse', 'schokolade'),
    ]);

    expect($this->dq->countFor($this->rootTeam, 'konzept_dramaturgie'))->toBe(0);
});

it('erkennt die Sorten-Variante als dieselbe Hauptzutat', function () {
    // `lachs` vs. `lachs_wild`: für den Gast zweimal Lachs. ankerSlugMatches deckt
    // genau diese Präfix-Nähe ab — eine reine Anker-ID-Gleichheit täte es nicht.
    ($this->menue)('Wildlachs-Menü', [
        ($this->gericht)('Lachstatar', 'lachs'),
        ($this->gericht)('Wildlachs', 'lachs_wild'),
    ]);

    expect($this->dq->countFor($this->rootTeam, 'konzept_dramaturgie'))->toBe(1);
});

it('macht aus fehlender Erdung keinen Befund', function () {
    // Zweimal `neutral` (= kein Identitäts-Anker, so liest es auch resolveRecipeAnchors)
    // und zweimal gar kein Mapping: der Graph sieht diese Gerichte nicht. Sie als
    // Wiederholung zu melden wäre eine erfundene Aussage.
    ($this->menue)('Neutral-Menü', [
        ($this->gericht)('Suppe', 'neutral'),
        ($this->gericht)('Eintopf', 'neutral'),
    ]);
    ($this->menue)('Ungeerdetes Menü', [
        ($this->gericht)('Vorspeise', null),
        ($this->gericht)('Hauptgang', null),
    ]);

    expect($this->dq->countFor($this->rootTeam, 'konzept_dramaturgie'))->toBe(0);
});

it('braucht eine Menüfolge — ein einzelnes Gericht wiederholt sich nicht', function () {
    ($this->menue)('Ein-Gang', [($this->gericht)('Lachsfilet', 'lachs')]);

    expect($this->dq->countFor($this->rootTeam, 'konzept_dramaturgie'))->toBe(0);
});

it('sieht auch die Gerichte eines Paket-Slots', function () {
    $paket = FoodAlchemistPaket::create(['team_id' => $this->rootTeam->id, 'name' => 'Fingerfood-Paket']);
    foreach ([($this->gericht)('Lachs-Häppchen', 'lachs'), ($this->gericht)('Lachs-Praline', 'lachs')] as $i => $g) {
        $paket->dishes()->create([
            'team_id' => $this->rootTeam->id,
            'sales_recipe_id' => $g->id,
            'position' => $i + 1,
        ]);
    }
    $k = $this->makeConcept($this->rootTeam, 'Paket-Menü');
    $this->makeConceptSlot($k, ['type' => 'paket', 'sales_recipe_id' => null, 'package_id' => $paket->id]);

    // Ein Paket bringt seine Gerichte in die Menüfolge ein, auch ohne eigenen Slot.
    expect($this->dq->countFor($this->rootTeam, 'konzept_dramaturgie'))->toBe(1);
});

it('hält Arbeitsmenge und Team-Grenze', function () {
    $k = ($this->menue)('Lachs-Menü', [
        ($this->gericht)('Lachstatar', 'lachs'),
        ($this->gericht)('Lachsfilet', 'lachs'),
    ]);
    ($this->menue)('Kind-A-Lachs', [
        ($this->gericht)('Lachstatar', 'lachs', $this->childA),
        ($this->gericht)('Lachsfilet', 'lachs', $this->childA),
    ], $this->childA);

    // Team-Hierarchie statt harter Isolation: ein Kind sieht die eigene Kette nach oben,
    // nie die Schwester. Root selbst sieht nur sein eigenes Konzept.
    expect($this->dq->countFor($this->rootTeam, 'konzept_dramaturgie'))->toBe(1)
        ->and($this->dq->countFor($this->childA, 'konzept_dramaturgie'))->toBe(2)
        ->and($this->dq->countFor($this->childB, 'konzept_dramaturgie'))->toBe(1);

    // Entwurf = Arbeitsstand, kein Befund (dieselbe Grenze wie S4a/S4b-1).
    $k->update(['status' => 'draft']);

    expect($this->dq->countFor($this->rootTeam, 'konzept_dramaturgie'))->toBe(0);
});

it('vergleicht die dominierende Zutat, nicht jede geteilte', function () {
    // Beide Gänge enthalten Butter — das ist keine Dramaturgie-Aussage, sondern Küche.
    $lachs = ($this->gericht)('Lachsfilet', 'lachs');
    $reh = ($this->gericht)('Rehrücken', 'reh');
    $this->makeIngredient($lachs, 'Butter', ($this->gp)('butter'), '30', 2);
    $this->makeIngredient($reh, 'Butter', ($this->gp)('butter'), '40', 2);

    expect(app(PairingService::class)->menuRepetitions([$lachs->id, $reh->id]))->toBe([]);

    // Kippt die Mengenlage, kippt der Befund: jetzt ist Butter in beiden die Hauptzutat.
    DB::table('foodalchemist_recipe_ingredients')
        ->whereIn('recipe_id', [$lachs->id, $reh->id])->where('position', 2)->update(['quantity' => 900]);
    $treffer = app(PairingService::class)->menuRepetitions([$lachs->id, $reh->id]);

    expect($treffer)->toHaveCount(1)
        ->and($treffer[0]['slug'])->toBe('butter')
        ->and($treffer[0]['recipe_ids'])->toBe([$lachs->id, $reh->id]);
});

it('lässt ein Gericht mit nicht massen-vergleichbarer Zeile ganz unbewertet', function () {
    // Der reale Fall (Dev-MySQL): eine Mayonnaise, deren Masse in Litern steht — die
    // schwerste *messbare* Zeile wäre dann das Salz. Ein Gericht wird deshalb nur
    // bewertet, wenn ALLE beitragenden Zeilen in Masse vergleichbar sind.
    $liter = \Platform\FoodAlchemist\Models\FoodAlchemistVocabEinheit::create([
        'team_id' => $this->rootTeam->id, 'slug' => 'l', 'display_de' => 'Liter', 'dimension' => 'volume',
    ]);
    $a = ($this->gericht)('Lachstatar', 'lachs');
    $b = ($this->gericht)('Lachsfilet', 'lachs');

    expect(app(PairingService::class)->menuRepetitions([$a->id, $b->id]))->toHaveCount(1);

    // Eine einzige Volumen-Zeile im ersten Gericht nimmt das ganze Paar aus der Wertung.
    $oel = $this->makeIngredient($a, 'Öl', ($this->gp)('oel'), '2', 2);
    $oel->update(['unit_vocab_id' => $liter->id]);

    expect(app(PairingService::class)->menuRepetitions([$a->id, $b->id]))->toBe([]);
});
