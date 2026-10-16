<?php

use Illuminate\Support\Facades\DB;
use Platform\FoodAlchemist\Services\Pairing\KontrastAbleitung;
use Platform\FoodAlchemist\Services\Pairing\Kombinationslogik;
use Platform\FoodAlchemist\Services\PairingService;
use Platform\FoodAlchemist\Tests\Support\Harmonie;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;
use Symfony\Component\Uid\UuidV7;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Composer „Passt das zusammen?" je Modus (Dominique 16.10.): als Gericht (Teller) oder als Basisrezept
 * (eine Zubereitung), Urteilszeile, Salz nie im Composer, Formwechsel nie zur gewählten Variante, kein
 * Netz-Partner mit dem Grundnamen einer gewählten Zutat.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->a = [];
    $mk = function (string $slug, string $name, ?string $grund = null, ?string $verfahren = null) {
        DB::table('foodalchemist_vocab_pairing_anchors')->insert(['uuid' => (string) UuidV7::generate(), 'slug' => $slug,
            'display_de' => $name, 'grundname' => $grund, 'verfahren' => $verfahren, 'aroma_intensitaet' => 1.0, 'created_at' => now(), 'updated_at' => now()]);

        return $this->a[$slug] = (int) DB::getPdo()->lastInsertId();
    };
    $mk('potato', 'Kartoffel', 'Kartoffel');
    $mk('roasted_potato', 'Kartoffel, geröstet', 'Kartoffel', 'geroestet');
    $mk('vinegar', 'Rotweinessig', 'Rotweinessig');
    $mk('sea_salt', 'Meersalz', 'Meersalz');
    $mk('crouton', 'Croutons', 'Croutons');
    $mk('apple', 'Apfel', 'Apfel');
    $mk('bean', 'Bohne', 'Bohne');
    $mk('cooked_bean', 'Bohne, gekocht', 'Bohne', 'gekocht');
    $mk('savory', 'Bohnenkraut', 'Bohnenkraut');
    $w = fn (string $t, array $z) => DB::table($t)->insert($z + ['status' => 'entwurf', 'created_at' => now(), 'updated_at' => now()]);
    $w('foodalchemist_anchor_bedarfe', ['anchor_id' => $this->a['potato'], 'achse' => 'salz', 'staerke' => 'muss']);
    $w('foodalchemist_anchor_bedarfe', ['anchor_id' => $this->a['potato'], 'achse' => 'saeure', 'staerke' => 'muss']);
    $w('foodalchemist_anchor_bedarfe', ['anchor_id' => $this->a['potato'], 'achse' => 'knusprig', 'staerke' => 'soll']);
    $w('foodalchemist_anchor_bedarfe', ['anchor_id' => $this->a['potato'], 'achse' => 'roestaroma', 'staerke' => 'soll', 'deckung' => 'zubereitung']);
    $w('foodalchemist_anchor_eigenschaften', ['anchor_id' => $this->a['vinegar'], 'achse' => 'saeure', 'stufe' => 3, 'quelle' => 'dossier']);
    $w('foodalchemist_anchor_eigenschaften', ['anchor_id' => $this->a['sea_salt'], 'achse' => 'salz', 'stufe' => 3, 'quelle' => 'naehrwert']);
    $w('foodalchemist_anchor_eigenschaften', ['anchor_id' => $this->a['roasted_potato'], 'achse' => 'roestaroma', 'stufe' => 3, 'quelle' => 'kategorie']);
    Harmonie::kante($this->a['potato'], $this->a['vinegar'], 3);
    Harmonie::kante($this->a['potato'], $this->a['sea_salt'], 3);
    Harmonie::kante($this->a['potato'], $this->a['bean'], 3);
    Harmonie::kante($this->a['potato'], $this->a['savory'], 3);
    Harmonie::kante($this->a['potato'], $this->a['crouton'], 3);
    app(KontrastAbleitung::class)->baue();
    $this->k = fn (array $slugs, string $modus = 'gericht') => app(Kombinationslogik::class)
        ->datenAusAnkern(array_map(fn ($s) => $this->a[$s], $slugs), null, null, (int) $this->rootTeam->id, $modus);
});

it('Salz: im Composer nie Bedarf und nie Kontrast-Lieferant — die Gerichts-Analyse bleibt unverändert', function () {
    $d = ($this->k)(['potato', 'apple']);
    $netz = app(PairingService::class)->pairingNetzForAnkers($this->rootTeam, [$this->a['potato'], $this->a['apple']]);
    $kontrastAchsen = collect($netz['nodes'])->where('kind', 'kandidat')->where('typ', 'kontrast')->pluck('achse')
        ->merge(collect($netz['nodes'])->where('kind', 'kandidat')->pluck('auch_kontrast'))->filter()->all();

    expect(array_column($d['offene_bedarfe'], 'achse'))->not->toContain('salz')
        ->and(collect($d['aussagen'])->flatten(1)->pluck('achse')->filter()->all())->not->toContain('salz')
        ->and($kontrastAchsen)->not->toContain('Salz');

    // Gegenprobe Grenze: Gericht-Panel/Rezeptprüfung rechnen über analysiereBestandteile ohne Composer-Filter
    $logik = app(Kombinationslogik::class);
    $roh = $logik->analysiereBestandteile($logik->bestandteileAusAnkern([$this->a['potato'], $this->a['apple']]));
    expect(array_column($roh['offene_bedarfe'], 'achse'))->toContain('salz');
});

