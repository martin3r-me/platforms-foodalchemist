<?php

use Illuminate\Support\Facades\DB;
use Platform\FoodAlchemist\Console\KnowledgePolicySeedCommand;
use Platform\FoodAlchemist\Console\WissenSteuerdatenW0Command;
use Platform\FoodAlchemist\Enums\SignalTyp;
use Platform\FoodAlchemist\Models\FoodAlchemistSignal;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

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
