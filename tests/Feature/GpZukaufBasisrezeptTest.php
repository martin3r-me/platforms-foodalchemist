<?php

use Livewire\Livewire;
use Platform\FoodAlchemist\Livewire\Gps\ZukaufRezept;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Services\ZukaufBasisrezeptService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Knopf „Als Zukauf-Basisrezept anlegen“ am Grundprodukt (Dominique 13.11.): gekaufte Fertigware bekommt ein
 * Basisrezept, das genau diese Ware führt und die Handgriffe beschreibt — gleicher Bau wie die Kaskade. Kein Doppel,
 * Rezepte mit dem GP bleiben unberührt, Rohware/Non-Food/fremde GPs nie.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam));
    config(['foodalchemist.ai.provider' => 'fake']);
    $this->unitG($this->rootTeam);
    app()->bind(\Platform\FoodAlchemist\Services\Ai\FakeAiProvider::class, fn () => new class extends \Platform\FoodAlchemist\Services\Ai\FakeAiProvider
    {
        public function chat(array $messages, array $options = []): array
        {
            return ['content' => json_encode(['werte' => [
                'schritte' => ['Crumble in GN-Behälter füllen.', 'Kurz vor dem Service auf das Gericht streuen.'],
                'hilfsstoffe' => [],
            ], 'confidence' => 0.8, 'reasoning' => 'stub'], JSON_UNESCAPED_UNICODE), 'usage' => ['input_tokens' => 0, 'output_tokens' => 0], 'model' => 'fake', 'tool_calls' => null];
        }
    });
    $this->gpMit = fn (\Platform\Core\Models\Team $team, string $name, string $wg, ?string $sub = null) => tap($this->makeGp($team, $name))
        ->update(['commodity_group_code' => $wg, 'sub_category' => $sub]);
    $this->regelAn = function () {
        $regel = \Platform\FoodAlchemist\Models\FoodAlchemistRule::where('schluessel', 'basisrezept.zukauf.typ')->firstOrFail();
        app(\Platform\FoodAlchemist\Services\Regeln\RegelService::class)->setzeAktiv($regel->id, true);
    };
});

it('Knopf legt das Zukauf-Basisrezept an: Typ aus der Regel, Ware als Zeile, Handgriffe, Editor öffnet, danach Link statt Knopf', function () {
    ($this->regelAn)();
    $gp = ($this->gpMit)($this->rootTeam, 'Kraeutercrumble gruen Jorda: trocken, Topping', '10', '10.4 Gewuerzmischungen');
    $gericht = $this->makeRecipe($this->rootTeam, '[HG] Kabeljau', ['status' => 'draft', 'is_sales_recipe' => true]);
    $zeile = $this->makeIngredient($gericht, 'Kräutercrumble', $gp, '20', 1);

    $lw = Livewire::test(ZukaufRezept::class, ['gpId' => $gp->id])
        ->assertSee('Als Zukauf-Basisrezept anlegen')
        ->assertSet('typ', 'Crunch')
        ->assertSet('bezeichnung', 'Kraeutercrumble gruen Jorda')
        ->call('formUmschalten')
        ->call('anlegen')
        ->assertSet('fehler', null);

    $r = FoodAlchemistRecipe::where('name', 'Crunch: Kraeutercrumble gruen Jorda (Zukauf)')->firstOrFail();
    $lw->assertDispatched('recipe-modal.oeffnen', id: (int) $r->id)
        ->assertSee('Zukauf-Basisrezept:')->assertSee($r->name)->assertDontSee('Als Zukauf-Basisrezept anlegen');

    $zeilen = $r->ingredients()->get();
    expect($r->is_sales_recipe)->toBeFalse()
        ->and((int) $r->team_id)->toBe((int) $this->rootTeam->id)
        ->and($r->production_depth)->toBe('convenience')
        ->and($zeilen)->toHaveCount(1)
        ->and((int) $zeilen->first()->gp_id)->toBe((int) $gp->id)
        ->and($r->steps()->count())->toBe(2)
        // Rezepte, die den GP nutzen, werden NICHT umgestellt
        ->and((int) $zeile->fresh()->gp_id)->toBe((int) $gp->id)
        ->and($zeile->fresh()->referenced_recipe_id)->toBeNull();

    // Kein Doppel: zweiter Aufruf liefert dasselbe Rezept
    $nochmal = app(ZukaufBasisrezeptService::class)->ausGp($this->rootTeam, (int) $gp->id);
    expect($nochmal['neu'])->toBeFalse()
        ->and((int) $nochmal['recipe']->id)->toBe((int) $r->id)
        ->and(FoodAlchemistRecipe::where('name', 'like', '%(Zukauf)')->count())->toBe(1);
});

it('ein von der Kaskade gebautes Zukauf-Basisrezept derselben Ware wird verlinkt, nicht doppelt angelegt', function () {
    $gp = ($this->gpMit)($this->rootTeam, 'Kuerbiskernoel: trocken', '11', '11.1 Oel');
    $vorhanden = $this->makeRecipe($this->rootTeam, 'Öl: Kürbiskern (Zukauf)');
    $this->makeIngredient($vorhanden, 'Kürbiskernöl', $gp, '1000', 1);

    Livewire::test(ZukaufRezept::class, ['gpId' => $gp->id])
        ->assertSee('Öl: Kürbiskern (Zukauf)')->assertDontSee('Als Zukauf-Basisrezept anlegen');
    expect((int) app(ZukaufBasisrezeptService::class)->ausGp($this->rootTeam, (int) $gp->id)['recipe']->id)->toBe((int) $vorhanden->id);
});