it('Gericht vs. Basisrezept: Teller-Lücken vs. Geschmacks-Balance, Zubereitung als Technik, Zutaten statt Basisrezepte, Urteilszeile', function () {
    $g = ($this->k)(['potato', 'apple']);
    $b = ($this->k)(['potato', 'apple'], 'basisrezept');

    expect($g['modus'])->toBe('gericht')
        ->and(array_column($g['offene_bedarfe'], 'achse'))->toContain('knusprig', 'roestaroma', 'saeure')
        ->and($g['urteil']['text'])->toContain('Keine der Zutaten harmoniert')
        ->and($g['urteil']['ton'])->toBeNull();                                            // Gericht: Hinweis, keine Warnung

    expect($b['modus'])->toBe('basisrezept')
        ->and(array_column($b['offene_bedarfe'], 'achse'))->toBe(['saeure'])                 // keine Textur, kein Röstaroma
        ->and($b['technik'])->toBe(['Kartoffel: Röstaroma über die Zubereitung'])
        ->and($b['vorschlaege'][0]['zutaten'])->toBe(['Rotweinessig'])                     // ★★★ zur Auswahl
        ->and($b['vorschlaege'][0]['basisrezepte'])->toBe([])
        ->and($b['urteil']['text'])->toContain('passt nicht zum Rest: Kartoffel, Apfel')
        ->and($b['urteil']['ton'])->toBe('warn');

    $drei = ($this->k)(['potato', 'vinegar', 'apple']);
    expect($drei['urteil']['text'])->toStartWith('Harmonieren: Kartoffel + Rotweinessig · Akzent ohne gemessenen Bezug: Apfel')
        ->and($drei['urteil']['aussen'])->toBe(['Apfel']);
});

it('Formwechsel: nie zur schon gewählten Variante; die gewählte geröstete Variante deckt Röstaroma selbst', function () {
    $roh = ($this->k)(['potato', 'apple']);
    $roest = collect($roh['vorschlaege'])->firstWhere('achse', 'roestaroma');
    expect($roest['formwechsel'])->toBe(['Kartoffel: als „Kartoffel, geröstet" zubereiten']);          // Gegenprobe: roh → Variante

    $geroestet = ($this->k)(['roasted_potato', 'apple']);
    expect(array_column($geroestet['offene_bedarfe'], 'achse'))->not->toContain('roestaroma')
        ->and(collect($geroestet['vorschlaege'])->pluck('formwechsel')->flatten()->filter(fn ($f) => str_contains($f, 'geröstet'))->all())->toBe([]);
});

it('Netz: kein Partner mit dem Grundnamen einer gewählten Zutat — Teilstring (Bohnenkraut) bleibt', function () {
    Harmonie::kante($this->a['cooked_bean'], $this->a['bean'], 3);
    Harmonie::kante($this->a['cooked_bean'], $this->a['savory'], 3);
    $netz = app(PairingService::class)->pairingNetzForAnkers($this->rootTeam, [$this->a['cooked_bean']]);
    $partner = collect($netz['nodes'])->where('kind', 'kandidat')->pluck('label')->all();

    expect($partner)->not->toContain('Bohne')                          // gleicher Grundname wie „Bohne, gekocht"
        ->and($partner)->toContain('Bohnenkraut');                    // Gegenprobe: nur Teilstring, eigener Grundname
});

it('Planung: der Modus-Umschalter bleibt beim Rerender stehen und schaltet die Sicht', function () {
    $this->actingAs($this->makeUser($this->rootTeam));
    $session = app(\Platform\FoodAlchemist\Services\PlanningSessionService::class)->create($this->rootTeam, ['title' => 'Composer', 'brief' => '']);
    $lw = \Livewire\Livewire::test(\Platform\FoodAlchemist\Livewire\Planung\Index::class)
        ->call('oeffne', $session->id)
        ->set('composerAnker', [['id' => $this->a['potato'], 'slug' => 'potato', 'label' => 'Kartoffel'],
            ['id' => $this->a['apple'], 'slug' => 'apple', 'label' => 'Apfel']])
        ->set('composerModus', 'basisrezept');
    expect($lw->html())->toContain('Was der Zubereitung fehlt');                 // Säure offen, Rotweinessig als Zutat

    $lw->call('composerAdd', $this->a['crouton']);                              // Rerender durch eine andere Aktion
    expect($lw->get('composerModus'))->toBe('basisrezept')
        ->and($lw->html())->toMatch('/aria-pressed="true"\s+class="[^"]*"\s+data-composer-modus-knopf="basisrezept"/')
        ->and($lw->html())->toContain('data-urteil')
        ->and($lw->html())->toContain('passt nicht zum Rest');
});
