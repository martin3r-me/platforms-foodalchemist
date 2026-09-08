<?php

use Illuminate\Support\Facades\DB;
use Platform\FoodAlchemist\Console\KnowledgePolicySeedCommand;
use Platform\FoodAlchemist\Console\WissenSteuerdatenW0Command;
use Platform\FoodAlchemist\Enums\SignalTyp;
use Platform\FoodAlchemist\Models\FoodAlchemistSignal;
use Platform\FoodAlchemist\Tests\Support\SeedsKanon;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class, SeedsKanon::class);

beforeEach(fn () => $this->seedTeamHierarchy());

/*
 * Die Routing-POLITIK hat den Import verlassen (2026-09-03).
 *
 * Vorher lag dieselbe Liste in `KnowledgeImportCommand` und wurde bei JEDEM Import
 * mitgeschrieben. Der Fehlermodus war sehr geduldig: auf demo ein No-op (`insertOrIgnore`, die
 * Zeilen existieren), auf einer FRISCHEN DB dagegen die Wiederherstellung des Monolithen-Pfads,
 * den Welle 0 abgebaut hatte — 15 von 31 Tupeln mit `mode='always'`, darunter
 * `ai_generate_recipe|regelwerk|always|1|9500`.
 *
 * Und gerade diese Zeile belebt den TOTEN Pfad wieder: `regelwerkBlock()` holt per `->first()`
 * genau EIN Dossier. Der Generator hätte also nach einem Neuaufbau ein Regelwerk statt der
 * gebundenen §-Dossiers bekommen — ohne Fehlermeldung, nur mit schlechteren Rezepten. Genau die
 * Sorte Schaden, die niemand einem Import zuordnet.
 */
it('der Import setzt KEINE Routing-Politik mehr', function () {
    $vorher = DB::table('foodalchemist_knowledge_routings')->count();

    $this->artisan('foodalchemist:knowledge-import', ['--dry-run' => true]);

    expect(DB::table('foodalchemist_knowledge_routings')->count())->toBe($vorher);

    // Und die Methode ist weg, nicht nur der Aufruf — sonst holt sie der nächste Refactor zurück.
    expect(method_exists(\Platform\FoodAlchemist\Console\KnowledgeImportCommand::class, 'seedRoutings'))->toBeFalse();
});

