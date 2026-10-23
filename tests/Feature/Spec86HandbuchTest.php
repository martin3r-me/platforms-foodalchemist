<?php

use Livewire\Livewire;
use Platform\FoodAlchemist\Livewire\Handbuch\Panel;
use Platform\FoodAlchemist\Models\FoodAlchemistHandbuch;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeStep;
use Platform\FoodAlchemist\Services\HandbuchService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 86 — Handbuch (Standards zum Nachschlagen): eigener, eingefrorener Lese-Link neben dem
 * Kundenlink. Kernzusagen: Zubereitung + Anrichten im Snapshot, KEINE Preise/Interna, eigener
 * Token/Ablauf/Widerruf, Lücken sichtbar, Kundenlink unberührt.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->hb = app(HandbuchService::class);
    $this->actingAs($this->makeUser($this->rootTeam, 'Handbuch'));

    $this->baue = function ($mitSchritten = true) {
        $team = $this->rootTeam;
        $concept = $this->makeConcept($team, 'Menü A', ['kind' => 'concept', 'consumer_name' => 'Genuss']);
        $dish = $this->makeRecipe($team, 'Zanderfilet', ['is_sales_recipe' => true, 'sales_net' => 12.34]);
        $this->makeConceptSlot($concept, ['sales_recipe_id' => $dish->id, 'wording' => 'Zanderfilet auf Linsen', 'position' => 1]);
        $fb = $this->makeFoodbook($team, 'Sommerfest', ['personen' => 6]);
        $kap = $this->makeChapter($fb, ['title' => 'Hauptgänge', 'consumer_title' => 'Hauptgänge', 'position' => 1]);
        $this->makeFoodbookBlock($kap, ['type' => 'concept_ref', 'concept_id' => $concept->id, 'position' => 1]);
        if ($mitSchritten) {
            foreach ([['produktion', 'Zander auf der Haut kross braten.'], ['anrichten', 'Linsen mittig auf den Teller.']] as $i => [$ebene, $text]) {
                FoodAlchemistRecipeStep::create([
                    'team_id' => $team->id, 'recipe_id' => $dish->id, 'position' => $i + 1, 'ebene' => $ebene, 'text' => $text,
                ]);
            }
        }

        return $fb;
    };
});

it('veröffentlicht ein Handbuch mit Zubereitung und Anrichten, ohne Preise', function () {
    $fb = ($this->baue)();
    $res = $this->hb->publish($this->rootTeam, 'foodbook', $fb->id, ['expires_at' => now()->addDays(30)->toDateString()]);

    $snap = FoodAlchemistHandbuch::where('token', $res['token'])->firstOrFail()->snapshot_json;
    $karte = array_values($snap['gerichte'])[0];
    expect($karte['zubereitung'][0]['text'])->toBe('Zander auf der Haut kross braten.')
        ->and($karte['anrichten'][0]['text'])->toBe('Linsen mittig auf den Teller.');
    // ALLOWLIST: weder Preisfelder noch EK im Snapshot.
    $json = json_encode($snap);
    expect($json)->not->toContain('sales_net')->not->toContain('12.34')->not->toContain('"ek')->not->toContain('price');

    $this->get('/p/handbuch/' . $res['token'])
        ->assertOk()
        ->assertSee('Zanderfilet auf Linsen')
        ->assertSee('Zander auf der Haut kross braten.')
        ->assertSee('Linsen mittig auf den Teller.')
        ->assertDontSee('12,34');
});

it('verlangt ein Ablaufdatum', function () {
    $fb = ($this->baue)();
    expect(fn () => $this->hb->publish($this->rootTeam, 'foodbook', $fb->id, []))
        ->toThrow(\RuntimeException::class, 'gültig-bis');
});

it('zurückgezogen und abgelaufen ist der Link 404, Wieder-Veröffentlichen behält den Token', function () {
    $fb = ($this->baue)();
    $res = $this->hb->publish($this->rootTeam, 'foodbook', $fb->id, ['expires_at' => now()->addDays(10)->toDateString()]);

    $this->hb->withdraw($this->rootTeam, 'foodbook', $fb->id);
    $this->get('/p/handbuch/' . $res['token'])->assertNotFound();

    $neu = $this->hb->publish($this->rootTeam, 'foodbook', $fb->id, ['expires_at' => now()->addDays(10)->toDateString()]);
    expect($neu['token'])->toBe($res['token']);
    $this->get('/p/handbuch/' . $res['token'])->assertOk();

    FoodAlchemistHandbuch::where('token', $res['token'])->update(['expires_at' => now()->subDay()]);
    $this->get('/p/handbuch/' . $res['token'])->assertNotFound();
});

