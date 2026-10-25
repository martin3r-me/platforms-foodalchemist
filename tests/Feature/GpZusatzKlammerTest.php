<?php

use Livewire\Livewire;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Tools\ToolRegistry;
use Platform\FoodAlchemist\Livewire\Gps\GpModal;
use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplier;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem;
use Platform\FoodAlchemist\Services\Ai\AiGatewayService;
use Platform\FoodAlchemist\Services\Ai\AiProposal;
use Platform\FoodAlchemist\Services\GpNamingService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * GP-Regelwerk v3.7.0 §6.6 (Dominique 25.10.2026): Fertig angemachte Salate / Komposit-Convenience mit Fantasienamen. Der Fantasiename steht als
 * Klammer-Zusatz hinten (`Reissalat: schwarzer Reis, Avocado, Azukibohnen, TK / (Mambonito)`) und kommt EXAKT aus Bezeichnung oder
 * Vermarktungsname des Artikels — die KI erfindet keine Namen. Feld `zusatz`.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->user = $this->makeUser($this->rootTeam, 'Root User');
    $this->actingAs($this->user);
    config(['foodalchemist.ai.provider' => 'fake', 'foodalchemist.bearbeitungssperre' => false]);
    $this->naming = app(GpNamingService::class);
    $this->salat = ['hauptzutat' => 'Reissalat', 'flavor' => 'schwarzer Reis, Avocado, Azukibohnen', 'condition' => 'TK'];
});

it('Render: Klammer-Zusatz steht hinten, VOR den festen Flags (Bio/Vegan …); ohne Zusatz unverändert', function () {
    expect($this->naming->renderGpName($this->salat + ['zusatz' => 'Mambonito']))->toBe('Reissalat: schwarzer Reis, Avocado, Azukibohnen, TK / (Mambonito)')
        ->and($this->naming->renderGpName($this->salat + ['zusatz' => 'Mambonito', 'bio' => true]))->toBe('Reissalat: schwarzer Reis, Avocado, Azukibohnen, TK / (Mambonito) / (Bio)')
        ->and($this->naming->renderGpName($this->salat + ['bio' => true, 'vegan' => true]))->toBe('Reissalat: schwarzer Reis, Avocado, Azukibohnen, TK / (Bio) / (Vegan)')
        ->and($this->naming->renderGpName($this->salat))->toBe('Reissalat: schwarzer Reis, Avocado, Azukibohnen, TK')
        ->and($this->naming->renderGpName($this->salat + ['zusatz' => 'Mamb(on)ito']))->toBe('Reissalat: schwarzer Reis, Avocado, Azukibohnen, TK / (Mamb on ito)');   // Klammern im Wert brechen den Namen nicht
});

it('Non-Food (WG 16) bleibt unberührt: zusatz wird dort nicht gerendert', function () {
    expect($this->naming->renderGpName(['hauptzutat' => 'Serviette', 'zusatz' => 'Mambonito', 'processing' => 'weiß', 'pflichtangabe' => '33 x 33 cm', 'commodity_group_code' => '16']))->toBe('Serviette: weiß, 33 x 33 cm');
});

it('Anlage und Bearbeiten speichern zusatz; Teil-Schreiber ohne zusatz lässt den Wert stehen', function () {
    $gp = $this->naming->createGp($this->rootTeam, $this->salat + ['zusatz' => 'Mambonito']);

    expect($gp->name)->toBe('Reissalat: schwarzer Reis, Avocado, Azukibohnen, TK / (Mambonito)')->and($gp->zusatz)->toBe('Mambonito')->and($gp->flavor)->toBe('schwarzer Reis, Avocado, Azukibohnen');

    $neu = $this->naming->updateGp($this->rootTeam, $gp, $this->salat + ['zusatz' => 'Mambolino']);
    expect($neu->name)->toBe('Reissalat: schwarzer Reis, Avocado, Azukibohnen, TK / (Mambolino)')->and($neu->zusatz)->toBe('Mambolino');

    $teil = $this->naming->updateGp($this->rootTeam, $neu->refresh(), ['name' => $neu->name, 'condition' => 'TK']);
    expect($teil->zusatz)->toBe('Mambolino');
});

it('felderAusName (Editor): ein Klammerteil, der kein Flag ist, wird zum Zusatz; Flags bleiben Flags; Rundlauf; Bestands-Lesart unverändert', function () {
    $name = 'Reissalat: schwarzer Reis, Avocado, Azukibohnen, TK / (Mambonito) / (Bio)';
    $f = $this->naming->felderAusName($name, true);

    expect($f['zusatz'])->toBe('Mambonito')->and($f['bio'])->toBeTrue()->and($f['flavor'])->toBe('schwarzer Reis, Avocado, Azukibohnen')->and($f['condition'])->toBe('TK')->and($f['hauptzutat'])->toBe('Reissalat')
        ->and($this->naming->renderGpName($f))->toBe($name);   // Rundlauf
    expect($this->naming->felderAusName('Mehl: trocken / (Bio)', true)['zusatz'])->toBe('');
    expect($this->naming->felderAusName($name))->not->toHaveKey('zusatz');   // Bestand/Backfill lesen wie bisher
});

