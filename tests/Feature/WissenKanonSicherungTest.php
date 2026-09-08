<?php

use Illuminate\Support\Facades\DB;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Tools\ToolRegistry;
use Platform\FoodAlchemist\Services\Knowledge\KanonSicherungService;
use Platform\FoodAlchemist\Services\Knowledge\KnowledgeCanonService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;
use Symfony\Component\Uid\UuidV7;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 52 · Paket 3 — der Kanon bekommt einen Wiederherstellungs-Pfad.
 *
 * Der Anlass ist kein Bug, sondern eine Lücke: der Kanon entsteht ausschliesslich über
 * `knowledge_canon.PUT` und lebt danach nur als DB-Zeilen (`H7`). Eine frische Umgebung hat
 * deshalb keinen — und weil `hasCanon()` dann `false` liefert, greift der Bindungs-Fallback,
 * den es dort ebenfalls nicht gibt. Die Generatoren laufen ohne Regelwerk, ohne Fehlermeldung.
 *
 * Diese Tests pinnen die drei Eigenschaften, an denen das hängt:
 * die Sicherung ist **vollständig** (auch stillgelegte Kuration), sie ist **treu** (active und
 * global überleben den Rückweg) und sie ist **ehrlich** (fehlende Slugs sind ein Befund, kein
 * Abbruch — und kein stiller Erfolg).
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->dienst = app(KanonSicherungService::class);
    $this->datei = sys_get_temp_dir().'/kanon-test-'.bin2hex(random_bytes(6)).'.json';

    $this->mkDoc = function (string $slug, ?int $teamId = null): int {
        return (int) DB::table('foodalchemist_knowledge_documents')->insertGetId([
            'uuid' => (string) UuidV7::generate(), 'team_id' => $teamId, 'slug' => $slug,
            'title' => 'Titel '.$slug, 'category' => 'regelwerk', 'content_md' => str_repeat('x', 500),
            'version' => 1, 'content_hash' => hash('sha256', $slug), 'char_count' => 500,
            'active' => 1, 'created_via' => 'ui', 'created_at' => now(), 'updated_at' => now(),
        ]);
    };

    $this->schreibe = function (array $zeilen): void {
        file_put_contents($this->datei, json_encode(
            ['erzeugt_am' => '2026-09-08', 'team_id' => 1, 'zeilen' => $zeilen], JSON_PRETTY_PRINT
        ));
    };
});

afterEach(function () {
    @unlink($this->datei);
});

it('sichert auch STILLGELEGTE Kuration — sonst verlöre die Sicherung genau die Befunde', function () {
    $kanon = app(KnowledgeCanonService::class);
    ($this->mkDoc)('rw_lebt');
    ($this->mkDoc)('rw_ruht');
    $kanon->set($this->rootTeam, ['scope' => 'prompt_key', 'scope_key' => 'test.key', 'slug' => 'rw_lebt', 'ord' => 10]);
    $kanon->set($this->rootTeam, ['scope' => 'prompt_key', 'scope_key' => 'test.key', 'slug' => 'rw_ruht', 'ord' => 20, 'active' => false]);

    $zeilen = $this->dienst->zeilen($this->rootTeam);

    // `documentsFor()` sähe hier EINE Zeile. Gesichert wird die ENTSCHEIDUNG, nicht ihr
    // heutiges Ergebnis — eine bewusst stillgelegte Zeile ist Teil davon.
    expect($zeilen)->toHaveCount(2)
        ->and(collect($zeilen)->firstWhere('slug', 'rw_ruht')['active'])->toBeFalse();
});

it('spielt den gesicherten Stand TREU zurück — active und global überleben den Rückweg', function () {
    // ★ Genau das war der Fehler meiner ersten Fassung: der Import setzte nur scope/key/role/
    // ord/mode/slug. Eine stillgelegte Kanon-Zeile wäre damit SCHARF zurückgekommen und eine
    // globale als Team-Zeile — die Sicherung hätte die Kuration verändert, statt sie zu retten.
    ($this->mkDoc)('rw_a');
    ($this->mkDoc)('rw_b');
    ($this->schreibe)([
        ['scope' => 'prompt_key', 'scope_key' => 'test.key', 'role' => 'root', 'ord' => 10,
            'mode' => 'pflicht', 'slug' => 'rw_a', 'active' => true, 'global' => true],
        ['scope' => 'prompt_key', 'scope_key' => 'test.key', 'role' => 'root', 'ord' => 20,
            'mode' => 'wenn_platz', 'slug' => 'rw_b', 'active' => false, 'global' => false],
    ]);

    $ergebnis = $this->dienst->spielEin($this->rootTeam, $this->dienst->lade($this->datei), apply: true);
    expect($ergebnis['geschrieben'])->toBe(2)->and($ergebnis['fehlend'])->toBe([]);

    $zurueck = collect($this->dienst->zeilen($this->rootTeam))->keyBy('slug');
    expect($zurueck['rw_a']['global'])->toBeTrue()
        ->and($zurueck['rw_a']['active'])->toBeTrue()
        ->and($zurueck['rw_b']['global'])->toBeFalse()
        ->and($zurueck['rw_b']['active'])->toBeFalse()
        ->and($zurueck['rw_b']['mode'])->toBe('wenn_platz')
        ->and($zurueck['rw_b']['ord'])->toBe(20);
});