it('kein Typ ableitbar: Fehler mit Hinweis auf die Regel statt raten; mit gewähltem Typ klappt es', function () {
    $gp = ($this->gpMit)($this->rootTeam, 'Gulasch Rind: TK, fertig', '13', '13.1 Fertiggerichte');   // Regel aus, WG 13 ohne Zuordnung

    $lw = Livewire::test(ZukaufRezept::class, ['gpId' => $gp->id])
        ->assertSet('typ', '')
        ->call('formUmschalten')
        ->assertSee('bitte wählen')
        ->call('anlegen');
    expect($lw->get('fehler'))->toContain('basisrezept.zukauf.typ')
        ->and(FoodAlchemistRecipe::where('name', 'like', '%(Zukauf)')->count())->toBe(0);

    $lw->set('typ', 'Crunch')->set('bezeichnung', 'Gulasch Rind')->call('anlegen')->assertSet('fehler', null);
    expect(FoodAlchemistRecipe::where('name', 'Crunch: Gulasch Rind (Zukauf)')->exists())->toBeTrue();

    expect(fn () => app(ZukaufBasisrezeptService::class)->ausGp($this->rootTeam, (int) ($this->gpMit)($this->rootTeam, 'Senf: mittelscharf', '13')->id, 'Gibtsnicht'))
        ->toThrow(\RuntimeException::class, 'Unbekannter Basisrezept-Typ');
});

it('Rohware und Non-Food: kein Knopf, Service lehnt ab', function () {
    ($this->regelAn)();
    $roh = ($this->gpMit)($this->rootTeam, 'Kuerbis Hokkaido: frisch, ganz', '01', '01.1 Fruchtgemuese');
    $nonFood = ($this->gpMit)($this->rootTeam, 'Servietten: weiss', '16');

    foreach ([$roh, $nonFood] as $gp) {
        Livewire::test(ZukaufRezept::class, ['gpId' => $gp->id])->assertDontSee('Als Zukauf-Basisrezept anlegen')->assertDontSee('Zukauf-Basisrezept:');
        expect(fn () => app(ZukaufBasisrezeptService::class)->ausGp($this->rootTeam, (int) $gp->id, 'Crunch'))
            ->toThrow(\RuntimeException::class, 'nur für gekaufte Fertigware');
    }
    expect(FoodAlchemistRecipe::where('name', 'like', '%(Zukauf)')->count())->toBe(0);
});

it('fremder GP (anderes Team) wird abgelehnt; Betrachter sieht keinen Knopf und darf nicht anlegen', function () {
    ($this->regelAn)();
    $fremd = ($this->gpMit)($this->childB, 'Roestzwiebeln: trocken, frittiert', '10', '10.4 Gewuerzmischungen');
    Livewire::test(ZukaufRezept::class, ['gpId' => $fremd->id])->assertDontSee('Als Zukauf-Basisrezept anlegen');
    expect(fn () => app(ZukaufBasisrezeptService::class)->ausGp($this->rootTeam, (int) $fremd->id))
        ->toThrow(\RuntimeException::class, 'nicht gefunden');

    $eigen = ($this->gpMit)($this->rootTeam, 'Roestzwiebeln: trocken, frittiert', '10', '10.4 Gewuerzmischungen');
    $this->actingAs($this->makeUser($this->rootTeam, 'Gast', 'viewer'));
    Livewire::test(ZukaufRezept::class, ['gpId' => $eigen->id])->assertDontSee('Als Zukauf-Basisrezept anlegen');
    expect(fn () => app(ZukaufBasisrezeptService::class)->ausGp($this->rootTeam, (int) $eigen->id))
        ->toThrow(\Platform\FoodAlchemist\Exceptions\FaRechtFehltException::class);
    expect(FoodAlchemistRecipe::where('name', 'like', '%(Zukauf)')->count())->toBe(0);
});

it('KI fällt aus: das Rezept entsteht trotzdem, ohne Schritte (Handgriffe im Editor nachpflegen)', function () {
    ($this->regelAn)();
    app()->bind(\Platform\FoodAlchemist\Services\Ai\FakeAiProvider::class, fn () => new class extends \Platform\FoodAlchemist\Services\Ai\FakeAiProvider
    {
        public function chat(array $messages, array $options = []): array
        {
            throw new \RuntimeException('Provider weg');
        }
    });
    $gp = ($this->gpMit)($this->rootTeam, 'Roestzwiebeln: trocken, frittiert', '10', '10.4 Gewuerzmischungen');

    $e = app(ZukaufBasisrezeptService::class)->ausGp($this->rootTeam, (int) $gp->id);
    expect($e['neu'])->toBeTrue()->and($e['recipe']->name)->toBe('Crunch: Roestzwiebeln (Zukauf)')
        ->and($e['recipe']->steps()->count())->toBe(0);
});

it('GP-Dialog und Detail-Panel binden den Knopf ein', function () {
    ($this->regelAn)();
    $gp = ($this->gpMit)($this->rootTeam, 'Roestzwiebeln: trocken, frittiert', '10', '10.4 Gewuerzmischungen');

    Livewire::test(\Platform\FoodAlchemist\Livewire\Gps\DetailPanel::class, ['gpId' => $gp->id])->assertSee('Als Zukauf-Basisrezept anlegen');
    Livewire::test(\Platform\FoodAlchemist\Livewire\Gps\GpModal::class)->call('oeffnen', $gp->id)->assertSee('Als Zukauf-Basisrezept anlegen');
});
