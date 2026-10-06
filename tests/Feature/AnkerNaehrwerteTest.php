<?php

use Illuminate\Support\Facades\DB;
use Platform\FoodAlchemist\Services\Pairing\AnkerNaehrwerte;
use Platform\FoodAlchemist\Services\SensorikService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;
use Symfony\Component\Uid\UuidV7;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 60 · Nährwert-Kanal: Salz/Süße/Fett je Anker aus den Nährwerten der GPs, die die Zutat SIND.
 * Die Messung je GP kommt aus SensorikService::erdungBulk (hier gefakt) — getestet wird die Auswahl
 * der GPs, der Median und die Umrechnung Stufe = 3 × Intensität.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->n = app(AnkerNaehrwerte::class);
    $this->anker = function (string $name, string $kat) {
        DB::table('foodalchemist_vocab_pairing_anchors')->insert(['uuid' => (string) UuidV7::generate(), 'slug' => mb_strtolower($name),
            'display_de' => $name, 'category' => explode('/', $kat)[0], 'subcategory' => $kat, 'created_at' => now(), 'updated_at' => now()]);

        return (int) DB::getPdo()->lastInsertId();
    };
    $this->gp = function (string $name, array $ankerIds) {
        $gp = $this->makeGp($this->rootTeam, $name);
        foreach ($ankerIds as $a) {
            DB::table('foodalchemist_gp_anchor_mappings')->insert(['uuid' => (string) UuidV7::generate(), 'team_id' => $this->rootTeam->id,
                'gp_id' => $gp->id, 'anchor_id' => $a, 'role' => 'kern', 'created_at' => now(), 'updated_at' => now()]);
        }

        return (int) $gp->id;
    };
});

it('Zutat oder Produkt: erstes Wort, Kompositum-Kopf, nichts Zusammengesetztes', function () {
    expect($this->n->istZutat('Honig: trocken, Bio', 'Honig'))->toBeTrue()
        ->and($this->n->istZutat('Waldhonig: trocken, fluessig', 'Honig'))->toBeTrue()
        ->and($this->n->istZutat('Olivenoel: fluessig', 'Olivenöl'))->toBeTrue()
        ->and($this->n->istZutat('Honigkuchen: trocken', 'Honig'))->toBeFalse()
        ->and($this->n->istZutat('Honig-Senf-Dressing: frisch', 'Honig'))->toBeFalse()
        ->and($this->n->istZutat('Eis Zitrone: TK, vegan', 'Zitrone'))->toBeFalse()
        ->and($this->n->istZutat('Avocado mit Steckrueben-Couscous: frisch', 'Avocado'))->toBeFalse();
});

it('Median über die Zutat-GPs, Stufe = 3 × Intensität; Produkte, Mehrfach-Kern und Gewürze zählen nicht', function () {
    $honig = ($this->anker)('Honig', 'Süßungsmittel/Honig');
    $senf = ($this->anker)('Senf', 'Würzmittel/Tischsaucen');
    $rosmarin = ($this->anker)('Rosmarin', 'Kräuter/Küchenkräuter');
    $salz = ($this->anker)('Meersalz', 'Gewuerze/Salz');
    $g = [
        'wald' => ($this->gp)('Waldhonig: trocken', [$honig]),
        'akazie' => ($this->gp)('Akazienhonig: trocken', [$honig]),
        'raps' => ($this->gp)('Rapshonig: trocken', [$honig]),
        'dressing' => ($this->gp)('Honig-Senf-Dressing: frisch', [$honig]),         // Produkt
        'dip' => ($this->gp)('Honig: Dip', [$honig, $senf]),                         // zwei Kern-Anker
        'ros' => ($this->gp)('Rosmarin: getrocknet', [$rosmarin]),                   // Würzmenge
        'salz' => ($this->gp)('Meersalz: fein', [$salz]),
    ];
    $mess = fn (float $s, float $z, float $f) => ['salzig' => ['wert' => $s, 'basis' => 'x'], 'suess' => ['wert' => $z, 'basis' => 'x'], 'fettig' => ['wert' => $f, 'basis' => 'x']];
    $this->mock(SensorikService::class, fn ($m) => $m->shouldReceive('erdungBulk')->andReturn([
        $g['wald'] => $mess(0.01, 0.92, 0.0), $g['akazie'] => $mess(0.02, 0.90, 0.0), $g['raps'] => $mess(0.0, 0.95, 0.01),
        $g['dressing'] => $mess(0.6, 0.5, 0.6), $g['dip'] => $mess(0.6, 0.5, 0.6),
        $g['ros'] => $mess(0.1, 0.7, 0.6), $g['salz'] => $mess(1.0, 0.0, 0.0),
    ]));

    expect(app(AnkerNaehrwerte::class)->ableiten()['anker'])->toBe(2);
    $werte = DB::table('foodalchemist_anchor_eigenschaften')->where('quelle', 'naehrwert')->orderBy('anchor_id')->orderBy('achse')
        ->get(['anchor_id', 'achse', 'stufe'])->map(fn ($r) => [$r->anchor_id, $r->achse, (int) $r->stufe])->all();

    expect($werte)->toBe([
        [$honig, 'fett', 0], [$honig, 'salz', 0], [$honig, 'suesse', 3],               // Median 0,92 → 3
        [$salz, 'fett', 0], [$salz, 'salz', 3], [$salz, 'suesse', 0],
    ]);
});
