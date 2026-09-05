<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * „Combi-Steamer" und „Konvektomat" sind dasselbe Gerät (Entscheid Dominique 2026-09-05:
 * Konvektomat bleibt). Ein Combi-Steamer IST ein Konvektomat mit Dampf; im Küchendeutsch heißt
 * beides Kombidämpfer.
 *
 * Warum das nicht kosmetisch ist: die Füllgrad-Matrix im Wissensmodul ist über Behälter × GERÄT
 * geführt. Zwei Namen für eine Maschine hieße, die Zeile zweimal zu pflegen — und die Hälfte der
 * Rezepte hinge an der ungepflegten. Dieselbe Dublettenklasse wie bei den GN-Behältern
 * (Migration 2026_09_04_000015), nur im Geräte-Vokabular.
 *
 * Gemerged wird per NAME, nicht per Slug — s. 000015: der Slug ist die unzuverlässige Achse.
 */
return new class extends Migration
{
    private const TABELLE = 'foodalchemist_vocab_regeneration_devices';

    private const ZIEL = 'konvektomat';

    /**
     * Namen, die auf den Konvektomat zusammenlaufen — BEREITS normalisiert (klein, Umlaute
     * transliteriert). Ein Eintrag mit „ä" wuerde hier nie treffen, weil `schluessel()` vorher
     * ae daraus macht.
     */
    private const ALIASSE = ['combi-steamer', 'combi steamer', 'combisteamer', 'kombidaempfer'];

    public function up(): void
    {
        if (! DB::getSchemaBuilder()->hasTable(self::TABELLE)) {
            return;
        }

        $alle = DB::table(self::TABELLE)->whereNull('deleted_at')->get();

        foreach ($alle->groupBy(fn ($g) => $g->team_id ?? 'global') as $gruppe) {
            $ziel = $gruppe->first(fn ($g) => $this->schluessel($g->name) === self::ZIEL);
            if ($ziel === null) {
                continue;                       // kein Konvektomat in diesem Team → nichts zu tun
            }

            foreach ($gruppe as $g) {
                if ((int) $g->id === (int) $ziel->id || ! in_array($this->schluessel($g->name), self::ALIASSE, true)) {
                    continue;
                }

                DB::table('foodalchemist_recipe_regenerations')
                    ->where('device_vocab_id', $g->id)->update(['device_vocab_id' => $ziel->id]);

                DB::table(self::TABELLE)->where('id', $g->id)
                    ->update(['deleted_at' => now(), 'is_inactive' => 1, 'updated_at' => now()]);
            }
        }
    }

    public function down(): void
    {
        // Nicht umkehrbar: welche Regenerationszeile vorher auf welchen Namen zeigte, ist nach
        // dem Umhängen nicht mehr rekonstruierbar.
    }

    private function schluessel(string $name): string
    {
        $s = mb_strtolower(trim($name));
        $s = strtr($s, ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss']);

        return trim((string) preg_replace('/\s+/u', ' ', $s));
    }
};