it('der Politik-Befehl legt fehlende Routings an — und faesst Bestand NICHT an', function () {
    // Bestand mit einem ABWEICHENDEN Wert: der Befehl darf ihn melden, aber nicht überschreiben.
    // Ein `--apply`, das stillschweigend Politik ändert, wäre derselbe Fehler wie vorher, nur an
    // anderer Stelle — richten tut `wissen-steuerdaten-w0 --apply`, das einen Assert mitbringt.
    // Vorher leeren: die Migrationen seeden Routings, sonst kollidiert der Insert am UNIQUE.
    DB::table('foodalchemist_knowledge_routings')->delete();
    DB::table('foodalchemist_knowledge_routings')->insert([
        'feature' => 'ai_generate_recipe', 'category' => 'regelwerk',
        'mode' => 'always', 'max_docs' => 1, 'max_chars_per_doc' => 9500,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $this->artisan('foodalchemist:knowledge-policy-seed', ['--apply' => true]);

    $rw = DB::table('foodalchemist_knowledge_routings')
        ->where('feature', 'ai_generate_recipe')->where('category', 'regelwerk')->first();

    expect($rw->mode)->toBe('always')            // unangetastet
        ->and($rw->max_chars_per_doc)->toBe(9500)
        // … und der Rest ist da.
        ->and(DB::table('foodalchemist_knowledge_routings')->count())->toBe(count(KnowledgePolicySeedCommand::ROUTINGS));
});

it('auf frischer DB setzt der Politik-Befehl regelwerk auf none, nicht auf always', function () {
    DB::table('foodalchemist_knowledge_routings')->delete();

    $this->artisan('foodalchemist:knowledge-policy-seed', ['--apply' => true]);

    $rw = DB::table('foodalchemist_knowledge_routings')
        ->where('feature', 'ai_generate_recipe')->where('category', 'regelwerk')->first();

    // DAS ist der Kern: der alte Import-Seed hätte hier `always|1|9500` geschrieben und damit
    // den toten regelwerkBlock-Pfad wiederbelebt.
    expect($rw->mode)->toBe('none');
});

/*
 * Der Drift-Wächter zwischen den ZWEI Listen. Beide behaupten, den Soll-Zustand des
 * Rezept-Generators zu kennen: die eine für den Neuaufbau, die andere fürs Richten des Bestands.
 * Ohne diese Zusicherung driften sie auseinander, und die Divergenz fiele erst beim nächsten
 * Neuaufbau auf — also genau dann, wenn niemand sie erwartet.
 */
it('Politik-Liste und Soll-Liste sagen fuer ai_generate_recipe dasselbe', function () {
    $politik = [];
    foreach (KnowledgePolicySeedCommand::ROUTINGS as [$feature, $category, $mode, $docs, $chars]) {
        if ($feature === 'ai_generate_recipe') {
            $politik[$category] = [$mode, $docs, $chars];
        }
    }

    foreach (WissenSteuerdatenW0Command::ROUTINGS as $category => $soll) {
        // `toHaveKey($k, $v)` nimmt als zweites Argument einen WERT, keine Meldung — dieselbe
        // Falle wie bei `toContain`. Darum `toBeTrue` mit Text.
        expect(array_key_exists($category, $politik))
            ->toBeTrue("Kategorie «{$category}» fehlt in der Politik-Liste");
        expect($politik[$category])->toBe(
            [$soll['mode'], $soll['max_docs'], $soll['max_chars']],
            "Politik und Soll widersprechen sich bei «{$category}»",
        );
    }
});

/*
 * Und der Grund, warum das Ganze überhaupt einen Wächter braucht: Drift ist unsichtbar. Ein
 * Regelwerk, das leise aus dem Prompt fällt, erzeugt keinen Fehler — der Generator läuft weiter.
 * Darum meldet `--verify` in das Signale-Cockpit statt in ein Log.
 */
it('verify meldet Drift als Signal — und schliesst es wieder, wenn sie weg ist', function () {
    // Ein Zustand, der garantiert abweicht: regelwerk auf always statt none.
    DB::table('foodalchemist_knowledge_routings')->delete();
    DB::table('foodalchemist_knowledge_routings')->insert([
        'feature' => 'ai_generate_recipe', 'category' => 'regelwerk',
        'mode' => 'always', 'max_docs' => 1, 'max_chars_per_doc' => 9500,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $this->artisan('foodalchemist:wissen-steuerdaten-w0', ['--verify' => true, '--team' => $this->rootTeam->id]);

    $signal = FoodAlchemistSignal::where('team_id', $this->rootTeam->id)
        ->where('type', SignalTyp::SteuerdatenDrift->value)->first();

    expect($signal)->not->toBeNull()
        ->and($signal->dedup_key)->toBe('wissen-steuerdaten')
        // Die Beschreibung muss sagen, WARUM es niemandem auffällt — sonst liest der Empfänger
        // „Abweichung" und hält es für kosmetisch.
        ->and($signal->description)->toContain('läuft dabei weiter')
        ->and($signal->description)->toContain('--apply');
});

/*
 * Hier stand `schreibt Bindings mit einem source-Wert, der in die Spalte UND ins
 * Kanal-Vokabular passt` — die Ersatzbremse fuer eine Engine-Luecke (SQLite erzwingt
 * string()-Laengen nicht, MySQL schon; der Wert war 21 Zeichen in varchar(16) und brach
 * auf demo mit SQLSTATE[22001]).
 *
 * Der Befehl schreibt seit Spec 52 · F3 keine Bindungen mehr, `BINDING_SOURCE` ist weg.
 * Die LEHRE ist es nicht: sie steht als eigener Riegel in
 * `feedback_sqlite_tests_no_varchar_length` und gilt fuer jede kuenftige varchar-Spalte,
 * die aus dem Code befuellt wird.
 */

/*
 * Spec 50 Welle 2 (2026-09-06): der Kanon hat die Layer-Bindings an den Generatoren abgelöst.
 * Der Gateway liest bei vorhandenem Kanon KEINE Bindings mehr — und die gebundenen Originale
 * (`mengen_defaults`, `geschmacksbalance`, `regelwerk.regelwerk_verkaufsgerichte`) sind zugunsten
 * ihrer Ein-Thema-Splits deaktiviert. Ein Wächter, der weiter die Bindings prüft, meldet ab dann
 * jeden Montag 06:30 eine Drift, die es nicht gibt — und übersieht die, die es gibt (ein inaktives
 * Kanon-Dossier fällt genauso still aus dem Prompt wie früher ein inaktives gebundenes).
 */
function w0MkDoc(string $slug, string $kategorie, bool $aktiv = true, int $chars = 1200, ?int $teamId = null): int
{
    $inhalt = str_repeat('x', $chars);

    return (int) DB::table('foodalchemist_knowledge_documents')->insertGetId([
        'uuid' => (string) \Symfony\Component\Uid\UuidV7::generate(),
        'team_id' => $teamId, 'slug' => $slug, 'title' => $slug, 'category' => $kategorie,
        'content_md' => $inhalt, 'version' => 1, 'content_hash' => hash('sha256', $inhalt),
        'char_count' => $chars, 'active' => $aktiv, 'created_at' => now(), 'updated_at' => now(),
    ]);
}


it('verify prueft bei vorhandenem Kanon den KANON — inaktive Originale hinter ihm sind keine Drift', function () {
    // Zustand nach Welle 2: das gebundene Original ist inaktiv, sein Split steckt im Kanon.
    w0MkDoc('mengen_defaults', 'cross_cutting', aktiv: false);
    w0MkDoc('mengen_defaults--hauptgang-komponenten', 'cross_cutting', teamId: $this->rootTeam->id);
    w0MkDoc('regelwerk-basisrezepte-2-verarbeitungs-reduktion-brunoise-roh-form', 'regelwerk', teamId: $this->rootTeam->id);
    $this->kanonZeile($this->rootTeam->id, 'recipe.generator', 'mengen_defaults--hauptgang-komponenten');
    $this->kanonZeile($this->rootTeam->id, 'recipe.generator', 'regelwerk-basisrezepte-2-verarbeitungs-reduktion-brunoise-roh-form');

    $this->artisan('foodalchemist:wissen-steuerdaten-w0', ['--verify' => true, '--team' => $this->rootTeam->id])
        ->expectsOutputToContain('recipe.generator   Kanon-Pflicht: 2 Dossiers');

    // Die Kanon-Ziele erzeugen KEINE Binding-Befunde — andere Ziele ohne Kanon dürfen es (Fallback).
    $signal = FoodAlchemistSignal::where('team_id', $this->rootTeam->id)
        ->where('type', SignalTyp::SteuerdatenDrift->value)->first();
    $befunde = $signal?->payload['abweichungen'] ?? [];

    expect(collect($befunde)->filter(fn ($f) => str_starts_with($f, 'recipe.generator:'))->all())->toBe([]);
});

it('verify meldet ein INAKTIVES Kanon-Pflicht-Dossier — es faellt genauso still aus dem Prompt wie frueher ein gebundenes', function () {
    w0MkDoc('regelwerk-basisrezepte-2-verarbeitungs-reduktion-brunoise-roh-form', 'regelwerk', teamId: $this->rootTeam->id);
    w0MkDoc('geschmacksbalance--grundprinzipien', 'cross_cutting', aktiv: false, teamId: $this->rootTeam->id);
    $this->kanonZeile($this->rootTeam->id, 'vk.generator', 'regelwerk-basisrezepte-2-verarbeitungs-reduktion-brunoise-roh-form');
    $this->kanonZeile($this->rootTeam->id, 'vk.generator', 'geschmacksbalance--grundprinzipien');

    $this->artisan('foodalchemist:wissen-steuerdaten-w0', ['--verify' => true, '--team' => $this->rootTeam->id])
        ->assertExitCode(1);

    $signal = FoodAlchemistSignal::where('team_id', $this->rootTeam->id)
        ->where('type', SignalTyp::SteuerdatenDrift->value)->first();

    expect($signal->description)->toContain('vk.generator: Kanon-Pflicht «geschmacksbalance--grundprinzipien» ist inaktiv');
});

it('verify meldet einen cross_cutting:always-Slug ohne aktives Dossier — die stille Luecke von Kundentext und Wording', function () {
    // Genau die Regression des Split-Cutovers: das Routing sagt `always`, die Slug-Liste zeigt auf
    // ein deaktiviertes Original, `crossCuttingDocs()` liefert leise nichts.
    config()->set('foodalchemist.ai.cross_cutting_slugs', ['foodbook.kundentext' => ['saisonkalender', 'synonyme']]);
    DB::table('foodalchemist_knowledge_routings')->updateOrInsert(
        ['feature' => 'foodbook.kundentext', 'category' => 'cross_cutting'],
        ['mode' => 'always', 'max_docs' => 2, 'max_chars_per_doc' => 1800, 'created_at' => now(), 'updated_at' => now()],
    );
    w0MkDoc('saisonkalender', 'cross_cutting', aktiv: false);
    w0MkDoc('synonyme', 'cross_cutting', aktiv: true);

    $this->artisan('foodalchemist:wissen-steuerdaten-w0', ['--verify' => true, '--team' => $this->rootTeam->id])
        ->assertExitCode(1);

    $signal = FoodAlchemistSignal::where('team_id', $this->rootTeam->id)
        ->where('type', SignalTyp::SteuerdatenDrift->value)->first();
    $befunde = collect($signal->payload['abweichungen']);

    expect($befunde->contains(fn ($f) => str_contains($f, '«foodbook.kundentext» lädt cross_cutting:always «saisonkalender»')))->toBeTrue()
        ->and($befunde->contains(fn ($f) => str_contains($f, '«synonyme»')))->toBeFalse();
});

it('apply fasst GAR KEINE Bindungen mehr an — auch nicht die, die frueher stillgelegt wurden', function () {
    // Vorher pinnte dieser Test die feine Bindungs-Choreografie des Befehls: Kanon-Ziele
    // auslassen, Fallback-Ziele auf `always` heben, inaktive Dossiers nicht reaktivieren,
    // UMBINDEN-Reste stilllegen. Vier Regeln fuer einen Kanal, den es seit Spec 52 · F2
    // nicht mehr gibt.
    //
    // Die neue Aussage ist eine Zeile und wichtiger als die vier alten: der Befehl LAESST
    // die Tabelle in Ruhe. Wer sie aufraeumt, ist ein Mensch mit `knowledge.UNBIND` — nicht
    // ein Waechter, der montags 06:30 Zeilen umschreibt, die niemand liest.
    $bau = w0MkDoc('regelwerk-basisrezepte-2-verarbeitungs-reduktion-brunoise-roh-form', 'regelwerk');
    $md = w0MkDoc('mengen_defaults', 'cross_cutting', aktiv: false);
    $this->kanonZeile($this->rootTeam->id, 'recipe.generator', 'regelwerk-basisrezepte-2-verarbeitungs-reduktion-brunoise-roh-form');

    $binding = fn (int $docId, string $ziel, string $mode, int $active) => [
        'uuid' => (string) \Illuminate\Support\Str::uuid(), 'team_id' => null, 'knowledge_document_id' => $docId,
        'binding_type' => 'layer', 'target_key' => $ziel, 'mode' => $mode, 'weight' => 50, 'active' => $active,
        'source' => 'import', 'created_at' => now(), 'updated_at' => now(),
    ];
    DB::table('foodalchemist_knowledge_bindings')->insert([
        $binding($bau, 'recipe.generator', 'discovery', 0),
        $binding($bau, 'vk.generator', 'discovery', 0),
        $binding($md, 'vk.generator', 'always', 1),
    ]);
    $vorher = DB::table('foodalchemist_knowledge_bindings')
        ->orderBy('id')->get(['knowledge_document_id', 'target_key', 'mode', 'active'])->toArray();

    $this->artisan('foodalchemist:wissen-steuerdaten-w0', ['--apply' => true, '--team' => $this->rootTeam->id]);

    $nachher = DB::table('foodalchemist_knowledge_bindings')
        ->orderBy('id')->get(['knowledge_document_id', 'target_key', 'mode', 'active'])->toArray();
    expect($nachher)->toEqual($vorher);
});

it('verify meldet einen LEEREN Kanon am Generator als Fehler — frueher fiel der Fall still in die Bindungen', function () {
    // ★ Der Zustand, den F2 ueberhaupt erst sichtbar macht. Vorher hiess „kein Kanon"
    // stillschweigend „dann eben Bindungen"; heute heisst es „dieser Prompt bekommt kein
    // Regelwerk", und genau das soll der Montags-Lauf sagen.
    $this->artisan('foodalchemist:wissen-steuerdaten-w0', ['--verify' => true, '--team' => $this->rootTeam->id]);

    $signal = DB::table('foodalchemist_signals')->where('dedup_key', 'wissen-steuerdaten')->latest('id')->first();
    expect($signal)->not->toBeNull()
        ->and($signal->description)->toContain('KEIN Kanon')
        ->and($signal->description)->toContain('wissen-kanon-sicherung');
});
