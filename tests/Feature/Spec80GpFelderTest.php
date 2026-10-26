<?php

use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Platform\FoodAlchemist\Livewire\Gps\GpModal;
use Platform\FoodAlchemist\Services\GpNamingService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;
use Symfony\Component\Uid\UuidV7;

uses(TestCase::class, SeedsTeamHierarchy::class);

/** Spec 80 Paket 11 — GP-Felder Verarbeitung/Form auch beim Bearbeiten, Name ⇄ Felder. */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam));
    // Spec 81: §2-Suffixe kommen aus den Regeln (Seed per Migration), kein Dossier-Fixture mehr.
});

it('zerlegt einen Bestandsnamen nach §6 in die Felder (Verarbeitung nur mit §2-Suffix)', function () {
    $n = app(GpNamingService::class);

    expect($n->felderAusName('Schalotten: Wuerfel 5 mm, frisch'))->toMatchArray(['hauptzutat' => 'Schalotten', 'condition' => 'frisch', 'processing' => '', 'form' => 'Wuerfel 5 mm'])
        ->and($n->felderAusName('Olivenoel: trocken, hochwertig'))->toMatchArray(['condition' => 'trocken', 'processing' => '', 'form' => '', 'pflichtangabe' => 'hochwertig'])
        ->and($n->felderAusName('Amerikaner mit Zuckerguss: TK, mini, 25 g pro Stueck'))->toMatchArray(['condition' => 'TK', 'form' => '', 'portion' => '25 g', 'pflichtangabe' => 'mini'])
        ->and($n->felderAusName('Reis Parboiled: trocken / (Bio)')['bio'])->toBeTrue();
});

it('Editor: Felder werden beim Bearbeiten geladen, gespeichert und der Name lässt sich ableiten', function () {
    $gp = $this->makeGp($this->rootTeam, 'Schalotten: Wuerfel 5 mm, frisch');
    $gp->update(['condition' => 'frisch', 'status' => 'approved']);

    $c = Livewire::test(GpModal::class)->call('oeffnen', $gp->id)->call('bearbeitenStarten');
    expect($c->get('builder.processing'))->toBe('');

    $c->call('felderAusName');
    expect($c->get('builder.form'))->toBe('Wuerfel 5 mm')
        ->and($c->get('builder.hauptzutat'))->toBe('Schalotten');

    $c->set('builder.processing', '')->set('builder.form', 'ganz')->call('nameAusFeldern');
    expect($c->get('manuellerName'))->toBe('Schalotten: ganz, frisch');

    $c->call('speichern');
    $gp->refresh();
    expect($gp->name)->toBe('Schalotten: ganz, frisch')->and($gp->form)->toBe('ganz')->and($gp->processing)->toBeNull();
});

it('Befüll-Befehl: nur Bericht ohne --apply, schreibt nur die Verarbeitung', function () {
    $geschaelt = $this->makeGp($this->rootTeam, 'Kartoffeln: frisch, geschält');
    $wuerfel = $this->makeGp($this->rootTeam, 'Schalotten: frisch, Wuerfel 5 mm');   // Stufe 2: Würfel ist Zuschnitt (form), keine Verarbeitung
    $oel = $this->makeGp($this->rootTeam, 'Olivenoel: trocken, hochwertig');

    $this->artisan('foodalchemist:gp-felder-backfill')->assertSuccessful();
    expect($geschaelt->fresh()->processing)->toBeNull();

    $this->artisan('foodalchemist:gp-felder-backfill', ['--apply' => true])->assertSuccessful();
    expect($geschaelt->fresh()->processing)->toBe('geschält')
        ->and($wuerfel->fresh()->processing)->toBeNull()
        ->and($oel->fresh()->processing)->toBeNull()
        ->and($oel->fresh()->form)->toBeNull();
});
