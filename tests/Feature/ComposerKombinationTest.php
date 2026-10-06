<?php

use Illuminate\Support\Facades\DB;
use Platform\FoodAlchemist\Services\Pairing\KombinationsPlan;
use Platform\FoodAlchemist\Services\Pairing\KontrastAbleitung;
use Platform\FoodAlchemist\Services\PairingService;
use Platform\FoodAlchemist\Services\RecipeGenerationContextService;
use Platform\FoodAlchemist\Tests\Support\Harmonie;
use Platform\FoodAlchemist\Tests\Support\RezeptAnker;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;
use Symfony\Component\Uid\UuidV7;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 60 · Composer: Anker kombinieren in der Planung — dieselbe Kombinationslogik wie im
 * Gericht-Panel. Netz: nur ★★★-Partner, Kontrast-Lieferanten für offene Bedarfe, Konflikt-Linien,
 * keine Brücken über geteilte Partner mehr. Übergabe an den Generator: Plan aus den gewählten
 * Ankern; beim Gericht je Leit-Aroma vorhandene Basisrezepte als Angebot (kein Zwang).
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->a = [];
    foreach (['pumpkin' => 'Kürbis', 'sage' => 'Salbei', 'walnut' => 'Walnuss', 'rice_vinegar' => 'Reisessig',
        'anise' => 'Anis', 'vanilla' => 'Vanille'] as $slug => $name) {
        DB::table('foodalchemist_vocab_pairing_anchors')->insert(['uuid' => (string) UuidV7::generate(), 'slug' => $slug,
            'display_de' => $name, 'aroma_intensitaet' => 1.0, 'created_at' => now(), 'updated_at' => now()]);
        $this->a[$slug] = (int) DB::getPdo()->lastInsertId();
    }
    Harmonie::kante($this->a['pumpkin'], $this->a['sage'], 3);
    Harmonie::kante($this->a['pumpkin'], $this->a['walnut'], 2);              // 2★ = Rauschen
    Harmonie::kante($this->a['pumpkin'], $this->a['rice_vinegar'], 3);        // Kontrast-Lieferant muss zur Auswahl passen
    $w = fn (string $t, array $z) => DB::table($t)->insert($z + ['status' => 'entwurf', 'created_at' => now(), 'updated_at' => now()]);
    $w('foodalchemist_anchor_bedarfe', ['anchor_id' => $this->a['pumpkin'], 'achse' => 'saeure', 'staerke' => 'muss']);
    $w('foodalchemist_anchor_eigenschaften', ['anchor_id' => $this->a['rice_vinegar'], 'achse' => 'saeure', 'stufe' => 3, 'quelle' => 'dossier']);
    DB::table('foodalchemist_anchor_beziehungen')->insert(['anchor_a_id' => $this->a['pumpkin'], 'anchor_b_id' => $this->a['anise'],
        'art' => 'konflikt', 'achse' => '', 'grundlage' => 'dossier', 'status' => 'entwurf', 'created_at' => now(), 'updated_at' => now()]);
    app(KontrastAbleitung::class)->baue();
    $this->netz = fn (array $slugs) => app(PairingService::class)->pairingNetzForAnkers($this->rootTeam,
        array_map(fn ($s) => $this->a[$s], $slugs));
    $this->knoten = fn (array $netz, string $typ) => collect($netz['nodes'])->where('kind', 'kandidat')->where('typ', $typ)
        ->pluck('label')->values()->all();
});

it('Netz: nur ★★★-Partner, Kontrast-Lieferant für den offenen Bedarf, keine Brücken', function () {
    $n = ($this->netz)(['pumpkin']);

    expect(($this->knoten)($n, 'stern3'))->toBe(['Salbei'])
        ->and(($this->knoten)($n, 'stern2'))->toBe([])                        // Walnuss (2★) fehlt
        ->and(($this->knoten)($n, 'kontrast'))->toBe(['Reisessig'])
        ->and(collect($n['nodes'])->firstWhere('label', 'Reisessig')['achse'])->toBe('Säure')
        ->and($n['meta'])->not->toHaveKey('bridge')
        ->and($n['meta']['typ_default'])->toBe(['stern3' => true, 'kontrast' => true])
        ->and(collect($n['edges'])->where('kind', 'bridge'))->toHaveCount(0);
});

