<?php

use Illuminate\Support\Facades\DB;
use Platform\FoodAlchemist\Services\Pairing\AnkerWissenImport;
use Platform\FoodAlchemist\Services\Pairing\Kombinationslogik;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;
use Symfony\Component\Uid\UuidV7;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 60 · P4 Teil 2 (Kurator 15.10.): Dossier-Warnungen ohne Sperre (Dosis, Technik, Gruppe)
 * erscheinen als graue Aussage „hinweis" in der Kombinationslogik. Treffer nur über den Anker
 * (`anker:<slug>`) oder seine Inspire-Unterkategorie (`gruppe:<pfad>`), nie über Freitext.
 * Höchstens 3 je Gericht, Grundlage „aus Dossier, ungeprüft".
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->a = [];
    foreach ([
        'lemon' => ['Zitrone', 'Obst/Zitrusfrüchte'],
        'lemongrass' => ['Zitronengras', 'Kräuter/Küchenkräuter'],
        'braised_lemon_sole' => ['Limande, geschmort', 'Protein/Fisch'],
        'anise' => ['Anis', 'Gewuerze/Anis- und Lakritzgewuerze'],
        'orange' => ['Orange', 'Obst/Zitrusfrüchte'],
        'lime' => ['Limette', 'Obst/Zitrusfrüchte'],
        'thyme' => ['Thymian', 'Kräuter/Küchenkräuter'],
        'butter' => ['Butter', 'Milchprodukte/Butter'],
    ] as $slug => [$name, $sub]) {
        DB::table('foodalchemist_vocab_pairing_anchors')->insert(['uuid' => (string) UuidV7::generate(), 'slug' => $slug,
            'display_de' => $name, 'subcategory' => $sub, 'aroma_intensitaet' => 1.0, 'created_at' => now(), 'updated_at' => now()]);
        $this->a[$slug] = (int) DB::getPdo()->lastInsertId();
    }
    $this->hinweis = fn (string $von, string $ziel, string $begriff, string $grund, string $status = 'entwurf') => DB::table('foodalchemist_anchor_wissen_offen')
        ->insert(['anchor_id' => $this->a[$von], 'art' => 'hinweis', 'name' => $ziel, 'kontext' => $begriff, 'grund' => $grund,
            'status' => $status, 'created_at' => now(), 'updated_at' => now()]);
    $this->analyse = function (array $slugs): array {
        $k = app(Kombinationslogik::class);

        return $k->analysiereBestandteile($k->bestandteileAusAnkern(array_map(fn ($s) => $this->a[$s], $slugs)));
    };
    $this->hinweise = fn (array $analyse) => collect($analyse['aussagen'])->filter(fn ($x) => $x->typ->value === 'hinweis')->values();
});

it('Import: Zerstört art=hinweis landet mit strukturiertem Ziel in der Prüfliste, ungültige Ziele nicht', function () {
    $r = app(AnkerWissenImport::class)->importiere(['anker_id' => $this->a['braised_lemon_sole'], 'anker_slug' => 'braised_lemon_sole',
        'zerstoert' => [
            ['art' => 'hinweis', 'ziel' => 'anker:lemon', 'name' => 'Zitrone', 'grund' => 'ein Spritzer hebt die Milde, zu viel überdeckt sie'],
            ['art' => 'hinweis', 'ziel' => 'Zitrone', 'name' => 'Zitrone', 'grund' => 'Freitext-Ziel zählt nicht'],
        ]]);

    $zeilen = DB::table('foodalchemist_anchor_wissen_offen')->where('anchor_id', $this->a['braised_lemon_sole'])->get();
    expect($r['hinweise'])->toBe(1)
        ->and($zeilen)->toHaveCount(1)
        ->and($zeilen[0]->art)->toBe('hinweis')
        ->and($zeilen[0]->name)->toBe('anker:lemon')
        ->and($zeilen[0]->kontext)->toBe('Zitrone')
        ->and(DB::table('foodalchemist_anchor_beziehungen')->count())->toBe(0);   // keine Sperre
});

