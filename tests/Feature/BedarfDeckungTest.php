<?php

use Illuminate\Support\Facades\DB;
use Platform\FoodAlchemist\Enums\BedarfDeckung;
use Platform\FoodAlchemist\Services\Pairing\AnkerWissenImport;
use Platform\FoodAlchemist\Services\Pairing\KontrastAbleitung;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;
use Symfony\Component\Uid\UuidV7;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 60 · P4 (Dominique 15.10.): „Erdnuss braucht Röstung" ist Zubereitung, kein Partner-Bedarf.
 * Ohne Deckung schlug das Netz Erdnusspulver/Kaffee als Röstaroma-Partner vor.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->a = [];
    foreach (['peanut' => 'Erdnuss', 'parsnip' => 'Pastinake', 'lentil' => 'Linse', 'coffee' => 'Kaffee'] as $slug => $name) {
        DB::table('foodalchemist_vocab_pairing_anchors')->insert(['uuid' => (string) UuidV7::generate(), 'slug' => $slug,
            'display_de' => $name, 'aroma_intensitaet' => 1.0, 'created_at' => now(), 'updated_at' => now()]);
        $this->a[$slug] = (int) DB::getPdo()->lastInsertId();
    }
});

it('Import übernimmt die Deckung; fehlt oder unbekannt = partner', function () {
    $imp = app(AnkerWissenImport::class);
    $imp->importiere(['anker_id' => $this->a['peanut'], 'anker_slug' => 'peanut', 'braucht' => [
        ['achse' => 'roestaroma', 'staerke' => 'soll', 'deckung' => 'zubereitung', 'beleg' => 'Hitze/Röstung (erzeugt überhaupt das Aroma)'],
        ['achse' => 'saeure', 'staerke' => 'soll', 'deckung' => 'irgendwas', 'beleg' => 'x'],
        ['achse' => 'salz', 'staerke' => 'soll', 'beleg' => 'y'],
    ]]);

    expect(DB::table('foodalchemist_anchor_bedarfe')->where('anchor_id', $this->a['peanut'])->orderBy('achse')->pluck('deckung', 'achse')->all())
        ->toBe(['roestaroma' => 'zubereitung', 'saeure' => 'partner', 'salz' => 'partner']);
});

it('Kontrast: Zubereitung erzeugt keine Partner-Kante, partner und beides schon', function () {
    $w = fn (string $t, array $z) => DB::table($t)->insert($z + ['status' => 'entwurf', 'created_at' => now(), 'updated_at' => now()]);
    $w('foodalchemist_anchor_eigenschaften', ['anchor_id' => $this->a['coffee'], 'achse' => 'roestaroma', 'stufe' => 3, 'quelle' => 'dossier']);
    $w('foodalchemist_anchor_bedarfe', ['anchor_id' => $this->a['peanut'], 'achse' => 'roestaroma', 'staerke' => 'soll', 'deckung' => 'zubereitung']);
    $w('foodalchemist_anchor_bedarfe', ['anchor_id' => $this->a['parsnip'], 'achse' => 'roestaroma', 'staerke' => 'soll', 'deckung' => 'beides']);
    $w('foodalchemist_anchor_bedarfe', ['anchor_id' => $this->a['lentil'], 'achse' => 'roestaroma', 'staerke' => 'soll']);   // Default partner

    app(KontrastAbleitung::class)->baue();
    $von = DB::table('foodalchemist_anchor_beziehungen')->where('art', 'kontrast')->where('anchor_b_id', $this->a['coffee'])->pluck('anchor_a_id')->all();

    expect($von)->not->toContain($this->a['peanut'])
        ->and($von)->toContain($this->a['parsnip'])
        ->and($von)->toContain($this->a['lentil']);
});

it('Vereinen: gleiche Deckung bleibt, gemischt wird „beides", leer zählt als partner', function () {
    expect(BedarfDeckung::vereine('zubereitung', 'zubereitung'))->toBe('zubereitung')
        ->and(BedarfDeckung::vereine('zubereitung', 'partner'))->toBe('beides')
        ->and(BedarfDeckung::vereine(null, null))->toBe('partner');
});
