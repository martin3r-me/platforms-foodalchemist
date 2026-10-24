<?php

use Livewire\Livewire;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Tools\ToolRegistry;
use Platform\FoodAlchemist\Livewire\Gps\GpModal;
use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Models\FoodAlchemistLookupWarengruppe;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplier;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem;
use Platform\FoodAlchemist\Services\Ai\AiGatewayService;
use Platform\FoodAlchemist\Services\Ai\AiProposal;
use Platform\FoodAlchemist\Services\GpNamingService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * GP-Regelwerk v3.7.0 §6.6 (Dominique 24.10.2026): Geschmackszutat. Die Grundware bleibt Hauptzutat, die Geschmackszutat steht als ERSTE
 * Eigenschaft nach dem Doppelpunkt, vor dem Zustand — Feld `flavor` am GP. Anlass: Test auf io machte aus „Elbländer Chili Scheiben 45 %“
 * den Namen „Käse: frisch, Scheiben, 45 % Fett i. Tr." (Chili verloren, keine Stelle dafür).
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->user = $this->makeUser($this->rootTeam, 'Root User');
    $this->actingAs($this->user);
    config(['foodalchemist.ai.provider' => 'fake', 'foodalchemist.bearbeitungssperre' => false]);
    $this->naming = app(GpNamingService::class);
});

it('Render: Geschmackszutat steht als ERSTE Eigenschaft vor dem Zustand; ohne Geschmack unverändert', function () {
    $felder = ['hauptzutat' => 'Käse', 'condition' => 'frisch', 'processing' => 'Scheiben', 'pflichtangabe' => '45 % Fett i. Tr.'];

    expect($this->naming->renderGpName($felder + ['flavor' => 'Chili']))->toBe('Käse: Chili, frisch, Scheiben, 45 % Fett i. Tr.')
        ->and($this->naming->renderGpName($felder))->toBe('Käse: frisch, Scheiben, 45 % Fett i. Tr.')
        ->and($this->naming->renderGpName(['hauptzutat' => 'Butter', 'flavor' => 'Kräuter', 'condition' => 'frisch', 'pflichtangabe' => '82 %']))->toBe('Butter: Kräuter, frisch, 82 %')
        ->and($this->naming->renderGpName(['hauptzutat' => 'Olivenöl', 'flavor' => 'Knoblauch', 'pflichtangabe' => 'extra vergine']))->toBe('Olivenöl: Knoblauch, extra vergine')
        ->and($this->naming->renderGpName($felder + ['flavor' => 'unbekannt']))->toBe('Käse: frisch, Scheiben, 45 % Fett i. Tr.');
});

it('Non-Food (WG 16) bleibt unberührt: flavor wird dort nicht gerendert', function () {
    $name = $this->naming->renderGpName(['hauptzutat' => 'Serviette', 'flavor' => 'Chili', 'processing' => 'weiß', 'pflichtangabe' => '33 x 33 cm', 'commodity_group_code' => '16']);

    expect($name)->toBe('Serviette: weiß, 33 x 33 cm');
});

it('gp_key: die Geschmackszutat trennt Varianten der Grundware, ohne Geschmack bleibt der Schlüssel wie bisher', function () {
    expect($this->naming->buildGpKey('kaese', 'scheiben', null, 'Chili'))->toBe('kaese_chili|scheiben|')
        ->and($this->naming->buildGpKey('kaese', 'scheiben', null))->toBe('kaese|scheiben|')
        ->and($this->naming->buildGpKey('kaese', 'scheiben', null, ''))->toBe('kaese|scheiben|');
});

it('Anlage und Bearbeiten speichern flavor; Käse mit Chili und Käse ohne Chili sind verschiedene GPs', function () {
    $mit = $this->naming->createGp($this->rootTeam, ['hauptzutat' => 'Käse', 'flavor' => 'Chili', 'condition' => 'frisch', 'processing' => 'Scheiben', 'pflichtangabe' => '45 % Fett i. Tr.']);
    $ohne = $this->naming->createGp($this->rootTeam, ['hauptzutat' => 'Käse', 'condition' => 'frisch', 'processing' => 'Scheiben', 'pflichtangabe' => '45 % Fett i. Tr.']);

    expect($mit->name)->toBe('Käse: Chili, frisch, Scheiben, 45 % Fett i. Tr.')->and($mit->flavor)->toBe('Chili')
        ->and($mit->gp_key)->toBe('kaese_chili|scheiben|')->and($ohne->flavor)->toBeNull()->and($ohne->gp_key)->toBe('kaese|scheiben|');

    $neu = $this->naming->updateGp($this->rootTeam, $ohne, ['hauptzutat' => 'Käse', 'flavor' => 'Kräuter', 'condition' => 'frisch', 'processing' => 'Scheiben', 'pflichtangabe' => '45 % Fett i. Tr.']);
    expect($neu->name)->toBe('Käse: Kräuter, frisch, Scheiben, 45 % Fett i. Tr.')->and($neu->flavor)->toBe('Kräuter')->and($neu->gp_key)->toBe('kaese|scheiben|');   // Schlüssel bleibt stabil

    $teil = $this->naming->updateGp($this->rootTeam, $neu->refresh(), ['name' => $neu->name, 'condition' => 'frisch']);   // Teil-Schreiber ohne flavor lässt den Wert stehen
    expect($teil->flavor)->toBe('Kräuter');
});

it('Validator: ein Zustandswort ist keine Geschmackszutat', function () {
    $r = $this->naming->validateGpName('Käse: frisch, frisch', ['hauptzutat' => 'Käse', 'flavor' => 'frisch', 'condition' => 'frisch']);

    expect(implode(' ', $r['errors']))->toContain('§6.6');
});

