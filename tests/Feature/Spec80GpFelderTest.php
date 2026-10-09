<?php

use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Platform\FoodAlchemist\Livewire\Gps\GpModal;
use Platform\FoodAlchemist\Services\GpNamingService;
use Platform\FoodAlchemist\Support\RegelwerkLeser;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;
use Symfony\Component\Uid\UuidV7;

uses(TestCase::class, SeedsTeamHierarchy::class);

/** Spec 80 Paket 11 — GP-Felder Verarbeitung/Form auch beim Bearbeiten, Name ⇄ Felder. */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam));
    $md = "- Verarbeitungs-Suffixe: `brunoise`, `würfel/wuerfel`, `gehackt`, `geschnitten`.\n";
    DB::table('foodalchemist_knowledge_documents')->insert([
        'uuid' => (string) UuidV7::generate(), 'slug' => 'regelwerk-basisrezepte-2-verarbeitungs-reduktion-brunoise-roh-form',
        'title' => '§2', 'category' => 'regelwerk', 'content_md' => $md, 'version' => 1, 'content_hash' => hash('sha256', $md),
        'char_count' => strlen($md), 'active' => 1, 'created_at' => now(), 'updated_at' => now(),
    ]);
    RegelwerkLeser::vergessen();
});

it('zerlegt einen Bestandsnamen nach §6 in die Felder (Verarbeitung nur mit §2-Suffix)', function () {
    $n = app(GpNamingService::class);

    expect($n->felderAusName('Schalotten: frisch, Wuerfel 5 mm'))->toMatchArray(['hauptzutat' => 'Schalotten', 'condition' => 'frisch', 'processing' => 'Wuerfel 5 mm', 'form' => ''])
        ->and($n->felderAusName('Olivenoel: trocken, hochwertig'))->toMatchArray(['condition' => 'trocken', 'processing' => '', 'form' => 'hochwertig'])
        ->and($n->felderAusName('Amerikaner mit Zuckerguss: TK, mini, 25 g pro Stueck'))->toMatchArray(['condition' => 'TK', 'form' => 'mini', 'pflichtangabe' => '25 g pro Stueck'])
        ->and($n->felderAusName('Reis Parboiled: trocken / (Bio)')['bio'])->toBeTrue();
});

it('Editor: Felder werden beim Bearbeiten geladen, gespeichert und der Name lässt sich ableiten', function () {
    $gp = $this->makeGp($this->rootTeam, 'Schalotten: frisch, Wuerfel 5 mm');
    $gp->update(['condition' => 'frisch', 'status' => 'approved']);

    $c = Livewire::test(GpModal::class)->call('oeffnen', $gp->id)->call('bearbeitenStarten');
    expect($c->get('builder.processing'))->toBe('');

    $c->call('felderAusName');
    expect($c->get('builder.processing'))->toBe('Wuerfel 5 mm')
        ->and($c->get('builder.hauptzutat'))->toBe('Schalotten');

    $c->set('builder.processing', '')->set('builder.form', 'ganz')->call('nameAusFeldern');
    expect($c->get('manuellerName'))->toBe('Schalotten: frisch, ganz');

    $c->call('speichern');
    $gp->refresh();
    expect($gp->name)->toBe('Schalotten: frisch, ganz')->and($gp->form)->toBe('ganz')->and($gp->processing)->toBeNull();
});

it('Befüll-Befehl: nur Bericht ohne --apply, schreibt nur die Verarbeitung', function () {
    $wuerfel = $this->makeGp($this->rootTeam, 'Schalotten: frisch, Wuerfel 5 mm');
    $oel = $this->makeGp($this->rootTeam, 'Olivenoel: trocken, hochwertig');

    $this->artisan('foodalchemist:gp-felder-backfill')->assertSuccessful();
    expect($wuerfel->fresh()->processing)->toBeNull();

    $this->artisan('foodalchemist:gp-felder-backfill', ['--apply' => true])->assertSuccessful();
    expect($wuerfel->fresh()->processing)->toBe('Wuerfel 5 mm')
        ->and($oel->fresh()->processing)->toBeNull()
        ->and($oel->fresh()->form)->toBeNull();
});
