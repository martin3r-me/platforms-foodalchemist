<?php

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Models\Team;
use Platform\Core\Tools\ToolRegistry;
use Platform\FoodAlchemist\Exceptions\FaRechtFehltException;
use Platform\FoodAlchemist\Livewire\Trendradar\Index as TrendradarIndex;
use Platform\FoodAlchemist\Models\FoodAlchemistTrend;
use Platform\FoodAlchemist\Models\FoodAlchemistTrendBeleg;
use Platform\FoodAlchemist\Models\FoodAlchemistTrendSignal;
use Platform\FoodAlchemist\Services\TeamSettingsService;
use Platform\FoodAlchemist\Services\TrendService;
use Platform\FoodAlchemist\Services\TrendSignalService;
use Platform\FoodAlchemist\Services\Trends\GoogleTrendsQuelle;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 79 · Trendradar nach Sarah Spork: Erfassen (jeder), Einordnen/Status (Kuratieren), Konfidenz aus
 * Belegen (Kap. 3.3), Radar-Regel, Mandanten, Datei-Belege, MCP im Lockstep, Google Trends mit Budget.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->inhaber = $this->makeUser($this->rootTeam, 'Inhaber Trend');
    $this->mitarbeiter = $this->makeUser($this->rootTeam, 'Koch Trend', 'member');
    $this->leser = $this->makeUser($this->rootTeam, 'Leser Trend', 'viewer');
    $this->actingAs($this->inhaber);
    $this->svc = app(TrendService::class);
});

function trendFake(array $werte): GoogleTrendsQuelle
{
    return new class($werte) implements GoogleTrendsQuelle
    {
        public array $abgefragt = [];

        public function __construct(private array $werte) {}

        public function verfuegbar(): bool
        {
            return true;
        }

        public function interesse(Team $team, string $begriff): array
        {
            $this->abgefragt[] = $begriff;

            return $this->werte;
        }
    };
}

function kurve(array $zahlen): array
{
    return array_map(fn ($v, $i) => ['date_from' => sprintf('2026-%02d-01', ($i % 12) + 1), 'date_to' => null, 'value' => $v], $zahlen, array_keys($zahlen));
}

it('Konfidenz folgt Kap. 3.3: Social allein niedrig, Befragung allein mittel, Befragung plus weitere Quelle hoch', function () {
    $t = $this->svc->anlegen($this->rootTeam, ['name' => 'Dubai-Schokolade', 'beleg' => ['quelle' => 'instagram', 'url' => 'https://www.instagram.com/p/abc']]);
    expect($t->konfidenz)->toBe('niedrig')->and($this->svc->bewertung($t)['bestaetigt'])->toBeFalse();

    $this->svc->belegAnhaengen($this->rootTeam, $t->id, ['quelle' => 'google_trends', 'notiz' => 'steigt']);
    expect($t->refresh()->konfidenz)->toBe('niedrig');   // zwei Social-Signale bestätigen trotzdem nicht

    $u = $this->svc->anlegen($this->rootTeam, ['name' => 'Loaded Mash Potatoes', 'beleg' => ['quelle' => 'befragung', 'anteil' => 27]]);
    expect($u->konfidenz)->toBe('mittel')->and($u->befragung_bestaetigt)->toBeTrue()->and($u->befragung_anteil)->toBe(27.0);

    $this->svc->belegAnhaengen($this->rootTeam, $u->id, ['quelle' => 'instagram', 'url' => 'https://www.instagram.com/p/xyz']);
    expect($u->refresh()->konfidenz)->toBe('hoch');

    $l = $this->svc->anlegen($this->rootTeam, ['name' => 'Flexitarismus', 'beleg' => ['quelle' => 'literatur', 'titel' => 'Imbeck 2016']]);
    expect($l->konfidenz)->toBe('mittel');
});

it('Radar-Regel: ohne Einordnung oder nur mit Social-Signalen nicht aufs Radar, mit Branchenquelle schon', function () {
    $t = $this->svc->anlegen($this->rootTeam, ['name' => 'Bubble Tea', 'beleg' => ['quelle' => 'instagram', 'notiz' => 'überall']]);
    expect(fn () => $this->svc->statusSetzen($this->rootTeam, $t->id, 'auf_radar'))->toThrow(\RuntimeException::class, 'Einordnung fehlt');

    $this->svc->aendern($this->rootTeam, $t->id, ['typ' => 'hype', 'ebene' => 'mode', 'kategorie' => 'food']);
    expect(fn () => $this->svc->statusSetzen($this->rootTeam, $t->id, 'auf_radar'))->toThrow(\RuntimeException::class, 'Social- oder Suchsignale');

    $this->svc->belegAnhaengen($this->rootTeam, $t->id, ['quelle' => 'branchenquelle', 'titel' => 'Fachverband']);
    $t = $this->svc->statusSetzen($this->rootTeam, $t->id, 'auf_radar', $this->inhaber->id);
    expect($t->status)->toBe('auf_radar')->and($t->geprueft_by)->toBe($this->inhaber->id)->and($t->einordnung_quelle)->toBe('manuell');

    // Einordnung wegnehmen, während er auf dem Radar steht → laut, nicht stillschweigend
    expect(fn () => $this->svc->aendern($this->rootTeam, $t->id, ['ebene' => '']))->toThrow(\RuntimeException::class, 'Radar-Regel');
});

