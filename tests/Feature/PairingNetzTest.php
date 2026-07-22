<?php

use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Platform\FoodAlchemist\Livewire\Recipes\PairingNetzModal;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Services\PairingService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;
use Symfony\Component\Uid\UuidV7;

uses(TestCase::class, SeedsTeamHierarchy::class);

function mkAnker(string $slug): int
{
    DB::table('foodalchemist_vocab_pairing_anchors')->insert([
        'uuid' => (string) UuidV7::generate(), 'slug' => $slug, 'display_de' => ucfirst($slug),
        'created_at' => now(), 'updated_at' => now(),
    ]);

    return (int) DB::getPdo()->lastInsertId();
}

function mkKante(int $a, int $b, string $typ, ?float $weight = null): void
{
    foreach ([[$a, $b], [$b, $a]] as [$x, $y]) {
        DB::table('foodalchemist_pairing_anchor_edges')->insert([
            'uuid' => (string) UuidV7::generate(), 'anchor_a_id' => $x, 'anchor_b_id' => $y,
            'type' => $typ, 'weight' => $weight, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}

/**
 * M5-07: Pairing-Netz — Empfehler-Datenbasis (Kern-Anker innen, typisierte
 * Kandidaten in Sektoren, komplementäre Basisrezepte) + Modal-Smoke.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->svc = app(PairingService::class);

    $this->kichererbse = mkAnker('kichererbse');
    $this->tahin = mkAnker('tahin');
    $this->knoblauch = mkAnker('knoblauch');     // erprobt-Partner beider Kern-Anker
    $this->granatapfel = mkAnker('granatapfel'); // kontrast-Partner
    $this->minze = mkAnker('minze');             // aroma-Partner

    // Kern-Anker ↔ Kandidaten (typisiert). knoblauch passt zu BEIDEN (cover=2).
    mkKante($this->kichererbse, $this->knoblauch, 'erprobt');
    mkKante($this->tahin, $this->knoblauch, 'erprobt');
    mkKante($this->kichererbse, $this->granatapfel, 'kontrast');
    mkKante($this->tahin, $this->minze, 'aroma');

    $this->rezept = FoodAlchemistRecipe::create(['team_id' => $this->rootTeam->id, 'recipe_key' => 'hummus', 'name' => 'Creme: Hummus', 'status' => 'draft']);
    $this->svc->setRecipeAnker($this->rootTeam, $this->rezept->id, $this->kichererbse);
    $this->svc->setRecipeAnker($this->rootTeam, $this->rezept->id, $this->tahin);

    // Komplementäres Basisrezept: baut auf knoblauch auf (Kandidat des Gerichts).
    $this->basis = FoodAlchemistRecipe::create(['team_id' => $this->rootTeam->id, 'recipe_key' => 'aioli', 'name' => 'Sauce: Aioli', 'status' => 'draft', 'is_sales_recipe' => false]);
    $this->svc->setRecipeAnker($this->rootTeam, $this->basis->id, $this->knoblauch);
    // VK-Rezept auf knoblauch → darf NICHT als Basisrezept auftauchen.
    $this->vk = FoodAlchemistRecipe::create(['team_id' => $this->rootTeam->id, 'recipe_key' => 'dip', 'name' => 'Dip: Knoblauch', 'status' => 'draft', 'is_sales_recipe' => true]);
    $this->svc->setRecipeAnker($this->rootTeam, $this->vk->id, $this->knoblauch);
});

it('pairingNetz: Zentrum + Kern-Anker innen, Kandidaten typisiert in Sektoren, dish_cover', function () {
    $netz = $this->svc->pairingNetz($this->rootTeam, $this->rezept->id);

    expect(collect($netz['nodes'])->firstWhere('kind', 'zentrum')['label'])->toBe('Creme: Hummus');

    $anker = collect($netz['nodes'])->where('kind', 'anker');
    expect($anker->pluck('slug')->sort()->values()->all())->toBe(['kichererbse', 'tahin'])
        ->and($anker->every(fn ($a) => $a['kern'] === true))->toBeTrue();

    $kand = collect($netz['nodes'])->where('kind', 'kandidat')->keyBy('slug');
    expect($kand['knoblauch']['typ'])->toBe('erprobt')
        ->and($kand['granatapfel']['typ'])->toBe('kontrast')
        ->and($kand['minze']['typ'])->toBe('aroma');
    // knoblauch bedient beide Kern-Anker → cover 2
    expect($kand['knoblauch']['cover'])->toBe(2)
        ->and($kand['granatapfel']['cover'])->toBe(1);

    // Kandidaten-Kanten tragen ihren Typ
    $kknob = collect($netz['edges'])->where('kind', 'kandidat')->where('source', 'k:'.$this->knoblauch);
    expect($kknob)->toHaveCount(2)                                      // zu kichererbse + tahin
        ->and($kknob->every(fn ($e) => $e['typ'] === 'erprobt'))->toBeTrue();

    expect($netz['meta']['counts'])->toBe(['erprobt' => 1, 'aroma' => 1, 'kontrast' => 1, 'basis' => 1])
        ->and($netz['meta']['typ_default'])->toBe(['erprobt' => true, 'aroma' => false, 'kontrast' => false]);
});

it('pairingNetz: komplementäres Basisrezept (baut auf Kandidat auf), VK ausgeschlossen', function () {
    $netz = $this->svc->pairingNetz($this->rootTeam, $this->rezept->id);

    $basis = collect($netz['nodes'])->where('kind', 'basisrezept');
    expect($basis)->toHaveCount(1)
        ->and($basis->first()['label'])->toBe('Sauce: Aioli')        // is_sales_recipe=false
        ->and($basis->first()['typ'])->toBe('erprobt')               // via knoblauch (erprobt)
        ->and($basis->first()['via'])->toBe('knoblauch');

    // VK-Rezept (Dip: Knoblauch) darf nicht als Basisrezept erscheinen
    expect($basis->pluck('label'))->not->toContain('Dip: Knoblauch');
});

it('pairingNetz: alle Knoten liegen im Canvas (0..W, 0..H)', function () {
    $netz = $this->svc->pairingNetz($this->rootTeam, $this->rezept->id);
    $w = $netz['meta']['canvas_w'];
    $h = $netz['meta']['canvas_h'];
    foreach ($netz['nodes'] as $n) {
        expect($n['x'])->toBeGreaterThanOrEqual(0)->toBeLessThanOrEqual($w)
            ->and($n['y'])->toBeGreaterThanOrEqual(0)->toBeLessThanOrEqual($h);
    }
});

it('Modal: öffnen liefert Netz-Payload, Klick auf Basisrezept navigiert und schließt', function () {
    $this->actingAs($this->makeUser($this->rootTeam));

    $c = Livewire::test(PairingNetzModal::class);
    $c->dispatch('pairing-netz.oeffnen', recipeId: $this->rezept->id);

    $c->assertDispatched('modal.open')
        ->assertViewHas('netz', function (array $netz) {
            $hatZentrum = collect($netz['nodes'])->firstWhere('kind', 'zentrum') !== null;
            $hatKandidat = collect($netz['nodes'])->where('kind', 'kandidat')->isNotEmpty();
            $hatBasis = collect($netz['nodes'])->firstWhere('id', 'b:'.$this->basis->id) !== null;

            return $hatZentrum && $hatKandidat && $hatBasis;
        });

    $c->call('zeigeRezept', $this->basis->id)
        ->assertDispatched('recipe-selected', id: $this->basis->id)
        ->assertDispatched('modal.close');
});
