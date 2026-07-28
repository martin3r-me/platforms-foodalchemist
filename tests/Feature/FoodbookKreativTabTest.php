<?php

use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Platform\FoodAlchemist\Livewire\Foodbooks\Index as FoodbooksIndex;
use Platform\FoodAlchemist\Models\FoodAlchemistFoodbook;
use Platform\FoodAlchemist\Models\FoodAlchemistSignal;
use Platform\FoodAlchemist\Services\FoodbookService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;
use Symfony\Component\Uid\UuidV7;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 19 E9.4 — Kreativ-Tab-UI: 3-Modus-Umschalter (pro Kapitel) + Pairing-Inspiration
 * (Pull-not-Push) + Lücke-Meldung. Voll-Page-Render.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam));
    $this->foodbooks = app(FoodbookService::class);

    // Pairing-Seed: zander → rote_bete (klassisch), zander → meerrettich (aroma, kein GP → Lücke)
    $mkAnker = function (string $slug) {
        DB::table('foodalchemist_vocab_pairing_anchors')->insert([
            'uuid' => (string) UuidV7::generate(), 'slug' => $slug, 'display_de' => ucfirst(str_replace('_', ' ', $slug)),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return (int) DB::getPdo()->lastInsertId();
    };
    $z = $mkAnker('zander');
    $rb = $mkAnker('rote_bete');
    $mr = $mkAnker('meerrettich');
    foreach ([[$z, $rb], [$rb, $z], [$z, $mr], [$mr, $z]] as [$x, $y]) {
        DB::table('foodalchemist_pairing_anchor_edges')->insert([
            'uuid' => (string) UuidV7::generate(), 'anchor_a_id' => $x, 'anchor_b_id' => $y,
            'type' => 'klassisch', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
    $gp = $this->makeGp($this->rootTeam, 'Rote Bete');
    $gp->update(['is_derivat' => false, 'is_platzhalter' => false, 'is_favorite' => true]);
    DB::table('foodalchemist_gp_anchor_mappings')->insert([
        'uuid' => (string) UuidV7::generate(), 'team_id' => $this->rootTeam->id,
        'gp_id' => $gp->id, 'anchor_id' => $rb, 'role' => 'kern', 'created_at' => now(), 'updated_at' => now(),
    ]);

    $this->fb = $this->foodbooks->create($this->rootTeam, ['label' => 'Kreativ-FB']);
    $this->kap = $this->foodbooks->addKapitel($this->rootTeam, $this->fb->id, ['title' => 'Vorspeisen']);
});

it('E9.4: Modus-Umschalter setzt creative_mode pro Kapitel', function () {
    Livewire::test(FoodbooksIndex::class)
        ->call('waehle', $this->fb->id)
        ->call('kapitelWaehle', $this->kap->id)
        ->call('kreativModusSetzen', 'voll_kreativ');

    expect($this->kap->refresh()->creative_mode)->toBe('voll_kreativ');

    // ungültiger Modus wird ignoriert (Vokabular-Pflicht)
    Livewire::test(FoodbooksIndex::class)
        ->call('waehle', $this->fb->id)->call('kapitelWaehle', $this->kap->id)
        ->call('kreativModusSetzen', 'quatsch');
    expect($this->kap->refresh()->creative_mode)->toBe('voll_kreativ'); // unverändert
});

it('E9.4: kreativModus wird als View-Daten geführt (default hybrid, geerdet)', function () {
    $comp = Livewire::test(FoodbooksIndex::class)
        ->call('waehle', $this->fb->id)
        ->call('kapitelWaehle', $this->kap->id);

    $modus = $comp->viewData('kreativModus');
    expect($modus['modus'])->toBe('hybrid')->and($modus['optionen'])->toContain('voll_kreativ', 'datenbank');
});

it('E9.4: Pairing-Inspiration ist Pull — erst mit Seed liefert die View Nachbarn', function () {
    $comp = Livewire::test(FoodbooksIndex::class)
        ->call('waehle', $this->fb->id)
        ->call('kapitelWaehle', $this->kap->id);

    // ohne Seed: keine Inspiration (Pull-not-Push)
    expect($comp->viewData('kreativInspiration'))->toBeNull();

    // Seed setzen → Nachbarn in den View-Daten (hybrid = geerdet)
    $comp->set('kreativSeed', 'zander');
    $insp = $comp->viewData('kreativInspiration');
    expect($insp)->not->toBeNull()
        ->and($insp['geerdet'])->toBeTrue()
        ->and(collect($insp['inspiration'][0]['nachbarn'])->pluck('slug')->all())
        ->toContain('rote_bete', 'meerrettich');
});

it('E9.4: voll_kreativ liefert abstrakte Inspiration (kein GP in den View-Daten)', function () {
    Livewire::test(FoodbooksIndex::class)
        ->call('waehle', $this->fb->id)->call('kapitelWaehle', $this->kap->id)
        ->call('kreativModusSetzen', 'voll_kreativ');

    $comp = Livewire::test(FoodbooksIndex::class)
        ->call('waehle', $this->fb->id)->call('kapitelWaehle', $this->kap->id)
        ->set('kreativSeed', 'zander');
    $insp = $comp->viewData('kreativInspiration');
    expect($insp['geerdet'])->toBeFalse()
        ->and($insp['inspiration'][0]['nachbarn'][0])->not->toHaveKey('gps');
});

it('E9.4: luckeMelden legt Signal an + setzt Hinweis', function () {
    Livewire::test(FoodbooksIndex::class)
        ->call('waehle', $this->fb->id)
        ->call('kapitelWaehle', $this->kap->id)
        ->call('luckeMelden', 'meerrettich')
        ->assertSet('kreativHinweis', fn ($v) => str_contains((string) $v, 'meerrettich'));

    expect(FoodAlchemistSignal::where('team_id', $this->rootTeam->id)
        ->where('type', 'sortiments_luecke')->count())->toBe(1);
});

/**
 * Der Erreichbarkeits-Riegel (2026-07-28).
 *
 * Bis hier prüfte diese Datei ausschließlich **Verhalten**: `->call('kreativModusSetzen', …)`
 * und dann der DB-Wert. Livewire-Methoden lassen sich direkt aufrufen — unabhängig davon, ob
 * irgendein Knopf dafür rendert. Genau deshalb blieb der Test grün, während das Feature für
 * jeden Menschen unerreichbar war: das Cockpit stand hinter `@if($selectedKapitelId === null)`,
 * der Modus-Schalter hinter `@if($kapitel)` — zwei Bedingungen, die sich ausschließen.
 *
 * Dieselbe Lücke hatte am selben Tag der „Prüfen"-Knopf der Signale-Seite (unerreichbarer
 * Core-Slot). Zweimal dasselbe Muster ist kein Zufall, sondern eine fehlende Testklasse:
 * **wir prüfen, ob Features funktionieren, nicht ob man sie anklicken kann.**
 *
 * Darum assertiert das hier auf das GERENDERTE Markup und nicht auf Zustand.
 */
it('E9.4: der Modus-Schalter ist mit gewähltem Kapitel wirklich SICHTBAR (nicht nur aufrufbar)', function () {
    Livewire::test(FoodbooksIndex::class)
        ->call('waehle', $this->fb->id)
        ->call('kapitelWaehle', $this->kap->id)
        // Die drei Modus-Knöpfe des Kreativ-Tabs …
        ->assertSee('Voll kreativ')
        ->assertSee('Hybrid')
        ->assertSee('Datenbank')
        // … und ihr Träger-Markup (fängt auch ein Umbenennen der Labels).
        ->assertSeeHtml('data-fb-kreativ-modus')
        ->assertSeeHtml('data-fb-modus="voll_kreativ"')
        ->assertSeeHtml('data-fb-modus="hybrid"')
        ->assertSeeHtml('data-fb-modus="datenbank"');
});

it('E9.4: die Pairing-Inspiration ist mit gewähltem Kapitel sichtbar', function () {
    // Lag im selben toten Zweig — Seed-Feld und „Inspirieren" waren nie erreichbar.
    Livewire::test(FoodbooksIndex::class)
        ->call('waehle', $this->fb->id)
        ->call('kapitelWaehle', $this->kap->id)
        ->assertSeeHtml('data-fb-pairing-inspiration')
        ->assertSeeHtml('data-fb-seed')
        ->assertSeeHtml('data-fb-inspirieren');
});

it('das Cockpit bleibt mit gewähltem Kapitel stehen — Tab-Leiste UND Kapitel-Editor', function () {
    // Der eigentliche Fix: Cockpit XOR Kapitel war die Ursache. Beides muss koexistieren,
    // sonst ist der Hinweis „Wähle links ein Kapitel" eine Anweisung ins Leere.
    $comp = Livewire::test(FoodbooksIndex::class)
        ->call('waehle', $this->fb->id)
        ->call('kapitelWaehle', $this->kap->id);

    // Tab-Leiste (Cockpit) …
    $comp->assertSeeHtml('data-fb-tab="kreativ"')
        ->assertSeeHtml('data-fb-panel="kreativ"')
        // … und gleichzeitig der Kapitel-Editor.
        ->assertSee('Konsumententitel');
});