it('Rechte: Lesen legt Fundstücke ab und belegt, Trend anlegen, einordnen und Status nur mit Kuratieren', function () {
    expect(fn () => $this->svc->anlegen($this->rootTeam, ['name' => 'Leser-Trend'], $this->leser->id))->toThrow(FaRechtFehltException::class);
    $f = $this->svc->fundstueckAblegen($this->rootTeam, ['titel' => 'Herzhafte Lollis', 'fundort' => 'Messe'], null, $this->leser->id);
    expect($f->trend_id)->toBeNull()->and($f->fundstueck)->toBeTrue()->and($f->quelle)->toBe('instagram');
    expect(fn () => $this->svc->fundstueckZuordnen($this->rootTeam, $f->id, 1, $this->leser->id))->toThrow(FaRechtFehltException::class);

    $t = $this->svc->anlegen($this->rootTeam, ['name' => 'Herzhafte Lollis', 'beleg' => ['quelle' => 'beobachtung', 'notiz' => 'Messe']], $this->mitarbeiter->id);
    expect($t->status)->toBe('gesichtet')->and($t->created_by)->toBe($this->mitarbeiter->id);
    $beleg = $this->svc->belegAnhaengen($this->rootTeam, $t->id, ['quelle' => 'instagram', 'url' => 'https://www.instagram.com/p/1'], null, $this->leser->id);

    expect(fn () => $this->svc->aendern($this->rootTeam, $t->id, ['typ' => 'hype'], $this->leser->id))->toThrow(FaRechtFehltException::class)
        ->and(fn () => $this->svc->statusSetzen($this->rootTeam, $t->id, 'verworfen', $this->leser->id))->toThrow(FaRechtFehltException::class)
        ->and(fn () => $this->svc->loeschen($this->rootTeam, $t->id, $this->leser->id))->toThrow(FaRechtFehltException::class);

    // eigenen Beleg darf er zurücknehmen
    $this->svc->belegEntfernen($this->rootTeam, $beleg->id, $this->leser->id);
    expect(FoodAlchemistTrendBeleg::find($beleg->id))->toBeNull();

    $this->svc->aendern($this->rootTeam, $t->id, ['typ' => 'hype', 'ebene' => 'mode', 'kategorie' => 'food'], $this->mitarbeiter->id);
    expect($t->refresh()->typ)->toBe('hype');
});

it('Mandanten: Kind-Team sieht den Trend der Eltern, darf ihn aber nicht ändern; Dubletten werden abgewiesen', function () {
    $t = $this->svc->anlegen($this->rootTeam, ['name' => 'Vegane Ernährung', 'typ' => 'trend', 'ebene' => 'mega', 'kategorie' => 'food']);

    expect($this->svc->liste($this->childA)->pluck('id')->all())->toContain($t->id)
        ->and(fn () => $this->svc->aendern($this->childA, $t->id, ['typ' => 'hype']))->toThrow(\Illuminate\Database\Eloquent\ModelNotFoundException::class)
        ->and(fn () => $this->svc->belegAnhaengen($this->childA, $t->id, ['quelle' => 'presse', 'notiz' => 'x']))->toThrow(\Illuminate\Database\Eloquent\ModelNotFoundException::class)
        ->and(fn () => $this->svc->anlegen($this->childA, ['name' => 'vegane ernährung']))->toThrow(\RuntimeException::class, 'gibt es schon');

    $fremd = $this->svc->anlegen($this->childB, ['name' => 'Nur bei B']);
    expect($this->svc->liste($this->childA)->pluck('id')->all())->not->toContain($fremd->id);
});

it('Validierung: falsche Werte, Food-Cluster außerhalb Food, Link ohne Schema werden abgewiesen', function () {
    expect(fn () => $this->svc->anlegen($this->rootTeam, ['name' => 'X', 'ebene' => 'giga']))->toThrow(\RuntimeException::class, 'Ebene')
        ->and(fn () => $this->svc->anlegen($this->rootTeam, ['name' => 'Y', 'kategorie' => 'deko', 'food_cluster' => 'funktionalitaet']))->toThrow(\RuntimeException::class, 'Food-Cluster')
        ->and(fn () => $this->svc->anlegen($this->rootTeam, ['name' => 'Z', 'beleg' => ['quelle' => 'instagram', 'url' => 'instagram.com/p/1']]))->toThrow(\RuntimeException::class, 'http')
        ->and(fn () => $this->svc->anlegen($this->rootTeam, ['name' => ' ']))->toThrow(\RuntimeException::class, 'Namen');
    expect(FoodAlchemistTrend::count())->toBe(0);   // nichts halb angelegt
});

it('Inspiration: Fundstücke liegen offen in der Team-Pinnwand, Häufungen je Schlagwort, Zuordnen und Trend daraus machen', function () {
    $a = $this->svc->fundstueckAblegen($this->rootTeam, ['titel' => 'Mash mit Pulled Pork', 'schlagworte' => 'Kartoffel, comfort food'], null, $this->leser->id);
    $b = $this->svc->fundstueckAblegen($this->rootTeam, ['url' => 'https://www.instagram.com/p/mash2', 'schlagworte' => ['kartoffel']], null, $this->mitarbeiter->id);
    $c = $this->svc->fundstueckAblegen($this->rootTeam, ['titel' => 'Herzhafte Lollis', 'schlagworte' => ['snack']]);
    expect(fn () => $this->svc->fundstueckAblegen($this->rootTeam, ['notiz' => 'nur Notiz']))->toThrow(\RuntimeException::class, 'Titel, einen Link oder ein Bild');

    // Team-Pinnwand: die ganze Teamfamilie sieht alles (eigener Test unten); hier nur das Oberteam
    expect($this->svc->fundstuecke($this->rootTeam)->pluck('id')->all())->toEqualCanonicalizing([$a->id, $b->id, $c->id])
        ->and($this->svc->haeufungen($this->rootTeam))->toBe(['kartoffel' => 2])
        ->and($this->svc->fundstuecke($this->rootTeam, 'offen', '', 'kartoffel')->count())->toBe(2);

    // Trend daraus machen: beide Kartoffel-Fundstücke gehen mit, Pinnwand „offen" wird leerer
    $t = $this->svc->anlegen($this->rootTeam, ['name' => 'Loaded Mash Potatoes', 'typ' => 'hype', 'ebene' => 'mode', 'kategorie' => 'food',
        'fundstueck_ids' => [$a->id, $b->id]], $this->mitarbeiter->id);
    expect($t->belege()->count())->toBe(2)->and($t->konfidenz)->toBe('niedrig')   // zwei Instagram-Funde bestätigen nicht
        ->and($this->svc->fundstuecke($this->rootTeam)->pluck('id')->all())->toBe([$c->id])
        ->and($this->svc->fundstuecke($this->rootTeam, 'zugeordnet')->count())->toBe(2)
        ->and($this->svc->haeufungen($this->rootTeam))->toBe([]);

    // Zuordnen + Lösen; Kind-Team darf fremde Fundstücke nicht kuratieren
    $this->svc->fundstueckZuordnen($this->rootTeam, $c->id, $t->id);
    expect($t->refresh()->belege()->count())->toBe(3);
    $this->svc->fundstueckLoesen($this->rootTeam, $c->id);
    expect($c->refresh()->trend_id)->toBeNull()
        ->and(fn () => $this->svc->fundstueckZuordnen($this->childA, $c->id, $t->id))->toThrow(\Illuminate\Database\Eloquent\ModelNotFoundException::class);

    // Google-Messungen und Befragungs-Belege sind keine Fundstücke
    $this->svc->belegAnhaengen($this->rootTeam, $t->id, ['quelle' => 'befragung', 'anteil' => 10]);
    expect($this->svc->fundstuecke($this->rootTeam, 'alle')->count())->toBe(3);
});

