<?php

use Illuminate\Support\Facades\DB;
use Platform\FoodAlchemist\Livewire\Planung\Index;
use Platform\FoodAlchemist\Models\FoodAlchemistPlanningSession;
use Platform\FoodAlchemist\Services\GpNamingService;
use Platform\FoodAlchemist\Services\IngredientMatchService;
use Platform\FoodAlchemist\Services\Matching\MatchHeuristics;
use Platform\FoodAlchemist\Services\Matching\TokenEngine;
use Platform\FoodAlchemist\Support\Planung\ReglerParams;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Regelwerk GP v3.6.0 §9 (Dominique 19.10.): `gepökelt`, `geräuchert`, `luftgetrocknet` sind eigene Zustände. EINE Quelle
 * (GpNamingService::ZUSTAND_VOCAB). Vorrang luftgetrocknet > geräuchert > gepökelt; TK + Behandlung ⇒ Behandlung, TK als Zusatz
 * im Namen — überall, wo TK zählt, wird es auch aus Name/Verarbeitung gelesen. Lager: Behandlung = Kühlung, mit TK = Tiefkühlung.
 */
it('das Vokabular trägt die drei Behandlungen; Schreibweisen werden auf den Kanon normalisiert', function () {
    $n = app(GpNamingService::class);

    expect(GpNamingService::ZUSTAND_VOCAB)->toContain('gepökelt', 'geräuchert', 'luftgetrocknet')
        ->and(GpNamingService::zustandListe())->toBe('frisch|TK|trocken|konserviert|gepökelt|geräuchert|luftgetrocknet')
        ->and($n->normalisiereZustand('Geräuchert'))->toBe('geräuchert')
        ->and($n->normalisiereZustand('gepökelt'))->toBe('gepökelt')
        ->and($n->normalisiereZustand('Luftgetrocknet'))->toBe('luftgetrocknet')
        ->and($n->normalisiereZustand('tiefgekühlt'))->toBe('TK')
        ->and($n->normalisiereZustand('Frisch'))->toBe('frisch')
        ->and($n->normalisiereZustand('gepoekelt'))->toBe('gepökelt')          // ASCII-Eingabe → Kanon mit Umlaut (Dominique 19.10.)
        ->and($n->normalisiereZustand('Geraeuchert'))->toBe('geräuchert');
});

it('GP-Namen mit echten Umlauten (Dominique 19.10.): Rundlauf, ASCII-Vokabular wird Kanon, gp_key gleich für ü und ue', function () {
    $n = app(GpNamingService::class);
    $f = $n->felderAusName('Möhre: frisch, Würfel 10 mm');

    expect($n->renderGpName($f))->toBe('Möhre: frisch, Würfel 10 mm')
        ->and($n->renderGpName(['hauptzutat' => 'Möhre', 'condition' => 'frisch', 'processing' => 'Wuerfel 10 mm']))->toBe('Möhre: frisch, Würfel 10 mm')
        ->and($n->renderGpName(['hauptzutat' => 'Kartoffel', 'condition' => 'frisch', 'processing' => 'geschaelt', 'portion' => '150 g']))->toBe('Kartoffel: frisch, geschält, 150 g pro Stück')
        ->and($n->slugify('Karottenpüree'))->toBe($n->slugify('Karottenpueree'))
        ->and($n->slugify('Süßkartoffel'))->toBe('suesskartoffel')
        ->and($n->slugify('Wuerfel 5 mm'))->toBe('wuerfel_5_mm')                    // ASCII-Bestand bleibt stabil
        ->and(\Platform\FoodAlchemist\Support\Suche::varianten('pueree'))->toContain('püree')
        ->and(\Platform\FoodAlchemist\Support\Suche::varianten('püree'))->toContain('pueree');
});

it('Bestandsname „Kassler: geräuchert, Scheiben" → Zustand geräuchert (vorher Verarbeitung)', function () {
    $f = app(GpNamingService::class)->felderAusName('Kassler: geräuchert, Scheiben');

    expect($f['condition'])->toBe('geräuchert')->and($f['hauptzutat'])->toBe('Kassler');
});