it('zusatzAusArtikel: nur was exakt (case-insensitiv) im Artikel steht, in der Schreibweise des Artikels; Erfundenes wird verworfen', function () {
    $texte = ['Darta Cuisin\'easy Reissalat MAMBONITO 1 kg', 'Mambonito'];

    expect($this->naming->zusatzAusArtikel('mambonito', $texte))->toBe('MAMBONITO')
        ->and($this->naming->zusatzAusArtikel('Mambolino', $texte))->toBe('')
        ->and($this->naming->zusatzAusArtikel('Mambonito Deluxe', $texte))->toBe('')
        ->and($this->naming->zusatzAusArtikel('Mambonito', [null, '  ']))->toBe('')
        ->and($this->naming->zusatzAusArtikel('', $texte))->toBe('')
        ->and($this->naming->zusatzAusArtikel('unbekannt', ['unbekannt']))->toBe('');
});

it('Modal: Vorschau, Speichern und Laden mit dem Feld Zusatz', function () {
    Livewire::test(GpModal::class)->call('oeffnen')
        ->set('builder.hauptzutat', 'Reissalat')->set('builder.flavor', 'schwarzer Reis, Avocado, Azukibohnen')->set('builder.condition', 'TK')->set('builder.zusatz', 'Mambonito')
        ->assertSeeHtml('Reissalat: schwarzer Reis, Avocado, Azukibohnen, TK / (Mambonito)')->assertSeeHtml('data-gp-zusatz')
        ->call('speichern')->assertSet('fehler', null);

    $gp = FoodAlchemistGp::where('zusatz', 'Mambonito')->firstOrFail();
    expect($gp->name)->toBe('Reissalat: schwarzer Reis, Avocado, Azukibohnen, TK / (Mambonito)');

    Livewire::test(GpModal::class)->call('oeffnen', $gp->id)->assertSet('builder.zusatz', 'Mambonito')->assertSeeHtml('data-gp-zusatz-edit')
        ->set('builder.zusatz', 'Mambolino')->call('nameAusFeldern')->call('speichern')->assertSet('fehler', null);
    expect($gp->fresh()->zusatz)->toBe('Mambolino')->and($gp->fresh()->name)->toContain('/ (Mambolino)');
});

it('gp.suggest: der Zusatz wird nur aus dem Quell-Artikel übernommen — erfundene Namen werden verworfen; Prompt enthält die Regel', function () {
    $lief = FoodAlchemistSupplier::create(['team_id' => $this->rootTeam->id, 'name' => 'Darta']);
    $la = FoodAlchemistSupplierItem::create(['team_id' => $this->rootTeam->id, 'supplier_id' => $lief->id, 'article_number' => '1', 'designation' => 'Cuisin\'easy Mambonito',
        'qty' => 1, 'unit_code' => 'kg']);
    $werte = fn (string $zusatz) => ['hauptzutat' => 'Reissalat', 'flavor' => 'schwarzer Reis, Avocado', 'condition' => 'TK', 'zusatz' => $zusatz];

    $this->mock(AiGatewayService::class, function ($mock) use ($werte) {
        $mock->shouldReceive('propose')->andReturnUsing(fn ($key, $kontext = []) => new AiProposal($werte('mambonito'), 0.8, 'Mock', [], $key));
    });
    Livewire::test(GpModal::class)->call('oeffnen', null, $la->id)->set('kiRohtext', 'Cuisin\'easy Mambonito')->call('kiVorschlagNaming')
        ->assertSet('builder.zusatz', 'Mambonito')->assertSet('builder.flavor', 'schwarzer Reis, Avocado');

    $this->mock(AiGatewayService::class, function ($mock) use ($werte) {
        $mock->shouldReceive('propose')->andReturnUsing(fn ($key, $kontext = []) => new AiProposal($werte('Mambo Rico'), 0.8, 'Mock', [], $key));
    });
    Livewire::test(GpModal::class)->call('oeffnen', null, $la->id)->set('kiRohtext', 'Cuisin\'easy Mambonito')->call('kiVorschlagNaming')
        ->assertSet('builder.zusatz', '');                                   // erfundener Name → verworfen

    $task = config('foodalchemist.prompts')['gp.suggest']['task'];
    expect($task)->toContain('zusatz')->toContain('exakt in Bezeichnung oder Vermarktungsname')->toContain('nie bilden, übersetzen oder ausschmücken');
});

it('MCP gps.POST und gps.PUT: Parameter zusatz wird gerendert und gespeichert, im Schema beschrieben', function () {
    $reg = app(ToolRegistry::class);
    $ctx = new ToolContext($this->user, $this->rootTeam);
    foreach (['foodalchemist.gps.POST', 'foodalchemist.gps.PUT'] as $name) {
        expect($reg->get($name)->getSchema()['properties'])->toHaveKey('zusatz');
    }

    $r = $reg->get('foodalchemist.gps.POST')->execute($this->salat + ['zusatz' => 'Mambonito'], $ctx);
    expect($r->success)->toBeTrue();
    $gp = FoodAlchemistGp::findOrFail($r->data['id']);
    expect($gp->name)->toBe('Reissalat: schwarzer Reis, Avocado, Azukibohnen, TK / (Mambonito)')->and($gp->zusatz)->toBe('Mambonito');

    $p = $reg->get('foodalchemist.gps.PUT')->execute(['id' => $gp->id, 'zusatz' => 'Mambolino'] + $this->salat, $ctx);
    expect($p->success)->toBeTrue()->and($gp->fresh()->name)->toContain('/ (Mambolino)')->and($gp->fresh()->zusatz)->toBe('Mambolino');
});