it('Inspiration: die ganze Teamfamilie sieht sich gegenseitig (auch Geschwister), Schreiben nur das hochladende Team mit Kuratieren', function () {
    $standortA = $this->makeUser($this->childA, 'Koch Standort A', 'member');
    $leserA = $this->makeUser($this->childA, 'Leser Standort A', 'viewer');
    $fA = $this->svc->fundstueckAblegen($this->childA, ['titel' => 'Fund aus Standort A'], null, $leserA->id);
    $fRoot = $this->svc->fundstueckAblegen($this->rootTeam, ['titel' => 'Fund vom Oberteam']);

    foreach ([$this->rootTeam, $this->childA, $this->childB] as $team) {
        expect($this->svc->fundstuecke($team)->pluck('id')->all())->toEqualCanonicalizing([$fA->id, $fRoot->id]);
    }
    // Planung kann Fundstücke der Familie kombinieren
    $s = app(\Platform\FoodAlchemist\Services\PlanningSessionService::class)->ausTrendradar($this->childB, [], [$fA->id]);
    expect($s->brief)->toContain('Inspiration: Fund aus Standort A');

    // Schreiben: nur das hochladende Team, und dort nur mit Kuratieren
    $kochB = $this->makeUser($this->childB, 'Koch Standort B', 'member');
    expect(fn () => $this->svc->belegEntfernen($this->childB, $fA->id, $kochB->id))->toThrow(FaRechtFehltException::class)          // Geschwister
        ->and(fn () => $this->svc->belegEntfernen($this->rootTeam, $fA->id, $this->mitarbeiter->id))->toThrow(FaRechtFehltException::class)  // Oberteam ohne Admin
        ->and(fn () => $this->svc->belegEntfernen($this->childA, $fA->id, $leserA->id))->toThrow(FaRechtFehltException::class);
    $this->svc->belegEntfernen($this->childA, $fA->id, $standortA->id);
    expect(FoodAlchemistTrendBeleg::find($fA->id))->toBeNull();

    // Moderation: der FA-Admin des Oberteams löscht Fundstücke seiner Standorte, ein Member des Oberteams nicht
    $fA2 = $this->svc->fundstueckAblegen($this->childA, ['titel' => 'Zweiter Fund aus A']);
    expect(fn () => $this->svc->belegEntfernen($this->rootTeam, $fA2->id, $this->mitarbeiter->id))->toThrow(FaRechtFehltException::class);
    $this->svc->belegEntfernen($this->rootTeam, $fA2->id, $this->inhaber->id);
    expect(FoodAlchemistTrendBeleg::find($fA2->id))->toBeNull();
    // … aber nicht fremde Familien
    $fremdesTeam = \Platform\Core\Models\Team::create(['name' => 'Fremde Familie', 'user_id' => 1, 'personal_team' => false]);
    $fremd = $this->svc->fundstueckAblegen($fremdesTeam, ['titel' => 'Fremd']);
    expect(fn () => $this->svc->belegEntfernen($this->rootTeam, $fremd->id, $this->inhaber->id))->toThrow(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
});

it('Inspiration: unter einem Master-Team sieht jeder Kunde nur die eigene Familie, nicht die anderen Kunden', function () {
    // rootTeam spielt den Master (BHG), childA und childB sind zwei Kunden, je mit einem Standort
    config(['foodalchemist.master_team_id' => $this->rootTeam->id]);
    $standortA = \Platform\Core\Models\Team::create(['name' => 'Kunde A Standort', 'user_id' => 1, 'personal_team' => false, 'parent_team_id' => $this->childA->id]);
    $fA = $this->svc->fundstueckAblegen($standortA, ['titel' => 'Fund Kunde A']);
    $fB = $this->svc->fundstueckAblegen($this->childB, ['titel' => 'Fund Kunde B']);

    expect($this->svc->fundstuecke($this->childA)->pluck('id')->all())->toBe([$fA->id])
        ->and($this->svc->fundstuecke($standortA)->pluck('id')->all())->toBe([$fA->id])
        ->and($this->svc->fundstuecke($this->childB)->pluck('id')->all())->toBe([$fB->id])
        ->and(fn () => app(\Platform\FoodAlchemist\Services\PlanningSessionService::class)->ausTrendradar($this->childB, [], [$fA->id]))
            ->toThrow(\RuntimeException::class, 'nicht (mehr) sichtbar');
});

it('UI: Fundstück eines anderen Standorts trägt den Standortnamen, ohne Lösch-Knopf', function () {
    $this->svc->fundstueckAblegen($this->childA, ['titel' => 'Fund aus A']);
    $this->actingAs($this->makeUser($this->childB, 'Koch B', 'member'));
    Livewire::test(TrendradarIndex::class)->set('ansicht', 'inspiration')
        ->assertSee('Fund aus A')
        ->assertSeeHtml('data-fundstueck-standort')
        ->assertSee($this->childA->name)
        ->assertDontSee('Fundstück löschen?');
});

it('Radar-Position: Ring nach Ebene, Sektor nach Kategorie, stabil', function () {
    $food = $this->svc->anlegen($this->rootTeam, ['name' => 'Superfood', 'typ' => 'trend', 'ebene' => 'konsum', 'kategorie' => 'food']);
    $deko = $this->svc->anlegen($this->rootTeam, ['name' => 'Coral Coast', 'typ' => 'hype', 'ebene' => 'meta', 'kategorie' => 'deko']);
    $p = $this->svc->radarPosition($food);
    $q = $this->svc->radarPosition($deko);
    $r = fn ($pt) => sqrt(($pt['x'] - 320) ** 2 + ($pt['y'] - 320) ** 2) / 268;

    expect($p['x'])->toBeLessThan(320)                       // Food links
        ->and($q['x'])->toBeGreaterThan(320)                 // Non-Food rechts
        ->and($r($p))->toBeGreaterThanOrEqual(0.35)->toBeLessThanOrEqual(0.57)
        ->and($r($q))->toBeGreaterThanOrEqual(0.85)->toBeLessThanOrEqual(0.97)
        ->and($this->svc->radarPosition($food->refresh()))->toBe($p);
});

it('Datei-Beleg: Screenshot wird am Trend abgelegt und beim Löschen mit entfernt', function () {
    Storage::fake('public');
    Storage::fake('local');
    $t = $this->svc->anlegen($this->rootTeam, ['name' => 'Pizza-Sandwich', 'beleg' => ['quelle' => 'instagram', 'fundort' => '@pizzaria']],
        $this->mitarbeiter->id, UploadedFile::fake()->image('insta.jpg'));
    $beleg = $t->belege()->first();
    expect($beleg->datei_name)->toBe('insta.jpg')->and($beleg->context_file_id)->not->toBeNull()->and($beleg->fundort)->toBe('@pizzaria');

    expect(fn () => $this->svc->belegAnhaengen($this->rootTeam, $t->id, ['quelle' => 'presse'], UploadedFile::fake()->create('x.exe', 10, 'application/x-msdownload')))
        ->toThrow(\RuntimeException::class, 'Bild');

    $this->svc->loeschen($this->rootTeam, $t->id);
    expect(FoodAlchemistTrend::find($t->id))->toBeNull()->and(FoodAlchemistTrendBeleg::where('trend_id', $t->id)->count())->toBe(0);
});

it('MCP im Lockstep: Tools registriert, POST mit base64-Screenshot, PUT nennt Radar-Hindernis, Lesen ist FORBIDDEN', function () {
    Storage::fake('public');
    Storage::fake('local');
    $reg = app(ToolRegistry::class);
    foreach (['trends.GET', 'trends.POST', 'trends.PUT', 'trends.DELETE', 'trend_belege.POST', 'trend_belege.DELETE', 'trends.MESSEN',
        'fundstuecke.GET', 'fundstuecke.POST', 'fundstuecke.PUT'] as $name) {
        expect($reg->has('foodalchemist.'.$name))->toBeTrue();
    }
    $ctx = new ToolContext($this->mitarbeiter, $this->rootTeam);
    $png = 'data:image/png;base64,'.base64_encode(UploadedFile::fake()->image('s.png')->getContent());

    $post = $reg->get('foodalchemist.trends.POST')->execute(['name' => 'Savory Yoghurt', 'typ' => 'hype', 'ebene' => 'mode', 'kategorie' => 'food',
        'beleg' => ['quelle' => 'instagram', 'url' => 'https://www.instagram.com/p/yog'], 'datei' => ['base64' => $png, 'name' => 'yoghurt.png']], $ctx);
    expect($post->success)->toBeTrue()
        ->and($post->data['belege'][0]['datei'])->toBe('yoghurt.png')
        ->and($post->data['konfidenz'])->toBe('niedrig')
        ->and($post->data['radar_hindernis'])->toContain('Social');

    $id = $post->data['id'];
    $put = $reg->get('foodalchemist.trends.PUT')->execute(['id' => $id, 'status' => 'auf_radar'], $ctx);
    expect($put->success)->toBeFalse()->and($put->error)->toContain('Noch nicht aufs Radar');

    $beleg = $reg->get('foodalchemist.trend_belege.POST')->execute(['trend_id' => $id, 'quelle' => 'befragung', 'anteil' => 12, 'titel' => 'Hatch Juli'], $ctx);
    expect($beleg->success)->toBeTrue()->and($beleg->data['konfidenz'])->toBe('hoch')->and($beleg->data['radar_hindernis'])->toBeNull();
    expect($reg->get('foodalchemist.trends.PUT')->execute(['id' => $id, 'status' => 'auf_radar'], $ctx)->data['status'])->toBe('auf_radar');

    $liste = $reg->get('foodalchemist.trends.GET')->execute(['nur_radar' => true, 'vokabular' => true], $ctx);
    expect($liste->data['anzahl'])->toBe(1)->and($liste->data['vokabular']['ebene'])->toHaveKey('meta');

    $verboten = $reg->get('foodalchemist.trends.POST')->execute(['name' => 'Leser-Trend'], new ToolContext($this->leser, $this->rootTeam));
    expect($verboten->success)->toBeFalse()->and($verboten->errorCode)->toBe('FORBIDDEN');

    // Fundstück per MCP mit Screenshot, dann Trend daraus machen
    $fs = $reg->get('foodalchemist.fundstuecke.POST')->execute(['titel' => 'Matcha-Tiramisu', 'schlagworte' => ['matcha'],
        'datei' => ['base64' => $png, 'name' => 'matcha.png']], $ctx);
    expect($fs->success)->toBeTrue()->and($fs->data['datei'])->toBe('matcha.png')->and($fs->data['trend_id'])->toBeNull();
    $pinn = $reg->get('foodalchemist.fundstuecke.GET')->execute([], $ctx);
    expect($pinn->data['anzahl'])->toBe(1)->and($pinn->data['fundstuecke'][0]['titel'])->toBe('Matcha-Tiramisu');
    expect($reg->get('foodalchemist.fundstuecke.PUT')->execute(['fundstueck_ids' => [$fs->data['fundstueck_id']]], $ctx)->errorCode)->toBe('VALIDATION_ERROR');
    $neu = $reg->get('foodalchemist.fundstuecke.PUT')->execute(['fundstueck_ids' => [$fs->data['fundstueck_id']],
        'trend_anlegen' => ['name' => 'Matcha-Desserts', 'typ' => 'hype', 'ebene' => 'mode', 'kategorie' => 'food']], $ctx);
    expect($neu->success)->toBeTrue()->and($neu->data['belege'][0]['datei'])->toBe('matcha.png');
    expect($reg->get('foodalchemist.fundstuecke.GET')->execute([], $ctx)->data['anzahl'])->toBe(0);

    expect($reg->get('foodalchemist.trends.DELETE')->execute(['id' => $id, 'confirm' => false], $ctx)->errorCode)->toBe('CONFIRM_REQUIRED');
    expect($reg->get('foodalchemist.trends.DELETE')->execute(['id' => $id, 'confirm' => true], new ToolContext($this->makeUser($this->childA, 'Kind'), $this->childA))->errorCode)->toBe('NOT_FOUND');
});

it('Google Trends: Messung speichert Kurve, Richtung, Kosten und einen Beleg, zählt aber nicht als Bestätigung', function () {
    $fake = trendFake(kurve([10, 12, 15, 20, 25, 30, 40, 45, 50, 60, 70, 80]));
    app()->instance(GoogleTrendsQuelle::class, $fake);
    $t = $this->svc->anlegen($this->rootTeam, ['name' => 'Matcha', 'suchbegriffe' => ['matcha latte', 'matcha']]);

    $reg = app(ToolRegistry::class);
    $ctx = new ToolContext($this->inhaber, $this->rootTeam);
    expect($reg->get('foodalchemist.trends.MESSEN')->execute(['trend_id' => $t->id], $ctx)->errorCode)->toBe('CONFIRM_REQUIRED');
    $res = $reg->get('foodalchemist.trends.MESSEN')->execute(['trend_id' => $t->id, 'confirm' => true], $ctx);

    expect($res->success)->toBeTrue()
        ->and($fake->abgefragt)->toBe(['matcha latte', 'matcha'])
        ->and($res->data['messungen'][0]['richtung'])->toBe('steigend')
        ->and($res->data['kosten_usd'])->toBe(0.018);
    expect(FoodAlchemistTrendBeleg::where('trend_id', $t->id)->where('quelle', 'google_trends')->count())->toBe(2)
        ->and($t->refresh()->konfidenz)->toBe('niedrig');

    // zweite Messung aktualisiert die Belege statt sie zu vervielfachen
    app(TrendSignalService::class)->messen($this->rootTeam, $t->id);
    expect(FoodAlchemistTrendBeleg::where('trend_id', $t->id)->count())->toBe(2)
        ->and(FoodAlchemistTrendSignal::where('trend_id', $t->id)->count())->toBe(4);
});

it('Google Trends: Monatsbudget stoppt die Abfrage, Wochenlauf nur bei eingeschalteter Messung', function () {
    app()->instance(GoogleTrendsQuelle::class, trendFake(kurve([5, 5, 90, 5])));
    $t = $this->svc->anlegen($this->rootTeam, ['name' => 'Tumeric-Tonic']);
    app(TeamSettingsService::class)->update($this->rootTeam, ['trend_dataforseo_budget_usd' => 0.01]);
    $signale = app(TrendSignalService::class);

    $ersteMessung = $signale->messen($this->rootTeam, $t->id);
    expect($ersteMessung->first()->spitze_ohne_sockel)->toBeTrue()
        ->and(fn () => $signale->messen($this->rootTeam, $t->id))->toThrow(\RuntimeException::class, 'Monatsbudget');

    expect($signale->wochenlauf($this->rootTeam)['gemessen'])->toBe(0);   // Messung im Team aus
    app(TeamSettingsService::class)->update($this->rootTeam, ['trend_dataforseo_enabled' => true, 'trend_dataforseo_budget_usd' => 5]);
    expect($signale->wochenlauf($this->rootTeam))->toMatchArray(['gemessen' => 0, 'uebersprungen' => 1]);   // eben erst gemessen
});

it('Auswertung der Kurve: Richtung und Spitze ohne Sockel', function () {
    $s = app(TrendSignalService::class);
    expect($s->auswerten(kurve([50, 50, 50, 50]))['richtung'])->toBe('stabil')
        ->and($s->auswerten(kurve([80, 60, 40, 20]))['richtung'])->toBe('fallend')
        ->and($s->auswerten(kurve([4, 6, 100, 8]))['spitze_ohne_sockel'])->toBeTrue()
        ->and($s->auswerten(kurve([40, 60, 80, 100]))['spitze_ohne_sockel'])->toBeFalse()
        ->and($s->auswerten([])['richtung'])->toBeNull();
});

it('UI: Radar zeigt eingeordnete Trends, Fundstück mit Screenshot landet in der Pinnwand, daraus wird ein Trend', function () {
    Storage::fake('public');
    Storage::fake('local');
    $t = $this->svc->anlegen($this->rootTeam, ['name' => 'Alkoholfreie Weine', 'typ' => 'trend', 'ebene' => 'konsum', 'kategorie' => 'getraenke',
        'beleg' => ['quelle' => 'marktforschung', 'titel' => 'Studie']]);
    $this->svc->statusSetzen($this->rootTeam, $t->id, 'auf_radar');
    $this->actingAs($this->mitarbeiter);

    Livewire::test(TrendradarIndex::class)
        ->assertSee('Alkoholfreie Weine')
        ->assertSeeHtml('data-trend-punkt="'.$t->id.'"')
        ->call('fundstueckOeffnen')
        ->set('fund.titel', 'Loaded Mash Potatoes')
        ->set('fund.url', 'https://www.instagram.com/p/mash')
        ->set('fund.schlagworte', 'kartoffel')
        ->set('fundDatei', UploadedFile::fake()->image('mash.jpg'))
        ->call('fundstueckAblegen')
        ->assertSet('fehler', null)
        ->assertSet('ansicht', 'inspiration')
        ->assertSeeHtml('data-pinnwand')
        ->assertSee('Loaded Mash Potatoes');

    $f = FoodAlchemistTrendBeleg::where('titel', 'Loaded Mash Potatoes')->first();
    expect($f->trend_id)->toBeNull()->and($f->datei_name)->toBe('mash.jpg');

    // Kuratieren: „Trend daraus machen" — Fundstück wird erster Beleg
    Livewire::test(TrendradarIndex::class)
        ->set('ansicht', 'inspiration')
        ->assertSeeHtml('data-trend-aus-fundstueck')
        ->call('trendAusFundstueck', $f->id)
        ->assertSet('neu.name', 'Loaded Mash Potatoes')
        ->call('erfassen')
        ->assertSet('fehler', null)
        ->call('ansichtSetzen', 'liste')
        ->assertSee('noch nicht eingeordnet');
    $neu = FoodAlchemistTrend::where('name', 'Loaded Mash Potatoes')->first();
    expect($neu->status)->toBe('gesichtet')->and($f->refresh()->trend_id)->toBe($neu->id);
});

it('UI: Lesen sieht keinen Einordnen-Knopf; Kuratieren ordnet ein und setzt aufs Radar', function () {
    $t = $this->svc->anlegen($this->rootTeam, ['name' => 'New Snacking', 'beleg' => ['quelle' => 'literatur', 'titel' => 'Imbeck']]);

    $this->actingAs($this->leser);
    Livewire::test(TrendradarIndex::class)->call('select', $t->id)
        ->assertDontSeeHtml('data-trend-einordnen')
        ->assertDontSeeHtml('data-trend-anlegen-knopf')
        ->assertSeeHtml('data-fundstueck-ablegen')
        ->assertSeeHtml('data-trend-beleg-form');

    $this->actingAs($this->mitarbeiter);
    Livewire::test(TrendradarIndex::class)->call('select', $t->id)
        ->assertSeeHtml('data-trend-einordnen')
        ->call('einordnenStarten')
        ->set('einordnung.typ', 'trend')->set('einordnung.ebene', 'konsum')->set('einordnung.kategorie', 'food')->set('einordnung.food_cluster', 'genuss_gesundheit')
        ->call('einordnungSpeichern')
        ->assertSet('fehler', null)
        ->call('statusSetzen', 'auf_radar')
        ->assertSet('fehler', null)
        ->assertSeeHtml('data-trend-punkt="'.$t->id.'"');
    expect($t->refresh()->food_cluster)->toBe('genuss_gesundheit');
});

it('Startbestand: Sarahs 27 Trends (ohne 4-Tage-Woche), Social-only bleibt geprüft, zweiter Lauf legt nichts doppelt an', function () {
    $this->artisan('foodalchemist:trends-startbestand', ['--team' => $this->rootTeam->id])->assertExitCode(0);
    expect(FoodAlchemistTrend::where('team_id', $this->rootTeam->id)->count())->toBe(27)
        ->and(FoodAlchemistTrend::where('name', 'Kurkuma-Tonic')->exists())->toBeTrue()
        ->and(FoodAlchemistTrend::where('kategorie', 'format')->count())->toBe(4)
        ->and(FoodAlchemistTrend::where('slug', 'bubble-tea')->value('status'))->toBe('geprueft')
        ->and(FoodAlchemistTrend::where('slug', 'vegane-ernaehrung')->value('status'))->toBe('auf_radar')
        ->and(FoodAlchemistTrend::where('slug', 'vegane-ernaehrung')->value('befragung_bestaetigt'))->toBeTrue();

    $this->artisan('foodalchemist:trends-startbestand', ['--team' => $this->rootTeam->id])->assertExitCode(0);
    expect(FoodAlchemistTrend::where('team_id', $this->rootTeam->id)->count())->toBe(27);
});

// ── Planung aus dem Trendradar ────────────────────────────────────────────

it('Planung: Kombination aus Trend, Hype und Fundstück wird ein Briefing mit Herkunft; Lead je Ebene', function () {
    $trend = $this->svc->anlegen($this->rootTeam, ['name' => 'Vegane Ernährung', 'typ' => 'trend', 'ebene' => 'mega', 'kategorie' => 'food',
        'definition' => 'Langfristiger Wertewandel.', 'beleg' => ['quelle' => 'marktforschung', 'titel' => 'Studie X']]);
    $hype = $this->svc->anlegen($this->rootTeam, ['name' => 'Dubai-Schokolade', 'typ' => 'hype', 'ebene' => 'mode', 'kategorie' => 'food']);
    $fund = $this->svc->fundstueckAblegen($this->rootTeam, ['titel' => 'Pistazien-Kataifi-Tarte', 'notiz' => 'Bäckerei in Köln', 'fundort' => '@baeckerei']);
    $planung = app(\Platform\FoodAlchemist\Services\PlanningSessionService::class);

    $s = $planung->ausTrendradar($this->rootTeam, [$trend->id, $hype->id], [$fund->id]);
    expect($s->created_via)->toBe('trendradar')
        ->and($s->source_trend_refs)->toBe(['trend_ids' => [$trend->id, $hype->id], 'fundstueck_ids' => [$fund->id]])
        ->and($s->title)->toBe('Vegane Ernährung + Dubai-Schokolade + Pistazien-Kataifi-Tarte')
        ->and($s->brief)->toContain('Aus diesen Impulsen aus dem Trendradar ein Konzept/Gericht/Basisrezept entwickeln.')
        ->and($s->brief)->toContain('Trend (Megatrend, Food): Vegane Ernährung — Langfristiger Wertewandel.')
        ->and($s->brief)->toContain('Hype (Mode, Food): Dubai-Schokolade')
        ->and($s->brief)->toContain('Inspiration: Pistazien-Kataifi-Tarte — Bäckerei in Köln (gesehen: @baeckerei)')
        ->and($s->analysis)->toStartWith('Vegane Ernährung + Dubai-Schokolade + Pistazien-Kataifi-Tarte')   // Anzeigename in der Leitstelle
        ->and($s->analysis)->toContain('Studie X')
        ->and(\Platform\FoodAlchemist\Services\PlanningSessionService::briefFuerScope($s->brief, 'gericht'))->toStartWith('Aus diesen Impulsen aus dem Trendradar ein Gericht entwickeln.');

    $fremd = $this->svc->anlegen($this->childB, ['name' => 'Nur B']);
    expect(fn () => $planung->ausTrendradar($this->rootTeam, [$fremd->id]))->toThrow(\RuntimeException::class, 'nicht (mehr) sichtbar')
        ->and(fn () => $planung->ausTrendradar($this->rootTeam, []))->toThrow(\RuntimeException::class, 'mindestens einen')
        ->and(fn () => $planung->ausTrendradar($this->rootTeam, range(1, 9)))->toThrow(\RuntimeException::class, 'Höchstens');
});

it('Planung UI: Reiter Trendradar kombiniert, übernimmt ins Briefing der Ziel-Ebene, füllt die anderen nur leer', function () {
    $trend = $this->svc->anlegen($this->rootTeam, ['name' => 'Fermentation', 'typ' => 'trend', 'ebene' => 'konsum', 'kategorie' => 'food']);
    $fund = $this->svc->fundstueckAblegen($this->rootTeam, ['titel' => 'Koji-Butter auf Brot']);

    $comp = Livewire::test(\Platform\FoodAlchemist\Livewire\Planung\Index::class)
        ->call('schnellTrendradar')
        ->assertSeeHtml('data-tab-trendradar')
        ->assertSeeHtml('data-trend-wahl="'.$trend->id.'"')
        ->assertSeeHtml('data-fund-wahl="'.$fund->id.'"')
        ->set('eingabe.concept.brief', 'Schon getippt')
        ->set('trendWahl.trends', [(string) $trend->id])
        ->set('trendWahl.fundstuecke', [(string) $fund->id])
        ->assertSeeHtml('data-trend-vorschau')
        ->call('trendradarUebernehmen', 'gericht')
        ->assertSet('trendMeldung', null)
        ->assertDispatched('modal.open', name: 'planung-editor', tab: 'gericht');

    expect($comp->get('eingabe.gericht.brief'))->toStartWith('Aus diesen Impulsen aus dem Trendradar ein Gericht entwickeln.')
        ->and($comp->get('eingabe.gericht.titel'))->toBe('Fermentation + Koji-Butter auf Brot')
        ->and($comp->get('eingabe.rezept.brief'))->toStartWith('Aus diesen Impulsen aus dem Trendradar ein Basisrezept entwickeln.')
        ->and($comp->get('eingabe.concept.brief'))->toBe('Schon getippt');   // getipptes Briefing bleibt

    // Wiederöffnen: Auswahl und Briefing kommen aus der Session zurück
    $id = $comp->get('sessionId');
    Livewire::test(\Platform\FoodAlchemist\Livewire\Planung\Index::class)->call('oeffne', $id)
        ->assertSet('trendWahl.trends', [(string) $trend->id])
        ->assertSet('eingabe.concept.brief', 'Aus diesen Impulsen aus dem Trendradar ein Konzept entwickeln.'."\n".'Trend (Konsum-/Branchentrend, Food): Fermentation'."\n".'Inspiration: Koji-Butter auf Brot'."\n".'Die Impulse verbinden, nicht nebeneinanderstellen.');
});

it('Trendradar → Planung: „In Planung öffnen" legt die Session an und springt in die Leitstelle; MCP kann kombinieren', function () {
    $trend = $this->svc->anlegen($this->rootTeam, ['name' => 'Savory Yoghurt', 'typ' => 'hype', 'ebene' => 'mode', 'kategorie' => 'food']);
    $fund = $this->svc->fundstueckAblegen($this->rootTeam, ['titel' => 'Labneh-Bowl']);

    Livewire::test(TrendradarIndex::class)->call('select', $trend->id)->call('inPlanungOeffnen', $trend->id)
        ->assertRedirectContains('/planung');
    Livewire::test(TrendradarIndex::class)->set('ansicht', 'inspiration')->assertSeeHtml('data-fundstueck-planung')
        ->call('inPlanungOeffnen', null, $fund->id)->assertRedirectContains('/planung');
    expect(\Platform\FoodAlchemist\Models\FoodAlchemistPlanningSession::where('created_via', 'trendradar')->pluck('title')->all())
        ->toBe(['Savory Yoghurt', 'Labneh-Bowl']);

    $res = app(ToolRegistry::class)->get('foodalchemist.planung_session.POST')->execute(
        ['trend_ids' => [$trend->id], 'fundstueck_ids' => [$fund->id]], new ToolContext($this->inhaber, $this->rootTeam));
    expect($res->success)->toBeTrue()
        ->and($res->data['source_trend_refs'])->toBe(['trend_ids' => [$trend->id], 'fundstueck_ids' => [$fund->id]])
        ->and($res->data['brief'])->toContain('Inspiration: Labneh-Bowl');
});

// ── Rückbau Alt-Pfad (Vault-Dossiers + Clustering) ─────────────────────────

it('Concept-Erfindung bekommt den Ursprung aus der Trendradar-Kombination der Planung', function () {
    $trend = $this->svc->anlegen($this->rootTeam, ['name' => 'Fermentation', 'typ' => 'trend', 'ebene' => 'konsum', 'kategorie' => 'food']);
    $fund = $this->svc->fundstueckAblegen($this->rootTeam, ['titel' => 'Koji-Butter']);
    $session = app(\Platform\FoodAlchemist\Services\PlanningSessionService::class)->ausTrendradar($this->rootTeam, [$trend->id], [$fund->id]);
    $frei = app(\Platform\FoodAlchemist\Services\PlanningSessionService::class)->create($this->rootTeam, ['title' => 'Frei']);

    $m = new ReflectionMethod(\Platform\FoodAlchemist\Services\IdeenService::class, 'ursprungsTrendBlock');
    $block = $m->invoke(app(\Platform\FoodAlchemist\Services\IdeenService::class), $this->rootTeam, (int) $session->id);
    expect($block)->toStartWith('# URSPRUNG AUS DEM TRENDRADAR')
        ->and($block)->toContain('Trend (Konsum-/Branchentrend, Food): Fermentation')
        ->and($block)->toContain('Inspiration: Koji-Butter')
        ->and($m->invoke(app(\Platform\FoodAlchemist\Services\IdeenService::class), $this->rootTeam, (int) $frei->id))->toBeNull();
});

it('Rückbau-Migration: alte Trend-Dossiers inaktiv, trend-Routing gelöscht, Cluster-Tabellen weg', function () {
    $docId = \Illuminate\Support\Facades\DB::table('foodalchemist_knowledge_documents')->insertGetId([
        'uuid' => (string) \Illuminate\Support\Str::uuid(), 'team_id' => null, 'slug' => 'trend.alt', 'title' => 'Alter Trend',
        'category' => 'trend', 'content_md' => 'x', 'char_count' => 1, 'content_hash' => hash('sha256', 'x'), 'version' => 1,
        'active' => 1, 'created_at' => now(), 'updated_at' => now(),
    ]);
    \Illuminate\Support\Facades\DB::table('foodalchemist_knowledge_routings')->insertOrIgnore([
        'feature' => 'foodbook.plan', 'category' => 'trend', 'mode' => 'discovery', 'max_docs' => 5, 'created_at' => now(), 'updated_at' => now(),
    ]);

    $migration = require __DIR__.'/../../database/migrations/2026_10_10_100200_retire_legacy_trend_knowledge.php';
    $migration->up();

    expect((int) \Illuminate\Support\Facades\DB::table('foodalchemist_knowledge_documents')->where('id', $docId)->value('active'))->toBe(0)
        ->and(\Illuminate\Support\Facades\DB::table('foodalchemist_knowledge_routings')->where('category', 'trend')->count())->toBe(0)
        ->and(\Illuminate\Support\Facades\Schema::hasTable('foodalchemist_trend_meta'))->toBeFalse()
        ->and(\Illuminate\Support\Facades\Schema::hasTable('foodalchemist_trend_taxonomy'))->toBeFalse()
        ->and(app(\Platform\Core\Tools\ToolRegistry::class)->has('foodalchemist.trendradar.IMPORT'))->toBeFalse();
});

it('Sparten (Nachtrag 08.10.): Mehrfachauswahl, geprüft; Filter zeigt passende + spartenlose Trends; UI + MCP', function () {
    $kita = $this->svc->anlegen($this->rootTeam, ['name' => 'Kita-Snack', 'kategorie' => 'food', 'sparten' => ['bildung']]);
    $event = $this->svc->anlegen($this->rootTeam, ['name' => 'Live-Cooking-Station', 'kategorie' => 'format', 'sparten' => ['event_bankett', 'restaurant_hotel']]);
    $alle = $this->svc->anlegen($this->rootTeam, ['name' => 'Vegane Ernährung', 'kategorie' => 'food']);
    expect(fn () => $this->svc->anlegen($this->rootTeam, ['name' => 'X', 'sparten' => ['raumfahrt']]))->toThrow(\RuntimeException::class, 'Sparte')
        ->and(fn () => $this->svc->anlegen($this->rootTeam, ['name' => 'Y', 'kategorie' => 'event']))->toThrow(\RuntimeException::class, 'Kategorie');

    $namen = fn (array $f) => $this->svc->liste($this->rootTeam, $f)->pluck('name')->sort()->values()->all();
    expect($namen(['sparte' => ['bildung']]))->toBe(['Kita-Snack', 'Vegane Ernährung'])
        ->and($namen(['sparte' => ['restaurant_hotel', 'bildung']]))->toBe(['Kita-Snack', 'Live-Cooking-Station', 'Vegane Ernährung'])
        ->and($namen([]))->toHaveCount(3);

    $this->svc->aendern($this->rootTeam, $kita->id, ['sparten' => []]);
    expect($kita->refresh()->sparten)->toBeNull();
    expect($this->svc->alsArray($event->refresh())['sparten_labels'])->toBe(['Event & Bankett', 'Restaurant & Hotel'])
        ->and(\Platform\FoodAlchemist\Services\TrendService::SEKTOREN)->toHaveKey('format');

    // Oberfläche: Filter „Sparte" + Feld im Einordnen; Badge an der Liste; Speichern übernimmt Sparten
    $lw = \Livewire\Livewire::test(TrendradarIndex::class)->set('ansicht', 'liste')
        ->assertSeeHtml('data-trend-sparten-filter')
        ->set('sparten', ['bildung'])->assertSee('Vegane Ernährung')->assertDontSee('Live-Cooking-Station')
        ->set('sparten', [])->assertSeeHtml('data-trend-sparte="event_bankett"');
    $lw->call('select', $alle->id)->call('einordnenStarten')->assertSeeHtml('data-trend-sparten-edit')
        ->set('einordnung.sparten', ['care', 'bildung'])->call('einordnungSpeichern')->assertSet('fehler', null);
    expect($alle->refresh()->sparten)->toBe(['care', 'bildung']);
});