it('Freitext: Behandlung erkannt, mehrere nach Vorrang, TK + Behandlung ⇒ Behandlung; echter Widerspruch bleibt offen', function () {
    $e = app(TokenEngine::class);

    expect($e->produktForm('Lachs, geräuchert')['zustand'])->toBe('geräuchert')
        ->and($e->produktForm('Kochschinken gepökelt')['zustand'])->toBe('gepökelt')
        ->and($e->produktForm('Serrano luftgetrocknet')['zustand'])->toBe('luftgetrocknet')
        ->and($e->produktForm('Rohschinken luftgetrocknet und geräuchert')['zustand'])->toBe('luftgetrocknet')
        ->and($e->produktForm('Bauchspeck gepökelt und geräuchert')['zustand'])->toBe('geräuchert')
        ->and($e->produktForm('Räucherlachs geräuchert, TK')['zustand'])->toBe('geräuchert')
        ->and($e->produktForm('Tomaten frisch und konserviert')['zustand'])->toBeNull()
        ->and($e->produktForm('Erbsen TK, getrocknet')['zustand'])->toBeNull()
        ->and($e->produktForm('Tomaten getrocknet')['zustand'])->toBe('trocken')                     // Gegenprobe: getrocknet ≠ luftgetrocknet
        ->and($e->produktForm('Lachs, geräuchert')['identitaet'])->toBe(['lachs']);
});

it('Matching: verlangte Behandlung trifft das GP mit dieser Spalte; Bestand mit altem Zustand nur, wenn der Name sie trägt', function () {
    $m = app(IngredientMatchService::class);

    expect($m->acceptsProductForm('Lachs geräuchert', 'Lachs: geräuchert, Scheiben', 'geräuchert'))->toBeTrue()
        ->and($m->acceptsProductForm('Lachs geräuchert', 'Lachs: TK, geräuchert', 'TK'))->toBeTrue()      // Übergang bis zur Daten-Nachführung
        ->and($m->acceptsProductForm('Lachs geräuchert', 'Lachs: TK, Filet', 'TK'))->toBeFalse()
        ->and($m->acceptsProductForm('Schinken luftgetrocknet', 'Schinken: gepökelt', 'gepökelt'))->toBeFalse()
        ->and($m->acceptsProductForm('Erbsen TK', 'Erbsen: TK', 'TK'))->toBeTrue();                       // Gegenprobe Bestandsweg
});

it('Ranking: die Behandlungen zählen als haltbar gemacht (preserved), eine Behandlung in der Zeile macht sie zur Ware', function () {
    $h = app(MatchHeuristics::class);

    expect($h->zustandClassResolved('Serrano', 'luftgetrocknet'))->toBe('preserved')
        ->and($h->zustandClassResolved('Kassler', 'geräuchert'))->toBe('preserved')
        ->and($h->zustandClass('Kochschinken gepökelt'))->toBe('preserved')
        ->and($h->istBasisrezeptZeile('Jus: Rind'))->toBeTrue()
        ->and($h->istBasisrezeptZeile('Jus: Lachs, geräuchert'))->toBeFalse();
});

it('Planung: eine Auswahl „behandelt" erlaubt die drei Zustände und kommt aus den Parametern zurück', function () {
    $regler = ['rezept' => [...Index::REGLER_DEFAULT, 'frische' => ['behandelt']]];
    $p = (new ReglerParams($regler, []))->reglerParams('rezept');

    expect($p['frische_erlaubt'])->toBe(GpNamingService::BEHANDLUNG)->and($p['frische'])->toBe('behandelt');

    $session = new FoodAlchemistPlanningSession(['generation_params' => ['frische_erlaubt' => ['geräuchert', 'gepökelt']]]);
    $rp = new ReglerParams(['rezept' => Index::REGLER_DEFAULT], []);
    $rp->rehydriereReglerAusParams($session);
    expect($rp->regler['rezept']['frische'] ?? null)->toBe(['behandelt']);
});