it('Export → Import ist ein geschlossener Kreis (derselbe Stand, keine Drift)', function () {
    $kanon = app(KnowledgeCanonService::class);
    foreach (['rw_1', 'rw_2', 'rw_3'] as $i => $slug) {
        ($this->mkDoc)($slug);
        $kanon->set($this->rootTeam, ['scope' => 'prompt_key', 'scope_key' => 'test.key',
            'slug' => $slug, 'ord' => ($i + 1) * 10, 'mode' => $i === 2 ? 'wenn_platz' : 'pflicht']);
    }
    $vorher = $this->dienst->zeilen($this->rootTeam);
    file_put_contents($this->datei, json_encode($this->dienst->inhalt($this->rootTeam)));

    // Kanon plattmachen — der Disaster-Recovery-Fall.
    DB::table('foodalchemist_knowledge_canon')->delete();
    expect($this->dienst->zeilen($this->rootTeam))->toBe([]);

    $this->dienst->spielEin($this->rootTeam, $this->dienst->lade($this->datei), apply: true);
    expect($this->dienst->zeilen($this->rootTeam))->toBe($vorher);
});

it('Vorschau schreibt NICHTS — ein Import ohne --apply darf nicht heimlich wirken', function () {
    ($this->mkDoc)('rw_a');
    ($this->schreibe)([['scope' => 'prompt_key', 'scope_key' => 'test.key', 'role' => 'root',
        'ord' => 10, 'mode' => 'pflicht', 'slug' => 'rw_a', 'active' => true, 'global' => false]]);

    $ergebnis = $this->dienst->spielEin($this->rootTeam, $this->dienst->lade($this->datei), apply: false);

    expect($ergebnis['geschrieben'])->toBe(1)                       // „würde schreiben"
        ->and($this->dienst->zeilen($this->rootTeam))->toBe([]);    // … hat aber nicht
});

it('ein fehlender Slug ist ein BEFUND, kein Abbruch — der Rest kommt an', function () {
    // Der reale Fall: der Korpus wird neu geschnitten, die Sicherung nennt alte Slugs. Ein
    // Abbruch liesse die Umgebung ganz ohne Kanon zurück; stilles Überspringen liesse sie
    // halb versorgt und meldete Erfolg. Beides falsch.
    ($this->mkDoc)('rw_da');
    ($this->schreibe)([
        ['scope' => 'prompt_key', 'scope_key' => 'test.key', 'role' => 'root', 'ord' => 10,
            'mode' => 'pflicht', 'slug' => 'rw_da', 'active' => true, 'global' => false],
        ['scope' => 'prompt_key', 'scope_key' => 'test.key', 'role' => 'root', 'ord' => 20,
            'mode' => 'pflicht', 'slug' => 'rw_neu_geschnitten', 'active' => true, 'global' => false],
    ]);

    $ergebnis = $this->dienst->spielEin($this->rootTeam, $this->dienst->lade($this->datei), apply: true);

    expect($ergebnis['geschrieben'])->toBe(1)
        ->and($ergebnis['uebersprungen'])->toBe(1)
        ->and($ergebnis['fehlend'])->toBe(['rw_neu_geschnitten'])
        ->and(collect($this->dienst->zeilen($this->rootTeam))->pluck('slug')->all())->toBe(['rw_da']);
});

it('ein fremdes Team-Dossier zählt als FEHLEND — sonst meldet der Bericht auflösbar, was set() ablehnt', function () {
    ($this->mkDoc)('rw_fremd', (int) $this->childB->id);
    ($this->schreibe)([['scope' => 'prompt_key', 'scope_key' => 'test.key', 'role' => 'root',
        'ord' => 10, 'mode' => 'pflicht', 'slug' => 'rw_fremd', 'active' => true, 'global' => false]]);

    expect($this->dienst->unaufloesbar($this->childA, $this->dienst->lade($this->datei)))->toBe(['rw_fremd']);
});

