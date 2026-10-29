<?php

use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Services\IngredientMatchService;
use Platform\FoodAlchemist\Services\Matching\MatchLeitplanken;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Dominique 29.10.: selbst gemacht oder gekauft entscheiden die Leitplanken des Gerichts (Eigenleistung vor Niveau) — für JEDE Basiskomponente,
 * „im Convenience-Bereich kannst du alles fertig kaufen“. Die Grenze zieht der Bestand: Zukauf nur, wenn ein passendes GP da ist, sonst
 * Unterrezept. Ohne Leitplanke: Unterrezept, mit Hinweis aufs Zukauf-GP. „Öl: neutral“ ist das Standard-GP, „Extrakt:“ immer Kaufware.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam));
    config(['foodalchemist.semantic_search.enabled' => false]);
    $this->gp = fn (string $name, string $slug, string $wg = '13') => FoodAlchemistGp::create(['team_id' => $this->rootTeam->id, 'name' => $name,
        'gp_key' => 'lz-' . bin2hex(random_bytes(4)) . '||', 'status' => 'approved', 'requires_la' => true, 'commodity_group_code' => $wg, 'main_ingredient_slug' => $slug]);
    $svc = app(IngredientMatchService::class);
    // Weg einer Zeile unter Leitplanken: 'gp' (Zukauf verdrahtet), 'stub' (Unterrezept), 'kein' (keine Basiskomponente)
    $this->weg = function (string $text, array $leitplanken = []) use ($svc) {
        $lp = MatchLeitplanken::aus($leitplanken);
        $r = $svc->matchIngredient($this->rootTeam, $text, null, 'gp_first', 'fresh_first', false, 'conventional', convenience: $lp['convenience'], zubereitung: $lp['zubereitung']);

        return [match (true) {
            ($r['basiskomponente'] ?? null) === 'stub' => 'stub',
            IngredientMatchService::istAutomatischVerdrahtbar($r) && $r['target'] === 'gp' => 'gp',
            default => 'kein',
        }, $r['hinweis'] ?? null];
    };
});

it('Leitplanken → Zubereitung: Eigenleistung vor Niveau, ohne beides „ohne“ (Standard eigen)', function () {
    expect(MatchLeitplanken::zubereitung(['convenience' => 'from_scratch', 'level' => 'klassisch']))->toBe('eigen')
        ->and(MatchLeitplanken::zubereitung(['convenience' => 'voll_convenience', 'level' => 'haute_cuisine']))->toBe('zukauf')
        ->and(MatchLeitplanken::zubereitung(['convenience' => 'teil_convenience']))->toBe('zukauf')
        ->and(MatchLeitplanken::zubereitung(['level' => 'gehoben']))->toBe('eigen')
        ->and(MatchLeitplanken::zubereitung(['level' => 'haute_cuisine']))->toBe('eigen')
        ->and(MatchLeitplanken::zubereitung(['niveau' => 'klassisch']))->toBe('zukauf')
        ->and(MatchLeitplanken::zubereitung([]))->toBe('ohne')
        ->and(MatchLeitplanken::OHNE_VORGABE)->toBe('eigen')
        ->and(MatchLeitplanken::aus(['level' => 'klassisch'])['zubereitung'])->toBe('zukauf');
});