it('Netz: Konflikt zwischen zwei gewählten Ankern ist eine rote Linie; ein Anker ohne Verbindung ist markiert', function () {
    $n = ($this->netz)(['pumpkin', 'anise', 'vanilla']);
    $konflikt = collect($n['edges'])->where('kind', 'konflikt')->values();

    expect($konflikt)->toHaveCount(1)
        ->and($konflikt[0]['text'])->toBe('Konflikt: Kürbis und Anis stören sich')
        ->and($n['meta']['counts']['konflikt'])->toBe(1)
        ->and(collect($n['nodes'])->where('kind', 'anker')->pluck('orphan', 'label')->all())
        ->toBe(['Kürbis' => true, 'Anis' => true, 'Vanille' => true]);   // Konflikt ist keine Verbindung

    $verbunden = ($this->netz)(['pumpkin', 'sage']);
    expect(collect($verbunden['nodes'])->where('kind', 'anker')->pluck('orphan', 'label')->all())
        ->toBe(['Kürbis' => false, 'Salbei' => false]);
});

it('Picker: ★★★, Kontrast und Konflikt relativ zur Auswahl', function () {
    $b = app(PairingService::class)->composerAnkerBrowse($this->rootTeam, '', null, [$this->a['pumpkin']]);

    expect(collect($b['items'])->pluck('typ', 'label')->all())->toBe([
        'Anis' => 'konflikt', 'Reisessig' => 'kontrast', 'Salbei' => 'stern3', 'Vanille' => null, 'Walnuss' => null,
    ]);
});

it('Generator-Plan aus den gewählten Ankern: beim Gericht vorhandene Basisrezepte mit dem Aroma als Angebot', function () {
    $pueree = $this->makeRecipe($this->rootTeam, 'Püree: Kürbis');
    $pueree->update(['is_sales_recipe' => false, 'function' => 'Beilage', 'taste_direction' => 'herzhaft']);
    RezeptAnker::gib($pueree, $this->a['pumpkin']);
    $beize = $this->makeRecipe($this->rootTeam, 'Beize: Kürbis');
    $beize->update(['is_sales_recipe' => false, 'function' => 'Marinade']);
    RezeptAnker::gib($beize, $this->a['pumpkin']);                            // geht nicht auf den Teller

    $plan = app(KombinationsPlan::class)->fuerAnker($this->rootTeam, [$this->a['pumpkin']], true);

    expect($plan['leit_aromen'][0]['basisrezepte'])->toBe([['sub_rezept_id' => $pueree->id, 'name' => 'Püree: Kürbis']])
        ->and($plan['hinweis'])->toContain('kein Zwang')
        ->and(app(KombinationsPlan::class)->fuerAnker($this->rootTeam, [$this->a['pumpkin']], false)['leit_aromen'][0])
        ->not->toHaveKey('basisrezepte');
});

it('seed_anker: der Kombinationsplan kommt aus den Ankern, auch wenn die Beschreibung sie nicht nennt', function () {
    $out = app(RecipeGenerationContextService::class)
        ->build($this->rootTeam, 'Herbstliche Kreation', ['seed_anker' => ['pumpkin']], true);

    expect($out['prompt'])->toHaveKey('kombinationsplan')
        ->and($out['prompt']['kombinationsplan']['rolle'])->toBe('komposition')
        ->and(array_column($out['prompt']['kombinationsplan']['leit_aromen'], 'aroma'))->toBe(['Kürbis'])
        ->and($out['prompt']['kombinationsplan']['leit_aromen'][0]['vermeiden'])->toBe(['Anis']);
});

it('Composer-Tab rendert „Passt das zusammen?" aus der Kombinationslogik, Kontrast-Chip statt ★★, Picker-Markierung', function () {
    $this->actingAs($this->makeUser($this->rootTeam));
    $session = app(\Platform\FoodAlchemist\Services\PlanningSessionService::class)->create($this->rootTeam, ['title' => 'Composer', 'brief' => '']);

    $html = \Livewire\Livewire::test(\Platform\FoodAlchemist\Livewire\Planung\Index::class)
        ->call('oeffne', $session->id)
        ->set('composerAnker', [['id' => $this->a['pumpkin'], 'slug' => 'pumpkin', 'label' => 'Kürbis'],
            ['id' => $this->a['anise'], 'slug' => 'anise', 'label' => 'Anis']])
        ->html();

    expect($html)->toContain('data-kombination')
        ->and($html)->toContain('Konflikt: Kürbis und Anis stören sich')
        ->and($html)->toContain("toggleTyp('kontrast')")
        ->and($html)->not->toContain("toggleTyp('stern2')")
        ->and($html)->not->toContain('Anker-Paare über gemeinsame Partner')
        ->and($html)->toContain('data-picker-typ="kontrast"');
});

it('Kontrast-Lieferant ohne ★★★ zur Auswahl wird nicht angeboten (kulinarisch beliebig)', function () {
    app(\Platform\FoodAlchemist\Services\Pairing\AnkerGraph::class)->setze($this->a['pumpkin'], $this->a['rice_vinegar'], 1);

    expect(($this->knoten)(($this->netz)(['pumpkin']), 'kontrast'))->toBe([]);
});
