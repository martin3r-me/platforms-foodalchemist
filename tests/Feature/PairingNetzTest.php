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

/**
 * M5-07: Pairing-Netz — Datenbasis (Ring, Brücken-Dedupe, Verwandte mit
 * Andock-Ankern, Vorschläge außerhalb des Rings) + Modal-Smoke.
 * DoD-Realdaten-Check (BBQ 27 Anker/115 Brücken) lief live, siehe Roadmap.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->svc = app(PairingService::class);

    $mkAnker = function (string $slug) {
        DB::table('foodalchemist_vocab_pairing_anchors')->insert([
            'uuid' => (string) UuidV7::generate(), 'slug' => $slug, 'display_de' => ucfirst($slug),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return (int) DB::getPdo()->lastInsertId();
    };
    $this->ketchup = $mkAnker('ketchup');
    $this->chili = $mkAnker('chili');
    $this->essig = $mkAnker('essig');
    $this->vanille = $mkAnker('vanille');                             // außerhalb des Rings → Vorschlag

    foreach ([[$this->ketchup, $this->chili, 'erprobt'], [$this->chili, $this->essig, 'kontrast'], [$this->ketchup, $this->vanille, 'erprobt']] as [$a, $b, $typ]) {
        foreach ([[$a, $b], [$b, $a]] as [$x, $y]) {
            DB::table('foodalchemist_pairing_anchor_edges')->insert([
                'uuid' => (string) UuidV7::generate(), 'anchor_a_id' => $x, 'anchor_b_id' => $y,
                'type' => $typ, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    $this->rezept = FoodAlchemistRecipe::create(['team_id' => $this->rootTeam->id, 'recipe_key' => 'netz', 'name' => 'Sauce: Netz', 'status' => 'draft']);
    $this->verwandt = FoodAlchemistRecipe::create(['team_id' => $this->rootTeam->id, 'recipe_key' => 'netz2', 'name' => 'Sauce: Verwandt', 'status' => 'draft', 'is_sales_recipe' => true]);

    $this->svc->setRecipeAnker($this->rootTeam, $this->rezept->id, $this->ketchup);   // Kern-Anker ★
    foreach ([[$this->rezept->id, $this->chili], [$this->rezept->id, $this->essig], [$this->verwandt->id, $this->chili], [$this->verwandt->id, $this->essig]] as [$rid, $aid]) {
        DB::table('foodalchemist_recipe_pairings')->insert([
            'uuid' => (string) UuidV7::generate(), 'team_id' => $this->rootTeam->id,
            'recipe_id' => $rid, 'anchor_id' => $aid, 'type' => 'erprobt', 'confidence' => 'hoch',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }
});

it('pairingNetz: Kern zuerst (★), Brücken einmal je Paar mit Typ, Verwandte docken an, VK-Flag', function () {
    $netz = $this->svc->pairingNetz($this->rootTeam, $this->rezept->id);

    expect($netz['zentrum']['name'])->toBe('Sauce: Netz')
        ->and(array_column($netz['anker'], 'slug'))->toBe(['ketchup', 'chili', 'essig'])  // Kern vor Pairing-Ankern
        ->and($netz['anker'][0]['kern'])->toBeTrue()
        ->and($netz['anker'][1]['kern'])->toBeFalse();

    // Kanten im Ring: ketchup–chili (erprobt) + chili–essig (kontrast); ketchup–vanille liegt außerhalb
    $kanten = collect($netz['kanten'])->map(fn ($k) => [min($k['a'], $k['b']), max($k['a'], $k['b']), $k['type']]);
    expect($kanten)->toHaveCount(2)
        ->and($kanten->contains([min($this->ketchup, $this->chili), max($this->ketchup, $this->chili), 'erprobt']))->toBeTrue()
        ->and($kanten->contains([min($this->chili, $this->essig), max($this->chili, $this->essig), 'kontrast']))->toBeTrue();

    expect($netz['verwandte'])->toHaveCount(1)
        ->and($netz['verwandte'][0]['vk'])->toBeTrue()
        ->and($netz['verwandte'][0]['shared_anker_ids'])->toEqualCanonicalizing([$this->chili, $this->essig]);

    expect($netz['vorschlaege'])->toBe([]);                           // Modus aus (0)
});

it('pairingNetz: Vorschlags-Modus liefert nur Anker AUSSERHALB des Rings', function () {
    $netz = $this->svc->pairingNetz($this->rootTeam, $this->rezept->id, 2);

    $slugs = array_column($netz['vorschlaege'], 'slug');
    expect($slugs)->toContain('vanille')                              // ketchup→vanille (klassisch)
        ->and(array_intersect($slugs, ['ketchup', 'chili', 'essig']))->toBe([]);
    expect($netz['vorschlaege'][0]['anchor_id'])->toBe($this->ketchup);
});

it('Modal: öffnen rendert Zentrum/Anker/Brücken, Klick auf Rezept navigiert und schließt', function () {
    $this->actingAs($this->makeUser($this->rootTeam));

    $c = Livewire::test(PairingNetzModal::class);
    $c->dispatch('pairing-netz.oeffnen', recipeId: $this->rezept->id);

    $c->assertDispatched('modal.open')
        ->assertSeeHtml('data-netz-zentrum')
        ->assertSeeHtml('data-netz-anker="ketchup"')
        ->assertSeeHtml('data-bruecke="kontrast"')
        ->assertSeeHtml('data-netz-rezept="' . $this->verwandt->id . '"');

    $c->call('zeigeRezept', $this->verwandt->id)
        ->assertDispatched('recipe-selected', id: $this->verwandt->id)
        ->assertDispatched('modal.close');
});
