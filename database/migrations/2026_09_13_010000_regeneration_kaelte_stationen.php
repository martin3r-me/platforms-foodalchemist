<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Uid\UuidV7;

/**
 * Das Regenerations-Vokabular kannte nur HITZE — und zwang damit jeden Kälte-Schritt in die Lücke.
 *
 * Bestand waren neun Geräte: Konvektomat, Induktion, Salamander, Mikrowelle, Bain Marie,
 * Holzkohle-Grill, Pizzaofen, Sous-Vide-Becken (plus ein stillgelegtes). Kein Kühlschrank, keine
 * Tiefkühlung, kein „Raumtemperatur". Wer ein Dessert dokumentieren wollte, hatte nur die
 * Möglichkeit, gar kein Gerät zu wählen — und die Oberfläche liest das als „kalt servieren".
 *
 * Gefunden an Gericht 2717 („Ananas-Carpaccio | Kokosnusssorbet | weisses Schokoladenmousse |
 * Estragonschnee | Himbeeren"). Dort steht ein vollstaendiger, praeziser Dessert-Serviceplan:
 *
 *     Ananas-Carpaccio (TK)        4 °C, 240 min   → im Kuehlschrank auftauen
 *     Weisses Schokoladenmousse    4 °C, 180 min   → kuehl temperieren
 *     Estragonschnee             -18 °C            → gefroren halten
 *     Knusper                     20 °C,  15 min   → auf Raumtemperatur bringen
 *     Himbeeren frisch             4 °C            → gekuehlt halten
 *     Kokosnusssorbet (TK)       -12 °C,  10 min   → antemperieren
 *
 * ★ Das ist kein Missbrauch des Feldes, sondern gute Arbeit ohne passendes Werkzeug. Mein erster
 * Reflex war ein Riegel „kein Geraet ⇒ keine Zahlen" — der haette genau diesen Serviceplan
 * geloescht. Fachlich ist Auftauen Teil der Regeneration, nicht ihr Gegenteil.
 *
 * `is_inactive` und Sortierung nach dem Bestandsmuster; die Stationen kommen fuer JEDES Team, das
 * bereits Regenerations-Geraete fuehrt — hart auf Team 6 zu schreiben waere ein Demo-Artefakt.
 */
return new class extends Migration
{
    private const STATIONEN = [
        ['slug' => 'kuehlung',       'name' => 'Kühlung',        'sort' => 100],
        ['slug' => 'tiefkuehlung',   'name' => 'Tiefkühlung',    'sort' => 110],
        ['slug' => 'raumtemperatur', 'name' => 'Raumtemperatur', 'sort' => 120],
        ['slug' => 'schockfroster',  'name' => 'Schockfroster',  'sort' => 130],
    ];

    public function up(): void
    {
        $teams = DB::table('foodalchemist_vocab_regeneration_devices')
            ->select('team_id')->distinct()->pluck('team_id');

        foreach ($teams as $teamId) {
            foreach (self::STATIONEN as $s) {
                $da = DB::table('foodalchemist_vocab_regeneration_devices')
                    ->where('slug', $s['slug'])
                    ->where(fn ($q) => $teamId === null ? $q->whereNull('team_id') : $q->where('team_id', $teamId))
                    ->exists();
                if ($da) {
                    continue;
                }
                DB::table('foodalchemist_vocab_regeneration_devices')->insert([
                    'uuid' => (string) UuidV7::generate(), 'team_id' => $teamId,
                    'slug' => $s['slug'], 'name' => $s['name'], 'sort_order' => $s['sort'],
                    'is_inactive' => false, 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        // Nur die Stationen entfernen, an denen NICHTS haengt — sonst risse der Rueckweg
        // Serviceplaene auseinander, die inzwischen darauf zeigen.
        $ids = DB::table('foodalchemist_vocab_regeneration_devices')
            ->whereIn('slug', array_column(self::STATIONEN, 'slug'))->pluck('id');
        $belegt = DB::table('foodalchemist_recipe_regenerations')->whereNull('deleted_at')
            ->whereIn('device_vocab_id', $ids)->pluck('device_vocab_id')->unique();

        DB::table('foodalchemist_vocab_regeneration_devices')
            ->whereIn('id', $ids->diff($belegt))->delete();
    }
};