it('der Abgleich trennt vier Aussagen, statt nur „stimmt nicht" zu sagen', function () {
    $kanon = app(KnowledgeCanonService::class);
    foreach (['rw_beide', 'rw_nur_live'] as $slug) {
        ($this->mkDoc)($slug);
    }
    $kanon->set($this->rootTeam, ['scope' => 'prompt_key', 'scope_key' => 'test.key', 'slug' => 'rw_beide', 'ord' => 10]);
    $kanon->set($this->rootTeam, ['scope' => 'prompt_key', 'scope_key' => 'test.key', 'slug' => 'rw_nur_live', 'ord' => 20]);

    ($this->schreibe)([
        // dieselbe Zeile, anderes mode → abweichend
        ['scope' => 'prompt_key', 'scope_key' => 'test.key', 'role' => 'root', 'ord' => 10,
            'mode' => 'wenn_platz', 'slug' => 'rw_beide', 'active' => true, 'global' => false],
        // gesichert, live weg, Dossier existiert → nur_datei
        ['scope' => 'prompt_key', 'scope_key' => 'test.key', 'role' => 'root', 'ord' => 30,
            'mode' => 'pflicht', 'slug' => 'rw_entfernt', 'active' => true, 'global' => false],
    ]);
    ($this->mkDoc)('rw_entfernt');

    $a = $this->dienst->abgleich($this->rootTeam, $this->dienst->lade($this->datei));

    expect($a['deckungsgleich'])->toBeFalse()
        ->and($a['live_zeilen'])->toBe(2)->and($a['datei_zeilen'])->toBe(2)
        ->and($a['nur_live'])->toBe(['prompt_key|test.key|root|rw_nur_live'])
        ->and($a['nur_datei'])->toBe(['prompt_key|test.key|root|rw_entfernt'])
        // Das Dossier steht noch — also eine echte Abweichung, kein Neuschnitt.
        ->and($a['nur_datei_aufloesbar'])->toBe(['prompt_key|test.key|root|rw_entfernt'])
        ->and($a['abweichend'])->toHaveCount(1)
        ->and($a['abweichend'][0]['felder'])->toHaveKey('mode')
        ->and($a['ohne_dossier'])->toBe([]);
});

it('deckungsgleich heisst NICHT einspielbar — `ohne_dossier` ist die zweite, eigene Frage', function () {
    // Eine Sicherung kann den heutigen Stand perfekt abbilden und trotzdem nicht zurückkommen,
    // wenn die Dossiers neu geschnitten wurden. Zwei Zustände, zwei Felder.
    $kanon = app(KnowledgeCanonService::class);
    ($this->mkDoc)('rw_x');
    $kanon->set($this->rootTeam, ['scope' => 'prompt_key', 'scope_key' => 'test.key', 'slug' => 'rw_x', 'ord' => 10]);
    file_put_contents($this->datei, json_encode($this->dienst->inhalt($this->rootTeam)));

    DB::table('foodalchemist_knowledge_documents')->where('slug', 'rw_x')->update(['deleted_at' => now()]);

    $a = $this->dienst->abgleich($this->rootTeam, $this->dienst->lade($this->datei));
    expect($a['ohne_dossier'])->toBe(['rw_x'])
        // ★ Und hier steckt die Falle: die Zeile ist zwangsläufig AUCH `nur_datei` — ohne
        // Dossier kann sie nicht live stehen. Nur `nur_datei_aufloesbar` trennt „Verdrahtung
        // entfernt" von „Dossier neu geschnitten"; wer über `nur_datei` roh rechnet, behandelt
        // beides gleich.
        ->and($a['nur_datei'])->toBe(['prompt_key|test.key|root|rw_x'])
        ->and($a['nur_datei_aufloesbar'])->toBe([]);
});

it('lehnt eine kaputte Datei ab, bevor sie halb geschrieben ist', function () {
    file_put_contents($this->datei, '{"kein":"kanon"}');
    expect(fn () => $this->dienst->lade($this->datei))->toThrow(InvalidArgumentException::class, 'keine Kanon-Sicherung');

    ($this->schreibe)([['scope' => 'quatsch', 'scope_key' => 'k', 'role' => 'root', 'ord' => 0, 'mode' => 'pflicht', 'slug' => 's']]);
    expect(fn () => $this->dienst->lade($this->datei))->toThrow(InvalidArgumentException::class, 'scope');

    ($this->schreibe)([['scope' => 'prompt_key', 'scope_key' => 'k', 'role' => 'root', 'ord' => 0, 'mode' => 'pflicht']]);
    expect(fn () => $this->dienst->lade($this->datei))->toThrow(InvalidArgumentException::class, '`slug` fehlt');
});

