<?php

use Illuminate\Database\Migrations\Migration;
use Platform\FoodAlchemist\Models\FoodAlchemistRule;
use Platform\FoodAlchemist\Services\Regeln\RegelService;

/**
 * Vokabular der Zeilen-Funktion (Dominique 11.10.) als Regel-Daten statt Konstante — die Werte sind die bisherigen
 * Plan-Funktionen (RecipeKomponentenPlanService::FUNKTIONEN, bleibt als Rückfall). Aliase fangen Schreibweisen der KI.
 */
return new class extends Migration
{
    private const SCHLUESSEL = 'rezeptzeile.funktion';

    public function up(): void
    {
        if (FoodAlchemistRule::query()->whereNull('team_id')->where('schluessel', self::SCHLUESSEL)->exists()) {
            return;
        }
        $w = static fn (string $wert, array $aliase = []) => ['wert' => $wert, 'aliase' => $aliase];
        app(RegelService::class)->speichere([
            'schluessel' => self::SCHLUESSEL, 'regelwerk' => 'basisrezept', 'paragraph' => 'Funktion',
            'titel' => 'Funktion einer Zutatenzeile', 'art' => 'vokabular', 'ziel' => 'rezeptzeile.funktion',
            'wirkung' => 'korrigieren',
            'params' => ['werte' => [
                $w('basis', ['grundlage', 'hauptmasse']), $w('farbe'), $w('bindung', ['binden', 'bindemittel']),
                $w('wuerze', ['würze', 'würzen', 'gewürz']), $w('textur', ['crunch', 'biss']), $w('aroma', ['aromatik']),
                $w('saeure', ['säure', 'frische']), $w('fett'), $w('garnitur', ['deko', 'finish']),
            ]],
            'beispiele' => ['richtig' => [['text' => 'säure', 'erwartet' => 'saeure'], ['text' => 'basis']], 'falsch' => [['text' => 'komponente']]],
            'notiz' => 'Dominique 2026-10-11 (Rollen-Paket). Gericht: Teller-Rolle (role) bleibt, Funktion optional.',
        ], null, true, 'migration');
    }

    public function down(): void
    {
        $r = FoodAlchemistRule::query()->whereNull('team_id')->where('schluessel', self::SCHLUESSEL)->first();
        if ($r !== null) {
            \Illuminate\Support\Facades\DB::table('foodalchemist_rule_versions')->where('rule_id', $r->id)->delete();
            $r->forceDelete();
        }
    }
};
