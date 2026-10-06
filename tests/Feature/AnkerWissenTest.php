<?php

use Illuminate\Support\Facades\DB;
use Platform\FoodAlchemist\Enums\Kantenart;
use Platform\FoodAlchemist\Services\Pairing\AnkerGraph;
use Platform\FoodAlchemist\Services\Pairing\AnkerVarianten;
use Platform\FoodAlchemist\Services\Pairing\AnkerWissenImport;
use Platform\FoodAlchemist\Services\Pairing\KategorieRegeln;
use Platform\FoodAlchemist\Services\Pairing\KontrastAbleitung;
use Platform\FoodAlchemist\Tests\Support\Harmonie;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;
use Symfony\Component\Uid\UuidV7;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 60 · P4: Anker-Wissen. Varianten aus den Inspire-Namen, Dossier-Profile als Entwurf,
 * Kontrast als abgeleitete Kante (Bedarf × Eigenschaft) — am Fall Kürbis + Reisessig, der
 * aromatisch Stufe 1 ist und trotzdem ein klassischer Kontrast.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->mk = function (string $slug, string $name): int {
        DB::table('foodalchemist_vocab_pairing_anchors')->insert([
            'uuid' => (string) UuidV7::generate(), 'slug' => $slug, 'display_de' => $name,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return (int) DB::getPdo()->lastInsertId();
    };
    $this->kuerbis = ($this->mk)('pumpkin', 'Kürbis');
    $this->kuerbisGeroestet = ($this->mk)('roasted_pumpkin', 'Kürbis, im Ofen geröstet');
    $this->speck = ($this->mk)('fried_bacon', 'Speck, in der Pfanne gebraten');
    $this->essig = ($this->mk)('rice_vinegar', 'Reisessig');
    $this->zitrone = ($this->mk)('lemon', 'Zitrone');
    $this->salbei = ($this->mk)('sage', 'Salbei');
    $this->anis = ($this->mk)('anise', 'Anis');

    $this->profil = fn (array $extra = []) => array_merge([
        'anker_id' => $this->kuerbis, 'anker_slug' => 'pumpkin', 'quellen' => [],
        'braucht' => [['achse' => 'saeure', 'staerke' => 'soll', 'beleg' => 'Säure als Gegengewicht.']],
        'liefert' => ['geschmack' => ['suesse' => 2, 'saeure' => 0], 'textur' => ['cremig'], 'beleg' => 'süß, cremig'],
        'vertraegt' => [['name' => 'Salbei'], ['name' => 'Zitrusfrüchte']],
        'zerstoert' => [['art' => 'zutat', 'name' => 'Anis', 'grund' => 'überdeckt'], ['art' => 'technik', 'name' => 'Kochen in Wasser', 'grund' => 'wässrig']],
        'komponenten' => [['name' => 'Kürbis-Chips', 'technik' => 'dünn, 160 °C', 'liefert' => ['knusprig', 'unbekannt']]],
    ], $extra);
    $this->importiere = fn (array $p) => app(AnkerWissenImport::class)->importiere($p);
});

it('leitet Grundname, Verfahren und Grund-Anker aus den Inspire-Namen ab', function () {
    $s = app(AnkerVarianten::class)->ableiten();
    $row = fn (int $id) => DB::table('foodalchemist_vocab_pairing_anchors')->where('id', $id)->first();

    expect($s['varianten'])->toBe(2)
        ->and($row($this->kuerbisGeroestet)->grundname)->toBe('Kürbis')
        ->and($row($this->kuerbisGeroestet)->verfahren)->toBe('geroestet')
        ->and((int) $row($this->kuerbisGeroestet)->grund_anchor_id)->toBe($this->kuerbis)
        // Inspire führt kein „Speck" — der Grundname verbindet trotzdem
        ->and($row($this->speck)->grundname)->toBe('Speck')
        ->and($row($this->speck)->verfahren)->toBe('gebraten')
        ->and($row($this->speck)->grund_anchor_id)->toBeNull()
        ->and($row($this->kuerbis)->verfahren)->toBeNull()
        ->and(app(AnkerVarianten::class)->ableiten()['geaendert'])->toBe(0);   // wiederholbar
});

