<?php

use Livewire\Livewire;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Tools\ToolRegistry;
use Platform\FoodAlchemist\Livewire\Gps\GpModal;
use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Services\GpNamingService;
use Platform\FoodAlchemist\Services\GpPaket\GpPaketValidator;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Diät-Namensmarker als Felder (Entscheid Dominique 25.10.2026): `namens_vegan`, `namens_glutenfrei`, `namens_laktosefrei` (+ `bio`) tragen die Marker
 * „/ (Vegan)“ usw. im Namen — getrennt von den Artikel-Tags `tag_is_*`. Feste Render-Reihenfolge Bio, Vegan, Glutenfrei, Laktosefrei.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->user = $this->makeUser($this->rootTeam, 'Root User');
    $this->actingAs($this->user);
    config(['foodalchemist.ai.provider' => 'fake', 'foodalchemist.bearbeitungssperre' => false]);
    $this->naming = app(GpNamingService::class);
    $this->margarine = ['hauptzutat' => 'Margarine', 'condition' => 'frisch'];
});

it('Anlage speichert die Marker; der Name zeigt sie in fester Reihenfolge, unabhängig von der Eingabereihenfolge; Artikel-Tags bleiben unberührt', function () {
    $gp = $this->naming->createGp($this->rootTeam, ['laktosefrei' => true, 'vegan' => true, 'bio' => true, 'glutenfrei' => true] + $this->margarine);

    expect($gp->name)->toBe('Margarine: frisch / (Bio) / (Vegan) / (Glutenfrei) / (Laktosefrei)')
        ->and($gp->bio)->toBe('bio')->and($gp->namens_vegan)->toBeTrue()->and($gp->namens_glutenfrei)->toBeTrue()->and($gp->namens_laktosefrei)->toBeTrue()
        ->and($gp->tag_is_vegan)->toBeNull()->and($gp->tag_is_gluten_free)->toBeNull();   // getrennt von den Artikel-Tags

    $ohne = $this->naming->createGp($this->rootTeam, ['hauptzutat' => 'Butter', 'condition' => 'frisch']);
    expect($ohne->namens_vegan)->toBeFalse()->and($ohne->bio)->toBeNull()->and($ohne->name)->toBe('Butter: frisch');
});

it('Bearbeiten setzt und löscht Marker; ein Teil-Schreiber ohne die Schlüssel lässt sie stehen', function () {
    $gp = $this->naming->createGp($this->rootTeam, $this->margarine + ['vegan' => true, 'bio' => true]);

    $teil = $this->naming->updateGp($this->rootTeam, $gp, ['name' => $gp->name, 'condition' => 'frisch']);
    expect($teil->namens_vegan)->toBeTrue()->and($teil->bio)->toBe('bio');

    $neu = $this->naming->updateGp($this->rootTeam, $teil->refresh(), $this->margarine + ['vegan' => false, 'glutenfrei' => true]);
    expect($neu->namens_vegan)->toBeFalse()->and($neu->namens_glutenfrei)->toBeTrue()->and($neu->name)->toBe('Margarine: frisch / (Glutenfrei)')->and($neu->bio)->toBe('bio');
});

it('felderAusName liest die Marker aus dem Namen; render → parse → render ist stabil', function () {
    $f = $this->naming->felderAusName('Margarine: frisch / (Bio) / (Vegan) / (Laktosefrei)');

    expect($f['bio'])->toBeTrue()->and($f['vegan'])->toBeTrue()->and($f['glutenfrei'])->toBeFalse()->and($f['laktosefrei'])->toBeTrue();
    expect($this->naming->renderGpName($f + ['hauptzutat' => 'Margarine']))->toBe('Margarine: frisch / (Bio) / (Vegan) / (Laktosefrei)');
});

it('Modal: Marker werden gespeichert und beim Bearbeiten geladen', function () {
    Livewire::test(GpModal::class)->call('oeffnen')
        ->set('builder.hauptzutat', 'Margarine')->set('builder.condition', 'frisch')->set('builder.vegan', true)->set('builder.laktosefrei', true)
        ->assertSeeHtml('Margarine: frisch / (Vegan) / (Laktosefrei)')->call('speichern')->assertSet('fehler', null);

    $gp = FoodAlchemistGp::where('namens_vegan', true)->firstOrFail();
    expect($gp->namens_laktosefrei)->toBeTrue()->and($gp->namens_glutenfrei)->toBeFalse();

    Livewire::test(GpModal::class)->call('oeffnen', $gp->id)->assertSet('builder.vegan', true)->assertSet('builder.laktosefrei', true)->assertSet('builder.glutenfrei', false)
        ->set('builder.glutenfrei', true)->call('nameAusFeldern')->call('speichern')->assertSet('fehler', null);
    expect($gp->fresh()->namens_glutenfrei)->toBeTrue()->and($gp->fresh()->name)->toBe('Margarine: frisch / (Vegan) / (Glutenfrei) / (Laktosefrei)');
});

it('MCP gps.POST/PUT: bio/vegan/glutenfrei/laktosefrei im Schema, gespeichert, Teil-Update lässt stehen', function () {
    $reg = app(ToolRegistry::class);
    $ctx = new ToolContext($this->user, $this->rootTeam);
    foreach (['foodalchemist.gps.POST', 'foodalchemist.gps.PUT'] as $name) {
        expect($reg->get($name)->getSchema()['properties'])->toHaveKeys(['bio', 'vegan', 'glutenfrei', 'laktosefrei']);
    }

    $r = $reg->get('foodalchemist.gps.POST')->execute($this->margarine + ['vegan' => true], $ctx);
    expect($r->success)->toBeTrue();
    $gp = FoodAlchemistGp::findOrFail($r->data['id']);
    expect($gp->namens_vegan)->toBeTrue()->and($gp->name)->toBe('Margarine: frisch / (Vegan)');

    $p = $reg->get('foodalchemist.gps.PUT')->execute(['id' => $gp->id, 'glutenfrei' => true, 'vegan' => true] + $this->margarine, $ctx);
    expect($p->success)->toBeTrue()->and($gp->fresh()->namens_glutenfrei)->toBeTrue()->and($gp->fresh()->namens_vegan)->toBeTrue();
});

it('Paket: Whitelist, Schreibfelder, Bool-Cast und Hash führen die Namensmarker', function () {
    foreach (['namens_vegan', 'namens_glutenfrei', 'namens_laktosefrei'] as $c) {
        expect(GpPaketValidator::GP_SPALTEN)->toContain($c)->and(GpPaketValidator::WRITE_SPALTEN)->toContain($c);
    }
    $v = app(GpPaketValidator::class);
    $ctx = ['gpSpalten' => array_fill_keys(['name', 'namens_vegan', 'namens_glutenfrei'], 1)];
    $w = $v->werte(['name' => 'X', 'namens_vegan' => true, 'namens_glutenfrei' => 0], '01', $ctx)['wert'];

    expect($w['namens_vegan'])->toBe(1)->and($w['namens_glutenfrei'])->toBe(0);
    expect($v->hashZeile(['name' => 'X', 'namens_vegan' => 1], $ctx))->not->toBe($v->hashZeile(['name' => 'X', 'namens_vegan' => 0], $ctx));
});
