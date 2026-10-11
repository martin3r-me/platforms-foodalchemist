<?php

use Illuminate\Database\Migrations\Migration;
use Platform\FoodAlchemist\Models\FoodAlchemistRule;
use Platform\FoodAlchemist\Services\Regeln\RegelService;

/**
 * Rollen-Umbau (Dominique 11.10.): Rollen gibt es nur im GERICHT — ein Teller-Vokabular als Regel-Daten, pflegbar.
 * Ersetzt V-21 (aroma_treiber|komponente|beilage|garnitur): „komponente“ war Auffangwert für 77 % der Gerichtszeilen.
 * Die Reihenfolge der Werte IST die Anrichte-Reihenfolge (Dominique 11.10.: basis → protein → beilage → sauce → crunch
 * → saeure → aroma → garnitur), gelesen von {@see \Platform\FoodAlchemist\Support\TellerRolle::rang}.
 * Dazu die Pairing-Gewichte je Rolle (Dominique 06.10., Spec 58, übertragen 11.10.) — und die Zeilen-Funktion aus.
 */
return new class extends Migration
{
    public function up(): void
    {
        $svc = app(RegelService::class);
        if (! FoodAlchemistRule::query()->whereNull('team_id')->where('schluessel', 'gericht.teller_rolle')->exists()) {
            $w = static fn (string $wert, array $aliase = []) => ['wert' => $wert, 'aliase' => $aliase];
            $svc->speichere([
                'schluessel' => 'gericht.teller_rolle', 'regelwerk' => 'gericht', 'paragraph' => 'Teller-Rolle',
                'titel' => 'Rolle einer Komponente auf dem Teller', 'art' => 'vokabular', 'ziel' => 'vk.zeile.rolle',
                'wirkung' => 'korrigieren',
                'params' => ['werte' => [
                    $w('basis', ['tellerbasis', 'püree', 'puree']), $w('protein', ['hauptkomponente', 'fleisch', 'fisch']),
                    $w('beilage'), $w('sauce', ['soße', 'jus']), $w('crunch', ['knusper', 'knusprig']),
                    $w('saeure', ['säure']), $w('aroma', ['akzent', 'würzakzent']), $w('garnitur', ['deko', 'finish']),
                ]],
                'beispiele' => ['richtig' => [['text' => 'säure', 'erwartet' => 'saeure'], ['text' => 'protein']],
                    'falsch' => [['text' => 'komponente'], ['text' => 'aroma_treiber']]],
                'notiz' => 'Dominique 2026-10-11. Reihenfolge der Werte = Anrichte-Reihenfolge. Kein Auffangwert „komponente“.',
            ], null, true, 'migration');
        }
        if (! FoodAlchemistRule::query()->whereNull('team_id')->where('schluessel', 'pairing.rollen_gewicht')->exists()) {
            $g = static fn (string $rolle, string $gewicht) => ['begriff' => $rolle, 'ziel_typ' => 'wert', 'ziel_name' => $gewicht];
            $svc->speichere([
                'schluessel' => 'pairing.rollen_gewicht', 'regelwerk' => 'pairing', 'paragraph' => 'Gewichte',
                'titel' => 'Pairing-Gewicht je Teller-Rolle', 'art' => 'zuordnung', 'ziel' => 'pairing.rolle',
                'wirkung' => 'korrigieren',
                'params' => ['vergleich' => 'text', 'eintraege' => [
                    $g('sauce', '1.5'), $g('aroma', '1.5'), $g('saeure', '1.5'), $g('protein', '1.0'),
                    $g('basis', '0.6'), $g('beilage', '0.6'), $g('crunch', '0.4'), $g('garnitur', '0.4'),
                ]],
                'beispiele' => ['richtig' => [['text' => 'sauce', 'erwartet' => '1.5'], ['text' => 'crunch', 'erwartet' => '0.4']]],
                'notiz' => 'Dominique 06.10. (Spec 58), übertragen 11.10. Fehlt eine Rolle: Gewicht 1,0.',
            ], null, true, 'migration');
        }
        // Basisrezept-Zeilen tragen weder Rolle noch Funktion (Dominique 11.10.) — Regel aus, Spalte bleibt vorerst.
        $funktion = FoodAlchemistRule::query()->whereNull('team_id')->where('schluessel', 'rezeptzeile.funktion')->first();
        if ($funktion !== null && $funktion->aktiv) {
            $svc->setzeAktiv((int) $funktion->id, false);
        }
    }

    public function down(): void
    {
    }
};