it('Zitrone + Limande → Hinweis (grau, aus Dossier ungeprüft), kein Konflikt', function () {
    ($this->hinweis)('braised_lemon_sole', 'anker:lemon', 'Zitrone', 'ein Spritzer hebt die Milde, zu viel überdeckt sie');

    $analyse = ($this->analyse)(['braised_lemon_sole', 'lemon']);
    $h = ($this->hinweise)($analyse);

    expect($h)->toHaveCount(1)
        ->and($h[0]->grundlage->label())->toBe('aus Dossier, ungeprüft')
        ->and($h[0]->text)->toBe('Hinweis zu Limande, geschmort + Zitrone: Zitrone – ein Spritzer hebt die Milde, zu viel überdeckt sie')
        ->and($analyse['zusammenfassung'])->not->toHaveKey('konflikt');
});

it('Zitronengras + Limande → KEIN Hinweis (kein Wortteil-Treffer); Limette + Limande auch nicht', function () {
    ($this->hinweis)('braised_lemon_sole', 'anker:lemon', 'Zitrone', 'ein Spritzer hebt die Milde, zu viel überdeckt sie');

    expect(($this->hinweise)(($this->analyse)(['braised_lemon_sole', 'lemongrass'])))->toHaveCount(0)
        ->and(($this->hinweise)(($this->analyse)(['braised_lemon_sole', 'lime'])))->toHaveCount(0);
});

it('Gruppe: trifft Anker der Unterkategorie und darunter, nicht Nachbarn oder halbe Pfade; verworfen zählt nie', function () {
    ($this->hinweis)('anise', 'gruppe:Protein/Fisch', 'Fisch', 'überdeckt feine Aromen');
    ($this->hinweis)('thyme', 'gruppe:Obst', 'Obst', 'nur sparsam');
    ($this->hinweis)('butter', 'gruppe:Obst/Zitrus', 'Zitrus', 'halber Pfad');
    ($this->hinweis)('lemongrass', 'anker:lemon', 'Zitrone', 'verworfen', 'verworfen');

    expect(($this->hinweise)(($this->analyse)(['anise', 'braised_lemon_sole'])))->toHaveCount(1)
        ->and(($this->hinweise)(($this->analyse)(['anise', 'lemon'])))->toHaveCount(0)
        ->and(($this->hinweise)(($this->analyse)(['thyme', 'orange'])))->toHaveCount(1)        // Obst → Obst/Zitrusfrüchte
        ->and(($this->hinweise)(($this->analyse)(['butter', 'orange'])))->toHaveCount(0)       // „Obst/Zitrus" ≠ „Obst/Zitrusfrüchte"
        ->and(($this->hinweise)(($this->analyse)(['lemongrass', 'lemon'])))->toHaveCount(0);
});

it('höchstens 3 Hinweise je Gericht', function () {
    foreach (['lemon', 'orange', 'lime', 'thyme', 'butter'] as $ziel) {
        ($this->hinweis)('braised_lemon_sole', 'anker:'.$ziel, $ziel, 'sparsam');
    }

    expect(($this->hinweise)(($this->analyse)(['braised_lemon_sole', 'lemon', 'orange', 'lime', 'thyme', 'butter'])))->toHaveCount(3);
});

it('Composer zeigt den Hinweis grau im Block „Passt das zusammen?", ohne Konflikt-Zählung', function () {
    ($this->hinweis)('braised_lemon_sole', 'anker:lemon', 'Zitrone', 'ein Spritzer hebt die Milde, zu viel überdeckt sie');
    $this->actingAs($this->makeUser($this->rootTeam));
    $session = app(\Platform\FoodAlchemist\Services\PlanningSessionService::class)->create($this->rootTeam, ['title' => 'Composer', 'brief' => '']);

    $html = \Livewire\Livewire::test(\Platform\FoodAlchemist\Livewire\Planung\Index::class)
        ->call('oeffne', $session->id)
        ->set('composerAnker', [['id' => $this->a['braised_lemon_sole'], 'slug' => 'braised_lemon_sole', 'label' => 'Limande, geschmort'],
            ['id' => $this->a['lemon'], 'slug' => 'lemon', 'label' => 'Zitrone']])
        ->html();

    expect($html)->toContain('data-gruppe="hinweis"')
        ->and($html)->toContain('Hinweis aus Dossier')
        ->and($html)->toContain('Zitrone – ein Spritzer hebt die Milde')
        ->and($html)->not->toContain('data-gruppe="konflikt"');
});