it('felderAusName: nur im Editor-Modus wird das Wort vor dem Zustand zur Geschmackszutat (Rundlauf); Bestand/Backfill unverändert', function () {
    $name = 'Käse: Chili, frisch, Scheiben, 45 % Fett i. Tr.';
    $f = $this->naming->felderAusName($name, true);

    expect($f['flavor'])->toBe('Chili')->and($f['condition'])->toBe('frisch')->and($f['hauptzutat'])->toBe('Käse')
        ->and($this->naming->renderGpName($f))->toBe($name);

    $alt = $this->naming->felderAusName($name);
    expect($alt)->not->toHaveKey('flavor')->and($alt['form'])->toBe('Chili');   // Bestands-Lesart wie bisher

    expect($this->naming->felderAusName('Käse: frisch, Scheiben', true)['flavor'])->toBe('')
        ->and($this->naming->felderAusName('Käse: frisch, Chili, Scheiben', true)['flavor'])->toBe('');   // nach dem Zustand ist es keine Geschmackszutat
});

it('Modal: Vorschau, Speichern und Laden mit dem Feld Geschmack', function () {
    Livewire::test(GpModal::class)->call('oeffnen')
        ->set('builder.hauptzutat', 'Käse')->set('builder.flavor', 'Chili')->set('builder.condition', 'frisch')->set('builder.processing', 'Scheiben')
        ->assertSeeHtml('Käse: Chili, frisch, Scheiben')->assertSeeHtml('data-gp-flavor')
        ->call('speichern')->assertSet('fehler', null);

    $gp = FoodAlchemistGp::where('name', 'Käse: Chili, frisch, Scheiben')->firstOrFail();
    expect($gp->flavor)->toBe('Chili');

    Livewire::test(GpModal::class)->call('oeffnen', $gp->id)->assertSet('builder.flavor', 'Chili')->assertSeeHtml('data-gp-flavor-edit')
        ->set('builder.flavor', 'Kräuter')->call('nameAusFeldern')->call('speichern')->assertSet('fehler', null);
    expect($gp->fresh()->flavor)->toBe('Kräuter')->and($gp->fresh()->name)->toBe('Käse: Kräuter, frisch, Scheiben');
});

it('gp.suggest: der Vorschlag übernimmt flavor in den Builder (Felder vorschlagen) und der Prompt kennt Feld und neue §5-Logik', function () {
    $this->mock(AiGatewayService::class, function ($mock) {
        $mock->shouldReceive('propose')->andReturnUsing(fn ($key, $kontext = []) => new AiProposal(
            ['hauptzutat' => 'Käse', 'flavor' => 'Chili', 'condition' => 'frisch', 'processing' => 'Scheiben', 'pflichtangabe' => '45 % Fett i. Tr.'], 0.8, 'Mock', [], $key));
    });
    $lief = FoodAlchemistSupplier::create(['team_id' => $this->rootTeam->id, 'name' => 'Elbländer']);
    $la = FoodAlchemistSupplierItem::create(['team_id' => $this->rootTeam->id, 'supplier_id' => $lief->id, 'article_number' => '1', 'designation' => 'Elbländer Chili Scheiben 45 %',
        'qty' => 1, 'unit_code' => 'kg']);

    Livewire::test(GpModal::class)->call('oeffnen', null, $la->id)->set('kiRohtext', 'Elbländer Chili Scheiben 45 %')->call('kiVorschlagNaming')
        ->assertSet('builder.flavor', 'Chili')->assertSet('builder.hauptzutat', 'Käse');

    $task = config('foodalchemist.prompts')['gp.suggest']['task'];
    expect($task)->toContain('flavor')->toContain('§6.6')->toContain('nur als Ausnahme')->toContain('§8.19')->not->toContain('Tiebreaker');

    // Abgrenzung Geschmack ↔ Grundware (Dominique 24.10.): nur eine kleine würzende Beigabe ist flavor; was die Ware ausmacht, bleibt hauptzutat (Pürees).
    // Nur Prompt-Text — kein Live-Call, das Ergebnis hängt vom Modell ab.
    expect($task)->toContain('kleine, würzende Beigabe')->toContain('Passionsfruchtpüree')->toContain('nie „Püree: Passionsfrucht“');
});

it('MCP gps.POST und gps.PUT: Parameter flavor wird gerendert und gespeichert, im Schema beschrieben', function () {
    $reg = app(ToolRegistry::class);
    $ctx = new ToolContext($this->user, $this->rootTeam);
    foreach (['foodalchemist.gps.POST', 'foodalchemist.gps.PUT'] as $name) {
        expect($reg->get($name)->getSchema()['properties'])->toHaveKey('flavor');
    }

    $r = $reg->get('foodalchemist.gps.POST')->execute(['hauptzutat' => 'Butter', 'flavor' => 'Kräuter', 'condition' => 'frisch', 'pflichtangabe' => '82 %'], $ctx);
    expect($r->success)->toBeTrue();
    $gp = FoodAlchemistGp::findOrFail($r->data['id']);
    expect($gp->name)->toBe('Butter: Kräuter, frisch, 82 %')->and($gp->flavor)->toBe('Kräuter');

    $p = $reg->get('foodalchemist.gps.PUT')->execute(['id' => $gp->id, 'hauptzutat' => 'Butter', 'flavor' => 'Knoblauch', 'condition' => 'frisch', 'pflichtangabe' => '82 %'], $ctx);
    expect($p->success)->toBeTrue()->and($gp->fresh()->name)->toBe('Butter: Knoblauch, frisch, 82 %')->and($gp->fresh()->flavor)->toBe('Knoblauch');
});