it('je Gruppe: klassisch mit GP → Zukauf, klassisch ohne GP → Unterrezept, gehoben → Unterrezept, voll_convenience mit GP → Zukauf', function (string $text, string $gpName, string $slug) {
    expect(($this->weg)($text, ['level' => 'klassisch'])[0])->toBe('stub');          // kein GP im Bestand → Unterrezept, nie ein erfundener Zukauf
    ($this->gp)($gpName, $slug);
    expect(($this->weg)($text, ['level' => 'klassisch'])[0])->toBe('gp')
        ->and(($this->weg)($text, ['convenience' => 'voll_convenience'])[0])->toBe('gp')
        ->and(($this->weg)($text, ['level' => 'gehoben'])[0])->toBe('stub')
        ->and(($this->weg)($text, ['convenience' => 'from_scratch', 'level' => 'klassisch'])[0])->toBe('stub');
})->with([
    'Fond (Kompositum)' => ['Geflügelfond', 'Geflügelfond', 'gefluegelfond'],
    'Fond (Präfix)' => ['Fond: Geflügel', 'Geflügelfond', 'gefluegelfond'],
    'Sauce' => ['Hollandaise', 'Hollandaise', 'hollandaise'],
    'Dressing' => ['Vinaigrette', 'Vinaigrette', 'vinaigrette'],
    'Pesto' => ['Pesto: Basilikum', 'Basilikumpesto', 'basilikumpesto'],
    'Dessert' => ['Mousse: Schokolade', 'Schokoladenmousse', 'schokoladenmousse'],
    'Gel' => ['Gel: Passionsfrucht', 'Passionsfruchtgel', 'passionsfruchtgel'],
    'Öl' => ['Öl: Kräuter', 'Kräuteröl', 'kraeuteroel'],
]);

it('ohne Leitplanke: Unterrezept wie bisher, mit Hinweis aufs vorhandene Zukauf-GP — ohne GP kein Hinweis', function () {
    [$weg, $hinweis] = ($this->weg)('Geflügelfond');
    expect($weg)->toBe('stub')->and($hinweis)->toBeNull();

    $gp = ($this->gp)('Geflügelfond', 'gefluegelfond');
    [$weg, $hinweis] = ($this->weg)('Geflügelfond');
    expect($weg)->toBe('stub')->and($hinweis)->toBe('Gibt es auch als Zukauf: ' . $gp->name);
});

it('„Öl: neutral“ ist das Standard-GP, nie ein Unterrezept; „Extrakt:“ bleibt Kaufware', function () {
    expect(($this->weg)('Öl: neutral')[0])->not->toBe('stub')
        ->and(($this->weg)('Öl: neutral', ['level' => 'gehoben'])[0])->not->toBe('stub')
        ->and(($this->weg)('Extrakt: Vanille', ['level' => 'gehoben'])[0])->not->toBe('stub')
        ->and(($this->weg)('Öl: Kräuter', ['level' => 'gehoben'])[0])->toBe('stub');
});

it('Zukauf-Signal im Text gewinnt weiter vor den Leitplanken (Pulver, Marke, TK)', function () {
    ($this->gp)('Geflügelfond: Pulver', 'gefluegelfond', '13');
    expect(($this->weg)('Geflügelfond Pulver', ['level' => 'haute_cuisine'])[0])->not->toBe('stub');
});

it('Import: die Leitplanke der Planung erreicht die Stub-Entscheidung — klassisch mit Zukauf-GP kein Stub, ohne Leitplanke Stub mit Hinweis', function () {
    \Illuminate\Support\Facades\Queue::fake();
    \Platform\FoodAlchemist\Models\FoodAlchemistVocabEinheit::create(['team_id' => $this->rootTeam->id, 'slug' => 'g', 'display_de' => 'Gramm', 'dimension' => 'mass', 'default_in_g' => 1]);
    $gp = ($this->gp)('Geflügelfond', 'gefluegelfond');
    $rezept = fn (string $name) => ['typ' => 'basisrezept', 'name' => $name, 'preparation' => 'Kochen.', 'zutaten' => [['text' => 'Geflügelfond', 'quantity' => '500', 'unit' => 'g']]];
    $import = app(\Platform\FoodAlchemist\Services\RecipeExtractService::class);

    $klassisch = $import->legeAn($this->rootTeam, $rezept('Rahmsauce klassisch'), parameter: ['level' => 'klassisch']);
    $ohne = $import->legeAn($this->rootTeam, $rezept('Rahmsauce ohne Vorgabe'));

    expect($klassisch['stubs'])->toBe([])
        ->and($klassisch['recipe']->ingredients()->first()->gp_id)->toBe($gp->id)
        ->and($ohne['stubs'])->toHaveCount(1)
        ->and($ohne['stubs'][0]['hinweis'] ?? null)->toBe('Gibt es auch als Zukauf: Geflügelfond');
});
