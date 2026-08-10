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

/**
 * Inspire-Umbau: Kanten tragen jetzt eine Stern-Stufe (`level` 3=★★★ / 2=★★ / 1=★).
 * `type='kontrast'` bleibt die eigene Achse (level=NULL). mkKante seedt bidirektional.
 */
function mkKante(int $a, int $b, string $typ, ?int $level = null, ?float $weight = null): void
{
    foreach ([[$a, $b], [$b, $a]] as [$x, $y]) {
        DB::table('foodalchemist_pairing_anchor_edges')->insert([
            'uuid' => (string) UuidV7::generate(), 'anchor_a_id' => $x, 'anchor_b_id' => $y,
            'type' => $typ, 'level' => $level, 'weight' => $weight,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}

/**
 * M5-07 / Inspire-Umbau 2a: Pairing-Netz — Empfehler-Datenbasis (Kern-Anker innen,
 * Kandidaten in Stern-Sektoren ★★★/★★/★, komplementäre Basisrezepte) + Modal-Smoke
 * + Inhalts-Signatur (`meta.sig`), die die wire:ignore-D3-Insel neu keyt.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->svc = app(PairingService::class);

    $this->kichererbse = mkAnker('kichererbse');
    $this->tahin = mkAnker('tahin');
    $this->knoblauch = mkAnker('knoblauch');     // ★★★-Partner beider Kern-Anker (cover 2)
    $this->granatapfel = mkAnker('granatapfel'); // ★★-Partner von kichererbse
    $this->minze = mkAnker('minze');             // ★★-Partner von tahin

    // Kern-Anker ↔ Kandidaten mit Stern-Stufe. knoblauch passt zu BEIDEN (cover=2).
    mkKante($this->kichererbse, $this->knoblauch, 'aroma', 3, 0.9);
    mkKante($this->tahin, $this->knoblauch, 'aroma', 3, 0.9);
    mkKante($this->kichererbse, $this->granatapfel, 'aroma', 2, 0.6);
    mkKante($this->tahin, $this->minze, 'aroma', 2, 0.6);

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

it('pairingNetz: Zentrum + Kern-Anker innen, Kandidaten nach Stern-Stufe, dish_cover', function () {
    $netz = $this->svc->pairingNetz($this->rootTeam, $this->rezept->id);

    expect(collect($netz['nodes'])->firstWhere('kind', 'zentrum')['label'])->toBe('Creme: Hummus');

    $anker = collect($netz['nodes'])->where('kind', 'anker');
    expect($anker->pluck('slug')->sort()->values()->all())->toBe(['kichererbse', 'tahin'])
        ->and($anker->every(fn ($a) => $a['kern'] === true))->toBeTrue();

    $kand = collect($netz['nodes'])->where('kind', 'kandidat')->keyBy('slug');
    expect($kand['knoblauch']['typ'])->toBe('stern3')
        ->and($kand['knoblauch']['level'])->toBe(3)
        ->and($kand['granatapfel']['typ'])->toBe('stern2')
        ->and($kand['minze']['typ'])->toBe('stern2');
    // knoblauch bedient beide Kern-Anker → cover 2
    expect($kand['knoblauch']['cover'])->toBe(2)
        ->and($kand['granatapfel']['cover'])->toBe(1);

    // Kandidaten-Kanten tragen ihre Stufe
    $kknob = collect($netz['edges'])->where('kind', 'kandidat')->where('source', 'k:'.$this->knoblauch);
    expect($kknob)->toHaveCount(2)                                      // zu kichererbse + tahin
        ->and($kknob->every(fn ($e) => $e['typ'] === 'stern3'))->toBeTrue();

    // Zweistufiges Modell: stern1 raus; anker_anker (innere Ebene) hier 0 (kichererbse↔tahin ungepaart).
    expect($netz['meta']['counts'])->toBe(['stern3' => 1, 'stern2' => 2, 'basis' => 1, 'anker_anker' => 0])
        ->and($netz['meta']['typ_default'])->toBe(['stern3' => true, 'stern2' => true]);
});

it('pairingNetz: Anker↔Anker-Kante aus der Harmonie-Matrix (innere Ebene)', function () {
    // Ohne Kante zwischen den Kern-Ankern gibt es keine innere Linie.
    $netz = $this->svc->pairingNetz($this->rootTeam, $this->rezept->id);
    expect(collect($netz['edges'])->where('kind', 'anker_anker'))->toHaveCount(0)
        ->and($netz['meta']['counts']['anker_anker'])->toBe(0);

    // kichererbse ↔ tahin als Best-Match (level 3) verdrahten → genau eine stern3-Linie.
    mkKante($this->kichererbse, $this->tahin, 'aroma', 3, 0.95);
    $netz = $this->svc->pairingNetz($this->rootTeam, $this->rezept->id);

    $aa = collect($netz['edges'])->where('kind', 'anker_anker')->values();
    expect($aa)->toHaveCount(1)                              // eine Kante je ungeordnetem Paar (bidirektional dedupt)
        ->and($aa[0]['typ'])->toBe('stern3')
        ->and($aa[0]['level'])->toBe(3)
        ->and($netz['meta']['counts']['anker_anker'])->toBe(1);

    // Endpunkte sind exakt die beiden Kern-Anker.
    $eps = collect([$aa[0]['source'], $aa[0]['target']])->sort()->values()->all();
    $soll = collect(['a:'.$this->kichererbse, 'a:'.$this->tahin])->sort()->values()->all();
    expect($eps)->toBe($soll);
});

it('pairingNetz: Anker↔Anker — beste Stufe gewinnt, kontrast ausgeschlossen, kein Selbst-Loop', function () {
    // minze als dritten Kern-Anker dazu.
    $this->svc->setRecipeAnker($this->rootTeam, $this->rezept->id, $this->minze);

    // kichererbse↔tahin doppelt (★★ und ★★★) → dedup auf beste Stufe (★★★).
    mkKante($this->kichererbse, $this->tahin, 'aroma', 2, 0.5);
    mkKante($this->kichererbse, $this->tahin, 'aroma', 3, 0.9);
    // kichererbse↔minze nur als Kontrast (eigene Achse) → NICHT als Harmonie-Linie.
    mkKante($this->kichererbse, $this->minze, 'kontrast', null, null);
    // Selbst-Loop (defensiv) → darf nie als Kante entstehen.
    DB::table('foodalchemist_pairing_anchor_edges')->insert([
        'uuid' => (string) \Symfony\Component\Uid\UuidV7::generate(),
        'anchor_a_id' => $this->kichererbse, 'anchor_b_id' => $this->kichererbse,
        'type' => 'aroma', 'level' => 3, 'weight' => 1.0, 'created_at' => now(), 'updated_at' => now(),
    ]);

    $netz = $this->svc->pairingNetz($this->rootTeam, $this->rezept->id);
    $aa = collect($netz['edges'])->where('kind', 'anker_anker')->values();

    expect($aa)->toHaveCount(1)                              // nur kichererbse↔tahin; kontrast+Selbst-Loop raus
        ->and($aa[0]['typ'])->toBe('stern3');                // beste Stufe gewann
});

it('pairingNetz: komplementäres Basisrezept (baut auf Kandidat auf), VK ausgeschlossen', function () {
    $netz = $this->svc->pairingNetz($this->rootTeam, $this->rezept->id);

    $basis = collect($netz['nodes'])->where('kind', 'basisrezept');
    expect($basis)->toHaveCount(1)
        ->and($basis->first()['label'])->toBe('Sauce: Aioli')        // is_sales_recipe=false
        ->and($basis->first()['typ'])->toBe('stern3')                // via knoblauch (★★★)
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

it('pairingNetz: meta.sig ist stabil, ändert sich aber wenn ein Kern-Anker dazukommt', function () {
    // Sichert den Modal-„detail blick"-Bug ab: die wire:ignore-D3-Insel wird auf sig
    // gekeyt. Ändert sich der Ankersatz, MUSS sig wechseln, sonst friert das Modal
    // auf dem Erst-Öffnungsstand ein (frischer Anker kommt nicht im grossen Panel an).
    $a = $this->svc->pairingNetz($this->rootTeam, $this->rezept->id)['meta']['sig'] ?? null;
    $b = $this->svc->pairingNetz($this->rootTeam, $this->rezept->id)['meta']['sig'] ?? null;

    expect($a)->toBeString()->toMatch('/^[0-9a-f]{10}$/')
        ->and($b)->toBe($a);                                   // deterministisch bei gleichen Daten

    $this->svc->setRecipeAnker($this->rootTeam, $this->rezept->id, $this->minze); // +1 Kern-Anker
    $c = $this->svc->pairingNetz($this->rootTeam, $this->rezept->id)['meta']['sig'] ?? null;

    expect($c)->not->toBe($a);                                 // Ankersatz änderte sich → neuer Key
});

it('Modal: öffnen liefert Netz-Payload (inkl. sig), Klick auf Basisrezept navigiert und schließt', function () {
    $this->actingAs($this->makeUser($this->rootTeam));

    $c = Livewire::test(PairingNetzModal::class);
    $c->dispatch('pairing-netz.oeffnen', recipeId: $this->rezept->id);

    $c->assertDispatched('modal.open')
        ->assertViewHas('netz', function (array $netz) {
            $hatZentrum = collect($netz['nodes'])->firstWhere('kind', 'zentrum') !== null;
            $hatKandidat = collect($netz['nodes'])->where('kind', 'kandidat')->isNotEmpty();
            $hatBasis = collect($netz['nodes'])->firstWhere('id', 'b:'.$this->basis->id) !== null;
            $hatSig = ! empty($netz['meta']['sig'] ?? null);

            return $hatZentrum && $hatKandidat && $hatBasis && $hatSig;
        });

    $c->call('zeigeRezept', $this->basis->id)
        ->assertDispatched('recipe-selected', id: $this->basis->id)
        ->assertDispatched('modal.close');
});

it('pairingNetzForAnkers: Ad-hoc-Netz aus freier Anker-Menge (Planungs-Composer)', function () {
    // Zwei Anker mit Best-Match-Kante untereinander (innere Ebene).
    mkKante($this->kichererbse, $this->tahin, 'aroma', 3, 0.9);

    $netz = $this->svc->pairingNetzForAnkers($this->rootTeam, [$this->kichererbse, $this->tahin], 'Komposition');

    // Zentrum trägt das freie Label (kein Rezept nötig).
    expect(collect($netz['nodes'])->firstWhere('kind', 'zentrum')['label'])->toBe('Komposition');

    // Beide gewählten Anker sind Innenring-Knoten.
    expect(collect($netz['nodes'])->where('kind', 'anker')->pluck('slug')->sort()->values()->all())
        ->toBe(['kichererbse', 'tahin']);

    // Kandidaten werden mitgerechnet (knoblauch bedient beide → cover 2).
    expect(collect($netz['nodes'])->where('kind', 'kandidat')->pluck('slug'))->toContain('knoblauch');

    // Anker↔Anker-Kante (innere Ebene) ist da.
    expect(collect($netz['edges'])->where('kind', 'anker_anker'))->toHaveCount(1);

    // Leere Auswahl → leeres Netz (kein Absturz).
    expect($this->svc->pairingNetzForAnkers($this->rootTeam, [])['nodes'])->toBe([]);
});

it('composerCohesion: Score + Orphan-Erkennung (passt-nicht-Anker)', function () {
    $fremd = mkAnker('fremd'); // ganz ohne Kanten → muss als Orphan erkannt werden
    mkKante($this->kichererbse, $this->tahin, 'aroma', 3, 0.9); // starkes Paar

    $co = $this->svc->composerCohesion([$this->kichererbse, $this->tahin, $fremd]);

    expect($co['rated_pairs'])->toBe(1)               // nur kichererbse↔tahin bewertet
        ->and($co['total_pairs'])->toBe(3)
        ->and($co['score'])->toBeGreaterThan(0);

    // 'fremd' hat keine Kante zu den anderen → is_orphan; die anderen nicht.
    $orphans = collect($co['komponenten'])->where('is_orphan', true)->pluck('label')->all();
    expect($orphans)->toBe(['Fremd']);

    // <2 Anker → neutraler Null-Score (kein Absturz).
    expect($this->svc->composerCohesion([$this->kichererbse])['score'])->toBe(0);
});

it('pairingNetzForAnkers: Brücken-Kanten aus geteilten Partnern + Orphan-Flag', function () {
    $fremd = mkAnker('fremd'); // teilt mit niemandem einen Partner

    // kichererbse & tahin haben KEINE Direktkante, teilen aber knoblauch (beide ★★★, cover 2).
    $netz = $this->svc->pairingNetzForAnkers($this->rootTeam, [$this->kichererbse, $this->tahin, $fremd]);

    // Genau eine Brücke (kichererbse↔tahin über knoblauch), obwohl keine Direktkante existiert.
    $bridges = collect($netz['edges'])->where('kind', 'bridge')->values();
    expect($bridges)->toHaveCount(1)
        ->and($bridges[0]['partners'])->toContain('Knoblauch')
        ->and(collect($netz['edges'])->where('kind', 'anker_anker'))->toHaveCount(0);

    // Brücken-Zusammenfassung: 1 von 3 Paaren verbunden, fremd ist Orphan.
    $b = $netz['meta']['bridge'];
    expect($b['pairs_connected'])->toBe(1)
        ->and($b['pairs_total'])->toBe(3)
        ->and($b['orphans'])->toBe(['Fremd']);

    // Orphan-Flag steckt am Anker-Knoten (für den Warn-Ring).
    $orphan = collect($netz['nodes'])->where('kind', 'anker')->mapWithKeys(fn ($n) => [$n['slug'] => $n['orphan'] ?? null]);
    expect($orphan['fremd'])->toBeTrue()
        ->and($orphan['kichererbse'])->toBeFalse()
        ->and($orphan['tahin'])->toBeFalse();
});