it('Backfill: Behandlung im Namen wird gesetzt, bei zweien gilt der Vorrang, TK ist Zusatz; Widerspruch bleibt offen', function () {
    $this->seedTeamHierarchy();
    $eins = $this->makeGp($this->rootTeam, 'Kochschinken: gepökelt, Scheiben');
    $zwei = $this->makeGp($this->rootTeam, 'Rohschinken: luftgetrocknet, geräuchert');
    $tk = $this->makeGp($this->rootTeam, 'Räucherlachs: geräuchert, TK, geschnitten');
    $widerspruch = $this->makeGp($this->rootTeam, 'Erbsen: TK, trocken');
    DB::table('foodalchemist_gps')->whereIn('id', [$eins->id, $zwei->id, $tk->id, $widerspruch->id])->update(['condition' => null]);

    $this->artisan('foodalchemist:gp-zustand-backfill', ['--apply' => true, '--team' => $this->rootTeam->id])->assertSuccessful();

    expect(DB::table('foodalchemist_gps')->where('id', $eins->id)->value('condition'))->toBe('gepökelt')
        ->and(DB::table('foodalchemist_gps')->where('id', $zwei->id)->value('condition'))->toBe('luftgetrocknet')
        ->and(DB::table('foodalchemist_gps')->where('id', $tk->id)->value('condition'))->toBe('geräuchert')
        ->and(DB::table('foodalchemist_gps')->where('id', $widerspruch->id)->value('condition'))->toBeNull();
});

it('TK als Zusatz: Name ⇄ Felder runden sauber; TK wird aus Name/Verarbeitung gelesen (Lager, Filter, Ranking)', function () {
    $n = app(GpNamingService::class);
    $f = $n->felderAusName('Räucherlachs: geräuchert, TK, geschnitten');

    expect($f['condition'])->toBe('geräuchert')
        ->and($f['processing'])->toBe('TK, geschnitten')
        ->and($n->renderGpName($f))->toBe('Räucherlachs: geräuchert, TK, geschnitten')
        ->and(GpNamingService::istTk('geräuchert', 'Räucherlachs: geräuchert, TK, geschnitten'))->toBeTrue()
        ->and(GpNamingService::istTk('geräuchert', 'Kassler: geräuchert, Scheiben'))->toBeFalse()
        ->and(GpNamingService::zustandPasst('geräuchert', 'Räucherlachs: geräuchert, TK', 'TK'))->toBeTrue()
        ->and(GpNamingService::zustandPasst('geräuchert', 'Kassler: geräuchert', 'TK'))->toBeFalse()
        ->and(app(MatchHeuristics::class)->zustandClassResolved('Räucherlachs: geräuchert, TK', 'geräuchert'))->toBe('frozen');
});

it('Lagerzone: Behandlung = Kühlung, Behandlung mit TK = Tiefkühlung', function () {
    $this->seedTeamHierarchy();
    $zone = fn (string $name, string $zustand) => app(\Platform\FoodAlchemist\Services\LagerEinrichtungService::class)->zoneFuer(
        tap($this->makeGp($this->rootTeam, $name), fn ($g) => $g->forceFill(['condition' => $zustand, 'commodity_group_code' => '04'])->save())->fresh());

    expect($zone('Kassler: geräuchert, Scheiben', 'geräuchert'))->toBe('kuehl')
        ->and($zone('Serrano: luftgetrocknet', 'luftgetrocknet'))->toBe('kuehl')
        ->and($zone('Räucherlachs: geräuchert, TK, geschnitten', 'geräuchert'))->toBe('tk');
});

it('§8.17 (Regel gp.8.17.pueree): gekauftes Püree/Mus/Mark ist eigene Hauptzutat — als Form hinter dem Doppelpunkt blockiert', function () {
    $this->seedTeamHierarchy();   // Regeln kommen aus den Migrationen
    $n = app(GpNamingService::class);
    $fehler = fn (string $name) => $n->validateGpName($name, ['condition' => 'konserviert'])['errors'];

    expect(implode(' ', $fehler('Karotte: konserviert, Püree')))->toContain('eigene Hauptzutat')->toContain('Püree')
        ->and(implode(' ', $fehler('Apfel: konserviert, Mus')))->toContain('eigene Hauptzutat')
        ->and($fehler('Karottenpüree: konserviert'))->toBe([])
        ->and($fehler('Tomatenmark: konserviert'))->toBe([])
        ->and($fehler('Püree: Kürbis'))->toBe([])                       // Hauptzutat = Püree (Bestandsmuster)
        ->and($fehler('Rinderknochen: konserviert, Mark'))->toBe([]);    // Knochen-Derivat (§11.2)
});