it('meldet Gerichte ohne Zubereitung, Anrichten und Regeneration als Lücke und lässt den Abschnitt weg', function () {
    $fb = ($this->baue)(mitSchritten: false);
    $status = $this->hb->status($this->rootTeam, 'foodbook', $fb->id);
    $luecken = $this->hb->luecken($this->rootTeam, 'foodbook', $fb->id);

    expect($status['veroeffentlicht'])->toBeFalse()
        ->and($luecken)->toHaveCount(1)
        ->and($luecken[0]['fehlt'])->toContain('Zubereitung', 'Anrichten');

    $res = $this->hb->publish($this->rootTeam, 'foodbook', $fb->id, ['expires_at' => now()->addDays(5)->toDateString()]);
    $this->get('/p/handbuch/' . $res['token'])->assertOk()->assertSee('Keine Zubereitung hinterlegt.');
});

it('lässt den Kundenlink der Präsentation unberührt', function () {
    $fb = ($this->baue)();
    $this->hb->publish($this->rootTeam, 'foodbook', $fb->id, ['expires_at' => now()->addDays(30)->toDateString()]);

    expect((bool) $fb->refresh()->presentation_enabled)->toBeFalse()
        ->and($fb->presentation_token)->toBeNull();
});

it('unbekannter Token ist 404', function () {
    $this->get('/p/handbuch/gibtesnicht')->assertNotFound();
});

it('Panel: veröffentlicht, zeigt Link und Lücken, zieht zurück', function () {
    $fb = ($this->baue)(mitSchritten: false);

    $c = Livewire::test(Panel::class, ['type' => 'foodbook', 'quelleId' => $fb->id])
        ->call('pruefen')
        ->assertSee('Lücken im aktuellen Stand')
        ->set('gueltigBis', now()->addDays(14)->toDateString())
        ->call('veroeffentlichen')
        ->assertSee('/p/handbuch/')
        ->assertSee('Erreichbar');
    $token = FoodAlchemistHandbuch::firstOrFail()->token;
    $this->get('/p/handbuch/' . $token)->assertOk();

    $c->call('zurueckziehen')->assertSee('Nicht erreichbar');
    $this->get('/p/handbuch/' . $token)->assertNotFound();
});

it('Zutaten und aufklappbares Basisrezept stehen im Handbuch, ohne Preise und Lieferanten', function () {
    $fb = ($this->baue)();
    $team = $this->rootTeam;
    $dish = \Platform\FoodAlchemist\Models\FoodAlchemistRecipe::where('name', 'Zanderfilet')->firstOrFail();
    $linsen = $this->makeRecipe($team, 'Linsengemüse Basis', ['is_sales_recipe' => false, 'sales_net' => 7.77]);
    $this->makeIngredient($linsen, 'Beluga-Linsen', $this->makeGp($team, 'Beluga-Linsen'), '500', 1);
    FoodAlchemistRecipeStep::create(['team_id' => $team->id, 'recipe_id' => $linsen->id, 'position' => 1, 'ebene' => 'produktion', 'text' => 'Linsen 25 min weich garen.']);
    $this->makeIngredient($dish, 'Zander', $this->makeGp($team, 'Zanderfilet mit Haut'), '180', 1);
    $sub = $this->makeIngredient($dish, 'Linsengemüse', null, '120', 2);
    $sub->update(['referenced_recipe_id' => $linsen->id]);

    $res = $this->hb->publish($team, 'foodbook', $fb->id, ['expires_at' => now()->addDays(10)->toDateString()]);
    $snap = FoodAlchemistHandbuch::where('token', $res['token'])->firstOrFail()->snapshot_json;
    $namen = array_column(array_values($snap['gerichte'])[0]['zutaten']['zeilen'], 'name');
    expect($namen)->toContain('Zanderfilet mit Haut')
        ->and($snap['basisrezepte'])->not->toBeEmpty()
        ->and(array_values($snap['basisrezepte'])[0]['schritte'])->toContain('Linsen 25 min weich garen.');

    $json = json_encode($snap);
    expect($json)->not->toContain('7.77')->not->toContain('ek_')->not->toContain('lieferant')->not->toContain('price');

    $this->get('/p/handbuch/' . $res['token'])->assertOk()->assertSee('Basisrezept')->assertSee('Linsen 25 min weich garen.')->assertDontSee('7,77');
});
