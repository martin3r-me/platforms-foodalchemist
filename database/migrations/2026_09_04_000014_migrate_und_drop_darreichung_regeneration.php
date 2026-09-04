<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Spec 51 §5 Nr. 1 (User-Entscheid 2026-09-04) — die dritte Regenerations-Ablage abräumen.
 *
 * `foodalchemist_recipe_presentations` trug vier Regenerations-Skalare je Servierform. Sie waren
 * eine zweite Wahrheit neben `recipe_regenerations`, und ein Schreibpfad füllte sie nie
 * (`syncStandardDarreichung()` spiegelt Behälter und Vehikel, die Regenerations-Werte nicht).
 * Gelesen wurden sie trotzdem — am Wandmonitor standen dadurch zwei Regenerationen nebeneinander.
 * Der Lesepfad ist bereits abgeklemmt; hier fallen die Spalten.
 *
 * ERST MIGRIEREN, DANN DROPPEN. Auf demo trug genau EINE Zeile echte Werte — und die belegt,
 * warum ein blindes Drop falsch gewesen wäre: Rezept 1373 hat BEIDES, eine V-19-Zeile
 * »[Gesamt] 140 °C / 8 min« UND die Darreichungs-Skalare mit denselben Werten plus
 * Kerntemperatur 68 und Gerät. Die V-19-Zeile hatte die Kerntemperatur verloren. Genau die Drift,
 * die dieser Spec abstellt — und sie wäre beim Löschen endgültig gewesen.
 *
 * Deshalb wird NICHT dupliziert, sondern zusammengeführt:
 *   - Rezept hat schon eine »Gesamt«-Zeile  → fehlende Felder dort NACHTRAGEN (nie überschreiben:
 *     die gepflegte V-19-Zeile ist die jüngere Wahrheit).
 *   - Rezept hat keine                      → eine anlegen.
 *   - Nur Nicht-Standard-Darreichungen tragen Werte → stehen lassen und melden; eine Servierform
 *     ändert nichts an 140 °C, das gehört einem Menschen vorgelegt (kommt auf demo nicht vor).
 */
return new class extends Migration
{
    private const SPALTEN = [
        'regeneration_temp_c' => 'temp_c',
        'regeneration_duration_min' => 'duration_min',
        'regeneration_core_temp_c' => 'core_temp_c',
        'regeneration_device_vocab_id' => 'device_vocab_id',
    ];

    public function up(): void
    {
        if (! Schema::hasTable('foodalchemist_recipe_presentations')
            || ! Schema::hasColumn('foodalchemist_recipe_presentations', 'regeneration_temp_c')) {
            return;
        }

        $this->rette();

        $fkLoesbar = $this->loeseFremdschluessel();

        Schema::table('foodalchemist_recipe_presentations', function (Blueprint $table) use ($fkLoesbar) {
            foreach (array_keys(self::SPALTEN) as $spalte) {
                if (! Schema::hasColumn('foodalchemist_recipe_presentations', $spalte)) {
                    continue;
                }
                // SQLite verweigert den Drop, solange eine Fremdschluessel-DEFINITION auf die
                // Spalte zeigt — und loesen laesst sie sich dort nicht per Namen. Die Spalte
                // bleibt deshalb auf der Testbasis stehen: ungenutzt, von keinem Pfad gelesen.
                // Dieselbe bewusste Asymmetrie wie beim partiellen Index in 2026_09_04_000001.
                if ($spalte === 'regeneration_device_vocab_id' && ! $fkLoesbar) {
                    continue;
                }
                $table->dropColumn($spalte);
            }
        });

        // Auf SQLite bleibt die Spalte — dann wenigstens ohne Inhalt, damit sie niemand liest.
        if (! $fkLoesbar && Schema::hasColumn('foodalchemist_recipe_presentations', 'regeneration_device_vocab_id')) {
            DB::table('foodalchemist_recipe_presentations')->update(['regeneration_device_vocab_id' => null]);
        }
    }

    /**
     * Den benannten Fremdschlüssel aus 2026_07_03_000004 lösen — nur wo das geht.
     *
     * BEFUND (volle Suite, 2.215 Fehler aus EINER Zeile): `$table->dropForeign('name')` REIHT den
     * Befehl nur ein; ausgeführt wird er beim Bauen des Blueprints. Ein try/catch um den Aufruf
     * fängt deshalb nichts — die Ausnahme fliegt später und riss jede Migration der Testbasis mit.
     * SQLite kann Fremdschlüssel nicht per Namen droppen, braucht es aber auch nicht: dort baut
     * Laravel die Tabelle beim Spalten-Drop ohnehin neu, der Constraint verschwindet mit.
     */
    private function loeseFremdschluessel(): bool
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            return false;
        }
        if (! Schema::hasColumn('foodalchemist_recipe_presentations', 'regeneration_device_vocab_id')) {
            return true;
        }

        try {
            Schema::table('foodalchemist_recipe_presentations', function (Blueprint $table) {
                $table->dropForeign('fa_recipe_darreichungen_regen_geraet_fk');
            });
        } catch (\Throwable) {
            // Den FK gab es nicht (ensureForeign lief nie durch) — kein Fehlerfall.
        }

        return true;
    }

    /** Werte in `recipe_regenerations` überführen, bevor die Spalten fallen. */
    private function rette(): void
    {
        $zeilen = DB::table('foodalchemist_recipe_presentations')
            ->whereNull('deleted_at')
            ->where(function ($q) {
                foreach (array_keys(self::SPALTEN) as $spalte) {
                    $q->orWhereNotNull($spalte);
                }
            })
            ->get(['recipe_id', 'is_standard', 'team_id', ...array_keys(self::SPALTEN)]);

        foreach ($zeilen as $z) {
            if (! $z->is_standard) {
                continue;                       // Nicht-Standard: stehen lassen, Mensch entscheidet
            }

            $werte = [];
            foreach (self::SPALTEN as $von => $nach) {
                if ($z->{$von} !== null) {
                    $werte[$nach] = $z->{$von};
                }
            }
            if ($werte === []) {
                continue;
            }

            $vorhanden = DB::table('foodalchemist_recipe_regenerations')
                ->where('recipe_id', $z->recipe_id)->whereNull('ingredient_id')->whereNull('deleted_at')
                ->orderBy('sort_order')->first();

            if ($vorhanden !== null) {
                // NUR was dort fehlt. Die gepflegte Zeile ist die jüngere Wahrheit.
                $nachtrag = array_filter(
                    $werte,
                    fn (string $feld) => $vorhanden->{$feld} === null,
                    ARRAY_FILTER_USE_KEY
                );
                if ($nachtrag !== []) {
                    DB::table('foodalchemist_recipe_regenerations')->where('id', $vorhanden->id)
                        ->update($nachtrag + ['updated_at' => now()]);
                }

                continue;
            }

            DB::table('foodalchemist_recipe_regenerations')->insert($werte + [
                'uuid' => (string) Str::uuid7(),
                'team_id' => $z->team_id,
                'recipe_id' => $z->recipe_id,
                'component_label' => 'Gesamt',
                'ingredient_id' => null,
                'sort_order' => 0,
                'source' => 'migration',
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        // Kein Rückbau: die Spalten waren eine zweite Wahrheit ohne Schreibpfad, kein Feature.
    }
};