it('kennt `achse` als Scope — die Enum-Prüfung liegt beim Kanon-Service, nicht als zweite Liste hier', function () {
    // Als `achse` in Paket 2 dazukam, hätte eine eigene Liste in der Sicherung eine gültige
    // Datei still abgelehnt. Deshalb prüft `lade()` gegen KnowledgeCanonService::SCOPES.
    ($this->mkDoc)('rw_achse');
    ($this->schreibe)([['scope' => 'achse', 'scope_key' => 'occasion:bankett', 'role' => 'root',
        'ord' => 10, 'mode' => 'pflicht', 'slug' => 'rw_achse', 'active' => true, 'global' => false]]);

    expect($this->dienst->lade($this->datei))->toHaveCount(1)
        ->and(KnowledgeCanonService::SCOPES)->toContain('achse');
});

it('die im Repo liegende Sicherung erfüllt den Vertrag', function () {
    // Diese Datei ist von Hand aus dem demo-Kanon erzeugt (dort gibt es keine Shell). Der Test
    // ist die Naht: er hält sie gegen genau den Parser, der sie beim Neuaufbau lesen muss.
    $datei = dirname(__DIR__, 2).'/database/kanon/kanon-team-6.json';
    expect(is_file($datei))->toBeTrue('Sicherung fehlt — der Wiederherstellungs-Pfad ist nur so gut wie die Datei.');

    $zeilen = $this->dienst->lade($datei);
    expect($zeilen)->not->toBeEmpty();

    foreach ($zeilen as $z) {
        expect($z['scope'])->toBeIn(KnowledgeCanonService::SCOPES)
            ->and($z['mode'])->toBeIn(KnowledgeCanonService::MODES)
            ->and($z['role'])->toBeIn(KnowledgeCanonService::ROLES);
    }

    // Kein Duplikat auf demselben Schlüssel — sonst gewönne beim Import die letzte Zeile still.
    $schluessel = array_map(fn ($z) => $z['scope'].'|'.$z['scope_key'].'|'.$z['role'].'|'.$z['slug'], $zeilen);
    expect(array_unique($schluessel))->toHaveCount(count($schluessel));
});

it('das Kommando meldet einen fehlenden Kanon als Fehler, nicht als leeren Erfolg', function () {
    $this->artisan('foodalchemist:wissen-kanon-sicherung', [
        'richtung' => 'export', '--team' => $this->rootTeam->id, '--datei' => $this->datei,
    ])->expectsOutputToContain('Kein Kanon zu sichern')->assertExitCode(1);

    expect(is_file($this->datei))->toBeFalse();
});

it('das Kommando schreibt eine Datei, die es selbst wieder einlesen kann', function () {
    $kanon = app(KnowledgeCanonService::class);
    ($this->mkDoc)('rw_a');
    $kanon->set($this->rootTeam, ['scope' => 'prompt_key', 'scope_key' => 'test.key', 'slug' => 'rw_a', 'ord' => 10]);

    $this->artisan('foodalchemist:wissen-kanon-sicherung', [
        'richtung' => 'export', '--team' => $this->rootTeam->id, '--datei' => $this->datei,
    ])->assertExitCode(0);

    $this->artisan('foodalchemist:wissen-kanon-sicherung', [
        'richtung' => 'pruefen', '--team' => $this->rootTeam->id, '--datei' => $this->datei,
    ])->expectsOutputToContain('Deckungsgleich')->assertExitCode(0);
});

it('meldet Drift ins Cockpit — nicht ins Log, wo sie niemand sieht', function () {
    $kanon = app(KnowledgeCanonService::class);
    ($this->mkDoc)('rw_a');
    $kanon->set($this->rootTeam, ['scope' => 'prompt_key', 'scope_key' => 'test.key', 'slug' => 'rw_a', 'ord' => 10]);
    ($this->schreibe)([]);   // Sicherung leer → die eine Zeile ist nur live

    $this->artisan('foodalchemist:wissen-kanon-sicherung', [
        'richtung' => 'pruefen', '--team' => $this->rootTeam->id, '--datei' => $this->datei,
    ])->assertExitCode(1);

    $signal = DB::table('foodalchemist_signals')->where('dedup_key', 'wissen-kanon-sicherung')->first();
    expect($signal)->not->toBeNull()
        ->and($signal->severity)->toBe('warnung')
        ->and($signal->title)->toContain('weichen von der Sicherung ab');

    // Eigener dedup_key: der Steuerdaten-Wächter benutzt denselben SignalTyp und darf sich
    // mit diesem hier nicht gegenseitig überschreiben.
    expect((string) $signal->dedup_key)->not->toBe('wissen-steuerdaten');
});