it('übernimmt ein Profil als Entwurf und löst Partner nur exakt auf', function () {
    $r = ($this->importiere)(($this->profil)());

    expect($r['status'])->toBe('ok')
        ->and(DB::table('foodalchemist_anchor_bedarfe')->where('anchor_id', $this->kuerbis)->value('achse'))->toBe('saeure')
        ->and(DB::table('foodalchemist_anchor_eigenschaften')->where('anchor_id', $this->kuerbis)->pluck('stufe', 'achse')->all())
            ->toEqualCanonicalizing(['suesse' => 2, 'saeure' => 0, 'cremig' => 2])
        ->and(json_decode(DB::table('foodalchemist_anchor_komponenten')->value('liefert')))->toBe(['knusprig'])   // unbekannte Achse fällt weg
        ->and(app(AnkerGraph::class)->beziehungen([$this->kuerbis], Kantenart::Kombination)->pluck('zu')->all())->toBe([$this->salbei])
        ->and(app(AnkerGraph::class)->beziehungen([$this->kuerbis], Kantenart::Konflikt)->pluck('zu')->all())->toBe([$this->anis])
        // „Zitrusfrüchte" trifft keinen Anker exakt → Prüfliste; der Verarbeitungsfehler ist ein Hinweis
        ->and(DB::table('foodalchemist_anchor_wissen_offen')->orderBy('art')->pluck('art', 'name')->all())
            ->toBe(['Zitrusfrüchte' => 'kombination', 'Kochen in Wasser' => 'verarbeitung'])
        ->and(DB::table('foodalchemist_anchor_bedarfe')->value('status'))->toBe('entwurf');
});

it('lehnt ein Profil ab, dessen Slug nicht zur Anker-ID passt', function () {
    expect(($this->importiere)(($this->profil)(['anker_slug' => 'apple']))['status'])->toBe('abgelehnt_anker')
        ->and(DB::table('foodalchemist_anchor_bedarfe')->count())->toBe(0);
});

it('ein erneuter Import ersetzt nur Entwürfe — geprüftes Wissen bleibt', function () {
    ($this->importiere)(($this->profil)());
    DB::table('foodalchemist_anchor_bedarfe')->where('anchor_id', $this->kuerbis)->update(['status' => 'geprueft', 'staerke' => 'muss']);

    ($this->importiere)(($this->profil)());

    expect(DB::table('foodalchemist_anchor_bedarfe')->where('anchor_id', $this->kuerbis)->count())->toBe(1)
        ->and(DB::table('foodalchemist_anchor_bedarfe')->where('anchor_id', $this->kuerbis)->value('staerke'))->toBe('muss')
        ->and(DB::table('foodalchemist_anchor_eigenschaften')->where('anchor_id', $this->kuerbis)->count())->toBe(3);
});

it('Kontrast: Kürbis braucht Säure, Reisessig und Zitrone liefern sie — ohne Harmonie-Filter', function () {
    ($this->importiere)(($this->profil)());
    foreach ([[$this->essig, 'rice_vinegar', 3], [$this->zitrone, 'lemon', 3], [$this->anis, 'anise', 2]] as [$id, $slug, $stufe]) {
        ($this->importiere)(['anker_id' => $id, 'anker_slug' => $slug, 'liefert' => ['geschmack' => ['saeure' => $stufe]]]);
    }
    // Zitrone harmoniert zusätzlich mit Kürbis → gewinnt den Gleichstand gegen Reisessig (Stufe 1).
    Harmonie::kante($this->kuerbis, $this->zitrone, 3);

    $s = app(KontrastAbleitung::class)->baue();
    $kontrast = app(AnkerGraph::class)->beziehungen([$this->kuerbis], Kantenart::Kontrast);

    expect($s['kanten'])->toBe(2)
        ->and($kontrast->pluck('zu')->all())->toBe([$this->zitrone, $this->essig])     // Anis: Konflikt → raus
        ->and($kontrast->pluck('achse')->unique()->all())->toBe(['saeure'])
        ->and(app(AnkerGraph::class)->stufe($this->kuerbis, $this->essig))->toBe(1)       // aromatisch neutral
        ->and($kontrast->first()->status)->toBe('entwurf');
});

it('verworfenes Wissen trägt keine Kontrast-Kante', function () {
    ($this->importiere)(($this->profil)());
    ($this->importiere)(['anker_id' => $this->essig, 'anker_slug' => 'rice_vinegar', 'liefert' => ['geschmack' => ['saeure' => 3]]]);
    DB::table('foodalchemist_anchor_bedarfe')->where('anchor_id', $this->kuerbis)->update(['status' => 'verworfen']);

    expect(app(KontrastAbleitung::class)->baue()['kanten'])->toBe(0);
});

it('Kategorie-Regeln: Träger, Frische und Röstaroma aus Kategorie bzw. Verfahren, gekennzeichnet', function () {
    $kat = fn (int $id, string $c, ?string $sub) => DB::table('foodalchemist_vocab_pairing_anchors')->where('id', $id)
        ->update(['category' => $c, 'subcategory' => $sub]);
    $reis = ($this->mk)('rice', 'Reis');
    $kat($reis, 'Getreide', 'Getreide/Reis');
    $kat($this->salbei, 'Kräuter', 'Kräuter/Küchenkräuter');
    $kat($this->zitrone, 'Obst', 'Obst/Zitrusfrüchte');
    $kat($this->kuerbis, 'Gemüse', 'Gemüse/Fruchtgemüse');
    $kat($this->kuerbisGeroestet, 'Gemüse', 'Gemüse/Fruchtgemüse');
    $salz = ($this->mk)('sea_salt', 'Meersalz');
    $kat($salz, 'Gewuerze', 'Gewuerze/Salz');
    app(AnkerVarianten::class)->ableiten();

    expect(app(KategorieRegeln::class)->eigenschaften())->toBeGreaterThan(0);
    $e = fn (int $id) => DB::table('foodalchemist_anchor_eigenschaften')->where('anchor_id', $id)->pluck('stufe', 'achse')->all();

    expect($e($reis))->toBe(['traeger' => 3])
        ->and($e($this->salbei))->toEqualCanonicalizing(['frische' => 3, 'aromatik' => 2])
        ->and($e($this->zitrone))->toBe(['frische' => 3])
        ->and($e($this->kuerbisGeroestet))->toBe(['roestaroma' => 3])       // Zubereitung liefert Röstaroma
        ->and($e($this->kuerbis))->toBe([])
        ->and($e($salz))->toBe([])                                          // Salz ist keine Aromatik
        ->and(DB::table('foodalchemist_anchor_eigenschaften')->distinct()->pluck('quelle')->all())->toBe(['kategorie']);

    // Pfifferling-Fall: Bedarf „Träger" findet jetzt einen Lieferanten.
    ($this->importiere)(['anker_id' => $this->kuerbis, 'anker_slug' => 'pumpkin', 'braucht' => [['achse' => 'traeger', 'staerke' => 'soll']]]);
    app(KontrastAbleitung::class)->baue();
    expect(app(AnkerGraph::class)->beziehungen([$this->kuerbis], Kantenart::Kontrast)->pluck('zu')->all())->toBe([$reis]);
});

it('Intensität: Startwert je Kategorie, längstes Präfix gewinnt, gesetzte Werte bleiben', function () {
    $kat = fn (int $id, string $c, ?string $sub) => DB::table('foodalchemist_vocab_pairing_anchors')->where('id', $id)
        ->update(['category' => $c, 'subcategory' => $sub]);
    $kat($this->salbei, 'Kräuter', 'Kräuter/Küchenkräuter');
    $kat($this->anis, 'Gewuerze', 'Gewuerze/Anis- und Lakritzgewuerze');
    $kat($this->kuerbis, 'Gemüse', 'Gemüse/Fruchtgemüse');
    $chili = ($this->mk)('chili', 'Chili');
    $kat($chili, 'Gemüse', 'Gemüse/Fruchtgemüse/Chili');
    DB::table('foodalchemist_vocab_pairing_anchors')->where('id', $this->essig)->update(['aroma_intensitaet' => 2.5, 'category' => 'Würzmittel']);

    app(KategorieRegeln::class)->intensitaet();
    $i = fn (int $id) => (float) DB::table('foodalchemist_vocab_pairing_anchors')->where('id', $id)->value('aroma_intensitaet');

    expect($i($this->salbei))->toBe(3.0)
        ->and($i($this->anis))->toBe(5.0)
        ->and($i($this->kuerbis))->toBe(1.0)
        ->and($i($chili))->toBe(4.0)
        ->and($i($this->essig))->toBe(2.5);
});