it('nur unauflösbare Slugs sind INFO — sonst stumpft der Riegel im Korpus-Umbau ab', function () {
    // Nach einem Neuschnitt zeigen gesicherte Slugs ins Leere. Das ist ein echter Befund, aber
    // der ERWARTETE Zustand während des Umbaus — wöchentlich als Warnung wäre Rauschen.
    ($this->schreibe)([['scope' => 'prompt_key', 'scope_key' => 'test.key', 'role' => 'root',
        'ord' => 10, 'mode' => 'pflicht', 'slug' => 'rw_gibt_es_nicht', 'active' => true, 'global' => false]]);

    $this->artisan('foodalchemist:wissen-kanon-sicherung', [
        'richtung' => 'pruefen', '--team' => $this->rootTeam->id, '--datei' => $this->datei,
    ])->assertExitCode(1);

    $signal = DB::table('foodalchemist_signals')->where('dedup_key', 'wissen-kanon-sicherung')->first();
    expect($signal->severity)->toBe('info')
        ->and($signal->title)->toContain('zeigen ins Leere');
});

it('--kein-signal hält lokale Läufe aus dem Cockpit raus', function () {
    ($this->schreibe)([]);
    $this->artisan('foodalchemist:wissen-kanon-sicherung', [
        'richtung' => 'pruefen', '--team' => $this->rootTeam->id, '--datei' => $this->datei, '--kein-signal' => true,
    ]);

    expect(DB::table('foodalchemist_signals')->where('dedup_key', 'wissen-kanon-sicherung')->count())->toBe(0);
});

it('das MCP-Tool sagt „nicht gesichert", wenn es keine Datei gibt — und nennt die Zeilen, die verloren gingen', function () {
    $kanon = app(KnowledgeCanonService::class);
    ($this->mkDoc)('rw_a');
    $kanon->set($this->rootTeam, ['scope' => 'prompt_key', 'scope_key' => 'test.key', 'slug' => 'rw_a', 'ord' => 10]);

    $tool = app(ToolRegistry::class)->get('foodalchemist.knowledge_kanon_sicherung.GET');
    $ergebnis = $tool->execute(['datei' => $this->datei], new ToolContext($this->makeUser($this->rootTeam), $this->rootTeam));

    expect($ergebnis->success)->toBeTrue()
        ->and($ergebnis->data['gesichert'])->toBeFalse()
        ->and($ergebnis->data['live_zeilen'])->toBe(1)
        ->and($ergebnis->data['hinweis'])->toContain('NICHT gesichert');
});

it('das MCP-Tool meldet Drift und liefert dieselben vier Aussagen wie das Kommando', function () {
    $kanon = app(KnowledgeCanonService::class);
    ($this->mkDoc)('rw_a');
    ($this->mkDoc)('rw_b');
    $kanon->set($this->rootTeam, ['scope' => 'prompt_key', 'scope_key' => 'test.key', 'slug' => 'rw_a', 'ord' => 10]);
    file_put_contents($this->datei, json_encode($this->dienst->inhalt($this->rootTeam)));
    $kanon->set($this->rootTeam, ['scope' => 'prompt_key', 'scope_key' => 'test.key', 'slug' => 'rw_b', 'ord' => 20]);

    $tool = app(ToolRegistry::class)->get('foodalchemist.knowledge_kanon_sicherung.GET');
    $ergebnis = $tool->execute(['datei' => $this->datei], new ToolContext($this->makeUser($this->rootTeam), $this->rootTeam));

    // ★ `gesichert` ist hier ein Boolean und in `abgleich()` war es einmal die Zeilen-Zahl.
    // `$antwort + $abgleich` behält den linken Operanden — die Zahl gewann, und der Test las
    // 1 statt true. Deshalb heissen die Zähler jetzt `datei_zeilen`/`live_zeilen`.
    expect($ergebnis->data['gesichert'])->toBeTrue()
        ->and($ergebnis->data['deckungsgleich'])->toBeFalse()
        ->and($ergebnis->data['datei_zeilen'])->toBe(1)
        ->and($ergebnis->data['nur_live'])->toBe(['prompt_key|test.key|root|rw_b'])
        ->and($ergebnis->data['hinweis'])->toContain('auseinander');
});
